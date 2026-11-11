import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Reports: one period for the whole page, five tiles that say how they moved, the team as a table,
// the tickets open longest and time by client, on the real stylesheet and script. The pieces come
// from the real helper (app/tests/reports_page_test.php --render); the sums behind them are checked
// there and, against MySQL, in ticket_queue_http_test.php. This checks what a person sees and does.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const PIECES = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/reports_page_test.php'), '--render'], { encoding: 'utf8' }));
const ORIGIN = 'http://safeharbor.test';

// The page as public/reports.php lays it out. days: 7, 30, 90 or 'quiet' (a period with nothing in it).
const html = (theme, key, owner) => {
  const piece = PIECES[key];
  const days = key === 'quiet' ? 30 : key;
  return `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reports</title><style>${CSS}</style></head>
<body data-active="reports" data-tenant-id="7" data-user-id="11" data-csrf="fixture"><main class="content"><div class="page page-reports">
<div class="page-head"><div><h1 class="page-title">Reports</h1><p class="page-sub">${piece.sub}</p></div>
<div class="page-actions">${piece.picker}${owner ? `<a class="btn-secondary" href="/reports_export.php?days=${days}" title="Approved billable time in the last ${days} days, as a CSV file">Export time</a>` : ''}</div></div>
${piece.tiles}
<h2 class="rail-label">By technician</h2>${piece.team}
<div class="report-grid"><div><h2 class="rail-label">Open longest</h2>${piece.longest}</div><div><h2 class="rail-label">Time by client</h2>${piece.clients}</div></div>
</div></main>
<div id="palette-root"></div><div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<script id="palette-data" type="application/json">{"tickets":[],"clients":[]}</script><script>${JS}</script></body></html>`;
};

