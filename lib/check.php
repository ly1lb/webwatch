<?php
declare(strict_types=1);

const WW_MAX_CONTENT = 300000;
const WW_KEEP_CHANGES = 50;
const WW_FAILS_BEFORE_ALERT = 3;  // numatytasis; keičiamas Nustatymuose (err_after)
const WW_RETRY_MIN = 5;          // po nesėkmės kartojama ne rečiau nei kas tiek min (net jei tikrinama kartą per parą)
const WW_DOWN_RECHECK_MIN = 60;  // po įspėjimo – ne rečiau nei kas tiek min, kad greitai sužinotumėt, kada vėl veikia

/** Po kiek nesėkmių iš eilės pranešti (Nustatymai, 2–10). */
function fails_before_alert(): int
{
    return max(2, min(10, (int)setting('err_after', (string)WW_FAILS_BEFORE_ALERT)));
}

/** Kanalai įspėjimams apie nepavykusius tikrinimus: Nustatymuose pasirinkti, kitaip – stebėjimo. */
function alert_channels(array $w): string
{
    $c = trim((string)setting('err_channels', ''));
    return $c !== '' ? $c : (string)$w['notify'];
}

/**
 * Kada tikrinti kitą kartą (min). Įprastai – stebėjimo intervalas, bet:
 *  - po nesėkmės, kol dar nepranešta – ne rečiau nei kas WW_RETRY_MIN min,
 *    kad kasdien tikrinamo puslapio bėda paaiškėtų per ~10 min, o ne per 3 paras;
 *  - jau pranešus – ne rečiau nei kas WW_DOWN_RECHECK_MIN min (sužinoti, kada vėl veiks).
 */
function effective_interval_min(array $w): int
{
    $iv = max(1, (int)$w['interval_min']);
    $fails = (int)($w['fail_count'] ?? 0);
    if ($fails <= 0) {
        return $iv;
    }
    return min($iv, $fails < fails_before_alert() ? WW_RETRY_MIN : WW_DOWN_RECHECK_MIN);
}

/** Jei puslapis prieš tai buvo neveikiantis ir apie tai pranešta – praneša, kad vėl veikia. */
function notify_recovered(array $w): void
{
    if ((int)$w['fail_count'] < fails_before_alert()) {
        return;
    }
    $since = (int)($w['fail_since'] ?? 0);
    $dur = $since > 0 ? human_duration(time() - $since) : '';
    notify_user(alert_channels($w), '✅ ' . watch_title($w) . ': vėl veikia',
        'Puslapį vėl pavyksta patikrinti.'
        . ($dur !== '' ? "\nNeveikė: " . $dur . ' (' . (int)$w['fail_count'] . ' nesėkm. bandym.).' : ''),
        app_url() . '?view=watch&id=' . $w['id'], 'watch-err-' . $w['id']);
}

function compare_modes(): array
{
    return [
        'text' => ['Bet koks teksto pakeitimas', 'Praneš, kai pasikeis matomas tekstas.'],
        'added' => ['Tik nauji įrašai (naujienoms)', 'Praneš tik kai atsiranda naujų eilučių – pašalinimai ignoruojami.'],
        'keyword_appear' => ['Kai atsiras žodis / frazė', 'Pvz. „Yra sandėlyje“, „Registracija atidaryta“.'],
        'keyword_disappear' => ['Kai dings žodis / frazė', 'Pvz. „Išparduota“, „Nėra prekyboje“.'],
        'number' => ['Skaičius / kaina pasikeitė', 'Stebi pirmą skaičių elemente (pvz. kainą).'],
        'html' => ['HTML kodas (tiksliausia)', 'Mato ir atributų, nuorodų, paveikslėlių pokyčius.'],
        'visual' => ['🖼️ Vaizdinis (ekrano nuotrauka)', 'Palygina puslapio nuotraukas ir rodo, kas pasikeitė. Reikia namų kompiuterio su Chrome/Edge.'],
    ];
}

