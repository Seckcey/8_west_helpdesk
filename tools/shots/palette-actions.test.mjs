import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The palette acts, not only goes: what it offers for the ticket that is open or highlighted, its
// Create and Go to groups, the key on every row, the keys added beside it (/, g r, g u, u) and the
// shortcut card. Real stylesheet and script; the queue and the ticket page come from the real
// render helpers (app/tests/ticket_controls_render_test.php --render); the endpoints are a
// stand-in that keeps what it is sent.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const FIX = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/ticket_controls_render_test.php'), '--render', '--status=open', '--audience=email'], { encoding: 'utf8' }));
const ORIGIN = 'http://safeharbor.test';
const DATA = {
  tickets: [{ id: 42, subject: 'Printer is down', priority: 'normal', client: 'Acme Dental' }, { id: 7, subject: 'Sign-in loop', priority: 'high', client: 'Harbor Logistics' }],
  clients: [{ id: 3, name: 'Acme Dental', domain: 'acme.example' }, { id: 5, name: 'Harbor Logistics', domain: 'harbor.example' }],
  menus: FIX.menus,
};

function html(view, theme) {
  const island = JSON.stringify(DATA).replace(/</g, '\\u003C');
  const body = view === 'queue' ? `<div class="page"><h1 class="page-title">Queue</h1><label class="field">Find<input type="text" id="some-box"></label>${FIX.queue}</div>` : FIX.ticket;
  return `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Safeharbor</title><style>${CSS}</style></head>
<body data-active="queue" data-tenant-id="1" data-user-id="7" data-csrf="fixture"><div class="shell"><aside class="sidebar" id="suite-sidebar"><nav class="nav"><a href="/" class="nav-item nav-on"><span class="nav-label">Queue</span><kbd class="kbd nav-kbd">G Q</kbd></a></nav>
<button type="button" class="nav-item nav-foot" id="shortcuts-open"><span class="nav-label">Keyboard shortcuts</span><kbd class="kbd nav-kbd">?</kbd></button></aside>
<div class="main"><header class="topbar"><button id="palette-trigger" class="search-trigger" type="button" aria-label="Search or run a command"><span class="search-label">Search or command…</span></button>
<a class="w365-menu-item" role="menuitem" href="/profile.php" aria-label="My settings"><span>My settings</span></a></header><main class="content">${body}</main></div></div>
<div id="palette-root"></div><div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<script id="canned-data" type="application/json">{"snippets":[],"merge":{}}</script><script id="palette-data" type="application/json">${island}</script><script>${JS}</script></body></html>`;
}

async function fixture(t, { view = 'queue', width = 1440, theme = 'dark', deep = [] } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  const calls = [];   // ticket_action bodies, in order
  const state = { status: view === 'queue' ? 'waiting' : 'open' };
  page.on('pageerror', (error) => errors.push(error.message));
  // The test origin is not https, so the browser offers no clipboard there: stand in for it.
  await page.addInitScript(() => {
    window.copied = [];
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: (text) => { window.copied.push(text); return Promise.resolve(); } } });
  });
  const tag = (list, value) => FIX.menus[list].find((choice) => choice.value === value).html;
  await page.route(`${ORIGIN}/**`, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/api/ticket_action.php') {
      const sent = request.postDataJSON();
      calls.push(sent);
      if (sent.field === 'status') state.status = sent.value;
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, toast: `${sent.field} is now ${sent.value}.`, status: state.status, priority: 'normal', assignee_id: null,
        chip: tag('status', state.status), pri: tag('priority', 'normal'), sla: '<span class="sla sla-healthy"><span class="sla-dot"></span>Due in 6 h</span>',
        assignee_html: '<span class="assignee-cell"><span class="assignee-name">Unassigned</span></span>', assignee_avatar: '<span class="avatar">—</span>' }) });
    }
    if (url.pathname === '/api/search.php') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, results: deep }) });
    if (url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true,"others":[]}' });
    return route.fulfill({ contentType: 'text/html', body: html(url.pathname === '/ticket.php' ? 'ticket' : url.pathname === '/' ? 'queue' : view, theme) });
  });
  await page.goto(ORIGIN + (view === 'queue' ? '/' : '/ticket.php?id=42'));
  await page.waitForFunction(() => document.readyState === 'complete' && [...document.querySelectorAll('.trow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return { page, errors, calls };
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_PALETTE_SHOTS) return;
  await page.waitForTimeout(200);
  await mkdir(process.env.SAFEHARBOR_PALETTE_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_PALETTE_SHOTS, `${name}.png`) });
}

