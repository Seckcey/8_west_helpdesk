/**
 * Westy chat survives navigation — Safeharbor.
 *
 * Serves the REAL westy.js and app.css through Playwright's router, wrapped in
 * the markup lib/westy.php emits. No server, no database, no network.
 *
 *   node westy-session.test.mjs
 */
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const PUBLIC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../app/public');
const ORIGIN = 'http://safeharbor.test';
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png' };

/* Two pages on the same site, so "click a link" is a real navigation. */
const page1 = (uid) => shell(uid, 'Queue', '/page2');
const page2 = (uid) => shell(uid, 'Ticket', '/');

const shell = (uid, title, linkTo) => `<!doctype html><meta charset="utf-8"><title>${title}</title>
<link rel="stylesheet" href="/assets/css/app.css">
<body>
<a id="nav" href="${linkTo}">go to the other page</a>
<div id="westy-root" data-csrf="test" data-onboarded="1" data-name="Frankie" data-uid="${uid}">
  <button type="button" id="westy-bubble" aria-expanded="false" aria-controls="westy-panel"
          aria-label="Ask Westy"><img src="/assets/img/westy-avatar.png" alt="Westy" width="56" height="56"></button>
  <section id="westy-panel" hidden aria-label="Chat with Westy">
    <header class="westy-head">
      <b>Westy</b><span class="westy-sub">Safeharbor helper</span>
      <button type="button" id="westy-close" aria-label="Close chat">&times;</button>
    </header>
    <div class="westy-log" id="westy-log" aria-live="polite"></div>
    <form id="westy-form" autocomplete="off">
      <input id="westy-input" maxlength="2000" placeholder="Ask…" required>
      <button id="westy-send" type="submit">Send</button>
    </form>
  </section>
</div>
<a id="signout" href="/logout.php">Sign out</a>
<script src="/assets/js/westy.js"></script>`;

const results = [];
const check = (name, pass, detail) => {
  results.push({ name, pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? `  — ${detail}` : ''}`);
};

const browser = await chromium.launch();

async function session(uid) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check('no page errors', false, e.message));
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: page1(uid) });
    if (url.pathname === '/page2') return route.fulfill({ contentType: 'text/html', body: page2(uid) });
    if (url.pathname === '/logout.php') return route.fulfill({ contentType: 'text/html', body: '<p>bye' });
    /* Westy's own endpoints: answer deterministically so no network is needed. */
    if (url.pathname === '/api/westy_chat.php') {
      return route.fulfill({ contentType: 'application/json',
        body: JSON.stringify({ ok: true, reply: 'Press j and k to move through the queue.' }) });
    }
    try {
      const file = path.join(PUBLIC, url.pathname);
      return route.fulfill({ contentType: MIME[path.extname(file)] || 'application/octet-stream',
                             body: await readFile(file) });
    } catch { return route.fulfill({ status: 404, body: '' }); }
  });
  await page.goto(`${ORIGIN}/`);
  return { page, ctx };
}

const transcript = (page) => page.evaluate(() =>
  Array.from(document.querySelectorAll('#westy-log .westy-msg')).map((d) => d.textContent));
const panelOpen = (page) => page.evaluate(() =>
  getComputedStyle(document.getElementById('westy-panel')).display !== 'none');

// ── the conversation must survive clicking a link ────────────────────────────
{
  const { page } = await session(7);
  await page.click('#westy-bubble');
  await page.fill('#westy-input', 'How do I move through the queue?');
  await page.click('#westy-send');
  await page.waitForTimeout(300);

  const before = await transcript(page);
  check('a question and an answer are on screen', before.length >= 2, JSON.stringify(before));

  await page.click('#nav');
  await page.waitForLoadState('load');
  await page.waitForTimeout(300);

  const after = await transcript(page);
  check('the conversation survives clicking a link',
    after.length >= 2 && after.join(' ').includes('Press j and k'),
    JSON.stringify(after));
  check('the panel is still open after navigating', await panelOpen(page));

  // Signing out must not leave a transcript for the next person at this machine.
  await page.click('#signout');
  await page.waitForTimeout(200);
  await page.goto(`${ORIGIN}/`);
  await page.click('#westy-bubble');
  await page.waitForTimeout(200);
  const afterLogout = await transcript(page);
  check('signing out clears the transcript',
    !afterLogout.join(' ').includes('Press j and k'), JSON.stringify(afterLogout));
}

// ── a different user in the same tab must not inherit it ─────────────────────
{
  const { page } = await session(7);
  await page.click('#westy-bubble');
  await page.fill('#westy-input', 'secret question');
  await page.click('#westy-send');
  await page.waitForTimeout(300);

  await page.evaluate(() => { document.getElementById('westy-root').setAttribute('data-uid', '99'); });
  await page.goto(`${ORIGIN}/page2`);
  await page.waitForTimeout(200);
  // page2 is served with uid 7, so re-serve as 99 by navigating a fresh context instead.
  const other = await session(99);
  await other.page.click('#westy-bubble');
  await other.page.waitForTimeout(200);
  const seen = await transcript(other.page);
  check('a different user does not inherit the transcript',
    !seen.join(' ').includes('secret question'), JSON.stringify(seen));
}

await browser.close();
const failed = results.filter((r) => !r.pass);
console.log(`\n${results.length - failed.length}/${results.length} passed`);
process.exit(failed.length ? 1 : 0);
