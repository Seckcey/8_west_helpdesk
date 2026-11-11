import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The Time page in plain words, and its three small dialogs, on the real stylesheet and script. The
// rows come from the real helper (app/tests/time_page_test.php --render). What the dialogs send,
// their keys and their retries are pinned in time-contract.test.mjs; this checks what a person
// sees and does: the words, History, the dialogs' fields and keyboard, and a measured correction.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const ROWS = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/time_page_test.php'), '--render'], { encoding: 'utf8' }));
const ORIGIN = 'http://safeharbor.test';

const html = (theme, zone) => `<!doctype html><html lang="en" data-theme="${theme}"${zone ? ` data-time-zone="${zone}"` : ''}><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Time</title><style>${CSS}</style></head>
<body data-active="time" data-tenant-id="7" data-user-id="11" data-csrf="fixture"><main class="content"><div class="page page-time">
<div class="page-head"><div><h1 class="page-title">Time</h1><p class="page-sub">55 min logged today. 1 waiting for review. 1 approved and billable today.</p></div></div>
<div class="card timer-card" id="timer-card" data-state="idle"><span class="timer-dot" id="timer-dot"></span><div class="timer-info"><div class="timer-title" id="timer-title">No timer running</div>
<div class="timer-sub" id="timer-sub"></div></div><span class="timer-clock" id="timer-clock"></span><button class="btn-link timer-discard" id="timer-discard" hidden>Discard</button><button class="btn-gold" id="timer-stop" hidden>Stop and log</button></div>
<h2 class="rail-label">Today</h2><div class="card rail-list">${ROWS.today}</div>
<h2 class="rail-label">Earlier</h2><div class="card rail-list" id="time-entry-history">${ROWS.earlier}${ROWS.measured}</div>
<h2 class="rail-label">To review</h2><div class="card rail-list" id="time-review-queue">${ROWS.review}</div>
<div class="time-ledger-head"><h2 class="rail-label">Approved, ready for billing</h2><form class="time-search" method="get" action="/time.php" id="approved-search" role="search">
<label class="sr-only" for="approved-q">Search approved time by ticket number or client</label><input type="search" name="approved" id="approved-q" placeholder="Ticket number or client" autocomplete="off" maxlength="100">
<button type="submit" class="btn-secondary">Search</button></form></div>
<div class="card rail-list" id="time-adjustment-ledger">${ROWS.ledger}</div>
<p class="page-note">Approving time does not send an invoice. Invoices are made in Coastmark.</p></div></main>
<div id="palette-root"></div><div id="toasts" class="toasts" role="status" aria-live="polite"></div><div id="timer-widget"></div>
<script id="palette-data" type="application/json">{"tickets":[],"clients":[]}</script><script>${JS}</script></body></html>`;

// zone: the Safeharbor time-zone setting the page is drawn with (History's times use it, and so do
// the pickers). computer: the zone of the computer the browser runs on, which must not matter.
async function fixture(t, { width = 1440, theme = 'dark', zone = 'America/Los_Angeles', computer = 'Asia/Tokyo' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const context = await browser.newContext({ viewport: { width, height: 900 }, timezoneId: computer });
  const page = await context.newPage();
  const errors = [];
  const sent = [];   // [path, body] for every api request
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route(`${ORIGIN}/**`, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (!url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'text/html', body: html(theme, zone) });
    const body = request.postDataJSON();
    sent.push([url.pathname, body]);
    const answer = url.pathname === '/api/time_entry_correction.php' ? { ok: true, entry: { entry_key: body.entry_key }, toast: 'Correction sent for review.' }
      : url.pathname === '/api/time_entry_review.php' ? { ok: true, toast: 'Time rejected.', review_ack: { entry_id: body.entry_id, decision: body.decision, reviewer_user_id: 11, note: body.note, replayed: false } }
      : url.pathname === '/api/time_entry_adjustment.php' ? { ok: true, toast: 'Change saved.', adjustment_ack: { adjustment_key: body.adjustment_key, entry_id: body.entry_id, version_no: body.expected_version + 1,
        effective_minutes: body.effective_minutes, effective_billable: body.effective_billable, adjusted_by_user_id: 11, reason: body.reason, replayed: false } }
      : { ok: true };
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(answer) });
  });
  await page.goto(`${ORIGIN}/time.php`);
  await page.waitForFunction(() => document.readyState === 'complete');
  return { page, errors, sent };
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_TIME_SHOTS) return;
  await page.waitForTimeout(200);
  await mkdir(process.env.SAFEHARBOR_TIME_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_TIME_SHOTS, `${name}.png`), fullPage: true });
}

