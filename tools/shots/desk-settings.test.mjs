import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// My settings, laid out like the rest of the desk: one card per section, each led by its heading, on
// the page's one left edge with an even gap between cards; a field's help under its field; the actions
// at the foot of their card, Cancel's word level with the filled button's; the desk's form sizes on a
// desktop and 44px controls with 16px text on a phone. The pages are the real app/public/profile.php in
// the real page shell, rendered by app/tests/client_shared_directory_ui_test.php from synthetic data
// (an account with a backup password in a workspace with a support inbox, and an 8 West ID account).
// What the forms do is checked in suite-settings.test.mjs. Not signed-in or identity acceptance.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-desk-settings-'));
const rendered = spawnSync('php', ['app/tests/client_shared_directory_ui_test.php'], {
  cwd: root, env: { ...process.env, CUSTOMER_UI_CAPTURE_DIR: scratch }, encoding: 'utf8',
});
assert.equal(rendered.status, 0, rendered.stdout + rendered.stderr);
test.after(() => rm(scratch, { recursive: true, force: true }));

const ORIGIN = 'http://safeharbor.test';
const shots = process.env.SAFEHARBOR_SETTINGS_SHOTS;

async function open(t, capture, { width, theme }) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (['error', 'warning'].includes(message.type())) errors.push(message.text()); });
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin !== ORIGIN) { errors.push('Unexpected request: ' + url.href); return route.abort(); }
    if (url.pathname === '/profile.php') {
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: await readFile(path.join(scratch, `${capture}-${theme}.html`), 'utf8') });
    }
    if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
      let file = path.join(root, 'app/public', url.pathname);
      if (url.pathname === '/assets/brand/favicon.svg') file = path.join(root, 'brand/svg/favicon.svg');
      if (url.pathname === '/assets/brand/apple-touch-icon.png') file = path.join(root, 'brand/png/apple-touch-icon.png');
      if (url.pathname === '/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png') file = path.join(root, 'brand/png/safeharbor-logo-horizontal-transparent-20260909.png');
      if (url.pathname === '/assets/brand/favicon.ico') return route.fulfill({ status: 204, body: '' });
      const types = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
      try { return await route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) }); }
      catch { errors.push('Missing fixture asset: ' + url.pathname); }
    }
    if (url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
    errors.push('Unexpected request: ' + url.href);
    return route.abort();
  });
  await page.goto(ORIGIN + '/profile.php');
  await page.getByRole('heading', { level: 1, name: 'My settings' }).waitFor();
  return { page, errors };
}

async function shot(page, name) {
  if (!shots) return;
  await mkdir(shots, { recursive: true });
  // The shell scrolls inside .content, so the window is made as tall as the page for the picture.
  const size = page.viewportSize();
  const height = await page.evaluate(() => { const content = document.querySelector('.content'); return content.getBoundingClientRect().top + content.scrollHeight; });
  await page.setViewportSize({ width: size.width, height: Math.max(size.height, Math.ceil(height)) });
  await page.screenshot({ path: path.join(shots, `${name}.png`) });
  await page.setViewportSize(size);
}

/** Where the words inside an element sit: the left edge of their first line and the middle of it. */
const words = (el) => {
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, { acceptNode: (node) => (node.textContent.trim() ? 1 : 3) });
  const range = document.createRange();
  range.selectNodeContents(walker.nextNode());
  const r = range.getClientRects()[0];
  return { left: r.left, middle: (r.top + r.bottom) / 2 };
};

