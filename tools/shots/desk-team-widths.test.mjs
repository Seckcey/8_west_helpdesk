import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Team, Saved replies and page widths, on the staff previews (tools/shots/preview) with the real
// stylesheet and page script: Team rows line up in columns with a quiet action, every page head starts
// at the same left edge, and Saved replies does not pretend to be the Queue.
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
      .replace(/<div id="toasts" class="toasts">.*?<\/div><\/div>/s, '<div id="toasts" class="toasts"></div>')
      .replace('</body>', '<div id="palette-root"></div><script src="/assets/js/app.js"></script></body>');
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(`${ORIGIN}/p/${name}.html?theme=${theme}`);
  await page.waitForFunction(() => document.readyState === 'complete'
    && [...document.querySelectorAll('.trow, .crow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return page;
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_TEAM_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_TEAM_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_TEAM_SHOTS, `${name}.png`), fullPage: true });
}

const boxes = (page, selector) => page.locator(selector).evaluateAll((els) => els.map((el) => {
  const r = el.getBoundingClientRect();
  return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height };
}));
const same = (values, what) => {
  const spread = Math.max(...values) - Math.min(...values);
  assert.ok(spread < 1, `${what} line up (spread ${spread.toFixed(1)}px over ${values.length})`);
};

for (const theme of ['dark', 'light']) {
  test(`${theme} desktop Team: role, access and the action sit in columns; the action is a quiet 36px button`, async (t) => {
    const page = await open(t, 'team', { theme });
    same((await boxes(page, '.team-list .urow-role .role-badge')).map((b) => b.left), 'role tags');
    same((await boxes(page, '.team-list .urow-status')).map((b) => b.left), 'access words');
    const buttons = await boxes(page, '.team-list .urow-action button');
    assert.equal(buttons.length, 3);
    same(buttons.map((b) => b.right), 'actions');
    const row = (await boxes(page, '.team-list .urow'))[1];
    for (const b of buttons) {
      assert.ok(b.height >= 35.5 && b.height < 44, `the action is ${b.height}px tall`);
      assert.ok(b.width < row.width / 4, 'the action is a button, not a bar');
    }
    const quiet = await page.locator('.team-list .urow-action button').first().evaluate((el) => {
      const s = getComputedStyle(el), card = getComputedStyle(el.closest('.card'));
      return { fill: s.backgroundColor, card: card.backgroundColor, border: parseFloat(s.borderTopWidth) };
    });
    assert.equal(quiet.fill, quiet.card, 'no red fill: the button is the surface colour');
    assert.equal(quiet.border, 1, 'with a border, so it looks like a control at rest');
    assert.equal(await page.locator('.team-list').getByText('·').count(), 0, 'no chain of facts joined by dots');
    await shot(page, `team-desktop-${theme}`);
  });
}

test('phone Team: the role and access share a line, and the action is a 44px button at its own width', async (t) => {
  const page = await open(t, 'team', { width: 390 });
  const rows = await page.locator('.team-list .urow').count();
  assert.ok(rows >= 3, 'the preview lists the team');
  for (let i = 0; i < rows; i += 1) {
    const row = page.locator('.team-list .urow').nth(i);
    const role = await row.locator('.role-badge').boundingBox();
    const status = await row.locator('.urow-status').boundingBox();
    assert.ok(Math.abs((role.y + role.height / 2) - (status.y + status.height / 2)) < 4, 'role and access are on one line');
    assert.ok(status.x > role.x + role.width, 'access follows the role');
    if (await row.locator('.urow-action button').count()) {
      const button = await row.locator('.urow-action button').boundingBox();
      const card = await row.boundingBox();
      assert.ok(button.height >= 43.5, `the action is ${button.height}px tall`);
      assert.ok(button.width < card.width * 0.6, 'the action is not a full-width bar');
    }
  }
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  await shot(page, 'team-phone-dark');
});

for (const width of [1440, 1920]) {
  test(`at ${width}px every page head starts at the same left edge, wide or narrow`, async (t) => {
    const lefts = {};
    for (const name of ['queue', 'time', 'clients', 'team', 'saved-replies', 'service-goals']) {
      const page = await open(t, name, { width });
      lefts[name] = (await boxes(page, '.page-title'))[0].left;
      await page.close();
    }
    same(Object.values(lefts), `page titles ${JSON.stringify(lefts)}`);
  });
}

test('Saved replies is not marked as the Queue: no rail item is current and there is no back link to Queue', async (t) => {
  const page = await open(t, 'saved-replies');
  assert.equal(await page.locator('.sidebar .nav-on, .sidebar [aria-current]').count(), 0);
  assert.equal(await page.locator('.page-saved-replies .backlink').count(), 0);
  assert.equal(await page.getByRole('heading', { level: 1, name: 'Saved replies' }).isVisible(), true);
});
