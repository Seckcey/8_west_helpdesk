import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Status, priority and owner menus, Resolve with Undo, and "send and set the next status", on the
// real stylesheet and script. The page is built from the real render helpers
// (app/tests/ticket_controls_render_test.php --render); the endpoints are a small stand-in that
// keeps one ticket's state, so every request the page makes can be read back.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const render = (...args) => JSON.parse(execFileSync('php',
  [path.join(ROOT, 'app/tests/ticket_controls_render_test.php'), '--render', ...args], { encoding: 'utf8' }));
const ME = 7;
const STATUS_LABELS = ['Open', 'In progress', 'Waiting', 'Resolved'];

function html(fix, { view = 'ticket', theme = 'dark', extra = '' } = {}) {
  const island = JSON.stringify({ tickets: [], clients: [], menus: fix.menus }).replace(/</g, '\\u003C');
  const canned = JSON.stringify({ snippets: [{ id: 1, title: 'Restart the spooler', body: 'Please restart the print spooler, {contact.first_name}.' }],
    merge: { 'contact.first_name': 'Dana' } });
  return `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ticket menus</title><style>${CSS}</style></head>
<body data-active="queue" data-tenant-id="1" data-user-id="${ME}" data-csrf="fixture"><main class="content">${view === 'queue' ? `<div class="page"><h1 class="page-title">Queue</h1>${fix.queue}</div>` : fix.ticket}${extra}</main>
<div id="palette-root"></div><div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<script id="canned-data" type="application/json">${canned}</script><script id="palette-data" type="application/json">${island}</script><script>${JS}</script></body></html>`;
}

async function fixture(t, { width = 1440, status = 'open', audience = 'email', view = 'ticket', theme = 'dark', extra = '', fail = false } = {}) {
  const fix = render(`--status=${status}`, `--audience=${audience}`);
  const page_html = html(fix, { view, theme, extra });
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  const calls = [];   // ticket_action bodies, in order
  const posts = [];   // reply form posts, as URLSearchParams
  const state = { status, priority: 'normal', assignee: null };
  const tag = (list, value) => fix.menus[list].find((choice) => choice.value === value).html;
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('http://safeharbor.test/**', async (route) => {
    const request = route.request();
    const url = request.url();
    if (url.endsWith('/api/ticket_action.php')) {
      assert.equal(request.method(), 'POST');
      assert.equal(request.headers()['x-csrf'], 'fixture');
      const body = request.postDataJSON();
      calls.push(body);
      if (fail) return route.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false,"error":"down"}' });
      if (body.field === 'status') state.status = body.value;
      if (body.field === 'priority') state.priority = body.value;
      if (body.field === 'assignee') state.assignee = body.value === 'none' ? null : Number(body.value);
      const person = fix.menus.team.find((member) => member.id === state.assignee);
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({
        ok: true, toast: `${body.field} is now ${body.value}.`, status: state.status, priority: state.priority, assignee_id: state.assignee,
        chip: tag('status', state.status), pri: tag('priority', state.priority), sla: '<span class="sla">On track</span>',
        assignee_html: `<span class="assignee-cell"><span class="assignee-name">${person ? person.initials : 'Unassigned'}</span></span>`,
        assignee_avatar: `<span class="avatar">${person ? person.initials : '—'}</span>`,
      }) });
    }
    if (url.includes('/api/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true,"others":[]}' });
    if (request.method() === 'POST') posts.push(new URLSearchParams(request.postData() || ''));
    return route.fulfill({ contentType: 'text/html', body: page_html });
  });
  await page.goto(view === 'queue' ? 'http://safeharbor.test/' : 'http://safeharbor.test/ticket.php?id=42');
  if (view === 'queue') await page.waitForFunction(() => [...document.querySelectorAll('.trow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return { page, errors, calls, posts, state, fix };
}

async function shot(page, name) {
  if (process.env.SAFEHARBOR_MENU_SHOTS) await page.waitForTimeout(250);   // let a toast finish arriving
  if (!process.env.SAFEHARBOR_MENU_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_MENU_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_MENU_SHOTS, `${name}.png`), fullPage: true });
}

const menuButton = (page, field) => page.locator(`.menu-btn[data-action="${field}"]`);
const options = (page) => page.getByRole('option');
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const settle = (page) => page.waitForTimeout(150);   // long enough for a wrongly sent request to show up
// A reply post reloads the page: wait for that post to arrive, then for the fresh page's empty box.
async function posted(page, posts, count) {
  for (let tries = 0; tries < 150 && posts.length < count; tries++) await page.waitForTimeout(20);
  assert.equal(posts.length, count);
  await page.waitForFunction(() => document.readyState === 'complete' && document.querySelector('#reply-box')?.value === '');
}

