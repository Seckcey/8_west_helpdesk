<?php
/** Human-reviewed customer work derived from the ticket's public v4 steps. */
declare(strict_types=1);
require_once __DIR__ . '/westy_work_log.php';
require_once __DIR__ . '/customer_mail.php';
require_once __DIR__ . '/ticket_changes.php';

const CUSTOMER_SUMMARY_LIMIT = 2000;

function customer_summary_ready(PDO $pdo): void
{
    westy_work_log_schema_ready($pdo);
    $query = $pdo->prepare('SELECT customer_summary_schema_health()');
    $query->execute();
    if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('summary_unavailable');
}

/** The receiver owns the unsafe-text rules. Internal identifiers also have no place in reviewed prose. */
function customer_summary_public_text(string $text): bool
{
    return !westy_work_log_summary_unsafe($text)
        && preg_match('/\b(?:host(?:name)?|device[_ -]?(?:id|name))\s*[:=]\s*\S/i', $text) === 0
        && preg_match('/\b(?:ai-staff:|milepost-user:|safeharbor-user:|system:milepost\b|milepost-customer:|alert:\d|fault_zero\b|qa\s*[:=]\s*(?:passed|void)\b|[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\b)/i', $text) === 0;
}

function customer_summary_valid(mixed $body): bool
{
    return is_string($body) && mb_check_encoding($body, 'UTF-8') && trim($body) !== ''
        && mb_strlen($body, 'UTF-8') <= CUSTOMER_SUMMARY_LIMIT
        && preg_match('/(?!\n)\p{Cc}/u', $body) === 0 && customer_summary_public_text($body);
}

/** Input is in timeline order. Keep whole steps and reserve room for the check date. */
function customer_summary_compose(array $rows, ?string $qaState, ?string $checkedAt): ?string
{
    if ($qaState !== 'passed' || $checkedAt === null) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $checkedAt, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d H:i:s') !== $checkedAt) return null;
    $closing = 'We checked again on ' . $date->format('F j, Y') . ' and the fix held.';
    $sentences = [];
    $length = mb_strlen($closing, 'UTF-8');
    foreach ($rows as $row) {
        if (!in_array($row['customer_visible'] ?? null, [true, 1, '1'], true)) continue;
        $step = $row['summary'] ?? null;
        if (!westy_work_log_summary_valid($step) || !customer_summary_public_text($step)) return null;
        $step = trim($step);
        if (!preg_match('/[.!?…]$/u', $step)) $step .= '.';
        $length += 1 + mb_strlen($step, 'UTF-8');
        if ($length <= CUSTOMER_SUMMARY_LIMIT) $sentences[] = $step;
    }
    if (!$sentences) return null;
    $sentences[] = $closing;
    return implode(' ', $sentences);
}

