import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, mkdir, cp, copyFile, appendFile, writeFile, readFile, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ORIGIN = 'http://safeharbor.test';
const ID_ORIGIN = 'https://accounts.example.test';
const evidence = process.env.PORTAL_GUIDE_EVIDENCE;
const searchError = 'Search could not run. Reload the page and try again.';
const {anchors} = JSON.parse(await readFile(path.join(ROOT, 'app/tests/fixtures/portal_guide_cases.json'), 'utf8'));
const cases = {
  employee: { role: 'client_staff', ids: anchors.employee, audience: 'employee' },
  viewer: { role: 'client_viewer', ids: anchors.viewer, audience: 'viewer' },
  owner: { role: 'client_owner', ids: anchors.owner, audience: 'owner_admin' },
  admin: { role: 'client_admin', ids: anchors.owner, audience: 'owner_admin' },
  technician: { role: 'technician', ids: anchors.legacy },
  delegated: { role: 'technician', overlay: 'client_owner', ids: anchors.legacy },
  overlayOwner: { role: 'client_owner', overlay: 'client_owner', ids: anchors.legacy },
  companion: { role: 'client_owner', companion: true, ids: anchors.legacy },
};

function catalogArticleText(row) {
  const inline = text => text.replace(/\*\*([^*]+)\*\*|`([^`]+)`/gu, (_, bold, code) => bold ?? code);
  return {anchor:row.anchor, title:row.title, blocks:row.body.blocks.map(block =>
    block.type === 'paragraph' ? inline(block.text) : block.items.map(inline))};
}

function checkPublicHtml(html, label) {
  for (const meta of ['drafted_by','approved_by','last_verified','fable-5.2','opus-5.5','gpt-6.2-astra','keywords','"approval"']) {
    assert.ok(!html.includes(meta), label+' HTML excludes catalog metadata '+meta);
  }
}

async function submitSearch(page, queryOrFields) {
  const form = page.locator('.portal-guide-search');
  if (typeof queryOrFields === 'string') {
    await form.getByRole('searchbox', {name:'Search help'}).fill(queryOrFields);
  } else {
    // Send malformed fields through a real form, including PHP's q[] array syntax.
    await form.evaluate((node, fields) => {
      node.querySelectorAll('input').forEach(input => input.remove());
      for (const [name, value] of fields) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        node.append(input);
      }
    }, queryOrFields);
  }
  const [response] = await Promise.all([
    page.waitForNavigation({waitUntil:'load'}),
    form.getByRole('button', {name:'Search', exact:true}).click(),
  ]);
  assert.equal(response.request().method(), 'POST');
  assert.equal(response.url(), ORIGIN+'/portal/guide.php', 'search stays out of the URL');
  assert.equal(response.status(), 200);
  return response;
}

async function checkGuideWidth(page) {
  await page.waitForLoadState('load');
  await page.evaluate(async () => {
    await document.fonts.ready;
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth), 'no horizontal overflow');
}

async function checkVoting(page, setReply) {
  const group = page.locator('#privacy .portal-guide-vote');
  const yes = group.getByRole('button', {name:'Yes', exact:true});
  const no = group.getByRole('button', {name:'No', exact:true});
  const status = group.getByRole('status');
  assert.equal(await group.getAttribute('data-article'), 'sh-privacy');
  assert.equal(await yes.getAttribute('aria-pressed'), 'false');
  assert.equal(await no.getAttribute('aria-pressed'), 'true', 'saved No renders as pressed');

  let finish;
  setReply(new Promise(resolve => { finish = resolve; }));
  try {
    const [request] = await Promise.all([
      page.waitForRequest(ORIGIN+'/portal/guide_vote.php'),
      yes.click(),
    ]);
    assert.equal(request.method(), 'POST');
    assert.equal(request.headers()['content-type'], 'application/json');
    assert.equal(request.headers()['x-portal-csrf'], await page.locator('main').getAttribute('data-csrf'));
    assert.deepEqual(request.postDataJSON(), {article:'sh-privacy', helpful:true});
    assert.ok(await yes.isDisabled() && await no.isDisabled(), 'both choices wait for the vote');
    assert.equal(await no.getAttribute('aria-pressed'), 'true', 'pending choice is not shown as saved');
  } finally { finish(true); }
  await group.getByText('Thanks for telling us.', {exact:true}).waitFor();
  assert.equal(await status.textContent(), 'Thanks for telling us.');
  assert.equal(await yes.getAttribute('aria-pressed'), 'true');
  assert.equal(await no.getAttribute('aria-pressed'), 'false');

  setReply(Promise.resolve(false));
  await no.click();
  await group.getByText("We couldn't save that. Try again.", {exact:true}).waitFor();
  assert.equal(await yes.getAttribute('aria-pressed'), 'true', 'failure keeps the last saved choice');
  assert.ok(await yes.isEnabled() && await no.isEnabled());
  setReply(Promise.resolve(true));
  await no.click();
  await group.getByText('Thanks for telling us.', {exact:true}).waitFor();
  assert.equal(await yes.getAttribute('aria-pressed'), 'false');
  assert.equal(await no.getAttribute('aria-pressed'), 'true', 'a retry can change the saved vote');
}

test('authenticated Guide selection, search, helpful votes, request links and preserved anchors at desktop/mobile sizes', async () => {
  const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-guide-'));
  const lib = path.join(scratch, 'lib');
  const portal = path.join(scratch, 'public/portal');
  const scripts = path.join(scratch, 'public/assets/js');
  const catalog = path.join(scratch, 'help');
  await mkdir(lib); await mkdir(portal, { recursive: true });
  await mkdir(scripts, {recursive:true});
  await copyFile(path.join(ROOT,'app/public/assets/js/portal-guide.js'), path.join(scripts,'portal-guide.js'));
  if (evidence) await mkdir(evidence, { recursive: true });
  // Execute the Guide controller and renderers with synthetic authentication.
  await copyFile(path.join(ROOT,'app/public/portal/guide.php'), path.join(portal,'guide.php'));
  await cp(path.join(ROOT, 'app/help'), catalog, {recursive:true});
  const index = JSON.parse(await readFile(path.join(catalog, 'customer-index.json'), 'utf8'));
  const fullArticles = index.articles.filter(row => !row.legacy_guide && ['safeharbor','companion'].includes(row.app))
    .sort((a, b) => a.order - b.order);
  for (const name of ['portal_render','portal_guide','help_catalog']) {
    await writeFile(path.join(lib, `${name}.php`), `<?php require_once $argv[1].'/app/lib/${name}.php';`);
  }
  await writeFile(path.join(lib,'portal_auth.php'), '<?php');
  await writeFile(path.join(lib,'portal_guide_votes.php'), String.raw`<?php
