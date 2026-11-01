import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { cp, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { request } from 'node:http';
import { createServer as createHttpsServer } from 'node:https';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// A self-submitting form on another site, in a real browser with a real cookie jar, against the real pages on
// PHP's built-in server. No database: the sign-in page, the queue's redirect and portal/desktop.php's answer to a
// bad post don't need one. cross_site_session_http_test.php covers the other pages and signed-in people, header by header.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const runtime = await mkdtemp(path.join(tmpdir(), 'safeharbor-cross-site-'));
await cp(path.join(root, 'app/lib'), path.join(runtime, 'lib'), { recursive: true });
await cp(path.join(root, 'app/public'), path.join(runtime, 'public'), { recursive: true });
for (const part of ['config', 'sessions', 'cache']) await mkdir(path.join(runtime, part), { recursive: true });
// What the browser really sent, so a change in its behaviour can't turn these checks into nothing.
await writeFile(path.join(runtime, 'public/__seen.php'), `<?php
header('Content-Type: application/json');
echo json_encode(['site' => $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null, 'origin' => $_SERVER['HTTP_ORIGIN'] ?? null, 'cookies' => array_keys($_COOKIE)]);
`);
const settings = spawnSync('php', ['-r', `
  $c = require $argv[1];
  $c['app_env'] = 'dev';
  $c['force_https'] = false;
  $c['portal'] = array_replace($c['portal'], ['enabled' => true, 'issuer' => 'https://id.fixture.invalid', 'cookie_secure' => false, 'revocation_cache_dir' => $argv[3],
    'client_id' => 'fixture-client', 'client_secret' => bin2hex(random_bytes(24)), 'redirect_uri' => 'https://safeharbor.fixture.invalid/portal/callback.php']);
  file_put_contents($argv[2], '<?php return ' . var_export($c, true) . ';');
`, path.join(root, 'app/config/config.sample.php'), path.join(runtime, 'config/config.php'), path.join(runtime, 'cache')], { encoding: 'utf8' });
assert.equal(settings.status, 0, settings.stdout + settings.stderr);

const freePort = () => new Promise((resolve, reject) => {
  const probe = createServer();
  probe.on('error', reject);
  probe.listen(0, '127.0.0.1', () => { const free = probe.address().port; probe.close(() => resolve(free)); });
});
const port = await freePort();
const server = spawn('php', ['-d', `session.save_path=${path.join(runtime, 'sessions')}`, '-S', `127.0.0.1:${port}`, '-t', path.join(runtime, 'public')], { stdio: 'ignore' });
for (let tries = 0; ; tries++) {
  const up = await fetch(`http://127.0.0.1:${port}/__seen.php`).then((r) => r.ok, () => false);
  if (up) break;
  assert.ok(tries < 100, 'PHP built-in server did not start');
  await new Promise((resolve) => setTimeout(resolve, 50));
}

// Chromium sends Fetch Metadata to 127.0.0.1 even over plain http, and not to an ordinary host over plain http,
// so the same server exercises both ways of telling a post came from elsewhere.
const FETCH_METADATA = `http://127.0.0.1:${port}`;
const ORIGIN_ONLY = `http://app.fixture.test:${port}`;
const SIBLING = `http://other.fixture.test:${port}`;
const ELSEWHERE = 'http://forged.example';
// The same app over https, the way production runs, in front of the same server: a throwaway self-signed
// certificate, and X-Forwarded-Proto so the app knows. The browser contexts that use it accept the certificate.
const tlsKey = path.join(runtime, 'tls-key.pem');
const tlsCert = path.join(runtime, 'tls-cert.pem');
const tls = spawnSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', tlsKey, '-out', tlsCert, '-days', '1',
  '-subj', '/CN=app.fixture.test', '-addext', 'subjectAltName=DNS:app.fixture.test'], { encoding: 'utf8' });
