import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Fixes from the review of the live desk, checked in a browser on the real stylesheet and script.
// The pages are the static staff previews (tools/shots/preview), served with the app's own assets
// and its page script added, so a test sees what a technician sees in each theme and on a phone.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PREVIEW = path.join(ROOT, 'tools/shots/preview');
const ORIGIN = 'http://safeharbor.test';
const TIMER_KEY = 'safeharbor.timer.v2.1:102';

async function open(t, name, { width = 1440, theme = 'dark', init = null } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
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
      .replace(/<body data-active="([a-z-]+)">/, '<body data-active="$1" data-tenant-id="1" data-user-id="102" data-csrf="fixture">')
      .replace(/<div id="toasts" class="toasts">.*?<\/div><\/div>/s, '<div id="toasts" class="toasts"></div>')
      .replace('</body>', '<div id="palette-root"></div><script src="/assets/js/app.js"></script></body>');
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(`${ORIGIN}/p/${name}.html?theme=${theme}`);
  // Loaded, and the list's rows have finished fading in.
  await page.waitForFunction(() => document.readyState === 'complete'
    && [...document.querySelectorAll('.trow, .crow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return page;
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_FIX_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_FIX_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_FIX_SHOTS, `${name}.png`), fullPage: true });
}

const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const box = (page, selector) => page.locator(selector).first().evaluate((el) => {
  const r = el.getBoundingClientRect();
  const s = getComputedStyle(el);
  return {
    left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height,
    shown: s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0,
    clipped: el.scrollWidth > el.clientWidth + 1,
    text: el.innerText.trim(),
  };
});
// Colour helpers that run in the page: a colour's parts, and what is painted behind an element.
const paint = (page, selector) => page.locator(selector).first().evaluate((el) => {
  const rgba = (value) => { const n = (value.match(/[\d.]+/g) || []).map(Number); return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 }; };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  const behind = (node) => {
    const layers = [];
    for (let n = node; n; n = n.parentElement) layers.push(rgba(getComputedStyle(n).backgroundColor));
    return layers.reverse().reduce((under, top) => over(top, under), { r: 255, g: 255, b: 255, a: 1 });
  };
  const luminance = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const style = getComputedStyle(el);
  const fill = behind(el);
  const ink = over(rgba(style.color), fill);
  const [hi, lo] = [luminance(ink), luminance(fill)].sort((a, b) => b - a);
  return {
    background: style.backgroundColor, light: luminance(fill), contrast: (hi + 0.05) / (lo + 0.05),
    shadow: style.boxShadow, borderWidth: parseFloat(style.borderTopWidth), borderAlpha: rgba(style.borderTopColor).a, fillAlpha: rgba(style.backgroundColor).a,
  };
});

// ---- B1: the top bar on a phone ----
for (const width of [390, 360]) {
  test(`phone top bar at ${width}px: Menu, a search button and the account cluster, nothing clipped`, async (t) => {
    const page = await open(t, 'queue', { width });
    const menu = await box(page, '#mobile-nav-toggle');
    const search = await box(page, '#palette-trigger');
    const cluster = await box(page, '.topbar .w365-cluster');
    assert.equal(menu.shown && search.shown && cluster.shown, true);
    assert.equal((await box(page, '#timer-widget')).shown, false, 'an idle timer is not in the bar');
    assert.equal((await box(page, '.search-label')).shown || (await box(page, '.search-keys')).shown, false, 'the search is an icon');
    assert.equal(await page.locator('#palette-trigger').getAttribute('aria-label'), 'Search or run a command');
    for (const [name, part] of [['Menu', menu], ['Search', search]]) {
      assert.ok(part.width >= 44 && part.height >= 44, `${name} is ${part.width} by ${part.height}`);
    }
    for (const [name, part] of [['Menu', menu], ['Search', search], ['cluster', cluster]]) {
      assert.equal(part.clipped, false, `${name} is not clipped`);
      assert.ok(part.left >= 0 && part.right <= width, `${name} is on screen`);
    }
    assert.ok(menu.right <= search.left && search.right <= cluster.left, 'no control sits on another');
    assert.equal(menu.text.includes('Menu'), true);
    assert.equal(await fits(page), true);
    await shot(page, `topbar-${width}-dark`);

    await page.locator('#palette-trigger').click();
    await page.locator('#palette-root .palette-overlay').waitFor();
  });

  test(`phone top bar at ${width}px with a timer running: a short clock, one tap from Time`, async (t) => {
    const page = await open(t, 'queue', {
      width,
      init: { fn: ([key, at]) => localStorage.setItem(key, JSON.stringify({ ticketId: 1041, tenantId: '1', userId: '102', entryKey: 'timer:fixture', state: 'active', startedAt: at })), arg: [TIMER_KEY, Date.now() - 761000] },
    });
    const timer = await box(page, '#timer-widget');
    assert.equal(timer.shown, true);
    assert.match(timer.text, /^\d\d:\d\d$/, 'the clock alone');
    assert.equal(timer.clipped, false);
    assert.equal(await page.locator('#timer-widget').getAttribute('href'), '#');   // the preview's link to Time
    // The chip is small; its touch area is 44px tall.
    const hits = await page.locator('#timer-widget').evaluate((el) => {
      const r = el.getBoundingClientRect();
      const x = r.left + r.width / 2, y = r.top + r.height / 2;
      return [y - 21, y, y + 21].map((at) => document.elementFromPoint(x, at)?.closest('#timer-widget') === el);
    });
    assert.deepEqual(hits, [true, true, true]);
    const search = await box(page, '#palette-trigger');
    const cluster = await box(page, '.topbar .w365-cluster');
    assert.ok(search.right <= timer.left && timer.right <= cluster.left && cluster.right <= width, 'everything still fits');
    assert.equal(await fits(page), true);
    await shot(page, `topbar-timer-${width}-dark`);
  });
}

