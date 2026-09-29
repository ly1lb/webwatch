<?php
declare(strict_types=1);

/*
 * Web Push be išorinių bibliotekų: VAPID (RFC 8292) + aes128gcm šifravimas (RFC 8291).
 * Veikia su iPhone (iOS 16.4+, programa pridėta į pradžios ekraną), Android Chrome, desktop naršyklėmis.
 */

function b64u_enc(string $d): string
{
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

function b64u_dec(string $d): string
{
    $d = strtr(trim($d), '-_', '+/');
    return (string)base64_decode($d . str_repeat('=', (4 - strlen($d) % 4) % 4));
}

function ec_new_key()
{
    $cfg = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    $key = openssl_pkey_new($cfg);
    if (!$key) {
        throw new RuntimeException('Nepavyko sugeneruoti EC rakto (OpenSSL): ' . openssl_error_string());
    }
    return $key;
}

function ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function p256_public_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Grąžina ['public' => base64url, 'private' => PEM]. Sugeneruoja pirmą kartą. */
function vapid_keys(): array
{
    $priv = setting('vapid_private');
    $pub = setting('vapid_public');
    if (!$priv || !$pub) {
        $key = ec_new_key();
        openssl_pkey_export($key, $priv);
        $pub = b64u_enc(ec_public_raw($key));
        set_setting('vapid_private', $priv);
        set_setting('vapid_public', $pub);
    }
    return ['public' => $pub, 'private' => $priv];
}

/** DER ECDSA parašą paverčia į 64 baitų r||s. */
function ecdsa_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $pos++; // 0x02
    $rl = ord($der[$pos++]);
    $r = substr($der, $pos, $rl);
    $pos += $rl;
    $pos++; // 0x02
    $sl = ord($der[$pos++]);
    $s = substr($der, $pos, $sl);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $r . $s;
}

function vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $subject = setting('vapid_subject') ?: (setting('email_to') ? 'mailto:' . setting('email_to') : rtrim(app_url(), '/'));
    if (!preg_match('~^(mailto:|https://)~', $subject)) {
        $subject = 'mailto:webwatch@' . (parse_url(app_url(), PHP_URL_HOST) ?: 'example.com');
    }
    $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    $pk = openssl_pkey_get_private($keys['private']);
    if (!openssl_sign("$h.$c", $sig, $pk, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Nepavyko pasirašyti VAPID JWT');
    }
    return 'vapid t=' . "$h.$c." . b64u_enc(ecdsa_der_to_raw($sig)) . ', k=' . $keys['public'];
}

/**
 * Užšifruoja turinį pagal RFC 8291 (aes128gcm).
 * $uaPublic – 65 baitų gavėjo raktas (p256dh), $authSecret – 16 baitų.
 */
function webpush_encrypt(string $payload, string $uaPublic, string $authSecret, $ephemeral = null, ?string $salt = null): string
{
    if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
        throw new InvalidArgumentException('Neteisingi prenumeratos raktai');
    }
    $eph = $ephemeral ?: ec_new_key();
    $asPublic = ec_public_raw($eph);
    $peer = openssl_pkey_get_public(p256_public_pem($uaPublic));
    if (!$peer) {
        throw new RuntimeException('Neteisingas gavėjo viešasis raktas');
    }
    $shared = openssl_pkey_derive($peer, $eph);
    if ($shared === false || strlen($shared) !== 32) {
        throw new RuntimeException('ECDH nepavyko: ' . openssl_error_string());
    }
    $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
    $salt = $salt ?? random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('AES-GCM šifravimas nepavyko');
    }
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/**
 * Išsiunčia vieną push pranešimą. Grąžina ['ok', 'status', 'error', 'gone'].
 */
function webpush_send(array $sub, array $message): array
{
    $res = ['ok' => false, 'status' => 0, 'error' => '', 'gone' => false];
    try {
        $body = webpush_encrypt(
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            b64u_dec($sub['p256dh']),
            b64u_dec($sub['auth'])
        );
        $auth = vapid_header($sub['endpoint']);
    } catch (Throwable $e) {
        $res['error'] = $e->getMessage();
        return $res;
    }

    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            'Authorization: ' . $auth,
        ],
    ]);
    $out = curl_exec($ch);
    $res['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($out === false) {
        $res['error'] = curl_error($ch);
    }
    curl_close($ch);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        $res['ok'] = true;
    } else {
        $res['gone'] = in_array($res['status'], [404, 410], true);
        if ($res['error'] === '') {
            $res['error'] = 'HTTP ' . $res['status'] . ($out ? ': ' . mb_substr(strip_tags((string)$out), 0, 200) : '');
        }
    }
    return $res;
}

/**
 * Siunčia visoms prenumeratoms. Grąžina pristatytų skaičių.
 */
function webpush_broadcast(array $message): int
{
    $subs = db()->query('SELECT * FROM subscriptions')->fetchAll();
    $sent = 0;
    foreach ($subs as $s) {
        $r = webpush_send($s, $message);
        if ($r['ok']) {
            $sent++;
            db()->prepare("UPDATE subscriptions SET last_ok = ?, last_error = '' WHERE id = ?")->execute([time(), $s['id']]);
        } elseif ($r['gone']) {
            db()->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$s['id']]);
            ww_log('info', 'Push prenumerata pašalinta (nebegalioja): ' . $s['label']);
        } else {
            db()->prepare('UPDATE subscriptions SET last_error = ? WHERE id = ?')->execute([$r['error'], $s['id']]);
            ww_log('error', 'Push klaida (' . $s['label'] . '): ' . $r['error']);
        }
    }
    return $sent;
}