function portal_guide_votes_installed(mixed $pdo): bool {
    return ($GLOBALS['input']['voteStorage'] ?? '') !== 'absent';
}
function portal_guide_vote_current(mixed $pdo, array $context): array {
    if (($GLOBALS['input']['voteStorage'] ?? '') === 'readError') throw new RuntimeException('Synthetic read failure');
    return $GLOBALS['input']['votes'] ?? [];
}
`);
  await writeFile(path.join(lib,'bootstrap.php'), String.raw`<?php
require_once __DIR__.'/help_catalog.php';
help_catalog_use_dir(__DIR__.'/../help');
// Route fulfillment uses the controller's HTTP status, including early exits.
ob_start();
register_shutdown_function(static function (): void {
    echo json_encode(['status'=>http_response_code() ?: 200, 'body'=>ob_get_clean()], JSON_THROW_ON_ERROR);
});
$input = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD'] = $input['method'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $input['uri'] ?? '/portal/guide.php';
parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $_GET);
parse_str($input['body'] ?? '', $_POST);
$_SESSION = [];
if (!empty($input['companion'])) $_SESSION['desktop_companion_session'] = str_repeat('a',32);
function cfg(string $key, mixed $default=null): mixed { return $default; }
function enforce_https(): void {}
function portal_enabled(): bool { return true; }
function portal_configured_issuer(): string { return '${ID_ORIGIN}/oidc'; }
function db(): mixed { return null; }
function portal_csrf_token(): string { return str_repeat('c',64); }
function portal_csrf_valid(mixed $sent): bool { return is_string($sent) && hash_equals(portal_csrf_token(), $sent); }
function portal_safe_return_path(mixed $value): string { return '/portal/'; }
function portal_login_can_start_automatically(string $method, array $cookies): bool { return false; }
function portal_authenticated_context(mixed $pdo): ?array {
    global $input;
    if ($input['role'] === '') return null;
    $identity = ['role'=>$input['role'],'display_name'=>'Synthetic member'];
    if (isset($input['overlay'])) $identity['customer_access'] = ['role'=>$input['overlay']];
    return ['identity'=>$identity,'binding'=>['client_name'=>'Synthetic business']];
}
`);
  await writeFile(path.join(portal,'requests.php'), String.raw`<?php