/** Contrast of an element's text against what is painted behind it. */
const contrast = (el) => {
  const rgba = (value) => { const n = (value.match(/[\d.]+/g) || []).map(Number); return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 }; };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  const layers = [];
  for (let node = el; node; node = node.parentElement) layers.push(rgba(getComputedStyle(node).backgroundColor));
  const behind = layers.reverse().reduce((under, top) => over(top, under), { r: 255, g: 255, b: 255, a: 1 });
  const luminance = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const [hi, lo] = [luminance(over(rgba(getComputedStyle(el).color), behind)), luminance(behind)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

for (const [width, theme] of [[1440, 'light'], [1440, 'dark'], [390, 'light'], [390, 'dark']]) {
  const phone = width < 701;
  test(`My settings at ${width}px (${theme}): one card per section on one left edge, help under its field, the form sizes`, async (t) => {
    const { page, errors } = await open(t, 'settings-local', { width, theme });
    const at = await page.evaluate(({ words: wordsSource, contrast: contrastSource }) => {
      const wordsAt = new Function(`return (${wordsSource})`)();
      const contrastOf = new Function(`return (${contrastSource})`)();
      const box = (el) => el.getBoundingClientRect();
      const sections = [...document.querySelectorAll('main .settings-section')];
      const title = document.querySelector('main .page-title');
      return {
        fits: document.documentElement.scrollWidth <= innerWidth + 1,
        titleLeft: wordsAt(title).left,
        cards: sections.map((section) => {
          const heading = section.firstElementChild;
          return { left: box(section).left, top: box(section).top, bottom: box(section).bottom,
            heading: heading.tagName, headingLeft: wordsAt(heading).left, padLeft: box(section).left + parseFloat(getComputedStyle(section).paddingLeft) };
        }),
        forms: [...document.querySelectorAll('main .settings-section form')].map((form) => {
          const primary = form.querySelector('.btn-primary');
          const cancel = [...form.querySelectorAll('a')].find((a) => a.textContent.trim() === 'Cancel');
          return { primary: box(primary).height, primaryMiddle: wordsAt(primary).middle, cancel: box(cancel).height, cancelMiddle: wordsAt(cancel).middle,
            actionsTop: box(form.querySelector('.form-actions')).top,
            fields: [...form.querySelectorAll('input:not([type=hidden])')].map((input) => ({ height: box(input).height, bottom: box(input).bottom, font: parseFloat(getComputedStyle(input).fontSize) })) };
        }),
        help: [...document.querySelectorAll('main .field-help')].map((help) => {
          const input = document.querySelector(`[aria-describedby~="${help.id}"]`);
          return { top: box(help).top, inputBottom: input ? box(input).bottom : null, inLabel: !!help.closest('label'), contrast: contrastOf(help) };
        }),
        quiet: [...document.querySelectorAll('main .settings-note, main .page-sub')].map((el) => contrastOf(el)),
        avatar: (() => { const avatar = document.querySelector('main .page-head .avatar'); return avatar ? avatar.getClientRects().length > 0 : null; })(),
        suite: (() => { const link = document.querySelector('main a[href="https://id.8westit.com/settings.php"]'); return { height: box(link).height, icon: link.querySelector('svg') ? box(link.querySelector('svg')).width : 0 }; })(),
      };
    }, { words: words.toString(), contrast: contrast.toString() });

    assert.ok(at.fits, 'the page is no wider than the window');
    assert.equal(at.cards.length, 4, 'four sections: the support inbox, the suite, the name and the password');
    for (const [i, card] of at.cards.entries()) {
      assert.equal(card.heading, 'H2', `section ${i + 1} starts with its heading`);
      assert.ok(Math.abs(card.left - at.titleLeft) <= 1, `section ${i + 1} starts ${(card.left - at.titleLeft).toFixed(1)}px off the title's edge`);
      assert.ok(Math.abs(card.headingLeft - card.padLeft) <= 1, `section ${i + 1}'s heading starts ${(card.headingLeft - card.padLeft).toFixed(1)}px off its card's inner edge`);
      if (i > 0) assert.ok(Math.abs(card.top - at.cards[i - 1].bottom - 16) <= 1, `${(card.top - at.cards[i - 1].bottom).toFixed(1)}px between sections ${i} and ${i + 1}`);
    }
    assert.equal(at.forms.length, 2);
    for (const form of at.forms) {
      assert.ok(Math.abs(form.cancelMiddle - form.primaryMiddle) <= 1, `Cancel's word is ${(form.cancelMiddle - form.primaryMiddle).toFixed(1)}px off the filled button's`);
      assert.ok(form.primary >= (phone ? 44 : 36) && form.cancel >= (phone ? 44 : 36), `buttons ${form.primary}px and ${form.cancel}px tall`);
      if (!phone) assert.ok(form.primary <= 40, `the filled button is the desk's ${form.primary}px, not a large one`);
      for (const field of form.fields) {
        assert.ok(field.bottom < form.actionsTop, 'the fields come before the actions');
        if (phone) assert.ok(field.height >= 44 && field.font >= 16, `a phone field is ${field.height}px with ${field.font}px text`);
        else assert.ok(field.height >= 36 && field.height <= 40, `a desktop field is ${field.height}px`);
      }
    }
    assert.ok(at.help.length >= 1, 'the name field has its help');
    for (const help of at.help) {
      assert.ok(help.inputBottom !== null && !help.inLabel, 'help is tied to its field and kept out of the label');
      assert.ok(help.top >= help.inputBottom && help.top - help.inputBottom <= 12, `help sits ${(help.top - help.inputBottom).toFixed(1)}px under its field`);
      assert.ok(help.contrast >= 4.5, `help text at ${help.contrast.toFixed(2)}:1`);
    }
    for (const ratio of at.quiet) assert.ok(ratio >= 4.5, `quiet text at ${ratio.toFixed(2)}:1`);
    assert.equal(at.avatar, !phone, phone ? 'no stray avatar under the title on a phone' : 'the avatar beside the title on a desktop');
    assert.ok(at.suite.height >= (phone ? 44 : 36) && at.suite.icon > 0, `the way to 8 West ID is a ${at.suite.height}px button marked as another app`);
    await shot(page, `settings-local-${width}-${theme}`);
    assert.deepEqual(errors, []);
  });
}

for (const [width, theme] of [[1440, 'dark'], [390, 'light']]) {
  test(`My settings for an 8 West ID account at ${width}px (${theme}): one card, no local forms`, async (t) => {
    const { page, errors } = await open(t, 'settings-id', { width, theme });
    assert.equal(await page.locator('main .settings-section').count(), 1);
    assert.equal(await page.locator('main form').count(), 0);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'the page is no wider than the window');
    await shot(page, `settings-id-${width}-${theme}`);
    assert.deepEqual(errors, []);
  });
}
