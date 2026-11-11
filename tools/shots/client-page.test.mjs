import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm, mkdir } from 'node:fs/promises';
import { execFileSync, spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// The client page as the customer's whole picture: head, note, number tiles, Open / Resolved, contacts,
// and the way out to Milepost and Coastmark. The pages are the real app/public/client.php, rendered by
// app/tests/client_shared_directory_ui_test.php from synthetic rows, on the real stylesheet and script.
// The shared-directory link rules are pinned in client-shared-directory.test.mjs; this checks what a
// person sees and does. Not signed-in, provider or customer acceptance.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-client-page-'));
const rendered = spawnSync('php', ['app/tests/client_shared_directory_ui_test.php'], {
  cwd: root, env: { ...process.env, CUSTOMER_UI_CAPTURE_DIR: scratch }, encoding: 'utf8',
});
assert.equal(rendered.status, 0, rendered.stdout + rendered.stderr);
test.after(() => rm(scratch, { recursive: true, force: true }));
// That renderer stands a plain link in for each ticket row (the row needs the whole of lib/render.php).
// Here each stand-in is swapped for the real row, drawn the way the page asks: without the client's name.
const ROWS = JSON.parse(execFileSync('php', [path.join(root, 'app/tests/ticket_queue_test.php'), '--client-rows'], { encoding: 'utf8' }));
const withRows = (html) => html.replace(/<a class="trow" href="\/ticket\.php\?id=(\d+)" data-with-client="0">[^<]*<\/a>/g, (stub, id) => {
  if (!ROWS[id]) throw new Error('No real row for ticket ' + id);
  return ROWS[id];
});

const ORIGIN = 'http://safeharbor.test';
const shots = process.env.SAFEHARBOR_CLIENT_SHOTS;

/** Which rendered page an address is. */
function captureFor(url) {
  const id = url.searchParams.get('id');
  if (id === '33') return 'client-local';
  if (id === '32') return 'client-watch';
  if (id !== '30') return null;
  if (url.searchParams.get('show') !== 'resolved') return 'client-business';
  return url.searchParams.get('page') === '2' ? 'client-business-resolved2' : 'client-business-resolved';
}

async function fixture(t, { width = 1440, height = 1000, theme = 'dark' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const context = await browser.newContext({ viewport: { width, height } });
  const errors = [];
  const posts = [];
  await context.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== ORIGIN) { errors.push('Unexpected request: ' + url.href); return route.abort(); }
    if (url.pathname === '/client.php' && request.method() === 'POST') {
      posts.push({ to: url.pathname + url.search, form: Object.fromEntries(new URLSearchParams(request.postData() || '')) });
      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Posted</title><h1>Posted</h1>' });
    }
    if (url.pathname === '/client.php') {
      const name = captureFor(url);
      if (name) return route.fulfill({ contentType: 'text/html; charset=utf-8', body: withRows(await readFile(path.join(scratch, `${name}-${theme}.html`), 'utf8')) });
    }
    if (['/ticket_new.php', '/client_edit.php', '/clients.php', '/ticket.php'].includes(url.pathname)) {
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><title>Fixture</title><h1>Fixture ${url.pathname}${url.search}</h1>` });
    }
    if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
      let file = path.join(root, 'app/public', url.pathname);
      if (url.pathname === '/assets/brand/favicon.svg') file = path.join(root, 'brand/svg/favicon.svg');
      if (url.pathname === '/assets/brand/apple-touch-icon.png') file = path.join(root, 'brand/png/apple-touch-icon.png');
      if (url.pathname === '/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png') file = path.join(root, 'brand/png/safeharbor-logo-horizontal-transparent-20260909.png');
      // The production .ico is deployed with the brand kit, not kept in this repository.
      if (url.pathname === '/assets/brand/favicon.ico') return route.fulfill({ status: 204, body: '' });
      const types = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
      try { return await route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) }); }
      catch { errors.push('Missing fixture asset: ' + url.pathname); }
    }
    errors.push('Unexpected request: ' + url.href);
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (['error', 'warning'].includes(message.type())) errors.push(message.text()); });
  const open = async (address) => {
    await page.goto(ORIGIN + address);
    await page.getByRole('heading', { level: 1 }).waitFor();
  };
  return { page, errors, posts, open };
}

async function shot(page, name) {
  if (!shots) return;
  await page.waitForTimeout(200);
  await mkdir(shots, { recursive: true });
  await page.screenshot({ path: path.join(shots, `${name}.png`), fullPage: true });
}

const squash = (text) => text.replace(/\s+/g, ' ').trim();
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
const ids = (page) => page.locator('.page-client .list .trow').evaluateAll((rows) => rows.map((row) => row.dataset.ticketId));
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
/** Handle the next browser confirm the given way and return what it asked. */
async function answering(page, answer, act) {
  const asked = [];
  const handler = (dialog) => { asked.push(dialog.message()); return answer === 'ok' ? dialog.accept() : dialog.dismiss(); };
  page.on('dialog', handler);
  try { await act(); await page.waitForTimeout(300); } finally { page.off('dialog', handler); }
  return asked;
}

test('the page reads top to bottom: who they are, the note, the numbers, their tickets, their contacts', async (t) => {
  const { page, errors, open } = await fixture(t);
  await open('/client.php?id=30');
  assert.deepEqual(await texts(page, '.client-title > *'), ['Harbor Dental', 'Premium plan', 'harbordental.test']);
  assert.deepEqual(await texts(page, '.client-actions > a'), ['New ticket', 'Edit']);
  assert.equal(squash(await page.locator('.client-note').innerText()), 'Note Call the office line before 9. <b>Front desk</b> closes at 5.');
  assert.deepEqual(await texts(page, '.client-stats .stat-k'), ['Open tickets', 'First reply, 90 days', 'Billable time, 30 days']);
  assert.deepEqual(await texts(page, '.client-stats .stat-v'), ['3', '42 min', '2 h 30 min']);
  assert.deepEqual(await texts(page, '.rail > .rail-label'), ['Contacts', 'Computers and invoices']);
  // Everything the old page put in sentences where a number or a link belonged is gone.
  const words = await page.locator('.page-client').innerText();
  for (const gone of ['Not shown here', 'SLA', 'Suite', 'source version', 'Avg first response', 'Shared client:']) {
    assert.equal(words.includes(gone), false, `"${gone}" is no longer on the page`);
  }
  // The page's own markup carries no inline style. (The shared ticket row sizes its priority bars that way.)
  assert.equal(await page.locator('.page-client [style]').evaluateAll((nodes) => nodes.filter((el) => !el.closest('.trow')).length), 0);
  assert.equal(await page.locator('.page-client .health-dot').count(), 0, 'no bare coloured dot stands for health');
  // The order a keyboard reaches things in: back, New ticket, Edit, then the two lists.
  await page.locator('.backlink').focus();
  const order = [];
  for (let i = 0; i < 4; i++) { await page.keyboard.press('Tab'); order.push(await page.evaluate(() => document.activeElement.textContent.replace(/\s+/g, ' ').trim())); }
  assert.deepEqual(order, ['New ticket', 'Edit', 'Open3', 'Resolved27']);
  assert.equal(await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle !== 'none'), true, 'focus is visible');
  assert.deepEqual(errors, []);
});

test('Open and Resolved are one filter; Resolved comes a page at a time', async (t) => {
  const { page, errors, open } = await fixture(t);
  await open('/client.php?id=30');
  const tabs = page.getByRole('navigation', { name: 'This client’s tickets' });
  assert.equal(await tabs.locator('[aria-current="page"]').innerText().then(squash), 'Open 3');
  assert.deepEqual(await ids(page), ['102', '103', '101']);
  assert.deepEqual(await texts(page, '.list .trow-subject'), ['Server room is hot', 'New hygienist starts Monday', 'Front desk PC will not print']);
  // Every row is this client's, so no row repeats the client's name: the line says how it came in and who asked.
  assert.deepEqual(await texts(page, '.list .trow-origin'), ['By email from Dana Front Desk', 'From the portal by Priya Anand', 'By phone from Dana Front Desk']);
  assert.equal((await page.locator('.page-client .list').innerText()).includes('Harbor Dental'), false);
  assert.equal(await page.getByRole('navigation', { name: 'Resolved ticket pages' }).count(), 0, 'Open is one list');

  await tabs.getByRole('link', { name: /Resolved/ }).click();
  await page.waitForURL(ORIGIN + '/client.php?id=30&show=resolved');
  assert.equal(await tabs.locator('[aria-current="page"]').innerText().then(squash), 'Resolved 27');
  assert.equal((await ids(page)).length, 25);
  assert.equal((await ids(page))[0], '201');
  const pager = page.getByRole('navigation', { name: 'Resolved ticket pages' });
  assert.equal(await pager.locator('.pager-count').innerText(), 'Showing 1 to 25 of 27');
  assert.equal(await pager.getByRole('link', { name: 'Previous' }).count(), 0, 'the first page has nothing before it');
  await shot(page, 'client-resolved-1440-dark');

  await pager.getByRole('link', { name: 'Next' }).click();
  await page.waitForURL(ORIGIN + '/client.php?id=30&show=resolved&page=2');
  assert.deepEqual(await ids(page), ['226', '199']);
  assert.equal(await page.getByRole('navigation', { name: 'Resolved ticket pages' }).locator('.pager-count').innerText(), 'Showing 26 to 27 of 27');
  await page.getByRole('navigation', { name: 'Resolved ticket pages' }).getByRole('link', { name: 'Previous' }).click();
  await page.waitForURL(ORIGIN + '/client.php?id=30&show=resolved');
  await page.getByRole('navigation', { name: 'This client’s tickets' }).getByRole('link', { name: /Open/ }).click();
  await page.waitForURL(ORIGIN + '/client.php?id=30');
  assert.deepEqual(errors, []);
});

test('the key c and the New ticket button both open the form with this client chosen', async (t) => {
  const { page, errors, open } = await fixture(t);
  await open('/client.php?id=30');
  await page.keyboard.press('c');
  await page.waitForURL(ORIGIN + '/ticket_new.php?client=30');
  await open('/client.php?id=30');
  await page.getByRole('link', { name: 'New ticket', exact: true }).click();
  await page.waitForURL(ORIGIN + '/ticket_new.php?client=30');
  await open('/client.php?id=30');
  await page.getByRole('link', { name: 'Edit', exact: true }).click();
  await page.waitForURL(ORIGIN + '/client_edit.php?id=30');
  assert.deepEqual(errors, []);
});

test('Remove sits behind More, asks first, and Cancel sends nothing', async (t) => {
  const { page, errors, posts, open } = await fixture(t);
  await open('/client.php?id=30');
  const row = page.locator('.contact-row').filter({ hasText: 'Dana Front Desk' });
  assert.equal(squash(await row.locator('.contact-main').innerText()), 'Dana Front Desk dana@harbordental.test');
  const remove = row.getByRole('button', { name: 'Remove Dana Front Desk' });
  assert.equal(await remove.isVisible(), false, 'Remove is not on the row until More is opened');
  // By keyboard: More is a real control, Enter opens it, Tab reaches Remove.
  const more = row.locator('summary');
  await more.focus();
  assert.equal(await more.evaluate((el) => getComputedStyle(el).outlineStyle !== 'none'), true);
  await page.keyboard.press('Enter');
  assert.equal(await remove.isVisible(), true);
  assert.equal(await remove.innerText(), 'Remove');
  await page.keyboard.press('Tab');
  assert.equal(await remove.evaluate((el) => el === document.activeElement), true);
  assert.deepEqual(await answering(page, 'cancel', () => page.keyboard.press('Enter')), ['Remove Dana Front Desk?']);
  assert.deepEqual(posts, [], 'Cancel sends nothing');
  await shot(page, 'client-contact-more-1440-dark');
  assert.deepEqual(await answering(page, 'ok', () => remove.click()), ['Remove Dana Front Desk?']);
  await page.getByRole('heading', { name: 'Posted' }).waitFor();
  assert.deepEqual(posts, [{ to: '/client.php?id=30', form: { csrf: 'synthetic-csrf', action: 'remove_contact', contact_id: '1' } }]);
  assert.deepEqual(errors, []);
});

test('Add a contact opens a small form with standard fields; a name is required, an address is not', async (t) => {
  const { page, errors, posts, open } = await fixture(t);
  await open('/client.php?id=30');
  const add = page.locator('.contact-add');
  const name = add.getByLabel('Name');
  assert.equal(await name.isVisible(), false, 'the fields stay closed until asked for');
  await add.locator('summary').click();
  assert.equal(await name.isVisible(), true);
  // The standard field: at least 38px tall, with the stronger control border, not a hairline.
  const field = await name.evaluate((el) => {
    const probe = document.createElement('span');
    probe.style.color = 'var(--control-border)';
    document.body.append(probe);
    const token = getComputedStyle(probe).color;
    probe.remove();
    const style = getComputedStyle(el);
    return { height: el.getBoundingClientRect().height, border: style.borderTopColor, token, size: parseFloat(style.fontSize) };
  });
  assert.ok(field.height >= 38, JSON.stringify(field));
  assert.equal(field.border, field.token, 'the field uses the control border');
  assert.ok(field.size >= 14, JSON.stringify(field));
  await add.getByRole('button', { name: 'Add contact' }).click();
  await page.waitForTimeout(200);
  assert.deepEqual(posts, [], 'no name, nothing sent');
  await name.fill('Dr. Priya Nair');
  await shot(page, 'client-contact-add-1440-dark');
  await page.keyboard.press('Enter');
  await page.getByRole('heading', { name: 'Posted' }).waitFor();
  assert.deepEqual(posts, [{ to: '/client.php?id=30', form: { csrf: 'synthetic-csrf', action: 'add_contact', name: 'Dr. Priya Nair', email: '' } }]);
  assert.deepEqual(errors, []);
});

test('Computers and Invoices open the other apps, and the Milepost link is a quiet line with its numbers under Details', async (t) => {
  const { page, errors, open } = await fixture(t);
  await open('/client.php?id=30');
  const apps = page.locator('.rail-list').filter({ hasText: 'Computers' });
  assert.deepEqual(await texts(page, '.rail-list > .rail-line'), ['ComputersOpen this client in Milepost ↗', 'InvoicesOpen this client in Coastmark ↗']);
  assert.equal(await apps.locator('.field-help').innerText(), 'Milepost and Coastmark check your access before showing this client.');
  for (const link of await apps.getByRole('link').all()) {
    assert.equal(await link.getAttribute('target'), '_blank');
    assert.equal(await link.getAttribute('rel'), 'noopener');
  }
  const link = page.locator('.client-link');
  assert.equal(squash(await link.locator('.client-shared').first().innerText()), 'Linked to Milepost as Harbor Dental Group · active · Shared details ↗');
  assert.equal(squash(await link.locator('.client-shared').nth(1).innerText()), 'Milepost directory now: Harbor Dental Group · active');
  const details = link.locator('details');
  assert.equal(await details.getByText('Source version last received: 3.').isVisible(), false);
  await details.locator('summary').click();
  assert.equal(await details.getByText('Source version last received: 3.').isVisible(), true);
  assert.equal(await details.getByText('Source version in Milepost’s directory: 3.').isVisible(), true);
  // The quiet line is the last thing in the rail.
  assert.equal(await page.locator('.rail > :last-child').evaluate((el) => el.classList.contains('client-link')), true);

  await open('/client.php?id=33');
  assert.deepEqual(await texts(page, '.rail-list > .rail-line'), ['ComputersOpen Milepost ↗', 'InvoicesOpen Coastmark ↗']);
  assert.equal(await page.locator('.rail-list > .field-help').innerText(), 'Open the app, then select the client.');
  assert.equal(await page.locator('.client-link').innerText(), 'Not linked to Milepost. Shared clients are created in Milepost.');
  assert.deepEqual(await texts(page, '.client-stats .stat-k'), ['Open tickets', 'Billable time, 30 days'], 'no first reply to average, no tile for it');
  assert.equal(await page.locator('.list .empty p').innerText(), 'No open tickets for Email Intake.');
  assert.deepEqual(errors, []);
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: the page reads well at desktop width, and a watched client says so in words`, async (t) => {
    const { page, errors, open } = await fixture(t, { theme });
    await open('/client.php?id=30');
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
    assert.equal(await fits(page), true);
    // The rail sits beside the tickets, and the head's buttons sit at the right of the name.
    const boxes = await page.evaluate(() => {
      const box = (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom }; };
      return { list: box('.page-client .list'), rail: box('.page-client .rail'), title: box('.client-title'), actions: box('.client-actions') };
    });
    assert.ok(boxes.rail.left >= boxes.list.right, JSON.stringify(boxes));
    assert.ok(boxes.actions.left >= boxes.title.right && boxes.actions.top < boxes.title.bottom, JSON.stringify(boxes));
    for (const selector of ['.client-title .tier', '.client-domain', '.client-note', '.client-note-k', '.stat-k', '.stat-v', '.stat-hint', '.filter-pill', '.pill-count',
      '.rail-list-name', '.rail-list-sub', '.contact-add-btn', '.rail-line > span', '.rail-line > .link', '.field-help', '.client-shared', '.client-shared .link',
      '.client-shared span', '.client-link-details > summary']) {
      const ratios = await contrast(page, selector);
      assert.ok(ratios.length > 0, selector);
      for (const ratio of ratios) assert.ok(ratio >= 4.5, `${selector} contrast ${ratio.toFixed(2)} in ${theme}`);
    }
    await shot(page, `client-1440-${theme}`);

    await open('/client.php?id=32');
    assert.deepEqual(await texts(page, '.client-title > *'), ['Copper Kettle', 'Standard plan', 'Needs attention', 'copperkettle.test']);
    for (const ratio of await contrast(page, '.tag-caution')) assert.ok(ratio >= 4.5, `Needs attention contrast ${ratio.toFixed(2)} in ${theme}`);
    assert.equal(squash(await page.locator('.client-shared').first().innerText()), 'Linked to Milepost as Copper Kettle · inactive · Shared details ↗');
    await shot(page, `client-watch-1440-${theme}`);
    assert.deepEqual(errors, []);
  });

  test(`${theme}: at phone width nothing overflows and everything pressable is at least 44px tall`, async (t) => {
    const { page, errors, open } = await fixture(t, { width: 390, height: 844, theme });
    await open('/client.php?id=30');
    assert.equal(await fits(page), true);
    await shot(page, `client-390-${theme}`);
    await open('/client.php?id=30&show=resolved');
    assert.equal(await fits(page), true);
    await page.locator('.contact-more > summary').first().click();
    await page.locator('.contact-add > summary').click();
    await page.locator('.client-link-details > summary').click();
    const small = await page.locator('.page-client').evaluate((root) => [...root.querySelectorAll('a, button, summary, input:not([type="hidden"])')]
      .filter((el) => el.offsetParent !== null)
      .map((el) => ({ what: (el.textContent.trim() || el.getAttribute('aria-label') || el.name || el.tagName).replace(/\s+/g, ' ').slice(0, 40), height: Math.round(el.getBoundingClientRect().height) }))
      .filter((item) => item.height < 44));
    assert.deepEqual(small, []);
    assert.equal(await fits(page), true, 'still nothing overflows with every disclosure open');
    // One column: the head's buttons under the name, the rail under the tickets.
    const boxes = await page.evaluate(() => {
      const box = (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return { top: r.top, bottom: r.bottom, width: r.width }; };
      return { title: box('.client-title'), actions: box('.client-actions'), list: box('.page-client .list'), rail: box('.page-client .rail') };
    });
    assert.ok(boxes.actions.top >= boxes.title.bottom && boxes.rail.top >= boxes.list.bottom, JSON.stringify(boxes));
    await page.locator('.page-client .rail').scrollIntoViewIfNeeded();
    await shot(page, `client-rail-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}
