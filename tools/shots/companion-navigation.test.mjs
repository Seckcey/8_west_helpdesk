import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { cp, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { createServer as createTcpServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

// Leave a page with unsent text in the composer, as a Companion window and as an ordinary browser. The Windows web view
// in the Companion has script dialogs turned off, so an unload prompt there never shows and the window just stays put:
// every link and button that navigates does nothing. Only the signed-in page is synthetic, as in
// companion-signout.test.mjs; the page it is left for is a stub, because where it goes is not what is being tested.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const runtime = await mkdtemp(path.join(tmpdir(), 'safeharbor-companion-navigation-'));
const sessions = path.join(runtime, 'sessions');

// ?companion=1 signs in the way a Companion window is: with the pairing marker in the session.
const FIXTURE_PAGE = String.raw`<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_render.php';
portal_session_start();
$now = time();
$identity = ['subject' => 't9u51', 'session_version' => '1.1', 'identity_tenant_slug' => 'synthetic-client',
    'role' => 'client_owner', 'display_name' => 'Synthetic Customer', 'binding_id' => 1, 'tenant_id' => 1, 'client_id' => 11,
    'issued_at' => $now, 'expires_at' => $now + 3600];
$_SESSION[PORTAL_SESSION_KEY] = $identity;
unset($_SESSION['desktop_companion_session']);
if (($_GET['companion'] ?? '') === '1') $_SESSION['desktop_companion_session'] = str_repeat('a', 32);
portal_render_workspace(['identity' => $identity, 'binding' => ['client_name' => 'Synthetic Client']]);
`;
const WESTY_STATE = String.raw`<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'state' => ['enabled' => true, 'ai_available' => true, 'can_write' => true, 'conversation' => str_repeat('b', 32), 'conversations' => [], 'turns' => [], 'draft' => null, 'operation_key' => str_repeat('c', 32)]]);
`;
const NO_COMPUTERS = String.raw`<?php
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'reason' => 'connection_unavailable']);
`;
const GUIDE_STUB = '<!doctype html><title>Guide</title><h1>Portal guide stub</h1>';

const free = createTcpServer();
await new Promise((resolve) => free.listen(0, '127.0.0.1', resolve));
const appPort = free.address().port;
await new Promise((resolve) => free.close(resolve));
const APP = `http://127.0.0.1:${appPort}`;

let server;
let browser;

async function windowWithDraft(t, { companion }) {
  const context = await browser.newContext({ viewport: { width: 420, height: 640 } });
  t.after(() => context.close());
  const page = await context.newPage();
  const dialogs = [];
  // Dismissing is what the Windows web view does with a prompt it is not allowed to show: the page stays.
  page.on('dialog', (dialog) => { dialogs.push(dialog.type()); return dialog.dismiss(); });
  await page.goto(`${APP}/portal/navigation-fixture.php${companion ? '?companion=1' : ''}`);
  const composer = page.locator('#portal-chat-input');
  await composer.click();
  await page.keyboard.type('A draft I never sent');
  assert.equal(await composer.inputValue(), 'A draft I never sent');
  return { page, dialogs,
    openGuide: async () => {
      await page.getByRole('button', { name: 'Toggle navigation' }).click();
      await page.getByRole('link', { name: 'Portal guide' }).click();
    } };
}

try {
  await mkdir(path.join(runtime, 'config'));
  await mkdir(sessions);
  await cp(path.join(root, 'app/lib'), path.join(runtime, 'lib'), { recursive: true });
  await cp(path.join(root, 'app/public/portal'), path.join(runtime, 'public/portal'), { recursive: true });
  await cp(path.join(root, 'app/public/assets'), path.join(runtime, 'public/assets'), { recursive: true });
  await writeFile(path.join(runtime, 'public/portal/navigation-fixture.php'), FIXTURE_PAGE);
  await writeFile(path.join(runtime, 'public/portal/westy.php'), WESTY_STATE);
  await writeFile(path.join(runtime, 'public/portal/desktop_sessions.php'), NO_COMPUTERS);
  await writeFile(path.join(runtime, 'public/portal/guide.php'), GUIDE_STUB);
  await writeFile(path.join(runtime, 'config/config.php'), `<?php return ['app_env' => 'dev', 'force_https' => false,
    'db' => ['host' => '127.0.0.1', 'port' => 1, 'user' => 'none', 'pass' => '', 'name' => 'none', 'charset' => 'utf8mb4'],
    'portal' => ['enabled' => true, 'issuer' => 'https://id.fixture.test', 'cookie_secure' => false]];`);

  server = spawn('php', ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${appPort}`, '-t', path.join(runtime, 'public')],
    { stdio: ['ignore', 'ignore', 'inherit'] });
  browser = await chromium.launch({ headless: true });

  let up = false;
  for (let tries = 0; tries < 100 && !up; tries++) {
    up = await fetch(`${APP}/portal/guide.php`).then((response) => response.status === 200, () => false);
    if (!up) await sleep(50);
  }
  assert.equal(up, true, 'the portal copy did not start');

  await test('in a Companion window, unsent text does not stop a link', async (t) => {
    const window = await windowWithDraft(t, { companion: true });
    await window.openGuide();
    await window.page.waitForURL(`${APP}/portal/guide.php`, { timeout: 10000 });
    await window.page.getByRole('heading', { name: 'Portal guide stub' }).waitFor();
    assert.deepEqual(window.dialogs, [], 'a prompt the Windows web view cannot show would keep the window where it is');
  });

  await test('in a Companion window, unsent text does not stop a reload', async (t) => {
    const window = await windowWithDraft(t, { companion: true });
    await window.page.reload({ timeout: 10000 });
    assert.deepEqual(window.dialogs, []);
  });

  await test('in an ordinary browser, unsent text still asks before leaving', async (t) => {
    const window = await windowWithDraft(t, { companion: false });
    await window.openGuide();
    for (let tries = 0; tries < 100 && window.dialogs.length === 0; tries++) await sleep(50);
    assert.deepEqual(window.dialogs, ['beforeunload']);
    assert.equal(new URL(window.page.url()).pathname, '/portal/navigation-fixture.php', 'dismissing the prompt keeps the page');
    assert.equal(await window.page.locator('#portal-chat-input').inputValue(), 'A draft I never sent');
  });

  await test('in an ordinary browser, Sign out still skips the prompt', async (t) => {
    const window = await windowWithDraft(t, { companion: false });
    const leaving = window.page.waitForRequest((request) => request.isNavigationRequest() && request.url() === `${APP}/portal/logout.php`);
    await window.page.getByRole('button', { name: 'Toggle navigation' }).click();
    await window.page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await leaving;
    assert.deepEqual(window.dialogs, []);
  });
} finally {
  await browser?.close();
  server?.kill();
  await rm(runtime, { recursive: true, force: true });
}
