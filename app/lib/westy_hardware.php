<?php
/** Measured hardware advice: same-ticket evidence and durable notice intent only. */
declare(strict_types=1);
require_once __DIR__.'/westy_workflow.php';

const WESTY_HARDWARE_COMMON = ['schema_version','action','event_key','workflow_key','tenant_slug','customer_id','alert_key','occurred_at'];
const WESTY_HARDWARE_FACTS = ['expected_version','finding_key','issue_key','decision_revision','device_id','device_binding_key','evidence_sha256','measured_at','constraint_code','summary','upgrade_options'];
const WESTY_HARDWARE_CONTACT_ENDPOINT = 'https://support.8westit.com/api/svc/westy_hardware_contact.php';
const WESTY_HARDWARE_CONTACT_CONTEXT = 'milepost-westy-hardware-contact-v1';

/** Strict flat objects, including duplicate-member rejection before decode loses evidence. */
function westy_hardware_decode(string $raw, array $fields): array
{
    try { $value = json_decode($raw,true,4,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new InvalidArgumentException('invalid_json'); }
    if (!is_array($value) || array_is_list($value)) throw new InvalidArgumentException('invalid_payload');
    $keys = array_keys($value); sort($keys); sort($fields);
    $scalars = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s','""',$raw);
    if ($keys !== $fields || substr_count((string)$scalars,':') !== count($keys)) throw new InvalidArgumentException('invalid_fields');
    foreach ($value as $item) if (is_array($item) || is_object($item)) throw new InvalidArgumentException('invalid_fields');
    return $value;
}

function westy_hardware_request(string $raw, ?int $now = null): array
{
    $now ??= time();
    $preview = json_decode($raw,true);
    $status = ($preview['action'] ?? null) === 'hardware_status';
    $p = westy_hardware_decode($raw,array_merge(WESTY_HARDWARE_COMMON,$status ? ['finding_key'] : WESTY_HARDWARE_FACTS));
    if ($p['schema_version'] !== 3 || $p['action'] !== ($status ? 'hardware_status' : 'hardware_outcome')) throw new InvalidArgumentException('invalid_action');
    foreach (array_merge(['event_key','workflow_key','customer_id','finding_key'],$status ? [] : ['issue_key']) as $key) {
        if (!is_string($p[$key]) || preg_match(WESTY_WORKFLOW_UUID,$p[$key]) !== 1) throw new InvalidArgumentException('invalid_'.$key);
    }
    if (!is_string($p['tenant_slug']) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D',$p['tenant_slug']) !== 1) throw new InvalidArgumentException('invalid_tenant');
    if (!is_string($p['alert_key']) || preg_match('/\Aalert:[1-9][0-9]{0,18}\z/D',$p['alert_key']) !== 1) throw new InvalidArgumentException('invalid_alert');
    if (!westy_workflow_date($p['occurred_at']) || strtotime($p['occurred_at']) > $now + 30
        || $now-strtotime($p['occurred_at'])>86400) throw new InvalidArgumentException('invalid_occurred_at');
    if ($status) return $p;
    foreach (['expected_version','decision_revision','device_id'] as $key) if (!is_int($p[$key]) || $p[$key] < 1 || $p[$key] > PHP_INT_MAX) throw new InvalidArgumentException('invalid_'.$key);
    foreach (['device_binding_key','evidence_sha256'] as $key) if (!is_string($p[$key]) || preg_match('/\A[0-9a-f]{64}\z/D',$p[$key]) !== 1) throw new InvalidArgumentException('invalid_'.$key);
    if (!westy_workflow_date($p['measured_at']) || $p['measured_at'] > $p['occurred_at']) throw new InvalidArgumentException('invalid_measured_at');
    if (!in_array($p['constraint_code'],['storage_capacity','memory_capacity','hardware_failure','unsupported_hardware'],true)) throw new InvalidArgumentException('invalid_constraint_code');
    foreach (['summary'=>2000,'upgrade_options'=>4000] as $key=>$limit) {
        if (!is_string($p[$key]) || trim($p[$key]) === '' || !mb_check_encoding($p[$key],'UTF-8') || mb_strlen($p[$key]) > $limit
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$p[$key])) throw new InvalidArgumentException('invalid_'.$key);
    }
    return $p;
}

function westy_hardware_json(array $value): string
{ return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }

function westy_hardware_person_email(mixed $email): bool
{
    return is_string($email) && strlen($email) <= 190 && $email === strtolower(trim($email))
        && filter_var($email,FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/^(?:mailer-daemon|postmaster|no[._-]?reply|do[._-]?not[._-]?reply|bounces?|autoreply)@/i',$email) !== 1;
}

/** Bounded fixed HTTPS origin. Raw bodies/headers never enter logs. */
function westy_hardware_contact_transport(string $body, array $headers): array
{
    $raw = ''; $responseHeaders = [];
    $curl = curl_init(WESTY_HARDWARE_CONTACT_ENDPOINT);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$raw): int {
            if (strlen($raw)+strlen($chunk)>8192) return 0;
            $raw .= $chunk; return strlen($chunk);
        },CURLOPT_HEADERFUNCTION=>static function($curl,string $line) use (&$responseHeaders): int {
            if (str_starts_with($line,'HTTP/')) $responseHeaders=[];
            elseif (str_contains($line,':')) { [$key,$value]=explode(':',$line,2); $responseHeaders[strtolower(trim($key))]=trim($value); }
            return strlen($line);
        }]);
    try {
        if (curl_exec($curl) === false) throw new RuntimeException('device_contact_unavailable');
        return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$raw,'headers'=>$responseHeaders];
    } finally { curl_close($curl); }
}

