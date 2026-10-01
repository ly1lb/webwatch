<?php
declare(strict_types=1);

/*
 * Nuotoliniai tikrinimo taškai („namų kompiuteriai“).
 *
 * Jūsų pačių kompiuteriai skirtingose vietose veikia kaip WebWatch tikrinimo taškai –
 * lygiai kaip Uptime Kuma ar Pingdom nutolę mazgai. Naudinga, kai stebimą svetainę
 * norite tikrinti iš kelių vietų arba kai hostingo serverio adresas kažkodėl negali
 * pasiekti puslapio, o namų ryšys gali.
 *
 * Veikimas: kiekvienas kompiuteris paleidžia mažą programą (agent/agent.py), kuri nuolat
 * „klausia“ serverio – agent.php?action=poll (ilga užklausa iki 25 s). Kai reikia puslapio,
 * WebWatch sukuria darbą konkrečiam kompiuteriui; tas jį paima, parsiunčia per savo ryšį ir
 * grąžina turinį (agent.php?action=result).
 *
 * Perdavimas kitam (failover): darbas siunčiamas kompiuteriams pagal eilę (prioritetą).
 * Jei pirmas neprisijungęs, nepaima ar jo ryšys neveikia – bandomas antras, tada trečias.
 */

const WW_AGENT_ONLINE_SECONDS = 45;   // per tiek s be „poll“ kompiuteris laikomas atsijungusiu
const WW_AGENT_CLAIM_TIMEOUT = 15;    // per tiek s kompiuteris turi paimti darbą
const WW_AGENT_RESULT_TIMEOUT = 110;  // per tiek s turi grąžinti rezultatą (naršyklės režimas lėtesnis)

function agents_all(): array
{
    return db()->query('SELECT * FROM agents ORDER BY priority, id')->fetchAll();
}

function agent_is_online(array $a): bool
{
    return (int)$a['last_seen'] >= time() - WW_AGENT_ONLINE_SECONDS;
}

function agents_online(): array
{
    return array_values(array_filter(agents_all(), 'agent_is_online'));
}

/**
 * Pasuka tikrinimo taškų sąrašą taip, kad šis tikrinimas prasidėtų nuo kito
 * kompiuterio nei praeitas (round-robin). Taip srautas pasiskirsto po visus
 * prisijungusius taškus, o ne lenda vis iš to paties IP. Eilė išlieka ta pati –
 * tik pradžios taškas pasislenka, todėl perdavimas kitam (failover) nenukenčia.
 */
function agents_rotated(array $agents): array
{
    $n = count($agents);
    if ($n < 2) {
        return $agents;
    }
    $last = (int)setting('agent_last_used', '0');
    $pos = 0;
    foreach ($agents as $i => $a) {
        if ((int)$a['id'] === $last) {
            $pos = $i + 1; // pradedame nuo kito po paskutinio naudoto
            break;
        }
    }
    $pos %= $n;
    return array_merge(array_slice($agents, $pos), array_slice($agents, 0, $pos));
}

function agent_by_token(string $token): ?array
{
    if (strlen($token) < 20) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM agents WHERE token = ?');
    $st->execute([$token]);
    $a = $st->fetch();
    return $a && hash_equals((string)$a['token'], $token) ? $a : null;
}

/**
 * Parsiunčia puslapį per nuotolinius tikrinimo taškus, eilės tvarka su perdavimu kitam.
 * Grąžina tą patį formatą kaip fetch_url() + 'agent' (kurio taško vardas atliko).
 */
