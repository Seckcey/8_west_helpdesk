import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// SH-33: every staff button that removes something asks first, naming exactly what it removes,
// whatever the name contains. Cancel sends nothing; OK posts the form. The pages are the actual
// client, client edit, clients and saved-replies pages, rendered by
// app/tests/destructive_confirm_ui_test.php from synthetic data and served with the real app.js.
// Contact names can come from an inbound email's sender name, so a name must never run as script.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scratch = await mkdtemp(path.join(tmpdir(), 'safeharbor-confirm-'));
const rendered = spawnSync('php', ['app/tests/destructive_confirm_ui_test.php'], {
  cwd: root, env: { ...process.env, CONFIRM_UI_CAPTURE_DIR: scratch }, encoding: 'utf8',
});
assert.equal(rendered.status, 0, rendered.stdout + rendered.stderr);

const pages = { '/client.php': 'client', '/client_edit.php': 'client-edit', '/clients.php': 'clients', '/snippets.php': 'snippets' };
const browser = await chromium.launch({ headless: true });

async function open(pathname) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const problems = [];
  const posts = [];
  await context.route('**/*', async request => {
    const url = new URL(request.request().url());
    if (url.hostname === 'safeharbor.test' && request.request().method() === 'POST') {
      posts.push({ to: url.pathname + url.search, form: Object.fromEntries(new URLSearchParams(request.request().postData() ?? '')) });
      return request.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>Posted</title><h1>Posted</h1>' });
    }
    if (url.hostname === 'safeharbor.test' && pages[url.pathname]) {
      return request.fulfill({ contentType: 'text/html; charset=utf-8', body: await readFile(path.join(scratch, `${pages[url.pathname]}.html`)) });
    }
    if (url.hostname === 'safeharbor.test' && url.pathname === '/favicon.ico') return request.fulfill({ status: 204, body: '' });
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
  const page = await context.newPage();
  page.on('pageerror', error => problems.push(error.message));
  page.on('console', message => { if (['error', 'warning'].includes(message.type())) problems.push(message.text()); });
  await page.goto('http://safeharbor.test' + pathname);
  return { context, page, problems, posts };
}

/** Submit a removal form the given way, answer its dialog, and report the dialogs and any POST. */
async function press(page, posts, submit, answer) {
  const dialogs = [];
  const handler = dialog => {
    dialogs.push({ type: dialog.type(), message: dialog.message() });
    return answer === 'ok' ? dialog.accept() : dialog.dismiss();
  };
  page.on('dialog', handler);
  const before = posts.length;
  try {
    if (answer === 'ok') {
      await Promise.all([page.waitForRequest(r => r.method() === 'POST'), submit()]);
      await page.getByRole('heading', { name: 'Posted' }).waitFor();
    } else {
      await submit();
      // Give a skipped prompt the chance to post before deciding that nothing left.
      await page.waitForTimeout(500);
    }
  } finally {
    page.off('dialog', handler);
  }
  return { dialogs, posted: posts.slice(before) };
}

const confirmOnce = message => [{ type: 'confirm', message }];

try {
  await test('Remove contact names the contact exactly, and Cancel sends nothing', async () => {
    const { context, page, problems, posts } = await open('/client.php?id=50');
    try {
      await page.getByRole('heading', { name: 'O\'Neil\'s Dental', exact: true, level: 1 }).waitFor();
      for (const name of ['Pat O\'Brien', 'Sam\'+(window.sh33=1)+\'', 'Jo "JJ" Lee \\ Ops']) {
        const row = page.locator('.rail-list-item').filter({ has: page.getByText(name, { exact: true }) });
        const remove = row.locator('button[title="Remove contact"]');
        assert.equal(await remove.count(), 1, name);
        const result = await press(page, posts, () => remove.click(), 'cancel');
        assert.deepEqual(result, { dialogs: confirmOnce(`Remove ${name}?`), posted: [] }, name);
        assert.equal(await page.evaluate(() => window.sh33), undefined, `${name} ran as script`);
      }
      const remove = page.locator('.rail-list-item').filter({ has: page.getByText('Pat O\'Brien', { exact: true }) })
        .locator('button[title="Remove contact"]');
      const result = await press(page, posts, () => remove.click(), 'ok');
      assert.deepEqual(result, { dialogs: confirmOnce('Remove Pat O\'Brien?'), posted: [
        { to: '/client.php?id=50', form: { csrf: 'synthetic-csrf', action: 'remove_contact', contact_id: '61' } },
      ] });
      assert.deepEqual(problems, []);
    } finally {
      await context.close();
    }
  });

  await test('Delete client names the client exactly, by mouse and by keyboard', async () => {
    const { context, page, problems, posts } = await open('/client_edit.php?id=50');
    try {
      const del = page.getByRole('button', { name: 'Delete client', exact: true });
      const message = 'Delete O\'Neil\'s Dental and its contacts? No undo.';
      assert.deepEqual(await press(page, posts, () => del.click(), 'cancel'), { dialogs: confirmOnce(message), posted: [] });
      assert.deepEqual(await press(page, posts, async () => { await del.focus(); await page.keyboard.press('Enter'); }, 'cancel'),
        { dialogs: confirmOnce(message), posted: [] });
      assert.deepEqual(await press(page, posts, () => del.click(), 'ok'), { dialogs: confirmOnce(message), posted: [
        { to: '/client_edit.php?id=50', form: { csrf: 'synthetic-csrf', action: 'delete' } },
      ] });
      assert.deepEqual(problems, []);
    } finally {
      await context.close();
    }
  });

  for (const [pathname, button, message, form] of [
    ['/clients.php', 'Remove demo data', 'Remove all demo clients and their tickets? No undo.',
      { to: '/clients.php', form: { csrf: 'synthetic-csrf', action: 'purge_demo' } }],
    ['/snippets.php', 'Delete', 'Delete this saved reply?',
      { to: '/snippets.php', form: { csrf: 'synthetic-csrf', action: 'delete', snippet_id: '71' } }],
  ]) {
    await test(`${button} on ${pathname} asks first, and Cancel sends nothing`, async () => {
      const { context, page, problems, posts } = await open(pathname);
      try {
        const target = page.getByRole('button', { name: button, exact: true });
        assert.deepEqual(await press(page, posts, () => target.click(), 'cancel'), { dialogs: confirmOnce(message), posted: [] });
        assert.deepEqual(await press(page, posts, () => target.click(), 'ok'), { dialogs: confirmOnce(message), posted: [form] });
        assert.deepEqual(problems, []);
      } finally {
        await context.close();
      }
    });
  }
} finally {
  await browser.close();
  await rm(scratch, { recursive: true, force: true });
}
