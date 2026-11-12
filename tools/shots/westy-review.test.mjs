import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-westy-review-'));
execFileSync('php', ['app/tests/westy_review_ui_test.php'], { cwd: root, env: { ...process.env, WESTY_REVIEW_CAPTURE_DIR: scratch } });
const browser = await chromium.launch();
after(async () => { await browser.close(); await rm(scratch, { recursive: true, force: true }); });
const origin = 'http://safeharbor.test';
const shots = process.env.WESTY_REVIEW_SHOTS;
async function open(t, state, { width = 1440, theme = 'light', js = true, hash = '' } = {}) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, javaScriptEnabled: js, timezoneId: 'UTC' });
  t.after(() => context.close());
  const page = await context.newPage(), posts = [], errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (route.request().method() === 'POST') { posts.push({ path: url.pathname, body: route.request().postData() }); return route.fulfill({ contentType: 'text/html', body: '<p>Synthetic action captured</p>' }); }
    if (url.pathname.endsWith('.php')) return route.fulfill({ contentType: 'text/html', body: (await readFile(path.join(scratch, state + '.html'), 'utf8')).replace('data-theme="light"', `data-theme="${theme}"`) });
    if (url.pathname.startsWith('/assets/')) return route.fulfill({ contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css', body: await readFile(path.join(root, 'app/public', url.pathname)) });
    return route.fulfill({ status: 204, body: '' });
  });
  await page.goto(origin + (state.startsWith('legacy') ? '/westy_email.php' : '/westy_mail.php') + '?ticket_id=1042' + hash);
  return { page, posts, errors };
}
async function shot(page, name) { if (shots) { await mkdir(shots, { recursive: true }); await page.screenshot({ path: path.join(shots, name + '.png'), fullPage: true }); } }

for (const theme of ['light', 'dark']) for (const width of [1440, 390]) {
  test(`review steps, disclosures and local times: ${theme} ${width}`, async t => {
    for (const state of ['mail-prepare', 'mail-draft', 'mail-history', 'legacy-draft']) {
      const { page, posts, errors } = await open(t, state, { theme, width });
      assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
      assert.equal(await page.getByRole('heading', { name: '1 Who gets it', exact: true }).count(), 1);
      assert.equal(await page.getByRole('heading', { name: '2 The message', exact: true }).count(), 1);
      assert.equal(await page.getByRole('heading', { name: '3 Approve', exact: true }).count(), 1);
      assert.equal(await page.locator('.westy-review-history').getAttribute('open'), null);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      for (const control of await page.locator('.westy-review :is(button, summary, .link, .btn-secondary)').all()) {
        if (await control.isVisible()) assert.ok((await control.boundingBox()).height >= (width < 700 ? 44 : 36));
      }
      if (state === 'mail-draft') {
        assert.equal(await page.locator('textarea').count(), 0);
        assert.equal(await page.getByRole('checkbox').count(), 2);
        assert.equal(await page.getByRole('checkbox').first().isChecked(), false);
      }
      if (state === 'legacy-draft') {
        assert.equal(await page.locator('textarea[name=body]').isVisible(), false);
        await page.getByText('Prepare a new draft', { exact: true }).click();
        assert.equal(await page.locator('textarea[name=body]').isVisible(), true);
        await page.getByText('Prepare a new draft', { exact: true }).click();
        assert.equal(await page.locator('#westy-contact').getAttribute('open'), null);
        await page.getByText('Change contact', { exact: true }).click();
        assert.equal(await page.getByRole('button', { name: 'Save contact role' }).isVisible(), true);
        await page.getByText('Change contact', { exact: true }).click();
      }
      await shot(page, `${state}-${theme}-${width}`);
      await page.getByText('History', { exact: true }).click();
      if (state === 'mail-history') {
        assert.equal(await page.getByRole('alert').isVisible(), true);
        assert.match(await page.locator('time').first().innerText(), /Jan 15, 2026, 10:05/);
        assert.match(await page.locator('.westy-history-body').innerText(), /does not prove inbox receipt/);
        await shot(page, `history-open-${theme}-${width}`);
      }
      assert.deepEqual(posts, []); assert.deepEqual(errors, []);
    }
  });
}
test('contact fragment reveals only the existing contact form', async t => {
  const { page, posts } = await open(t, 'legacy-draft', { hash: '#westy-contact' });
  assert.equal(await page.getByRole('button', { name: 'Save contact role' }).isVisible(), true);
  assert.deepEqual(posts, []);
});
test('saved message requires existing confirmation before synthetic submit', async t => {
  const { page, posts } = await open(t, 'mail-draft');
  await page.getByRole('button', { name: 'Approve and send once' }).click();
  assert.deepEqual(posts, []);
  await page.locator('[name=confirm_send]').check();
  await page.getByRole('button', { name: 'Approve and send once' }).click();
  await page.waitForURL('**/westy_mail.php?ticket_id=1042');
  assert.equal(posts.length, 1);
  const body = new URLSearchParams(posts[0].body);
  assert.equal(body.get('action'), 'approve_send'); assert.equal(body.get('conversation'), '51');
  assert.equal(body.get('confirm_send'), 'yes'); assert.equal(body.get('csrf'), 'synthetic-csrf');
  assert.equal(body.has('delegate'), false); assert.match(body.get('review'), /^[a-f0-9]{32}$/);
});
test('unavailable approval paths and no-JavaScript history', async t => {
  for (const state of ['mail-viewer', 'mail-disabled']) {
    const { page } = await open(t, state);
    assert.equal(await page.getByRole('button', { name: 'Approve and send once' }).count(), 0);
  }
  const { page, posts } = await open(t, 'legacy-history', { js: false });
  await page.getByText('History', { exact: true }).click();
  assert.match(await page.locator('.westy-history-body').innerText(), /not recorded with this message/);
  assert.match(await page.locator('time').first().innerText(), /UTC/);
  assert.deepEqual(posts, []);
});

