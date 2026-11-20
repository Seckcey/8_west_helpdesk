<?php
/** Verified closure -> AI service, covered service, or approved-time invoice review. */
declare(strict_types=1);
require_once __DIR__ . '/westy_work_log.php';
const WESTY_BILLING_ENDPOINT = 'https://coastmark.8westit.com/api/integrations/safeharbor/billing-handoffs';
const WESTY_BILLING_EXCLUDED_CUSTOMER = '4ebaeefa-b101-47f8-ac76-e49ab309d272';

function westy_billing_settings(array $config): array
{
    if (($config['enabled'] ?? false) !== true || ($config['endpoint'] ?? '') !== WESTY_BILLING_ENDPOINT
        || ($config['service'] ?? '') !== 'safeharbor-billing'
        || !is_string($config['secret'] ?? null) || strlen($config['secret']) < 32
        || !is_array($config['tenant_slugs'] ?? null) || !$config['tenant_slugs']
        || !is_array($config['customer_ids'] ?? null) || !$config['customer_ids']) throw new RuntimeException('billing_handoff_unavailable');
    foreach ($config['tenant_slugs'] as $slug) if (!is_string($slug) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D',$slug)!==1) throw new RuntimeException('billing_handoff_unavailable');
    foreach ($config['customer_ids'] as $id) if (!is_string($id) || preg_match(WESTY_WORKFLOW_UUID,$id)!==1) throw new RuntimeException('billing_handoff_unavailable');
    $config['ai_service']??=['enabled'=>false,'customer_ids'=>[]];
    if (($config['ai_service']['enabled']??false)===true) {
        $ids=$config['ai_service']['customer_ids']??null;
        if (!is_array($ids) || !array_is_list($ids)) throw new RuntimeException('billing_handoff_unavailable');
        foreach ($ids as $id) if (!is_string($id) || preg_match(WESTY_WORKFLOW_UUID,$id)!==1) throw new RuntimeException('billing_handoff_unavailable');
    }
    $hours = $config['qa_release_hours'] ?? 72;
    if (is_string($hours) && preg_match('/\A[+-]?[0-9]+\z/D', trim($hours))) $hours = (int)$hours;
    $config['qa_release_hours'] = is_int($hours) ? max(48, min(336, $hours)) : 72;
    return $config;
}

/** Read the exact SQL UTC second. DateTime's normalization of invalid dates must never change evidence. */
function westy_billing_ai_time(mixed $stored): DateTimeImmutable
{
    if (!is_string($stored) || str_contains($stored,"\0")) throw new RuntimeException('ai_service_evidence_conflict');
    $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$stored,new DateTimeZone('UTC'));
    if (!$time || $time->format('Y-m-d H:i:s')!==$stored) throw new RuntimeException('ai_service_evidence_conflict');
    return $time;
}

