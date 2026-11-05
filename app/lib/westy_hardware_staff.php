<?php
/** Internal staff fallback for a terminal device-contact absence. */
declare(strict_types=1);
require_once __DIR__.'/westy_hardware.php';

function westy_hardware_staff_person(?array $user): ?array
{
    if (!$user || (int)$user['is_active'] !== 1 || !in_array($user['role'],['owner','admin','tech'],true)
        || !is_string($user['email']) || !westy_hardware_person_email(strtolower($user['email']))) return null;
    return ['id'=>(int)$user['id'],'role'=>$user['role'],'email'=>$user['email']];
}

/** Lock the recorded staff authority, never route through customer contacts. */
function westy_hardware_staff_recipient(PDO $pdo, int $tenantId, ?int $assignee): array
{
    $lock = westy_workflow_lock($pdo);
    if ($assignee !== null) {
        $user = westy_workflow_row($pdo,'SELECT id,role,email,is_active FROM users WHERE tenant_id=? AND id=?'.$lock,[$tenantId,$assignee]);
        if ($recipient = westy_hardware_staff_person($user)) return [$recipient,null];
    }
    $query = $pdo->prepare("SELECT id,role,email,is_active FROM users WHERE tenant_id=? AND role='owner' AND is_active=1 ORDER BY id".$lock);
    $query->execute([$tenantId]); $recipient = null;
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $owner = westy_hardware_staff_person($row);
        if (!$owner) continue;
        if ($recipient) { $query->closeCursor(); return [null,'ambiguous_tenant_owners']; }
        $recipient = $owner;
    }
    return [$recipient,$recipient ? null : 'no_eligible_staff'];
}

function westy_hardware_staff_matches(array $staff, ?array $recipient): bool
{
    return $recipient !== null && (int)$staff['recipient_user_id'] === $recipient['id']
        && $staff['recipient_role'] === $recipient['role'] && $staff['recipient_email'] === $recipient['email'];
}

/** Follow the receiver lock order, including for suppressed or interrupted mail. */
function westy_hardware_staff_lock(PDO $pdo, array $settings, array $source, int $tenantId, int $noticeId): array
{
    $lock = westy_workflow_lock($pdo);
    $tenant = westy_workflow_row($pdo,'SELECT slug FROM tenants WHERE id=?'.$lock,[$tenantId]);
    $scope = null;
    if ($tenant && $tenant['slug'] === $source['tenant_slug']) {
        try {
            $scope = westy_hardware_scope($pdo,$settings,$source);
            westy_hardware_unresolved($scope);
        } catch (WestyWorkflowConflict) { $scope = null; }
        catch (RuntimeException $error) {
            if ($error->getMessage() !== 'unauthorized') throw $error;
            $scope = null;
        }
    }
    $notice = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE tenant_id=? AND id=?'.$lock,[$tenantId,$noticeId]);
    $staff = westy_workflow_row($pdo,'SELECT * FROM westy_hardware_staff_notices WHERE tenant_id=? AND notice_id=?'.$lock,[$tenantId,$noticeId]);
    if (!$scope || !$notice || (int)$notice['workflow_id'] !== (int)$scope['run']['id']
        || (int)$notice['ticket_id'] !== (int)$scope['ticket']['id'] || (int)$notice['client_id'] !== $scope['client_id']) $scope = null;
    return [$scope,$notice,$staff];
}

function westy_hardware_staff_applicable(PDO $pdo, ?array $scope, ?array $notice): bool
{
    if (!$scope || !$notice || $notice['state'] !== 'no_device_contact' || !$notice['no_contact_message_id']) return false;
    $query = $pdo->prepare("SELECT id FROM messages WHERE ticket_id=? AND kind='system' AND id IN (?,?)".westy_workflow_lock($pdo));
    $query->execute([$notice['ticket_id'],$notice['message_id'],$notice['no_contact_message_id']]);
    return count($query->fetchAll(PDO::FETCH_COLUMN)) === 2;
}

function westy_hardware_staff_message(array $source, int $ticketId): array
{
    return ['[#'.$ticketId.'] Hardware upgrade options for staff review',
        "Westy recorded these hardware upgrade options. Milepost reported no email linked to the device.\n\n"
        .utf8_clean($source['summary'])."\n\nUpgrade options:\n".utf8_clean($source['upgrade_options'])
        ."\n\nSafeharbor case #".$ticketId.": https://safeharbor.8westit.com/ticket.php?id=".$ticketId];
}

