import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const PIECES = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/service_goal_desk_test.php'), '--render'], { encoding: 'utf8' }));
const PREVIEW = await readFile(path.join(ROOT, 'tools/shots/preview/service-goals.html'), 'utf8');
const ORIGIN = 'http://safeharbor.test';

async function fixture(t, { theme = 'light', width = 1200, key = 'normal', contrast = 'standard' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await page.route(`${ORIGIN}/**`, route => route.fulfill({ contentType: 'text/html', body: `<!doctype html>
<html lang="en" data-theme="${theme}" data-contrast="${contrast}" data-motion="reduce"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Service goals</title><style>${CSS}</style></head>
<body><main class="content">${PIECES[key]}</main></body></html>` }));
  await page.goto(`${ORIGIN}/service_goals.php`);
  return { page, errors };
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_SERVICE_GOALS_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_SERVICE_GOALS_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_SERVICE_GOALS_SHOTS, `${name}.png`), fullPage: true });
}

test('the static preview contains the real renderer and no fake success toast', () => {
  assert.ok(PREVIEW.includes(PIECES.normal));
  assert.ok(!PREVIEW.includes('assigned to you'));
});

for (const theme of ['light', 'dark']) for (const width of [1200, 390]) {
  test(`targets, risk and keyboard history in ${theme} at ${width}`, async t => {
    const { page, errors } = await fixture(t, { theme, width });
    assert.equal(await page.locator('h1').innerText(), 'Service goals');
    assert.equal(await page.locator('.goal-risk-list li').count(), 3);
    assert.match(await page.locator('#goal-risk-title').innerText(), /At risk now.*3/s);
    assert.deepEqual(await page.locator('tbody td').allTextContents(), ['8 h','8 h','8 h','8 h','2 h','2 h','2 h','2 h']);
    assert.equal(await page.locator('.goal-history').getAttribute('open'), null);
    assert.equal(await page.locator('.goal-history-entry').first().isVisible(), false);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    // The five-column target table fits the phone; no concealed horizontal content.
    assert.ok(await page.locator('.goal-table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth + 1));
    await shot(page, `${theme}-${width}`);
    const summary = page.locator('.goal-history > summary');
    await summary.focus();
    assert.ok(await summary.evaluate(el => parseFloat(getComputedStyle(el).outlineWidth) >= 2));
    await page.keyboard.press('Enter');
    assert.ok(await page.locator('.goal-history-entry').first().isVisible());
    assert.equal(await page.locator('.goal-history-entry').count(), 3);
    assert.ok(!((await page.locator('body').innerText()).includes('1970')));
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await shot(page, `${theme}-${width}-history`);
    await page.keyboard.press('Space');
    assert.equal(await page.locator('.goal-history').getAttribute('open'), null);
    assert.equal(await page.locator('.goal-section-head a').getAttribute('href'), '/?view=risk');
    assert.equal(await page.locator('.goal-risk-ticket a').first().getAttribute('href'), '/ticket.php?id=1036&in=risk');
    if (width === 390) {
      assert.ok(await summary.evaluate(el => el.getBoundingClientRect().height >= 44));
      assert.ok(await page.locator('.goal-risk-ticket a').evaluateAll(links => links.every(el => el.getBoundingClientRect().height >= 44)));
    }
    assert.deepEqual(errors, []);
  });
}

for (const key of ['empty', 'unavailable', 'many']) {
  test(`honest ${key} state in high contrast on a phone`, async t => {
    const { page, errors } = await fixture(t, { key, width: 390, theme: 'dark', contrast: 'high' });
    const text = await page.locator('main').innerText();
    if (key === 'empty') {
      assert.match(text, /No unanswered tickets/);
      assert.equal(await page.locator('tbody td').count(), 8);
      assert.ok((await page.locator('tbody td').allTextContents()).every(value => value === 'Not set'));
    } else if (key === 'unavailable') {
      assert.match(text, /temporarily unavailable/);
      assert.equal(await page.locator('table').count(), 0);
      assert.equal(await page.locator('#goal-risk-title').innerText(), 'At risk now');
    } else assert.match(text, /Showing 10 of 63/);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    assert.equal(await page.locator('form').count(), 0);
    assert.deepEqual(errors, []);
    await shot(page, key);
  });
}
