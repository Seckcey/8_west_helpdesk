<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$cfgPath = __DIR__ . '/../config/config.php';
$backup = null;
if (file_exists($cfgPath)) {
    $backup = $cfgPath . '.logouttest-bak-' . getmypid();
    rename($cfgPath, $backup);
}
register_shutdown_function(static function () use ($cfgPath, $backup): void {
    if (is_file($cfgPath)) @unlink($cfgPath);
    if ($backup !== null && is_file($backup)) rename($backup, $cfgPath);
});

$testConfig = [
    'app_env' => 'production',
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'unused',
        'user' => 'unused',
        'pass' => 'unused',
        'charset' => 'utf8mb4',
    ],
    'force_https' => false,
    'suite' => [
        'issuer' => 'https://id.8westit.com/',
    ],
];
file_put_contents($cfgPath, '<?php return ' . var_export($testConfig, true) . ';' . "\n");

if (!function_exists('mb_internal_encoding')) { function mb_internal_encoding($encoding = null) { return $encoding === null ? 'UTF-8' : true; } }
if (!function_exists('mb_strlen')) { function mb_strlen($string, $encoding = null) { return strlen((string)$string); } }
if (!function_exists('mb_substr')) { function mb_substr($string, $start, $length = null, $encoding = null) { return $length === null ? substr((string)$string, (int)$start) : substr((string)$string, (int)$start, (int)$length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($string, $encoding = null) { return strtolower((string)$string); } }

session_id('suite-logout-' . getmypid());

require_once __DIR__ . '/../lib/auth.php';

function logout_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

logout_check(session_status() === PHP_SESSION_ACTIVE, 'test fixture could not start a local PHP session');
logout_check(suite_logout_url() === 'https://id.8westit.com/logout.php', 'configured issuer did not produce the central logout URL');

$CONFIG['suite']['issuer'] = 'https://identity.example.test/base/';
logout_check(suite_logout_url() === 'https://identity.example.test/base/logout.php', 'valid HTTPS issuer was not honored');

foreach (['http://id.8westit.com', 'javascript:alert(1)', 'https://user@id.8westit.com', 'https://id.8westit.com?next=evil'] as $unsafeIssuer) {
    $CONFIG['suite']['issuer'] = $unsafeIssuer;
    logout_check(suite_logout_url() === 'https://id.8westit.com/logout.php', 'unsafe issuer did not fail closed: ' . $unsafeIssuer);
}

$_SESSION = ['user_id' => 42, 'suite_products' => ['safeharbor']];
logout();
logout_check(session_status() === PHP_SESSION_NONE, 'logout left the local PHP session active');
logout_check($_SESSION === [], 'logout left local session data behind');

$endpoint = file_get_contents(__DIR__ . '/../public/logout.php');
$destroyAt = strpos($endpoint, 'logout();');
$redirectAt = strpos($endpoint, "header('Location: ' . suite_logout_url());");
logout_check($destroyAt !== false && $redirectAt !== false && $destroyAt < $redirectAt, 'logout endpoint does not destroy the local session before central redirect');
logout_check(strpos($endpoint, 'exit;', $redirectAt) !== false, 'logout endpoint does not stop after redirect');

echo "suite_logout_test: ok\n";
