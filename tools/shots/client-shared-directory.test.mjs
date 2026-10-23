import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// The actual Clients directory and client page, rendered by app/tests/client_shared_directory_ui_test.php
// from synthetic data and a synthetic Milepost directory. Destinations are synthetic too. This is a
// rendered-UI check, not signed-in, provider or customer acceptance.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-client-directory-'));
const rendered = spawnSync('php', ['app/tests/client_shared_directory_ui_test.php'], {
  cwd: root, env: { ...process.env, CUSTOMER_UI_CAPTURE_DIR: scratch }, encoding: 'utf8',
});
assert.equal(rendered.status, 0, rendered.stdout + rendered.stderr);

const uuid = n => `${n}a${n}b${n}c${n}d-${n}e${n}f-4a${n}b-8c${n}d-${n}e${n}f${n}a${n}b${n}c${n}d`;
const devices = n => `https://support.8westit.com/client.php?provider=8west&customer_id=${uuid(n)}`;
const billing = 'https://coastmark.8westit.com/auth/suite';
const tenantClients = ['Harbor Dental', 'Beta Canary', 'Copper Kettle', 'Email Intake', 'Northwind Partners',
  'Lagged Labs', 'Drifted Co', 'Removed Rentals'];
const browser = await chromium.launch({ headless: true });
const evidence = process.env.CUSTOMER_UI_SCREENSHOT_DIR;
if (evidence) await mkdir(evidence, { recursive: true });

async function route(context, theme, listCase, problems) {
  await context.route('**/*', async request => {
    const url = new URL(request.request().url());
    if (url.hostname === 'safeharbor.test' && url.pathname === '/clients.php') {
      return request.fulfill({ contentType: 'text/html; charset=utf-8', body: await readFile(path.join(scratch, `clients-${listCase}-${theme}.html`)) });
    }
    if (url.hostname === 'safeharbor.test' && url.pathname === '/client.php' && ['30', '31'].includes(url.searchParams.get('id'))) {
      const page = url.searchParams.get('id') === '30' ? 'business' : 'retained';
      return request.fulfill({ contentType: 'text/html; charset=utf-8', body: await readFile(path.join(scratch, `client-${page}-${theme}.html`)) });
    }
    if (url.hostname === 'support.8westit.com' && url.pathname === '/client.php') {
      return request.fulfill({ contentType: 'text/html', body: '<title>Synthetic Milepost destination</title><h1>Milepost devices destination fixture</h1>' });
    }
    if (url.href === billing) {
      return request.fulfill({ contentType: 'text/html', body: '<title>Synthetic Coastmark destination</title><h1>Coastmark destination fixture</h1>' });
    }
    if (url.hostname === 'safeharbor.test' && url.pathname.startsWith('/assets/') && !url.pathname.includes('..')) {
      let file = path.join(root, 'app/public', url.pathname);
      if (url.pathname === '/assets/brand/favicon.svg') file = path.join(root, 'brand/svg/favicon.svg');
      if (url.pathname === '/assets/brand/apple-touch-icon.png') file = path.join(root, 'brand/png/apple-touch-icon.png');
      if (url.pathname === '/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png') file = path.join(root, 'brand/png/safeharbor-logo-horizontal-transparent-20260909.png');
      // The production .ico is deployed with the brand kit, not kept in this repository.
      if (url.pathname === '/assets/brand/favicon.ico') return request.fulfill({ status: 204, body: '' });
      const types = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
      try { return await request.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: await readFile(file) }); }
      catch { problems.push('Missing fixture asset: ' + url.pathname); }
    }
    problems.push('Unexpected request: ' + url.href);
    return request.abort();
  });
}

async function layout(page, theme, viewport) {
  const metrics = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth, background: getComputedStyle(document.body).backgroundColor }));
  assert.ok(metrics.scroll <= metrics.width + 1, JSON.stringify(metrics));
  const channels = metrics.background.match(/\d+/g).map(Number);
  assert.ok(theme === 'dark' ? Math.max(...channels.slice(0, 3)) < 90 : Math.min(...channels.slice(0, 3)) > 190, JSON.stringify(metrics));
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  // Every row action is reachable and nothing covers it.
  for (const action of await page.locator('.crow-actions a').all()) {
    const clear = await action.evaluate(el => {
      el.scrollIntoView({ block: 'center', inline: 'nearest' });
      const r = el.getBoundingClientRect();
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return r.width > 0 && r.height > 0 && r.right <= innerWidth + 1 && (hit === el || el.contains(hit));
    });
    assert.equal(clear, true, await action.evaluate(el => el.outerHTML));
  }
}

