<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/render.php';
require_once __DIR__.'/../lib/westy_email.php';
require_once __DIR__.'/../lib/westy_mail_graph.php';
require_once __DIR__.'/../lib/westy_mail.php';
enforce_https();
$user=require_login(); $tenant=(int)$user['tenant_id']; $actor=(int)$user['id'];
$ticketId=(int)($_GET['ticket_id']??0); $admin=in_array($user['role'],['owner','admin'],true);
$error=null; $notice=$_SESSION['westy_mail_notice']??null; unset($_SESSION['westy_mail_notice']);
function wm_input(string $key): string { return is_string($_POST[$key]??null)?$_POST[$key]:''; }
function wm_review(int $id,string $action): array {
    $r=$_SESSION['westy_mail_review'][$id]??null;
    if (!$r || $r['ticket']!==(int)($_GET['ticket_id']??0) || $r['expires']<time() || !hash_equals($r['nonce'],wm_input('review'))) throw new RuntimeException('Review expired. Reload this page.');
    unset($_SESSION['westy_mail_review'][$id]);
    return $r;
}
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check();
        if (!$admin) throw new RuntimeException('An owner or administrator must approve email.');
        $action=wm_input('action');
        if ($action==='reconcile') {
            if (wm_input('confirm_reconcile')!=='yes') throw new RuntimeException('Review the old attempt before recording its disposition.');
            $old=westy_mail_row(db(),'SELECT * FROM westy_email_drafts WHERE tenant_id=? AND ticket_id=? AND id=?',[$tenant,$ticketId,(int)wm_input('old_draft')]);
            if (!$old || $old['state']!=='uncertain' || (int)$old['provider_http']!==404) throw new RuntimeException('This attempt needs separate investigation.');
            westy_mail_reconcile_rejected(db(),$tenant,$ticketId,$actor,(int)$old['id']);
            $_SESSION['westy_mail_notice']='The HTTP 404 rejection was recorded separately. The original attempt remains unchanged. Review a new message from Westy below.';
        } elseif ($action==='draft') {
            $requestKey=wm_input('request_key');
            $preview=$_SESSION['westy_mail_prepare'][$requestKey]??null;
            if (!$preview || $preview['ticket']!==$ticketId || $preview['expires']<time()) throw new RuntimeException('Draft preparation expired. Reload this page.');
            $subject=trim(utf8_clean(wm_input('subject'))).' [wm:'.$preview['token'].']';
            westy_mail_create_replacement(db(),$tenant,$ticketId,$actor,$preview['reconciliation'],$subject,utf8_clean(wm_input('body')),$preview['token'],$requestKey,$preview['context']);
            unset($_SESSION['westy_mail_prepare'][$requestKey]);
            $_SESSION['westy_mail_notice']='Message saved for review. Nothing has been sent.';
        } elseif ($action==='approve_send') {
            if (wm_input('confirm_send')!=='yes') throw new RuntimeException('Confirm the exact sender, recipient, and message.');
            $id=(int)wm_input('conversation'); $review=wm_review($id,$action);
            westy_mail_approve(db(),$tenant,$ticketId,$actor,$id,$review['hash'],wm_input('delegate')==='yes');
            $state=westy_mail_send(db(),$tenant,$ticketId,$actor,$id,'westy_mail_graph_send');
            $_SESSION['westy_mail_notice']=$state==='submitted'?'Microsoft accepted the email. Recipient inbox receipt is still unverified.':'The attempt is recorded below. It will not be resent automatically.';
        } elseif ($action==='send_approved') {
            $id=(int)wm_input('conversation'); wm_review($id,$action);
            $state=westy_mail_send(db(),$tenant,$ticketId,$actor,$id,'westy_mail_graph_send');
            $_SESSION['westy_mail_notice']=$state==='submitted'?'Microsoft accepted the email. Recipient inbox receipt is still unverified.':'The existing result is shown below. No additional send was created.';
        } elseif ($action==='revoke') {
            westy_mail_revoke(db(),$tenant,$ticketId,$actor,(int)wm_input('conversation'));
            $_SESSION['westy_mail_notice']='Further email action is stopped. An email already submitted cannot be recalled.';
        } else throw new RuntimeException('Unknown action.');
        header('Location: /westy_mail.php?ticket_id='.$ticketId); exit;
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error=get_class($e)===RuntimeException::class?$e->getMessage():'Email action could not finish. Check the saved result before trying anything else.';
}
$context=null; $conversation=null; $legacy=[]; $reconciliation=null; $receipts=[]; $auto=[]; $delegation=null; $template=null; $attention=[]; $sentEvidence=[];
try {
    // Access uses the established tenant/case permission checks even when the
    // dedicated transport is unavailable, so existing history stays readable.
    db()->beginTransaction(); $legacyContext=westy_email_context(db(),$tenant,$ticketId,$actor); db()->rollBack();
    $q=db()->prepare('SELECT * FROM westy_email_drafts WHERE tenant_id=? AND ticket_id=? ORDER BY id DESC'); $q->execute([$tenant,$ticketId]); $legacy=$q->fetchAll();
    $conversation=westy_mail_row(db(),'SELECT * FROM westy_mail_conversations WHERE tenant_id=? AND ticket_id=?',[$tenant,$ticketId]);
    $reconciliation=westy_mail_row(db(),'SELECT r.* FROM westy_mail_reconciliations r JOIN westy_email_drafts d ON d.id=r.old_draft_id WHERE d.tenant_id=? AND d.ticket_id=? ORDER BY r.id DESC LIMIT 1',[$tenant,$ticketId]);
    if ($conversation) {
        $delegation=westy_mail_row(db(),'SELECT d.*,r.reason revoked_reason FROM westy_mail_delegations d LEFT JOIN westy_mail_delegation_revocations r ON r.delegation_id=d.id WHERE d.conversation_id=?',[$conversation['id']]);
        $q=db()->prepare('SELECT id,received_at,disposition FROM westy_mail_inbound_receipts WHERE conversation_id=? ORDER BY id'); $q->execute([$conversation['id']]); $receipts=$q->fetchAll();
        $q=db()->prepare('SELECT a.* FROM westy_mail_auto_attempts a JOIN westy_mail_delegations d ON d.id=a.delegation_id WHERE d.conversation_id=? ORDER BY a.id'); $q->execute([$conversation['id']]); $auto=$q->fetchAll();
        $q=db()->prepare('SELECT reason,created_at FROM westy_mail_attention_events WHERE conversation_id=? ORDER BY id'); $q->execute([$conversation['id']]); $attention=$q->fetchAll();
        $q=db()->prepare("SELECT r.owner_kind,r.created_at FROM westy_mail_sent_reconciliations r WHERE (r.owner_kind='conversation' AND r.owner_id=?) OR (r.owner_kind='auto' AND r.owner_id IN (SELECT a.id FROM westy_mail_auto_attempts a JOIN westy_mail_delegations d ON d.id=a.delegation_id WHERE d.conversation_id=?)) ORDER BY r.id"); $q->execute([$conversation['id'],$conversation['id']]); $sentEvidence=$q->fetchAll();
    }
    try {
        db()->beginTransaction(); $context=westy_mail_context(db(),$tenant,$ticketId,$actor); db()->rollBack();
        $template=westy_email_template(['ticket'=>$context['ticket'],'contact'=>$context['contact']]);
    } catch (Throwable) { if (db()->inTransaction()) db()->rollBack(); }
} catch (Throwable) { if (db()->inTransaction()) db()->rollBack(); $error='This case or its email safety controls are unavailable.'; }
$labels=['draft'=>'Awaiting approval — not sent','approved'=>'Approved — not yet attempted','claimed'=>'Attempt started — result unknown; do not resend','submitted'=>'Microsoft accepted — inbox receipt unverified','rejected'=>'Microsoft rejected the attempt','unknown'=>'Result unknown — do not resend','revoked'=>'Further action stopped'];
$ack=westy_mail_template();
page_top($user,'Westy email','queue');
?>
<style>.westy-mail{max-width:1100px}.westy-mail .card{padding:20px;margin:16px 0}.westy-mail form{display:grid;gap:14px}.westy-mail .field{min-width:0}.westy-mail input:not([type=checkbox]),.westy-mail textarea{width:100%}.westy-mail .message{white-space:pre-wrap;overflow-wrap:anywhere}.westy-mail button{justify-self:start}.westy-mail p{overflow-wrap:anywhere}</style>
<div class="page westy-mail">
<a class="backlink" href="/ticket.php?id=<?= $ticketId ?>">← Case #<?= $ticketId ?></a>
<h1 class="page-title">Westy email</h1>
<p>Approve useful advice and a limited reply. Computer changes, purchases, charges, and case closure need their own authority.</p>
<?php if($error): ?><div class="banner banner-warn" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if($notice): ?><div class="banner" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if(!westy_mail_enabled()): ?><p>New email actions are disabled. Existing history is retained.</p><?php endif; ?>
<p><a href="/westy_email.php?ticket_id=<?= $ticketId ?>">Contact routing and earlier email history</a></p>
<?php if($context): ?><section class="card"><h2>Who receives it?</h2>
<p>From: <?= h($context['graph']['sender']) ?><br>To: <?= h($context['contact']['name'].' · '.$context['contact']['email']) ?></p>
<p>The case’s endpoint POC takes priority. Otherwise the designated client POC receives the advice. Contact changes stop this conversation for Frankie to review.</p></section><?php endif; ?>
<?php if(!$conversation): ?>
<?php $attempted=array_filter($legacy,static fn($d)=>$d['attempted_at']!==null); ?>
<?php foreach($attempted as $old): ?><section class="card"><h2>Earlier attempt #<?= (int)$old['id'] ?></h2>
<p>Saved result: <?= h($old['state']) ?>. Microsoft HTTP result: <?= h((string)($old['provider_http']??'not recorded')) ?>. Attempted <?= h($old['attempted_at']) ?> UTC.</p>
<p>The original record stays unchanged. An uncertain response cannot be retried.</p>
<?php if(!$reconciliation && $admin && $old['state']==='uncertain' && (int)$old['provider_http']===404 && westy_mail_enabled()): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reconcile"><input type="hidden" name="old_draft" value="<?= (int)$old['id'] ?>">
<label><input type="checkbox" name="confirm_reconcile" value="yes" required> I reviewed the saved HTTP 404 rejection. This records a separate disposition and permits review of one new message; it does not resend the old attempt.</label>
<button class="btn-chip">Record the rejected attempt</button></form>
<?php endif; ?></section><?php endforeach; ?>
<?php if($reconciliation): ?><p>The earlier HTTP 404 rejection was reviewed by staff #<?= (int)$reconciliation['actor_id'] ?> at <?= h($reconciliation['created_at']) ?> UTC. Its original record is preserved.</p><?php endif; ?>
<?php if($admin && $context && $template && westy_mail_enabled() && (!$attempted || $reconciliation)):
    $key=bin2hex(random_bytes(16)); $token=bin2hex(random_bytes(16));
    $_SESSION['westy_mail_prepare']=[$key=>['ticket'=>$ticketId,'token'=>$token,'reconciliation'=>(int)($reconciliation['id']??0),'context'=>$context['fingerprint'],'expires'=>time()+900]];
