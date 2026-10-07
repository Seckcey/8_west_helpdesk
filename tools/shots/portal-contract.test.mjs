import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm, writeFile, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { spawnSync } from 'node:child_process';
import { createServer } from 'node:http';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { createHash } from 'node:crypto';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const APP_CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const FAVICON = await readFile(path.join(ROOT, 'brand/svg/favicon.svg'), 'utf8');
const PORTAL_ASSETS = {
  '/assets/css/portal.css': ['text/css', await readFile(path.join(ROOT,'app/public/assets/css/portal.css'))],
  '/assets/css/portal-workspace.css': ['text/css', await readFile(path.join(ROOT,'app/public/assets/css/portal-workspace.css'))],
  '/assets/css/portal-devices.css': ['text/css', await readFile(path.join(ROOT,'app/public/assets/css/portal-devices.css'))],
  '/assets/css/portal-mobile.css': ['text/css', await readFile(path.join(ROOT,'app/public/assets/css/portal-mobile.css'))],
  '/assets/css/portal-security.css': ['text/css', await readFile(path.join(ROOT,'app/public/assets/css/portal-security.css'))],
  '/assets/js/portal-westy.js': ['text/javascript', await readFile(path.join(ROOT,'app/public/assets/js/portal-westy.js'))],
  '/assets/js/portal-desktop.js': ['text/javascript', await readFile(path.join(ROOT,'app/public/assets/js/portal-desktop.js'))],
  '/assets/js/portal-device-help.js': ['text/javascript', await readFile(path.join(ROOT,'app/public/assets/js/portal-device-help.js'))],
  '/assets/img/westy-avatar.png': ['image/png', await readFile(path.join(ROOT,'app/public/assets/img/westy-avatar.png'))],
  '/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png': ['image/png', await readFile(path.join(ROOT,'brand/png/safeharbor-logo-horizontal-transparent-20260909.png'))],
};
const PORTAL_RENDER_SOURCE = await readFile(path.join(ROOT, 'app/lib/portal_render.php'), 'utf8');
const ORIGIN = 'http://safeharbor.test';
const SERVE_MODE = process.argv.includes('--serve');
// Exported by Milepost's actual disposable MySQL/poll/result workflow. Never live data.
const WORKSPACE_RECEIPTS = JSON.parse(await readFile(path.join(ROOT,'app/tests/fixtures/customer_workspace/operations.json'),'utf8')).operations;

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
function cfg(string $key,mixed $default=null):mixed{
    global $argv;
    if($key==='portal_mobile')return ['enabled'=>in_array($argv[3],['devicesmobile','devicesmobileviewer','mobile','mobileviewer'],true)];
    return $key==='portal_devices'?['diagnostics_enabled'=>true,'security_orders_enabled'=>true]:$default;
}
function portal_action_nonce(string $purpose, ?int $now = null): string {
    return hash('sha256', $purpose . ':' . ($now ?? 1));
}
require $argv[1];
require dirname($argv[1]) . '/business_reports.php';
require $argv[2];
require dirname($argv[2]) . '/portal_devices_render.php';
require dirname($argv[2]) . '/portal_device_operations_render.php';
require dirname($argv[2]) . '/portal_mobile_render.php';
require dirname($argv[2]) . '/portal_security_orders_render.php';
$_SERVER['REQUEST_URI'] = str_starts_with($argv[3], 'help') ? '/portal/device_help.php' : (str_starts_with($argv[3], 'devices') ? '/portal/devices.php' : '/portal/');
if(str_starts_with($argv[3],'mobile'))$_SERVER['REQUEST_URI']='/portal/mobile.php';
if(str_starts_with($argv[3],'security'))$_SERVER['REQUEST_URI']='/portal/security.php';
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
    case 'mobile':
    case 'mobileviewer':
        if($argv[3]==='mobileviewer')$context['identity']['role']='client_viewer';
        portal_render_mobile($context,['providers'=>['android'=>'setup_required','intune'=>'setup_required'],'items'=>[],'next_offset'=>null],null,null,null);
        break;
    case 'help':
    case 'helpreview':
    case 'helpwaiting':
    case 'helpverified':
    case 'helpunknown':
    case 'helpviewer':
        $device=['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk computer','platform'=>'Windows 11','connection'=>'reporting','connection_label'=>'Connected and reporting'];
        $health=['reference'=>str_repeat('2',32),'recipe'=>'health','title'=>'Computer health check','impact'=>'Read-only system status.','device_reference'=>$device['reference'],
            'state'=>'completed','created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),'can_approve'=>false,'approval_fingerprint'=>null,
            'result'=>['version'=>2,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-30),'memory_used_percent'=>42.1,'memory_total_bytes'=>17179869184,'memory_available_bytes'=>8589934592,'system_disk_free_percent'=>55.5,'spooler'=>'stopped']];
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
    case 'devicesmobile':
    case 'devicesmobileviewer':
    case 'devicesviewer':
    case 'devicesempty':
    case 'deviceserror':
    case 'devicesdownload':
    case 'devicesrevoked':
        if(in_array($argv[3],['devicesviewer','devicesmobileviewer'],true))$context['identity']['role']='client_viewer';
        $devices=['items'=>[['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk computer','platform'=>'Windows 11',
            'connection'=>'reporting','connection_label'=>'Connected and reporting','connection_help'=>'Fresh check-in and inventory received.',
            'last_seen_at'=>gmdate('Y-m-d\\TH:i:s\\Z',time()-60),'troubleshooting'=>'support_request','hardware'=>['ram_gb'=>15.8,'observed_at'=>'2026-10-04T09:57:35Z','source'=>'agent_inventory']],
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
    case 'security':
    case 'securityreview':
    case 'securityaccepted':
    case 'securityready':
    case 'securitywaiting':
    case 'securityinstalled':
    case 'securityunknown':
    case 'securityviewer':
    case 'securityexpired':
    case 'securityrecovery':
        $device=['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk computer','platform'=>'Windows 11'];
        $fixture=json_decode(file_get_contents(dirname($argv[1]).'/../tests/fixtures/security_orders/coastmark_secure_plus_v1.json'),true);
        $order=['reference'=>str_repeat('a',32),'state'=>'review','expired'=>false,'device_reference'=>$device['reference'],
            'offer'=>$fixture['quote']['offer'],'gateway_stage'=>null,'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),'can_approve'=>true,'can_install'=>false,
            'approval_fingerprint'=>str_repeat('b',64),'can_continue'=>false,'can_refresh'=>false,'error_code'=>'','installation'=>null,
            'existing_order'=>false,'superseded'=>false,'can_review_setup'=>false];
        if(!in_array($argv[3],['security','securityreview','securityrecovery'],true))$order=array_replace($order,[
            'state'=>'accepted','gateway_stage'=>'ready','can_approve'=>false,'can_install'=>true,'can_refresh'=>true]);
        if($argv[3]==='securityaccepted')$order=array_replace($order,['gateway_stage'=>'company_ready','can_install'=>false,'approval_fingerprint'=>null,'can_continue'=>true]);
        if(in_array($argv[3],['securitywaiting','securityinstalled','securityunknown'],true))$order=array_replace($order,[
            'can_install'=>false,'approval_fingerprint'=>null,'installation'=>[
                'state'=>$argv[3]==='securityinstalled'?'installed':($argv[3]==='securityunknown'?'unknown':'awaiting_verification'),
                'outcome'=>'ok','reboot_pending'=>false,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                'protection'=>$argv[3]==='securityinstalled'?'current':'unknown','mdr'=>'unknown',
                'observed_at'=>$argv[3]==='securityinstalled'?gmdate('Y-m-d\TH:i:s\Z'):null]]);
        if($argv[3]==='securityviewer')$context['identity']['role']='client_viewer';
        if($argv[3]==='securityexpired')$order=array_replace($order,['expired'=>true,'can_install'=>false,'can_refresh'=>false,'approval_fingerprint'=>null,'can_review_setup'=>true]);
        if($argv[3]==='securityrecovery')$order['existing_order']=true;
        portal_render_security_orders($context,$device,$argv[3]==='security'?[]:[$order]);
        break;
    case 'workspace': portal_render_workspace($context); break;
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
  for (const name of ['workspace','dashboard', 'ticket', 'new', 'reports', 'report', 'devices', 'devicesmobile', 'devicesmobileviewer', 'mobile', 'mobileviewer', 'devicesviewer', 'devicesempty', 'deviceserror', 'devicesdownload', 'devicesrevoked','help','helpreview','helpwaiting','helpverified','helpunknown','helpviewer',
    'security','securityreview','securityaccepted','securityready','securitywaiting','securityinstalled','securityunknown','securityviewer','securityexpired','securityrecovery']) {
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
  let recoveringSecurityOrder=false;
  page.on('pageerror', (error) => consoleProblems.push(error.message));
  page.on('console', (message) => {
    if (['error', 'warning'].includes(message.type())) consoleProblems.push(message.text());
  });
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if(url.searchParams.get('fixture')==='securityexpired')recoveringSecurityOrder=true;
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
    if(url.pathname==='/portal/security.php'&&route.request().method()==='POST') {
      const form=new URLSearchParams(route.request().postData());
      assert.equal(form.get('csrf'),'c'.repeat(64));
      const action=form.get('action');
      if(['security_accept','security_install'].includes(action)) {
        assert.equal(form.get('consent'),'yes');
        assert.equal(form.get('approval_fingerprint'),'b'.repeat(64));
      }
      const next={security_review:recoveringSecurityOrder?'securityrecovery':'securityreview',security_accept:'securityaccepted',security_continue:'securityready',
        security_install:'securitywaiting',security_refresh:'securityinstalled'}[action];
      assert.ok(next,'only closed customer security forms can submit');
      return route.fulfill({contentType:'text/html',body:pages[next]});
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
    const body = url.pathname === '/portal/' ? pages.workspace
      : url.pathname === '/portal/requests.php' ? pages.dashboard
      : url.pathname === '/portal/devices.php' ? pages[url.searchParams.get('fixture') || 'devices']
      : url.pathname === '/portal/mobile.php' ? pages[url.searchParams.get('fixture') || 'mobile']
      : url.pathname === '/portal/security.php' ? pages[url.searchParams.get('fixture') || 'security']
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
  return url.pathname === '/portal/' ? pages.workspace
    : url.pathname === '/portal/requests.php' ? pages.dashboard
    : url.pathname === '/portal/devices.php' ? pages[url.searchParams.get('fixture') || 'devices']
    : url.pathname === '/portal/mobile.php' ? pages[url.searchParams.get('fixture') || 'mobile']
    : url.pathname === '/portal/security.php' ? pages[url.searchParams.get('fixture') || 'security']
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
  server.listen(8898, process.env.PORTAL_FIXTURE_HOST || '127.0.0.1', () => {
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
    await desktop.page.goto(`${ORIGIN}/portal/requests.php`);
    assert.equal(await desktop.page.title(), 'Your support · Safeharbor');
    await desktop.page.getByRole('heading', { name: 'Support requests',exact:true }).waitFor();
    await desktop.page.getByRole('heading', { name: 'Needs your attention' }).waitFor();
    await desktop.page.getByRole('link', { name: 'Open support request' }).first().waitFor();
    await desktop.page.getByRole('heading', { name: 'Service summaries' }).waitFor();
    assert.equal(await desktop.page.locator('img').evaluateAll(images=>images.every(image=>image.complete&&image.naturalWidth>0)),true);
    assert.equal(await desktop.page.locator('body').evaluate((body) => body.scrollWidth <= body.clientWidth), true);

    await desktop.page.getByRole('link', { name: /#102.*Front desk printer is offline/ }).first().click();
    await desktop.page.getByText('The support team is waiting for your reply.').waitFor();
    await desktop.page.getByLabel('Send the update the support team needs').waitFor();
    assert.equal(await desktop.page.getByText('INTERNAL SECRET', { exact: true }).count(), 0);

    await desktop.page.getByRole('link', { name: 'Support requests',exact:true }).click();
    await desktop.page.getByRole('link', { name: /Latest archived summary/ }).click();
    await desktop.page.getByRole('heading', { name: 'Aug 17 – Aug 23, 2026' }).waitFor();
    await desktop.page.getByText('145 minutes · 2.42 hours').waitFor();
    await desktop.page.getByText('Read the exact archived summary').click();
    await desktop.page.getByText('Safeharbor weekly client service summary').waitFor();
    assert.deepEqual(desktop.consoleProblems, []);
    await desktop.context.close();

    const mobile = await openPortalPage(browser, pages, { width: 390, height: 844 });
    await mobile.page.goto(`${ORIGIN}/portal/requests.php`);
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

if (!SERVE_MODE) test('mobile discovery honors its gate and preserves device navigation for admins and viewers', async () => {
  const { pages, scratch } = await renderedFixtures();
  const browser = await chromium.launch();
  try {
    for (const viewport of [{ width: 1365, height: 900 }, { width: 390, height: 844 }]) {
      for (const viewer of [false, true]) {
        const enabledPages = { ...pages, devices: pages[viewer ? 'devicesmobileviewer' : 'devicesmobile'], mobile: pages[viewer ? 'mobileviewer' : 'mobile'], help: pages[viewer ? 'helpviewer' : 'help'] };
        const { page, context, consoleProblems } = await openPortalPage(browser, enabledPages, viewport);
        await page.goto(`${ORIGIN}/portal/devices.php`);
        await page.getByRole('heading', { name: 'Your devices', exact: true }).waitFor();
        assert.equal(await page.getByRole('link', { name: 'Computer checks', exact: true }).count(), 2);
        assert.equal(await page.getByRole('button', { name: 'Create Windows setup link', exact: true }).count(), viewer ? 0 : 1);
        const evidence = process.env.PORTAL_MOBILE_EVIDENCE;
        if (evidence && !viewer) await page.screenshot({ path: path.join(evidence, `mobile-entry-${viewport.width}.png`), fullPage: true });
        await page.getByRole('link', { name: 'Phones, tablets & Macs', exact: true }).click();
        await page.getByRole('heading', { name: 'Phones, tablets & Macs', exact: true }).waitFor();
        assert.equal(new URL(page.url()).pathname, '/portal/mobile.php');
        assert.equal(await page.getByRole('button', { name: 'Create Windows setup link', exact: true }).count(), 0);
        assert.equal(await page.locator('nav[aria-label="Customer portal"] a[aria-current="page"]').count(), 1);
        assert.equal(await page.locator('nav[aria-label="Customer portal"] a[aria-current="page"]').textContent(), 'Your devices');
        assert.equal(await page.locator('body').evaluate(body => body.scrollWidth <= body.clientWidth), true);
        if (evidence && !viewer) await page.screenshot({ path: path.join(evidence, `mobile-current-${viewport.width}.png`), fullPage: true });
        await page.getByRole('link', { name: 'Computer installation & support', exact: true }).click();
        await page.getByRole('heading', { name: 'Your devices', exact: true }).waitFor();
        await page.getByRole('link', { name: 'Computer checks', exact: true }).first().click();
        await page.getByRole('heading', { name: 'Front desk computer', exact: true }).waitFor();
        assert.equal(await page.locator('nav[aria-label="Customer portal"] a[aria-current="page"]').textContent(), 'Your devices');
        assert.deepEqual(consoleProblems, []);
        await context.close();

        const disabledPages = { ...pages, devices: pages[viewer ? 'devicesviewer' : 'devices'] };
        const disabled = await openPortalPage(browser, disabledPages, viewport);
        await disabled.page.goto(`${ORIGIN}/portal/devices.php`);
        assert.equal(await disabled.page.locator('a[href="/portal/mobile.php"]').count(), 0);
        assert.equal(await disabled.page.getByRole('link', { name: 'Computer checks', exact: true }).count(), 2);
        assert.equal(await disabled.page.locator('body').evaluate(body => body.scrollWidth <= body.clientWidth), true);
        if (evidence && !viewer) await disabled.page.screenshot({ path: path.join(evidence, `mobile-disabled-${viewport.width}.png`), fullPage: true });
        assert.deepEqual(disabled.consoleProblems, []);
        await disabled.context.close();
      }
    }
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
      await page.getByText(/Usable RAM: 16\.0 GB; 8\.0 GB available/).waitFor();
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
      if(request.action==='message'){
        state.turns.push({operation_key:request.operation,state:'complete',input_text:request.message,reply:{reply:'Guidance <img src=x onerror=alert(1)> is text.',sources:['requests'],draft_subject:'Printer is offline',draft_body:'The printer is offline.'}});
        return route.fulfill({contentType:'text/event-stream',body:'event: done\ndata: '+JSON.stringify({state})+'\n\n'});
      }
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
    const input=desktop.page.getByRole('textbox',{name:'Ask Westy about your computer'});
    await input.fill('Help me report the printer.');await desktop.page.getByRole('button',{name:'Send message',exact:true}).click();
    await desktop.page.getByText('Guidance <img src=x onerror=alert(1)> is text.',{exact:true}).waitFor();
    assert.equal(await desktop.page.locator('#portal-chat-messages img[src="x"]').count(),0);
    const box=await desktop.page.locator('#portal-chat-panel').boundingBox();
    assert.ok(box.width>500,'large composer remains in the page, unaffected by staff widget IDs');
    assert.equal(await desktop.page.locator('#portal-chat-bubble').count(),0);
    assert.equal(await desktop.page.getByRole('textbox',{name:'Ask Westy about your computer'}).count(),1);
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
    await mobile.page.getByRole('textbox',{name:'Ask Westy about your computer'}).press('Escape');
    assert.equal(await mobile.page.locator('#portal-chat-bubble').evaluate(e=>e===document.activeElement),true);
    deny=true;await mobile.page.reload();await mobile.page.getByRole('button',{name:'Westy',exact:true}).click();
    await mobile.page.getByText('Your sign-in ended or access changed. Sign in again to continue.',{exact:true}).waitFor();
    assert.equal(await mobile.page.getByText('Request #102 received',{exact:true}).count(),0);
    await mobile.context.close();
  }finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('Secure Plus requires separate order and installation consent and separates provider evidence', async () => {
  const {pages,scratch}=await renderedFixtures();
  const browser=await chromium.launch();
  try {
    for(const viewport of [{width:1365,height:900},{width:390,height:844}]) {
      const {page,context,consoleProblems}=await openPortalPage(browser,pages,viewport);
      page.setDefaultTimeout(10000);
      await page.goto(ORIGIN+'/portal/devices.php');
      await page.getByRole('link',{name:'Secure Plus'}).first().click();
      await page.getByRole('heading',{name:'No Secure Plus order for this computer'}).waitFor();
      await page.getByRole('button',{name:'Review Secure Plus',exact:true}).click();
      await page.getByRole('button',{name:'Accept $15/month order',exact:true}).waitFor();
      assert.equal(await page.locator('form:has(button:text-is("Accept $15/month order"))').evaluate(f=>f.checkValidity()),false);
      assert.equal(await page.evaluate(()=>{
        const b=document.getElementById('portal-chat-bubble').getBoundingClientRect();
        return [...document.querySelectorAll('.portal-device-card,.portal-device-setup')].every(e=>{
          const r=e.getBoundingClientRect();return r.right<=b.left||r.left>=b.right||r.bottom<=b.top||r.top>=b.bottom;
        });
      }),true,'Westy stays outside the complete order, terms and receipt text');
      if(process.env.PORTAL_SECURITY_EVIDENCE)await page.screenshot({path:path.join(process.env.PORTAL_SECURITY_EVIDENCE,'security-review-'+viewport.width+'.png'),fullPage:true});
      let writes=0;
      page.on('request',r=>{if(r.method()==='POST'&&new URL(r.url()).pathname==='/portal/security.php')writes++;});
      await page.getByRole('button',{name:'Accept $15/month order',exact:true}).click();
      assert.equal(writes,0,'unchecked commercial consent cannot submit');
      await page.getByRole('checkbox').check();
      await page.getByRole('button',{name:'Accept $15/month order',exact:true}).click();
      await page.getByRole('button',{name:'Continue setup',exact:true}).waitFor();
      assert.equal(await page.getByRole('button',{name:'Install on this computer',exact:true}).count(),0);
      await page.getByRole('button',{name:'Continue setup',exact:true}).click();
      await page.getByRole('button',{name:'Install on this computer',exact:true}).waitFor();
      const before=writes;
      await page.getByRole('button',{name:'Install on this computer',exact:true}).click();
      assert.equal(writes,before,'unchecked installation consent cannot submit');
      await page.getByRole('checkbox').check();
      await page.getByRole('button',{name:'Install on this computer',exact:true}).click();
      await page.getByText('Checking provider enrollment',{exact:true}).waitFor();
      assert.equal(await page.getByText('Not yet verified',{exact:true}).count(),2);
      await page.getByRole('button',{name:'Check setup status',exact:true}).click();
      await page.getByText('Provider confirmed',{exact:true}).waitFor();
      await page.getByText('Current',{exact:true}).waitFor();
      assert.equal(await page.getByText('Not yet verified',{exact:true}).count(),1,'MDR stays unknown until independently observed');
      assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);
      assert.equal(await page.evaluate(()=>{
        const bubble=document.getElementById('portal-chat-bubble'),b=bubble.getBoundingClientRect();
        return [...document.querySelectorAll('.portal-device-card,.portal-device-setup')].every(e=>{
          const r=e.getBoundingClientRect();return !r.width||!r.height||r.right<=b.left||r.left>=b.right||r.bottom<=b.top||r.top>=b.bottom;
        });
      }),true,'Westy stays outside complete installation and receipt content');
      if(viewport.width<700)await page.getByRole('button',{name:'Toggle navigation'}).click();
      assert.equal(await page.getByRole('navigation',{name:'Customer portal'}).getByRole('link',{name:'Your devices',exact:true}).getAttribute('aria-current'),'page');
      if(viewport.width<700)await page.getByRole('button',{name:'Toggle navigation'}).click();
      if(process.env.PORTAL_SECURITY_EVIDENCE)await page.screenshot({path:path.join(process.env.PORTAL_SECURITY_EVIDENCE,'security-installed-'+viewport.width+'.png'),fullPage:true});
      await page.goto(ORIGIN+'/portal/security.php?fixture=securityunknown');
      await page.getByText(/before trying another install/).waitFor();
      assert.equal(await page.getByRole('button',{name:'Install on this computer',exact:true}).count(),0);
      await page.goto(ORIGIN+'/portal/security.php?fixture=securityexpired');
      await page.getByRole('button',{name:'Review setup for existing order',exact:true}).click();
      await page.getByRole('button',{name:'Approve setup for existing order',exact:true}).waitFor();
      assert.equal(await page.getByRole('button',{name:'Accept $15/month order',exact:true}).count(),0);
      await page.getByText(/does not place another order or change its price or terms/).waitFor();
      const beforeRenew=writes;
      if(process.env.PORTAL_SECURITY_EVIDENCE)await page.screenshot({path:path.join(process.env.PORTAL_SECURITY_EVIDENCE,'security-recovery-'+viewport.width+'.png'),fullPage:true});
      await page.getByRole('button',{name:'Approve setup for existing order',exact:true}).click();
      assert.equal(writes,beforeRenew,'unchecked fresh setup consent cannot submit');
      await page.getByRole('checkbox').check();
      await page.getByRole('button',{name:'Approve setup for existing order',exact:true}).click();
      await page.getByRole('button',{name:'Continue setup',exact:true}).waitFor();
      assert.equal(await page.getByRole('button',{name:'Install on this computer',exact:true}).count(),0,'fresh setup consent still requires separate install');
      await page.goto(ORIGIN+'/portal/security.php?fixture=securityviewer');
      assert.equal(await page.locator('form[action^="/portal/security.php"]').count(),0);
      assert.deepEqual(consoleProblems,[]);
      await context.close();
    }
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

if (!SERVE_MODE) test('workspace renders incremental network events, keeps its composer visible and preserves history',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  const device=WORKSPACE_RECEIPTS.health_queued.device_reference;let state, messagePosts=0, stops=0;
  const reset=()=>{state={enabled:true,ai_available:true,can_write:true,tools_enabled:true,conversation:'a'.repeat(32),conversations:[],turns:[],draft:null,devices:[{reference:device,label:'Synthetic workstation'}]};};reset();
  const server=createServer(async(request,response)=>{
    const url=new URL(request.url,'http://localhost');
    if(url.pathname==='/portal/westy.php'){
      if(request.method==='POST'){
        let raw='';for await(const chunk of request)raw+=chunk;const payload=JSON.parse(raw);
        assert.equal(request.headers['x-portal-csrf'],'c'.repeat(64));
        if(payload.action==='message'){
          assert.match(payload.operation,/^f1[0-9a-f]{30}$/);
          assert.ok(Math.abs(parseInt(payload.operation.slice(2,10),16)-Math.floor(Date.now()/1000))<=60,'new paid turn uses a current bounded-lifetime operation');
          messagePosts++;const turn={operation_key:payload.operation,state:'pending',input_text:payload.message,reply:{reply:'',tools:[],sources:[]}};state.turns.push(turn);
          state.conversations=[{key:state.conversation,title:payload.message}];
          response.writeHead(200,{'Content-Type':'text/event-stream','Cache-Control':'no-store'});
          const emit=(event,data)=>response.write('event: '+event+'\ndata: '+JSON.stringify(data)+'\n\n');
          emit('accepted',{operation:payload.operation,conversation:state.conversation});
          turn.reply.reply='I’ll check the selected computer.';emit('delta',{operation:payload.operation,text:turn.reply.reply});
          const tool={key:'call_check',name:'start_health_check',state:'complete',device_reference:device,operation:structuredClone(WORKSPACE_RECEIPTS.health_queued)};
          turn.reply.tools.push(tool);emit('tool',{operation:payload.operation,tool});
          await new Promise(resolve=>setTimeout(resolve,1200));
          if(turn.state==='pending'){
            turn.reply.reply+=' The health check is queued.';emit('delta',{operation:payload.operation,text:' The health check is queued.'});
            turn.state='complete';
          }
          emit('done',{state});response.end();return;
        }
        if(payload.action==='stop'){stops++;const turn=state.turns.find(t=>t.operation_key===payload.operation);if(turn){turn.state='unavailable';turn.reason_code='stopped';}}
        if(payload.action==='new_chat'){state={...state,conversation:payload.next_conversation,turns:[]};}
      }
      response.writeHead(200,{'Content-Type':'application/json'});response.end(JSON.stringify({ok:true,state}));return;
    }
    let asset=PORTAL_ASSETS[url.pathname];
    if(url.pathname==='/assets/css/app.css')asset=['text/css',APP_CSS];
    if(url.pathname==='/assets/brand/favicon.svg')asset=['image/svg+xml',FAVICON];
    if(asset){response.writeHead(200,{'Content-Type':asset[0]});response.end(asset[1]);return;}
    response.writeHead(200,{'Content-Type':'text/html'});response.end(pages.workspace);
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin='http://127.0.0.1:'+server.address().port;
  try{
    for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
      reset();const page=await browser.newPage({viewport});const problems=[];page.on('pageerror',e=>problems.push(e.message));
      await page.goto(origin+'/portal/');const input=page.getByRole('textbox',{name:'Ask Westy about your computer'});await input.fill('Check my computer.');
      const geometry=await page.locator('#portal-chat-form').boundingBox();assert.ok(geometry.y>=0&&geometry.y+geometry.height<=viewport.height,'composer is in the first viewport');
      assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth),true);assert.equal(await page.locator('#portal-chat-bubble').count(),0);
      const navigation=await page.evaluate(()=>performance.getEntriesByType('navigation').length);
      await input.press('Shift+Enter');assert.equal(await input.inputValue(),'Check my computer.\n');await input.press('Enter');
      await page.getByText('I’ll check the selected computer.',{exact:true}).waitFor();
      assert.equal(state.turns[0].state,'pending','visible text precedes provider completion');
      assert.equal(await page.getByRole('button',{name:'Stop reply'}).isVisible(),true);
      await page.getByText('I’ll check the selected computer. The health check is queued.',{exact:true}).waitFor();
      await page.waitForFunction(()=>document.getElementById('portal-chat-stop').hidden);
      assert.equal(await page.evaluate(()=>performance.getEntriesByType('navigation').length),navigation,'sending does not navigate or reload the page');
      await page.reload();await page.getByText('I’ll check the selected computer. The health check is queued.',{exact:true}).waitFor();
      await input.fill('Explain that.');await input.press('Enter');await page.getByRole('button',{name:'Stop reply'}).click();
      await page.waitForFunction(()=>document.getElementById('portal-chat-stop').hidden);
      assert.equal(state.turns.at(-1).state,'unavailable');
      assert.equal(await page.locator('#portal-chat-input').evaluate(e=>e===document.activeElement),true,'focus returns to composer');
      assert.equal(await page.getByText('Computer health check · Queued',{exact:true}).count(),2,'stopping a reply preserves both recorded queued operations');
      if(process.env.PORTAL_SCREENSHOT_DIR){
        const dir=process.env.PORTAL_SCREENSHOT_DIR;await mkdir(dir,{recursive:true});
        await page.screenshot({path:path.join(dir,`workspace-${viewport.width}.png`)});
      }
      if(viewport.width===390){
        await page.setViewportSize({width:390,height:450});
        const compact=await page.locator('#portal-chat-form').boundingBox();assert.ok(compact.y>=0&&compact.y+compact.height<=450,'composer stays visible in a keyboard-sized viewport');
        const menu=page.getByRole('button',{name:'Toggle navigation'});await menu.click();
        assert.equal(await page.locator('.portal-content').evaluate(e=>e.inert),true);
        await page.keyboard.press('Escape');assert.equal(await menu.evaluate(e=>e===document.activeElement),true,'drawer dismissal restores keyboard focus');
      }
      assert.deepEqual(problems,[]);await page.close();
    }
    assert.equal(messagePosts,4);assert.equal(stops,2);
  }finally{await browser.close();await new Promise(resolve=>server.close(resolve));await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('expired message operations preserve text and require refresh without replaying paid work',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  const state={enabled:true,ai_available:true,can_write:true,conversation:'a'.repeat(32),conversations:[],turns:[],draft:null};
  const posts=[];let reject=true;
  const handler=async route=>{
    if(route.request().method()==='GET')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state})});
    const payload=route.request().postDataJSON();posts.push(payload);
    assert.equal(payload.action,'message');assert.match(payload.operation,/^f1[0-9a-f]{30}$/);
    if(reject)return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({ok:false,reason:'operation_expired'})});
    state.turns.push({operation_key:payload.operation,state:'complete',input_text:payload.message,reply:{reply:'Saved despite an interrupted connection.',tools:[],sources:[]}});
    return route.fulfill({contentType:'text/event-stream',body:'event: accepted\ndata: '+JSON.stringify({operation:payload.operation,conversation:state.conversation})+'\n\n'});
  };
  try{
    const {page,context,consoleProblems}=await openPortalPage(browser,pages,{width:390,height:844},handler);
    await page.goto(`${ORIGIN}/portal/`);
    const version=createHash('sha256').update(PORTAL_ASSETS['/assets/js/portal-westy.js'][1]).digest('hex').slice(0,20);
    assert.equal(await page.locator('script[src*="portal-westy.js"]').getAttribute('src'),'/assets/js/portal-westy.js?v='+version,'refresh loads the exact current message generator by content');
    const input=page.getByRole('textbox',{name:'Ask Westy about your computer'});
    await input.fill('Keep my original request.');await input.press('Enter');
    await page.getByText('Refresh this page before sending a new message. If this continues, check your computer clock.',{exact:true}).waitFor();
    assert.equal(await input.inputValue(),'Keep my original request.');assert.equal(posts.length,1);
    reject=false;await page.reload();await input.fill('Keep my original request.');await input.press('Enter');
    await page.getByText('Saved despite an interrupted connection.',{exact:true}).waitFor();
    await page.waitForFunction(()=>document.getElementById('portal-chat-stop').hidden);
    assert.equal(posts.length,2,'refresh and deliberate resubmission creates one new request, interrupted acceptance creates no retry');
    assert.notEqual(posts[0].operation,posts[1].operation);assert.equal(state.turns.length,1);
    await page.reload();await page.getByText('Saved despite an interrupted connection.',{exact:true}).waitFor();
    assert.equal(posts.length,2,'saved response recovery is read-only');
    assert.deepEqual(consoleProblems,['Failed to load resource: the server responded with a status of 409 (Conflict)'],'only the deliberately rejected expired request reports a browser error');
    await context.close();
  }finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('desktop continuation keeps the exact completed turn and never replays an uncertain resume',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  const conversation='a'.repeat(32),operation='b'.repeat(32),posts=[];
  const state={enabled:true,ai_available:true,can_write:true,conversation,conversations:[],turns:[{operation_key:operation,state:'complete',input_text:'Open my approved website.',reply:{reply:'Choose the window on your computer.',sources:[],tools:[]}}],draft:null};
  const handler=async route=>{
    if(route.request().method()==='GET')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state})});
    const payload=route.request().postDataJSON();posts.push(payload);
    assert.deepEqual(payload,{action:'desktop_resume',operation,conversation});
    assert.equal(route.request().headers()['x-portal-csrf'],'c'.repeat(64));
    return route.fulfill({contentType:'text/event-stream',body:'event: accepted\ndata: '+JSON.stringify({operation,conversation})+'\n\nevent: delta\ndata: '+JSON.stringify({operation,text:'Window permission received.'})+'\n\n'});
  };
  try{
    const {page,context,consoleProblems}=await openPortalPage(browser,pages,{width:1440,height:900},handler);
    await page.goto(`${ORIGIN}/portal/`);await page.getByText('Choose the window on your computer.',{exact:true}).waitFor();
    const emit=detail=>page.evaluate(detail=>window.dispatchEvent(new CustomEvent('westy-desktop-resume',{detail})),detail);
    await emit({conversation:'c'.repeat(32),operation});await emit({conversation,operation:'d'.repeat(32)});
    assert.equal(posts.length,0,'different conversation or operation cannot resume');
    await emit({conversation,operation});
    await page.waitForFunction(()=>document.getElementById('portal-chat-stop').hidden);
    await page.getByText('Choose the window on your computer.',{exact:true}).waitFor();
    await emit({conversation,operation});
    assert.equal(posts.length,1,'interrupted stream is inspected but never replayed');
    assert.equal(posts.filter(p=>p.action==='message').length,0,'desktop continuation never synthesizes a new user message');
    assert.equal(await page.getByText('Open my approved website.',{exact:true}).count(),1,'original user turn remains singular');
    assert.deepEqual(consoleProblems,[]);await context.close();
  }finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('workspace operation cards use recorded preview, completion and cancellation receipts',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  const geometryProof=[];
  try{
    for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
      let phase='approval',posts=[];
      const getState=()=>{
        const op=structuredClone(WORKSPACE_RECEIPTS[phase==='offline'?'health_queued':phase]);
        const tool={key:'call_activity',name:op.recipe==='health'?'start_health_check':'prepare_temp_cleanup',state:phase==='offline'?'unavailable':'complete',device_reference:op.device_reference,operation:phase==='offline'?null:op,reason:phase==='offline'?'device_offline':null};
        return {enabled:true,ai_available:true,can_write:true,conversation:'a'.repeat(32),conversations:[],draft:null,devices:[{reference:op.device_reference,label:'Synthetic workstation'}],turns:[{operation_key:'b'.repeat(32),state:'complete',input_text:'Help with this computer.',reply:{reply:'The recorded computer activity is shown here.',tools:[tool],sources:[]}}]};
      };
      const apiHandler=async route=>{
        if(route.request().method()==='POST'){
          const payload=route.request().postDataJSON();posts.push(payload);
          assert.equal(payload.reference,WORKSPACE_RECEIPTS.approval.reference);
          assert.equal(payload.approval_fingerprint,WORKSPACE_RECEIPTS.approval.approval_fingerprint);
          assert.equal(payload.reviewed,true);assert.equal(payload.action,'approve_operation');phase='cleanup_queued';
        }
        await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state:getState()})});
      };
      const {context,page,consoleProblems}=await openPortalPage(browser,pages,viewport,apiHandler);
      await page.goto(ORIGIN+'/portal/');
      const review=page.locator('.portal-tool-review');await page.getByRole('button',{name:'Approve cleanup'}).waitFor();
      assert.match(await review.innerText(),/Synthetic workstation/);assert.match(await review.innerText(),/2 eligible files · 2\.0 KB/);
      assert.match(await review.innerText(),/C:\\Windows\\Temp/);assert.match(await review.innerText(),/permanently deletes only files in the exact unchanged preview set/);
      if(process.env.PORTAL_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.PORTAL_SCREENSHOT_DIR,`workspace-approval-${viewport.width}.png`)});
      assert.equal(posts.length,0,'rendering a preview cannot authorize deletion');
      await page.getByRole('button',{name:'Approve cleanup'}).click();await page.getByText('Waiting for your computer to report. You can leave this chat and return.',{exact:true}).waitFor();
      assert.equal(posts.length,1,'exact human approval posts once');
      for(const next of ['preview_queued','verifying','completed','cancel_delivered','unknown','offline']){
        phase=next;await page.reload();
        if(next==='offline')await page.getByText('The computer is not currently available.',{exact:true}).waitFor();
        else await review.waitFor();
        if(next==='completed'){assert.match(await review.innerText(),/2 files · 2\.0 KB/);assert.match(await review.innerText(),/55\.5% free/);}
        if(['cancel_delivered','unknown'].includes(next)){assert.match(await review.innerText(),/outcome is not confirmed/);assert.match(await review.innerText(),/Do not repeat this check/);assert.equal(await page.getByRole('button',{name:/Approve/}).count(),0);}
        const composer=await page.locator('#portal-chat-form').boundingBox(),footer=await page.locator('.portal-chat-foot').boundingBox();
        assert.ok(composer.y>=0&&composer.y+composer.height<=viewport.height,'usable composer remains in the viewport for '+next);
        assert.ok(footer.y>=0&&footer.y+footer.height<=viewport.height,'privacy/support footer remains in the viewport for '+next);
        const input=page.getByRole('textbox',{name:'Ask Westy about your computer'});assert.equal(await input.isEnabled(),true);await input.fill('');
        geometryProof.push({state:next,viewport,composer,footer,input_enabled:true});
        if(process.env.PORTAL_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.PORTAL_SCREENSHOT_DIR,`workspace-${next}-${viewport.width}.png`)});
      }
      assert.equal(posts.length,1,'refresh, offline and unknown results never replay work');assert.deepEqual(consoleProblems,[]);await context.close();
    }
    if(process.env.PORTAL_SCREENSHOT_DIR)await writeFile(path.join(process.env.PORTAL_SCREENSHOT_DIR,'workspace-geometry.json'),JSON.stringify(geometryProof,null,2)+'\n');
  }finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('late device responses cannot restore private names after logout',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  let release;const gate=new Promise(resolve=>release=resolve);
  const state={enabled:true,ai_available:true,can_write:true,conversation:'a'.repeat(32),conversations:[],draft:null,turns:[],devices:[{reference:WORKSPACE_RECEIPTS.health_queued.device_reference,label:'Synthetic private computer'}]};
  const {context,page}=await openPortalPage(browser,pages,{width:1000,height:800},async route=>{
    if(new URL(route.request().url()).searchParams.has('devices'))await gate;
    await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state})});
  });
  try{
    await page.goto(ORIGIN+'/portal/');await page.waitForFunction(()=>!document.getElementById('portal-chat-input').disabled);
    await page.evaluate(()=>{const channel=new BroadcastChannel('safeharbor-portal-access');channel.postMessage('signed-out');channel.close();});
    await page.getByText('Your sign-in ended or access changed. Sign in again to continue.',{exact:true}).waitFor();
    const response=page.waitForResponse(r=>r.url().includes('?devices=1'));release();await (await response).finished();
    // Allow the fetch continuation to run so the assertion observes the late result.
    await page.waitForTimeout(100);
    assert.equal(await page.locator('#portal-chat-device option').count(),1);assert.equal(await page.getByText('Synthetic private computer',{exact:true}).count(),0);
    assert.equal(await page.locator('#portal-chat-input').isDisabled(),true);
  }finally{release();await context.close();await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('general checks wait for receipts, resume once and stop in desktop and mobile chat',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  try{for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
    const conversation='a'.repeat(32),operation='b'.repeat(32);let resumes=0,stops=0,automatic=true;
    const state={enabled:true,ai_available:true,can_write:true,tools_enabled:true,conversation,conversations:[],draft:null,
      devices:[{reference:'1:'+ 'c'.repeat(64),label:'Synthetic computer'}],turns:[{operation_key:operation,state:'complete',
        input_text:'Investigate memory use.',reply:{reply:'Checking current process memory.',sources:[],tools:[
          {key:'invalid_0',name:'inspect_computer',state:'rejected',result:{state:'rejected',executed:false,correction_allowed:true,retry_allowed:false}},
          {key:'inspect_1',name:'inspect_computer',state:'complete',effect:'Read current process memory',result:{state:'running'}}]},
        run:{state:'waiting',ready:false,sequence:1}}]};
    const handler=async route=>{
      const payload=route.request().method()==='POST'?route.request().postDataJSON():null;
      if(payload?.action==='diagnostic_preference')automatic=payload.automatic_diagnostics;
      if(payload?.action==='stop'){stops++;state.turns[0].run=null;state.turns[0].reply.reply='Stopped. Waiting for cancellation confirmation.';}
      if(payload?.action==='run_resume'){
        assert.deepEqual(payload,{action:'run_resume',operation,conversation,sequence:1});resumes++;
        state.turns[0].run=null;state.turns[0].reply.reply='The completed memory result explains the slowdown.';
        state.turns[0].reply.tools[1].result={state:'completed',result:{stdout:'PRIVATE_RAW_OUTPUT',exit_code:0}};
        return route.fulfill({contentType:'text/event-stream',body:'event: done\ndata: '+JSON.stringify({state})+'\n\n'});
      }
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state:{...state,diagnostic_preference:{automatic_diagnostics:automatic}}})});
    };
    const {page,context,consoleProblems}=await openPortalPage(browser,pages,viewport,handler);
    await page.route('**/portal/desktop_sessions.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,result:{sessions:[]}})}));
    try{
      await page.goto(ORIGIN+'/portal/');await page.getByText('Computer investigation · Running',{exact:true}).waitFor();
      await page.getByText('Computer investigation · Not executed',{exact:true}).waitFor();
      await page.getByText('The plan was rejected before execution. Westy can use the validation feedback to correct it.',{exact:true}).waitFor();
      assert.equal(await page.getByText('Device tool',{exact:false}).count(),0);
      assert.equal(await page.locator('#portal-chat-input').isDisabled(),true);assert.equal(await page.locator('#portal-chat-stop').isVisible(),true);
      await page.getByLabel('Ask me before each automatic computer check').check();assert.equal(automatic,false);
      state.turns[0].run.ready=true;await page.getByText('The completed memory result explains the slowdown.',{exact:true}).waitFor();
      assert.equal(resumes,1);assert.equal(await page.locator('#portal-chat-input').isDisabled(),false);
      assert.equal(await page.getByText('PRIVATE_RAW_OUTPUT',{exact:false}).count(),0);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
      state.turns[0].run={state:'waiting',ready:false,sequence:2};await page.reload();await page.locator('#portal-chat-stop').click();
      await page.waitForFunction(()=>!document.getElementById('portal-chat-input').disabled);assert.equal(stops,1);assert.equal(resumes,1);
      await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pagehide')));
      assert.equal(await page.getByLabel('Ask me before each automatic computer check').count(),0);
      await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
      await page.getByLabel('Ask me before each automatic computer check').waitFor();assert.deepEqual(consoleProblems,[]);
    }finally{await context.close();}
  }}finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});

