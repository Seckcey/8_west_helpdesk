import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { spawn, spawnSync } from 'node:child_process';
import { cp, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
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
  $c['portal'] = array_replace($c['portal'], ['enabled' => true, 'issuer' => 'https://id.fixture.invalid', 'cookie_secure' => false, 'revocation_cache_dir' => $argv[3]]);
  file_put_contents($argv[2], '<?php return ' . var_export($c, true) . ';');
`, path.join(root, 'app/config/config.sample.php'), path.join(runtime, 'config/config.php'), path.join(runtime, 'cache')], { encoding: 'utf8' });
assert.equal(settings.status, 0, settings.stdout + settings.stderr);

const port = await new Promise((resolve, reject) => {
  const probe = createServer();
  probe.on('error', reject);
  probe.listen(0, '127.0.0.1', () => { const free = probe.address().port; probe.close(() => resolve(free)); });
});
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
const browser = await chromium.launch({ headless: true, args: ['--host-resolver-rules=MAP *.fixture.test 127.0.0.1'] });

after(async () => {
  await browser.close();
  server.kill();
  await rm(runtime, { recursive: true, force: true });
});

async function fixture(t) {
  const context = await browser.newContext();
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
