<?php
/**
 * The queue page over real HTTP: which tickets each view holds and in what order, the counts on
 * the views bar, the filters, the orders, paging, old links, roles and tenant isolation.
 * A disposable loopback MySQL, a runtime copy of the app on PHP's built-in server, synthetic data
 * only, no mail.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_QUEUE_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_QUEUE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_QUEUE_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_QUEUE_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_queue_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$user = getenv('SAFEHARBOR_QUEUE_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_QUEUE_TEST_PASS') ?: '';
$database = $base . '_http_' . bin2hex(random_bytes(5));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$pdo->exec("USE `$database`");
$pdo->exec("SET time_zone = '+00:00'");

$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-ticket-queue-http-' . bin2hex(random_bytes(8));
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
    $pdo->exec("INSERT INTO tenants (id, name, slug, plan) VALUES (1, 'Synthetic One', 'synthetic-one', 'suite'),
        (2, 'Synthetic Two', 'synthetic-two', 'suite'), (3, 'Synthetic Three', 'synthetic-three', 'suite')");
    $pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Fixture Dental'), (12, 1, 'Bayside Realty'), (21, 2, 'Other Fixture'), (31, 3, 'Brand New')");
    $pdo->exec("INSERT INTO contacts (id, client_id, name, email) VALUES (31, 11, 'Dana Whitfield', 'dana@example.test')");
    $hash = password_hash('synthetic-not-a-login', PASSWORD_DEFAULT);
    $u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, initials, role, is_active) VALUES (?,?,?,?,?,?,?,1)');
    foreach ([[101, 1, 'Olive Owner', 'owner'], [102, 1, 'Maya Reyes', 'tech'], [103, 1, 'Jon Sato', 'tech'],
        [201, 2, 'Other Tenant', 'tech'], [301, 3, 'New Owner', 'owner']] as [$id, $tenant, $name, $role]) {
        $u->execute([$id, $tenant, "u{$id}@example.test", $hash, $name, 'F' . $role[0], $role]);
    }

    // Times are minutes from now: due (negative is overdue) and opened (always in the past).
    $t = $pdo->prepare("INSERT INTO tickets (id, tenant_id, client_id, contact_id, subject, channel, status, priority, assignee_id, sla_due_at, created_at)
        VALUES (?,?,?,?,?,?,?,?,?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE))");
    foreach ([
        [7001, 1, 11, 31, 'Unowned printer', 'email', 'open', 'normal', null, 300, 60],
        [7002, 1, 11, 31, 'Mine and due soon', 'email', 'open', 'normal', 102, 60, 420],
        [7003, 1, 11, 31, 'Customer wrote back', 'email', 'in_progress', 'high', 102, -180, 660],
        [7004, 1, 11, 31, 'We wrote last', 'email', 'in_progress', 'normal', 102, -180, 1200],
        [7005, 1, 11, 31, 'Parked without a reply', 'phone', 'waiting', 'normal', 102, -60, 540],
        [7006, 1, 11, 31, 'Somebody else is late', 'email', 'open', 'low', 103, -120, 600],
        [7007, 1, 11, 31, 'Done last week', 'email', 'resolved', 'normal', 102, -6000, 12000],
        [7008, 1, 12, null, 'Portal request', 'portal', 'open', 'urgent', null, 180, 30],
        [8001, 2, 21, null, 'Another tenant', 'email', 'open', 'normal', null, 60, 60],
    ] as $row) {
        $t->execute($row);
    }
    $pdo->exec("UPDATE tickets SET resolved_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 HOUR) WHERE id = 7007");
    $m = $pdo->prepare('INSERT INTO messages (ticket_id, author_name, kind, body, created_at) VALUES (?,?,?,?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE))');
    foreach ([
        [7003, 'Dana Whitfield', 'client', 'It is broken.', 660], [7003, 'Maya Reyes', 'tech', 'Try this.', 600], [7003, 'Dana Whitfield', 'client', 'Still broken.', 120],
        [7004, 'Dana Whitfield', 'client', 'Please help.', 1200], [7004, 'Maya Reyes', 'tech', 'Done, please check.', 1140],
        [7008, 'Priya Anand', 'client', 'The front desk cannot print.', 30],
    ] as $row) {
        $m->execute($row);
    }
    // Sixty more resolved tickets, the newest resolution first: 9001 an hour ago ... 9060 sixty hours ago.
    $old = $pdo->prepare("INSERT INTO tickets (id, tenant_id, client_id, subject, channel, status, sla_due_at, created_at, resolved_at)
        VALUES (?, 1, 11, ?, 'email', 'resolved', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY), DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR))");
    for ($n = 1; $n <= 60; $n++) $old->execute([9000 + $n, 'Old ticket ' . $n, $n]);

    // A runtime copy of the app with a settings file built from the sample, as CI's settings step does.
    $copyTree = static function (string $source, string $target): void {
        if (!is_dir($target)) mkdir($target, 0700, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $to = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
            $file->isDir() ? (is_dir($to) || mkdir($to, 0700, true)) : copy($file->getPathname(), $to);
        }
    };
    foreach (['config', 'public', 'sessions', 'attachments'] as $part) mkdir($runtime . '/' . $part, 0700, true);
    $copyTree($app . '/lib', $runtime . '/lib');
    copy($app . '/public/index.php', $runtime . '/public/index.php');
    $config = require $app . '/config/config.sample.php';
    $config['app_env'] = 'dev';
    $config['force_https'] = false;
    $config['db'] = ['host' => $host, 'port' => (int)$port, 'user' => $user, 'pass' => $pass, 'name' => $database, 'charset' => 'utf8mb4'];
    $config['storage']['attachments_dir'] = $runtime . '/attachments';
    file_put_contents($runtime . '/config/config.php', '<?php return ' . var_export($config, true) . ';');

    $sessions = [];
    foreach ([101, 102, 103, 201, 301] as $id) {
        $sessions[$id] = bin2hex(random_bytes(16));
        file_put_contents($runtime . '/sessions/sess_' . $sessions[$id], 'user_id|' . serialize($id) . 'csrf|' . serialize(bin2hex(random_bytes(32))));
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'session.save_path=' . $runtime . '/sessions', '-S', $address, '-t', $runtime . '/public'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtime . '/http.log', 'a'], 2 => ['file', $runtime . '/http.log', 'a']], $pipes);
    for ($n = 0; $n < 100; $n++) { $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1); if ($probe) { fclose($probe); break; } usleep(20000); }

    /** @return array{status:int, body:string, location:string} */
    $http = static function (string $route, ?int $as) use ($address, $sessions): array {
        $curl = curl_init('http://' . $address . $route);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $as === null ? [] : ['Cookie: PHPSESSID=' . $sessions[$as]]]);
        $raw = (string)curl_exec($curl);
        $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => substr($raw, $size),
            'location' => preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $to) === 1 ? $to[1] : ''];
    };
    /** The queue as one person sees it at one address. */
    $queue = static function (int $as, string $route = '/') use ($http): DOMXPath {
        $r = $http($route, $as);
        if ($r['status'] !== 200) throw new RuntimeException("FAIL $route answered {$r['status']}");
        if (preg_match('/(?:<b>|^)(?:Warning|Notice|Deprecated|Fatal error)(?:<\/b>)?:/m', $r['body']) === 1) {
            throw new RuntimeException("FAIL $route printed a PHP warning");
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $r['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    };
    $texts = static function (DOMXPath $x, string $query): array {
        $out = [];
        foreach ($x->query($query) as $node) $out[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        return $out;
    };
    $hasClass = static fn(string $class): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
    /** The ticket numbers on the page, top to bottom. */
    $ids = static function (DOMXPath $x) use ($hasClass): array {
        $out = [];
        foreach ($x->query('//a[' . $hasClass('trow') . '][@data-ticket-id]') as $row) $out[] = (int)$row->getAttribute('data-ticket-id');
        return $out;
    };
    $tabs = static fn(DOMXPath $x): array => $texts($x, '//nav[@aria-label="Queue views"]/a');
    $current = static fn(DOMXPath $x): array => $texts($x, '//nav[@aria-label="Queue views"]/a[@aria-current="page"]');
    $says = static fn(DOMXPath $x): array => $texts($x, '//div[' . $hasClass('empty') . ']/p');

    // ---- signed out ----
    $r = $http('/', null);
    $check($r['status'] === 302 && str_starts_with($r['location'], '/login.php'), 'nobody signed in is sent to sign in');

    // ---- what each view holds, for a technician with work of their own ----
    $x = $queue(102);
    $check($current($x) === ['Needs attention41'], 'the queue opens on Needs attention');
    $check($ids($x) === [7002, 7008, 7001, 7003], 'Needs attention: mine and due soon, the unowned ones by due time, then mine where the customer wrote last');
    $check($tabs($x) === ['Needs attention41', 'Mine42', 'Unassigned23', 'Waiting on client14', 'All open75', 'Resolved6'], 'each view shows how many tickets it holds');
    $check($texts($x, '//p[' . $hasClass('page-sub') . ']') === ['7 open tickets. 5 are waiting for a first reply, 2 of them overdue.'], 'the summary counts open, unanswered and overdue');
    $check($ids($queue(102, '/?view=mine')) === [7002, 7003, 7004, 7005], 'Mine: every open ticket I own, the unanswered one first, the parked one last');
    $check($ids($queue(102, '/?view=unassigned')) === [7008, 7001], 'Unassigned: open tickets nobody owns');
    $check($ids($queue(102, '/?view=waiting')) === [7005], 'Waiting on client: parked tickets');
    $check($ids($queue(102, '/?view=open')) === [7006, 7002, 7008, 7001, 7003, 7004, 7005], 'All open: unanswered by due time, then the customer\'s turn, then the rest');
    $x = $queue(102, '/?view=resolved');
    $check(count($ids($x)) === 50 && array_slice($ids($x), 0, 3) === [9001, 9002, 9003] && !in_array(7001, $ids($x), true), 'Resolved: only resolved tickets, the latest first');

    // The same data means something else to somebody else.
    $x = $queue(103);
    $check($ids($x) === [7006, 7008, 7001] && $tabs($x)[0] === 'Needs attention31' && $tabs($x)[1] === 'Mine12', 'another technician sees their own late ticket and the unowned ones');
    $x = $queue(101);
    $check($ids($x) === [7008, 7001], 'an owner with no tickets of their own sees what nobody owns');
    $x = $queue(101, '/?view=mine');
    $check($ids($x) === [] && $says($x) === ['No open tickets are assigned to you.']
        && $x->query('//div[' . $hasClass('empty') . ']/a[@href="/?view=unassigned"]')->length === 1, 'an empty view says so and offers one way on');

    // ---- a row says who asked and whose turn it is ----
    $x = $queue(102, '/?view=open');
    $origin = static fn(int $id): array => $texts($x, '//a[@data-ticket-id="' . $id . '"]//*[' . $hasClass('trow-origin') . ']');
    $check($origin(7008) === ['Bayside Realty, from the portal by Priya Anand'] && $origin(7001) === ['Fixture Dental, by email from Dana Whitfield']
        && $origin(7005) === ['Fixture Dental, by phone from Dana Whitfield'], 'the second line names the client, the way in and the person');
    $flagged = [];
    foreach ($x->query('//a[@data-ticket-id][.//*[' . $hasClass('trow-flag') . ']]') as $row) $flagged[] = (int)$row->getAttribute('data-ticket-id');
    $check($flagged === [7003], 'only the ticket the customer answered after us is flagged');
    $check($texts($x, '//*[' . $hasClass('trow-head') . ']/span') === ['Priority', 'No.', 'Ticket', 'Status', 'Owner', 'First response', 'Updated'], 'the columns are named in words');

    // ---- filters narrow any view, and the counts follow ----
    $x = $queue(102, '/?view=open&client=12');
    $check($ids($x) === [7008] && $tabs($x) === ['Needs attention11', 'Mine02', 'Unassigned13', 'Waiting on client04', 'All open15', 'Resolved6'], 'one client: its ticket, and counts for that client only');
    $links = [];
    foreach ($x->query('//nav[@aria-label="Queue views"]/a') as $a) $links[] = $a->getAttribute('href');
    $check($links === ['/?client=12', '/?view=mine&client=12', '/?view=unassigned&client=12', '/?view=waiting&client=12', '/?view=open&client=12', '/?view=resolved&client=12'],
        'the views keep the filter');
    $check($ids($queue(102, '/?view=open&priority=urgent')) === [7008], 'one priority');
    $check($ids($queue(102, '/?view=open&owner=none')) === [7008, 7001], 'unassigned as a filter');
    $check($ids($queue(102, '/?view=open&owner=103')) === [7006], 'one teammate');
    $check($ids($queue(102, '/?view=open&owner=me')) === [7002, 7003, 7004, 7005] && $ids($queue(103, '/?view=open&owner=me')) === [7006], 'me is whoever is signed in');
    $x = $queue(102, '/?view=open&client=12&priority=low');
    $check($ids($x) === [] && $says($x) === ['No tickets match these filters.']
        && $x->query('//div[' . $hasClass('empty') . ']/a[@href="/?view=open"]')->length === 1, 'filters that match nothing say so and offer to clear');

    // ---- orders ----
    $check($ids($queue(102, '/?view=open&sort=priority')) === [7008, 7003, 7004, 7005, 7002, 7001, 7006], 'by priority, then by due time');
    $check($ids($queue(102, '/?view=open&sort=newest')) === [7008, 7001, 7002, 7005, 7006, 7003, 7004], 'newest first');
    $check($ids($queue(102, '/?view=open&sort=updated')) === [7008, 7001, 7003, 7002, 7005, 7006, 7004], 'last message first');

    // ---- paging ----
    $x = $queue(102, '/?view=resolved');
    $check($texts($x, '//*[' . $hasClass('pager-count') . ']') === ['Showing 1 to 50 of 61'] && $ids($x) === range(9001, 9050), 'fifty to a page');
    $check($x->query('//nav[' . $hasClass('pager') . ']//a[@rel="next"][@href="/?view=resolved&page=2"]')->length === 1
        && $x->query('//nav[' . $hasClass('pager') . ']//a[@rel="prev"]')->length === 0, 'Next is a link, Previous is off on page 1');
    $x = $queue(102, '/?view=resolved&page=2');
    $check($ids($x) === [...range(9051, 9060), 7007] && $texts($x, '//*[' . $hasClass('pager-count') . ']') === ['Showing 51 to 61 of 61'], 'page 2 holds the rest');
    $check($ids($queue(102, '/?view=resolved&page=99')) === [...range(9051, 9060), 7007], 'a page past the end is the last page');
    $x = $queue(102, '/?view=open');
    $check($texts($x, '//*[' . $hasClass('pager-count') . ']') === ['7 tickets'] && $x->query('//nav[' . $hasClass('pager') . ']//a')->length === 0, 'one page needs no Next');

    // ---- old links, and anything odd in the address ----
    $x = $queue(102, '/?f=waiting');
    $check($current($x) === ['Waiting on client14'] && $ids($x) === [7005], 'an old ?f=waiting link opens Waiting on client');
    $check($current($queue(102, '/?f=in_progress')) === ['All open75'], 'an old ?f=in_progress link opens All open');
    $x = $queue(102, '/?view=' . rawurlencode("open' OR '1'='1") . '&client=' . rawurlencode('11 OR 1=1') . '&priority=' . rawurlencode("x'") . '&owner=' . rawurlencode('"><script>') . '&sort=' . rawurlencode('id;--') . '&page=-5');
    $check($current($x) === ['Needs attention41'] && $ids($x) === [7002, 7008, 7001, 7003] && $x->query('//script[not(@src)][not(@type="application/json")][contains(., "<script>")]')->length === 0,
        'anything that is not a known choice is ignored');

    // ---- one tenant never sees another's tickets, clients or people ----
    $x = $queue(201);
    $check($ids($x) === [8001] && $ids($queue(201, '/?view=open')) === [8001] && $ids($queue(201, '/?view=resolved')) === [], 'another tenant sees only its own ticket');
    $check($texts($x, '//select[@name="client"]/option') === ['All', 'Other Fixture'] && $texts($x, '//select[@name="owner"]/option') === ['Anyone', 'Me', 'Unassigned'],
        'and only its own clients and people in the filters');
    $check($ids($queue(102, '/?view=open&client=21')) === [] && $ids($queue(102, '/?view=open&owner=201')) === [], 'asking for another tenant\'s client or person finds nothing');
    $all = array_merge($ids($queue(102, '/?view=open')), $ids($queue(102, '/?view=resolved')), $ids($queue(102, '/?view=resolved&page=2')));
    $check(count($all) === 68 && !in_array(8001, $all, true), 'no view of this tenant ever holds the other tenant\'s ticket');

    // ---- who may select ----
    $x = $queue(101, '/?view=open');
    $check($x->query('//button[@id="bulk-toggle"][@aria-pressed="false"]')->length === 1 && $x->query('//input[' . $hasClass('bulk-pick') . ']')->length === 7
        && $x->query('//*[@id="bulk-bar"][@data-filter-label="All open"]')->length === 1, 'an owner gets Select, a checkbox per row, and a bar that names the view');
    $x = $queue(102, '/?view=open');
    $check($x->query('//button[@id="bulk-toggle"]')->length === 0 && $x->query('//input[' . $hasClass('bulk-pick') . ']')->length === 0 && $x->query('//*[@id="bulk-bar"]')->length === 0,
        'a technician gets none of them');

    // ---- a workspace with no tickets at all ----
    $x = $queue(301);
    $check($x->query('//*[@id="queue-setup-heading"]')->length === 1 && $ids($x) === [] && $says($x) === ['Nothing needs you right now.'], 'a brand-new workspace shows its owner where to start');
    $check($texts($x, '//p[' . $hasClass('page-sub') . ']') === ['No open tickets.'] && $texts($x, '//*[' . $hasClass('pager-count') . ']') === [], 'and says there are no open tickets');
    $check($queue(301, '/?view=open&client=31')->query('//*[@id="queue-setup-heading"]')->length === 1, 'a filter does not hide the first steps');
    $check($queue(102)->query('//*[@id="queue-setup-heading"]')->length === 0 && $queue(101)->query('//*[@id="queue-setup-heading"]')->length === 0, 'a workspace with tickets never shows them');

    echo "PASS: $checks ticket queue checks\n";
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
