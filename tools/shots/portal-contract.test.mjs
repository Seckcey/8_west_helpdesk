import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { spawnSync } from 'node:child_process';
import { createServer } from 'node:http';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const APP_CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const FAVICON = await readFile(path.join(ROOT, 'brand/svg/favicon.svg'), 'utf8');
const PORTAL_ASSETS = {
  '/assets/css/portal.css': ['text/css', await readFile(path.join(ROOT,'app/public/assets/css/portal.css'))],
  '/assets/css/portal-devices.css': ['text/css', await readFile(path.join(ROOT,'app/public/assets/css/portal-devices.css'))],
  '/assets/js/portal-westy.js': ['text/javascript', await readFile(path.join(ROOT,'app/public/assets/js/portal-westy.js'))],
  '/assets/js/portal-device-help.js': ['text/javascript', await readFile(path.join(ROOT,'app/public/assets/js/portal-device-help.js'))],
  '/assets/img/westy-avatar.png': ['image/png', await readFile(path.join(ROOT,'app/public/assets/img/westy-avatar.png'))],
  '/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png': ['image/png', await readFile(path.join(ROOT,'brand/png/safeharbor-logo-horizontal-transparent-20260909.png'))],
};
const PORTAL_RENDER_SOURCE = await readFile(path.join(ROOT, 'app/lib/portal_render.php'), 'utf8');
const ORIGIN = 'http://safeharbor.test';
const SERVE_MODE = process.argv.includes('--serve');

/**
 * The shared 8 West IT 365 suite chrome is vendored under app/public/assets/w365/ and served from
 * the app's own origin. The customer portal is a separate, stricter surface and deliberately does
 * NOT render the cluster, so nothing here should ask for these files — but this fixture stands in
 * for the whole origin, and a request it cannot answer becomes a 404 that the clean-console
 * assertion below would report as a mystery. Serving them from disk means the assertion keeps
 * measuring the portal's own behaviour, and an accidental import of the staff chrome into a portal
 * page shows up as a visible change rather than a broken asset.
 */
const W365_DIR = path.join(ROOT, 'app/public/assets/w365');
const W365_TYPES = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
};
async function w365Asset(pathname) {
  const relative = pathname.slice('/assets/w365/'.length);
  // Same-origin, inside the vendored directory only: no traversal, no absolute paths.
  if (!/^[a-z0-9][a-z0-9./-]*$/i.test(relative) || relative.includes('..')) return null;
  const file = path.join(W365_DIR, relative);
  if (!file.startsWith(W365_DIR + path.sep)) return null;
  const contentType = W365_TYPES[path.extname(file).toLowerCase()];
  if (!contentType) return null;
  try {
    return { contentType, body: await readFile(file) };
  } catch {
    return null;
  }
}

