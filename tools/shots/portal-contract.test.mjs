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
const PORTAL_RENDER_SOURCE = await readFile(path.join(ROOT, 'app/lib/portal_render.php'), 'utf8');
const ORIGIN = 'http://safeharbor.test';
const SERVE_MODE = process.argv.includes('--serve');

const FIXTURE_PHP = String.raw`<?php
declare(strict_types=1);
function portal_csrf_token(): string { return str_repeat('c', 64); }
function portal_action_nonce(string $purpose, ?int $now = null): string {
    return hash('sha256', $purpose . ':' . ($now ?? 1));
}
require $argv[1];
require dirname($argv[1]) . '/business_reports.php';
require $argv[2];
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
  for (const name of ['dashboard', 'ticket', 'new', 'reports', 'report']) {
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

async function openPortalPage(browser, pages, viewport) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  const consoleProblems = [];
  page.on('pageerror', (error) => consoleProblems.push(error.message));
  page.on('console', (message) => {
    if (['error', 'warning'].includes(message.type())) consoleProblems.push(message.text());
  });
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/assets/css/app.css') {
      return route.fulfill({ contentType: 'text/css; charset=utf-8', body: APP_CSS });
    }
    if (url.pathname === '/assets/brand/favicon.svg') {
      return route.fulfill({ contentType: 'image/svg+xml', body: FAVICON });
    }
    const body = url.pathname === '/portal/' ? pages.dashboard
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
    assert.equal(await desktop.page.title(), 'Help center · Safeharbor');
    await desktop.page.getByRole('heading', { name: 'Your support requests' }).waitFor();
    await desktop.page.getByRole('heading', { name: 'Waiting for your reply' }).waitFor();
    await desktop.page.getByRole('link', { name: 'Open support request' }).first().waitFor();
    await desktop.page.getByRole('heading', { name: 'Service summaries' }).waitFor();
    assert.equal(await desktop.page.locator('body').evaluate((body) => body.scrollWidth <= body.clientWidth), true);

    await desktop.page.getByRole('link', { name: /#102.*Front desk printer is offline/ }).click();
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