/** Only this signed current device relation may select a hardware-mail recipient. */
function westy_hardware_contact(array $source, int $ticketId, array $settings, ?callable $transport = null, ?int $now = null): array
{
    $now ??= time();
    $request = ['schema_version'=>1] + array_intersect_key($source,array_flip(['tenant_slug','customer_id','workflow_key','alert_key','device_id','device_binding_key','finding_key','issue_key','decision_revision']))
        + ['ticket_id'=>$ticketId,'request_key'=>bin2hex(random_bytes(16))];
    $body = westy_hardware_json($request); $timestamp = (string)$now;
    $preimage = WESTY_HARDWARE_CONTACT_CONTEXT."\nPOST\n/api/svc/westy_hardware_contact.php\n".$timestamp."\n".$body;
    $response = ($transport ?? 'westy_hardware_contact_transport')($body,['Content-Type: application/json','X-8W-Service: safeharbor-workflow','X-8W-Timestamp: '.$timestamp,
        'X-8W-Signature: '.hash_hmac('sha256',$preimage,$settings['hmac_secret'])]);
    if (($response['status'] ?? null) !== 200 || !is_string($response['body'] ?? null) || strlen($response['body'])>8192) throw new RuntimeException('device_contact_unavailable');
    $headers = array_change_key_case($response['headers'] ?? [],CASE_LOWER);
    $ts = $headers['x-8w-timestamp'] ?? ''; $signature = $headers['x-8w-signature'] ?? '';
    if (($headers['x-8w-service'] ?? null) !== 'milepost-workflow' || !is_string($ts) || !ctype_digit($ts) || abs($now-(int)$ts)>30
        || !is_string($signature) || preg_match('/\A[0-9a-f]{64}\z/D',$signature)!==1
        || !hash_equals(hash_hmac('sha256',WESTY_HARDWARE_CONTACT_CONTEXT."-response\n".$ts."\n".$response['body'],$settings['hmac_secret']),$signature)) throw new RuntimeException('device_contact_unauthenticated');
    $result = westy_hardware_decode($response['body'],array_merge(array_keys($request),['ok','contact_state','contact_reference','contact_revision','email','resolved_at','expires_at']));
    foreach ($request as $key=>$value) if ($result[$key] !== $value) throw new RuntimeException('device_contact_scope_changed');
    if ($result['ok'] !== true || !in_array($result['contact_state'],['present','absent','stale','ambiguous'],true)
        || !westy_workflow_date($result['resolved_at']) || !westy_workflow_date($result['expires_at'])
        || strtotime($result['resolved_at']) > $now+5 || strtotime($result['expires_at']) !== strtotime($result['resolved_at'])+30
        || strtotime($result['expires_at']) <= $now) throw new RuntimeException('device_contact_stale');
    if ($result['contact_state'] === 'present') {
        if (!is_string($result['contact_reference']) || preg_match('/\A[0-9a-f]{32}\z/D',$result['contact_reference'])!==1
            || !is_int($result['contact_revision']) || $result['contact_revision']<1 || !westy_hardware_person_email($result['email'])) throw new RuntimeException('device_contact_invalid');
    } elseif ($result['contact_reference'] !== null || $result['contact_revision'] !== null || $result['email'] !== null) throw new RuntimeException('device_contact_invalid');
    return $result;
}