function westy_hardware_staff_send(string $email, string $subject, string $body, ?callable $httpPost = null): array
{
    require_once __DIR__.'/mailer.php';
    require_once __DIR__.'/support_addresses.php';
    $supportConfig = (array)cfg('support_addresses', []);
    $supportGraph = support_graph_config($supportConfig, mailer_graph_config());
    if ($supportGraph !== null) {
        return mailer_graph_submission_evidence(mailer_send_graph_result($supportGraph, $email, $subject, $body, $httpPost));
    }
    return mail_send_result($email, $subject, $body, null, $httpPost);
}

function westy_hardware_staff_evidence(callable $sender, string $email, string $subject, string $body): array
{
    try {
        $result = $sender($email,$subject,$body);
        if (is_array($result) && in_array($result['outcome'] ?? null,['failed','uncertain','provider_accepted'],true)
            && in_array($result['transport'] ?? null,['none','graph','smtp','php_mail'],true)
            && is_string($result['outcome_code'] ?? null) && preg_match('/\A[a-z0-9_]{1,64}\z/D',$result['outcome_code']) === 1
            && (isset($result['provider_http']) ? is_int($result['provider_http']) && $result['provider_http']>=100 && $result['provider_http']<=599 : true)) {
            $result['provider_http'] ??= null;
            return $result;
        }
    } catch (Throwable) { /* A missing result does not prove that no submission took place. */ }
    return ['outcome'=>'uncertain','transport'=>'none','provider_http'=>null,'outcome_code'=>'submission_unknown'];
}

