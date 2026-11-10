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
    $pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Fixture Dental'), (21, 2, 'Other Fixture')");
    $pdo->exec("INSERT INTO contacts (id, client_id, name, email) VALUES
        (31, 11, 'Dana Whitfield', 'dana@example.test'), (32, 11, 'Scanner', 'no-reply@example.test')");
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
    $dueNow = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL AND next_try_at <= UTC_TIMESTAMP() AND attempts < max_attempts')->fetchColumn();
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
    $check($held($survey[0]) && $dueNow() === 0, 'the email is held: the dispatcher would not send it yet');
    $check($json['toast'] === 'Resolved. Dana gets a short survey in a minute.', 'the toast says who is asked and when');

    // Undo inside the window: the status goes back and the survey never leaves.
    [$status, $json] = $act(102, ['id' => 7001, 'field' => 'status', 'value' => 'in_progress']);
    $check($status === 200 && $ticket(7001)['status'] === 'in_progress' && $ticket(7001)['resolved_at'] === null, 'Undo puts the status back');
    $check($surveys(7001) === 0 && $mail(7001) === [] && $dueNow() === 0, 'and withdraws the survey: nothing is left to send');
    $check($withdrawn(7001) === ['Cancelled: ticket reopened before its survey was sent'], 'the email is cancelled in place, with the reason, not removed');
    $check($json['toast'] === 'Reopened as In progress. The survey was not sent.', 'and says the survey was not sent');

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
    foreach ([7002 => 'no contact', 7003 => 'a machine address'] as $id => $why) {
        $check(str_starts_with($text($page(102, $id), '//*[@id="composer-hint"]'), 'No customer is attached. This reply is saved on the ticket only.'), "a ticket with $why promises no email");
    }

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
    [$status] = $act(102, ['id' => 7004, 'field' => 'status', 'value' => 'waiting']);
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
    [$status, $json] = $act(102, ['id' => 7008, 'field' => 'status', 'value' => 'in_progress']);
    $check($status === 200 && $ticket(7008)['status'] === 'in_progress' && $mail(7008) === [] && $dueNow() === 0
        && $withdrawn(7008) === ['Cancelled: ticket reopened before its survey was sent'], 'limited account: Undo still stops the survey email from ever leaving');
    $check($json['toast'] === 'Reopened as In progress. The survey was not sent.' && $surveys(7008) === 1, 'the survey row it could not remove stays, unanswered');
    $x = $page(102, 7008);
    $check(str_contains($text($x, '//button[@name="after_status"][@value="resolved"]'), 'Dana gets a short survey.'), 'and the page still promises a survey on the next resolve');
    [$status, $json] = $act(102, ['id' => 7008, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $survey = $mail(7008, '[#7008] Resolved: ');
    $check($status === 200 && $surveys(7008) === 1 && (string)$pdo->query('SELECT token FROM csat WHERE ticket_id = 7008')->fetchColumn() === $token,
        'limited account: resolving again reuses that one survey');
    $check(count($survey) === 1 && $held($survey[0]) && str_contains($survey[0]['body_text'], 'csat.php?t=' . $token . '&s=3')
        && $json['toast'] === 'Resolved. Dana gets a short survey in a minute.', 'and holds one new email carrying it');
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

    // A survey with no record of any email (made before mail was queued) counts as asked.
    $pdo->exec("INSERT INTO csat (ticket_id, token) VALUES (7009, REPEAT('c', 40))");
    [$status, $json] = $act(102, ['id' => 7009, 'field' => 'status', 'value' => 'resolved', 'chosen' => true]);
    $check($status === 200 && $surveys(7009) === 1 && $mail(7009) === [] && $json['toast'] === 'Resolved. Dana was already asked to rate this ticket.',
        'a survey with no email on record is never asked again');

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
