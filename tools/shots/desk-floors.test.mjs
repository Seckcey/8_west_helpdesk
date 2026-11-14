import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The floors in spec/DESIGN.md, checked on the staff previews (tools/shots/preview) with the app's
// real stylesheet and page script: click targets 36px on a desktop and 44px on a phone, phone fields
// with 16px text, no text under 12px, avatars readable in both themes, and control edges at 3:1.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PREVIEW = path.join(ROOT, 'tools/shots/preview');
const ORIGIN = 'http://safeharbor.test';
const PAGES = ['queue', 'ticket', 'time', 'clients', 'team', 'service-goals', 'saved-replies', 'saved-reply-edit'];

async function open(t, name, { width = 1440, theme = 'dark', presence = null } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  await page.route(`${ORIGIN}/**`, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/presence.php' && presence) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, others: presence }) });
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
      // The static ticket's composer lacks the hidden mode field the page script starts from.
      .replace('id="reply-form">', 'id="reply-form"><input type="hidden" name="mode" id="composer-mode" value="reply">')
      .replace('</body>', '<div id="palette-root"></div><script src="/assets/js/app.js"></script></body>');
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(`${ORIGIN}/p/${name}.html?theme=${theme}`);
  await page.waitForFunction(() => document.readyState === 'complete'
    && [...document.querySelectorAll('.trow, .crow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return page;
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_FLOOR_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_FLOOR_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_FLOOR_SHOTS, `${name}.png`), fullPage: true });
}

// In the page: any CSS colour as 0–255 RGBA (a canvas reads color-mix() and color(srgb …) too),
// what is painted behind an element, and the WCAG ratio of two colours.
const COLOUR_HELPERS = `
  const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  const rgba = (value) => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = '#000'; ctx.fillStyle = value; ctx.fillRect(0, 0, 1, 1);
    const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data; return { r, g, b, a: a / 255 }; };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  const behind = (node) => { const layers = []; for (let n = node; n; n = n.parentElement) layers.push(rgba(getComputedStyle(n).backgroundColor));
    return layers.reverse().reduce((under, top) => over(top, under), { r: 255, g: 255, b: 255, a: 1 }); };
  const luminance = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const ratio = (x, y) => { const [hi, lo] = [luminance(x), luminance(y)].sort((a, b) => b - a); return (hi + 0.05) / (lo + 0.05); };
`;
const inPage = (page, body, arg) => page.evaluate(new Function('arg', `${COLOUR_HELPERS}\n${body}`), arg);

const shown = `const shown = (el) => { const r = el.getBoundingClientRect(), s = getComputedStyle(el);
  return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !el.closest('[hidden], .sr-only'); };`;

// ---- every click target 36px on a desktop ----
const TARGETS = ['.btn-primary', '.btn-secondary', '.btn-ghost', '.btn-danger', '.btn-sm', '.btn-chip', '.btn-gold', '.btn-link',
  '.toast-undo', '.att-pick', '.timer-log-chip', '.contact-add-btn', '.contact-remove', '.call-new > summary',
  '.client-link-details > summary', '.entry-history > summary', '.composer-tab', '.ticket-step', '.contact-more-btn',
  '#timer-widget', '.page-ticket .ticket-bar .backlink', '.timer-btn', '.filter-select', '.menu-btn', '.search-trigger'].join(', ');

for (const name of PAGES) {
  test(`desktop ${name}: every button and link-button is at least 36px tall, and no text is under 12px`, async (t) => {
    const page = await open(t, name);
    const small = await inPage(page, `${shown}
      return [...document.querySelectorAll(arg)].filter((el) => el.closest('.shell, .palette, .toasts') && shown(el))
        .map((el) => ({ what: (el.className || el.id) + ' "' + el.innerText.trim().slice(0, 30) + '"', height: el.getBoundingClientRect().height }))
        .filter((c) => c.height < 35.5);`, TARGETS);
    assert.deepEqual(small, [], 'nothing to click is shorter than 36px');
    const tiny = await inPage(page, `${shown}
      const out = [];
      for (const el of document.querySelectorAll('.shell *')) {
        if (el.closest('.w365-cluster') || !shown(el)) continue;
        const own = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== '');
        if (own && parseFloat(getComputedStyle(el).fontSize) < 12) out.push(el.tagName + '.' + el.className + ' ' + getComputedStyle(el).fontSize);
      }
      return out;`);
    assert.deepEqual(tiny, [], 'no text is smaller than 12px');
    await shot(page, `desktop-${name}-dark`);
  });
}

// ---- phone: fields 44px with 16px text, filled buttons 44px ----
for (const name of ['queue', 'ticket', 'time', 'team', 'saved-reply-edit']) {
  test(`phone ${name}: text fields have 16px text, fields in a form are 44px, every filled button is 44px`, async (t) => {
    const page = await open(t, name, { width: 390 });
    await page.evaluate(() => document.querySelectorAll('.shell details').forEach((d) => { d.open = true; }));
    const fields = await inPage(page, `${shown}
      return [...document.querySelectorAll('.shell input, .shell select, .shell textarea')]
        .filter((el) => !['checkbox', 'radio', 'hidden', 'file'].includes(el.type) && shown(el))
        .map((el) => ({ what: el.tagName + '#' + el.id + '[' + (el.name || '') + ']', font: parseFloat(getComputedStyle(el).fontSize),
          height: el.getBoundingClientRect().height, inForm: !!el.closest('.field') }));`);
    assert.ok(fields.length > 0 || name === 'queue' || name === 'ticket', `${name} has fields to check`);
    for (const field of fields) {
      assert.ok(field.font >= 16, `${field.what} text is ${field.font}px`);
      if (field.inForm) assert.ok(field.height >= 43.5, `${field.what} is ${field.height}px tall`);
    }
    const buttons = await inPage(page, `${shown}
      return [...document.querySelectorAll('.shell :is(.btn-primary, .btn-danger, .btn-ghost, .btn-secondary, .btn-gold)')].filter(shown)
        .map((el) => ({ what: el.className + ' "' + el.innerText.trim().slice(0, 30) + '"', height: el.getBoundingClientRect().height }))
        .filter((b) => b.height < 43.5);`);
    assert.deepEqual(buttons, [], 'every filled button is 44px on a phone');
    await shot(page, `phone-${name}`);
  });
}

// ---- avatars ----
for (const theme of ['light', 'dark']) {
  test(`${theme}: avatars have 12px initials at 4.5:1, and in dark a tint rather than the bright colour`, async (t) => {
    const page = await open(t, 'queue', { theme });
    const avatars = await inPage(page, `
      return [...document.querySelectorAll('.avatar[style*="--avatar"]')].map((el) => {
        const s = getComputedStyle(el), raw = rgba(el.style.getPropertyValue('--avatar').trim()), fill = rgba(s.backgroundColor);
        return { who: el.title, font: parseFloat(s.fontSize), contrast: ratio(rgba(s.color), fill), fill: luminance(fill), raw: luminance(raw) };
      });`);
    assert.ok(avatars.length >= 3, 'the preview has coloured avatars');
    for (const a of avatars) {
      assert.ok(a.font >= 12, `${a.who}: initials ${a.font}px`);
      assert.ok(a.contrast >= 4.5, `${a.who}: initials at ${a.contrast.toFixed(2)}:1`);
      if (theme === 'dark') assert.ok(a.fill < a.raw / 3, `${a.who}: a tint (${a.fill.toFixed(3)}) of the colour (${a.raw.toFixed(3)})`);
      else assert.ok(Math.abs(a.fill - a.raw) < 0.01, `${a.who}: the colour itself`);
    }
    // Nobody: a dashed ring that is a control's edge, 3:1 against the row.
    const none = await inPage(page, `
      const el = document.querySelector('.trow .avatar-none'), s = getComputedStyle(el);
      return { style: s.borderTopStyle, width: parseFloat(s.borderTopWidth), edge: ratio(rgba(s.borderTopColor), behind(el.parentElement)), mark: ratio(rgba(s.color), behind(el.parentElement)) };`);
    assert.equal(none.style, 'dashed');
    assert.ok(none.width >= 1, 'the ring is drawn');
    assert.ok(none.edge >= 3, `the ring is ${none.edge.toFixed(2)}:1 against the row`);
    assert.ok(none.mark >= 4.5, `the dash is ${none.mark.toFixed(2)}:1`);
    await shot(page, `queue-avatars-${theme}`);
  });
}

test('the people on a ticket are drawn with the same avatar: 22px, 12px initials, and a bad colour is ignored', async (t) => {
  const page = await open(t, 'ticket', { presence: [
    { name: 'Jon Silva', initials: 'JS', color: '#62F6B0', mode: 'viewing' },
    { name: 'Ana Kim', initials: 'AK', color: 'red;background:url(x)', mode: 'typing' },
  ] });
  await page.locator('#presence .presence-chip', { hasText: 'Ana Kim' }).waitFor();
  const chips = await page.locator('#presence .avatar').evaluateAll((els) => els.map((el) => ({
    size: el.getBoundingClientRect().height, font: parseFloat(getComputedStyle(el).fontSize), style: el.getAttribute('style') })));
  assert.equal(chips.length, 2);
  for (const chip of chips) { assert.equal(Math.round(chip.size), 22); assert.ok(chip.font >= 12); }
  assert.equal(chips[0].style, 'width:22px;height:22px;--avatar:#62F6B0');
  assert.equal(chips[1].style, 'width:22px;height:22px', 'a colour that is not #RRGGBB never reaches the style');
});

// ---- control edges ----
for (const theme of ['light', 'dark']) {
  test(`${theme}: the search field's edge is 3:1, and the rail has a hairline edge only in dark`, async (t) => {
    const page = await open(t, 'queue', { theme });
    const edges = await inPage(page, `
      const search = document.querySelector('#palette-trigger'), rail = getComputedStyle(document.querySelector('.sidebar'));
      return { search: ratio(rgba(getComputedStyle(search).borderTopColor), behind(search.parentElement)),
        rail: { width: parseFloat(rail.borderRightWidth), alpha: rgba(rail.borderRightColor).a, same: rail.borderRightColor === rail.backgroundColor } };`);
    assert.ok(edges.search >= 3, `the search field's edge is ${edges.search.toFixed(2)}:1`);
    if (theme === 'dark') {
      assert.equal(edges.rail.width, 1);
      assert.ok(edges.rail.alpha === 1 && !edges.rail.same, 'dark: the rail has a visible edge');
    } else {
      assert.equal(edges.rail.alpha, 0, 'light: the navy rail needs no edge');
    }
  });
}

test('the queue gives the ticket number a column its own size, and the number is whole', async (t) => {
  const page = await open(t, 'queue');
  const nums = await page.locator('.trow:not(.trow-head) .trow-num').evaluateAll((els) => els.map((el) => ({
    width: el.getBoundingClientRect().width, cut: el.scrollWidth > el.clientWidth + 1 })));
  assert.ok(nums.length > 0);
  for (const n of nums) { assert.ok(n.width <= 56, `the number column is ${n.width}px`); assert.equal(n.cut, false); }
  const head = await page.locator('.trow-head .trow-num').evaluate((el) => el.getBoundingClientRect().left);
  const first = await page.locator('.trow:not(.trow-head) .trow-num').first().evaluate((el) => el.getBoundingClientRect().left);
  assert.ok(Math.abs(head - first) < 1, '"No." stays over the numbers');
});
