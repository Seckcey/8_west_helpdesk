/** Synthetic rendered readiness regression; no application server, DB, login, or provider.
 * node tools/shots/desktop-availability.mjs
 * Requires the shots Playwright dependency and PHP CLI. DESKTOP_CONTROLS_HTML may
 * instead name a fragment rendered by the same PHP function on a test host.
 * SHOTS must point outside the repository when retaining screenshots.
 */
import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const repo = fileURLToPath(new URL('../../', import.meta.url));
const render = `function portal_h($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');} function portal_csrf_token(){return 'synthetic-only';} require 'app/lib/portal_desktop_controls.php'; portal_desktop_controls();`;
const php = process.env.DESKTOP_CONTROLS_HTML ? null : spawnSync(process.env.PHP_BIN || 'php', ['-r', render], { cwd: repo, encoding: 'utf8' });
if (php) assert.equal(php.status, 0, php.stderr);
const fragment = process.env.DESKTOP_CONTROLS_HTML ? readFileSync(process.env.DESKTOP_CONTROLS_HTML, 'utf8') : php.stdout;
const origin = 'http://desktop-availability.test';
const html = `<!doctype html><html lang="en"><head><title>Desktop readiness fixture</title><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/portal.css"></head><body class="portal-app"><main style="max-width:640px;margin:auto;padding:16px"><h1>Westy</h1><section id="portal-chat-panel" class="portal-westy"><h2>Private conversation</h2><p>Chat and support remain available.</p><label for="portal-chat-input">Your request</label><textarea id="portal-chat-input"></textarea>${fragment}<a href="/portal/new.php">Contact support</a><a href="/portal/devices.php">Install existing agent</a></section></main></body></html>`;
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1100, height: 850 } });
let assertions = 0;
const check = (condition, name) => { assert.ok(condition, name); assertions++; console.log(`PASS ${name}`); };
const shots = process.env.SHOTS;
if (shots) mkdirSync(shots, { recursive: true });
const shot = name => shots ? page.screenshot({ path: path.join(shots, `${name}.png`), fullPage: true }) : Promise.resolve();
const a = 'a'.repeat(32), b = 'b'.repeat(32), conversation = 'c'.repeat(32), operation = 'd'.repeat(32), taskId = 'e'.repeat(32);
const companion = (session_id, extra = {}) => ({ session_id, device_name: session_id === a ? 'Studio laptop' : 'Office desktop', connected: true,
  expires_at: Math.floor(Date.now() / 1000) + 1800, state: 'paired', ...extra });
