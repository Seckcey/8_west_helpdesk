import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { cp, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { createServer as createTcpServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

// Sign out as a Companion window does it: the app's own headers, account menu, chat script and logout.php over HTTP, at
// the window's size. Both native shells refuse to leave the origin and a redirect that tries leaves the private page on
// screen, so nothing here may ask to. Only the signed-in page is synthetic: this CI job has no database, so Milepost's
// answer stays unconfirmed. The real WebView2 and WKWebView runs are recorded on the pull request.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const runtime = await mkdtemp(path.join(tmpdir(), 'safeharbor-companion-signout-'));
const sessions = path.join(runtime, 'sessions');

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
$_SESSION['desktop_companion_session'] = str_repeat('a', 32);
portal_render_workspace(['identity' => $identity, 'binding' => ['client_name' => 'Synthetic Client']]);
`;
// What the chat widget asks for when a page opens: nothing to show, ready for a message.
const WESTY_STATE = String.raw`<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'state' => ['enabled' => true, 'ai_available' => true, 'can_write' => true, 'conversation' => str_repeat('b', 32), 'conversations' => [], 'turns' => [], 'draft' => null, 'operation_key' => str_repeat('c', 32)]]);
`;
const NO_COMPUTERS = String.raw`<?php
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'reason' => 'connection_unavailable']);
`;

const free = createTcpServer();
await new Promise((resolve) => free.listen(0, '127.0.0.1', resolve));
const appPort = free.address().port;
await new Promise((resolve) => free.close(resolve));
const APP = `http://127.0.0.1:${appPort}`;

let server;
let browser;

async function companionWindow(t) {
  const context = await browser.newContext({ viewport: { width: 420, height: 640 } });
  t.after(() => context.close());
  const page = await context.newPage();
  const asked = [];
  const dialogs = [];
  const blocked = [];
  page.on('request', (request) => { if (request.isNavigationRequest()) asked.push(request.url()); });
  page.on('dialog', (dialog) => { dialogs.push(dialog.type()); return dialog.dismiss(); });
  page.on('console', (message) => { if (/Content Security Policy/i.test(message.text())) blocked.push(message.text()); });
  await page.goto(`${APP}/portal/signout-fixture.php`);
  await page.getByRole('button', { name: 'Toggle navigation' }).waitFor();
  const [session] = (await context.cookies(`${APP}/portal/`)).filter((cookie) => cookie.name === 'safeharbor_portal');
  assert.ok(session, 'the page started a portal session');
  const sessionFile = path.join(sessions, `sess_${session.value}`);
  assert.equal(existsSync(sessionFile), true);
  return { context, page, asked, dialogs, blocked, sessionFile,
    signOut: async () => {
      await page.getByRole('button', { name: 'Toggle navigation' }).click();
      await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    } };
}

async function signedOutInTheWindow({ context, page, asked, blocked, sessionFile }) {
  await page.waitForURL(/\/portal\/signed-out\.php/, { timeout: 10000 });
  await page.getByRole('heading', { name: 'You’re signed out of Westy' }).waitFor();
  assert.deepEqual(blocked, []);
  assert.deepEqual(asked.filter((url) => !url.startsWith(`${APP}/`)), [], 'something tried to leave the window');
  assert.equal((await page.locator('body').innerText()).includes('Synthetic Customer'), false, 'the name is still on screen');
  assert.equal((await context.cookies(`${APP}/portal/`)).some((cookie) => cookie.name === 'safeharbor_portal'), false, 'the portal cookie is still set');
  assert.equal(existsSync(sessionFile), false, 'the session is still on the server');
}

try {
  await mkdir(path.join(runtime, 'config'));
  await mkdir(sessions);
  await cp(path.join(root, 'app/lib'), path.join(runtime, 'lib'), { recursive: true });
  await cp(path.join(root, 'app/public/portal'), path.join(runtime, 'public/portal'), { recursive: true });
  await cp(path.join(root, 'app/public/assets'), path.join(runtime, 'public/assets'), { recursive: true });
  await writeFile(path.join(runtime, 'public/portal/signout-fixture.php'), FIXTURE_PAGE);
  await writeFile(path.join(runtime, 'public/portal/westy.php'), WESTY_STATE);
  await writeFile(path.join(runtime, 'public/portal/desktop_sessions.php'), NO_COMPUTERS);
  // Nothing listens at this database address, so the sign-out meets a real connection failure and must carry on.
  await writeFile(path.join(runtime, 'config/config.php'), `<?php return ['app_env' => 'dev', 'force_https' => false,
    'db' => ['host' => '127.0.0.1', 'port' => 1, 'user' => 'none', 'pass' => '', 'name' => 'none', 'charset' => 'utf8mb4'],
    'portal' => ['enabled' => true, 'issuer' => 'https://id.fixture.test', 'cookie_secure' => false]];`);

  server = spawn('php', ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${appPort}`, '-t', path.join(runtime, 'public')],
    { stdio: ['ignore', 'ignore', 'inherit'] });
  browser = await chromium.launch({ headless: true });

  let up = false;
  for (let tries = 0; tries < 100 && !up; tries++) {
    up = await fetch(`${APP}/portal/signed-out.php`).then((response) => response.status === 200, () => false);
    if (!up) await sleep(50);
  }
  assert.equal(up, true, 'the portal copy did not start');

  await test('Sign out ends in the window, on a page with nothing private', async (t) => {
    const window = await companionWindow(t);
    await window.signOut();
    await signedOutInTheWindow(window);
    // This job has no Milepost to answer, and the page says what that means rather than claiming it closed.
    await window.page.getByText('could not confirm that this computer disconnected').waitFor();
  });

  await test('unsent text in the composer does not stop Sign out', async (t) => {
    const window = await companionWindow(t);
    const composer = window.page.locator('#portal-chat-input');
    await composer.click();
    await window.page.keyboard.type('A draft I never sent');
    assert.equal(await composer.inputValue(), 'A draft I never sent');
    await window.signOut();
    await signedOutInTheWindow(window);
    assert.deepEqual(window.dialogs, [], 'a prompt the Windows web view cannot show would stop the sign-out there');
  });

  await test('a double-click on Sign out ends on the same page', async (t) => {
    const window = await companionWindow(t);
    const { page } = window;
    await page.getByRole('button', { name: 'Toggle navigation' }).click();
    const button = page.getByRole('button', { name: 'Sign out', exact: true });
    await button.scrollIntoViewIfNeeded();
    const box = await button.boundingBox();
    const [x, y] = [box.x + box.width / 2, box.y + box.height / 2];
    // The second post replaces the first, and by the time it is answered the session and its marker have gone.
    await page.mouse.click(x, y);
    await sleep(40);
    await page.mouse.click(x, y);
    await signedOutInTheWindow(window);
  });

  await test('the signed-out page offers the browser, not another sign-in here', async (t) => {
    const window = await companionWindow(t);
    await window.signOut();
    await signedOutInTheWindow(window);
    const link = window.page.getByRole('link', { name: 'open the customer portal in your browser' });
    assert.equal(await link.getAttribute('target'), '_blank');
    assert.equal(await link.getAttribute('href'), '/portal/');
    assert.equal(await window.page.locator('form').count(), 0);
  });
} finally {
  await browser?.close();
  server?.kill();
  await rm(runtime, { recursive: true, force: true });
}
