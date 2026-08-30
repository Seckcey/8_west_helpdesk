<?php
/** Hermetic customer create/read/reply workflow and tenant-isolation tests. */
declare(strict_types=1);

function portal_csrf_token(): string
{
    return str_repeat('c', 64);
}

function portal_action_nonce(string $purpose, ?int $now = null): string
{
    return hash('sha256', $purpose . ':' . ($now ?? 1));
}

require_once __DIR__ . '/../lib/portal_data.php';
require_once __DIR__ . '/../lib/portal_render.php';

$checks = 0;
$failures = 0;

function portal_workflow_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$name}\n";
}

function portal_workflow_expect(string $name, string $class, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        portal_workflow_check($name, $error instanceof $class);
        return;
    }
    portal_workflow_check($name, false);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys=ON');
$schema = [
    'CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
    'CREATE TABLE clients (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL,
        sla_tier TEXT NOT NULL DEFAULT "standard", UNIQUE(tenant_id,id))',
    'CREATE TABLE service_goal_policy_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
        policy_key TEXT NOT NULL, version_no INTEGER NOT NULL, display_name TEXT NOT NULL,
        effective_from TEXT NOT NULL, clock_mode TEXT NOT NULL, time_zone TEXT NOT NULL,
        pause_mode TEXT NOT NULL, created_by_user_id INTEGER NULL, reason TEXT NULL,
        UNIQUE(tenant_id,policy_key,version_no), UNIQUE(tenant_id,id))',
    'CREATE TABLE service_goal_policy_targets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
        policy_version_id INTEGER NOT NULL, priority TEXT NOT NULL,
        first_response_minutes INTEGER NOT NULL, resolution_minutes INTEGER NULL,
        UNIQUE(tenant_id,policy_version_id,priority), UNIQUE(tenant_id,id))',
    'CREATE TABLE tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
        contact_id INTEGER NULL, subject TEXT NOT NULL, status TEXT NOT NULL DEFAULT "open",
        priority TEXT NOT NULL DEFAULT "normal", assignee_id INTEGER NULL, channel TEXT NOT NULL,
        sla_due_at TEXT NOT NULL, service_goal_target_id INTEGER NULL, resurface_at TEXT NULL,
        merged_into_id INTEGER NULL, auto_close_eligible INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL, resolved_at TEXT NULL)',
    'CREATE TABLE messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL,
        author_name TEXT NOT NULL, kind TEXT NOT NULL, body TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
];
foreach ($schema as $statement) $pdo->exec($statement);
$pdo->exec("INSERT INTO tenants (id,name) VALUES (1,'Provider One'),(2,'Provider Two')");
$pdo->exec("INSERT INTO clients (id,tenant_id,name,sla_tier) VALUES
    (11,1,'Acme Company','standard'),
    (12,1,'Beta Company','premium'),
    (21,2,'Other Provider','standard')");

portal_workflow_expect('blank subject is refused', PortalDataValidationException::class,
    fn() => portal_ticket_subject('   '));
portal_workflow_expect('control characters in a customer body are refused', PortalDataValidationException::class,
    fn() => portal_ticket_body("hello\x00world"));
portal_workflow_expect('unknown priority is refused rather than normalized', PortalDataValidationException::class,
    fn() => portal_ticket_priority('emergency'));
$longAuthor = portal_ticket_author(str_repeat('A', 190));
portal_workflow_check('admitted long display name becomes a deterministic database-safe author label',
    portal_text_length($longAuthor) === 128
    && $longAuthor === str_repeat('A', 127) . '…');
portal_workflow_expect('author label still refuses a name beyond the OIDC admission bound',
    PortalDataValidationException::class,
    fn() => portal_ticket_author(str_repeat('A', 191)));
portal_workflow_expect('viewer cannot bypass the controller to open a ticket', PortalDataForbiddenException::class,
    fn() => portal_create_ticket(
        $pdo, 1, 11, 'client_viewer', 'Viewer', 'Should not open', 'normal', 'Should not be written.'
    ));

$ticketId = portal_create_ticket(
    $pdo, 1, 11, 'client_staff', 'Customer One', 'Printer is offline', 'high', "Front desk cannot print.\r\nPlease help."
);
$ticket = $pdo->query("SELECT * FROM tickets WHERE id={$ticketId}")->fetch();
portal_workflow_check('customer request creates one open portal ticket inside the exact boundary',
    is_array($ticket)
    && (int)$ticket['tenant_id'] === 1
    && (int)$ticket['client_id'] === 11
    && $ticket['status'] === 'open'
    && $ticket['channel'] === 'portal'
    && $ticket['contact_id'] === null
    && $ticket['assignee_id'] === null
    && (int)$ticket['auto_close_eligible'] === 0);
portal_workflow_check('new customer ticket freezes a service-goal target and deadline',
    (int)$ticket['service_goal_target_id'] > 0
    && strtotime((string)$ticket['sla_due_at'] . ' UTC') > strtotime((string)$ticket['created_at'] . ' UTC'));
$initial = $pdo->query("SELECT * FROM messages WHERE ticket_id={$ticketId}")->fetchAll();
portal_workflow_check('new request stores exactly one customer message with normalized newlines',
    count($initial) === 1
    && $initial[0]['kind'] === 'client'
    && $initial[0]['author_name'] === 'Customer One'
    && $initial[0]['body'] === "Front desk cannot print.\nPlease help.");

$pdo->exec("INSERT INTO messages (ticket_id,author_name,kind,body,created_at) VALUES
    ({$ticketId},'Private Technician','note','INTERNAL SECRET','2026-08-29 10:01:00'),
    ({$ticketId},'Milepost','system','AUTOMATION SECRET','2026-08-29 10:02:00'),
    ({$ticketId},'Support Tech','tech','I am checking the printer.','2026-08-29 10:03:00')");
$detail = portal_ticket_detail($pdo, 1, 11, $ticketId);
portal_workflow_check('ticket detail includes only customer and technician conversation',
    array_column($detail['messages'], 'kind') === ['client', 'tech']
    && ! str_contains(json_encode($detail, JSON_THROW_ON_ERROR), 'INTERNAL SECRET')
    && ! str_contains(json_encode($detail, JSON_THROW_ON_ERROR), 'AUTOMATION SECRET'));
portal_workflow_expect('same-provider different customer cannot read the ticket', PortalDataNotFoundException::class,
    fn() => portal_ticket_detail($pdo, 1, 12, $ticketId));
portal_workflow_expect('different provider cannot read the ticket', PortalDataNotFoundException::class,
    fn() => portal_ticket_detail($pdo, 2, 21, $ticketId));

// Model a historical unsafe staff merge: a different customer's source stub
// points at this customer's survivor and its customer message was moved there.
// Until a release audit/reset proves history clean, the portal must hide the
// whole survivor rather than guess which message belongs to whom.
$pdo->exec("INSERT INTO tickets
    (tenant_id,client_id,subject,status,priority,channel,sla_due_at,merged_into_id,created_at,updated_at)
    VALUES (1,11,'Historical merge target','open','normal','email','2026-08-30 00:00:00',NULL,
            '2026-08-29 09:00:00','2026-08-29 09:00:00')");
$mergedTargetId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO tickets
    (tenant_id,client_id,subject,status,priority,channel,sla_due_at,merged_into_id,created_at,updated_at,resolved_at)
    VALUES (1,12,'Historical foreign source','resolved','normal','email','2026-08-30 00:00:00',{$mergedTargetId},
            '2026-08-29 08:00:00','2026-08-29 09:00:00','2026-08-29 09:00:00')");
$pdo->exec("INSERT INTO messages (ticket_id,author_name,kind,body,created_at)
    VALUES ({$mergedTargetId},'Other Customer','client','CROSS CUSTOMER SECRET','2026-08-29 08:30:00')");
portal_workflow_expect('merged survivor is hidden instead of leaking historical customer messages',
    PortalDataNotFoundException::class,
    fn() => portal_ticket_detail($pdo, 1, 11, $mergedTargetId));
$summaryAfterHistoricalMerge = portal_ticket_summary($pdo, 1, 11, 50);
portal_workflow_check('merged survivor is absent from the customer dashboard',
    !in_array($mergedTargetId, array_map(
        static fn(array $row): int => (int)$row['id'],
        $summaryAfterHistoricalMerge['tickets'],
    ), true));
portal_workflow_expect('merged survivor also refuses a customer reply', PortalDataNotFoundException::class,
    fn() => portal_reply_to_ticket(
        $pdo, 1, 11, $mergedTargetId, 'client_staff', 'Customer One', 'Must not cross the merge boundary.'
    ));

$mergeSource = file_get_contents(__DIR__ . '/../public/api/ticket_merge.php');
$lockedReadAt = is_string($mergeSource) ? strpos($mergeSource, 'SELECT id, subject, client_id, merged_into_id') : false;
$firstMoveAt = is_string($mergeSource) ? strpos($mergeSource, 'UPDATE messages') : false;
portal_workflow_check('staff merge locks both ticket/customer facts before moving conversation',
    $lockedReadAt !== false
    && $firstMoveAt !== false
    && $lockedReadAt < $firstMoveAt
    && str_contains((string)$mergeSource, 'FOR UPDATE'));
portal_workflow_check('staff merge explicitly refuses cross-customer tickets',
    is_string($mergeSource)
    && str_contains($mergeSource, "(int)\$rows[\$src]['client_id'] !== (int)\$rows[\$dst]['client_id']")
    && str_contains($mergeSource, 'Tickets may only be merged within the same customer.'));

$portalDataSource = file_get_contents(__DIR__ . '/../lib/portal_data.php');
portal_workflow_check('reply touch preserves a newer timestamp written by a database trigger',
    is_string($portalDataSource)
    && str_contains($portalDataSource, 'WHEN updated_at > :updated_at_floor THEN updated_at')
    && str_contains($portalDataSource, 'ELSE :updated_at_value')
    && str_contains($portalDataSource, 't.updated_at >= :updated_at_floor'));

$pdo->exec("UPDATE tickets SET status='waiting' WHERE id={$ticketId}");
$replyId = portal_reply_to_ticket($pdo, 1, 11, $ticketId, 'client_staff', 'Customer One', 'The printer is powered on now.');
$afterReply = $pdo->query("SELECT status,auto_close_eligible FROM tickets WHERE id={$ticketId}")->fetch();
$reply = $pdo->query("SELECT * FROM messages WHERE id={$replyId}")->fetch();
portal_workflow_check('customer reply returns a waiting ticket to the human queue',
    is_array($afterReply) && $afterReply['status'] === 'open');
portal_workflow_check('customer reply is a client message and cannot gain auto-close ownership',
    is_array($reply)
    && $reply['kind'] === 'client'
    && $reply['body'] === 'The printer is powered on now.'
    && (int)$afterReply['auto_close_eligible'] === 0);
$pdo->prepare('UPDATE tickets SET updated_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $ticketId]);
$sameSecondBefore = (string)$pdo->query("SELECT updated_at FROM tickets WHERE id={$ticketId}")->fetchColumn();
$sameSecondReplyId = portal_reply_to_ticket(
    $pdo, 1, 11, $ticketId, 'client_staff', 'Customer One', 'Immediate same-second follow-up.'
);
$sameSecondAfter = (string)$pdo->query("SELECT updated_at FROM tickets WHERE id={$ticketId}")->fetchColumn();
portal_workflow_check('same-second open-ticket reply saves after proving the locked postcondition',
    strtotime($sameSecondAfter . ' UTC') >= strtotime($sameSecondBefore . ' UTC')
    && (string)$pdo->query("SELECT body FROM messages WHERE id={$sameSecondReplyId}")->fetchColumn()
        === 'Immediate same-second follow-up.');
$futureActivity = gmdate('Y-m-d H:i:s', time() + 300);
$pdo->prepare('UPDATE tickets SET updated_at = ? WHERE id = ?')->execute([$futureActivity, $ticketId]);
$skewReplyId = portal_reply_to_ticket(
    $pdo, 1, 11, $ticketId, 'client_staff', 'Customer One', 'Clock-skew-safe follow-up.'
);
$afterSkewReply = (string)$pdo->query("SELECT updated_at FROM tickets WHERE id={$ticketId}")->fetchColumn();
portal_workflow_check('customer reply never moves a later activity timestamp backward',
    strtotime($afterSkewReply . ' UTC') >= strtotime($futureActivity . ' UTC')
    && (string)$pdo->query("SELECT body FROM messages WHERE id={$skewReplyId}")->fetchColumn()
        === 'Clock-skew-safe follow-up.');
portal_workflow_expect('different customer cannot reply to the ticket', PortalDataNotFoundException::class,
    fn() => portal_reply_to_ticket($pdo, 1, 12, $ticketId, 'client_staff', 'Beta User', 'Cross-customer reply'));
portal_workflow_expect('viewer cannot bypass the controller to reply', PortalDataForbiddenException::class,
    fn() => portal_reply_to_ticket(
        $pdo, 1, 11, $ticketId, 'client_viewer', 'Viewer', 'Should not be written.'
    ));

$pdo->exec("UPDATE tickets SET status='resolved',resolved_at='2026-08-29 11:00:00' WHERE id={$ticketId}");
$beforeResolvedReply = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE ticket_id={$ticketId}")->fetchColumn();
portal_workflow_expect('resolved ticket cannot receive a portal reply', PortalDataConflictException::class,
    fn() => portal_reply_to_ticket($pdo, 1, 11, $ticketId, 'client_staff', 'Customer One', 'One more thing'));
portal_workflow_check('refused resolved reply writes no message',
    (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE ticket_id={$ticketId}")->fetchColumn()
        === $beforeResolvedReply);

$context = [
    'identity' => ['display_name' => 'Customer One', 'role' => 'client_staff'],
    'binding' => ['client_name' => 'Acme Company'],
];
$detail = portal_ticket_detail($pdo, 1, 11, $ticketId);
ob_start();
portal_render_ticket($context, $detail);
$writerHtml = (string)ob_get_clean();
portal_workflow_check('writer sees customer conversation and no private evidence',
    str_contains($writerHtml, 'I am checking the printer.')
    && ! str_contains($writerHtml, 'INTERNAL SECRET')
    && ! str_contains($writerHtml, 'AUTOMATION SECRET'));
portal_workflow_check('resolved ticket offers a new request but no reply mutation form',
    str_contains($writerHtml, 'href="/portal/new.php"')
    && ! str_contains($writerHtml, 'Reply to the support team'));
$context['identity']['role'] = 'client_viewer';
$pdo->exec("UPDATE tickets SET status='open',resolved_at=NULL WHERE id={$ticketId}");
$detail = portal_ticket_detail($pdo, 1, 11, $ticketId);
ob_start();
portal_render_ticket($context, $detail);
$viewerHtml = (string)ob_get_clean();
portal_workflow_check('viewer can read but receives no create or reply controls',
    str_contains($viewerHtml, 'I am checking the printer.')
    && ! str_contains($viewerHtml, 'href="/portal/new.php"')
    && ! str_contains($viewerHtml, '<form method="post" action="/portal/ticket.php'));

echo "Portal ticket workflow: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
