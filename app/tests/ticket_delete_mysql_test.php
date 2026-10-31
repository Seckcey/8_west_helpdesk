<?php
/**
 * Bulk ticket delete against real MySQL (lib/ticket_delete.php, docs/ticket-bulk-delete.md).
 * New random databases only, on an explicitly disposable loopback server; synthetic data only.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';
require_once __DIR__ . '/../lib/ticket_delete.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_TICKET_DELETE_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_TICKET_DELETE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_TICKET_DELETE_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_TICKET_DELETE_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_ticket_delete_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$rootUser = getenv('SAFEHARBOR_TICKET_DELETE_TEST_USER') ?: 'root';
$rootPass = getenv('SAFEHARBOR_TICKET_DELETE_TEST_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];

$checks = 0;
$failures = 0;
function td_check(bool $ok, string $name): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) $failures++;
    echo ($ok ? 'ok ' : 'FAIL ') . $checks . ' - ' . $name . "\n";
}
/** $expect: an exception class, or a MySQL error number, or a message fragment. */
function td_refuse(callable $fn, string|int $expect, string $name): void
{
    try {
        $fn();
    } catch (Throwable $error) {
        $match = is_int($expect)
            ? ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === $expect)
            : ($error instanceof $expect || str_contains($error->getMessage(), $expect));
        td_check($match, $name . ($match ? '' : ' (got ' . $error::class . ': ' . $error->getMessage() . ')'));
        return;
    }
    td_check(false, $name . ' (accepted)');
}

$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $rootUser, $rootPass, $options);
$database = $base . '_' . bin2hex(random_bytes(6));
$quoted = '`' . $database . '`';
$runtimeUsers = [];
$files = sys_get_temp_dir() . '/safeharbor-ticket-delete-' . bin2hex(random_bytes(8));
$connect = static function (string $user, string $pass) use ($host, $port, $database, $options): PDO {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $pass, $options);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
};
$server->exec("CREATE DATABASE $quoted CHARACTER SET utf8mb4");
mkdir($files, 0700);