assert.equal(tls.status, 0, tls.stdout + tls.stderr);
const securePort = await freePort();
const front = createHttpsServer({ key: await readFile(tlsKey), cert: await readFile(tlsCert) }, (incoming, outgoing) => {
  const upstream = request({ host: '127.0.0.1', port, method: incoming.method, path: incoming.url,
    headers: { ...incoming.headers, 'x-forwarded-proto': 'https' } }, (answer) => {
    outgoing.writeHead(answer.statusCode, answer.headers);
    answer.pipe(outgoing);
  });
  upstream.on('error', () => outgoing.destroy());
  incoming.pipe(upstream);
});
await new Promise((resolve) => front.listen(securePort, '127.0.0.1', resolve));
const SECURE = `https://app.fixture.test:${securePort}`;
const browser = await chromium.launch({ headless: true, args: ['--host-resolver-rules=MAP *.fixture.test 127.0.0.1'] });

after(async () => {
  await browser.close();
  front.close();
  server.kill();
  await rm(runtime, { recursive: true, force: true });
});

async function fixture(t, options = {}) {
  const context = await browser.newContext(options);
  t.after(() => context.close());
  const page = await context.newPage();
  for (const site of [ELSEWHERE, SIBLING]) {
    await context.route(`${site}/**`, (route) => {
      const url = new URL(route.request().url());
      const to = url.searchParams.get('to');
      const body = url.searchParams.get('link') !== null
        ? `<!doctype html><title>elsewhere</title><a href="${to}">go</a>`
        : `<!doctype html><title>elsewhere</title><form method="post" action="${to}"><input type="hidden" name="${url.searchParams.get('field') ?? 'x'}" value="${url.searchParams.get('value') ?? '1'}"></form><script>document.forms[0].submit()</script>`;
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body });
    });
  }
  return { context, page };
}

// Loads a page on `site` that immediately posts itself to `to`, and returns the response to that post.
async function posted(page, site, to) {
  const answer = page.waitForResponse((response) => response.url() === to && response.request().method() === 'POST');
  await page.goto(`${site}/?to=${encodeURIComponent(to)}`, { waitUntil: 'commit' });
  const response = await answer;
  await page.waitForURL(to);
  return response;
}
async function seen(page, site, app) {
  const response = await posted(page, site, `${app}/__seen.php`);
  return response.json();
}
// A signed-in customer's portal session, written as a file the way PHP stores one, and its cookie in the browser.
async function signedIn(context, app, name) {
  const id = randomBytes(16).toString('hex');
  const made = spawnSync('php', ['-r', `
    file_put_contents($argv[1], '_safeharbor_portal_identity|' . serialize(['subject' => 't1u201', 'session_version' => '1.1',
      'identity_tenant_slug' => 'fixture-dental', 'role' => 'client_owner', 'display_name' => $argv[2], 'binding_id' => 1,
      'tenant_id' => 1, 'client_id' => 11, 'issued_at' => time() - 60, 'expires_at' => time() + 3600])
      . '_safeharbor_portal_csrf|' . serialize(bin2hex(random_bytes(32))));
  `, path.join(runtime, 'sessions', `sess_${id}`), name], { encoding: 'utf8' });
  assert.equal(made.status, 0, made.stdout + made.stderr);
  await context.addCookies([{ name: 'safeharbor_portal', value: id, domain: new URL(app).hostname, path: '/portal', httpOnly: true, sameSite: 'Lax' }]);
  return id;
}
const cookie = async (context, url, name) => (await context.cookies(url)).find((c) => c.name === name)?.value ?? null;
const session = (context, app) => cookie(context, app, 'PHPSESSID');
const portal = (context, app) => cookie(context, `${app}/portal/`, 'safeharbor_portal');
const formToken = (page) => page.locator('input[name=csrf]').first().inputValue();

