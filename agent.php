<?php
declare(strict_types=1);

/*
 * Namų agentų API.
 *   GET  ?action=poll&wait=25          – laukia darbo (ilga užklausa)
 *   POST ?action=result&id=N           – grąžina parsiųstą puslapį (turinys – užklausos kūnas)
 *   GET  ?action=script&os=win|unix&t= – agento programa su įrašytu raktu
 * Autentifikacija: antraštė X-Agent-Token (arba t= parametras programos atsisiuntimui).
 */

require __DIR__ . '/lib/bootstrap.php';

header('Cache-Control: no-store');
$action = (string)($_GET['action'] ?? '');

function agent_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$token = (string)($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ($_GET['t'] ?? ''));
$agent = agent_by_token($token);
if (!$agent) {
    usleep(500000);
    agent_out(['error' => 'Neteisingas agento raktas. Sugeneruokite naują WebWatch nustatymuose.'], 403);
}

if ($action === 'script') {
    $os = ($_GET['os'] ?? '') === 'win' ? 'win' : 'unix';
    $file = __DIR__ . '/agent/' . ($os === 'win' ? 'agent.ps1' : 'agent.py');
    // Serverio adresą imame iš nustatymų, o jei jų dar nėra – iš šios užklausos
    $server = app_url();
    if ($server === '' || $server === '/') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $server = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
    }
    $script = str_replace(
        ['__WW_SERVER__', '__WW_TOKEN__', '__WW_NAME__'],
        [$server, $agent['token'], preg_replace('/[^\w .-]/u', '', (string)$agent['name'])],
        (string)file_get_contents($file)
    );
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($os === 'win' ? 'ww-agent.ps1' : 'ww-agent.py') . '"');
    echo $script;
    exit;
}

$db = db();
$touch = function () use ($agent) {
    $info = mb_substr(trim((string)($_GET['os'] ?? '') . ' ' . (string)($_GET['v'] ?? '') . ' ' . (string)($_GET['browser'] ?? '')), 0, 120);
    db_write('UPDATE agents SET last_seen = ?, last_ip = ?, info = ? WHERE id = ?',
        [time(), (string)($_SERVER['REMOTE_ADDR'] ?? ''), $info, $agent['id']]);
};

if ($action === 'poll') {
    ignore_user_abort(false);
    $wait = max(0, min(25, (int)($_GET['wait'] ?? 25)));
    @set_time_limit($wait + 20);
    $deadline = microtime(true) + $wait;
    $touch();
    $lastTouch = time();
    $find = $db->prepare("SELECT id FROM agent_requests WHERE status = 'pending' AND target_agent = ? ORDER BY id LIMIT 1");
    do {
        // Darbą paima tik tas kompiuteris, kuriam jis skirtas (perdavimo tvarka tvarkoma serveryje)
        $find->execute([$agent['id']]);
        $row = $find->fetch();
        $find->closeCursor();
        if ($row) {
            $claimed = db_retry(function () use ($db, $agent, $row) {
                $claim = $db->prepare("UPDATE agent_requests SET status = 'claimed', agent_id = ?, claimed = ? WHERE id = ? AND status = 'pending'");
                $claim->execute([$agent['id'], time(), $row['id']]);
                return $claim->rowCount() === 1;
            });
            if ($claimed) {
                $st = $db->prepare('SELECT id, url, headers, ua, browser FROM agent_requests WHERE id = ?');
                $st->execute([$row['id']]);
                $job = $st->fetch();
                $headers = [];
                foreach (parse_header_lines((string)$job['headers']) as $h) {
                    [$k, $v] = array_map('trim', explode(':', $h, 2));
                    $headers[$k] = $v;
                }
                agent_out(['job' => [
                    'id' => (int)$job['id'],
                    'url' => $job['url'],
                    'ua' => $job['ua'],
                    'headers' => (object)$headers,
                    'browser' => (bool)$job['browser'],
                ]]);
            }
        }
        if (connection_aborted()) {
            exit;
        }
        if (time() - $lastTouch >= 10) {
            $touch();
            $lastTouch = time();
        }
        usleep(500000);
    } while (microtime(true) < $deadline);
    agent_out(['job' => null]);
}

if ($action === 'result') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT id FROM agent_requests WHERE id = ? AND agent_id = ? AND status = 'claimed'");
    $st->execute([$id, $agent['id']]);
    if (!$st->fetch()) {
        agent_out(['ok' => false, 'error' => 'Užklausa nebegalioja']);
    }
    $body = (string)file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024);
    // Turinys atkeliauja suspaustas ir base64 (kad hostingo WAF nemuštų HTML POST)
    if (($_SERVER['HTTP_X_BODY_ENCODING'] ?? '') === 'gzip+base64') {
        $dec = base64_decode($body, true);
        $un = $dec !== false ? @gzdecode($dec) : false;
        $body = $un !== false ? $un : (string)$dec;
    }
    if (strlen($body) > 8 * 1024 * 1024) {
        $body = substr($body, 0, 8 * 1024 * 1024);
    }
    db_retry(function () use ($db, $body, $id) {
        $up = $db->prepare("UPDATE agent_requests SET status = 'done', http_status = :status, body = :body, final_url = :final,
            content_type = :ctype, via = :via, error = :error WHERE id = :id");
        $up->bindValue(':status', (int)($_SERVER['HTTP_X_STATUS'] ?? 0), PDO::PARAM_INT);
        $up->bindValue(':body', $body, PDO::PARAM_LOB);
        $up->bindValue(':final', mb_substr(rawurldecode((string)($_SERVER['HTTP_X_FINAL_URL'] ?? '')), 0, 2000));
        $up->bindValue(':ctype', mb_substr((string)($_SERVER['HTTP_X_CONTENT_TYPE'] ?? ''), 0, 200));
        $up->bindValue(':via', mb_substr((string)($_SERVER['HTTP_X_VIA'] ?? ''), 0, 20));
        $up->bindValue(':error', mb_substr(rawurldecode((string)($_SERVER['HTTP_X_ERROR'] ?? '')), 0, 500));
        $up->bindValue(':id', $id, PDO::PARAM_INT);
        $up->execute();
    });
    db_write('UPDATE agents SET jobs_done = jobs_done + 1, last_seen = ? WHERE id = ?', [time(), $agent['id']]);
    agent_out(['ok' => true]);
}

agent_out(['error' => 'Nežinomas veiksmas'], 404);