let items = [], fail = true, stopFails = false, state = null, listCalls = 0, resumeCalls = 0;
const starts = [], stops = [], errors = [];
page.on('pageerror', error => errors.push(error.message));
page.on('console', message => { if (['error', 'warning'].includes(message.type())) errors.push(message.text()); });
await page.exposeFunction('fixtureResume', () => { resumeCalls++; });
await page.clock.install();
await page.route('**/*', async route => {
  const url = new URL(route.request().url());
  assert.equal(url.origin, origin, 'fixture must not contact external services');
  if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: html });
  if (url.pathname.startsWith('/assets/')) {
    const asset = url.pathname.slice(1);
    assert.ok(['assets/css/app.css', 'assets/css/portal.css', 'assets/js/portal-desktop.js'].includes(asset));
    return route.fulfill({ contentType: asset.endsWith('.js') ? 'text/javascript' : 'text/css', body: readFileSync(path.join(repo, 'app/public', asset), 'utf8') });
  }
  assert.equal(url.pathname, '/portal/desktop_sessions.php');
  const body = route.request().postDataJSON();
  assert.equal(route.request().headers()['x-portal-csrf'], 'synthetic-only');
  if (body.action === 'list') listCalls++;
  if (body.action === 'stop') stops.push(body);
  if (fail || (body.action === 'stop' && stopFails)) return route.fulfill({ json: { ok: false, reason: 'connection_unavailable' } });
  if (body.action === 'list') return route.fulfill({ json: { ok: true, result: { items } } });
  if (body.action === 'start') {
    starts.push(body);
    state = companion(body.session_id, { state: 'consent_pending', task_id: taskId, conversation_id: conversation, origin_channel: 'portal' });
  }
  if (body.action === 'stop') state = { ...state, state: 'stopped' };
  return route.fulfill({ json: { ok: true, result: state } });
});
const root = page.locator('#portal-desktop-controls'), fields = page.locator('#portal-desktop-available');
const start = page.getByRole('button', { name: 'Allow a computer task' });
const stop = page.getByRole('button', { name: 'Stop computer control' });
const select = page.locator('#portal-desktop-session');
const refresh = async () => {
  await page.waitForFunction(() => document.querySelector('#portal-desktop-controls').getAttribute('aria-busy') !== 'true');
  const response = page.waitForResponse(r => r.url().endsWith('/portal/desktop_sessions.php'));
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await (await response).finished();
  await page.waitForFunction(() => document.querySelector('#portal-desktop-controls').getAttribute('aria-busy') === 'false');
};
try {
  await page.goto(origin);
  await page.waitForFunction(() => document.querySelector('#portal-desktop-status').textContent.includes('unavailable'));
  check(page.url() === `${origin}/` && await page.title() === 'Desktop readiness fixture', 'page identity');
  check(await page.getByRole('heading', { name: 'Westy', exact: true }).isVisible(), 'meaningful rendered page');
  check(await root.isHidden() && await start.count() === 0, 'missing native setup offers no computer task');
  check(!(await page.locator('body').innerText()).includes('tray'), 'no unavailable tray-app instruction');
  await page.locator('#portal-chat-input').fill('Can you help with my computer?');
  check(await page.getByRole('link', { name: 'Contact support' }).isVisible() && await page.getByRole('link', { name: 'Install existing agent' }).isVisible(), 'ordinary chat, support and existing installer remain usable');
  await shot('desktop-unavailable');
  fail = false; items = [companion(a, { connected: false }), companion(b, { expires_at: 1 }), companion('f'.repeat(32), { state: 'active', task_id: taskId })];
  await refresh(); check(await root.isHidden(), 'offline, expired and another active task are not offered');
  items = [companion(a)];
  const automatic = page.waitForResponse(r => r.url().endsWith('/portal/desktop_sessions.php') && r.request().postDataJSON()?.action === 'list');
  await page.clock.runFor(6500); await automatic;
  await root.waitFor({ state: 'visible' });
  check(await root.isVisible(), 'connected companion is discovered automatically without reload');
  await root.locator('summary').click();
  check(await start.isDisabled(), 'availability alone cannot start without an original chat turn');
  await page.evaluate(({ conversation, operation }) => {
    window.addEventListener('westy-desktop-resume', () => window.fixtureResume());
    window.dispatchEvent(new CustomEvent('westy-conversation', { detail: { conversation, operation } }));
  }, { conversation, operation });
  await page.waitForFunction(() => !document.querySelector('#portal-desktop-start').disabled);
  check(await select.inputValue() === a && await start.isEnabled(), 'eligible companion and original turn enable consent request');
  check(await page.locator('#portal-desktop-status').getAttribute('role') === 'status', 'availability status has live-region semantics');
  await shot('desktop-connected');
  items = [companion(b)]; await refresh();
  check(await select.inputValue() === '' && await start.isDisabled(), 'disconnect does not silently select a different computer');
  await select.selectOption(b); check(await start.isEnabled(), 'explicit device change enables the selected connected computer');
  await select.focus(); items = []; await refresh();
  check(await fields.isHidden() && await start.count() === 0, 'disconnection removes unusable controls');
  check(await root.locator('summary').evaluate(el => el === document.activeElement), 'focus moves to summary before controls disappear');
  items = [companion(b)]; await refresh();
  check(await select.inputValue() === b && await start.isEnabled(), 'same companion reconnects without reload');
  await start.click(); await stop.waitFor({ state: 'visible' });
  check(starts.length === 1 && starts[0].session_id === b && starts[0].operation_key === operation && starts[0].conversation_id === conversation, 'start preserves exact selected companion and original-turn authority');
  check(await select.isDisabled() && await start.count() === 0, 'consent-pending task cannot offer another start');
  state = { ...state, state: 'active' }; items = [state]; await refresh();
  await page.waitForFunction(() => document.querySelector('#portal-desktop-status').textContent.includes('approved'));
  await refresh(); check(resumeCalls === 1, 'active consent resumes original turn once');
  fail = true; await refresh();
  check(await stop.isVisible() && await stop.isEnabled() && await fields.isHidden(), 'network failure preserves actionable Stop and removes start');
  await stop.click();
  await page.waitForFunction(() => document.querySelector('#portal-desktop-status').textContent.includes('Stop could not reach'));
  check(await stop.isEnabled(), 'failed Stop remains retryable with local Stop guidance');
  await shot('desktop-disconnected-stop');
  fail = false; await stop.click();
  await stop.waitFor({ state: 'hidden' });
  check(stops.every(value => value.session_id === b && value.task_id === taskId), 'Stop always retains the original active task identity');
  items = [companion(b)]; await refresh();
  await page.setViewportSize({ width: 390, height: 844 });
  await root.locator('summary').evaluate(el => { el.parentElement.open = true; });
  check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'mobile layout has no horizontal overflow');
  await shot('desktop-mobile');
  await page.evaluate(() => { document.querySelector('#portal-chat-panel').hidden = true; });
  const beforeHidden = listCalls; await page.clock.runFor(10000);
  check(listCalls === beforeHidden, 'hidden chat does not poll companion availability');
  await page.evaluate(() => { document.querySelector('#portal-chat-panel').hidden = false; });
  await refresh();
  check(errors.length === 0, `no browser errors, warnings or framework overlays: ${errors.join('; ')}`);
  console.log(`PASS ${assertions} rendered desktop readiness checks; requests synthetic only`);
} finally { await browser.close(); }
