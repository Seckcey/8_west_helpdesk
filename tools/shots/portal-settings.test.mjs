import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-settings-browser-'));
const rendered = spawnSync('php', ['app/tests/portal_settings_test.php'], {
  cwd: root, env: { ...process.env, CUSTOMER_UI_CAPTURE_DIR: scratch }, encoding: 'utf8',
});
assert.equal(rendered.status, 0, rendered.stdout + rendered.stderr);
const browser = await chromium.launch({ headless: true });
const evidence = process.env.CUSTOMER_UI_SCREENSHOT_DIR;
if (evidence) await mkdir(evidence, { recursive: true });
try {
  for (const fixture of ['client_admin-light', 'client_staff-light', 'client_viewer-light', 'admin-dark', 'workspace-light', 'workspace-dark']) {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      await test(`personal settings and scoped navigation: ${fixture} ${viewport.width}`, async () => {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        const problems = [];
        page.on('pageerror', error => problems.push(error.message));
        page.on('console', message => { if (['error', 'warning'].includes(message.type())) problems.push(message.text()); });
        const html = await readFile(path.join(scratch, `settings-${fixture}.html`), 'utf8');
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.pathname === '/portal/requests.php') return route.fulfill({ contentType: 'text/html', body: html });
          if (url.pathname === '/portal/westy.php') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, state: { enabled: false, ai_available: false, can_write: true, conversation: null, turns: [], draft: null } }) });
          if (url.pathname === '/settings.php' && url.hostname === 'id.8westit.com') return route.fulfill({ contentType: 'text/html', body: '<title>Synthetic ID destination</title><h1>My settings destination fixture</h1>' });
          if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
            let file = path.join(root, 'app/public', url.pathname);
            if (url.pathname === '/assets/brand/favicon.svg') file = path.join(root, 'brand/svg/favicon.svg');
            if (url.pathname === '/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png') file = path.join(root, 'brand/png/safeharbor-logo-horizontal-transparent-20260909.png');
            const types = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
            try { return await route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) }); }
            catch { problems.push('Missing fixture asset: ' + url.pathname); }
          }
          return route.fulfill({ status: 404, body: '' });
        });
        try {
          await page.goto('http://safeharbor.test/portal/requests.php');
          await page.getByRole('heading', { name: fixture.startsWith('workspace') ? 'What can I help you with?' : 'Support requests', exact: true }).waitFor();
          assert.match(await page.title(), fixture.startsWith('workspace') ? /Westy/ : /Your support/);
          assert.equal(await page.locator('html').getAttribute('lang'), 'en-GB');
          assert.equal(await page.locator('html').getAttribute('data-theme'), fixture.endsWith('dark') ? 'dark' : 'light');
          assert.equal(await page.locator('html').getAttribute('data-density'), 'compact');
          const metrics = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth, background: getComputedStyle(document.body).backgroundColor }));
          assert.ok(metrics.scroll <= metrics.width + 1, JSON.stringify(metrics));
          const channels = metrics.background.match(/\d+/g).map(Number);
          assert.ok(fixture.endsWith('dark') ? Math.max(...channels) < 80 : Math.min(...channels) > 200, JSON.stringify(metrics));
          if (viewport.width < 600) {
            await page.getByRole('button', { name: 'Toggle navigation' }).click();
            assert.equal(await page.getByRole('button', { name: 'Toggle navigation' }).getAttribute('aria-expanded'), 'true');
          }
          if (fixture.startsWith('workspace') && fixture.endsWith('light')) {
            const composer = await page.locator('.portal-composer').evaluate(el => getComputedStyle(el).backgroundColor);
            assert.ok(Math.min(...composer.match(/\d+/g).map(Number)) > 200, composer);
          }
          const admin = fixture.startsWith('admin') || fixture.startsWith('client_admin') || fixture.startsWith('workspace');
          assert.equal(await page.getByRole('link', { name: 'Client Admin', exact: true }).count(), admin ? 1 : 0);
          assert.equal(await page.getByRole('link', { name: 'My settings', exact: true }).count(), 1);
          await page.getByRole('link', { name: 'My settings', exact: true }).waitFor({ state: 'visible' });
          for (const action of await page.locator('.portal-account-action').all()) {
            const box = await action.boundingBox();
            assert.ok(box.height >= 44 && box.width >= 44, JSON.stringify(box));
          }
          const personal = page.getByRole('link', { name: 'My settings', exact: true });
          await personal.focus();
          await page.keyboard.press('Tab');
          await page.keyboard.press('Shift+Tab');
          assert.equal(await personal.evaluate(el => el === document.activeElement && getComputedStyle(el).outlineStyle !== 'none'), true);
          assert.equal(await page.locator('.portal-account form').getAttribute('method'), 'post');
          assert.equal(await page.locator('.portal-account form').getAttribute('action'), '/portal/logout.php');
          assert.ok(await page.locator('.portal-account input[name="csrf"]').getAttribute('value'));
          await page.getByRole('button', { name: 'Sign out', exact: true }).scrollIntoViewIfNeeded();
          const logoutBox = await page.getByRole('button', { name: 'Sign out', exact: true }).boundingBox();
          assert.ok(logoutBox.y >= 0 && logoutBox.y + logoutBox.height <= viewport.height + 1, JSON.stringify(logoutBox));
          assert.equal(await page.getByRole('button', { name: 'Sign out', exact: true }).evaluate(el => {
            const r = el.getBoundingClientRect();
            return [r.left + 8, r.right - 8].every(x => el.contains(document.elementFromPoint(x, r.top + r.height / 2)));
          }), true, 'Sign out target is not covered by the floating chat button');
          if (evidence) await page.screenshot({ path: path.join(evidence, `${fixture}-${viewport.width}.png`), fullPage: true });
          await page.getByRole('link', { name: 'My settings', exact: true }).click();
          await page.getByRole('heading', { name: 'My settings destination fixture' }).waitFor();
          assert.equal(page.url(), 'https://id.8westit.com/settings.php');
          assert.deepEqual(problems, []);
        } finally { await context.close(); }
      });
    }
  }
  // Home: role-scoped actions, honest states, readable at 390px, keyboard focus visible.
  for (const fixture of ['home-client_owner-light', 'home-client_staff-light', 'home-client_viewer-light', 'home-empty-dark']) {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      await test(`member Home: ${fixture} ${viewport.width}`, async () => {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        const problems = [];
        page.on('pageerror', error => problems.push(error.message));
        page.on('console', message => { if (['error', 'warning'].includes(message.type())) problems.push(message.text()); });
        const html = await readFile(path.join(scratch, `settings-${fixture}.html`), 'utf8');
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.pathname === '/portal/' && url.search === '') return route.fulfill({ contentType: 'text/html', body: html });
          if (url.pathname === '/portal/' && url.searchParams.get('view') === 'westy') return route.fulfill({ contentType: 'text/html', body: '<title>Westy fixture</title><h1>Westy workspace fixture</h1>' });
          if (url.pathname === '/portal/westy.php') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, state: { enabled: false, ai_available: false, can_write: true, conversation: null, turns: [], draft: null } }) });
          if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
            let file = path.join(root, 'app/public', url.pathname);
            if (url.pathname === '/assets/brand/favicon.svg') file = path.join(root, 'brand/svg/favicon.svg');
            const types = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
            try { return await route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) }); }
            catch { problems.push('Missing fixture asset: ' + url.pathname); }
          }
          return route.fulfill({ status: 404, body: '' });
        });
        try {
          await page.goto('http://safeharbor.test/portal/');
          await page.getByRole('heading', { level: 1, name: /^Welcome/ }).waitFor();
          assert.match(await page.title(), /^Home · /);
          const owner = fixture.includes('owner');
          const writer = !fixture.includes('viewer');
          const metrics = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
          assert.ok(metrics.scroll <= metrics.width + 1, JSON.stringify(metrics));
          assert.equal(await page.locator('img').evaluateAll(images => images.every(image => image.complete && image.naturalWidth > 0)), true);
          const actions = page.getByRole('navigation', { name: 'Start here' });
          assert.equal(await actions.getByRole('link', { name: /^Ask Westy/ }).getAttribute('href'), '/portal/?view=westy');
          assert.equal(await actions.getByRole('link', { name: /^Write a request/ }).count(), writer ? 1 : 0);
          assert.equal(await actions.getByRole('link', { name: /^My settings in 8 West ID/ }).getAttribute('href'), 'https://id.8westit.com/settings.php');
          assert.equal(await page.getByRole('link', { name: 'Manage computer access', exact: true }).count(), owner ? 1 : 0);
          assert.equal(await page.getByRole('heading', { name: 'Service summaries', exact: true }).count(), owner ? 1 : 0);
          assert.equal(await page.getByRole('heading', { name: writer ? 'Needs your reply' : 'Waiting for a reply', exact: true }).count(), 1);
          for (const target of await page.locator('.portal-overview-action, .portal-overview-button').all()) {
            const box = await target.boundingBox();
            assert.ok(box.height >= 44 && box.width >= 44, JSON.stringify(box));
          }
          if (viewport.width < 600) {
            await page.getByRole('button', { name: 'Toggle navigation' }).click();
            assert.equal(await page.getByRole('navigation', { name: 'Customer portal' }).getByRole('link', { name: 'Home', exact: true }).getAttribute('aria-current'), 'page');
            await page.getByRole('button', { name: 'Toggle navigation' }).click();
          } else {
            assert.equal(await page.getByRole('navigation', { name: 'Customer portal' }).getByRole('link', { name: 'Home', exact: true }).getAttribute('aria-current'), 'page');
          }
          const ask = actions.getByRole('link', { name: /^Ask Westy/ });
          await ask.focus();
          await page.keyboard.press('Tab');
          await page.keyboard.press('Shift+Tab');
          assert.equal(await ask.evaluate(el => el === document.activeElement && getComputedStyle(el).outlineStyle !== 'none'), true);
          if (evidence) await page.screenshot({ path: path.join(evidence, `${fixture}-${viewport.width}.png`), fullPage: true });
          await page.keyboard.press('Enter');
          await page.getByRole('heading', { name: 'Westy workspace fixture' }).waitFor();
          assert.equal(new URL(page.url()).search, '?view=westy');
          assert.deepEqual(problems, []);
        } finally { await context.close(); }
      });
    }
  }
} finally {
  await browser.close();
  await rm(scratch, { recursive: true, force: true });
}