/** Called only for an enabled, resolved workflow with no matching time. The caller holds the tenant lock. */
function westy_billing_ai_service(PDO $pdo,array $run,array $outbox): ?array
{
    $scope=[$run['tenant_id'],$run['id']];
    $seal=westy_work_log_state($pdo,(int)$run['tenant_id'],(int)$run['id']);
    $verdict=$seal['qa_state']??null;
    if (in_array($verdict,['fault_zero','void'],true)) throw new RuntimeException('ai_service_not_chargeable');
    if (westy_billing_released_without_review($outbox['detail_code']??null)) return null;
    if ($verdict===null) throw new RuntimeException('qa_review_required');
    if ($verdict!=='passed') throw new RuntimeException('ai_service_evidence_conflict');

    $receipts=$pdo->prepare("SELECT response_json,request_sha256,received_at FROM westy_workflow_v4_receipts
        WHERE tenant_id=? AND workflow_id=? AND action='qa'");
    $receipts->execute($scope);
    $passed=null;
    while ($receipt=$receipts->fetch(PDO::FETCH_ASSOC)) {
        $result=json_decode($receipt['response_json'],true);
        if (!isset($result['qa_state'])) throw new RuntimeException('ai_service_evidence_conflict');
        if ($result['qa_state']!=='passed') continue;
        if ($passed!==null) throw new RuntimeException('ai_service_evidence_conflict');
        $passed=$receipt;
    }
    if (!$passed || !westy_work_log_hash($seal['log_sha256'])
        || !westy_work_log_hash($passed['request_sha256'])) throw new RuntimeException('ai_service_evidence_conflict');
    $resolved=westy_billing_ai_time($run['alert_resolved_at']);
    $closed=westy_billing_ai_time($run['closed_at']);
    $checked=westy_billing_ai_time($passed['received_at']);
    if ($closed<$resolved || $checked->getTimestamp()-$resolved->getTimestamp()<86400
        || max($closed->getTimestamp(),$checked->getTimestamp())>time()+300) throw new RuntimeException('ai_service_evidence_conflict');

    $refs=$pdo->prepare("SELECT milepost_ref FROM ticket_work_log
        WHERE tenant_id=? AND workflow_id=? AND ticket_id=? AND source='milepost' AND kind='verify' AND seq<=?");
    $refs->execute([...$scope,$run['ticket_id'],$seal['log_sealed_seq']]);
    $codes=[];
    foreach ($refs->fetchAll(PDO::FETCH_COLUMN) as $ref) {
        if (is_string($ref) && preg_match('/;service:([a-z][a-z0-9_]{0,63})\z/D',$ref,$match)===1) $codes[$match[1]]=true;
    }
    if (count($codes)>20) throw new RuntimeException('ai_service_evidence_conflict');
    if (!$codes) return null;
    $codes=array_keys($codes); sort($codes,SORT_STRING);
    $utc='Y-m-d\TH:i:s\Z';
    return ['version'=>3,'event'=>'safeharbor.ticket.ai_service_completed','event_key'=>$outbox['event_key'],
        'tenant_key'=>$run['tenant_slug'],'client_key'=>'milepost-customer:'.$run['customer_id'],
        'ticket_id'=>(int)$run['ticket_id'],'run_key'=>$run['workflow_key'],'resolved_at'=>$resolved->format($utc),
        'closed_at'=>$closed->format($utc),'qa_checked_at'=>$checked->format($utc),'verification_sha256'=>$run['evidence_sha256'],
        'log_sha256'=>$seal['log_sha256'],'qa_sha256'=>$passed['request_sha256'],'qa_state'=>'passed','service_codes'=>$codes];
}

/** All three versions share the same customer, closure, and time-entry checks. */
function westy_billing_payload(PDO $pdo, array $run, array $outbox, array $config): array
{
    if ($run['customer_id']===WESTY_BILLING_EXCLUDED_CUSTOMER || !in_array($run['tenant_slug'],$config['tenant_slugs'],true)
        || !in_array($run['customer_id'],$config['customer_ids'],true)) throw new RuntimeException('billing_scope_not_enabled');
    $lock=westy_workflow_lock($pdo);
    $ticket=westy_workflow_row($pdo,'SELECT status,resolved_at,client_id,merged_into_id FROM tickets WHERE tenant_id=? AND id=?'.$lock,[$run['tenant_id'],$run['ticket_id']]);
    $binding=westy_workflow_row($pdo,'SELECT client_id FROM suite_customer_sync_bindings WHERE tenant_id=? AND customer_id=? AND status=?'.$lock,[$run['tenant_id'],$run['customer_id'],'active']);
    if (!$ticket || !$binding || $ticket['status']!=='resolved' || !$run['closed_at'] || $ticket['resolved_at']!==$run['closed_at']
        || (int)$ticket['client_id']!==(int)$run['client_id'] || (int)$binding['client_id']!==(int)$run['client_id'] || $ticket['merged_into_id']!==null) throw new RuntimeException('verified_closed_ticket_required');
    $query=$pdo->prepare('SELECT id,approval_status,billable FROM time_entries WHERE tenant_id=? AND client_id=? AND ticket_id=? ORDER BY id LIMIT 101'.$lock);
    $query->execute([$run['tenant_id'],$run['client_id'],$run['ticket_id']]); $entries=$query->fetchAll(PDO::FETCH_ASSOC);
    $aiCandidate=!$entries && ($run['state']??null)==='resolved' && ($config['ai_service']['enabled']??false)===true
        && in_array($run['customer_id'],$config['ai_service']['customer_ids'],true);
    if (!westy_work_log_hash($run['evidence_sha256'])) {
        $reason=$aiCandidate && !westy_billing_released_without_review($outbox['detail_code']??null) ? 'ai_service_evidence_conflict' : 'verified_closed_ticket_required';
        throw new RuntimeException($reason);
    }
    if ($aiCandidate) {
        // A historical row filed under a different client is still time on this ticket.
        if (westy_workflow_row($pdo,'SELECT id FROM time_entries WHERE tenant_id=? AND ticket_id=? LIMIT 1',[$run['tenant_id'],$run['ticket_id']])) throw new RuntimeException('time_source_scope_conflict');
        $service=westy_billing_ai_service($pdo,$run,$outbox);
        if ($service!==null) return $service;
    }
    if (count($entries)>100) throw new RuntimeException('billing_time_limit_requires_review');
    $keys=[];
    foreach ($entries as $entry) {
        if ($entry['approval_status']==='pending') throw new RuntimeException('time_approval_required');
        if ($entry['approval_status']!=='approved' || (int)$entry['billable']!==1) continue;
        $adjustment=westy_workflow_row($pdo,'SELECT MAX(version_no) AS version FROM time_entry_approval_adjustments WHERE tenant_id=? AND time_entry_id=?',[$run['tenant_id'],$entry['id']]);
        $version=(int)($adjustment['version']??0);
        $claim=westy_workflow_row($pdo,'SELECT id,event_key,payload_json,payload_sha256,source_version FROM coastmark_time_export_claims WHERE tenant_id=? AND time_entry_id=? AND source_version=?'.$lock,[$run['tenant_id'],$entry['id'],$version]);
        if (!$claim) throw new RuntimeException('current_time_export_required');
        $receipt=westy_workflow_row($pdo,'SELECT outcome,invoice_id FROM coastmark_time_export_receipts WHERE tenant_id=? AND claim_id=? ORDER BY id DESC LIMIT 1'.$lock,[$run['tenant_id'],$claim['id']]);
        if (!$receipt || !in_array($receipt['outcome'],['accepted','replayed'],true) || (int)$receipt['invoice_id']<1) throw new RuntimeException('time_delivery_verification_required');
        if (!hash_equals($claim['payload_sha256'],hash('sha256',$claim['payload_json']))) throw new RuntimeException('time_source_integrity_required');
        $source=json_decode($claim['payload_json'],true,16,JSON_THROW_ON_ERROR);
        if (($source['version']??null)!==3 || ($source['tenant_key']??'')!==$run['tenant_slug']
            || ($source['client_key']??'')!=='milepost-customer:'.$run['customer_id'] || ($source['ticket_id']??0)!==(int)$run['ticket_id']
            || ($source['entry_id']??0)!==(int)$entry['id'] || ($source['source_version']??-1)!==$version
            || ($source['approval_status']??'')!=='approved' || ($source['event_key']??'')!==$claim['event_key']
            || !westy_workflow_date($source['worked_at']??null) || $source['worked_at']>str_replace(' ','T',$run['closed_at']).'Z') throw new RuntimeException('time_source_scope_conflict');
        $keys[]=$claim['event_key'];
    }
    if (!$keys) {
        // Human time, even nonbillable/rejected time, needs a human billing decision.
        // Coverage comes from an explicit policy in both apps; never from model text.
        $coverage=$config['included_service']['policies'][$run['tenant_slug']][$run['customer_id']]??null;
        if (($config['included_service']['enabled']??false)!==true || !is_array($coverage)) throw new RuntimeException('approved_billable_time_required');
        if (($run['state']??null)!=='resolved') throw new RuntimeException('included_service_workflow_changed');
        if ($entries) throw new RuntimeException('included_service_has_human_time');
        if (($coverage['enabled']??false)!==true || !is_string($coverage['coverage_key']??null)
            || preg_match(WESTY_WORKFLOW_UUID,$coverage['coverage_key'])!==1
            || !is_int($coverage['coverage_revision']??null) || $coverage['coverage_revision']<1
            || ($coverage['service_code']??null)!=='routine_support') throw new RuntimeException('included_service_policy_required');
        return ['version'=>2,'event'=>'safeharbor.ticket.service_completed','event_key'=>$outbox['event_key'],
            'tenant_key'=>$run['tenant_slug'],'client_key'=>'milepost-customer:'.$run['customer_id'],
            'ticket_id'=>(int)$run['ticket_id'],'run_key'=>$run['workflow_key'],
            'resolved_at'=>str_replace(' ','T',$run['closed_at']).'Z','closed_at'=>str_replace(' ','T',$run['closed_at']).'Z',
            'verification_sha256'=>$run['evidence_sha256'],'coverage_key'=>$coverage['coverage_key'],
            'coverage_revision'=>$coverage['coverage_revision'],'service_code'=>$coverage['service_code']];
    }
    sort($keys,SORT_STRING);
    return ['version'=>1,'event'=>'safeharbor.ticket.billing_requested','event_key'=>$outbox['event_key'],
        'tenant_key'=>$run['tenant_slug'],'client_key'=>'milepost-customer:'.$run['customer_id'],
        'ticket_id'=>(int)$run['ticket_id'],'run_key'=>$run['workflow_key'],
        'resolved_at'=>str_replace(' ','T',$run['closed_at']).'Z','closed_at'=>str_replace(' ','T',$run['closed_at']).'Z',
        'verification_sha256'=>$run['evidence_sha256'],'time_event_keys'=>$keys];
}

/** Shared by the transport and the independent suite interoperability harness. */
function westy_billing_headers(array $config,string $body,?int $now=null): array
{
    $timestamp=(string)($now??time());
    return ['Content-Type: application/json','X-8W-Service: safeharbor-billing','X-8W-Timestamp: '.$timestamp,
        'X-8W-Signature: '.hash_hmac('sha256',$timestamp."\n".$body,$config['secret'])];
}

/** Bounded transport; redirects/other destinations cannot receive the key. */
function westy_billing_transport(array $config,string $body): array
{
    $headers=westy_billing_headers($config,$body);
    $response=''; $curl=curl_init(WESTY_BILLING_ENDPOINT);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$response): int {
            if (strlen($response)+strlen($chunk)>65536) return 0; $response.=$chunk; return strlen($chunk);
        }]);
    $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    return ['status'=>$ok===false?0:$status,'body'=>$response];
}

