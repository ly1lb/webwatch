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
    $attempts = $_SESSION['login_attempts'] ?? [];
    $attempts = array_filter($attempts, fn($t) => $t > time() - 900);
    if (count($attempts) >= 8) {
        flash('Per daug bandymų. Palaukite 15 min.', 'err');
        redirect('./');
    }
    if (password_verify((string)($_POST['password'] ?? ''), (string)setting('password_hash', ''))) {
        login_user();
        $_SESSION['login_attempts'] = [];
        redirect('./');
    }
    $attempts[] = time();
    $_SESSION['login_attempts'] = $attempts;
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
            'notify' => array_key_exists($_POST['notify'] ?? '', notify_modes()) ? $_POST['notify'] : 'auto',
            'active' => !empty($_POST['active']) ? 1 : 0,
        ];
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
            $resetKeys = ['url', 'selector', 'compare_mode', 'keyword', 'ignore_numbers', 'ignore_regex'];
            $reset = false;
            foreach ($resetKeys as $k) {
                if ((string)$old[$k] !== (string)$data[$k]) {
                    $reset = true;
                }
            }
            if ($reset) {
                $cols .= ', last_content = NULL, fail_count = 0';
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

    if ($do === 'delete_watch') {
        db()->prepare('DELETE FROM watches WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Ištrinta');
        redirect('./');
    }

    if ($do === 'save_settings') {
        foreach (['email_to', 'email_from', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user'] as $k) {
            set_setting($k, trim((string)($_POST[$k] ?? '')));
        }
        if (($_POST['smtp_pass'] ?? '') !== '') {
            set_setting('smtp_pass', (string)$_POST['smtp_pass']);
        }
        if (!empty($_POST['smtp_clear'])) {
            set_setting('smtp_pass', '');
        }
        flash('Nustatymai išsaugoti');
        redirect('?view=settings#email');
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
        db()->prepare('DELETE FROM changes WHERE watch_id = ?')->execute([$id]);
        flash('Istorija išvalyta');
        redirect('?view=watch&id=' . $id);
    }
    redirect('./');
}

/* ------------------------------------------------------------------ */
/* Puslapiai                                                            */
/* ------------------------------------------------------------------ */

switch ($view) {
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
    $watches = db()->query('SELECT * FROM watches ORDER BY unseen DESC, active DESC, COALESCE(last_change, created) DESC')->fetchAll();
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
        <div class="list-head">
            <span class="muted"><?= count($watches) ?> stebimi</span>
            <button class="btn small ghost" data-check-all>↻ Tikrinti visus</button>
        </div>
        <div class="watch-list">
        <?php foreach ($watches as $w): ?>
            <a class="watch-card <?= $w['active'] ? '' : 'paused' ?>" href="?view=watch&id=<?= $w['id'] ?>" data-watch-id="<?= $w['id'] ?>" data-active="<?= (int)$w['active'] ?>">
                <div class="wc-top">
                    <?= status_dot($w) ?>
                    <strong class="wc-title"><?= h(watch_title($w)) ?></strong>
                    <?php if ($w['unseen'] > 0): ?><span class="badge"><?= (int)$w['unseen'] ?></span><?php endif; ?>
                </div>
                <div class="wc-url"><?= h(preg_replace('~^https?://(www\.)?~', '', (string)$w['url'])) ?></div>
                <div class="wc-meta">
                    <span><?= h(compare_modes()[$w['compare_mode']][0] ?? '') ?><?= $w['selector'] !== '' ? ' · elementas' : '' ?></span>
                    <span>Pokytis: <?= h(human_time($w['last_change'] ? (int)$w['last_change'] : null)) ?></span>
                    <span class="wc-check">Tikrinta: <?= h(human_time($w['last_check'] ? (int)$w['last_check'] : null)) ?></span>
                </div>
                <?php if ($w['last_status'] === 'error'): ?>
                    <div class="wc-error">⚠️ <?= h($w['last_error']) ?></div>
                <?php endif; ?>
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
        'interval_min' => 60, 'notify' => 'auto', 'active' => 1,
    ];
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
            <div data-show-mode="keyword_appear keyword_disappear">
                <label>Žodis ar frazė <span class="muted">(kelios – atskirkite |)</span>
                    <input type="text" name="keyword" id="f-keyword" value="<?= h($w['keyword']) ?>" placeholder="pvz. Yra sandėlyje">
                </label>
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
            <label>Pranešimas
                <select name="notify">
                    <?php foreach (notify_modes() as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= $w['notify'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="check"><input type="checkbox" name="active" value="1" <?= $w['active'] ? 'checked' : '' ?>> Aktyvus</label>
            <details>
                <summary>Papildomi nustatymai</summary>
                <label class="check"><input type="checkbox" name="ignore_numbers" id="f-ignore-numbers" value="1" <?= $w['ignore_numbers'] ? 'checked' : '' ?>> Ignoruoti skaičių pokyčius (datos, laikai, skaitliukai)</label>
                <label>Ignoruoti tekstą (reguliarios išraiškos, po vieną eilutėje)
                    <textarea name="ignore_regex" id="f-ignore-regex" rows="3" placeholder="pvz. Atnaujinta:.*&#10;\d+ komentar\w+" autocapitalize="off" spellcheck="false"><?= h($w['ignore_regex']) ?></textarea>
                </label>
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
    $st = db()->prepare('SELECT * FROM changes WHERE watch_id = ? ORDER BY id DESC LIMIT ' . WW_KEEP_CHANGES);
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
    </div>
    <?php if ($w['last_status'] === 'error'): ?>
        <div class="flash err">⚠️ <?= h($w['last_error']) ?> (<?= (int)$w['fail_count'] ?> k. iš eilės)</div>
    <?php endif; ?>

    <div class="actions wrap-btns">
        <button class="btn primary" data-check-now="<?= $id ?>">↻ Tikrinti dabar</button>
        <a class="btn" href="?view=edit&id=<?= $id ?>">✎ Redaguoti</a>
        <button class="btn" data-toggle="<?= $id ?>"><?= $w['active'] ? '⏸ Pristabdyti' : '▶ Tęsti' ?></button>
        <form method="post" onsubmit="return confirm('Ištrinti šį stebėjimą?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete_watch"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn danger">🗑 Ištrinti</button>
        </form>
    </div>

    <h2>Pokyčių istorija</h2>
    <?php if (!$changes): ?>
        <p class="muted">Pokyčių dar neužfiksuota.</p>
    <?php else: ?>
        <?php foreach ($changes as $i => $c): ?>
            <details class="card change" <?= $i === 0 ? 'open' : '' ?>>
                <summary>
                    <span class="ch-date"><?= date('Y-m-d H:i', (int)$c['created']) ?></span>
                    <span class="ch-pct"><?= $c['change_pct'] > 0 ? h(number_format((float)$c['change_pct'], $c['change_pct'] < 1 ? 2 : 0, ',', '')) . ' %' : '' ?></span>
                    <span class="ch-sum"><?= h(mb_substr((string)$c['summary'], 0, 160)) ?></span>
                </summary>
                <?php if (in_array($w['compare_mode'], ['text', 'added', 'html'], true) && $c['old_content'] !== null): ?>
                    <div class="diff"><?= diff_html(line_diff(comparable_text((string)$c['old_content'], $w), comparable_text((string)$c['new_content'], $w))) ?></div>
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

    <details class="card">
        <summary>Dabartinis turinys (<?= number_format(mb_strlen((string)$w['last_content']), 0, ',', ' ') ?> simb.)</summary>
        <pre class="content"><?= h(mb_substr((string)$w['last_content'], 0, 20000)) ?></pre>
    </details>
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

    <section class="card" id="cron">
        <h2>⏰ Automatinis tikrinimas (cron)</h2>
        <?php $last = (int)setting('cron_last_run', '0'); ?>
        <p>Paskutinį kartą vykdyta: <b><?= $last ? h(date('Y-m-d H:i:s', $last)) . ' (' . h(human_time($last)) . ')' : 'niekada' ?></b></p>
        <p class="hint">Hostinger hPanel → <b>Advanced → Cron Jobs</b> → pasirinkite „Custom“, dažnį <b>kas 5 minutes</b> (<code>*/5 * * * *</code>) ir įrašykite komandą:</p>
        <label>PHP komanda (rekomenduojama)
            <input type="text" readonly value="/usr/bin/php <?= h($cronPath) ?>" class="mono copy" onclick="this.select()">
        </label>
        <label>Arba per URL (pvz. cron-job.org paslaugai)
            <input type="text" readonly value="<?= h($cronUrl) ?>" class="mono copy" onclick="this.select()">
        </label>
        <form method="post" class="right"><?= csrf_field() ?><input type="hidden" name="do" value="new_cron_token"><button class="btn small ghost">Sugeneruoti naują raktą</button></form>
    </section>

    <section class="card">
        <h2>🔑 Slaptažodis</h2>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="change_password">
            <label>Dabartinis<input type="password" name="old" required autocomplete="current-password"></label>
            <label>Naujas<input type="password" name="new" minlength="8" required autocomplete="new-password"></label>
            <button class="btn">Pakeisti</button>
        </form>
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