for (const [how, app, sent] of [
  ['by Sec-Fetch-Site', FETCH_METADATA, { site: 'cross-site', origin: ELSEWHERE, cookies: [] }],
  ['by Origin, with no Sec-Fetch-Site', ORIGIN_ONLY, { site: null, origin: ELSEWHERE, cookies: [] }],
]) {
  test(`a form on another site leaves the session cookie alone, told ${how}`, async (t) => {
    const { context, page } = await fixture(t);
    await page.goto(`${app}/login.php`);
    const before = await session(context, app);
    const token = await formToken(page);
    assert.ok(before, 'the sign-in page started a session');
    assert.ok(token, 'the sign-in page issued a token');

    assert.deepEqual(await seen(page, ELSEWHERE, app), sent, 'the post arrives as a cross-site post without the session cookie');

    const response = await posted(page, ELSEWHERE, `${app}/login.php`);
    assert.equal(response.status(), 419);
    assert.equal(await session(context, app), before, 'the browser kept its session cookie');

    await page.goto(`${app}/login.php`);
    assert.equal(await session(context, app), before, 'and is still in that session on its next visit');
    assert.equal(await formToken(page), token, 'with the same token');
  });

  test(`the redirect after a form on another site lands in the person's own session, told ${how}`, async (t) => {
    const { context, page } = await fixture(t);
    await page.goto(`${app}/login.php`);
    const before = await session(context, app);
    const token = await formToken(page);

    const answer = page.waitForResponse((response) => response.url() === `${app}/` && response.request().method() === 'POST');
    await page.goto(`${ELSEWHERE}/?to=${encodeURIComponent(`${app}/`)}`, { waitUntil: 'commit' });
    assert.equal((await answer).status(), 302);
    await page.waitForURL(`${app}/login.php?next=%2F`);
    assert.equal(await session(context, app), before, 'the browser kept its session cookie');
    assert.equal(await formToken(page), token, 'and the page after the redirect is in that session');
  });

  test(`a form on another site leaves the portal cookie alone, told ${how}`, async (t) => {
    const { context, page } = await fixture(t);
    await page.goto(`${app}/login.php`);
    await page.evaluate(() => fetch('/portal/desktop.php', { method: 'POST' }));
    const before = await portal(context, app);
    assert.ok(before, 'a first post from our own page started a portal session');

    await posted(page, ELSEWHERE, `${app}/portal/desktop.php`);
    assert.equal(await portal(context, app), before, 'the browser kept its portal cookie');
  });

  test(`a forged Companion sign-out leaves the portal cookie alone, told ${how}`, async (t) => {
    const { context, page } = await fixture(t);
    await page.goto(`${app}/login.php`);
    await page.evaluate(() => fetch('/portal/desktop.php', { method: 'POST' }));
    const before = await portal(context, app);
    assert.ok(before, 'a first post from our own page started a portal session');

    await page.goto(`${ELSEWHERE}/?field=window&value=companion&to=${encodeURIComponent(`${app}/portal/logout.php`)}`, { waitUntil: 'commit' });
    await page.waitForURL(`${app}/portal/signed-out.php?connection=open`);
    assert.equal(await portal(context, app), before, 'the browser kept its portal cookie');
  });
}

test('a form on a sibling site carries the session cookie and leaves it alone', async (t) => {
  const { context, page } = await fixture(t);
  await page.goto(`${ORIGIN_ONLY}/login.php`);
  const before = await session(context, ORIGIN_ONLY);
  const token = await formToken(page);
  assert.ok(before, 'the sign-in page started a session');

  assert.deepEqual(await seen(page, SIBLING, ORIGIN_ONLY), { site: null, origin: SIBLING, cookies: ['PHPSESSID'] }, 'the post arrives with the session cookie');

  const response = await posted(page, SIBLING, `${ORIGIN_ONLY}/login.php`);
  assert.equal(response.status(), 419);
  assert.equal(await session(context, ORIGIN_ONLY), before, 'the browser kept its session cookie');
  await page.goto(`${ORIGIN_ONLY}/login.php`);
  assert.equal(await formToken(page), token, 'and is still in that session on its next visit');
});

// A link is a GET, so it carries the Lax portal cookie. Only a callback with this browser's own sign-in state may end the session.
test('a link on another site to the sign-in callback leaves a signed-in customer signed in', async (t) => {
  const { context, page } = await fixture(t);
  const id = await signedIn(context, FETCH_METADATA, 'Fixture Customer');
  const callback = `${FETCH_METADATA}/portal/callback.php`;
  await page.goto(`${ELSEWHERE}/?link=1&to=${encodeURIComponent(callback)}`);
  const [response] = await Promise.all([page.waitForResponse((r) => r.url() === callback), page.click('a')]);
  assert.equal(response.status(), 401, 'the callback refuses it');
  assert.equal(await portal(context, FETCH_METADATA), id, 'the browser kept its portal cookie');
  await page.goto(`${FETCH_METADATA}/portal/signed-out.php`);
  assert.equal(await page.locator('#portal-still-signed-in-heading').count(), 1, 'and the session it names is still signed in');
});

