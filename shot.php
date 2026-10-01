<?php
declare(strict_types=1);

/* Atiduoda vaizdinio stebėjimo ekrano nuotraukas (tik prisijungus). */

require __DIR__ . '/lib/bootstrap.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Neprisijungta');
}

$type = in_array($_GET['t'] ?? '', ['old', 'new', 'diff', 'current'], true) ? $_GET['t'] : '';
$file = null;

if ($type === 'current') {
    $wid = (int)($_GET['watch'] ?? 0);
    if (get_watch($wid)) {
        $file = shots_dir($wid) . '/current.png';
    }
} elseif ($type !== '') {
    $cid = (int)($_GET['change'] ?? 0);
    $st = db()->prepare('SELECT watch_id FROM changes WHERE id = ?');
    $st->execute([$cid]);
    $wid = (int)($st->fetchColumn() ?: 0);
    if ($wid) {
        $file = shots_dir($wid) . "/$cid-$type.png";
    }
}

if (!$file || !is_file($file)) {
    http_response_code(404);
    exit('Nerasta');
}
header('Content-Type: image/png');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