function agent_fetch(string $url, array $headers, string $ua, bool $render = false, bool $shot = false): array
{
    $res = ['ok' => false, 'status' => 0, 'body' => '', 'final_url' => $url, 'error' => '', 'blocked' => false, 'agent' => ''];
    $db = db();
    $db->prepare('DELETE FROM agent_requests WHERE created < ?')->execute([time() - 900]);

    $agents = agents_all();
    if (!$agents) {
        $res['error'] = 'Nėra pridėtų tikrinimo taškų';
        return $res;
    }

    // Rotacija: kad srautas nesklistų vis iš to paties IP, kiekvieną kartą pradedame
    // nuo kito prisijungusio kompiuterio (round-robin). Perdavimas kitam (failover)
    // veikia kaip anksčiau. Galima išjungti nustatymu agent_rotate=0 – tada griežta
    // prioriteto eilė (pirmas visada pirmas, kiti – tik atsarginiai).
    if (setting('agent_rotate', '1') !== '0') {
        $agents = agents_rotated($agents);
    }

    $lastBlocked = null;
    $lastFail = null;
    $anyTried = false;
    foreach ($agents as $agent) {
        if (!agent_is_online($agent)) {
            continue; // atsijungusį praleidžiame
        }
        $anyTried = true;
        $r = agent_dispatch($db, (int)$agent['id'], $url, $headers, $ua, $render, $shot);
        $r['agent'] = (string)$agent['name'];

        if ($r['node_failed']) {
            // Sutriko pats kompiuteris ar jo ryšys – perduodame kitam. Priežastį įsimename matomoje vietoje.
            db_write('UPDATE agents SET fails = fails + 1, last_error = ? WHERE id = ?', [mb_substr($r['error'], 0, 300), $agent['id']]);
            ww_log('info', 'Tikrinimo taškas „' . $agent['name'] . '“: ' . $r['error'] . ' – perduodama kitam');
            $lastFail = $r;
            continue;
        }
        // Sėkmingą darbą pažymime (išvalome seną klaidą) ir įsimename kaip paskutinį naudotą (rotacijai)
        db_write("UPDATE agents SET last_error = '' WHERE id = ?", [$agent['id']]);
        set_setting('agent_last_used', (string)$agent['id']);
        // Kompiuteris atsakė. Jei svetainė jį irgi užblokavo – gal kita vieta praeis
        if ($r['blocked']) {
            $lastBlocked = $r;
            continue;
        }
        return $r; // sėkmė arba tikras svetainės atsakymas (pvz. 404)
    }

    if ($lastBlocked) {
        return $lastBlocked;
    }
    if ($lastFail) {
        // Konkreti priežastis iš paskutinio bandymo (nepaėmė / negrąžino)
        $lastFail['error'] = ($lastFail['agent'] !== '' ? '„' . $lastFail['agent'] . '“: ' : '') . $lastFail['error'];
        $lastFail['blocked'] = true;
        return $lastFail;
    }
    $res['error'] = 'Nė vienas tikrinimo taškas šiuo metu neprisijungęs. Paleiskite agento programą kompiuteryje.';
    $res['blocked'] = true;
    return $res;
}

/**
 * Sukuria darbą konkrečiam kompiuteriui ir laukia rezultato.
 * Grąžina fetch_url() formatą + 'node_failed' (ar sutriko pats taškas, ne svetainė).
 */
