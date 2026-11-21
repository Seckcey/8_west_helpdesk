<?php
/** Disposable in-memory SQLite, with transport replaced by a recorder. */
declare(strict_types=1);
function db(): PDO { return $GLOBALS['pdo']; }
function cfg(string $key, mixed $default = null): mixed { return $key === 'portal.enabled' ? true : $default; }
function utf8_clean(string $s): string { return mb_scrub($s, 'UTF-8'); }
function csrf_valid(): bool { return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }
require_once __DIR__ . '/../lib/customer_summary.php';

function cs_check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}
function cs_refuses(callable $call, string $reason): void
{
    try { $call(); }
    catch (Throwable $error) {
        cs_check($error->getMessage() === $reason, 'unexpected refusal for ' . $reason);
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $reason);
}

// A one-shot hook models another request winning just after the approval and claim commit.
$pdo = new class('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]) extends PDO {
    public ?Closure $afterCommit = null;
    public function commit(): bool
    {
        $ok = parent::commit();
        $hook = $this->afterCommit;
        $this->afterCommit = null;
        if ($hook !== null) $hook();
        return $ok;
    }
};
$health = 1;
$pdo->sqliteCreateFunction('customer_summary_schema_health', static function () use (&$health): int { return $health; }, 0);
$pdo->sqliteCreateFunction('westy_work_log_schema_health', static fn(): int => 1, 0);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,tenant_id INTEGER,role TEXT,is_active INTEGER,full_name TEXT DEFAULT '');
    CREATE TABLE clients (id INTEGER PRIMARY KEY,tenant_id INTEGER,name TEXT,work_summary_delivery TEXT DEFAULT 'portal');
    CREATE TABLE contacts (id INTEGER PRIMARY KEY,client_id INTEGER,email TEXT);
    CREATE TABLE tickets (id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,contact_id INTEGER,subject TEXT,merged_into_id INTEGER);
    CREATE TABLE westy_workflows (id INTEGER PRIMARY KEY,tenant_id INTEGER,ticket_id INTEGER,client_id INTEGER);
    CREATE TABLE westy_workflow_log_state (tenant_id INTEGER,workflow_id INTEGER,qa_state TEXT,updated_at TEXT);
    CREATE TABLE westy_workflow_v4_receipts (tenant_id INTEGER,workflow_id INTEGER,action TEXT,received_at TEXT);
    CREATE TABLE ticket_work_log (id INTEGER PRIMARY KEY,tenant_id INTEGER,ticket_id INTEGER,workflow_id INTEGER,customer_visible INTEGER,summary TEXT,occurred_at TEXT);
    CREATE TABLE westy_email_drafts (tenant_id INTEGER,ticket_id INTEGER,client_id INTEGER,state TEXT);
    CREATE TABLE customer_portal_bindings (tenant_id INTEGER,client_id INTEGER,status TEXT);
    CREATE TABLE ticket_customer_summaries (id INTEGER PRIMARY KEY,tenant_id INTEGER,ticket_id INTEGER,client_id INTEGER,body TEXT,
      state TEXT DEFAULT 'draft',source_sha256 TEXT,last_log_id INTEGER,approved_by INTEGER,approved_at TEXT,emailed_at TEXT,email_result TEXT,created_at TEXT,
      approved_ticket_id INTEGER GENERATED ALWAYS AS (CASE WHEN state='approved' THEN ticket_id END),
      emailed_ticket_id INTEGER GENERATED ALWAYS AS (CASE WHEN emailed_at IS NOT NULL THEN ticket_id END),
      UNIQUE(tenant_id,approved_ticket_id),UNIQUE(tenant_id,emailed_ticket_id));
    INSERT INTO users (id,tenant_id,role,is_active) VALUES (1,1,'tech',1),(2,1,'admin',1),(3,2,'admin',1),(4,1,'viewer',1),(5,1,'tech',0);
    UPDATE users SET full_name='Casey Example' WHERE tenant_id=1 AND id=1;
    INSERT INTO clients VALUES (10,1,'Harbor','portal'),(11,1,'Bay','email'),(20,2,'North','portal');
    INSERT INTO contacts VALUES (1,10,'reader@example.invalid'),(2,11,'wrong@example.invalid');
    INSERT INTO tickets VALUES (101,1,10,1,'Printer',NULL),(102,1,11,2,'Other client',NULL),(201,2,20,NULL,'Other tenant',NULL);
    INSERT INTO westy_workflows VALUES (91,1,101,10),(92,1,102,11),(93,2,201,20);
    INSERT INTO westy_workflow_log_state VALUES (1,91,'passed','2026-11-05 09:00:00'),(1,92,'fault_zero','2026-11-05 09:00:00'),(2,93,'passed','2026-11-05 09:00:00');
    INSERT INTO westy_workflow_v4_receipts VALUES (1,91,'qa','2026-11-05 09:00:00'),(1,92,'qa','2026-11-05 09:00:00'),(2,93,'qa','2026-11-05 09:00:00');
    INSERT INTO ticket_work_log VALUES (1,1,101,91,1,'Restarted the print service','2026-11-02 04:00:00'),
      (2,1,101,91,0,'Private observation','2026-11-02 04:01:00'),(3,1,101,91,1,'A test page printed.','2026-11-02 04:02:00'),
      (4,2,201,93,1,'Other customer work','2026-11-02 04:00:00');");

