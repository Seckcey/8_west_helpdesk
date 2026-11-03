import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const card = execFileSync('php', ['-r', `require $argv[1]; echo westy_workflow_card(['ticket_id'=>42,'workflow_key'=>'22222222-2222-4222-8222-222222222222','state'=>'working','summary'=>'Waiting for a technician to approve the repair.']);`, path.join(ROOT, 'app/lib/westy_workflow.php')], { encoding: 'utf8' });
const PAGE = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Safeharbor workflow contract</title><style>${CSS}</style></head>
<body data-active="ticket" data-tenant-id="1" data-user-id="1" data-csrf="fixture"><main style="width:100%;max-width:900px;padding:20px;margin:auto"><div id="toasts"></div><div id="thread" data-ticket-id="42">${card}<span data-assignee>Westy</span>
<div class="card reply"><form id="reply-form"><input type="hidden" id="composer-mode" value="reply"><div class="composer-tabs"><button type="button" class="composer-tab tab-on" data-mode="reply">Reply</button><button type="button" class="composer-tab" data-mode="note">Internal note</button></div><textarea id="reply-box" placeholder="Write a reply"></textarea><div class="reply-foot"><span id="composer-hint">No client contact is attached. Replies are saved on this ticket.</span><button id="composer-send" type="button">Send</button></div></form></div></div></main><script>${JS}</script></body></html>`;

for (const width of [1440, 390]) {
  test(`actual queue rows distinguish Westy, a technician and unassigned work at ${width}px`, async (t) => {
    const rows = execFileSync('php', [path.join(ROOT, 'app/tests/westy_queue_owner_test.php'), '--render'], { encoding: 'utf8' });
    const browser = await chromium.launch({ headless: true });
    t.after(() => browser.close());
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => route.abort());
    await page.setContent(`<!doctype html><html lang="en" data-theme="dark"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Queue owner check</title><style>${CSS}</style><body><main style="padding:16px;width:100%"><h1>Queue</h1>${rows}</main></body></html>`);
    await page.waitForFunction(() => [...document.querySelectorAll('.trow')].every(row => Number(getComputedStyle(row).opacity) > 0.99));
    assert.equal(await page.locator('[aria-label="Assigned to Westy"]').count(), 1);
    assert.deepEqual(await page.locator('.trow-tech').allTextContents(), ['W', 'FT', '—']);
    assert.equal(await page.locator('.trow-tech [title="Unassigned"]').count(), 1);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.deepEqual(errors, []);
    if (process.env.SAFEHARBOR_OWNER_SHOTS) {
      await mkdir(process.env.SAFEHARBOR_OWNER_SHOTS, { recursive: true });
      await page.screenshot({ path: path.join(process.env.SAFEHARBOR_OWNER_SHOTS, `safeharbor-owner-${width}.png`), fullPage: true });
    }
  });
}

async function fixture(t, { width = 1280, failure = false } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 844 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('http://safeharbor.test/**', async (route) => {
    if (route.request().url().includes('/api/ticket_action.php')) {
      const body = route.request().postDataJSON();
      assert.deepEqual(body, { id: '42', field: 'assignee', value: 'me' });
      await route.fulfill({ status: failure ? 409 : 200, contentType: 'application/json', body: JSON.stringify(failure ? { ok: false, error: 'conflict' } : { ok: true, toast: 'Assigned to you', chip: 'In Progress', pri: 'Normal', sla: 'On track', assignee_html: 'Fixture technician' }) });
    } else if (route.request().url().includes('/api/')) {
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    } else await route.fulfill({ contentType: 'text/html', body: PAGE });
  });
  await page.goto('http://safeharbor.test/');
  return { page, errors };
}

test('confirmed takeover preserves draft and makes human ownership visible', async (t) => {
  const { page, errors } = await fixture(t);
  await page.getByPlaceholder('Write a reply').fill('Keep this draft.');
  await page.getByRole('button', { name: 'Take over ticket', exact: true }).click();
  await page.getByRole('heading', { name: 'A technician owns this ticket' }).waitFor();
  assert.equal(await page.locator('#reply-box').inputValue(), 'Keep this draft.');
  assert.equal(await page.getByRole('button', { name: 'Take over ticket' }).count(), 0);
  assert.deepEqual(errors, []);
});

test('failed takeover retains working state and offers a retry', async (t) => {
  const { page, errors } = await fixture(t, { failure: true });
  await page.getByRole('button', { name: 'Take over ticket', exact: true }).click();
  await page.getByText("Couldn't save — check your connection.", { exact: true }).waitFor();
  assert.equal(await page.locator('.westy-workflow-card').getAttribute('data-workflow-state'), 'working');
  assert.equal(await page.getByRole('button', { name: 'Take over ticket', exact: true }).isEnabled(), true);
  assert.deepEqual(errors, []);
});

test('phone workflow fits and Reply keeps the truthful no-contact explanation', async (t) => {
  const { page, errors } = await fixture(t, { width: 390 });
  await page.getByRole('button', { name: 'Internal note', exact: true }).click();
  await page.getByRole('button', { name: 'Reply', exact: true }).click();
  assert.equal(await page.locator('#composer-hint').innerText(), 'No client contact is attached. Replies are saved on this ticket.');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  const link = await page.getByRole('link', { name: 'Open troubleshooting' }).getAttribute('href');
  assert.equal(link, 'https://support.8westit.com/westy_diag.php?workflow=22222222-2222-4222-8222-222222222222');
  assert.deepEqual(errors, []);
});

const GRANT_KEY = '88888888-8888-4888-8888-888888888888';
function grantCard(control, state = 'needs_human', key = '') {
  return execFileSync('php', ['-r', `require $argv[1]; echo westy_workflow_card(['ticket_id'=>42,'workflow_key'=>'22222222-2222-4222-8222-222222222222','state'=>$argv[3],'summary'=>'Earlier commands were not dispatched.','grant_control'=>$argv[2],'grant_key'=>$argv[4],'grant_version'=>2]);`, path.join(ROOT, 'app/lib/westy_workflow.php'), control, state, key], { encoding: 'utf8' });
}
function grantPage(control, state, key) {
  return PAGE.replace(card, grantCard(control, state, key)).replace('<span data-assignee>Westy</span>', '<span data-assignee>Fixture technician</span>');
}
async function grantFixture(t, { width = 1280, lost = false, stale = false, conflict = false } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [], requests = [], dialogs = [];
  let control = stale ? 'cancel' : 'create', state = stale ? 'human_owned' : 'needs_human', key = stale ? GRANT_KEY : '';
  page.on('pageerror', e => errors.push(e.message));
  page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
  await page.route('http://safeharbor.test/**', async route => {
    const req = route.request();
    if (req.url().includes('/api/westy_workflow_grant.php')) {
      const body = req.postDataJSON(); requests.push(body);
      assert.equal(req.headers()['x-csrf'], 'fixture');
      assert.equal(body.ticket_id, 42);
      assert.equal(body.workflow_key, '22222222-2222-4222-8222-222222222222');
      assert.equal(body.expected_version, 2);
      assert.match(body.grant_key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
      if (conflict) return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'version_conflict' }) });
      control = body.action === 'create' ? 'cancel' : (stale ? '' : 'create'); key = body.grant_key;
      if (lost && requests.length === 1) return route.abort('failed');
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, ...body, assignee_id: 1,
        grant_state: body.action === 'create' ? 'pending' : 'cancelled', created_at: '2026-11-03T00:00:00Z', cancelled_at: body.action === 'cancel' ? '2026-11-03T00:01:00Z' : null }) });
    }
    if (req.url().includes('/api/ticket_action.php')) throw new Error('Grant must not invoke ordinary ticket assignment');
    if (req.url().includes('/api/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
    return route.fulfill({ contentType: 'text/html', body: grantPage(control, state, key) });
  });
  await page.goto('http://safeharbor.test/ticket.php?id=42');
  await page.getByPlaceholder('Write a reply').fill('Keep the technician draft.');
  return { page, requests, errors, dialogs };
}

for (const width of [1280, 390]) {
  test(`assignee grant and cancellation preserve ownership and draft at ${width}px`, async t => {
    const { page, requests, errors, dialogs } = await grantFixture(t, { width });
    await page.getByRole('button', { name: 'Return to Westy', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel return request', exact: true }).waitFor();
    assert.equal(await page.locator('.westy-workflow-card').getAttribute('data-workflow-state'), 'needs_human');
    assert.equal(await page.locator('[data-assignee]').innerText(), 'Fixture technician');
    assert.equal(await page.locator('#reply-box').inputValue(), 'Keep the technician draft.');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    if (process.env.SAFEHARBOR_GRANT_SHOTS) {
      await mkdir(process.env.SAFEHARBOR_GRANT_SHOTS, { recursive: true });
      await page.screenshot({ path: path.join(process.env.SAFEHARBOR_GRANT_SHOTS, `safeharbor-grant-${width}.png`), fullPage: true });
    }
    await page.getByRole('button', { name: 'Cancel return request', exact: true }).click();
    await page.getByRole('button', { name: 'Return to Westy', exact: true }).waitFor();
    assert.equal(requests.length, 2);
    assert.equal(requests[0].grant_key, requests[1].grant_key);
    assert.equal(requests[1].action, 'cancel');
    assert.equal(await page.locator('#reply-box').inputValue(), 'Keep the technician draft.');
    assert.deepEqual(dialogs, []);
    assert.deepEqual(errors, []);
  });
}

test('lost create response retries exact intent then reads current card', async t => {
  const { page, requests, errors } = await grantFixture(t, { lost: true });
  await page.getByRole('button', { name: 'Return to Westy', exact: true }).click();
  await page.getByText('The request could not be confirmed. Retry to check the same request. Your draft is unchanged.', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Return to Westy', exact: true }).click();
  await page.getByRole('button', { name: 'Cancel return request', exact: true }).waitFor();
  assert.deepEqual(requests[0], requests[1]);
  assert.equal(await page.locator('#reply-box').inputValue(), 'Keep the technician draft.');
  assert.deepEqual(errors, []);
});

test('original creator cancels stale grant using original version', async t => {
  const { page, requests, errors } = await grantFixture(t, { stale: true });
  await page.getByRole('button', { name: 'Cancel return request', exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('[data-workflow-grant]'));
  assert.equal(requests[0].grant_key, GRANT_KEY);
  assert.equal(requests[0].expected_version, 2);
  assert.equal(await page.locator('.westy-workflow-card').getAttribute('data-workflow-state'), 'human_owned');
  assert.equal(await page.locator('#reply-box').inputValue(), 'Keep the technician draft.');
  assert.deepEqual(errors, []);
});

test('conflict never paints grant intent as ownership transfer', async t => {
  const { page, errors } = await grantFixture(t, { conflict: true });
  await page.getByRole('button', { name: 'Return to Westy', exact: true }).click();
  await page.getByText('The request no longer matches this workflow. Refresh the ticket to review its current state.', { exact: true }).waitFor();
  assert.equal(await page.locator('.westy-workflow-card').getAttribute('data-workflow-state'), 'needs_human');
  assert.equal(await page.locator('[data-assignee]').innerText(), 'Fixture technician');
  assert.deepEqual(errors, []);
});