function agent_dispatch(PDO $db, int $agentId, string $url, array $headers, string $ua, bool $render, bool $shot = false): array
{
    $res = ['ok' => false, 'status' => 0, 'body' => '', 'final_url' => $url, 'error' => '', 'blocked' => false, 'node_failed' => false];

    db_write('INSERT INTO agent_requests (created, url, headers, ua, browser, target_agent, shot) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [time(), $url, implode("\n", $headers), WW_UA[$ua] ?? WW_UA['desktop'], $render ? 1 : 0, $agentId, $shot ? 1 : 0]);
    $id = (int)$db->lastInsertId();
    @set_time_limit(WW_AGENT_RESULT_TIMEOUT + 30);

    $start = microtime(true);
    $claimed = false;
    $st = $db->prepare('SELECT status FROM agent_requests WHERE id = ?');
    while (true) {
        usleep(400000);
        $st->execute([$id]);
        $status = (string)$st->fetchColumn();
        $st->closeCursor();
        $waited = microtime(true) - $start;

        if ($status === 'done') {
            $full = $db->prepare('SELECT * FROM agent_requests WHERE id = ?');
            $full->execute([$id]);
            $r = $full->fetch();
            db_write('DELETE FROM agent_requests WHERE id = ?', [$id]);
            return agent_interpret($r, $url);
        }
        if ($status !== 'claimed' && !$claimed && $waited > WW_AGENT_CLAIM_TIMEOUT) {
            // Kompiuteris prisijungęs (skambina), bet nepaėmė jam skirto darbo –
            // beveik visada sena agento versija arba jis nevykdo naujų užduočių.
            $res['error'] = 'nepaėmė darbo (sena agento versija? atnaujinkite ją tame kompiuteryje)';
            $res['stage'] = 'not_claimed';
            $res['node_failed'] = true;
            break;
        }
        if ($status === 'claimed') {
            $claimed = true;
        }
        if ($waited > WW_AGENT_RESULT_TIMEOUT) {
            // Darbą paėmė, bet negrąžino turinio – lėta naršyklė, lėtas tinklas ar WAF muša grąžinimą
            $res['error'] = 'paėmė darbą, bet negrąžino turinio per ' . WW_AGENT_RESULT_TIMEOUT . ' s (lėta naršyklė, tinklas arba hostingo apsauga)';
            $res['stage'] = 'no_result';
            $res['node_failed'] = true;
            break;
        }
    }
    db_write('DELETE FROM agent_requests WHERE id = ?', [$id]);
    return $res;
}

/** Įvertina kompiuterio grąžintą rezultatą (svetainės atsakymą). */
function agent_interpret(array $r, string $url): array
{
    $res = ['ok' => false, 'status' => (int)$r['http_status'], 'body' => '', 'final_url' => $r['final_url'] ?: $url,
        'error' => '', 'blocked' => false, 'node_failed' => false];

    // Kompiuteris nepasiekė svetainės (ne svetainės, o taško ryšio bėda) – perduodame kitam
    if ($r['error'] !== '' && (string)$r['body'] === '') {
        $res['error'] = (string)$r['error'];
        $res['node_failed'] = true;
        return $res;
    }
    // Dvejetainis turinys (ekrano nuotrauka ir pan.) – NEGALIMA kišti per to_utf8,
    // nes jis sugadintų PNG baitus. Grąžiname žaliavą tokią, kokia yra.
    $isBinary = !empty($r['shot']) || stripos((string)$r['content_type'], 'image/') === 0;
    if ($isBinary) {
        $res['body'] = (string)$r['body'];
        $res['ok'] = $res['status'] < 400;
        if (!$res['ok']) {
            $res['error'] = 'Svetainė grąžino HTTP ' . $res['status'];
        }
        return $res;
    }
    $body = to_utf8((string)$r['body'], (string)$r['content_type']);
    $res['body'] = $body;
    $res['blocked'] = is_block_response($res['status'], $body);
    if ($res['blocked']) {
        $res['error'] = 'Svetainė užblokavo ir šį tašką (HTTP ' . $res['status'] . ')';
    } elseif ($res['status'] >= 400) {
        $res['error'] = 'Svetainė grąžino HTTP ' . $res['status'];
    } else {
        $res['ok'] = true;
    }
    return $res;
}

/** Programos atsisiuntimo nuorodos ir paleidimo komandos vienam tikrinimo taškui. */
function agent_setup(array $agent): array
{
    $t = rawurlencode((string)$agent['token']);
    $winUrl = app_url() . 'agent.php?action=script&os=win&t=' . $t;
    $nixUrl = app_url() . 'agent.php?action=script&os=unix&t=' . $t;
    return [
        'win_url' => $winUrl,
        'nix_url' => $nixUrl,
        // Windows: viena eilutė PowerShell lange
        'windows' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "irm \'' . $winUrl . '\' -OutFile $env:APPDATA\\WWAgent.ps1; & $env:APPDATA\\WWAgent.ps1 -Install"',
        // Mac / Linux: viena eilutė terminale
        'unix' => 'curl -fsSL "' . $nixUrl . '" -o ~/ww-agent.py && python3 ~/ww-agent.py --install',
    ];
}
