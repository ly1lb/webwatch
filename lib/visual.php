<?php
declare(strict_types=1);

/*
 * Vaizdinis stebėjimas: ekrano nuotraukos (per namų kompiuterio naršyklę) ir jų palyginimas.
 * Nuotraukos saugomos diske data/shots/<watch_id>/.
 */

function visual_available(): bool
{
    return function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
}

function shots_dir(int $watchId): string
{
    $dir = WW_DATA . '/shots/' . $watchId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Padaro ekrano nuotrauką per namų kompiuterį. Grąžina ['ok','png','error']. */
function fetch_screenshot(array $w): array
{
    if (!agents_all()) {
        return ['ok' => false, 'png' => '', 'error' => 'Vaizdiniam stebėjimui reikia namų kompiuterio (su Chrome/Edge)'];
    }
    $r = agent_fetch((string)$w['url'], parse_header_lines((string)($w['headers'] ?? '')),
        ($w['user_agent'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop', false, true);
    if (!$r['ok']) {
        return ['ok' => false, 'png' => '', 'error' => $r['error'] ?: 'Nepavyko padaryti ekrano nuotraukos'];
    }
    if (strncmp($r['body'], "\x89PNG", 4) !== 0) {
        return ['ok' => false, 'png' => '', 'error' => 'Kompiuteris grąžino ne paveikslėlį (ar įdiegta Chrome/Edge?)'];
    }
    return ['ok' => true, 'png' => $r['body'], 'error' => '', 'agent' => $r['agent'] ?? ''];
}

/**
 * Palygina dvi PNG nuotraukas. Grąžina ['pct'=>%, 'diff'=>PNG su pažymėtais pakeitimais].
 * Lyginama sumažinta kopija (greita ir atspari smulkmenoms), pakeitimai pažymimi ant naujos.
 */
function image_diff(string $oldPng, string $newPng, int $cols = 120): array
{
    $old = @imagecreatefromstring($oldPng);
    $new = @imagecreatefromstring($newPng);
    if (!$old || !$new) {
        return ['pct' => 100.0, 'diff' => $newPng];
    }
    $nw = imagesx($new);
    $nh = imagesy($new);
    $ow = imagesx($old);
    $oh = imagesy($old);

    // Jei labai skiriasi matmenys – laikome, kad pasikeitė viskas
    if ($ow < 1 || $oh < 1 || abs($nw - $ow) > $nw * 0.5 || abs($nh - $oh) > max($nh, $oh) * 0.5) {
        imagedestroy($old);
        imagedestroy($new);
        return ['pct' => 100.0, 'diff' => $newPng];
    }

    $rows = max(1, (int)round($cols * $nh / max(1, $nw)));
    $sOld = imagecreatetruecolor($cols, $rows);
    $sNew = imagecreatetruecolor($cols, $rows);
    imagecopyresampled($sOld, $old, 0, 0, 0, 0, $cols, $rows, $ow, $oh);
    imagecopyresampled($sNew, $new, 0, 0, 0, 0, $cols, $rows, $nw, $nh);

    $changed = [];     // [y][x] = true
    $diffCount = 0;
    for ($y = 0; $y < $rows; $y++) {
        for ($x = 0; $x < $cols; $x++) {
            $a = imagecolorat($sOld, $x, $y);
            $b = imagecolorat($sNew, $x, $y);
            $dr = (($a >> 16) & 0xFF) - (($b >> 16) & 0xFF);
            $dg = (($a >> 8) & 0xFF) - (($b >> 8) & 0xFF);
            $db = ($a & 0xFF) - ($b & 0xFF);
            if ($dr * $dr + $dg * $dg + $db * $db > 1200) { // ~35 spalvų atstumas
                $changed[$y][$x] = true;
                $diffCount++;
            }
        }
    }
    $pct = round($diffCount / ($cols * $rows) * 100, 2);

    // Pažymime pakeitimus ant naujos nuotraukos (raudoni stačiakampiai)
    imagedestroy($sOld);
    imagedestroy($sNew);
    $diff = $newPng;
    if ($diffCount > 0) {
        $cellW = $nw / $cols;
        $cellH = $nh / $rows;
        $red = imagecolorallocatealpha($new, 255, 40, 40, 85);
        $redLine = imagecolorallocate($new, 255, 40, 40);
        foreach ($changed as $y => $xs) {
            foreach ($xs as $x => $_) {
                $x1 = (int)floor($x * $cellW);
                $y1 = (int)floor($y * $cellH);
                $x2 = (int)ceil(($x + 1) * $cellW) - 1;
                $y2 = (int)ceil(($y + 1) * $cellH) - 1;
                imagefilledrectangle($new, $x1, $y1, $x2, $y2, $red);
            }
        }
        // Kontūrai aplink pažymėtas sritis (ryškumui)
        foreach ($changed as $y => $xs) {
            foreach ($xs as $x => $_) {
                if (empty($changed[$y - 1][$x])) {
                    imageline($new, (int)floor($x * $cellW), (int)floor($y * $cellH), (int)ceil(($x + 1) * $cellW), (int)floor($y * $cellH), $redLine);
                }
            }
        }
        ob_start();
        imagepng($new, null, 6);
        $diff = (string)ob_get_clean();
    }
    imagedestroy($old);
    imagedestroy($new);
    return ['pct' => $pct, 'diff' => $diff];
}

/** Vaizdinio stebėjimo patikrinimas (atskiras nuo teksto). */
function run_visual_check(array $w, bool $sendNotify): array
{
    $now = time();
    if (!visual_available()) {
        return record_failure($w, 'Serveryje nėra GD plėtinio (paveikslėliams)', $sendNotify);
    }
    $shot = fetch_screenshot($w);
    if (!$shot['ok']) {
        return record_failure($w, $shot['error'], $sendNotify);
    }
    $png = $shot['png'];
    $dir = shots_dir((int)$w['id']);
    $cur = $dir . '/current.png';
    $first = !is_file($cur);

    if ($first) {
        file_put_contents($cur, $png);
        db_write("UPDATE watches SET last_check=?, last_status='ok', last_error='', fail_count=0, last_content='[screenshot]' WHERE id=?",
            [$now, $w['id']]);
        return ['ok' => true, 'changed' => false, 'first' => true, 'summary' => '', 'pct' => 0, 'count' => 1];
    }

    $old = (string)file_get_contents($cur);
    $d = image_diff($old, $png);
    $pct = $d['pct'];
    $threshold = max(0.3, (float)$w['threshold']); // vaizdui bent 0,3 %
    $changed = $pct >= $threshold;

    file_put_contents($cur, $png); // nauja bazė

    if ($changed) {
        $summary = 'Vaizdas pasikeitė ' . number_format($pct, $pct < 1 ? 2 : 0, ',', '') . ' %';
        db_write('INSERT INTO changes (watch_id, created, old_content, new_content, summary, change_pct, has_shot) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$w['id'], $now, '[screenshot]', '[screenshot]', $summary, $pct]);
        $cid = (int)db()->lastInsertId();
        file_put_contents($dir . "/$cid-old.png", $old);
        file_put_contents($dir . "/$cid-new.png", $png);
        file_put_contents($dir . "/$cid-diff.png", $d['diff']);
        prune_shots($dir, (int)$w['id']);
        db_write('UPDATE watches SET last_check=?, last_status=\'ok\', last_error=\'\', fail_count=0, last_change=?, unseen=unseen+1 WHERE id=?',
            [$now, $now, $w['id']]);
        $sent = null;
        if ($sendNotify) {
            $sent = notify_user((string)$w['notify'], '🖼️ ' . watch_title($w), $summary,
                app_url() . '?view=watch&id=' . $w['id'], 'watch-' . $w['id']);
        }
        return ['ok' => true, 'changed' => true, 'first' => false, 'summary' => $summary, 'pct' => $pct, 'count' => 1, 'notified' => $sent];
    }
    db_write("UPDATE watches SET last_check=?, last_status='ok', last_error='', fail_count=0 WHERE id=?", [$now, $w['id']]);
    return ['ok' => true, 'changed' => false, 'first' => false, 'summary' => '', 'pct' => $pct, 'count' => 1];
}

/** Palieka tik paskutinių pakeitimų nuotraukas. */
function prune_shots(string $dir, int $watchId): void
{
    $st = db()->prepare('SELECT id FROM changes WHERE watch_id = ? ORDER BY id DESC LIMIT ' . WW_KEEP_CHANGES);
    $st->execute([$watchId]);
    $keep = array_flip(array_map('intval', array_column($st->fetchAll(), 'id')));
    foreach (glob($dir . '/*-*.png') ?: [] as $f) {
        if (preg_match('/(\d+)-(old|new|diff)\.png$/', $f, $m) && !isset($keep[(int)$m[1]])) {
            @unlink($f);
        }
    }
}

/** Pašalina viso stebėjimo nuotraukų katalogą (ištrynus stebėjimą). */
function remove_shots_dir(int $watchId): void
{
    $dir = WW_DATA . '/shots/' . $watchId;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
