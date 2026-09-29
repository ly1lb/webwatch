<?php
declare(strict_types=1);

/*
 * Puslapių parsiuntimas.
 *
 * Kai kurios svetainės blokuoja užklausas iš serverių (Cloudflare, DataDome ir pan.).
 * Todėl naudojama automatinė grandinė – kitas būdas bandomas tik jei ankstesnį užblokavo:
 *   1. direct       – tiesiogiai, kaip tikra naršyklė (pilnos naršyklės antraštės, HTTP/2, slapukai)
 *   2. direct-alt   – kitas naršyklės tipas + „apšildymas“ (pirma aplankomas pagrindinis puslapis)
 *   3. reader       – Jina Reader (nemokama tikra naršyklė debesyje)
 *   4. service      – apėjimo paslauga su API raktu (ScrapingBee, ScraperAPI, ZenRows ar sava)
 * Pasisekęs būdas įsimenamas stebėjimui ir kitą kartą bandomas pirmas.
 */

const WW_UA = [
    'mobile' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
    'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
];

const WW_VIA_LABELS = [
    'direct' => 'Tiesiogiai',
    'direct-alt' => 'Tiesiogiai (kitas naršyklės tipas)',
    'reader' => 'Jina Reader (debesies naršyklė)',
    'service' => 'Apėjimo paslauga',
];

function scrape_providers(): array
{
    return [
        'scrapingbee' => ['ScrapingBee', 'https://www.scrapingbee.com', 'https://app.scrapingbee.com/api/v1/?api_key={key}&url={url}&render_js=true&stealth_proxy=true'],
        'scraperapi' => ['ScraperAPI', 'https://www.scraperapi.com', 'https://api.scraperapi.com/?api_key={key}&url={url}&render=true&premium=true'],
        'zenrows' => ['ZenRows', 'https://www.zenrows.com', 'https://api.zenrows.com/v1/?apikey={key}&url={url}&js_render=true&premium_proxy=true'],
        'custom' => ['Kita (savas adresas su {url})', '', ''],
    ];
}

/** Apėjimo paslaugos adresas konkrečiam puslapiui arba '' jei nesukonfigūruota. */
function service_url(string $url): string
{
    $provider = (string)setting('scrape_provider', '');
    $key = trim((string)setting('scrape_key', ''));
    $providers = scrape_providers();
    if ($provider === 'custom' || ($provider === '' && trim((string)setting('render_api', '')) !== '')) {
        $tpl = trim((string)setting('render_api', ''));
        return str_contains($tpl, '{url}') ? str_replace('{url}', rawurlencode($url), $tpl) : '';
    }
    if (!isset($providers[$provider]) || $key === '') {
        return '';
    }
    return str_replace(['{key}', '{url}'], [rawurlencode($key), rawurlencode($url)], $providers[$provider][2]);
}

function reader_enabled(): bool
{
    return setting('bypass_reader', '1') === '1';
}

/**
 * Parsiunčia stebėjimo puslapį, prireikus automatiškai apeinant blokavimą.
 * Rezultate papildomai: 'via' – kuriuo būdu gauta.
 */
