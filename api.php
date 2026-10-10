<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_logged_in()) {
    out(['ok' => false, 'error' => 'Neprisijungta'], 401);
}
$action = (string)($_GET['action'] ?? '');
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_SERVER['HTTP_X_CSRF'] ?? null)) {
    out(['ok' => false, 'error' => 'Neteisinga užklausa (CSRF)'], 400);
}
remember_app_url();
// Atleidžiam sesijos užraktą, kad ilgas tikrinimas (per namų kompiuterį) neužstabdytų kitų langų
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
@set_time_limit(180);

try {
    switch ($action) {
        case 'subscribe':
            $sub = $input['subscription'] ?? [];
            $endpoint = (string)($sub['endpoint'] ?? '');
            $p256 = (string)($sub['keys']['p256dh'] ?? '');
            $auth = (string)($sub['keys']['auth'] ?? '');
            if (!preg_match('~^https://~', $endpoint) || $p256 === '' || $auth === '') {
                out(['ok' => false, 'error' => 'Neteisinga prenumerata']);
            }
            $label = mb_substr(trim((string)($input['label'] ?? '')), 0, 80) ?: 'Įrenginys';
            db_upsert('subscriptions', ['endpoint'],
                ['endpoint' => $endpoint, 'p256dh' => $p256, 'auth' => $auth, 'label' => $label, 'created' => time()]);
            out(['ok' => true]);

        case 'unsubscribe':
            db()->prepare('DELETE FROM subscriptions WHERE endpoint = ?')->execute([(string)($input['endpoint'] ?? '')]);
            out(['ok' => true]);

        case 'test_push':
            $n = webpush_broadcast([
                'title' => '✅ WebWatch veikia',
                'body' => 'Bandomasis pranešimas ' . date('H:i:s'),
                'url' => app_url(),
                'tag' => 'test',
            ]);
            $total = (int)db()->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
            out(['ok' => $n > 0, 'sent' => $n, 'total' => $total,
                'error' => $n > 0 ? '' : ($total ? 'Nepavyko pristatyti (žr. žurnalą nustatymuose)' : 'Nėra užregistruotų įrenginių')]);

        case 'test_email':
            $r = send_mail((string)setting('email_to', ''), '✅ WebWatch bandomasis laiškas',
                "Jei matote šį laišką – el. pašto pranešimai veikia.\n\n" . app_url());
            out($r);

        case 'test_extract':
            $w = [
                'url' => trim((string)($input['url'] ?? '')),
                'selector' => trim((string)($input['selector'] ?? '')),
                'compare_mode' => (string)($input['compare_mode'] ?? 'text'),
                'ignore_numbers' => !empty($input['ignore_numbers']),
                'ignore_regex' => (string)($input['ignore_regex'] ?? ''),
                'keyword' => (string)($input['keyword'] ?? ''),
                'headers' => (string)($input['headers'] ?? ''),
                'user_agent' => (string)($input['user_agent'] ?? 'mobile'),
                'render_js' => !empty($input['render_js']),
                'check_from' => in_array($input['check_from'] ?? '', ['server', 'auto', 'agent'], true) ? $input['check_from'] : (agents_all() ? 'auto' : 'server'),
                'extract_regex' => (string)($input['extract_regex'] ?? ''),
                'keyword_all' => !empty($input['keyword_all']),
            ];
            if (!preg_match('~^https?://~i', $w['url'])) {
                out(['ok' => false, 'error' => 'Įrašykite adresą (https://...)']);
            }
            $f = fetch_for_watch($w, false);
            if (!$f['ok']) {
                out(['ok' => false, 'error' => $f['error']]);
            }
            $ex = extract_content($f['body'], $w);
            if (!$ex['ok']) {
                out(['ok' => false, 'error' => $ex['error']]);
            }
            $content = $ex['content'];
            if (trim((string)$w['extract_regex']) !== '') {
                $content = apply_extract($content, (string)$w['extract_regex']);
            }
            $cmp = comparable_text($content, $w);
            $info = '';
            if ($w['compare_mode'] === 'number') {
                $n = parse_number($content);
                $info = $n === null ? 'Skaičius nerastas!' : 'Rastas skaičius: ' . format_number($n);
            } elseif (str_starts_with($w['compare_mode'], 'keyword')) {
                $info = keyword_found($cmp, $w['keyword'], !empty($w['keyword_all'])) ? 'Frazė šiuo metu RASTA' : 'Frazė šiuo metu NERASTA';
            } elseif ($w['compare_mode'] === 'added' && trim($w['keyword']) !== '') {
                $n = count(array_filter(explode("\n", $cmp), fn($l) => keyword_found($l, $w['keyword'])));
                $info = "Eilučių su filtru: $n";
            }
            $title = '';
            if (preg_match('~<title[^>]*>(.*?)</title>~is', $f['body'], $m)) {
                $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            out([
                'ok' => true,
                'count' => $ex['count'],
                'length' => mb_strlen($cmp),
                'content' => mb_substr($cmp, 0, 4000),
                'info' => $info,
                'title' => mb_substr($title, 0, 120),
                'via' => $f['via'] === 'direct' ? '' : (WW_VIA_LABELS[$f['via']] ?? ''),
            ]);

        case 'check_now':
            $w = get_watch((int)($input['id'] ?? 0));
            if (!$w) {
                out(['ok' => false, 'error' => 'Nerasta']);
            }
            $r = run_check($w, true);
            out($r);

        case 'bypass_test':
            // Bandoma iš eilės iki pirmo pasisekusio būdo (paslaugos kreditai neeikvojami be reikalo)
            $url = trim((string)($input['url'] ?? ''));
            if (!preg_match('~^https?://~i', $url)) {
                out(['ok' => false, 'error' => 'Įrašykite adresą (https://...)']);
            }
            $steps = [
                'direct' => fn() => fetch_url($url, 25, [], 'mobile'),
                'direct-alt' => fn() => fetch_url($url, 25, [], 'desktop', 1, true),
            ];
            if (reader_enabled()) {
                $steps['reader'] = fn() => fetch_reader($url);
            }
            if (service_url($url) !== '') {
                $steps['service'] = fn() => fetch_for_watch(['url' => $url, 'render_js' => 1], false);
            }
            $rows = [];
            $okVia = '';
            foreach ($steps as $via => $fn) {
                $r = $fn();
                $title = preg_match('~<title[^>]*>(.*?)</title>~is', $r['body'], $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
                $rows[] = [
                    'via' => WW_VIA_LABELS[$via],
                    'ok' => $r['ok'],
                    'status' => $r['status'],
                    'detail' => $r['ok'] ? mb_substr($title ?: number_format(strlen($r['body'])) . ' baitų', 0, 80) : ($r['blocked'] ? 'užblokuota' : $r['error']),
                ];
                if ($r['ok']) {
                    $okVia = WW_VIA_LABELS[$via];
                    break;
                }
            }
            if (!$okVia && service_url($url) === '') {
                $rows[] = ['via' => WW_VIA_LABELS['service'], 'ok' => false, 'status' => 0, 'detail' => 'nesukonfigūruota – įrašykite API raktą aukščiau'];
            }
            out(['ok' => true, 'rows' => $rows, 'works' => $okVia]);

        case 'agent_test':
            // Išbando puslapį tik per namų kompiuterius (su perdavimu kitam)
            $url = trim((string)($input['url'] ?? ''));
            if (!preg_match('~^https?://~i', $url)) {
                out(['ok' => false, 'error' => 'Įrašykite adresą (https://...)']);
            }
            if (!agents_all()) {
                out(['ok' => false, 'error' => 'Pirmiausia pridėkite bent vieną kompiuterį']);
            }
            $r = agent_fetch($url, [], 'desktop', false);
            $title = preg_match('~<title[^>]*>(.*?)</title>~is', $r['body'], $m)
                ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            out([
                'ok' => $r['ok'],
                'agent' => $r['agent'] ?? '',
                'status' => $r['status'],
                'detail' => $r['ok'] ? mb_substr($title ?: number_format(strlen($r['body'])) . ' baitų', 0, 90) : $r['error'],
                'online' => count(agents_online()),
                'total' => count(agents_all()),
            ]);

        case 'diff':
            $st = db()->prepare('SELECT c.*, w.ignore_numbers, w.ignore_regex FROM changes c JOIN watches w ON w.id = c.watch_id WHERE c.id = ?');
            $st->execute([(int)($input['id'] ?? 0)]);
            $c = $st->fetch();
            if (!$c) {
                out(['ok' => false, 'error' => 'Nerasta']);
            }
            out(['ok' => true, 'html' => diff_html(line_diff(comparable_text((string)unpack_text($c['old_content']), $c), comparable_text((string)unpack_text($c['new_content']), $c)))]);

        case 'test_channel':
            $ch = (string)($input['channel'] ?? '');
            $fn = ['telegram' => 'send_telegram', 'ntfy' => 'send_ntfy', 'webhook' => 'send_webhook'][$ch] ?? null;
            if (!$fn) {
                out(['ok' => false, 'error' => 'Nežinomas kanalas']);
            }
            $r = $fn('✅ WebWatch veikia', 'Bandomasis pranešimas ' . date('H:i:s'), app_url());
            out(['ok' => $r['ok'], 'error' => $r['error']]);

        case 'tg_chats':
            $token = trim((string)setting('tg_token', ''));
            if ($token === '') {
                out(['ok' => false, 'error' => 'Pirmiausia išsaugokite boto raktą']);
            }
            $raw = @file_get_contents("https://api.telegram.org/bot$token/getUpdates", false,
                stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]));
            $data = json_decode((string)$raw, true);
            if (empty($data['ok'])) {
                out(['ok' => false, 'error' => 'Telegram: ' . ($data['description'] ?? 'neteisingas raktas')]);
            }
            $chats = [];
            foreach ($data['result'] as $u) {
                $c = $u['message']['chat'] ?? $u['channel_post']['chat'] ?? $u['my_chat_member']['chat'] ?? null;
                if ($c) {
                    $chats[(string)$c['id']] = trim(($c['title'] ?? '') . ' ' . ($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: (string)$c['id'];
                }
            }
            if (!$chats) {
                out(['ok' => false, 'error' => 'Parašykite savo botui Telegram programėlėje (bet ką), tada bandykite vėl']);
            }
            if (count($chats) === 1) {
                set_setting('tg_chat', (string)array_key_first($chats));
            }
            out(['ok' => true, 'chats' => $chats, 'saved' => count($chats) === 1]);

        case 'toggle':
            $w = get_watch((int)($input['id'] ?? 0));
            if (!$w) {
                out(['ok' => false, 'error' => 'Nerasta']);
            }
            db()->prepare('UPDATE watches SET active = ? WHERE id = ?')->execute([$w['active'] ? 0 : 1, $w['id']]);
            out(['ok' => true, 'active' => !$w['active']]);

        default:
            out(['ok' => false, 'error' => 'Nežinomas veiksmas'], 404);
    }
} catch (Throwable $e) {
    ww_log('error', 'API ' . $action . ': ' . $e->getMessage());
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