const dialog = (page) => page.getByRole('dialog');
const settle = (page) => page.waitForTimeout(150);
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const focused = (page) => page.evaluate(() => document.activeElement.id || document.activeElement.className || document.activeElement.tagName);
const squash = (text) => text.replace(/\s+/g, ' ').trim();
const contrast = (page, selector) => page.locator(selector).evaluateAll((nodes) => nodes.filter((el) => el.offsetParent !== null).map((el) => {
  const rgba = (value) => { const n = (value.match(/[\d.]+/g) || []).map(Number); return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 }; };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  const layers = [];
  for (let node = el; node; node = node.parentElement) layers.push(rgba(getComputedStyle(node).backgroundColor));
  const behind = layers.reverse().reduce((under, top) => over(top, under), { r: 255, g: 255, b: 255, a: 1 });
  const luminance = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const [hi, lo] = [luminance(over(rgba(getComputedStyle(el).color), behind)), luminance(behind)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}));

test('a row says how long, what, which ticket and where it stands; the rest is under History', async (t) => {
  const { page, errors } = await fixture(t);
  const rows = await page.locator('.entry-row').evaluateAll((list) => list.map((row) => ({
    min: row.querySelector('.entry-min').textContent,
    tags: [...row.querySelectorAll('.entry-tags .entry-badge')].map((tag) => tag.textContent),
    historyOpen: row.querySelector('.entry-history').open,
  })));
  assert.deepEqual(rows, [
    { min: '20 min', tags: ['Billable', 'Approved'], historyOpen: false },
    { min: '35 min', tags: ['Billable', 'Waiting for review'], historyOpen: false },
    { min: '17 min', tags: ['Not billable', 'Rejected'], historyOpen: false },
    { min: '20 min', tags: ['Not billable', 'Rejected'], historyOpen: false },
    { min: '35 min', tags: ['Billable'], historyOpen: true },      // to review: what a reviewer weighs is open
    { min: '45 min', tags: ['Not billable'], historyOpen: false },  // approved, then changed twice
    { min: '20 min', tags: ['Not billable'], historyOpen: false },
  ]);
  const text = squash(await page.locator('.page-time').innerText());
  assert.doesNotMatch(text, /UTC|user #|\bversion\b|·|internal|\d+m\b/, 'no UTC stamps, user numbers, record versions, dot chains or stored words');
  assert.match(text, /Approving time does not send an invoice\. Invoices are made in Coastmark\./);
  // History, opened, holds what used to be printed on the row.
  const first = page.locator('.entry-row').first();
  await first.locator('summary').click();
  assert.deepEqual(await first.locator('.entry-history li').allInnerTexts(),
    ['Worked 9:30 AM.', 'Logged from the timer.', 'Measured from 9:10 AM to 9:30 AM.', 'Approved as 20 min, billable, by Olive Owner, 9:45 AM.']);
  assert.equal(squash(await page.locator('.review-row .entry-history').innerText()), 'History Worked 9:30 AM. Logged from the timer. Measured from 9:10 AM to 9:30 AM.');
  assert.deepEqual(await page.locator('.review-actions .time-review').evaluateAll((buttons) => buttons.map((button) => button.className)),
    ['btn-primary time-review', 'btn-secondary time-review'], 'Approve is the primary button');
  assert.deepEqual(errors, []);
});

test('Reject is a dialog: one labelled box, said-inside errors, Escape, and Ctrl+Enter', async (t) => {
  const { page, errors, sent } = await fixture(t);
  const reject = page.getByRole('button', { name: 'Reject', exact: true });
  await reject.click();
  await page.getByRole('heading', { name: 'Reject this time entry' }).waitFor();
  assert.equal(await focused(page), 'reject-note');
  assert.equal(await dialog(page).getByLabel('Why? The technician sees this.').count(), 1);
  await shot(page, 'time-reject-1440-dark');
  // Page keys do nothing behind it, and Tab stays inside.
  await page.keyboard.press('Escape');
  assert.equal(await dialog(page).count(), 0);
  assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Reject', 'focus goes back to the button');
  assert.deepEqual(sent, [], 'cancelling sends nothing');
  assert.equal(await page.locator('.review-row').count(), 1);

  await reject.click();
  await dialog(page).getByRole('button', { name: 'Reject entry' }).click();
  assert.equal(await page.getByRole('alert').innerText(), 'Say why, so the technician can correct it.');
  assert.deepEqual(sent, []);
  await page.keyboard.type('  Missing the customer\'s name  ');
  for (let presses = 0; presses < 5; presses++) {
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => !!document.activeElement.closest('[role="dialog"]')), true, 'focus stays in the dialog');
  }
  await page.locator('#reject-note').focus();
  await page.keyboard.press('g');          // a letter, not the start of "g then q"
  await page.keyboard.press('Backspace');
  await page.keyboard.press('Control+Enter');
  await page.getByText('Time rejected.').waitFor();
  assert.deepEqual(sent, [['/api/time_entry_review.php', { entry_id: 73, decision: 'rejected', note: 'Missing the customer\'s name' }]]);
  assert.equal(await page.locator('#time-review-queue .empty').innerText(), 'No time is waiting for review.');
  assert.deepEqual(errors, []);
});