function fetch_for_watch(array $w, bool $remember = true): array
{
    $url = (string)$w['url'];
    $ua = ($w['user_agent'] ?? 'mobile') === 'desktop' ? 'desktop' : 'mobile';
    $altUa = $ua === 'desktop' ? 'mobile' : 'desktop';
    $headers = parse_header_lines((string)($w['headers'] ?? ''));
    $hasService = service_url($url) !== '';

    $chain = ['direct', 'direct-alt'];
    if (reader_enabled() && !$headers) { // prisijungimo slapukų trečiajai šaliai nesiunčiame
        $chain[] = 'reader';
    }
    if ($hasService) {
        $chain[] = 'service';
    }
    if (!empty($w['render_js'])) {
        // „Visada per naršyklę“ (JS puslapiams) – tiesioginis būdas nieko neduotų
        $browser = array_values(array_filter(['service', 'reader'], fn($s) => in_array($s, $chain, true)));
        $chain = $browser ?: $chain;
    }
    $remembered = (string)($w['fetch_via'] ?? '');
    $retryDirect = false;
    if (in_array($remembered, ['reader', 'service'], true) && !empty($w['id']) && empty($w['render_js'])) {
        // Kartą per parą vėl pabandome nemokamą tiesioginį būdą – gal svetainė nebeblokuoja
        $key = 'direct_retry_' . (int)$w['id'];
        if (time() - (int)setting($key, '0') > 86400) {
            set_setting($key, (string)time());
            $retryDirect = true;
        }
    }
    if ($remembered !== '' && !$retryDirect && in_array($remembered, $chain, true)) {
        $chain = array_values(array_unique(array_merge([$remembered], $chain)));
    }

    $first = null;
    $blocked = null;
    foreach ($chain as $via) {
        switch ($via) {
            case 'direct':
                $r = fetch_url($url, 25, $headers, $ua);
                break;
            case 'direct-alt':
                $r = fetch_url($url, 25, $headers, $altUa, 1, true);
                break;
            case 'reader':
                $r = fetch_reader($url);
                break;
            default:
                $r = fetch_url(service_url($url), 120, [], 'desktop', 2, false, true);
                if ($r['ok'] && is_block_response(200, $r['body'])) {
                    $r['ok'] = false;
                    $r['blocked'] = true;
                } elseif (!$r['ok'] && !$r['blocked']) {
                    // Paslaugos klaida (raktas, kreditai, laikina) – toliau nebandoma
                    $r['blocked'] = true;
                    $r['error'] = 'Apėjimo paslauga grąžino klaidą (HTTP ' . $r['status'] . '). Patikrinkite API raktą ir likusius kreditus.';
                }
                $r['final_url'] = $url;
        }
        $r['via'] = $via;
        $first ??= $r;
        if ($r['ok']) {
            if ($remember && !empty($w['id']) && $remembered !== $via) {
                db()->prepare('UPDATE watches SET fetch_via = ? WHERE id = ?')->execute([$via, $w['id']]);
            }
            return $r;
        }
        if (!$r['blocked'] && ($via === 'direct' || $via === 'direct-alt')) {
            // Ne blokavimas (pvz. 404, neteisingas adresas) – kiti būdai nepadės
            return $r;
        }
        $blocked = $r;
    }

    $res = $blocked ?? $first;
    if ($res['via'] !== 'service' || !str_contains($res['error'], 'API raktą')) {
        $res['error'] = 'Svetainė blokuoja automatinius tikrinimus (apsauga nuo robotų, HTTP ' . $res['status'] . '). '
            . ($hasService
                ? 'Nepadėjo ir apėjimo paslauga – pabandykite kitą paslaugą.'
                : 'Įjunkite apėjimo paslaugą: Nustatymai → „Apsaugos nuo robotų apėjimas“.');
    }
    return $res;
}

/** Jina Reader – nemokama debesies naršyklė, grąžina atvaizduotą HTML. */
function fetch_reader(string $url): array
{
    $headers = ['X-Return-Format: html', 'X-No-Cache: true', 'X-Timeout: 40'];
    if (($k = trim((string)setting('jina_key', ''))) !== '') {
        $headers[] = 'Authorization: Bearer ' . $k;
    }
    $r = fetch_url('https://r.jina.ai/' . $url, 60, $headers, 'desktop', 2, false, true);
    $r['final_url'] = $url;
    if ($r['ok'] && is_block_response(200, $r['body'])) {
        // Reader'is pats gavo patikros puslapį
        $r['ok'] = false;
        $r['blocked'] = true;
    } elseif (!$r['ok']) {
        $r['blocked'] = true; // limitas / laikina klaida – bandome toliau
    }
    return $r;
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

/** Ar atsakymas yra apsaugos nuo robotų blokavimas / patikra. */
function is_block_response(int $status, string $body): bool
{
    if (in_array($status, [401, 403, 405, 406, 429, 451, 503], true)) {
        return true;
    }
    if ($status !== 200 || strlen($body) > 80000) {
        return false;
    }
    // Kai kurios apsaugos patikros puslapį grąžina su 200
    $head = substr($body, 0, 30000);
    $challenge = '/cf-chl|challenge-platform|cf_chl_opt|<title>Just a moment\.\.\.|Attention Required! \| Cloudflare'
        . '|_Incapsula_Resource|px-captcha|captcha-delivery\.com|geo\.captcha-delivery|ddos-guard\.net'
        . '|Pardon Our Interruption|sgcaptcha/i';
    return preg_match($challenge, $head) === 1;
}

function browser_headers(string $ua, string $url, bool $sameOrigin): array
{
    $isChrome = $ua === 'desktop';
    $h = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,' . ($isChrome ? 'image/avif,image/webp,image/apng,*/*;q=0.8' : '*/*;q=0.8'),
        'Accept-Language: lt-LT,lt;q=0.9,en-US;q=0.8,en;q=0.7',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: ' . ($sameOrigin ? 'same-origin' : 'none'),
        'Sec-Fetch-User: ?1',
    ];
    if ($isChrome) {
        $h[] = 'sec-ch-ua: "Chromium";v="140", "Not=A?Brand";v="24", "Google Chrome";v="140"';
        $h[] = 'sec-ch-ua-mobile: ?0';
        $h[] = 'sec-ch-ua-platform: "Windows"';
    }
    if ($sameOrigin) {
        $h[] = 'Referer: ' . origin_of($url) . '/';
    }
    return $h;
}