const where = (page) => page.url().slice(ORIGIN.length);
const settle = (page) => page.waitForTimeout(150);
const open = async (page) => { await page.keyboard.press('Control+k'); await page.locator('.palette-input').waitFor(); };
const groups = (page) => page.locator('.palette-group').allTextContents();
const rows = (page) => page.locator('.palette-item-main').allTextContents();
// Each row as "Title [keys]", so a row and its key are read together.
const rowsWithKeys = (page) => page.locator('.palette-item').evaluateAll((items) => items.map((item) => {
  const keys = [...item.querySelectorAll('.palette-item-keys kbd')].map((key) => key.textContent);
  return item.querySelector('.palette-item-main').textContent + (keys.length ? ` [${keys.join(' ')}]` : '');
}));
// The rows under one group heading.
const under = (page, group) => page.locator('#palette-list').evaluate((list, name) => {
  const out = [];
  let on = false;
  for (const node of list.children) {
    if (node.classList.contains('palette-group')) on = node.textContent === name;
    else if (on) out.push(node.querySelector('.palette-item-main').textContent);
  }
  return out;
}, group);
const choose = async (page, title) => { await page.locator('.palette-item', { has: page.locator('.palette-item-main', { hasText: new RegExp(`^${title}$`) }) }).click(); };
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const contrast = (page, selector) => page.locator(selector).evaluateAll((nodes) => nodes.map((el) => {
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

test('with no ticket open or highlighted: Create, Go to, Account, then tickets and clients, each row with its key', async (t) => {
  const { page, errors } = await fixture(t);
  await open(page);
  assert.deepEqual(await groups(page), ['Create', 'Go to', 'Account', 'Tickets', 'Clients']);
  assert.deepEqual(await rowsWithKeys(page), ['New ticket [c]', 'New client', 'New saved reply',
    'Queue [g q]', 'Time [g t]', 'Clients [g c]', 'Service goals [g s]', 'Reports [g r]', 'Team [g u]', 'Saved replies', 'My settings', 'Keyboard shortcuts [?]',
    'Sign out', '#42 Printer is down', '#7 Sign-in loop', 'Acme Dental', 'Harbor Logistics']);
  assert.doesNotMatch(await page.locator('.palette-foot').innerText(), /Queue keys/, 'the foot does not repeat the queue\'s keys');
  assert.match(await page.locator('.palette-foot').innerText(), /move.*choose.*every shortcut/s);
  await shot(page, 'palette-1440-dark');
  // My settings goes where the account menu's own link goes.
  await page.locator('.palette-input').fill('my set');
  assert.deepEqual((await rows(page)).slice(0, 1), ['My settings']);
  await page.keyboard.press('Enter');
  await page.waitForURL(`${ORIGIN}/profile.php`);
  assert.deepEqual(errors, []);
});

test('on the queue a highlighted row gets its own group: open it, change it, time it, resolve it, copy its link', async (t) => {
  const { page, errors, calls } = await fixture(t);
  await page.keyboard.press('j');
  await open(page);
  assert.equal((await groups(page))[0], 'Ticket #101, highlighted');
  assert.deepEqual((await rowsWithKeys(page)).slice(0, 7),
    ['Open [↵]', 'Change status [s]', 'Assign to [t]', 'Set priority [p]', 'Start timer [e]', 'Resolve', 'Copy link']);
  assert.deepEqual(await under(page, 'Ticket #101, highlighted'), ['Open', 'Change status', 'Assign to', 'Set priority', 'Start timer', 'Resolve', 'Copy link'],
    'a row has no reply box and no Merge: those are on the ticket');
  await shot(page, 'palette-row-1440-dark');

  // The same menu as the S key, for that row.
  await choose(page, 'Change status');
  assert.equal(await page.locator('.palette-overlay').count(), 0, 'the palette gets out of the way');
  await page.getByRole('listbox', { name: 'Status' }).waitFor();
  await page.getByRole('option', { name: /^Open/ }).click();
  await page.getByText('status is now open.').waitFor();
  assert.deepEqual(calls, [{ id: '101', field: 'status', value: 'open', chosen: true }]);

  await open(page);
  await choose(page, 'Resolve');
  await page.getByText('status is now resolved.').waitFor();
  assert.deepEqual(calls[1], { id: '101', field: 'status', value: 'resolved', chosen: true }, 'Resolve is a chosen resolve, as the button is');
  await open(page);
  assert.ok((await under(page, 'Ticket #101, highlighted')).includes('Reopen'), 'a resolved ticket offers Reopen instead');

  await choose(page, 'Copy link');
  await page.getByText('Link copied.').waitFor();
  assert.deepEqual(await page.evaluate(() => window.copied), [`${ORIGIN}/ticket.php?id=101`]);

  await open(page);
  await choose(page, 'Open');
  await page.waitForURL(`${ORIGIN}/ticket.php?id=101`);
  assert.deepEqual(errors, []);
});

test('with no row highlighted, or on a phone where no highlight shows, the palette offers nothing for "a ticket"', async (t) => {
  let { page, errors } = await fixture(t);
  await open(page);
  assert.equal((await groups(page)).some((name) => name.startsWith('Ticket #') || name === 'This ticket'), false);
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t, { width: 390 }));
  await page.keyboard.press('j');
  await open(page);
  assert.equal((await groups(page)).some((name) => name.startsWith('Ticket #')), false, 'a row nobody can see highlighted is not acted on');
  assert.equal((await groups(page))[0], 'Create');
  assert.deepEqual(errors, []);
});

test('on a ticket "This ticket" comes first and each row does what its key does', async (t) => {
  const { page, errors, calls } = await fixture(t, { view: 'ticket' });
  await open(page);
  assert.equal((await groups(page))[0], 'This ticket');
  assert.deepEqual(await under(page, 'This ticket'), ['Change status', 'Assign to', 'Set priority', 'Start timer', 'Reply', 'Add internal note',
    'Send and wait for the customer', 'Send and resolve', 'Merge into another ticket', 'Resolve', 'Copy link']);
  assert.deepEqual((await rowsWithKeys(page)).slice(0, 6), ['Change status [s]', 'Assign to [t]', 'Set priority [p]', 'Start timer [e]', 'Reply [r]', 'Add internal note [n]']);
  await shot(page, 'palette-ticket-1440-dark');

  await choose(page, 'Set priority');
  await page.getByRole('listbox', { name: 'Priority' }).waitFor();
  assert.equal(await page.locator('.menu-btn[data-action="priority"]').getAttribute('aria-expanded'), 'true', 'the menu opens under its own button');
  await page.keyboard.press('Escape');

  await open(page);
  await page.locator('.palette-input').fill('assign');
  assert.deepEqual((await rows(page)).slice(0, 1), ['Assign to']);
  await page.keyboard.press('Enter');
  await page.getByRole('combobox', { name: 'Assign to' }).waitFor();
  await page.keyboard.press('Escape');

  await open(page);
  await choose(page, 'Add internal note');
  assert.equal(await page.evaluate(() => document.activeElement.id), 'reply-box');
  assert.equal(await page.locator('#composer-mode').inputValue(), 'note');
  await page.locator('h1').click();
  await open(page);
  await choose(page, 'Reply');
  assert.equal(await page.evaluate(() => document.activeElement.id), 'reply-box');
  assert.equal(await page.locator('#composer-mode').inputValue(), 'reply');
  await page.locator('h1').click();

  await open(page);
  await choose(page, 'Start timer');
  await page.getByText('Timer started').waitFor();
  await open(page);
  assert.ok((await under(page, 'This ticket')).includes('Stop timer and log time'), 'with the timer on this ticket the row stops it');
  assert.equal((await under(page, 'This ticket')).includes('Start timer'), false);
  await page.keyboard.press('Escape');

  await open(page);
  await choose(page, 'Copy link');
  await page.getByText('Link copied.').waitFor();
  assert.deepEqual(await page.evaluate(() => window.copied), [`${ORIGIN}/ticket.php?id=42`]);

  await open(page);
  await choose(page, 'Resolve');
  await page.getByText('status is now resolved.').waitFor();
  assert.deepEqual(calls, [{ id: '42', field: 'status', value: 'resolved', chosen: true }]);
  await open(page);
  assert.ok((await under(page, 'This ticket')).includes('Reopen'));
  assert.deepEqual(errors, []);
});

test('a client that was typed offers a new ticket for them as the row under their name', async (t) => {
  const { page, errors } = await fixture(t);
  await open(page);
  await page.locator('.palette-input').fill('acme');
  assert.deepEqual((await rows(page)).slice(0, 2), ['Acme Dental', 'New ticket for Acme Dental']);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await page.waitForURL(`${ORIGIN}/ticket_new.php?client=3`);
  assert.deepEqual(errors, []);
});

test('nothing found says what was looked for; a ticket found only in its messages says its status in words', async (t) => {
  let { page, errors } = await fixture(t);
  await open(page);
  await page.locator('.palette-input').fill('  zzqx ');
  assert.equal(await page.locator('.palette-empty').innerText(), 'No ticket, client or action matches “zzqx”.');
  assert.equal(await page.locator('.palette-item').count(), 0);
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t, { deep: [{ id: 900, subject: 'Old router swap', client: 'Acme Dental', status: 'in_progress' }] }));
  await open(page);
  await page.locator('.palette-input').fill('router');
  await page.locator('.palette-item', { hasText: '#900 Old router swap' }).waitFor();
  assert.deepEqual(await groups(page), ['More tickets']);
  assert.equal(await page.locator('.palette-item .palette-item-hint').innerText(), 'Acme Dental, In progress');
  assert.deepEqual(errors, []);
});

