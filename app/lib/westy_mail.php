<?php
/** Dedicated, reviewed Westy email.  Never uses mail_queue or repair controls. */
declare(strict_types=1);
require_once __DIR__ . '/westy_workflow.php';
require_once __DIR__ . '/westy_email.php';
require_once __DIR__ . '/westy_mail_graph.php';

function westy_mail_row(PDO $p, string $sql, array $args=[]): ?array { $q=$p->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC) ?: null; }
function westy_mail_hash(mixed $v): string { return hash('sha256', is_string($v) ? $v : json_encode($v, JSON_THROW_ON_ERROR)); }
function westy_mail_address(string $v): string { return westy_email_address($v); }
function westy_mail_enabled(): bool { return cfg('westy_mail.enabled',false) === true; }
function westy_mail_template(): array { $body="Thanks for your reply. I’ve added it to this case. Frankie will review your preferred next step and confirm any compatibility and costs before work or purchases. No laptop changes or purchases have been authorized."; return ['id'=>'acknowledgment','version'=>1,'body'=>$body,'sha256'=>hash('sha256',$body)]; }
function westy_mail_graph_snapshot(): array {
    $g=function_exists('westy_mail_graph_config') ? westy_mail_graph_config() : null;
    if (!is_array($g)) throw new RuntimeException('Dedicated Westy mail is not configured.');
    $sender=westy_mail_address((string)($g['sender']??''));
    if ($sender!=='westy@8westit.com') throw new RuntimeException('Only the dedicated Westy mailbox is permitted.');
    $identity=function_exists('westy_mail_graph_identity') ? westy_mail_graph_identity($g) : westy_mail_hash([$g['tenant_id']??'', $g['client_id']??'', $sender]);
    if (!is_string($identity) || !preg_match('/\A[0-9a-f]{64}\z/D',$identity)) $identity=westy_mail_hash($identity);
    return ['graph'=>$g,'sender'=>$sender,'identity_sha256'=>$identity];
}
/** Shared lock order, but this context has its own dedicated-mail fingerprint. */
function westy_mail_context(PDO $p,int $tenant,int $ticketId,int $actor,bool $admin=false): array {
    if (!$p->inTransaction()) throw new LogicException('Transaction required');
    if ((int)$p->query('SELECT westy_mail_schema_health()')->fetchColumn()!==1) throw new RuntimeException('Westy mail safety controls are unavailable.');
    $lock=westy_workflow_lock($p);
    $u=westy_mail_row($p,'SELECT * FROM users WHERE id=? AND tenant_id=?'.$lock,[$actor,$tenant]);
    $ticket=westy_mail_row($p,'SELECT * FROM tickets WHERE id=? AND tenant_id=?'.$lock,[$ticketId,$tenant]);
    if (!$u || !(int)$u['is_active'] || !in_array($u['role'],$admin?['owner','admin']:['owner','admin','tech'],true) || !$ticket || $ticket['merged_into_id']!==null || $ticket['channel']!=='alert') throw new RuntimeException('Current staff and an unmerged alert case are required.');
    $w=westy_mail_row($p,'SELECT * FROM westy_workflows WHERE tenant_id=? AND ticket_id=?'.$lock,[$tenant,$ticketId]);
    $b=$w ? westy_mail_row($p,"SELECT * FROM suite_customer_sync_bindings WHERE tenant_id=? AND client_id=? AND customer_id=? AND status='active'".$lock,[$tenant,$ticket['client_id'],$w['customer_id']]) : null;
    if (!$w || !$b || (int)$w['client_id']!==(int)$ticket['client_id'] || $w['alert_key']!==$ticket['external_key']) throw new RuntimeException('Current customer and source-case binding is required.');
    if (!in_array($tenant,cfg('westy_mail.tenant_ids',[]),true) || !in_array($w['customer_id'],cfg('westy_mail.customer_ids',[]),true)) throw new RuntimeException('This customer is outside the approved Westy mail scope.');
    $route=westy_mail_row($p,'SELECT * FROM westy_client_poc WHERE tenant_id=? AND client_id=? ORDER BY id DESC LIMIT 1'.$lock,[$tenant,$ticket['client_id']]);
    $contactId=(int)(($ticket['contact_id']??0) ?: ($route['contact_id']??0)); $contact=$contactId ? westy_mail_row($p,'SELECT * FROM contacts WHERE id=? AND client_id=?'.$lock,[$contactId,$ticket['client_id']]) : null;
    if (!$contact) throw new RuntimeException('Choose an endpoint POC or client POC first.');
    $graph=westy_mail_graph_snapshot();
    $messages=$p->prepare('SELECT id,kind,body,created_at FROM messages WHERE ticket_id=? ORDER BY id'.$lock); $messages->execute([$ticketId]);
    $alias=westy_mail_alias_snapshot(compact('contact','w'));
    $staff=[$u['id'],$u['tenant_id'],$u['role'],$u['is_active'],$u['suite_subject']??null];
    $authority_sha256=westy_mail_hash([$staff,$ticket,$w,$b,$route,$contact,$alias,$graph['sender'],$graph['identity_sha256']]);
    $fingerprint=westy_mail_hash([$authority_sha256,$messages->fetchAll(PDO::FETCH_ASSOC)]);
    return compact('u','ticket','w','b','contact','graph','fingerprint','authority_sha256','alias');
}
function westy_mail_identity_ok(array $user,mixed $version=null): bool {
    if (empty($user['suite_subject'])) return !empty($user['is_active']);
    require_once __DIR__.'/suite_revocation_policy.php';
    if (!function_exists('revocation_list')) require_once __DIR__.'/revocation.php';
    $snapshot=revocation_list(); $version??=$_SESSION['suite_session_version']??null;
    return $snapshot!==null && suite_revocation_decision($snapshot,(string)$user['suite_subject'],$version)['action']==='allow';
}
function westy_mail_alias_snapshot(array $c): ?string { $all=cfg('westy_email.graph.reviewed_reply_aliases',[]); $entry=is_array($all)?($all[(string)$c['contact']['id']]??null):null; if(!is_array($entry))return null; if(($entry['canonical_recipient']??'')!==strtolower($c['contact']['email']) || ($entry['customer_id']??'')!==$c['w']['customer_id'] || !is_string($entry['address']??null) || !is_string($entry['directory_mailbox_guid']??null) || !is_string($entry['proof_sha256']??null) || !preg_match('/\A[0-9a-f-]{36}\z/D',$entry['directory_mailbox_guid']) || !preg_match('/\A[0-9a-f]{64}\z/D',$entry['proof_sha256'])) throw new RuntimeException('Reviewed reply alias configuration is invalid.'); return json_encode(['canonical_recipient'=>strtolower($c['contact']['email']),'customer_id'=>$c['w']['customer_id'],'address'=>strtolower($entry['address']),'directory_mailbox_guid'=>$entry['directory_mailbox_guid'],'proof_sha256'=>$entry['proof_sha256']],JSON_THROW_ON_ERROR); }
function westy_mail_admin(PDO $p,int $tenant,int $ticket,int $actor): array { if(!westy_mail_enabled()) throw new RuntimeException('Westy email is disabled.'); $c=westy_mail_context($p,$tenant,$ticket,$actor,true); if (!westy_mail_identity_ok($c['u'])) throw new RuntimeException('Current identity is unavailable.'); return $c; }
function westy_mail_reconcile_rejected(PDO $p,int $tenant,int $ticket,int $actor,int $oldDraft): int {
    $p->beginTransaction(); try { westy_mail_admin($p,$tenant,$ticket,$actor); $old=westy_mail_row($p,'SELECT * FROM westy_email_drafts WHERE id=? AND tenant_id=? AND ticket_id=? FOR UPDATE',[$oldDraft,$tenant,$ticket]); if (!$old || $old['state']!=='uncertain' || (int)$old['provider_http']!==404) throw new RuntimeException('Only the original definitive HTTP 404 attempt can be reconciled.');
        $prior=westy_mail_row($p,'SELECT * FROM westy_mail_reconciliations WHERE old_draft_id=? FOR UPDATE',[$oldDraft]); if ($prior) { $p->commit(); return (int)$prior['id']; }
        $evidenceHash=westy_mail_hash(['v'=>1,'id'=>(int)$old['id'],'http'=>(int)$old['provider_http'],'attempted_at'=>$old['attempted_at'],'body_sha256'=>hash('sha256',$old['body_text']),'detail'=>$old['detail']]);
        $p->prepare("INSERT INTO westy_mail_reconciliations(old_draft_id,actor_id,evidence_sha256,outcome) VALUES(?,?,?,'provider_rejected')")->execute([$oldDraft,$actor,$evidenceHash]); $id=(int)$p->lastInsertId(); $p->commit(); return $id;
    } catch(Throwable $e){ if($p->inTransaction())$p->rollBack(); throw $e; }
}
function westy_mail_create_replacement(PDO $p,int $tenant,int $ticket,int $actor,int $reconciliation,string $subject,string $body,string $token,string $requestKey,string $expected): int {
    if (!westy_mail_enabled() || !preg_match('/\A[a-f0-9]{32}\z/D',$token) || !preg_match('/\A[a-f0-9]{32}\z/D',$requestKey) || trim($body)==='' || strlen($body)>12000 || strlen($subject)>190 || preg_match('/[\r\n]/',$subject)) throw new RuntimeException('Review the replacement fields.');
    preg_match_all('/\[#([0-9]+)\]/',$subject,$refs); preg_match_all('/\[wm:([a-f0-9]{32})\]/i',$subject,$tokens); if ($refs[1]!==[(string)$ticket] || $tokens[1]!==[$token]) throw new RuntimeException('Keep exactly this case reference and reply token.');
    $p->beginTransaction(); try { $c=westy_mail_admin($p,$tenant,$ticket,$actor); if (!hash_equals($c['fingerprint'],$expected)) throw new RuntimeException('Case, contact, sender, or evidence changed. Reload.');
        $old=$reconciliation>0 ? westy_mail_row($p,"SELECT d.* FROM westy_mail_reconciliations r JOIN westy_email_drafts d ON d.id=r.old_draft_id WHERE r.id=? AND r.outcome='provider_rejected' FOR UPDATE",[$reconciliation]) : null;
        if ($reconciliation>0 && (!$old || (int)$old['tenant_id']!==$tenant || (int)$old['ticket_id']!==$ticket || $old['state']!=='uncertain' || (int)$old['provider_http']!==404)) throw new RuntimeException('A definitive rejected original attempt is required.');
        if ($reconciliation===0 && westy_mail_row($p,'SELECT id FROM westy_email_drafts WHERE tenant_id=? AND ticket_id=? AND attempted_at IS NOT NULL FOR UPDATE',[$tenant,$ticket])) throw new RuntimeException('A prior send requires definitive rejection reconciliation.');
        $prior=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE tenant_id=? AND request_key=? FOR UPDATE',[$tenant,$requestKey]); if($prior){ if((int)$prior['ticket_id']!==$ticket || $prior['subject']!==$subject || $prior['body_text']!==$body) throw new RuntimeException('Replacement request changed.'); $p->commit(); return (int)$prior['id']; }
        if(westy_mail_row($p,'SELECT id FROM westy_mail_conversations WHERE tenant_id=? AND ticket_id=? FOR UPDATE',[$tenant,$ticket])) throw new RuntimeException('Only one replacement conversation is allowed for this case.');
        $recipient=westy_mail_address($c['contact']['email']);$alias=westy_mail_alias_snapshot($c);$msgHash=westy_mail_hash([$recipient,$subject,$body,$c['graph']['sender'],$alias]);
        $p->prepare('INSERT INTO westy_mail_conversations(tenant_id,ticket_id,client_id,customer_id,contact_id,recipient,recipient_alias_json,sender,mail_identity_sha256,subject,body_text,message_sha256,context_sha256,authority_sha256,reply_token_sha256,request_key,message_key,replacement_of_draft_id,reconciliation_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$tenant,$ticket,$c['ticket']['client_id'],$c['w']['customer_id'],$c['contact']['id'],$recipient,$alias,$c['graph']['sender'],$c['graph']['identity_sha256'],$subject,$body,$msgHash,$c['fingerprint'],$c['authority_sha256'],hash('sha256',$token),$requestKey,bin2hex(random_bytes(16)),$old['id']??null,$reconciliation?:null,$actor]);
        $id=(int)$p->lastInsertId(); $p->commit(); return $id;
    } catch(Throwable $e){ if($p->inTransaction())$p->rollBack(); throw $e; }
}
function westy_mail_approve(PDO $p,int $tenant,int $ticket,int $actor,int $conversation,string $review,bool $delegate=false): void {
    $p->beginTransaction();
    try {
        $c=westy_mail_admin($p,$tenant,$ticket,$actor);
        $m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=? AND tenant_id=? AND ticket_id=? FOR UPDATE',[$conversation,$tenant,$ticket]);
        if (!$m || $m['state']!=='draft' || strtotime($m['created_at'].' UTC')<time()-86400
            || !hash_equals(westy_mail_review_hash($m),$review) || !hash_equals($m['context_sha256'],$c['fingerprint'])) throw new RuntimeException('Approval is stale. Review the current case.');
        require_once __DIR__.'/suite_revocation_policy.php';
        $version=$_SESSION['suite_session_version']??null;
        if (!suite_session_version_valid($version)) throw new RuntimeException('Sign in through 8 West ID again before approving mail.');
        if ($delegate && ($c['w']['state']??'')!=='human_owned') throw new RuntimeException('Automatic acknowledgment requires a case already owned by a technician.');
        $p->prepare("UPDATE westy_mail_conversations SET state='approved',approved_by=?,approved_at=UTC_TIMESTAMP(),approval_session_version=? WHERE id=?")->execute([$actor,$version,$conversation]);
        if ($delegate) {
            $t=westy_mail_template();
            $p->prepare('INSERT INTO westy_mail_delegations(conversation_id,template_id,template_version,template_sha256,expires_at,approved_by) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),?)')->execute([$conversation,$t['id'],$t['version'],$t['sha256'],$actor]);
        }
        $p->commit();
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
}
function westy_mail_review_hash(array $m): string {
    // The session stores this at render time. A deployment must not substitute
    // acknowledgment wording between that review and the approval POST.
    $t=westy_mail_template();
    return westy_mail_hash([$m['id'],$m['recipient'],$m['recipient_alias_json'],$m['sender'],$m['message_sha256'],$m['context_sha256'],$m['authority_sha256'],$m['reply_token_sha256'],[$t['id'],$t['version'],$t['sha256']]]);
}
function westy_mail_revoke(PDO $p,int $tenant,int $ticket,int $actor,int $conversation): void {
    $p->beginTransaction();
    try {
        // Stopping mail must work even when the certificate or kill switch is unavailable.
        $u=westy_mail_row($p,"SELECT * FROM users WHERE id=? AND tenant_id=? AND is_active=1 AND role IN ('owner','admin') FOR UPDATE",[$actor,$tenant]);
        if (!$u || !westy_mail_identity_ok($u)) throw new RuntimeException('Current administrator authority is required.');
        $m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=? AND tenant_id=? AND ticket_id=? FOR UPDATE',[$conversation,$tenant,$ticket]);
        if (!$m) throw new RuntimeException('Conversation not found.');
        if (in_array($m['state'],['draft','approved'],true)) $p->prepare("UPDATE westy_mail_conversations SET state='revoked' WHERE id=?")->execute([$conversation]);
        $p->prepare("INSERT IGNORE INTO westy_mail_conversation_revocations(conversation_id,reason) VALUES(?,'staff_revoked')")->execute([$conversation]);
        $p->prepare("INSERT IGNORE INTO westy_mail_delegation_revocations(delegation_id,reason) SELECT id,'staff_revoked' FROM westy_mail_delegations WHERE conversation_id=?")->execute([$conversation]);
        $p->commit();
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
}
function westy_mail_result_values(array $r): array {
    $http=$r['provider_http']??null;
    $definite=is_int($http) && $http>=400 && $http<500 && !in_array($http,[408,409,425,429],true);
    $state=($r['outcome']??'')==='submitted' && $http===202 ? 'submitted' : (($r['outcome']??'')==='rejected' && $definite?'rejected':'unknown');
    return [$state,is_int($r['provider_http']??null)?$r['provider_http']:null,substr((string)($r['provider_request_id']??''),0,190),substr((string)($r['outcome_code']??''),0,64)];
}
function westy_mail_send(PDO $p,int $tenant,int $ticket,int $actor,int $conversation,callable $transport,?callable $identity=null): string {
    $identity??='westy_mail_identity_ok';
    $p->beginTransaction();
    try {
        $c=westy_mail_admin($p,$tenant,$ticket,$actor);
        $m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=? AND tenant_id=? AND ticket_id=? FOR UPDATE',[$conversation,$tenant,$ticket]);
        if (!$m) throw new RuntimeException('Conversation not found.');
        // Competing callers reconcile the durable claim; they never change its owner or send.
        if ($m['state']!=='approved') { $p->commit(); return $m['state']; }
        if (westy_mail_row($p,'SELECT id FROM westy_mail_conversation_revocations WHERE conversation_id=? FOR UPDATE',[$conversation])) throw new RuntimeException('Email authority was revoked.');
        if (!$identity($c['u'],$m['approval_session_version']) || (int)$m['approved_by']!==$actor
            || strtotime($m['approved_at'].' UTC')<time()-900 || !hash_equals($m['context_sha256'],$c['fingerprint'])) throw new RuntimeException('Approval expired or authority changed.');
        $p->prepare("UPDATE westy_mail_conversations SET state='claimed',attempted_at=UTC_TIMESTAMP() WHERE id=?")->execute([$conversation]);
        $p->commit();
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
    $p->beginTransaction();
    try {
        $c=westy_mail_admin($p,$tenant,$ticket,$actor);
        $m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=? FOR UPDATE',[$conversation]);
        if (westy_mail_row($p,'SELECT id FROM westy_mail_conversation_revocations WHERE conversation_id=? FOR UPDATE',[$conversation])) throw new RuntimeException('authority_revoked');
        if (!$m || $m['state']!=='claimed' || !$identity($c['u'],$m['approval_session_version'])
            || !hash_equals($m['context_sha256'],$c['fingerprint'])) throw new RuntimeException('authority_changed');
        $result=$transport($c['graph']['graph'],array_intersect_key($m,array_flip(['recipient','subject','body_text','message_key'])));
        $values=westy_mail_result_values($result);
        $p->prepare('UPDATE westy_mail_conversations SET state=?,provider_http=?,provider_request_id=?,outcome_code=? WHERE id=?')->execute([...$values,$conversation]);
        $p->commit(); return $values[0];
    } catch(Throwable) { if($p->inTransaction())$p->rollBack(); return 'unknown'; }
}
/** Pure normalizer; only a connector that authenticated headers may set auth_verified. */
function westy_mail_parse_inbound(array $in): array {
    $subject=(string)($in['subject']??'');
    preg_match_all('/\[#([0-9]+)\]/',$subject,$tickets); preg_match_all('/\[wm:([a-f0-9]{32})\]/i',$subject,$tokens);
    try {
        $from=westy_mail_address((string)($in['from']??''));
        $reply=westy_mail_address((string)($in['source_reply_to']??$from));
        $destination=westy_mail_address((string)($in['destination']??''));
    } catch(Throwable) { return ['ok'=>false]; }
    $body=(string)($in['body']??'');
    $auto=($in['auto']??false)!==false || preg_match('/^(?:out of office|automatic reply|undeliverable|delivery status)/i',$subject);
    $ok=($in['auth_verified']??false)===true && !$auto && $from===$reply && $from!==$destination
        && count($tickets[1])===1 && count($tokens[1])===1 && strlen($subject)<=512 && strlen($body)<=12000
        && mb_check_encoding($body,'UTF-8') && mb_check_encoding($subject,'UTF-8');
    return ['ok'=>$ok,'ticket_id'=>(int)($tickets[1][0]??0),'token'=>strtolower((string)($tokens[1][0]??'')),
        'from'=>$from,'reply_to'=>$reply,'destination'=>$destination,'subject'=>$subject,'body'=>$body,
        'provider_id'=>trim((string)($in['provider_message_id']??'')),'internet_id'=>trim((string)($in['internet_message_id']??''))];
}
function westy_mail_reviewed_alias(array $m,string $address): bool { if(hash_equals($m['recipient'],$address)) return true; try{$entry=json_decode((string)($m['recipient_alias_json']??''),true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return false;} return is_array($entry) && ($entry['canonical_recipient']??'')===$m['recipient'] && ($entry['customer_id']??'')===$m['customer_id'] && is_string($entry['directory_mailbox_guid']??null) && preg_match('/\A[0-9a-f-]{36}\z/D',$entry['directory_mailbox_guid']) && is_string($entry['address']??null) && hash_equals(strtolower($entry['address']),$address) && is_string($entry['proof_sha256']??null) && preg_match('/\A[0-9a-f]{64}\z/D',$entry['proof_sha256']); }
function westy_mail_accept_inbound(PDO $p,int $tenant,array $input): array {
    if (!westy_mail_enabled() || !in_array($tenant,cfg('westy_mail.tenant_ids',[]),true)) throw new RuntimeException('Mailbox intake is disabled.');
    $i=westy_mail_parse_inbound($input);
    if (!$i['ok'] || $i['provider_id']==='' || strlen($i['provider_id'])>512) return westy_mail_hold_inbound($p,$tenant,$input,'inbound_mismatch');
    $p->beginTransaction();
    try {
        $hint=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE tenant_id=? AND ticket_id=?',[$tenant,$i['ticket_id']]);
        if (!$hint || !in_array($hint['customer_id'],cfg('westy_mail.customer_ids',[]),true)) throw new RuntimeException('inbound_mismatch');
        $c=westy_mail_context($p,$tenant,$i['ticket_id'],(int)$hint['approved_by'],true);
        $m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=? FOR UPDATE',[$hint['id']]);
        if (!$m || $m['state']!=='submitted' || !hash_equals($m['reply_token_sha256'],hash('sha256',$i['token']))
            || !hash_equals($m['sender'],$i['destination']) || !hash_equals($m['mail_identity_sha256'],$c['graph']['identity_sha256'])
            || (int)$m['contact_id']!==(int)$c['contact']['id'] || !hash_equals($m['recipient'],westy_mail_address($c['contact']['email']))
            || json_decode((string)($m['recipient_alias_json']??'null'),true)!=json_decode((string)($c['alias']??'null'),true)
            || !westy_mail_reviewed_alias($m,$i['from'])) throw new RuntimeException('inbound_mismatch');
        $provider=hash('sha256',$i['provider_id']);
        $prior=westy_mail_row($p,'SELECT id,conversation_id FROM westy_mail_inbound_receipts WHERE provider_message_sha256=? FOR UPDATE',[$provider]);
        if (!$prior && $i['internet_id']!=='') $prior=westy_mail_row($p,'SELECT id,conversation_id FROM westy_mail_inbound_receipts WHERE internet_message_sha256=? FOR UPDATE',[hash('sha256',$i['internet_id'])]);
        $held=westy_mail_row($p,'SELECT id FROM westy_mail_inbound_holds WHERE tenant_id=? AND (provider_message_sha256=? OR internet_message_sha256=?) FOR UPDATE',[$tenant,$provider,$i['internet_id']!==''?hash('sha256',$i['internet_id']):null]);
        if ($held) { $p->commit(); return ['state'=>'held']; }
        if ($prior) {
            if ((int)$prior['conversation_id']!==(int)$m['id']) throw new RuntimeException('inbound_mismatch');
            $p->commit(); return ['state'=>'duplicate','receipt_id'=>(int)$prior['id'],'conversation_id'=>(int)$prior['conversation_id']];
        }
        $body=$i['body']===''?'(no text body)':$i['body'];
        $p->prepare("INSERT INTO westy_mail_inbound_receipts(conversation_id,provider_message_sha256,internet_message_sha256,sender_sha256,reply_to_sha256,body_sha256,auth_verified,disposition) VALUES(?,?,?,?,?,?,1,'accepted')")
            ->execute([$m['id'],$provider,$i['internet_id']!==''?hash('sha256',$i['internet_id']):null,hash('sha256',$i['from']),hash('sha256',$i['reply_to']),hash('sha256',$body)]);
        $receipt=(int)$p->lastInsertId();
        $p->prepare("INSERT INTO messages(ticket_id,author_name,kind,body,westy_mail_receipt_id) VALUES(?,?,'client',?,?)")->execute([$m['ticket_id'],$i['from'],$body,$receipt]);
        $p->commit(); return ['state'=>'accepted','receipt_id'=>$receipt,'conversation_id'=>(int)$m['id']];
    } catch(RuntimeException $e) {
        if($p->inTransaction())$p->rollBack();
        if($e instanceof PDOException) throw $e;
        return westy_mail_hold_inbound($p,$tenant,$input,'case_or_authority_mismatch');
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
}
function westy_mail_hold_inbound(PDO $p,int $tenant,array $input,string $reason): array {
    $provider=trim((string)($input['provider_message_id']??''));
    if ($provider==='') return ['state'=>'held'];
    $from=strtolower(trim((string)($input['from']??'')));
    $reply=strtolower(trim((string)($input['source_reply_to']??$input['reply_to']??'')));
    $internet=trim((string)($input['internet_message_id']??''));
    $providerHash=hash('sha256',$provider); $internetHash=$internet!==''?hash('sha256',$internet):null;
    $p->beginTransaction();
    try {
        // Same read order in both receipt paths; a deadlock rolls back and leaves the cursor unchanged.
        $accepted=westy_mail_row($p,'SELECT id FROM westy_mail_inbound_receipts WHERE provider_message_sha256=? OR internet_message_sha256=? FOR UPDATE',[$providerHash,$internetHash]);
        if ($accepted) { $p->commit(); return ['state'=>'duplicate']; }
        $held=westy_mail_row($p,'SELECT id FROM westy_mail_inbound_holds WHERE tenant_id=? AND (provider_message_sha256=? OR internet_message_sha256=?) FOR UPDATE',[$tenant,$providerHash,$internetHash]);
        if (!$held) $p->prepare('INSERT INTO westy_mail_inbound_holds(tenant_id,provider_message_sha256,internet_message_sha256,sender_sha256,reply_to_sha256,reason,auth_verified) VALUES(?,?,?,?,?,?,?)')
            ->execute([$tenant,$providerHash,$internetHash,$from!==''?hash('sha256',$from):null,$reply!==''?hash('sha256',$reply):null,substr($reason,0,64),($input['auth_verified']??false)===true?1:0]);
        $p->commit(); return ['state'=>'held'];
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
}
function westy_mail_auto_authority(PDO $p,int $conversation,int $receipt): array {
    if (!westy_mail_enabled()) throw new RuntimeException('Automatic email is disabled.');
    $hint=westy_mail_row($p,'SELECT tenant_id,ticket_id,approved_by FROM westy_mail_conversations WHERE id=?',[$conversation]);
    if (!$hint) throw new RuntimeException('Conversation not found.');
    $c=westy_mail_context($p,(int)$hint['tenant_id'],(int)$hint['ticket_id'],(int)$hint['approved_by'],true);
    $row=westy_mail_row($p,"SELECT c.*,d.id delegation_id,d.template_id,d.template_version,d.template_sha256,d.expires_at,r.id receipt_id FROM westy_mail_conversations c JOIN westy_mail_delegations d ON d.conversation_id=c.id JOIN westy_mail_inbound_receipts r ON r.conversation_id=c.id LEFT JOIN westy_mail_delegation_revocations x ON x.delegation_id=d.id WHERE c.id=? AND r.id=? AND r.disposition='accepted' AND x.id IS NULL FOR UPDATE",[$conversation,$receipt]);
    $t=westy_mail_template();
    if (!$row || $row['state']!=='submitted' || strtotime($row['expires_at'].' UTC')<=time()
        || !hash_equals($row['authority_sha256'],$c['authority_sha256']) || ($c['w']['state']??'')!=='human_owned'
        || $row['template_id']!==$t['id'] || (int)$row['template_version']!==$t['version'] || !hash_equals($row['template_sha256'],$t['sha256'])
        || !westy_mail_identity_ok($c['u'],$row['approval_session_version'])) throw new RuntimeException('Automatic reply authority is unavailable or revoked.');
    return ['row'=>$row,'context'=>$c,'template'=>$t];
}
function westy_mail_auto_reply(PDO $p,int $conversation,int $receipt,callable $transport): string {
    $p->beginTransaction();
    try {
        $a=westy_mail_auto_authority($p,$conversation,$receipt); $row=$a['row'];
        $prior=westy_mail_row($p,'SELECT state FROM westy_mail_auto_attempts WHERE delegation_id=? FOR UPDATE',[$row['delegation_id']]);
        if ($prior) { $p->commit(); return $prior['state']; }
        $p->prepare('INSERT INTO westy_mail_auto_attempts(delegation_id,receipt_id,message_key) VALUES(?,?,?)')->execute([$row['delegation_id'],$receipt,bin2hex(random_bytes(16))]);
        $attemptId=(int)$p->lastInsertId(); $p->commit();
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
    $p->beginTransaction();
    try {
        $a=westy_mail_auto_authority($p,$conversation,$receipt); $row=$a['row'];
        $attempt=westy_mail_row($p,'SELECT * FROM westy_mail_auto_attempts WHERE id=? FOR UPDATE',[$attemptId]);
        if (!$attempt || $attempt['state']!=='claimed') throw new RuntimeException('The existing attempt must be reconciled.');
        $result=$transport($a['context']['graph']['graph'],['recipient'=>$row['recipient'],'subject'=>$row['subject'],'body_text'=>$a['template']['body'],'message_key'=>$attempt['message_key']]);
        $values=westy_mail_result_values($result);
        $p->prepare('UPDATE westy_mail_auto_attempts SET state=?,provider_http=?,provider_request_id=?,outcome_code=? WHERE id=?')->execute([...$values,$attemptId]);
        $p->commit(); return $values[0];
    } catch(Throwable) { if($p->inTransaction())$p->rollBack(); return 'unknown'; }
}
/* UI/poller seams: reconcile_rejected, create_replacement, approve, send,
 * parse_inbound, accept_inbound, and auto_reply.  Pollers pass only a
 * connector-derived auth_verified boolean; raw Graph bodies cannot grant it. */
