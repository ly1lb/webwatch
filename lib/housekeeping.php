<?php
declare(strict_types=1);

/*
 * Automatinis valymas, kad duomenys neužpildytų serverio vietos.
 * Paleidžiamas iš cron kartą per parą (arba Nustatymuose – „Išvalyti dabar“).
 *
 * Kas valoma:
 *  - pakeitimų istorija senesnė nei nustatyta (numatyta 1 m.), ir ne daugiau WW_KEEP_CHANGES vienam stebėjimui;
 *    seni nesuspausti istorijos tekstai suspaudžiami (užima ~4–8 k. mažiau);
 *  - ekrano nuotraukos – tik paskutinių WW_KEEP_SHOTS pakeitimų; ištrintų stebėjimų katalogai;
 *  - žurnalas senesnis nei WW_LOG_DAYS d.;
 *  - anoniminės sesijos (robotai, neprisijungę lankytojai) – po 2 d., visos – po 400 d.;
 *  - pasenę nepavykusių prisijungimų įrašai, nebereikalingi nustatymai;
 *  - SQLite failas suspaudžiamas (VACUUM), kai jame daug tuščios vietos.
 */

const WW_LOG_DAYS = 60;
const WW_HISTORY_DAYS_DEFAULT = 365;

/** Kiek dienų laikyti pakeitimų istoriją (0 – neribotai, bet vis tiek ≤ WW_KEEP_CHANGES). */
function history_days(): int
{
    return max(0, (int)setting('keep_days', (string)WW_HISTORY_DAYS_DEFAULT));
}

/** Rašymo užklausa, grąžinanti paveiktų eilučių skaičių. */
function db_exec_count(string $sql, array $params = []): int
{
    return (int)db_retry(function () use ($sql, $params) {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    });
}

/**
 * Atlieka valymą. Be $force – ne dažniau nei kartą per ~20 val. Grąžina statistiką arba null.
 */