function westy_billing_response(array $response,string $eventKey,?array $payload=null,?string &$detail=null): bool
{
    $detail=null;
    if (!in_array($response['status']??0,[200,201],true) || !is_string($response['body']??null) || strlen($response['body'])>65536) return false;
    try { $body=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR); } catch (JsonException) { return false; }
    if (($body['ok']??false)!==true || !in_array($body['action']??'',['created','ignored'],true)
        || ($response['status']===201 && $body['action']!=='created')) return false;
    if (($payload['version']??null)===3) {
        $service=$body['service']??null;
        $keys=['id','event_key','state','amount_cents','invoice_id','invoice_line_id','hold_reason'];
        if (count($body)!==3 || !is_array($service) || count($service)!==count($keys) || array_diff($keys,array_keys($service))
            || !is_int($service['id']) || $service['id']<1 || $service['event_key']!==$eventKey || ($payload['event_key']??null)!==$eventKey
            || !is_int($service['amount_cents']) || $service['amount_cents']<0) return false;
        $state=$service['state'];
        if (!in_array($state,['priced','included','unmapped','held','superseded_by_time','fault_zero'],true)
            || ($state==='fault_zero' && ($payload['qa_state']??null)!=='fault_zero')) return false;
        if ($state==='priced') {
            if ($service['amount_cents']<1 || !is_int($service['invoice_id']) || $service['invoice_id']<1
                || !is_int($service['invoice_line_id']) || $service['invoice_line_id']<1) return false;
        } elseif ($service['amount_cents']!==0 || $service['invoice_id']!==null || $service['invoice_line_id']!==null) return false;
        if (in_array($state,['held','unmapped'],true)) {
            if (!is_string($service['hold_reason']) || preg_match('/\A[a-z_]{1,64}\z/D',$service['hold_reason'])!==1) return false;
        } elseif ($service['hold_reason']!==null) return false;
        $detail='ai_service_'.$state;
        return true;
    }
    if (($payload['version']??null)===2) {
        $receipt=$body['completion']??null;
        if (!is_array($receipt) || !is_int($receipt['id']??null) || $receipt['id']<1
            || ($receipt['state']??null)!=='included' || ($receipt['additional_amount_cents']??null)!==0
            || !array_key_exists('invoice_id',$receipt) || $receipt['invoice_id']!==null
            || !is_array($receipt['source']??null) || count($receipt['source'])!==count($payload)
            || ($payload['event_key']??null)!==$eventKey) return false;
        foreach ($payload as $key=>$value) if (!array_key_exists($key,$receipt['source']) || $receipt['source'][$key]!==$value) return false;
        $detail='included_service_recorded';
        return true;
    }
    if (($body['handoff']['event_key']??'')!==$eventKey || ($body['handoff']['state']??'')!=='review_required'
        || !is_int($body['handoff']['id']??null) || $body['handoff']['id']<1 || !is_array($body['invoices']??null) || !$body['invoices']) return false;
    foreach ($body['invoices'] as $invoice) {
        if (!is_int($invoice['invoice_id']??null) || $invoice['invoice_id']<1
            || ($invoice['review_url']??'')!=='https://coastmark.8westit.com/invoices/'.$invoice['invoice_id']) return false;
    }
    $detail='coastmark_review_required';
    return true;
}

