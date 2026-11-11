<?php
/**
 * Status, priority and owner changes over real HTTP: the action endpoint behind the menus and the
 * Resolve button, and the ticket page's "send and set the next status". A disposable loopback
 * MySQL, a runtime copy of the app on PHP's built-in server, synthetic data only, no mail sent.
 *
 * What it guards:
 *  - no status step asks the customer for a rating unless a person chose Resolved;
 *  - a held survey (and a held "assigned to you" email) is withdrawn by Undo;
 *  - teammates can be assigned, and people outside the tenant or switched off cannot, as before;
 *  - the composer's sentence about email is true for the ticket;
 *  - none of it needs the right to remove rows: a second copy of the app runs as a database account
 *    that may only read, add and change, as production's runtime account does for these tables.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_TICKET_ACTION_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_TICKET_ACTION_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_TICKET_ACTION_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_TICKET_ACTION_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_ticket_action_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$user = getenv('SAFEHARBOR_TICKET_ACTION_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_TICKET_ACTION_TEST_PASS') ?: '';
$database = $base . '_http_' . bin2hex(random_bytes(5));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$pdo->exec("USE `$database`");
$pdo->exec("SET time_zone = '+00:00'");

$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-ticket-action-' . bin2hex(random_bytes(8));
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
    $pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Fixture Dental'), (12, 1, 'Bayside Realty'), (21, 2, 'Other Fixture')");
    $pdo->exec("INSERT INTO contacts (id, client_id, name, email) VALUES
        (31, 11, 'Dana Whitfield', 'dana@example.test'), (32, 11, 'Scanner', 'no-reply@example.test'),
        (33, 12, 'Lee Okafor', 'lee@example.test')");
    $hash = password_hash('synthetic-not-a-login', PASSWORD_DEFAULT);
    $u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, initials, role, is_active) VALUES (?,?,?,?,?,?,?,?)');
    foreach ([[101, 1, 'Olive Owner', 'owner', 1], [102, 1, 'Maya Reyes', 'tech', 1], [103, 1, 'Jon Sato', 'tech', 1],
        [104, 1, 'Gone Person', 'tech', 0], [201, 2, 'Other Tenant', 'tech', 1]] as [$id, $tenant, $name, $role, $active]) {
        $u->execute([$id, $tenant, "u{$id}@example.test", $hash, $name, 'F' . $role[0], $role, $active]);
    }
    $t = $pdo->prepare('INSERT INTO tickets (id, tenant_id, client_id, contact_id, subject, channel, status, sla_due_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    foreach ([
        [7001, 1, 11, 31, 'Printer offline', 'email', 'waiting'],      // a person to survey
        [7002, 1, 11, null, 'No contact here', 'phone', 'open'],       // nobody attached
        [7003, 1, 11, 32, 'Scanner bounce', 'email', 'open'],          // a machine address
        [7004, 1, 11, 31, 'Mailbox will not sync', 'email', 'open'],   // send and set the next status
        [7005, 1, 11, 31, 'Answered survey', 'email', 'open'],
        [7006, 1, 11, 31, 'Notes and stale sends', 'email', 'open'],
        [7007, 1, 11, null, 'Portal request', 'portal', 'open'],       // opened in the portal: no contact
        [7008, 1, 11, 31, 'Limited account', 'email', 'open'],         // worked by the account that may not remove rows
        [7009, 1, 11, 31, 'Old survey', 'email', 'open'],
        [7010, 1, 11, 31, 'Undo came late', 'email', 'open'],
        [7011, 1, 11, 33, 'Contact of another client', 'email', 'open'],   // same tenant, but the contact is Bayside Realty's
        [8001, 2, 21, null, 'Another tenant', 'email', 'open'],
    ] as $row) {
        $t->execute($row);
    }
    $pdo->exec("INSERT INTO portal_ticket_requesters (ticket_id, tenant_id, client_id, subject, identity_tenant_slug) VALUES (7007, 1, 11, 't1u5', 'synthetic-one')");
    $pdo->exec("INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (7007, 'Priya Anand', 'client', 'The front desk cannot print.')");
    $pdo->exec("INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (7006, 'Dana Whitfield', 'client', 'First message'), (7006, 'Dana Whitfield', 'client', 'Second message')");
    $firstOn7006 = (int)$pdo->query('SELECT MIN(id) FROM messages WHERE ticket_id = 7006')->fetchColumn();

    // A runtime copy of the app with a settings file built from the sample, as CI's settings step does.
    $copyTree = static function (string $source, string $target): void {
        if (!is_dir($target)) mkdir($target, 0700, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $to = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
            $file->isDir() ? (is_dir($to) || mkdir($to, 0700, true)) : copy($file->getPathname(), $to);
        }
    };
    // Production's runtime account may SELECT, INSERT and UPDATE everywhere but remove rows only
    // from a short list of tables (docs/ticket-bulk-delete.md, Grants). The second copy of the app
    // runs as an account with no right to remove anything, so nothing here can depend on one.
    $limitedUser = 'sh_action_' . bin2hex(random_bytes(4));
    $limitedPass = bin2hex(random_bytes(12));
    $pdo->exec("CREATE USER '$limitedUser'@'%' IDENTIFIED BY '$limitedPass'");
    $pdo->exec("GRANT SELECT, INSERT, UPDATE ON `$database`.* TO '$limitedUser'@'%'");

    // Staff sessions are files: one per person, each with its own CSRF token.
    $sessions = [];
    foreach ([101, 102, 104, 201] as $id) {
        $sessions[$id] = ['id' => bin2hex(random_bytes(16)), 'csrf' => bin2hex(random_bytes(32))];
    }
    /** A runtime copy of the app on its own port, signed in to the database as $dbUser. */
    $start = static function (string $name, string $dbUser, string $dbPass) use ($app, $runtime, $copyTree, $host, $port, $database, $sessions): array {
        $root = $runtime . '/' . $name;
        foreach (['config', 'public/api', 'sessions', 'attachments'] as $part) mkdir($root . '/' . $part, 0700, true);
        $copyTree($app . '/lib', $root . '/lib');
        copy($app . '/public/ticket.php', $root . '/public/ticket.php');
        copy($app . '/public/api/ticket_action.php', $root . '/public/api/ticket_action.php');
        copy($app . '/public/ticket_new.php', $root . '/public/ticket_new.php');
        $config = require $app . '/config/config.sample.php';
        $config['app_env'] = 'dev';
        $config['force_https'] = false;
        $config['db'] = ['host' => $host, 'port' => (int)$port, 'user' => $dbUser, 'pass' => $dbPass, 'name' => $database, 'charset' => 'utf8mb4'];
        $config['storage']['attachments_dir'] = $root . '/attachments';
        file_put_contents($root . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
        foreach ($sessions as $id => $session) {
            file_put_contents($root . '/sessions/sess_' . $session['id'], 'user_id|' . serialize($id) . 'csrf|' . serialize($session['csrf']));
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $listen = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'session.save_path=' . $root . '/sessions', '-S', $listen, '-t', $root . '/public'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtime . '/http.log', 'a'], 2 => ['file', $runtime . '/http.log', 'a']], $pipes);
        for ($n = 0; $n < 100; $n++) { $probe = @stream_socket_client('tcp://' . $listen, $errno, $errstr, 0.1); if ($probe) { fclose($probe); break; } usleep(20000); }
        return [$server, $listen];
    };
    mkdir($runtime, 0700, true);
    [$process, $fullAddress] = $start('full', $user, $pass);
    [$limitedProcess, $limitedAddress] = $start('limited', $limitedUser, $limitedPass);
    $address = $fullAddress;   // which copy the requests below go to

    /** @return array{status:int, body:string, location:string} */
    $http = static function (string $method, string $route, ?int $as, ?string $body, array $headers) use (&$address, &$sessions): array {
        $curl = curl_init('http://' . $address . $route);
        if ($as !== null) $headers[] = 'Cookie: PHPSESSID=' . $sessions[$as]['id'];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $raw = (string)curl_exec($curl);
        $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => substr($raw, $size),
            'location' => preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $to) === 1 ? $to[1] : ''];
    };
    /** A field change as app.js sends it. @return array{0:int, 1:array} status and decoded JSON */
    $act = static function (int $as, array $fields) use ($http, &$sessions): array {
        $r = $http('POST', '/api/ticket_action.php', $as, json_encode($fields), ['Content-Type: application/json', 'X-CSRF: ' . $sessions[$as]['csrf']]);
        return [$r['status'], json_decode($r['body'], true) ?? []];
    };
    /** The ticket page's composer, as a browser submits it. */
    $send = static function (int $as, int $ticket, array $fields) use ($http, &$sessions): array {
        return $http('POST', '/ticket.php?id=' . $ticket, $as,
            http_build_query($fields + ['csrf' => $sessions[$as]['csrf'], 'mode' => 'reply', 'last_message_id' => 0]),
            ['Content-Type: application/x-www-form-urlencoded']);
    };
    /** The ticket page as a technician sees it. */
    $page = static function (int $as, int $ticket) use ($http): DOMXPath {
        $r = $http('GET', '/ticket.php?id=' . $ticket, $as, null, []);
        if ($r['status'] !== 200) throw new RuntimeException("FAIL ticket page $ticket answered {$r['status']}");
        if (preg_match('/(?:<b>|^)(?:Warning|Notice|Deprecated|Fatal error)(?:<\/b>)?:/m', $r['body']) === 1) {
            throw new RuntimeException("FAIL ticket page $ticket printed a PHP warning");
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $r['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    };
    $text = static fn(DOMXPath $x, string $query): string => trim((string)preg_replace('/\s+/', ' ', (string)$x->query($query)->item(0)?->textContent));
    $attr = static fn(DOMXPath $x, string $query, string $name): ?string =>
        ($node = $x->query($query)->item(0)) instanceof DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;

    $ticket = static function (int $id) use ($pdo): array {
        $q = $pdo->prepare('SELECT status, priority, assignee_id, resolved_at,
            TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), resurface_at) AS resurface_minutes FROM tickets WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch();
    };
    $surveys = static function (int $id) use ($pdo): int {
        $q = $pdo->prepare('SELECT COUNT(*) FROM csat WHERE ticket_id = ?');
        $q->execute([$id]);
        return (int)$q->fetchColumn();
    };
    /**
     * A ticket's mail whose subject starts with $subject and that was sent or still will be.
     * hold: seconds until the dispatcher may send it.
     */
    $mail = static function (int $id, string $subject = '') use ($pdo): array {
        $q = $pdo->prepare('SELECT to_addr, subject, body_text, sent_at, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), next_try_at) AS hold
            FROM mail_queue WHERE ticket_id = ? AND subject LIKE ? AND (sent_at IS NOT NULL OR attempts < max_attempts) ORDER BY id');
        $q->execute([$id, $subject . '%']);
        return $q->fetchAll();
    };
    /** The reasons written on a ticket's mail that was withdrawn before it left. */
    $withdrawn = static function (int $id) use ($pdo): array {
        $q = $pdo->prepare('SELECT last_error FROM mail_queue WHERE ticket_id = ? AND sent_at IS NULL AND attempts >= max_attempts ORDER BY id');
        $q->execute([$id]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    };
    /** Mark what is still waiting for a ticket as sent, as the dispatcher would. */
    $deliver = static function (int $id) use ($pdo): void {
        $pdo->prepare('UPDATE mail_queue SET sent_at = UTC_TIMESTAMP() WHERE ticket_id = ? AND sent_at IS NULL AND attempts < max_attempts')->execute([$id]);
    };
    /** How many of a ticket's emails the dispatcher would send if it ran this second. */
    /** How many of a ticket's surveys a report for the last day would count as sent (business_reports.php counts by creation time). */
    $sentInWindow = static function (int $id) use ($pdo): int {
        $q = $pdo->prepare('SELECT COUNT(*) FROM csat WHERE ticket_id = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) AND created_at < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
        $q->execute([$id]);
        return (int)$q->fetchColumn();
    };
    $dueNow = static function (int $id) use ($pdo): int {
        $q = $pdo->prepare('SELECT COUNT(*) FROM mail_queue WHERE ticket_id = ? AND sent_at IS NULL AND next_try_at <= UTC_TIMESTAMP() AND attempts < max_attempts');
        $q->execute([$id]);
        return (int)$q->fetchColumn();
    };
    $setStatus = static function (int $id, string $status) use ($pdo): void {
        $pdo->prepare('UPDATE tickets SET status = ?, resolved_at = NULL, resurface_at = NULL WHERE id = ?')->execute([$status, $id]);
    };
    $held = static fn(array $row): bool => $row['sent_at'] === null && (int)$row['hold'] >= 45 && (int)$row['hold'] <= 60;

    // ---- who may act: unchanged ----
    $r = $http('POST', '/api/ticket_action.php', null, json_encode(['id' => 7001, 'field' => 'status', 'value' => 'open']), ['Content-Type: application/json']);
    $check($r['status'] === 302 && str_starts_with($r['location'], '/login.php') && $ticket(7001)['status'] === 'waiting', 'nobody signed in changes nothing');
    [$status] = $act(104, ['id' => 7001, 'field' => 'status', 'value' => 'open']);
    $check($status === 302 && $ticket(7001)['status'] === 'waiting', 'a person who was switched off changes nothing');
    [$status] = $act(102, ['id' => 8001, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($status === 404 && $ticket(8001)['status'] === 'open', 'another tenant\'s ticket is not found');
    [$status] = $act(201, ['id' => 7001, 'field' => 'assignee', 'value' => 'me']);
    $check($status === 404 && $ticket(7001)['assignee_id'] === null, 'and their technician cannot reach this tenant\'s ticket');
    foreach ([['status', 'closed'], ['priority', 'critical'], ['subject', 'x']] as [$field, $value]) {
        [$status] = $act(102, ['id' => 7001, 'field' => $field, 'value' => $value]);
        $check($status === 422, "$field = $value is refused");
    }
    $check($ticket(7001)['status'] === 'waiting' && $ticket(7001)['priority'] === 'normal', 'no refusal changed the ticket');

    // ---- the defect this replaces: Waiting back to Open went through Resolved and sent a survey ----
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'open', 'chosen' => true]);
    $check($status === 200 && $json['status'] === 'open' && $ticket(7001)['status'] === 'open', 'Waiting goes straight to Open');
    $check($surveys(7001) === 0 && $mail(7001) === [], 'and no survey exists and no mail is queued');

    // Every step that does not end in Resolved, from every status, even when a person chose it.
    foreach (array_keys(['open' => 1, 'in_progress' => 1, 'waiting' => 1, 'resolved' => 1]) as $from) {
        foreach (['open', 'in_progress', 'waiting'] as $to) {
            if ($from === $to) continue;
            $setStatus(7001, $from);
            [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => $to, 'chosen' => true]);
            $check($status === 200 && $json['status'] === $to && $surveys(7001) === 0 && $mail(7001) === [], "$from to $to sends no survey");
        }
    }
    // Resolved reached without a person choosing it (what a page loaded before this change sends).
    foreach (['open', 'in_progress', 'waiting'] as $from) {
        $setStatus(7001, $from);
        [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'resolved']);
        $now = $ticket(7001);
        $check($status === 200 && $now['status'] === 'resolved' && $now['resolved_at'] !== null, "$from to resolved without a choice still resolves");
        $check($surveys(7001) === 0 && $mail(7001) === [] && $json['toast'] === 'Resolved. No survey is sent.', 'but sends no survey, and says so');
    }
    foreach (['yes', 1, 'true'] as $notTrue) {
        $setStatus(7001, 'open');
        $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'resolved', 'chosen' => $notTrue]);
        $check($surveys(7001) === 0, 'chosen = ' . var_export($notTrue, true) . ' is not a choice');
    }

    // Waiting parks the ticket for three days; leaving Waiting clears that.
    $setStatus(7001, 'open');
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'waiting', 'chosen' => true]);
    $minutes = (int)$ticket(7001)['resurface_minutes'];
    $check($minutes >= 4318 && $minutes <= 4320, 'Waiting comes back in 72 hours');
    $check($json['toast'] === 'Waiting on the customer. The ticket comes back in 3 days if they stay silent.', 'and the toast says so');
    $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'in_progress', 'chosen' => true]);
    $check($ticket(7001)['resurface_minutes'] === null, 'leaving Waiting clears the return date');

    // ---- a chosen Resolve: one survey, held for the undo window ----
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $survey = $mail(7001, '[#7001] Resolved: ');
    $check($status === 200 && $ticket(7001)['status'] === 'resolved' && $surveys(7001) === 1, 'a chosen Resolve creates the survey');
    $check(count($survey) === 1 && $survey[0]['to_addr'] === 'dana@example.test' && count($mail(7001)) === 1, 'and queues one survey email to the contact');
    $token = (string)$pdo->query('SELECT token FROM csat WHERE ticket_id = 7001')->fetchColumn();
    $check(str_contains($survey[0]['body_text'], 'csat.php?t=' . $token . '&s=3'), 'whose links carry that survey\'s token');
    $check($held($survey[0]) && $dueNow(7001) === 0, 'the email is held: the dispatcher would not send it yet');
    $check($json['toast'] === 'Resolved. Dana gets a short survey in a minute.', 'the toast says who is asked and when');

    // Undo inside the window: the status goes back and the survey never leaves.
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'in_progress', 'undo' => true]);
    $check($status === 200 && $ticket(7001)['status'] === 'in_progress' && $ticket(7001)['resolved_at'] === null, 'Undo puts the status back');
    $check($surveys(7001) === 0 && $mail(7001) === [] && $dueNow(7001) === 0, 'and withdraws the survey: nothing is left to send');
    $check($withdrawn(7001) === ['Cancelled: ticket reopened before its survey was sent'], 'the email is cancelled in place, with the reason, not removed');
    $check($json['toast'] === 'Reopened as In progress. The survey was not sent.', 'and says the survey was not sent');

    // An Undo that lands on Resolved, even one that claims a person chose it, asks nobody.
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'resolved', 'chosen' => true, 'undo' => true]);
    $check($status === 200 && $ticket(7001)['status'] === 'resolved' && $surveys(7001) === 0 && $mail(7001) === [] && $json['toast'] === 'Resolved. No survey is sent.',
        'an Undo back to Resolved sends no survey');
    $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'in_progress', 'undo' => true]);

    // A later, real resolve still asks. Once it has been sent it is asked only that once.
    $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($surveys(7001) === 1 && count($mail(7001, '[#7001] Resolved: ')) === 1, 'resolving again after an Undo asks again');
    $deliver(7001);
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'open', 'chosen' => true]);
    $check($surveys(7001) === 1 && count($mail(7001)) === 1 && $json['toast'] === 'Reopened as Open.', 'a survey that was already sent is not withdrawn');
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($surveys(7001) === 1 && count($mail(7001)) === 1, 'and resolving again never asks twice');
    $check($json['toast'] === 'Resolved. Dana was already asked to rate this ticket.', 'the toast says they were already asked');

    // An answered survey stays, whatever happens to the ticket afterwards.
    $act(102, ['id' => 7005, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $pdo->exec("UPDATE csat SET score = 3, responded_at = UTC_TIMESTAMP() WHERE ticket_id = 7005");
    $act(102, ['id' => 7005, 'field' => 'status', 'value' => 'open', 'chosen' => true]);
    $check($surveys(7005) === 1, 'reopening never removes a rating the customer gave');

    // Nobody to ask: no contact, or a machine address (mail-loop protection).
    foreach ([7002 => 'no contact', 7003 => 'a machine address'] as $id => $why) {
        [$status, $json] = $act(102, ['id' => $id, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
        $check($status === 200 && $ticket($id)['status'] === 'resolved' && $surveys($id) === 0 && $mail($id) === [], "a ticket with $why resolves without a survey");
        $check($json['toast'] === 'Resolved. No survey is sent.', 'and says so');
        $act(102, ['id' => $id, 'field' => 'status', 'value' => 'open', 'chosen' => true]);
    }

    // ---- priority ----
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'priority', 'value' => 'urgent']);
    $check($status === 200 && $ticket(7002)['priority'] === 'urgent' && $json['priority'] === 'urgent' && $json['toast'] === 'Priority set to Urgent.', 'priority is set to the chosen value');

    // ---- owner: a technician may give a ticket to a teammate, as before ----
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => 'me']);
    $check($status === 200 && (int)$ticket(7002)['assignee_id'] === 102 && $json['assignee_id'] === 102 && $mail(7002) === [], '"me" assigns the signed-in technician, with no email');
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => 'me']);
    $check($ticket(7002)['assignee_id'] === null && $json['assignee_id'] === null && $json['toast'] === 'Unassigned.', '"me" again unassigns, as before');
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '102']);
    $check((int)$ticket(7002)['assignee_id'] === 102 && $json['toast'] === 'Assigned to you.' && $mail(7002) === [], 'the menu\'s Me assigns by id and never toggles');
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '102']);
    $check((int)$ticket(7002)['assignee_id'] === 102, 'choosing Me twice keeps the ticket');

    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '103']);
    $told = $mail(7002, '[#7002] Assigned to you: ');
    $check($status === 200 && (int)$ticket(7002)['assignee_id'] === 103 && $json['assignee_id'] === 103, 'a technician assigns a teammate');
    $check(count($told) === 1 && $told[0]['to_addr'] === 'u103@example.test' && $held($told[0]), 'the teammate\'s email is queued and held');
    $check($json['toast'] === 'Assigned to Jon Sato. They get an email in a minute.', 'and the toast names them');
    $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '101']);
    $told = $mail(7002, '[#7002] Assigned to you: ');
    $check((int)$ticket(7002)['assignee_id'] === 101 && count($told) === 1 && $told[0]['to_addr'] === 'u101@example.test',
        'reassigning inside the window tells only the person who ends up with the ticket');
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => 'none']);
    $check($ticket(7002)['assignee_id'] === null && $json['toast'] === 'Unassigned.' && $mail(7002, '[#7002] Assigned to you: ') === [],
        'Unassigned clears the owner and withdraws the unsent email');

    $check($withdrawn(7002) === array_fill(0, 2, 'Cancelled: ticket reassigned before this was sent'), 'both withdrawn emails are cancelled in place, with the reason');

    // Undo of an owner change puts the earlier owner back and tells nobody.
    $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '103']);
    $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '101']);
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '103', 'undo' => true]);
    $check($status === 200 && (int)$ticket(7002)['assignee_id'] === 103 && $mail(7002, '[#7002] Assigned to you: ') === [] && $json['toast'] === 'Assigned to Jon Sato.',
        'Undo re-assigns the earlier owner, withdraws the email to the other, and sends none of its own');
    [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => 'none', 'undo' => true]);
    $check($ticket(7002)['assignee_id'] === null && $mail(7002, '[#7002] Assigned to you: ') === [], 'Undo back to nobody sends nothing either');
    $pdo->exec("UPDATE mail_queue SET ticket_id = NULL WHERE ticket_id = 7002");   // start the next checks from a clean slate

    $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '103']);
    $deliver(7002);
    $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => 'none']);
    $check(count($mail(7002, '[#7002] Assigned to you: ')) === 1, 'an assignment email that was already sent is left alone');

    foreach ([201 => 'a person in another tenant', 104 => 'a person who was switched off', 999999 => 'nobody at all'] as $id => $who) {
        $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => '102']);
        [$status, $json] = $act(102, ['id' => 7002, 'field' => 'assignee', 'value' => (string)$id]);
        $check($status === 200 && $ticket(7002)['assignee_id'] === null && $json['assignee_id'] === null, "$who is not assigned; the ticket is left unassigned, as before");
        $check(count($mail(7002, '[#7002] Assigned to you: ')) === 1, 'and gets no email');
    }

    // ---- the ticket page: what the composer promises ----
    $x = $page(102, 7004);
    $check($text($x, '//*[@id="composer-hint"]') === 'Dana gets this by email. The ticket moves to In progress.', 'an open ticket with a contact: emailed, and Send moves it to In progress');
    $check(str_contains($text($x, '//button[@name="after_status"][@value="resolved"]'), 'Dana gets a short survey.'), 'Send and resolve says Dana gets a survey');
    $check($x->query('//button[@id="resolve-btn"][not(@hidden)]')->length === 1 && $x->query('//button[@id="reopen-btn"][@hidden]')->length === 1, 'an open ticket offers Resolve');
    $check($attr($x, '//*[@id="thread"]', 'data-status') === 'open' && $attr($x, '//*[@id="thread"]', 'data-owner') === '', 'the page states its status and owner for the menus');
    $x = $page(102, 7007);
    $check($text($x, '//*[@id="composer-hint"]') === 'Priya sees this in the portal. No email is sent. The ticket moves to In progress.', 'a portal request: seen in the portal, no email');
    $check(str_contains($text($x, '//button[@name="after_status"][@value="resolved"]'), 'No survey is sent.'), 'and no survey on resolve');
    $check($text($page(102, 7002), '//*[@id="composer-hint"]') === 'No customer is attached. This reply is saved on the ticket only. The ticket moves to In progress.',
        'a ticket with no contact says nobody is attached');
    $check($text($page(102, 7003), '//*[@id="composer-hint"]') === 'Scanner\'s address cannot receive mail, so no email is sent. This reply is saved on the ticket only. The ticket moves to In progress.',
        'a contact with a machine address is named, and the page says its address cannot receive mail');

    // ---- a contact of another client, even in the same tenant, is nobody to this ticket ----
    $x = $page(102, 7011);
    $check($text($x, '//*[@id="composer-hint"]') === 'No customer is attached. This reply is saved on the ticket only. The ticket moves to In progress.'
        && str_contains($text($x, '//button[@name="after_status"][@value="resolved"]'), 'No survey is sent.'),
        'a ticket whose contact belongs to another client says nobody is attached, and promises no survey');
    $check($x->query('//button[@data-copy]')->length === 0 && $x->query('//p[contains(concat(" ", normalize-space(@class), " "), " rail-person ")]')->length === 0
        && !str_contains($x->document->textContent, 'lee@example.test'), 'and the page shows neither that person nor their address');
    $r = $send(102, 7011, ['reply' => 'Checking this now.']);
    $check($r['status'] === 302 && $ticket(7011)['status'] === 'in_progress' && $mail(7011) === [], 'a reply on it is saved and emails nobody');
    [$status, $json] = $act(102, ['id' => 7011, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($status === 200 && $ticket(7011)['status'] === 'resolved' && $surveys(7011) === 0 && $mail(7011) === [] && $json['toast'] === 'Resolved. No survey is sent.',
        'and resolving it asks nobody for a rating');

    // A plain Send: Open becomes In progress, the contact is emailed, nobody is surveyed.
    $r = $send(102, 7004, ['reply' => 'Looking at it now.']);
    $check($r['status'] === 302 && $r['location'] === '/ticket.php?id=7004#reply', 'a reply redirects back to the ticket');
    $replies = $mail(7004, '[#7004] Mailbox will not sync');
    $check($ticket(7004)['status'] === 'in_progress' && count($replies) === 1 && $replies[0]['to_addr'] === 'dana@example.test' && (int)$replies[0]['hold'] <= 0,
        'a plain Send moves Open to In progress and emails the contact straight away');
    $check($surveys(7004) === 0, 'and asks for no rating');
    $x = $page(102, 7004);
    $check($x->query('//*[@id="ticket-flash"]')->length === 0 && $text($x, '//*[@id="composer-hint"]') === 'Dana gets this by email.', 'a plain Send leaves no notice, and the hint no longer promises a move');

    // Send and wait.
    $r = $send(102, 7004, ['reply' => 'Can you try again?', 'after_status' => 'waiting']);
    $minutes = (int)$ticket(7004)['resurface_minutes'];
    $check($r['status'] === 302 && $ticket(7004)['status'] === 'waiting' && $minutes >= 4318 && $minutes <= 4320 && $surveys(7004) === 0, 'Send and wait parks the ticket for 72 hours, with no survey');
    $x = $page(102, 7004);
    $check($attr($x, '//*[@id="ticket-flash"]', 'data-toast') === 'Reply sent. Waiting on the customer. The ticket comes back in 3 days if they stay silent.'
        && $attr($x, '//*[@id="ticket-flash"]', 'data-undo-status') === 'in_progress', 'the page says what happened and Undo goes back to In progress');
    $check($page(102, 7004)->query('//*[@id="ticket-flash"]')->length === 0, 'the notice shows once');

    // Send and resolve.
    $r = $send(102, 7004, ['reply' => 'Fixed: the profile was rebuilt.', 'after_status' => 'resolved']);
    $survey = $mail(7004, '[#7004] Resolved: ');
    $check($r['status'] === 302 && $ticket(7004)['status'] === 'resolved' && $surveys(7004) === 1 && count($survey) === 1 && $held($survey[0]), 'Send and resolve resolves and holds one survey');
    $check(count($mail(7004, '[#7004] Mailbox will not sync')) === 3, 'and the reply itself is emailed like any other');
    $x = $page(102, 7004);
    $check($attr($x, '//*[@id="ticket-flash"]', 'data-toast') === 'Reply sent. Resolved. Dana gets a short survey in a minute.'
        && $attr($x, '//*[@id="ticket-flash"]', 'data-undo-status') === 'waiting', 'the page says so and Undo goes back to Waiting');
    $check($x->query('//button[@id="reopen-btn"][not(@hidden)]')->length === 1 && str_contains($text($x, '//button[@name="after_status"][@value="resolved"]'), 'No survey is sent.'),
        'a resolved ticket offers Reopen, and promises no second survey');
    [$status] = $act(102, ['id' => 7004, 'field' => 'status', 'value' => 'waiting', 'undo' => true]);
    $check($status === 200 && $ticket(7004)['status'] === 'waiting' && $surveys(7004) === 0 && $mail(7004, '[#7004] Resolved: ') === []
        && count($mail(7004, '[#7004] Mailbox will not sync')) === 3, 'that Undo withdraws the survey and leaves the replies alone');

    // What must not carry a status: a note, a send the page refused as stale, and an empty box.
    $r = $send(102, 7006, ['reply' => 'Spooler was stopped.', 'mode' => 'note', 'after_status' => 'resolved']);
    $notes = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE ticket_id = 7006 AND kind = 'note'")->fetchColumn();
    $check($r['status'] === 302 && $notes === 1 && $ticket(7006)['status'] === 'open' && $surveys(7006) === 0 && $mail(7006) === [], 'a note is saved and never sets a status or sends mail');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE ticket_id = 7006')->fetchColumn();
    $r = $send(102, 7006, ['reply' => 'Typed before the thread changed.', 'after_status' => 'resolved', 'last_message_id' => $firstOn7006]);
    $check($r['status'] === 200 && (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE ticket_id = 7006')->fetchColumn() === $before
        && $ticket(7006)['status'] === 'open' && $surveys(7006) === 0, 'a stale send saves nothing and sets no status');
    $r = $send(102, 7006, ['reply' => '   ', 'after_status' => 'resolved']);
    $check($ticket(7006)['status'] === 'open' && $surveys(7006) === 0, 'an empty Send and resolve changes nothing');
    $x = $page(102, 7006);
    $check($x->query('//*[@id="ticket-flash"]')->length === 0, 'and none of them leaves a notice');

    // No email where the hint promised none.
    $send(102, 7007, ['reply' => 'We are on it.']);
    $send(102, 7003, ['reply' => 'Acknowledged.']);
    $check($mail(7007) === [] && $mail(7003) === [] && $ticket(7007)['status'] === 'in_progress', 'a portal request and a machine contact get no reply email');

    // ---- the same promises under an account that may not remove any row ----
    $address = $limitedAddress;
    $limited = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $limitedUser, $limitedPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $refused = false;
    try { $limited->exec('DELETE FROM csat WHERE id = 0'); } catch (PDOException $denied) { $refused = true; }
    $check($refused, 'the limited account really cannot remove a survey row');
    $limited = null;

    [$status, $json] = $act(102, ['id' => 7008, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $survey = $mail(7008, '[#7008] Resolved: ');
    $token = (string)$pdo->query('SELECT token FROM csat WHERE ticket_id = 7008')->fetchColumn();
    $check($status === 200 && $surveys(7008) === 1 && count($survey) === 1 && $held($survey[0]) && $json['toast'] === 'Resolved. Dana gets a short survey in a minute.',
        'limited account: a chosen Resolve holds one survey');
    $check($sentInWindow(7008) === 1, 'while it is held, the survey counts as sent in the report window it was asked in');
    [$status, $json] = $act(102, ['id' => 7008, 'field' => 'status', 'value' => 'in_progress', 'undo' => true]);
    $check($status === 200 && $ticket(7008)['status'] === 'in_progress' && $mail(7008) === [] && $dueNow(7008) === 0
        && $withdrawn(7008) === ['Cancelled: ticket reopened before its survey was sent'], 'limited account: Undo still stops the survey email from ever leaving');
    $check($json['toast'] === 'Reopened as In progress. The survey was not sent.' && $surveys(7008) === 1, 'the survey row it could not remove stays, unanswered');
    $check($sentInWindow(7008) === 0 && (string)$pdo->query('SELECT created_at FROM csat WHERE ticket_id = 7008')->fetchColumn() === '1970-01-01 00:00:00',
        'and is marked withdrawn on the row, so no report window counts it as sent');
    $x = $page(102, 7008);
    $check(str_contains($text($x, '//button[@name="after_status"][@value="resolved"]'), 'Dana gets a short survey.'), 'and the page still promises a survey on the next resolve');
    [$status, $json] = $act(102, ['id' => 7008, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $survey = $mail(7008, '[#7008] Resolved: ');
    $check($status === 200 && $surveys(7008) === 1 && (string)$pdo->query('SELECT token FROM csat WHERE ticket_id = 7008')->fetchColumn() === $token,
        'limited account: resolving again reuses that one survey');
    $check(count($survey) === 1 && $held($survey[0]) && str_contains($survey[0]['body_text'], 'csat.php?t=' . $token . '&s=3')
        && $json['toast'] === 'Resolved. Dana gets a short survey in a minute.', 'and holds one new email carrying it');
    $check($sentInWindow(7008) === 1, 'now that it is really asked, it counts in the window of the real resolve');
    $deliver(7008);
    $act(102, ['id' => 7008, 'field' => 'status', 'value' => 'open', 'chosen' => true]);
    [$status, $json] = $act(102, ['id' => 7008, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($surveys(7008) === 1 && count($mail(7008, '[#7008] Resolved: ')) === 1 && $json['toast'] === 'Resolved. Dana was already asked to rate this ticket.',
        'limited account: once it has been sent it is never asked twice');

    $act(102, ['id' => 7008, 'field' => 'assignee', 'value' => '103']);
    [$status, $json] = $act(102, ['id' => 7008, 'field' => 'assignee', 'value' => '101']);
    $told = $mail(7008, '[#7008] Assigned to you: ');
    $check($status === 200 && (int)$ticket(7008)['assignee_id'] === 101 && count($told) === 1 && $told[0]['to_addr'] === 'u101@example.test' && $held($told[0]),
        'limited account: reassigning tells only the person who ends up with the ticket');
    $r = $send(102, 7008, ['reply' => 'All set.', 'after_status' => 'waiting']);
    $check($r['status'] === 302 && $ticket(7008)['status'] === 'waiting' && count($mail(7008, '[#7008] Limited account')) === 1, 'limited account: Send and wait saves, parks and emails the reply');

    // Undo pressed after the hold ran out: the dispatcher may already have the email in hand.
    $act(102, ['id' => 7010, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $pdo->exec("UPDATE mail_queue SET next_try_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE ticket_id = 7010");
    [$status, $json] = $act(102, ['id' => 7010, 'field' => 'status', 'value' => 'in_progress', 'undo' => true]);
    $check($status === 200 && $ticket(7010)['status'] === 'in_progress' && $json['toast'] === 'Reopened as In progress. The survey may already have been sent.',
        'an Undo that comes after the hold says the survey may already have been sent');
    $check($mail(7010) === [] && $withdrawn(7010) === ['Cancelled: ticket reopened before its survey was sent (too late to be sure: it may have been sent)'],
        'the email is still cancelled, with a reason that says it was too late to be sure');
    $check($surveys(7010) === 1 && $sentInWindow(7010) === 1, 'and the survey stays counted as asked');
    [$status, $json] = $act(102, ['id' => 7010, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($mail(7010) === [] && $json['toast'] === 'Resolved. Dana was already asked to rate this ticket.', 'so it is never asked a second time');

    // A survey with no record of any email (made before mail was queued) counts as asked.
    $pdo->exec("INSERT INTO csat (ticket_id, token) VALUES (7009, REPEAT('c', 40))");
    [$status, $json] = $act(102, ['id' => 7009, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($status === 200 && $surveys(7009) === 1 && $mail(7009) === [] && $json['toast'] === 'Resolved. Dana was already asked to rate this ticket.',
        'a survey with no email on record is never asked again');

    // ---- "Log a call": a ticket created from the New ticket form ----
    /** Any page as a signed-in person sees it. */
    $view = static function (int $as, string $route) use ($http): DOMXPath {
        $r = $http('GET', $route, $as, null, []);
        if ($r['status'] !== 200) throw new RuntimeException("FAIL $route answered {$r['status']}");
        if (preg_match('/(?:<b>|^)(?:Warning|Notice|Deprecated|Fatal error)(?:<\/b>)?:/m', $r['body']) === 1) throw new RuntimeException("FAIL $route printed a PHP warning");
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $r['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    };
    /** The New ticket form, as a browser submits it. A 200 answer is the form again; its page is returned too. */
    $create = static function (int $as, array $fields, string $query = '') use ($http, &$sessions): array {
        $r = $http('POST', '/ticket_new.php' . $query, $as, http_build_query($fields + ['csrf' => $sessions[$as]['csrf']]), ['Content-Type: application/x-www-form-urlencoded']);
        $x = null;
        if ($r['status'] === 200) {
            $doc = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8">' . $r['body']);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $x = new DOMXPath($doc);
        }
        return [$r['status'], preg_match('~\A/ticket\.php\?id=(\d+)\z~', $r['location'], $to) === 1 ? (int)$to[1] : 0, $x];
    };
    $row = static function (int $id) use ($pdo): array {
        $q = $pdo->prepare('SELECT tenant_id, client_id, contact_id, subject, status, priority, channel, assignee_id, service_goal_target_id, sla_due_at > created_at AS has_goal FROM tickets WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: [];
    };
    $thread = static function (int $id) use ($pdo): array {
        $q = $pdo->prepare('SELECT author_name, kind, body FROM messages WHERE ticket_id = ? ORDER BY id');
        $q->execute([$id]);
        return $q->fetchAll(PDO::FETCH_NUM);
    };
    $count = static fn(string $table): int => (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $options = static function (DOMXPath $x, string $select): array {
        $out = [];
        foreach ($x->query('//select[@id="' . $select . '"]/option') as $o) $out[] = trim($o->textContent);
        return $out;
    };

    $x = $view(102, '/ticket_new.php');
    $check($options($x, 'f-client') === ['Choose a client', 'Bayside Realty', 'Fixture Dental'] && $x->query('//select[@id="f-client"]/option[@selected]')->length === 0,
        'the form lists this tenant\'s clients by name, and none is chosen');
    $check($options($x, 'f-contact') === ['Choose a contact', 'Dana Whitfield', 'Lee Okafor', 'Scanner'], 'and their contacts, never another tenant\'s');
    $check($view(102, '/ticket_new.php?client=12')->query('//select[@id="f-client"]/option[@selected][@value="12"]')->length === 1
        && $view(102, '/ticket_new.php?client=21')->query('//select[@id="f-client"]/option[@selected]')->length === 0, '?client= preselects one of this tenant\'s clients, and only one of them');

    // A phone call, start to finish.
    $before = [$count('tickets'), $count('contacts'), $count('messages')];
    [$status, $called] = $create(102, ['client_id' => 11, 'contact_id' => 31, 'subject' => '  Front office printer is offline ', 'said' => 'The printer shows offline.',
        'told' => 'Restart it and print one page.', 'start_timer' => '1']);
    $made = $row($called);
    $check($status === 302 && $called > 0 && (int)$made['tenant_id'] === 1 && (int)$made['client_id'] === 11 && (int)$made['contact_id'] === 31
        && $made['subject'] === 'Front office printer is offline' && $made['status'] === 'open' && $made['priority'] === 'normal' && $made['channel'] === 'phone'
        && (int)$made['assignee_id'] === 102, 'a logged call becomes an open ticket for that client and contact, by phone, assigned to whoever logged it');
    $check($made['service_goal_target_id'] !== null && (int)$made['has_goal'] === 1, 'with its service goal captured as it opens');
    $check($thread($called) === [['Dana Whitfield', 'client', 'The printer shows offline.'], ['Maya Reyes', 'tech', 'Restart it and print one page.']],
        'what they said opens the conversation in the contact\'s name, then what the technician told them');
    $check($mail($called) === [] && $surveys($called) === 0 && $count('contacts') === $before[1], 'nothing is emailed, nobody is surveyed, no contact is added');
    $x = $page(102, $called);
    $check($x->query('//*[@id="ticket-flash"][@data-start-timer="1"]')->length === 1 && $page(102, $called)->query('//*[@id="ticket-flash"]')->length === 0,
        'its page is asked, once, to start the timer');
    $check($text($x, '//p[contains(concat(" ", normalize-space(@class), " "), " ticket-sub ")]') === "#$called for Fixture Dental, opened 1 minute ago by phone from Dana Whitfield"
        && $x->query('//article//*[contains(concat(" ", normalize-space(@class), " "), " msg-role ")]')->length === 2, 'and shows who called and both messages');

    // A new contact typed in place; a contact picked at the same time does not win.
    [$status, $fresh] = $create(102, ['client_id' => 11, 'contact_id' => 31, 'contact_name' => ' Sam Rivera ', 'contact_email' => 'Sam@Example.TEST', 'subject' => 'New starter', 'said' => 'I need a mailbox.']);
    $made = $row($fresh);
    $added = $pdo->query('SELECT id, client_id, name, email FROM contacts ORDER BY id DESC LIMIT 1')->fetch();
    $check($status === 302 && $count('contacts') === $before[1] + 1 && (int)$added['client_id'] === 11 && $added['name'] === 'Sam Rivera' && $added['email'] === 'sam@example.test'
        && (int)$made['contact_id'] === (int)$added['id'] && $thread($fresh) === [['Sam Rivera', 'client', 'I need a mailbox.']],
        'a new contact is added to that client and is who asked');
    $check($page(102, $fresh)->query('//*[@id="ticket-flash"]')->length === 0, 'with the timer box unticked the page is asked for nothing');

    // A contact of another client is nobody here; the customer's words are then in the client's name.
    [$status, $stray] = $create(102, ['client_id' => 11, 'contact_id' => 33, 'subject' => 'Wrong contact', 'said' => 'Hello.', 'channel' => 'alert', 'priority' => 'urgent']);
    $made = $row($stray);
    $check($status === 302 && $made['contact_id'] === null && $thread($stray) === [['Fixture Dental', 'client', 'Hello.']] && $made['channel'] === 'phone' && $made['priority'] === 'urgent',
        'a contact from another client is dropped, and a ticket typed in by hand is never an alert');
    $check($text($page(102, $stray), '//p[contains(concat(" ", normalize-space(@class), " "), " ticket-sub ")]') === "#$stray for Fixture Dental, opened 1 minute ago by phone",
        'a call with no contact is not said to be from the client itself');

    // Create and resolve: the Resolve button's own rule decides about a survey.
    [$status, $done] = $create(102, ['client_id' => 11, 'contact_id' => 31, 'subject' => 'Password reset', 'said' => 'I am locked out.', 'told' => 'Reset. Try now.',
        'start_timer' => '1', 'after' => 'resolved']);
    $check($status === 302 && $ticket($done)['status'] === 'resolved' && $ticket($done)['resolved_at'] !== null && $surveys($done) === 1
        && count($mail($done)) === 1 && $mail($done)[0]['to_addr'] === 'dana@example.test' && $held($mail($done)[0]),
        'Create and resolve resolves the new ticket and holds one survey for its contact');
    $x = $page(102, $done);
    $check($attr($x, '//*[@id="ticket-flash"]', 'data-toast') === 'Created and resolved. Dana gets a short survey in a minute.' && $attr($x, '//*[@id="ticket-flash"]', 'data-undo-status') === 'open'
        && $attr($x, '//*[@id="ticket-flash"]', 'data-start-timer') === null, 'its page says so, offers Undo, and starts no timer on a resolved ticket');
    [$status, $json] = $act(102, ['id' => $done, 'field' => 'status', 'value' => 'open', 'undo' => true]);
    $check($status === 200 && $ticket($done)['status'] === 'open' && $mail($done) === [] && count($withdrawn($done)) === 1, 'Undo reopens it and takes the survey back before it leaves');
    [$status, $quiet] = $create(102, ['client_id' => 12, 'subject' => 'Quick question', 'after' => 'resolved']);
    $x = $page(102, $quiet);
    $check($status === 302 && $ticket($quiet)['status'] === 'resolved' && $surveys($quiet) === 0 && $mail($quiet) === [] && $thread($quiet) === []
        && $attr($x, '//*[@id="ticket-flash"]', 'data-toast') === 'Created and resolved. No survey is sent.', 'with no contact there is nobody to survey, and the page says so');

    // Refusals: nothing is created, and what was typed comes back.
    $before = [$count('tickets'), $count('contacts'), $count('messages')];
    $unchanged = static fn(): bool => [$count('tickets'), $count('contacts'), $count('messages')] === $before;
    $says = static fn(?DOMXPath $x): string => $x === null ? '' : trim((string)$x->query('//div[contains(concat(" ", normalize-space(@class), " "), " form-error ")]')->item(0)?->textContent);
    [$status, , $x] = $create(102, ['client_id' => 11, 'contact_id' => 31, 'subject' => '   ', 'said' => 'Kept <b>words</b>', 'priority' => 'high']);
    $check($status === 200 && $says($x) === 'Add a subject so the ticket can be found later.' && $unchanged(), 'no subject: the form says what is missing and creates nothing');
    $check(trim((string)$x->query('//textarea[@name="said"]')->item(0)?->textContent) === 'Kept <b>words</b>' && $x->query('//textarea//b')->length === 0
        && $x->query('//select[@id="f-client"]/option[@selected][@value="11"]')->length === 1 && $x->query('//select[@id="f-contact"]/option[@selected][@value="31"]')->length === 1
        && $x->query('//input[@name="priority"][@value="high"][@checked]')->length === 1, 'and keeps the client, the contact, the words and the priority');
    [$status, , $x] = $create(102, ['subject' => 'No client']);
    $check($status === 200 && $says($x) === 'Choose a client from the list.' && $unchanged(), 'no client: refused');
    [$status, , $x] = $create(102, ['client_id' => 21, 'subject' => 'Another tenant\'s client', 'contact_name' => 'Mallory', 'said' => 'Hello.']);
    $check($status === 200 && $says($x) === 'This client is unavailable for new tickets. Pick another client.' && $unchanged(),
        'another tenant\'s client: refused, and the contact typed with it is not added anywhere');
    [$status, , $x] = $create(102, ['client_id' => 999999, 'subject' => 'No such client']);
    $check($status === 200 && $says($x) === 'This client is unavailable for new tickets. Pick another client.' && $unchanged(), 'a client that does not exist: refused');
    [$status, , $x] = $create(102, ['client_id' => 11, 'subject' => 'Bad address', 'contact_name' => 'Sam', 'contact_email' => 'sam at example']);
    $check($status === 200 && $says($x) === 'That email address does not look right. Check it, or leave it empty.' && $unchanged()
        && $x->query('//details[@id="contact-new"][@open]//input[@name="contact_name"][@value="Sam"]')->length === 1, 'a new contact with a broken address: refused, with the contact still open');
    $r = $http('POST', '/ticket_new.php', 102, http_build_query(['client_id' => 11, 'subject' => 'No token']), ['Content-Type: application/x-www-form-urlencoded']);
    $check($r['status'] === 419 && $unchanged(), 'a form without its token creates nothing');
    $r = $http('POST', '/ticket_new.php', null, http_build_query(['client_id' => 11, 'subject' => 'Signed out']), ['Content-Type: application/x-www-form-urlencoded']);
    $check($r['status'] === 302 && str_starts_with($r['location'], '/login.php') && $unchanged(), 'nor does somebody who is not signed in');

    echo "PASS: $checks ticket action checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n" . (is_file($runtime . '/http.log') ? substr((string)file_get_contents($runtime . '/http.log'), -6000) : ''));
    $exit = 1;
} finally {
    foreach ([$process, $limitedProcess ?? null] as $server) {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    }
    if (isset($limitedUser)) $pdo->exec("DROP USER IF EXISTS '$limitedUser'@'%'");
    if (is_dir($runtime)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($runtime);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
exit($exit ?? 0);
