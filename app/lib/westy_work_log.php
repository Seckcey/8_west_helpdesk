<?php
/** Immutable v4 evidence. Mirrored seals and QA live apart from ticket and mail activity. */
declare(strict_types=1);
require_once __DIR__ . '/westy_workflow.php';

const WESTY_WORK_LOG_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
const WESTY_WORK_LOG_KINDS = ['detect','triage','hypothesis','probe','finding','decision','action','verify','rollback','escalate','handoff','note','qa'];
const WESTY_WORK_LOG_VISIBLE_KINDS = ['detect','action','verify','escalate'];
const WESTY_WORK_LOG_ENTRY_FIELDS = ['seq','occurred_at','actor_key','kind','summary','cause_code','evidence_sha256','milepost_ref','customer_visible','entry_sha256'];
const WESTY_WORK_LOG_FIELDS = ['schema_version','action','event_key','workflow_key','tenant_slug','customer_id','alert_key','occurred_at','first_seq','entries','sealed_seq','log_sha256'];
const WESTY_WORK_QA_FIELDS = ['schema_version','action','event_key','workflow_key','tenant_slug','customer_id','alert_key','occurred_at','verdict','reason_code','log_sha256','evidence_sha256'];

function westy_work_log_json(mixed $value): string
{
    return json_encode($value, WESTY_WORK_LOG_FLAGS);
}

function westy_work_log_hash(mixed $value): bool
{
    return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
}

function westy_work_log_genesis(string $workflowKey): string
{
    return hash('sha256', "milepost-westy-case-log-v1\n" . $workflowKey);
}

function westy_work_log_entry_hash(string $previous, array $entry): string
{
    return hash('sha256', $previous . "\n" . westy_work_log_json([
        $entry['seq'], $entry['occurred_at'], $entry['actor_key'], $entry['kind'],
        $entry['summary'], $entry['cause_code'], $entry['evidence_sha256'],
        $entry['milepost_ref'], $entry['customer_visible'],
    ]));
}

/** These five patterns are verbatim from Milepost 355511cd, westy_workflow_learning.php:12-16. */
function westy_work_log_summary_unsafe(string $summary): bool
{
    foreach ([
        '/\b(?:password|passwd|secret|token|(?:access|refresh)[_ -]?token|api[_ -]?key|authorization|client[_ -]?secret)["\x27]?\s*[:=]\s*[^\r\n]+/i',
        '/\bBearer\s+\S+|\b[A-Za-z0-9+_\/-]{40,}={0,2}/i',
        '~https?://[^\s<>"\x27]+~i',
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
    ] as $pattern) {
        if (preg_match($pattern, $summary) === 1) return true;
    }
    return false;
}

function westy_work_log_summary_valid(mixed $summary): bool
{
    // Bound the original as well as the trimmed text: never truncate hashed bytes to fit SQL.
    return is_string($summary) && mb_check_encoding($summary, 'UTF-8')
        && trim($summary) !== '' && mb_strlen($summary, 'UTF-8') <= 1000
        && preg_match('/\p{Cc}/u', $summary) === 0;
}

function westy_work_log_actor_type(string $key): ?string
{
    if ($key === 'system:milepost') return 'system';
    if (strlen($key) <= 255 && preg_match('/\Amilepost-user:[1-9][0-9]*\z/D', $key) === 1) return 'user';
    if (preg_match('/\Aai-staff:[a-z0-9_:-]{1,240}\z/D', $key) === 1) return 'ai_staff';
    return null;
}

