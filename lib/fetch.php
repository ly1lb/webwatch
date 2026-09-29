<?php
declare(strict_types=1);

const WW_UA = [
    'mobile' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
    'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
];

/**
 * Parsiunčia stebėjimo puslapį pagal jo nustatymus (antraštės, naršyklė, JS atvaizdavimas).
 */
function fetch_for_watch(array $w): array
{
    $url = (string)$w['url'];
    $template = trim((string)setting('render_api', ''));
    if (!empty($w['render_js']) && $template !== '') {
        if (strpos($template, '{url}') === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'final_url' => $url, 'error' => 'JS atvaizdavimo adrese nėra {url}'];
        }
        $r = fetch_url(str_replace('{url}', rawurlencode($url), $template), 90);
        $r['final_url'] = $url;
        return $r;
    }
    return fetch_url($url, 25, parse_header_lines((string)($w['headers'] ?? '')), (string)($w['user_agent'] ?? 'mobile'));
}

/** „Pavadinimas: reikšmė“ eilutės -> cURL antraščių masyvas. */
function parse_header_lines(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line !== '' && preg_match('/^[A-Za-z0-9-]+\s*:/', $line)) {
            $out[] = $line;
        }
    }
    return $out;
}

/**
 * Parsiunčia puslapį. Grąžina ['ok', 'status', 'body', 'final_url', 'error'].
 * body visada konvertuojamas į UTF-8.
 */
function fetch_url(string $url, int $timeout = 25, array $extraHeaders = [], string $ua = 'mobile', int $attempt = 1): array
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

    $reqHeaders = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
        'Accept-Language: lt-LT,lt;q=0.9,en;q=0.8',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    foreach ($extraHeaders as $h) {
        $name = strtolower(trim(explode(':', $h, 2)[0]));
        $reqHeaders = array_values(array_filter($reqHeaders, fn($x) => strtolower(explode(':', $x, 2)[0]) !== $name));
        $reqHeaders[] = $h;
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
        CURLOPT_USERAGENT => WW_UA[$ua] ?? WW_UA['mobile'],
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            if (preg_match('~^HTTP/~i', $line)) {
                $headers = []; // nauja (peradresuota) atsakymo dalis
            }
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
        $err = curl_error($ch);
        curl_close($ch);
        if ($attempt < 2) {
            sleep(2); // vienas pakartotinis bandymas dėl laikinų tinklo klaidų
            return fetch_url($url, $timeout, $extraHeaders, $ua, $attempt + 1);
        }
        $res['error'] = 'Nepavyko prisijungti: ' . $err;
        return $res;
    }
    curl_close($ch);

    if ($res['status'] >= 400) {
        $b = (string)$body;
        if (in_array($res['status'], [403, 429, 503], true) && preg_match('/cf-chl|challenge-platform|Just a moment|cf_captcha|captcha/i', substr($b, 0, 20000))) {
            $res['error'] = 'Svetainė blokuoja automatines užklausas (apsauga nuo robotų, HTTP ' . $res['status'] . '). Pabandykite kitą naršyklės tipą arba JS atvaizdavimo paslaugą.';
        } elseif ($res['status'] >= 500 && $attempt < 2) {
            sleep(3);
            return fetch_url($url, $timeout, $extraHeaders, $ua, $attempt + 1);
        } else {
            $res['error'] = 'Svetainė grąžino HTTP ' . $res['status'];
        }
        $res['body'] = to_utf8($b, $headers['content-type'] ?? '');
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