try {
    $pdo = $connect($rootUser, $rootPass);

    // ---------------------------------------------------------------- schema
    // The AGENTS.md fresh-install recipe, plus mail_queue (migration 001 only).
    foreach (['schema.sql', 'migrations/001_mail_queue.sql', 'migrations/002_svc_intake.sql',
        'migrations/009_support_intake.sql', 'migrations/018_ticket_auto_close_eligibility.sql'] as $file) {
        test_execute_sql_file($pdo, __DIR__ . '/../db/' . $file);
    }
    $shape = static function (PDO $pdo): array {
        $table = (string)$pdo->query('SHOW CREATE TABLE ticket_deletions')->fetch()['Create Table'];
        $triggers = $pdo->query("SELECT trigger_name, action_timing, event_manipulation, action_statement FROM information_schema.triggers
            WHERE trigger_schema = DATABASE() AND event_object_table = 'ticket_deletions' ORDER BY trigger_name")->fetchAll();
        $index = $pdo->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols, MIN(non_unique) AS non_unique
            FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'tickets'
             AND index_name = 'ix_tickets_merged_into'")->fetch();
        return [preg_replace('/ AUTO_INCREMENT=\d+/', '', $table), $triggers, $index];
    };
    $freshShape = $shape($pdo);
    td_check($freshShape[2]['cols'] === 'tenant_id,merged_into_id' && (int)$freshShape[2]['non_unique'] === 1 && count($freshShape[1]) === 2,
        'fresh schema carries ticket_deletions, its two guards and the merge index');

    // Tripwire: every table that points at tickets must be one the delete handles. A new one needs a
    // decision (remove with the ticket, detach, or keep the ticket) before this test passes again.
    $referencing = $pdo->query("SELECT DISTINCT table_name FROM information_schema.key_column_usage
        WHERE table_schema = DATABASE() AND referenced_table_name = 'tickets' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    td_check($referencing === ['attachments', 'csat', 'email_threads', 'mail_queue', 'messages', 'portal_ticket_requesters',
            'portal_westy_drafts', 'ticket_presence', 'time_entries', 'westy_email_drafts', 'westy_mail_conversations', 'westy_workflows'],
        'every foreign key to tickets is handled: ' . implode(',', $referencing));
    td_check(array_diff(array_keys(TICKET_DELETE_GUARDED), $referencing) === [], 'every guarded table really references tickets');

    // ---------------------------------------------------------------- seed (synthetic)
    $pdo->exec("INSERT INTO tenants (id, name, slug, plan) VALUES (1, 'Synthetic One', 'synthetic-one', 'suite'), (2, 'Synthetic Two', 'synthetic-two', 'suite')");
    $pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Fixture Dental'), (12, 1, 'Fixture Realty'), (21, 2, 'Other Tenant Co')");
    $hash = password_hash('synthetic-not-a-login', PASSWORD_DEFAULT);
    $u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, is_active) VALUES (?,?,?,?,?,?,?)');
    foreach ([[101, 1, 'owner', 1], [102, 1, 'admin', 1], [103, 1, 'tech', 1], [104, 1, 'owner', 0], [201, 2, 'owner', 1]] as [$id, $t, $role, $active]) {
        $u->execute([$id, $t, "u{$id}@fixture.invalid", $hash, "Fixture {$id}", $role, $active]);
    }
    $ticket = static function (int $id, int $tenant, int $client, string $channel = 'email', string $status = 'open',
        ?int $mergedInto = null, int $eligible = 0, ?string $externalKey = null) use ($pdo): void {
        $pdo->prepare("INSERT INTO tickets (id, tenant_id, client_id, subject, status, channel, sla_due_at, merged_into_id,
                auto_close_eligible, external_key, resolved_at)
            VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),?,?,?,?)")
            ->execute([$id, $tenant, $client, "Synthetic <b>ticket</b> {$id}", $status, $channel, $mergedInto, $eligible, $externalKey,
                $status === 'resolved' ? gmdate('Y-m-d H:i:s') : null]);
    };
    $message = static function (int $ticketId, string $kind = 'tech', string $author = 'Fixture Tech') use ($pdo): int {
        $pdo->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
            ->execute([$ticketId, $author, $kind, "Synthetic {$kind} body"]);
        return (int)$pdo->lastInsertId();
    };
    $attachment = static function (int $ticketId, ?int $messageId, string $stored) use ($pdo): void {
        $pdo->prepare('INSERT INTO attachments (ticket_id, message_id, filename, mime, size_bytes, stored_name) VALUES (?,?,?,?,?,?)')
            ->execute([$ticketId, $messageId, 'fixture.txt', 'text/plain', 7, $stored]);
    };
    $timeEntry = static function (PDO $pdo, int $ticketId): void {
        $pdo->prepare("INSERT INTO time_entries (ticket_id, user_id, minutes, note, billable, created_at)
            VALUES (?, 103, 25, 'Synthetic time', 1, '2026-10-01 10:00:00')")->execute([$ticketId]);
    };
    $workflow = static function (int $ticketId, int $client) use ($pdo): void {
        $pdo->prepare("INSERT INTO westy_workflows (tenant_id, client_id, customer_id, ticket_id, workflow_key, alert_key, state,
                version, summary, started_at, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, 'working', 1, 'Synthetic workflow', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute([$client, sprintf('00000000-0000-4000-8000-%012d', $ticketId), $ticketId,
                sprintf('11111111-1111-4111-8111-%012d', $ticketId), 'alert:' . $ticketId]);
    };

    // A1–A4: untouched machine alerts, each with its one Milepost line (exercises 018's message trigger).
    foreach ([1001, 1002, 1003, 1004] as $id) {
        $ticket($id, 1, 11, 'alert', 'open', null, 1, 'alert:' . $id);
        $message($id, 'system', 'Milepost');
    }
    // B: everything that goes with a ticket, including mail in three states.
    $ticket(1010, 1, 11);
    $message(1010, 'client', 'Fixture Client');
    $bTech = $message(1010, 'tech');
    $bNote = $message(1010, 'note');
    $bFile = bin2hex(random_bytes(20));
    file_put_contents($files . '/' . $bFile, 'fixture');
    $attachment(1010, $bTech, $bFile);
    $pdo->prepare('INSERT INTO csat (ticket_id, token, score, responded_at) VALUES (1010, ?, 3, UTC_TIMESTAMP())')->execute([bin2hex(random_bytes(20))]);
    $pdo->exec("INSERT INTO email_threads (ticket_id, conversation_id) VALUES (1010, 'synthetic-conversation-1010')");
    $pdo->exec("INSERT INTO mail_queue (to_addr, subject, body_text, ticket_id) VALUES ('client@fixture.invalid', 'Unsent', 'Body', 1010)");
    $pdo->exec("INSERT INTO mail_queue (to_addr, subject, body_text, ticket_id, sent_at) VALUES ('client@fixture.invalid', 'Sent', 'Body', 1010, UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO mail_queue (to_addr, subject, body_text, ticket_id, attempts, max_attempts, last_error)
        VALUES ('client@fixture.invalid', 'Failed', 'Body', 1010, 5, 5, 'SMTP 550 mailbox unavailable')");
    $pdo->exec('INSERT INTO ticket_presence (ticket_id, user_id) VALUES (1010, 101)');
    $pdo->prepare("INSERT INTO westy_reports (tenant_id, fingerprint, external_key, app, kind, ticket_id, system_message_id)
        VALUES (1, 'fp-1010', 'westy:fixture:1010', 'safeharbor', 'fail', 1010, ?)")->execute([$bNote]);
    // C1 -> C2: unprotected merge pair. M1 -> M2 -> M3: a chain that crosses batch boundaries.
    $ticket(1022, 1, 11);
    $ticket(1021, 1, 11, 'email', 'resolved', 1022);
    $attachment(1022, $message(1022), bin2hex(random_bytes(20)));   // no file on disk: a missing file is fine
    $ticket(1033, 1, 12);
    $ticket(1032, 1, 12, 'email', 'resolved', 1033);
    $ticket(1031, 1, 12, 'email', 'resolved', 1032);
    // D: technician time. E: Westy workflow. H: customer-portal requester.
    $ticket(1040, 1, 11);
    $timeEntry($pdo, 1040);
    $ticket(1050, 1, 11, 'alert', 'in_progress');
    $workflow(1050, 11);
    $message(1050, 'tech');
    $ticket(1060, 1, 12, 'portal');
    $pdo->exec("INSERT INTO portal_ticket_requesters (ticket_id, tenant_id, client_id, subject, identity_tenant_slug) VALUES (1060, 1, 12, 't1u7', 'synthetic-one')");
    // J merged into D. L (with time) merged into K.
    $ticket(1070, 1, 11, 'email', 'resolved', 1040);
    $ticket(1080, 1, 11);
    $ticket(1081, 1, 11, 'email', 'resolved', 1080);
    $timeEntry($pdo, 1081);
    // N: its message carries an attachment row filed under D, a kept ticket (a cross-link app code
    // never makes). N's message cannot go while that row points at it.
    $ticket(1090, 1, 12);
    $nMessage = $message(1090, 'client', 'Fixture Client');
    $attachment(1040, $nMessage, bin2hex(random_bytes(20)));
    // F1/F2: attachment files that cannot be removed (a directory; a name att_store never makes).
    $ticket(1101, 1, 11);
    $dirName = bin2hex(random_bytes(20));
    mkdir($files . '/' . $dirName);
    $attachment(1101, $message(1101), $dirName);
    $ticket(1102, 1, 11);
    $attachment(1102, $message(1102), str_repeat('Z', 40));
    // X: another tenant.
    $ticket(2001, 2, 21);
    $message(2001);

    $tables = ['tickets', 'messages', 'attachments', 'csat', 'email_threads', 'mail_queue', 'ticket_presence', 'time_entries',
        'time_entry_events', 'westy_workflows', 'westy_reports', 'portal_ticket_requesters', 'ticket_deletions', 'westy_mail_conversation_revocations'];
    $state = static function (PDO $pdo) use ($tables): array {
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = $pdo->query("CHECKSUM TABLE `$table`")->fetch()['Checksum'] . '/' . $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        }
        return $out;
    };
    $tenantOneIds = static fn(PDO $pdo): array => array_map('intval', $pdo->query('SELECT id FROM tickets WHERE tenant_id = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $page = ['ids' => $tenantOneIds($pdo)];   // "select all" on the queue: every ticket the page showed
    $runKey = bin2hex(random_bytes(16));

    // ---------------------------------------------------------------- pure units
    td_refuse(fn() => ticket_delete_scope(null), TicketDeleteValidationException::class, 'scope: missing is refused');
    td_refuse(fn() => ticket_delete_scope(['ids' => []]), TicketDeleteValidationException::class, 'scope: empty id list is refused');
    td_refuse(fn() => ticket_delete_scope(['ids' => ['5']]), TicketDeleteValidationException::class, 'scope: string ids are refused, not coerced');
    td_refuse(fn() => ticket_delete_scope(['ids' => [1.5]]), TicketDeleteValidationException::class, 'scope: non-integer ids are refused');
    td_refuse(fn() => ticket_delete_scope(['ids' => [0]]), TicketDeleteValidationException::class, 'scope: id zero is refused');
    td_refuse(fn() => ticket_delete_scope(['ids' => range(1, TICKET_DELETE_MAX_IDS + 1)]), TicketDeleteValidationException::class, 'scope: more than 10,000 ids is refused');
    td_refuse(fn() => ticket_delete_scope(['filter' => 'all']), TicketDeleteValidationException::class, 'scope: a live filter is refused; tickets are always named');
    td_refuse(fn() => ticket_delete_scope(['ids' => [1], 'extra' => true]), TicketDeleteValidationException::class, 'scope: unknown keys are refused');
    td_check(ticket_delete_scope(['ids' => [9, 3, 9]]) === ['ids' => [3, 9]], 'scope: ids are de-duplicated and sorted');
    $digest = ticket_delete_scope_digest(['ids' => [3, 9]]);
    td_check($digest === ticket_delete_scope_digest(ticket_delete_scope(['ids' => [9, 3]])) && $digest !== ticket_delete_scope_digest(['ids' => [3, 9, 10]]),
        'digest: the same list in any order matches, a longer list does not');
    $stored = ['tenant' => 1, 'actor' => 101, 'digest' => $digest, 'phrase' => 'DELETE 3', 'at' => 1000];
    td_check(ticket_delete_preview_check($stored, 1, 101, $digest, ' DELETE 3 ', 1100) === null, 'preview check: a matching request passes');
    td_check(ticket_delete_preview_check($stored, 1, 101, $digest, 'DELETE 3', 1000 + TICKET_DELETE_PREVIEW_TTL + 1) === 'preview_expired', 'preview check: older than an hour expires');
    td_check(ticket_delete_preview_check($stored, 1, 102, $digest, 'DELETE 3', 1100) === 'preview_expired', 'preview check: another person cannot use it');
    td_check(ticket_delete_preview_check($stored, 2, 101, $digest, 'DELETE 3', 1100) === 'preview_expired', 'preview check: another tenant cannot use it');
    td_check(ticket_delete_preview_check($stored, 1, 101, ticket_delete_scope_digest(['ids' => [3, 9, 10]]), 'DELETE 3', 1100) === 'preview_expired', 'preview check: a different ticket list cannot use it');
    td_check(ticket_delete_preview_check($stored, 1, 101, $digest, 'delete 3', 1100) === 'confirm_mismatch', 'preview check: the phrase must match exactly');
    td_check(ticket_delete_preview_check(null, 1, 101, $digest, 'DELETE 3', 1100) === 'preview_expired', 'preview check: no preview, no delete');

    // ---------------------------------------------------------------- preview changes nothing
    $before = $state($pdo);
    $preview = ticket_delete_preview($pdo, 1, 101, $page);
    $expectedDeletable = [1001, 1002, 1003, 1004, 1010, 1021, 1022, 1031, 1032, 1033, 1101, 1102];
    $expectedKept = [1040 => 'time', 1050 => 'westy_workflow', 1060 => 'portal_requester', 1070 => 'merge_kept',
        1080 => 'merge_kept', 1081 => 'time', 1090 => 'attachment_link'];
    td_check($preview['total'] === 19 && $preview['deletable'] === 12 && $preview['kept'] === 7 && $preview['not_found'] === 0,
        'preview: 19 selected, 12 deletable, 7 kept (' . json_encode([$preview['total'], $preview['deletable'], $preview['kept']]) . ')');
    td_check($preview['deletable_ids'] === $expectedDeletable, 'preview: lists exactly the deletable ids');
    td_check($preview['kept_by_reason'] == ['time' => 2, 'westy_workflow' => 1, 'portal_requester' => 1, 'merge_kept' => 2, 'attachment_link' => 1],
        'preview: kept by reason ' . json_encode($preview['kept_by_reason']));
    $sampleReasons = [];
    foreach ($preview['kept_sample'] as $row) $sampleReasons[$row['id']] = $row['reason'];
    td_check($sampleReasons === $expectedKept, 'preview: every kept ticket is listed with its reason');
    td_check($preview['effects'] === ['messages' => 10, 'attachments' => 4, 'csat' => 1, 'mail_unsent' => 1, 'mail_sent' => 1,
            'westy_reports' => 1, 'alert_tickets' => 4],
        'preview: effects, with mail that already failed for good not counted as queued ' . json_encode($preview['effects']));
    td_check($preview['permissions'] === ['attachments' => true, 'csat' => true], 'preview: the test account may remove attachments and surveys');
    td_check($state($pdo) === $before, 'preview: no table changed');

    // ---------------------------------------------------------------- who may delete
    foreach ([[103, 'a technician'], [104, 'an inactive owner'], [201, 'another tenant\'s owner']] as [$actor, $who]) {
        td_refuse(fn() => ticket_delete_preview($pdo, 1, $actor, $page), TicketDeleteForbiddenException::class, "preview refused for {$who}");
        td_refuse(fn() => ticket_delete_batch($pdo, 1, $actor, $page, 0, $runKey, $files), TicketDeleteForbiddenException::class, "delete refused for {$who}");
    }
    td_check(ticket_delete_allowed(['role' => 'admin']) && !ticket_delete_allowed(['role' => 'tech']) && !ticket_delete_allowed([]), 'role check: owners and admins only');
    td_check($state($pdo) === $before, 'refusals changed nothing');

    // ---------------------------------------------------------------- named tickets, tenancy, merge halves
    $idsPreview = ticket_delete_preview($pdo, 1, 101, ticket_delete_scope(['ids' => [1001, 2001, 9999]]));
    td_check($idsPreview['total'] === 3 && $idsPreview['not_found'] === 2 && $idsPreview['deletable_ids'] === [1001],
        'ids: another tenant\'s ticket and a missing id both count as not found');
    $foreign = ticket_delete_batch($pdo, 2, 201, ticket_delete_scope(['ids' => [1001, 1010]]), 0, $runKey, $files);
    td_check($foreign['deleted'] === 0 && $state($pdo) === $before, 'ids: another tenant\'s owner cannot touch tenant 1 tickets');
    $half = ticket_delete_preview($pdo, 1, 101, ticket_delete_scope(['ids' => [1021]]));
    td_check($half['deletable'] === 0 && ($half['kept_by_reason']['merge_unselected'] ?? 0) === 1, 'ids: half of a merge pair is kept as merge_unselected');
    td_refuse(function () use ($pdo, $runKey, $files, $page): void {
        $pdo->beginTransaction();
        try { ticket_delete_batch($pdo, 1, 101, $page, 0, $runKey, $files); } finally { $pdo->rollBack(); }
    }, 'transaction_ownership_required', 'a batch refuses to join a caller\'s transaction');
    td_refuse(fn() => ticket_delete_batch($pdo, 1, 101, $page, 0, 'not-a-key', $files), TicketDeleteValidationException::class, 'a malformed run key is refused');

    // ---------------------------------------------------------------- why messages go by literal ids
    $pdo->beginTransaction();
    td_refuse(fn() => $pdo->prepare('DELETE m FROM messages m JOIN tickets t ON t.id = m.ticket_id WHERE t.id = ?')->execute([1001]), 1442,
        'a message delete joined to tickets fails with 1442 (018 trigger), so the batch uses literal ids');
    $pdo->rollBack();
    td_check($state($pdo) === $before, 'the documentation case left nothing behind');

    // ---------------------------------------------------------------- least-privilege runtime accounts
    $allowlist = ['attachments', 'canned_responses', 'clients', 'contacts', 'csat', 'email_threads', 'messages',
        'portal_desktop_bindings', 'portal_desktop_handoffs', 'portal_westy_accounts', 'portal_westy_ai_attempts',
        'portal_westy_budgets', 'portal_westy_drafts', 'portal_westy_reply_feedback', 'portal_westy_tool_runs',
        'portal_westy_turns', 'svc_rate_buckets', 'svc_support_rate', 'tickets'];
    $existing = array_flip($pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN));
    $makeRuntime = static function (array $deleteTables) use ($server, $quoted, $existing, &$runtimeUsers, $connect): PDO {
        $name = 'td_rt_' . bin2hex(random_bytes(5));
        $pass = bin2hex(random_bytes(16));
        $server->exec("CREATE USER '{$name}'@'%' IDENTIFIED BY '{$pass}'");
        $runtimeUsers[] = $name;
        $server->exec("GRANT SELECT, INSERT, UPDATE ON {$quoted}.* TO '{$name}'@'%'");
        foreach ($deleteTables as $table) {
            if (isset($existing[$table])) $server->exec("GRANT DELETE ON {$quoted}.`{$table}` TO '{$name}'@'%'");
        }
        return $connect($name, $pass);
    };
    $runtime = $makeRuntime($allowlist);
    td_refuse(fn() => $runtime->exec('DELETE FROM time_entries WHERE 1 = 0'), 1142, 'the runtime account still cannot touch time entries');
    td_check(ticket_delete_permissions($runtime) === ['attachments' => true, 'csat' => true], 'permission probe: granted account sees both grants');

    $run = static function (PDO $pdo, int $tenant, int $actor, array $scope, string $runKey, string $dir, int $limit, ?callable $between = null): array {
        $after = 0;
        $deleted = 0;
        $kept = [];
        $filesFailed = 0;
        $batches = 0;
        do {
            $r = ticket_delete_batch($pdo, $tenant, $actor, $scope, $after, $runKey, $dir, $limit);
            $deleted += $r['deleted'];
            foreach ($r['kept'] as $k) $kept[$k['id']] = $k['reason'];
            $filesFailed += $r['files_failed'];
            if (!$r['done'] && $r['next_after_id'] <= $after) throw new RuntimeException('the delete made no progress');
            $after = $r['next_after_id'];
            if (++$batches > 500) throw new RuntimeException('runaway delete loop');
            if ($between && !$r['done']) $between($batches);
        } while (!$r['done']);
        ksort($kept);
        return ['deleted' => $deleted, 'kept' => $kept, 'files_failed' => $filesFailed, 'batches' => $batches];
    };

    // ---------------------------------------------------------------- a failing batch changes nothing
    $pdo->exec("CREATE TRIGGER trg_fixture_refuse_one_ticket BEFORE DELETE ON tickets FOR EACH ROW
        BEGIN IF OLD.id = 1003 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic refusal'; END IF; END");
    td_refuse(fn() => ticket_delete_batch($runtime, 1, 101, $page, 0, $runKey, $files, 100), 'Synthetic refusal', 'a batch that fails part-way throws');
    td_check($state($pdo) === $before && is_file($files . '/' . $bFile), 'the failed batch rolled back everything, files included');
    $pdo->exec('DROP TRIGGER trg_fixture_refuse_one_ticket');

    // ---------------------------------------------------------------- while a batch holds its locks
    // Two tickets of their own, numbered below every merge link so that REPEATABLE READ would
    // gap-lock the spot where a new ticket goes; a second connection gives up after one second.
    $ticket(501, 1, 11, 'alert', 'open', null, 1, 'alert:501');
    $message(501, 'system', 'Milepost');
    $ticket(502, 1, 11);
    $message(502, 'client', 'Fixture Client');
    $other = $connect($rootUser, $rootPass);
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $probeKey = bin2hex(random_bytes(16));
    $seen = [];
    $lockProbe = ticket_delete_batch($runtime, 1, 101, ticket_delete_scope(['ids' => [501, 502]]), 0, $probeKey, $files, 100,
        static function (array $doomed) use ($other, $timeEntry, &$seen): void {
            $seen['doomed'] = $doomed;
            try { $timeEntry($other, 501); $seen['time'] = 'inserted'; } catch (PDOException $e) { $seen['time'] = (int)($e->errorInfo[1] ?? 0); }
            try {
                $other->prepare("INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (502, 'Fixture Client', 'client', 'Late reply')")->execute();
                $seen['message'] = 'inserted';
            } catch (PDOException $e) { $seen['message'] = (int)($e->errorInfo[1] ?? 0); }
            $started = microtime(true);
            $other->prepare("INSERT INTO tickets (tenant_id, client_id, subject, channel, sla_due_at) VALUES (1, 11, 'Arrives during the batch', 'alert', UTC_TIMESTAMP())")->execute();
            $seen['new_ticket'] = (int)$other->lastInsertId();
            $seen['new_ticket_wait'] = microtime(true) - $started;
        });
    td_check(($seen['doomed'] ?? null) === [501, 502] && $lockProbe['deleted'] === 2, 'lock probe: the batch deleted the two tickets it locked');
    td_check(($seen['time'] ?? null) === 1205, 'lock probe: time cannot be logged on a ticket being deleted (got ' . json_encode($seen['time'] ?? null) . ')');
    td_check(($seen['message'] ?? null) === 1205, 'lock probe: a reply cannot land on a ticket being deleted (got ' . json_encode($seen['message'] ?? null) . ')');
    td_check(isset($seen['new_ticket']) && $seen['new_ticket_wait'] < 0.5,
        'lock probe: a brand-new ticket is created at once while a batch runs, so no gap locks (' . round($seen['new_ticket_wait'] ?? -1, 3) . 's)');
    $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$seen['new_ticket']]);
    $before = $state($pdo);

    // ---------------------------------------------------------------- the full run
    $y = (int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn() + 100;
    $ticket($y, 1, 11);   // Y arrives after the page loaded: never deleted unseen
    $eventsBefore = (int)$pdo->query('SELECT COUNT(*) FROM time_entry_events')->fetchColumn();
    $revocationsBefore = (int)$pdo->query('SELECT COUNT(*) FROM westy_mail_conversation_revocations')->fetchColumn();
    $workflowBefore = $pdo->query('SELECT state, version FROM westy_workflows WHERE ticket_id = 1050')->fetch();
    $full = $run($runtime, 1, 101, $page, $runKey, $files, 2);
    td_check($full['deleted'] === 12, 'full run: 12 tickets deleted in batches of 2 (' . $full['deleted'] . ' in ' . $full['batches'] . ' batches)');
    td_check($full['kept'] === $expectedKept, 'full run: kept exactly the protected set ' . json_encode($full['kept']));
    td_check($full['files_failed'] === 2, 'full run: the directory and the malformed name are reported, not removed');
    td_check($tenantOneIds($pdo) === [1040, 1050, 1060, 1070, 1080, 1081, 1090, $y], 'full run: only kept tickets and the late arrival remain');
    td_check((int)$pdo->query('SELECT COUNT(*) FROM tickets WHERE tenant_id = 2')->fetchColumn() === 1, 'full run: the other tenant is untouched');
    $orphans = [];
    foreach (['messages', 'attachments', 'csat', 'email_threads', 'ticket_presence'] as $table) {
        $orphans[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table` x LEFT JOIN tickets t ON t.id = x.ticket_id WHERE t.id IS NULL")->fetchColumn();
    }
    td_check(array_sum($orphans) === 0, 'full run: no child row outlived its ticket ' . json_encode($orphans));
    td_check((int)$pdo->query('SELECT COUNT(*) FROM messages WHERE ticket_id = 1010')->fetchColumn() === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM ticket_presence')->fetchColumn() === 0, 'full run: B\'s thread is gone and its presence row cascaded');
    $mail = [];
    foreach ($pdo->query('SELECT subject, ticket_id, attempts, max_attempts, last_error, sent_at FROM mail_queue')->fetchAll() as $row) $mail[$row['subject']] = $row;
    td_check($mail['Unsent']['ticket_id'] === null && (int)$mail['Unsent']['attempts'] === (int)$mail['Unsent']['max_attempts']
        && $mail['Unsent']['last_error'] === 'Cancelled: ticket deleted' && $mail['Unsent']['sent_at'] === null, 'full run: unsent mail is cancelled and detached');
    td_check($mail['Sent']['ticket_id'] === null && $mail['Sent']['sent_at'] !== null && (int)$mail['Sent']['attempts'] === 0, 'full run: sent mail keeps its record and only lets go of the ticket');
    td_check($mail['Failed']['ticket_id'] === null && $mail['Failed']['last_error'] === 'SMTP 550 mailbox unavailable',
        'full run: mail that had already failed for good keeps its real error');
    $due = (int)$pdo->query('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL AND next_try_at <= UTC_TIMESTAMP() AND attempts < max_attempts')->fetchColumn();
    td_check($due === 0, 'full run: mail dispatch\'s own query no longer picks up the cancelled email');
    $report = $pdo->query("SELECT ticket_id, system_message_id FROM westy_reports WHERE external_key = 'westy:fixture:1010'")->fetch();
    td_check($report['ticket_id'] === null && $report['system_message_id'] === null, 'full run: the Westy report lets go of the deleted ticket');
    td_check(!file_exists($files . '/' . $bFile) && is_dir($files . '/' . $dirName), 'full run: B\'s file is removed after commit; the directory is left alone');

    // protection was decided before anything was touched
    td_check((int)$pdo->query('SELECT COUNT(*) FROM messages WHERE ticket_id = 1050')->fetchColumn() === 1
        && $pdo->query('SELECT state, version FROM westy_workflows WHERE ticket_id = 1050')->fetch() == $workflowBefore,
        'protected: the Westy workflow ticket kept its message and its workflow state');
    td_check((int)$pdo->query('SELECT COUNT(*) FROM time_entry_events')->fetchColumn() === $eventsBefore
        && (int)$pdo->query('SELECT COUNT(*) FROM westy_mail_conversation_revocations')->fetchColumn() === $revocationsBefore,
        'protected: no time or mailbox history was written by the delete');
    td_check((int)$pdo->query('SELECT COUNT(*) FROM messages WHERE ticket_id = 1090')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM attachments WHERE ticket_id = 1040')->fetchColumn() === 1,
        'protected: the cross-linked message and the attachment row pointing at it both stayed');

    // the audit trail
    $audit = $pdo->prepare('SELECT ticket_id, run_key, deleted_by_user_id, message_count, attachment_count, csat_score, mail_cancelled, channel, external_key, merged_into_id
        FROM ticket_deletions WHERE run_key = ? ORDER BY ticket_id');
    $audit->execute([$runKey]);
    $audit = $audit->fetchAll();
    td_check(array_map(static fn($r) => (int)$r['ticket_id'], $audit) === $expectedDeletable, 'audit: one record per deleted ticket');
    td_check(count(array_filter($audit, static fn($r) => (int)$r['deleted_by_user_id'] === 101)) === 12, 'audit: every record names the person');
    $b = $audit[4];
    td_check((int)$b['message_count'] === 3 && (int)$b['attachment_count'] === 1 && (int)$b['csat_score'] === 3 && (int)$b['mail_cancelled'] === 1,
        'audit: B\'s record counts its messages, attachment, survey score and the one email it actually cancelled');
    td_check($audit[0]['channel'] === 'alert' && $audit[0]['external_key'] === 'alert:1001' && (int)$audit[5]['merged_into_id'] === 1022,
        'audit: channel, alert key and merge link are kept');
    td_refuse(fn() => $pdo->exec('UPDATE ticket_deletions SET csat_score = 1'), 'immutable', 'audit: records cannot be changed');
    td_refuse(fn() => $pdo->exec('DELETE FROM ticket_deletions'), 'immutable', 'audit: records cannot be removed');

    // idempotent; a freed number is never reused, even at the top of the range
    $again = $run($runtime, 1, 101, $page, bin2hex(random_bytes(16)), $files, 100);
    td_check($again['deleted'] === 0 && $again['kept'] === $expectedKept, 'a second run deletes nothing and keeps the same set');
    $topRun = $run($runtime, 1, 101, ['ids' => [$y]], bin2hex(random_bytes(16)), $files, 100);
    td_check($topRun['deleted'] === 1 && (int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn() < $y, 'the highest ticket number is deleted');
    $pdo->prepare("INSERT INTO tickets (tenant_id, client_id, subject, channel, sla_due_at, external_key) VALUES (1, 11, 'Alert again', 'alert', UTC_TIMESTAMP(), 'alert:1001')")->execute();
    td_check((int)$pdo->lastInsertId() > $y, 'the freed alert key opens a new ticket, numbered above every deleted one');

    // ---------------------------------------------------------------- without the two new grants
    $ticket(3001, 1, 11);
    $attachment(3001, $message(3001), bin2hex(random_bytes(20)));
    $ticket(3002, 1, 11);
    $pdo->prepare('INSERT INTO csat (ticket_id, token) VALUES (3002, ?)')->execute([bin2hex(random_bytes(20))]);
    $ticket(3003, 1, 11);
    $message(3003);
    $narrow = $makeRuntime(array_values(array_diff($allowlist, ['attachments', 'csat'])));
    td_check(ticket_delete_permissions($narrow) === ['attachments' => false, 'csat' => false], 'permission probe: missing grants are detected without an error');
    $narrowScope = ['ids' => [3001, 3002, 3003]];
    $narrowPreview = ticket_delete_preview($narrow, 1, 102, $narrowScope);
    td_check(($narrowPreview['kept_by_reason']['grant_attachments'] ?? 0) === 1 && ($narrowPreview['kept_by_reason']['grant_csat'] ?? 0) === 1
        && $narrowPreview['deletable_ids'] === [3003], 'without the grants the preview keeps those tickets and says why');
    $narrowRun = $run($narrow, 1, 102, $narrowScope, bin2hex(random_bytes(16)), $files, 100);
    td_check(($narrowRun['kept'][3001] ?? '') === 'grant_attachments' && ($narrowRun['kept'][3002] ?? '') === 'grant_csat'
        && $narrowRun['deleted'] === 1, 'without the grants everything else is still deleted, by an admin');

    // ---------------------------------------------------------------- evidence that appears mid-run counts
    foreach ([4001, 4002, 4003, 4004] as $id) { $ticket($id, 1, 12); $message($id); }
    $racing = $run($runtime, 1, 101, ['ids' => [4001, 4002, 4003, 4004]], bin2hex(random_bytes(16)), $files, 2,
        static function (int $batch) use ($timeEntry, $pdo): void { if ($batch === 1) $timeEntry($pdo, 4004); });
    td_check($racing['deleted'] === 3 && ($racing['kept'][4004] ?? '') === 'time', 'time logged between batches protects that ticket');

    // ---------------------------------------------------------------- a merge group bigger than a batch
    $ticket(6000, 1, 12);
    $stubs = [];
    for ($id = 6001; $id <= 6120; $id++) { $ticket($id, 1, 12, 'email', 'resolved', 6000); $stubs[] = $id; }
    $bigRun = $run($runtime, 1, 101, ['ids' => array_merge([6000], $stubs)], bin2hex(random_bytes(16)), $files, 100);
    td_check($bigRun['deleted'] === 121 && $bigRun['kept'] === [], 'a merge group of 121 goes whole even with batches of 100 (' . $bigRun['batches'] . ' batches)');

    // ---------------------------------------------------------------- the migration matches schema.sql
    $pdo->exec('DROP TRIGGER trg_ticket_deletions_no_update');
    $pdo->exec('DROP TRIGGER trg_ticket_deletions_no_delete');
    $pdo->exec('DROP TABLE ticket_deletions');
    $pdo->exec('ALTER TABLE tickets DROP INDEX ix_tickets_merged_into');
    test_execute_sql_file($pdo, __DIR__ . '/../db/migrations/035_ticket_bulk_delete.sql');
    td_check($shape($pdo) == $freshShape, 'migration 035 builds exactly what schema.sql declares');
    test_execute_sql_file($pdo, __DIR__ . '/../db/migrations/035_ticket_bulk_delete.sql');
    td_check($shape($pdo) == $freshShape, 'migration 035 replays without change');
} catch (Throwable $error) {
    td_check(false, 'unexpected ' . $error::class . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
} finally {
    foreach ($runtimeUsers as $name) $server->exec("DROP USER IF EXISTS '{$name}'@'%'");
    $server->exec("DROP DATABASE IF EXISTS $quoted");
    $real = realpath($files);
    $tmp = realpath(sys_get_temp_dir());
    if ($real !== false && $tmp !== false && str_starts_with($real, $tmp . DIRECTORY_SEPARATOR) && str_starts_with(basename($real), 'safeharbor-ticket-delete-')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($real);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "Ticket bulk delete MySQL: {$failures} of {$checks} checks failed.\n");
    exit(1);
}
echo "Ticket bulk delete MySQL: {$checks}/{$checks} passed.\n";