test('Waiting back to Open is one chosen step and never passes through Resolved', async (t) => {
  const { page, errors, calls } = await fixture(t, { status: 'waiting' });
  await menuButton(page, 'status').click();
  await page.getByRole('listbox', { name: 'Status' }).waitFor();
  assert.deepEqual(await options(page).allInnerTexts().then((texts) => texts.map((text) => text.replace(/\s*\d$/, '').trim())), STATUS_LABELS);
  assert.deepEqual(await page.locator('[role="option"][aria-selected="true"]').allInnerTexts().then((texts) => texts.map((text) => text.replace(/\s*\d$/, '').trim())), ['Waiting']);
  assert.equal(await menuButton(page, 'status').getAttribute('aria-expanded'), 'true');
  await shot(page, 'status-menu-1440-dark');
  await page.getByRole('option', { name: /^Open/ }).click();
  await page.getByText('status is now open.').waitFor();
  assert.deepEqual(calls, [{ id: '42', field: 'status', value: 'open', chosen: true }]);
  assert.equal(calls.some((call) => call.value === 'resolved'), false);
  assert.equal(await page.getByRole('listbox').count(), 0);
  assert.equal(await menuButton(page, 'status').innerText().then((text) => text.replace(/\s+/g, ' ').trim()), 'Open S');
  assert.deepEqual(errors, []);
});

test('S, P and A open their menus: arrows and Enter, a number key, Escape', async (t) => {
  const { page, errors, calls } = await fixture(t);
  await page.keyboard.press('s');
  await page.getByRole('listbox', { name: 'Status' }).waitFor();
  await page.keyboard.press('Escape');
  assert.equal(await page.getByRole('listbox').count(), 0);
  assert.equal(await page.evaluate(() => document.activeElement === document.body || document.activeElement.dataset.action === 'status'), true);
  assert.deepEqual(calls, [], 'opening and closing a menu changes nothing');

  await page.keyboard.press('s');
  await page.keyboard.press('ArrowDown');           // Open (current) to In progress
  await page.keyboard.press('Enter');
  await page.getByText('status is now in_progress.').waitFor();
  await page.keyboard.press('s');
  await page.keyboard.press('Enter');               // Enter on the current value closes the menu, no request
  await page.keyboard.press('p');
  await page.getByRole('listbox', { name: 'Priority' }).waitFor();
  await page.keyboard.press('3');
  await page.getByText('priority is now high.').waitFor();
  assert.deepEqual(calls, [
    { id: '42', field: 'status', value: 'in_progress', chosen: true },
    { id: '42', field: 'priority', value: 'high' },
  ]);

  // While a menu is open its keys do not reach the page: "r" must not jump to the reply box.
  await page.keyboard.press('s');
  await page.keyboard.press('r');
  assert.equal(await page.evaluate(() => document.activeElement.id), 'ticket-menu-status');
  await page.keyboard.press('Escape');
  assert.deepEqual(errors, []);
});

test('the owner menu offers Me, Unassigned and teammates, and finds one by name', async (t) => {
  const { page, errors, calls } = await fixture(t);
  await page.keyboard.press('a');
  const filter = page.getByRole('combobox', { name: 'Assign to' });
  await filter.waitFor();
  assert.equal(await filter.evaluate((el) => el === document.activeElement), true);
  const names = await page.locator('.menu-item-name').allInnerTexts();
  assert.deepEqual(names, ['Me', 'Unassigned', 'Jon <Sato>']);
  assert.equal(await page.locator('.menu-pop b, .menu-pop sato').count(), 0, 'a teammate\'s name is text, never markup');
  assert.deepEqual(await page.locator('[role="option"][aria-selected="true"] .menu-item-name').allInnerTexts(), ['Unassigned']);
  await shot(page, 'owner-menu-1440-dark');
  await filter.fill('jon');
  assert.deepEqual(await page.locator('.menu-item-name').allInnerTexts(), ['Jon <Sato>']);
  await page.keyboard.press('Enter');
  await page.getByText('assignee is now 9.').waitFor();

  await page.keyboard.press('a');
  await page.keyboard.press('1');                   // Me
  await page.getByText(`assignee is now ${ME}.`).waitFor();
  await page.keyboard.press('a');
  await page.keyboard.press('2');                   // Unassigned
  await page.getByText('assignee is now none.').waitFor();
  assert.deepEqual(calls, [
    { id: '42', field: 'assignee', value: '9' },
    { id: '42', field: 'assignee', value: String(ME) },
    { id: '42', field: 'assignee', value: 'none' },
  ]);

  await page.keyboard.press('a');
  await page.getByRole('combobox', { name: 'Assign to' }).fill('nobody here');
  await page.getByText('No teammate matches.').waitFor();
  await page.keyboard.press('Enter');
  assert.equal(calls.length, 3, 'Enter on an empty list assigns nobody');
  assert.deepEqual(errors, []);
});