const FIXTURE_PHP = String.raw`<?php
declare(strict_types=1);
function portal_csrf_token(): string { return str_repeat('c', 64); }
function cfg(string $key,mixed $default=null):mixed{return $key==='portal_devices'?['diagnostics_enabled'=>true]:$default;}
function portal_action_nonce(string $purpose, ?int $now = null): string {
    return hash('sha256', $purpose . ':' . ($now ?? 1));
}
require $argv[1];
require dirname($argv[1]) . '/business_reports.php';
require $argv[2];
require dirname($argv[2]) . '/portal_devices_render.php';
require dirname($argv[2]) . '/portal_device_operations_render.php';
$_SERVER['REQUEST_URI'] = str_starts_with($argv[3], 'help') ? '/portal/device_help.php' : (str_starts_with($argv[3], 'devices') ? '/portal/devices.php' : '/portal/');
$context = [
    'identity' => ['display_name' => 'Frank at 8 West Lifestyle', 'role' => 'client_admin'],
    'binding' => ['client_name' => '8 West Lifestyle'],
];
$tickets = [
    ['id' => 102, 'subject' => 'Front desk printer is offline', 'status' => 'waiting', 'priority' => 'high',
     'created_at' => '2026-08-29 09:00:00', 'updated_at' => gmdate('Y-m-d H:i:s', time() - 600), 'resolved_at' => null],
    ['id' => 101, 'subject' => 'New employee cannot sign in', 'status' => 'in_progress', 'priority' => 'normal',
     'created_at' => '2026-08-29 08:00:00', 'updated_at' => gmdate('Y-m-d H:i:s', time() - 1200), 'resolved_at' => null],
    ['id' => 99, 'subject' => 'Conference room display', 'status' => 'resolved', 'priority' => 'low',
     'created_at' => '2026-08-25 08:00:00', 'updated_at' => gmdate('Y-m-d H:i:s', time() - 86400),
     'resolved_at' => gmdate('Y-m-d H:i:s', time() - 86400)],
];
$metrics = [
    'schema_version' => 1,
    'report_type' => BUSINESS_REPORT_TYPE,
    'definition' => ['key' => BUSINESS_REPORT_DEFINITION_KEY, 'version' => 1,
                     'sha256' => business_report_contract_sha256()],
    'source' => ['tenant_key' => '8west', 'client_key' => 'safeharbor-client:14',
                 'client_name' => '8 West Lifestyle'],
    'period' => ['start_utc' => '2026-08-17T07:00:00Z',
                 'end_utc_exclusive' => '2026-08-24T07:00:00Z',
                 'schedule_timezone' => 'America/Los_Angeles'],
    'generated_at' => '2026-08-29T03:27:01Z',
    'tickets' => ['opened' => 4, 'resolved' => 3, 'merged_histories_excluded_from_response_metrics' => 0],
    'first_response' => ['answered' => 4, 'average_minutes' => 37],
    'service_goal' => ['eligible_versioned' => 4, 'legacy_unversioned_excluded' => 0,
                       'decided' => 4, 'met' => 4, 'attainment_percent' => 100, 'undecided' => 0],
    'approved_billable_time' => ['minutes' => 145,
                                 'classification' => 'operational_approval_evidence_not_financial_status'],
    'csat' => ['surveys_sent' => 2, 'responses_received_by_generated_at' => 2,
               'average_score_out_of_3' => 3.0],
    'delivery_truth' => 'A provider acceptance is submission evidence, not inbox delivery proof.',
];
$reportText = business_report_text($metrics);
$archive = [
    'id' => 3, 'period_start' => '2026-08-17 07:00:00', 'period_end' => '2026-08-24 07:00:00',
    'generated_at' => '2026-08-29 03:27:01', 'definition_version' => 1,
    'content_sha256' => business_report_content_sha256($metrics, $reportText),
    'metrics' => $metrics, 'text' => $reportText,
];
switch ($argv[3]) {
    case 'help':
    case 'helpreview':
    case 'helpwaiting':
    case 'helpverified':
    case 'helpunknown':
    case 'helpviewer':
        $device=['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk computer','platform'=>'Windows 11','connection'=>'reporting','connection_label'=>'Connected and reporting'];
        $health=['reference'=>str_repeat('2',32),'recipe'=>'health','title'=>'Computer health check','impact'=>'Read-only system status.','device_reference'=>$device['reference'],
            'state'=>'completed','created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),'can_approve'=>false,'approval_fingerprint'=>null,
            'result'=>['version'=>1,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-30),'memory_used_percent'=>42.1,'system_disk_free_percent'=>55.5,'spooler'=>'stopped']];
        $repair=$health;$repair['reference']=str_repeat('3',32);$repair['recipe']='spooler_restart';$repair['title']='Restart the Windows print service';
        $repair['impact']='Printing pauses while the service restarts. Pending print jobs are preserved. There is no automatic rollback. Two later health checks verify the service; you still need to confirm that printing works.';
        $repair['state']=match($argv[3]){'helpwaiting'=>'verifying','helpverified'=>'service_verified','helpunknown'=>'needs_help',default=>'awaiting_approval'};
        $repair['can_approve']=$repair['state']==='awaiting_approval';$repair['approval_fingerprint']=$repair['can_approve']?str_repeat('f',64):null;
        $repair['result']=$repair['state']==='service_verified'?array_replace($health['result'],['observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'spooler'=>'running']):null;
        if($argv[3]==='helpviewer')$context['identity']['role']='client_viewer';
        $eligibility=match($argv[3]){
            'helpunknown'=>['can_check'=>false,'can_propose_repair'=>false,'reason'=>'support_review'],
            'helpwaiting'=>['can_check'=>false,'can_propose_repair'=>false,'reason'=>'in_progress'],
            'helpverified'=>['can_check'=>true,'can_propose_repair'=>false,'reason'=>'repair_cooldown'],
            'helpviewer'=>['can_check'=>false,'can_propose_repair'=>false,'reason'=>'read_only'],
            default=>['can_check'=>true,'can_propose_repair'=>true,'reason'=>'ready']};
        portal_render_device_help($context,$device,['available'=>true,'eligibility'=>$eligibility,'items'=>$argv[3]==='help'?[$health]:[$repair,$health]]);
        break;
    case 'devices':
    case 'devicesviewer':
    case 'devicesempty':
    case 'deviceserror':
    case 'devicesdownload':
    case 'devicesrevoked':
        if($argv[3]==='devicesviewer')$context['identity']['role']='client_viewer';
        $devices=['items'=>[['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk computer','platform'=>'Windows 11',
            'connection'=>'reporting','connection_label'=>'Connected and reporting','connection_help'=>'Fresh check-in and inventory received.',
            'last_seen_at'=>gmdate('Y-m-d\\TH:i:s\\Z',time()-60),'troubleshooting'=>'support_request'],
            ['reference'=>'2:'.str_repeat('b',64),'label'=>'Warehouse laptop','platform'=>'Windows 11',
            'connection'=>'stale','connection_label'=>'Not checking in','connection_help'=>'Keep this computer online so support can help.',
            'last_seen_at'=>gmdate('Y-m-d\\TH:i:s\\Z',time()-86400),'troubleshooting'=>'support_request']], 'next_after'=>null];
        $grant=['reference'=>str_repeat('1',32),'state'=>$argv[3]==='devicesrevoked'?'revoked':'ready',
            'created_at'=>gmdate('Y-m-d\\TH:i:s\\Z'),'expires_at'=>gmdate('Y-m-d\\TH:i:s\\Z',time()+14400)];
        if($argv[3]==='devicesempty')$devices['items']=[];
        portal_render_devices($context,$argv[3]==='deviceserror'?null:$devices,[$grant],
            $argv[3]==='deviceserror'?portal_devices_error('service_unavailable'):null,
            $argv[3]==='devicesdownload'?$grant+['download_url'=>'https://support.8westit.com/download.php?t='.str_repeat('d',64)]:null,
            $argv[3]==='devicesrevoked'?'The installation link is no longer available.':null);
        break;
    case 'dashboard':
        portal_render_dashboard($context, [
            'client' => ['name' => '8 West Lifestyle'],
            'counts' => ['open' => 0, 'in_progress' => 1, 'waiting' => 1, 'resolved' => 1],
            'tickets' => $tickets,
        ], [$archive]);
        break;
    case 'ticket':
        portal_render_ticket($context, [
            'ticket' => [
                'id' => 102, 'subject' => 'Front desk printer is offline', 'status' => 'waiting',
                'priority' => 'high', 'created_at' => '2026-08-29 09:00:00',
                'sla_due_at' => '2026-08-29 11:00:00', 'first_response_at' => '2026-08-29 09:22:00',
                'service_goal_policy_name' => 'Premium', 'service_goal_version_no' => 2,
                'service_goal_response_minutes' => 60,
            ],
            'messages' => [
                ['kind' => 'client', 'author_name' => 'Frank', 'created_at' => '2026-08-29 09:00:00',
                 'body' => 'The front desk cannot print.'],
                ['kind' => 'tech', 'author_name' => '8 West IT support', 'created_at' => '2026-08-29 09:22:00',
                 'body' => 'Is the blue power light on?'],
            ],
        ]);
        break;
    case 'new':
        portal_render_new_ticket($context);
        break;
    case 'reports':
        portal_render_reports($context, [$archive]);
        break;
    case 'report':
        portal_render_report($context, $archive);
        break;
    default:
        throw new RuntimeException('Unknown fixture page.');
}
`;

