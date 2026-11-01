import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// ⌘K search on the real app.js and stylesheet; deep search and the pages it opens are stubs.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(root, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(root, 'app/public/assets/css/app.css'), 'utf8');
const DATA = {
  tickets: [
    { id: 42, subject: 'Printer is down', priority: 'normal', client: 'Acme Dental' },
    { id: 7, subject: 'Sign-in loop', priority: 'high', client: 'Harbor Logistics' },
  ],
  clients: [
    { id: 3, name: 'Acme Dental', domain: 'acme.example' },
    { id: 5, name: 'Harbor Logistics', domain: 'harbor.example' },
  ],
};
const PAGE = `<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><title>Palette search</title><style>${CSS}</style></head>
<body data-active="queue" data-tenant-id="1" data-user-id="1" data-csrf="fixture"><main class="content"><div class="page"><h1 class="page-title">Queue</h1></div></main><div id="palette-root"></div><div id="toasts" class="toasts"></div><script id="palette-data" type="application/json">${JSON.stringify(DATA)}</script><script>${JS}</script></body></html>`;

const browser = await chromium.launch({ headless: true });

// Every check starts on an open palette and ends with no script errors on the page.
async function palette(t, deep = []) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, []);
  });
  await page.route('http://safeharbor.test/**', (route) => {
    const { pathname } = new URL(route.request().url());
    if (pathname === '/start') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: PAGE });
    if (pathname === '/api/search.php') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, results: deep }) });
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>Opened</title>' });
  });
  await page.goto('http://safeharbor.test/start');
  await page.keyboard.press('Control+k');
  return page;
}

const search = (page, query) => page.locator('.palette-input').pressSequentially(query);
const rows = (page) => page.locator('.palette-item-main').allTextContents();
const selected = (page) => page.locator('.palette-item.sel .palette-item-main').allTextContents();