// ---- F1: the search hint names this computer's key ----
test('the search hint is two key chips: Ctrl K, or the Mac key on a Mac', async (t) => {
  const page = await open(t, 'queue');
  assert.deepEqual(await page.locator('.search-keys kbd').allInnerTexts(), ['Ctrl', 'K']);
  const keys = await page.locator('.search-keys kbd').evaluateAll((chips) => chips.map((chip) => chip.getBoundingClientRect()).map((r) => ({ left: r.left, right: r.right })));
  assert.ok(keys[1].left - keys[0].right >= 2, 'the two chips do not touch');
  assert.equal((await box(page, '.search-label')).text, 'Search or command…');
  assert.equal((await box(page, '#timer-widget')).text, 'No timer running');
  await shot(page, 'topbar-1440-dark');

  const mac = await open(t, 'queue', { init: { fn: () => Object.defineProperty(navigator, 'platform', { get: () => 'MacIntel' }), arg: null } });
  assert.deepEqual(await mac.locator('.search-keys kbd').allInnerTexts(), ['⌘', 'K']);
});

// ---- F5: the selected view ----
for (const [theme, width] of [['dark', 1440], ['dark', 390], ['light', 1440]]) {
  test(`${theme} at ${width}px: the selected view is the lightest thing in its track`, async (t) => {
    const page = await open(t, 'queue', { theme, width });
    const on = await paint(page, '.filter-pill.pill-on');
    const track = await paint(page, '.filters');
    const off = await paint(page, '.filter-pill:not(.pill-on)');
    assert.ok(on.light > track.light, `chip ${on.background} on track ${track.background}`);
    assert.ok(on.light > off.light);
    assert.ok(on.contrast >= 4.5 && off.contrast >= 4.5, `${on.contrast.toFixed(2)} and ${off.contrast.toFixed(2)} to 1`);
    assert.ok((await paint(page, '.pill-on .pill-count')).contrast >= 4.5);
  });
}

// ---- F33: large caution surfaces ----
test('dark: a notice and an internal note are a card with an amber edge, not a brown slab', async (t) => {
  for (const width of [1440, 390]) {
    const page = await open(t, 'ticket', { width });
    // The Clients bands use these same two classes.
    // (and so does any page that still prints a .banner-warn).
    await page.evaluate(() => document.querySelector('.page').insertAdjacentHTML('beforeend',
      '<div class="demo-card"><span class="demo-text">A band on Clients</span></div><div class="banner banner-warn">A banner on another page</div>'));
    const card = (await paint(page, '.card')).background;
    for (const selector of ['.ticket-callout-caution', '.banner-warn', '.msg-note', '.demo-card']) {
      const part = await paint(page, selector);
      assert.equal(part.background, card, `${selector} is the card colour`);
      assert.notEqual(part.background, 'rgb(61, 49, 21)');
      assert.match(part.shadow, /inset/, `${selector} has its caution edge`);
      assert.match(part.shadow, /rgb\(233, 182, 58\)/, `${selector}'s edge is amber`);
      assert.ok(part.contrast >= 4.5, `${selector} reads at ${part.contrast.toFixed(2)} to 1`);
    }
    assert.ok((await paint(page, '.msg-note .msg-author')).contrast >= 4.5);
    assert.ok((await paint(page, '.msg-note .msg-role')).contrast >= 4.5);
    await shot(page, `ticket-caution-${width}-dark`);
  }
});

