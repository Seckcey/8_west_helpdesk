/**
 * Westy bubble: minimize + drag acceptance check.
 *
 * Serves the REAL app/public assets (css/js) through Playwright's router under
 * a fake origin, wrapped in the same markup lib/westy.php emits. No server, no
 * database, no network — so this runs anywhere the repo is checked out.
 *
 *   node westy-drag.test.mjs
 */
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const PUBLIC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../app/public');
const ORIGIN = 'http://safeharbor.test';

const PAGE = `<!doctype html><meta charset="utf-8"><title>Westy drag</title>
<link rel="stylesheet" href="/assets/css/app.css">
<body>
<div id="westy-root" data-csrf="test" data-onboarded="1" data-name="Frankie">
  <button type="button" id="westy-bubble" aria-expanded="false" aria-controls="westy-panel"
          aria-label="Ask Westy"><img src="/assets/img/westy-avatar.png" alt="Westy" width="56" height="56"></button>
  <section id="westy-panel" hidden aria-label="Chat with Westy">
    <header class="westy-head">
      <img class="westy-head-avatar" src="/assets/img/westy-avatar.png" alt="" width="28" height="28">
      <b>Westy</b><span class="westy-sub">Safeharbor helper · advises, never acts</span>
      <button type="button" id="westy-close" aria-label="Close chat">&times;</button>
    </header>
    <div class="westy-log" id="westy-log" aria-live="polite"></div>
    <form id="westy-form" autocomplete="off">
      <input id="westy-input" maxlength="2000" placeholder="Ask…" required>
      <button id="westy-send" type="submit">Send</button>
    </form>
  </section>
</div>
<script src="/assets/js/westy.js"></script>`;

const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png' };