try {
  for (const [query, first] of [
    ['Sign out', 'Sign out'],
    ['Sign', 'Sign out'],
    ['sign OUT', 'Sign out'],
    ['Go to Queue', 'Go to Queue'],
    ['Go to Cl', 'Go to Clients'],
    ['New', 'New ticket'],
    ['Saved', 'Saved replies'],
    ['#42', '#42 Printer is down'],
    ['#42 Printer', '#42 Printer is down'],
    ['#7 Sign-in loop', '#7 Sign-in loop'],
    ['Acme', 'Acme Dental'],
    ['Acme Dental', 'Acme Dental'],
    ['harbor', 'Harbor Logistics'],
  ]) {
    await test(`typing "${query}" puts ${first} first and selects it`, async (t) => {
      const page = await palette(t);
      await search(page, query);
      assert.equal((await rows(page))[0], first);
      assert.deepEqual(await selected(page), [first]);
    });
  }

  await test('"go" lists the Go to actions ahead of Sign out', async (t) => {
    const page = await palette(t);
    await search(page, 'go');
    const list = await rows(page);
    assert.deepEqual(list.slice(0, 5), ['Go to Queue', 'Go to Time', 'Go to Clients', 'Go to Service goals', 'Go to Team']);
    assert.ok(list.indexOf('Sign out') > 4, list.join(', '));
  });

  for (const [query, opens] of [
    ['go', '/'],
    ['Go to Time', '/time.php'],
    ['New', '/ticket_new.php'],
    ['Saved', '/snippets.php'],
    ['Sign out', '/logout.php'],
    ['#42 Pr', '/ticket.php?id=42'],
    ['Harb', '/client.php?id=5'],
  ]) {
    await test(`Enter after "${query}" opens ${opens}`, async (t) => {
      const page = await palette(t);
      await search(page, query);
      const [frame] = await Promise.all([
        page.waitForEvent('framenavigated', { timeout: 5000 }),
        page.keyboard.press('Enter'),
      ]);
      const url = new URL(frame.url());
      assert.equal(url.pathname + url.search, opens);
    });
  }

  for (const [query, first] of [
    ['out', 'Sign out'],
    ['snippets', 'Saved replies'],
    ['gq', 'Go to Queue'],
    ['Printer', '#42 Printer is down'],
    ['acme.example', 'Acme Dental'],
  ]) {
    await test(`"${query}" still finds ${first} without being its start`, async (t) => {
      const page = await palette(t);
      await search(page, query);
      assert.equal((await rows(page))[0], first);
    });
  }

  for (const query of ['zzzz', 'Sign outz']) {
    await test(`"${query}" matches nothing and says so`, async (t) => {
      const page = await palette(t);
      await search(page, query);
      assert.deepEqual(await rows(page), []);
      assert.equal(await page.locator('.palette-empty').isVisible(), true);
    });
  }

  // A ticket's number. The stub answers by query, so a row only shows if that exact text reached it.
  const answering = (page, answer) => page.route('**/api/search.php*', async (route) => {
    const q = new URL(route.request().url()).searchParams.get('q');
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, results: await answer(q) }) });
  });
  const oldTicket = (id) => ({ id, subject: 'Old ticket', status: 'resolved', client: 'Acme Dental' });
  const row = (page, id) => page.locator('.palette-item-main', { hasText: new RegExp(`^#${id} `) });
  const enterOpens = async (page) => {
    const [frame] = await Promise.all([
      page.waitForEvent('framenavigated', { timeout: 5000 }),
      page.keyboard.press('Enter'),
    ]);
    const url = new URL(frame.url());
    return url.pathname + url.search;
  };

  for (const [query, id] of [
    ['#9', 9],
    ['9', 9],
    ['#88', 88],
    ['88', 88],
    ['#7002', 7002],
    ['7002', 7002],
    ['#7002 Old', 7002],
    ['7002 Old', 7002],
  ]) {
    await test(`"${query}" reaches deep search whole, and Enter opens ticket ${id}`, async (t) => {
      const page = await palette(t);
      await answering(page, (q) => (q === query ? [oldTicket(id)] : []));
      await search(page, query);
      await row(page, id).waitFor();
      assert.deepEqual(await selected(page), [`#${id} Old ticket`]);
      assert.equal(await enterOpens(page), `/ticket.php?id=${id}`);
    });
  }

  for (const query of ['ab', '#a', '#', 'a7', '7a', '0', '#0', '07']) {
    await test(`"${query}" is short and not a ticket's number, so deep search is not asked`, async (t) => {
      const asked = [];
      const page = await palette(t);
      await answering(page, (q) => { asked.push(q); return []; });
      await page.locator('.palette-input').fill(query);
      await page.waitForTimeout(600);
      assert.deepEqual(asked, []);
    });
  }

  for (const [query, id, subject] of [
    ['#7', 7, 'Sign-in loop'],
    ['7', 7, 'Sign-in loop'],
    ['#42', 42, 'Printer is down'],
    ['42', 42, 'Printer is down'],
  ]) {
    await test(`"${query}" shows a local ticket once, when deep search finds it too`, async (t) => {
      const page = await palette(t);
      await answering(page, () => [{ id, subject, status: 'open', client: 'Acme Dental' }, oldTicket(900)]);
      await search(page, query);
      await row(page, 900).waitFor();
      assert.deepEqual(await rows(page), [`#${id} ${subject}`, '#900 Old ticket']);
    });
  }

  await test('the exact number stays below a loose local match, which Enter opens', async (t) => {
    const page = await palette(t);
    await answering(page, (q) => (q === '#4' ? [oldTicket(4)] : []));
    await search(page, '#4');
    await row(page, 4).waitFor();
    assert.deepEqual(await rows(page), ['#42 Printer is down', '#4 Old ticket']);
    assert.equal(await enterOpens(page), '/ticket.php?id=42');
  });

  await test('a row found for "#700" goes as soon as the number changes, so Enter cannot open it', async (t) => {
    let release;
    const held = new Promise((resolve) => { release = resolve; });
    const page = await palette(t);
    await answering(page, async (q) => {
      if (q === '#700') return [oldTicket(700)];
      await held;
      return q === '#7002' ? [oldTicket(7002)] : [];
    });
    await search(page, '#700');
    await row(page, 700).waitFor();
    await page.keyboard.press('2');
    assert.deepEqual(await rows(page), []);
    await page.keyboard.press('Enter');
    release();
    await row(page, 7002).waitFor();
    assert.equal(await enterOpens(page), '/ticket.php?id=7002');
  });

  await test('a late answer for "#700" does not show once the box says "#7002"', async (t) => {
    let release;
    const held = new Promise((resolve) => { release = resolve; });
    const page = await palette(t);
    await answering(page, async (q) => {
      if (q === '#700') { await held; return [oldTicket(700)]; }
      return q === '#7002' ? [oldTicket(7002)] : [];
    });
    const asked = page.waitForRequest(/q=%23700$/);
    await search(page, '#700');
    await asked;
    await page.keyboard.press('2');
    await row(page, 7002).waitFor();
    const late = page.waitForResponse(/q=%23700$/);
    release();
    await late;
    await page.waitForTimeout(300);
    assert.deepEqual(await rows(page), ['#7002 Old ticket']);
    assert.equal(await enterOpens(page), '/ticket.php?id=7002');
  });

  await test('deep search results stay below local matches and skip local tickets', async (t) => {
    const page = await palette(t, [
      { id: 7, subject: 'Sign-in loop', status: 'open', client: 'Harbor Logistics' },
      { id: 900, subject: 'Signal booster install', status: 'resolved', client: 'Acme Dental' },
    ]);
    await search(page, 'Sign');
    await page.locator('.palette-item-main', { hasText: '#900' }).waitFor();
    assert.deepEqual(await rows(page), ['Sign out', '#7 Sign-in loop', 'Saved replies', '#900 Signal booster install']);
    assert.deepEqual(await selected(page), ['Sign out']);
  });
} finally {
  await browser.close();
}