?>
<section class="card"><h2>Prepare advice</h2><p>Review these options against the case evidence. A private case reference is added to the subject so replies can be matched safely.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="draft"><input type="hidden" name="request_key" value="<?= $key ?>">
<label class="field">Subject <input name="subject" maxlength="145" required value="<?= h($template['subject']) ?>"></label>
<label class="field">Message <textarea name="body" rows="18" maxlength="12000" required><?= h($template['body']) ?></textarea></label>
<button class="btn-primary">Save for review</button></form></section>
<?php endif; ?>
<?php else: $m=$conversation; ?>
<section class="card"><h2><?= h($labels[$m['state']]??$m['state']) ?></h2>
<p>From: <?= h($m['sender']) ?><br>To: <?= h($m['recipient']) ?><br>Subject: <?= h($m['subject']) ?></p>
<?php $replyAlias=json_decode((string)($m['recipient_alias_json']??'null'),true); if(is_array($replyAlias)): ?><p>Also accept replies from this verified alias of the same person: <?= h($replyAlias['address']) ?>. Any outgoing email still goes to <?= h($m['recipient']) ?>.</p><?php endif; ?>
<div class="message"><?= h($m['body_text']) ?></div>
<?php if($m['attempted_at']): ?><p>Attempted once at <?= h($m['attempted_at']) ?> UTC. Refreshing this page will not send it again.</p><?php endif; ?>
<?php if($admin && in_array($m['state'],['draft','approved'],true) && westy_mail_enabled()):
 $nonce=bin2hex(random_bytes(16)); $_SESSION['westy_mail_review'][(int)$m['id']]=['ticket'=>$ticketId,'hash'=>westy_mail_review_hash($m),'nonce'=>$nonce,'expires'=>time()+900]; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="conversation" value="<?= (int)$m['id'] ?>"><input type="hidden" name="review" value="<?= $nonce ?>">
