<?php
/** Assignee intent is separate from ticket ownership; only the signed receiver consumes it. */
declare(strict_types=1);
require_once __DIR__ . '/westy_workflow.php';

/** Ordinary SELECT privileges expose this table/enum/index metadata. No new definer or grant. */
function westy_workflow_resume_schema_ready(PDO $pdo): void
{
    // The hermetic SQLite fixture exercises behavior; MySQL qualifies real DDL and privileges.
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        $pdo->query('SELECT create_response,cancel_response,consumed_event_key,continuation_key,pending_workflow_id FROM westy_workflow_grants LIMIT 0');
        return;
    }
    $column = westy_workflow_row($pdo, "SELECT column_type AS column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_workflow_receipts' AND column_name='action'", []);
    if (($column['column_type'] ?? '') !== "enum('claim','progress','escalate','resolve','resume')") throw new RuntimeException('workflow_v2_schema_unavailable');
    $columns = $pdo->query("SELECT column_name AS column_name,column_type AS column_type,is_nullable AS is_nullable,collation_name AS collation_name,extra AS extra,generation_expression AS generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='westy_workflow_grants'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $types = [
        'id'=>'bigint unsigned','tenant_id'=>'int unsigned','workflow_id'=>'bigint unsigned',
        'client_id'=>'int unsigned','customer_id'=>'char(36)','ticket_id'=>'int unsigned',
        'workflow_key'=>'char(36)','alert_key'=>'varchar(64)','grant_key'=>'char(36)',
        'assignee_id'=>'int unsigned','expected_version'=>'int unsigned','create_sha256'=>'char(64)',
        'create_response'=>'json','created_at'=>'datetime','cancelled_by'=>'int unsigned',
        'cancelled_at'=>'datetime','cancel_sha256'=>'char(64)','cancel_response'=>'json',
        'consumed_event_key'=>'char(36)','continuation_key'=>'char(36)','consumed_at'=>'datetime',
        'pending_workflow_id'=>'bigint unsigned',
    ];
    $nullable = ['cancelled_by','cancelled_at','cancel_sha256','cancel_response','consumed_event_key','continuation_key','consumed_at','pending_workflow_id'];
    foreach ($types as $name=>$type) {
        $c = $columns[$name] ?? [];
        if (($c['column_type'] ?? '') !== $type || ($c['is_nullable'] ?? '') !== (in_array($name,$nullable,true) ? 'YES' : 'NO')
            || (str_contains($type,'char(') && ($c['collation_name'] ?? '') !== 'ascii_bin')) throw new RuntimeException('workflow_v2_schema_unavailable');
    }
    $expression = preg_replace('/[\s`()]/', '', strtolower((string)($columns['pending_workflow_id']['generation_expression'] ?? '')));
    if (($columns['id']['extra'] ?? '') !== 'auto_increment'
        || ($columns['pending_workflow_id']['extra'] ?? '') !== 'STORED GENERATED'
        || $expression !== 'casewhencancelled_atisnullandconsumed_atisnullthenworkflow_idelsenullend') throw new RuntimeException('workflow_v2_schema_unavailable');
    $indexes = [];
    foreach ($pdo->query("SELECT index_name AS index_name,column_name AS column_name,seq_in_index AS seq_in_index,non_unique AS non_unique,sub_part AS sub_part FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='westy_workflow_grants' ORDER BY index_name,seq_in_index") as $i) {
        if ($i['sub_part'] !== null) throw new RuntimeException('workflow_v2_schema_unavailable');
        $indexes[$i['index_name']][] = [(string)$i['column_name'],(int)$i['non_unique']];
    }
    foreach (['PRIMARY'=>['id'],'uq_westy_grant_key'=>['tenant_id','grant_key'],
        'uq_westy_grant_pending'=>['tenant_id','pending_workflow_id'],
        'uq_westy_grant_event'=>['tenant_id','consumed_event_key'],
        'uq_westy_grant_continuation'=>['tenant_id','continuation_key'],
        'ix_westy_grant_history'=>['tenant_id','workflow_id','id']] as $name=>$names) {
        $expected = array_map(static fn(string $n): array => [$n,$name === 'ix_westy_grant_history' ? 1 : 0],$names);
        if (($indexes[$name] ?? []) !== $expected) throw new RuntimeException('workflow_v2_schema_unavailable');
    }
    $table = westy_workflow_row($pdo,"SELECT engine AS engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='westy_workflow_grants'",[]);
    if (($table['engine'] ?? '') !== 'InnoDB') throw new RuntimeException('workflow_v2_schema_unavailable');
    $foreign = [];
    foreach ($pdo->query("SELECT constraint_name AS constraint_name,column_name AS column_name,referenced_table_name AS referenced_table_name,referenced_column_name AS referenced_column_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='westy_workflow_grants' AND referenced_table_name IS NOT NULL ORDER BY constraint_name,ordinal_position") as $f) {
        $foreign[$f['constraint_name']][] = [$f['column_name'],$f['referenced_table_name'],$f['referenced_column_name']];
    }
    if (($foreign['fk_westy_grant_workflow'] ?? []) !== [['tenant_id','westy_workflows','tenant_id'],['workflow_id','westy_workflows','id']]
        || ($foreign['fk_westy_grant_receipt'] ?? []) !== [['tenant_id','westy_workflow_receipts','tenant_id'],['consumed_event_key','westy_workflow_receipts','event_key']]) throw new RuntimeException('workflow_v2_schema_unavailable');
}