/** Call after portal ticket authorization. Read both portions under one visibility decision. */
function customer_summary_portal_work(PDO $pdo, int $tenantId, int $ticketId, int $clientId): array
{
    try {
        return customer_summary_reviewed_work($pdo, $tenantId, $ticketId, $clientId);
    } catch (Throwable $error) {
        error_log('[safeharbor-portal] customer_summary_unavailable type=' . $error::class);
    }

    // A submitted advice draft has its own prior human review. Preserve it on a 039-only
    // installation, or if the new review table is unavailable. An installed opt-out still wins.
    westy_work_log_schema_ready($pdo);
    $columns = $pdo->prepare('SELECT * FROM clients WHERE tenant_id=? AND id=? LIMIT 0');
    $columns->execute([$tenantId,$clientId]);
    $delivery = '';
    for ($i = 0; $i < $columns->columnCount(); ++$i) {
        $column = $columns->getColumnMeta($i);
        if (!isset($column['name'])) throw new RuntimeException('summary_delivery_unavailable');
        if ($column['name'] === 'work_summary_delivery') $delivery = " AND c.work_summary_delivery IN ('portal','email')";
    }
    $query = $pdo->prepare("SELECT l.occurred_at,l.summary FROM ticket_work_log l
        JOIN tickets t ON t.tenant_id=l.tenant_id AND t.id=l.ticket_id
        JOIN clients c ON c.tenant_id=t.tenant_id AND c.id=t.client_id
        WHERE l.tenant_id=? AND l.ticket_id=? AND t.client_id=? AND l.customer_visible=1" . $delivery . "
          AND EXISTS (SELECT 1 FROM westy_email_drafts d WHERE d.tenant_id=t.tenant_id AND d.ticket_id=t.id
                      AND d.client_id=t.client_id AND d.state='submitted') ORDER BY l.occurred_at,l.id");
    $query->execute([$tenantId,$ticketId,$clientId]);
    return ['customer_summary'=>null, 'work_log'=>$query->fetchAll(PDO::FETCH_ASSOC)];
}

function customer_summary_reviewed_work(PDO $pdo, int $tenantId, int $ticketId, int $clientId): array
{
    customer_summary_ready($pdo);
    $query = $pdo->prepare("SELECT l.occurred_at,l.summary,s.body
        FROM tickets t JOIN clients c ON c.tenant_id=t.tenant_id AND c.id=t.client_id
        JOIN ticket_work_log l ON l.tenant_id=t.tenant_id AND l.ticket_id=t.id AND l.customer_visible=1
        LEFT JOIN westy_workflows w ON w.tenant_id=t.tenant_id AND w.ticket_id=t.id AND w.client_id=t.client_id
        LEFT JOIN westy_workflow_log_state q ON q.tenant_id=w.tenant_id AND q.workflow_id=w.id
        LEFT JOIN ticket_customer_summaries s ON s.tenant_id=t.tenant_id AND s.approved_ticket_id=t.id
            AND s.client_id=t.client_id AND q.qa_state='passed'
        WHERE t.tenant_id=? AND t.id=? AND t.client_id=? AND c.work_summary_delivery IN ('portal','email')
          AND ((s.id IS NOT NULL AND l.workflow_id=w.id AND l.id<=s.last_log_id)
            OR EXISTS (SELECT 1 FROM westy_email_drafts d WHERE d.tenant_id=t.tenant_id AND d.ticket_id=t.id
                       AND d.client_id=t.client_id AND d.state='submitted'))
        ORDER BY l.occurred_at,l.id");
    $query->execute([$tenantId,$ticketId,$clientId]);
    $rows = [];
    $body = null;
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $body = $row['body'];
        $rows[] = ['occurred_at'=>$row['occurred_at'], 'summary'=>$row['summary']];
    }
    return ['customer_summary'=>$body, 'work_log'=>$rows];
}

function customer_summary_ticket(PDO $pdo, int $tenantId, int $ticketId, bool $lock = false): ?array
{
    return westy_workflow_row($pdo, "SELECT t.client_id,t.contact_id,c.work_summary_delivery,p.email,
            w.id AS workflow_id,q.qa_state,
            (SELECT CASE WHEN COUNT(*)=1 THEN MIN(r.received_at) END FROM westy_workflow_v4_receipts r
             WHERE r.tenant_id=w.tenant_id AND r.workflow_id=w.id AND r.action='qa') AS checked_at
        FROM tickets t JOIN clients c ON c.tenant_id=t.tenant_id AND c.id=t.client_id
        LEFT JOIN contacts p ON p.id=t.contact_id AND p.client_id=t.client_id
        LEFT JOIN westy_workflows w ON w.tenant_id=t.tenant_id AND w.ticket_id=t.id AND w.client_id=t.client_id
        LEFT JOIN westy_workflow_log_state q ON q.tenant_id=w.tenant_id AND q.workflow_id=w.id
        WHERE t.tenant_id=? AND t.id=? AND t.merged_into_id IS NULL" . ($lock ? westy_workflow_lock($pdo) : ''),
        [$tenantId,$ticketId]);
}

function customer_summary_source(PDO $pdo, int $tenantId, int $ticketId, array $ticket, bool $lock = false): ?array
{
    if ($ticket['qa_state'] !== 'passed') return null;
    $query = $pdo->prepare('SELECT id,occurred_at,summary,customer_visible FROM ticket_work_log
        WHERE tenant_id=? AND ticket_id=? AND workflow_id=? AND customer_visible=1 ORDER BY occurred_at,id'
        . ($lock ? westy_workflow_lock($pdo) : ''));
    $query->execute([$tenantId,$ticketId,$ticket['workflow_id']]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;
    $body = customer_summary_compose($rows, $ticket['qa_state'], $ticket['checked_at']);
    return ['body'=>$body, 'steps'=>array_column($rows, 'summary'), 'last_log_id'=>max(array_column($rows, 'id')),
        'source_sha256'=>hash('sha256', westy_work_log_json([$tenantId,$ticketId,(int)$ticket['client_id'],$ticket['checked_at'],$rows]))];
}

function customer_summary_latest(PDO $pdo, int $tenantId, int $ticketId, bool $lock = false): ?array
{
    return westy_workflow_row($pdo, "SELECT id,client_id,state,body,email_result FROM ticket_customer_summaries
        WHERE tenant_id=? AND ticket_id=? ORDER BY (state='approved') DESC,id DESC LIMIT 1"
        . ($lock ? westy_workflow_lock($pdo) : ''), [$tenantId,$ticketId]);
}

function customer_summary_staff_role(array $user): bool
{
    return in_array($user['role'] ?? null, ['owner','admin','tech'], true);
}

function customer_summary_staff_view(PDO $pdo, int $tenantId, int $ticketId): ?array
{
    customer_summary_ready($pdo);
    $ticket = customer_summary_ticket($pdo, $tenantId, $ticketId);
    if (!$ticket) return null;
    $latest = customer_summary_latest($pdo, $tenantId, $ticketId);
    $draft = ($latest['state'] ?? null) === 'approved' ? null : customer_summary_source($pdo, $tenantId, $ticketId, $ticket);
    if ($draft === null && ($latest['state'] ?? null) !== 'approved') return null;
    return ['latest'=>$latest, 'draft'=>$draft, 'delivery'=>$ticket['work_summary_delivery'],
        'approval_visible'=>($latest['state'] ?? '') === 'approved' && $latest['client_id'] === $ticket['client_id']
            && $ticket['qa_state'] === 'passed' && $ticket['work_summary_delivery'] !== 'none',
        'has_contact'=>customer_summary_recipient($ticket) !== null];
}

function customer_summary_waiting(PDO $pdo, int $tenantId): array
{
    customer_summary_ready($pdo);
    $query = $pdo->prepare("SELECT t.id,t.subject,c.name AS client_name
        FROM tickets t JOIN clients c ON c.tenant_id=t.tenant_id AND c.id=t.client_id
        JOIN westy_workflows w ON w.tenant_id=t.tenant_id AND w.ticket_id=t.id AND w.client_id=t.client_id
        JOIN westy_workflow_log_state q ON q.tenant_id=w.tenant_id AND q.workflow_id=w.id
        WHERE t.tenant_id=? AND t.merged_into_id IS NULL AND q.qa_state='passed'
          AND EXISTS (SELECT 1 FROM ticket_work_log l WHERE l.tenant_id=t.tenant_id AND l.ticket_id=t.id
                      AND l.workflow_id=w.id AND l.customer_visible=1)
          AND NOT EXISTS (SELECT 1 FROM ticket_customer_summaries s WHERE s.tenant_id=t.tenant_id AND s.approved_ticket_id=t.id)
        ORDER BY q.updated_at,t.id");
    $query->execute([$tenantId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** The address must belong to this ticket's own client. Reuse the customer reply loop guard. */
function customer_summary_recipient(array $ticket): ?string
{
    $email = trim((string)($ticket['email'] ?? ''));
    return filter_var($email, FILTER_VALIDATE_EMAIL) && !ticket_machine_address($email) ? $email : null;
}

/** Approval also releases the original steps. Check the whole public copy for tenant staff names. */
function customer_summary_contains_staff_name(PDO $pdo, int $tenantId, array $steps, string $body): bool
{
    $text = implode("\n", [...$steps, $body]);
    $names = $pdo->prepare("SELECT full_name FROM users WHERE tenant_id=? AND full_name<>''");
    $names->execute([$tenantId]);
    foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) continue;
        $pattern = implode('\s+', array_map(static fn(string $word): string => preg_quote($word, '~'), $words));
        if (preg_match('~(?<![\pL\pN])' . $pattern . '(?![\pL\pN])~iu', $text) !== 0) return true;
    }
    return false;
}

/** Only the winning approval reaches the mail call. Never dispatch from a poll, refresh or retry. */
function customer_summary_review(PDO $pdo, int $tenantId, int $userId, int $ticketId, array $input, ?callable $send = null): string
{
    if (!csrf_valid()) throw new RuntimeException('csrf_required');
    $action = $input['customer_summary_action'] ?? null;
    if (!in_array($action, ['approve','revoke'], true) || !is_string($input['review_id'] ?? null)
        || !ctype_digit($input['review_id'])) throw new InvalidArgumentException('invalid_review');
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        $ticket = customer_summary_ticket($pdo, $tenantId, $ticketId, true);
        $actor = westy_workflow_row($pdo, 'SELECT role FROM users WHERE tenant_id=? AND id=? AND is_active=1'
            . westy_workflow_lock($pdo), [$tenantId,$userId]);
        if (!$ticket || !$actor || !customer_summary_staff_role($actor)) throw new RuntimeException('unauthorized');
        $latest = customer_summary_latest($pdo, $tenantId, $ticketId, true);
        customer_summary_ready($pdo);
        if ((string)($latest['id'] ?? 0) !== $input['review_id']) throw new DomainException('review_changed');
        if ($action === 'revoke') {
            if (($latest['state'] ?? '') !== 'approved') throw new DomainException('review_changed');
            $pdo->prepare("UPDATE ticket_customer_summaries SET state='revoked' WHERE tenant_id=? AND ticket_id=? AND id=? AND state='approved'")
                ->execute([$tenantId,$ticketId,$latest['id']]);
            $pdo->commit();
            return 'Summary revoked.';
        }

        $source = customer_summary_source($pdo, $tenantId, $ticketId, $ticket, true);
        if ($source !== null && $source['body'] === null) throw new DomainException('unpublishable_work_log');
        if (($latest['state'] ?? '') === 'approved' || !$source || !is_string($input['source_sha256'] ?? null)
            || !hash_equals($source['source_sha256'], $input['source_sha256'])) throw new DomainException('review_changed');
        $body = $input['summary_body'] ?? null;
        if (!is_string($body)) throw new InvalidArgumentException('invalid_body');
        $body = str_replace("\r\n", "\n", $body);
        if (!customer_summary_valid($body) || utf8_clean($body) !== $body) throw new InvalidArgumentException('invalid_body');
        if (customer_summary_contains_staff_name($pdo, $tenantId, $source['steps'], $body)) {
            throw new InvalidArgumentException('staff_name_in_review');
        }
        $body = trim($body);
        $email = null;
        $notice = 'Summary approved.';
        if ($ticket['work_summary_delivery'] === 'email') {
            $claimed = westy_workflow_row($pdo, 'SELECT id FROM ticket_customer_summaries WHERE tenant_id=? AND emailed_ticket_id=?', [$tenantId,$ticketId]);
            if ($claimed) {
                $notice .= ' This ticket already used its one email attempt.';
            } elseif (($email = customer_summary_recipient($ticket)) === null) {
                $notice .= ' No email was sent because the ticket has no contact with an eligible address.';
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO ticket_customer_summaries (tenant_id,ticket_id,client_id,body,source_sha256,last_log_id,created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$tenantId,$ticketId,$ticket['client_id'],$body,$source['source_sha256'],$source['last_log_id'],$now]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE ticket_customer_summaries SET state='approved',approved_by=?,approved_at=?,emailed_at=?,email_result=?
            WHERE tenant_id=? AND ticket_id=? AND id=? AND state='draft'")
            ->execute([$userId,$now,$email === null ? null : $now,$email === null ? null : 'claimed',$tenantId,$ticketId,$id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    if ($email === null) return $notice;
    // Revoke, late QA, contact changes and delivery settings can win between approval and submission.
    // Hold their current rows through this one transport call. A crash consumes the committed claim.
    try {
        $pdo->beginTransaction();
        $current = customer_summary_ticket($pdo, $tenantId, $ticketId, true);
        $summary = westy_workflow_row($pdo, "SELECT state,body FROM ticket_customer_summaries
            WHERE tenant_id=? AND ticket_id=? AND id=? AND email_result='claimed'" . westy_workflow_lock($pdo), [$tenantId,$ticketId,$id]);
        customer_summary_ready($pdo);
        $accepted = false;
        if (($summary['state'] ?? '') === 'approved' && $current && $current['qa_state'] === 'passed'
            && $current['work_summary_delivery'] === 'email' && $current['client_id'] === $ticket['client_id']
            && $current['contact_id'] === $ticket['contact_id'] && customer_summary_recipient($current) === $email) {
            $link = customer_mail_portal_link($tenantId, (int)$current['client_id'], $ticketId);
            $message = $summary['body'];
            if ($link !== null) $message .= "\n\nOwners and admins of your business can view the work log in the support portal:\n" . $link . '#work-log';
            if ($send === null) require_once __DIR__ . '/mailer.php';
            $result = ($send ?? 'mail_send_result')($email, 'Summary of work on ticket #' . $ticketId, $message);
            $accepted = ($result['outcome'] ?? null) === 'provider_accepted';
        }
        $pdo->prepare("UPDATE ticket_customer_summaries SET email_result=? WHERE tenant_id=? AND ticket_id=? AND id=? AND email_result='claimed'")
            ->execute([$accepted ? 'submitted' : 'unconfirmed',$tenantId,$ticketId,$id]);
        $pdo->commit();
        if ($accepted) return 'Summary approved. The mail service accepted the email.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Customer summary email unconfirmed type=' . $error::class);
    }
    return 'Summary approved. Email submission is unconfirmed. It will not be retried.';
}

function customer_summary_change_delivery(PDO $pdo, int $tenantId, int $userId, int $clientId, mixed $level): void
{
    if (!csrf_valid()) throw new RuntimeException('csrf_required');
    if (!in_array($level, ['none','portal','email'], true)) throw new InvalidArgumentException('invalid_delivery');
    customer_summary_ready($pdo);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        $actor = westy_workflow_row($pdo, "SELECT id FROM users WHERE tenant_id=? AND id=? AND is_active=1 AND role IN ('owner','admin')"
            . westy_workflow_lock($pdo), [$tenantId,$userId]);
        $client = westy_workflow_row($pdo, 'SELECT id FROM clients WHERE tenant_id=? AND id=?' . westy_workflow_lock($pdo), [$tenantId,$clientId]);
        if (!$actor || !$client) throw new RuntimeException('unauthorized');
        $pdo->prepare('UPDATE clients SET work_summary_delivery=? WHERE tenant_id=? AND id=?')->execute([utf8_clean($level),$tenantId,$clientId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