/** Same lock order as the workflow receiver. No customer or contact fallback. */
function westy_hardware_scope(PDO $pdo, array $settings, array $p): array
{
    $lock = westy_workflow_lock($pdo);
    $tenant = westy_workflow_row($pdo,'SELECT id,slug FROM tenants WHERE slug=?'.$lock,[$p['tenant_slug']]);
    if (!$tenant) throw new RuntimeException('unauthorized');
    $tid = (int)$tenant['id'];
    if (!in_array($p['tenant_slug'],['8west','internal'],true)
        && (($settings['managed_providers_enabled'] ?? false) !== true || suite_managed_provider($pdo,$p['tenant_slug'],true) === null)) throw new RuntimeException('unauthorized');
    if (!westy_workflow_row($pdo,'SELECT id FROM svc_identities WHERE tenant_id=? AND service=? AND is_active=1'.$lock,[$tid,WESTY_WORKFLOW_SERVICE])) throw new RuntimeException('unauthorized');
    $binding = westy_workflow_row($pdo,"SELECT client_id FROM suite_customer_sync_bindings WHERE tenant_id=? AND customer_id=? AND status='active'".$lock,[$tid,$p['customer_id']]);
    if (!$binding || !managed_customer_operational($pdo,$tid,(int)$binding['client_id'],true)) throw new WestyWorkflowConflict('active_customer_binding_required');
    $ticket = westy_workflow_row($pdo,'SELECT * FROM tickets WHERE tenant_id=? AND external_key=?'.$lock,[$tid,$p['alert_key']]);
    $run = westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=? AND workflow_key=?'.$lock,[$tid,$p['workflow_key']]);
    if (!$ticket || !$run || $run['customer_id'] !== $p['customer_id'] || $run['alert_key'] !== $p['alert_key']
        || (int)$run['ticket_id'] !== (int)$ticket['id'] || (int)$run['client_id'] !== (int)$binding['client_id']
        || (int)$ticket['client_id'] !== (int)$binding['client_id']) throw new WestyWorkflowConflict('workflow_scope_conflict');
    return ['tenant_id'=>$tid,'client_id'=>(int)$binding['client_id'],'ticket'=>$ticket,'run'=>$run];
}

function westy_hardware_unresolved(array $scope): void
{
    if (!in_array($scope['ticket']['status'],['open','in_progress','waiting'],true)
        || $scope['ticket']['resolved_at'] !== null || $scope['ticket']['merged_into_id'] !== null
        || $scope['run']['state'] === 'resolved' || $scope['run']['closed_at'] !== null) throw new WestyWorkflowConflict('unresolved_original_ticket_required');
}

