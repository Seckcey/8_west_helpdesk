<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_render.php';
require_once __DIR__.'/../../lib/portal_guide.php';
enforce_https();
if(!portal_enabled()){http_response_code(404);exit('Not found.');}
if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');portal_render_error(405,'Method not allowed','Open the portal guide to read help.');exit;}
try{$context=portal_authenticated_context(db());}
catch(Throwable){portal_render_error(503,'Temporarily unavailable','We could not validate your sign-in. Try again shortly.');exit;}
if($context===null){portal_require_sign_in();exit;}
portal_page_start('Portal guide','',$context);
?>
<main class="portal-guide"><h1>How this portal works</h1><p>Help with the things you can do here. These articles describe the portal; they are not device diagnostics.</p>
<?php foreach(portal_guide_articles() as $id=>$article): ?>
<article id="<?= portal_h($id) ?>"><h2><?= portal_h($article['title']) ?></h2><p><?= portal_h($article['text']) ?></p>
<?php if($id==='privacy'): ?><p>New chat messages and unsent drafts are available for up to <?= (int)cfg('portal_westy.retention_days',30) ?> days from creation. If this period changes, existing messages and drafts keep the expiry dates set when they were created. Starting a new chat starts fresh; it does not delete a support request. Expired content is hidden immediately and erased from active storage by scheduled maintenance. Protected backups follow the operator’s backup retention.</p><p>Messages are processed by OpenAI when you ask Westy. Provider storage and abuse-monitoring rules are separate from local history; this service does not promise zero provider retention. <a href="https://developers.openai.com/api/docs/guides/your-data" rel="noreferrer" target="_blank">Read OpenAI’s data controls</a>. Only your reviewed request text is added to a ticket. Support staff have no portal chat viewer.</p><?php endif; ?>
</article><?php endforeach; ?>
<article id="contact"><h2>Need a person?</h2><p>Write a request directly to the support team. You do not need to use Westy first. Your request will be visible to your business and the support team; a saved receipt confirms the ticket exists, not that a technician has read it.</p>
<?php if(portal_role_can_write_tickets(portal_customer_role($context['identity']))): ?><a class="btn-primary" href="/portal/new.php">Write a request</a><?php else: ?><p>Your viewer role cannot send requests. Ask your business’s account owner or admin to submit one.</p><?php endif; ?>
<p>If your business has an agreed urgent contact route, use that route for urgent help. No support hours or emergency response time are published here.</p></article></main>
<?php portal_page_end(); ?>