test('the keys beside the palette: / searches, g r and g u go to Reports and Team, u goes back to the list', async (t) => {
  let { page, errors } = await fixture(t);
  await page.locator('#some-box').click();
  await page.keyboard.type('a/b u');
  await settle(page);
  assert.equal(await page.locator('.palette-overlay').count(), 0, 'in a text box / and u are characters');
  assert.equal(await page.locator('#some-box').inputValue(), 'a/b u');
  assert.equal(where(page), '/');
  await page.locator('h1').click();
  await page.keyboard.press('u');
  await settle(page);
  assert.equal(where(page), '/', 'the queue is the list: u has nowhere to go back to');
  await page.keyboard.press('/');
  await page.locator('.palette-input').waitFor();
  assert.equal(await page.locator('.palette-input').inputValue(), '', 'the slash that opened the search is not typed into it');
  await page.keyboard.press('Escape');
  await page.keyboard.press('g');
  await page.keyboard.press('r');
  await page.waitForURL(`${ORIGIN}/reports.php`);
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t));
  await page.keyboard.press('g');
  await page.keyboard.press('u');
  await page.waitForURL(`${ORIGIN}/users.php`);
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t, { view: 'ticket' }));
  await page.locator('#reply-box').click();
  await page.keyboard.type('u');
  await settle(page);
  assert.equal(where(page), '/ticket.php?id=42');
  await page.locator('h1').click();
  await page.keyboard.press('u');
  await page.waitForURL(`${ORIGIN}/`);
  assert.deepEqual(errors, []);
});