/** Local transaction only. Every observation is retained; one notice per decision. */
function westy_hardware_receive(PDO $pdo, array $settings, array $p, string $requestHash): array
{
    $settings = westy_workflow_settings($settings);
    westy_workflow_schema_ready($pdo);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try {
        $scope = westy_hardware_scope($pdo,$settings,$p);
        $tid = $scope['tenant_id']; $run = $scope['run']; $ticket = $scope['ticket'];
        if ($p['action'] === 'hardware_status') {
            $finding = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_findings WHERE tenant_id=? AND finding_key=? AND workflow_id=?',[$tid,$p['finding_key'],$run['id']]);
            if (!$finding) throw new WestyWorkflowConflict('hardware_finding_not_found');
            $notice = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE tenant_id=? AND id=?',[$tid,$finding['notice_id']]);
            $receipt = westy_workflow_row($pdo,'SELECT response_json FROM westy_hardware_receipts WHERE tenant_id=? AND finding_id=? ORDER BY id LIMIT 1',[$tid,$finding['id']]);
            $result = ['schema_version'=>3,'ok'=>true,'action'=>'hardware_status','event_key'=>$p['event_key'],
                'workflow_key'=>$run['workflow_key'],'tenant_slug'=>$p['tenant_slug'],'customer_id'=>$p['customer_id'],'alert_key'=>$p['alert_key'],
                'ticket_id'=>(int)$ticket['id'],'finding_key'=>$p['finding_key'],'outcome'=>'needs_hardware_upgrade',
                'version'=>(int)$run['version'],'state'=>$run['state'],
                'ticket'=>['status'=>$ticket['status'],'assignee_id'=>$ticket['assignee_id'] === null ? null : (int)$ticket['assignee_id'],'client_id'=>(int)$ticket['client_id']],
                'notice'=>['id'=>(int)$notice['id'],'state'=>$notice['state'],'detail_code'=>$notice['detail_code'],
                    'mail_queue_id'=>$notice['mail_queue_id'] === null ? null : (int)$notice['mail_queue_id']],
                'original_receipt'=>json_decode($receipt['response_json'],true,16,JSON_THROW_ON_ERROR)];
            $pdo->commit(); return $result;
        }
        $prior = westy_workflow_row($pdo,'SELECT request_sha256,response_json FROM westy_hardware_receipts WHERE tenant_id=? AND event_key=?',[$tid,$p['event_key']]);
        if ($prior) {
            if (!hash_equals($prior['request_sha256'],$requestHash)) throw new WestyWorkflowConflict('event_key_conflict');
            $result = json_decode($prior['response_json'],true,16,JSON_THROW_ON_ERROR);
            $result['replayed'] = true; $pdo->commit(); return $result;
        }
        // A finding's identity excludes only transport event/version/time fields.
        $facts = array_diff_key($p,array_flip(['event_key','expected_version','occurred_at']));
        ksort($facts); $findingHash = hash('sha256',westy_hardware_json($facts));
        $finding = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_findings WHERE tenant_id=? AND finding_key=?',[$tid,$p['finding_key']]);
        if ($finding && ((int)$finding['workflow_id'] !== (int)$run['id'] || !hash_equals($finding['finding_sha256'],$findingHash))) throw new WestyWorkflowConflict('finding_key_conflict');
        $now = gmdate('Y-m-d H:i:s');
        $notice = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE tenant_id=? AND workflow_id=? AND issue_key=? AND decision_revision=?',[$tid,$run['id'],$p['issue_key'],$p['decision_revision']]);
        $deduplicated = $notice !== null;
        if ($notice && ((int)$notice['device_id'] !== $p['device_id'] || $notice['device_binding_key'] !== $p['device_binding_key'])) throw new WestyWorkflowConflict('hardware_issue_scope_conflict');
        if (!$notice) {
            westy_hardware_unresolved($scope);
            if ((int)$run['version'] !== $p['expected_version']) throw new WestyWorkflowConflict('version_conflict');
            $previous = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE tenant_id=? AND workflow_id=? AND issue_key=? ORDER BY decision_revision DESC LIMIT 1',[$tid,$run['id'],$p['issue_key']]);
            if ($p['decision_revision'] !== ($previous ? (int)$previous['decision_revision'] + 1 : 1)) throw new WestyWorkflowConflict('decision_revision_conflict');
            if ($previous && ((int)$previous['device_id'] !== $p['device_id'] || $previous['device_binding_key'] !== $p['device_binding_key'])) throw new WestyWorkflowConflict('hardware_issue_scope_conflict');
            $body = "Westy: hardware upgrade needed. The ticket remains unresolved.\n\n".$p['summary']."\n\nUpgrade options:\n".$p['upgrade_options']
                ."\n\nMeasured: ".$p['measured_at']."\nEvidence SHA256: ".$p['evidence_sha256'];
            $pdo->prepare('INSERT INTO messages(ticket_id,author_name,kind,body) VALUES(?,?,?,?)')->execute([$ticket['id'],'Westy hardware','system',utf8_clean($body)]);
            $messageId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO westy_hardware_notices(tenant_id,client_id,workflow_id,ticket_id,device_id,device_binding_key,issue_key,decision_revision,source_json,message_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tid,$scope['client_id'],$run['id'],$ticket['id'],$p['device_id'],$p['device_binding_key'],$p['issue_key'],$p['decision_revision'],utf8_clean(westy_hardware_json($p)),$messageId,$now,$now]);
            $notice = ['id'=>(int)$pdo->lastInsertId(),'state'=>'pending'];
            // A new decision supersedes only unsent intentions, never accepted/unknown attempts.
            $pdo->prepare("UPDATE westy_hardware_notices SET state='superseded',detail_code='newer_hardware_decision',updated_at=? WHERE tenant_id=? AND workflow_id=? AND issue_key=? AND decision_revision<? AND state IN ('pending','verifying','verification_failed','queued','failed','no_device_contact')")
                ->execute([$now,$tid,$run['id'],$p['issue_key'],$p['decision_revision']]);
            if ($run['state'] !== 'human_owned') {
                $run['version'] = (int)$run['version'] + 1; $run['state'] = 'needs_human';
                $pdo->prepare("UPDATE westy_workflows SET state='needs_human',version=?,summary=?,updated_at=? WHERE tenant_id=? AND id=?")
                    ->execute([$run['version'],utf8_clean($p['summary']),$now,$tid,$run['id']]);
            }
        }
        if (!$finding) {
            $pdo->prepare('INSERT INTO westy_hardware_findings(tenant_id,workflow_id,notice_id,finding_key,finding_sha256,source_json,received_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$tid,$run['id'],$notice['id'],$p['finding_key'],$findingHash,utf8_clean(westy_hardware_json($p)),$now]);
            $finding = ['id'=>(int)$pdo->lastInsertId()];
        }
        $result = ['schema_version'=>3,'ok'=>true,'action'=>'hardware_outcome','event_key'=>$p['event_key'],'workflow_key'=>$run['workflow_key'],
            'tenant_slug'=>$p['tenant_slug'],'customer_id'=>$p['customer_id'],'alert_key'=>$p['alert_key'],'ticket_id'=>(int)$ticket['id'],
            'ticket_url'=>'https://safeharbor.8westit.com/ticket.php?id='.(int)$ticket['id'],'version'=>(int)$run['version'],'state'=>$run['state'],
            'receipt_id'=>$p['event_key'],'finding_key'=>$p['finding_key'],'issue_key'=>$p['issue_key'],'decision_revision'=>$p['decision_revision'],
            'outcome'=>'needs_hardware_upgrade','notice_id'=>(int)$notice['id'],'deduplicated'=>$deduplicated,'request_sha256'=>$requestHash,'replayed'=>false];
        $pdo->prepare('INSERT INTO westy_hardware_receipts(tenant_id,finding_id,event_key,request_sha256,response_json,received_at) VALUES(?,?,?,?,?,?)')
            ->execute([$tid,$finding['id'],$p['event_key'],$requestHash,westy_hardware_json($result),$now]);
        $pdo->commit(); return $result;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** One claimed intent. Network ambiguity is retained on this notice only. */
function westy_hardware_dispatch(PDO $pdo, int $noticeId, array $settings, ?callable $resolver = null, ?callable $sender = null): string
{
    $settings = westy_workflow_settings($settings);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $snapshot = westy_workflow_row($pdo,'SELECT tenant_id,source_json,ticket_id FROM westy_hardware_notices WHERE id=?',[$noticeId]);
    if (!$snapshot) throw new RuntimeException('hardware_notice_not_found');
    $source = json_decode($snapshot['source_json'],true,8,JSON_THROW_ON_ERROR);
    $tid = (int)$snapshot['tenant_id']; $claim = bin2hex(random_bytes(16)); $lock = westy_workflow_lock($pdo);
    $pdo->beginTransaction();
    try {
        $scope = westy_hardware_scope($pdo,$settings,$source);
        westy_hardware_unresolved($scope);
        $notice = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE tenant_id=? AND id=?'.$lock,[$tid,$noticeId]);
        if ($notice['state'] === 'sending' && $notice['updated_at'] < gmdate('Y-m-d H:i:s',time()-120)) {
            $pdo->prepare("UPDATE westy_hardware_notices SET state='uncertain',detail_code='interrupted_submission',updated_at=? WHERE tenant_id=? AND id=?")
                ->execute([gmdate('Y-m-d H:i:s'),$tid,$noticeId]);
            $pdo->commit(); return 'uncertain';
        }
        $eligible = in_array($notice['state'],['pending','verification_failed','queued','failed'],true)
            || ($notice['state'] === 'verifying' && $notice['claim_until'] < gmdate('Y-m-d H:i:s'));
        if (!$eligible || $notice['next_try_at'] > gmdate('Y-m-d H:i:s') || (int)$notice['send_attempts'] >= 5) {
            $pdo->commit(); return $notice['state'];
        }
        $pdo->prepare("UPDATE westy_hardware_notices SET state='verifying',claim_key=?,claim_until=?,verification_attempts=verification_attempts+1,detail_code='recipient_verification_pending',updated_at=? WHERE tenant_id=? AND id=?")
            ->execute([$claim,gmdate('Y-m-d H:i:s',time()+30),gmdate('Y-m-d H:i:s'),$tid,$noticeId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    try {
        $contact = westy_hardware_contact($source,(int)$snapshot['ticket_id'],$settings,$resolver);
        if (in_array($contact['contact_state'],['stale','ambiguous'],true)) throw new RuntimeException('device_contact_'.$contact['contact_state']);
    } catch (Throwable $error) {
        $code = in_array($error->getMessage(),['device_contact_stale','device_contact_ambiguous','device_contact_scope_changed','device_contact_unauthenticated','device_contact_invalid'],true)
            ? $error->getMessage() : 'device_contact_unavailable';
        return westy_hardware_verification_failed($pdo,$tid,$noticeId,$claim,$code);
    }
    $attemptId = null;
    $pdo->beginTransaction();
    try {
        $scope = westy_hardware_scope($pdo,$settings,$source);
        westy_hardware_unresolved($scope);
        $notice = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE tenant_id=? AND id=?'.$lock,[$tid,$noticeId]);
        if ($notice['state'] !== 'verifying' || $notice['claim_key'] !== $claim) { $pdo->commit(); return $notice['state']; }
        if (strtotime($contact['expires_at']) <= time()) {
            $pdo->rollBack(); return westy_hardware_verification_failed($pdo,$tid,$noticeId,$claim,'device_contact_stale');
        }
        if ($contact['contact_state'] === 'absent') {
            $messageId = $notice['no_contact_message_id'];
            if ($messageId === null) {
                $pdo->prepare('INSERT INTO messages(ticket_id,author_name,kind,body) VALUES(?,?,?,?)')
                    ->execute([$notice['ticket_id'],'Westy hardware','system','Hardware upgrade options are recorded. Milepost verified that no customer email is explicitly linked to this device. A technician must obtain the device contact; no email was sent.']);
                $messageId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE westy_hardware_notices SET state='no_device_contact',detail_code='no_device_contact',contact_json=?,no_contact_message_id=?,claim_key=NULL,claim_until=NULL,updated_at=? WHERE tenant_id=? AND id=?")
                ->execute([westy_hardware_json($contact),$messageId,gmdate('Y-m-d H:i:s'),$tid,$noticeId]);
            $pdo->commit(); return 'no_device_contact';
        }
        $subject = '[#'.(int)$notice['ticket_id'].'] Hardware upgrade options';
        $body = "Your computer has a hardware constraint that software repair cannot resolve under the recorded decision.\n\n".$source['summary']
            ."\n\nYour upgrade options:\n".$source['upgrade_options']
            ."\n\nPlease reply to discuss the next step. A technician will verify compatibility and any costs before a purchase. No purchase or hardware change has been made.\n\nWesty, on behalf of your support team\nSafeharbor case #".(int)$notice['ticket_id'];
        $mailId = $notice['mail_queue_id'];
        if ($mailId === null) {
            $pdo->prepare('INSERT INTO mail_queue(to_addr,subject,body_text,ticket_id,max_attempts) VALUES(?,?,?,?,0)')
                ->execute([utf8_clean($contact['email']),utf8_clean($subject),utf8_clean($body),$notice['ticket_id']]);
            $mailId = (int)$pdo->lastInsertId();
        } else {
            $mail = westy_workflow_row($pdo,'SELECT * FROM mail_queue WHERE id=?'.$lock,[$mailId]);
            if (!$mail || $mail['sent_at'] !== null || (int)$mail['max_attempts'] !== 0 || (int)$mail['ticket_id'] !== (int)$notice['ticket_id']
                || $mail['subject'] !== $subject || $mail['body_text'] !== $body) throw new RuntimeException('hardware_mail_queue_integrity');
            // Only an established failed submission may reach a fresh recipient check.
            $pdo->prepare('UPDATE mail_queue SET to_addr=? WHERE id=?')->execute([utf8_clean($contact['email']),$mailId]);
        }
        $contactJson = utf8_clean(westy_hardware_json($contact));
        $pdo->prepare('INSERT INTO westy_hardware_mail_attempts(tenant_id,notice_id,attempt_key,contact_json,message_sha256,attempted_at) VALUES(?,?,?,?,?,?)')
            ->execute([$tid,$noticeId,$claim,$contactJson,hash('sha256',westy_hardware_json([$contact['email'],$subject,$body])),gmdate('Y-m-d H:i:s')]);
        $attemptId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE westy_hardware_notices SET mail_queue_id=?,state='sending',send_attempts=send_attempts+1,contact_json=?,detail_code='submission_in_progress',updated_at=? WHERE tenant_id=? AND id=?")
            ->execute([$mailId,$contactJson,gmdate('Y-m-d H:i:s'),$tid,$noticeId]);
        $pdo->commit();
        // The attempt survives a crash. Reacquire local scope and hold it through submission.
        $pdo->beginTransaction();
        $scope = westy_hardware_scope($pdo,$settings,$source);
        westy_hardware_unresolved($scope);
        $notice = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE tenant_id=? AND id=?'.$lock,[$tid,$noticeId]);
        $latest = westy_workflow_row($pdo,'SELECT MAX(decision_revision) AS revision FROM westy_hardware_notices WHERE tenant_id=? AND workflow_id=? AND issue_key=?',[$tid,$notice['workflow_id'],$notice['issue_key']]);
        if ($notice['state'] !== 'sending' || $notice['claim_key'] !== $claim || strtotime($contact['expires_at']) <= time()) {
            $evidence = ['outcome'=>'failed','transport'=>'none','provider_http'=>null,'outcome_code'=>'scope_changed_before_submission'];
        } elseif ((int)$latest['revision'] > (int)$notice['decision_revision']) {
            $evidence = ['outcome'=>'failed','transport'=>'none','provider_http'=>null,'outcome_code'=>'decision_superseded_before_submission'];
        } else {
            $evidence = ['outcome'=>'uncertain','transport'=>'none','provider_http'=>null,'outcome_code'=>'submission_unknown'];
            try {
                $evidence = ($sender ?? 'westy_hardware_send')($pdo,$notice,$contact['email'],$subject,$body);
                if (!in_array($evidence['outcome'] ?? null,['failed','uncertain','provider_accepted'],true)
                    || !in_array($evidence['transport'] ?? null,['none','graph','smtp','php_mail'],true)
                    || !is_string($evidence['outcome_code'] ?? null) || preg_match('/\A[a-z0-9_]{1,64}\z/D',$evidence['outcome_code']) !== 1
                    || (($evidence['provider_http'] ?? null) !== null && (!is_int($evidence['provider_http']) || $evidence['provider_http']<100 || $evidence['provider_http']>599))) throw new RuntimeException('invalid_submission_evidence');
            } catch (Throwable) { $evidence = ['outcome'=>'uncertain','transport'=>'none','provider_http'=>null,'outcome_code'=>'submission_unknown']; }
        }
        westy_hardware_complete_attempt($pdo,$tid,$noticeId,$attemptId,(int)$mailId,$evidence,(int)$notice['send_attempts']);
        $pdo->commit(); return $evidence['outcome_code'] === 'decision_superseded_before_submission' ? 'superseded' : $evidence['outcome'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($attemptId === null) return westy_hardware_verification_failed($pdo,$tid,$noticeId,$claim,'local_binding_changed');
        // A durable attempt exists. Any uncertain interruption stays non-retriable.
        throw $error;
    }
}

function westy_hardware_verification_failed(PDO $pdo, int $tid, int $noticeId, string $claim, string $code): string
{
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("UPDATE westy_hardware_notices SET state='verification_failed',detail_code=?,claim_key=NULL,claim_until=NULL,next_try_at=?,updated_at=? WHERE tenant_id=? AND id=? AND state='verifying' AND claim_key=?")
        ->execute([$code,gmdate('Y-m-d H:i:s',time()+60),$now,$tid,$noticeId,$claim]);
    return (string)westy_workflow_row($pdo,'SELECT state FROM westy_hardware_notices WHERE tenant_id=? AND id=?',[$tid,$noticeId])['state'];
}

function westy_hardware_complete_attempt(PDO $pdo, int $tid, int $noticeId, int $attemptId, int $mailId, array $evidence, int $attempts): void
{
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('UPDATE westy_hardware_mail_attempts SET outcome=?,transport=?,provider_http=?,outcome_code=?,completed_at=? WHERE tenant_id=? AND id=? AND completed_at IS NULL')
        ->execute([$evidence['outcome'],$evidence['transport'],$evidence['provider_http'],$evidence['outcome_code'],$now,$tid,$attemptId]);
    $state = $evidence['outcome_code'] === 'decision_superseded_before_submission' ? 'superseded' : $evidence['outcome'];
    $pdo->prepare('UPDATE westy_hardware_notices SET state=?,detail_code=?,claim_key=NULL,claim_until=NULL,next_try_at=?,updated_at=? WHERE tenant_id=? AND id=?')
        ->execute([$state,$evidence['outcome_code'],gmdate('Y-m-d H:i:s',time()+max(1,$attempts**2)*60),$now,$tid,$noticeId]);
    $pdo->prepare('UPDATE mail_queue SET attempts=?,last_error=?,sent_at=? WHERE id=? AND max_attempts=0')
        ->execute([$attempts,$evidence['outcome_code'],$evidence['outcome'] === 'provider_accepted' ? $now : null,$mailId]);
}

/** Reuse the established support reply route and the ordinary mail transport. */
function westy_hardware_send(PDO $pdo, array $notice, string $to, string $subject, string $body): array
{
    require_once __DIR__.'/mailer.php';
    require_once __DIR__.'/support_addresses.php';
    $config = (array)cfg('support_addresses',[]);
    $reply = support_ticket_reply_address($pdo,(int)$notice['ticket_id'],$config,(string)($config['mailbox'] ?? ''));
    if ($reply !== null) {
        $graph = support_graph_config($config,mailer_graph_config());
        if ($graph === null) return ['outcome'=>'failed','transport'=>'none','provider_http'=>null,'outcome_code'=>'support_transport_unavailable'];
        return mailer_graph_submission_evidence(mailer_send_graph_result($graph,$to,$subject,$body,null,$reply));
    }
    return mail_send_result($to,$subject,$body);
}

function westy_hardware_dispatch_due(PDO $pdo, array $config): void
{
    if (($config['enabled'] ?? false) !== true) return;
    try {
        $query = $pdo->prepare("SELECT id FROM westy_hardware_notices WHERE state IN ('pending','verifying','verification_failed','queued','failed','sending') AND next_try_at<=? ORDER BY id LIMIT 25");
        $query->execute([gmdate('Y-m-d H:i:s')]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1146) error_log('Hardware notice queue unavailable: '.$error::class);
        return;
    }
    foreach ($ids as $id) {
        try { westy_hardware_dispatch($pdo,(int)$id,$config); }
        catch (Throwable $error) { error_log('Hardware notice dispatch: '.$error::class); }
    }
}