function westy_workflow_v4_request(string $body, ?int $now = null): array
{
    if ($body === '' || strlen($body) > 16384) throw new InvalidArgumentException('invalid_body');
    try {
        // Decode objects as objects for the round trip: {} must not silently become [].
        $object = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        if (westy_work_log_json($object) !== $body) throw new InvalidArgumentException('noncanonical_body');
        $p = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) { throw new InvalidArgumentException('invalid_json'); }
    if (!is_array($p) || array_is_list($p)) throw new InvalidArgumentException('invalid_payload');
    if (($p['schema_version'] ?? null) !== 4) throw new InvalidArgumentException('invalid_version');
    if (!in_array($p['action'] ?? null, ['log','qa'], true)) throw new InvalidArgumentException('invalid_action');
    if (array_keys($p) !== ($p['action'] === 'log' ? WESTY_WORK_LOG_FIELDS : WESTY_WORK_QA_FIELDS)) throw new InvalidArgumentException('invalid_fields');
    foreach (['event_key','workflow_key','customer_id'] as $key) {
        if (!is_string($p[$key]) || preg_match(WESTY_WORKFLOW_UUID, $p[$key]) !== 1) throw new InvalidArgumentException('invalid_' . $key);
    }
    if (!is_string($p['tenant_slug']) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $p['tenant_slug']) !== 1) throw new InvalidArgumentException('invalid_tenant');
    if (!is_string($p['alert_key']) || preg_match('/\Aalert:([1-9][0-9]{0,19})\z/D', $p['alert_key'], $alert) !== 1
        || (strlen($alert[1]) === 20 && strcmp($alert[1], '18446744073709551615') > 0)) throw new InvalidArgumentException('invalid_alert');
    $now ??= time();
    if (!westy_workflow_date($p['occurred_at']) || abs($now - strtotime($p['occurred_at'])) > 86400
        || strtotime($p['occurred_at']) > $now + 30) throw new InvalidArgumentException('invalid_occurred_at');
    if ($p['action'] === 'qa') {
        if (!in_array($p['verdict'], ['passed','fault_zero','void'], true)) throw new InvalidArgumentException('invalid_verdict');
        if (!is_string($p['reason_code']) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $p['reason_code']) !== 1) throw new InvalidArgumentException('invalid_reason_code');
        foreach (['log_sha256','evidence_sha256'] as $key) {
            if (!westy_work_log_hash($p[$key])) throw new InvalidArgumentException('invalid_' . $key);
        }
        return $p;
    }
    if (!is_int($p['first_seq']) || $p['first_seq'] < 1 || $p['first_seq'] > 4294967295) throw new InvalidArgumentException('invalid_first_seq');
    if (!is_array($p['entries']) || !array_is_list($p['entries']) || count($p['entries']) < 1 || count($p['entries']) > 20) throw new InvalidArgumentException('invalid_entries');
    foreach ($p['entries'] as $index => $entry) {
        if (!is_array($entry) || array_keys($entry) !== WESTY_WORK_LOG_ENTRY_FIELDS) throw new InvalidArgumentException('invalid_entry_fields');
        if (!is_int($entry['seq']) || $entry['seq'] !== $p['first_seq'] + $index || $entry['seq'] > 4294967295) throw new InvalidArgumentException('invalid_seq');
        // A current request can carry historical steps from a long-running case.
        if (!westy_workflow_date($entry['occurred_at']) || strtotime($entry['occurred_at']) > $now + 30)
            throw new InvalidArgumentException('invalid_occurred_at');
        if (!is_string($entry['actor_key']) || westy_work_log_actor_type($entry['actor_key']) === null) throw new InvalidArgumentException('invalid_actor_key');
        if (!in_array($entry['kind'], WESTY_WORK_LOG_KINDS, true)) throw new InvalidArgumentException('invalid_kind');
        if (!westy_work_log_summary_valid($entry['summary'])) throw new InvalidArgumentException('invalid_summary');
        if (westy_work_log_summary_unsafe($entry['summary'])) throw new InvalidArgumentException('summary_unsafe');
        if (!is_string($entry['cause_code']) || preg_match('/\A[A-Za-z][A-Za-z0-9_:.-]{0,63}\z/D', $entry['cause_code']) !== 1) throw new InvalidArgumentException('invalid_cause_code');
        if ($entry['evidence_sha256'] !== null && !westy_work_log_hash($entry['evidence_sha256'])) throw new InvalidArgumentException('invalid_evidence_sha256');
        if ($entry['milepost_ref'] !== null && (!is_string($entry['milepost_ref']) || preg_match('/\A[a-z_0-9:;]{1,255}\z/D', $entry['milepost_ref']) !== 1)) throw new InvalidArgumentException('invalid_milepost_ref');
        if (!is_bool($entry['customer_visible']) || ($entry['customer_visible'] && !in_array($entry['kind'], WESTY_WORK_LOG_VISIBLE_KINDS, true))) throw new InvalidArgumentException('invalid_customer_visible');
        if (!westy_work_log_hash($entry['entry_sha256'])) throw new InvalidArgumentException('invalid_entry_sha256');
        if ($entry['seq'] > 501 || ($entry['seq'] === 501 && $entry['kind'] !== 'note')) throw new InvalidArgumentException('log_limit');
    }
    if (($p['sealed_seq'] === null) !== ($p['log_sha256'] === null)
        || ($p['sealed_seq'] !== null && (!is_int($p['sealed_seq']) || $p['sealed_seq'] !== $p['entries'][count($p['entries']) - 1]['seq']
            || !westy_work_log_hash($p['log_sha256'])))) throw new InvalidArgumentException('invalid_seal');
    return $p;
}

