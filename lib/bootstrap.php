<?php
declare(strict_types=1);

/*
 * WebWatch – bendras įkėlimas: DB, nustatymai, sesija, pagalbinės funkcijos.
 */

define('WW_ROOT', dirname(__DIR__));
define('WW_DATA', WW_ROOT . '/data');
define('WW_VERSION', '1.7.0');

if (is_file(WW_ROOT . '/config.php')) {
    require WW_ROOT . '/config.php';
}
if (!defined('WW_TIMEZONE')) {
    define('WW_TIMEZONE', 'Europe/Vilnius');
}
date_default_timezone_set(WW_TIMEZONE);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/diff.php';
require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/fetch.php';
require_once __DIR__ . '/agent.php';
require_once __DIR__ . '/visual.php';
require_once __DIR__ . '/webpush.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/check.php';

/** Naudojama DB variklio pavadinimas: 'mysql' arba 'sqlite'. */
function ww_driver(): string
{
    return defined('WW_DB_HOST') && WW_DB_HOST !== '' ? 'mysql' : 'sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (ww_driver() === 'mysql') {
        // MySQL / MariaDB (Hostinger): jokių užraktų problemų. Nustatoma config.php faile.
        $host = WW_DB_HOST;
        $port = defined('WW_DB_PORT') && WW_DB_PORT ? (int)WW_DB_PORT : 3306;
        $name = defined('WW_DB_NAME') ? WW_DB_NAME : '';
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, defined('WW_DB_USER') ? WW_DB_USER : '', defined('WW_DB_PASS') ? WW_DB_PASS : '', [
            PDO::ATTR_TIMEOUT => 15,
        ]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        migrate($pdo);
        return $pdo;
    }
    if (!is_dir(WW_DATA)) {
        @mkdir(WW_DATA, 0755, true);
    }
    $pdo = new PDO('sqlite:' . WW_DATA . '/webwatch.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');       // rašymas neblokuoja skaitymo
    $pdo->exec('PRAGMA busy_timeout = 30000');     // laukti iki 30 s, kol bazė atsilaisvins
    $pdo->exec('PRAGMA synchronous = NORMAL');     // saugu su WAL, trumpesni užraktai
    $pdo->exec('PRAGMA wal_autocheckpoint = 300');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

/** Įterpimas/atveju atnaujinimas („upsert“) – veikia su SQLite ir MySQL. */
function db_upsert(string $table, array $keyCols, array $data): void
{
    $cols = array_keys($data);
    $ph = implode(', ', array_map(fn($c) => ':' . $c, $cols));
    $colList = implode(', ', $cols);
    $updateCols = array_values(array_diff($cols, $keyCols));
    if (ww_driver() === 'mysql') {
        $set = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $updateCols));
        $sql = "INSERT INTO $table ($colList) VALUES ($ph)" . ($set ? " ON DUPLICATE KEY UPDATE $set" : '');
    } else {
        $set = implode(', ', array_map(fn($c) => "$c = excluded.$c", $updateCols));
        $conflict = implode(', ', $keyCols);
        $sql = "INSERT INTO $table ($colList) VALUES ($ph) ON CONFLICT($conflict) DO UPDATE SET $set";
    }
    db_retry(function () use ($sql, $data) {
        db()->prepare($sql)->execute($data);
    });
}

/**
 * Įvykdo DB operaciją su pakartojimu, kai bazė laikinai užimta („database is locked“).
 * Būtina, nes cron, „Tikrinti dabar“ ir agentų apklausos gali rašyti vienu metu.
 */
function db_retry(callable $fn, int $tries = 6)
{
    for ($i = 1; ; $i++) {
        try {
            return $fn();
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if ($i >= $tries || (stripos($msg, 'locked') === false && stripos($msg, 'busy') === false)) {
                throw $e;
            }
            usleep(random_int(150000, 500000) * $i); // didėjanti pauzė
        }
    }
}

/** Trumpinys: paruošia ir įvykdo rašymo užklausą su pakartojimu. */
function db_write(string $sql, array $params = []): void
{
    db_retry(function () use ($sql, $params) {
        db()->prepare($sql)->execute($params);
    });
}

