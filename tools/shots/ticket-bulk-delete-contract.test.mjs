import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Bulk delete on the real queue markup, stylesheet and script; the endpoint is a stub.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const QUEUE = execFileSync('php', [path.join(ROOT, 'app/tests/ticket_bulk_delete_render_test.php'), '--render'], { encoding: 'utf8' });
const PAGE = `<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bulk delete contract</title><style>${CSS}</style></head>
<body data-active="queue" data-tenant-id="1" data-user-id="1" data-csrf="fixture"><main class="content" style="padding:16px"><div class="page"><h1 class="page-title">Queue</h1>${QUEUE}</div></main><div id="palette-root"></div><div id="toasts" class="toasts"></div><script>${JS}</script></body></html>`;
const RUN_KEY = 'a'.repeat(32);
const PREVIEW = {
  ok: true, total: 3, not_found: 0, deletable: 2, deletable_ids: [101, 103], kept: 1, kept_by_reason: { time: 1 },
  kept_sample: [{ id: 102, subject: 'Kept <b>subject</b>', reason: 'time' }],
  effects: { messages: 4, attachments: 1, csat: 0, mail_unsent: 1, mail_sent: 0, westy_reports: 0, alert_tickets: 2 },
  permissions: { attachments: true, csat: true }, reasons: { time: 'Technician time is logged on it' },
  run_key: RUN_KEY, phrase: 'DELETE 2',
};
const TWO_BATCHES = [
  { ok: true, deleted: 1, kept: [], next_after_id: 101, done: false, files_failed: 0 },
  { ok: true, deleted: 1, kept: [], next_after_id: 103, done: true, files_failed: 0 },
];

async function fixture(t, { width = 1440, preview = PREVIEW, batches = TWO_BATCHES, failAt = null, dropAt = null, holdFirst = null, timer = null } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  const calls = [];
  let batch = 0;
  page.on('pageerror', (error) => errors.push(error.message));
  if (timer) await page.addInitScript((value) => localStorage.setItem('safeharbor.timer.v2.1:1', JSON.stringify(value)), timer);
  await page.route('http://safeharbor.test/**', async (route) => {
    const request = route.request();
    if (request.url().endsWith('/api/ticket_bulk_delete.php')) {
      assert.equal(request.method(), 'POST');
      assert.equal(request.headers()['x-csrf'], 'fixture');
      const body = request.postDataJSON();
      calls.push(body);
      if (body.action === 'preview') return route.fulfill({ contentType: 'application/json', body: JSON.stringify(preview) });
      const index = batch++;
      if (index === 0 && holdFirst) await holdFirst;
      if (index === dropAt) return route.abort('connectionreset');
      if (index === failAt) {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Delete stopped — nothing in this batch was changed.' }) });
      }
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(batches[Math.min(index, batches.length - 1)]) });
    }
    if (request.url().includes('/ticket.php')) return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Ticket</title>' });
    return route.fulfill({ contentType: 'text/html', body: PAGE });
  });
  await page.goto('http://safeharbor.test/');
  await page.waitForFunction(() => [...document.querySelectorAll('.trow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return { page, errors, calls };
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_BULK_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_BULK_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_BULK_SHOTS, `${name}.png`), fullPage: true });
}

async function openConfirm(page) {
  await page.getByRole('checkbox', { name: 'Select every ticket in this view' }).check();
  await page.getByRole('button', { name: 'Delete…' }).click();
  await page.getByRole('heading', { name: 'Permanently delete 2 tickets?' }).waitFor();
}

