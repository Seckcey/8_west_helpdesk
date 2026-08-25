<?php
/**
 * Shared bootstrap: loads config, opens DB, defines helpers.
 * Every entry point (web page or API) includes this first.
 * (Same shape as Milepost's lib/bootstrap.php — one way of doing things.)
 */
declare(strict_types=1);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));

$configPath = APP_ROOT . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.sample.php to config/config.php.');
}
$CONFIG = require $configPath;

require_once __DIR__ . '/service_goals.php';

if (($CONFIG['app_env'] ?? 'production') === 'dev') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

/** Open a shared PDO connection. */
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $d = $CONFIG['db'];
        $port = !empty($d['port']) ? ";port={$d['port']}" : '';
        $dsn = "mysql:host={$d['host']}{$port};dbname={$d['name']};charset={$d['charset']}";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/** Config read with dot paths: cfg('mail.smtp.host'). */
function cfg(string $key, $default = null)
{
    global $CONFIG;
    $node = $CONFIG;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) return $default;
        $node = $node[$part];
    }
    return $node;
}

/** HTML-escape. Every echoed string passes through this. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Force HTTPS if configured. */
function enforce_https(): void
{
    if (!cfg('force_https')) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$https) {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . $url, true, 301);
        exit;
    }
}

/** "3m" / "2h" / "4d" relative age from a UTC datetime string. */
function rel_time(string $utc): string
{
    $mins = max(1, (int)floor((time() - strtotime($utc . ' UTC')) / 60));
    if ($mins < 60) return $mins . 'm';
    $hours = intdiv($mins, 60);
    if ($hours < 24) return $hours . 'h';
    return intdiv($hours, 24) . 'd';
}

/** JSON response + exit (API endpoints). */
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * The signed-in tenant id. An 8 West ID sign-in stamps the claim-resolved
 * tenant into the session; local sign-ins (and cron, which has no session)
 * use the seeded tenant 1.
 */
function tenant_id(): int
{
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['tenant_id'])) {
        return (int)$_SESSION['tenant_id'];
    }
    return 1;
}

/**
 * Force valid UTF-8. Wild emails (and mis-encoded clients) send invalid byte
 * sequences; a raw insert would 500 on utf8mb4 with "Incorrect string value".
 *
 * This used to be mb_convert_encoding($s, 'UTF-8', 'UTF-8'). That does yield
 * valid UTF-8, but by replacing every bad byte with '?' — so a pasted em dash
 * was silently saved as a question mark. That failure is worse than the 500
 * it prevents, because nothing reports it: the text is just quietly wrong.
 *
 * Invalid bytes arriving at a UTF-8 form are almost always Windows-1252 out
 * of Word or Outlook — \x97 em dash, \x92 curly apostrophe, \x93/\x94 smart
 * quotes. Decoding as that keeps the character the person actually typed.
 * Windows-1252 defines nearly every byte, so the result is valid UTF-8 even
 * when the input was something else entirely.
 */
function utf8_clean(string $s): string
{
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }

    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/**
 * The same coercion applied to a whole input bag, once, at the edge.
 *
 * Cleaning at each call site is how the original bug happened: four of the
 * fifteen free-text fields called utf8_clean() and eleven did not, so a smart
 * quote in a saved reply was fine while the same quote in a ticket subject
 * was a fatal 500. Doing it here means a new form cannot forget.
 *
 * Passwords are passed through untouched. A password is a byte string rather
 * than prose, and rewriting bytes inside one would change the secret.
 *
 * @param  array<array-key, mixed>  $input
 * @return array<array-key, mixed>
 */
function utf8_clean_input(array $input): array
{
    $clean = [];

    foreach ($input as $key => $value) {
        if (is_string($key) && str_contains(strtolower($key), 'password')) {
            $clean[$key] = $value;
            continue;
        }

        $cleanKey = is_string($key) ? utf8_clean($key) : $key;

        $clean[$cleanKey] = match (true) {
            is_array($value) => utf8_clean_input($value),
            is_string($value) => utf8_clean($value),
            default => $value,
        };
    }

    return $clean;
}

/** "FG" from "Frank Gonzalez" — avatar initials (shared by Team page + SSO provisioning). */
function initials_of(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr(end($parts) ?: '', 0, 1));
    return mb_substr($ini, 0, 2);
}

// Coerce this request's text to valid UTF-8 before any handler reads it.
// Every page reaches the database through this file, so this is the one
// place that cannot be forgotten. CLI callers (the test suites, cron) get
// empty bags and are unaffected.
if (PHP_SAPI !== 'cli') {
    $_POST = utf8_clean_input($_POST);
    $_GET = utf8_clean_input($_GET);
}