/** The cron visits due handoffs in ID order, up to ten per run. */
function westy_billing_due_ids(PDO $pdo): array
{
    return $pdo->query("SELECT id FROM westy_billing_outbox WHERE state NOT IN ('accepted','blocked','qa_pending')
        AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
}

/** Release at most ten expired holds in outbox ID order. Only the v4 receiver writes a verdict. */
function westy_billing_release_expired_qa(PDO $pdo, array $config): int
{
    $config = westy_billing_settings($config);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    westy_work_log_schema_ready($pdo);
    $now = new DateTimeImmutable($pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn(), new DateTimeZone('UTC'));
    $cutoff = $now->sub(new DateInterval('PT' . $config['qa_release_hours'] . 'H'))->format('Y-m-d H:i:s');
    $tenants = implode(',', array_fill(0, count($config['tenant_slugs']), '?'));
    $customers = implode(',', array_fill(0, count($config['customer_ids']), '?'));
    // created_at is the immutable timestamp written in the resolve transaction. A missing seal is eligible.
    $eligible = "state = 'qa_pending' AND created_at <= ? AND payload_json IS NULL AND payload_sha256 IS NULL
        AND EXISTS (SELECT 1 FROM westy_workflows w JOIN tenants t ON t.id = w.tenant_id
            WHERE w.tenant_id = westy_billing_outbox.tenant_id AND w.id = westy_billing_outbox.workflow_id
              AND t.slug IN ($tenants) AND w.customer_id IN ($customers) AND w.customer_id <> ?)
        AND NOT EXISTS (SELECT 1 FROM westy_workflow_log_state s WHERE s.tenant_id = westy_billing_outbox.tenant_id
            AND s.workflow_id = westy_billing_outbox.workflow_id AND s.qa_state IS NOT NULL)
        AND NOT EXISTS (SELECT 1 FROM westy_workflow_v4_receipts r WHERE r.tenant_id = westy_billing_outbox.tenant_id
            AND r.workflow_id = westy_billing_outbox.workflow_id AND r.action = 'qa')";
    $params = [$cutoff, ...array_values($config['tenant_slugs']), ...array_values($config['customer_ids']), WESTY_BILLING_EXCLUDED_CUSTOMER];
    $due = $pdo->prepare("SELECT tenant_id,id FROM westy_billing_outbox WHERE $eligible ORDER BY id LIMIT 10");
    $due->execute($params);
    $release = $pdo->prepare("UPDATE westy_billing_outbox SET state = 'waiting_for_time', detail_code = 'qa_not_received',
        updated_at = UTC_TIMESTAMP() WHERE tenant_id = ? AND id = ? AND $eligible");
    $released = 0;
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pdo->beginTransaction();
        try {
            // Serialize with QA and dispatch, then recheck eligibility on the exact outbox row.
            $tenant = westy_workflow_row($pdo, 'SELECT slug FROM tenants WHERE id = ?' . westy_workflow_lock($pdo), [$row['tenant_id']]);
            if ($tenant && in_array($tenant['slug'], $config['tenant_slugs'], true)) {
                $release->execute([$row['tenant_id'], $row['id'], ...$params]);
                $released += $release->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    return $released;
}

/** Claim in SQL, release the lock, send exact bytes, then record the result. */
function westy_billing_dispatch(PDO $pdo,int $outboxId,array $config,?callable $transport=null): string
{
    $config=westy_billing_settings($config); westy_workflow_schema_ready($pdo);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    // Look up immutable routing IDs before opening the transaction. The first evidence snapshot
    // must include every seal and verdict committed before we acquire the tenant lock.
    $scope=westy_workflow_row($pdo,'SELECT b.tenant_id,w.ticket_id FROM westy_billing_outbox b LEFT JOIN westy_workflows w
        ON w.tenant_id=b.tenant_id AND w.id=b.workflow_id WHERE b.id=?',[$outboxId]);
    if (!$scope) throw new RuntimeException('handoff_not_found');
    $pdo->beginTransaction();
    try {
        $lock=westy_workflow_lock($pdo);
        $tenant=westy_workflow_row($pdo,'SELECT id,slug FROM tenants WHERE id=?'.$lock,[$scope['tenant_id']]);
        $outbox=westy_workflow_row($pdo,'SELECT * FROM westy_billing_outbox WHERE tenant_id=? AND id=?'.$lock,[$scope['tenant_id'],$outboxId]);
        if (!$outbox || !$tenant) throw new RuntimeException('handoff_not_found');
        if (in_array($outbox['state'],['accepted','blocked','qa_pending'],true) || ($outbox['state']==='sending' && strtotime($outbox['last_attempt_at'].' UTC')>time()-120)
            || ($outbox['next_attempt_at']!==null && strtotime($outbox['next_attempt_at'].' UTC')>time())) { $pdo->commit(); return $outbox['state']; }
        // Ticket edits can lock the workflow through a trigger. Take the ticket lock first, as QA does.
        westy_workflow_row($pdo,'SELECT id FROM tickets WHERE tenant_id=? AND id=?'.$lock,[$scope['tenant_id'],$scope['ticket_id']]);
        $run=westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=? AND id=?'.$lock,[$scope['tenant_id'],$outbox['workflow_id']]);
        if (!$run) throw new RuntimeException('workflow_not_found'); $run['tenant_slug']=$tenant['slug'];
        $previousDetail = $outbox['detail_code'];
        try {
            $payload=westy_billing_payload($pdo,$run,$outbox,$config);
            $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if ($outbox['payload_json']!==null && (!hash_equals($outbox['payload_sha256'],hash('sha256',$body)) || $body!==$outbox['payload_json'])) throw new RuntimeException('billing_source_changed_after_claim');
            if ((int)$outbox['attempts']>=8) throw new RuntimeException('billing_delivery_needs_review');
        } catch (Throwable $error) {
            $code=$error->getMessage();
            $waiting=in_array($code,['time_approval_required','current_time_export_required','time_delivery_verification_required','approved_billable_time_required','billing_scope_not_enabled','qa_review_required'],true);
            $state=$waiting?'waiting_for_time':'blocked';
            $pdo->prepare('UPDATE westy_billing_outbox SET state=?,detail_code=?,next_attempt_at=?,updated_at=? WHERE id=?')
                ->execute([$state,westy_billing_next_detail($previousDetail,$code),gmdate('Y-m-d H:i:s',time()+60),gmdate('Y-m-d H:i:s'),$outboxId]);
            $pdo->commit(); return $state;
        }
        $attempt=(int)$outbox['attempts']+1;
        $pdo->prepare("UPDATE westy_billing_outbox SET payload_json=?,payload_sha256=?,state='sending',attempts=?,detail_code=?,last_attempt_at=?,next_attempt_at=?,updated_at=? WHERE id=?")
            ->execute([$body,hash('sha256',$body),$attempt,westy_billing_next_detail($previousDetail,'dispatch_started'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+120),gmdate('Y-m-d H:i:s'),$outboxId]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    try { $response=($transport??'westy_billing_transport')($config,$body); }
    catch (Throwable) { $response=['status'=>0,'body'=>'']; }
    $accepted=westy_billing_response($response,$outbox['event_key'],$payload,$detail);
    $status=(int)($response['status']??0);
    $refused=in_array($status,[400,401,403,409,422],true) || ($status===404 && $payload['version']!==3);
    $state=$accepted?'accepted':($refused?'blocked':'uncertain');
    $detail??=$refused?'receiver_refused':'delivery_uncertain';
    $pdo->prepare('UPDATE westy_billing_outbox SET state=?,detail_code=?,response_json=?,next_attempt_at=?,updated_at=? WHERE id=? AND state=? AND attempts=?')
        ->execute([$state,westy_billing_next_detail($previousDetail,$detail),$accepted?$response['body']:null,$state==='uncertain'?gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min($attempt,6)))):null,gmdate('Y-m-d H:i:s'),$outboxId,'sending',$attempt]);
    return $state;
}
