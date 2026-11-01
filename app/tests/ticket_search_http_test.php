<?php
/**
 * A ticket's number in ⌘K's deep search over real HTTP: a disposable loopback MySQL, a runtime copy
 * of the app on PHP's built-in server, synthetic data only, no live identity or mail.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_TICKET_SEARCH_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_TICKET_SEARCH_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_TICKET_SEARCH_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_TICKET_SEARCH_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_ticket_search_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$user = getenv('SAFEHARBOR_TICKET_SEARCH_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_TICKET_SEARCH_TEST_PASS') ?: '';
$database = $base . '_http_' . bin2hex(random_bytes(5));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$pdo->exec("USE `$database`");
$pdo->exec("SET time_zone = '+00:00'");

$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-search-http-' . bin2hex(random_bytes(8));
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
    $pdo->exec("INSERT INTO tenants (id, name, slug, plan) VALUES (1, 'Synthetic One', 'synthetic-one', 'suite'), (2, 'Synthetic Two', 'synthetic-two', 'suite')");
    $pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Fixture Dental'), (21, 2, 'Other Tenant Clinic')");
    $u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, initials, role, is_active) VALUES (?,?,?,?,?,?,\'owner\',1)');
    foreach ([[101, 1], [201, 2]] as [$id, $tenant]) {
        $u->execute([$id, $tenant, "u{$id}@fixture.invalid", password_hash('synthetic-not-a-login', PASSWORD_DEFAULT), "Fixture {$id}", 'FX']);
    }
    $t = $pdo->prepare("INSERT INTO tickets (id, tenant_id, client_id, subject, status, channel, sla_due_at, updated_at, resolved_at) VALUES (?, ?, ?, ?, ?, 'email', UTC_TIMESTAMP(), ?, ?)");
    $ticket = static function (int $id, string $subject, string $status, string $updated, int $tenant = 1) use ($t): void {
        $t->execute([$id, $tenant, $tenant === 1 ? 11 : 21, $subject, $status, $updated, $status === 'resolved' ? $updated : null]);
    };
    [$old, $newer, $newest] = ['2026-01-01 00:00:00', '2026-09-01 00:00:00', '2026-10-01 00:00:00'];
    // Not in the palette's local list: resolved, or open and older than its 60 most recently updated.
    $ticket(7002, 'Old resolved router swap', 'resolved', $old);
    $ticket(7050, 'Buried open vlan change', 'open', $old);
    $ticket(7, 'Early resolved keyboard swap', 'resolved', $old);
    $ticket(42, 'Early open badge reader', 'open', $old);
    for ($i = 1; $i <= 60; $i++) $ticket(7300 + $i, 'Filler open ticket', 'open', $newest);
    // Newer tickets that only mention a number, in a subject or a message.
    $ticket(7201, 'Follow-up to #7002 and 7002', 'resolved', $newer);
    $ticket(7202, 'Body mention only', 'resolved', $newer);
    $ticket(7601, 'Ticket 7601 own number', 'resolved', $old);
    $ticket(7602, 'Ticket 7601 again', 'resolved', $newer);
    // More subjects naming 7777 than the result cap holds.
    $ticket(7777, 'Old printer tray', 'resolved', $old);
    for ($i = 1; $i <= 20; $i++) $ticket(7400 + $i, 'Cap mention 7777', 'resolved', $newer);
    // Another tenant's ticket, and a message there that mentions tenant 1's number.
    $ticket(8001, 'Foreign tenant secret', 'open', $newer, 2);
    $m = $pdo->prepare("INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?, 'Fixture Client', 'client', ?)");
    $m->execute([7202, 'See 7002 about the wombatgear light']);
    $m->execute([8001, 'See 7002 about the foreign widget']);

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
    copy($app . '/public/api/search.php', $runtime . '/public/api/search.php');
    $config = require $app . '/config/config.sample.php';
    $config['app_env'] = 'dev';
    $config['force_https'] = false;
    $config['db'] = ['host' => $host, 'port' => (int)$port, 'user' => $user, 'pass' => $pass, 'name' => $database, 'charset' => 'utf8mb4'];
    $config['storage']['attachments_dir'] = $runtime . '/attachments';
    file_put_contents($runtime . '/config/config.php', '<?php return ' . var_export($config, true) . ';');

    // Staff sessions are files: one person in each tenant.
    $sessions = [];
    foreach ([101, 201] as $id) {
        $sessions[$id] = bin2hex(random_bytes(16));
        file_put_contents($runtime . '/sessions/sess_' . $sessions[$id], 'user_id|' . serialize($id));
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'session.save_path=' . $runtime . '/sessions', '-S', $address, '-t', $runtime . '/public'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtime . '/http.log', 'a'], 2 => ['file', $runtime . '/http.log', 'a']], $pipes);
    for ($n = 0; $n < 100; $n++) { $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1); if ($probe) { fclose($probe); break; } usleep(20000); }

    /** @return array{0:int, 1:string} status, raw body */
    $get = static function (string $route, ?int $as) use ($address, $sessions): array {
        $curl = curl_init('http://' . $address . $route);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $as === null ? [] : ['Cookie: PHPSESSID=' . $sessions[$as]]]);
        $raw = (string)curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return [$status, $raw];
    };
    $search = static fn(string $q, ?int $as = 101): array => $get('/api/search.php?q=' . rawurlencode($q), $as);
    $finds = static function (string $why, array $table, int $as = 101) use ($search, $check): void {
        foreach ($table as [$q, $want]) {
            [$status, $raw] = $search($q, $as);
            $got = array_column(json_decode($raw, true)['results'] ?? [], 'id');
            $check($status === 200 && $got === $want, sprintf('%s: %s finds %s%s', $why, json_encode($q, JSON_INVALID_UTF8_SUBSTITUTE), json_encode($want),
                $status === 200 && $got === $want ? '' : ' (got ' . json_encode($got) . ", status $status)"));
        }
    };

    // The fixture's premise: only deep search can find these tickets.
    [$status, $queue] = $get('/', 101);
    preg_match('#<script id="palette-data" type="application/json">(.*?)</script>#s', $queue, $island);
    $local = array_column(json_decode($island[1] ?? '{}', true)['tickets'] ?? [], 'id');
    $check($status === 200 && count($local) === 60 && array_intersect([7002, 7050, 7, 42, 7777], $local) === [],
        'the local palette list is full and holds none of the older tickets');

    [$status, $raw] = $search('#7050', null);
    $check($status === 302 && !str_contains($raw, 'Buried'), 'no session is sent to sign in and sees no ticket');

    [$status, $raw] = $search('#7002');
    $check($status === 200 && (json_decode($raw, true)['results'][0] ?? null) === ['id' => 7002, 'subject' => 'Old resolved router swap', 'status' => 'resolved', 'client' => 'Fixture Dental'],
        'a number returns the same row shape as any other result');

    $finds('with or without #', [['#7002', [7002, 7201, 7202]], ['7002', [7002, 7201, 7202]], ['#7050', [7050]], ['7050', [7050]], ['  #7050  ', [7050]]]);
    $finds('short numbers', [['#7', [7]], ['7', [7]], ['#42', [42]], ['42', [42]]]);
    $finds('its own subject does not move it or repeat it', [['7601', [7601, 7602]]]);

    [, $raw] = $search('7777');
    $capped = array_column(json_decode($raw, true)['results'] ?? [], 'id');
    $check(count($capped) === 15 && $capped[0] === 7777 && count(array_unique($capped)) === 15, 'the cap of 15 keeps the number ahead of the subjects that mention it');

    $finds('only that exact number', [['70', []], ['#70', [7201]], ['700', [7201]], ['70021', []], ['#70021', []], ['#7002x', []], ['x7002', []], ['0', []], ['#0', []], ['#007', []], ['0042', []]]);
    // 4294967303 is ticket 7 if anything on the way cuts it to 32 bits.
    $finds('numbers no ticket can have', [['4294967303', []], ['9999999999', []], ['99999999999', []], [str_repeat('7', 200), []]]);

    $finds('words after the number narrow it', [['#7050 Buried', [7050]], ['7050 Buried', [7050]], ['#7050  Buried', [7050]], ['#7050 vlan change', [7050]],
        ['#7050 BURIED OPEN', [7050]], ['#7050 Dental', [7050]], ['#7050 buried change', [7050]], ['#7050 vlan Dental', [7050]], ['#7050 vlan buried', [7050]],
        ['#7002 Old', [7002, 7202]], ['#7 Early', [7]]]);
    $finds('a word it does not have rules it out', [['#7050 zzzz', []], ['#7050 vlan zzzz', []], ['#7050 Early', []], ['#7050 %', []], ['#7050 _', []], ['#7002 zzzz', [7202]]]);
    $finds('words go to the database as data', [["7050 ' OR '1'='1", []], ["#7050' OR 1=1 -- ", []], ['7050 ") OR 1=1 -- ', []], ["#7050 \xFF", []]]);

    $finds('text search still works', [['Old resolved', [7002]], ['router swap', [7002]], ['Buried', [7050]], ['wombatgear', [7202]]]);
    $finds('short text still finds nothing', [['ro', []], ['#a', []], ['#', []], ['', []]]);

    $finds('another tenant\'s number finds nothing', [['#8001', []], ['8001', []], ['#8001 Foreign', []], ['8001 Other', []], ['Foreign', []]]);
    $finds('a tenant finds its own number, not another\'s', [['#8001', [8001]], ['8001', [8001]], ['#8001 Foreign', [8001]], ['#8001 Clinic', [8001]],
        ['#7050', []], ['7050', []], ['#7', []], ['Buried', []], ['#7002', [8001]]], 201);
    echo "Ticket search HTTP: {$checks} checks passed\n";
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