function ww_housekeeping(bool $force = false): ?array
{
    $now = time();
    if (!$force && (int)setting('housekeeping_last', '0') > $now - 20 * 3600) {
        return null;
    }
    set_setting('housekeeping_last', (string)$now); // iš karto – kad du cron nedarytų to paties
    @set_time_limit(300);
    $db = db();
    $st = ['changes' => 0, 'shots' => 0, 'log' => 0, 'sessions' => 0, 'settings' => 0, 'freed' => 0];
    $sizeBefore = ww_storage_usage()['total'];

    $watchIds = array_flip(array_map('intval', $db->query('SELECT id FROM watches')->fetchAll(PDO::FETCH_COLUMN)));

    // 1) Pakeitimų istorija: pagal amžių, ištrintų stebėjimų, ir ne daugiau WW_KEEP_CHANGES vienam
    $days = history_days();
    if ($days > 0) {
        $st['changes'] += db_exec_count('DELETE FROM changes WHERE created < ?', [$now - $days * 86400]);
    }
    $st['changes'] += db_exec_count('DELETE FROM changes WHERE watch_id NOT IN (SELECT id FROM watches)');
    foreach ($db->query('SELECT watch_id FROM changes GROUP BY watch_id HAVING COUNT(*) > ' . WW_KEEP_CHANGES)->fetchAll(PDO::FETCH_COLUMN) as $wid) {
        $st['changes'] += db_exec_count('DELETE FROM changes WHERE watch_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM changes WHERE watch_id = ? ORDER BY id DESC LIMIT '
            . WW_KEEP_CHANGES . ') keep)', [$wid, $wid]);
    }

    // 1b) Seni (iki suspaudimo įrašyti) istorijos tekstai – suspaudžiam po dalį kiekvieną kartą
    $st['compressed'] = 0;
    $rows = $db->query("SELECT id, old_content, new_content FROM changes WHERE (LENGTH(old_content) >= 1024 AND old_content NOT LIKE 'gz:%')
        OR (LENGTH(new_content) >= 1024 AND new_content NOT LIKE 'gz:%') LIMIT 500")->fetchAll();
    foreach ($rows as $r) {
        db_write('UPDATE changes SET old_content = ?, new_content = ? WHERE id = ?', [pack_text($r['old_content']), pack_text($r['new_content']), $r['id']]);
        $st['compressed']++;
    }

    // 2) Ekrano nuotraukos
    foreach (glob(WW_DATA . '/shots/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $wid = (int)basename($dir);
        if (!isset($watchIds[$wid])) {
            $st['shots'] += count(glob($dir . '/*') ?: []);
            remove_shots_dir($wid);
            continue;
        }
        $st['shots'] += prune_shots($dir, $wid);
    }

    // 3) Žurnalas
    $st['log'] += db_exec_count('DELETE FROM log WHERE created < ?', [$now - WW_LOG_DAYS * 86400]);

    // 4) Nebereikalingi nustatymai: seni nepavykę prisijungimai, ištrintų stebėjimų žymės
    foreach ($db->query("SELECT k, v FROM settings WHERE k LIKE 'login_fail%'")->fetchAll() as $r) {
        $ts = json_decode((string)$r['v'], true);
        if (!is_array($ts) || !$ts || max(array_map('intval', $ts)) < $now - 86400) {
            $st['settings'] += db_exec_count('DELETE FROM settings WHERE k = ?', [$r['k']]);
        }
    }
    foreach ($db->query("SELECT k FROM settings WHERE k LIKE 'direct_retry%'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
        if (!isset($watchIds[(int)substr((string)$k, strlen('direct_retry_'))])) {
            $st['settings'] += db_exec_count('DELETE FROM settings WHERE k = ?', [$k]);
        }
    }

    // 5) Pasenusios agentų užklausos (saugiklis)
    db_exec_count('DELETE FROM agent_requests WHERE created < ?', [$now - 3600]);

    // 6) Sesijų failai
    $st['sessions'] = ww_clean_sessions();

    // 7) SQLite: atlaisvinti tuščią vietą faile (ištrynus duomenis failas pats nesumažėja)
    if (ww_driver() === 'sqlite') {
        ww_sqlite_compact();
    }

    clearstatcache();
    $st['freed'] = max(0, $sizeBefore - ww_storage_usage()['total']);
    set_setting('housekeeping_stats', json_encode(['at' => $now] + $st));
    if ($st['changes'] + $st['shots'] + $st['log'] + $st['sessions'] + $st['settings'] > 0) {
        ww_log('info', 'Valymas: pakeitimų ' . $st['changes'] . ', nuotraukų ' . $st['shots'] . ', žurnalo ' . $st['log']
            . ', sesijų ' . $st['sessions'] . ', kita ' . $st['settings'] . '; atlaisvinta ' . ww_bytes($st['freed']));
    }
    return $st;
}

/**
 * Sesijų failai: anoniminės (robotai, neprisijungę) – po 2 d., prisijungusių – po 400 d.
 * (PHP pats jų dažnai netrina: hostinguose sesijų „šiukšlių rinkimas" būna išjungtas.)
 */
function ww_clean_sessions(): int
{
    $n = 0;
    $now = time();
    foreach (glob(WW_DATA . '/sessions/sess_*') ?: [] as $f) {
        $age = $now - (int)@filemtime($f);
        if ($age > 400 * 86400) {
            $n += @unlink($f) ? 1 : 0;
        } elseif ($age > 2 * 86400) {
            $data = (string)@file_get_contents($f, false, null, 0, 8192);
            if (!str_contains($data, 'ww_auth|b:1')) {
                $n += @unlink($f) ? 1 : 0;
            }
        }
    }
    return $n;
}

/** SQLite: WAL suliejimas ir VACUUM, jei faile daug tuščios vietos. */
function ww_sqlite_compact(): bool
{
    $pdo = db();
    try {
        $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
        $pages = (int)$pdo->query('PRAGMA page_count')->fetchColumn();
        $free = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
        $size = (int)$pdo->query('PRAGMA page_size')->fetchColumn();
        if ($free * $size > 5 * 1048576 || ($pages > 0 && $free / $pages > 0.3 && $free * $size > 1048576)) {
            $pdo->exec('VACUUM');
            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
            return true;
        }
    } catch (Throwable $e) {
        // užimta – pabandysim kitą kartą
    }
    return false;
}

/** Kiek vietos užima duomenys (baitais). */
function ww_storage_usage(): array
{
    $u = ['db' => 0, 'shots' => 0, 'shot_files' => 0, 'sessions' => 0, 'session_files' => 0, 'sqlite_backup' => 0, 'total' => 0];
    $sqlite = WW_DATA . '/webwatch.sqlite';
    $sqliteSize = 0;
    foreach (['', '-wal', '-shm'] as $sfx) {
        $sqliteSize += is_file($sqlite . $sfx) ? (int)filesize($sqlite . $sfx) : 0;
    }
    if (ww_driver() === 'mysql') {
        try {
            $u['db'] = (int)db()->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
        }
        $u['sqlite_backup'] = $sqliteSize; // senoji SQLite – tik atsarginė kopija
    } else {
        $u['db'] = $sqliteSize;
    }
    if (is_dir(WW_DATA . '/shots')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WW_DATA . '/shots', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $u['shots'] += $f->getSize();
            $u['shot_files']++;
        }
    }
    foreach (glob(WW_DATA . '/sessions/sess_*') ?: [] as $f) {
        $u['sessions'] += (int)@filesize($f);
        $u['session_files']++;
    }
    $u['total'] = $u['db'] + $u['shots'] + $u['sessions'] + $u['sqlite_backup'];
    return $u;
}

function ww_bytes(int $b): string
{
    if ($b < 1024) {
        return $b . ' B';
    }
    if ($b < 1048576) {
        return round($b / 1024) . ' KB';
    }
    if ($b < 1073741824) {
        return number_format($b / 1048576, 1, ',', '') . ' MB';
    }
    return number_format($b / 1073741824, 2, ',', '') . ' GB';
}
