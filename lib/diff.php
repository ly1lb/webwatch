<?php
declare(strict_types=1);

/**
 * Eilučių skirtumai. Grąžina operacijų sąrašą: [['=', eilutė], ['-', eilutė], ['+', eilutė], ...].
 */
function line_diff(string $old, string $new): array
{
    $a = $old === '' ? [] : explode("\n", $old);
    $b = $new === '' ? [] : explode("\n", $new);

    // Bendra pradžia ir pabaiga
    $pre = 0;
    $na = count($a);
    $nb = count($b);
    while ($pre < $na && $pre < $nb && $a[$pre] === $b[$pre]) {
        $pre++;
    }
    $suf = 0;
    while ($suf < $na - $pre && $suf < $nb - $pre && $a[$na - 1 - $suf] === $b[$nb - 1 - $suf]) {
        $suf++;
    }
    $ops = [];
    for ($i = 0; $i < $pre; $i++) {
        $ops[] = ['=', $a[$i]];
    }
    $ma = array_slice($a, $pre, $na - $pre - $suf);
    $mb = array_slice($b, $pre, $nb - $pre - $suf);

    $n = count($ma);
    $m = count($mb);
    if ($n * $m <= 250000) {
        // LCS dinaminis programavimas
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $ma[$i] === $mb[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($ma[$i] === $mb[$j]) {
                $ops[] = ['=', $ma[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['-', $ma[$i++]];
            } else {
                $ops[] = ['+', $mb[$j++]];
            }
        }
        for (; $i < $n; $i++) {
            $ops[] = ['-', $ma[$i]];
        }
        for (; $j < $m; $j++) {
            $ops[] = ['+', $mb[$j]];
        }
    } else {
        // Dideliems tekstams – paprastas aibių palyginimas
        $inB = array_count_values($mb);
        $inA = array_count_values($ma);
        foreach ($ma as $line) {
            if (!empty($inB[$line])) {
                $inB[$line]--;
                $ops[] = ['=', $line];
            } else {
                $ops[] = ['-', $line];
            }
        }
        foreach ($mb as $line) {
            if (!empty($inA[$line])) {
                $inA[$line]--;
            } else {
                $ops[] = ['+', $line];
            }
        }
    }
    for ($i = $na - $suf; $i < $na; $i++) {
        $ops[] = ['=', $a[$i]];
    }
    return $ops;
}

/** Pokyčio dydis procentais (pagal pasikeitusių simbolių kiekį). */
function change_percent(array $ops): float
{
    $total = 0;
    $changed = 0;
    foreach ($ops as [$op, $line]) {
        $len = max(1, mb_strlen($line));
        $total += $len;
        if ($op !== '=') {
            $changed += $len;
        }
    }
    if ($changed === 0) {
        return 0.0;
    }
    return max(0.01, round($changed / max(1, $total) * 100, 2));
}

/** Trumpas pokyčio aprašymas pranešimui. */
function diff_summary(array $ops, int $maxLines = 4, int $maxLen = 240): string
{
    $added = [];
    $removed = [];
    foreach ($ops as [$op, $line]) {
        if ($op === '+') {
            $added[] = $line;
        } elseif ($op === '-') {
            $removed[] = $line;
        }
    }
    $lines = [];
    foreach (array_slice($added, 0, $maxLines) as $l) {
        $lines[] = '+ ' . $l;
    }
    if (count($lines) < $maxLines) {
        foreach (array_slice($removed, 0, $maxLines - count($lines)) as $l) {
            $lines[] = '− ' . $l;
        }
    }
    $s = implode("\n", $lines);
    if (mb_strlen($s) > $maxLen) {
        $s = mb_substr($s, 0, $maxLen - 1) . '…';
    }
    $extra = count($added) + count($removed) - count($lines);
    if ($extra > 0) {
        $s .= "\n(+ dar $extra eil.)";
    }
    return $s;
}

/** HTML atvaizdavimas su kontekstu aplink pakeitimus. */
function diff_html(array $ops, int $context = 3): string
{
    $n = count($ops);
    $show = array_fill(0, $n, false);
    for ($i = 0; $i < $n; $i++) {
        if ($ops[$i][0] !== '=') {
            for ($k = max(0, $i - $context); $k <= min($n - 1, $i + $context); $k++) {
                $show[$k] = true;
            }
        }
    }
    $out = '';
    $skipped = false;
    for ($i = 0; $i < $n; $i++) {
        if (!$show[$i]) {
            $skipped = true;
            continue;
        }
        if ($skipped) {
            $out .= '<div class="d-skip">⋯</div>';
            $skipped = false;
        }
        [$op, $line] = $ops[$i];
        $cls = $op === '+' ? 'd-add' : ($op === '-' ? 'd-del' : 'd-eq');
        $sign = $op === '=' ? ' ' : ($op === '+' ? '+' : '−');
        $out .= '<div class="' . $cls . '"><span class="d-sign">' . $sign . '</span>' . h($line) . '</div>';
    }
    if ($skipped && $out !== '') {
        $out .= '<div class="d-skip">⋯</div>';
    }
    return $out === '' ? '<div class="d-skip">Skirtumų nėra</div>' : $out;
}
