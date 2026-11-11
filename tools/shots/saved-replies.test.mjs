import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-saved-replies-'));
execFileSync('php', ['app/tests/saved_replies_test.php'], { cwd: root, env: { ...process.env, SAVED_REPLIES_CAPTURE_DIR: scratch }, stdio: ['ignore', 'pipe', 'pipe'] });
const browser = await chromium.launch({ headless: true });
after(async () => { await browser.close(); await rm(scratch, { recursive: true, force: true }); });
const origin = 'http://safeharbor.test';
const shots = process.env.SAVED_REPLIES_SHOTS;
const css = await readFile(path.join(root, 'app/public/assets/css/app.css'), 'utf8');
const js = await readFile(path.join(root, 'app/public/assets/js/app.js'), 'utf8');

async function open(t, { state = 'library', width = 1440, theme = 'dark', javaScriptEnabled = true, ticket = null } = {}) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, javaScriptEnabled });
  t.after(() => context.close());
  const page = await context.newPage(), posts = [], errors = [];
  page.setDefaultTimeout(5000);
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (request.method() === 'POST') {
      posts.push({ path: url.pathname, body: Object.fromEntries(new URLSearchParams(request.postData())) });
      return route.fulfill({ contentType: 'text/html', body: '<h1>Posted</h1>' });
    }
    if (url.pathname === '/snippets.php') {
      const chosen = url.searchParams.has('new') ? 'new' : (url.searchParams.get('edit') === '11' ? 'edit' : state);
      const html = (await readFile(path.join(scratch, `${chosen}.html`), 'utf8')).replace('var t="light"', `var t="${theme}"`);
      return route.fulfill({ contentType: 'text/html', body: html });
    }
    if (ticket !== null && url.pathname === '/ticket.php') {
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html data-theme="${theme}"><head><style>${css}</style></head><body data-active="queue" data-user-id="7" data-tenant-id="1" data-csrf="synthetic-csrf"><main class="page"><div id="thread" data-ticket-id="1042"><div class="reply"><form id="reply-form"><input type="hidden" id="composer-mode" value="reply"><textarea id="reply-box" aria-label="Reply"></textarea><button type="button" id="canned-open">Saved replies</button><div id="canned-pop" hidden></div><button type="submit">Send reply</button></form></div></div></main><script id="canned-data" type="application/json">${JSON.stringify({ snippets: ticket, merge: { 'contact.first_name': 'Customer' } }).replaceAll('<', '\\u003c')}</script><script>${js}</script></body></html>` });
    }
    if (url.pathname.startsWith('/assets/')) {
      let file = path.join(root, 'app/public', url.pathname);
      if (url.pathname.startsWith('/assets/brand/')) {
        const name = path.basename(url.pathname); file = path.join(root, 'brand', name.endsWith('.svg') ? 'svg' : 'png', name);
      }
      try { return route.fulfill({ contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png' })[path.extname(file)] || 'application/octet-stream', body: await readFile(file) }); }
      catch { return route.fulfill({ status: 204, body: '' }); }
    }
    if (url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true,"others":[]}' });
    return route.fulfill({ status: 204, body: '' });
  });
  await page.goto(origin + (ticket === null ? '/snippets.php' : '/ticket.php?id=1042'));
  await page.waitForLoadState('load');
  return { page, posts, errors };
}
async function shot(page, name) {
  if (!shots) return;
  await mkdir(shots, { recursive: true });
  await page.screenshot({ path: path.join(shots, `${name}.png`), fullPage: true });
}

for (const theme of ['light', 'dark']) for (const width of [1440, 390]) {
  test(`saved reply library and editor: ${theme} ${width}`, async t => {
    const { page, posts, errors } = await open(t, { theme, width });
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
    assert.equal(await page.locator('.saved-reply-row').count(), 3);
    assert.equal(await page.locator('#saved-reply-editor').isVisible(), false);
    assert.equal(await page.locator('.snippet-body').first().innerText(), 'Hi {contact.first_name},');
    assert.equal(await page.locator('img[src="x"]').count(), 0);
    await shot(page, `library-${theme}-${width}`);
    await page.getByRole('link', { name: 'Edit Connection check', exact: true }).click();
    assert.equal(await page.locator('#saved-reply-form input[name=title]').inputValue(), 'Connection check');
    assert.match(await page.locator('[data-reply-preview]').innerText(), /Hi Customer,\nPlease check the cable at Example client.\nTicket #1042\nTeammate/);
    assert.equal(await page.locator('#saved-reply-form input[name=action]').inputValue(), 'edit');
    assert.equal(await page.locator('#saved-reply-form input[name=snippet_id]').inputValue(), '11');
    assert.equal(await page.locator('main.content').evaluate(el => el.scrollWidth <= el.clientWidth + 1), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    for (const control of await page.locator('#saved-reply-editor button, #saved-reply-editor .btn-secondary, #saved-reply-editor input:not([type=hidden])').all()) {
      assert.ok((await control.boundingBox()).height >= (width < 700 ? 44 : 36));
    }
    await page.locator('#saved-reply-editor').scrollIntoViewIfNeeded();
    await shot(page, `editor-${theme}-${width}`);
    await page.getByRole('link', { name: 'Cancel', exact: true }).click();
    assert.equal(await page.locator('#saved-reply-editor').isVisible(), false);
    assert.equal(await page.getByRole('link', { name: 'Edit Connection check', exact: true }).evaluate(el => el === document.activeElement), true);
    assert.deepEqual(posts, []); assert.deepEqual(errors, []);
  });
}

test('new reply, field buttons and preview keep literal text, the cursor and the existing add POST', async t => {
  const { page, posts, errors } = await open(t);
  await page.getByRole('link', { name: 'New saved reply', exact: true }).click();
  const form = page.locator('#saved-reply-form'), body = form.locator('textarea');
  await form.locator('[name=title]').fill('A new reply');
  await body.fill('Before REPLACE after <img src=x onerror="window.replyInjected=1"> {unknown.field}');
  await body.evaluate(el => el.setSelectionRange(7, 14));
  await page.getByRole('button', { name: 'Customer first name', exact: true }).click();
  assert.equal(await body.inputValue(), 'Before {contact.first_name} after <img src=x onerror="window.replyInjected=1"> {unknown.field}');
  assert.match(await page.locator('[data-reply-preview]').innerText(), /^Before Customer after <img/);
  assert.match(await page.locator('[data-reply-preview]').innerText(), /\{unknown.field\}$/);
  for (const [label, field] of [['Client name', 'client.name'], ['Ticket number', 'ticket.id'], ['Your first name', 'tech.first_name']]) {
    await body.evaluate(el => el.setSelectionRange(el.value.length, el.value.length));
    await page.getByRole('button', { name: label, exact: true }).click();
    assert.ok((await body.inputValue()).endsWith(`{${field}}`));
    assert.equal(await body.evaluate(el => document.activeElement === el), true);
  }
  assert.equal(await page.evaluate(() => window.replyInjected), undefined);
  const expectedBody = await body.inputValue();
  await page.getByRole('button', { name: 'Add saved reply', exact: true }).click();
  await page.waitForURL('**/snippets.php');
  await page.getByRole('heading', { name: 'Posted' }).waitFor();
  assert.deepEqual(posts, [{ path: '/snippets.php', body: { csrf: 'synthetic-csrf', action: 'add', snippet_id: '0', title: 'A new reply', body: expectedBody } }]);
  assert.deepEqual(errors, []);
});

test('edit submits the selected ID and preserves multiline content', async t => {
  const { page, posts } = await open(t);
  await page.getByRole('link', { name: 'Edit Printer steps', exact: true }).click();
  await page.locator('#saved-reply-form textarea').fill('First line\nSecond line');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.getByRole('heading', { name: 'Posted' }).waitFor();
  assert.equal(posts.length, 1); assert.equal(posts[0].body.snippet_id, '12'); assert.equal(posts[0].body.action, 'edit');
  assert.equal(posts[0].body.body.replaceAll('\r\n', '\n'), 'First line\nSecond line');
  assert.equal(posts[0].body.csrf, 'synthetic-csrf');
});

test('empty, invalid and restricted pages explain the next step without exposing edit', async t => {
  const empty = await open(t, { state: 'empty', width: 390 });
  assert.match(await empty.page.locator('.empty').innerText(), /No saved replies yet. Save the answers you write most often./);
  assert.equal(await empty.page.getByRole('link', { name: 'New saved reply', exact: true }).count(), 1);
  await shot(empty.page, 'empty-dark-390');
  const invalid = await open(t, { state: 'error', theme: 'light' });
  assert.equal(await invalid.page.locator('#saved-reply-form textarea').inputValue(), 'Unsaved draft');
  assert.equal(await invalid.page.locator('#saved-reply-editor').isVisible(), true);
  await shot(invalid.page, 'invalid-light-1440');
  const tech = await open(t, { state: 'tech' });
  assert.equal(await tech.page.getByRole('link', { name: 'Edit Printer steps', exact: true }).count(), 0);
  assert.equal(await tech.page.getByText('Printer steps', { exact: true }).count(), 1);
});

test('new and edit links work without JavaScript', async t => {
  const { page } = await open(t, { javaScriptEnabled: false });
  await page.getByRole('link', { name: 'Edit Connection check', exact: true }).click();
  assert.equal(await page.locator('#saved-reply-editor').isVisible(), true);
  assert.equal(await page.locator('#saved-reply-form input[name=action]').inputValue(), 'edit');
  await page.getByRole('link', { name: 'Cancel', exact: true }).click();
  await page.getByRole('link', { name: 'New saved reply', exact: true }).click();
  assert.equal(await page.locator('#saved-reply-editor').isVisible(), true);
  assert.equal(await page.locator('#saved-reply-form input[name=action]').inputValue(), 'add');
});

for (const snippets of [[], [{ id: 11, title: 'Connection check', body: 'Hi {contact.first_name}' }]]) {
  test(`composer management link stays reachable by keyboard (${snippets.length} replies)`, async t => {
    const { page, posts, errors } = await open(t, { ticket: snippets });
    await page.getByRole('button', { name: 'Saved replies', exact: true }).click();
    const manage = page.getByRole('link', { name: 'Manage saved replies', exact: true });
    await manage.focus(); await page.waitForTimeout(250);
    assert.equal(await manage.isVisible(), true);
    await page.keyboard.press('Enter'); await page.waitForURL('**/snippets.php');
    assert.deepEqual(posts, []); assert.deepEqual(errors, []);
  });
}
test('composer insertion still fills the ticket values without sending anything', async t => {
  const { page, posts } = await open(t, { ticket: [{ id: 11, title: 'Connection check', body: 'Hi {contact.first_name}' }] });
  await page.getByRole('button', { name: 'Saved replies', exact: true }).click();
  await page.locator('.canned-item').click();
  assert.equal(await page.locator('#reply-box').inputValue(), 'Hi Customer');
  assert.equal(await page.locator('#canned-pop').isVisible(), false); assert.deepEqual(posts, []);
});
