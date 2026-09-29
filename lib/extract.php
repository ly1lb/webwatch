<?php
declare(strict_types=1);

/*
 * HTML analizė: CSS parinkiklis -> XPath, teksto ištraukimas, normalizavimas.
 */

function load_html(string $html): DOMDocument
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // Ne-ASCII simbolius paverčiame entity, kad libxml neapsiriktų dėl koduotės.
    $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    if (trim($html) === '') {
        $html = '<html><body></body></html>';
    }
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT | (defined('LIBXML_PARSEHUGE') ? LIBXML_PARSEHUGE : 0));
    libxml_clear_errors();
    return $doc;
}

/** Ar parinkiklis yra XPath (prasideda / arba ( ). */
function is_xpath(string $sel): bool
{
    $sel = ltrim($sel);
    return $sel !== '' && ($sel[0] === '/' || $sel[0] === '(');
}

/** XPath eilutės literalas. */
function xq(string $s): string
{
    if (strpos($s, "'") === false) {
        return "'" . $s . "'";
    }
    if (strpos($s, '"') === false) {
        return '"' . $s . '"';
    }
    return "concat('" . str_replace("'", "',\"'\",'", $s) . "')";
}

/**
 * Konvertuoja CSS parinkiklį į XPath. Palaikoma: tag, *, #id, .class,
 * [attr], [attr=v], [attr^=v], [attr$=v], [attr*=v], [attr~=v],
 * :first-child, :last-child, :nth-child(n), :nth-of-type(n), :first-of-type,
 * :last-of-type, :contains("tekstas"), :not(paprastas), kombinatoriai ' ', '>', '+', '~' ir ','.
 */
function css_to_xpath(string $css): string
{
    $parts = split_top_level(trim($css), ',');
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = css_single_to_xpath($p);
        }
    }
    if (!$out) {
        throw new InvalidArgumentException('Tuščias parinkiklis');
    }
    return implode(' | ', $out);
}

function split_top_level(string $s, string $sep): array
{
    $parts = [];
    $depth = 0;
    $quote = '';
    $cur = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($quote !== '') {
            if ($c === $quote) {
                $quote = '';
            }
        } elseif ($c === '"' || $c === "'") {
            $quote = $c;
        } elseif ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        } elseif ($c === $sep && $depth === 0) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    $parts[] = $cur;
    return $parts;
}

function css_single_to_xpath(string $sel): string
{
    $pos = 0;
    $len = strlen($sel);
    $xpath = '';
    $comb = ' ';
    $first = true;

    while ($pos < $len) {
        // Kombinatorius
        if (preg_match('/\G\s*([>+~])\s*/A', $sel, $m, 0, $pos)) {
            $comb = $m[1];
            $pos += strlen($m[0]);
            continue;
        }
        if (preg_match('/\G\s+/A', $sel, $m, 0, $pos)) {
            $comb = ' ';
            $pos += strlen($m[0]);
            continue;
        }
        [$tag, $conds, $consumed] = css_compound($sel, $pos);
        if ($consumed === 0) {
            throw new InvalidArgumentException('Nepalaikomas parinkiklis ties: ' . substr($sel, $pos, 20));
        }
        $pos += $consumed;
        $condStr = implode('', array_map(fn($c) => '[' . $c . ']', $conds));

        if ($first) {
            $xpath = ($comb === '>' ? '/' : '//') . $tag . $condStr;
        } else {
            switch ($comb) {
                case '>':
                    $xpath .= '/' . $tag . $condStr;
                    break;
                case '+':
                    $xpath .= '/following-sibling::*[1]' . ($tag !== '*' ? '[self::' . $tag . ']' : '') . $condStr;
                    break;
                case '~':
                    $xpath .= '/following-sibling::' . $tag . $condStr;
                    break;
                default:
                    $xpath .= '//' . $tag . $condStr;
            }
        }
        $first = false;
        $comb = ' ';
    }
    if ($xpath === '') {
        throw new InvalidArgumentException('Tuščias parinkiklis');
    }
    return $xpath;
}

