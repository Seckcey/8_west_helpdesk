<?php
/** Closed workflow -> existing approved-time invoice review. Never sends mail. */
declare(strict_types=1);
require_once __DIR__ . '/westy_workflow.php';
const WESTY_BILLING_ENDPOINT = 'https://coastmark.8westit.com/api/integrations/safeharbor/billing-handoffs';

function westy_billing_settings(array $config): array
{
    if (($config['enabled'] ?? false) !== true || ($config['endpoint'] ?? '') !== WESTY_BILLING_ENDPOINT
        || ($config['service'] ?? '') !== 'safeharbor-billing'
        || !is_string($config['secret'] ?? null) || strlen($config['secret']) < 32
        || !is_array($config['tenant_slugs'] ?? null) || !$config['tenant_slugs']
        || !is_array($config['customer_ids'] ?? null) || !$config['customer_ids']) throw new RuntimeException('billing_handoff_unavailable');
    foreach ($config['tenant_slugs'] as $slug) if (!is_string($slug) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D',$slug)!==1) throw new RuntimeException('billing_handoff_unavailable');
    foreach ($config['customer_ids'] as $id) if (!is_string($id) || preg_match(WESTY_WORKFLOW_UUID,$id)!==1) throw new RuntimeException('billing_handoff_unavailable');
    return $config;
}

/** All facts come from exact local ownership and accepted v3 time receipts. */
function westy_billing_payload(PDO $pdo, array $run, array $outbox, array $config): array
{
    if (!in_array($run['tenant_slug'],$config['tenant_slugs'],true) || !in_array($run['customer_id'],$config['customer_ids'],true)
        || $run['customer_id']==='4ebaeefa-b101-47f8-ac76-e49ab309d272') throw new RuntimeException('billing_scope_not_enabled');
    $lock=westy_workflow_lock($pdo);
    $ticket=westy_workflow_row($pdo,'SELECT status,resolved_at,client_id,merged_into_id FROM tickets WHERE tenant_id=? AND id=?'.$lock,[$run['tenant_id'],$run['ticket_id']]);
    $binding=westy_workflow_row($pdo,'SELECT client_id FROM suite_customer_sync_bindings WHERE tenant_id=? AND customer_id=? AND status=?'.$lock,[$run['tenant_id'],$run['customer_id'],'active']);
    if (!$ticket || !$binding || $ticket['status']!=='resolved' || !$run['closed_at'] || $ticket['resolved_at']!==$run['closed_at']
        || (int)$ticket['client_id']!==(int)$run['client_id'] || (int)$binding['client_id']!==(int)$run['client_id'] || $ticket['merged_into_id']!==null
        || !is_string($run['evidence_sha256']) || preg_match('/\A[0-9a-f]{64}\z/D',$run['evidence_sha256'])!==1) throw new RuntimeException('verified_closed_ticket_required');
    $query=$pdo->prepare('SELECT id,approval_status,billable FROM time_entries WHERE tenant_id=? AND client_id=? AND ticket_id=? ORDER BY id LIMIT 101'.$lock);
    $query->execute([$run['tenant_id'],$run['client_id'],$run['ticket_id']]); $entries=$query->fetchAll(PDO::FETCH_ASSOC);
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
    if (!$keys) throw new RuntimeException('approved_billable_time_required');
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

function westy_billing_response(array $response,string $eventKey): bool
{
    if (!in_array($response['status']??0,[200,201],true) || !is_string($response['body']??null) || strlen($response['body'])>65536) return false;
    try { $body=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR); } catch (JsonException) { return false; }
    if (($body['ok']??false)!==true || !in_array($body['action']??'',['created','ignored'],true)
        || (($response['status']??0)===201 && ($body['action']??'')!=='created')
        || ($body['handoff']['event_key']??'')!==$eventKey || ($body['handoff']['state']??'')!=='review_required'
        || !is_int($body['handoff']['id']??null) || $body['handoff']['id']<1 || !is_array($body['invoices']??null) || !$body['invoices']) return false;
    foreach ($body['invoices'] as $invoice) {
        if (!is_int($invoice['invoice_id']??null) || $invoice['invoice_id']<1
            || ($invoice['review_url']??'')!=='https://coastmark.8westit.com/invoices/'.$invoice['invoice_id']) return false;
    }
    return true;
}

/** Claim in SQL, release the lock, send exact bytes, then record the result. */
function westy_billing_dispatch(PDO $pdo,int $outboxId,array $config,?callable $transport=null): string
{
    $config=westy_billing_settings($config); westy_workflow_schema_ready($pdo);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        // Same provider tenant lock as approval/export and workflow writers.
        $scope=westy_workflow_row($pdo,'SELECT tenant_id FROM westy_billing_outbox WHERE id=?',[$outboxId]);
        if (!$scope) throw new RuntimeException('handoff_not_found');
        $tenant=westy_workflow_row($pdo,'SELECT id,slug FROM tenants WHERE id=?'.westy_workflow_lock($pdo),[$scope['tenant_id']]);
        $outbox=westy_workflow_row($pdo,'SELECT * FROM westy_billing_outbox WHERE tenant_id=? AND id=?'.westy_workflow_lock($pdo),[$scope['tenant_id'],$outboxId]);
        if (!$outbox || !$tenant) throw new RuntimeException('handoff_not_found');
        if (in_array($outbox['state'],['accepted','blocked'],true) || ($outbox['state']==='sending' && strtotime($outbox['last_attempt_at'].' UTC')>time()-120)
            || ($outbox['next_attempt_at']!==null && strtotime($outbox['next_attempt_at'].' UTC')>time())) { $pdo->commit(); return $outbox['state']; }
        $run=westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=? AND id=?'.westy_workflow_lock($pdo),[$scope['tenant_id'],$outbox['workflow_id']]);
        if (!$run) throw new RuntimeException('workflow_not_found'); $run['tenant_slug']=$tenant['slug'];
        try {
            $payload=westy_billing_payload($pdo,$run,$outbox,$config);
            $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if ($outbox['payload_json']!==null && (!hash_equals($outbox['payload_sha256'],hash('sha256',$body)) || $body!==$outbox['payload_json'])) throw new RuntimeException('billing_source_changed_after_claim');
            if ((int)$outbox['attempts']>=8) throw new RuntimeException('billing_delivery_needs_review');
        } catch (Throwable $error) {
            $code=$error->getMessage();
            $waiting=in_array($code,['time_approval_required','current_time_export_required','time_delivery_verification_required','approved_billable_time_required','billing_scope_not_enabled'],true);
            $state=$waiting?'waiting_for_time':'blocked';
            $pdo->prepare('UPDATE westy_billing_outbox SET state=?,detail_code=?,next_attempt_at=?,updated_at=? WHERE id=?')
                ->execute([$state,preg_match('/\A[a-z_]{1,64}\z/D',$code)===1?$code:'billing_review_required',gmdate('Y-m-d H:i:s',time()+60),gmdate('Y-m-d H:i:s'),$outboxId]);
            $pdo->commit(); return $state;
        }
        $attempt=(int)$outbox['attempts']+1;
        $pdo->prepare("UPDATE westy_billing_outbox SET payload_json=?,payload_sha256=?,state='sending',attempts=?,detail_code='dispatch_started',last_attempt_at=?,next_attempt_at=?,updated_at=? WHERE id=?")
            ->execute([$body,hash('sha256',$body),$attempt,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+120),gmdate('Y-m-d H:i:s'),$outboxId]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    try { $response=($transport??'westy_billing_transport')($config,$body); }
    catch (Throwable) { $response=['status'=>0,'body'=>'']; }
    $accepted=westy_billing_response($response,$outbox['event_key']);
    $state=$accepted?'accepted':(in_array((int)($response['status']??0),[400,401,403,404,409,422],true)?'blocked':'uncertain');
    $pdo->prepare('UPDATE westy_billing_outbox SET state=?,detail_code=?,response_json=?,next_attempt_at=?,updated_at=? WHERE id=? AND state=? AND attempts=?')
        ->execute([$state,$accepted?'coastmark_review_required':($state==='blocked'?'receiver_refused':'delivery_uncertain'),$accepted?$response['body']:null,$state==='uncertain'?gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min($attempt,6)))):null,gmdate('Y-m-d H:i:s'),$outboxId,'sending',$attempt]);
    return $state;
}
