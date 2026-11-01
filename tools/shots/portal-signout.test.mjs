import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { cp, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { existsSync, readFileSync } from 'node:fs';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:https';
import { createServer as createTcpServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

// Signing out for real: the app's own headers, account menu and logout.php over HTTP, then a browser following the
// redirect to a stand-in 8 West ID. route() never sees a redirected hop, so the stand-in is a real HTTPS server.
// Only the signed-in page is synthetic: real portal pages need a database, and this CI job has none.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const runtime = await mkdtemp(path.join(tmpdir(), 'safeharbor-portal-signout-'));
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
portal_page_start('Home', '', ['identity' => $identity, 'binding' => ['client_name' => 'Synthetic Client']]);
echo '<main><h1>Synthetic Home</h1></main>';
portal_page_end();
`;
// The two routes a portal page calls by itself once it opens, answered with nothing to show and no database.
const WESTY_STATE = String.raw`<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'state' => ['enabled' => false, 'ai_available' => false, 'can_write' => true, 'conversation' => null, 'turns' => [], 'draft' => null]]);
`;
const NO_COMPUTERS = String.raw`<?php
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'reason' => 'connection_unavailable']);
`;

const ISSUER_HOST = 'id.fixture.test';
const APP_HOST = 'app.fixture.test';
const certificate = spawnSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes',
  '-keyout', path.join(runtime, 'key.pem'), '-out', path.join(runtime, 'cert.pem'), '-days', '1',
  '-subj', `/CN=${ISSUER_HOST}`, '-addext', `subjectAltName=DNS:${ISSUER_HOST}`], { encoding: 'utf8' });
assert.equal(certificate.status, 0, certificate.stderr);

// The stand-in does what the live issuer does: clears its own cookie on /logout.php, then lands on its login page.
let logouts = [];
let hold = null;
const issuer = createServer({ key: readFileSync(path.join(runtime, 'key.pem')), cert: readFileSync(path.join(runtime, 'cert.pem')) }, async (req, res) => {
  const { pathname } = new URL(req.url, 'https://' + ISSUER_HOST);
  if (pathname === '/logout.php') {
    logouts.push({ method: req.method, signedIn: /(^|;\s*)ewid_token=signed-in/.test(req.headers.cookie ?? '') });
    await hold;
    res.writeHead(302, { Location: '/login.php', 'Set-Cookie': 'ewid_token=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax' });
    return res.end();
  }
  if (pathname === '/login.php') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end('<!doctype html><title>8 West ID</title><h1>Sign in fixture</h1>');
  }
  res.writeHead(404);
  res.end();
});
await new Promise((resolve) => issuer.listen(0, '127.0.0.1', resolve));
const ISSUER = `https://${ISSUER_HOST}:${issuer.address().port}`;

const free = createTcpServer();
await new Promise((resolve) => free.listen(0, '127.0.0.1', resolve));
const appPort = free.address().port;
await new Promise((resolve) => free.close(resolve));
const APP = `http://${APP_HOST}:${appPort}`;
const LOGOUT = `${APP}/portal/logout.php`;

let server;
let browser;

async function signedInPage(t) {
  logouts = [];
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
  t.after(() => context.close());
  await context.addCookies([{ name: 'ewid_token', value: 'signed-in', url: ISSUER, secure: true, httpOnly: true, sameSite: 'Lax' }]);
  const page = await context.newPage();
  const blocked = [];
  page.on('console', (message) => { if (/Content Security Policy/i.test(message.text())) blocked.push(message.text()); });
  await page.goto(`${APP}/portal/signout-fixture.php`);
  await page.getByRole('heading', { name: 'Synthetic Home' }).waitFor();
  const [session] = (await context.cookies(`${APP}/portal/`)).filter((cookie) => cookie.name === 'safeharbor_portal');
  assert.ok(session, 'the page started a portal session');
  const sessionFile = path.join(sessions, `sess_${session.value}`);
  assert.equal(existsSync(sessionFile), true);
  return {
    context, page, blocked, sessionFile,
    button: page.getByRole('button', { name: 'Sign out', exact: true }),
    issuerCookie: async () => (await context.cookies(ISSUER)).some((cookie) => cookie.name === 'ewid_token'),
    localCookie: async () => (await context.cookies(`${APP}/portal/`)).some((cookie) => cookie.name === 'safeharbor_portal'),
  };
}

