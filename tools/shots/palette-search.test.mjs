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
