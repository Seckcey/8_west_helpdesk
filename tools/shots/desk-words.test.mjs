import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Marks that used to be typed characters are drawn icons, and a plan tag is not the attention colour,
// on the staff previews (tools/shots/preview) with the real stylesheet and page script.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PREVIEW = path.join(ROOT, 'tools/shots/preview');
const ORIGIN = 'http://safeharbor.test';

async function open(t, name, { width = 1440, theme = 'dark' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
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
      .replace('</body>', '<div id="palette-root"></div><script src="/assets/js/app.js"></script></body>');
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(`${ORIGIN}/p/${name}.html?theme=${theme}`);
  await page.waitForFunction(() => document.readyState === 'complete'
    && [...document.querySelectorAll('.trow, .crow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return page;
}

test('phone: Menu and Close menu are a drawn icon and a word, with no typed ☰ or ×', async (t) => {
  const page = await open(t, 'queue', { width: 390 });
  const menu = page.locator('#mobile-nav-toggle');
  assert.equal((await menu.innerText()).trim(), 'Menu');
  assert.equal(await menu.locator('svg[aria-hidden="true"]').isVisible(), true);
  const box = await menu.boundingBox();
  assert.ok(box.height >= 43.5);
  await menu.click();
  const close = page.locator('.mobile-nav-close');
  await close.waitFor();
  assert.equal((await close.innerText()).trim(), 'Close menu');
  assert.equal(await close.locator('svg[aria-hidden="true"]').isVisible(), true);
  const typed = await page.evaluate(() => /[☰×←↗]/.test(document.querySelector('.topbar').innerText + document.querySelector('.sidebar').innerText));
  assert.equal(typed, false, 'no typed marks in the top bar or the menu');
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: a Premium plan tag is the same neutral tag as Standard, not the attention colour`, async (t) => {
    const page = await open(t, 'clients', { theme });
    const tags = await page.evaluate(() => {
      const paint = (el) => { const s = getComputedStyle(el); return s.backgroundColor + ' ' + s.color; };
      const premium = document.querySelector('.tier.tier-premium');
      const standard = [...document.querySelectorAll('.tier')].find((el) => !el.classList.contains('tier-premium'));
      return { premium: premium && paint(premium), standard: standard && paint(standard), text: premium && premium.textContent.trim() };
    });
    assert.ok(tags.premium && tags.standard, 'the preview has both plans');
    assert.equal(tags.premium, tags.standard);
  });
}