// width 1200 stands for a desktop: it is about what the page gets beside the 248px sidebar at 1440.
async function fixture(t, { width = 1200, height = 900, theme = 'dark', owner = true, quiet = false } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const context = await browser.newContext({ viewport: { width, height } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (['error', 'warning'].includes(message.type())) errors.push(message.text()); });
  await page.route(`${ORIGIN}/**`, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
    if (url.pathname !== '/reports.php') {
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><title>Fixture</title><h1>Fixture ${url.pathname}${url.search}</h1>` });
    }
    const asked = url.searchParams.get('days');
    const key = quiet ? 'quiet' : (['7', '90'].includes(asked) ? asked : '30');
    return route.fulfill({ contentType: 'text/html', body: html(theme, key, owner) });
  });
  const open = async (address = '/reports.php') => {
    await page.goto(ORIGIN + address);
    await page.waitForFunction(() => document.readyState === 'complete');
  };
  await open();
  return { page, errors, open };
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_REPORTS_SHOTS) return;
  await page.waitForTimeout(200);
  await mkdir(process.env.SAFEHARBOR_REPORTS_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_REPORTS_SHOTS, `${name}.png`), fullPage: true });
}

const squash = (text) => text.replace(/\s+/g, ' ').trim();
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
const texts = (page, selector) => page.locator(selector).evaluateAll((nodes) => nodes.map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
const contrast = (page, selector) => page.locator(selector).evaluateAll((nodes) => nodes.filter((el) => el.offsetParent !== null).map((el) => {
  const rgba = (value) => { const n = (value.match(/[\d.]+/g) || []).map(Number); return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 }; };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  const layers = [];
  for (let node = el; node; node = node.parentElement) layers.push(rgba(getComputedStyle(node).backgroundColor));
  const behind = layers.reverse().reduce((under, top) => over(top, under), { r: 255, g: 255, b: 255, a: 1 });
  const luminance = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const [hi, lo] = [luminance(over(rgba(getComputedStyle(el).color), behind)), luminance(behind)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}));

test('the page answers "how did the desk do": one period, five numbers, the team, then two lists', async (t) => {
  const { page, errors } = await fixture(t);
  assert.equal(await page.locator('.page-sub').innerText(), 'How the desk did in the last 30 days.');
  assert.deepEqual(await texts(page, '.report-stats .stat-k'), ['New tickets', 'Resolved', 'First reply (median)', 'On time', 'Rated Great']);
  assert.deepEqual(await texts(page, '.report-stats .stat-v'), ['14', '12', '42 min', '86%', '3 of 4']);
  // Five tiles in one row: no tile left on a line of its own.
  const tops = await page.locator('.report-stats .stat').evaluateAll((tiles) => tiles.map((tile) => Math.round(tile.getBoundingClientRect().top)));
  assert.equal(new Set(tops).size, 1, JSON.stringify(tops));
  // Each number says how it moved against the period before.
  assert.deepEqual(await texts(page, '.report-stats .stat:nth-child(1) .stat-hint'), ['3 more than the 30 days before']);
  assert.deepEqual(await texts(page, '.report-stats .stat:nth-child(3) .stat-hint'), ['Over 12 tickets', '8 min quicker than the 30 days before']);
  assert.deepEqual(await texts(page, '.report-stats .stat:nth-child(4) .stat-hint'), ['19 of 22 first replies', '4 points down on the 30 days before']);
  // The meter under On time is 6px tall and as full as the number says.
  const meter = await page.locator('.stat-meter').evaluate((el) => ({ height: el.getBoundingClientRect().height, value: el.value, max: el.max, label: el.getAttribute('aria-label') }));
  assert.deepEqual(meter, { height: 6, value: 86, max: 100, label: '86% of first replies on time' });
  assert.deepEqual(await texts(page, '.page-reports h2'), ['By technician', 'Open longest', 'Time by client']);
  assert.deepEqual(await texts(page, '.report-table thead th'), ['Technician', 'Open now', 'Resolved', 'First reply', 'On time', 'Billable time']);
  assert.deepEqual(await texts(page, '.report-table tbody th'), ['ARAna Ruiz', 'MTMarcus <Tran>', 'JPJo Park', 'WWesty', '—Unassigned']);
  // The old page's words for an auditor are gone.
  const words = await page.locator('.page-reports').innerText();
  for (const gone of ['Real numbers', 'this week', 'Avg first response', 'attainment', 'CSAT', '(30d)', 'effective approved', 'decided tickets', 'correction slip', 'ticket aging']) {
    assert.equal(words.includes(gone), false, `"${gone}" is no longer on the page`);
  }
  // The page's own markup carries no inline style. (The shared avatar is sized and coloured that way.)
  assert.equal(await page.locator('.page-reports [style]:not(.avatar)').count(), 0);
  assert.deepEqual(errors, []);
});

test('the period is one choice for the whole page, and the export follows it', async (t) => {
  const { page, errors } = await fixture(t);
  const picker = page.getByRole('navigation', { name: 'Period' });
  assert.equal(await picker.locator('[aria-current="page"]').innerText(), 'Last 30 days');
  assert.equal(await page.getByRole('link', { name: 'Export time' }).getAttribute('href'), '/reports_export.php?days=30');
  await picker.getByRole('link', { name: 'Last 7 days' }).click();
  await page.waitForURL(ORIGIN + '/reports.php?days=7');
  assert.equal(await page.getByRole('navigation', { name: 'Period' }).locator('[aria-current="page"]').innerText(), 'Last 7 days');
  assert.equal(await page.locator('.page-sub').innerText(), 'How the desk did in the last 7 days.');
  assert.deepEqual(await texts(page, '.report-stats .stat:nth-child(2) .stat-hint'), ['1 fewer than the 7 days before']);
  assert.equal(await page.getByRole('link', { name: 'Export time' }).getAttribute('href'), '/reports_export.php?days=7');
  await page.getByRole('navigation', { name: 'Period' }).getByRole('link', { name: 'Last 90 days' }).click();
  await page.waitForURL(ORIGIN + '/reports.php?days=90');
  assert.equal(await page.locator('.report-table caption').innerText().catch(() => ''), 'The team in the last 90 days');
  await page.getByRole('navigation', { name: 'Period' }).getByRole('link', { name: 'Last 30 days' }).click();
  await page.waitForURL(ORIGIN + '/reports.php');
  assert.deepEqual(errors, []);
});

test('a number with a list behind it opens that list; by keyboard, in reading order', async (t) => {
  const { page, errors, open } = await fixture(t);
  assert.deepEqual(await page.locator('.report-table td a').evaluateAll((links) => links.map((a) => [a.textContent, a.getAttribute('href')])),
    [['4', '/?view=open&owner=11'], ['2', '/?view=open&owner=12'], ['3', '/?view=unassigned']]);
  // Period, export, then the table's links, then the two lists.
  await page.getByRole('link', { name: 'Last 7 days' }).focus();
  const order = [];
  for (let i = 0; i < 7; i++) { await page.keyboard.press('Tab'); order.push(await page.evaluate(() => document.activeElement.textContent.replace(/\s+/g, ' ').trim())); }
  assert.deepEqual(order, ['Last 30 days', 'Last 90 days', 'Export time', '4', '2', '3', '#108 Quarterly phishing <refresher>Copper Kettle Bakery, Ana RuizOpened 2 days ago']);
  assert.equal(await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle !== 'none'), true, 'focus is visible');
  await page.keyboard.press('Enter');
  await page.waitForURL(ORIGIN + '/ticket.php?id=108');
  await open();
  await page.locator('.report-table td a').first().click();
  await page.waitForURL(ORIGIN + '/?view=open&owner=11');
  await open();
  await page.getByRole('link', { name: /Copper Kettle Bakery 1 h 15 min before corrections/ }).click();
  await page.waitForURL(ORIGIN + '/client.php?id=32');
  assert.deepEqual(errors, []);
});

test('someone who cannot export sees no Export time; a quiet period still has five tiles, in words', async (t) => {
  const tech = await fixture(t, { owner: false });
  assert.equal(await tech.page.getByRole('link', { name: 'Export time' }).count(), 0);
  assert.deepEqual(tech.errors, []);
  const { page, errors } = await fixture(t, { quiet: true });
  assert.deepEqual(await texts(page, '.report-stats .stat-v'), ['0', '0', 'No replies', 'Nothing due', 'No ratings']);
  assert.equal(await page.locator('.stat-meter').count(), 0);
  assert.equal(await page.locator('.report-grid .empty').first().innerText(), 'No open tickets.');
  assert.equal(await page.locator('.report-grid .empty').nth(1).innerText(), 'No approved billable time in the last 30 days.');
  assert.equal(await fits(page), true);
  await shot(page, 'reports-quiet-desktop-dark');
  assert.deepEqual(errors, []);
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: the page reads well at desktop width`, async (t) => {
    const { page, errors } = await fixture(t, { theme });
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
    assert.equal(await fits(page), true);
    for (const selector of ['.page-sub', '.filter-pill', '.stat-k', '.stat-v', '.stat-hint', '.report-table thead th', '.report-table tbody th', '.report-table td',
      '.report-table td .link', '.report-of', '.report-none', '.page-note', '.report-row-title', '.report-row-sub', '.report-row-value', '.page-reports h2']) {
      const ratios = await contrast(page, selector);
      assert.ok(ratios.length > 0, selector);
      for (const ratio of ratios) assert.ok(ratio >= 4.5, `${selector} contrast ${ratio.toFixed(2)} in ${theme}`);
    }
    // Numbers line up in their columns: every cell of a column ends at the same edge.
    const edges = await page.locator('.report-table tbody tr').evaluateAll((rows) => rows.map((row) => [...row.querySelectorAll('td')].map((cell) => Math.round(cell.getBoundingClientRect().right))));
    for (const row of edges) assert.deepEqual(row, edges[0]);
    await shot(page, `reports-desktop-${theme}`);
    assert.deepEqual(errors, []);
  });

  test(`${theme}: at phone width nothing overflows, the table reads as cards, and every link is at least 44px tall`, async (t) => {
    const { page, errors } = await fixture(t, { width: 390, height: 844, theme });
    assert.equal(await fits(page), true);
    // Two tiles a row.
    const lefts = await page.locator('.report-stats .stat').evaluateAll((tiles) => tiles.map((tile) => Math.round(tile.getBoundingClientRect().left)));
    assert.equal(new Set(lefts).size, 2, JSON.stringify(lefts));
    // Each number sits beside its column's name, and an empty cell takes no room.
    const first = await page.locator('.report-table tbody tr').first().locator('td').evaluateAll((cells) => cells.map((cell) => getComputedStyle(cell, '::before').content.replace(/"/g, '') + ': ' + cell.textContent.replace(/\s+/g, ' ').trim()));
    assert.deepEqual(first, ['Open now: 4', 'Resolved: 7', 'First reply: 31 min', 'On time: 92% 11 of 12', 'Billable time: 6 h 10 min']);
    assert.equal(await page.locator('.report-table tbody tr').nth(3).locator('td[data-label="Billable time"]').isVisible(), false);
    const small = await page.locator('.page-reports').evaluate((root) => [...root.querySelectorAll('a, button, summary')]
      .filter((el) => el.offsetParent !== null)
      .map((el) => ({ what: el.textContent.replace(/\s+/g, ' ').trim().slice(0, 40), height: Math.round(el.getBoundingClientRect().height) }))
      .filter((item) => item.height < 44));
    assert.deepEqual(small, []);
    await shot(page, `reports-390-${theme}`);
    await page.locator('.report-table').scrollIntoViewIfNeeded();
    await shot(page, `reports-team-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}