const results = [];
const check = (name, pass, detail) => {
  results.push({ name, pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? `  — ${detail}` : ''}`);
};

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
page.on('pageerror', (e) => check('no page errors', false, e.message));

await page.route('**/*', async (route) => {
  const url = new URL(route.request().url());
  if (url.pathname === '/' || url.pathname === '/index.html') {
    return route.fulfill({ contentType: 'text/html', body: PAGE });
  }
  try {
    const file = path.join(PUBLIC, url.pathname);
    return route.fulfill({ contentType: MIME[path.extname(file)] || 'application/octet-stream',
                           body: await readFile(file) });
  } catch { return route.fulfill({ status: 404, body: '' }); }
});

const box = () => page.locator('#westy-bubble').boundingBox();
const panelBox = () => page.locator('#westy-panel').boundingBox();
const panelShown = () => page.evaluate(() =>
  getComputedStyle(document.getElementById('westy-panel')).display !== 'none');

async function dragBubble(dx, dy) {
  const b = await box();
  const cx = b.x + b.width / 2, cy = b.y + b.height / 2;
  await page.mouse.move(cx, cy);
  await page.mouse.down();
  for (let i = 1; i <= 8; i++) await page.mouse.move(cx + (dx * i) / 8, cy + (dy * i) / 8);
  await page.mouse.up();
}

await page.goto(`${ORIGIN}/`);

// ── 1. minimize (the original bug) ──────────────────────────────────────────
check('panel starts hidden', !(await panelShown()));
await page.click('#westy-bubble');
check('bubble opens the panel', await panelShown());
await page.click('#westy-close');
check('close button minimizes the panel', !(await panelShown()));

// ── 2. drag moves Westy, and does not count as a click ──────────────────────
const before = await box();
await dragBubble(-400, -300);
const after = await box();
check('drag moves the bubble',
  Math.abs(after.x - (before.x - 400)) < 12 && Math.abs(after.y - (before.y - 300)) < 12,
  `${Math.round(before.x)},${Math.round(before.y)} → ${Math.round(after.x)},${Math.round(after.y)}`);
check('dragging does not open the chat', !(await panelShown()));

// ── 3. a real click still opens ─────────────────────────────────────────────
await page.click('#westy-bubble');
check('click after a drag still opens the chat', await panelShown());

// ── 4. the open panel stays fully on screen wherever Westy sits ─────────────
{
  const vp = page.viewportSize();
  const p = await panelBox();
  check('panel fully on screen after moving up-left',
    p.x >= 0 && p.y >= 0 && p.x + p.width <= vp.width && p.y + p.height <= vp.height,
    `panel ${Math.round(p.x)},${Math.round(p.y)} ${Math.round(p.width)}x${Math.round(p.height)} in ${vp.width}x${vp.height}`);
}

// ── 5. cannot be thrown off screen ──────────────────────────────────────────
await page.click('#westy-close');
await dragBubble(-3000, -3000);
{
  const b = await box();
  check('clamped at the top-left edge', b.x >= -1 && b.y >= -1, `${Math.round(b.x)},${Math.round(b.y)}`);
}
await dragBubble(5000, 5000);
{
  const b = await box(), vp = page.viewportSize();
  check('clamped at the bottom-right edge',
    b.x + b.width <= vp.width + 1 && b.y + b.height <= vp.height + 1,
    `${Math.round(b.x + b.width)},${Math.round(b.y + b.height)} in ${vp.width}x${vp.height}`);
}

// ── 5b. the panel fits on screen from every corner ──────────────────────────
{
  const vp = page.viewportSize();
  const corners = [['top-left', -5000, -5000], ['top-right', 5000, -5000],
                   ['bottom-left', -5000, 5000], ['bottom-right', 5000, 5000]];
  for (const [name, dx, dy] of corners) {
    if (await panelShown()) await page.click('#westy-close');
    await dragBubble(dx, dy);
    await page.click('#westy-bubble');
    const p = await panelBox();
    check(`panel fits when Westy is ${name}`,
      p.x >= -1 && p.y >= -1 && p.x + p.width <= vp.width + 1 && p.y + p.height <= vp.height + 1,
      `panel ${Math.round(p.x)},${Math.round(p.y)} ${Math.round(p.width)}x${Math.round(p.height)}`);
  }
}

// ── 5c. the panel header drags the whole widget too ─────────────────────────
{
  if (!(await panelShown())) await page.click('#westy-bubble');
  const b0 = await box();
  const h = await page.locator('.westy-head').boundingBox();
  await page.mouse.move(h.x + 40, h.y + h.height / 2);
  await page.mouse.down();
  for (let i = 1; i <= 6; i++) await page.mouse.move(h.x + 40 - (150 * i) / 6, h.y + h.height / 2 - (60 * i) / 6);
  await page.mouse.up();
  const b1 = await box();
  check('header drags the whole widget',
    Math.abs(b1.x - (b0.x - 150)) < 12 && Math.abs(b1.y - (b0.y - 60)) < 12,
    `${Math.round(b0.x)},${Math.round(b0.y)} → ${Math.round(b1.x)},${Math.round(b1.y)}`);
  check('panel stays open while dragged by its header', await panelShown());
}

// ── 5d. the close button still closes, it is not a drag handle ──────────────
{
  if (!(await panelShown())) await page.click('#westy-bubble');
  await page.click('#westy-close');
  check('close still works after dragging', !(await panelShown()));
}

// ── 6. position survives a reload ───────────────────────────────────────────
await dragBubble(-500, -200);
const moved = await box();
await page.reload();
const reloaded = await box();
check('position persists across reload',
  Math.abs(reloaded.x - moved.x) < 2 && Math.abs(reloaded.y - moved.y) < 2,
  `${Math.round(moved.x)},${Math.round(moved.y)} → ${Math.round(reloaded.x)},${Math.round(reloaded.y)}`);

// ── 7. a smaller window pulls Westy back into view ──────────────────────────
await page.setViewportSize({ width: 640, height: 480 });
await page.waitForTimeout(120);
{
  const b = await box();
  check('re-clamped after the window shrinks',
    b.x >= -1 && b.y >= -1 && b.x + b.width <= 641 && b.y + b.height <= 481,
    `${Math.round(b.x)},${Math.round(b.y)} ${Math.round(b.width)}x${Math.round(b.height)}`);
}

await browser.close();
const failed = results.filter((r) => !r.pass);
console.log(`\n${results.length - failed.length}/${results.length} passed`);
process.exit(failed.length ? 1 : 0);
