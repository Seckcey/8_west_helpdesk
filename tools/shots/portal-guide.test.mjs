import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, mkdir, copyFile, writeFile, readFile, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ORIGIN = 'http://safeharbor.test';
const evidence = process.env.PORTAL_GUIDE_EVIDENCE;
const legacy = ['devices','requests','updates','response','summaries','privacy','contact'];
const cases = {
  employee: { role: 'client_staff', ids: ['sign-in','assigned-computers','requests','updates','private-computer-link'] },
  viewer: { role: 'client_viewer', ids: ['assigned-computers','updates','private-computer-link'] },
  owner: { role: 'client_owner', ids: ['requests','updates','private-computer-link','give-computer-access'] },
  admin: { role: 'client_admin', ids: ['requests','updates','private-computer-link','give-computer-access'] },
  delegated: { role: 'technician', overlay: 'client_owner', ids: [] },
  overlayOwner: { role: 'client_owner', overlay: 'client_owner', ids: [] },
  companion: { role: 'client_owner', companion: true, ids: [] },
};

test('authenticated Guide selection, request links and preserved anchors at desktop/mobile sizes', async () => {
  const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-guide-'));
  const lib = path.join(scratch, 'lib');
  const portal = path.join(scratch, 'public/portal');
  await mkdir(lib); await mkdir(portal, { recursive: true });
  if (evidence) await mkdir(evidence, { recursive: true });
  // Execute the actual GET controller and actual renderers with synthetic authentication only.
  await copyFile(path.join(ROOT,'app/public/portal/guide.php'), path.join(portal,'guide.php'));
  for (const name of ['portal_render','portal_guide']) {
    await writeFile(path.join(lib, `${name}.php`), `<?php require $argv[1].'/app/lib/${name}.php';`);
  }
  await writeFile(path.join(lib,'portal_auth.php'), '<?php');
  await writeFile(path.join(lib,'bootstrap.php'), String.raw`<?php
$input = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD'] = $input['method'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $input['uri'] ?? '/portal/guide.php';
parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $_GET);
$_SESSION = [];
if (!empty($input['companion'])) $_SESSION['desktop_companion_session'] = str_repeat('a',32);
function cfg(string $key, mixed $default=null): mixed { return $default; }
function enforce_https(): void {}
function portal_enabled(): bool { return true; }
function db(): mixed { return null; }
function portal_csrf_token(): string { return str_repeat('c',64); }
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
    return r.stdout;
  };
  let browser;
  try {
    assert.ok(!render('guide.php',{role:''}).includes('id="give-computer-access"'), 'anonymous entry cannot read owner guide');
    assert.ok(!render('guide.php',{role:'client_owner',method:'POST'}).includes('id="give-computer-access"'), 'guide stays GET-only');
    browser = await chromium.launch({headless:true});
    for (const viewport of [{width:1440,height:1000},{width:390,height:844}]) {
      for (const [name, input] of Object.entries(cases)) {
        const context = await browser.newContext({viewport,colorScheme:'dark'});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type()==='error') errors.push(message.text()); });
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          assert.equal(url.origin, ORIGIN, 'no real sign-in, provider or production traffic');
          if (url.pathname.startsWith('/assets/')) {
            const type = {'.css':'text/css','.js':'text/javascript','.png':'image/png','.svg':'image/svg+xml','.json':'application/json'}[path.extname(url.pathname)];
            return route.fulfill({contentType:type ?? 'application/octet-stream',body:await readFile(path.join(ROOT,'app/public',url.pathname))});
          }
          if (url.pathname === '/portal/westy.php') return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:false,reason:'ai_unavailable'})});
          assert.ok(['/portal/guide.php','/portal/requests.php'].includes(url.pathname), `unexpected route ${url.pathname}`);
          assert.equal(route.request().method(),'GET');
          return route.fulfill({contentType:'text/html',body:render(path.basename(url.pathname), {...input,uri:url.pathname+url.search})});
        });
        await page.goto(ORIGIN+'/portal/requests.php');
        // Direct customers have the privacy Guide link; delegated/Companion also have the shortcut.
        await page.locator('main a[href^="/portal/guide.php"]').first().click();
        assert.equal(new URL(page.url()).pathname,'/portal/guide.php');
        assert.match(await page.title(), /Portal guide/);
        assert.equal(await page.getByRole('heading',{level:1}).textContent(),'How this portal works');
        const ids = await page.locator('main article').evaluateAll(nodes=>nodes.map(n=>n.id));
        assert.equal(new Set(ids).size, ids.length, 'no duplicate articles/anchors');
        for (const id of legacy) assert.ok(ids.includes(id), `${name} retains #${id}`);
        const pilotIds = await page.locator('main article:has(ol)').evaluateAll(nodes=>nodes.map(n=>n.id));
        assert.deepEqual(pilotIds,input.ids,`${name} audience selection`);
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
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
        if (input.ids.length) {
          assert.ok(await page.locator('main ol').first().evaluate(el=>parseFloat(getComputedStyle(el).paddingLeft)>=20),'ordered steps keep markers inside the content edge');
          assert.ok(await page.locator('main strong').first().evaluate(el=>Number(getComputedStyle(el).fontWeight)>=600),'approved bold labels remain visually emphasized');
        }
        if (evidence && ['employee','viewer','owner'].includes(name)) {
          await page.evaluate(()=>window.scrollTo(0,0));
          await page.screenshot({path:path.join(evidence,`${name}-${viewport.width}.png`)});
          if (name==='owner') {
            await page.locator('#give-computer-access').evaluate(el=>el.scrollIntoView({block:'start'}));
            await page.screenshot({path:path.join(evidence,`owner-article-${viewport.width}.png`)});
          }
        }
        // An untrusted role query cannot alter server-selected content.
        await page.goto(ORIGIN+'/portal/guide.php?role=client_owner&audience=owner#privacy');
        assert.equal(await page.locator('#give-computer-access').count(),input.ids.includes('give-computer-access')?1:0);
        if (!input.overlay && !input.companion) {
          await page.locator('#contact a[href="/portal/requests.php"]').click();
          assert.equal(new URL(page.url()).pathname,'/portal/requests.php');
          assert.equal(await page.getByRole('heading',{level:1}).textContent(),'Support Requests');
          assert.equal(await page.locator('main a[href="/portal/new.php"]').count(),input.role==='client_viewer'?0:1);
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