/** No cached positive result: missing function, missing privilege and partial schema all refuse. */
function westy_work_log_schema_ready(PDO $pdo): void
{
    try {
        westy_workflow_schema_ready($pdo);
        if ((int)$pdo->query('SELECT westy_work_log_schema_health()')->fetchColumn() !== 1) throw new RuntimeException('workflow_v4_unavailable');
    } catch (Throwable $error) { throw new RuntimeException('workflow_v4_unavailable', 0, $error); }
}

function westy_workflow_v4_result(array $p, array $run): array
{
    return ['schema_version'=>4, 'ok'=>true, 'action'=>$p['action'], 'event_key'=>$p['event_key'],
        'workflow_key'=>$p['workflow_key'], 'tenant_slug'=>$p['tenant_slug'], 'customer_id'=>$p['customer_id'],
        'alert_key'=>$p['alert_key'], 'ticket_id'=>(int)$run['ticket_id'], 'receipt_id'=>$p['event_key'], 'replayed'=>false];
}

function westy_work_log_state(PDO $pdo, int $tenantId, int $workflowId, bool $forUpdate = false): ?array
{
    return westy_workflow_row($pdo, 'SELECT log_sha256,log_sealed_seq,qa_state,qa_reason FROM westy_workflow_log_state
        WHERE tenant_id = ? AND workflow_id = ?' . ($forUpdate ? westy_workflow_lock($pdo) : ''), [$tenantId,$workflowId]);
}

function westy_workflow_v4_receive(PDO $pdo, array $settings, array $p, string $requestHash): array
{
    $settings = westy_workflow_settings($settings);
    westy_work_log_schema_ready($pdo);
    $managed = !in_array($p['tenant_slug'], ['8west','internal'], true);
    if ($managed && ($settings['managed_providers_enabled'] ?? false) !== true) throw new RuntimeException('unauthorized');
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        $lock = westy_workflow_lock($pdo);
        $tenant = westy_workflow_row($pdo, 'SELECT id,slug FROM tenants WHERE slug = ?' . $lock, [$p['tenant_slug']]);
        if (!$tenant || $tenant['slug'] !== $p['tenant_slug']) throw new RuntimeException('unauthorized');
        $tid = (int)$tenant['id'];
        if ($managed) {
            $provider = suite_managed_provider($pdo, $p['tenant_slug'], true);
            if ($provider === null || (int)$provider['tenant_id'] !== $tid) throw new RuntimeException('unauthorized');
        }
        $identity = westy_workflow_row($pdo, 'SELECT id FROM svc_identities WHERE tenant_id = ? AND service = ? AND is_active = 1' . $lock, [$tid, WESTY_WORKFLOW_SERVICE]);
        if (!$identity) throw new RuntimeException('unauthorized');
        $key = [$tid,$p['event_key']];
        $legacy = westy_workflow_row($pdo, 'SELECT event_key FROM westy_workflow_receipts WHERE tenant_id = ? AND event_key = ?', $key);
        $prior = westy_workflow_row($pdo, 'SELECT request_sha256,response_json FROM westy_workflow_v4_receipts WHERE tenant_id = ? AND event_key = ?', $key);
        $readTicket = static fn() => westy_workflow_row($pdo, 'SELECT * FROM tickets WHERE tenant_id = ? AND external_key = ?' . $lock, [$tid,$p['alert_key']]);
        $readRun = static fn() => westy_workflow_row($pdo, 'SELECT * FROM westy_workflows WHERE tenant_id = ? AND workflow_key = ?' . $lock, [$tid,$p['workflow_key']]);
        if ($p['action'] === 'qa' && !$prior && !$legacy) {
            // The tenant lock serializes QA with dispatch. Lock the outbox first, then the ticket
            // before the workflow, as a technician's ticket edit takes the workflow lock through a trigger.
            $billing = $pdo->prepare('SELECT state,detail_code,(payload_json IS NOT NULL OR payload_sha256 IS NOT NULL) AS frozen
                FROM westy_billing_outbox WHERE tenant_id = ? AND workflow_id =
                    (SELECT id FROM westy_workflows WHERE tenant_id = ? AND workflow_key = ? AND customer_id = ?)
                ORDER BY id' . $lock);
            $billing->execute([$tid,$tid,$p['workflow_key'],$p['customer_id']]);
            $billingRows = $billing->fetchAll(PDO::FETCH_ASSOC);
            $ticket = $readTicket();
            $run = $readRun();
        }
        $binding = westy_workflow_row($pdo, 'SELECT client_id FROM suite_customer_sync_bindings WHERE tenant_id = ? AND customer_id = ? AND status = ?' . $lock, [$tid,$p['customer_id'],'active']);
        if (!$binding || !managed_customer_operational($pdo, $tid, (int)$binding['client_id'], true)) throw new WestyWorkflowConflict('active_customer_binding_required');
        // Only an authorized, operational customer can receive a conflict or replay of its request.
        if ($legacy) throw new WestyWorkflowConflict('event_key_conflict');
        if ($prior) {
            if (!hash_equals($prior['request_sha256'], $requestHash)) throw new WestyWorkflowConflict('event_key_conflict');
            $result = json_decode($prior['response_json'], true, 16, JSON_THROW_ON_ERROR);
            $result['replayed'] = true; $pdo->commit(); return $result;
        }
        if ($p['action'] === 'log') {
            $ticket = $readTicket();
            $run = $readRun();
        }
        if ($run && ($run['customer_id'] !== $p['customer_id'] || $run['alert_key'] !== $p['alert_key'] || !$ticket || (int)$run['ticket_id'] !== (int)$ticket['id'])) throw new WestyWorkflowConflict('workflow_scope_conflict');
        if ($ticket && (int)$ticket['client_id'] !== (int)$binding['client_id']) throw new WestyWorkflowConflict('ticket_customer_conflict');
        if (!$run) throw new WestyWorkflowConflict('workflow_not_found');
        $logState = westy_work_log_state($pdo, $tid, (int)$run['id'], true);
        $now = gmdate('Y-m-d H:i:s');
        $result = westy_workflow_v4_result($p, $run);
        if ($p['action'] === 'log') {
            $tail = westy_workflow_row($pdo, "SELECT seq,entry_sha256 FROM ticket_work_log WHERE tenant_id = ? AND workflow_id = ? AND source = 'milepost' ORDER BY seq DESC LIMIT 1", [$tid,$run['id']]);
            if ($p['first_seq'] !== (int)($tail['seq'] ?? 0) + 1) throw new WestyWorkflowConflict('log_sequence_gap');
            $previous = $tail['entry_sha256'] ?? westy_work_log_genesis($p['workflow_key']);
            $insert = $pdo->prepare('INSERT INTO ticket_work_log (tenant_id,ticket_id,workflow_id,source,source_event_key,seq,kind,actor_type,actor_key,summary,cause_code,evidence_sha256,milepost_ref,customer_visible,entry_sha256,occurred_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($p['entries'] as $entry) {
                $hash = westy_work_log_entry_hash($previous, $entry);
                if (!hash_equals($hash, $entry['entry_sha256'])) throw new WestyWorkflowConflict('log_chain_mismatch');
                $summary = utf8_clean($entry['summary']);
                if ($summary !== $entry['summary']) throw new InvalidArgumentException('summary_unsafe');
                $insert->execute([$tid,$ticket['id'],$run['id'],'milepost',utf8_clean($p['event_key'] . ':' . $entry['seq']),$entry['seq'],
                    utf8_clean($entry['kind']),westy_work_log_actor_type($entry['actor_key']),utf8_clean($entry['actor_key']),$summary,
                    utf8_clean($entry['cause_code']),$entry['evidence_sha256'] === null ? null : utf8_clean($entry['evidence_sha256']),
                    $entry['milepost_ref'] === null ? null : utf8_clean($entry['milepost_ref']),(int)$entry['customer_visible'],utf8_clean($hash),
                    gmdate('Y-m-d H:i:s', strtotime($entry['occurred_at'])),$now]);
                $previous = $hash;
            }
            $last = $p['entries'][count($p['entries']) - 1]['seq'];
            if ($p['sealed_seq'] !== null) {
                if (!hash_equals($previous, $p['log_sha256']) || $p['sealed_seq'] !== $last
                    || ($logState !== null && $last <= (int)$logState['log_sealed_seq'])) throw new WestyWorkflowConflict('log_seal_mismatch');
                $hash = utf8_clean($previous);
                $pdo->prepare('INSERT INTO westy_workflow_log_state (tenant_id,workflow_id,log_sha256,log_sealed_seq,updated_at)
                    VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE log_sha256 = ?,log_sealed_seq = ?,updated_at = ?')
                    ->execute([$tid,$run['id'],$hash,$last,$now,$hash,$last,$now]);
            }
            $result += ['stored_through'=>$last, 'log_sha256'=>$p['log_sha256'] ?? $logState['log_sha256'] ?? null];
        } else {
            if ($run['state'] !== 'resolved') throw new WestyWorkflowConflict('workflow_not_resolved');
            if ($logState === null || !hash_equals($logState['log_sha256'], $p['log_sha256'])
                || !is_string($run['evidence_sha256']) || !hash_equals($run['evidence_sha256'], $p['evidence_sha256'])) throw new WestyWorkflowConflict('log_seal_mismatch');
            $lateVoid = $logState['qa_state'] === 'passed' && $p['verdict'] === 'void';
            if ($logState['qa_state'] !== null && !$lateVoid) throw new WestyWorkflowConflict('qa_already_recorded');
            $eligible = "state = 'qa_pending'";
            if ($p['verdict'] !== 'passed') {
                foreach ($billingRows as $row) {
                    // A retry can move to waiting or blocked while retaining the bytes of an earlier dispatch.
                    if ($row['frozen'] || in_array($row['state'], ['sending','uncertain','accepted'], true)) {
                        throw new WestyWorkflowConflict('qa_charge_already_dispatched');
                    }
                }
                $eligible = "state IN ('qa_pending','waiting_for_time','ready') AND payload_json IS NULL AND payload_sha256 IS NULL";
            }
            [$billingState,$detail] = match ($p['verdict']) {
                'passed' => ['waiting_for_time','approved_time_required'],
                'fault_zero' => ['blocked','qa_fault_zero'],
                'void' => ['blocked','qa_void'],
            };
            $pdo->prepare('UPDATE westy_workflow_log_state SET qa_state = ?,qa_reason = ?,updated_at = ? WHERE tenant_id = ? AND workflow_id = ?')
                ->execute([utf8_clean($p['verdict']),utf8_clean($p['reason_code']),$now,$tid,$run['id']]);
            $pdo->prepare("UPDATE westy_billing_outbox SET state = ?,detail_code = ?,updated_at = ? WHERE tenant_id = ? AND workflow_id = ? AND $eligible")
                ->execute([$billingState,westy_billing_next_detail($billingRows[0]['detail_code'] ?? null, $detail),$now,$tid,$run['id']]);
            $result += ['qa_state'=>$p['verdict']];
        }
        $pdo->prepare('INSERT INTO westy_workflow_v4_receipts (tenant_id,workflow_id,event_key,request_sha256,action,response_json,received_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$tid,$run['id'],utf8_clean($p['event_key']),utf8_clean($requestHash),utf8_clean($p['action']),utf8_clean(westy_work_log_json($result)),$now]);
        $pdo->commit(); return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Local authenticated staff only. Actor identity and visibility are never form fields. */
function westy_work_log_staff(PDO $pdo, int $tenantId, int $userId, int $ticketId, array $input): int
{
    if (!csrf_valid()) throw new RuntimeException('csrf_required');
    westy_work_log_schema_ready($pdo);
    if (!in_array($input['kind'] ?? null, ['note','finding','action','verify'], true)) throw new InvalidArgumentException('invalid_kind');
    if (!westy_work_log_summary_valid($input['summary'] ?? null)) throw new InvalidArgumentException('invalid_summary');
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        $lock = westy_workflow_lock($pdo);
        if (!westy_workflow_row($pdo, 'SELECT id FROM tenants WHERE id = ?' . $lock, [$tenantId])) throw new RuntimeException('unauthorized');
        $ticket = westy_workflow_row($pdo, 'SELECT id FROM tickets WHERE tenant_id = ? AND id = ?' . $lock, [$tenantId,$ticketId]);
        if (!$ticket) throw new WestyWorkflowConflict('ticket_not_found');
        $actor = westy_workflow_row($pdo, "SELECT id FROM users WHERE tenant_id = ? AND id = ? AND is_active = 1 AND role IN ('owner','admin','tech')" . $lock, [$tenantId,$userId]);
        if (!$actor) throw new RuntimeException('unauthorized');
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes); $key = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO ticket_work_log (tenant_id,ticket_id,source,source_event_key,kind,actor_type,actor_key,summary,cause_code,customer_visible,occurred_at,created_at) VALUES (?,?,'staff',?,?,'user',?,?,'unknown',0,?,?)")
            ->execute([$tenantId,$ticketId,$key,utf8_clean($input['kind']),'safeharbor-user:'.$userId,utf8_clean(trim($input['summary'])),$now,$now]);
        $id = (int)$pdo->lastInsertId();
        // Staff work also consumes legacy alert closure when no Westy run exists (H6).
        $pdo->prepare('UPDATE tickets SET auto_close_eligible = 0 WHERE tenant_id = ? AND id = ? AND auto_close_eligible = 1')
            ->execute([$tenantId,$ticketId]);
        $pdo->commit(); return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Call the customer reader only after portal_ticket_detail has authorized the exact request. */
function westy_work_log_rows(PDO $pdo, int $tenantId, int $ticketId, ?int $clientId = null, bool $ready = false): array
{
    if (!$ready) {
        try { westy_work_log_schema_ready($pdo); }
        catch (RuntimeException $error) {
            if ($clientId !== null) error_log('[safeharbor-portal] work_log_unavailable type=' . $error::class);
            return [];
        }
    }
    if ($clientId !== null) {
        // Project only public fields, even in memory; no actor/key/hash/id reaches the renderer.
        $query = $pdo->prepare("SELECT l.occurred_at,l.summary FROM ticket_work_log l JOIN tickets t ON t.tenant_id=l.tenant_id AND t.id=l.ticket_id
            WHERE l.tenant_id=? AND l.ticket_id=? AND t.client_id=? AND l.customer_visible=1
            AND EXISTS (SELECT 1 FROM westy_email_drafts d WHERE d.tenant_id=l.tenant_id AND d.ticket_id=l.ticket_id AND d.state='submitted')
            ORDER BY l.occurred_at,l.id");
        $query->execute([$tenantId,$ticketId,$clientId]);
    } else {
        $query = $pdo->prepare('SELECT l.* FROM ticket_work_log l JOIN tickets t ON t.tenant_id=l.tenant_id AND t.id=l.ticket_id WHERE l.tenant_id=? AND l.ticket_id=? ORDER BY l.occurred_at,l.id');
        $query->execute([$tenantId,$ticketId]);
    }
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function westy_work_log_timeline(array $rows, bool $customer = false, ?array $state = null, bool $hasWorkflow = false): string
{
    if ($rows === [] && ($customer || $state === null)) return '';
    $html = '<section class="card reply" aria-label="Work log"><h2>Work log</h2>';
    if (!$customer && ($state !== null || $hasWorkflow || in_array('milepost', array_column($rows, 'source'), true))) {
        $html .= $state === null ? '<p>Not sealed yet. The log is sealed when the case ends.</p>'
            : '<p>Sealed through step ' . h((string)$state['log_sealed_seq']) . '.</p>';
        $verdict = match ($state['qa_state'] ?? null) {
            'passed' => 'passed',
            'fault_zero' => 'our fault, no charge',
            'void' => 'void',
            default => null,
        };
        if ($verdict !== null) $html .= '<p>QA: ' . h($verdict) . ' [' . h($state['qa_reason']) . ']</p>';
    }
    if ($rows === []) return $html . '</section>';
    $html .= '<ol>';
    foreach ($rows as $row) {
        $html .= '<li><p class="msg-body"><time>' . h((string)$row['occurred_at']) . ' UTC</time>';
        if (!$customer) {
            $actor = match ($row['actor_type']) { 'ai_staff'=>'AI staff', 'user'=>'Technician', default=>'System' };
            $html .= ' · ' . h(ucfirst((string)$row['kind'])) . ' · ' . h($actor) . ' (' . h((string)$row['actor_key']) . ')';
        }
        $html .= '</p><p class="msg-body">' . h((string)$row['summary']) . '</p></li>';
    }
    return $html . '</ol></section>';
}

function westy_work_log_form(bool $westyOwned, array $input = [], ?string $error = null): string
{
    $html = '<section class="card reply"><h2>Log step</h2>';
    if ($westyOwned) $html .= '<p>Logging a step takes this ticket over from Westy.</p>';
    if ($error !== null) $html .= '<p class="form-error" role="alert">' . h($error) . '</p>';
    $html .= '<form method="post">' . csrf_field() . '<input type="hidden" name="work_log_step" value="1">'
        . '<div class="field"><label for="work-log-kind">Kind</label><select id="work-log-kind" name="kind">';
    foreach (['note','finding','action','verify'] as $kind) {
        $html .= '<option value="' . h($kind) . '"' . (($input['kind'] ?? 'note') === $kind ? ' selected' : '') . '>' . h(ucfirst($kind)) . '</option>';
    }
    return $html . '</select></div><div class="field"><label for="work-log-summary">Summary</label>'
        . '<textarea id="work-log-summary" name="summary" maxlength="1000" rows="3" required>'
        . h(is_string($input['summary'] ?? null) ? $input['summary'] : '') . '</textarea></div><p>Only your team sees this step. Do not include passwords.</p>'
        . '<button class="btn-primary" type="submit">Log step</button></form></section>';
}
