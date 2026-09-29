<?php
declare(strict_types=1);

/*
 * El. laiškų siuntimas: per SMTP (rekomenduojama, pvz. smtp.hostinger.com) arba PHP mail().
 */

function send_mail(string $to, string $subject, string $text, string $html = ''): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Nenurodytas arba neteisingas gavėjo el. paštas'];
    }
    $from = trim((string)setting('email_from', ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $host = parse_url(app_url(), PHP_URL_HOST) ?: 'localhost';
        $from = 'webwatch@' . preg_replace('/^www\./', '', $host);
    }
    $fromName = 'WebWatch';
    $boundary = 'ww_' . bin2hex(random_bytes(8));
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [
        'Date: ' . date('r'),
        'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$from>",
        'To: <' . $to . '>',
        'Subject: ' . $encSubject,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'webwatch') . '>',
        'MIME-Version: 1.0',
        'X-Mailer: WebWatch',
    ];
    if ($html !== '') {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($text));
    }

    $smtpHost = trim((string)setting('smtp_host', ''));
    if ($smtpHost !== '') {
        return smtp_send($from, $to, implode("\r\n", $headers), $body);
    }

    // PHP mail(): To ir Subject perduodami atskirai
    $extra = array_filter($headers, fn($h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:'));
    $ok = @mail($to, $encSubject, $body, implode("\r\n", $extra), '-f' . $from);
    return ['ok' => $ok, 'error' => $ok ? '' : 'PHP mail() nepavyko (patikrinkite SMTP nustatymus)'];
}

function smtp_send(string $from, string $to, string $headers, string $body): array
{
    $host = trim((string)setting('smtp_host'));
    $port = (int)(setting('smtp_port') ?: 465);
    $secure = (string)(setting('smtp_secure') ?: ($port === 465 ? 'ssl' : 'tls'));
    $user = (string)setting('smtp_user', '');
    $pass = (string)setting('smtp_pass', '');

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return ['ok' => false, 'error' => "SMTP prisijungimas nepavyko: $errstr ($errno)"];
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c, array $expect) use ($fp, $read): string {
        if ($c !== '') {
            fwrite($fp, $c . "\r\n");
        }
        $r = $read();
        $code = (int)substr($r, 0, 3);
        if (!in_array($code, $expect, true)) {
            $shown = str_starts_with($c, 'AUTH') || strlen($c) > 60 ? '(auth)' : $c;
            throw new RuntimeException("SMTP klaida po „{$shown}“: " . trim($r));
        }
        return $r;
    };

    try {
        $cmd('', [220]);
        $ehloHost = parse_url(app_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $ehloHost, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Nepavyko įjungti TLS');
            }
            $cmd('EHLO ' . $ehloHost, [250]);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $data = $headers . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));
        $cmd($data . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        @fclose($fp);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
