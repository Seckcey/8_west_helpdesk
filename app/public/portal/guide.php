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
$canWrite = portal_role_can_write_tickets(portal_customer_role($context['identity']));
portal_page_start('Portal guide','',$context);
?>
<main class="portal-guide"><h1>How this portal works</h1><p>Help with the things you can do here. These articles describe the portal; they are not device diagnostics.</p>
<?php foreach(portal_guide_articles($context) as $id=>$article): ?>
<article id="<?= portal_h($id) ?>"><?php if(isset($article['audience'])): ?><p class="portal-eyebrow"><?= portal_h($article['audience']) ?></p><?php endif; ?><h2><?= portal_h($article['title']) ?></h2><?= portal_guide_article_html($article) ?>
<?php if($id==='privacy'): ?><p>New chat messages and unsent drafts are available for up to <?= (int)cfg('portal_westy.retention_days',30) ?> days from creation. If this period changes, existing messages and drafts keep the expiry dates set when they were created. Starting a new chat leaves existing support requests in place. Expired content is hidden immediately and erased from active storage by scheduled maintenance. Protected backups follow the operator’s backup retention.</p><p>When you ask Westy, OpenAI or Anthropic processes your messages according to the AI model selected for your organization. The AI provider’s storage and abuse-monitoring rules are separate from local history. Provider retention may apply. Only your reviewed request text is added to a ticket. Support staff have no portal chat viewer.</p><?php endif; ?>
</article><?php endforeach; ?>
<article id="contact"><h2>Contact the support team</h2><p>When your role allows it, you can send a request directly to the support team. Your request is visible to you, your client owners and admins, and the support team. A saved receipt confirms the ticket exists. Use the request status to follow progress.</p>
<?php if (portal_direct_customer_browser($context)): ?>
  <a class="btn-link" href="/portal/requests.php">Support Requests</a>
<?php elseif ($canWrite): ?>
  <a class="btn-primary" href="/portal/new.php">Write a request</a>
<?php endif; ?>
<?php if (!$canWrite): ?><p>Your viewer role cannot send requests. Ask your business’s account owner or admin to submit one.</p><?php endif; ?>
<p>If your business has an agreed urgent contact route, use that route for urgent help. No support hours or emergency response time are published here.</p></article></main>
<?php portal_page_end(); ?>
