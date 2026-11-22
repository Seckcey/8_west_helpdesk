<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_render.php';
require_once __DIR__.'/../../lib/portal_guide.php';
require_once __DIR__.'/../../lib/portal_guide_votes.php';
enforce_https();
if(!portal_enabled()){http_response_code(404);exit('Not found.');}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST');
    portal_render_error(405, 'Method not allowed', 'Open the portal guide to read help.');
    exit;
}
try{$context=portal_authenticated_context(db());}
catch(Throwable){portal_render_error(503,'Temporarily unavailable','We could not validate your sign-in. Try again shortly.');exit;}
if($context===null){portal_require_sign_in();exit;}
$canWrite = portal_role_can_write_tickets(portal_customer_role($context['identity']));
$q = '';
$searchError = false;
if ($method === 'POST') {
    $keys = array_keys($_POST);
    sort($keys, SORT_STRING);
    if ($keys === ['csrf','q'] && is_string($_POST['csrf']) && is_string($_POST['q']) && portal_csrf_valid($_POST['csrf'])) {
        $q = help_catalog_search_query($_POST['q']);
    } else {
        $searchError = true;
    }
}
$csrf = portal_csrf_token();
$showVotes = false;
$votes = [];
portal_page_start('Portal guide','',$context);
?>
<main class="portal-guide" data-csrf="<?= portal_h($csrf) ?>"><h1>How this portal works</h1><p>Help with the things you can do here. These articles describe the portal; they are not device diagnostics.</p>
<form class="portal-guide-search" method="post" action="/portal/guide.php" role="search"><input type="hidden" name="csrf" value="<?= portal_h($csrf) ?>"><label for="guide-q">Search help</label><input id="guide-q" name="q" type="search" maxlength="100" autocomplete="off" value="<?= portal_h($q) ?>"><button class="btn-primary" type="submit">Search</button></form>
<?php if ($searchError): ?><p>Search could not run. Reload the page and try again.</p><?php endif; ?>
<?php try {
    $articles = portal_guide_articles($context);
    if (portal_guide_audience($context) !== 'legacy') {
        try {
            if (portal_guide_votes_installed(db())) {
                $votes = portal_guide_vote_current(db(), $context);
                $showVotes = true;
            }
        } catch (Throwable) {
            $showVotes = false;
        }
    }
    if ($q !== ''):
        $results = portal_guide_search($context, $q); ?>
<section class="portal-guide-results" aria-live="polite"><h2>Search results</h2>
<?php if ($results): ?>
<ul>
<?php foreach ($results as $result): $inId = $result['app'] === 'id'; ?>
<li><a href="<?= portal_h($inId ? $result['href'] : '#'.$result['anchor']) ?>"<?= $inId ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= portal_h($result['title'].($inId ? ' · 8 West ID' : '')) ?></a><p><?= portal_guide_inline($result['summary']) ?></p></li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<p>No article matches that. Try different words, ask Westy, or contact the support team below.</p>
<?php endif; ?>
</section>
<?php endif;
    foreach ($articles as $id=>$article): ?>
<article id="<?= portal_h($id) ?>"><?php if(isset($article['audience'])): ?><p class="portal-eyebrow"><?= portal_h($article['audience']) ?></p><?php endif; ?><h2><?= portal_h($article['title']) ?></h2><?= portal_guide_article_html($article) ?>
<?php if($id==='privacy'): ?><p>New chat messages and unsent drafts are available for up to <?= (int)cfg('portal_westy.retention_days',30) ?> days from creation. If this period changes, existing messages and drafts keep the expiry dates set when they were created. Starting a new chat leaves existing support requests in place. Expired content is hidden immediately and erased from active storage by scheduled maintenance. Protected backups follow the operator’s backup retention.</p><p>When you ask Westy, OpenAI or Anthropic processes your messages according to the AI model selected for your organization. The AI provider’s storage and abuse-monitoring rules are separate from local history. Provider retention may apply. Only your reviewed request text is added to a ticket. Support staff have no portal chat viewer.</p><?php endif; ?>
<?php if ($showVotes): $current = $votes[$article['catalog_id']] ?? null; ?>
<div class="portal-guide-vote" data-article="<?= portal_h($article['catalog_id']) ?>"><span>Was this helpful?</span>
<button type="button" aria-pressed="<?= $current === true ? 'true' : 'false' ?>" data-helpful="1">Yes</button>
<button type="button" aria-pressed="<?= $current === false ? 'true' : 'false' ?>" data-helpful="0">No</button>
<span class="portal-hint" role="status"></span></div>
<?php endif; ?>
</article><?php endforeach;
} catch (HelpCatalogException) { ?>
<p>Help is unavailable right now.</p>
<?php } ?>
<article id="contact"><h2>Contact the support team</h2><p>When your role allows it, you can send a request directly to the support team. Your request is visible to you, your client owners and admins, and the support team. A saved receipt confirms the ticket exists. Use the request status to follow progress.</p>
<?php if (portal_direct_customer_browser($context)): ?>
  <a class="btn-link" href="/portal/requests.php">Support Requests</a>
<?php elseif ($canWrite): ?>
  <a class="btn-primary" href="/portal/new.php">Write a request</a>
<?php endif; ?>
<?php if (!$canWrite): ?><p>Your viewer role cannot send requests. Ask your business’s account owner or admin to submit one.</p><?php endif; ?>
<p>If your business has an agreed urgent contact route, use that route for urgent help. No support hours or emergency response time are published here.</p></article></main>
<?php if ($showVotes): ?>
<script defer src="/assets/js/portal-guide.js?v=<?= portal_asset_version(__DIR__.'/../assets/js/portal-guide.js') ?>"></script>
<?php endif; ?>
<?php portal_page_end(); ?>
