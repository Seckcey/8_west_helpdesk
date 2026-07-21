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

function cfg(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
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

/**
 * SLA state for a ticket row: healthy (>2h), at_risk (<2h), breached (past
 * due), met (resolved in time). Labels are short, for the SLA lamp.
 */
function sla_info(array $ticket): array
{
    if ($ticket['status'] === 'resolved') {
        return ['state' => 'met', 'label' => 'SLA met'];
    }
    $diff = strtotime($ticket['sla_due_at'] . ' UTC') - time();
    $absMin = (int)round(abs($diff) / 60);
    $label = $absMin >= 60
        ? intdiv($absMin, 60) . 'h ' . str_pad((string)($absMin % 60), 2, '0', STR_PAD_LEFT) . 'm'
        : $absMin . 'm';
    if ($diff < 0)            return ['state' => 'breached', 'label' => $label . ' over'];
    if ($diff < 2 * 3600)     return ['state' => 'at_risk',  'label' => $label . ' left'];
    return                           ['state' => 'healthy',  'label' => $label . ' left'];
}

/** JSON response + exit (API endpoints). */
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** The signed-in tenant id. Phase 0: single seeded tenant; SSO later. */
function tenant_id(): int
{
    return 1;
}