test('Change is a dialog: minutes, billable or not, and why; it starts from what counts now', async (t) => {
  const { page, errors, sent } = await fixture(t);
  const row = page.locator('.adjustment-row[data-time-entry-id="88"]');
  await row.getByRole('button', { name: 'Change', exact: true }).click();
  await page.getByRole('heading', { name: 'Change approved time' }).waitFor();
  assert.equal(await page.locator('#change-minutes').inputValue(), '45', 'the minutes that count now');
  assert.deepEqual(await dialog(page).getByRole('radio').evaluateAll((radios) => radios.map((radio) => [radio.value, radio.checked])), [['1', false], ['0', true]],
    'approved as billable, so billable is a choice; it counts as not billable now');
  assert.match(await dialog(page).innerText(), /From 0 to 60, the minutes that were approved\. 0 cancels this approval\. To add time, log a new entry\./);
  await shot(page, 'time-change-1440-dark');
  await page.locator('#change-minutes').fill('61');
  await page.locator('#change-reason').fill('Checked the call log');
  await dialog(page).getByRole('button', { name: 'Save change' }).click();
  assert.equal(await page.getByRole('alert').innerText(), 'A change cannot add time. Log extra work as a new entry.');
  await page.locator('#change-minutes').fill('50');
  await dialog(page).getByRole('radio', { name: 'Billable', exact: true }).check();
  await dialog(page).getByRole('button', { name: 'Save change' }).click();
  await page.getByText('Change saved.').waitFor();
  assert.equal(sent.length, 1);
  assert.deepEqual({ ...sent[0][1], adjustment_key: 'key' }, { entry_id: 88, adjustment_key: 'key', expected_version: 2, effective_minutes: 50, effective_billable: true, reason: 'Checked the call log' });
  assert.deepEqual(errors, []);
});

test('Change to 0 minutes is never billable, and time approved as not billable has no billable choice', async (t) => {
  let { page, errors, sent } = await fixture(t);
  await page.locator('.adjustment-row[data-time-entry-id="88"]').getByRole('button', { name: 'Change', exact: true }).click();
  await page.locator('#change-minutes').fill('0');
  await dialog(page).getByRole('radio', { name: 'Billable', exact: true }).check();
  await page.locator('#change-reason').fill('Logged twice');
  await dialog(page).getByRole('button', { name: 'Save change' }).click();
  await page.getByText('Change saved.').waitFor();
  assert.equal(sent[0][1].effective_minutes, 0);
  assert.equal(sent[0][1].effective_billable, false, 'nothing is billable about no minutes');
  assert.deepEqual(errors, []);

  ({ page, errors, sent } = await fixture(t));
  await page.locator('.adjustment-row[data-time-entry-id="89"]').getByRole('button', { name: 'Change', exact: true }).click();
  assert.equal(await dialog(page).getByRole('radio').count(), 0);
  assert.match(await dialog(page).innerText(), /This time was approved as not billable, so it stays not billable\./);
  assert.deepEqual(errors, []);
});