function westy_hardware_staff_finish(PDO $pdo, int $tenantId, array $staff, array $result, string $state): void
{
    $now = gmdate('Y-m-d H:i:s'); $attempts = (int)$staff['send_attempts'];
    $query = $pdo->prepare('UPDATE westy_hardware_staff_attempts SET outcome=?,transport=?,provider_http=?,outcome_code=?,completed_at=?
        WHERE tenant_id=? AND staff_notice_id=? AND attempt_number=? AND completed_at IS NULL');
    $query->execute([$result['outcome'],$result['transport'],$result['provider_http'],$result['outcome_code'],$now,$tenantId,$staff['id'],$attempts]);
    if ($query->rowCount() !== 1) throw new RuntimeException('staff_attempt_not_claimed');
    $next = $state === 'failed' && $attempts < 5 ? gmdate('Y-m-d H:i:s',time()+$attempts**2*60) : null;
    $pdo->prepare('UPDATE westy_hardware_staff_notices SET state=?,detail_code=?,next_try_at=?,updated_at=? WHERE tenant_id=? AND id=?')
        ->execute([$state,$result['outcome_code'],$next,$now,$tenantId,$staff['id']]);
    $pdo->prepare('UPDATE mail_queue SET attempts=?,last_error=?,sent_at=? WHERE id=? AND max_attempts=0 AND sent_at IS NULL')
        ->execute([$attempts,$state === 'provider_accepted' ? '' : $result['outcome_code'],$state === 'provider_accepted' ? $now : null,$staff['mail_queue_id']]);
}

/** Commit one held queue identity and attempt before any provider call. */
function westy_hardware_staff_dispatch(PDO $pdo, int $tenantId, int $noticeId, array $settings, ?callable $sender = null): string
{
    $settings = westy_workflow_settings($settings);
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    $snapshot = westy_workflow_row($pdo,'SELECT source_json FROM westy_hardware_notices WHERE tenant_id=? AND id=?',[$tenantId,$noticeId]);
    if (!$snapshot) return 'not_applicable';
    $source = json_decode($snapshot['source_json'],true,8,JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        [$scope,$notice,$staff] = westy_hardware_staff_lock($pdo,$settings,$source,$tenantId,$noticeId);
        $now = gmdate('Y-m-d H:i:s');
        if ($staff && $staff['state'] === 'sending') {
            if ($staff['next_try_at'] <= $now) {
                westy_hardware_staff_finish($pdo,$tenantId,$staff,['outcome'=>'uncertain','transport'=>'none','provider_http'=>null,'outcome_code'=>'interrupted_submission'],'uncertain');
                $pdo->commit(); return 'uncertain';
            }
            $pdo->commit(); return 'sending';
        }
        if ($staff && ($staff['state'] !== 'failed' || $staff['next_try_at'] === null || $staff['next_try_at'] > $now)) {
            $pdo->commit(); return $staff['state'];
        }
        if (!$staff && (!$notice || $notice['state'] !== 'no_device_contact')) { $pdo->commit(); return 'not_applicable'; }
        [$recipient,$reason] = westy_hardware_staff_applicable($pdo,$scope,$notice)
            ? westy_hardware_staff_recipient($pdo,$tenantId,$scope['ticket']['assignee_id'] === null ? null : (int)$scope['ticket']['assignee_id'])
            : [null,'source_no_longer_eligible'];
        if (!$recipient || ($staff && !westy_hardware_staff_matches($staff,$recipient))) {
            if ($staff) {
                $state = 'suppressed';
                $pdo->prepare('UPDATE westy_hardware_staff_notices SET state=?,detail_code=?,next_try_at=NULL,updated_at=? WHERE tenant_id=? AND id=?')
                    ->execute([$state,$reason === 'source_no_longer_eligible' ? $reason : 'staff_recipient_changed',$now,$tenantId,$staff['id']]);
            } else {
                $state = $reason === 'source_no_longer_eligible' ? 'suppressed' : 'no_recipient';
                $pdo->prepare('INSERT INTO westy_hardware_staff_notices(tenant_id,notice_id,state,detail_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
                    ->execute([$tenantId,$noticeId,$state,$reason,$now,$now]);
            }
            $pdo->commit(); return $state;
        }
        [$subject,$body] = westy_hardware_staff_message($source,(int)$notice['ticket_id']);
        $attempt = $staff ? (int)$staff['send_attempts']+1 : 1;
        $reconcileAt = gmdate('Y-m-d H:i:s',time()+120);
        if (!$staff) {
            $pdo->prepare('INSERT INTO mail_queue(to_addr,subject,body_text,ticket_id,max_attempts) VALUES(?,?,?,?,0)')
                ->execute([utf8_clean($recipient['email']),$subject,$body,$notice['ticket_id']]);
            $mailId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO westy_hardware_staff_notices(tenant_id,notice_id,recipient_user_id,recipient_role,recipient_email,mail_queue_id,state,detail_code,send_attempts,next_try_at,created_at,updated_at)
                VALUES(?,?,?,?,?,?,'sending','submission_claimed',?,?,?,?)")
                ->execute([$tenantId,$noticeId,$recipient['id'],$recipient['role'],utf8_clean($recipient['email']),$mailId,$attempt,$reconcileAt,$now,$now]);
            $staffId = (int)$pdo->lastInsertId();
        } else {
            $staffId = (int)$staff['id'];
            $pdo->prepare("UPDATE westy_hardware_staff_notices SET state='sending',detail_code='submission_claimed',send_attempts=?,next_try_at=?,updated_at=? WHERE tenant_id=? AND id=?")
                ->execute([$attempt,$reconcileAt,$now,$tenantId,$staffId]);
        }
        $pdo->prepare('INSERT INTO westy_hardware_staff_attempts(tenant_id,staff_notice_id,attempt_number,attempted_at) VALUES(?,?,?,?)')
            ->execute([$tenantId,$staffId,$attempt,$now]);
        $pdo->commit();

        $pdo->beginTransaction();
        [$scope,$notice,$staff] = westy_hardware_staff_lock($pdo,$settings,$source,$tenantId,$noticeId);
        if (!$staff || (int)$staff['id'] !== $staffId || $staff['state'] !== 'sending' || (int)$staff['send_attempts'] !== $attempt) {
            $pdo->commit(); return $staff['state'] ?? 'not_applicable';
        }
        $problem = 'source_no_longer_eligible';
        if (westy_hardware_staff_applicable($pdo,$scope,$notice)) {
            [$current] = westy_hardware_staff_recipient($pdo,$tenantId,$scope['ticket']['assignee_id'] === null ? null : (int)$scope['ticket']['assignee_id']);
            $problem = 'staff_recipient_changed';
            if (westy_hardware_staff_matches($staff,$current)) {
                $mail = westy_workflow_row($pdo,'SELECT * FROM mail_queue WHERE id=?'.westy_workflow_lock($pdo),[$staff['mail_queue_id']]);
                $problem = !$mail || (int)$mail['ticket_id'] !== (int)$notice['ticket_id'] || (int)$mail['max_attempts'] !== 0
                    || $mail['sent_at'] !== null || (int)$mail['attempts'] !== $attempt-1 || $mail['to_addr'] !== $current['email']
                    || $mail['subject'] !== $subject || $mail['body_text'] !== $body ? 'staff_mail_queue_changed' : null;
            }
        }
        if ($problem !== null) {
            $result = ['outcome'=>'failed','transport'=>'none','provider_http'=>null,'outcome_code'=>$problem];
            $state = 'suppressed';
        } else {
            $result = westy_hardware_staff_evidence($sender ?? 'westy_hardware_staff_send',$staff['recipient_email'],$subject,$body);
            $state = $result['outcome'];
        }
        westy_hardware_staff_finish($pdo,$tenantId,$staff,$result,$state);
        $pdo->commit(); return $state;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Optional signed read; never expose staff addresses, message text, or provider payloads. */
function westy_hardware_staff_status(PDO $pdo, array $scope, array $p): array
{
    $tenantId = $scope['tenant_id']; $workflowId = (int)$scope['run']['id'];
    $notice = westy_workflow_row($pdo,'SELECT n.id,n.message_id,n.no_contact_message_id FROM westy_hardware_findings f
        JOIN westy_hardware_notices n ON n.tenant_id=f.tenant_id AND n.id=f.notice_id AND n.workflow_id=f.workflow_id
        WHERE f.tenant_id=? AND f.finding_key=? AND f.workflow_id=? AND n.ticket_id=?',
        [$tenantId,$p['finding_key'],$workflowId,$scope['ticket']['id']]);
    if (!$notice) throw new WestyWorkflowConflict('hardware_finding_not_found');
    $staff = westy_workflow_row($pdo,'SELECT id,recipient_user_id,recipient_role,mail_queue_id,state,detail_code,send_attempts
        FROM westy_hardware_staff_notices WHERE tenant_id=? AND notice_id=?',[$tenantId,$notice['id']]);
    $attempt = !$staff || (int)$staff['send_attempts'] === 0 ? null : westy_workflow_row($pdo,
        'SELECT attempt_number,outcome,transport,provider_http,attempted_at,completed_at FROM westy_hardware_staff_attempts
        WHERE tenant_id=? AND staff_notice_id=? AND attempt_number=?',[$tenantId,$staff['id'],$staff['send_attempts']]);
    if ($staff && (int)$staff['send_attempts'] !== 0 && !$attempt) throw new RuntimeException('staff_attempt_missing');
    $stamp = static fn(string $date): string => str_replace(' ','T',$date).'Z';
    return ['schema_version'=>3,'ok'=>true,'action'=>'hardware_staff_status','event_key'=>$p['event_key'],
        'workflow_key'=>$p['workflow_key'],'tenant_slug'=>$p['tenant_slug'],'customer_id'=>$p['customer_id'],'alert_key'=>$p['alert_key'],
        'ticket_id'=>(int)$scope['ticket']['id'],'finding_key'=>$p['finding_key'],'notice_id'=>(int)$notice['id'],
        'recommendation_message_id'=>(int)$notice['message_id'],
        'no_device_contact_message_id'=>$notice['no_contact_message_id'] === null ? null : (int)$notice['no_contact_message_id'],
        'staff_notice'=>$staff === null ? null : ['id'=>(int)$staff['id'],
            'recipient_user_id'=>$staff['recipient_user_id'] === null ? null : (int)$staff['recipient_user_id'],'recipient_role'=>$staff['recipient_role'],
            'mail_queue_id'=>$staff['mail_queue_id'] === null ? null : (int)$staff['mail_queue_id'],
            'state'=>$staff['state'] === 'sending' ? 'queued' : $staff['state'],'detail_code'=>$staff['detail_code'],
            'attempt'=>$attempt === null ? null : ['number'=>(int)$attempt['attempt_number'],'outcome'=>$attempt['outcome'],'transport'=>$attempt['transport'],
                'provider_http'=>$attempt['provider_http'] === null ? null : (int)$attempt['provider_http'],
                'attempted_at'=>$stamp($attempt['attempted_at']),'completed_at'=>$attempt['completed_at'] === null ? null : $stamp($attempt['completed_at'])]]];
}

function westy_hardware_staff_dispatch_due(PDO $pdo, array $config, ?callable $sender = null): void
{
    if (($config['enabled'] ?? false) !== true) return;
    try {
        $query = $pdo->prepare("SELECT tenant_id,notice_id FROM westy_hardware_staff_notices
            WHERE state IN ('sending','failed') AND next_try_at<=? ORDER BY next_try_at,id LIMIT 5");
        $query->execute([gmdate('Y-m-d H:i:s')]); $due = $query->fetchAll(PDO::FETCH_ASSOC);
        $fresh = $pdo->query("SELECT n.tenant_id,n.id AS notice_id FROM westy_hardware_notices n
            LEFT JOIN westy_hardware_staff_notices s ON s.tenant_id=n.tenant_id AND s.notice_id=n.id
            WHERE n.state='no_device_contact' AND s.id IS NULL ORDER BY n.id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1146) error_log('Hardware staff queue unavailable: '.$error::class);
        return;
    }
    foreach (array_merge($due,$fresh) as $row) {
        try { westy_hardware_staff_dispatch($pdo,(int)$row['tenant_id'],(int)$row['notice_id'],$config,$sender); }
        catch (Throwable $error) { error_log('Hardware staff dispatch: '.$error::class); }
    }
}