function migrate(PDO $pdo): void
{
    $mysql = ww_driver() === 'mysql';
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $blob = $mysql ? 'LONGBLOB' : 'BLOB';
    // $sd – trumpas tekstas su numatyta reikšme (gali būti neįterptas). VARCHAR abiejuose leidžia DEFAULT.
    $sd = $mysql ? "VARCHAR(1024) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $req = 'TEXT NOT NULL';                 // visada įterpiama reikšmė
    $lt = $mysql ? 'LONGTEXT' : 'TEXT';     // ilgas turinys, gali būti NULL
    $kkey = $mysql ? 'VARCHAR(191)' : 'TEXT';
    $tok = $mysql ? 'VARCHAR(64)' : 'TEXT';
    $endpoint = $mysql ? 'VARCHAR(512)' : 'TEXT';
    $eng = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $tables = [
        "CREATE TABLE IF NOT EXISTS settings (k $kkey NOT NULL PRIMARY KEY, v $lt)$eng",
        "CREATE TABLE IF NOT EXISTS watches (
            id $pk,
            name $sd, url $req, selector $sd,
            compare_mode VARCHAR(32) NOT NULL DEFAULT 'text',
            keyword $sd, number_dir VARCHAR(8) NOT NULL DEFAULT 'any',
            threshold DOUBLE NOT NULL DEFAULT 0,
            ignore_numbers INTEGER NOT NULL DEFAULT 0,
            ignore_regex $sd,
            interval_min INTEGER NOT NULL DEFAULT 60,
            notify $sd,
            active INTEGER NOT NULL DEFAULT 1,
            last_check INTEGER, last_change INTEGER,
            last_status VARCHAR(16) NOT NULL DEFAULT 'new',
            last_error $sd, last_content $lt,
            fail_count INTEGER NOT NULL DEFAULT 0,
            unseen INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        )$eng",
        "CREATE TABLE IF NOT EXISTS changes (
            id $pk,
            watch_id INTEGER NOT NULL,
            created INTEGER NOT NULL,
            old_content $lt, new_content $lt,
            summary $sd, change_pct DOUBLE NOT NULL DEFAULT 0
        )$eng",
        "CREATE TABLE IF NOT EXISTS subscriptions (
            id $pk,
            endpoint $endpoint NOT NULL UNIQUE,
            p256dh $sd, auth $sd, label $sd,
            created INTEGER NOT NULL, last_ok INTEGER, last_error $sd
        )$eng",
        "CREATE TABLE IF NOT EXISTS queue (
            id $pk,
            created INTEGER NOT NULL, channels $sd,
            title $sd, body $lt, url $sd, tag $sd
        )$eng",
        "CREATE TABLE IF NOT EXISTS agents (
            id $pk,
            name $sd, token $tok NOT NULL UNIQUE,
            created INTEGER NOT NULL, last_seen INTEGER, last_ip $sd,
            info $sd, jobs_done INTEGER NOT NULL DEFAULT 0,
            fails INTEGER NOT NULL DEFAULT 0, last_error $sd,
            priority INTEGER NOT NULL DEFAULT 0
        )$eng",
        "CREATE TABLE IF NOT EXISTS agent_requests (
            id $pk,
            created INTEGER NOT NULL, url $req, headers $lt,
            ua $sd, browser INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            agent_id INTEGER, claimed INTEGER,
            http_status INTEGER NOT NULL DEFAULT 0, body $blob,
            final_url $sd, content_type $sd,
            via $sd, error $sd, target_agent INTEGER
        )$eng",
        "CREATE TABLE IF NOT EXISTS log (
            id $pk,
            created INTEGER NOT NULL, level VARCHAR(16) NOT NULL, message $req
        )$eng",
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    $idx = $mysql
        ? "CREATE INDEX idx_changes_watch ON changes (watch_id, created)"
        : "CREATE INDEX IF NOT EXISTS idx_changes_watch ON changes(watch_id, created)";
    try {
        $pdo->exec($idx);
    } catch (PDOException $e) {
        // MySQL: indeksas jau yra – ignoruojam
    }

    // Stulpeliai, pridėti vėlesnėse versijose (senos DB atnaujinamos automatiškai).
    add_columns($pdo, 'watches', [
        'tags' => $sd,
        'headers' => $lt,
        'user_agent' => "VARCHAR(16) NOT NULL DEFAULT 'mobile'",
        'render_js' => 'INTEGER NOT NULL DEFAULT 0',
        'value_history' => $lt,
        'fetch_via' => "VARCHAR(16) NOT NULL DEFAULT ''",
        'check_from' => "VARCHAR(16) NOT NULL DEFAULT 'server'",
        // Tvarkaraštis
        'sched_days' => "VARCHAR(16) NOT NULL DEFAULT ''",   // pvz. "1,2,3,4,5"; tuščia = visada
        'sched_from' => "VARCHAR(5) NOT NULL DEFAULT ''",    // HH:MM
        'sched_to' => "VARCHAR(5) NOT NULL DEFAULT ''",
        // Aplankai
        'folder' => "VARCHAR(64) NOT NULL DEFAULT ''",
        // Sudėtingesnės sąlygos
        'extract_regex' => $sd,                              // ištraukti reikšmę (1-a grupė) prieš lyginant
        'require_regex' => $sd,                              // pranešti tik jei naujas turinys atitinka
        'keyword_all' => 'INTEGER NOT NULL DEFAULT 0',       // raktažodžiai: 1 = visi (IR), 0 = bet kuris (ARBA)
    ]);
    add_columns($pdo, 'changes', [
        'has_shot' => 'INTEGER NOT NULL DEFAULT 0',          // ar yra ekrano nuotraukos
    ]);
    add_columns($pdo, 'agent_requests', [
        'shot' => 'INTEGER NOT NULL DEFAULT 0',              // ar prašoma ekrano nuotraukos
    ]);
    add_columns($pdo, 'agents', [
        'diag' => $lt,                                       // paskutinės agento žurnalo eilutės
        'procs' => 'INTEGER NOT NULL DEFAULT -1',            // naršyklės procesų skaičius (orphan'ai)
        'diag_at' => 'INTEGER NOT NULL DEFAULT 0',           // kada gautas paskutinis diag
    ]);
}

