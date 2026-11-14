import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// A tablet beside the rail: between 701 and 960px the 248px rail leaves the page 390 to 650px. On the
// staff previews (tools/shots/preview) with the real stylesheet and page script: queue rows are cards
// with a subject that has room, Team and Time keep every name and button inside their card, and the
// top bar never wraps or pushes the page sideways, even with a long name and a running timer. Once the
// list is wide enough the queue is columns again and still leaves the subject room. A client's own
// ticket list, in the client page's narrower column, is checked in client-page.test.mjs.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PREVIEW = path.join(ROOT, 'tools/shots/preview');
const ORIGIN = 'http://safeharbor.test';
const TIMER_KEY = 'safeharbor.timer.v2.1:102';
const LONG_NAME = 'Alexandria Featherstonehaugh-Whitley';   // made up; the shared cluster cuts it at 160px
const TABLET = [701, 768, 820, 960];

async function open(t, name, { width = 768, theme = 'dark', longName = false, timer = false } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const context = await browser.newContext({ viewport: { width, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  if (timer) {
    await page.addInitScript(([key, at]) => localStorage.setItem(key, JSON.stringify({ ticketId: 10418, tenantId: '1', userId: '102',
      entryKey: 'timer:fixture', state: 'active', startedAt: at })), [TIMER_KEY, Date.now() - 4561000]);
  }
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
    let body = (await readFile(path.join(PREVIEW, match[1]), 'utf8'))
      .replaceAll('../../../app/public/assets/', '/assets/')
      .replace(/<body data-active="([a-z-]+)"/, '<body data-active="$1" data-tenant-id="1" data-user-id="102" data-csrf="fixture"')
      .replace(/<div id="toasts" class="toasts">.*?<\/div><\/div>/s, '<div id="toasts" class="toasts"></div>')
      .replace('</body>', '<div id="palette-root"></div><script src="/assets/js/app.js"></script></body>');
    if (longName) body = body.replace(/(class="w365-account-name">)[^<]*</, `$1${LONG_NAME}<`);
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(`${ORIGIN}/p/${name}.html?theme=${theme}`);
  await page.waitForFunction(() => document.readyState === 'complete'
    && [...document.querySelectorAll('.trow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  t.after(() => assert.deepEqual(errors, [], 'no script errors'));
  return page;
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_TABLET_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_TABLET_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_TABLET_SHOTS, `${name}.png`) });
}

const noSidewaysScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

/** Everything drawn inside each box matching `boxes` that sticks out past that box, as "text: px". */
function outside(page, boxes, parts) {
  return page.evaluate(([boxes, parts]) => [...document.querySelectorAll(boxes)].flatMap((box) => {
    const edge = box.getBoundingClientRect();
    return [...box.querySelectorAll(parts)].filter((el) => {
      const r = el.getBoundingClientRect(), s = getComputedStyle(el);
      return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0
        && (r.right > edge.right + 0.5 || r.left < edge.left - 0.5);
    }).map((el) => `${(el.innerText || el.className).trim().slice(0, 30)}: ${Math.round(el.getBoundingClientRect().right - edge.right)}px`);
  }), [boxes, parts]);
}

// ---- the queue ----
for (const width of TABLET) {
  for (const select of [false, true]) {
    test(`queue at ${width}px${select ? ', selecting' : ''}: cards whose subject has room and nothing cut off`, async (t) => {
      const page = await open(t, 'queue', { width });
      if (select) await page.locator('#bulk-toggle').click();
      assert.equal(await page.locator('.trow-head').first().isVisible(), false, 'cards have no column header');
      if (select) {
        assert.equal(await page.locator('.pick-all-label').isVisible(), true, 'the select-all box says what it does');
        assert.equal(await page.locator('.bulk-pick').first().isVisible(), true);
      }
      const rows = await page.locator('.trow:not(.trow-head)').evaluateAll((list) => list.map((row) => {
        const box = row.getBoundingClientRect();
        const width = (selector) => row.querySelector(selector).getBoundingClientRect().width;
        return { row: box.width, subject: width('.trow-subject'), client: width('.trow-client-phone'), height: box.height };
      }));
      assert.ok(rows.length >= 6);
      for (const row of rows) {
        assert.ok(row.subject >= row.row * 0.35 && row.subject >= 120, `the subject is ${Math.round(row.subject)}px of a ${Math.round(row.row)}px row`);
        assert.ok(row.client >= 80, `the client is ${Math.round(row.client)}px`);
      }
      assert.deepEqual(await outside(page, '.trow:not(.trow-head)', '.trow-subject, .trow-client-phone, .trow-status .chip, .trow-sla .sla, .trow-sla > *, .trow-tech > *'), []);
      assert.deepEqual(await outside(page, '.card.list', '.trow, .trow-pick, .pick'), []);
      assert.equal(await noSidewaysScroll(page), true);
      await shot(page, `queue-${width}${select ? '-select' : ''}`);
    });
  }
}

// The rows follow the list's own width: cards up to 680px (a window up to about 995px on the
// queue), narrower columns up to 790px, then the full ones.
for (const width of [1000, 1024, 1104, 1105, 1180]) {
  test(`queue at ${width}px: columns again, with room for the subject and Updated inside the list`, async (t) => {
    const page = await open(t, 'queue', { width });
    assert.equal(await page.locator('.trow-head').first().isVisible(), true, 'columns have a header');
    const subjects = await page.locator('.trow:not(.trow-head) .trow-subject').evaluateAll((list) => list.map((el) => el.getBoundingClientRect().width));
    for (const subject of subjects) assert.ok(subject >= 140, `the subject is ${Math.round(subject)}px`);
    assert.deepEqual(await outside(page, '.card.list', '.trow-age, .trow-sla > *, .trow-tech > *, .trow-status > *'), []);
    assert.equal(await noSidewaysScroll(page), true);
  });
}

// ---- the top bar ----
for (const width of TABLET) {
  for (const timer of [false, true]) {
    test(`top bar at ${width}px with a long name${timer ? ' and a running timer' : ''}: one line, nothing past the edge`, async (t) => {
      const page = await open(t, 'queue', { width, longName: true, timer });
      const bar = await page.locator('.topbar').evaluate((el) => {
        const edge = el.getBoundingClientRect();
        const box = (selector) => { const r = el.querySelector(selector).getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, height: r.height, width: r.width }; };
        const lines = (selector) => { const node = el.querySelector(selector); return node && getComputedStyle(node).display !== 'none' ? node.getClientRects().length : 0; };
        return { edge: { left: edge.left, right: edge.right, top: edge.top, bottom: edge.bottom }, scroll: el.scrollWidth <= el.clientWidth,
          search: box('#palette-trigger'), timer: box('#timer-widget'), cluster: box('.w365-cluster'),
          searchLines: lines('.search-label'), timerText: el.querySelector('#timer-widget').innerText.trim(),
          timerLabel: el.querySelector('#timer-widget').getAttribute('aria-label'), searchName: el.querySelector('#palette-trigger').getAttribute('aria-label') };
      });
      assert.equal(bar.scroll, true, 'the bar holds everything');
      for (const [part, r] of Object.entries({ search: bar.search, timer: bar.timer, cluster: bar.cluster })) {
        assert.ok(r.left >= bar.edge.left - 0.5 && r.right <= bar.edge.right + 0.5, `${part} stays inside the bar`);
        assert.ok(r.top >= bar.edge.top - 0.5 && r.bottom <= bar.edge.bottom + 0.5, `${part} stays on the bar's one line`);
      }
      assert.ok(bar.search.right <= bar.timer.left && bar.timer.right <= bar.cluster.left, 'search, timer and the suite cluster do not overlap');
      assert.ok(bar.timer.height <= 36.5, `the timer is one line (${bar.timer.height}px)`);
      assert.ok(bar.searchLines <= 1, 'the search label, when shown, is one line');
      assert.ok(bar.search.width >= 36, 'the search keeps a usable target');
      assert.equal(bar.searchName, 'Search or run a command');
      if (timer) {
        assert.match(bar.timerText, /^\d+:\d\d:\d\d$/, 'a running timer shows its time');
        assert.match(bar.timerLabel, /^Time: \d+:\d\d:\d\d, running$/);
      } else {
        assert.equal(bar.timerText, '', 'the idle timer is its clock alone');
        assert.equal(bar.timerLabel, 'Time: no timer running', 'and keeps its name');
        assert.ok(bar.timer.width >= 35.5, `a ${bar.timer.width}px target`);
      }
      assert.equal(await noSidewaysScroll(page), true);
      await shot(page, `topbar-${width}${timer ? '-timer' : ''}`);
    });
  }
}

test('top bar at 1440px: the idle timer keeps its words and the search its label', async (t) => {
  const page = await open(t, 'queue', { width: 1440 });
  assert.equal((await page.locator('#timer-widget').innerText()).trim(), 'No timer running');
  assert.equal(await page.locator('.search-label').isVisible(), true);
  assert.equal(await page.locator('.search-keys').isVisible(), true);
});

// ---- Team ----
for (const width of [701, 760, 761, 768, 820, 960, 1024, 1100]) {
  test(`Team at ${width}px: names have room and every button stays in its card`, async (t) => {
    const page = await open(t, 'team', { width });
    const rows = await page.locator('.team-list .urow').evaluateAll((list) => list.map((row) => {
      const box = (selector) => row.querySelector(selector)?.getBoundingClientRect() || null;
      return { main: box('.urow-main'), role: box('.urow-role'), action: box('.urow-action') };
    }));
    assert.ok(rows.length >= 3);
    for (const row of rows) {
      assert.ok(row.main.width >= 160, `the name and email get ${Math.round(row.main.width)}px`);
      assert.ok(row.role.top >= row.main.bottom - 0.5, 'the role and access sit under the name');
      if (!row.action || !row.action.width) continue;
      // Up to 760px the action has a line of its own, under the role, so the name keeps the width.
      if (width <= 760) assert.ok(row.action.top >= row.role.bottom - 0.5, 'the action sits under the role');
      else assert.ok(row.action.bottom <= row.role.top + 0.5, 'the action shares the first line');
    }
    assert.deepEqual(await outside(page, '.team-list', '.urow-main, .urow-role, .urow-status, .urow-action, .urow-action button'), []);
    assert.equal(await noSidewaysScroll(page), true);
    await shot(page, `team-${width}`);
  });
}

// ---- Time ----
for (const width of TABLET) {
  test(`Time at ${width}px: titles have room, the duration is on the title line, buttons stay in the card`, async (t) => {
    const page = await open(t, 'time', { width });
    const rows = await page.locator('.entry-row').evaluateAll((list) => list.map((row) => {
      const box = (selector) => row.querySelector(selector)?.getBoundingClientRect() || null;
      return { row: row.getBoundingClientRect().width, main: box('.entry-main'), min: box('.entry-min') };
    }));
    assert.ok(rows.length >= 5);
    for (const row of rows) {
      assert.ok(row.main.width >= row.row * 0.6, `the title gets ${Math.round(row.main.width)}px of ${Math.round(row.row)}px`);
      assert.ok(Math.abs(row.min.top - row.main.top) <= 4, 'the duration sits on the title line');
    }
    assert.deepEqual(await outside(page, '.entry-row', '.entry-main, .entry-min, .entry-tags > *, .review-actions > *, .review-actions button'), []);
    assert.equal(await noSidewaysScroll(page), true);
    await shot(page, `time-${width}`);
  });
}