test('Resolve is one button, and Undo (or Z) puts the status back without asking for a survey', async (t) => {
  const { page, errors, calls } = await fixture(t, { status: 'in_progress' });
  const resolve = page.getByRole('button', { name: 'Resolve', exact: true });
  const reopen = page.getByRole('button', { name: 'Reopen', exact: true });
  assert.equal(await reopen.count(), 0);
  await resolve.click();
  await page.getByText('status is now resolved.').waitFor();
  assert.deepEqual(calls[0], { id: '42', field: 'status', value: 'resolved', chosen: true });
  await reopen.waitFor();
  assert.equal(await resolve.count(), 0);
  await shot(page, 'resolved-undo-1440-dark');
  await page.getByRole('button', { name: 'Undo' }).click();
  await page.getByText('status is now in_progress.').waitFor();
  assert.deepEqual(calls[1], { id: '42', field: 'status', value: 'in_progress' }, 'an Undo never says a person chose the status');
  await resolve.waitFor();
  assert.equal(await page.getByRole('button', { name: 'Undo' }).count(), 0, 'an Undo is not itself undoable');

  await resolve.click();
  await page.getByRole('button', { name: 'Undo' }).waitFor();
  await page.locator('body').click({ position: { x: 5, y: 5 } });
  await page.keyboard.press('z');
  await page.getByText('status is now in_progress.').nth(0).waitFor();
  assert.deepEqual(calls.slice(2), [
    { id: '42', field: 'status', value: 'resolved', chosen: true },
    { id: '42', field: 'status', value: 'in_progress' },
  ]);
  await page.keyboard.press('z');
  await settle(page);
  assert.equal(calls.length, 4, 'Z does nothing once there is nothing to undo');
  assert.deepEqual(errors, []);
});

