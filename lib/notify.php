<?php
declare(strict_types=1);

/*
 * Pranešimų kanalai: push, el. paštas, Telegram, ntfy, webhook (Discord/Slack/bet koks).
 * Stebėjimo `notify` laukas – kableliais atskirtas kanalų sąrašas, pvz. "push,fallback,telegram".
 *   fallback – el. laiškas tik tada, kai push nepavyko pristatyti nė vienam įrenginiui.
 */

const WW_CHANNELS = ['push', 'email', 'fallback', 'telegram', 'ntfy', 'webhook'];

function channel_labels(): array
{
    return [
        'push' => ['📱 Push pranešimas', ''],
        'fallback' => ['✉️ El. paštas, jei push nepavyko', ''],
        'email' => ['✉️ El. paštas visada', 'email_to'],
        'telegram' => ['✈️ Telegram', 'tg_token'],
        'ntfy' => ['🔔 ntfy', 'ntfy_topic'],
        'webhook' => ['🔗 Webhook (Discord, Slack…)', 'webhook_url'],
    ];
}

/** Paverčia `notify` reikšmę kanalų sąrašu (palaiko senas reikšmes). */
function parse_channels(string $notify): array
{
    $legacy = [
        'auto' => ['push', 'fallback'],
        'push' => ['push'],
        'email' => ['email'],
        'both' => ['push', 'email'],
        'none' => [],
        '' => [],
    ];
    if (isset($legacy[$notify])) {
        return $legacy[$notify];
    }
    return array_values(array_intersect(WW_CHANNELS, array_map('trim', explode(',', $notify))));
}

function is_quiet_now(?int $ts = null): bool
{
    $from = (string)setting('quiet_from', '');
    $to = (string)setting('quiet_to', '');
    if (!preg_match('/^\d{1,2}:\d{2}$/', $from) || !preg_match('/^\d{1,2}:\d{2}$/', $to) || $from === $to) {
        return false;
    }
    $toMin = fn($s) => (int)explode(':', $s)[0] * 60 + (int)explode(':', $s)[1];
    $now = (int)date('G', $ts ?? time()) * 60 + (int)date('i', $ts ?? time());
    $f = $toMin($from);
    $t = $toMin($to);
    return $f < $t ? ($now >= $f && $now < $t) : ($now >= $f || $now < $t);
}

/**
 * Išsiunčia pranešimą nurodytais kanalais. Tyliomis valandomis – atideda į eilę.
 */