// A129: every directory row keeps a readable information column whose lines and chips neither overlap
// nor overflow. On a narrow screen the app actions sit on their own line below the information; on a
// wide one they stay on the same line, after it.
async function rowLayout(page, narrow) {
  const rows = await page.locator('.crow-row').evaluateAll(all => all.map(row => {
    const box = el => {
      const r = el.getBoundingClientRect();
      return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width };
    };
    const actions = row.querySelector('.crow-actions');
    return {
      label: row.querySelector('.crow-name')?.textContent ?? '',
      row: box(row),
      info: box(row.querySelector('.crow')),
      main: box(row.querySelector('.crow-main')),
      actions: actions && actions.children.length > 0 ? box(actions) : null,
      parts: [...row.querySelectorAll('.crow-name, .crow-sub, .crow-shared, .tier, .crow-open')].map(el => ({
        text: el.textContent.trim(), ...box(el), overflow: el.scrollWidth > el.clientWidth + 1,
      })),
    };
  }));
  assert.ok(rows.length > 0);
  for (const row of rows) {
    const detail = JSON.stringify(row);
    assert.ok(row.main.width >= row.row.width * (narrow ? 0.6 : 0.3), 'information column is not readable: ' + detail);
    for (const part of row.parts) assert.equal(part.overflow, false, `"${part.text}" overflows its box: ` + detail);
    for (let i = 0; i < row.parts.length; i++) for (let j = i + 1; j < row.parts.length; j++) {
      const [a, b] = [row.parts[i], row.parts[j]];
      const overlap = Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1
        && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1;
      assert.equal(overlap, false, `"${a.text}" overlaps "${b.text}": ` + detail);
    }
    if (row.actions === null) continue;
    if (narrow) assert.ok(row.actions.top >= row.info.bottom - 1, 'actions are not on their own line below: ' + detail);
    else assert.ok(row.actions.top < row.info.bottom && row.actions.left >= row.info.right - 1, 'actions left the row line: ' + detail);
  }
}

