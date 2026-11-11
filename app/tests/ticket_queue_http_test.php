<?php
/**
 * The queue page over real HTTP: which tickets each view holds and in what order, the counts on
 * the views bar, the filters, the orders, paging, old links, roles and tenant isolation. Also the
 * ticket page opened from a queue row: its place in that list, and the customer beside it. And
 * the Time page: its rows in plain words, people by name, and the approved list's search.
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
        [8002, 2, 21, null, 'The assistant is working this', 'alert', 'in_progress', 'normal', null, 600, 60],
        [8003, 2, 21, null, 'The assistant needs a person', 'alert', 'in_progress', 'normal', null, 660, 60],
        [8004, 2, 21, null, 'The assistant is working this one late', 'alert', 'in_progress', 'normal', null, -5, 90],
    ] as $row) {
        $t->execute($row);
    }
    // Westy owns a ticket while its workflow is working; once it needs a person the ticket is nobody's.
    // (These rows are never updated below: any change to a ticket hands its workflow to a person.)
    $w = $pdo->prepare("INSERT INTO westy_workflows (tenant_id, client_id, customer_id, ticket_id, workflow_key, alert_key, state,
            version, summary, started_at, updated_at)
        VALUES (2, 21, ?, ?, ?, ?, ?, 1, 'Synthetic workflow', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    foreach ([8002 => 'working', 8003 => 'needs_human', 8004 => 'working'] as $ticketId => $state) {
        $w->execute([sprintf('00000000-0000-4000-8000-%012d', $ticketId), $ticketId, sprintf('11111111-1111-4111-8111-%012d', $ticketId), 'alert:' . $ticketId, $state]);
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
    // What the ticket page shows beside the conversation: a note about the client, and time two
    // different people logged on the same ticket.
    $pdo->exec("UPDATE clients SET notes = 'Call the office line before 9. <i>Ask for Dana.</i>' WHERE id = 11");
    $logged = $pdo->prepare("INSERT INTO time_entries (tenant_id, client_id, entry_key, ticket_id, user_id, minutes, note, billable, source, worked_at)
        VALUES (1, 11, ?, 7002, ?, ?, 'Synthetic time', 1, 'legacy', UTC_TIMESTAMP())");
    $logged->execute(['synthetic-time-maya', 102, 12]);
    $logged->execute(['synthetic-time-jon', 103, 30]);
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
    foreach (['config', 'public/api', 'sessions', 'attachments'] as $part) mkdir($runtime . '/' . $part, 0700, true);
    $copyTree($app . '/lib', $runtime . '/lib');
    copy($app . '/public/index.php', $runtime . '/public/index.php');
    copy($app . '/public/ticket.php', $runtime . '/public/ticket.php');
    copy($app . '/public/time.php', $runtime . '/public/time.php');
    copy($app . '/public/client.php', $runtime . '/public/client.php');
    copy($app . '/public/reports.php', $runtime . '/public/reports.php');
    copy($app . '/public/api/time_entry_review.php', $runtime . '/public/api/time_entry_review.php');
    $config = require $app . '/config/config.sample.php';
    $config['app_env'] = 'dev';
    $config['force_https'] = false;
    $config['db'] = ['host' => $host, 'port' => (int)$port, 'user' => $user, 'pass' => $pass, 'name' => $database, 'charset' => 'utf8mb4'];
    $config['storage']['attachments_dir'] = $runtime . '/attachments';
    file_put_contents($runtime . '/config/config.php', '<?php return ' . var_export($config, true) . ';');

    $sessions = [];
    foreach ([101, 102, 103, 201, 301] as $id) {
        $sessions[$id] = bin2hex(random_bytes(16));
        $tokens[$id] = bin2hex(random_bytes(32));
        file_put_contents($runtime . '/sessions/sess_' . $sessions[$id], 'user_id|' . serialize($id) . 'csrf|' . serialize($tokens[$id]));
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
    $check($current($x) === ['Needs attention61'], 'the queue opens on Needs attention');
    $check($ids($x) === [7006, 7005, 7002, 7008, 7001, 7003],
        'Needs attention: everything unanswered and late or nearly late whoever owns it (a teammate\'s, and my own parked one), the unowned ones, then mine where the customer wrote last');
    $check($tabs($x) === ['Needs attention61', 'Mine42', 'Unassigned23', 'Waiting on client14', 'All open75', 'Resolved6'], 'each view shows how many tickets it holds');
    $check($texts($x, '//p[' . $hasClass('queue-elsewhere') . ']') === ['Not in this view: 1 more in All open, 61 resolved.']
        && $x->query('//p[' . $hasClass('queue-elsewhere') . ']/a[@href="/?view=open"]')->length === 1
        && $x->query('//p[' . $hasClass('queue-elsewhere') . ']/a[@href="/?view=resolved"]')->length === 1, 'the page says what this view leaves out, with links');
    $check($texts($x, '//p[' . $hasClass('page-sub') . ']') === ['7 open tickets. 5 are waiting for a first reply, 2 of them overdue.'], 'the summary counts open, unanswered and overdue');
    $check($ids($queue(102, '/?view=mine')) === [7005, 7002, 7003, 7004], 'Mine: every open ticket I own, the unanswered ones first by due time, parked or not');
    $check($ids($queue(102, '/?view=unassigned')) === [7008, 7001], 'Unassigned: open tickets nobody owns');
    $check($ids($queue(102, '/?view=waiting')) === [7005], 'Waiting on client: parked tickets');
    $x = $queue(102, '/?view=open');
    $check($ids($x) === [7006, 7005, 7002, 7008, 7001, 7003, 7004], 'All open: unanswered by due time (waiting ones too), then the customer\'s turn, then the rest');
    $check($texts($x, '//p[' . $hasClass('queue-elsewhere') . ']') === ['Not in this view: 61 resolved.'], 'All open says how many are resolved');
    $x = $queue(102, '/?view=resolved');
    $check(count($ids($x)) === 50 && array_slice($ids($x), 0, 3) === [9001, 9002, 9003] && !in_array(7001, $ids($x), true), 'Resolved: only resolved tickets, the latest first');
    $check($texts($x, '//p[' . $hasClass('queue-elsewhere') . ']') === ['Not in this view: 7 open.'], 'Resolved says how many are open');

    // The same data means something else to somebody else.
    $x = $queue(103);
    $check($ids($x) === [7006, 7005, 7002, 7008, 7001] && $tabs($x)[0] === 'Needs attention51' && $tabs($x)[1] === 'Mine12',
        'another technician sees every late or nearly late ticket and the unowned ones, but not my customer\'s reply');
    $x = $queue(101);
    $check($ids($x) === [7006, 7005, 7002, 7008, 7001], 'an owner with no tickets of their own still sees what is late and what nobody owns');
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

    // ---- a ticket opened from a queue row knows its place in that list ----
    $x = $queue(102, '/?view=mine');
    $check($x->query('//a[@data-ticket-id="7002"][@href="/ticket.php?id=7002&in=mine"]')->length === 1, 'a queue row links to its ticket and names the view');
    $step = static fn(DOMXPath $x, string $which): array => array_map(static fn(DOMElement $a): string => $a->getAttribute('href'),
        iterator_to_array($x->query('//a[@data-step="' . $which . '"]')));
    $x = $queue(102, '/ticket.php?id=7002&in=mine');
    $check($texts($x, '//*[' . $hasClass('ticket-pos') . ']') === ['2 of 4'] && $step($x, 'prev') === ['/ticket.php?id=7005&in=mine'] && $step($x, 'next') === ['/ticket.php?id=7003&in=mine'],
        'the ticket says where it stands in Mine and links to the tickets beside it, in the queue\'s own order');
    $check($x->query('//a[' . $hasClass('backlink') . '][@href="/?view=mine"]')->length === 1
        && $x->query('//form[@id="reply-form"][@action="/ticket.php?id=7002&in=mine&at=2"]')->length === 1, 'the way back is that view, and a reply comes back to the same place');
    $check($texts($x, '//p[' . $hasClass('ticket-sub') . ']') === ['#7002 for Fixture Dental, opened 7 hours ago by email from Dana Whitfield'], 'the sentence under the title says who asked, how and when');
    $x = $queue(102, '/ticket.php?id=7005&in=mine');
    $check($texts($x, '//*[' . $hasClass('ticket-pos') . ']') === ['1 of 4'] && $step($x, 'prev') === [] && $step($x, 'next') === ['/ticket.php?id=7002&in=mine'], 'the first ticket has nothing before it');
    $x = $queue(102, '/ticket.php?id=7004&in=mine');
    $check($texts($x, '//*[' . $hasClass('ticket-pos') . ']') === ['4 of 4'] && $step($x, 'next') === [] && $step($x, 'prev') === ['/ticket.php?id=7003&in=mine'], 'the last has nothing after it');
    $x = $queue(102, '/ticket.php?id=7002');
    $check($x->query('//*[' . $hasClass('ticket-pos') . ']')->length === 0 && $x->query('//*[' . $hasClass('ticket-step') . ']')->length === 0
        && $x->query('//a[' . $hasClass('backlink') . '][@href="/"]')->length === 1
        && $x->query('//form[@id="reply-form"][@action="/ticket.php?id=7002"]')->length === 1, 'opened without a list there is no counter and no arrows');
    $x = $queue(102, '/ticket.php?id=7002&in=' . rawurlencode("mine' OR '1'='1") . '&at=' . rawurlencode('2;--'));
    $check($x->query('//*[' . $hasClass('ticket-pos') . ']')->length === 0 && $x->query('//form[@id="reply-form"][@action="/ticket.php?id=7002"]')->length === 1, 'a list that does not exist is no list');
    $x = $queue(102, '/ticket.php?id=7001&in=mine&at=2');
    $check($texts($x, '//*[' . $hasClass('ticket-pos') . ']') === ['No longer in Mine'] && $step($x, 'prev') === ['/ticket.php?id=7005&in=mine'] && $step($x, 'next') === ['/ticket.php?id=7002&in=mine'],
        'a ticket that is not in the list any more says so, and its old place still leads to the tickets beside it');
    $x = $queue(102, '/ticket.php?id=7008&in=open&client=12');
    $check($texts($x, '//*[' . $hasClass('ticket-pos') . ']') === ['1 of 1'] && $step($x, 'prev') === [] && $step($x, 'next') === []
        && $x->query('//a[' . $hasClass('backlink') . '][@href="/?view=open&client=12"]')->length === 1, 'the filters go along: one client\'s only open ticket is 1 of 1');
    $x = $queue(102, '/ticket.php?id=7002&in=open&sort=newest');
    $check($texts($x, '//*[' . $hasClass('ticket-pos') . ']') === ['3 of 7'] && $step($x, 'prev') === ['/ticket.php?id=7001&in=open&sort=newest'] && $step($x, 'next') === ['/ticket.php?id=7005&in=open&sort=newest'],
        'and so does the order');
    $x = $queue(201, '/ticket.php?id=8001&in=open');
    $check($texts($x, '//*[' . $hasClass('ticket-pos') . ']') === ['2 of 4'] && $step($x, 'prev') === ['/ticket.php?id=8004&in=open'], 'another tenant has its own list');
    $x = $queue(102, '/ticket.php?id=8001&in=open');
    $check($x->query('//*[@id="thread"]')->length === 0 && $x->query('//*[' . $hasClass('ticket-pos') . ']')->length === 0 && $x->query('//a[@data-step]')->length === 0
        && !str_contains($x->document->textContent, 'Another tenant'), 'and its ticket cannot be opened, or stepped to, from this one');

    // ---- the customer beside the conversation ----
    $x = $queue(102, '/ticket.php?id=7002&in=mine');
    $check($texts($x, '//p[' . $hasClass('rail-person') . ']') === ['Dana Whitfield'] && $x->query('//button[@data-copy="dana@example.test"]')->length === 1
        && $x->query('//p[' . $hasClass('rail-line') . ']/a[@href="/client.php?id=11"]')->length === 1, 'Details names the contact, offers their address to copy, and links to the client');
    $check($texts($x, '//p[' . $hasClass('rail-client-note') . ']') === ['Note about Fixture DentalCall the office line before 9. <i>Ask for Dana.</i>']
        && $x->query('//p[' . $hasClass('rail-client-note') . ']//i')->length === 0, 'the client\'s note is there, as text');
    $railTickets = static fn(DOMXPath $x): array => array_map(static fn(DOMElement $a): string => $a->getAttribute('href'),
        iterator_to_array($x->query('//a[' . $hasClass('rail-ticket') . ']')));
    $check($texts($x, '//*[' . $hasClass('rail-others') . ']/h3') === ['Other open tickets for Fixture Dental (5)']
        && $railTickets($x) === ['/ticket.php?id=7003', '/ticket.php?id=7004', '/ticket.php?id=7005', '/ticket.php?id=7001', '/ticket.php?id=7006'],
        'the client\'s other open tickets, the most pressing first: not this one, not resolved ones, not another client\'s');
    $mergeData = json_decode((string)$x->query('//script[@id="merge-data"]')->item(0)?->textContent, true);
    $check(is_array($mergeData) && $mergeData['ticket']['id'] === 7002 && $mergeData['client'] === 'Fixture Dental'
        && array_column($mergeData['choices'], 'id') === [7003, 7004, 7005, 7001, 7006] && $mergeData['more'] === false, 'Merge offers exactly those tickets');
    $check($x->query('//button[@id="merge-btn"][@data-id="7002"]')->length === 1 && $x->query('//button[@id="timer-btn"][@data-id="7002"]')->length === 1
        && $x->query('//*[@id="presence"]')->length === 1 && $x->query('//*[@id="reply"]')->length === 1, 'the page keeps the ids its script and tests rely on');
    $check($x->query('//button[@id="merge-dupe-btn"][@data-src="7002"][@data-dst="7006"]')->length === 1
        && $x->query('//div[' . $hasClass('ticket-callout-caution') . ']//a[@href="/ticket.php?id=7006"]')->length === 1, 'a possible duplicate from the same contact is offered for merging');
    $check($texts($x, '//*[' . $hasClass('rail-minutes') . ']') === ['12 min'] && $texts($queue(103, '/ticket.php?id=7002'), '//*[' . $hasClass('rail-minutes') . ']') === ['30 min']
        && $texts($queue(101, '/ticket.php?id=7002'), '//*[' . $hasClass('rail-minutes') . ']') === ['0 min'], 'each person sees the time they logged on the ticket, never a teammate\'s');
    $x = $queue(102, '/ticket.php?id=7003');
    $check($texts($x, '//article//*[' . $hasClass('msg-role') . ']') === ['Customer', 'Support', 'Customer']
        && $texts($x, '//article//*[' . $hasClass('msg-author') . ']') === ['Dana Whitfield', 'Maya Reyes', 'Dana Whitfield'], 'each message is a card that says who wrote it');
    $x = $queue(102, '/ticket.php?id=7008');
    $check(preg_match('/\A#7008 for Bayside Realty, opened \d+ minutes ago from the portal by Priya Anand\z/', $texts($x, '//p[' . $hasClass('ticket-sub') . ']')[0] ?? '') === 1
        && $texts($x, '//*[' . $hasClass('rail-others') . ']/h3') === ['Other open tickets for Bayside Realty (0)'] && $railTickets($x) === []
        && $x->query('//button[@data-copy]')->length === 0, 'a client with one open ticket lists no others, and a ticket with no contact offers no address');

    // ---- filters narrow any view, and the counts follow ----
    $x = $queue(102, '/?view=open&client=12');
    $check($ids($x) === [7008] && $tabs($x) === ['Needs attention11', 'Mine02', 'Unassigned13', 'Waiting on client04', 'All open15', 'Resolved6'], 'one client: its ticket, and counts for that client only');
    $check($texts($x, '//p[' . $hasClass('queue-elsewhere') . ']') === ['Not in this view: 67 more hidden by the filters.']
        && $x->query('//p[' . $hasClass('queue-elsewhere') . ']/a[@href="/?view=open"]')->length === 1, 'filters say how many tickets they hide, with a link that clears them');
    $links = [];
    foreach ($x->query('//nav[@aria-label="Queue views"]/a') as $a) $links[] = $a->getAttribute('href');
    $check($links === ['/?client=12', '/?view=mine&client=12', '/?view=unassigned&client=12', '/?view=waiting&client=12', '/?view=open&client=12', '/?view=resolved&client=12'],
        'the views keep the filter');
    $check($ids($queue(102, '/?view=open&priority=urgent')) === [7008], 'one priority');
    $check($ids($queue(102, '/?view=open&owner=none')) === [7008, 7001], 'unassigned as a filter');
    $check($ids($queue(102, '/?view=open&owner=103')) === [7006], 'one teammate');
    $check($ids($queue(102, '/?view=open&owner=me')) === [7005, 7002, 7003, 7004] && $ids($queue(103, '/?view=open&owner=me')) === [7006], 'me is whoever is signed in');
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
    $check($x->query('//nav[' . $hasClass('pager') . ']//a[@rel="next"][@href="/?view=resolved&page=2&after=9050"]')->length === 1
        && $x->query('//nav[' . $hasClass('pager') . ']//a[@rel="prev"]')->length === 0, 'Next is a link that names the last ticket of this page; Previous is off on page 1');
    $warned = static fn(DOMXPath $x): int => $x->query('//p[' . $hasClass('queue-note-warn') . ']')->length;
    $x = $queue(102, '/?view=resolved&page=2&after=9050');
    $check($ids($x) === [...range(9051, 9060), 7007] && $texts($x, '//*[' . $hasClass('pager-count') . ']') === ['Showing 51 to 61 of 61'] && $warned($x) === 0,
        'page 2 holds the rest, and says nothing when the list did not move');
    $check($x->query('//nav[' . $hasClass('pager') . ']//a[@rel="prev"][@href="/?view=resolved&before=9051"]')->length === 1, 'Previous names the first ticket of this page');
    $x = $queue(102, '/?view=resolved&before=9051');
    $check($ids($x) === range(9001, 9050) && $warned($x) === 0, 'going back finds the same page 1');
    // A ticket resolved between two page loads pushes every row down by one.
    $pdo->exec("UPDATE tickets SET status = 'resolved', resolved_at = UTC_TIMESTAMP() WHERE id = 7004");
    $x = $queue(102, '/?view=resolved&page=2&after=9050');
    $check($ids($x) === [...range(9050, 9060), 7007] && $warned($x) === 1
        && $x->query('//p[' . $hasClass('queue-note-warn') . ']/a[@href="/?view=resolved"]')->length === 1, 'when the list moved between pages, the page says so and offers page 1');
    $check($warned($queue(102, '/?view=resolved&before=9051')) === 1, 'going back across a moved edge says so too');
    $pdo->exec("UPDATE tickets SET status = 'in_progress', resolved_at = NULL WHERE id = 7004");
    $x = $queue(102, '/?view=resolved&page=2');
    $check($ids($x) === [...range(9051, 9060), 7007] && $warned($x) === 0, 'a page opened without a neighbour to compare just shows its rows');
    $check($ids($queue(102, '/?view=resolved&page=99')) === [...range(9051, 9060), 7007], 'a page past the end is the last page');
    $x = $queue(102, '/?view=open');
    $check($texts($x, '//*[' . $hasClass('pager-count') . ']') === ['7 tickets'] && $x->query('//nav[' . $hasClass('pager') . ']//a')->length === 0, 'one page needs no Next');

    // ---- old links, and anything odd in the address ----
    // Links from before the views existed keep their meaning: every ticket, or every ticket with one status.
    $x = $queue(102, '/?f=all');
    $check(count($ids($x)) === 50 && array_slice($ids($x), 0, 9) === [7008, 7003, 7004, 7005, 7002, 7001, 7006, 9001, 9002]
        && $texts($x, '//*[' . $hasClass('pager-count') . ']') === ['Showing 1 to 50 of 68'], 'an old ?f=all link lists everything, resolved last, by priority and due time, as before');
    $check($current($x) === [] && $texts($x, '//p[' . $hasClass('queue-note') . ']') === ['Every ticket, resolved included: this is how an older link listed the queue. Open Needs attention'],
        'and says what it is, with a way to the new default');
    $check(count($ids($queue(102, '/?f=all&page=2'))) === 18, 'its second page holds the rest');
    $x = $queue(102, '/?f=open');
    $check($ids($x) === [7008, 7002, 7001, 7006] && $texts($x, '//p[' . $hasClass('queue-elsewhere') . ']') === ['Not in this view: 64 more with another status.']
        && $x->query('//p[' . $hasClass('queue-elsewhere') . ']/a[@href="/?f=all"]')->length === 1, 'an old ?f=open link lists tickets whose status is Open, and says what else there is');
    $check($ids($queue(102, '/?f=in_progress')) === [7003, 7004] && $ids($queue(102, '/?f=waiting')) === [7005], 'old ?f=in_progress and ?f=waiting links list that status');
    $check(count($ids($queue(102, '/?f=resolved'))) === 50 && $ids($queue(102, '/?f=bogus')) === [7006, 7005, 7002, 7008, 7001, 7003], 'an old ?f=resolved link lists resolved tickets; an unknown one opens the default');
    $x = $queue(102, '/?view=' . rawurlencode("open' OR '1'='1") . '&client=' . rawurlencode('11 OR 1=1') . '&priority=' . rawurlencode("x'") . '&owner=' . rawurlencode('"><script>') . '&sort=' . rawurlencode('id;--') . '&page=-5');
    $check($current($x) === ['Needs attention61'] && $ids($x) === [7006, 7005, 7002, 7008, 7001, 7003] && $x->query('//script[not(@src)][not(@type="application/json")][contains(., "<script>")]')->length === 0,
        'anything that is not a known choice is ignored');

    // ---- one tenant never sees another's tickets, clients or people ----
    $x = $queue(201);
    $check($ids($x) === [8004, 8001, 8003] && $ids($queue(201, '/?view=open')) === [8004, 8001, 8002, 8003] && $ids($queue(201, '/?view=resolved')) === [], 'another tenant sees only its own tickets');

    // ---- a ticket the assistant is working is the assistant's, in the rows and in the counts ----
    $check($tabs($x) === ['Needs attention31', 'Mine02', 'Unassigned23', 'Waiting on client04', 'All open45', 'Resolved6'],
        'a ticket Westy is working is not counted as unassigned, nor as needing attention unless it is late; one where Westy needs a person is both');
    $check($ids($queue(201, '/?view=unassigned')) === [8001, 8003] && $ids($queue(201, '/?view=open&owner=none')) === [8001, 8003], 'Unassigned, as a view and as a filter, leaves out the ticket Westy is working');
    $x2 = $queue(201, '/?view=open');
    $check($x2->query('//a[@data-ticket-id="8002"]//*[@aria-label="Assigned to Westy"]')->length === 1
        && $x2->query('//a[@data-ticket-id="8004"]//*[@aria-label="Assigned to Westy"]')->length === 1
        && $x2->query('//a[@data-ticket-id="8003"]//*[@aria-label="Assigned to Westy"]')->length === 0, 'and its row shows Westy as the owner, which is what the counts say');
    $check($x->query('//a[@data-ticket-id="8004"]//*[@aria-label="Assigned to Westy"]')->length === 1 && !in_array(8002, $ids($x), true),
        'a ticket Westy is working needs attention only once it is late, and still shows Westy as its owner');
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

    // ---- the Time page: plain words, names, and the approved list's search ----
    $entryOf = $pdo->query('SELECT user_id, id FROM time_entries WHERE tenant_id = 1 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $rowTexts = static fn(DOMXPath $x, string $within, string $class): array => $texts($x, $within . '//*[' . $hasClass($class) . ']');
    $x = $queue(102, '/time.php');
    $check($rowTexts($x, '//div[' . $hasClass('entry-row') . ']', 'entry-min') === ['12 min']
        && $texts($x, '//*[' . $hasClass('entry-tags') . ']/span') === ['Billable', 'Waiting for review']
        && $texts($x, '//p[' . $hasClass('page-sub') . ']') === ['12 min logged today. 1 waiting for review. 0 approved and billable today.'],
        'a technician sees their own time: how long, billable, and that it waits for review');
    $check($x->query('//*[@id="time-review-queue"]')->length === 0 && $x->query('//*[@id="time-adjustment-ledger"]')->length === 0 && $x->query('//form[@id="approved-search"]')->length === 0
        && $x->query('//div[' . $hasClass('entry-row') . ']')->length === 1, 'and nobody else\'s, and none of the review or billing lists');
    $check($texts($x, '//details[' . $hasClass('entry-history') . ']/ul/li[position() > 1]') === ['Logged before timers were recorded.', 'No start and end were measured.']
        && preg_match('/UTC|user #/', implode(' ', $texts($x, '//div[' . $hasClass('entry-row') . ']'))) === 0, 'History says how it was logged, with no UTC stamp and no user number');

    $x = $queue(101, '/time.php');
    $check($rowTexts($x, '//*[@id="time-review-queue"]', 'entry-note') === ['Maya Reyes, Fixture Dental: Synthetic time', 'Jon Sato, Fixture Dental: Synthetic time']
        && $x->query('//*[@id="time-review-queue"]//details[' . $hasClass('entry-history') . '][@open]')->length === 2
        && $x->query('//*[@id="time-review-queue"]//button[' . $hasClass('time-review') . ']')->length === 4, 'an owner sees what waits for review, with what a reviewer weighs open on each row');
    $check($texts($x, '//*[@id="time-adjustment-ledger"]/div[' . $hasClass('empty') . ']/p') === ['No time has been approved yet.']
        && $x->query('//form[@id="approved-search"]//input[@name="approved"]')->length === 1, 'and an empty approved list with its search box');

    // Approve one entry the way the page does.
    $curl = curl_init('http://' . $address . '/api/time_entry_review.php');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['entry_id' => (int)$entryOf[103], 'decision' => 'approved', 'note' => '']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF: ' . $tokens[101], 'Cookie: PHPSESSID=' . $sessions[101]]]);
    $answer = json_decode((string)curl_exec($curl), true);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $check($status === 200 && ($answer['ok'] ?? false) === true && ($answer['review_ack']['decision'] ?? '') === 'approved', 'the owner approves Jon\'s entry');

    $ledger = '//*[@id="time-adjustment-ledger"]/div[' . $hasClass('adjustment-row') . ']';
    $x = $queue(101, '/time.php');
    $check($x->query($ledger)->length === 1 && $x->query($ledger . '[@data-time-entry-id="' . (int)$entryOf[103] . '"]')->length === 1
        && $rowTexts($x, $ledger, 'entry-min') === ['30 min'] && $rowTexts($x, $ledger, 'entry-note') === ['Jon Sato, Fixture Dental: Synthetic time']
        && count($rowTexts($x, '//*[@id="time-review-queue"]', 'entry-note')) === 1, 'the approved entry moves to the approved list');
    $original = $rowTexts($x, $ledger, 'adjustment-original')[0] ?? '';
    $check(str_starts_with($original, 'Original approval: 30 min, billable, by Olive Owner, ') && !str_contains($original, 'UTC')
        && in_array('Entry number ' . (int)$entryOf[103] . '.', $texts($x, $ledger . '//details/ul/li'), true)
        && in_array('The original approval is never erased. To add time, log a new entry.', $texts($x, $ledger . '//details/ul/li'), true),
        'its History names who approved it, in local time, and keeps the entry number and the standing rule');
    $change = $x->query($ledger . '//button[' . $hasClass('time-adjust') . ']')->item(0);
    $check($change instanceof DOMElement && trim($change->textContent) === 'Change' && $change->getAttribute('data-original-minutes') === '30'
        && $change->getAttribute('data-effective-minutes') === '30' && $change->getAttribute('data-original-billable') === '1' && $change->getAttribute('data-version') === '0'
        && $x->query($ledger . '//*[@data-billing-label]')->length === 1, 'Change carries the approved value, and the row says where billing stands');
    $x = $queue(103, '/time.php');
    $check($texts($x, '//*[' . $hasClass('entry-tags') . ']/span') === ['Billable', 'Approved']
        && count(array_filter($texts($x, '//details[' . $hasClass('entry-history') . ']/ul/li'), static fn(string $line): bool => str_starts_with($line, 'Approved as 30 min, billable, by Olive Owner, '))) === 1,
        'Jon sees his entry approved, and by whom');

    // The approved list's search: a ticket number or part of a client's name, in this tenant only.
    $found = static fn(DOMXPath $x): int => $x->query('//*[@id="time-adjustment-ledger"]/div[' . $hasClass('adjustment-row') . ']')->length;
    $note = static fn(DOMXPath $x): array => $texts($x, '//p[' . $hasClass('page-note') . '][@role="status"]');
    $check($found($queue(101, '/time.php?approved=7002')) === 1 && $found($queue(101, '/time.php?approved=%237002')) === 1 && $found($queue(101, '/time.php?approved=7001')) === 0,
        'a ticket number, with or without #, finds that ticket\'s approved time');
    $x = $queue(101, '/time.php?approved=fixture');
    $check($found($x) === 1 && $note($x) === ['Approved time for “fixture”, the newest first.'] && $x->query('//input[@name="approved"][@value="fixture"]')->length === 1
        && $x->query('//form[@id="approved-search"]//a[@href="/time.php"]')->length === 1, 'part of a client\'s name finds its approved time, says so, and offers Clear');
    $x = $queue(101, '/time.php?approved=Bayside');
    $check($found($x) === 0 && $note($x) === ['No approved time matches “Bayside”.'] && $x->query('//*[@id="time-adjustment-ledger"]/div[' . $hasClass('empty') . ']')->length === 0,
        'a client with no approved time says so');
    $check($found($queue(101, '/time.php?approved=' . rawurlencode("%' OR '1'='1"))) === 0 && $found($queue(101, '/time.php?approved=' . rawurlencode('%'))) === 0
        && $found($queue(101, '/time.php?approved=' . rawurlencode('Fixture_Dental'))) === 0, 'what is typed is only ever a value: quotes, % and _ find nothing by themselves');
    $check($found($queue(301, '/time.php?approved=fixture')) === 0 && $found($queue(301, '/time.php?approved=7002')) === 0
        && $queue(102, '/time.php?approved=fixture')->query('//*[@id="time-adjustment-ledger"]')->length === 0, 'another tenant\'s owner finds nothing, and a technician has no such list to search');
    $x = $queue(101, '/time.php?approved_entry_id=999999');
    $check($texts($x, '//div[' . $hasClass('form-error') . ']') === ['No approved entry has that number.'] && $found($queue(101, '/time.php?approved_entry_id=' . (int)$entryOf[103])) === 1,
        'an older link by entry number still works');

    // ---- the client page, read from MySQL: one client's whole picture, and nobody else's ----
    $count = static function (string $where) use ($pdo): int {
        return (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND client_id = 11 AND $where")->fetchColumn();
    };
    $openNow = $count("status <> 'resolved'");
    $resolvedNow = $count("status = 'resolved'");
    $x = $queue(102, '/client.php?id=11');
    $check($texts($x, '//div[' . $hasClass('client-title') . ']/*') === ['Fixture Dental', 'Standard plan']
        && $texts($x, '//div[' . $hasClass('client-actions') . ']/a') === ['New ticket', 'Edit']
        && $x->query('//a[@data-new-ticket][@href="/ticket_new.php?client=11"]')->length === 1,
        'the client page names the client and its plan, then New ticket and Edit');
    $check($texts($x, '//p[' . $hasClass('client-note') . ']') === ['Note Call the office line before 9. <i>Ask for Dana.</i>']
        && $x->query('//p[' . $hasClass('client-note') . ']//i')->length === 0, 'the note about the client is under the head, as text');
    $tiles = array_combine($texts($x, '//div[' . $hasClass('client-stats') . ']//div[' . $hasClass('stat-k') . ']'),
        $texts($x, '//div[' . $hasClass('client-stats') . ']//div[' . $hasClass('stat-v') . ']'));
    // What Reports shows for the same client: approved billable minutes worked in the last 30 days, after any approved change.
    $billable = (int)$pdo->query("SELECT COALESCE(SUM(CASE WHEN COALESCE(a.effective_billable, e.billable) = 1 THEN COALESCE(a.effective_minutes, e.minutes) ELSE 0 END), 0)
        FROM time_entries e LEFT JOIN time_entry_approval_adjustments a ON a.tenant_id = e.tenant_id AND a.time_entry_id = e.id
         AND a.version_no = (SELECT MAX(l.version_no) FROM time_entry_approval_adjustments l WHERE l.tenant_id = e.tenant_id AND l.time_entry_id = e.id)
        WHERE e.tenant_id = 1 AND e.client_id = 11 AND e.approval_status = 'approved' AND e.worked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)")->fetchColumn();
    $check(($tiles['Open tickets'] ?? null) === number_format($openNow)
        && ($tiles['Billable time, 30 days'] ?? null) === ($billable < 60 ? $billable . ' min' : intdiv($billable, 60) . ' h' . ($billable % 60 > 0 ? ' ' . ($billable % 60) . ' min' : ''))
        && preg_match('/^\d+ (min|h)( \d+ min)?$/', (string)($tiles['First reply, 90 days'] ?? '')) === 1 && count($tiles) === 3,
        'the tiles are numbers: open tickets, the first reply inside ninety days, approved billable time inside thirty');
    $rows = $x->query('//div[' . $hasClass('list') . ']/a[' . $hasClass('trow') . ']');
    $origins = $texts($x, '//div[' . $hasClass('list') . ']//span[' . $hasClass('trow-origin') . ']');
    $check($texts($x, '//nav[' . $hasClass('client-filters') . ']/a') === ['Open' . number_format($openNow), 'Resolved' . number_format($resolvedNow)]
        && $rows->length === $openNow && $openNow > 0 && $x->query('//nav[' . $hasClass('pager') . ']')->length === 0
        && $x->query('//div[' . $hasClass('list') . ']/a[@data-status="resolved"]')->length === 0,
        'Open lists every open ticket of this client and none that is resolved');
    $check($origins !== [] && array_filter($origins, static fn(string $line): bool => str_contains($line, 'Fixture Dental')) === []
        && preg_grep('/^By (email|phone) from Dana Whitfield$/', $origins) !== [], 'a row says how the ticket came in and who asked, without repeating the client');
    $x = $queue(102, '/client.php?id=11&show=resolved');
    $shown = [];
    foreach ($x->query('//div[' . $hasClass('list') . ']/a[' . $hasClass('trow') . ']/@data-ticket-id') as $attr) $shown[] = (int)$attr->nodeValue;
    $newest = array_map('intval', $pdo->query("SELECT id FROM tickets WHERE tenant_id = 1 AND client_id = 11 AND status = 'resolved'
        ORDER BY COALESCE(resolved_at, created_at) DESC, id DESC LIMIT 25")->fetchAll(PDO::FETCH_COLUMN));
    $check($resolvedNow > 50 && $shown === $newest && $x->query('//div[' . $hasClass('list') . ']/a[@data-status!="resolved"]')->length === 0
        && $texts($x, '//nav[' . $hasClass('pager') . ']/span[' . $hasClass('pager-count') . ']') === ['Showing 1 to 25 of ' . number_format($resolvedNow)]
        && $x->query('//nav[' . $hasClass('pager') . ']/a[@rel="next"][@href="/client.php?id=11&show=resolved&page=2"]')->length === 1
        && $x->query('//nav[' . $hasClass('pager') . ']/a[@rel="prev"]')->length === 0, 'Resolved lists the newest twenty-five, with Next');
    $lastPage = (int)ceil($resolvedNow / 25);
    $x = $queue(102, '/client.php?id=11&show=resolved&page=9999');
    $check($x->query('//div[' . $hasClass('list') . ']/a[' . $hasClass('trow') . ']')->length === $resolvedNow - ($lastPage - 1) * 25
        && $x->query('//nav[' . $hasClass('pager') . ']/a[@rel="next"]')->length === 0
        && $x->query('//nav[' . $hasClass('pager') . ']/a[@rel="prev"]')->length === 1, 'a page past the end is the last page');
    $check($queue(102, '/client.php?id=11&show=resolved&page=' . rawurlencode('2; DROP TABLE tickets'))->query('//div[' . $hasClass('list') . ']/a[' . $hasClass('trow') . ']')->length === 25
        && $count('1 = 1') === $openNow + $resolvedNow, 'what is typed as a page is only ever a whole number');
    $check($texts($x, '//div[' . $hasClass('contact-row') . ']//div[' . $hasClass('rail-list-name') . ']') === ['Dana Whitfield']
        && $x->query('//div[' . $hasClass('contact-row') . ']//details/form[@data-confirm="Remove Dana Whitfield?"]//input[@name="contact_id"][@value="31"]')->length === 1
        && $texts($x, '//div[' . $hasClass('client-link') . ']/p') === ['Not linked to Milepost. Shared clients are created in Milepost.'],
        'contacts are listed with Remove behind More, and a local client says it is not linked');
    // Another tenant's member is told the client is unknown, whatever list or page they ask for.
    foreach (['/client.php?id=11', '/client.php?id=11&show=resolved&page=2'] as $route) {
        $other = $http($route, 201);
        $check($other['status'] === 200 && str_contains($other['body'], 'Unknown client.') && !str_contains($other['body'], 'Fixture Dental')
            && !str_contains($other['body'], 'Old ticket') && !str_contains($other['body'], 'Dana Whitfield'), "another tenant's member sees nothing of this client at $route");
    }
    $x = $queue(301, '/client.php?id=31');
    $check($texts($x, '//div[' . $hasClass('client-stats') . ']//div[' . $hasClass('stat-k') . ']') === ['Open tickets', 'Billable time, 30 days']
        && $texts($x, '//div[' . $hasClass('client-stats') . ']//div[' . $hasClass('stat-v') . ']') === ['0', '0 min']
        && $texts($x, '//div[' . $hasClass('empty') . ']/p') === ['No open tickets for Brand New.'], 'a client with nothing yet shows zeros and says so');

    // ---- Reports, read from MySQL: one tenant's numbers for one period ----
    $one = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
    $tiles = static fn(DOMXPath $x): array => array_combine($texts($x, '//div[' . $hasClass('report-stats') . ']//div[' . $hasClass('stat-k') . ']'),
        $texts($x, '//div[' . $hasClass('report-stats') . ']//div[' . $hasClass('stat-v') . ']'));
    $team = static function (DOMXPath $x) use ($hasClass): array {
        $rows = [];
        foreach ($x->query('//table[' . $hasClass('report-table') . ']/tbody/tr') as $tr) {
            $cells = [];
            foreach ($x->query('./td', $tr) as $td) $cells[$td->getAttribute('data-label')] = trim((string)preg_replace('/\s+/', ' ', $td->textContent));
            $link = $x->query('./td[@data-label="Open now"]//a/@href', $tr)->item(0);
            $name = $x->query('./th/span/span[last()]', $tr)->item(0);
            $rows[trim((string)$name?->textContent)] = $cells + ['link' => $link?->nodeValue];
        }
        return $rows;
    };
    $x = $queue(101, '/reports.php');
    $shown = $tiles($x);
    $check($texts($x, '//p[' . $hasClass('page-sub') . ']') === ['How the desk did in the last 30 days.']
        && array_keys($shown) === ['New tickets', 'Resolved', 'First reply (median)', 'On time', 'Rated Great']
        && $shown['New tickets'] === number_format($one("SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"))
        && $shown['Resolved'] === number_format($one("SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND resolved_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"))
        && preg_match('/^\d+ (min|h)( \d+ min)?$/', $shown['First reply (median)']) === 1 && preg_match('/^\d+%$/', $shown['On time']) === 1,
        'Reports opens on the last 30 days with five tiles counted from this tenant\'s tickets');
    $people = $team($x);
    $names = array_keys($people);
    $check(array_slice($names, 0, 3) === ['Jon Sato', 'Maya Reyes', 'Olive Owner'] && end($names) === 'Unassigned'
        && array_intersect($names, ['Other Tenant', 'New Owner']) === [], 'the team table lists this tenant\'s people, A to Z, then the tickets nobody owns');
    $mayaOpen = $one("SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND status <> 'resolved' AND assignee_id = 102");
    $check($mayaOpen > 0 && $people['Maya Reyes']['Open now'] === (string)$mayaOpen && $people['Maya Reyes']['link'] === '/?view=open&owner=102'
        && count($ids($queue(101, '/?view=open&owner=102'))) === $mayaOpen, 'Open now for a person is a link to exactly those tickets in the queue');
    $check($people['Unassigned']['link'] === ((int)$people['Unassigned']['Open now'] > 0 ? '/?view=unassigned' : null)
        && count($ids($queue(101, '/?view=unassigned'))) === (int)$people['Unassigned']['Open now'], 'Unassigned is the queue\'s own Unassigned list');
    $check(array_sum(array_map(static fn(array $row): int => (int)str_replace(',', '', $row['Resolved']), $people)) === (int)str_replace(',', '', $shown['Resolved']),
        'the Resolved column adds up to the Resolved tile');
    $oldest = array_map(static fn($id): string => '/ticket.php?id=' . (int)$id, $pdo->query("SELECT id FROM tickets WHERE tenant_id = 1 AND status <> 'resolved'
        ORDER BY created_at ASC, id ASC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN));
    $rowLinks = static function (DOMXPath $x, int $column) use ($hasClass): array {
        $out = [];
        foreach ($x->query('//div[' . $hasClass('report-grid') . ']/div[' . $column . ']//a[' . $hasClass('report-row') . ']/@href') as $href) $out[] = $href->nodeValue;
        return $out;
    };
    $check($oldest !== [] && $rowLinks($x, 1) === $oldest, 'Open longest lists the tickets that have been open longest, oldest first');
    $check(array_diff($rowLinks($x, 2), ['/client.php?id=11', '/client.php?id=12']) === [], 'Time by client names only this tenant\'s clients');
    $check($x->query('//a[@href="/reports_export.php?days=30"]')->length === 1 && $texts($x, '//a[@href="/reports_export.php?days=30"]') === ['Export time']
        && $queue(102, '/reports.php')->query('//a[starts-with(@href, "/reports_export.php")]')->length === 0
        && $tiles($queue(102, '/reports.php')) === $shown, 'an owner can export the period; a technician sees the same numbers and no export');
    // A technician's table: their own row, exactly as the owner sees it, beside the whole team's. No colleague has a row.
    $x = $queue(102, '/reports.php');
    $mine = $team($x);
    $tableText = (string)$x->query('//table[' . $hasClass('report-table') . ']')->item(0)?->textContent;
    $check(array_keys($mine) === ['Maya Reyes', 'Whole team'] && $mine['Maya Reyes'] === $people['Maya Reyes']
        && !str_contains($tableText, 'Jon Sato') && !str_contains($tableText, 'Olive Owner') && !str_contains($tableText, 'Unassigned')
        && $texts($x, '//div[' . $hasClass('page-reports') . ']/h2') === ['You and the team', 'Open longest', 'Time by client']
        && $texts($queue(101, '/reports.php'), '//div[' . $hasClass('page-reports') . ']/h2') === ['By technician', 'Open longest', 'Time by client'],
        'a technician sees their own row and the whole team\'s, and no colleague\'s; an owner sees everyone');
    $allOpen = $one("SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND status <> 'resolved'");
    $check($mine['Whole team']['Open now'] === number_format($allOpen) && $mine['Whole team']['link'] === '/?view=open'
        && count($ids($queue(102, '/?view=open'))) === $allOpen
        && $mine['Whole team']['Resolved'] === $shown['Resolved'] && $mine['Whole team']['First reply'] === $shown['First reply (median)']
        && str_starts_with($mine['Whole team']['On time'], $shown['On time'] . ' '),
        'the whole team\'s row is the tiles\' own totals, and its Open now is the queue\'s All open list');
    $x = $queue(101, '/reports.php?days=90');
    $check($texts($x, '//p[' . $hasClass('page-sub') . ']') === ['How the desk did in the last 90 days.']
        && $tiles($x)['New tickets'] === number_format($one("SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)"))
        && $x->query('//a[@href="/reports_export.php?days=90"]')->length === 1
        && $texts($x, '//nav[@aria-label="Period"]/a[@aria-current="page"]') === ['Last 90 days'], 'the period is one choice for the whole page, and the export follows it');
    foreach (['365', '0', rawurlencode('7 OR 1=1'), rawurlencode('7; DROP TABLE tickets')] as $odd) {
        $check($texts($queue(101, '/reports.php?days=' . $odd), '//p[' . $hasClass('page-sub') . ']') === ['How the desk did in the last 30 days.'],
            "a period that is not one of the three offered is the last 30 days ($odd)");
    }
    // Another tenant. Its one member is a technician: their own row and their own team's totals, and nothing of tenant 1.
    $x = $queue(201, '/reports.php');
    $theirs = $team($x);
    $check(array_keys($theirs) === ['Other Tenant', 'Whole team'] && !str_contains($x->document->textContent, 'Fixture Dental')
        && !str_contains($x->document->textContent, 'Maya Reyes')
        && $theirs['Whole team']['Open now'] === (string)$one("SELECT COUNT(*) FROM tickets WHERE tenant_id = 2 AND status <> 'resolved'"),
        'another tenant\'s member sees only their own tenant');
    // The same person as an admin sees every row. That tenant's unowned tickets: two the assistant is
    // working (the queue shows them as Westy's), and two nobody has, which is exactly the queue's
    // Unassigned list. (Nothing after this block reads this account.)
    $pdo->exec("UPDATE users SET role = 'admin' WHERE id = 201");
    $x = $queue(201, '/reports.php');
    $theirs = $team($x);
    $working = $one("SELECT COUNT(*) FROM tickets t JOIN westy_workflows w ON w.ticket_id = t.id AND w.tenant_id = t.tenant_id AND w.client_id = t.client_id
        WHERE t.tenant_id = 2 AND t.status = 'in_progress' AND t.assignee_id IS NULL AND w.state = 'working'");
    // Westy's row carries its own tickets' numbers: one of its two is overdue with no reply; neither of nobody's is due yet.
    $check(array_keys($theirs) === ['Other Tenant', 'Westy', 'Unassigned']
        && $theirs['Westy']['Resolved'] === '0' && $theirs['Westy']['First reply'] === 'None' && $theirs['Westy']['On time'] === '0% 0 of 1'
        && $theirs['Unassigned']['First reply'] === 'None' && $theirs['Unassigned']['On time'] === 'None',
        'a ticket the assistant is working counts under Westy for First reply and On time, not under Unassigned');
    $held = $texts($x, '//div[' . $hasClass('report-grid') . ']/div[1]//span[' . $hasClass('report-row-sub') . ']');
    sort($held);
    $check($held === ['Other Fixture, Westy', 'Other Fixture, Westy', 'Other Fixture, unassigned', 'Other Fixture, unassigned'],
        'Open longest names Westy on the tickets the assistant is working');
    $check($working === 2 && $theirs['Westy']['Open now'] === (string)$working && $theirs['Westy']['link'] === null
        && $theirs['Unassigned']['link'] === '/?view=unassigned'
        && count($ids($queue(201, '/?view=unassigned'))) === (int)$theirs['Unassigned']['Open now']
        && (int)$theirs['Westy']['Open now'] + (int)$theirs['Unassigned']['Open now'] + (int)$theirs['Other Tenant']['Open now']
            === $one("SELECT COUNT(*) FROM tickets WHERE tenant_id = 2 AND status <> 'resolved'"),
        'tickets the assistant is working have their own row, apart from Unassigned, and every open ticket is counted once');
    $x = $queue(301, '/reports.php');
    $check(array_values($tiles($x)) === ['0', '0', 'No replies', 'Nothing due', 'No ratings']
        && $texts($x, '//div[' . $hasClass('report-grid') . ']//div[' . $hasClass('empty') . ']/p') === ['No open tickets.', 'No approved billable time in the last 30 days.'],
        'a desk with nothing yet shows five tiles in words and two empty lists');

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
