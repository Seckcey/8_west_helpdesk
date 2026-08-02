// Quick Westy probe: load the queue as the demo user, wait for the
// first-run auto-open, dump console errors + the panel's text, screenshot.
import { chromium } from 'playwright';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
page.on('console', (m) => { if (m.type() === 'error') console.log('CONSOLE ERROR:', m.text()); });
page.on('pageerror', (e) => console.log('PAGE ERROR:', e.message));

await page.goto('https://safeharbor.8westit.com/login.php');
await page.fill('input[name=email]', 'frankie@8westit.com');
await page.fill('input[name=password]', 'harbor');
await page.click('button[type=submit]');
await page.waitForSelector('.trow', { timeout: 15000 });
await page.waitForTimeout(2500); // past the 1.2s auto-open

console.log('panel hidden attr:', await page.locator('#westy-panel').getAttribute('hidden'));
console.log('onboarded:', await page.locator('#westy-root').getAttribute('data-onboarded'));
console.log('log innerText:', JSON.stringify(await page.locator('#westy-log').innerText()));
console.log('chip count:', await page.locator('.westy-chip').count());
await page.screenshot({ path: 'C:/tmp/shots/westy-check.png' });
await browser.close();
