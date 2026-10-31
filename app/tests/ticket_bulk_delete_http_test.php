<?php
/**
 * The bulk delete endpoint and the queue over real HTTP: a disposable loopback MySQL, a runtime copy
 * of the app on PHP's built-in server, synthetic data only, no live identity or mail.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_TICKET_DELETE_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_TICKET_DELETE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_TICKET_DELETE_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_TICKET_DELETE_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_ticket_delete_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$user = getenv('SAFEHARBOR_TICKET_DELETE_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_TICKET_DELETE_TEST_PASS') ?: '';
$database = $base . '_http_' . bin2hex(random_bytes(5));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$pdo->exec("USE `$database`");
$pdo->exec("SET time_zone = '+00:00'");

$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-bulk-http-' . bin2hex(random_bytes(8));
$process = null;
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'ok ' . (++$checks) . ' - ' . $name . "\n";
};

try {
    foreach (['schema.sql', 'migrations/001_mail_queue.sql', 'migrations/002_svc_intake.sql',
        'migrations/009_support_intake.sql', 'migrations/018_ticket_auto_close_eligibility.sql'] as $file) {
        test_execute_sql_file($pdo, $app . '/db/' . $file);
    }
    $pdo->exec("INSERT INTO tenants (id, name, slug, plan) VALUES (1, 'Synthetic One', 'synthetic-one', 'suite')");
    $pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Fixture Dental')");
    $hash = password_hash('synthetic-not-a-login', PASSWORD_DEFAULT);
    $u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, initials, role, is_active) VALUES (?,1,?,?,?,?,?,?)');
    foreach ([[101, 'owner', 1], [102, 'admin', 1], [103, 'tech', 1], [104, 'owner', 0]] as [$id, $role, $active]) {
        $u->execute([$id, "u{$id}@fixture.invalid", $hash, "Fixture {$id}", 'F' . $role[0], $role, $active]);
    }
    $t = $pdo->prepare("INSERT INTO tickets (id, tenant_id, client_id, subject, channel, sla_due_at) VALUES (?, 1, 11, ?, ?, UTC_TIMESTAMP())");
    foreach ([[7001, 'Synthetic alert one', 'alert'], [7002, 'Synthetic email', 'email'], [7003, 'Synthetic with time', 'email'], [7004, 'Synthetic alert two', 'alert']] as $row) {
        $t->execute($row);
    }
    $pdo->exec("INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (7002, 'Fixture Client', 'client', 'Synthetic body')");
    $pdo->exec("INSERT INTO time_entries (ticket_id, user_id, minutes, note, billable, created_at) VALUES (7003, 103, 15, 'Synthetic time', 1, '2026-10-01 10:00:00')");

    // A runtime copy of the app with a settings file built from the sample, as CI's settings step does.
    $copyTree = static function (string $source, string $target): void {
        if (!is_dir($target)) mkdir($target, 0700, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $to = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
            $file->isDir() ? (is_dir($to) || mkdir($to, 0700, true)) : copy($file->getPathname(), $to);
        }
    };
    foreach (['config', 'public/api', 'sessions', 'attachments'] as $part) mkdir($runtime . '/' . $part, 0700, true);
    $copyTree($app . '/lib', $runtime . '/lib');
    copy($app . '/public/index.php', $runtime . '/public/index.php');
    copy($app . '/public/api/ticket_bulk_delete.php', $runtime . '/public/api/ticket_bulk_delete.php');
    $config = require $app . '/config/config.sample.php';
    $config['app_env'] = 'dev';
    $config['force_https'] = false;
    $config['db'] = ['host' => $host, 'port' => (int)$port, 'user' => $user, 'pass' => $pass, 'name' => $database, 'charset' => 'utf8mb4'];
    $config['storage']['attachments_dir'] = $runtime . '/attachments';
    file_put_contents($runtime . '/config/config.php', '<?php return ' . var_export($config, true) . ';');

    // Staff sessions are files: one per person, each with its own CSRF token.
    $sessions = [];
    foreach ([101, 102, 103, 104] as $id) {
        $sessions[$id] = ['id' => bin2hex(random_bytes(16)), 'csrf' => bin2hex(random_bytes(32))];
        file_put_contents($runtime . '/sessions/sess_' . $sessions[$id]['id'], 'user_id|' . serialize($id) . 'csrf|' . serialize($sessions[$id]['csrf']));
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'session.save_path=' . $runtime . '/sessions', '-S', $address, '-t', $runtime . '/public'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtime . '/http.log', 'a'], 2 => ['file', $runtime . '/http.log', 'a']], $pipes);
    for ($n = 0; $n < 100; $n++) { $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1); if ($probe) { fclose($probe); break; } usleep(20000); }

    /** @return array{0:int, 1:mixed, 2:string} status, decoded JSON (or null), raw body */
    $http = static function (string $method, string $route, ?int $as = null, ?array $body = null, bool $csrf = true) use ($address, &$sessions): array {
        $curl = curl_init('http://' . $address . $route);
        $headers = ['Content-Type: application/json'];
        if ($as !== null) {
            $headers[] = 'Cookie: PHPSESSID=' . $sessions[$as]['id'];
            if ($csrf) $headers[] = 'X-CSRF: ' . $sessions[$as]['csrf'];
        }
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = (string)curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return [$status, json_decode($raw, true), $raw];
    };
    $api = '/api/ticket_bulk_delete.php';
    $all = ['ids' => [7001, 7002, 7003, 7004]];
    $ticketIds = static fn(): array => array_map('intval', $pdo->query('SELECT id FROM tickets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

    // The gates, in the order the endpoint applies them.
    $check($http('GET', $api, 101)[0] === 405, 'only POST is accepted');
    $check($http('POST', $api, null, ['action' => 'preview', 'scope' => $all])[0] === 401, 'no session is refused with 401, not a redirect');
    $check($http('POST', $api, 104, ['action' => 'preview', 'scope' => $all])[0] === 401, 'an inactive owner has no session');
    $check($http('POST', $api, 101, ['action' => 'preview', 'scope' => $all], false)[0] === 419, 'a request without the CSRF token is refused');
    [$status, $json] = $http('POST', $api, 103, ['action' => 'preview', 'scope' => $all]);
    $check($status === 403 && ($json['error'] ?? '') === 'Only owners and admins can delete tickets.', 'a technician is refused');
    $check($http('POST', $api, 101, ['action' => 'preview', 'scope' => ['filter' => 'all']])[0] === 422, 'a live filter is refused; tickets must be named');
    $check($ticketIds() === [7001, 7002, 7003, 7004], 'no refusal changed anything');

    // The preview, and every way a delete can fail to match it.
    [$status, $preview] = $http('POST', $api, 101, ['action' => 'preview', 'scope' => $all]);
    $check($status === 200 && $preview['deletable'] === 3 && $preview['deletable_ids'] === [7001, 7002, 7004] && $preview['phrase'] === 'DELETE 3'
        && ($preview['kept_by_reason']['time'] ?? 0) === 1 && preg_match('/\A[0-9a-f]{32}\z/', (string)$preview['run_key']) === 1,
        'the preview offers 3, keeps the ticket with time, and hands back the exact list');
    $delete = ['action' => 'delete', 'scope' => ['ids' => $preview['deletable_ids']], 'after_id' => 0, 'run_key' => $preview['run_key'], 'confirm' => 'DELETE 3'];
    [$status, $json] = $http('POST', $api, 101, array_replace($delete, ['run_key' => str_repeat('b', 32)]));
    $check($status === 409 && ($json['code'] ?? '') === 'preview_expired', 'an unknown run key is refused');
    [$status, $json] = $http('POST', $api, 101, array_replace($delete, ['scope' => $all]));
    $check($status === 409 && ($json['code'] ?? '') === 'preview_expired', 'a list other than the preview\'s deletable list is refused');
    [$status, $json] = $http('POST', $api, 101, array_replace($delete, ['confirm' => 'DELETE 4']));
    $check($status === 422 && ($json['code'] ?? '') === 'confirm_mismatch', 'the wrong phrase is refused');
    [$status, $json] = $http('POST', $api, 102, $delete);
    $check($status === 409 && ($json['code'] ?? '') === 'preview_expired', 'another admin cannot use this owner\'s preview');
    $check($ticketIds() === [7001, 7002, 7003, 7004], 'no refused delete changed anything');

    // The real thing, then a replay.
    [$status, $json] = $http('POST', $api, 101, $delete);
    $check($status === 200 && $json['deleted'] === 3 && $json['run_deleted'] === 3 && $json['done'] === true && $json['next_after_id'] === 7004,
        'the confirmed delete removes exactly the 3, and the run total says so');
    $check($ticketIds() === [7003], 'only the ticket with time remains');
    $audit = $pdo->query('SELECT COUNT(*) AS n, MIN(deleted_by_user_id) AS actor, COUNT(DISTINCT run_key) AS runs FROM ticket_deletions')->fetch();
    $check((int)$audit['n'] === 3 && (int)$audit['actor'] === 101 && (int)$audit['runs'] === 1, 'three audit records name the owner and the run');
    [$status, $json] = $http('POST', $api, 101, $delete);
    $check($status === 200 && $json['deleted'] === 0 && $json['run_deleted'] === 3 && $json['done'] === true,
        'replaying the batch is harmless, and the run total still counts the earlier batch');

    // The queue page: owners get the controls, technicians get today's page.
    $pdo->exec("INSERT INTO tickets (id, tenant_id, client_id, subject, channel, sla_due_at) VALUES (7005, 1, 11, 'Synthetic queue row', 'email', UTC_TIMESTAMP())");
    [$status, , $ownerPage] = $http('GET', '/', 101);
    $check($status === 200 && str_contains($ownerPage, 'id="bulk-bar"') && str_contains($ownerPage, 'class="bulk-pick"') && str_contains($ownerPage, 'id="bulk-all"'),
        'an owner\'s queue has the selection controls');
    [$status, , $techPage] = $http('GET', '/', 103);
    $check($status === 200 && str_contains($techPage, 'Synthetic queue row') && !str_contains($techPage, 'bulk-pick') && !str_contains($techPage, 'bulk-bar'),
        'a technician\'s queue has none of them');
    echo "Ticket bulk delete HTTP: {$checks} checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n" . (is_file($runtime . '/http.log') ? substr((string)file_get_contents($runtime . '/http.log'), -6000) : ''));
    $exit = 1;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_dir($runtime)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($runtime);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
exit($exit ?? 0);