for (const width of [1440, 390]) {
  test(`picking rows at ${width}px: checkboxes never navigate, x toggles, Escape clears`, async (t) => {
    const { page, errors } = await fixture(t, { width });
    assert.equal(await page.locator('#bulk-bar').isHidden(), true);
    await page.getByRole('checkbox', { name: 'Select ticket #101' }).check();
    assert.equal(page.url(), 'http://safeharbor.test/');
    assert.equal(await page.locator('.bulk-count').innerText(), '1 selected');
    await page.keyboard.press('j');
    await page.keyboard.press('x');
    assert.equal(await page.getByRole('checkbox', { name: 'Select ticket #102' }).isChecked(), true);
    assert.equal(await page.locator('.bulk-count').innerText(), '2 selected');
    await shot(page, `bulk-picked-${width}`);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#bulk-bar').isHidden(), true);
    assert.equal(await page.getByRole('checkbox', { name: 'Select ticket #101' }).isChecked(), false);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.deepEqual(errors, []);
  });

  test(`the confirmation fits and stays text-only at ${width}px`, async (t) => {
    const { page, errors } = await fixture(t, { width });
    await openConfirm(page);
    assert.equal(await page.locator('.delete-kept b').count(), 0);
    assert.match(await page.locator('.delete-kept').innerText(), /#102 Kept <b>subject<\/b> — Technician time is logged on it/);
    assert.match(await page.locator('.delete-kept h3').innerText(), /^Kept — will not be deleted:$/);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await shot(page, `bulk-confirm-${width}`);
    assert.deepEqual(errors, []);
  });
}

test('select all names every row on the page, and the run sends only the previewed list', async (t) => {
  const { page, errors, calls } = await fixture(t);
  await page.getByRole('checkbox', { name: 'Select every ticket in this view' }).check();
  assert.equal(await page.locator('.bulk-count').innerText(), 'All 3 tickets in All are selected.');
  await page.getByRole('button', { name: 'Delete…' }).click();
  await page.getByRole('heading', { name: 'Permanently delete 2 tickets?' }).waitFor();
  assert.deepEqual(calls[0], { action: 'preview', scope: { ids: [101, 102, 103] } });
  const go = page.getByRole('button', { name: 'Delete 2 tickets' });
  const phrase = page.getByLabel('Type DELETE 2 to confirm');
  assert.equal(await go.isDisabled(), true);
  await phrase.fill('DELETE 3');
  assert.equal(await go.isDisabled(), true);
  await phrase.fill('DELETE 2');
  await go.click();
  await page.getByRole('heading', { name: 'Deleted 2 tickets.' }).waitFor();
  assert.deepEqual(calls.slice(1), [
    { action: 'delete', scope: { ids: [101, 103] }, after_id: 0, run_key: RUN_KEY, confirm: 'DELETE 2' },
    { action: 'delete', scope: { ids: [101, 103] }, after_id: 101, run_key: RUN_KEY, confirm: 'DELETE 2' },
  ]);
  assert.match(await page.locator('.delete-kept').innerText(), /Kept 1:[\s\S]*#102 Kept <b>subject<\/b>/);
  await shot(page, 'bulk-result-1440');
  assert.deepEqual(errors, []);
});

test('Enter on a focused Delete… opens the dialog instead of the highlighted ticket', async (t) => {
  const { page, errors } = await fixture(t);
  await page.getByRole('checkbox', { name: 'Select ticket #103' }).check();
  await page.locator('#bulk-delete').focus();
  await page.keyboard.press('Enter');
  await page.getByRole('heading', { name: 'Permanently delete 2 tickets?' }).waitFor();
  assert.equal(page.url(), 'http://safeharbor.test/');
  assert.deepEqual(errors, []);
});

test('x then d opens the dialog from the keyboard, and Escape closes it before anything runs', async (t) => {
  const { page, errors, calls } = await fixture(t);
  await page.keyboard.press('x');
  await page.keyboard.press('d');
  await page.getByRole('heading', { name: 'Permanently delete 2 tickets?' }).waitFor();
  assert.deepEqual(calls[0], { action: 'preview', scope: { ids: [101] } });
  assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.closest('.delete-panel') !== null), true);
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('#delete-overlay').count(), 0);
  assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.id), 'bulk-delete');
  assert.equal(calls.length, 1);
  assert.deepEqual(errors, []);
});

test('stop after this batch leaves the rest untouched', async (t) => {
  let release;
  const holdFirst = new Promise((resolve) => { release = resolve; });
  const { page, errors, calls } = await fixture(t, { holdFirst });
  await openConfirm(page);
  await page.getByLabel('Type DELETE 2 to confirm').fill('DELETE 2');
  await page.getByRole('button', { name: 'Delete 2 tickets' }).click();
  await page.getByRole('button', { name: 'Stop after this batch' }).click();
  await page.getByText('Stopping after this batch…').waitFor();
  release();
  await page.getByRole('heading', { name: 'Stopped. Deleted 1 of 2; the rest were not touched.' }).waitFor();
  assert.equal(calls.filter((c) => c.action === 'delete').length, 1);
  assert.deepEqual(errors, []);
});

test('a batch the server refuses says nothing changed and resumes where it stopped', async (t) => {
  const { page, errors, calls } = await fixture(t, { failAt: 1 });
  await openConfirm(page);
  await page.getByLabel('Type DELETE 2 to confirm').fill('DELETE 2');
  await page.getByRole('button', { name: 'Delete 2 tickets' }).click();
  await page.getByRole('heading', { name: 'Delete stopped' }).waitFor();
  assert.equal(await page.locator('.delete-error').innerText(), 'Delete stopped — nothing in this batch was changed.');
  assert.match(await page.locator('.delete-panel').innerText(), /1 ticket was deleted before this\./);
  await page.getByRole('button', { name: 'Try again' }).click();
  await page.getByRole('heading', { name: 'Deleted 2 tickets.' }).waitFor();
  assert.equal(calls.at(-1).after_id, 101);
  assert.deepEqual(errors, []);
});

test('a dropped connection does not claim the batch changed nothing', async (t) => {
  const { page, errors } = await fixture(t, { dropAt: 1 });
  await openConfirm(page);
  await page.getByLabel('Type DELETE 2 to confirm').fill('DELETE 2');
  await page.getByRole('button', { name: 'Delete 2 tickets' }).click();
  await page.getByRole('heading', { name: 'Delete stopped' }).waitFor();
  const text = await page.locator('.delete-error').innerText();
  assert.match(text, /may or may not have finished\. Try again carries on safely\./);
  assert.doesNotMatch(text, /nothing in/);
  await page.getByRole('button', { name: 'Try again' }).click();
  await page.getByRole('heading', { name: 'Deleted 2 tickets.' }).waitFor();
  assert.deepEqual(errors, []);
});