// A forged Companion sign-out ends nothing, but it lands on the signed-out page. That page must not say otherwise.
test('a forged Companion sign-out does not tell a customer who is still signed in that they were signed out', async (t) => {
  const { context, page } = await fixture(t);
  const id = await signedIn(context, FETCH_METADATA, 'Fixture Customer');
  await page.goto(`${ELSEWHERE}/?field=window&value=companion&to=${encodeURIComponent(`${FETCH_METADATA}/portal/logout.php`)}`, { waitUntil: 'commit' });
  await page.waitForURL(`${FETCH_METADATA}/portal/signed-out.php?connection=open`);
  await page.getByRole('heading', { level: 2 }).waitFor();
  assert.equal(await page.locator('#portal-signed-out-heading').count(), 0, 'the page makes no signed-out claim');
  assert.equal(await page.locator('#portal-still-signed-in-heading').count(), 1, 'it says this did not sign them out');
  assert.equal(await page.getByText('Fixture Customer').count(), 0, 'and names nobody');
  assert.equal(await page.locator('a[href="/portal/"]').count(), 1, 'and links to the portal');
  assert.equal(await portal(context, FETCH_METADATA), id, 'the browser kept its portal cookie');

  await context.clearCookies();
  await page.reload();
  assert.equal(await page.locator('#portal-signed-out-heading').count(), 1, 'with no portal session, the same page says signed out');
});

// A sibling host on plain http is the same site by name only. Chromium calls its post to an https page
// cross-site and withholds the Lax cookies, so the app must not answer it with a new session cookie either.
test('a form on a plain-http sibling leaves the https session and portal cookies alone', async (t) => {
  const { context, page } = await fixture(t, { ignoreHTTPSErrors: true });
  await page.goto(`${SECURE}/login.php`);
  const before = await session(context, SECURE);
  const token = await formToken(page);
  await page.evaluate(() => fetch('/portal/desktop.php', { method: 'POST' }));
  const portalBefore = await portal(context, SECURE);
  assert.ok(before, 'the sign-in page started a session');
  assert.ok(portalBefore, 'a first post from our own page started a portal session');

  assert.deepEqual(await seen(page, SIBLING, SECURE), { site: 'cross-site', origin: SIBLING, cookies: [] },
    'the post arrives as a cross-site post without the session cookies');

  assert.equal((await posted(page, SIBLING, `${SECURE}/login.php`)).status(), 419);
  await posted(page, SIBLING, `${SECURE}/portal/desktop.php`);
  assert.equal(await session(context, SECURE), before, 'the browser kept its session cookie');
  assert.equal(await portal(context, SECURE), portalBefore, 'and its portal cookie');
  await page.goto(`${SECURE}/login.php`);
  assert.equal(await formToken(page), token, 'and is still in that session on its next visit');
});

for (const [how, app] of [['with Fetch Metadata', FETCH_METADATA], ['without Fetch Metadata', ORIGIN_ONLY]]) {
  test(`a first post from our own page still gets a session, ${how}`, async (t) => {
    const { context, page } = await fixture(t);
    await page.goto(`${app}/login.php`);
    await context.clearCookies();
    const [response] = await Promise.all([
      page.waitForResponse((answer) => answer.url() === `${app}/login.php` && answer.request().method() === 'POST'),
      page.waitForNavigation(),
      page.evaluate(() => document.querySelector('input[name=csrf]').form.submit()),
    ]);
    assert.equal(response.status(), 419);
    const after = await session(context, app);
    assert.ok(after, 'the post left the browser a session cookie');

    await page.goto(`${app}/login.php`);
    assert.equal(await session(context, app), after, 'and the next page carries on in that session');
  });
}

test('a link on another site starts a first visit\'s session', async (t) => {
  const { context, page } = await fixture(t);
  await page.goto(`${ELSEWHERE}/?link=1&to=${encodeURIComponent(`${FETCH_METADATA}/login.php`)}`);
  await Promise.all([page.waitForURL(`${FETCH_METADATA}/login.php`), page.click('a')]);
  assert.ok(await session(context, FETCH_METADATA), 'the first visit got a session cookie');
  assert.ok(await formToken(page), 'and a sign-in token');
});
