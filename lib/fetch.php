<?php
declare(strict_types=1);

/**
 * Parsiunčia puslapį. Grąžina ['ok', 'status', 'body', 'final_url', 'error'].
 * body visada konvertuojamas į UTF-8.
 */
function fetch_url(string $url, int $timeout = 25): array
{
    $res = ['ok' => false, 'status' => 0, 'body' => '', 'final_url' => $url, 'error' => ''];

    if (!preg_match('~^https?://~i', $url)) {
        $res['error'] = 'Adresas turi prasidėti http:// arba https://';
        return $res;
    }
    if (!function_exists('curl_init')) {
        $res['error'] = 'Serveryje nėra PHP cURL plėtinio';
        return $res;
    }

    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: lt-LT,lt;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $res['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $res['final_url'] = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    if ($body === false) {
        $res['error'] = 'Nepavyko prisijungti: ' . curl_error($ch);
        curl_close($ch);
        return $res;
    }
    curl_close($ch);

    if ($res['status'] >= 400) {
        $res['error'] = 'Svetainė grąžino HTTP ' . $res['status'];
        $res['body'] = (string)$body;
        return $res;
    }
    if (strlen($body) > 8 * 1024 * 1024) {
        $body = substr($body, 0, 8 * 1024 * 1024);
    }

    $res['body'] = to_utf8((string)$body, $headers['content-type'] ?? '');
    $res['ok'] = true;
    return $res;
}

function to_utf8(string $body, string $contentType): string
{
    // BOM
    if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
        return substr($body, 3);
    }
    $charset = '';
    if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
        $charset = $m[1];
    }
    $charset = strtoupper($charset);
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') {
        if (mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }
        $charset = 'WINDOWS-1257';
    }
    try {
        $converted = mb_convert_encoding($body, 'UTF-8', $charset);
    } catch (Throwable $e) {
        $converted = false;
    }
    if ($converted === false || $converted === '') {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
    }
    return $converted !== false && $converted !== '' ? $converted : mb_convert_encoding($body, 'UTF-8', 'WINDOWS-1252');
}
