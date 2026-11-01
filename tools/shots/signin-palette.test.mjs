import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { spawn, spawnSync } from 'node:child_process';
import { cp, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

// The real sign-in page and app.js on PHP's built-in server, with no database. The sign-in page has no command palette,
// and the ⌘K shortcut must not leave the keyboard thinking one is open: Enter has to submit the form whatever was
// pressed before it. The post itself is answered by a stub, because checking the password is not what is tested here.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const runtime = await mkdtemp(path.join(tmpdir(), 'safeharbor-signin-palette-'));
await cp(path.join(root, 'app/lib'), path.join(runtime, 'lib'), { recursive: true });
await cp(path.join(root, 'app/public'), path.join(runtime, 'public'), { recursive: true });
for (const part of ['config', 'sessions', 'cache']) await mkdir(path.join(runtime, part), { recursive: true });
const settings = spawnSync('php', ['-r', `
  $c = require $argv[1];
  $c['app_env'] = 'dev';
  $c['force_https'] = false;
  $c['portal'] = array_replace($c['portal'], ['enabled' => true, 'issuer' => 'https://id.fixture.invalid', 'cookie_secure' => false, 'revocation_cache_dir' => $argv[3]]);
  file_put_contents($argv[2], '<?php return ' . var_export($c, true) . ';');
`, path.join(root, 'app/config/config.sample.php'), path.join(runtime, 'config/config.php'), path.join(runtime, 'cache')], { encoding: 'utf8' });
assert.equal(settings.status, 0, settings.stdout + settings.stderr);

const port = await new Promise((resolve, reject) => {
  const probe = createServer();
  probe.on('error', reject);
  probe.listen(0, '127.0.0.1', () => { const free = probe.address().port; probe.close(() => resolve(free)); });
});
const APP = `http://127.0.0.1:${port}`;
const server = spawn('php', ['-d', `session.save_path=${path.join(runtime, 'sessions')}`, '-S', `127.0.0.1:${port}`, '-t', path.join(runtime, 'public')], { stdio: 'ignore' });
let browser;

// The sign-in page with no script errors on it at the end, and the sign-in post answered by a stub.
async function signInPage(t) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  const posts = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route(`${APP}/login.php`, (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    posts.push(new URLSearchParams(route.request().postData() || ''));
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>Posted</title><h1>Posted</h1>' });
  });
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, []);
  });
  await page.goto(`${APP}/login.php`);
  await page.getByRole('button', { name: 'Sign in' }).waitFor();
  return { page, posts };
}

async function fillIn(page) {
  await page.getByLabel('Work email').fill('tech@example.test');
  await page.getByLabel('Password').fill('not a real password');
}

async function enterSignsIn({ page, posts }) {
  await page.getByLabel('Password').press('Enter');
  await page.getByRole('heading', { name: 'Posted' }).waitFor({ timeout: 5000 });
  assert.equal(posts.length, 1);
  assert.equal(posts[0].get('email'), 'tech@example.test');
  assert.equal(posts[0].get('password'), 'not a real password');
}

try {
  for (let tries = 0; ; tries++) {
    const up = await fetch(`${APP}/login.php`).then((r) => r.ok, () => false);
    if (up) break;
    assert.ok(tries < 100, 'the app copy did not start');
    await sleep(50);
  }
  browser = await chromium.launch({ headless: true });

  await test('Enter in the password box signs in', async (t) => {
    const signIn = await signInPage(t);
    await fillIn(signIn.page);
    await enterSignsIn(signIn);
  });

  for (const shortcut of ['Control+k', 'Meta+k']) {
    await test(`${shortcut} on the sign-in page opens nothing, and Enter still signs in`, async (t) => {
      const signIn = await signInPage(t);
      await signIn.page.getByLabel('Work email').focus();
      await signIn.page.keyboard.press(shortcut);
      assert.equal(await signIn.page.locator('.palette-overlay').count(), 0);
      await fillIn(signIn.page);
      await enterSignsIn(signIn);
    });

    await test(`${shortcut} pressed twice, from the page itself, leaves Enter signing in`, async (t) => {
      const signIn = await signInPage(t);
      await signIn.page.locator('body').click({ position: { x: 5, y: 5 } });
      await signIn.page.keyboard.press(shortcut);
      await signIn.page.keyboard.press(shortcut);
      await fillIn(signIn.page);
      await enterSignsIn(signIn);
    });
  }

  await test('after the shortcut the Sign in button still signs in', async (t) => {
    const signIn = await signInPage(t);
    await signIn.page.keyboard.press('Control+k');
    await fillIn(signIn.page);
    await signIn.page.getByRole('button', { name: 'Sign in' }).click();
    await signIn.page.getByRole('heading', { name: 'Posted' }).waitFor({ timeout: 5000 });
    assert.equal(signIn.posts.length, 1);
  });
} finally {
  await browser?.close();
  server.kill();
  await rm(runtime, { recursive: true, force: true });
}
