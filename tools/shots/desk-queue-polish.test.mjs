import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The queue and the top bar, on the staff preview (tools/shots/preview/queue.html) with the real
// stylesheet and page script: on a phone the filters fold behind a Filters button and the views row
// fades at its edge; the timer is a button with a clock; priority bars say what they mean in colour;
// ages read "3 h", like the first-response tag beside them.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PREVIEW = path.join(ROOT, 'tools/shots/preview');
const ORIGIN = 'http://safeharbor.test';
const TIMER_KEY = 'safeharbor.timer.v2.1:102';

async function open(t, name, { width = 1440, theme = 'dark', js = true, init = null } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const context = await browser.newContext({ viewport: { width, height: 900 }, javaScriptEnabled: js });
  const page = await context.newPage();
  if (init) await page.addInitScript(init.fn, init.arg);
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
  if (!js) { await page.waitForLoadState('load'); await page.waitForTimeout(400); return page; }   // rows fade in for 160ms
  await page.waitForFunction(() => document.readyState === 'complete'
    && [...document.querySelectorAll('.trow, .crow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return page;
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_QUEUE_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_QUEUE_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_QUEUE_SHOTS, `${name}.png`), fullPage: true });
}

const visible = (page, selector) => page.locator(selector).first().isVisible();
const top = (page, selector) => page.locator(selector).first().evaluate((el) => el.getBoundingClientRect().top);

// ---- the phone's Filters button ----
test('phone: the filters fold behind a Filters button on the views row', async (t) => {
  const page = await open(t, 'queue', { width: 390 });
  const toggle = page.locator('#filters-toggle');
  assert.equal(await toggle.isVisible(), true);
  assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
  assert.equal(await visible(page, '#queue-filters'), false, 'the filters start folded');
  assert.equal(await visible(page, '#bulk-toggle'), true, 'Select stays: bulk delete works on a phone');
  const select = await page.locator('#bulk-toggle').boundingBox(), row = await page.locator('.queue-toolbar .filters').boundingBox();
  assert.ok(select.y >= row.y + row.height, 'Select sits below the views row');
  assert.ok(row.width >= 390 * 0.6, `the views row keeps most of the width (${Math.round(row.width)}px)`);
  const views = await page.locator('.queue-toolbar .filters').boundingBox();
  const button = await toggle.boundingBox();
  assert.ok(Math.abs((views.y + views.height / 2) - (button.y + button.height / 2)) < 4, 'the button shares the views row');
  assert.ok(button.height >= 43.5, `the button is ${button.height}px tall`);
  assert.ok(await top(page, '.trow:not(.trow-head)') < 360, 'the list starts near the top of the screen');
  await shot(page, 'phone-folded');

  await toggle.click();
  assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
  assert.equal(await visible(page, '#queue-filters'), true, 'pressing it shows the filters');
  assert.equal(await page.getByLabel('Priority').isVisible(), true);
  await shot(page, 'phone-open');
  await toggle.click();
  assert.equal(await visible(page, '#queue-filters'), false, 'and pressing it again folds them');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
});

test('phone without the script: the filters stay in view and there is no dead button', async (t) => {
  const page = await open(t, 'queue', { width: 390, js: false });
  assert.equal(await visible(page, '#queue-filters'), true);
  assert.equal(await visible(page, '#filters-toggle'), false);
});

test('desktop: no Filters button; the filters and Select are in the toolbar as before', async (t) => {
  const page = await open(t, 'queue');
  assert.equal(await visible(page, '#filters-toggle'), false);
  assert.equal(await visible(page, '#queue-filters'), true);
  assert.equal(await visible(page, '#bulk-toggle'), true);
});