function keyword_list(string $keywords): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R|\|/', $keywords)), fn($k) => $k !== ''));
}

function keyword_found(string $text, string $keywords, bool $all = false): bool
{
    $list = keyword_list($keywords);
    if (!$list) {
        return false;
    }
    foreach ($list as $k) {
        $hit = mb_stripos($text, $k) !== false;
        if ($all && !$hit) {
            return false;   // IR: turi būti visi
        }
        if (!$all && $hit) {
            return true;    // ARBA: pakanka vieno
        }
    }
    return $all;            // IR: visi rasti
}

/** Pritaiko „ištraukimo“ reguliarią išraišką: palieka tik atitikmenis (1-ą grupę). */
function apply_extract(string $text, string $regex): string
{
    $regex = trim($regex);
    if ($regex === '') {
        return $text;
    }
    $pattern = '~' . str_replace('~', '\~', $regex) . '~u';
    if (@preg_match_all($pattern, $text, $m) && !empty($m[0])) {
        $out = !empty($m[1]) && count(array_filter($m[1], fn($x) => $x !== '')) ? $m[1] : $m[0];
        return implode("\n", $out);
    }
    return ''; // nieko nerado – tuščia (bus matoma kaip „nerasta“)
}

/**
 * Patikrina vieną stebėjimą, įrašo rezultatą ir (jei reikia) išsiunčia pranešimą.
 */