$view = static fn(): array => customer_summary_staff_view($pdo, 1, 101);
$form = static function () use ($view): array {
    $v = $view();
    return ['customer_summary_action'=>'approve','review_id'=>(string)($v['latest']['id'] ?? 0),
        'source_sha256'=>$v['draft']['source_sha256'],'summary_body'=>$v['draft']['body']];
};
$mail = [];
$accepted = true;
$transport = static function (string $to, string $subject, string $body) use (&$mail,&$accepted): array {
    $mail[] = [$to,$subject,$body];
    return ['outcome'=>$accepted ? 'provider_accepted' : 'uncertain'];
};
$approve = static fn(array $input, int $actor=1): string => customer_summary_review($pdo,1,$actor,101,$input,$transport);
$revoke = static fn(int $actor=1): string => customer_summary_review($pdo,1,$actor,101,
    ['customer_summary_action'=>'revoke','review_id'=>(string)customer_summary_latest($pdo,1,101)['id']],$transport);
$portal = static fn(int $tenant=1,int $client=10): array => customer_summary_portal_work($pdo,$tenant,101,$client);
$hidden = ['customer_summary'=>null,'work_log'=>[]];
$input = $form();
cs_check($input['summary_body'] === 'Restarted the print service. A test page printed. We checked again on November 5, 2026 and the fix held.', 'ordered public text and date');
$rows = [['customer_visible'=>1,'summary'=>'Restored printing']];
foreach (['void','fault_zero',null] as $qa) cs_check(customer_summary_compose($rows,$qa,'2026-11-05 09:00:00') === null, 'non-passing QA');
foreach (['','not an address','postmaster@example.invalid','no-reply@example.invalid'] as $address) {
    cs_check(customer_summary_recipient(['email'=>$address]) === null, 'invalid or automated contact');
}
cs_check(customer_summary_compose([], 'passed','2026-11-05 09:00:00') === null
    && customer_summary_compose([['customer_visible'=>0,'summary'=>'Private']], 'passed','2026-11-05 09:00:00') === null
    && customer_summary_compose($rows,'passed','2026-02-30 09:00:00') === null, 'no public work or valid date');
$long = array_fill(0,8,['customer_visible'=>1,'summary'=>str_repeat('é ',450)]);
cs_check(mb_strlen(customer_summary_compose($long,'passed','2026-11-05 09:00:00')) <= 2000, 'bounded Unicode draft');
cs_check(array_column(customer_summary_waiting($pdo,1),'id') === [101] && $portal() === $hidden, 'scoped waiting list and no automatic release');