test('Keyboard shortcuts: the rail\'s button and the palette\'s row open the same card, which lists every key', async (t) => {
  const { page, errors } = await fixture(t);
  await page.getByRole('button', { name: 'Keyboard shortcuts' }).click();
  const card = page.getByRole('dialog', { name: 'Keyboard shortcuts' });
  await card.waitFor();
  const words = (await card.innerText()).replace(/\s+/g, ' ');
  for (const part of ['back to the list', 'new ticket', 'search and actions', 'Reports', 'Team', 'previous', 'next', 'status', 'priority', 'timer', 'reply', 'note']) {
    assert.ok(words.includes(part), `the card mentions "${part}"`);
  }
  await page.keyboard.press('Escape');
  assert.equal(await card.count(), 0);
  await open(page);
  await page.locator('.palette-input').fill('keyb');
  await page.keyboard.press('Enter');
  await card.waitFor();
  assert.equal(await page.locator('.palette-overlay:has(.palette-input)').count(), 0, 'the palette closes when the card opens');
  await page.keyboard.press('Escape');
  await page.keyboard.press('?');
  await card.waitFor();
  assert.deepEqual(errors, []);
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: the palette reads well at desktop width and fits a phone`, async (t) => {
    let { page, errors } = await fixture(t, { view: 'ticket', theme });
    await open(page);
    for (const selector of ['.palette-group', '.palette-item-main', '.palette-item-hint', '.palette-item-keys kbd', '.palette-foot span', '.palette-input']) {
      for (const ratio of await contrast(page, selector)) assert.ok(ratio >= 4.5, `${selector} reads at ${ratio.toFixed(2)} to 1`);
    }
    await shot(page, `palette-ticket-1440-${theme}`);
    assert.deepEqual(errors, []);

    ({ page, errors } = await fixture(t, { view: 'ticket', theme, width: 390 }));
    await page.locator('#palette-trigger').click();
    await page.locator('.palette-input').waitFor();
    const panel = await page.locator('.palette').evaluate((node) => { const box = node.getBoundingClientRect(); return [box.left, box.right]; });
    assert.ok(panel[0] >= 0 && panel[1] <= 390, 'the palette is inside the screen');
    assert.equal(await fits(page), true);
    for (const height of await page.locator('.palette-item').evaluateAll((items) => items.map((item) => item.getBoundingClientRect().height))) {
      assert.ok(height >= 44, `a row is ${height}px tall`);
    }
    await shot(page, `palette-ticket-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}
