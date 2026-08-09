/**
 * Quick Westy probe against a running Safeharbor: sign in, open the bubble,
 * and report what Westy is actually doing — including which version of the
 * SHARED layout file the page is running.
 *
 *   node westy-check.mjs                       # production, seeded demo user
 *   SH_EMAIL=you@example.com SH_PASSWORD=… node westy-check.mjs
 *   SH_BASE=https://staging.example node westy-check.mjs
 *
 * Moving and resizing are NOT tested here — they live in the shared package
 * (Seckcey/8_west_westy) and are covered by westy-contract.test.mjs, which
 * needs no login. This probe is for "what is production actually doing".
 */
import { chromium } from 'playwright';

const BASE = process.env.SH_BASE || 'https://safeharbor.8westit.com';
const EMAIL = process.env.SH_EMAIL || 'ana@8westit.com';
const PASSWORD = process.env.SH_PASSWORD || 'harbor';
const SHOT = process.env.SH_SHOT || '';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
page.on('console', (m) => { if (m.type() === 'error') console.log('CONSOLE ERROR:', m.text()); });
page.on('pageerror', (e) => console.log('PAGE ERROR:', e.message));

await page.goto(`${BASE}/login.php`);
await page.fill('input[name=email]', EMAIL);
await page.fill('input[name=password]', PASSWORD);
await page.click('button[type=submit]');

/* Wait for EITHER the queue or a rejected sign-in. Racing them turns a wrong
   password into one clear line instead of a 15-second timeout and a stack
   trace that says nothing about the real problem — which is exactly how the
   old hard-coded login wasted time on 2026-08-08 after it silently went
   stale. Any seeded email works; the password hint is on the login page. */
const outcome = await Promise.race([
  page.waitForSelector('.trow', { timeout: 20000 }).then(() => 'in'),
  page.waitForSelector('text=didn’t match', { timeout: 20000 }).then(() => 'rejected'),
]).catch(() => 'timeout');

if (outcome !== 'in') {
  console.log(outcome === 'rejected'
    ? `SIGN-IN REJECTED for ${EMAIL} — set SH_EMAIL / SH_PASSWORD to a real account.`
    : `NO QUEUE AND NO ERROR after 20s at ${BASE} — is it up?`);
  await browser.close();
  process.exit(1);
}

await page.waitForTimeout(2500);   // past the 1.2s first-run auto-open

const root = page.locator('#westy-root');
console.log('shared layout version:', await root.getAttribute('data-westy-version') || 'NOT LOADED');
console.log('panel visible       :', await page.evaluate(() =>
  getComputedStyle(document.getElementById('westy-panel')).display !== 'none'));
console.log('onboarded           :', await root.getAttribute('data-onboarded'));
console.log('resize grip         :', await page.locator('.westy-resize').count() ? 'present' : 'MISSING');

await page.evaluate(() => document.getElementById('westy-bubble').click());
await page.waitForTimeout(600);
console.log('opens on click      :', await page.evaluate(() =>
  getComputedStyle(document.getElementById('westy-panel')).display !== 'none'));
console.log('log innerText       :', JSON.stringify(await page.locator('#westy-log').innerText()));
console.log('chip count          :', await page.locator('.westy-chip').count());

if (SHOT) {
  await page.screenshot({ path: SHOT });
  console.log('screenshot          :', SHOT);
}
await browser.close();