function add_columns(PDO $pdo, string $table, array $cols): void
{
    $have = [];
    if (ww_driver() === 'mysql') {
        foreach ($pdo->query("SHOW COLUMNS FROM $table") as $c) {
            $have[$c['Field']] = true;
        }
    } else {
        foreach ($pdo->query("PRAGMA table_info($table)") as $c) {
            $have[$c['name']] = true;
        }
    }
    foreach ($cols as $name => $def) {
        if (!isset($have[$name])) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $name $def");
        }
    }
}

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null || $key === '__reload') {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $row) {
            $cache[$row['k']] = $row['v'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, ?string $value): void
{
    db_upsert('settings', ['k'], ['k' => $key, 'v' => $value]);
    setting('__reload');
}

function ww_log(string $level, string $message): void
{
    try {
        db_write('INSERT INTO log (created, level, message) VALUES (?, ?, ?)', [time(), $level, mb_substr($message, 0, 2000)]);
        // Laikome tik paskutinius 500 įrašų (retkarčiais, kad nekrautų DB).
        if (random_int(1, 20) === 1) {
            db_retry(fn() => db()->exec('DELETE FROM log WHERE id <= (SELECT MAX(id) - 500 FROM log)'));
        }
    } catch (Throwable $e) {
        error_log('WebWatch log: ' . $e->getMessage());
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_watch(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM watches WHERE id = ?');
    $st->execute([$id]);
    $w = $st->fetch();
    return $w ?: null;
}

function watch_title(array $w): string
{
    if (trim((string)$w['name']) !== '') {
        return (string)$w['name'];
    }
    $host = parse_url((string)$w['url'], PHP_URL_HOST) ?: $w['url'];
    return preg_replace('/^www\./', '', (string)$host);
}

/** Programos adresas (reikalingas nuorodoms pranešimuose, kai veikia cron). */
function app_url(): string
{
    if (defined('WW_APP_URL') && WW_APP_URL) {
        return rtrim(WW_APP_URL, '/') . '/';
    }
    return rtrim((string)setting('app_url', ''), '/') . '/';
}

function remember_app_url(): void
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $url = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir . '/';
    if (setting('app_url') !== $url) {
        set_setting('app_url', $url);
    }
}

/** Trukmė žmogui: 90000 -> „1 d.“, 3600 -> „1 val.“ */
function human_duration(int $sec): string
{
    if ($sec < 90) {
        return $sec . ' sek.';
    }
    if ($sec < 5400) {
        return max(1, (int)round($sec / 60)) . ' min.';
    }
    if ($sec < 129600) {
        return max(1, (int)round($sec / 3600)) . ' val.';
    }
    return max(1, (int)round($sec / 86400)) . ' d.';
}

function human_time(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    $d = time() - $ts;
    if ($d < 60) {
        return 'ką tik';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' min. prieš';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' val. prieš';
    }
    if ($d < 86400 * 7) {
        return floor($d / 86400) . ' d. prieš';
    }
    return date('Y-m-d H:i', $ts);
}

function interval_label(int $min): string
{
    $opts = interval_options();
    return $opts[$min] ?? ($min . ' min.');
}

function interval_options(): array
{
    return [
        1 => 'kas minutę',
        2 => 'kas 2 min.',
        5 => 'kas 5 min.',
        10 => 'kas 10 min.',
        15 => 'kas 15 min.',
        30 => 'kas 30 min.',
        60 => 'kas valandą',
        120 => 'kas 2 val.',
        180 => 'kas 3 val.',
        360 => 'kas 6 val.',
        720 => 'kas 12 val.',
        1440 => 'kartą per parą',
    ];
}

/* ------------------------------------------------------------------ */
/* Sesija ir prisijungimas                                              */
/* ------------------------------------------------------------------ */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = WW_DATA . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $lifetime = 60 * 60 * 24 * 365;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    session_save_path($dir);
    session_name('wwsess');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_logged_in(): bool
{
    start_session();
    return !empty($_SESSION['ww_auth']) && ($_SESSION['ww_pw'] ?? '') === substr((string)setting('password_hash', ''), -12);
}

function login_user(): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['ww_auth'] = true;
    $_SESSION['ww_pw'] = substr((string)setting('password_hash', ''), -12);
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function ensure_secrets(): void
{
    if (!setting('cron_token')) {
        set_setting('cron_token', bin2hex(random_bytes(16)));
    }
    vapid_keys();
}

function normalize_tags(string $tags): string
{
    $list = array_unique(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 30), preg_split('/[,;]+/', $tags))));
    return implode(', ', array_slice($list, 0, 10));
}

function tag_list(string $tags): array
{
    return array_values(array_filter(array_map('trim', explode(',', $tags))));
}