test('a lost answer for a batch the server did finish still ends with the right count', async (t) => {
  const batches = [
    { ok: true, deleted: 1, run_deleted: 1, kept: [], next_after_id: 101, done: false, files_failed: 0 },
    { ok: true, deleted: 1, run_deleted: 2, kept: [], next_after_id: 103, done: true, files_failed: 0 },   // answer lost
    { ok: true, deleted: 0, run_deleted: 2, kept: [], next_after_id: 103, done: true, files_failed: 0 },   // the retry finds it gone
  ];
  const { page, errors } = await fixture(t, { batches, dropAt: 1 });
  await openConfirm(page);
  await page.getByLabel('Type DELETE 2 to confirm').fill('DELETE 2');
  await page.getByRole('button', { name: 'Delete 2 tickets' }).click();
  await page.getByRole('heading', { name: 'Delete stopped' }).waitFor();
  await page.getByRole('button', { name: 'Try again' }).click();
  await page.getByRole('heading', { name: 'Deleted 2 tickets.' }).waitFor();
  assert.deepEqual(errors, []);
});

test('clicking a checkbox moves the highlight there, so x and Enter act on that row', async (t) => {
  const { page, errors } = await fixture(t);
  await page.getByRole('checkbox', { name: 'Select ticket #103' }).check();
  assert.equal(await page.locator('.trow.trow-sel').getAttribute('data-ticket-id'), '103');
  await page.keyboard.press('x');
  assert.equal(await page.getByRole('checkbox', { name: 'Select ticket #103' }).isChecked(), false);
  assert.equal(await page.getByRole('checkbox', { name: 'Select ticket #101' }).isChecked(), false);
  // A tick without focus (how Safari clicks a checkbox) moves the highlight too.
  await page.getByRole('checkbox', { name: 'Select ticket #101' }).evaluate((box) => box.click());
  assert.equal(await page.locator('.trow.trow-sel').getAttribute('data-ticket-id'), '101');
  // Moving focus onto another checkbox moves it; Enter then opens exactly that row.
  await page.getByRole('checkbox', { name: 'Select ticket #101' }).focus();
  await page.getByRole('checkbox', { name: 'Select ticket #102' }).focus();
  assert.equal(await page.locator('.trow.trow-sel').getAttribute('data-ticket-id'), '102');
  await page.keyboard.press('Enter');
  await page.waitForURL(/ticket\.php\?id=102$/);
  assert.deepEqual(errors, []);
});

test('the window regaining focus does not pull the highlight back to an old checkbox', async (t) => {
  const { page, errors } = await fixture(t);
  await page.getByRole('checkbox', { name: 'Select ticket #101' }).check();
  await page.keyboard.press('j');
  await page.keyboard.press('j');
  assert.equal(await page.locator('.trow.trow-sel').getAttribute('data-ticket-id'), '103');
  // What a browser does when the window comes back: focus fires again on the still-focused box.
  await page.getByRole('checkbox', { name: 'Select ticket #101' }).evaluate((box) => box.dispatchEvent(new FocusEvent('focus', { relatedTarget: null })));
  assert.equal(await page.locator('.trow.trow-sel').getAttribute('data-ticket-id'), '103');
  assert.deepEqual(errors, []);
});

test('tickets someone else has open right now are called out', async (t) => {
  const { page, errors } = await fixture(t, { preview: { ...PREVIEW, effects: { ...PREVIEW.effects, open_elsewhere: 2 } } });
  await openConfirm(page);
  assert.match(await page.locator('.delete-panel').innerText(), /2 of these tickets are open for someone else right now\./);
  assert.deepEqual(errors, []);
});

test('an unlogged timer on a ticket about to go is called out', async (t) => {
  const { page, errors } = await fixture(t, { timer: { ticketId: 103, startedAt: Date.now() - 60000, tenantId: '1', userId: '1', state: 'active', entryKey: 'fixture-entry' } });
  await openConfirm(page);
  assert.match(await page.locator('.delete-panel').innerText(), /Your timer on #103 hasn't been logged yet/);
  assert.deepEqual(errors, []);
});

test('when nothing can be deleted the dialog only explains and closes', async (t) => {
  const { page, errors } = await fixture(t, { preview: { ...PREVIEW, deletable: 0, deletable_ids: [], kept: 3, kept_by_reason: { time: 3 }, phrase: null } });
  await page.getByRole('checkbox', { name: 'Select every ticket in this view' }).check();
  await page.getByRole('button', { name: 'Delete…' }).click();
  await page.getByRole('heading', { name: 'Nothing here can be deleted' }).waitFor();
  assert.match(await page.locator('.delete-panel').innerText(), /None of the 3 selected tickets can be deleted; the reasons are below\./);
  assert.equal(await page.getByRole('textbox').count(), 0);
  await page.getByRole('button', { name: 'Close' }).click();
  assert.equal(await page.locator('#delete-overlay').count(), 0);
  assert.deepEqual(errors, []);
});