test('a change that could not be saved says so and leaves the page as it was', async (t) => {
  const { page, errors, calls } = await fixture(t, { status: 'open', fail: true });
  await page.keyboard.press('s');
  await page.keyboard.press('4');
  await page.getByText("Couldn't save — check your connection.").waitFor();
  assert.equal(calls.length, 1);
  assert.equal(await page.locator('#thread').getAttribute('data-status'), 'open');
  assert.equal(await page.getByRole('button', { name: 'Undo' }).count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Resolve', exact: true }).count(), 1);
  assert.deepEqual(errors, []);
});

test('Send carries the next status only when asked, by menu or by key', async (t) => {
  const { page, errors, posts } = await fixture(t);
  const box = page.locator('#reply-box');
  const more = page.getByRole('button', { name: 'More ways to send' });
  assert.equal(await page.locator('#send-menu').isHidden(), true);

  // Nothing typed: "Send and resolve" changes nothing and says why.
  await more.click();
  await shot(page, 'send-menu-1440-dark');
  await page.getByRole('menuitem', { name: /Send and resolve/ }).click();
  await page.getByText('Write your reply first.').waitFor();
  assert.equal(posts.length, 0);

  await box.fill('Restarted the spooler.');
  await more.click();
  assert.match(await page.getByRole('menuitem', { name: /Send and resolve/ }).innerText(), /Dana gets a short survey\./);
  assert.match(await page.getByRole('menuitem', { name: /Send and wait/ }).innerText(), /Comes back in 3 days if they stay silent\./);
  await page.getByRole('menuitem', { name: /Send and resolve/ }).click();
  await posted(page, posts, 1);
  assert.equal(posts.length, 1);
  assert.equal(posts[0].get('after_status'), 'resolved');
  assert.equal(posts[0].get('reply'), 'Restarted the spooler.');
  assert.equal(posts[0].get('mode'), 'reply');

  await box.fill('Can you try again?');
  await page.keyboard.press('Control+Shift+Enter');
  await posted(page, posts, 2);
  assert.equal(posts.length, 2);
  assert.equal(posts[1].get('after_status'), 'waiting');

  await box.fill('On it.');
  await page.keyboard.press('Control+Alt+Enter');
  await posted(page, posts, 3);
  assert.equal(posts[2].get('after_status'), 'resolved');

  await box.fill('Plain reply.');
  await page.keyboard.press('Control+Enter');
  await posted(page, posts, 4);
  assert.equal(posts.length, 4);
  assert.equal(posts[3].has('after_status'), false, 'a plain Send sets no status of its own');

  await box.fill('Button reply.');
  await page.getByRole('button', { name: 'Send reply', exact: true }).click();
  await posted(page, posts, 5);
  assert.equal(posts[4].has('after_status'), false);
  assert.deepEqual(errors, []);
});

test('an internal note has one way to be saved', async (t) => {
  const { page, errors, posts } = await fixture(t);
  await page.keyboard.press('n');
  assert.equal(await page.getByRole('button', { name: 'More ways to send' }).count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Save note', exact: true }).count(), 1);
  assert.equal(await page.locator('#composer-hint').innerText(), 'Only your team sees an internal note.');
  assert.equal(await page.locator('#reply-box').getAttribute('placeholder'), 'Write an internal note');
  await page.locator('#reply-box').fill('Spooler was stopped.');
  await page.keyboard.press('Control+Alt+Enter');
  await page.getByText('An internal note is saved as it is. Switch to Reply to send and set a status.').waitFor();
  assert.equal(posts.length, 0, 'a note is never sent with a status');
  await page.keyboard.press('Control+Enter');
  await posted(page, posts, 1);
  assert.equal(posts.length, 1);
  assert.equal(posts[0].get('mode'), 'note');
  assert.equal(posts[0].has('after_status'), false);
  assert.deepEqual(errors, []);
});

test('the sentence beside Send is true for the ticket and follows its status', async (t) => {
  const email = await fixture(t);
  const hint = email.page.locator('#composer-hint');
  assert.equal(await hint.innerText(), 'Dana gets this by email. The ticket moves to In progress.');
  await email.page.keyboard.press('s');
  await email.page.keyboard.press('2');
  await email.page.getByText('status is now in_progress.').waitFor();
  assert.equal(await hint.innerText(), 'Dana gets this by email.');
  await email.page.keyboard.press('n');
  await email.page.locator('body').click({ position: { x: 5, y: 5 } });
  await email.page.keyboard.press('r');
  assert.equal(await hint.innerText(), 'Dana gets this by email.');
  assert.deepEqual(email.errors, []);

  const portal = await fixture(t, { audience: 'portal', status: 'waiting' });
  assert.equal(await portal.page.locator('#composer-hint').innerText(), 'Dana sees this in the portal. No email is sent.');
  await portal.page.getByRole('button', { name: 'More ways to send' }).click();
  assert.match(await portal.page.getByRole('menuitem', { name: /Send and resolve/ }).innerText(), /No survey is sent\./);

  const nobody = await fixture(t, { audience: 'none' });
  assert.equal(await nobody.page.locator('#composer-hint').innerText(),
    'No customer is attached. This reply is saved on the ticket only. The ticket moves to In progress.');
});

test('after "Send and resolve" the page says what happened and Undo puts the status back', async (t) => {
  const flash = '<span id="ticket-flash" data-toast="Reply sent. Resolved. Dana gets a short survey in a minute." data-undo-status="in_progress" hidden></span>';
  const { page, errors, calls } = await fixture(t, { status: 'resolved', extra: flash });
  await page.getByText('Reply sent. Resolved. Dana gets a short survey in a minute.').waitFor();
  await page.getByRole('button', { name: 'Undo' }).click();
  await page.getByText('status is now in_progress.').waitFor();
  assert.deepEqual(calls, [{ id: '42', field: 'status', value: 'in_progress' }]);
  assert.deepEqual(errors, []);
});

test('Saved replies opens the same list as "/" and keeps a draft', async (t) => {
  const { page, errors } = await fixture(t);
  const box = page.locator('#reply-box');
  await box.fill('Hello. ');
  await page.getByRole('button', { name: 'Saved replies', exact: true }).click();
  await page.getByRole('button', { name: /Restart the spooler/ }).click();
  assert.equal(await box.inputValue(), 'Hello. Please restart the print spooler, Dana.');
  await box.fill('/rest');
  await page.getByRole('button', { name: /Restart the spooler/ }).waitFor();
  await page.keyboard.press('Enter');
  assert.equal(await box.inputValue(), 'Please restart the print spooler, Dana.');
  assert.deepEqual(errors, []);
});

test('on the queue S, P and A act on the highlighted row', async (t) => {
  const { page, errors, calls } = await fixture(t, { view: 'queue' });
  await page.keyboard.press('s');
  await page.getByRole('listbox', { name: 'Status' }).waitFor();
  assert.deepEqual(await page.locator('[role="option"][aria-selected="true"]').allInnerTexts().then((texts) => texts.map((text) => text.replace(/\s*\d$/, '').trim())), ['Waiting']);
  await shot(page, 'queue-status-menu-1440-dark');
  await page.keyboard.press('1');
  await page.getByText('status is now open.').waitFor();
  assert.equal(await page.locator('.trow[data-ticket-id="101"]').getAttribute('data-status'), 'open');
  assert.equal(page.url(), 'http://safeharbor.test/', 'choosing from a row\'s menu does not open the ticket');

  await page.keyboard.press('j');
  await page.keyboard.press('a');
  await page.getByRole('combobox', { name: 'Assign to' }).waitFor();
  assert.deepEqual(await page.locator('[role="option"][aria-selected="true"] .menu-item-name').allInnerTexts(), ['Jon <Sato>']);
  await page.keyboard.press('1');
  await page.getByText(`assignee is now ${ME}.`).waitFor();
  await page.keyboard.press('p');
  await page.keyboard.press('4');
  await page.getByText('priority is now urgent.').waitFor();
  assert.deepEqual(calls, [
    { id: '101', field: 'status', value: 'open', chosen: true },
    { id: '102', field: 'assignee', value: String(ME) },
    { id: '102', field: 'priority', value: 'urgent' },
  ]);
  assert.equal(await page.locator('.trow[data-ticket-id="102"]').getAttribute('data-owner'), String(ME));
  assert.deepEqual(errors, []);
});

for (const theme of ['dark', 'light']) {
  test(`at phone width every control is a 44px target and nothing overflows (${theme})`, async (t) => {
    const { page, errors } = await fixture(t, { width: 390, theme });
    const tall = async (locator, name) => {
      for (const box of await locator.evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().height))) {
        assert.ok(box >= 44, `${name} is ${box}px tall`);
      }
    };
    await tall(page.locator('.menu-btn'), 'a menu button');
    await tall(page.getByRole('button', { name: 'Resolve', exact: true }), 'Resolve');
    await tall(page.locator('.send-split .btn-primary'), 'Send');
    await tall(page.locator('.att-pick'), 'Attach a file');
    await tall(page.getByRole('button', { name: 'Saved replies', exact: true }), 'Saved replies');
    assert.equal(await fits(page), true);
    await shot(page, `ticket-390-${theme}`);

    await menuButton(page, 'assignee').click();
    await page.getByRole('combobox', { name: 'Assign to' }).waitFor();
    await tall(options(page), 'a menu choice');
    await tall(page.locator('.menu-filter'), 'the teammate box');
    const pop = await page.locator('.menu-pop[data-menu="assignee"]').boundingBox();
    assert.ok(pop.x >= 0 && pop.x + pop.width <= 390 && pop.y >= 0 && pop.y + pop.height <= 900, 'the menu stays inside the window');
    assert.equal(await fits(page), true);
    await shot(page, `owner-menu-390-${theme}`);
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'More ways to send' }).click();
    await tall(page.getByRole('menuitem'), 'a send choice');
    const menu = await page.locator('#send-menu').boundingBox();
    assert.ok(menu.x >= 0 && menu.x + menu.width <= 390, 'the send choices stay inside the window');
    assert.equal(await fits(page), true);
    await shot(page, `send-menu-390-${theme}`);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#send-menu').isHidden(), true);

    await page.getByRole('button', { name: 'Resolve', exact: true }).click();
    await tall(page.getByRole('button', { name: 'Undo' }), 'Undo');
    assert.equal(await fits(page), true);
    await shot(page, `resolved-undo-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}

test('light theme at desktop width (for the eye; also checks focus is visible on a menu row)', async (t) => {
  const { page, errors } = await fixture(t, { theme: 'light' });
  await page.keyboard.press('s');
  await page.keyboard.press('ArrowDown');
  const ring = await page.locator('.menu-item.sel').evaluate((node) => getComputedStyle(node).boxShadow);
  assert.notEqual(ring, 'none', 'the highlighted choice carries a visible focus mark when the keyboard opened the menu');
  await shot(page, 'status-menu-1440-light');
  await page.keyboard.press('Escape');
  await page.keyboard.press('Tab');
  await shot(page, 'ticket-1440-light');
  assert.deepEqual(errors, []);
});
