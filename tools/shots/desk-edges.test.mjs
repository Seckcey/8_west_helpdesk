import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// One left edge: on the staff previews (tools/shots/preview) with the real stylesheet and page script,
// the quiet lines around a list (the queue's count and "Not in this view", Time's note under the
// approved list) start on the list's own left edge, and the pager ends on its right edge. The two
// Saved replies previews run the page script without an error, so their editor opens as on the page.
// A client's rail and New client are checked on the real pages in client-page.test.mjs.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PREVIEW = path.join(ROOT, 'tools/shots/preview');
const ORIGIN = 'http://safeharbor.test';

async function open(t, name, { width = 1440, theme = 'dark' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 1000 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route(`${ORIGIN}/**`, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
    if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
      const file = path.join(ROOT, 'app/public', url.pathname);
      const types = { '.css': 'text/css', '.js': 'text/javascript' };
      return route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) }).catch(() => route.fulfill({ status: 404, body: '' }));
    }
    const match = url.pathname.match(/^\/p\/([a-z-]+\.(html|js|css))$/);
    if (!match) return route.fulfill({ status: 404, body: '' });
    if (match[2] !== 'html') {
      return route.fulfill({ contentType: match[2] === 'js' ? 'text/javascript' : 'text/css', body: await readFile(path.join(PREVIEW, match[1])) });
    }
    const body = (await readFile(path.join(PREVIEW, match[1]), 'utf8'))
      .replaceAll('../../../app/public/assets/', '/assets/')
      .replace(/<body data-active="([a-z-]+)"/, '<body data-active="$1" data-tenant-id="1" data-user-id="102" data-csrf="fixture"')
      .replace(/<div id="toasts" class="toasts">.*?<\/div><\/div>/s, '<div id="toasts" class="toasts"></div>')
      .replace('</body>', '<div id="palette-root"></div><script src="/assets/js/app.js"></script></body>');
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(`${ORIGIN}/p/${name}.html?theme=${theme}`);
  await page.waitForFunction(() => document.readyState === 'complete'
    && [...document.querySelectorAll('.trow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return { page, errors };
}

/** Where the first word inside an element starts, in px from the window's left. */
const wordsLeft = (page, selector) => page.locator(selector).first().evaluate((el) => {
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, { acceptNode: (node) => (node.textContent.trim() ? 1 : 3) });
  const range = document.createRange();
  range.selectNodeContents(walker.nextNode());
  return range.getClientRects()[0].left;
});
const edges = (page, selector) => page.locator(selector).first().evaluate((el) => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right }; });

for (const theme of ['light', 'dark']) {
  test(`queue (${theme}): the count, the pager and "Not in this view" keep the list's edges`, async (t) => {
    const { page, errors } = await open(t, 'queue', { theme });
    const list = await edges(page, '.card.list');
    for (const selector of ['.pager-count', '.queue-elsewhere']) {
      const left = await wordsLeft(page, selector);
      assert.ok(Math.abs(left - list.left) <= 0.5, `${selector} starts ${(left - list.left).toFixed(1)}px from the list's left edge`);
    }
    const last = await edges(page, '.pager > :last-child');
    assert.ok(Math.abs(last.right - list.right) <= 0.5, `the pager ends ${(last.right - list.right).toFixed(1)}px from the list's right edge`);
    assert.deepEqual(errors, []);
  });
}

test('Time: the note under the approved list starts on the list\'s left edge', async (t) => {
  const { page, errors } = await open(t, 'time');
  const note = await wordsLeft(page, '.page-note');
  const card = await edges(page, '.page .card');
  assert.ok(Math.abs(note - card.left) <= 0.5, `the note starts ${(note - card.left).toFixed(1)}px from the card's edge`);
  assert.deepEqual(errors, []);
});

for (const name of ['saved-replies', 'saved-reply-edit']) {
  test(`${name} preview: the page script runs, and New saved reply opens the editor`, async (t) => {
    const { page, errors } = await open(t, name);
    if (await page.locator('#saved-reply-editor').isVisible()) {   // the editing preview: Cancel closes it first
      await page.locator('[data-reply-cancel]').click();
      assert.equal(await page.locator('#saved-reply-editor').isVisible(), false);
    }
    await page.locator('[data-reply-new]').first().click();
    assert.equal(await page.locator('#saved-reply-editor').isVisible(), true);
    assert.equal(await page.locator('#saved-reply-heading').textContent(), 'New saved reply');
    assert.deepEqual(errors, [], 'no script errors');
  });
}