// ---- the views row on a phone ----
test('phone: the chosen view is in sight, and the views row fades only while more views are off screen', async (t) => {
  const page = await open(t, 'queue', { width: 390 });
  const row = page.locator('.queue-toolbar .filters');
  const inside = await row.evaluate((nav) => {
    const chosen = nav.querySelector('.pill-on').getBoundingClientRect(), box = nav.getBoundingClientRect();
    return chosen.left >= box.left - 1 && chosen.right <= box.right + 1;
  });
  assert.equal(inside, true, 'the chosen view is not hidden off the edge');
  const fades = () => row.evaluate((nav) => ({ right: nav.classList.contains('filters-more'), left: nav.classList.contains('filters-more-left'),
    mask: getComputedStyle(nav).maskImage || getComputedStyle(nav).webkitMaskImage }));
  await row.evaluate((nav) => { nav.scrollLeft = 0; nav.dispatchEvent(new Event('scroll')); });
  let edge = await fades();
  assert.deepEqual([edge.right, edge.left], [true, false], 'at the start: more views to the right, so only the right edge fades');
  assert.notEqual(edge.mask, 'none');
  await row.evaluate((nav) => { nav.scrollLeft = nav.scrollWidth; nav.dispatchEvent(new Event('scroll')); });
  edge = await fades();
  assert.deepEqual([edge.right, edge.left], [false, true], 'at the end: only the left edge fades');
  assert.notEqual(edge.mask, 'none');
});

// ---- the timer in the top bar ----
test('desktop: the idle timer is an outlined button with a clock, not a tag', async (t) => {
  const page = await open(t, 'queue');
  const timer = await page.locator('#timer-widget').evaluate((el) => {
    const s = getComputedStyle(el), r = el.getBoundingClientRect();
    return { border: parseFloat(s.borderTopWidth), edge: s.borderTopColor, fill: s.backgroundColor, height: r.height, text: el.innerText.trim(),
      icon: getComputedStyle(el.querySelector('.tw-icon')).display, label: el.getAttribute('aria-label') };
  });
  assert.equal(timer.border, 1);
  assert.notEqual(timer.edge, 'rgba(0, 0, 0, 0)', 'an outline at rest');
  assert.equal(timer.fill, 'rgba(0, 0, 0, 0)', 'no tag fill');
  assert.ok(timer.height >= 35.5);
  assert.notEqual(timer.icon, 'none', 'with a clock');
  assert.equal(timer.text, 'No timer running');
  assert.equal(timer.label, 'Time: no timer running');
});

test('desktop: a running timer shows its clock, the elapsed time and the ticket', async (t) => {
  const page = await open(t, 'queue', {
    init: { fn: ([key, at]) => localStorage.setItem(key, JSON.stringify({ ticketId: 1041, tenantId: '1', userId: '102', entryKey: 'timer:fixture', state: 'active', startedAt: at })), arg: [TIMER_KEY, Date.now() - 761000] },
  });
  const timer = page.locator('#timer-widget');
  assert.match(await timer.innerText(), /^\d\d:\d\d\s*#1041$/);
  assert.equal(await page.locator('#timer-widget .tw-icon').isVisible(), true);
  assert.equal(await page.locator('#timer-widget .tw-dot').isVisible(), false, 'the clock replaces the dot');
  await shot(page, 'desktop-timer-running');
});

// ---- priority bars and ages ----
for (const theme of ['light', 'dark']) {
  test(`${theme}: High bars are the caution ink at 3:1 or better, Normal bars are not the action blue`, async (t) => {
    const page = await open(t, 'queue', { theme });
    const bars = await page.evaluate(() => {
      const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      const rgba = (v) => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = v; ctx.fillRect(0, 0, 1, 1); const d = ctx.getImageData(0, 0, 1, 1).data; return { r: d[0], g: d[1], b: d[2] }; };
      const lum = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)).reduce((s, v, i) => s + v * [0.2126, 0.7152, 0.0722][i], 0);
      const ratio = (a, b) => { const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
      const row = getComputedStyle(document.querySelector('.card.list')).backgroundColor;
      const gold = document.querySelector('.trow .bar-gold'), blue = document.querySelector('.trow .bar-blue');
      return { gold: ratio(rgba(getComputedStyle(gold).backgroundColor), rgba(row)),
        normal: getComputedStyle(blue).backgroundColor,
        accents: [getComputedStyle(document.querySelector('.btn-primary')).backgroundColor, getComputedStyle(document.querySelector('.link')).color] };
    });
    assert.ok(bars.gold >= 3, `High bars at ${bars.gold.toFixed(2)}:1`);
    assert.equal(bars.accents.includes(bars.normal), false, 'Normal is a neutral, not the action blue');
  });
}

test('ages in the list read like the first-response tag beside them: "20 min", "3 h", "5 d"', async (t) => {
  const page = await open(t, 'queue');
  const ages = await page.locator('.trow:not(.trow-head) .trow-age').allInnerTexts();
  assert.ok(ages.length > 0);
  for (const age of ages) assert.match(age.trim(), /^\d+ (min|h|d)$/);
});
