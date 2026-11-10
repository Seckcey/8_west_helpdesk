<?php
/**
 * tenant_client_count() against a real MySQL: two tenants, and neither count includes the other's
 * clients. Uses its own scratch database on the CI's throwaway server and drops it afterwards.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/first_steps.php';

$database = getenv('SAFEHARBOR_FIRST_STEPS_TEST_DB') ?: 'safeharbor_first_steps_test';
if (preg_match('/\Asafeharbor_first_steps_test(?:_[a-z0-9_]+)?\z/', $database) !== 1) {
    fwrite(STDERR, "Refusing destructive test database: {$database}\n");
    exit(2);
}
$host = getenv('SAFEHARBOR_FIRST_STEPS_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_FIRST_STEPS_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_FIRST_STEPS_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_FIRST_STEPS_TEST_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];

$checks = 0;
$failures = [];
function count_check(bool $ok, string $name): void
{
    global $checks, $failures;
    $checks++;
    echo ($ok ? 'ok ' : 'not ok ') . $checks . ' - ' . $name . "\n";
    if (!$ok) $failures[] = $name;
}

$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $options);
$server->exec("DROP DATABASE IF EXISTS `{$database}`");
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
try {
    $db = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $pass, $options);
    // The real tenants and clients tables, exactly as the fresh-install schema creates them.
    $schema = (string)file_get_contents(__DIR__ . '/../db/schema.sql');
    foreach (['tenants', 'clients'] as $table) {
        $found = preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $m);
        count_check($found === 1, "schema.sql defines the {$table} table");
        $db->exec($m[0]);
    }
    $db->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Harbor IT', 'harbor-it'), (2, 'Cove IT', 'cove-it'), (3, 'Empty IT', 'empty-it')");

    count_check(tenant_client_count($db, 1) === 0 && tenant_client_count($db, 2) === 0, 'an empty table counts zero for every tenant');
    $insert = $db->prepare('INSERT INTO clients (tenant_id, name) VALUES (?, ?)');
    foreach ([[1, 'Harbor Dental'], [1, 'Bayside Realty'], [1, 'Juniper Bakery'], [2, 'Cedar and Pine Law']] as $row) $insert->execute($row);
    count_check(tenant_client_count($db, 1) === 3, 'tenant 1 counts only its own three clients');
    count_check(tenant_client_count($db, 2) === 1, 'tenant 2 counts only its own client');
    count_check(tenant_client_count($db, 3) === 0, 'a tenant with no clients counts zero');

    $source = (string)file_get_contents(__DIR__ . '/../lib/first_steps.php');
    count_check(str_contains($source, "\$db->prepare('SELECT COUNT(*) FROM clients WHERE tenant_id = ?')"),
        'the helper is one prepared, tenant-scoped COUNT');
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}

echo $failures === [] ? "\nTenant client count: {$checks} passed.\n" : "\nTenant client count: " . count($failures) . " of {$checks} failed.\n";
exit($failures === [] ? 0 : 1);
