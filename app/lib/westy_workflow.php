<?php
/** Signed, tenant-bound controller events. Chat/model output has no authority here. */
declare(strict_types=1);
require_once __DIR__ . '/suite_managed_provider.php';
require_once __DIR__ . '/managed_customer_status.php';

const WESTY_WORKFLOW_SERVICE = 'milepost-workflow';
const WESTY_WORKFLOW_CONTEXT = 'safeharbor-westy-workflow-v1';
const WESTY_WORKFLOW_UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
const WESTY_WORKFLOW_FIELDS = ['schema_version','event_key','workflow_key','tenant_slug','customer_id','alert_key','action','expected_version','summary','occurred_at','evidence_sha256','verification_method','job_id','job_completed_at','alert_resolved_at','assignee_id'];
final class WestyWorkflowConflict extends RuntimeException {}

function westy_workflow_settings(array $config): array
{
    $config+=['tenant_slugs'=>[],'customer_ids'=>[]];
    if (($config['enabled'] ?? false) !== true || !is_string($config['hmac_secret'] ?? null)
        || strlen($config['hmac_secret']) < 32
        || !is_bool($config['managed_providers_enabled'] ?? false)
        || !is_array($config['tenant_slugs'] ?? null) || !array_is_list($config['tenant_slugs'])
        || !is_array($config['customer_ids'] ?? null) || !array_is_list($config['customer_ids'])
        || count($config['tenant_slugs']) > 1000 || count($config['customer_ids']) > 1000) {
        throw new RuntimeException('workflow_unavailable');
    }
    foreach ($config['tenant_slugs'] as $slug) {
        if (!is_string($slug) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $slug) !== 1) throw new RuntimeException('workflow_unavailable');
    }
    foreach ($config['customer_ids'] as $id) {
        if (!is_string($id) || preg_match(WESTY_WORKFLOW_UUID, $id) !== 1) throw new RuntimeException('workflow_unavailable');
    }
    return $config;
}

function westy_workflow_authenticated(array $settings, array $headers, string $body, ?int $now = null): bool
{
    $timestamp = $headers['timestamp'] ?? '';
    $signature = $headers['signature'] ?? '';
    return ($headers['service'] ?? '') === WESTY_WORKFLOW_SERVICE
        && is_string($timestamp) && preg_match('/\A[1-9][0-9]{0,11}\z/D', $timestamp) === 1
        && abs(($now ?? time()) - (int)$timestamp) <= 300
        && is_string($signature) && preg_match('/\A[0-9a-f]{64}\z/D', $signature) === 1
        && strlen($body) <= 16384 && $body !== ''
        && hash_equals(hash_hmac('sha256', WESTY_WORKFLOW_CONTEXT . "\n" . $timestamp . "\n" . $body, $settings['hmac_secret']), $signature);
}