async function renderedFixtures() {
  const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-portal-browser-'));
  const fixturePath = path.join(scratch, 'fixture.php');
  await writeFile(fixturePath, FIXTURE_PHP, 'utf8');
  const pages = {};
  for (const name of ['dashboard', 'ticket', 'new', 'reports', 'report', 'devices', 'devicesviewer', 'devicesempty', 'deviceserror', 'devicesdownload', 'devicesrevoked','help','helpreview','helpwaiting','helpverified','helpunknown','helpviewer']) {
    const result = spawnSync('php', [
      fixturePath,
      path.join(ROOT, 'app/lib/portal_data.php'),
      path.join(ROOT, 'app/lib/portal_render.php'),
      name,
    ], { encoding: 'utf8' });
    assert.equal(result.status, 0, `PHP fixture ${name} failed: ${result.stderr}`);
    pages[name] = result.stdout;
  }
  return { pages, scratch };
}

async function openPortalPage(browser, pages, viewport, apiHandler = null) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  const consoleProblems = [];
  page.on('pageerror', (error) => consoleProblems.push(error.message));
  page.on('console', (message) => {
    if (['error', 'warning'].includes(message.type())) consoleProblems.push(message.text());
  });
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (PORTAL_ASSETS[url.pathname]) {
      const [contentType,body]=PORTAL_ASSETS[url.pathname];return route.fulfill({contentType,body});
    }
    if(url.pathname==='/portal/westy.php'){
      if(apiHandler)return apiHandler(route);
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state:{enabled:false,ai_available:false,can_write:true,conversation:null,turns:[],draft:null}})});
    }
    if (url.pathname === '/portal/devices.php' && route.request().method() === 'POST') {
      const form = new URLSearchParams(route.request().postData());
      assert.equal(form.get('csrf'), 'c'.repeat(64));
      if (form.get('action') === 'enrollment_create') assert.equal(form.get('consent'), 'yes');
      const body = form.get('action') === 'enrollment_revoke' ? pages.devicesrevoked : pages.devicesdownload;
      return route.fulfill({contentType:'text/html',body});
    }
    if(url.pathname==='/portal/device_help.php'&&route.request().method()==='POST') {
      const form=new URLSearchParams(route.request().postData());
      assert.equal(form.get('csrf'),'c'.repeat(64));
      if(form.get('action')!=='repair_propose')assert.equal(form.get('consent'),'yes');
      if(form.get('action')==='repair_approve')assert.equal(form.get('approval_fingerprint'),'f'.repeat(64));
      return route.fulfill({contentType:'text/html',body:form.get('action')==='repair_propose'?pages.helpreview:pages.helpwaiting});
    }
    if (url.pathname === '/assets/css/app.css') {
      return route.fulfill({ contentType: 'text/css; charset=utf-8', body: APP_CSS });
    }
    if (url.pathname === '/assets/brand/favicon.svg') {
      return route.fulfill({ contentType: 'image/svg+xml', body: FAVICON });
    }
    if (url.pathname.startsWith('/assets/w365/')) {
      const asset = await w365Asset(url.pathname);
      return asset === null
        ? route.fulfill({ status: 404, body: '' })
        : route.fulfill({ contentType: asset.contentType, body: asset.body });
    }
    const body = url.pathname === '/portal/' ? pages.dashboard
      : url.pathname === '/portal/devices.php' ? pages[url.searchParams.get('fixture') || 'devices']
      : url.pathname === '/portal/device_help.php' ? pages[url.searchParams.get('fixture') || 'help']
      : url.pathname === '/portal/new.php' ? pages.new
      : url.pathname === '/portal/ticket.php' ? pages.ticket
      : url.pathname === '/portal/reports.php' && url.searchParams.has('id') ? pages.report
      : url.pathname === '/portal/reports.php' ? pages.reports
      : null;
    return body === null
      ? route.fulfill({ status: 404, body: '' })
      : route.fulfill({ contentType: 'text/html; charset=utf-8', body });
  });
  return { context, page, consoleProblems };
}