try {
  for (const theme of ['light', 'dark']) for (const listCase of ['directory', 'empty', 'unavailable', 'other-provider']) {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      await test(`client shared directory: ${listCase} ${theme} ${viewport.width}`, async () => {
        const context = await browser.newContext({ viewport });
        const problems = [];
        await route(context, theme, listCase, problems);
        const page = await context.newPage();
        page.on('pageerror', error => problems.push(error.message));
        page.on('console', message => { if (['error', 'warning'].includes(message.type())) problems.push(message.text()); });
        try {
          await page.goto('http://safeharbor.test/clients.php');
          await page.getByRole('heading', { name: 'Clients', exact: true, level: 1 }).waitFor();
          assert.equal(await page.title(), 'Clients · Safeharbor');
          await layout(page, theme, viewport);
          await rowLayout(page, viewport.width <= 640);
          const classified = ['directory', 'empty'].includes(listCase);
          assert.equal(await page.getByRole('heading', { name: 'Businesses', exact: true }).count(), classified ? 1 : 0);
          assert.equal(await page.getByRole('heading', { name: 'Retained and local', exact: true }).count(), classified ? 1 : 0);
          assert.equal(await page.getByRole('status').filter({ hasText: 'directory is unavailable right now' }).count(), classified ? 0 : 1);
          assert.equal(await page.getByRole('status').filter({ hasText: 'lists no shared businesses' }).count(), listCase === 'empty' ? 1 : 0);
          for (const name of tenantClients) assert.equal(await page.getByText(name, { exact: true }).count(), 1, name);
          assert.equal(await page.getByText('Other MSP Client').count(), 0);
          assert.equal(await page.locator('a[href*="coastmark.8westit.com/clients"]').count(), 0);
          for (const link of await page.getByRole('link', { name: 'Billing ↗' }).all()) assert.equal(await link.getAttribute('href'), billing);
          if (listCase === 'directory') {
            const primary = page.locator('[aria-label="Businesses"]');
            const retained = page.locator('[aria-label="Retained and local"]');
            for (const name of ['Harbor Dental', 'Lagged Labs', 'Valley Veterinary']) assert.equal(await primary.getByText(name, { exact: true }).count(), 1, name);
            for (const name of ['Beta Canary', 'Copper Kettle', 'Email Intake', 'Northwind Partners', 'Drifted Co', 'Removed Rentals']) {
              assert.equal(await retained.getByText(name, { exact: true }).count(), 1, name);
            }
            assert.equal(await primary.getByText('Safeharbor last received: Lagged Labs · inactive (version 1)').count(), 1);
            assert.equal(await retained.locator('[data-client-id="37"]').getByText('Shared details unavailable').count(), 1);
            const valley = primary.locator('[data-customer-pending="1"]');
            assert.equal(await valley.count(), 1);
            assert.equal(await valley.getByRole('link', { name: 'Support' }).count(), 0);
            assert.equal(await valley.getByRole('link', { name: 'Devices ↗' }).getAttribute('href'), devices(5));
            const harbor = primary.locator('.crow-row').filter({ has: page.locator('[data-client-id="30"]') });
            assert.equal(await harbor.getByRole('link', { name: 'Devices ↗' }).getAttribute('href'), devices(1));
            assert.equal(await harbor.getByRole('link', { name: 'Support' }).getAttribute('href'), '/client.php?id=30');
            // Keyboard: the client link, then its Support action, with a visible focus ring.
            await harbor.locator('.crow').focus();
            await page.keyboard.press('Tab');
            const support = harbor.getByRole('link', { name: 'Support' });
            assert.equal(await support.evaluate(el => el === document.activeElement && getComputedStyle(el).outlineStyle !== 'none'), true);
            if (evidence) await page.screenshot({ path: path.join(evidence, `clients-${listCase}-${theme}-${viewport.width}.png`), fullPage: true });
            const [milepost] = await Promise.all([context.waitForEvent('page'), harbor.getByRole('link', { name: 'Devices ↗' }).click()]);
            await milepost.getByRole('heading', { name: 'Milepost devices destination fixture' }).waitFor();
            assert.equal(milepost.url(), devices(1));
            await milepost.close();
            const [coastmark] = await Promise.all([context.waitForEvent('page'), harbor.getByRole('link', { name: 'Billing ↗' }).click()]);
            await coastmark.getByRole('heading', { name: 'Coastmark destination fixture' }).waitFor();
            await coastmark.close();
            await support.focus();
            await page.keyboard.press('Enter');
            await page.getByRole('heading', { name: 'Harbor Dental', exact: true, level: 1 }).waitFor();
            assert.equal(await page.title(), 'Harbor Dental · Safeharbor');
            await layout(page, theme, viewport);
            assert.equal(await page.getByText('Milepost directory now:').count(), 1);
            assert.equal(await page.getByRole('link', { name: 'View this client in Milepost ↗' }).getAttribute('href'), devices(1));
            assert.equal(await page.getByRole('link', { name: 'View in Coastmark ↗' }).getAttribute('href'), billing);
            if (evidence) await page.screenshot({ path: path.join(evidence, `client-business-${theme}-${viewport.width}.png`), fullPage: true });
            await page.goto('http://safeharbor.test/client.php?id=31');
            await page.getByRole('heading', { name: 'Beta Canary', exact: true, level: 1 }).waitFor();
            assert.equal(await page.getByText('Not in Milepost’s current client directory; kept here as retained history.').count(), 1);
            await layout(page, theme, viewport);
            if (evidence) await page.screenshot({ path: path.join(evidence, `client-retained-${theme}-${viewport.width}.png`), fullPage: true });
          } else {
            if (listCase === 'empty') {
              assert.equal(await page.locator('[aria-label="Businesses"]').getByText('No active shared business is linked here yet.').count(), 1);
            }
            assert.equal(await page.getByText('Valley Veterinary').count(), 0);
            const first = page.locator('.crow').first();
            await first.focus();
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.activeElement?.textContent?.trim()), 'Support');
            assert.equal(await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle !== 'none'), true);
            if (evidence) await page.screenshot({ path: path.join(evidence, `clients-${listCase}-${theme}-${viewport.width}.png`), fullPage: true });
          }
          assert.deepEqual(problems, []);
        } finally { await context.close(); }
      });
    }
  }
} finally {
  await browser.close();
  await rm(scratch, { recursive: true, force: true });
}