/** Nuskaito vieną sudėtinį parinkiklį (pvz. div.a#b[x]:nth-of-type(2)). */
function css_compound(string $sel, int $pos): array
{
    $start = $pos;
    $tag = '*';
    $conds = [];
    if (preg_match('/\G(\*|[a-zA-Z][\w-]*)/A', $sel, $m, 0, $pos)) {
        $tag = strtolower($m[1]);
        $pos += strlen($m[0]);
    }
    while (true) {
        if (preg_match('/\G#((?:[\w-]|\\\\.)+)/A', $sel, $m, 0, $pos)) {
            $conds[] = '@id=' . xq(css_unescape($m[1]));
        } elseif (preg_match('/\G\.((?:[\w-]|\\\\.)+)/A', $sel, $m, 0, $pos)) {
            $conds[] = "contains(concat(' ', normalize-space(@class), ' '), " . xq(' ' . css_unescape($m[1]) . ' ') . ')';
        } elseif (preg_match('/\G\[\s*([\w:-]+)\s*(?:([~^$*|]?=)\s*("[^"]*"|\'[^\']*\'|[^\]\s]+)\s*(i)?\s*)?\]/A', $sel, $m, 0, $pos)) {
            $attr = '@' . strtolower($m[1]);
            if (empty($m[2])) {
                $conds[] = $attr;
            } else {
                $v = $m[3];
                if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                    $v = substr($v, 1, -1);
                }
                $q = xq($v);
                switch ($m[2]) {
                    case '=':
                        $conds[] = "$attr=$q";
                        break;
                    case '^=':
                        $conds[] = "starts-with($attr, $q)";
                        break;
                    case '$=':
                        $conds[] = "substring($attr, string-length($attr) - string-length($q) + 1)=$q";
                        break;
                    case '*=':
                        $conds[] = "contains($attr, $q)";
                        break;
                    case '~=':
                        $conds[] = "contains(concat(' ', normalize-space($attr), ' '), " . xq(' ' . $v . ' ') . ')';
                        break;
                    case '|=':
                        $conds[] = "($attr=$q or starts-with($attr, " . xq($v . '-') . '))';
                        break;
                }
            }
        } elseif (preg_match('/\G:(first-child|last-child|only-child|first-of-type|last-of-type|empty)(?![\w(-])/A', $sel, $m, 0, $pos)) {
            $t = $tag === '*' ? '*' : $tag;
            switch ($m[1]) {
                case 'first-child':
                    $conds[] = 'not(preceding-sibling::*)';
                    break;
                case 'last-child':
                    $conds[] = 'not(following-sibling::*)';
                    break;
                case 'only-child':
                    $conds[] = 'not(preceding-sibling::*) and not(following-sibling::*)';
                    break;
                case 'first-of-type':
                    $conds[] = "not(preceding-sibling::$t)";
                    break;
                case 'last-of-type':
                    $conds[] = "not(following-sibling::$t)";
                    break;
                case 'empty':
                    $conds[] = 'not(*) and not(normalize-space())';
                    break;
            }
        } elseif (preg_match('/\G:(nth-child|nth-of-type|nth-last-child|nth-last-of-type)\(\s*([^)]*)\s*\)/A', $sel, $m, 0, $pos)) {
            $t = ($m[1] === 'nth-of-type' || $m[1] === 'nth-last-of-type') ? $tag : '*';
            $axis = str_starts_with($m[1], 'nth-last') ? 'following-sibling' : 'preceding-sibling';
            $conds[] = nth_condition(trim($m[2]), "count($axis::$t)");
        } elseif (preg_match('/\G:contains\(\s*("[^"]*"|\'[^\']*\'|[^)]*)\s*\)/A', $sel, $m, 0, $pos)) {
            $v = $m[1];
            if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                $v = substr($v, 1, -1);
            }
            $conds[] = 'contains(normalize-space(.), ' . xq($v) . ')';
        } elseif (preg_match('/\G:not\(([^()]*)\)/A', $sel, $m, 0, $pos)) {
            [$ntag, $nconds, $nc] = css_compound(trim($m[1]), 0);
            if ($nc === 0) {
                throw new InvalidArgumentException('Nepalaikomas :not()');
            }
            $parts = $nconds;
            if ($ntag !== '*') {
                $parts[] = 'self::' . $ntag;
            }
            $conds[] = 'not(' . implode(' and ', $parts ?: ['true()']) . ')';
        } else {
            break;
        }
        $pos += strlen($m[0]);
    }
    return [$tag, $conds, $pos - $start];
}

function nth_condition(string $expr, string $countExpr): string
{
    $expr = strtolower(str_replace(' ', '', $expr));
    if ($expr === 'odd') {
        $expr = '2n+1';
    } elseif ($expr === 'even') {
        $expr = '2n';
    }
    if (preg_match('/^\d+$/', $expr)) {
        return "$countExpr = " . ((int)$expr - 1);
    }
    if (preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $expr, $m)) {
        $a = $m[1] === '' || $m[1] === '+' ? 1 : ($m[1] === '-' ? -1 : (int)$m[1]);
        $b = isset($m[2]) ? (int)$m[2] : 0;
        // pozicija p = count + 1; reikia p = a*n + b, n >= 0
        $p = "($countExpr + 1)";
        if ($a === 0) {
            return "$p = $b";
        }
        if ($a > 0) {
            return "$p >= $b and ($p - $b) mod $a = 0";
        }
        return "$p <= $b and ($b - $p) mod " . abs($a) . ' = 0';
    }
    throw new InvalidArgumentException('Nepalaikoma nth išraiška: ' . $expr);
}