require __DIR__.'/../../lib/bootstrap.php';
require __DIR__.'/../../lib/portal_render.php';
portal_render_dashboard(portal_authenticated_context(null), ['client'=>['name'=>'Synthetic business'],
    'counts'=>['open'=>0,'in_progress'=>0,'waiting'=>0,'resolved'=>0],'tickets'=>[]], null);
`);
  const render = (file, input) => {
    const r = spawnSync('php',[path.join(portal,file), ROOT, JSON.stringify(input)],{encoding:'utf8'});
    assert.equal(r.status,0,r.stderr); assert.equal(r.stderr,'');
    return JSON.parse(r.stdout);
  };
  let browser;
  try {
    assert.ok(!render('guide.php',{role:''}).body.includes('id="give-computer-access"'), 'anonymous entry cannot read owner guide');
    assert.equal(render('guide.php',{role:'client_owner',method:'PUT'}).status, 405);
    const post = render('guide.php',{role:'client_staff',method:'POST',body:'q=give&role=client_owner'});
    assert.equal(post.status, 200);
    assert.ok(post.body.includes(searchError) && !post.body.includes('id="give-computer-access"'), 'POST never changes the role');
    browser = await chromium.launch({headless:true});
    for (const viewport of [{width:1440,height:1000},{width:390,height:844}]) {
      for (const [name, input] of Object.entries(cases)) {
        const context = await browser.newContext({viewport,colorScheme:'dark'});
        const page = await context.newPage();
        const errors = [];
        const votes = {'sh-privacy':false};
        let voteReply = Promise.resolve(true);
        let voteStorage = 'installed';
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type()==='error') errors.push(message.text()); });
        await context.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.origin === ID_ORIGIN && url.pathname === '/help.php') {
            assert.equal(route.request().method(), 'GET');
            return route.fulfill({contentType:'text/html', body:'<link rel="icon" href="data:,"><h1 id="passkey-add">ID help fixture</h1>'});
          }
          assert.equal(url.origin, ORIGIN, 'no real sign-in, provider or production traffic');
          if (url.pathname.startsWith('/assets/')) {
            const type = {'.css':'text/css','.js':'text/javascript','.png':'image/png','.svg':'image/svg+xml','.json':'application/json'}[path.extname(url.pathname)];
            return route.fulfill({contentType:type ?? 'application/octet-stream',body:await readFile(path.join(ROOT,'app/public',url.pathname))});
          }
          if (url.pathname === '/portal/westy.php') return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:false,reason:'ai_unavailable'})});
          assert.ok(['/portal/guide.php','/portal/guide_vote.php','/portal/requests.php'].includes(url.pathname), `unexpected route ${url.pathname}`);
          const method = route.request().method();
          if (url.pathname === '/portal/guide_vote.php') {
            assert.equal(method, 'POST');
            assert.equal(route.request().headers()['x-portal-csrf'], 'c'.repeat(64));
            const vote = route.request().postDataJSON();
            assert.deepEqual(Object.keys(vote).sort(), ['article','helpful']);
            assert.equal(typeof vote.helpful, 'boolean');
            const ok = await voteReply;
            if (ok) votes[vote.article] = vote.helpful;
            return route.fulfill({json:ok ? {ok, vote} : {ok}});
          }
          assert.ok(method === 'GET' || (method === 'POST' && url.pathname === '/portal/guide.php'));
          const body = route.request().postData();
          return route.fulfill({contentType:'text/html',...render(path.basename(url.pathname), {...input,method,body,uri:url.pathname+url.search,votes,voteStorage})});
        });
        await page.goto(ORIGIN+'/portal/requests.php');
        // Direct customers have the privacy Guide link; delegated/Companion also have the shortcut.
        await page.locator('main a[href^="/portal/guide.php"]').first().click();
        assert.equal(new URL(page.url()).pathname,'/portal/guide.php');
        assert.match(await page.title(), /Portal guide/);
        assert.equal(await page.getByRole('heading',{level:1}).textContent(),'How this portal works');
        assert.equal(await page.getByRole('searchbox', {name:'Search help'}).inputValue(), '');
        assert.equal(await page.locator('.portal-guide-results').count(), 0);
        const ids = await page.locator('main article').evaluateAll(nodes=>nodes.map(n=>n.id));
        assert.equal(new Set(ids).size, ids.length, 'no duplicate articles/anchors');
        assert.deepEqual(ids, [...input.ids, 'contact'], `${name} keeps every anchor in page order`);
        assert.equal(await page.locator('[id="guide-q"]').count(), 1, 'the search field has its own anchor');
        const voteGroups = page.locator('.portal-guide-vote');
        const voteScript = page.locator('script[src^="/assets/js/portal-guide.js?v="]');
        const canVote = Boolean(input.audience);
        assert.equal(await voteGroups.count(), canVote ? ids.length - 1 : 0, name+' vote controls match its catalog articles');
        assert.equal(await voteScript.count(), canVote ? 1 : 0);
        assert.equal(await page.locator('#contact .portal-guide-vote').count(), 0);
        if (canVote) {
          assert.match(await voteScript.getAttribute('src'), /\?v=[0-9a-f]{20}$/);
          assert.equal(await page.locator('#devices .portal-guide-vote button[aria-pressed="false"]').count(), 2, 'unrated article has no pressed choice');
        }
        if (name === 'employee') await checkVoting(page, reply => { voteReply = reply; });
        checkPublicHtml(await page.content(), name);
        const rendered = await page.locator('main article:has(ol)').evaluateAll(nodes => nodes.map(node => ({
          anchor:node.id, title:node.querySelector('h2').textContent,
          blocks:[...node.querySelectorAll(':scope > p:not(.portal-eyebrow), :scope > ol')].map(block =>
            block.tagName === 'OL' ? [...block.children].map(item => item.textContent) : block.textContent),
        })));
        const expectedArticles = fullArticles.filter(row => input.audience ? row.audience.includes(input.audience) :
          row.app === 'companion' && ['owner_admin','employee','viewer'].every(audience => row.audience.includes(audience)));
        assert.deepEqual(rendered, expectedArticles.map(catalogArticleText), name+' renders the indexed titles and complete bodies in order');
        assert.equal(await page.locator('#portal-nav nav a[href="/portal/requests.php"]').count(),1);
        assert.equal(await page.locator('#portal-nav a[href="/portal/guide.php"]').count(),1);
        assert.equal(await page.locator('#portal-nav nav a[href="/portal/guide.php"]').count(),0,'no added navigation entry');
        const body = await page.locator('main').innerText();
        assert.ok(body.includes('OpenAI or Anthropic'));
        for (const word of ['Publication verification','Authorship','Astra','HOLD FOR CUSTOMER','754c92d']) assert.ok(!body.includes(word));
        if (input.ids.includes('give-computer-access')) {
          assert.ok(ids.indexOf('private-computer-link') < ids.indexOf('give-computer-access'));
          assert.equal(await page.locator('#private-computer-link .portal-eyebrow').textContent(),'Employee');
        }
        // A link navigation can expose the new DOM before its CSS/fonts finish loading.
        // Measure the settled page, without retrying or relaxing the overflow assertion.
        await checkGuideWidth(page);
        assert.ok(await page.locator('main ol').first().evaluate(el=>parseFloat(getComputedStyle(el).paddingLeft)>=20),'ordered steps keep markers inside the content edge');
        assert.ok(await page.locator('main strong').first().evaluate(el=>Number(getComputedStyle(el).fontWeight)>=600),'approved bold labels remain visually emphasized');
        if (evidence && ['employee','viewer','owner'].includes(name)) {
          await page.evaluate(()=>window.scrollTo(0,0));
          await page.screenshot({path:path.join(evidence,`${name}-${viewport.width}.png`)});
          if (name==='owner') {
            await page.locator('#give-computer-access').evaluate(el=>el.scrollIntoView({block:'start'}));
            await page.screenshot({path:path.join(evidence,`owner-article-${viewport.width}.png`)});
          }
        }
        const results = page.locator('.portal-guide-results');
        const query = {employee:'sign in', owner:'give access computer'}[name] ?? 'request';
        const searchResponse = await submitSearch(page, query);
        checkPublicHtml(await searchResponse.text(), name+' search');
        assert.equal(await results.getByRole('heading', {level:2}).textContent(), 'Search results');
        const hits = await results.locator('li a').evaluateAll(links => links.map(link => ({
          href:link.getAttribute('href'), target:link.getAttribute('target'), rel:link.getAttribute('rel'),
          title:link.textContent, summary:link.nextElementSibling?.textContent,
        })));
        assert.ok(hits.length > 0 && hits.length <= 10);
        const idArticles = index.articles.filter(row => row.app === 'id' && (input.audience
          ? row.audience.includes(input.audience)
          : ['employee', 'viewer', 'owner_admin'].every(audience => row.audience.includes(audience))));
        for (const {href, target, rel, title, summary} of hits) {
          if (href.startsWith('#')) {
            assert.ok(ids.some(id => href === '#'+id), name+' result points to a visible page article');
            assert.equal(await page.locator(href+' h2').textContent(), title);
            assert.equal(target, null);
            assert.equal(rel, null);
          } else {
            const row = idArticles.find(article => title === article.title + ' · 8 West ID');
            assert.ok(row, name+' result names an eligible ID article');
            assert.equal(href, ID_ORIGIN + '/help.php' + (input.companion ? '' : '#' + row.anchor));
            assert.equal(target, '_blank');
            assert.equal(rel, 'noopener noreferrer');
          }
          assert.ok(summary?.trim(), 'each result includes its summary');
        }
        if (name === 'employee') {
          assert.equal(hits[0].href, '#companion-connect');
          assert.ok(hits.some(hit => hit.href === '#sign-in') && hits.every(hit => !hit.href.startsWith(ID_ORIGIN)),
            'employee sign-in results include the Safeharbor sign-in guide and stay on the portal');
        }
        if (name === 'owner') assert.equal(hits[0].href, '#give-computer-access');
        await checkGuideWidth(page);
        const localHit = results.locator('li a[href^="#"]').first();
        const localHref = await localHit.getAttribute('href');
        await localHit.click();
        assert.equal(new URL(page.url()).hash, localHref);

        if (name === 'owner' || input.companion) {
          const response = await submitSearch(page, 'passkey');
          checkPublicHtml(await response.text(), name+' passkey search');
          const passkey = results.getByRole('link', {name:'Add a passkey for 8 West ID · 8 West ID', exact:true});
          const expectedHref = ID_ORIGIN + '/help.php' + (input.companion ? '' : '#passkey-add');
          assert.equal(await passkey.getAttribute('href'), expectedHref);
          assert.equal(await passkey.getAttribute('target'), '_blank');
          assert.equal(await passkey.getAttribute('rel'), 'noopener noreferrer');
          assert.equal(await page.locator('main article#passkey-add, .portal-guide-vote[data-article="id-passkey-add"]').count(), 0);
          const [idPage] = await Promise.all([context.waitForEvent('page'), passkey.click()]);
          await idPage.getByRole('heading', {name:'ID help fixture'}).waitFor();
          assert.equal(idPage.url(), expectedHref);
          assert.ok(await idPage.evaluate(() => window.opener === null));
          assert.equal(page.url(), ORIGIN+'/portal/guide.php', 'the reader keeps the Guide search open');
          await idPage.close();
        }

        if (name === 'employee') {
          const markup = '<script>x</script>';
          const scripts = await page.locator('script').count();
          const response = await submitSearch(page, markup);
          const html = await response.text();
          const escaped = '&lt;script&gt;x&lt;/script&gt;';
          assert.ok(!html.includes(markup) && html.includes('value="'+escaped+'"') && html.split(escaped).length === 2);
          assert.equal(await page.getByRole('searchbox', {name:'Search help'}).inputValue(), markup);
          assert.equal(await page.locator('script').count(), scripts, 'search adds no script element');
          assert.equal(await results.getByRole('heading', {level:2}).textContent(), 'Search results');
          assert.equal(await results.locator('p').textContent(), 'No article matches that. Try different words, ask Westy, or contact the support team below.');

          const csrf = await page.locator('.portal-guide-search input[name=csrf]').inputValue();
          for (const [label, fields] of [
            ['role field', [['q','give'],['role','client_owner'],['csrf',csrf]]],
            ['missing csrf', [['q','give']]],
            ['missing query', [['csrf',csrf]]],
            ['incorrect csrf', [['q','give'],['csrf','wrong']]],
            ['array query', [['csrf',csrf],['q[]','a']]],
            ['array csrf', [['csrf[]',csrf],['q','give']]],
            ['extra field', [['csrf',csrf],['q','give'],['extra','1']]],
          ]) {
            await submitSearch(page, fields);
            assert.equal(await page.getByText(searchError, {exact:true}).count(), 1, label);
            assert.equal(await results.count(), 0, label+' has no results');
            assert.equal(await page.locator('main a[href="#give-computer-access"]').count(), 0);
            assert.deepEqual(await page.locator('main article').evaluateAll(nodes=>nodes.map(n=>n.id)), ids);
          }
        }
        // Neither a role nor a search query in the URL changes this GET.
        await page.goto(ORIGIN+'/portal/guide.php?q=give&role=client_owner&audience=owner#privacy');
        assert.equal(await page.locator('#give-computer-access').count(),input.ids.includes('give-computer-access')?1:0);
        assert.equal(await results.count(), 0);
        assert.equal(await page.getByRole('searchbox', {name:'Search help'}).inputValue(), '');
        if (canVote) {
          await page.locator('#contact a[href="/portal/requests.php"]').click();
          assert.equal(new URL(page.url()).pathname,'/portal/requests.php');
          assert.equal(await page.getByRole('heading',{level:1}).textContent(),'Support Requests');
          assert.equal(await page.locator('main a[href="/portal/new.php"]').count(),input.role==='client_viewer'?0:1);
        }
        if (name === 'employee') {
          for (const failure of ['absent','readError']) {
            voteStorage = failure;
            const response = await page.goto(ORIGIN+'/portal/guide.php');
            assert.equal(response.status(), 200);
            assert.deepEqual(await page.locator('main article').evaluateAll(nodes=>nodes.map(n=>n.id)), ids, failure+' preserves every article');
            assert.equal(await voteGroups.count(), 0);
            assert.equal(await voteScript.count(), 0);
          }
          voteStorage = 'installed';
          const index = path.join(catalog, 'customer-index.json');
          await appendFile(index, '\n');
          try {
            const response = await page.goto(ORIGIN+'/portal/guide.php');
            assert.equal(response.status(), 200);
            assert.equal(await page.locator('main > p').last().textContent(), 'Help is unavailable right now.');
            assert.equal(await page.locator('main article').count(), 1);
            assert.equal(await page.locator('main > p + #contact h2').textContent(), 'Contact the support team');
            await submitSearch(page, 'open request');
            assert.equal(await page.getByText('Help is unavailable right now.', {exact:true}).count(), 1);
            assert.equal(await results.count(), 0, 'a damaged catalog does not show a no-match result');
            assert.equal(await page.locator('main article').count(), 1);
            assert.equal(await page.locator('#contact h2').textContent(), 'Contact the support team');
          } finally {
            await copyFile(path.join(ROOT, 'app/help/customer-index.json'), index);
          }
        }
        assert.deepEqual(errors,[],'clean browser console');
        await context.close();
      }
    }
  } finally {
    await browser?.close();
    await rm(scratch,{recursive:true,force:true});
  }
});