function origin_of(string $url): string
{
    $p = parse_url($url);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
}

/** Slapukų failas svetainei (išlaiko sesiją tarp tikrinimų, kaip tikra naršyklė). */
function cookie_jar(string $url): string
{
    $dir = WW_DATA . '/cookies';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . substr(sha1((string)parse_url($url, PHP_URL_HOST)), 0, 16) . '.txt';
}

/**
 * Parsiunčia puslapį. Grąžina ['ok', 'status', 'body', 'final_url', 'error', 'blocked'].
 * body visada konvertuojamas į UTF-8.
 *   $warmup – pirma aplankyti pagrindinį puslapį (gauti slapukus), tada tikslinį
 *   $plain  – be naršyklės antraščių ir slapukų (API / paslaugoms)
 */
function fetch_url(string $url, int $timeout = 25, array $extraHeaders = [], string $ua = 'mobile', int $attempt = 1, bool $warmup = false, bool $plain = false): array
{
    $res = ['ok' => false, 'status' => 0, 'body' => '', 'final_url' => $url, 'error' => '', 'blocked' => false];

    if (!preg_match('~^https?://~i', $url)) {
        $res['error'] = 'Adresas turi prasidėti http:// arba https://';
        return $res;
    }
    if (!function_exists('curl_init')) {
        $res['error'] = 'Serveryje nėra PHP cURL plėtinio';
        return $res;
    }

    if ($warmup) {
        $home = origin_of($url) . '/';
        if (rtrim($home, '/') !== rtrim($url, '/')) {
            fetch_url($home, 15, $extraHeaders, $ua, 2);
            usleep(random_int(800000, 1800000)); // žmogiška pauzė
        }
    }

    $reqHeaders = $plain ? ['Accept: */*'] : browser_headers($ua, $url, $warmup);
    foreach ($extraHeaders as $h) {
        $name = strtolower(trim(explode(':', $h, 2)[0]));
        $reqHeaders = array_values(array_filter($reqHeaders, fn($x) => strtolower(explode(':', $x, 2)[0]) !== $name));
        $reqHeaders[] = $h;
    }
    $headers = [];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => WW_UA[$ua] ?? WW_UA['mobile'],
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_AUTOREFERER => true,
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
    ];
    if (!$plain) {
        $opts[CURLOPT_COOKIEFILE] = cookie_jar($url);
        $opts[CURLOPT_COOKIEJAR] = cookie_jar($url);
        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $res['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $res['final_url'] = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        if ($attempt < 2) {
            sleep(2); // vienas pakartotinis bandymas dėl laikinų tinklo klaidų
            return fetch_url($url, $timeout, $extraHeaders, $ua, $attempt + 1, false, $plain);
        }
        $res['error'] = 'Nepavyko prisijungti: ' . $err;
        return $res;
    }
    curl_close($ch);
    $b = (string)$body;
    if (strlen($b) > 8 * 1024 * 1024) {
        $b = substr($b, 0, 8 * 1024 * 1024);
    }

    $res['blocked'] = $plain ? false : is_block_response($res['status'], $b);
    if ($res['status'] >= 400 || $res['blocked']) {
        if ($res['status'] >= 500 && $res['status'] !== 503 && $attempt < 2) {
            sleep(3);
            return fetch_url($url, $timeout, $extraHeaders, $ua, $attempt + 1, false, $plain);
        }
        $res['error'] = $res['blocked']
            ? 'Svetainė blokuoja automatinius tikrinimus (HTTP ' . $res['status'] . ')'
            : 'Svetainė grąžino HTTP ' . $res['status'];
        $res['body'] = to_utf8($b, $headers['content-type'] ?? '');
        return $res;
    }

    $res['body'] = to_utf8($b, $headers['content-type'] ?? '');
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
