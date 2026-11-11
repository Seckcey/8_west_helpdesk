import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The queue's views, filters and paging in a browser, on the real stylesheet and script. The page
// is built from the real helpers (app/tests/ticket_queue_test.php --render). Every address answers
// with the same page, so a test reads where a click or a key went from the address it produced.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const FIX = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/ticket_queue_test.php'), '--render'], { encoding: 'utf8' }));
const ORIGIN = 'http://safeharbor.test';

const html = (theme) => `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Queue</title><style>${CSS}</style></head>
<body data-active="queue" data-tenant-id="1" data-user-id="102" data-csrf="fixture"><main class="content"><div class="page">
<div class="page-head"><div><h1 class="page-title">Queue</h1><p class="page-sub">${FIX.summary}</p></div><div class="page-actions"><a href="/ticket_new.php" class="btn-primary">New ticket</a></div></div>
${FIX.toolbar}${FIX.list}${FIX.pager}${FIX.elsewhere}${FIX.bar}</div></main>
<div id="palette-root"></div><div id="toasts" class="toasts"></div><script>${JS}</script></body></html>`;

async function fixture(t, { width = 1440, theme = 'dark' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route(`${ORIGIN}/**`, (route) => route.request().url().includes('/api/')
    ? route.fulfill({ contentType: 'application/json', body: '{"ok":true}' })
    : route.fulfill({ contentType: 'text/html', body: html(theme) }));
  await page.goto(`${ORIGIN}/?view=open`);
  await settled(page);
  return { page, errors };
}

const settled = (page) => page.waitForFunction(() => document.readyState === 'complete'
  && [...document.querySelectorAll('.trow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
const where = (page) => page.url().slice(ORIGIN.length);
const highlighted = (page) => page.locator('.trow.trow-sel').evaluateAll((rows) => rows.map((row) => row.dataset.ticketId));
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
// A key or a click that leads somewhere: wait for that address, then for the page behind it.
async function goes(page, act, address) {
  await act();
  await page.waitForURL(ORIGIN + address);
  await settled(page);
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_QUEUE_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_QUEUE_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_QUEUE_SHOTS, `${name}.png`), fullPage: true });
}

// The row keys are the ones the queue has always had: no key gained a step.
test('no row is highlighted until a key asks; the first j shows the first row, then j and k move it', async (t) => {
  const { page, errors } = await fixture(t);
  assert.deepEqual(await highlighted(page), [], 'nothing looks chosen when the page opens');
  await shot(page, 'queue-1440-dark');
  await page.keyboard.press('j');
  assert.deepEqual(await highlighted(page), ['101'], 'the first j shows the highlight on the first row');
  await page.keyboard.press('j');
  assert.deepEqual(await highlighted(page), ['102'], 'the next j moves to the second row');
  await page.keyboard.press('j');
  await page.keyboard.press('j');
  assert.deepEqual(await highlighted(page), ['103'], 'j stops at the bottom');
  await page.keyboard.press('k');
  await page.keyboard.press('k');
  await page.keyboard.press('k');
  assert.deepEqual(await highlighted(page), ['101'], 'k stops at the top');
  assert.equal(where(page), '/?view=open');
  assert.deepEqual(errors, []);
});

test('the first Enter opens the first row at once', async (t) => {
  const { page, errors } = await fixture(t);
  await goes(page, () => page.keyboard.press('Enter'), '/ticket.php?id=101');
  assert.deepEqual(errors, []);
});

test('the first k or arrow shows the highlight too, and a key that acts on a row shows which row', async (t) => {
  for (const key of ['k', 'ArrowDown', 'ArrowUp']) {
    const { page } = await fixture(t);
    await page.keyboard.press(key);
    assert.deepEqual(await highlighted(page), ['101'], key);
  }
  const { page, errors } = await fixture(t);
  await page.keyboard.press('s');
  await page.getByRole('listbox').waitFor();
  assert.deepEqual(await highlighted(page), ['101'], 'the menu is for the row now highlighted');
  assert.deepEqual(errors, []);
});

test('j, j then Enter opens the second row', async (t) => {
  const { page, errors } = await fixture(t);
  await page.keyboard.press('j');
  await page.keyboard.press('j');
  await goes(page, () => page.keyboard.press('Enter'), '/ticket.php?id=102');
  assert.deepEqual(errors, []);
});

test('keys 1 to 6 open the six views', async (t) => {
  const { page, errors } = await fixture(t);
  const views = ['/', '/?view=mine', '/?view=unassigned', '/?view=waiting', '/?view=open', '/?view=resolved'];
  assert.deepEqual(await page.locator('nav[aria-label="Queue views"] a').evaluateAll((links) => links.map((a) => a.getAttribute('href'))), views);
  for (const key of [6, 2, 3, 4, 1, 5]) {
    await goes(page, () => page.keyboard.press(String(key)), views[key - 1]);
  }
  await page.keyboard.press('7');
  assert.equal(where(page), '/?view=open', '7 is not a view');
  assert.deepEqual(errors, []);
});

test('a filter applies as soon as it is chosen, and the address stays short', async (t) => {
  const { page, errors } = await fixture(t);
  assert.equal(await page.getByRole('button', { name: 'Apply', exact: true }).count(), 0, 'Apply is only for browsers without script');
  await goes(page, () => page.getByLabel('Priority').selectOption('urgent'), '/?view=open&priority=urgent');
  await goes(page, () => page.getByLabel('Client').selectOption({ label: 'Fixture Dental' }), '/?view=open&client=11');
  await goes(page, () => page.getByLabel('Owner').selectOption('none'), '/?view=open&owner=none');
  await goes(page, () => page.getByLabel('Sort').selectOption('newest'), '/?view=open&sort=newest');
  // Back restores the page: its selects must answer again, not stay switched off by the submit.
  await page.goBack();
  await settled(page);
  assert.deepEqual(await page.locator('#queue-filters select').evaluateAll((selects) => selects.map((select) => select.disabled)), [false, false, false, false]);
  assert.deepEqual(errors, []);
});

test('with every choice back at its default the address is the plain queue', async (t) => {
  const { page, errors } = await fixture(t);
  // The default view carries no hidden field; with nothing chosen there is nothing to send.
  await page.evaluate(() => document.querySelector('#queue-filters input[name="view"]').remove());
  await page.evaluate(() => document.querySelector('#queue-filters').requestSubmit());
  await page.waitForURL(`${ORIGIN}/`);
  assert.equal(page.url(), `${ORIGIN}/`, 'no address that ends in a bare question mark');
  assert.deepEqual(errors, []);
});

test('Next and Previous are links', async (t) => {
  const { page, errors } = await fixture(t);
  assert.equal(await page.locator('.pager-count').innerText(), 'Showing 1 to 50 of 131');
  assert.equal(await page.locator('.pager [aria-disabled="true"]').innerText(), 'Previous');
  // Next names the last ticket on this page, so page 2 can tell whether the list moved in between.
  await goes(page, () => page.getByRole('link', { name: 'Next', exact: true }).click(), '/?view=open&page=2&after=103');
  assert.deepEqual(errors, []);
});

test('the page says what the view leaves out, and the count is a link', async (t) => {
  const { page, errors } = await fixture(t);
  assert.equal(await page.locator('.queue-elsewhere').innerText(), 'Not in this view: 40 resolved.');
  await goes(page, () => page.getByRole('link', { name: '40 resolved', exact: true }).click(), '/?view=resolved');
  assert.deepEqual(errors, []);
});

test('the columns line up down the list, and a row says who asked and whose turn it is', async (t) => {
  const { page, errors } = await fixture(t);
  for (const column of ['.trow-status', '.trow-tech', '.trow-sla', '.trow-age']) {
    const lefts = await page.locator(`.trow ${column}`).evaluateAll((cells) => cells.map((cell) => Math.round(cell.getBoundingClientRect().left)));
    assert.equal(lefts.length, 4, `${column}: a header and three rows`);
    assert.equal(new Set(lefts).size, 1, `${column} starts at the same place on every row: ${lefts.join(', ')}`);
  }
  assert.deepEqual(await page.locator('.trow:not(.trow-head) .trow-origin').allInnerTexts(), [
    'Fixture <Dental>, by email from Dana Whitfield',
    'Fixture <Dental>, from the portal by Priya Anand',
    'Fixture <Dental>, from a Milepost alert',
  ]);
  assert.deepEqual(await page.locator('.trow[data-ticket-id="102"] .trow-flag').allInnerTexts(), ['Customer replied']);
  assert.equal(await page.locator('.trow-flag').count(), 1);
  assert.equal(await page.locator('.page-sub').innerText(), '131 open tickets. 1 is waiting for a first reply, and it is overdue.');
  assert.equal(await fits(page), true);
  assert.deepEqual(errors, []);
});

for (const theme of ['dark', 'light']) {
  test(`at phone width the queue fits, the views stay on one line, and controls are 44px (${theme})`, async (t) => {
    const { page, errors } = await fixture(t, { width: 390, theme });
    assert.equal(await fits(page), true);
    const views = await page.locator('nav[aria-label="Queue views"]').evaluate((nav) => ({
      height: nav.getBoundingClientRect().height, scrolls: nav.scrollWidth > nav.clientWidth,
      tops: [...nav.querySelectorAll('a')].map((a) => Math.round(a.getBoundingClientRect().top)),
    }));
    assert.equal(new Set(views.tops).size, 1, 'the six views sit on one line');
    assert.equal(views.scrolls, true, 'and scroll sideways');
    const tall = async (locator, name) => {
      const heights = await locator.evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().height));
      assert.ok(heights.length > 0, `${name}: none found`);
      for (const height of heights) assert.ok(height >= 44, `${name} is ${height}px tall`);
    };
    await tall(page.locator('nav[aria-label="Queue views"] a'), 'a view');
    await tall(page.locator('.filter-select'), 'a filter');
    await tall(page.locator('.pager a'), 'Next');
    await tall(page.locator('.queue-elsewhere a'), 'a link to what the view leaves out');
    await tall(page.getByRole('button', { name: 'Select', exact: true }), 'Select');
    assert.equal(await page.getByRole('checkbox', { name: 'Select ticket #101' }).isVisible(), false);
    await shot(page, `queue-390-${theme}`);

    await page.getByRole('button', { name: 'Select', exact: true }).click();
    assert.equal(await page.getByRole('checkbox', { name: 'Select ticket #101' }).isVisible(), true);
    await page.getByRole('checkbox', { name: 'Select ticket #101' }).check();
    assert.equal(await fits(page), true);
    await shot(page, `queue-select-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}

test('light theme at desktop width, with the keyboard highlight and select mode (for the eye)', async (t) => {
  const { page, errors } = await fixture(t, { theme: 'light' });
  await page.keyboard.press('j');
  await page.keyboard.press('j');
  await shot(page, 'queue-1440-light');
  await page.keyboard.press('x');
  assert.deepEqual(await highlighted(page), ['102']);
  assert.equal(await page.getByRole('checkbox', { name: 'Select ticket #102' }).isChecked(), true);
  await shot(page, 'queue-select-1440-light');
  assert.deepEqual(errors, []);
});
