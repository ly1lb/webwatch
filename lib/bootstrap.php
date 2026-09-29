<?php
declare(strict_types=1);

/*
 * WebWatch – bendras įkėlimas: DB, nustatymai, sesija, pagalbinės funkcijos.
 */

define('WW_ROOT', dirname(__DIR__));
define('WW_DATA', WW_ROOT . '/data');
define('WW_VERSION', '1.2.0');

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
require_once __DIR__ . '/webpush.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/check.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(WW_DATA)) {
        @mkdir(WW_DATA, 0755, true);
    }
    $pdo = new PDO('sqlite:' . WW_DATA . '/webwatch.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT
        );
        CREATE TABLE IF NOT EXISTS watches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL,
            selector TEXT NOT NULL DEFAULT '',
            compare_mode TEXT NOT NULL DEFAULT 'text',
            keyword TEXT NOT NULL DEFAULT '',
            number_dir TEXT NOT NULL DEFAULT 'any',
            threshold REAL NOT NULL DEFAULT 0,
            ignore_numbers INTEGER NOT NULL DEFAULT 0,
            ignore_regex TEXT NOT NULL DEFAULT '',
            interval_min INTEGER NOT NULL DEFAULT 60,
            notify TEXT NOT NULL DEFAULT 'auto',
            active INTEGER NOT NULL DEFAULT 1,
            last_check INTEGER,
            last_change INTEGER,
            last_status TEXT NOT NULL DEFAULT 'new',
            last_error TEXT NOT NULL DEFAULT '',
            last_content TEXT,
            fail_count INTEGER NOT NULL DEFAULT 0,
            unseen INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            watch_id INTEGER NOT NULL REFERENCES watches(id) ON DELETE CASCADE,
            created INTEGER NOT NULL,
            old_content TEXT,
            new_content TEXT,
            summary TEXT NOT NULL DEFAULT '',
            change_pct REAL NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_changes_watch ON changes(watch_id, created);
        CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            last_ok INTEGER,
            last_error TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created INTEGER NOT NULL,
            channels TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            url TEXT NOT NULL,
            tag TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created INTEGER NOT NULL,
            level TEXT NOT NULL,
            message TEXT NOT NULL
        );
    ");

    // Stulpeliai, pridėti vėlesnėse versijose (senos DB atnaujinamos automatiškai).
    add_columns($pdo, 'watches', [
        'tags' => "TEXT NOT NULL DEFAULT ''",
        'headers' => "TEXT NOT NULL DEFAULT ''",
        'user_agent' => "TEXT NOT NULL DEFAULT 'mobile'",
        'render_js' => 'INTEGER NOT NULL DEFAULT 0',
        'value_history' => "TEXT NOT NULL DEFAULT ''",
        'fetch_via' => "TEXT NOT NULL DEFAULT ''",
    ]);
}

function add_columns(PDO $pdo, string $table, array $cols): void
{
    $have = [];
    foreach ($pdo->query("PRAGMA table_info($table)") as $c) {
        $have[$c['name']] = true;
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
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $st->execute([$key, $value]);
    setting('__reload');
}

function ww_log(string $level, string $message): void
{
    try {
        db()->prepare('INSERT INTO log (created, level, message) VALUES (?, ?, ?)')
            ->execute([time(), $level, mb_substr($message, 0, 2000)]);
        // Laikome tik paskutinius 500 įrašų.
        db()->exec('DELETE FROM log WHERE id <= (SELECT MAX(id) - 500 FROM log)');
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
