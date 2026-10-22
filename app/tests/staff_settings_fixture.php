<?php
/** CLI-only synthetic adapter for the real Team/profile handlers and templates.
 * No bootstrap, real identity, configuration, database, email or account writes.
 * Browser tests supply form data; this in-memory adapter exercises feedback only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});
$surface = $argv[1] ?? '';
$theme = $argv[2] ?? 'light';
$account = $argv[3] ?? 'local';
if (!in_array($surface, ['users', 'profile'], true) || !in_array($theme, ['light', 'dark'], true)
    || !in_array($account, ['local', 'suite', 'tech'], true)) exit(1);
$_POST = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
$_SERVER['REQUEST_URI'] = '/'.$surface.'.php';
$_SESSION = ['suite_settings_context' => ['capabilities' => ['manage_people' => true], 'panels' => ['msp_admin']]];
$fixtureUser = ['id'=>1, 'tenant_id'=>1, 'full_name'=>'Synthetic Technician', 'email'=>'synthetic@example.test',
    'role'=>$account === 'tech' ? 'tech' : 'admin', 'initials'=>'ST', 'color'=>'#2D8CFF',
    'suite_subject'=>$account === 'suite' ? 't1u1' : null, 'password_hash'=>password_hash('synthetic-password', PASSWORD_DEFAULT)];
$fixtureRows = [
    [...$fixtureUser, 'is_active'=>1, 'last_login_at'=>null],
    [...$fixtureUser, 'id'=>2, 'full_name'=>'Jordan Ellis', 'email'=>'jordan.ellis.longaddress@example.test', 'role'=>'tech', 'is_active'=>1, 'last_login_at'=>null],
    [...$fixtureUser, 'id'=>3, 'full_name'=>'Sam Park', 'email'=>'sam@example.test', 'role'=>'owner', 'is_active'=>0, 'last_login_at'=>null],
];
function enforce_https(): void {}
function require_login(): array { return $GLOBALS['fixtureUser']; }
function suite_local_password_allowed(array $user): bool { return empty($user['suite_subject']); }
function cfg(string $key, mixed $default = null): mixed { return $default; }
function tenant_id(): int { return 1; }
function h(mixed $text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function initials_of(string $name): string { return 'ST'; }
function avatar(array $user, int $size): string { return '<span class="avatar" style="width:'.$size.'px;height:'.$size.'px;background:'.h($user['color']).'" title="'.h($user['full_name']).'">'.h($user['initials']).'</span>'; }
function rel_time(string $time): string { return '1d'; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic-csrf">'; }
function csrf_check(): void { if (($_POST['csrf'] ?? '') !== 'synthetic-csrf') throw new RuntimeException('Invalid synthetic CSRF'); }
function palette_data(): array { return []; }
function page_top(array $user, string $title, string $active): void {
    $dir = getenv('CUSTOMER_UI_CAPTURE_DIR');
    $role = $GLOBALS['account'] === 'tech' ? 'tech' : 'admin';
    $chrome = file_get_contents($dir.'/staff-'.$role.'-'.$GLOBALS['theme'].'.html');
    echo str_replace('<title>Safeharbor staff settings fixture</title>', '<title>'.h($title).' · Safeharbor fixture</title>', explode('<main>', $chrome)[0]);
    echo '<main>';
}
function page_bottom(array $data): void { echo '</main><script src="/assets/w365/w365-launcher.js" defer></script></body></html>'; }
class SettingsFixtureStatement {
    public function __construct(private string $sql) {}
    public function execute(array $params): void {
        if (str_starts_with($this->sql, 'UPDATE users SET is_active')) {
            foreach ($GLOBALS['fixtureRows'] as &$row) if ($row['id'] === $params[1]) $row['is_active'] = $params[0];
        }
    }
    public function fetch(): bool { return ($_POST['email'] ?? '') === 'duplicate@example.test'; }
    public function fetchAll(): array { return $GLOBALS['fixtureRows']; }
}
class SettingsFixtureDatabase {
    public function prepare(string $sql): SettingsFixtureStatement { return new SettingsFixtureStatement($sql); }
}
function db(): SettingsFixtureDatabase { return new SettingsFixtureDatabase(); }
$source = file_get_contents(__DIR__.'/../public/'.$surface.'.php');
// Same whole-page source, substituting only the two bootstrap includes above.
$source = preg_replace('/^require_once .*;\R/m', '', $source);
eval(substr($source, strlen('<?php')));
