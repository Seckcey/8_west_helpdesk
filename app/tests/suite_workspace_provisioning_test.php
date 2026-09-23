<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/suite_workspace_provisioning.php';

function check_workspace(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$manifest = ['schema' => '8west.workspace.v1', 'tenant_id' => 42, 'slug' => 'north-star', 'name' => 'North Star',
    'report_email' => 'owner@example.test', 'products' => ['safeharbor'],
    'owner' => ['id' => 7, 'subject' => 't42u7', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'msp_owner']];
suite_workspace_manifest_validate($manifest, 'safeharbor');
foreach (['subject', 'role', 'product', 'house'] as $case) {
    $invalid = $manifest;
    if ($case === 'subject') $invalid['owner']['subject'] = 't43u7';
    if ($case === 'role') $invalid['owner']['role'] = 'client_owner';
    if ($case === 'product') $invalid['products'] = [];
    if ($case === 'house') $invalid['slug'] = '8west';
    $refused = false;
    try { suite_workspace_manifest_validate($invalid, 'safeharbor'); } catch (InvalidArgumentException) { $refused = true; }
    check_workspace($refused, 'invalid manifest admitted: ' . $case);
}
echo "workspace manifest denial checks passed\n";

if (!getenv('SUITE_WORKSPACE_TEST_DSN')) exit(0);
$pdo = new PDO((string)getenv('SUITE_WORKSPACE_TEST_DSN'), 'root', 'suite-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// The caller supplies a disposable, task-owned test database; never production.
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
check_workspace($database === 'suite_workspace_test', 'disposable database required');
$pdo->exec('CREATE TABLE tenants (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL, slug VARCHAR(64) NOT NULL UNIQUE)');
$pdo->exec("CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL, suite_subject VARCHAR(64) UNIQUE, password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(128) NOT NULL, initials VARCHAR(4) NOT NULL, role ENUM('owner','admin','tech') NOT NULL,
    is_active TINYINT NOT NULL DEFAULT 1, UNIQUE(tenant_id,email), FOREIGN KEY(tenant_id) REFERENCES tenants(id))");
$tenant = suite_workspace_provision($pdo, $manifest);
check_workspace($tenant === suite_workspace_provision($pdo, $manifest), 'replay changed tenant');
check_workspace((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'replay duplicated owner');
$pdo->exec('UPDATE users SET is_active = 0');
$refused = false;
try { suite_workspace_provision($pdo, $manifest); } catch (RuntimeException) { $refused = true; }
check_workspace($refused, 'inactive owner reactivated');
check_workspace((int)$pdo->query('SELECT is_active FROM users')->fetchColumn() === 0, 'inactive owner was changed');
echo "workspace MySQL replay and inactive-owner checks passed\n";