function westy_workflow_date(mixed $value): bool
{
    if (!is_string($value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
}

function westy_workflow_request(string $body, ?int $now = null): array
{
    if ($body === '' || strlen($body) > 16384) throw new InvalidArgumentException('invalid_body');
    try { $p = json_decode($body, true, 4, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new InvalidArgumentException('invalid_json'); }
    if (!is_array($p) || array_is_list($p)) throw new InvalidArgumentException('invalid_payload');
    $keys = array_keys($p); $expected = WESTY_WORKFLOW_FIELDS;
    sort($keys); sort($expected);
    // Flat scalars only: counting key separators outside quoted values detects
    // duplicate members that json_decode would otherwise silently replace.
    $withoutStrings = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $body);
    if ($keys !== $expected || substr_count((string)$withoutStrings, ':') !== count($keys)) throw new InvalidArgumentException('invalid_fields');
    if ($p['schema_version'] !== 1 || !is_int($p['expected_version']) || $p['expected_version'] < 0) throw new InvalidArgumentException('invalid_version');
    foreach (['event_key','workflow_key','customer_id'] as $key) {
        if (!is_string($p[$key]) || preg_match(WESTY_WORKFLOW_UUID, $p[$key]) !== 1) throw new InvalidArgumentException('invalid_' . $key);
    }
    if (!is_string($p['tenant_slug']) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $p['tenant_slug']) !== 1) throw new InvalidArgumentException('invalid_tenant');
    if (!is_string($p['alert_key']) || preg_match('/\Aalert:([1-9][0-9]{0,19})\z/D', $p['alert_key'], $alert) !== 1
        || (strlen($alert[1]) === 20 && strcmp($alert[1], '18446744073709551615') > 0)) throw new InvalidArgumentException('invalid_alert');
    if (!in_array($p['action'], ['claim','progress','escalate','resolve','status'], true)) throw new InvalidArgumentException('invalid_action');
    if (!is_string($p['summary']) || !mb_check_encoding($p['summary'], 'UTF-8') || mb_strlen($p['summary']) > 2000
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $p['summary']) === 1) throw new InvalidArgumentException('invalid_summary');
    if ($p['action'] !== 'status' && trim($p['summary']) === '') throw new InvalidArgumentException('summary_required');
    if (!westy_workflow_date($p['occurred_at']) || abs(($now ?? time()) - strtotime($p['occurred_at'])) > 86400
        || strtotime($p['occurred_at']) > ($now ?? time()) + 30) throw new InvalidArgumentException('invalid_occurred_at');
    foreach (['job_id','assignee_id'] as $key) {
        if ($p[$key] !== null && (!is_int($p[$key]) || $p[$key] < 1)) throw new InvalidArgumentException('invalid_' . $key);
    }
    if ($p['assignee_id'] !== null && $p['action'] !== 'escalate') throw new InvalidArgumentException('assignee_only_on_escalation');
    if ($p['action'] === 'resolve') {
        if ($p['verification_method'] !== 'agent_job_and_alert_recovery' || $p['job_id'] === null
            || !is_string($p['evidence_sha256']) || preg_match('/\A[0-9a-f]{64}\z/D', $p['evidence_sha256']) !== 1
            || !westy_workflow_date($p['job_completed_at']) || !westy_workflow_date($p['alert_resolved_at'])
            || $p['job_completed_at'] > $p['alert_resolved_at'] || $p['alert_resolved_at'] > $p['occurred_at']
            || strtotime($p['occurred_at']) - strtotime($p['alert_resolved_at']) > 900) throw new InvalidArgumentException('independent_recovery_required');
    } else {
        foreach (['verification_method','evidence_sha256','job_id','job_completed_at','alert_resolved_at'] as $key) {
            if ($p[$key] !== null) throw new InvalidArgumentException('verification_only_on_resolution');
        }
    }
    return $p;
}