if (!SERVE_MODE) test('general runtime shows exact approval, live output, Stop and personal restrictions on desktop and mobile',async()=>{
  const {pages,scratch}=await renderedFixtures();const browser=await chromium.launch();
  try{for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
    const conversation='a'.repeat(32),operation='b'.repeat(32),fingerprint='e'.repeat(64);let approved=0,stopped=0,saved=0;
    const command={command:'Restart-Service -Name SyntheticService',working_directory:'C:\\Synthetic',execution_context:'system',timeout_seconds:120,effect:'Restart the synthetic service',tty:false};
    const settings={revision:0,restrictions:{commands:true,files:true,repairs:true,system:true,browser:true,desktop:true}};
    const state={enabled:true,ai_available:true,can_write:true,conversation,conversations:[],draft:null,
      devices:[{reference:'1:'+ 'c'.repeat(64),label:'Synthetic computer'}],turns:[{operation_key:operation,state:'complete',input_text:'Restart the synthetic service.',
        reply:{reply:'Review the exact service restart.',tools:[{key:'terminal_1',name:'exec_command',state:'awaiting_approval',awaiting_run:true,effect:command.effect}]},
        run:{state:'waiting',ready:false,sequence:1,receipt:{state:'awaiting_approval',execution_context:'system',process_id:'d'.repeat(32)},
          approval:{fingerprint,command,chars:null,reason:'This interrupts the service.'}}}]};
    const handler=async route=>{
      const payload=route.request().method()==='POST'?route.request().postDataJSON():null;
      if(payload?.action==='approve_terminal'){
        assert.deepEqual(payload,{action:'approve_terminal',operation,conversation,sequence:1,fingerprint,reviewed:true});approved++;
        const receipt={state:'running',execution_context:'system',process_id:'d'.repeat(32),progress:{sequence:1,exit_code:null,stdout:'Synthetic service is stopping…',stderr:'',truncated:false}};
        state.turns[0].reply.reply='The command is running on your computer.';
        state.turns[0].reply.tools[0].result=receipt;state.turns[0].reply.tools[0].awaiting_run=false;
        state.turns[0].run=null;state.turns[0].terminal_active=true;state.turns[0].terminal_processes={['d'.repeat(32)]:receipt};
      }else if(payload?.action==='stop'){
        assert.equal(payload.operation,operation);stopped++;state.turns[0].terminal_processes['d'.repeat(32)].state='unknown';state.turns[0].state='unavailable';
      }else if(payload?.action==='tool_preferences'){
        assert.equal(payload.revision,settings.revision);assert.equal(payload.restrictions.system,false);saved++;settings.revision++;settings.restrictions=payload.restrictions;
      }else if(payload)throw new Error('Unexpected action '+payload.action);
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state:{...state,tool_preferences:settings}})});
    };
    const {page,context,consoleProblems}=await openPortalPage(browser,pages,viewport,handler);
    await page.route('**/portal/desktop_sessions.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,result:{sessions:[]}})}));
    try{
      await page.goto(ORIGIN+'/portal/');assert.equal(new URL(page.url()).pathname,'/portal/');assert.match(await page.title(),/Westy|Safeharbor/i);
      await page.getByText('Review this exact action',{exact:true}).waitFor();assert.equal(approved,0);
      assert.equal(await page.getByRole('button',{name:'Approve action',exact:true}).isDisabled(),true);
      await page.getByText(command.command,{exact:true}).waitFor();await page.getByText('This interrupts the service.',{exact:true}).waitFor();
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
      if(process.env.WESTY_QA_OUTPUT){await mkdir(process.env.WESTY_QA_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.WESTY_QA_OUTPUT,`approval-${viewport.width}.png`),fullPage:false});}
      await page.getByLabel('I reviewed and approve this exact action.').check();await page.getByRole('button',{name:'Approve action',exact:true}).click();
      await page.getByText('Computer command · Running',{exact:true}).waitFor();assert.equal(approved,1);
      await page.getByText('View command output',{exact:true}).click();await page.getByText('Synthetic service is stopping…',{exact:true}).waitFor();
      assert.equal(await page.getByText('Exit code:',{exact:false}).count(),0,'running output never invents an exit code');
      await page.reload();await page.getByText('Computer command · Running',{exact:true}).waitFor();
      assert.equal(await page.locator('#portal-chat-stop').isVisible(),true,'final reply retains the global original-task Stop on reload');
      state.turns[0].terminal_processes['d'.repeat(32)].progress.stdout='Fresh command output after the final reply';
      await page.getByText('Fresh command output after the final reply',{exact:true}).waitFor({state:'attached',timeout:10000});
      await page.getByText('View command output',{exact:true}).click();
      if(process.env.WESTY_QA_OUTPUT)await page.screenshot({path:path.join(process.env.WESTY_QA_OUTPUT,`output-${viewport.width}.png`),fullPage:false});
      await page.getByRole('button',{name:'Stop this task',exact:true}).click();await page.getByText('Computer command · Outcome unknown',{exact:true}).waitFor();assert.equal(stopped,1);
      assert.equal(await page.locator('#portal-chat-stop').isVisible(),true,'unconfirmed Stop retains unresolved controls');
      const completed=state.turns[0].terminal_processes['d'.repeat(32)];completed.state='completed';completed.progress.exit_code=0;state.turns[0].terminal_active=false;
      await page.reload();await page.getByText('Computer command · Finished',{exact:true}).waitFor();await page.getByText('Exit code: 0',{exact:true}).waitFor();
      assert.equal(await page.locator('#portal-chat-stop').isVisible(),false,'actual final receipt clears global Stop');
      completed.content_expired=true;completed.progress.stdout='';completed.progress.stderr='';
      await page.reload();await page.getByText('Temporary command output expired after one day. The execution result remains recorded.',{exact:true}).waitFor();
      assert.equal(await page.getByText('View command output',{exact:true}).count(),0,'expired temporary output does not reappear on reload');
      await page.getByText('Computer tool permissions',{exact:true}).click();await page.getByLabel('Windows SYSTEM tools',{exact:true}).uncheck();
      await page.getByRole('button',{name:'Save permissions',exact:true}).click();await page.getByText('Your computer tool permissions are saved.',{exact:true}).waitFor();assert.equal(saved,1);
      await page.getByText('Computer tool permissions',{exact:true}).click();assert.equal(await page.getByLabel('Windows SYSTEM tools',{exact:true}).isChecked(),false);
      assert.deepEqual(consoleProblems,[]);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
      await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pagehide')));assert.equal(await page.getByText('Computer tool permissions',{exact:true}).count(),0);
    }finally{await context.close();}
  }}finally{await browser.close();await rm(scratch,{recursive:true,force:true});}
});
