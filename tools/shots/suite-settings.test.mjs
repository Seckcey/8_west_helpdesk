import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-suite-settings-'));
const rendered = spawnSync('php', ['app/tests/suite_chrome_test.php'], {
  cwd: root, env: { ...process.env, CUSTOMER_UI_CAPTURE_DIR: scratch }, encoding: 'utf8',
});
assert.equal(rendered.status, 0, rendered.stdout + rendered.stderr);
const browser = await chromium.launch({ headless: true });
const evidence = process.env.CUSTOMER_UI_SCREENSHOT_DIR;
if (evidence) await mkdir(evidence, { recursive: true });
try {
  for (const role of ['admin', 'tech']) for (const theme of ['light', 'dark']) {
    for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
      await test(`published launcher with actual staff menu: ${role} ${theme} ${viewport.width}`, async () => {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        const problems = [];
        page.on('pageerror', error => problems.push(error.message));
        page.on('console', message => { if (['error', 'warning'].includes(message.type())) problems.push(message.text()); });
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.pathname === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: await readFile(path.join(scratch, `staff-${role}-${theme}.html`)) });
          if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
            const file = path.join(root, 'app/public', url.pathname);
            const types = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png' };
            return route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) });
          }
          return route.abort();
        });
        try {
          await page.goto('http://safeharbor.test/');
          const button = page.getByRole('button', { name: 'Account menu for Synthetic Technician' });
          await button.click();
          const settings = page.getByRole('menuitem', { name: 'My settings', exact: true });
          assert.equal(await settings.getAttribute('href'), 'https://id.8westit.com/settings.php');
          assert.equal(await settings.evaluate(el => el === document.activeElement), true);
          await page.keyboard.press('ArrowDown');
          assert.equal(await page.getByRole('menuitem', { name: 'Technician Workspace' }).evaluate(el => el === document.activeElement), true);
          assert.equal(await page.getByRole('menuitem', { name: 'MSP Admin', exact: true }).count(), role === 'admin' ? 1 : 0);
          const bounds = await page.getByRole('menu').boundingBox();
          assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= viewport.width + 1, JSON.stringify(bounds));
          if (evidence) await page.screenshot({ path: path.join(evidence, `staff-${role}-${theme}-${viewport.width}.png`) });
          await page.keyboard.press('Escape');
          assert.equal(await button.getAttribute('aria-expanded'), 'false');
          assert.equal(await button.evaluate(el => el === document.activeElement), true);
          assert.deepEqual(problems, []);
        } finally { await context.close(); }
      });
    }
  }
  // Exercise the actual PHP page source with a CLI-only in-memory adapter.
  // These are synthetic controls/feedback checks, not signed-in or database acceptance.
  for (const surface of ['users', 'profile']) for (const theme of ['light', 'dark']) {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      await test(`staff ${surface} controls and feedback: ${theme} ${viewport.width}`, async () => {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        const problems = [];
        const submissions = [];
        page.on('pageerror', error => problems.push(error.message));
        page.on('console', message => { if (['error', 'warning'].includes(message.type())) problems.push(message.text()); });
        page.on('dialog', dialog => { problems.push('Unexpected confirmation dialog'); dialog.dismiss(); });
        const render = (account, data = {}) => {
          const result = spawnSync('php', ['app/tests/staff_settings_fixture.php', surface, theme, account], {
            cwd: root, env: { ...process.env, CUSTOMER_UI_CAPTURE_DIR: scratch }, input: JSON.stringify(data), encoding: 'utf8',
          });
          assert.equal(result.status, 0, result.stdout + result.stderr);
          return result.stdout;
        };
        let account = 'local';
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.pathname === `/${surface}.php`) {
            const data = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
            if (route.request().method() === 'POST') submissions.push(data);
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: render(account, data) });
          }
          if (url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
            const file = path.join(root, 'app/public', url.pathname);
            const types = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png' };
            return route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) });
          }
          return route.abort();
        });
        try {
          const url = `http://safeharbor.test/${surface}.php`;
          await page.goto(url);
          assert.match(await page.title(), surface === 'users' ? /Team/ : /My settings/);
          const metrics = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
          assert.ok(metrics.scroll <= metrics.width + 1, JSON.stringify(metrics));
          if (surface === 'users') {
            const role = page.getByRole('radio', { name: 'Technician Work on support requests.' });
            await role.focus();
            await page.keyboard.press('ArrowRight');
            await page.getByRole('radio', { name: 'Administrator Manage support and local team access.' }).waitFor();
            assert.equal(await page.locator('input[name="role"][value="admin"]').isChecked(), true);
            const blue = page.getByRole('radio', { name: 'Blue', exact: true });
            await blue.focus();
            await page.keyboard.press('ArrowRight');
            const cyan = page.getByRole('radio', { name: 'Cyan', exact: true });
            assert.equal(await cyan.isChecked(), true);
            assert.equal(await cyan.evaluate(el => el === document.activeElement && getComputedStyle(el.nextElementSibling).outlineStyle !== 'none'), true);
            if (evidence) await page.screenshot({ path: path.join(evidence, `team-${theme}-${viewport.width}.png`), fullPage: true });
            await page.getByRole('button', { name: 'Disable Safeharbor access for Jordan Ellis' }).click();
            await page.getByRole('status').filter({ hasText: 'User deactivated.' }).waitFor();
            assert.equal(await page.getByRole('button', { name: 'Enable Safeharbor access for Jordan Ellis' }).count(), 1);
            assert.equal(submissions.at(-1).action, 'deactivate');
            assert.equal(submissions.at(-1).csrf, 'synthetic-csrf');
            await page.getByRole('textbox', { name: 'Full name', exact: true }).fill('Synthetic Example');
            await page.getByRole('textbox', { name: 'Work email' }).fill('duplicate@example.test');
            await page.getByRole('textbox', { name: 'Local password' }).fill('synthetic-password');
            await page.getByRole('button', { name: 'Add local account' }).click();
            await page.getByRole('alert').filter({ hasText: 'already exists' }).waitFor();
          } else {
            await page.getByRole('textbox', { name: 'Full name', exact: true }).fill('Synthetic Updated Name');
            await page.getByRole('button', { name: 'Save name', exact: true }).click();
            await page.getByRole('status').filter({ hasText: 'Name updated.' }).waitFor();
            assert.equal(await page.getByRole('textbox', { name: 'Full name', exact: true }).inputValue(), 'Synthetic Updated Name');
            assert.equal(submissions.at(-1).action, 'save_name');
            assert.equal(submissions.at(-1).csrf, 'synthetic-csrf');
            if (evidence) await page.screenshot({ path: path.join(evidence, `profile-${theme}-${viewport.width}.png`), fullPage: true });
            await page.getByLabel('Current password', { exact: true }).fill('wrong-synthetic-password');
            await page.getByLabel('New password', { exact: true }).fill('synthetic-new-password');
            await page.getByLabel('Confirm new password', { exact: true }).fill('synthetic-new-password');
            await page.getByRole('button', { name: 'Change password', exact: true }).click();
            await page.getByRole('alert').filter({ hasText: 'Current password didn’t match.' }).waitFor();
          }
          if (evidence) await page.screenshot({ path: path.join(evidence, `${surface}-error-${theme}-${viewport.width}.png`), fullPage: true });
          const count = submissions.length;
          await page.getByRole('link', { name: 'Cancel', exact: true }).first().click();
          assert.equal(await page.getByRole('alert').count(), 0);
          assert.equal(submissions.length, count);
          account = surface === 'users' ? 'tech' : 'suite';
          await page.goto(url);
          assert.equal(await page.getByRole('button', { name: /Disable Safeharbor access|Add local account|Save name|Change password/ }).count(), 0);
          assert.deepEqual(problems, []);
        } finally { await context.close(); }
      });
    }
  }
} finally {
  await browser.close();
  await rm(scratch, { recursive: true, force: true });
}