function css_unescape(string $s): string
{
    return preg_replace('/\\\\(.)/', '$1', $s);
}

/** Elementai, kurių turinys niekada neįdomus. */
const WW_SKIP_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'head', 'iframe', 'object', 'embed', 'canvas'];

const WW_BLOCK_TAGS = [
    'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
    'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
    'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tr', 'ul', 'option',
    'thead', 'tbody', 'tfoot', 'caption', 'body', 'html', 'button', 'label', 'select', 'textarea',
];

/** Surenka mazgo tekstą, blokiniams elementams dedant naujas eilutes. */
function node_text(DOMNode $node): string
{
    $buf = '';
    node_text_walk($node, $buf);
    return normalize_text($buf);
}

function node_text_walk(DOMNode $node, string &$buf): void
{
    if ($node instanceof DOMText) {
        $buf .= $node->nodeValue;
        return;
    }
    if (!($node instanceof DOMElement) && !($node instanceof DOMDocument)) {
        return;
    }
    $tag = $node instanceof DOMElement ? strtolower($node->nodeName) : '';
    if (in_array($tag, WW_SKIP_TAGS, true)) {
        return;
    }
    if ($tag === 'img') {
        $alt = trim((string)$node->getAttribute('alt'));
        if ($alt !== '') {
            $buf .= ' ' . $alt . ' ';
        }
        return;
    }
    if ($tag === 'input') {
        $type = strtolower($node->getAttribute('type'));
        if (in_array($type, ['submit', 'button'], true)) {
            $buf .= ' ' . $node->getAttribute('value') . ' ';
        }
        return;
    }
    $block = in_array($tag, WW_BLOCK_TAGS, true);
    if ($block) {
        $buf .= "\n";
    } elseif ($tag === 'td' || $tag === 'th') {
        $buf .= ' ';
    }
    foreach ($node->childNodes as $child) {
        node_text_walk($child, $buf);
    }
    if ($block) {
        $buf .= "\n";
    } elseif ($tag === 'td' || $tag === 'th') {
        $buf .= ' ';
    }
}

function normalize_text(string $s): string
{
    $s = str_replace(["\r\n", "\r", "\u{00A0}", "\u{200B}", "\u{FEFF}"], ["\n", "\n", ' ', '', ''], $s);
    $lines = explode("\n", $s);
    $out = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/[ \t\f\v]+/u', ' ', $line) ?? $line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return implode("\n", $out);
}

/** Švarus elemento HTML (be skriptų, su sutvarkytais tarpais). */
function node_html(DOMNode $node): string
{
    $clone = $node->cloneNode(true);
    if ($clone instanceof DOMElement) {
        $xp = new DOMXPath($node->ownerDocument);
        $list = $xp->query('.//script|.//style|.//noscript|.//comment()', $clone);
        foreach ($list ? iterator_to_array($list) : [] as $n) {
            $n->parentNode?->removeChild($n);
        }
    }
    $html = $node->ownerDocument->saveHTML($clone);
    $html = preg_replace('/>\s*</', ">\n<", (string)$html);
    return normalize_text((string)$html);
}

/**
 * Iš HTML ištraukia stebimą turinį pagal stebėjimo nustatymus.
 * Grąžina ['ok', 'content', 'count', 'error'].
 */
function extract_content(string $html, array $w): array
{
    $trim = ltrim($html);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $data = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return extract_json($data, trim((string)($w['selector'] ?? '')));
        }
    }
    $doc = load_html($html);
    $xp = new DOMXPath($doc);
    $selector = trim((string)($w['selector'] ?? ''));
    $mode = (string)($w['compare_mode'] ?? 'text');

    if ($selector === '') {
        $nodes = [$doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement ?? $doc];
    } else {
        try {
            $q = is_xpath($selector) ? $selector : css_to_xpath($selector);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'content' => '', 'count' => 0, 'error' => 'Blogas parinkiklis: ' . $e->getMessage()];
        }
        $list = @$xp->query($q);
        if ($list === false) {
            return ['ok' => false, 'content' => '', 'count' => 0, 'error' => 'Blogas parinkiklis (XPath klaida)'];
        }
        $nodes = iterator_to_array($list);
        if (!$nodes) {
            return ['ok' => false, 'content' => '', 'count' => 0, 'error' => 'Elementas nerastas puslapyje'];
        }
    }

    $chunks = [];
    foreach ($nodes as $n) {
        if ($n instanceof DOMAttr) {
            $chunks[] = trim((string)$n->value);
        } elseif ($mode === 'html') {
            $chunks[] = node_html($n);
        } else {
            $chunks[] = node_text($n);
        }
    }
    $content = implode("\n", array_filter($chunks, fn($c) => $c !== ''));
    return ['ok' => true, 'content' => $content, 'count' => count($nodes), 'error' => ''];
}