test('Correct and resubmit, unmeasured: minutes, what the work was, billable or not', async (t) => {
  const { page, errors, sent } = await fixture(t);
  await page.locator('.time-correct[data-rejected-entry-id="51"]').click();
  await page.getByRole('heading', { name: 'Correct and resubmit' }).waitFor();
  assert.equal(await page.locator('#correct-minutes').inputValue(), '17');
  assert.equal(await page.locator('#correct-note').inputValue(), 'Laptop build');
  assert.equal(await dialog(page).locator('input[type="datetime-local"]').count(), 0, 'an unmeasured entry has no start and end to pick');
  await page.locator('#correct-minutes').fill('0');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  assert.equal(await page.getByRole('alert').innerText(), 'Minutes must be a whole number from 1 to 1440.');
  await page.locator('#correct-minutes').fill('25');
  await page.locator('#correct-note').fill('Laptop build, asset tag 4417');
  await dialog(page).getByRole('radio', { name: 'Billable', exact: true }).check();
  await page.keyboard.press('Enter');
  await page.getByText('Correction sent for review.').waitFor();
  assert.deepEqual({ ...sent[0][1], entry_key: 'key' }, { rejected_entry_id: 51, entry_key: 'key', worked_at: '2026-11-09T20:00:00Z', started_at: null, ended_at: null,
    minutes: 25, note: 'Laptop build, asset tag 4417', billable: 1 });
  assert.match(sent[0][1].entry_key, /^correction:/);
  assert.deepEqual(errors, []);
});

test('Correct and resubmit: minutes are digits only, so "1e2" is not 100 and "0x1e" is not 30', async (t) => {
  const { page, errors, sent } = await fixture(t);
  await page.locator('.time-correct[data-rejected-entry-id="51"]').click();
  for (const typed of ['1e2', '0x1e', '1.5', '+30', '030', '3 0', '30m', '']) {
    await page.locator('#correct-minutes').fill(typed);
    await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
    assert.equal(await page.getByRole('alert').innerText(), 'Minutes must be a whole number from 1 to 1440.', `"${typed}" is refused`);
    assert.equal(await page.locator('#correct-minutes').inputValue(), typed, 'and is left as typed, to be corrected');
  }
  await settle(page);
  assert.deepEqual(sent, [], 'none of them was sent');
  await page.locator('#correct-minutes').fill(' 30 ');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  await page.getByText('Correction sent for review.').waitFor();
  assert.equal(sent.length, 1);
  assert.strictEqual(sent[0][1].minutes, 30, 'spaces around a whole number are not part of it');
  assert.deepEqual(errors, []);
});

test('Correct and resubmit, measured: start and end are picked in the Safeharbor time zone and sent in UTC', async (t) => {
  let { page, errors, sent } = await fixture(t);
  await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
  // 17:10 to 17:30 UTC is 9:10 to 9:30 in Los Angeles, the setting History's times are shown in. The
  // computer is in Tokyo, where it is 2:10 the next morning: that must not be what the picker says.
  assert.match(await dialog(page).innerText(), /Times are in America\/Los Angeles time, as History shows them\./);
  assert.equal(await page.locator('#correct-start').inputValue(), '2026-11-10T09:10');   // a picker leaves out seconds that are :00
  assert.equal(await page.locator('#correct-end').inputValue(), '2026-11-10T09:30');
  assert.equal(await page.locator('#correct-minutes').count(), 0, 'the minutes come from the start and the end');
  await shot(page, 'time-correct-1440-dark');
  await page.locator('#correct-end').fill('2026-11-10T09:05');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  assert.equal(await page.getByRole('alert').innerText(), 'The end has to be after the start, and no more than 24 hours after it.');
  await page.locator('#correct-end').fill('2026-11-10T09:40');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  await page.getByText('Correction sent for review.').waitFor();
  // The work's own time (17:30 UTC) is still between the corrected start and end, so it is kept.
  assert.deepEqual({ ...sent[0][1], entry_key: 'key' }, { rejected_entry_id: 52, entry_key: 'key', worked_at: '2026-11-10T17:30:00Z', started_at: '2026-11-10T17:10:00Z',
    ended_at: '2026-11-10T17:40:00Z', minutes: 30, note: 'Laptop imaging', billable: 0 });
  assert.deepEqual(errors, []);

  // Moved to another hour: the work's time can no longer be 17:30, so it becomes the corrected end.
  ({ page, errors, sent } = await fixture(t));
  await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
  await page.locator('#correct-start').fill('2026-11-10T08:00');
  await page.locator('#correct-end').fill('2026-11-10T08:15:30');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  await page.getByText('Correction sent for review.').waitFor();
  assert.deepEqual([sent[0][1].started_at, sent[0][1].ended_at, sent[0][1].worked_at, sent[0][1].minutes], ['2026-11-10T16:00:00Z', '2026-11-10T16:15:30Z', '2026-11-10T16:15:30Z', 16]);
  assert.deepEqual(errors, []);

  ({ page, errors, sent } = await fixture(t));
  await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
  await page.locator('#correct-end').fill('');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  assert.equal(await page.getByRole('alert').innerText(), 'Pick the end date and time.');
  assert.deepEqual(sent, []);
  assert.deepEqual(errors, []);
});

