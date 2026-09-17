<?php
/** Human-reviewed advice only. No endpoint, billing, or automatic case-resolution calls. */
declare(strict_types=1);
require_once __DIR__.'/westy_workflow.php';

function westy_email_enabled(): bool { return cfg('westy_email.enabled',false) === true; }
function westy_email_row(PDO $p,string $sql,array $args=[]): ?array {
    $q=$p->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function westy_email_hash(array $v): string { return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR)); }
function westy_email_address(string $email): string {
    $email=strtolower(trim($email));
    if (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]|^(?:mailer-daemon|postmaster|no[._-]?reply|do[._-]?not[._-]?reply|bounces?|autoreply)@/i',$email)) {
        throw new RuntimeException('A valid person’s email address is required.');
    }
    return $email;
}
/** Current tenant, customer, ticket and contact locks stay held through each mutation. */
function westy_email_context(PDO $p,int $tenant,int $ticketId,int $actor,bool $admin=false): array {
    if (!$p->inTransaction()) throw new LogicException('Transaction required');
    if ((int)$p->query('SELECT westy_email_schema_health()')->fetchColumn()!==1) throw new RuntimeException('Email safety controls are unavailable.');
    $lock=westy_workflow_lock($p);
    $t=westy_email_row($p,'SELECT id,slug FROM tenants WHERE id=?'.$lock,[$tenant]);
    $u=westy_email_row($p,'SELECT * FROM users WHERE id=? AND tenant_id=?'.$lock,[$actor,$tenant]);
    if (!$t || !$u || !(int)$u['is_active'] || !in_array($u['role'],$admin?['owner','admin']:['owner','admin','tech'],true)) throw new RuntimeException('Current staff authority is required.');
    $ticket=westy_email_row($p,'SELECT * FROM tickets WHERE id=? AND tenant_id=?'.$lock,[$ticketId,$tenant]);
    if (!$ticket || $ticket['channel']!=='alert' || $ticket['merged_into_id']!==null) throw new RuntimeException('An unmerged alert case is required.');
    $w=westy_email_row($p,'SELECT * FROM westy_workflows WHERE ticket_id=? AND tenant_id=?'.$lock,[$ticketId,$tenant]);
    $b=$w?westy_email_row($p,"SELECT * FROM suite_customer_sync_bindings WHERE tenant_id=? AND client_id=? AND customer_id=? AND status='active'".$lock,[$tenant,$ticket['client_id'],$w['customer_id']]):null;
    if (!$w || !$b || $w['alert_key']!==$ticket['external_key'] || (int)$w['client_id']!==(int)$ticket['client_id']) throw new RuntimeException('Current customer and source-case binding is required.');
    if (!in_array($tenant,cfg('westy_email.tenant_ids',[]),true) || !in_array($w['customer_id'],cfg('westy_email.customer_ids',[]),true)) throw new RuntimeException('This customer is outside the approved email scope.');
    $route=westy_email_row($p,'SELECT * FROM westy_client_poc WHERE tenant_id=? AND client_id=? ORDER BY id DESC LIMIT 1'.$lock,[$tenant,$ticket['client_id']]);
    $contactId=$ticket['contact_id'] ?: ($route['contact_id']??null);
    $contact=$contactId?westy_email_row($p,'SELECT * FROM contacts WHERE id=? AND client_id=?'.$lock,[$contactId,$ticket['client_id']]):null;
    if ($contactId && !$contact) throw new RuntimeException('The selected contact no longer belongs to this customer.');
    $messages=$p->prepare('SELECT id,kind,body,created_at FROM messages WHERE ticket_id=? ORDER BY id'.$lock);
    $messages->execute([$ticketId]);$evidence=$messages->fetchAll(PDO::FETCH_ASSOC);
    return ['ticket'=>$ticket,'workflow'=>$w,'actor'=>$u,'contact'=>$contact,'route'=>$ticket['contact_id']?'endpoint':'client',
        'fingerprint'=>westy_email_hash([$ticket,$w,$b,$route,$contact,$evidence,cfg('mail.graph.sender','')])];
}
function westy_email_contact(PDO $p,int $tenant,int $ticket,int $actor,int $contact,string $scope): void {
    if (!westy_email_enabled()) throw new RuntimeException('Westy email is not enabled.');
    $p->beginTransaction();try {
        $c=westy_email_context($p,$tenant,$ticket,$actor,true);
        if (!in_array($scope,['endpoint','client'],true)) throw new RuntimeException('Invalid contact scope.');
        if ($contact && !westy_email_row($p,'SELECT id FROM contacts WHERE id=? AND client_id=? FOR UPDATE',[$contact,$c['ticket']['client_id']])) throw new RuntimeException('Select a contact from this customer.');
        if ($scope==='endpoint') $p->prepare('UPDATE tickets SET contact_id=? WHERE id=? AND tenant_id=?')->execute([$contact?:null,$ticket,$tenant]);
        else $p->prepare('INSERT INTO westy_client_poc(tenant_id,client_id,contact_id,actor_id) VALUES(?,?,?,?)')->execute([$tenant,$c['ticket']['client_id'],$contact?:null,$actor]);
        $p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
/** Conservative template: no inferred hardware defect, invented specifications or prices. */
function westy_email_template(array $c): array {
    $name=$c['contact']['name']??'there';$id=(int)$c['ticket']['id'];
    $subject='[#'.$id.'] Laptop memory alert — your options';
    if (!preg_match('/\]\s+mem on /',$c['ticket']['subject'])) throw new RuntimeException('This first advice recipe supports memory alert cases only.');
    $body="Hi {$name},\n\nWe received a high-memory-use alert for your laptop. High usage alone does not prove that the RAM is faulty or that an upgrade is required. We have not changed your laptop or confirmed a software repair.\n\nYour options:\n1. Reduce the number of applications and browser tabs open at the same time, if that fits your work. Save your work before closing anything.\n2. Ask us to review the existing findings. Any additional diagnostic work needs separate authorization.\n3. If your normal workload needs more memory, we can check whether your exact laptop supports a compatible RAM upgrade.\n4. If memory cannot be expanded or an upgrade is unsuitable, we can discuss a replacement laptop that fits your workload.\n\nPlease reply with your preferred next step and whether you are still noticing slowdowns. We will confirm compatibility and any costs before you commit. No purchase is being made.\n\nWesty, on behalf of 8 West IT\nSafeharbor case #{$id}";
    return ['subject'=>$subject,'body'=>$body];
}
function westy_email_save(PDO $p,int $tenant,int $ticket,int $actor,string $subject,string $body,string $key,string $expected): int {
    if (!westy_email_enabled()) throw new RuntimeException('Westy email is not enabled.');
    if (!preg_match('/\A[0-9a-f]{32}\z/D',$key) || trim($subject)==='' || strlen($subject)>190 || preg_match('/[\r\n]/',$subject) || trim($body)==='' || strlen($body)>12000) throw new RuntimeException('Review the message fields.');
    preg_match_all('/\[#([0-9]+)\]/',$subject,$refs);
    if (!str_starts_with($subject,'[#'.$ticket.'] ') || $refs[1]!==[(string)$ticket]) throw new RuntimeException('Keep this case’s subject reference so replies return to the correct case.');
    $p->beginTransaction();try {
        $c=westy_email_context($p,$tenant,$ticket,$actor);
        $old=westy_email_row($p,'SELECT * FROM westy_email_drafts WHERE tenant_id=? AND request_key=?',[$tenant,$key]);
        if($old){if((int)$old['ticket_id']!==$ticket || $old['subject']!==$subject || $old['body_text']!==$body)throw new RuntimeException('Draft request changed.');$p->commit();return (int)$old['id'];}
        if (!hash_equals($c['fingerprint'],$expected)) throw new RuntimeException('Case or contact changed. Reload and review again.');
        if (!$c['contact']) throw new RuntimeException('Choose the endpoint POC or client POC first.');
        $to=westy_email_address($c['contact']['email']);
        if(westy_email_row($p,'SELECT id FROM westy_email_drafts WHERE tenant_id=? AND ticket_id=? AND attempted_at IS NOT NULL',[$tenant,$ticket])) throw new RuntimeException('A send was already attempted for this case. Review its result; do not resend.');
        $p->prepare("UPDATE westy_email_drafts SET state='revoked',detail='superseded_by_new_draft' WHERE tenant_id=? AND ticket_id=? AND state='draft'")->execute([$tenant,$ticket]);
        $p->prepare('INSERT INTO westy_email_drafts(tenant_id,ticket_id,client_id,contact_id,recipient,subject,body_text,context_sha256,created_by,request_key) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$tenant,$ticket,$c['ticket']['client_id'],$c['contact']['id'],$to,$subject,$body,$c['fingerprint'],$actor,$key]);
        $id=(int)$p->lastInsertId();$p->commit();return $id;
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
/** Signed identity feed failures stop email even though ordinary browsing can fail open. */
function westy_email_identity(array $u): bool {
    if (empty($u['suite_subject'])) return true;
    require_once __DIR__.'/revocation.php';
    $snapshot=revocation_list();
    return $snapshot!==null && suite_revocation_decision($snapshot,$u['suite_subject'],$_SESSION['suite_session_version']??null)['action']==='allow';
}
function westy_email_review_hash(array $d): string {return westy_email_hash([$d['id'],$d['recipient'],$d['subject'],$d['body_text'],$d['context_sha256']]);}
/** Commit intent BEFORE the network call. A crash or uncertain reply never permits another send. */
function westy_email_send(PDO $p,int $tenant,int $ticket,int $actor,int $id,string $review,?callable $transport=null,?callable $identity=null): string {
    $identity??='westy_email_identity';
    $p->beginTransaction();try {
        $c=westy_email_context($p,$tenant,$ticket,$actor,true);
        $d=westy_email_row($p,'SELECT * FROM westy_email_drafts WHERE id=? AND tenant_id=? AND ticket_id=? FOR UPDATE',[$id,$tenant,$ticket]);
        if (!$d) throw new RuntimeException('Draft not found.');
        if ($d['attempted_at']!==null || $d['state']!=='draft') {$p->commit();return $d['state'];}
        if ($transport===null) {require_once __DIR__.'/mailer.php';if(!mailer_graph_config())throw new RuntimeException('Microsoft mail is not configured. No send was attempted.');}
        if (!westy_email_enabled() || !$identity($c['actor']) || !hash_equals(westy_email_review_hash($d),$review) || !hash_equals($d['context_sha256'],$c['fingerprint']) || strtotime($d['created_at'].' UTC')<time()-86400) throw new RuntimeException('Approval is stale or authority changed. Save and review a fresh draft.');
        if (westy_email_row($p,'SELECT id FROM westy_email_drafts WHERE tenant_id=? AND ticket_id=? AND attempted_at IS NOT NULL FOR UPDATE',[$tenant,$ticket])) throw new RuntimeException('This case already has a send attempt.');
        $p->prepare("UPDATE westy_email_drafts SET state='uncertain',approved_by=?,approved_at=UTC_TIMESTAMP(),attempted_at=UTC_TIMESTAMP(),detail='send_claimed_result_unknown' WHERE id=?")->execute([$actor,$id]);
        $p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    // Recheck after the durable claim and serialize ticket/contact/actor changes during submission.
    $p->beginTransaction();try {
        $c=westy_email_context($p,$tenant,$ticket,$actor,true);
        $live=westy_email_row($p,'SELECT * FROM westy_email_drafts WHERE id=? AND tenant_id=? FOR UPDATE',[$id,$tenant]);
        if (!westy_email_enabled() || !$identity($c['actor']) || $live['state']!=='uncertain' || !hash_equals($d['context_sha256'],$c['fingerprint'])) throw new RuntimeException('authority_changed');
        if ($transport===null) {
            require_once __DIR__.'/mailer.php';
            $g=mailer_graph_config();if (!$g) throw new RuntimeException('graph_unavailable');
            $result=mailer_send_graph_result($g,$d['recipient'],$d['subject'],$d['body_text']);
        }else $result=$transport($d);
        $submitted=($result['outcome']??'')==='submitted' && ($result['provider_http']??null)===202;
        $state=$submitted?'submitted':'uncertain';
        $p->prepare('UPDATE westy_email_drafts SET state=?,detail=?,provider_http=? WHERE id=?')->execute([$state,$submitted?'graph_accepted_inbox_unverified':'provider_result_unknown',$result['provider_http']??null,$id]);
        $p->commit();return $state;
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();return 'uncertain';}
}
function westy_email_revoke(PDO $p,int $tenant,int $ticket,int $actor,int $id): void {
    $p->beginTransaction();try {
        westy_email_context($p,$tenant,$ticket,$actor,true);
        $p->prepare("UPDATE westy_email_drafts SET state='revoked',detail='revoked_by_staff' WHERE id=? AND tenant_id=? AND ticket_id=? AND state='draft' AND attempted_at IS NULL")->execute([$id,$tenant,$ticket]);
        $p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