/** Paruošia tekstą palyginimui pagal ignoravimo taisykles. */
function comparable_text(string $text, array $w): string
{
    if (trim((string)($w['ignore_regex'] ?? '')) !== '') {
        foreach (preg_split('/\R/', (string)$w['ignore_regex']) as $rx) {
            $rx = trim($rx);
            if ($rx === '') {
                continue;
            }
            $pattern = '~' . str_replace('~', '\~', $rx) . '~iu';
            $r = @preg_replace($pattern, '', $text);
            if ($r !== null) {
                $text = $r;
            }
        }
    }
    if (!empty($w['ignore_numbers'])) {
        $text = preg_replace('/\d+/u', '#', $text) ?? $text;
    }
    return normalize_text($text);
}

/**
 * Suranda pirmą skaičių tekste (kainoms). Supranta „1 299,99 €“, „$1,299.99“, „1.299,99“.
 */
function parse_number(string $text): ?float
{
    if (!preg_match('/-?\d[\d\s\x{00A0}\x{202F}.,\']*/u', $text, $m)) {
        return null;
    }
    $n = preg_replace('/[\s\x{00A0}\x{202F}\']/u', '', $m[0]);
    $n = rtrim($n, '.,');
    $lastComma = strrpos($n, ',');
    $lastDot = strrpos($n, '.');
    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $n = str_replace('.', '', $n);
            $n = str_replace(',', '.', $n);
        } else {
            $n = str_replace(',', '', $n);
        }
    } elseif ($lastComma !== false) {
        $after = strlen($n) - $lastComma - 1;
        if (substr_count($n, ',') === 1 && $after !== 3) {
            $n = str_replace(',', '.', $n);
        } else {
            $n = str_replace(',', '', $n);
        }
    } elseif ($lastDot !== false) {
        $after = strlen($n) - $lastDot - 1;
        if (substr_count($n, '.') > 1 || ($after === 3 && strlen($n) > 4 && $n[0] !== '0')) {
            $n = str_replace('.', '', $n);
        }
    }
    return is_numeric($n) ? (float)$n : null;
}

function format_number(float $n): string
{
    $dec = abs($n - round($n)) < 0.00001 ? 0 : 2;
    return number_format($n, $dec, ',', ' ');
}

/* ------------------------------------------------------------------ */
/* JSON API                                                             */
/* ------------------------------------------------------------------ */

/**
 * JSON atsakymas. Parinkiklis – kelias: $.items[0].price, data.list[*].title, arba tuščias (viskas).
 */
function extract_json(array $data, string $path): array
{
    $values = $path === '' ? [$data] : json_path($data, $path);
    if (!$values) {
        return ['ok' => false, 'content' => '', 'count' => 0, 'error' => 'JSON kelias nerastas: ' . $path];
    }
    $lines = [];
    foreach ($values as $v) {
        if (is_array($v)) {
            json_flatten($v, '', $lines);
        } else {
            $lines[] = json_scalar($v);
        }
    }
    return ['ok' => true, 'content' => normalize_text(implode("\n", $lines)), 'count' => count($values), 'error' => ''];
}

function json_path(array $data, string $path): array
{
    $path = preg_replace('/^\$\.?/', '', trim($path));
    preg_match_all('/([^.\[\]]+)|\[(\*|-?\d+|"[^"]*"|\'[^\']*\')\]/', $path, $m, PREG_SET_ORDER);
    $cur = [$data];
    foreach ($m as $tok) {
        $key = $tok[1] !== '' ? $tok[1] : trim($tok[2], '"\'');
        $next = [];
        foreach ($cur as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ($key === '*') {
                foreach ($node as $child) {
                    $next[] = $child;
                }
            } elseif (preg_match('/^-?\d+$/', $key) && array_is_list($node)) {
                $i = (int)$key;
                $i = $i < 0 ? count($node) + $i : $i;
                if (array_key_exists($i, $node)) {
                    $next[] = $node[$i];
                }
            } elseif (array_key_exists($key, $node)) {
                $next[] = $node[$key];
            }
        }
        $cur = $next;
    }
    return $cur;
}

function json_flatten(array $a, string $prefix, array &$lines): void
{
    foreach ($a as $k => $v) {
        $p = $prefix === '' ? (string)$k : (is_int($k) ? "{$prefix}[$k]" : "$prefix.$k");
        if (is_array($v)) {
            json_flatten($v, $p, $lines);
        } else {
            $lines[] = $p . ': ' . json_scalar($v);
        }
    }
}

function json_scalar($v): string
{
    if ($v === null) {
        return 'null';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    return (string)$v;
}
