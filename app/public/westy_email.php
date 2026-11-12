<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/render.php';
require_once __DIR__.'/../lib/westy_email.php';
require_once __DIR__.'/../lib/westy_review_ui.php';
enforce_https();$user=require_login();$tenant=(int)$user['tenant_id'];$actor=(int)$user['id'];
$ticketId=(int)($_GET['ticket_id']??0);$admin=in_array($user['role'],['owner','admin'],true);
$error=null;$notice=$_SESSION['westy_email_notice']??null;unset($_SESSION['westy_email_notice']);
function we_input(string $key): string {return is_string($_POST[$key]??null)?$_POST[$key]:'';}
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check();$action=we_input('action');
        if ($action==='contact') {
            if (we_input('confirm_contact')!=='yes') throw new RuntimeException('Confirm the selected person’s contact role.');
            westy_email_contact(db(),$tenant,$ticketId,$actor,(int)we_input('contact_id'),we_input('scope'));
            $_SESSION['westy_email_notice']='Contact routing saved. Review a fresh draft before sending.';
        }elseif($action==='draft') {
            westy_email_save(db(),$tenant,$ticketId,$actor,utf8_clean(we_input('subject')),utf8_clean(we_input('body')),we_input('request_key'),we_input('context'));
            $_SESSION['westy_email_notice']='Draft saved. It has not been sent.';
        }elseif($action==='send') {
            if (we_input('confirm_send')!=='yes') throw new RuntimeException('Confirm the exact recipient and message before sending.');
            $id=(int)we_input('draft_id');$review=$_SESSION['westy_email_review'][$id]??null;
            if (!$review || $review['ticket']!==$ticketId || $review['expires']<time() || !hash_equals($review['nonce'],we_input('review'))) throw new RuntimeException('Review expired. Reload the saved draft.');
            // Single-use approval; an uncertain browser response reads the durable existing result.
            unset($_SESSION['westy_email_review'][$id]);
            $state=westy_email_send(db(),$tenant,$ticketId,$actor,$id,$review['hash']);
            $_SESSION['westy_email_notice']=$state==='submitted'?'Microsoft accepted this email. Inbox receipt is not confirmed.':'Check the saved status below. No automatic resend will occur.';
        }elseif($action==='revoke') {
            westy_email_revoke(db(),$tenant,$ticketId,$actor,(int)we_input('draft_id'));
            $_SESSION['westy_email_notice']='Unsent draft revoked. A claimed send cannot be recalled.';
        }else throw new RuntimeException('Unknown action.');
        header('Location: /westy_email.php?ticket_id='.$ticketId);exit;
    }
}catch(Throwable $e){$error=get_class($e)===RuntimeException::class?$e->getMessage():'Email action could not be completed. Review saved history before retrying.';}
$context=null;$drafts=[];$contacts=[];$template=null;
try {
    db()->beginTransaction();$context=westy_email_context(db(),$tenant,$ticketId,$actor);db()->rollBack();
    $q=db()->prepare('SELECT id,name,email FROM contacts WHERE client_id=? ORDER BY name');$q->execute([$context['ticket']['client_id']]);$contacts=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM westy_email_drafts WHERE tenant_id=? AND ticket_id=? ORDER BY id DESC');$q->execute([$tenant,$ticketId]);$drafts=$q->fetchAll();
    try{$template=westy_email_template($context);}catch(RuntimeException){}
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error='Advice email is unavailable for this ticket or its safety controls are not installed.';}
$labels=['draft'=>'Awaiting approval — not sent','revoked'=>'Revoked — not sent','uncertain'=>'Result unknown — do not resend','submitted'=>'Microsoft accepted — inbox receipt unverified'];
page_top($user,'Westy advice email','queue');
?>
<div class="page page-narrow westy-email westy-review">
 <a class="backlink" href="/ticket.php?id=<?= $ticketId ?>">Back to ticket</a>
 <div class="page-head"><div><h1 class="page-title">Westy advice email</h1><p class="page-sub">Review the recipient and message before approving an email.</p></div></div>
 <p class="page-note">This sends no computer command, closes no ticket and creates no charge.</p>
 <p><a class="btn-secondary" href="/westy_mail.php?ticket_id=<?= $ticketId ?>">Open Westy mailbox conversation</a></p>
 <?php if($error): ?><div class="banner banner-warn" role="alert"><?= h($error) ?></div><?php endif; ?>
 <?php if($notice): ?><div class="banner" role="status"><?= h($notice) ?></div><?php endif; ?>
 <?php if(!westy_email_enabled()): ?><p>Sending and new drafts are disabled. Existing history remains visible.</p><?php endif; ?>
 <?php if($drafts && $drafts[0]['state']!=='draft'): ?><p class="westy-review-state"><?= h($labels[$drafts[0]['state']]) ?></p><?php endif; ?>
 <?php if($context): ?>
 <section class="card westy-review-card">
 <h2>1 Who gets it</h2>
 <p><?= h($context['ticket']['subject']) ?></p>
 <?php if($context['contact']): ?><p class="westy-recipient"><strong><?= h($context['contact']['name']) ?></strong><span><?= h($context['contact']['email']) ?></span></p><p class="page-note"><?= $context['route']==='endpoint'?'Contact for this computer':'Main client contact' ?></p><?php else: ?><p>No contact for this computer or main client contact is set. Sending is blocked.</p><?php endif; ?>
 <p class="page-note">The contact for this computer takes priority. Otherwise Westy uses the main client contact. A contact change requires a new review.</p>
 <?php if($admin && westy_email_enabled()): ?>
 <details class="westy-contact" id="westy-contact"><summary>Change contact</summary>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="contact">
 <label class="field">Contact <select name="contact_id" required><option value="0">None / clear this assignment</option><?php foreach($contacts as $contact): ?><option value="<?= (int)$contact['id'] ?>"><?= h($contact['name'].' · '.$contact['email']) ?></option><?php endforeach; ?></select></label>
 <label class="field">Role <select name="scope"><option value="endpoint">Contact for this computer</option><option value="client">Main client contact</option></select></label>
 <label class="westy-review-check"><input type="checkbox" name="confirm_contact" value="yes" required> I confirm this person’s contact role, or intentionally clear it.</label>
 <div class="westy-review-actions"><button class="btn-secondary" type="submit">Save contact role</button><a class="link" href="/client.php?id=<?= (int)$context['ticket']['client_id'] ?>">Manage this client’s contacts</a></div>
 </form></details><?php endif; ?>
 </section>
 <?php $attempted=(bool)array_filter($drafts,static fn($d)=>$d['attempted_at']!==null); ?>
 <section class="card westy-review-card"><h2>2 The message</h2>
 <?php if($attempted && !array_filter($drafts,static fn($d)=>$d['state']==='draft')): ?><p>The saved message and its attempt are in History.</p><?php endif; ?>
 <?php foreach($drafts as $d): if($d['state']!=='draft') continue; ?>
 <section class="westy-history-entry"><p class="westy-review-state"><?= h($labels[$d['state']]) ?></p>
 <p>Original sender: not retained in this legacy draft. The current mail setting is not evidence of the sender used for an earlier attempt.</p>
 <dl class="westy-message-address"><dt>To</dt><dd><?= h($d['recipient']) ?></dd><dt>Subject</dt><dd><?= h($d['subject']) ?></dd></dl>
 <div class="westy-message"><?= h($d['body_text']) ?></div>
 <?php if($d['attempted_at']): ?><p><?php if(!empty($d['approved_by'])): ?>Approved by staff #<?= (int)$d['approved_by'] ?> <?= westy_review_time($d['approved_at']) ?>.<?php else: ?>No approver was recorded. Approval time: <?= westy_review_time($d['approved_at']) ?>.<?php endif; ?> Submission attempted once <?= westy_review_time($d['attempted_at']) ?>. A refresh never sends it again.</p><?php endif; ?></section>
 <?php endforeach; ?>
 <?php if($template && !$attempted && westy_email_enabled() && cfg('westy_mail.enabled',false)!==true): ?>
 <details class="westy-review-draft"<?= !$drafts?' open':'' ?>><summary>Prepare a new draft</summary>
 <p>Review the options against the ticket's evidence and edit them before saving. Westy’s starting draft does not diagnose a hardware fault. Saving does not send.</p>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="draft"><input type="hidden" name="context" value="<?= h($context['fingerprint']) ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
 <label class="field">Subject <input name="subject" maxlength="190" required value="<?= h($template['subject']) ?>"></label>
 <label class="field">Message <textarea name="body" rows="10" maxlength="12000" required><?= h($template['body']) ?></textarea></label>
 <button class="btn-primary" type="submit" <?= !$context['contact']?'disabled':'' ?>>Save draft for approval</button></form></details>
 <?php endif; ?>
 </section>
 <section class="card westy-review-card"><h2>3 Approve</h2>
 <?php foreach($drafts as $d): if($d['state']!=='draft') continue; ?>
 <?php if($admin && $d['state']==='draft' && cfg('westy_mail.enabled',false)!==true): ?>
 <?php $nonce=bin2hex(random_bytes(16));$_SESSION['westy_email_review'][(int)$d['id']]=['ticket'=>$ticketId,'hash'=>westy_email_review_hash($d),'nonce'=>$nonce,'expires'=>time()+900]; ?>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="draft_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="review" value="<?= $nonce ?>">
 <label class="westy-review-check"><input type="checkbox" name="confirm_send" value="yes"> I approve this exact recipient and message for one email.</label>
 <div class="westy-review-actions"><button class="btn-primary" name="action" value="send" <?= !westy_email_enabled()?'disabled':'' ?>>Approve and send once</button>
 <button class="btn-secondary" name="action" value="revoke">Revoke unsent draft</button></div></form>
 <?php else: ?><p>An owner or administrator reviews email before sending. Open the mailbox conversation for its current review when that mail service is enabled.</p><?php endif; ?>
 <?php endforeach; ?>
 <?php if(!array_filter($drafts,static fn($d)=>$d['state']==='draft')): ?><p><?= $attempted?'The saved result is shown above. There is no automatic resend.':'Save the message, then review the saved recipient and text here before sending.' ?></p><?php endif; ?>
 </section>
 <details class="card westy-review-history"><summary>History</summary><div class="westy-history-body">
 <?php $past=array_filter($drafts,static fn($d)=>$d['state']!=='draft'); ?>
 <?php if(!$past): ?><p>No earlier message is recorded.</p><?php endif; ?>
 <?php foreach($past as $d): ?>
 <section class="westy-history-entry"><h3><?= h($labels[$d['state']]) ?></h3>
 <p>Original sender: not retained in this legacy draft. The current mail setting is not evidence of the sender used for an earlier attempt.</p>
 <dl class="westy-message-address"><dt>To</dt><dd><?= h($d['recipient']) ?></dd><dt>Subject</dt><dd><?= h($d['subject']) ?></dd></dl>
 <div class="westy-message"><?= h($d['body_text']) ?></div>
 <?php if($d['attempted_at']): ?><p><?php if(!empty($d['approved_by'])): ?>Approved by staff #<?= (int)$d['approved_by'] ?> <?= westy_review_time($d['approved_at']) ?>.<?php else: ?>No approver was recorded. Approval time: <?= westy_review_time($d['approved_at']) ?>.<?php endif; ?> Submission attempted once <?= westy_review_time($d['attempted_at']) ?>.</p><p>This is the existing send record. A refresh never sends it again. Unknown results need provider investigation; there is no automatic resend.</p><?php endif; ?>
 </section><?php endforeach; ?>
 </div></details>
 <?php endif; ?>
</div>
<?php page_bottom(palette_data()); ?>
