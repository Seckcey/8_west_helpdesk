<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/render.php';
require_once __DIR__.'/../lib/westy_email.php';
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
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error='Advice email is unavailable for this case or its safety controls are not installed.';}
$labels=['draft'=>'Awaiting approval — not sent','revoked'=>'Revoked — not sent','uncertain'=>'Result unknown — do not resend','submitted'=>'Microsoft accepted — inbox receipt unverified'];
page_top($user,'Westy advice email','queue');
?>
<style>.westy-email{max-width:1100px}.westy-email p{margin:.6rem 0}.westy-email form{display:grid;gap:14px}.westy-email select{max-width:100%}.westy-email .btn-primary,.westy-email .btn-chip{justify-self:start}.westy-email .field{min-width:0}</style>
<div class="page westy-email">
 <a class="backlink" href="/ticket.php?id=<?= $ticketId ?>">← Case #<?= $ticketId ?></a>
 <h1 class="page-title">Westy advice email</h1>
 <p>Review practical options for the customer. This sends no computer command, closes no case and creates no charge.</p>
 <p><a class="btn-chip" href="/westy_mail.php?ticket_id=<?= $ticketId ?>">Open Westy mailbox conversation</a></p>
 <?php if($error): ?><div class="banner banner-warn" role="alert"><?= h($error) ?></div><?php endif; ?>
 <?php if($notice): ?><div class="banner" role="status"><?= h($notice) ?></div><?php endif; ?>
 <?php if(!westy_email_enabled()): ?><p>Sending and new drafts are disabled. Existing history remains visible.</p><?php endif; ?>
 <?php if($context): ?>
 <section class="card" style="padding:20px;margin:16px 0">
 <h2>Who receives it?</h2>
 <p><?= h($context['ticket']['subject']) ?></p>
 <p><?php if($context['contact']): ?><?= h($context['contact']['name']) ?> · <?= h($context['contact']['email']) ?> (<?= $context['route']==='endpoint'?'endpoint POC for this case':'client POC fallback' ?>)<?php else: ?>No endpoint or client POC is set. Sending is blocked.<?php endif; ?></p>
 <p>The endpoint POC recorded on this case takes priority. Otherwise Westy uses the explicitly designated client POC. A contact change requires a new review.</p>
 <?php if($admin && westy_email_enabled()): ?>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="contact">
 <label class="field">Contact <select name="contact_id" required><option value="0">None / clear this assignment</option><?php foreach($contacts as $contact): ?><option value="<?= (int)$contact['id'] ?>"><?= h($contact['name'].' · '.$contact['email']) ?></option><?php endforeach; ?></select></label>
 <label class="field">Role <select name="scope"><option value="endpoint">Endpoint POC for this case</option><option value="client">Client POC fallback for this customer</option></select></label>
 <label style="display:block;margin:12px 0"><input type="checkbox" name="confirm_contact" value="yes" required> I confirm this person’s contact role, or intentionally clear it.</label>
 <button class="btn-primary" type="submit">Save contact role</button>
 <a class="link" href="/client.php?id=<?= (int)$context['ticket']['client_id'] ?>">Manage this customer’s contacts</a>
 </form><?php endif; ?>
 </section>
 <?php $attempted=(bool)array_filter($drafts,static fn($d)=>$d['attempted_at']!==null); ?>
 <?php if($template && !$attempted && westy_email_enabled() && cfg('westy_mail.enabled',false)!==true): ?>
 <section class="card" style="padding:20px;margin:16px 0"><h2>Prepare advice</h2>
 <p>Westy’s starting draft does not diagnose a hardware fault. Review it against the case evidence and edit the options before saving. Saving does not send.</p>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="draft"><input type="hidden" name="context" value="<?= h($context['fingerprint']) ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
 <label class="field">Subject <input name="subject" maxlength="190" required style="width:100%" value="<?= h($template['subject']) ?>"></label>
 <label class="field">Message <textarea name="body" rows="18" maxlength="12000" required style="width:100%"><?= h($template['body']) ?></textarea></label>
 <button class="btn-primary" type="submit" <?= !$context['contact']?'disabled':'' ?>>Save draft for approval</button></form></section>
 <?php endif; ?>
 <?php foreach($drafts as $d): ?>
 <section class="card" style="padding:20px;margin:16px 0"><h2>Draft #<?= (int)$d['id'] ?> · <?= h($labels[$d['state']]) ?></h2>
 <p>Original sender: not retained in this legacy draft. The current mail setting is not evidence of the sender used for an earlier attempt.</p>
 <p>To: <?= h($d['recipient']) ?></p><p>Subject: <?= h($d['subject']) ?></p>
 <div style="white-space:pre-wrap;overflow-wrap:anywhere"><?= h($d['body_text']) ?></div>
 <?php if($d['attempted_at']): ?><p>Approved by staff #<?= (int)$d['approved_by'] ?> at <?= h($d['approved_at']) ?> UTC. Submission attempted once at <?= h($d['attempted_at']) ?> UTC.</p><p>This is the existing send record. A refresh never sends it again. Unknown results need provider investigation; there is no automatic resend.</p><?php endif; ?>
 <?php if($admin && $d['state']==='draft' && cfg('westy_mail.enabled',false)!==true): ?>
 <?php $nonce=bin2hex(random_bytes(16));$_SESSION['westy_email_review'][(int)$d['id']]=['ticket'=>$ticketId,'hash'=>westy_email_review_hash($d),'nonce'=>$nonce,'expires'=>time()+900]; ?>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="draft_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="review" value="<?= $nonce ?>">
 <label style="display:block;margin:12px 0"><input type="checkbox" name="confirm_send" value="yes"> I approve this exact recipient and message for one email.</label>
 <button class="btn-primary" name="action" value="send" <?= !westy_email_enabled()?'disabled':'' ?>>Approve and send once</button>
 <button class="btn-chip" name="action" value="revoke">Revoke unsent draft</button></form>
 <?php endif; ?></section>
 <?php endforeach; ?>
 <?php endif; ?>
</div>
<?php page_bottom(); ?>
