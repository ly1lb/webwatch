<?php
declare(strict_types=1);

/*
 * Periodinis tikrinimas. Hostinger → Advanced → Cron Jobs:
 *   PHP komanda:  /usr/bin/php /home/uXXXX/domains/JUSU-DOMENAS/public_html/cron.php
 *   arba URL:     https://jusu-subdomenas/cron.php?token=SLAPTAS_RAKTAS
 * Rekomenduojamas dažnis – kas 5 minutes.
 */

require __DIR__ . '/lib/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $token = (string)($_GET['token'] ?? '');
    if ($token === '' || !hash_equals((string)setting('cron_token', ''), $token)) {
        http_response_code(403);
        exit("Neteisingas token\n");
    }
    ignore_user_abort(true);
}

@set_time_limit(290);
$started = time();
$maxRuntime = (int)(defined('WW_CRON_MAX_SECONDS') ? WW_CRON_MAX_SECONDS : 240);

$lock = fopen(WW_DATA . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Kitas tikrinimas dar vyksta\n");
}

set_setting('cron_last_run', (string)$started);
$flushed = flush_queue();
if ($flushed) {
    echo "Išsiųsti atidėti pranešimai: $flushed\n";
}
try {
    agents_health_check(); // pranešti, jei namų kompiuteris atsijungė / vėl prisijungė
} catch (Throwable $e) {
    ww_log('error', 'Kompiuterių būsenos patikra: ' . $e->getMessage());
}
$watches = due_watches();
$checked = 0;
$changed = 0;
foreach ($watches as $w) {
    if (time() - $started > $maxRuntime) {
        echo "Laiko limitas – likę bus patikrinti kitą kartą\n";
        break;
    }
    try {
        $r = run_check($w);
        $checked++;
        if (!empty($r['changed'])) {
            $changed++;
        }
        echo date('H:i:s') . ' ' . watch_title($w) . ': ' . ($r['ok'] ? ($r['changed'] ? 'PASIKEITĖ' : 'be pokyčių') : 'KLAIDA ' . $r['error']) . "\n";
    } catch (Throwable $e) {
        ww_log('error', 'Cron (' . watch_title($w) . '): ' . $e->getMessage());
        echo 'KLAIDA ' . watch_title($w) . ': ' . $e->getMessage() . "\n";
    }
}
echo "Patikrinta: $checked, pasikeitė: $changed\n";
try {
    $hk = ww_housekeeping(); // kartą per parą: sena istorija, nuotraukos, sesijos, žurnalas
    if ($hk !== null) {
        echo 'Valymas: atlaisvinta ' . ww_bytes($hk['freed']) . "\n";
    }
} catch (Throwable $e) {
    ww_log('error', 'Valymas: ' . $e->getMessage());
}
flock($lock, LOCK_UN);
fclose($lock);
