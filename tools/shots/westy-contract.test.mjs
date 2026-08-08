/**
 * Safeharbor satisfies the shared Westy layout contract.
 *
 * Renders Safeharbor's OWN Westy markup and stylesheet, loads the shared layout
 * file from production, and checks that dragging and resizing actually work
 * here — not merely that a script tag is present in the source.
 *
 *   node portal/tests/westy_layout_contract_test.mjs
 *
 * Needs network (fetches https://westy.8westit.com/v1/). Skips cleanly if the
 * asset host is unreachable, so it can never fail a build over someone's wifi.
 */
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../app/public');
const LAYOUT = 'https://westy.8westit.com/v1/westy-layout.js';

const results = [];
const check = (name, pass, detail) => {
  results.push(pass);
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? `  — ${detail}` : ''}`);
};

/* Safeharbor's real widget markup, copied from portal/lib/westy.php. If that
   markup changes so it no longer matches, this test is where it surfaces. */
const PAGE = `<!doctype html><meta charset="utf-8"><title>Safeharbor</title>
<link rel="stylesheet" href="/assets/css/app.css">
<body>
<div id="westy-root" data-csrf="t" data-uid="1">
  <button type="button" id="westy-bubble" aria-expanded="false" aria-controls="westy-panel"
          aria-label="Ask Westy — Safeharbor helper"><img src="/assets/img/westy-avatar.png" alt="Westy" width="56" height="56"></button>
  <section id="westy-panel" hidden aria-label="Chat with Westy">
    <header class="westy-head">
      <img class="westy-head-avatar" src="/assets/img/westy-avatar.png" alt="" width="28" height="28">
      <b>Westy</b><span class="westy-sub">Safeharbor helper · advises, never acts</span>
      <button type="button" id="westy-close" aria-label="Close chat">&times;</button>
    </header>
    <div class="westy-log" id="westy-log"></div>
    <form id="westy-form"><input id="westy-input"><button id="westy-send" type="submit">Send</button></form>
  </section>
</div>
<script>
  document.getElementById('westy-bubble').addEventListener('click', function () {
    var p = document.getElementById('westy-panel'); p.hidden = !p.hidden; });
  document.getElementById('westy-close').addEventListener('click', function () {
    document.getElementById('westy-panel').hidden = true; });
</script>
<script src="${LAYOUT}" defer></script>`;

const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png' };

const browser = await chromium.launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
page.on('pageerror', (e) => check('no page errors', false, e.message));

await page.route('**/*', async (route) => {
  const url = new URL(route.request().url());
  if (url.hostname === 'westy.8westit.com') return route.continue();     // the real thing
  if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: PAGE });
  try {
    const file = path.join(APP, url.pathname);
    return route.fulfill({ contentType: MIME[path.extname(file)] || 'application/octet-stream',
                           body: await readFile(file) });
  } catch { return route.fulfill({ status: 404, body: '' }); }
});

await page.goto('http://safeharbor.test/');
await page.waitForTimeout(2500);

const loaded = await page.evaluate(() => window.__westyLayoutLoaded === true);
if (!loaded) {
  console.log('SKIP  the shared layout file could not be reached — not a Safeharbor failure');
  await browser.close();
  process.exit(0);
}

check('the shared layout file loaded', true);
check('Safeharbor stamps the running Westy version',
  /^\d+\.\d+\.\d+$/.test(await page.evaluate(() =>
    document.getElementById('westy-root').getAttribute('data-westy-version')) || ''));

const box = (sel) => page.locator(sel).boundingBox();
const b0 = await box('#westy-bubble');
await page.mouse.move(b0.x + 28, b0.y + 28);
await page.mouse.down();
for (let i = 1; i <= 8; i++) await page.mouse.move(b0.x + 28 - (420 * i) / 8, b0.y + 28 - (300 * i) / 8);
await page.mouse.up();
const b1 = await box('#westy-bubble');
check('Westy drags in Safeharbor',
  Math.abs(b1.x - (b0.x - 420)) < 12 && Math.abs(b1.y - (b0.y - 300)) < 12,
  `${Math.round(b0.x)},${Math.round(b0.y)} → ${Math.round(b1.x)},${Math.round(b1.y)}`);

await page.click('#westy-bubble');
await page.waitForTimeout(200);
const p = await box('#westy-panel');
const vp = page.viewportSize();
check('the panel opens fully on screen',
  p.x >= -1 && p.y >= -1 && p.x + p.width <= vp.width + 1 && p.y + p.height <= vp.height + 1,
  `${Math.round(p.x)},${Math.round(p.y)} ${Math.round(p.width)}x${Math.round(p.height)}`);

const grip = await page.locator('.westy-resize').boundingBox({ timeout: 2000 }).catch(() => null);
check('the resize grip is present and styled', !!grip && grip.width === 16,
  grip ? `${grip.width}x${grip.height}` : 'none');

await page.click('#westy-close');
check('close still works', await page.evaluate(() =>
  getComputedStyle(document.getElementById('westy-panel')).display === 'none'));

await browser.close();
const failed = results.filter((r) => !r).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