<?php if($m['state']==='draft'): ?>
<label><input type="checkbox" name="confirm_send" value="yes" required> I approve this exact sender, recipient, and message for one email.</label>
<h3>Optional: one automatic acknowledgment</h3>
<div class="message"><?= h($ack['body']) ?></div>
<label><input type="checkbox" name="delegate" value="yes" <?= ($context['w']['state']??'')!=='human_owned'?'disabled':'' ?>> I approve this exact response once, within seven days, after a verified reply from this contact or its displayed alias. Other requests go to Frankie.</label>
<?php if(($context['w']['state']??'')!=='human_owned'): ?><p>A technician must already own the case before automatic acknowledgment can be approved.</p><?php endif; ?>
<p>This approval grants email authority only. Staff edits, contact changes, revoked permissions, or an expired approval stop further action.</p>
<button class="btn-primary" name="action" value="approve_send">Approve and send once</button>
<?php else: ?><p>Approval was saved, but no send attempt was started. Current authority will be checked again before sending.</p><button class="btn-primary" name="action" value="send_approved">Send approved message once</button><?php endif; ?>
</form><?php endif; ?>
<?php if($admin): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="conversation" value="<?= (int)$m['id'] ?>"><button class="btn-chip" name="action" value="revoke">Stop further email action</button></form><?php endif; ?>
</section>
<section class="card"><h2>Replies and follow-up</h2>
<?php foreach($attention as $event): ?><div class="banner banner-warn"><p>Frankie needs to review the follow-up. <?= $event['reason']==='auto_ack_authority_unavailable'?'The automatic response could not pass its permission check.':'The automatic response was rejected or its result is unknown.' ?> Recorded <?= h($event['created_at']) ?> UTC. Review the saved attempt before sending anything else.</p></div><?php endforeach; ?>
<?php foreach($sentEvidence as $evidence): ?><p>Microsoft Sent Items contained a matching <?= $evidence['owner_kind']==='auto'?'acknowledgment':'advice message' ?> when checked at <?= h($evidence['created_at']) ?> UTC. This separate evidence preserves the original attempt and does not prove inbox receipt.</p><?php endforeach; ?>
<?php if($delegation): ?><p>One automatic acknowledgment was approved until <?= h($delegation['expires_at']) ?> UTC. <?= $delegation['revoked_reason']?'Stopped: '.h($delegation['revoked_reason']):'It is still subject to current permissions and reply verification.' ?></p><?php else: ?><p>Automatic replies were not approved. Frankie handles replies.</p><?php endif; ?>
<?php if(!$receipts): ?><p>No verified reply has been recorded.</p><?php endif; ?>
<?php foreach($receipts as $receipt): ?><p>Reply #<?= (int)$receipt['id'] ?>: <?= h($receipt['disposition']) ?> at <?= h($receipt['received_at']) ?> UTC. Read its text on the case.</p><?php endforeach; ?>
<?php foreach($auto as $attempt): ?><p>Acknowledgment: <?= h($labels[$attempt['state']]??$attempt['state']) ?>. Attempted <?= h($attempt['attempted_at']) ?> UTC.</p><?php endforeach; ?>
</section>
<?php endif; ?>
</div>
<?php page_bottom(); ?>
