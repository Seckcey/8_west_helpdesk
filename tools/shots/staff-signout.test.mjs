import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Signing out on the real account menu, app.js, westy.js and launcher; the endpoint is a stub. Every way
// in posts a token, none of them loads the address, and each one clears Westy's transcript.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-staff-signout-'));
const rendered = spawnSync('php', ['app/tests/suite_chrome_test.php'], {
  cwd: root, env: { ...process.env, CUSTOMER_UI_CAPTURE_DIR: scratch }, encoding: 'utf8',
});
assert.equal(rendered.status, 0, rendered.stdout + rendered.stderr);
const chrome = await readFile(path.join(scratch, 'staff-tech-light.html'), 'utf8');
await rm(scratch, { recursive: true, force: true });

const STORE = 'sh.westy.7';
const WESTY = `<div id="westy-root" data-csrf="westy-token" data-onboarded="1" data-name="Synthetic" data-uid="7">
  <button type="button" id="westy-bubble" aria-expanded="false" aria-controls="westy-panel" aria-label="Ask Westy"></button>
  <section id="westy-panel" hidden aria-label="Chat with Westy">
    <button type="button" id="westy-close" aria-label="Close chat">&times;</button>
    <div class="westy-log" id="westy-log" aria-live="polite"></div>
    <form id="westy-form" autocomplete="off"><input id="westy-input" required><button id="westy-send" type="submit">Send</button></form>
  </section>
</div>`;
// One queue row, so Enter has somewhere else it could go: app.js opens the highlighted row on Enter.
const PAGE = chrome
  .replace('<body>', '<body data-active="queue" data-tenant-id="1" data-user-id="7" data-csrf="page-token">')
  .replace('</main>', '<a class="trow" href="/ticket.php?id=1" data-ticket-id="1">Synthetic ticket</a></main>')
  .replace('</body>', `<div id="palette-root"></div><div id="toasts" class="toasts"></div>${WESTY}
<script id="palette-data" type="application/json">{"tickets":[],"clients":[]}</script>
<script src="/assets/js/app.js" defer></script><script src="/assets/js/westy.js" defer></script></body>`);

const browser = await chromium.launch({ headless: true });

async function fixture(t) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  t.after(() => context.close());
  const page = await context.newPage();
  const errors = [];
  const hits = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: PAGE });
    if (url.pathname === '/logout.php') {
      hits.push({ method: request.method(), body: request.postData() });
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>Signed out</title>' });
    }
    if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
      const file = path.join(root, 'app/public', url.pathname);
      const types = { '.css': 'text/css', '.js': 'text/javascript' };
      return route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) });
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto('http://safeharbor.test/');
  await page.waitForFunction(() => document.querySelector('.trow.trow-sel') !== null);
  const transcript = () => page.evaluate((key) => sessionStorage.getItem(key), STORE);
  await page.evaluate((key) => sessionStorage.setItem(key, JSON.stringify({ open: false, h: [{ role: 'user', content: 'private question' }] })), STORE);
  assert.notEqual(await transcript(), null);
  return { page, errors, hits, transcript };
}

async function signedOut({ page, errors, hits, transcript }, body) {
  await page.waitForURL('**/logout.php');
  assert.deepEqual(hits, [{ method: 'POST', body }]);
  assert.equal(await transcript(), null);
  assert.deepEqual(errors, []);
}

try {
  await test('the ⌘K action posts the page token and clears the transcript', async (t) => {
    const signOut = await fixture(t);
    await signOut.page.keyboard.press('Control+k');
    await signOut.page.locator('.palette-input').fill('Sign out');
    await signOut.page.keyboard.press('Enter');
    await signedOut(signOut, 'csrf=page-token');
  });

  await test('clicking the account menu item posts its token and clears the transcript', async (t) => {
    const signOut = await fixture(t);
    await signOut.page.getByRole('button', { name: 'Account menu for Synthetic Technician' }).click();
    await signOut.page.getByRole('menuitem', { name: 'Sign out', exact: true }).click();
    await signedOut(signOut, 'csrf=synthetic-csrf');
  });

  await test('Enter on the focused menu item signs out, not the highlighted queue row', async (t) => {
    const signOut = await fixture(t);
    await signOut.page.getByRole('button', { name: 'Account menu for Synthetic Technician' }).click();
    await signOut.page.keyboard.press('ArrowUp');
    assert.equal(await signOut.page.getByRole('menuitem', { name: 'Sign out', exact: true }).evaluate((el) => el === document.activeElement), true);
    await signOut.page.keyboard.press('Enter');
    await signedOut(signOut, 'csrf=synthetic-csrf');
  });

  await test('Space on the focused menu item signs out as well', async (t) => {
    const signOut = await fixture(t);
    await signOut.page.getByRole('button', { name: 'Account menu for Synthetic Technician' }).click();
    await signOut.page.keyboard.press('ArrowUp');
    await signOut.page.keyboard.press('Space');
    await signedOut(signOut, 'csrf=synthetic-csrf');
  });

  await test('sending Westy a message is not mistaken for signing out', async (t) => {
    const { page, errors, hits, transcript } = await fixture(t);
    await page.evaluate(() => document.getElementById('westy-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.notEqual(await transcript(), null);
    assert.deepEqual(hits, []);
    assert.deepEqual(errors, []);
  });
} finally {
  await browser.close();
}