test('a time in the hour that repeats when the clocks go back is asked about, never guessed', async (t) => {
  // In Los Angeles the clocks went back at 2:00 on 1 November 2026: 1:00 to 1:59 happened twice.
  const { page, errors, sent } = await fixture(t);
  await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
  assert.equal(await page.locator('#correct-start-twice').isHidden(), true);
  await page.locator('#correct-start').fill('2026-11-01T01:10');
  await page.locator('#correct-end').fill('2026-11-01T01:40');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  assert.equal(await page.getByRole('alert').innerText(), 'That start time happened twice, because the clocks went back that night. Say which one you mean.');
  assert.deepEqual(sent, []);
  const which = (name) => page.getByRole('group', { name });
  assert.equal(await which('That start time happened twice. Which one?').isVisible(), true);
  assert.equal(await which('That end time happened twice. Which one?').isVisible(), true, 'both are asked about at once');
  await which('That start time happened twice. Which one?').getByRole('radio', { name: 'The first, before the clocks went back' }).check();
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  assert.equal(await page.getByRole('alert').innerText(), 'That end time happened twice, because the clocks went back that night. Say which one you mean.');
  await which('That end time happened twice. Which one?').getByRole('radio', { name: 'The second, after they went back' }).check();
  await shot(page, 'time-correct-twice-1440-dark');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  await page.getByText('Correction sent for review.').waitFor();
  // The first 1:10 is daylight time (UTC-7), the second 1:40 is standard time (UTC-8): 90 minutes apart, not 30.
  assert.deepEqual([sent[0][1].started_at, sent[0][1].ended_at, sent[0][1].minutes], ['2026-11-01T08:10:00Z', '2026-11-01T09:40:00Z', 90]);
  assert.deepEqual(errors, []);
});

test('the question goes away when the time is moved out of the repeated hour, and a skipped hour is refused', async (t) => {
  const { page, errors, sent } = await fixture(t);
  await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
  await page.locator('#correct-start').fill('2026-11-01T01:10');
  await page.locator('#correct-end').fill('2026-11-01T03:00');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  await page.locator('#correct-start-twice').getByRole('radio', { name: 'The second, after they went back' }).check();
  assert.equal(await page.locator('#correct-end-twice').isHidden(), true, '3:00 happened once');
  // Moved to midnight: nothing to ask, and the earlier answer is not kept for later.
  await page.locator('#correct-start').fill('2026-11-01T00:10');
  await dialog(page).getByRole('button', { name: 'Send corrected entry' }).click();
  await page.getByText('Correction sent for review.').waitFor();
  assert.deepEqual([sent[0][1].started_at, sent[0][1].ended_at, sent[0][1].minutes], ['2026-11-01T07:10:00Z', '2026-11-01T11:00:00Z', 230]);
  assert.deepEqual(errors, []);

  // The clocks went forward at 2:00 on 8 March 2026: 2:00 to 2:59 never happened.
  const again = await fixture(t);
  await again.page.locator('.time-correct[data-rejected-entry-id="52"]').click();
  await again.page.locator('#correct-start').fill('2026-03-08T02:30');
  await again.page.locator('#correct-end').fill('2026-03-08T03:30');
  await dialog(again.page).getByRole('button', { name: 'Send corrected entry' }).click();
  assert.equal(await again.page.getByRole('alert').innerText(), 'That start time does not exist: the clocks went forward that night and skipped it. Pick another time.');
  await again.page.locator('#correct-start').fill('2026-03-08T01:30');
  await dialog(again.page).getByRole('button', { name: 'Send corrected entry' }).click();
  await again.page.getByText('Correction sent for review.').waitFor();
  // 1:30 standard time to 3:30 daylight time is one hour, not two.
  assert.deepEqual([again.sent[0][1].started_at, again.sent[0][1].ended_at, again.sent[0][1].minutes], ['2026-03-08T09:30:00Z', '2026-03-08T10:30:00Z', 60]);
  assert.deepEqual(again.errors, []);
});