function run_check(array $w, bool $sendNotify = true): array
{
    $now = time();
    if (($w['compare_mode'] ?? '') === 'visual') {
        return run_visual_check($w, $sendNotify); // atskiras kelias – ekrano nuotraukos
    }
    $fetch = fetch_for_watch($w);
    if (!$fetch['ok']) {
        return record_failure($w, $fetch['error'], $sendNotify);
    }
    $ex = extract_content($fetch['body'], $w);
    if (!$ex['ok']) {
        return record_failure($w, $ex['error'], $sendNotify);
    }

    $new = mb_substr($ex['content'], 0, WW_MAX_CONTENT);
    // Ištraukimo reguliari išraiška (pvz. iš teksto palikti tik kainą)
    if (trim((string)($w['extract_regex'] ?? '')) !== '') {
        $new = apply_extract($new, (string)$w['extract_regex']);
    }
    $old = $w['last_content'];
    $first = $old === null;
    $mode = (string)$w['compare_mode'];
    $threshold = (float)$w['threshold'];
    $kwAll = !empty($w['keyword_all']);

    $changed = false;
    $updateBaseline = true;
    $summary = '';
    $pct = 0.0;

    switch ($mode) {
        case 'keyword_appear':
        case 'keyword_disappear':
            $present = keyword_found(comparable_text($new, $w), (string)$w['keyword'], $kwAll);
            $prev = $first ? null : keyword_found(comparable_text((string)$old, $w), (string)$w['keyword'], $kwAll);
            if ($mode === 'keyword_appear' && $present && $prev !== true) {
                $changed = true;
                $summary = 'Atsirado: „' . trim((string)$w['keyword']) . '“';
            } elseif ($mode === 'keyword_disappear' && !$present && $prev !== false) {
                $changed = true;
                $summary = 'Dingo: „' . trim((string)$w['keyword']) . '“';
            }
            $pct = $changed ? 100 : 0;
            break;

        case 'number':
            $n = parse_number($new);
            if ($n === null) {
                return record_failure($w, 'Elemente nerastas skaičius', $sendNotify);
            }
            $valueHistory = append_value_history((string)($w['value_history'] ?? ''), $now, $n);
            $o = $first ? null : parse_number((string)$old);
            if ($o !== null && abs($n - $o) > 0.000001) {
                $rel = $o != 0.0 ? ($n - $o) / abs($o) * 100 : 100.0;
                $dirOk = $w['number_dir'] === 'any'
                    || ($w['number_dir'] === 'down' && $n < $o)
                    || ($w['number_dir'] === 'up' && $n > $o);
                if ($dirOk && abs($rel) >= $threshold) {
                    $changed = true;
                    $pct = round(abs($rel), 2);
                    $summary = format_number($o) . ' → ' . format_number($n)
                        . ' (' . ($rel > 0 ? '+' : '−') . number_format(abs($rel), 1, ',', '') . '%)';
                } elseif ($dirOk) {
                    // per mažas pokytis – kaupiame nuo senos reikšmės
                    $updateBaseline = false;
                }
            }
            break;

        default: // text, added, html
            if ($first) {
                break;
            }
            $ops = line_diff(comparable_text((string)$old, $w), comparable_text($new, $w));
            if ($mode === 'added') {
                $kw = (string)$w['keyword'];
                $ops = array_values(array_filter($ops, fn($op) => $op[0] === '='
                    || ($op[0] === '+' && (trim($kw) === '' || keyword_found($op[1], $kw)))));
            }
            $pct = change_percent($ops);
            if ($pct > 0) {
                if ($pct >= $threshold) {
                    $changed = true;
                    $summary = diff_summary($ops);
                } elseif ($threshold > 0) {
                    $updateBaseline = false;
                }
            }
    }

    // Papildoma sąlyga: pranešti tik jei naujas turinys atitinka nurodytą išraišką/žodžius.
    if ($changed && trim((string)($w['require_regex'] ?? '')) !== '') {
        $req = trim((string)$w['require_regex']);
        $pat = '~' . str_replace('~', '\~', $req) . '~ui';
        $matches = @preg_match($pat, $new);
        if ($matches === false) { // ne regex – tada kaip žodis/frazė
            $matches = mb_stripos($new, $req) !== false ? 1 : 0;
        }
        if (!$matches) {
            $changed = false;      // pokytis yra, bet neatitinka sąlygos – tyliai atnaujinam
            $updateBaseline = true;
        }
    }

    $db = db();
    $fields = 'last_check = ?, last_status = ?, last_error = \'\', fail_count = 0, fail_since = 0';
    $params = [$now, 'ok'];
    if (isset($valueHistory)) {
        $fields .= ', value_history = ?';
        $params[] = $valueHistory;
    }
    if ($updateBaseline || $first) {
        $fields .= ', last_content = ?';
        $params[] = $new;
    }
    if ($changed) {
        $fields .= ', last_change = ?, unseen = unseen + 1';
        $params[] = $now;
        db_write('INSERT INTO changes (watch_id, created, old_content, new_content, summary, change_pct) VALUES (?, ?, ?, ?, ?, ?)',
            [$w['id'], $now, $old, $new, $summary, $pct]);
        // Įterptas SELECT su papildomu lygmeniu – kad veiktų ir MySQL (jis neleidžia LIMIT tiesiai IN viduje)
        db_write('DELETE FROM changes WHERE watch_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM changes WHERE watch_id = ? ORDER BY id DESC LIMIT ' . WW_KEEP_CHANGES . ') keep)',
            [$w['id'], $w['id']]);
    }
    $params[] = $w['id'];
    db_write("UPDATE watches SET $fields WHERE id = ?", $params);

    $sent = null;
    if ($sendNotify && !$changed) {
        notify_recovered($w);
    }
    if ($changed && $sendNotify) {
        $sent = notify_user(
            (string)$w['notify'],
            '🔔 ' . watch_title($w),
            $summary !== '' ? $summary : 'Puslapis pasikeitė',
            app_url() . '?view=watch&id=' . $w['id'],
            'watch-' . $w['id']
        );
    }
    return [
        'ok' => true,
        'changed' => $changed,
        'first' => $first,
        'summary' => $summary,
        'pct' => $pct,
        'count' => $ex['count'],
        'notified' => $sent,
    ];
}