$_SESSION['csrf'] = 'synthetic-form-marker';
$_POST = [];
cs_refuses(fn() => $approve($input),'csrf_required');
$_POST['csrf'] = $_SESSION['csrf'];
foreach ([3,4,5] as $actor) cs_refuses(fn() => $approve($input,$actor),'unauthorized');
cs_refuses(fn() => $approve(array_replace($input,['source_sha256'=>str_repeat('0',64)])),'review_changed');
cs_refuses(fn() => $approve(array_replace($input,['summary_body'=>'Casey Example completed the repair.'])),'staff_name_in_review');
foreach (['https://example.invalid/private','reader@example.invalid','192.0.2.8','ai-staff:support','hostname: PC-2','QA: passed',str_repeat('a',2001)] as $unsafe) {
    cs_refuses(fn() => $approve(array_replace($input,['summary_body'=>$unsafe])),'invalid_body');
}
cs_check(customer_summary_latest($pdo,1,101) === null && !$mail, 'refusals write and send nothing');
cs_check(customer_summary_contains_staff_name($pdo,1,['Casey Example checked printing.'],'Printing works.')
    && !customer_summary_contains_staff_name($pdo,2,['Casey Example checked printing.'],'Printing works.'),
    'staff-name check covers the original public steps and only the ticket tenant');
$edited = 'The printer works again <after the repair>.';
$approve(array_replace($input,['summary_body'=>$edited]));
cs_check($portal()['customer_summary'] === $edited && count($portal()['work_log']) === 2 && !$mail, 'portal approval never emails');
cs_check(array_keys($portal()['work_log'][0]) === ['occurred_at','summary']
    && $portal(2,10) === $hidden && $portal(1,11) === $hidden
    && customer_summary_waiting($pdo,1) === [], 'no metadata, cross-tenant or cross-client read');
$pdo->exec("INSERT INTO ticket_work_log VALUES (5,1,101,91,1,'New step','2026-11-02 04:03:00')");
cs_check(count($portal()['work_log']) === 2, 'approval only releases reviewed steps');
$pdo->exec("UPDATE westy_workflow_log_state SET qa_state='void' WHERE tenant_id=1 AND workflow_id=91");
cs_check($portal() === $hidden, 'late void withdraws new release');
$pdo->exec("UPDATE westy_workflow_log_state SET qa_state='passed' WHERE tenant_id=1 AND workflow_id=91");
$_POST = [];
cs_refuses(fn() => $revoke(),'csrf_required');
$_POST['csrf'] = $_SESSION['csrf'];
foreach ([3,4,5] as $actor) cs_refuses(fn() => $revoke($actor),'unauthorized');
$revoke(2);
cs_check($portal() === $hidden, 'revocation hides approval and work steps');
cs_refuses(fn() => $approve($input),'review_changed');
$pdo->exec("INSERT INTO westy_email_drafts VALUES (1,101,10,'submitted')");
cs_check($portal()['customer_summary'] === null && count($portal()['work_log']) === 3, 'legacy gate after revoke');
cs_refuses(fn() => customer_summary_change_delivery($pdo,1,1,10,'none'),'unauthorized');
cs_refuses(fn() => customer_summary_change_delivery($pdo,2,3,10,'none'),'unauthorized');
cs_refuses(fn() => customer_summary_change_delivery($pdo,1,2,10,'invalid'),'invalid_delivery');
customer_summary_change_delivery($pdo,1,2,10,'none');
$approve($form());
cs_check($portal() === $hidden && !$mail, 'none hides legacy rows and approved summary and sends nothing');
$revoke();
customer_summary_change_delivery($pdo,1,2,10,'email');
$pdo->exec('UPDATE tickets SET contact_id=2 WHERE tenant_id=1 AND id=101');
$notice = $approve($form());
cs_check(!$mail && str_contains($notice,'no contact'), 'contact from another client cannot receive the email, reason shown');
$revoke();
$pdo->exec('UPDATE tickets SET contact_id=1 WHERE tenant_id=1 AND id=101');
$attempt = $form();
$approve($attempt);
cs_refuses(fn() => $approve($attempt),'review_changed');
$revoke();
$approve($form());
cs_check(count($mail) === 1 && $mail[0] === ['reader@example.invalid','Summary of work on ticket #101',$attempt['summary_body']],
    'one message for the exact contact, fixed subject, optional link absent, no resend after revocation');