test('staff suggestions follow the page without changing first-run or dismiss behavior', async t => {
  const script = await readFile(path.join(root, 'app/public/assets/js/westy.js'), 'utf8');
  for (const [pathname, expected] of [['/ticket.php','How do I merge tickets?'], ['/snippets.php','How do I use a saved reply?'], ['/service_goals.php','How are service goals applied?'], ['/westy_mail.php','Who receives this email?'], ['/index.php','How do tickets get here?']]) {
    const context = await browser.newContext(); t.after(() => context.close());
    const page = await context.newPage(), posts = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      if (route.request().method() === 'POST') { posts.push(new URL(route.request().url()).pathname); return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' }); }
      return route.fulfill({ contentType: 'text/html', body: `<div id="westy-root" data-csrf="synthetic-csrf" data-onboarded="0" data-name="Example Teammate" data-uid="7"><button id="westy-bubble">Ask Westy</button><section id="westy-panel" hidden><button id="westy-close">Close</button><div id="westy-log"></div><form id="westy-form"><input id="westy-input"><button id="westy-send">Send</button></form></section></div><script>${script}</script>` });
    });
    await page.goto(origin + pathname);
    await page.waitForSelector('.westy-chip');
    assert.equal(await page.locator('.westy-chip:not(.westy-chip-dismiss)').count(), 3);
    assert.equal(await page.locator('.westy-chip').first().innerText(), expected);
    assert.deepEqual(posts, []);
    await page.getByRole('button', { name: 'Thanks, got it' }).click();
    await page.waitForFunction(() => document.querySelector('#westy-root').dataset.onboarded === '1');
    assert.equal(await page.locator('.westy-chip').count(), 0);
    assert.deepEqual(posts, ['/api/westy_onboard.php']); assert.deepEqual(errors, []);
  }
});