function westy_workflow_lock(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function westy_workflow_row(PDO $pdo, string $sql, array $args): ?array
{
    $query = $pdo->prepare($sql); $query->execute($args);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A partly applied migration must never enable unattended ticket closure. */
function westy_workflow_schema_ready(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    if ((int)$pdo->query('SELECT westy_workflow_schema_health()')->fetchColumn() !== 1) throw new RuntimeException('workflow_schema_unavailable');
}

function westy_workflow_result(array $run, string $action, ?string $receiptId, bool $replayed = false): array
{
    return ['ok'=>true, 'action'=>$action, 'ticket_id'=>(int)$run['ticket_id'], 'workflow_key'=>$run['workflow_key'],
        'version'=>(int)$run['version'], 'state'=>$run['state'], 'ticket_url'=>'https://safeharbor.8westit.com/ticket.php?id=' . (int)$run['ticket_id'],
        'receipt_id'=>$receiptId, 'replayed'=>$replayed];
}

/** One local transaction; zero network, mail, AI, financial, or endpoint calls. */
function westy_workflow_receive(PDO $pdo, array $settings, array $p, string $requestHash): array
{
    $settings = westy_workflow_settings($settings);
    westy_workflow_schema_ready($pdo);
    $managed = !in_array($p['tenant_slug'], ['8west', 'internal'], true);
    if ($managed && ($settings['managed_providers_enabled'] ?? false) !== true) throw new RuntimeException('unauthorized');
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        $lock = westy_workflow_lock($pdo);
        // Tenant -> customer binding -> ticket -> run is the shared lock order.
        $tenant = westy_workflow_row($pdo, 'SELECT id,slug FROM tenants WHERE slug = ?' . $lock, [$p['tenant_slug']]);
        if (!$tenant || $tenant['slug'] !== $p['tenant_slug']) throw new RuntimeException('unauthorized');
        $tid = (int)$tenant['id'];
        if ($managed) {
            $provider = suite_managed_provider($pdo, $p['tenant_slug'], true);
            if ($provider === null || (int)$provider['tenant_id'] !== $tid) throw new RuntimeException('unauthorized');
        }
        $identity = westy_workflow_row($pdo, 'SELECT id FROM svc_identities WHERE tenant_id = ? AND service = ? AND is_active = 1' . $lock, [$tid, WESTY_WORKFLOW_SERVICE]);
        if (!$identity) throw new RuntimeException('unauthorized');
        $binding = westy_workflow_row($pdo, 'SELECT client_id FROM suite_customer_sync_bindings WHERE tenant_id = ? AND customer_id = ? AND status = ?' . $lock, [$tid,$p['customer_id'],'active']);
        if (!$binding) throw new WestyWorkflowConflict('active_customer_binding_required');
        if (!managed_customer_operational($pdo, $tid, (int)$binding['client_id'], true)) {
            throw new WestyWorkflowConflict('active_customer_binding_required');
        }
        $prior = westy_workflow_row($pdo, 'SELECT request_sha256,response_json FROM westy_workflow_receipts WHERE tenant_id = ? AND event_key = ?', [$tid,$p['event_key']]);
        if ($prior) {
            if (!hash_equals($prior['request_sha256'], $requestHash)) throw new WestyWorkflowConflict('event_key_conflict');
            $result = json_decode($prior['response_json'], true, 16, JSON_THROW_ON_ERROR);
            $result['replayed'] = true; $pdo->commit(); return $result;
        }
        $ticket = westy_workflow_row($pdo, 'SELECT * FROM tickets WHERE tenant_id = ? AND external_key = ?' . $lock, [$tid,$p['alert_key']]);
        $run = westy_workflow_row($pdo, 'SELECT * FROM westy_workflows WHERE tenant_id = ? AND workflow_key = ?' . $lock, [$tid,$p['workflow_key']]);
        if ($run && ($run['customer_id'] !== $p['customer_id'] || $run['alert_key'] !== $p['alert_key'] || !$ticket || (int)$run['ticket_id'] !== (int)$ticket['id'])) throw new WestyWorkflowConflict('workflow_scope_conflict');
        if ($ticket && (int)$ticket['client_id'] !== (int)$binding['client_id']) throw new WestyWorkflowConflict('ticket_customer_conflict');
        if ($p['action'] === 'status') {
            if (!$run) throw new WestyWorkflowConflict('workflow_not_found');
            $result = westy_workflow_result($run, 'status', null) + array_intersect_key($p, array_flip(['event_key','tenant_slug','customer_id','alert_key'])); $pdo->commit(); return $result;
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($p['action'] === 'claim') {
            if ($run || $p['expected_version'] !== 0) throw new WestyWorkflowConflict('workflow_already_claimed');
            if ($ticket) {
                $other = westy_workflow_row($pdo, 'SELECT id FROM westy_workflows WHERE tenant_id = ? AND ticket_id = ?', [$tid,$ticket['id']]);
                $work = westy_workflow_row($pdo, "SELECT id FROM messages WHERE ticket_id = ? AND kind <> 'system' LIMIT 1", [$ticket['id']]);
                $time = westy_workflow_row($pdo, 'SELECT id FROM time_entries WHERE tenant_id = ? AND ticket_id = ? LIMIT 1', [$tid,$ticket['id']]);
                if ($other || $work || $time || $ticket['status'] !== 'open' || $ticket['assignee_id'] !== null || $ticket['merged_into_id'] !== null || (int)$ticket['auto_close_eligible'] !== 1) throw new WestyWorkflowConflict('human_owns_ticket');
            } else {
                $goal = service_goal_snapshot_for_new_ticket($pdo, $tid, (int)$binding['client_id'], 'normal');
                $pdo->prepare('INSERT INTO tickets (tenant_id,client_id,subject,priority,channel,external_key,auto_close_eligible,sla_due_at,service_goal_target_id,created_at,updated_at) VALUES (?,?,?,?,?,?,0,?,?,?,?)')
                    ->execute([$tid,$binding['client_id'],'Milepost ' . $p['alert_key'] . ' - Westy troubleshooting','normal','alert',$p['alert_key'],$goal['due_at'],$goal['target_id'],$goal['opened_at'],$goal['opened_at']]);
                $ticket = ['id'=>(int)$pdo->lastInsertId()];
            }
            $pdo->prepare("UPDATE tickets SET status = 'in_progress',auto_close_eligible = 0 WHERE id = ? AND tenant_id = ?")->execute([$ticket['id'],$tid]);
            $pdo->prepare('INSERT INTO westy_workflows (tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tid,$binding['client_id'],$p['customer_id'],$ticket['id'],$p['workflow_key'],$p['alert_key'],'working',1,$p['summary'],$now,$now]);
            $run = ['id'=>(int)$pdo->lastInsertId(),'ticket_id'=>$ticket['id'],'workflow_key'=>$p['workflow_key'],'version'=>1,'state'=>'working'];
        } else {
            if (!$run) throw new WestyWorkflowConflict('workflow_not_found');
            if ((int)$run['version'] !== $p['expected_version']) throw new WestyWorkflowConflict('version_conflict');
            if ($run['state'] !== 'working' || $ticket['status'] !== 'in_progress' || $ticket['assignee_id'] !== null || $ticket['merged_into_id'] !== null) throw new WestyWorkflowConflict('human_owns_ticket');
            if ($p['action'] === 'resolve' && str_replace(' ', 'T', $run['started_at']) . 'Z' > $p['job_completed_at']) throw new WestyWorkflowConflict('job_predates_workflow');
            $state = match ($p['action']) { 'resolve'=>'resolved', 'escalate'=>'needs_human', default=>'working' };
            if ($p['action'] === 'escalate') {
                if ($p['assignee_id'] !== null && !westy_workflow_row($pdo, "SELECT id FROM users WHERE tenant_id = ? AND id = ? AND is_active = 1 AND role IN ('owner','admin','tech')" . $lock, [$tid,$p['assignee_id']])) throw new WestyWorkflowConflict('assignee_unavailable');
                $pdo->prepare("UPDATE tickets SET status = 'open',assignee_id = ?,auto_close_eligible = 0 WHERE tenant_id = ? AND id = ?")->execute([$p['assignee_id'],$tid,$ticket['id']]);
            } elseif ($p['action'] === 'resolve') {
                $pdo->prepare("UPDATE tickets SET status = 'resolved',resolved_at = ?,auto_close_eligible = 0 WHERE tenant_id = ? AND id = ?")->execute([$now,$tid,$ticket['id']]);
            }
            $run['version'] = (int)$run['version'] + 1; $run['state'] = $state;
            $pdo->prepare('UPDATE westy_workflows SET state = ?,version = ?,summary = ?,evidence_sha256 = ?,job_id = ?,job_completed_at = ?,alert_resolved_at = ?,closed_at = ?,updated_at = ? WHERE tenant_id = ? AND id = ?')
                ->execute([$state,$run['version'],$p['summary'],$p['evidence_sha256'],$p['job_id'],$p['job_completed_at'] === null ? null : gmdate('Y-m-d H:i:s',strtotime($p['job_completed_at'])),$p['alert_resolved_at'] === null ? null : gmdate('Y-m-d H:i:s',strtotime($p['alert_resolved_at'])),$state === 'resolved' ? $now : null,$now,$tid,$run['id']]);
        }
        // System evidence is never a customer reply or an approved time entry.
        $pdo->prepare('INSERT INTO messages (ticket_id,author_name,kind,body) VALUES (?,?,?,?)')->execute([$ticket['id'],'Westy workflow','system','Westy ' . $p['action'] . ': ' . $p['summary']]);
        $result = westy_workflow_result($run, $p['action'], $p['event_key']) + array_intersect_key($p, array_flip(['event_key','tenant_slug','customer_id','alert_key']));
        $pdo->prepare('INSERT INTO westy_workflow_receipts (tenant_id,workflow_id,event_key,request_sha256,action,version,response_json,received_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$tid,$run['id'],$p['event_key'],$requestHash,$p['action'],$run['version'],json_encode($result,JSON_THROW_ON_ERROR),$now]);
        if ($p['action'] === 'resolve') {
            $billingEvent = 'safeharbor-billing:' . substr(hash('sha256',$p['tenant_slug'] . ':' . $p['workflow_key']),0,32);
            $pdo->prepare('INSERT INTO westy_billing_outbox (tenant_id,workflow_id,event_key,created_at,updated_at) VALUES (?,?,?,?,?)')
                ->execute([$tid,$run['id'],$billingEvent,$now,$now]);
        }
        // The stable event UUID is also the public receipt ID; database IDs
        // remain internal, and an exact replay returns identical source facts.
        $pdo->commit(); return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Read-only ticket card. Absence before migration is normal while gated off. */
function westy_workflow_ticket(PDO $pdo, int $tenantId, int $ticketId): ?array
{
    try {
        return westy_workflow_row($pdo, 'SELECT workflow.*,billing.state AS billing_state,billing.detail_code AS billing_detail,billing.response_json AS billing_response FROM westy_workflows workflow LEFT JOIN westy_billing_outbox billing ON billing.tenant_id=workflow.tenant_id AND billing.workflow_id=workflow.id WHERE workflow.tenant_id = ? AND workflow.ticket_id = ?', [$tenantId,$ticketId]);
    } catch (PDOException $error) {
        // Historical evidence stays visible when automation is disabled. Before
        // migration, the ordinary ticket page remains quiet and fully usable.
        if (($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' && (int)($error->errorInfo[1]??0)===1146)
            || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' && str_contains($error->getMessage(),'no such table:'))) return null;
        throw $error;
    }
}

/** One bounded read per batch, including when the integration is later disabled. */
function westy_workflow_ticket_owners(PDO $pdo, int $tenantId, array $tickets): array
{
    $ids = [];
    foreach ($tickets as &$ticket) {
        $ticket['westy_owned'] = false;
        if ((int)($ticket['tenant_id'] ?? 0) === $tenantId && (int)($ticket['id'] ?? 0) > 0
            && ($ticket['status'] ?? '') === 'in_progress' && empty($ticket['assignee_id'])) {
            $ids[(int)$ticket['id']] = (int)$ticket['id'];
        }
    }
    unset($ticket);
    $owned = [];
    try {
        foreach (array_chunk(array_values($ids), 500) as $batch) {
            $s = $pdo->prepare("SELECT w.ticket_id,w.client_id FROM westy_workflows w
                JOIN tickets t ON t.id=w.ticket_id AND t.tenant_id=w.tenant_id AND t.client_id=w.client_id
                WHERE w.tenant_id=? AND t.tenant_id=? AND w.state='working'
                AND t.status='in_progress' AND t.assignee_id IS NULL
                AND t.id IN (" . implode(',', array_fill(0, count($batch), '?')) . ")");
            $s->execute(array_merge([$tenantId,$tenantId], $batch));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $owned[(int)$row['ticket_id']] = (int)$row['client_id'];
        }
    } catch (PDOException $error) {
        if (!(($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' && (int)($error->errorInfo[1]??0)===1146)
            || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' && str_contains($error->getMessage(),'no such table:')))) throw $error;
    }
    foreach ($tickets as &$ticket) {
        $ticket['westy_owned'] = (int)($ticket['tenant_id'] ?? 0) === $tenantId
            && isset($owned[(int)($ticket['id'] ?? 0)])
            && $owned[(int)$ticket['id']] === (int)$ticket['client_id']
            && ($ticket['status'] ?? '') === 'in_progress' && empty($ticket['assignee_id']);
    }
    unset($ticket);
    return $tickets;
}

function westy_workflow_card(array $run): string
{
    $billingReceipt=is_string($run['billing_response']??null)?json_decode($run['billing_response'],true):null;
    $included=($run['state']??null)==='resolved' && ($run['billing_state']??null)==='accepted' && ($billingReceipt['completion']['state']??null)==='included'
        && ($billingReceipt['completion']['additional_amount_cents']??null)===0
        && ($billingReceipt['completion']['source']['run_key']??null)===($run['workflow_key']??null);
    $labels = ['working'=>'Westy is troubleshooting','needs_human'=>'A technician is needed','human_owned'=>'A technician owns this ticket','resolved'=>'Recovery verified'];
    $explanations = ['working'=>'Review progress and approve commands in Milepost. Taking over here pauses Westy before his next action.',
        'needs_human'=>'Westy stopped and left the findings below. Review the assigned technician or assign one to continue.',
        'human_owned'=>'Someone changed the ticket or added work. Westy cannot close it automatically.',
        'resolved'=>'Milepost recorded a completed agent job and fresh recovery of the original alert.'];
    $state = (string)$run['state'];
    $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $html = '<section class="card westy-workflow-card" aria-labelledby="westy-workflow-title" data-workflow-state="' . $escape($state) . '">'
        . '<div class="westy-workflow-heading"><span class="westy-workflow-mark" aria-hidden="true">W</span><div><span class="rail-k">Troubleshooting</span>'
        . '<h2 id="westy-workflow-title">' . $escape($labels[$state] ?? 'Workflow status unavailable') . '</h2></div></div>'
        . '<p>' . $escape($explanations[$state] ?? 'Open Milepost to review this workflow.') . '</p>'
        . '<p class="westy-workflow-summary">' . $escape($run['summary']) . '</p>'
        . '<div class="westy-workflow-actions"><a class="btn-primary btn-sm" href="https://support.8westit.com/westy_diag.php?workflow=' . rawurlencode($run['workflow_key']) . '">Open troubleshooting</a>';
    if ($state === 'working') {
        $html .= '<button type="button" class="btn-ghost btn-sm" data-action="assignee" data-id="' . (int)$run['ticket_id'] . '">Take over ticket</button>';
    }
    if (!$included && ($state === 'resolved' || !empty($run['closed_at']))) $html .= '<a class="btn-ghost btn-sm" href="/time.php">Review time &amp; billing</a>';
    $html .= '</div>';
    if (isset($run['billing_state'])) {
        $billingLabels = ['waiting_for_time'=>'Billing is waiting for approved time and its Coastmark export.', 'ready'=>'Billing handoff is ready.',
            'sending'=>'Checking the billing handoff.', 'uncertain'=>'Billing receipt is being reconciled. No invoice email has been sent by Safeharbor.',
            'accepted'=>'Coastmark received the handoff. Review the invoice before sending.', 'blocked'=>'Billing needs a technician review before it can continue.'];
        if ($included) $billingLabels['accepted']='Service complete. Coastmark confirmed this work is covered by the customer plan. No extra charge.';
        $html .= '<p class="rail-note">' . $escape($billingLabels[$run['billing_state']] ?? 'Billing status unavailable.') . '</p>';
        if ($run['billing_state'] === 'accepted' && is_string($run['billing_response'] ?? null)) {
            $receipt = json_decode($run['billing_response'], true);
            foreach (array_slice(is_array($receipt['invoices'] ?? null) ? $receipt['invoices'] : [], 0, 10) as $invoice) {
                if (is_int($invoice['invoice_id'] ?? null) && $invoice['invoice_id'] > 0) $html .= '<a class="link" href="https://coastmark.8westit.com/invoices/' . $invoice['invoice_id'] . '">Open invoice ' . $invoice['invoice_id'] . ' for review</a> ';
            }
        }
    }
    if (!empty($run['evidence_sha256'])) {
        $html .= '<details class="westy-workflow-evidence"><summary>Recovery evidence</summary><p>Agent job ' . (int)$run['job_id'] . '</p><code>' . $escape($run['evidence_sha256']) . '</code><p>Closed ' . $escape($run['closed_at']) . ' UTC</p></details>';
    }
    return $html . '</section>';
}
