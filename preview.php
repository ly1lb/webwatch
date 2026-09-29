<?php
declare(strict_types=1);

/*
 * Rodo stebimą puslapį (be jo skriptų) su įterptu elementų parinkikliu.
 * Atidaromas iframe'e su sandbox, todėl neturi prieigos prie WebWatch sesijos.
 */

require __DIR__ . '/lib/bootstrap.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Neprisijungta');
}

$url = trim((string)($_GET['url'] ?? ''));
$nonce = base64_encode(random_bytes(12));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: sandbox allow-scripts; script-src 'nonce-$nonce'; object-src 'none'; frame-src 'none'; form-action 'none'; base-uri *");
header('Referrer-Policy: no-referrer');

$f = fetch_for_watch([
    'url' => $url,
    'headers' => (string)($_GET['h'] ?? ''),
    'user_agent' => (string)($_GET['ua'] ?? 'mobile'),
    'render_js' => !empty($_GET['js']),
]);
if (!$f['ok']) {
    echo '<!doctype html><meta charset="utf-8"><body style="font:16px -apple-system,sans-serif;padding:24px;color:#b91c1c">'
        . h($f['error']) . '</body>';
    exit;
}

$trim = ltrim($f['body']);
if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[') && is_array($json = json_decode($trim, true))) {
    $lines = [];
    json_flatten($json, '', $lines);
    echo '<!doctype html><meta charset="utf-8"><body style="font:14px ui-monospace,Menlo,monospace;padding:16px;white-space:pre-wrap;word-break:break-all">'
        . '<b style="font-family:-apple-system,sans-serif">Tai JSON API. Įrašykite kelią laukelyje, pvz. <code>$.items[0].price</code> arba <code>data.list[*].title</code>:</b>' . "\n\n"
        . h(implode("\n", array_slice($lines, 0, 2000))) . '</body>';
    exit;
}

$doc = load_html($f['body']);
$xp = new DOMXPath($doc);

// Šaliname skriptus ir kitus aktyvius elementus
foreach (iterator_to_array($xp->query('//script|//iframe|//object|//embed|//noscript|//base|//meta[@http-equiv]|//link[@rel="preload" or @rel="modulepreload" or @rel="prefetch"]')) as $n) {
    $n->parentNode?->removeChild($n);
}
foreach (iterator_to_array($xp->query('//@*')) as $attr) {
    /** @var DOMAttr $attr */
    $name = strtolower($attr->nodeName);
    $val = strtolower(trim((string)$attr->value));
    if (str_starts_with($name, 'on') || (in_array($name, ['href', 'src', 'action', 'formaction'], true) && str_starts_with($val, 'javascript:'))) {
        $attr->ownerElement?->removeAttributeNode($attr);
    }
}
// „Lazy“ paveikslėliai
foreach (iterator_to_array($xp->query('//img[@data-src and not(@src)] | //img[@data-src and contains(@src, "data:")]')) as $img) {
    $img->setAttribute('src', $img->getAttribute('data-src'));
}

$head = $doc->getElementsByTagName('head')->item(0);
if (!$head) {
    $head = $doc->createElement('head');
    $htmlEl = $doc->documentElement ?? $doc->appendChild($doc->createElement('html'));
    $htmlEl->insertBefore($head, $htmlEl->firstChild);
}
$base = $doc->createElement('base');
$base->setAttribute('href', $f['final_url']);
$head->insertBefore($base, $head->firstChild);
$meta = $doc->createElement('meta');
$meta->setAttribute('name', 'viewport');
$meta->setAttribute('content', 'width=device-width, initial-scale=1');
$head->appendChild($meta);

$style = $doc->createElement('style');
$style->appendChild($doc->createTextNode('
.__ww_hover{outline:2px dashed #f59e0b!important;outline-offset:1px!important;cursor:crosshair!important}
.__ww_sel{outline:3px solid #d946ef!important;outline-offset:1px!important;background-color:rgba(217,70,239,.14)!important}
*{cursor:crosshair!important}
'));
$head->appendChild($style);

$script = $doc->createElement('script');
$script->setAttribute('nonce', $nonce);
$script->appendChild($doc->createTextNode(file_get_contents(__DIR__ . '/assets/picker.js')));
$body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
$body->appendChild($script);

echo "<!doctype html>\n" . $doc->saveHTML($doc->documentElement);