$pdo->exec("UPDATE westy_workflow_log_state SET qa_state='passed' WHERE tenant_id=1 AND workflow_id=92");
cs_check(customer_summary_waiting($pdo,1) === [], 'passing QA with no public steps is excluded');
$pdo->exec("INSERT INTO ticket_work_log VALUES (6,1,102,92,1,'Verified the connection','2026-11-02 04:00:00');
    INSERT INTO customer_portal_bindings VALUES (1,11,'active')");
$second = customer_summary_staff_view($pdo,1,102)['draft'];
$otherInput = ['customer_summary_action'=>'approve','review_id'=>'0','source_sha256'=>$second['source_sha256'],'summary_body'=>$second['body']];
$accepted = false;
$notice = customer_summary_review($pdo,1,2,102,$otherInput,$transport);
cs_check(count($mail) === 2 && $mail[1][0] === 'wrong@example.invalid'
    && str_contains($mail[1][2], CUSTOMER_MAIL_SITE . '/portal/ticket.php?id=102#work-log')
    && customer_summary_latest($pdo,1,102)['email_result'] === 'unconfirmed'
    && str_contains($notice,'unconfirmed'), 'optional exact link and durable uncertain transport outcome');
cs_refuses(fn() => customer_summary_review($pdo,1,2,102,$otherInput,$transport),'review_changed');
foreach (['revoke','opt_out','change_contact','void'] as $index=>$interruption) {
    $ticketId = 103 + $index;
    $pdo->exec("UPDATE clients SET work_summary_delivery='email' WHERE tenant_id=1 AND id=10");
    $pdo->prepare("INSERT INTO tickets VALUES (?,1,10,1,'Review boundary',NULL)")->execute([$ticketId]);
    $pdo->prepare('INSERT INTO westy_workflows VALUES (?,1,?,10)')->execute([$ticketId,$ticketId]);
    $pdo->prepare("INSERT INTO westy_workflow_log_state VALUES (1,?,'passed','2026-11-05 09:00:00')")->execute([$ticketId]);
    $pdo->prepare("INSERT INTO westy_workflow_v4_receipts VALUES (1,?,'qa','2026-11-05 09:00:00')")->execute([$ticketId]);
    $pdo->prepare("INSERT INTO ticket_work_log VALUES (?,1,?,?,1,'Verified recovery','2026-11-05 09:00:00')")->execute([$ticketId,$ticketId,$ticketId]);
    $draft = customer_summary_staff_view($pdo,1,$ticketId)['draft'];
    $pdo->afterCommit = static function () use ($pdo,$ticketId,$interruption,$transport): void {
        $saved = customer_summary_latest($pdo,1,$ticketId);
        cs_check(!$pdo->inTransaction() && $saved['state'] === 'approved' && $saved['email_result'] === 'claimed', 'claim committed before send');
        if ($interruption === 'revoke') {
            customer_summary_review($pdo,1,2,$ticketId,['customer_summary_action'=>'revoke','review_id'=>(string)$saved['id']],$transport);
        } elseif ($interruption === 'opt_out') {
            customer_summary_change_delivery($pdo,1,2,10,'none');
        } elseif ($interruption === 'change_contact') {
            $pdo->prepare('UPDATE tickets SET contact_id=2 WHERE tenant_id=1 AND id=?')->execute([$ticketId]);
        } else {
            $pdo->prepare("UPDATE westy_workflow_log_state SET qa_state='void' WHERE tenant_id=1 AND workflow_id=?")->execute([$ticketId]);
        }
    };
    customer_summary_review($pdo,1,2,$ticketId,['customer_summary_action'=>'approve','review_id'=>'0',
        'source_sha256'=>$draft['source_sha256'],'summary_body'=>$draft['body']],$transport);
    cs_check(count($mail) === 2 && customer_summary_latest($pdo,1,$ticketId)['email_result'] === 'unconfirmed',
        'post-commit ' . $interruption . ' prevents transport');
}
$health = 0;
cs_refuses(fn() => $revoke(),'summary_unavailable');
cs_check($portal()['customer_summary'] === null && count($portal()['work_log']) === 3 && count($mail) === 2,
    'failed readiness releases no summary or email, prior submitted advice still qualifies');
$pdo->exec("DELETE FROM westy_email_drafts WHERE tenant_id=1 AND ticket_id=101");
cs_check(westy_work_log_rows($pdo,1,101,10) === [], 'with no earlier submitted advice, missing readiness hides all steps');
echo "Customer summary review and delivery checks passed.\n";