function fixtureForUrl(pages, url) {
  return url.pathname === '/portal/' ? pages.dashboard
    : url.pathname === '/portal/devices.php' ? pages[url.searchParams.get('fixture') || 'devices']
    : url.pathname === '/portal/device_help.php' ? pages[url.searchParams.get('fixture') || 'help']
    : url.pathname === '/portal/new.php' ? pages.new
    : url.pathname === '/portal/ticket.php' ? pages.ticket
    : url.pathname === '/portal/reports.php' && url.searchParams.has('id') ? pages.report
    : url.pathname === '/portal/reports.php' ? pages.reports
    : null;
}

if (SERVE_MODE) {
  const { pages, scratch } = await renderedFixtures();
  const server = createServer((request, response) => {
    const url = new URL(request.url ?? '/', 'http://127.0.0.1:8898');
    if(PORTAL_ASSETS[url.pathname]){
      const [contentType,body]=PORTAL_ASSETS[url.pathname];response.writeHead(200,{'Content-Type':contentType});response.end(body);return;
    }
    if(url.pathname==='/portal/westy.php'){
      response.writeHead(200,{'Content-Type':'application/json'});response.end(JSON.stringify({ok:true,state:{enabled:false,ai_available:false,can_write:true,conversation:null,turns:[],draft:null}}));return;
    }
    if (url.pathname === '/assets/css/app.css') {
      response.writeHead(200, { 'Content-Type': 'text/css; charset=utf-8' });
      response.end(APP_CSS);
      return;
    }
    if (url.pathname === '/assets/brand/favicon.svg') {
      response.writeHead(200, { 'Content-Type': 'image/svg+xml' });
      response.end(FAVICON);
      return;
    }
    if (url.pathname.startsWith('/assets/w365/')) {
      w365Asset(url.pathname).then((asset) => {
        if (asset === null) {
          response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
          response.end('Not found.');
          return;
        }
        response.writeHead(200, { 'Content-Type': asset.contentType });
        response.end(asset.body);
      });
      return;
    }
    const body = fixtureForUrl(pages, url);
    response.writeHead(body === null ? 404 : 200, {
      'Content-Type': body === null ? 'text/plain; charset=utf-8' : 'text/html; charset=utf-8',
    });
    response.end(body ?? 'Not found.');
  });
  server.listen(8898, '127.0.0.1', () => {
    console.log('Safeharbor portal fixture listening at http://127.0.0.1:8898/portal/');
  });
  const cleanup = async () => {
    server.close();
    await rm(scratch, { recursive: true, force: true });
    process.exit(0);
  };
  process.on('SIGINT', cleanup);
  process.on('SIGTERM', cleanup);
} else test('customer portal makes support work and verified weekly archives easy to use', async () => {
  assert.match(PORTAL_RENDER_SOURCE, /Waiting for your reply/);
  assert.match(PORTAL_RENDER_SOURCE, /Open support request/);
  assert.match(PORTAL_RENDER_SOURCE, /Read the exact archived summary/);

  const { pages, scratch } = await renderedFixtures();
  const browser = await chromium.launch();
  try {
    const desktop = await openPortalPage(browser, pages, { width: 1365, height: 850 });
    await desktop.page.goto(`${ORIGIN}/portal/`);
    assert.equal(await desktop.page.title(), 'Your support · Safeharbor');
    await desktop.page.getByRole('heading', { name: 'Business support requests' }).waitFor();
    await desktop.page.getByRole('heading', { name: 'Needs your attention' }).waitFor();
    await desktop.page.getByRole('link', { name: 'Open support request' }).first().waitFor();
    await desktop.page.getByRole('heading', { name: 'Service summaries' }).waitFor();
    assert.equal(await desktop.page.locator('img').evaluateAll(images=>images.every(image=>image.complete&&image.naturalWidth>0)),true);
    assert.equal(await desktop.page.locator('body').evaluate((body) => body.scrollWidth <= body.clientWidth), true);

    await desktop.page.getByRole('link', { name: /#102.*Front desk printer is offline/ }).first().click();
    await desktop.page.getByText('The support team is waiting for your reply.').waitFor();
    await desktop.page.getByLabel('Send the update the support team needs').waitFor();
    assert.equal(await desktop.page.getByText('INTERNAL SECRET', { exact: true }).count(), 0);

    await desktop.page.getByRole('link', { name: 'Help center' }).click();
    await desktop.page.getByRole('link', { name: /Latest archived summary/ }).click();
    await desktop.page.getByRole('heading', { name: 'Aug 17 – Aug 23, 2026' }).waitFor();
    await desktop.page.getByText('145 minutes · 2.42 hours').waitFor();
    await desktop.page.getByText('Read the exact archived summary').click();
    await desktop.page.getByText('Safeharbor weekly client service summary').waitFor();
    assert.deepEqual(desktop.consoleProblems, []);
    await desktop.context.close();

    const mobile = await openPortalPage(browser, pages, { width: 390, height: 844 });
    await mobile.page.goto(`${ORIGIN}/portal/`);
    await mobile.page.getByRole('link', { name: 'Open support request' }).first().waitFor();
    await mobile.page.getByRole('heading', { name: 'Service summaries' }).waitFor();
    assert.equal(await mobile.page.locator('body').evaluate((body) => body.scrollWidth <= body.clientWidth), true);
    assert.deepEqual(mobile.consoleProblems, []);
    await mobile.context.close();
  } finally {
    await browser.close();
    await rm(scratch, { recursive: true, force: true });
  }
});

if (!SERVE_MODE) test('computer checks preserve explicit consent, exact repair review and honest outcomes on desktop and mobile',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  try {
    for(const viewport of [{width:1365,height:900},{width:390,height:844}]) {
      const {page,context,consoleProblems}=await openPortalPage(browser,pages,viewport);
      const posts=[];page.on('request',r=>{if(r.method()==='POST'&&r.url().includes('device_help.php'))posts.push(new URLSearchParams(r.postData()));});
      await page.goto(`${ORIGIN}/portal/device_help.php`);
      await page.getByRole('heading',{name:'Front desk computer'}).waitFor();
      await page.getByText('Run another health check',{exact:true}).click();
      await page.getByRole('button',{name:'Run health check',exact:true}).click();
      assert.equal(posts.length,0,'unchecked health consent sends nothing');
      await page.getByText('Run another health check',{exact:true}).click();
      await page.getByRole('button',{name:'Review print-service repair',exact:true}).click();
      await page.getByText('Ready for your review',{exact:true}).waitFor();
      assert.equal(posts.at(-1).get('action'),'repair_propose');
      await page.getByRole('button',{name:'Approve this repair',exact:true}).click();
      assert.equal(posts.length,1,'review alone and unchecked repair consent cannot approve');
      assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);
      const evidence=process.env.PORTAL_DEVICE_EVIDENCE;
      await page.getByRole('heading',{name:'Front desk computer'}).click();await page.evaluate(()=>scrollTo(0,0));
      if(evidence)await page.screenshot({path:path.join(evidence,`repair-review-${viewport.width}.png`),fullPage:true});
      await page.getByRole('checkbox',{name:/I approve restarting/}).check();
      await page.getByRole('button',{name:'Approve this repair',exact:true}).click();
      await page.getByText('The repair reported completion. Waiting for two separate health checks.',{exact:true}).waitFor();
      assert.equal(posts.at(-1).get('action'),'repair_approve');
      assert.equal(posts.length,2);
      for(const fixture of ['helpverified','helpunknown','helpviewer']) {
        await page.goto(`${ORIGIN}/portal/device_help.php?fixture=${fixture}`);
        assert.equal(await page.getByRole('button',{name:'Approve this repair',exact:true}).count(),0);
        assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);
        if(fixture==='helpverified'){
          await page.getByText('Try printing now.',{exact:false}).waitFor();
          await page.locator('.portal-health-service').getByText('Running',{exact:true}).waitFor();
          assert.equal(await page.getByText('Stopped',{exact:true}).count(),0);
          assert.equal(await page.getByRole('button',{name:'Review print-service repair',exact:true}).count(),0);
        }
        if(fixture==='helpunknown'){
          await page.getByText('Support review needed',{exact:true}).waitFor();
          assert.equal(await page.getByRole('button',{name:'Run health check',exact:true}).count(),0);
          assert.equal(await page.getByRole('button',{name:'Review print-service repair',exact:true}).count(),0);
        }
        if(fixture==='helpviewer')assert.equal(await page.getByRole('button',{name:'Run health check',exact:true}).count(),0);
        if(evidence)await page.screenshot({path:path.join(evidence,`${fixture}-${viewport.width}.png`),fullPage:true});
      }
      await page.goto(`${ORIGIN}/portal/device_help.php`);
      await page.getByText('Run another health check',{exact:true}).click();
      await page.getByRole('checkbox',{name:/I authorize this health check/}).check();
      await page.getByRole('button',{name:'Run health check',exact:true}).click();
      await page.getByText('The repair reported completion. Waiting for two separate health checks.',{exact:true}).waitFor();
      assert.equal(posts.at(-1).get('action'),'health_start');
      assert.deepEqual(consoleProblems,[]);
      await context.close();
    }
  }finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('private composer preserves review, receipt recovery and mobile focus boundaries', async () => {
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  let saved=null,sends=0,deny=false,receiptRecovery=false;
  const state={enabled:true,ai_available:true,can_write:true,conversation:'a'.repeat(32),turns:[],draft:null};
  const handler=async route=>{
    if(deny)return route.fulfill({status:401,contentType:'application/json',body:JSON.stringify({ok:false,reason:'sign_in'})});
    if(route.request().method()==='POST'){
      assert.equal(route.request().headers()['x-portal-csrf'],'c'.repeat(64));
      const request=route.request().postDataJSON();
      if(request.action==='message')state.turns.push({operation_key:request.operation,state:'complete',input_text:request.message,reply:{reply:'Guidance <img src=x onerror=alert(1)> is text.',sources:['requests'],draft_subject:'Printer is offline',draft_body:'The printer is offline.'}});
      if(request.action==='save_draft'){
        saved=request;state.draft={...request,revision:1,state:'draft'};
      }
      if(request.action==='handoff'){
        assert.equal(request.reviewed,true);assert.equal(request.revision,1);sends++;
        state.draft={draft_key:state.draft.draft_key,revision:1,state:'sent',subject:null,body:null,ticket_id:102,ticket_url:'/portal/ticket.php?id=102'};
        // The server committed but the response was lost. UI must GET, not resend.
        receiptRecovery=true;return route.abort('failed');
      }
    }
    if(receiptRecovery){
      assert.equal(new URL(route.request().url()).searchParams.get('receipt'),state.draft.draft_key);
      receiptRecovery=false;
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state:{...state,receipt:state.draft}})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state})});
  };
  try{
    const desktop=await openPortalPage(browser,pages,{width:1365,height:850},handler);
    await desktop.page.goto(`${ORIGIN}/portal/`);
    const input=desktop.page.getByRole('textbox',{name:'Tell Westy what is happening'});
    await input.fill('Help me report the printer.');await desktop.page.getByRole('button',{name:'Ask Westy',exact:true}).click();
    await desktop.page.getByText('Guidance <img src=x onerror=alert(1)> is text.',{exact:true}).waitFor();
    assert.equal(await desktop.page.locator('#portal-chat-messages img').count(),0);
    const box=await desktop.page.locator('#portal-chat-panel').boundingBox();
    assert.ok(box.width>500,'large composer remains in the page, unaffected by staff widget IDs');
    await desktop.page.getByRole('button',{name:'Westy Continue chat'}).click();
    assert.equal(await desktop.page.getByRole('textbox',{name:'Tell Westy what is happening'}).count(),1);
    await desktop.page.getByRole('button',{name:'Close Westy',exact:true}).click();
    await desktop.page.getByRole('button',{name:'Edit suggested request'}).click();
    await desktop.page.getByLabel('What happened and who is affected?').fill('Exact reviewed text, with no private transcript.');
    await desktop.page.getByRole('button',{name:'Save & review request'}).click();
    await desktop.page.getByRole('heading',{name:'Review before sending'}).waitFor();
    assert.equal(await desktop.page.getByRole('button',{name:'Send request',exact:true}).isEnabled(),false);
    await desktop.page.getByRole('checkbox').check();await desktop.page.getByRole('button',{name:'Send request',exact:true}).click();
    await desktop.page.getByText('Request #102 received',{exact:true}).waitFor();
    assert.equal(sends,1);assert.equal(saved.body,'Exact reviewed text, with no private transcript.');
    await desktop.page.reload();await desktop.page.getByText('Request #102 received',{exact:true}).waitFor();
    await desktop.page.goto(`${ORIGIN}/portal/ticket.php?id=102`);
    await desktop.page.getByRole('button',{name:'Westy Continue chat'}).click();
    await desktop.page.getByText('Guidance <img src=x onerror=alert(1)> is text.',{exact:true}).waitFor();
    await desktop.context.close();
    const mobile=await openPortalPage(browser,pages,{width:390,height:844},handler);
    await mobile.page.goto(`${ORIGIN}/portal/ticket.php?id=102`);
    await mobile.page.getByRole('button',{name:'Westy',exact:true}).click();
    await mobile.page.getByRole('dialog',{name:'Private conversation with Westy'}).waitFor();
    assert.equal(await mobile.page.locator('.portal-content').evaluate(e=>e.inert),true);
    assert.equal(await mobile.page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);
    await mobile.page.getByRole('textbox',{name:'Tell Westy what is happening'}).press('Escape');
    assert.equal(await mobile.page.locator('#portal-chat-bubble').evaluate(e=>e===document.activeElement),true);
    deny=true;await mobile.page.reload();await mobile.page.getByRole('button',{name:'Westy',exact:true}).click();
    await mobile.page.getByText('Your sign-in has ended or access changed. Sign in again to continue.',{exact:true}).waitFor();
    assert.equal(await mobile.page.getByText('Request #102 received',{exact:true}).count(),0);
    await mobile.context.close();
  }finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('customer device enrollment consent, link lifecycle and responsive states', async () => {
  const {pages,scratch}=await renderedFixtures();
  const browser=await chromium.launch();
  const evidence=process.env.PORTAL_DEVICE_EVIDENCE;
  const unobstructed=async page=>assert.equal(await page.evaluate(()=>{
    const bubble=document.getElementById('portal-chat-bubble'), b=bubble.getBoundingClientRect();
    return [...document.querySelectorAll('.portal-devices a,.portal-devices button,.portal-devices input')].filter(e=>e!==bubble).every(e=>{
      const r=e.getBoundingClientRect();return !r.width||!r.height||r.right<=b.left||r.left>=b.right||r.bottom<=b.top||r.top>=b.bottom;
    });
  }),true,'Westy trigger must not overlap device controls');
  const contrast=async locator=>assert.ok(await locator.evaluate(e=>{
    const luminance=c=>{const channels=c.match(/[\d.]+/g).slice(0,3).map(x=>Number(x)/255).map(x=>x<=0.04045?x/12.92:((x+0.055)/1.055)**2.4);return channels[0]*0.2126+channels[1]*0.7152+channels[2]*0.0722;};
    const s=getComputedStyle(e),a=luminance(s.color),b=luminance(s.backgroundColor);return(Math.max(a,b)+0.05)/(Math.min(a,b)+0.05);
  })>=4.5,'primary CTA text must meet normal-text contrast');
  try {
    for (const viewport of [{width:1365,height:900},{width:390,height:844}]) {
      const {page,context,consoleProblems}=await openPortalPage(browser,pages,viewport);
      page.setDefaultTimeout(10000);
      await page.goto(`${ORIGIN}/portal/devices.php`);
      assert.equal(await page.title(),'Your devices · Safeharbor');
      if(viewport.width<700)await page.getByRole('button',{name:'Toggle navigation'}).click();
      assert.equal(await page.getByRole('navigation',{name:'Customer portal'}).getByRole('link',{name:'Your devices',exact:true}).getAttribute('aria-current'),'page');
      if(viewport.width<700)await page.getByRole('button',{name:'Toggle navigation'}).click();
      await page.getByRole('heading',{name:'Connected computers'}).waitFor();
      await page.getByText('Front desk computer',{exact:true}).waitFor();
      assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);
      assert.equal(await page.locator('img').evaluateAll(images=>images.every(i=>i.complete&&i.naturalWidth>0)),true);
      await unobstructed(page);
      const chat=page.locator('#portal-chat-bubble');await chat.click();
      await page.getByRole('region',{name:'Private conversation with Westy'}).or(page.getByRole('dialog',{name:'Private conversation with Westy'})).waitFor();
      await page.getByRole('button',{name:'Close Westy',exact:true}).click();
      assert.equal(await chat.evaluate(e=>e===document.activeElement),true);
      await contrast(page.getByRole('button',{name:'Create Windows setup link',exact:true}));
      if(evidence)await page.screenshot({path:path.join(evidence,`devices-${viewport.width}.png`),fullPage:true});
      await page.getByRole('button',{name:'Create Windows setup link',exact:true}).click();
      assert.equal(await page.getByRole('heading',{name:'Install on your Windows computer'}).count(),0);
      await page.getByRole('checkbox',{name:/I am authorized to add this computer/}).check();
      await page.getByRole('button',{name:'Create Windows setup link',exact:true}).click();
      await page.getByRole('heading',{name:'Install on your Windows computer'}).waitFor();
      assert.match(await page.getByRole('link',{name:'Download Milepost setup'}).getAttribute('href'),/^https:\/\/support\.8westit\.com\/download\.php\?t=/);
      const download=page.getByRole('link',{name:'Download Milepost setup'});
      await contrast(download);await download.hover();await contrast(download);await download.focus();await contrast(download);
      assert.notEqual(await download.evaluate(e=>getComputedStyle(e).outlineStyle),'none');
      await unobstructed(page);
      assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);
      if(evidence&&viewport.width===1365)await page.screenshot({path:path.join(evidence,'devices-setup.png'),fullPage:true});
      await page.getByRole('button',{name:'Revoke',exact:true}).click();
      await page.getByText('Link revoked',{exact:true}).waitFor();
      assert.equal(await page.getByRole('button',{name:'Get setup link',exact:true}).count(),0);
      await page.goto(`${ORIGIN}/portal/devices.php?fixture=devicesviewer`);
      assert.equal(await page.getByRole('button',{name:'Create Windows setup link',exact:true}).count(),0);
      assert.equal(await page.getByRole('button',{name:'Revoke',exact:true}).count(),0);
      await page.goto(`${ORIGIN}/portal/devices.php?fixture=devicesempty`);
      await page.getByRole('heading',{name:'No computers on this page yet'}).waitFor();
      await page.goto(`${ORIGIN}/portal/devices.php?fixture=deviceserror`);
      await page.getByRole('alert').getByText(/Device services are temporarily unavailable/).waitFor();
      assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);
      assert.deepEqual(consoleProblems,[]);
      await context.close();
    }
  } finally {await browser.close();await rm(scratch,{recursive:true,force:true});}
});