function westy_workflow_grant_request(string $raw): array
{
    if ($raw === '' || strlen($raw) > 2048) throw new InvalidArgumentException('invalid_body');
    try { $p = json_decode($raw,true,3,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new InvalidArgumentException('invalid_json'); }
    if (!is_array($p) || array_is_list($p)) throw new InvalidArgumentException('invalid_payload');
    $keys = array_keys($p); sort($keys);
    $expected = ['action','ticket_id','workflow_key','expected_version','grant_key']; sort($expected);
    $withoutStrings = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s','""',$raw);
    if ($keys !== $expected || substr_count((string)$withoutStrings,':') !== 5) throw new InvalidArgumentException('invalid_fields');
    if (!in_array($p['action'],['create','cancel'],true)) throw new InvalidArgumentException('invalid_action');
    foreach (['ticket_id','expected_version'] as $key) {
        if (!is_int($p[$key]) || $p[$key] < 1) throw new InvalidArgumentException('invalid_' . $key);
    }
    foreach (['workflow_key','grant_key'] as $key) {
        if (!is_string($p[$key]) || preg_match(WESTY_WORKFLOW_UUID,$p[$key]) !== 1) throw new InvalidArgumentException('invalid_' . $key);
    }
    return $p;
}

function westy_workflow_staff(PDO $pdo, int $tenantId, int $actorId, bool $lock = true): ?array
{
    return westy_workflow_row($pdo,"SELECT id FROM users WHERE tenant_id=? AND id=? AND is_active=1 AND role IN ('owner','admin','tech')" . ($lock ? westy_workflow_lock($pdo) : ''),[$tenantId,$actorId]);
}

/** This eligibility never widens human_owned into a supported return path. */
function westy_workflow_return_eligible(array $run, array $ticket, array $binding, ?array $assignee): bool
{
    return $run['state'] === 'needs_human' && (int)$run['version'] > 0
        && in_array($ticket['status'],['open','in_progress','waiting'],true)
        && $ticket['merged_into_id'] === null && $ticket['resolved_at'] === null
        && $run['closed_at'] === null && $run['evidence_sha256'] === null
        && $run['job_id'] === null && $run['job_completed_at'] === null && $run['alert_resolved_at'] === null
        && $assignee !== null && (int)$ticket['assignee_id'] === (int)$assignee['id']
        && (int)$run['ticket_id'] === (int)$ticket['id'] && $run['alert_key'] === $ticket['external_key']
        && (int)$run['client_id'] === (int)$ticket['client_id'] && (int)$run['client_id'] === (int)$binding['client_id']
        && ($binding['status'] ?? 'active') === 'active';
}

function westy_workflow_grant_matches(array $grant, array $run, array $ticket): bool
{
    foreach (['tenant_id','client_id','ticket_id','workflow_key','customer_id','alert_key'] as $key) {
        if ((string)$grant[$key] !== (string)$run[$key]) return false;
    }
    return (int)$grant['workflow_id'] === (int)$run['id']
        && (int)$grant['expected_version'] === (int)$run['version']
        && (int)$grant['assignee_id'] === (int)$ticket['assignee_id']
        && $grant['cancelled_at'] === null && $grant['consumed_at'] === null;
}

function westy_workflow_has_closure(PDO $pdo, int $tenantId, int $workflowId): bool
{
    return westy_workflow_row($pdo,"SELECT id FROM westy_workflow_receipts WHERE tenant_id=? AND workflow_id=? AND action='resolve' LIMIT 1",[$tenantId,$workflowId]) !== null
        || westy_workflow_row($pdo,'SELECT id FROM westy_billing_outbox WHERE tenant_id=? AND workflow_id=? LIMIT 1',[$tenantId,$workflowId]) !== null;
}

function westy_workflow_grant_result(array $grant, string $action): array
{
    return ['ok'=>true,'action'=>$action,'ticket_id'=>(int)$grant['ticket_id'],'workflow_key'=>$grant['workflow_key'],
        'grant_key'=>$grant['grant_key'],'expected_version'=>(int)$grant['expected_version'],
        'assignee_id'=>(int)$grant['assignee_id'],'grant_state'=>$action === 'create' ? 'pending' : 'cancelled',
        'created_at'=>str_replace(' ','T',$grant['created_at']).'Z',
        'cancelled_at'=>$action === 'create' ? null : str_replace(' ','T',$grant['cancelled_at']).'Z'];
}

/** Canonical UI request plus authenticated actor. Results are frozen, including on later retry. */
function westy_workflow_grant_action(PDO $pdo, int $tenantId, int $actorId, array $p): array
{
    westy_workflow_schema_ready($pdo); westy_workflow_resume_schema_ready($pdo);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        $lock = westy_workflow_lock($pdo);
        if (!westy_workflow_row($pdo,'SELECT id FROM tenants WHERE id=?'.$lock,[$tenantId])) throw new WestyWorkflowConflict('ticket_not_found');
        // The identity columns cannot change. Read them to acquire binding before ticket/run.
        $identity = westy_workflow_row($pdo,'SELECT customer_id FROM westy_workflows WHERE tenant_id=? AND ticket_id=? AND workflow_key=?',[$tenantId,$p['ticket_id'],$p['workflow_key']]);
        if (!$identity) throw new WestyWorkflowConflict('workflow_not_found');
        $binding = westy_workflow_row($pdo,'SELECT client_id,status FROM suite_customer_sync_bindings WHERE tenant_id=? AND customer_id=?'.$lock,[$tenantId,$identity['customer_id']]);
        $ticket = westy_workflow_row($pdo,'SELECT * FROM tickets WHERE tenant_id=? AND id=?'.$lock,[$tenantId,$p['ticket_id']]);
        $run = westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=? AND workflow_key=?'.$lock,[$tenantId,$p['workflow_key']]);
        if (!$ticket || !$run || (int)$run['ticket_id'] !== $p['ticket_id']) throw new WestyWorkflowConflict('workflow_scope_conflict');
        $actor = $p['action'] === 'cancel'
            ? westy_workflow_row($pdo,'SELECT id FROM users WHERE tenant_id=? AND id=? AND is_active=1'.$lock,[$tenantId,$actorId])
            : westy_workflow_staff($pdo,$tenantId,$actorId);
        if (!$actor) throw new WestyWorkflowConflict('assignee_unavailable');
        $grant = westy_workflow_row($pdo,'SELECT * FROM westy_workflow_grants WHERE tenant_id=? AND grant_key=?'.$lock,[$tenantId,$p['grant_key']]);
        $bound = $grant ?? $run;
        $canonical = ['tenant_id'=>$tenantId,'actor_id'=>$actorId,'client_id'=>(int)$bound['client_id'],
            'customer_id'=>$bound['customer_id'],'alert_key'=>$bound['alert_key']];
        foreach (['action','ticket_id','workflow_key','expected_version','grant_key'] as $key) $canonical[$key] = $p[$key];
        $hash = hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR));
        if ($grant) {
            if ((int)$grant['assignee_id'] !== $actorId || (int)$grant['ticket_id'] !== $p['ticket_id']
                || $grant['workflow_key'] !== $p['workflow_key'] || (int)$grant['expected_version'] !== $p['expected_version']) throw new WestyWorkflowConflict('grant_key_conflict');
            $column = $p['action'] === 'create' ? 'create' : 'cancel';
            if ($grant[$column.'_response'] !== null) {
                if (!hash_equals($grant[$column.'_sha256'],$hash)) throw new WestyWorkflowConflict('grant_key_conflict');
                $result = json_decode($grant[$column.'_response'],true,8,JSON_THROW_ON_ERROR);
                $pdo->commit(); return $result;
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($p['action'] === 'cancel') {
            if (!$grant || $grant['consumed_at'] !== null) throw new WestyWorkflowConflict('grant_not_cancellable');
            $grant['cancelled_at'] = $now;
            $result = westy_workflow_grant_result($grant,'cancel');
            $pdo->prepare('UPDATE westy_workflow_grants SET cancelled_by=?,cancelled_at=?,cancel_sha256=?,cancel_response=? WHERE tenant_id=? AND id=?')
                ->execute([$actorId,$now,$hash,json_encode($result,JSON_THROW_ON_ERROR),$tenantId,$grant['id']]);
        } else {
            if (!$binding || !westy_workflow_return_eligible($run,$ticket,$binding,$actor)
                || !managed_customer_operational($pdo,$tenantId,(int)$binding['client_id'],true)
                || westy_workflow_has_closure($pdo,$tenantId,(int)$run['id'])) throw new WestyWorkflowConflict('return_not_available');
            if ((int)$run['version'] !== $p['expected_version']) throw new WestyWorkflowConflict('version_conflict');
            if (westy_workflow_row($pdo,'SELECT id FROM westy_workflow_grants WHERE tenant_id=? AND pending_workflow_id=? ORDER BY id'.$lock,[$tenantId,$run['id']])) throw new WestyWorkflowConflict('pending_grant_exists');
            $grant = $run + ['created_at'=>$now];
            $grant['grant_key'] = $p['grant_key']; $grant['assignee_id'] = $actorId; $grant['expected_version'] = $p['expected_version'];
            $result = westy_workflow_grant_result($grant,'create');
            $pdo->prepare('INSERT INTO westy_workflow_grants (tenant_id,workflow_id,client_id,customer_id,ticket_id,workflow_key,alert_key,grant_key,assignee_id,expected_version,create_sha256,create_response,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tenantId,$run['id'],$run['client_id'],$run['customer_id'],$run['ticket_id'],$run['workflow_key'],$run['alert_key'],$p['grant_key'],$actorId,$p['expected_version'],$hash,json_encode($result,JSON_THROW_ON_ERROR),$now]);
        }
        $pdo->commit(); return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Validate immutable storage for both status and replay; never infer proof from current ownership. */
function westy_workflow_original_receipt(array $run, array $accepted, string $tenantSlug): array
{
    foreach (['tenant_id','client_id','customer_id','ticket_id','workflow_key','alert_key'] as $key) {
        if ((string)$accepted[$key] !== (string)$run[$key]) throw new RuntimeException('workflow_v2_receipt_unavailable');
    }
    if ((int)$accepted['workflow_id'] !== (int)$run['id'] || (int)$accepted['expected_version'] < 1
        || (int)$accepted['version'] !== (int)$accepted['expected_version']+1
        || $accepted['consumed_event_key'] !== $accepted['event_key'] || $accepted['cancelled_at'] !== null
        || $accepted['consumed_at'] !== $accepted['received_at']) throw new RuntimeException('workflow_v2_receipt_unavailable');
    $original = json_decode($accepted['response_json'],true,16,JSON_THROW_ON_ERROR);
    $expected = ['schema_version'=>2] + westy_workflow_result(array_replace($run,['version'=>(int)$accepted['version'],'state'=>'working']),'resume',$accepted['event_key'])
        + ['event_key'=>$accepted['event_key'],'tenant_slug'=>$tenantSlug,'customer_id'=>$run['customer_id'],'alert_key'=>$run['alert_key'],
            'grant_key'=>$accepted['grant_key'],'continuation_key'=>$accepted['continuation_key'],
            'accepted_at'=>str_replace(' ','T',$accepted['received_at']).'Z','request_sha256'=>$accepted['request_sha256']];
    if (!is_array($original) || count($original) !== count($expected)) throw new RuntimeException('workflow_v2_receipt_unavailable');
    foreach ($expected as $key=>$value) {
        if (!array_key_exists($key,$original) || $original[$key] !== $value) throw new RuntimeException('workflow_v2_receipt_unavailable');
    }
    return $original;
}

function westy_workflow_v2_replay(PDO $pdo, int $tenantId, array $p, string $requestHash): array
{
    $run = westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=? AND workflow_key=?',[$tenantId,$p['workflow_key']]);
    $accepted = westy_workflow_row($pdo,"SELECT g.*,r.event_key,r.version,r.received_at,r.request_sha256,r.response_json FROM westy_workflow_grants g JOIN westy_workflow_receipts r ON r.tenant_id=g.tenant_id AND r.workflow_id=g.workflow_id AND r.event_key=g.consumed_event_key AND r.action='resume' WHERE g.tenant_id=? AND g.consumed_event_key=?",[$tenantId,$p['event_key']]);
    if (!$run || !$accepted || $p['action'] !== 'resume' || $accepted['grant_key'] !== $p['grant_key']
        || $accepted['continuation_key'] !== $p['continuation_key'] || (int)$accepted['expected_version'] !== $p['expected_version']
        || $run['customer_id'] !== $p['customer_id'] || $run['alert_key'] !== $p['alert_key']
        || !hash_equals($accepted['request_sha256'],$requestHash)) throw new RuntimeException('workflow_v2_receipt_unavailable');
    return westy_workflow_original_receipt($run,$accepted,$p['tenant_slug']);
}

/** Called under the service transaction's tenant/binding/ticket/workflow locks. No writes. */
function westy_workflow_v2_status(PDO $pdo, int $tenantId, array $binding, array $ticket, array $run, array $p): array
{
    if ((int)$run['client_id'] !== (int)$binding['client_id']) throw new WestyWorkflowConflict('workflow_scope_conflict');
    $actor = westy_workflow_staff($pdo,$tenantId,(int)$ticket['assignee_id']);
    $pending = westy_workflow_row($pdo,'SELECT * FROM westy_workflow_grants WHERE tenant_id=? AND pending_workflow_id=? ORDER BY id'.westy_workflow_lock($pdo),[$tenantId,$run['id']]);
    $available = $pending && westy_workflow_return_eligible($run,$ticket,$binding,$actor) && westy_workflow_grant_matches($pending,$run,$ticket)
        && !westy_workflow_has_closure($pdo,$tenantId,(int)$run['id']);
    $accepted = westy_workflow_row($pdo,"SELECT g.*,r.event_key,r.version,r.received_at,r.request_sha256,r.response_json FROM westy_workflow_grants g JOIN westy_workflow_receipts r ON r.tenant_id=g.tenant_id AND r.workflow_id=g.workflow_id AND r.event_key=g.consumed_event_key AND r.action='resume' WHERE g.tenant_id=? AND g.workflow_id=? AND g.consumed_at IS NOT NULL ORDER BY r.version DESC LIMIT 1",[$tenantId,$run['id']]);
    $original = $accepted ? westy_workflow_original_receipt($run,$accepted,$p['tenant_slug']) : null;
    return ['schema_version'=>2] + westy_workflow_result($run,'status',null)
        + array_intersect_key($p,array_flip(['event_key','tenant_slug','customer_id','alert_key']))
        + ['binding'=>['client_id'=>(int)$binding['client_id'],'status'=>'active'],
            'ticket'=>['client_id'=>(int)$ticket['client_id'],'status'=>$ticket['status'],
                'assignee_id'=>$ticket['assignee_id'] === null ? null : (int)$ticket['assignee_id'],
                'merged_into_id'=>$ticket['merged_into_id'] === null ? null : (int)$ticket['merged_into_id'],
                'resolved_at'=>$ticket['resolved_at'] === null ? null : str_replace(' ','T',$ticket['resolved_at']).'Z'],
            'pending_grant'=>$available ? ['grant_key'=>$pending['grant_key'],'assignee_id'=>(int)$pending['assignee_id'],
                'expected_version'=>(int)$pending['expected_version'],'created_at'=>str_replace(' ','T',$pending['created_at']).'Z'] : null,
            'accepted_continuation'=>$accepted ? ['grant_key'=>$accepted['grant_key'],'continuation_key'=>$accepted['continuation_key'],
                'event_key'=>$accepted['event_key'],'version'=>(int)$accepted['version'],'accepted_at'=>str_replace(' ','T',$accepted['received_at']).'Z',
                'request_sha256'=>$accepted['request_sha256'],'original_receipt'=>$original] : null];
}

/** Consume once, preserving original identity/history and accounting for our own ticket trigger. */
function westy_workflow_resume(PDO $pdo, int $tenantId, array $binding, ?array $ticket, ?array $run, array $p, string $requestHash, string $now): array
{
    if (!$run || !$ticket) throw new WestyWorkflowConflict('workflow_not_found');
    if ((int)$run['version'] !== $p['expected_version']) throw new WestyWorkflowConflict('version_conflict');
    $actor = westy_workflow_staff($pdo,$tenantId,(int)$ticket['assignee_id']);
    if (!westy_workflow_return_eligible($run,$ticket,$binding,$actor)
        || westy_workflow_has_closure($pdo,$tenantId,(int)$run['id'])) throw new WestyWorkflowConflict('return_not_available');
    $grant = westy_workflow_row($pdo,'SELECT * FROM westy_workflow_grants WHERE tenant_id=? AND grant_key=?'.westy_workflow_lock($pdo),[$tenantId,$p['grant_key']]);
    if (!$grant || !westy_workflow_grant_matches($grant,$run,$ticket)) throw new WestyWorkflowConflict('grant_conflict');
    if (westy_workflow_row($pdo,'SELECT id FROM westy_workflow_grants WHERE tenant_id=? AND continuation_key=?',[$tenantId,$p['continuation_key']])) throw new WestyWorkflowConflict('continuation_key_conflict');
    $pdo->prepare("UPDATE tickets SET status='in_progress',assignee_id=NULL,auto_close_eligible=0 WHERE tenant_id=? AND id=?")->execute([$tenantId,$ticket['id']]);
    // The existing trigger has advanced needs_human -> human_owned, V -> V+1.
    // Assign the original V+1, not a second increment, in this same transaction.
    $run['version'] = $p['expected_version'] + 1; $run['state'] = 'working';
    $summary = function_exists('utf8_clean') ? utf8_clean($p['summary']) : $p['summary'];
    $pdo->prepare("UPDATE westy_workflows SET state='working',version=?,summary=?,updated_at=? WHERE tenant_id=? AND id=?")
        ->execute([$run['version'],$summary,$now,$tenantId,$run['id']]);
    $pdo->prepare('INSERT INTO messages(ticket_id,author_name,kind,body) VALUES (?,?,?,?)')
        ->execute([$ticket['id'],'Westy workflow','system','Technician #'.$grant['assignee_id'].' requested return to Westy (grant '.$grant['grant_key'].'). Milepost confirmed the prior commands were not dispatched. Westy resume: '.$summary]);
    $result = ['schema_version'=>2] + westy_workflow_result($run,'resume',$p['event_key'])
        + array_intersect_key($p,array_flip(['event_key','tenant_slug','customer_id','alert_key','grant_key','continuation_key']))
        + ['accepted_at'=>str_replace(' ','T',$now).'Z','request_sha256'=>$requestHash];
    $pdo->prepare('INSERT INTO westy_workflow_receipts(tenant_id,workflow_id,event_key,request_sha256,action,version,response_json,received_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$tenantId,$run['id'],$p['event_key'],$requestHash,'resume',$run['version'],json_encode($result,JSON_THROW_ON_ERROR),$now]);
    $pdo->prepare('UPDATE westy_workflow_grants SET consumed_event_key=?,continuation_key=?,consumed_at=? WHERE tenant_id=? AND id=?')
        ->execute([$p['event_key'],$p['continuation_key'],$now,$tenantId,$grant['id']]);
    return $result;
}

/** Read-only projection for this signed-in viewer. The POST rechecks every fact under locks. */
function westy_workflow_grant_controls(PDO $pdo, array $run, array $ticket, array $user, bool $enabled): array
{
    try {
        westy_workflow_schema_ready($pdo); westy_workflow_resume_schema_ready($pdo);
        $tid = (int)$user['tenant_id'];
        if ((int)$run['tenant_id'] !== $tid || (int)$ticket['tenant_id'] !== $tid) return [];
        $actor = westy_workflow_staff($pdo,$tid,(int)$user['id'],false);
        $grant = westy_workflow_row($pdo,'SELECT * FROM westy_workflow_grants WHERE tenant_id=? AND pending_workflow_id=?',[$tid,$run['id']]);
        if ($grant) {
            return ['grant_control'=>(int)$grant['assignee_id'] === (int)$user['id'] ? 'cancel' : 'pending',
                'grant_key'=>$grant['grant_key'],'grant_version'=>(int)$grant['expected_version']];
        }
        $binding = westy_workflow_row($pdo,'SELECT client_id,status FROM suite_customer_sync_bindings WHERE tenant_id=? AND customer_id=?',[$tid,$run['customer_id']]);
        if (!$enabled || !$binding || !westy_workflow_return_eligible($run,$ticket,$binding,$actor)
            || !managed_customer_operational($pdo,$tid,(int)$binding['client_id'])
            || westy_workflow_has_closure($pdo,$tid,(int)$run['id'])) return [];
        return ['grant_control'=>'create','grant_version'=>(int)$run['version']];
    } catch (Throwable $error) {
        // Pre-migration tickets and ordinary human work stay usable. The POST/service
        // explicitly refuse missing v2 capability; they never silently accept v1.
        error_log('Westy grant controls unavailable: ' . $error::class);
        return [];
    }
}