function record_failure(array $w, string $error, bool $sendNotify): array
{
    $now = time();
    $fails = (int)$w['fail_count'] + 1;
    $since = $fails === 1 || empty($w['fail_since']) ? $now : (int)$w['fail_since'];
    db_write("UPDATE watches SET last_check = ?, last_status = 'error', last_error = ?, fail_count = ?, fail_since = ? WHERE id = ?",
        [$now, $error, $fails, $since, $w['id']]);
    $limit = fails_before_alert();
    if ($sendNotify && $fails === $limit) {
        $w2 = $w;
        $w2['fail_count'] = $fails;
        $next = effective_interval_min($w2);
        $body = 'Nepavyko patikrinti ' . $fails . ' kartus iš eilės'
            . ($now - $since >= 60 ? ' (pirmą kartą prieš ' . human_duration($now - $since) . ')' : '') . ".\n"
            . 'Klaida: ' . $error . "\n"
            . 'Toliau bandysiu kas ' . $next . ' min ir pranešiu, kai vėl pavyks.';
        notify_user(alert_channels($w), '⚠️ ' . watch_title($w) . ': nepavyksta patikrinti', $body,
            app_url() . '?view=watch&id=' . $w['id'], 'watch-err-' . $w['id']);
    }
    return ['ok' => false, 'changed' => false, 'error' => $error, 'fails' => $fails];
}

/** Stebėjimai, kuriuos laikas tikrinti. */
function due_watches(): array
{
    // Intervalas su pakartojimais po nesėkmės – tokia pati logika kaip effective_interval_min()
    $lim = fails_before_alert();
    $iv = 'CASE WHEN fail_count <= 0 THEN interval_min'
        . ' WHEN fail_count < ' . $lim . ' THEN (CASE WHEN interval_min < ' . WW_RETRY_MIN . ' THEN interval_min ELSE ' . WW_RETRY_MIN . ' END)'
        . ' ELSE (CASE WHEN interval_min < ' . WW_DOWN_RECHECK_MIN . ' THEN interval_min ELSE ' . WW_DOWN_RECHECK_MIN . ' END) END';
    $st = db()->prepare("SELECT * FROM watches WHERE active = 1 AND (last_check IS NULL OR last_check + ($iv) * 60 - 30 <= ?) ORDER BY last_check IS NOT NULL, last_check");
    $st->bindValue(1, time(), PDO::PARAM_INT); // kitaip SQLite lygina kaip tekstą
    $st->execute();
    return array_values(array_filter($st->fetchAll(), 'watch_in_schedule'));
}

/** Ar dabar stebėjimo tvarkaraščio laikas (tuščias tvarkaraštis = visada). */
function watch_in_schedule(array $w, ?int $ts = null): bool
{
    $ts = $ts ?? time();
    $days = trim((string)($w['sched_days'] ?? ''));
    if ($days !== '') {
        $dow = (int)date('N', $ts); // 1 (pirmadienis) – 7 (sekmadienis)
        if (!in_array((string)$dow, array_map('trim', explode(',', $days)), true)) {
            return false;
        }
    }
    $from = (string)($w['sched_from'] ?? '');
    $to = (string)($w['sched_to'] ?? '');
    if (preg_match('/^\d{1,2}:\d{2}$/', $from) && preg_match('/^\d{1,2}:\d{2}$/', $to) && $from !== $to) {
        $toMin = fn($s) => (int)explode(':', $s)[0] * 60 + (int)explode(':', $s)[1];
        $now = (int)date('G', $ts) * 60 + (int)date('i', $ts);
        $f = $toMin($from);
        $t = $toMin($to);
        $in = $f < $t ? ($now >= $f && $now < $t) : ($now >= $f || $now < $t);
        if (!$in) {
            return false;
        }
    }
    return true;
}

/** Skaičių istorija grafikui: JSON [[laikas, reikšmė], ...], įrašoma tik pasikeitus. */
function append_value_history(string $json, int $ts, float $value): string
{
    $h = json_decode($json, true);
    $h = is_array($h) ? $h : [];
    $last = end($h);
    if (!$last || abs((float)$last[1] - $value) > 0.000001) {
        $h[] = [$ts, $value];
    }
    return json_encode(array_slice($h, -300));
}