async function signedOutEverywhere({ page, blocked, issuerCookie, localCookie }) {
  await page.waitForURL(`${ISSUER}/login.php`, { timeout: 10000 });
  await page.getByRole('heading', { name: 'Sign in fixture' }).waitFor();
  assert.deepEqual(blocked, []);
  assert.equal(await issuerCookie(), false, '8 West ID still has its cookie');
  assert.equal(await localCookie(), false, 'the portal cookie is still set');
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
  await writeFile(path.join(runtime, 'config/config.php'), `<?php return ['app_env' => 'dev', 'force_https' => false,
    'portal' => ['enabled' => true, 'issuer' => '${ISSUER}', 'cookie_secure' => false]];`);

  server = spawn('php', ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${appPort}`, '-t', path.join(runtime, 'public')],
    { stdio: ['ignore', 'ignore', 'inherit'] });
  browser = await chromium.launch({
    headless: true,
    args: [`--host-resolver-rules=MAP ${ISSUER_HOST} 127.0.0.1, MAP ${APP_HOST} 127.0.0.1`],
  });

  let up = false;
  for (let tries = 0; tries < 100 && !up; tries++) {
    up = await fetch(`http://127.0.0.1:${appPort}/portal/logout.php`).then((response) => response.status === 405, () => false);
    if (!up) await sleep(50);
  }
  assert.equal(up, true, 'the portal copy did not start');

  await test('Sign out goes through to 8 West ID and ends both sessions', async (t) => {
    const signOut = await signedInPage(t);
    await signOut.button.click();
    await signedOutEverywhere(signOut);
    assert.deepEqual(logouts, [{ method: 'GET', signedIn: true }]);
    assert.equal(existsSync(signOut.sessionFile), false, 'the local session is still on the server');
  });

  await test('a double-click still ends at 8 West ID', async (t) => {
    const signOut = await signedInPage(t);
    const { page, button } = signOut;
    await button.scrollIntoViewIfNeeded();
    const box = await button.boundingBox();
    const [x, y] = [box.x + box.width / 2, box.y + box.height / 2];
    // The issuer's answer waits, so the second click lands while the first redirect is still under way, as it does for
    // a person on a real connection. The browser drops the first redirect for the second post, whose session has gone.
    let release = () => {};
    hold = new Promise((resolve) => { release = resolve; });
    t.after(() => { release(); hold = null; });
    const atIssuer = page.waitForRequest(`${ISSUER}/logout.php`);
    await page.mouse.click(x, y);
    await atIssuer;
    const secondPost = page.waitForRequest((request) => request.method() === 'POST' && request.url() === LOGOUT);
    await page.mouse.click(x, y);
    await secondPost;
    release();
    await signedOutEverywhere(signOut);
    assert.equal(existsSync(signOut.sessionFile), false, 'the local session is still on the server');
  });

  await test('a tab whose session has already ended still reaches 8 West ID', async (t) => {
    const signOut = await signedInPage(t);
    await signOut.context.clearCookies({ name: 'safeharbor_portal' });
    await signOut.button.click();
    await signedOutEverywhere(signOut);
    assert.deepEqual(logouts, [{ method: 'GET', signedIn: true }]);
  });

  await test('a live session is not ended without its token', async (t) => {
    const { page, button, sessionFile, issuerCookie, localCookie } = await signedInPage(t);
    await page.locator('form[action="/portal/logout.php"] input[name="csrf"]').evaluate((input) => { input.value = 'f'.repeat(64); });
    const answer = page.waitForResponse(LOGOUT);
    await button.click();
    assert.equal((await answer).status(), 403);
    await page.getByRole('heading', { name: 'Request rejected' }).waitFor();
    assert.deepEqual(logouts, []);
    assert.equal(await issuerCookie(), true);
    assert.equal(await localCookie(), true);
    assert.equal(existsSync(sessionFile), true);
  });

  // With no token to check, a forged post reaches 8 West ID as a plain link to its /logout.php can. It costs nothing here.
  await test('a post from another site leaves the portal session alone', async (t) => {
    const { context, page, sessionFile, localCookie } = await signedInPage(t);
    const session = async () => (await context.cookies(`${APP}/portal/`)).find((cookie) => cookie.name === 'safeharbor_portal')?.value;
    const before = await session();
    await page.route('http://forged.example/', (route) => route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><form method="post" action="${LOGOUT}"></form><script>document.forms[0].submit()</script>`,
    }));
    await page.goto('http://forged.example/', { waitUntil: 'commit' });
    await page.waitForURL(`${ISSUER}/login.php`, { timeout: 10000 });
    assert.equal(logouts.length, 1);
    assert.equal(await localCookie(), true);
    assert.equal(await session(), before, 'the browser kept its portal cookie');
    assert.match(readFileSync(sessionFile, 'utf8'), /Synthetic Customer/, 'the session on the server is still the customer\'s');
  });
} finally {
  await browser?.close();
  server?.kill();
  issuer.close();
  issuer.closeAllConnections();
  await rm(runtime, { recursive: true, force: true });
}
