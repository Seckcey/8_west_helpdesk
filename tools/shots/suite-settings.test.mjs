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
} finally {
  await browser.close();
  await rm(scratch, { recursive: true, force: true });
}