test('light: the caution tint is unchanged', async (t) => {
  const page = await open(t, 'ticket', { theme: 'light' });
  await page.evaluate(() => document.querySelector('.page').insertAdjacentHTML('beforeend', '<div class="banner banner-warn">A banner on another page</div>'));
  for (const selector of ['.ticket-callout-caution', '.banner-warn', '.msg-note']) {
    const part = await paint(page, selector);
    assert.equal(part.background, 'rgb(255, 242, 209)');
    assert.doesNotMatch(part.shadow, /rgb\(/, `${selector} has no visible edge`);
    assert.ok(part.contrast >= 4.5);
  }
  await shot(page, 'ticket-caution-1440-light');
});

// ---- F8: controls that look like controls ----
test('Select, Saved replies and Reject are buttons with a border and a fill, 36px tall and 44px on a phone', async (t) => {
  for (const [name, selector] of [['queue', '#bulk-toggle'], ['ticket', '#canned-open'], ['time', '.time-review[data-decision="rejected"]']]) {
    for (const theme of ['dark', 'light']) {
      const page = await open(t, name, { theme });
      const look = await paint(page, selector);
      const size = await box(page, selector);
      assert.ok(look.borderWidth >= 1 && look.borderAlpha > 0 && look.fillAlpha > 0, `${selector} has an edge and a fill in ${theme}`);
      assert.ok(size.height >= 36, `${selector} is ${size.height}px tall`);
      assert.ok(look.contrast >= 4.5);
    }
    const phone = await open(t, name, { width: 390 });
    assert.ok((await box(phone, selector)).height >= 44, `${selector} is a 44px target on a phone`);
  }
  const time = await open(t, 'time');
  assert.equal((await box(time, '.time-review[data-decision="approved"]')).height, (await box(time, '.time-review[data-decision="rejected"]')).height, 'Approve and Reject are one height');
});

// ---- F3: no row looks chosen on load (the queue's keys are in queue-views.test.mjs) ----
test('Clients: nothing is highlighted on load; the first j shows the first row', async (t) => {
  for (const width of [1440, 390]) {
    const page = await open(t, 'clients', { width });
    assert.equal(await page.locator('.crow.crow-sel').count(), 0);
    if (width === 390) continue;
    await page.keyboard.press('j');
    assert.deepEqual(await page.locator('.crow.crow-sel').evaluateAll((rows) => rows.map((row) => row.dataset.clientId)), ['1']);
    await page.keyboard.press('j');
    assert.deepEqual(await page.locator('.crow.crow-sel').evaluateAll((rows) => rows.map((row) => row.dataset.clientId)), ['2']);
  }
  const queue = await open(t, 'queue', { width: 390 });
  assert.equal(await queue.locator('.trow.trow-sel').count(), 0);
});

// ---- B2: Time on a phone ----
test('Time on a phone: the whole title with the duration on its line, and the record text at full width', async (t) => {
  const page = await open(t, 'time', { width: 390 });
  const rows = await page.locator('.entry-row').evaluateAll((list) => list.map((row) => {
    const rect = (el) => el.getBoundingClientRect();
    const note = row.querySelector('.entry-note'), min = row.querySelector('.entry-min');
    const inner = rect(row).width - parseFloat(getComputedStyle(row).paddingLeft) - parseFloat(getComputedStyle(row).paddingRight);
    const badge = row.querySelector('.entry-badge'), actions = row.querySelector('.review-actions');
    return {
      titleCut: note.scrollWidth > note.clientWidth + 1 || getComputedStyle(note).textOverflow === 'ellipsis' && getComputedStyle(note).whiteSpace === 'nowrap',
      minOnTitleLine: Math.abs(rect(min).top - rect(note).top) < 4,
      minFromTop: Math.round(rect(min).top - rect(row).top),
      minAtRight: rect(row).right - rect(min).right < 24 && rect(min).left >= rect(note).right - 1,
      wide: [...row.querySelectorAll('.entry-evidence, .entry-lineage, .entry-ticket')].map((el) => rect(el).width / inner),
      evidenceLines: [...row.querySelectorAll('.entry-evidence')].map((el) => Math.round(rect(el).height / parseFloat(getComputedStyle(el).lineHeight))),
      badgeAtLeft: Math.abs(rect(badge).left - rect(note).left) < 2,
      actionsAtRight: actions ? rect(row).right - rect(actions).right < 24 && Math.abs(rect(actions).bottom - rect(badge).bottom) < 16 : null,
      buttons: [...row.querySelectorAll('button')].map((button) => rect(button).height),
    };
  }));
  assert.equal(rows.length, 3);
  for (const row of rows) {
    assert.equal(row.titleCut, false, 'the title is whole');
    assert.equal(row.minOnTitleLine && row.minAtRight, true, 'the duration is at the right of the title line');
    assert.ok(row.wide.every((share) => share > 0.95), `the text under the title is full width (${row.wide.map((s) => s.toFixed(2))})`);
    assert.ok(row.evidenceLines.every((lines) => lines <= 3), 'the record text is not a narrow column');
    assert.equal(row.badgeAtLeft, true);
    assert.ok(row.buttons.every((height) => height >= 44));
  }
  assert.equal(new Set(rows.map((row) => row.minFromTop)).size, 1, 'every duration sits at the same height in its card');
  assert.equal(rows[2].actionsAtRight, true, 'Approve and Reject are at the right of the last line');
  assert.equal(await fits(page), true);
  await shot(page, 'time-390-dark');

  // On a desktop the row is unchanged: one line, duration first.
  const desk = await open(t, 'time');
  const first = await desk.locator('.entry-row').first().evaluate((row) => ({
    minFirst: row.querySelector('.entry-min').getBoundingClientRect().right <= row.querySelector('.entry-note').getBoundingClientRect().left,
    display: getComputedStyle(row).display,
  }));
  assert.deepEqual(first, { minFirst: true, display: 'flex' });
});
