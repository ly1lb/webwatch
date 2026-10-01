<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

start_session();
remember_app_url();

$view = (string)($_GET['view'] ?? 'list');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = [$type, $msg];
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/* ------------------------------------------------------------------ */
/* Pirmas paleidimas / prisijungimas                                    */
/* ------------------------------------------------------------------ */

// Slaptažodžio atkūrimas: sukurkite tuščią failą data/reset-password (per File Manager).
$hasPassword = (string)setting('password_hash', '') !== '' && !is_file(WW_DATA . '/reset-password');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'setup' && !$hasPassword) {
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 8) {
        flash('Slaptažodis turi būti bent 8 simbolių', 'err');
    } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
        flash('Slaptažodžiai nesutampa', 'err');
    } else {
        set_setting('password_hash', password_hash($pw, PASSWORD_DEFAULT));
        @unlink(WW_DATA . '/reset-password');
        $email = trim((string)($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_setting('email_to', $email);
        }
        ensure_secrets();
        login_user();
        flash('Sveiki! Dabar įjunkite pranešimus ir pridėkite pirmą puslapį.');
        redirect('?view=settings');
    }
    redirect('./');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    // Bandymų ribojimas pagal IP (saugoma DB, ne sesijoje – kitaip lengva apeiti)
    $key = 'login_fail_' . substr(sha1((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
    $attempts = array_filter(json_decode((string)setting($key, '[]'), true) ?: [], fn($t) => $t > time() - 900);
    if (count($attempts) >= 8) {
        flash('Per daug bandymų. Palaukite 15 min.', 'err');
        redirect('./');
    }
    if (password_verify((string)($_POST['password'] ?? ''), (string)setting('password_hash', ''))) {
        login_user();
        set_setting($key, null);
        redirect('./');
    }
    $attempts[] = time();
    set_setting($key, json_encode(array_values($attempts)));
    usleep(700000);
    flash('Neteisingas slaptažodis', 'err');
    redirect('./');
}

if (!$hasPassword) {
    page_start('Pradžia');
    render_flash($flash);
    ?>
    <div class="card narrow">
        <h1>👋 WebWatch</h1>
        <p class="muted">Pirmas paleidimas – susikurkite slaptažodį. Juo prisijungsite iš telefono ir kompiuterio.</p>
        <form method="post" class="form">
            <input type="hidden" name="do" value="setup">
            <label>Slaptažodis<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
            <label>Pakartokite slaptažodį<input type="password" name="password2" minlength="8" required autocomplete="new-password"></label>
            <label>El. paštas pranešimams <span class="muted">(nebūtina)</span><input type="email" name="email" autocomplete="email"></label>
            <button class="btn primary">Sukurti</button>
        </form>
    </div>
    <?php
    page_end();
    exit;
}

if (!is_logged_in()) {
    page_start('Prisijungimas');
    render_flash($flash);
    ?>
    <div class="card narrow">
        <h1>🔔 WebWatch</h1>
        <form method="post" class="form">
            <input type="hidden" name="do" value="login">
            <label>Slaptažodis<input type="password" name="password" required autofocus autocomplete="current-password"></label>
            <button class="btn primary">Prisijungti</button>
        </form>
    </div>
    <?php
    page_end();
    exit;
}

ensure_secrets();

/* ------------------------------------------------------------------ */
/* POST veiksmai                                                        */
/* ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        flash('Sesija pasibaigė – bandykite dar kartą', 'err');
        redirect('./');
    }
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect('./');
    }

    if ($do === 'save_watch') {
        $id = (int)($_POST['id'] ?? 0);
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            flash('Neteisingas adresas', 'err');
            redirect('?view=edit' . ($id ? '&id=' . $id : ''));
        }
        $scope = (string)($_POST['scope'] ?? 'page');
        $data = [
            'name' => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120),
            'url' => $url,
            'selector' => $scope === 'element' ? trim((string)($_POST['selector'] ?? '')) : '',
            'compare_mode' => array_key_exists($_POST['compare_mode'] ?? '', compare_modes()) ? $_POST['compare_mode'] : 'text',
            'keyword' => trim((string)($_POST['keyword'] ?? '')),
            'number_dir' => in_array($_POST['number_dir'] ?? '', ['any', 'down', 'up'], true) ? $_POST['number_dir'] : 'any',
            'threshold' => max(0, min(100, (float)str_replace(',', '.', (string)($_POST['threshold'] ?? '0')))),
            'ignore_numbers' => !empty($_POST['ignore_numbers']) ? 1 : 0,
            'ignore_regex' => trim((string)($_POST['ignore_regex'] ?? '')),
            'interval_min' => array_key_exists((int)($_POST['interval_min'] ?? 60), interval_options()) ? (int)$_POST['interval_min'] : 60,
            'notify' => implode(',', array_intersect(WW_CHANNELS, (array)($_POST['channels'] ?? []))),
            'active' => !empty($_POST['active']) ? 1 : 0,
            'tags' => normalize_tags((string)($_POST['tags'] ?? '')),
            'headers' => trim((string)($_POST['headers'] ?? '')),
            'user_agent' => ($_POST['user_agent'] ?? '') === 'desktop' ? 'desktop' : 'mobile',
            'render_js' => !empty($_POST['render_js']) ? 1 : 0,
            'check_from' => in_array($_POST['check_from'] ?? '', ['server', 'auto', 'agent'], true) ? $_POST['check_from'] : 'server',
            'folder' => mb_substr(trim((string)($_POST['folder'] ?? '')), 0, 64),
            'sched_days' => implode(',', array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], (array)($_POST['sched_days'] ?? [])))),
            'sched_from' => preg_match('/^\d{1,2}:\d{2}$/', (string)($_POST['sched_from'] ?? '')) ? $_POST['sched_from'] : '',
            'sched_to' => preg_match('/^\d{1,2}:\d{2}$/', (string)($_POST['sched_to'] ?? '')) ? $_POST['sched_to'] : '',
            'extract_regex' => trim((string)($_POST['extract_regex'] ?? '')),
            'require_regex' => trim((string)($_POST['require_regex'] ?? '')),
            'keyword_all' => !empty($_POST['keyword_all']) ? 1 : 0,
        ];
        if ($data['name'] === '') {
            $data['name'] = mb_substr(trim((string)($_POST['auto_name'] ?? '')), 0, 120);
        }
        if (str_starts_with($data['compare_mode'], 'keyword') && $data['keyword'] === '') {
            flash('Įrašykite žodį ar frazę, kurios ieškoti', 'err');
            redirect('?view=edit' . ($id ? '&id=' . $id : ''));
        }
        if ($scope === 'element' && $data['selector'] === '') {
            flash('Pasirinkite elementą arba įrašykite CSS parinkiklį', 'err');
            redirect('?view=edit' . ($id ? '&id=' . $id : ''));
        }
        if ($id) {
            $old = get_watch($id);
            if (!$old) {
                redirect('./');
            }
            $cols = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
            $resetKeys = ['url', 'selector', 'compare_mode', 'keyword', 'ignore_numbers', 'ignore_regex', 'headers', 'user_agent', 'render_js', 'extract_regex', 'keyword_all'];
            $reset = false;
            foreach ($resetKeys as $k) {
                if ((string)$old[$k] !== (string)$data[$k]) {
                    $reset = true;
                }
            }
            if ($reset) {
                $cols .= ", last_content = NULL, fail_count = 0, value_history = '', fetch_via = ''";
            }
            $st = db()->prepare("UPDATE watches SET $cols WHERE id = :id");
            $st->execute($data + ['id' => $id]);
        } else {
            $data['created'] = time();
            $cols = implode(', ', array_keys($data));
            $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
            db()->prepare("INSERT INTO watches ($cols) VALUES ($vals)")->execute($data);
            $id = (int)db()->lastInsertId();
            $reset = true;
        }
        $w = get_watch($id);
        if ($reset && $w && $w['active']) {
            @set_time_limit(90);
            $r = run_check($w, true);
            if ($r['ok']) {
                flash('Išsaugota. Pradinė būsena užfiksuota' . ($r['changed'] ? ' – sąlyga jau įvykdyta, pranešimas išsiųstas!' : '.'));
            } else {
                flash('Išsaugota, bet patikrinti nepavyko: ' . $r['error'], 'err');
            }
        } else {
            flash('Išsaugota');
        }
        redirect('?view=watch&id=' . $id);
    }

    if ($do === 'duplicate_watch') {
        $w = get_watch((int)($_POST['id'] ?? 0));
        if ($w) {
            $copy = array_intersect_key($w, array_flip(['url', 'selector', 'compare_mode', 'keyword', 'number_dir', 'threshold',
                'ignore_numbers', 'ignore_regex', 'interval_min', 'notify', 'tags', 'headers', 'user_agent', 'render_js']));
            $copy['name'] = watch_title($w) . ' (kopija)';
            $copy['active'] = 0;
            $copy['created'] = time();
            $cols = implode(', ', array_keys($copy));
            $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($copy)));
            db()->prepare("INSERT INTO watches ($cols) VALUES ($vals)")->execute($copy);
            flash('Nukopijuota (pristabdyta). Pakeiskite ką reikia ir išsaugokite.');
            redirect('?view=edit&id=' . db()->lastInsertId());
        }
        redirect('./');
    }

    if ($do === 'mark_all_read') {
        db()->exec('UPDATE watches SET unseen = 0');
        redirect('./');
    }

    if ($do === 'import') {
        $raw = is_uploaded_file($_FILES['file']['tmp_name'] ?? '') ? file_get_contents($_FILES['file']['tmp_name']) : '';
        $data = json_decode((string)$raw, true);
        $list = $data['watches'] ?? (is_array($data) && array_is_list($data) ? $data : null);
        if (!is_array($list)) {
            flash('Netinkamas failas (reikia WebWatch eksporto JSON)', 'err');
            redirect('?view=settings#backup');
        }
        $allowed = ['name', 'url', 'selector', 'compare_mode', 'keyword', 'number_dir', 'threshold', 'ignore_numbers',
            'ignore_regex', 'interval_min', 'notify', 'active', 'tags', 'headers', 'user_agent', 'render_js', 'check_from',
            'folder', 'sched_days', 'sched_from', 'sched_to', 'extract_regex', 'require_regex', 'keyword_all'];
        $n = 0;
        foreach ($list as $item) {
            if (!is_array($item) || !preg_match('~^https?://~i', (string)($item['url'] ?? ''))) {
                continue;
            }
            $row = array_intersect_key($item, array_flip($allowed));
            $row = array_map(fn($v) => is_scalar($v) ? $v : '', $row);
            if (!array_key_exists((string)($row['compare_mode'] ?? 'text'), compare_modes())) {
                $row['compare_mode'] = 'text';
            }
            $row['created'] = time();
            $cols = implode(', ', array_keys($row));
            $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($row)));
            db()->prepare("INSERT INTO watches ($cols) VALUES ($vals)")->execute($row);
            $n++;
        }
        flash("Importuota stebėjimų: $n");
        redirect('./');
    }

    if ($do === 'delete_watch') {
        $wid = (int)($_POST['id'] ?? 0);
        db_write('DELETE FROM changes WHERE watch_id = ?', [$wid]);
        db_write('DELETE FROM watches WHERE id = ?', [$wid]);
        remove_shots_dir($wid);
        flash('Ištrinta');
        redirect('./');
    }

    if ($do === 'save_settings') {
        $keys = [
            'email' => ['email_to', 'email_from', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user'],
            'channels' => ['tg_token', 'tg_chat', 'ntfy_server', 'ntfy_topic', 'ntfy_token', 'webhook_url'],
            'general' => ['quiet_from', 'quiet_to'],
            'bypass' => ['jina_key', 'scrape_provider', 'scrape_key', 'render_api'],
        ];
        if (($_POST['section'] ?? '') === 'bypass') {
            set_setting('bypass_reader', !empty($_POST['bypass_reader']) ? '1' : '0');
        }
        $section = array_key_exists($_POST['section'] ?? '', $keys) ? $_POST['section'] : 'email';
        foreach ($keys[$section] as $k) {
            set_setting($k, trim((string)($_POST[$k] ?? '')));
        }
        if (($_POST['smtp_pass'] ?? '') !== '') {
            set_setting('smtp_pass', (string)$_POST['smtp_pass']);
        }
        if (!empty($_POST['smtp_clear'])) {
            set_setting('smtp_pass', '');
        }
        flash('Nustatymai išsaugoti');
        redirect('?view=settings#' . $section);
    }

    if ($do === 'change_password') {
        if (!password_verify((string)($_POST['old'] ?? ''), (string)setting('password_hash', ''))) {
            flash('Neteisingas dabartinis slaptažodis', 'err');
        } elseif (mb_strlen((string)($_POST['new'] ?? '')) < 8) {
            flash('Naujas slaptažodis per trumpas (min. 8)', 'err');
        } else {
            set_setting('password_hash', password_hash((string)$_POST['new'], PASSWORD_DEFAULT));
            login_user();
            flash('Slaptažodis pakeistas');
        }
        redirect('?view=settings');
    }

    if ($do === 'delete_sub') {
        db()->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Įrenginys pašalintas');
        redirect('?view=settings');
    }

    if ($do === 'new_cron_token') {
        set_setting('cron_token', bin2hex(random_bytes(16)));
        flash('Sugeneruotas naujas cron raktas – atnaujinkite cron užduotį');
        redirect('?view=settings#cron');
    }

    if ($do === 'clear_history') {
        $id = (int)($_POST['id'] ?? 0);
        db_write('DELETE FROM changes WHERE watch_id = ?', [$id]);
        // Pašalinam pokyčių nuotraukas (paliekam dabartinę bazę)
        foreach (glob(shots_dir($id) . '/*-*.png') ?: [] as $f) {
            @unlink($f);
        }
        flash('Istorija išvalyta');
        redirect('?view=watch&id=' . $id);
    }

    if ($do === 'add_agent') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60) ?: 'Kompiuteris';
        $prio = (int)(db()->query('SELECT COALESCE(MAX(priority), 0) + 1 FROM agents')->fetchColumn());
        db()->prepare('INSERT INTO agents (name, token, created, priority) VALUES (?, ?, ?, ?)')
            ->execute([$name, bin2hex(random_bytes(24)), time(), $prio]);
        flash('Tikrinimo taškas pridėtas – dabar įdiekite programą kompiuteryje.');
        redirect('?view=settings&agent=' . db()->lastInsertId() . '#agents');
    }

    if ($do === 'delete_agent') {
        db()->prepare('DELETE FROM agents WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Tikrinimo taškas pašalintas. Tame kompiuteryje paleiskite agentą su „--uninstall“, kad jis nustotų veikti.');
        redirect('?view=settings#agents');
    }

    if ($do === 'rotate_agent') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE agents SET token = ?, last_seen = NULL, last_ip = '', last_error = '' WHERE id = ?")
            ->execute([bin2hex(random_bytes(24)), $id]);
        flash('Sugeneruotas naujas raktas. Tame kompiuteryje paleiskite naują diegimo komandą iš naujo.');
        redirect('?view=settings&agent=' . $id . '#agents');
    }

    if ($do === 'agent_priority') {
        // Perkelia tašką eilėje aukštyn/žemyn (svarbu perdavimo tvarkai)
        $id = (int)($_POST['id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $agents = agents_all();
        $idx = array_search($id, array_column($agents, 'id'));
        if ($idx !== false) {
            $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
            if (isset($agents[$swap])) {
                $st = db()->prepare('UPDATE agents SET priority = ? WHERE id = ?');
                $st->execute([$swap, $id]);
                $st->execute([$idx, $agents[$swap]['id']]);
            }
        }
        redirect('?view=settings#agents');
    }
    if ($do === 'agent_rotate') {
        // Įjungia/išjungia tikrinimo taškų rotaciją (round-robin po visus kompiuterius)
        set_setting('agent_rotate', isset($_POST['on']) ? '1' : '0');
        redirect('?view=settings#agents');
    }
    redirect('./');
}

/* ------------------------------------------------------------------ */
/* Puslapiai                                                            */
/* ------------------------------------------------------------------ */

switch ($view) {
    case 'export':
        $rows = db()->query('SELECT name, url, selector, compare_mode, keyword, number_dir, threshold, ignore_numbers, ignore_regex,
            interval_min, notify, active, tags, headers, user_agent, render_js, check_from, folder, sched_days, sched_from, sched_to, extract_regex, require_regex, keyword_all FROM watches ORDER BY id')->fetchAll();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="webwatch-' . date('Y-m-d') . '.json"');
        echo json_encode(['app' => 'WebWatch', 'version' => WW_VERSION, 'exported' => date('c'), 'watches' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    case 'edit':
        view_edit($flash);
        break;
    case 'watch':
        view_watch($flash);
        break;
    case 'settings':
        view_settings($flash);
        break;
    default:
        view_list($flash);
}
exit;

/* ------------------------------------------------------------------ */

function page_start(string $title, bool $nav = false, string $active = ''): void
{
    $csrf = is_logged_in() ? csrf_token() : '';
    ?>
<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= h($title) ?> · WebWatch</title>
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="WebWatch">
    <meta name="csrf" content="<?= h($csrf) ?>">
    <meta name="vapid" content="<?= $csrf ? h(vapid_keys()['public']) : '' ?>">
    <meta name="unseen" content="<?= $csrf ? unseen_total() : 0 ?>">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icons/icon-192.png">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= WW_VERSION ?>">
    <script src="assets/app.js?v=<?= WW_VERSION ?>" defer></script>
</head>
<body>
<?php if ($nav): ?>
<header class="topbar">
    <a href="./" class="brand">🔔 WebWatch</a>
    <nav>
        <a href="./" class="<?= $active === 'list' ? 'on' : '' ?>">Sąrašas</a>
        <a href="?view=settings" class="<?= $active === 'settings' ? 'on' : '' ?>">Nustatymai</a>
        <a href="?view=edit" class="btn primary small">＋ Naujas</a>
    </nav>
</header>
<?php endif; ?>
<main class="wrap">
    <?php
}

function page_end(): void
{
    ?>
</main>
</body>
</html>
    <?php
}

function render_flash(?array $flash): void
{
    if ($flash) {
        echo '<div class="flash ' . ($flash[0] === 'err' ? 'err' : 'ok') . '">' . h($flash[1]) . '</div>';
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function status_dot(array $w): string
{
    if (!$w['active']) {
        return '<span class="dot paused" title="Pristabdyta"></span>';
    }
    if ($w['last_status'] === 'error') {
        return '<span class="dot err" title="Klaida"></span>';
    }
    if ($w['last_status'] === 'new') {
        return '<span class="dot new" title="Dar netikrinta"></span>';
    }
    return '<span class="dot ok" title="Veikia"></span>';
}

function cron_warning(): string
{
    $last = (int)setting('cron_last_run', '0');
    if ($last > time() - 1800) {
        return '';
    }
    $msg = $last ? 'Automatinis tikrinimas (cron) nevyko nuo ' . date('Y-m-d H:i', $last) . '.' : 'Automatinis tikrinimas (cron) dar nesukonfigūruotas.';
    return '<div class="flash warn">⏰ ' . h($msg) . ' <a href="?view=settings#cron">Kaip įjungti →</a></div>';
}

function view_list(?array $flash): void
{
    $watches = db()->query('SELECT * FROM watches ORDER BY folder, unseen DESC, active DESC, COALESCE(last_change, created) DESC')->fetchAll();
    $subs = (int)db()->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
    page_start('Stebimi puslapiai', true, 'list');
    render_flash($flash);
    echo cron_warning();
    if (!$subs && !setting('email_to')) {
        echo '<div class="flash warn">🔕 Pranešimai neįjungti. <a href="?view=settings">Įjunkite push arba el. paštą →</a></div>';
    }
    ?>
    <div id="push-hint"></div>
    <?php if (!$watches): ?>
        <div class="card empty">
            <div class="big">👀</div>
            <h2>Dar nieko nestebite</h2>
            <p class="muted">Pridėkite puslapį – naujienų skiltį, prekės kainą, registracijos formą ar bet ką kitą.</p>
            <a class="btn primary" href="?view=edit">＋ Pridėti puslapį</a>
        </div>
    <?php else: ?>
        <?php
        $allTags = [];
        foreach ($watches as $w) {
            foreach (tag_list((string)$w['tags']) as $t) {
                $allTags[mb_strtolower($t)] = $t;
            }
        }
        $unseen = array_sum(array_column($watches, 'unseen'));
        ?>
        <div class="list-head">
            <span class="muted"><?= count($watches) ?> stebimi</span>
            <span class="lh-btns">
                <?php if ($unseen): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="mark_all_read"><button class="btn small ghost">✓ Viską perskaičiau</button></form>
                <?php endif; ?>
                <button class="btn small ghost" data-check-all>↻ Tikrinti visus</button>
            </span>
        </div>
        <?php if (count($watches) > 4 || $allTags): ?>
            <input type="search" id="list-search" class="search" placeholder="🔍 Ieškoti…" autocomplete="off">
            <?php if ($allTags): ?>
                <div class="chips" id="tag-chips">
                    <button type="button" class="chip on" data-tag="">Visi</button>
                    <?php foreach ($allTags as $k => $t): ?>
                        <button type="button" class="chip" data-tag="<?= h($k) ?>"><?= h($t) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="watch-list">
        <?php $curFolder = null; foreach ($watches as $w): ?>
            <?php
            $isNew = (int)$w['unseen'] > 0;
            $folder = (string)$w['folder'];
            if ($folder !== $curFolder) {
                $curFolder = $folder;
                if ($folder !== '') {
                    echo '<div class="folder-head" data-folder="' . h(mb_strtolower($folder)) . '">📁 ' . h($folder) . '</div>';
                }
            }
            ?>
            <a class="watch-card <?= $w['active'] ? '' : 'paused' ?> <?= $isNew ? 'is-new' : '' ?>" href="?view=watch&id=<?= $w['id'] ?>" data-watch-id="<?= $w['id'] ?>" data-active="<?= (int)$w['active'] ?>"
               data-search="<?= h(mb_strtolower(watch_title($w) . ' ' . $w['url'] . ' ' . $w['tags'] . ' ' . $folder)) ?>" data-tags="<?= h(mb_strtolower(implode('|', tag_list((string)$w['tags'])))) ?>">
                <?= status_dot($w) ?>
                <div class="wc-body">
                    <div class="wc-top">
                        <strong class="wc-title"><?= h(watch_title($w)) ?></strong>
                        <?php if ($w['compare_mode'] === 'number' && ($vh = json_decode((string)$w['value_history'], true))): ?>
                            <span class="wc-value"><?= h(format_number((float)end($vh)[1])) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isNew): ?>
                        <div class="wc-new">🔴 Nauji pakeitimai<?= $w['unseen'] > 1 ? ' (' . (int)$w['unseen'] . ')' : '' ?> · <?= h(human_time((int)$w['last_change'])) ?></div>
                    <?php endif; ?>
                    <div class="wc-meta">
                        <span><?= h(compare_modes()[$w['compare_mode']][0] ?? '') ?><?= $w['selector'] !== '' ? ' · elementas' : '' ?></span>
                        <?php if (!$isNew): ?><span>Pokytis: <?= h(human_time($w['last_change'] ? (int)$w['last_change'] : null)) ?></span><?php endif; ?>
                        <span class="wc-check">Tikrinta: <?= h(human_time($w['last_check'] ? (int)$w['last_check'] : null)) ?></span>
                    </div>
                    <?php if ($w['tags'] !== ''): ?>
                        <div class="wc-tags"><?php foreach (tag_list((string)$w['tags']) as $t): ?><span class="tag"><?= h($t) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if ($w['last_status'] === 'error'): ?>
                        <div class="wc-error">⚠️ <?= h($w['last_error']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($isNew): ?><span class="wc-chevron">›</span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif;
    page_end();
}

function view_edit(?array $flash): void
{
    $id = (int)($_GET['id'] ?? 0);
    $w = $id ? get_watch($id) : null;
    if ($id && !$w) {
        redirect('./');
    }
    $w = $w ?: [
        'id' => 0, 'name' => '', 'url' => (string)($_GET['url'] ?? ''), 'selector' => '', 'compare_mode' => 'text',
        'keyword' => '', 'number_dir' => 'any', 'threshold' => 0, 'ignore_numbers' => 0, 'ignore_regex' => '',
        'interval_min' => 60, 'notify' => 'push,fallback', 'active' => 1, 'tags' => '', 'headers' => '',
        'user_agent' => 'mobile', 'render_js' => 0, 'check_from' => agents_all() ? 'auto' : 'server',
        'folder' => (string)($_GET['folder'] ?? ''), 'sched_days' => '', 'sched_from' => '', 'sched_to' => '',
        'extract_regex' => '', 'require_regex' => '', 'keyword_all' => 0,
    ];
    $channels = parse_channels((string)$w['notify']);
    $hasAgents = count(agents_all()) > 0;
    $schedDays = array_filter(array_map('trim', explode(',', (string)$w['sched_days'])));
    $allFolders = array_values(array_filter(array_unique(array_map(fn($r) => (string)$r['folder'], db()->query("SELECT DISTINCT folder FROM watches WHERE folder <> ''")->fetchAll()))));
    page_start($id ? 'Redaguoti' : 'Naujas stebėjimas', true, 'edit');
    render_flash($flash);
    $scope = $w['selector'] !== '' ? 'element' : 'page';
    ?>
    <h1><?= $id ? 'Redaguoti' : 'Naujas stebėjimas' ?></h1>
    <form method="post" class="form" id="watch-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save_watch">
        <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">

        <div class="card">
            <label>Puslapio adresas
                <input type="url" name="url" id="f-url" value="<?= h($w['url']) ?>" placeholder="https://..." required inputmode="url" autocapitalize="off" autocorrect="off">
            </label>
            <label>Pavadinimas <span class="muted">(nebūtina)</span>
                <input type="text" name="name" id="f-name" value="<?= h($w['name']) ?>" placeholder="pvz. Naujienos, iPhone kaina">
                <input type="hidden" name="auto_name" id="f-auto-name" value="">
            </label>
            <label>Žymos <span class="muted">(nebūtina, kableliais)</span>
                <input type="text" name="tags" value="<?= h($w['tags']) ?>" placeholder="pvz. kainos, darbas" autocapitalize="off">
            </label>
        </div>

        <div class="card">
            <h3>Ką stebėti?</h3>
            <div class="seg">
                <label><input type="radio" name="scope" value="page" <?= $scope === 'page' ? 'checked' : '' ?>><span>Visą puslapį</span></label>
                <label><input type="radio" name="scope" value="element" <?= $scope === 'element' ? 'checked' : '' ?>><span>Tik elementą</span></label>
            </div>
            <div data-show-scope="element">
                <button type="button" class="btn block" id="open-picker">🎯 Pasirinkti elementą puslapyje</button>
                <label>CSS parinkiklis arba XPath
                    <input type="text" name="selector" id="f-selector" value="<?= h($w['selector']) ?>" placeholder="pvz. #kaina, .news-list li, //h1" autocapitalize="off" autocorrect="off" spellcheck="false">
                </label>
                <p class="hint">Paspauskite „Pasirinkti“ ir bakstelėkite norimą vietą puslapyje. Galite rinktis ir kelis panašius elementus (pvz. visas naujienų antraštes).</p>
            </div>
        </div>

        <div class="card">
            <h3>Kada pranešti?</h3>
            <div class="radio-list">
                <?php foreach (compare_modes() as $k => [$label, $desc]): ?>
                    <label class="radio-row">
                        <input type="radio" name="compare_mode" value="<?= h($k) ?>" <?= $w['compare_mode'] === $k ? 'checked' : '' ?>>
                        <span><strong><?= h($label) ?></strong><small><?= h($desc) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div data-show-mode="keyword_appear keyword_disappear added">
                <label><span data-show-mode="keyword_appear keyword_disappear">Žodis ar frazė</span><span data-show-mode="added">Filtras: tik naujos eilutės su žodžiu <span class="muted">(nebūtina)</span></span> <span class="muted">– kelis atskirkite |</span>
                    <input type="text" name="keyword" id="f-keyword" value="<?= h($w['keyword']) ?>" placeholder="pvz. Yra sandėlyje | In stock">
                </label>
                <label class="check" data-show-mode="keyword_appear keyword_disappear"><input type="checkbox" name="keyword_all" value="1" <?= !empty($w['keyword_all']) ? 'checked' : '' ?>> Reikia <b>visų</b> žodžių (IR), o ne bet kurio</label>
            </div>
            <div data-show-mode="number">
                <label>Kryptis
                    <select name="number_dir">
                        <option value="any" <?= $w['number_dir'] === 'any' ? 'selected' : '' ?>>Bet koks pokytis</option>
                        <option value="down" <?= $w['number_dir'] === 'down' ? 'selected' : '' ?>>Tik sumažėjus (kaina nukrito)</option>
                        <option value="up" <?= $w['number_dir'] === 'up' ? 'selected' : '' ?>>Tik padidėjus</option>
                    </select>
                </label>
            </div>
            <div data-show-mode="text added html number">
                <label>Jautrumas: pranešti, kai pasikeičia bent <b id="thr-val"><?= h((string)(float)$w['threshold']) ?></b> %
                    <input type="range" name="threshold" id="f-threshold" min="0" max="50" step="0.5" value="<?= h((string)(float)$w['threshold']) ?>">
                </label>
                <p class="hint">0 % – apie bet kokį pakeitimą. Didesnė reikšmė ignoruoja smulkmenas (pvz. besikeičiančią datą ar reklamą).</p>
            </div>
        </div>

        <div class="card">
            <h3>Tikrinimas ir pranešimai</h3>
            <label>Kaip dažnai tikrinti
                <select name="interval_min">
                    <?php foreach (interval_options() as $min => $label): ?>
                        <option value="<?= $min ?>" <?= (int)$w['interval_min'] === $min ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <fieldset class="channels">
                <legend>Kur pranešti</legend>
                <?php foreach (channel_labels() as $k => [$label, $needs]): ?>
                    <?php $configured = $needs === '' || (string)setting($needs, '') !== ''; ?>
                    <label class="check">
                        <input type="checkbox" name="channels[]" value="<?= $k ?>" <?= in_array($k, $channels, true) ? 'checked' : '' ?>>
                        <span><?= h($label) ?><?php if (!$configured): ?> <a class="muted small" href="?view=settings#channels">(nesukonfigūruota)</a><?php endif; ?></span>
                    </label>
                <?php endforeach; ?>
                <p class="hint">Nieko nepažymėjus – pokyčiai tik išsaugomi istorijoje.</p>
            </fieldset>
            <label class="check"><input type="checkbox" name="active" value="1" <?= $w['active'] ? 'checked' : '' ?>> Aktyvus</label>
            <label>Aplankas <span class="muted">(nebūtina – grupavimui sąraše)</span>
                <input type="text" name="folder" value="<?= h($w['folder']) ?>" list="folders" placeholder="pvz. Darbas, Pirkiniai" maxlength="64" autocapitalize="off">
                <datalist id="folders"><?php foreach ($allFolders as $f): ?><option value="<?= h($f) ?>"></option><?php endforeach; ?></datalist>
            </label>
            <details>
                <summary>⏰ Tvarkaraštis (tikrinti tik tam tikru laiku)</summary>
                <p class="hint">Palikite tuščia – tikrinama visada. Galite riboti pagal savaitės dienas ir valandas.</p>
                <div class="weekdays">
                    <?php foreach (['1' => 'Pr', '2' => 'An', '3' => 'Tr', '4' => 'Kt', '5' => 'Pn', '6' => 'Št', '7' => 'Sk'] as $d => $lbl): ?>
                        <label class="wd"><input type="checkbox" name="sched_days[]" value="<?= $d ?>" <?= in_array($d, $schedDays, true) ? 'checked' : '' ?>><span><?= $lbl ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="row2">
                    <label>Nuo<input type="time" name="sched_from" value="<?= h($w['sched_from']) ?>"></label>
                    <label>Iki<input type="time" name="sched_to" value="<?= h($w['sched_to']) ?>"></label>
                </div>
            </details>
            <details>
                <summary>Papildomi nustatymai</summary>
                <label class="check"><input type="checkbox" name="ignore_numbers" id="f-ignore-numbers" value="1" <?= $w['ignore_numbers'] ? 'checked' : '' ?>> Ignoruoti skaičių pokyčius (datos, laikai, skaitliukai)</label>
                <label>Ignoruoti tekstą (reguliarios išraiškos, po vieną eilutėje)
                    <textarea name="ignore_regex" id="f-ignore-regex" rows="3" placeholder="pvz. Atnaujinta:.*&#10;\d+ komentar\w+" autocapitalize="off" spellcheck="false"><?= h($w['ignore_regex']) ?></textarea>
                </label>
                <label>Ištraukti reikšmę (reguliari išraiška) <span class="muted">(nebūtina)</span>
                    <input type="text" name="extract_regex" value="<?= h($w['extract_regex']) ?>" placeholder="pvz. (\d+[.,]\d+)\s*€ – lyginama tik rasta reikšmė" autocapitalize="off" spellcheck="false">
                </label>
                <label>Pranešti tik jei naujas turinys atitinka <span class="muted">(žodis arba reguliari išraiška, nebūtina)</span>
                    <input type="text" name="require_regex" value="<?= h($w['require_regex']) ?>" placeholder="pvz. sandėlyje|in stock" autocapitalize="off" spellcheck="false">
                </label>
                <label>Naršyklės tipas
                    <select name="user_agent" id="f-ua">
                        <option value="mobile" <?= $w['user_agent'] === 'mobile' ? 'selected' : '' ?>>Telefonas (iPhone Safari)</option>
                        <option value="desktop" <?= $w['user_agent'] === 'desktop' ? 'selected' : '' ?>>Kompiuteris (Chrome)</option>
                    </select>
                </label>
                <label>HTTP antraštės / slapukai <span class="muted">(puslapiams, kuriems reikia prisijungti)</span>
                    <textarea name="headers" id="f-headers" rows="3" placeholder="Cookie: sesija=abc123&#10;Authorization: Bearer ..." autocapitalize="off" spellcheck="false"><?= h($w['headers']) ?></textarea>
                </label>
                <details class="howto">
                    <summary>📋 Įklijuoti iš kompiuterio naršyklės (cURL)</summary>
                    <p class="hint">Chrome kompiuteryje: prisijunkite svetainėje → F12 → <b>Network</b> → perkraukite puslapį → dešiniu pelės mygtuku ant pirmos užklausos → <b>Copy → Copy as cURL (bash)</b> → įklijuokite čia. Slapukai ir naršyklės tipas bus perkelti automatiškai.</p>
                    <textarea id="curl-paste" rows="3" placeholder="curl 'https://...' -H 'cookie: ...'" autocapitalize="off" spellcheck="false"></textarea>
                </details>
                <label class="check"><input type="checkbox" name="render_js" id="f-render-js" value="1" <?= $w['render_js'] ? 'checked' : '' ?>>
                    <span>Visada per debesies naršyklę <span class="muted">(puslapiams, kurie turinį krauna per JavaScript; užblokuoti puslapiai perjungiami automatiškai ir be šios varnelės)</span></span></label>
                <label>Iš kur tikrinti
                    <select name="check_from">
                        <option value="server" <?= $w['check_from'] === 'server' ? 'selected' : '' ?>>Tik hostingo serveris</option>
                        <option value="auto" <?= $w['check_from'] === 'auto' ? 'selected' : '' ?>>Serveris, o jei nepavyksta – namų kompiuteriai</option>
                        <option value="agent" <?= $w['check_from'] === 'agent' ? 'selected' : '' ?>>Tik namų kompiuteriai</option>
                    </select>
                </label>
                <?php if (!$hasAgents): ?>
                    <p class="hint">Namų kompiuterius pridėsite <a href="?view=settings#agents">Nustatymuose</a> – tada svetaines, kurios blokuoja serverį, tikrins jūsų kompiuteriai.</p>
                <?php endif; ?>
            </details>
        </div>

        <div class="card">
            <button type="button" class="btn block" id="test-extract">🔍 Išbandyti – ką matys WebWatch?</button>
            <div id="test-result" class="test-result" hidden></div>
        </div>

        <div class="actions sticky">
            <a href="<?= $id ? '?view=watch&id=' . $id : './' ?>" class="btn ghost">Atšaukti</a>
            <button class="btn primary">💾 Išsaugoti</button>
        </div>
    </form>

    <div class="picker" id="picker" hidden>
        <div class="picker-bar">
            <button type="button" class="btn small ghost" data-picker="close">✕</button>
            <span class="picker-title">Bakstelėkite elementą</span>
            <button type="button" class="btn small ghost" data-picker="declutter" title="Paslėpti slapukų juostas ir užsklandas">🍪 Slėpti juostas</button>
        </div>
        <iframe id="picker-frame" sandbox="allow-scripts" referrerpolicy="no-referrer" title="Puslapio peržiūra"></iframe>
        <div class="picker-panel">
            <div class="picker-info" id="picker-info">Kraunama…</div>
            <div class="picker-btns">
                <button type="button" class="btn small" data-picker="parent" disabled>⬆ Didesnė sritis</button>
                <button type="button" class="btn small" data-picker="similar" disabled>☰ Visi panašūs</button>
                <button type="button" class="btn small primary" data-picker="use" disabled>✓ Naudoti</button>
            </div>
        </div>
    </div>
    <?php
    page_end();
}

function view_watch(?array $flash): void
{
    $id = (int)($_GET['id'] ?? 0);
    $w = get_watch($id);
    if (!$w) {
        redirect('./');
    }
    if ($w['unseen'] > 0) {
        db()->prepare('UPDATE watches SET unseen = 0 WHERE id = ?')->execute([$id]);
    }
    // Turinys nekraunamas visiems įrašams – skirtumai atsisiunčiami tik atidarius (api.php?action=diff).
    $st = db()->prepare('SELECT id, created, summary, change_pct, has_shot, old_content IS NOT NULL AS has_old FROM changes WHERE watch_id = ? ORDER BY id DESC LIMIT ' . WW_KEEP_CHANGES);
    $st->execute([$id]);
    $changes = $st->fetchAll();
    page_start(watch_title($w), true, 'watch');
    render_flash($flash);
    ?>
    <div class="watch-head">
        <h1><?= status_dot($w) ?> <?= h(watch_title($w)) ?></h1>
        <a class="url" href="<?= h($w['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($w['url']) ?> ↗</a>
    </div>

    <div class="card stats">
        <div><small>Režimas</small><b><?= h(compare_modes()[$w['compare_mode']][0] ?? '') ?></b></div>
        <div><small>Stebima</small><b class="mono"><?= $w['selector'] !== '' ? h($w['selector']) : 'Visas puslapis' ?></b></div>
        <?php if ($w['keyword'] !== '' && str_starts_with($w['compare_mode'], 'keyword')): ?>
            <div><small>Frazė</small><b>„<?= h($w['keyword']) ?>“</b></div>
        <?php endif; ?>
        <div><small>Dažnis</small><b><?= h(interval_label((int)$w['interval_min'])) ?><?= $w['active'] ? '' : ' (pristabdyta)' ?></b></div>
        <div><small>Paskutinis tikrinimas</small><b><?= h(human_time($w['last_check'] ? (int)$w['last_check'] : null)) ?></b></div>
        <div><small>Paskutinis pokytis</small><b><?= h(human_time($w['last_change'] ? (int)$w['last_change'] : null)) ?></b></div>
        <?php if (($w['fetch_via'] ?? '') !== '' && $w['fetch_via'] !== 'direct'): ?>
            <div><small>Gaunama</small><b><?= h(WW_VIA_LABELS[$w['fetch_via']] ?? $w['fetch_via']) ?></b></div>
        <?php endif; ?>
        <div><small>Pranešimai</small><b><?php
            $ch = parse_channels((string)$w['notify']);
            echo $ch ? h(implode(', ', array_map(fn($c) => trim(preg_replace('/^\S+\s/u', '', channel_labels()[$c][0])), $ch))) : 'išjungti';
        ?></b></div>
    </div>
    <?php if ($w['compare_mode'] === 'number'): ?>
        <?= value_chart((string)$w['value_history']) ?>
    <?php endif; ?>
    <?php if ($w['last_status'] === 'error'): ?>
        <div class="flash err">⚠️ <?= h($w['last_error']) ?> (<?= (int)$w['fail_count'] ?> k. iš eilės)
            <?php if (str_contains((string)$w['last_error'], 'blokuoja')): ?><br><a href="?view=settings#bypass">🛡️ Apsaugos apėjimo nustatymai →</a><?php endif; ?></div>
    <?php endif; ?>

    <div class="actions wrap-btns">
        <button class="btn primary" data-check-now="<?= $id ?>">↻ Tikrinti dabar</button>
        <a class="btn" href="?view=edit&id=<?= $id ?>">✎ Redaguoti</a>
        <button class="btn" data-toggle="<?= $id ?>"><?= $w['active'] ? '⏸ Pristabdyti' : '▶ Tęsti' ?></button>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="do" value="duplicate_watch"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn">⧉ Kopijuoti</button>
        </form>
        <form method="post" onsubmit="return confirm('Ištrinti šį stebėjimą?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete_watch"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn danger">🗑 Ištrinti</button>
        </form>
    </div>

    <?php
    // Statistika
    $stTimes = db()->prepare('SELECT created FROM changes WHERE watch_id = ? ORDER BY created');
    $stTimes->execute([$id]);
    $times = array_map('intval', array_column($stTimes->fetchAll(), 'created'));
    if ($times):
        $now = time();
        $tot = count($times);
        $d30 = count(array_filter($times, fn($t) => $t > $now - 30 * 86400));
        $d7 = count(array_filter($times, fn($t) => $t > $now - 7 * 86400));
        $avg = '';
        if ($tot >= 2) {
            $avg = human_duration((int)(($times[$tot - 1] - $times[0]) / ($tot - 1)));
        }
        // Savaitinė histograma (12 sav.)
        $weeks = array_fill(0, 12, 0);
        foreach ($times as $t) {
            $wi = 11 - (int)floor(($now - $t) / (7 * 86400));
            if ($wi >= 0 && $wi < 12) {
                $weeks[$wi]++;
            }
        }
        $max = max($weeks) ?: 1;
    ?>
    <div class="card">
        <div class="wstats">
            <span>Iš viso pokyčių: <b><?= $tot ?></b></span>
            <span>Per 30 d.: <b><?= $d30 ?></b></span>
            <span>Per 7 d.: <b><?= $d7 ?></b></span>
            <?php if ($avg): ?><span>Vidutiniškai kas <b><?= h($avg) ?></b></span><?php endif; ?>
        </div>
        <div class="spark" title="Pokyčiai per paskutines 12 savaičių">
            <?php foreach ($weeks as $cnt): ?><i style="height:<?= (int)round($cnt / $max * 100) ?>%" title="<?= $cnt ?>"></i><?php endforeach; ?>
        </div>
        <div class="wstats"><span class="muted">← prieš 12 sav.</span><span class="muted" style="margin-left:auto">ši sav. →</span></div>
    </div>
    <?php endif; ?>

    <h2>Pokyčių istorija</h2>
    <?php if (!$changes): ?>
        <p class="muted">Pokyčių dar neužfiksuota.</p>
    <?php else: ?>
        <?php foreach ($changes as $i => $c): ?>
            <?php
            $hasShot = !empty($c['has_shot']);
            $hasDiff = !$hasShot && in_array($w['compare_mode'], ['text', 'added', 'html'], true) && $c['has_old'];
            ?>
            <details class="card change" <?= $i === 0 ? 'open' : '' ?> <?= $hasDiff ? 'data-diff="' . (int)$c['id'] . '"' : '' ?>>
                <summary>
                    <span class="ch-date"><?= date('Y-m-d H:i', (int)$c['created']) ?></span>
                    <span class="ch-pct"><?= $c['change_pct'] > 0 ? h(number_format((float)$c['change_pct'], $c['change_pct'] < 1 ? 2 : 0, ',', '')) . ' %' : '' ?></span>
                    <span class="ch-sum"><?= h(mb_substr((string)$c['summary'], 0, 160)) ?></span>
                </summary>
                <?php if ($hasShot): ?>
                    <div class="shots">
                        <div class="shot-tabs">
                            <button type="button" class="st-btn on" data-shot="diff">Pakeitimai</button>
                            <button type="button" class="st-btn" data-shot="new">Dabar</button>
                            <button type="button" class="st-btn" data-shot="old">Buvo</button>
                        </div>
                        <a href="shot.php?change=<?= (int)$c['id'] ?>&t=diff" target="_blank" rel="noopener">
                            <img class="shot-img" loading="lazy" src="shot.php?change=<?= (int)$c['id'] ?>&t=diff"
                                 data-diff="shot.php?change=<?= (int)$c['id'] ?>&t=diff"
                                 data-new="shot.php?change=<?= (int)$c['id'] ?>&t=new"
                                 data-old="shot.php?change=<?= (int)$c['id'] ?>&t=old" alt="Pakeitimai">
                        </a>
                        <p class="hint">Raudonai pažymėta, kas pasikeitė. Bakstelėkite „Dabar“ / „Buvo“ palyginimui, arba paveikslėlį – pilnam dydžiui.</p>
                    </div>
                <?php elseif ($hasDiff): ?>
                    <div class="diff"><div class="d-skip">Kraunama…</div></div>
                <?php else: ?>
                    <pre class="summary"><?= h((string)$c['summary']) ?></pre>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        <form method="post" onsubmit="return confirm('Išvalyti istoriją?')" class="right">
            <?= csrf_field() ?><input type="hidden" name="do" value="clear_history"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn small ghost">Išvalyti istoriją</button>
        </form>
    <?php endif; ?>

    <?php if ($w['compare_mode'] === 'visual'): ?>
        <?php if (is_file(shots_dir($id) . '/current.png')): ?>
            <details class="card">
                <summary>Dabartinė nuotrauka</summary>
                <a href="shot.php?watch=<?= $id ?>&t=current" target="_blank" rel="noopener"><img class="shot-img" loading="lazy" src="shot.php?watch=<?= $id ?>&t=current" alt="Dabartinė nuotrauka"></a>
            </details>
        <?php endif; ?>
    <?php else: ?>
        <details class="card">
            <summary>Dabartinis turinys (<?= number_format(mb_strlen((string)$w['last_content']), 0, ',', ' ') ?> simb.)</summary>
            <pre class="content"><?= h(mb_substr((string)$w['last_content'], 0, 20000)) ?></pre>
        </details>
    <?php endif; ?>
    <?php
    page_end();
}

function view_settings(?array $flash): void
{
    $subs = db()->query('SELECT * FROM subscriptions ORDER BY created DESC')->fetchAll();
    $logs = db()->query('SELECT * FROM log ORDER BY id DESC LIMIT 30')->fetchAll();
    $token = (string)setting('cron_token');
    $cronUrl = app_url() . 'cron.php?token=' . $token;
    $cronPath = WW_ROOT . '/cron.php';
    page_start('Nustatymai', true, 'settings');
    render_flash($flash);
    ?>
    <h1>Nustatymai</h1>

    <section class="card" id="push">
        <h2>📱 Push pranešimai šiame įrenginyje</h2>
        <div id="push-status" class="push-status muted">Tikrinama…</div>
        <div class="actions wrap-btns">
            <button class="btn primary" id="push-enable" hidden>🔔 Įjungti pranešimus</button>
            <button class="btn" id="push-disable" hidden>Išjungti šiame įrenginyje</button>
            <button class="btn" id="push-test">Siųsti bandomąjį</button>
        </div>
        <details class="howto">
            <summary>Kaip įjungti iPhone?</summary>
            <ol>
                <li>Atidarykite šį puslapį <b>Safari</b> naršyklėje (reikia iOS 16.4 ar naujesnės).</li>
                <li>Spauskite <b>Bendrinti</b> (kvadratas su rodykle) → <b>„Add to Home Screen“ / „Pridėti prie pradžios ekrano“</b>.</li>
                <li>Atidarykite <b>WebWatch</b> programėlę iš pradžios ekrano ir prisijunkite.</li>
                <li>Čia, Nustatymuose, spauskite <b>„Įjungti pranešimus“</b> ir leiskite.</li>
            </ol>
            <p class="muted">Android / kompiuteryje – tiesiog spauskite „Įjungti pranešimus“ naršyklėje.</p>
        </details>
        <?php if ($subs): ?>
            <h3>Užregistruoti įrenginiai</h3>
            <ul class="subs">
                <?php foreach ($subs as $s): ?>
                    <li>
                        <div>
                            <b><?= h($s['label']) ?></b>
                            <small class="muted">nuo <?= date('Y-m-d', (int)$s['created']) ?><?= $s['last_ok'] ? ' · paskutinis pristatytas ' . h(human_time((int)$s['last_ok'])) : '' ?></small>
                            <?php if ($s['last_error']): ?><small class="err-text"><?= h($s['last_error']) ?></small><?php endif; ?>
                        </div>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="delete_sub"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn small ghost">✕</button></form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card" id="email">
        <h2>✉️ El. paštas</h2>
        <p class="muted">Naudojamas, kai push neįjungtas / nepavyko, arba jei stebėjime pasirinkta siųsti el. paštu.</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save_settings">
            <input type="hidden" name="section" value="email">
            <label>Gavėjas (jūsų el. paštas)<input type="email" name="email_to" value="<?= h(setting('email_to', '')) ?>"></label>
            <label>Siuntėjas <span class="muted">(pvz. webwatch@jusu-domenas.lt)</span><input type="email" name="email_from" value="<?= h(setting('email_from', '')) ?>"></label>
            <details <?= setting('smtp_host') ? 'open' : '' ?>>
                <summary>SMTP (rekomenduojama – laiškai nepateks į šlamštą)</summary>
                <p class="hint">Hostinger: susikurkite pašto dėžutę hPanel → Emails, tada: serveris <code>smtp.hostinger.com</code>, prievadas <code>465</code>, SSL, vartotojas – pilnas el. pašto adresas. Siuntėjas turi sutapti su vartotoju.</p>
                <label>SMTP serveris<input type="text" name="smtp_host" value="<?= h(setting('smtp_host', '')) ?>" placeholder="smtp.hostinger.com" autocapitalize="off"></label>
                <div class="row2">
                    <label>Prievadas<input type="number" name="smtp_port" value="<?= h(setting('smtp_port', '465')) ?>"></label>
                    <label>Šifravimas
                        <select name="smtp_secure">
                            <?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'Nėra'] as $k => $l): ?>
                                <option value="<?= $k ?>" <?= setting('smtp_secure', 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label>Vartotojas<input type="text" name="smtp_user" value="<?= h(setting('smtp_user', '')) ?>" autocapitalize="off" autocomplete="off"></label>
                <label>Slaptažodis <span class="muted"><?= setting('smtp_pass') ? '(išsaugotas – palikite tuščią, jei nekeičiate)' : '' ?></span><input type="password" name="smtp_pass" autocomplete="new-password"></label>
            </details>
            <div class="actions wrap-btns">
                <button class="btn primary">Išsaugoti</button>
                <button type="button" class="btn" id="email-test">Siųsti bandomąjį laišką</button>
            </div>
        </form>
    </section>

    <section class="card" id="channels">
        <h2>✈️ Kiti pranešimų kanalai</h2>
        <p class="muted">Nebūtina. Pažymėkite juos konkretaus stebėjimo nustatymuose.</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save_settings">
            <input type="hidden" name="section" value="channels">
            <details <?= setting('tg_token') ? 'open' : '' ?>>
                <summary>Telegram</summary>
                <ol class="hint-list">
                    <li>Telegram'e parašykite <b>@BotFather</b> → <code>/newbot</code> → gausite raktą (token).</li>
                    <li>Įklijuokite jį čia ir išsaugokite.</li>
                    <li>Parašykite savo naujam botui bet ką, tada spauskite „Rasti chat ID“.</li>
                </ol>
                <label>Boto raktas (token)<input type="text" name="tg_token" value="<?= h(setting('tg_token', '')) ?>" placeholder="123456:ABC-DEF..." autocapitalize="off" autocomplete="off" spellcheck="false"></label>
                <label>Chat ID<input type="text" name="tg_chat" id="tg-chat" value="<?= h(setting('tg_chat', '')) ?>" autocapitalize="off"></label>
                <div class="actions wrap-btns">
                    <button type="button" class="btn small" data-tg-chats>Rasti chat ID</button>
                    <button type="button" class="btn small" data-test-channel="telegram">Siųsti bandomąjį</button>
                </div>
            </details>
            <details <?= setting('ntfy_topic') ? 'open' : '' ?>>
                <summary>ntfy (alternatyvūs push per ntfy programėlę)</summary>
                <p class="hint">Įdiekite <b>ntfy</b> programėlę (App Store / Google Play), prenumeruokite sugalvotą temą ir įrašykite ją čia. Temą rinkitės sunkiai atspėjamą.</p>
                <label>Serveris<input type="url" name="ntfy_server" value="<?= h(setting('ntfy_server', '')) ?>" placeholder="https://ntfy.sh" autocapitalize="off"></label>
                <label>Tema (topic)<input type="text" name="ntfy_topic" value="<?= h(setting('ntfy_topic', '')) ?>" placeholder="pvz. webwatch-<?= h(substr((string)setting('cron_token'), 0, 8)) ?>" autocapitalize="off"></label>
                <label>Prieigos raktas <span class="muted">(jei serveris reikalauja)</span><input type="text" name="ntfy_token" value="<?= h(setting('ntfy_token', '')) ?>" autocapitalize="off" autocomplete="off"></label>
                <button type="button" class="btn small" data-test-channel="ntfy">Siųsti bandomąjį</button>
            </details>
            <details <?= setting('webhook_url') ? 'open' : '' ?>>
                <summary>Webhook (Discord, Slack, Home Assistant, n8n…)</summary>
                <p class="hint">Discord ir Slack adresai atpažįstami automatiškai. Kitiems siunčiamas JSON: <code>{title, body, url, time}</code>.</p>
                <label>Webhook adresas<input type="url" name="webhook_url" value="<?= h(setting('webhook_url', '')) ?>" placeholder="https://discord.com/api/webhooks/..." autocapitalize="off"></label>
                <button type="button" class="btn small" data-test-channel="webhook">Siųsti bandomąjį</button>
            </details>
            <div class="actions"><button class="btn primary">Išsaugoti</button></div>
        </form>
    </section>

    <section class="card" id="general">
        <h2>🌙 Tylios valandos ir kita</h2>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save_settings">
            <input type="hidden" name="section" value="general">
            <p class="hint">Tyliomis valandomis pranešimai kaupiami ir išsiunčiami joms pasibaigus (vienu suvestiniu, jei jų daug). Palikite tuščia, jei nereikia.</p>
            <div class="row2">
                <label>Nuo<input type="time" name="quiet_from" value="<?= h(setting('quiet_from', '')) ?>"></label>
                <label>Iki<input type="time" name="quiet_to" value="<?= h(setting('quiet_to', '')) ?>"></label>
            </div>
            <div class="actions"><button class="btn primary">Išsaugoti</button></div>
        </form>
    </section>

    <section class="card" id="bypass">
        <h2>🛡️ Apsaugos nuo robotų apėjimas</h2>
        <p class="muted">Android programėlės tikrina iš paties telefono, o WebWatch – iš serverio, kurį dalis svetainių (Cloudflare ir pan.) blokuoja.
            Todėl WebWatch pats bando kelis būdus iš eilės ir įsimena tą, kuris veikia:</p>
        <ol class="hint-list">
            <li><b>Tiesiogiai</b>, apsimetant tikra naršykle (slapukai, antraštės, kitas naršyklės tipas) – nemokama.</li>
            <li><b>Jina Reader</b> – tikra naršyklė debesyje, nemokama, be registracijos.</li>
            <li><b>Apėjimo paslauga</b> – patikimiausia prieš griežtas apsaugas (reikia API rakto, yra nemokami kreditai).</li>
        </ol>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save_settings">
            <input type="hidden" name="section" value="bypass">
            <label class="check"><input type="checkbox" name="bypass_reader" value="1" <?= reader_enabled() ? 'checked' : '' ?>>
                <span>Naudoti Jina Reader, kai svetainė blokuoja <span class="muted">(užblokuoto puslapio adresas perduodamas r.jina.ai; puslapiams su slapukais nenaudojama)</span></span></label>
            <label>Jina API raktas <span class="muted">(nebūtina – didesni limitai, <a href="https://jina.ai/reader" target="_blank" rel="noopener">jina.ai</a>)</span>
                <input type="text" name="jina_key" value="<?= h(setting('jina_key', '')) ?>" autocapitalize="off" autocomplete="off" spellcheck="false"></label>
            <label>Apėjimo paslauga
                <select name="scrape_provider" id="scrape-provider">
                    <option value="">— nenaudoti —</option>
                    <?php foreach (scrape_providers() as $k => [$label, $site]): ?>
                        <option value="<?= h($k) ?>" <?= setting('scrape_provider', setting('render_api') ? 'custom' : '') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label data-provider="scrapingbee scraperapi zenrows">API raktas
                <input type="text" name="scrape_key" value="<?= h(setting('scrape_key', '')) ?>" autocapitalize="off" autocomplete="off" spellcheck="false"></label>
            <label data-provider="custom">Paslaugos adresas su <code>{url}</code>
                <input type="text" name="render_api" value="<?= h(setting('render_api', '')) ?>" placeholder="https://.../?key=RAKTAS&url={url}" autocapitalize="off" spellcheck="false"></label>
            <p class="hint">Rekomenduojama: <a href="https://www.scrapingbee.com" target="_blank" rel="noopener">ScrapingBee</a> (1000 nemokamų kreditų, užklausa per apsaugą kainuoja ~75),
                <a href="https://www.scraperapi.com" target="_blank" rel="noopener">ScraperAPI</a> (5000 nemok.), <a href="https://www.zenrows.com" target="_blank" rel="noopener">ZenRows</a>.
                Paslauga naudojama <b>tik užblokuotiems puslapiams</b>, todėl kreditai eikvojami taupiai – tokiems puslapiams rinkitės tikrinimą kas valandą ar rečiau.</p>
            <div class="actions wrap-btns">
                <button class="btn primary">Išsaugoti</button>
            </div>
        </form>
        <form class="form test-url-form" id="bypass-test">
            <label>Išbandyti svetainę<input type="url" id="bypass-url" placeholder="https://..." autocapitalize="off" inputmode="url"></label>
            <button type="submit" class="btn">🔍 Tikrinti visus būdus</button>
            <div id="bypass-result" class="test-result" hidden></div>
        </form>
    </section>

    <section class="card" id="agents">
        <h2>🖥️ Namų kompiuteriai (tikrinimo taškai)</h2>
        <p class="muted">Jūsų pačių kompiuteriai skirtingose vietose gali tikrinti puslapius per savo interneto ryšį –
            naudinga, kai hostingo serverio adresą svetainė blokuoja. Stebėjime pasirinkite <b>„Serveris, o jei nepavyksta – namų kompiuteriai“</b>.
            Perdavimas veikia eilės tvarka: jei pirmas kompiuteris neprisijungęs ar jo ryšys neveikia, bandomas antras, tada trečias.</p>
        <p class="hint">💡 Jei svetainė blokuoja net namų kompiuterį (HTTP 403, „Just a moment“), tame kompiuteryje įdiekite
            <b>Google Chrome</b> arba <b>Microsoft Edge</b> – agentas tokius puslapius automatiškai parsiųs per tikrą naršyklę
            (tikras atspaudas ir JavaScript), o būtent to apsaugos ir tikrina.</p>
        <p class="hint">ℹ️ <b>Norint prijungti kompiuterį iš naujo</b> (pvz. atnaujinus programą) – <b>netrinkite</b> taško, o spauskite
            <b>„Įdiegti“</b> ir paleiskite komandą tame kompiuteryje. Trynimas pakeičia raktą, todėl senoji programa nustoja veikti.</p>
        <p class="hint">🩺 Jei rodo „neatsakė laiku“, priežastį matysite tame kompiuteryje: agento žurnale
            <code>~/.wwagent/agent.log</code> (Mac/Linux) arba <code>%APPDATA%\WWAgent\agent.log</code> (Windows).
            Dažniausia priežastis – sena agento versija (atnaujinkite) arba hostingo apsauga (WAF), atmetanti rezultato siuntimą –
            nuo šios versijos turinys siunčiamas suspaustas, kad to išvengtų.</p>
        <?php
        $agents = agents_all();
        $newId = (int)($_GET['agent'] ?? 0);
        ?>
        <?php if ($agents): ?>
            <ul class="agents">
                <?php foreach ($agents as $i => $a): $on = agent_is_online($a); ?>
                    <li>
                        <span class="ag-order"><?= $i + 1 ?>.</span>
                        <span class="dot <?= $on ? 'ok' : 'paused' ?>" title="<?= $on ? 'Prisijungęs' : 'Neprisijungęs' ?>"></span>
                        <div class="ag-info">
                            <b><?= h($a['name']) ?></b>
                            <small class="muted">
                                <?= $on ? 'prisijungęs' : ($a['last_seen'] ? 'matytas ' . h(human_time((int)$a['last_seen'])) : 'dar neprisijungė') ?>
                                · atlikta <?= (int)$a['jobs_done'] ?>
                                <?= $a['last_ip'] ? ' · ' . h($a['last_ip']) : '' ?>
                            </small>
                            <?php if ($a['last_error']): ?><small class="err-text">⚠️ <?= h($a['last_error']) ?></small><?php endif; ?>
                        </div>
                        <span class="ag-btns">
                            <?php if ($i > 0): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="agent_priority"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="up"><button class="btn small ghost" title="Aukštyn">↑</button></form><?php endif; ?>
                            <?php if ($i < count($agents) - 1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="agent_priority"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="down"><button class="btn small ghost" title="Žemyn">↓</button></form><?php endif; ?>
                            <a class="btn small" href="?view=settings&agent=<?= $a['id'] ?>#agents">Įdiegti</a>
                            <form method="post" onsubmit="return confirm('Ištrinti šį tašką visam laikui? Norėdami tik prijungti kompiuterį iš naujo, spauskite „Įdiegti“, o ne šį mygtuką.')"><?= csrf_field() ?><input type="hidden" name="do" value="delete_agent"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn small ghost" title="Ištrinti visam laikui">🗑</button></form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($agents) > 1): $rot = setting('agent_rotate', '1') !== '0'; ?>
                <form method="post" class="ag-rotate">
                    <?= csrf_field() ?><input type="hidden" name="do" value="agent_rotate">
                    <label class="switch-row">
                        <input type="checkbox" name="on" value="1" <?= $rot ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span><b>Sukti per kompiuterius</b> (round-robin) – kiekvienas tikrinimas eina iš kito kompiuterio, kad srautas nesklistų vis iš to paties IP.</span>
                    </label>
                    <small class="muted">Išjungus – griežta eilė: pirmas visada pirmas, kiti tik kaip atsarginiai (perdavimas kitam veikia abiem atvejais).</small>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">Dar nepridėta nė vieno kompiuterio.</p>
        <?php endif; ?>

        <?php if ($newId && ($a = array_values(array_filter($agents, fn($x) => (int)$x['id'] === $newId))[0] ?? null)): $setup = agent_setup($a); ?>
            <div class="ag-install">
                <h3>Įdiegimas: „<?= h($a['name']) ?>“</h3>
                <p class="hint">Paleiskite <b>tik tame kompiuteryje</b>, kurį norite įdarbinti. Raktas slaptas – kas jį turi, gali prisijungti kaip šis taškas.</p>
                <details open>
                    <summary><b>Windows</b></summary>
                    <p class="hint">Start mygtukas → įrašykite <b>PowerShell</b> → atidarykite → įklijuokite ir Enter:</p>
                    <textarea readonly rows="3" class="mono copy" onclick="this.select()"><?= h($setup['windows']) ?></textarea>
                </details>
                <details>
                    <summary><b>Mac / Linux</b></summary>
                    <p class="hint">Atidarykite <b>Terminal</b> ir įklijuokite (reikia Python 3, Mac/Linux jį turi):</p>
                    <textarea readonly rows="2" class="mono copy" onclick="this.select()"><?= h($setup['unix']) ?></textarea>
                </details>
                <p class="hint">Įdiegus, kompiuteris pats prisijungs (žalias taškas viršuje) ir veiks fone net po perkrovimo.
                    Programą galima ir tiesiog atsisiųsti: <a href="<?= h($setup['win_url']) ?>">Windows (.ps1)</a> ·
                    <a href="<?= h($setup['nix_url']) ?>">Mac/Linux (.py)</a>. Pašalinti: paleiskite tą pačią komandą su <code>-Install</code> → <code>-Uninstall</code> (arba <code>--uninstall</code>).</p>
                <form method="post" onsubmit="return confirm('Pakeisti raktą? Senas nustos veikti – tame kompiuteryje reikės paleisti komandą iš naujo.')">
                    <?= csrf_field() ?><input type="hidden" name="do" value="rotate_agent"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                    <button class="btn small ghost">🔑 Pakeisti raktą (jei nutekėjo)</button>
                </form>
            </div>
        <?php endif; ?>

        <form method="post" class="form ag-add">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="add_agent">
            <label>Naujo kompiuterio pavadinimas
                <input type="text" name="name" placeholder="pvz. Namai, Sodas, Pas tetą" maxlength="60" required>
            </label>
            <button class="btn primary">＋ Pridėti tikrinimo tašką</button>
        </form>
        <form class="form test-url-form" id="agent-test">
            <label>Išbandyti puslapį per namų kompiuterius<input type="url" id="agent-url" placeholder="https://..." autocapitalize="off" inputmode="url"></label>
            <button type="submit" class="btn">🖥️ Tikrinti per kompiuterius</button>
            <div id="agent-result" class="test-result" hidden></div>
        </form>
    </section>

    <section class="card" id="backup">
        <h2>💾 Atsarginė kopija</h2>
        <p class="muted">Eksportuojami visi stebėjimai su nustatymais (be istorijos). Importuojant jie pridedami prie esamų.</p>
        <div class="actions wrap-btns">
            <a class="btn" href="?view=export">⬇ Eksportuoti (JSON)</a>
            <form method="post" enctype="multipart/form-data" class="import-form">
                <?= csrf_field() ?><input type="hidden" name="do" value="import">
                <label class="btn">⬆ Importuoti<input type="file" name="file" accept=".json,application/json" hidden onchange="this.form.submit()"></label>
            </form>
        </div>
    </section>

    <section class="card" id="cron">
        <h2>⏰ Automatinis tikrinimas (cron)</h2>
        <?php $last = (int)setting('cron_last_run', '0'); ?>
        <p>Paskutinį kartą vykdyta: <b><?= $last ? h(date('Y-m-d H:i:s', $last)) . ' (' . h(human_time($last)) . ')' : 'niekada' ?></b></p>
        <p class="hint">Hostinger hPanel → <b>Advanced → Cron Jobs</b> → pasirinkite „Custom“, dažnį <b>kas 5 minutes</b> (<code>*/5 * * * *</code>; jei naudosite tikrinimą „kas minutę“ – <code>* * * * *</code>) ir įrašykite komandą:</p>
        <label>PHP komanda (rekomenduojama)
            <input type="text" readonly value="/usr/bin/php <?= h($cronPath) ?>" class="mono copy" onclick="this.select()">
        </label>
        <label>Arba per URL (pvz. cron-job.org paslaugai)
            <input type="text" readonly value="<?= h($cronUrl) ?>" class="mono copy" onclick="this.select()">
        </label>
        <form method="post" class="right"><?= csrf_field() ?><input type="hidden" name="do" value="new_cron_token"><button class="btn small ghost">Sugeneruoti naują raktą</button></form>
    </section>

    <section class="card">
        <!-- Slaptažodžio laukai paslėpti, kol neatidaroma – kitaip naršyklės slaptažodžių
             pildymas „nušoka“ prie jų spustelėjus bet kur nustatymuose. -->
        <details>
            <summary><b>🔑 Keisti slaptažodį</b></summary>
            <form method="post" class="form" style="margin-top:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="change_password">
                <label>Dabartinis<input type="password" name="old" required autocomplete="current-password"></label>
                <label>Naujas<input type="password" name="new" minlength="8" required autocomplete="new-password"></label>
                <button class="btn">Pakeisti</button>
            </form>
        </details>
    </section>

    <?php if ($logs): ?>
    <details class="card">
        <summary>Žurnalas (klaidos)</summary>
        <ul class="log">
            <?php foreach ($logs as $l): ?>
                <li class="<?= h($l['level']) ?>"><small><?= date('m-d H:i', (int)$l['created']) ?></small> <?= h($l['message']) ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>

    <form method="post" class="center">
        <?= csrf_field() ?><input type="hidden" name="do" value="logout">
        <button class="btn ghost">Atsijungti</button>
    </form>
    <p class="center muted small">WebWatch <?= WW_VERSION ?></p>
    <?php
    page_end();
}

/** SVG grafikas skaičiaus (kainos) istorijai. */
function value_chart(string $json): string
{
    $h = json_decode($json, true);
    if (!is_array($h) || count($h) < 2) {
        return '';
    }
    $h[] = [time(), end($h)[1]]; // dabartinė reikšmė iki šiandien
    $W = 600;
    $H = 170;
    $pad = [14, 12, 22, 12]; // viršus, dešinė, apačia, kairė
    $t0 = $h[0][0];
    $t1 = max($t0 + 1, end($h)[0]);
    $vals = array_column($h, 1);
    $min = min($vals);
    $max = max($vals);
    if ($max - $min < 0.000001) {
        $min -= 1;
        $max += 1;
    }
    $x = fn($t) => $pad[3] + ($t - $t0) / ($t1 - $t0) * ($W - $pad[1] - $pad[3]);
    $y = fn($v) => $pad[0] + ($max - $v) / ($max - $min) * ($H - $pad[0] - $pad[2]);
    $pts = [];
    $prevY = null;
    foreach ($h as [$t, $v]) {
        if ($prevY !== null) {
            $pts[] = round($x($t), 1) . ',' . $prevY; // laiptinė linija – kaina galioja iki kito pokyčio
        }
        $prevY = round($y($v), 1);
        $pts[] = round($x($t), 1) . ',' . $prevY;
    }
    $line = implode(' ', $pts);
    $area = $line . ' ' . round($x($t1), 1) . ',' . ($H - $pad[2]) . ' ' . $pad[3] . ',' . ($H - $pad[2]);
    $dots = '';
    foreach (array_slice($h, 0, -1) as [$t, $v]) {
        $dots .= '<circle cx="' . round($x($t), 1) . '" cy="' . round($y($v), 1) . '" r="3.5"><title>'
            . h(date('Y-m-d H:i', (int)$t) . ' — ' . format_number((float)$v)) . '</title></circle>';
    }
    return '<div class="card chart"><div class="chart-head"><span>Mažiausia <b>' . h(format_number(min($vals))) . '</b></span>'
        . '<span>Didžiausia <b>' . h(format_number(max($vals))) . '</b></span>'
        . '<span>Dabar <b>' . h(format_number((float)end($vals))) . '</b></span></div>'
        . '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Reikšmės istorija">'
        . '<polygon class="c-area" points="' . $area . '"/><polyline class="c-line" points="' . $line . '"/>' . $dots . '</svg>'
        . '<div class="chart-axis"><span>' . date('Y-m-d', (int)$t0) . '</span><span>' . date('Y-m-d', (int)$t1) . '</span></div></div>';
}