function notify_user(string $notify, string $title, string $body, string $link, string $tag = 'webwatch', bool $allowDefer = true): array
{
    $channels = parse_channels($notify);
    $result = ['push' => 0, 'email' => false, 'deferred' => false, 'sent' => [], 'errors' => []];
    if (!$channels) {
        return $result;
    }
    if ($allowDefer && is_quiet_now()) {
        db()->prepare('INSERT INTO queue (created, channels, title, body, url, tag) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([time(), implode(',', $channels), $title, $body, $link, $tag]);
        $result['deferred'] = true;
        return $result;
    }

    if (in_array('push', $channels, true)) {
        $result['push'] = webpush_broadcast([
            'title' => $title,
            'body' => mb_substr($body, 0, 400),
            'url' => $link,
            'tag' => $tag,
            'badge' => unseen_total(),
        ]);
        if ($result['push'] > 0) {
            $result['sent'][] = 'push';
        }
    }
    $wantEmail = in_array('email', $channels, true)
        || (in_array('fallback', $channels, true) && $result['push'] === 0);
    if ($wantEmail) {
        $r = send_notification_email($title, $body, $link);
        $result['email'] = $r['ok'];
        if ($r['ok']) {
            $result['sent'][] = 'email';
        } else {
            $result['errors'][] = 'El. paštas: ' . $r['error'];
        }
    }
    foreach (['telegram' => 'send_telegram', 'ntfy' => 'send_ntfy', 'webhook' => 'send_webhook'] as $ch => $fn) {
        if (in_array($ch, $channels, true)) {
            $r = $fn($title, $body, $link);
            if ($r['ok']) {
                $result['sent'][] = $ch;
            } else {
                $result['errors'][] = ucfirst($ch) . ': ' . $r['error'];
            }
        }
    }
    foreach ($result['errors'] as $e) {
        ww_log('error', $e);
    }
    return $result;
}

/** Išsiunčia tyliomis valandomis sukauptus pranešimus. */
function flush_queue(): int
{
    if (is_quiet_now()) {
        return 0;
    }
    $items = db()->query('SELECT * FROM queue ORDER BY id')->fetchAll();
    if (!$items) {
        return 0;
    }
    db()->exec('DELETE FROM queue WHERE id <= ' . (int)end($items)['id']);
    if (count($items) <= 3) {
        foreach ($items as $it) {
            notify_user($it['channels'], $it['title'], $it['body'] . "\n(" . date('H:i', (int)$it['created']) . ')', $it['url'], $it['tag'], false);
        }
        return count($items);
    }
    $channels = [];
    $lines = [];
    foreach ($items as $it) {
        $channels = array_merge($channels, parse_channels($it['channels']));
        $lines[] = date('H:i', (int)$it['created']) . ' ' . $it['title'];
    }
    notify_user(implode(',', array_unique($channels)), '🔔 ' . count($items) . ' pokyčiai(-ų) per tylias valandas',
        implode("\n", array_slice($lines, 0, 15)), app_url(), 'digest', false);
    return count($items);
}

function unseen_total(): int
{
    return (int)db()->query('SELECT COALESCE(SUM(unseen), 0) FROM watches')->fetchColumn();
}

function send_notification_email(string $title, string $body, string $link): array
{
    $to = (string)setting('email_to', '');
    if ($to === '') {
        return ['ok' => false, 'error' => 'Nenurodytas el. paštas nustatymuose'];
    }
    $text = $body . "\n\n" . $link . "\n\n— WebWatch";
    $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:15px;color:#111">'
        . '<h2 style="margin:0 0 12px;font-size:18px">' . h($title) . '</h2>'
        . '<pre style="white-space:pre-wrap;font-family:inherit;background:#f4f4f6;padding:12px;border-radius:8px">' . h($body) . '</pre>'
        . '<p><a href="' . h($link) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Atidaryti WebWatch</a></p>'
        . '</div>';
    return send_mail($to, $title, $text, $html);
}

function http_post_json(string $url, array $data, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $out === false ? curl_error($ch) : '';
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'error' => '', 'body' => (string)$out];
    }
    return ['ok' => false, 'error' => $err ?: 'HTTP ' . $code . ' ' . mb_substr(strip_tags((string)$out), 0, 200), 'body' => (string)$out];
}

function send_telegram(string $title, string $body, string $link): array
{
    $token = trim((string)setting('tg_token', ''));
    $chat = trim((string)setting('tg_chat', ''));
    if ($token === '' || $chat === '') {
        return ['ok' => false, 'error' => 'Telegram nesukonfigūruotas'];
    }
    $text = '<b>' . h($title) . "</b>\n\n" . h(mb_substr($body, 0, 3500)) . "\n\n" . '<a href="' . h($link) . '">Atidaryti WebWatch</a>';
    return http_post_json("https://api.telegram.org/bot$token/sendMessage", [
        'chat_id' => $chat,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}

function send_ntfy(string $title, string $body, string $link): array
{
    $topic = trim((string)setting('ntfy_topic', ''));
    if ($topic === '') {
        return ['ok' => false, 'error' => 'ntfy nesukonfigūruotas'];
    }
    $server = rtrim(trim((string)setting('ntfy_server', '')) ?: 'https://ntfy.sh', '/');
    $headers = [];
    if (($t = trim((string)setting('ntfy_token', ''))) !== '') {
        $headers[] = 'Authorization: Bearer ' . $t;
    }
    return http_post_json($server, [
        'topic' => $topic,
        'title' => $title,
        'message' => mb_substr($body, 0, 3500),
        'click' => $link,
        'priority' => 4,
    ], $headers);
}

function send_webhook(string $title, string $body, string $link): array
{
    $url = trim((string)setting('webhook_url', ''));
    if (!preg_match('~^https?://~', $url)) {
        return ['ok' => false, 'error' => 'Webhook nesukonfigūruotas'];
    }
    $text = "**$title**\n$body\n$link";
    if (str_contains($url, 'discord.com/api/webhooks') || str_contains($url, 'discordapp.com/api/webhooks')) {
        $payload = ['content' => mb_substr($text, 0, 1900)];
    } elseif (str_contains($url, 'hooks.slack.com')) {
        $payload = ['text' => "*$title*\n$body\n<$link|Atidaryti>"];
    } else {
        $payload = ['title' => $title, 'body' => $body, 'url' => $link, 'time' => date('c')];
    }
    return http_post_json($url, $payload);
}