test('the pickers follow the setting, whatever it is, and fall back to the computer only when the page names no zone', async (t) => {
  let { page, errors } = await fixture(t, { zone: 'Europe/London', computer: 'America/Los_Angeles' });
  await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
  assert.equal(await page.locator('#correct-start').inputValue(), '2026-11-10T17:10');
  assert.match(await dialog(page).innerText(), /Times are in Europe\/London time/);
  assert.deepEqual(errors, []);

  for (const zone of ['', 'Not/AZone']) {
    ({ page, errors } = await fixture(t, { zone, computer: 'Asia/Tokyo' }));
    await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
    assert.equal(await page.locator('#correct-start').inputValue(), '2026-11-11T02:10', `with "${zone}" the computer's own zone is used`);
    assert.match(await dialog(page).innerText(), /Times are in Asia\/Tokyo time/);
    assert.deepEqual(errors, []);
  }
});

test('a double click on Reject, Change or Correct and resubmit opens its dialog and leaves it open', async (t) => {
  for (const [opener, heading] of [
    ['.review-row .time-review[data-decision="rejected"]', 'Reject this time entry'],
    ['.adjustment-row[data-time-entry-id="88"] .time-adjust', 'Change approved time'],
    ['.time-correct[data-rejected-entry-id="51"]', 'Correct and resubmit'],
  ]) {
    const { page, errors, sent } = await fixture(t);
    await page.locator(opener).dblclick();
    await settle(page);
    assert.equal(await dialog(page).count(), 1, `${heading} is still open after a double click`);
    assert.equal(await page.getByRole('heading', { name: heading }).count(), 1);
    // Later, a press outside the dialog still closes it.
    await page.waitForTimeout(350);
    await page.mouse.click(5, 5);
    assert.equal(await dialog(page).count(), 0);
    assert.deepEqual(sent, []);
    assert.deepEqual(errors, []);
  }
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: the page and its dialogs read well at desktop width`, async (t) => {
    const { page, errors } = await fixture(t, { theme });
    await page.locator('.entry-row').first().locator('summary').click();
    await page.locator('.adjustment-row[data-time-entry-id="88"] summary').click();
    for (const selector of ['.entry-min', '.entry-note', '.entry-badge', '.entry-lineage', '.entry-review-note', '.entry-history summary', '.entry-history li', '.billing-state', '.page-note', '.page-sub', '.rail-label']) {
      for (const ratio of await contrast(page, selector)) assert.ok(ratio >= 4.5, `${selector} reads at ${ratio.toFixed(2)} to 1`);
    }
    assert.equal(await fits(page), true);
    await shot(page, `time-1440-${theme}`);
    await page.locator('.adjustment-row[data-time-entry-id="88"]').getByRole('button', { name: 'Change', exact: true }).click();
    for (const selector of ['.form-dialog h2', '.form-dialog-sub', '.form-dialog label', '.form-dialog .field-help', '.form-dialog .seg-item span']) {
      for (const ratio of await contrast(page, selector)) assert.ok(ratio >= 4.5, `${selector} reads at ${ratio.toFixed(2)} to 1`);
    }
    await shot(page, `time-change-1440-${theme}`);
    assert.deepEqual(errors, []);
  });

  test(`${theme}: on a phone nothing overflows, every control is a 44px target, and a dialog fits`, async (t) => {
    const { page, errors } = await fixture(t, { width: 390, theme });
    assert.equal(await fits(page), true);
    const heights = await page.locator('.entry-row button, .entry-row summary, .time-search input, .time-search button').evaluateAll((nodes) => nodes.map((node) => [node.textContent.trim() || node.name, node.getBoundingClientRect().height]));
    for (const [name, height] of heights) assert.ok(height >= 44, `${name} is ${height}px tall`);
    await shot(page, `time-390-${theme}`);
    await page.locator('.time-correct[data-rejected-entry-id="52"]').click();
    await page.getByRole('heading', { name: 'Correct and resubmit' }).waitFor();
    const panel = await dialog(page).evaluate((node) => { const box = node.getBoundingClientRect(); return [box.left, box.right, box.bottom]; });
    assert.ok(panel[0] >= 0 && panel[1] <= 390 && panel[2] <= 900, 'the dialog is inside the screen');
    // (The "which one?" choices for a repeated hour are in the dialog but not shown until they are needed.)
    for (const height of await dialog(page).locator('input:not([type="radio"]), button, .seg-item').evaluateAll((nodes) => nodes.filter((node) => node.offsetParent !== null).map((node) => node.getBoundingClientRect().height))) {
      assert.ok(height >= 44, `a dialog control is ${height}px tall`);
    }
    assert.equal(await fits(page), true);
    await shot(page, `time-correct-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}
