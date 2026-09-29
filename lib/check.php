<?php
declare(strict_types=1);

const WW_MAX_CONTENT = 300000;
const WW_KEEP_CHANGES = 50;
const WW_FAILS_BEFORE_ALERT = 3;

function compare_modes(): array
{
    return [
        'text' => ['Bet koks teksto pakeitimas', 'Praneš, kai pasikeis matomas tekstas.'],
        'added' => ['Tik nauji įrašai (naujienoms)', 'Praneš tik kai atsiranda naujų eilučių – pašalinimai ignoruojami.'],
        'keyword_appear' => ['Kai atsiras žodis / frazė', 'Pvz. „Yra sandėlyje“, „Registracija atidaryta“.'],
        'keyword_disappear' => ['Kai dings žodis / frazė', 'Pvz. „Išparduota“, „Nėra prekyboje“.'],
        'number' => ['Skaičius / kaina pasikeitė', 'Stebi pirmą skaičių elemente (pvz. kainą).'],
        'html' => ['HTML kodas (tiksliausia)', 'Mato ir atributų, nuorodų, paveikslėlių pokyčius.'],
    ];
}

function notify_modes(): array
{
    return [
        'auto' => 'Push, o jei nepavyksta – el. paštu',
        'push' => 'Tik push pranešimas',
        'email' => 'Tik el. paštas',
        'both' => 'Push ir el. paštas',
        'none' => 'Nesiųsti (tik istorija)',
    ];
}

function keyword_found(string $text, string $keywords): bool
{
    foreach (preg_split('/\R|\|/', $keywords) as $k) {
        $k = trim($k);
        if ($k !== '' && mb_stripos($text, $k) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Patikrina vieną stebėjimą, įrašo rezultatą ir (jei reikia) išsiunčia pranešimą.
 */
function run_check(array $w, bool $sendNotify = true): array
{
    $now = time();
    $fetch = fetch_url((string)$w['url']);
    if (!$fetch['ok']) {
        return record_failure($w, $fetch['error'], $sendNotify);
    }
    $ex = extract_content($fetch['body'], $w);
    if (!$ex['ok']) {
        return record_failure($w, $ex['error'], $sendNotify);
    }

    $new = mb_substr($ex['content'], 0, WW_MAX_CONTENT);
    $old = $w['last_content'];
    $first = $old === null;
    $mode = (string)$w['compare_mode'];
    $threshold = (float)$w['threshold'];

    $changed = false;
    $updateBaseline = true;
    $summary = '';
    $pct = 0.0;

    switch ($mode) {
        case 'keyword_appear':
        case 'keyword_disappear':
            $present = keyword_found(comparable_text($new, $w), (string)$w['keyword']);
            $prev = $first ? null : keyword_found(comparable_text((string)$old, $w), (string)$w['keyword']);
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
                $ops = array_values(array_filter($ops, fn($op) => $op[0] !== '-'));
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

    $db = db();
    $fields = 'last_check = ?, last_status = ?, last_error = \'\', fail_count = 0';
    $params = [$now, 'ok'];
    if ($updateBaseline || $first) {
        $fields .= ', last_content = ?';
        $params[] = $new;
    }
    if ($changed) {
        $fields .= ', last_change = ?, unseen = unseen + 1';
        $params[] = $now;
        $db->prepare('INSERT INTO changes (watch_id, created, old_content, new_content, summary, change_pct) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$w['id'], $now, $old, $new, $summary, $pct]);
        $db->prepare('DELETE FROM changes WHERE watch_id = ? AND id NOT IN (SELECT id FROM changes WHERE watch_id = ? ORDER BY id DESC LIMIT ' . WW_KEEP_CHANGES . ')')
            ->execute([$w['id'], $w['id']]);
    }
    $params[] = $w['id'];
    $db->prepare("UPDATE watches SET $fields WHERE id = ?")->execute($params);

    $sent = null;
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
    $fails = (int)$w['fail_count'] + 1;
    db()->prepare("UPDATE watches SET last_check = ?, last_status = 'error', last_error = ?, fail_count = ? WHERE id = ?")
        ->execute([time(), $error, $fails, $w['id']]);
    if ($sendNotify && $fails === WW_FAILS_BEFORE_ALERT && $w['notify'] !== 'none') {
        notify_user(
            (string)$w['notify'],
            '⚠️ ' . watch_title($w) . ': nepavyksta patikrinti',
            $error . "\nBandyta " . $fails . ' kartus iš eilės.',
            app_url() . '?view=watch&id=' . $w['id'],
            'watch-err-' . $w['id']
        );
    }
    return ['ok' => false, 'changed' => false, 'error' => $error, 'fails' => $fails];
}

/** Stebėjimai, kuriuos laikas tikrinti. */
function due_watches(): array
{
    $st = db()->prepare('SELECT * FROM watches WHERE active = 1 AND (last_check IS NULL OR last_check + interval_min * 60 - 30 <= ?) ORDER BY last_check IS NOT NULL, last_check');
    $st->bindValue(1, time(), PDO::PARAM_INT); // kitaip SQLite lygina kaip tekstą
    $st->execute();
    return $st->fetchAll();
}
