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
