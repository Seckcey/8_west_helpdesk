/**
 * Safeharbor visual walkthrough (dev tooling — not part of the app stack).
 *
 *   cd tools/shots && npm install
 *   BASE=https://safeharbor.8westit.com node walkthrough.mjs
 *
 * Captures: login, queue, keyboard ops, ticket, reply, clients, client,
 * time, palette → PNGs in C:/tmp/shots (or $SHOTS).
 */
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const BASE = process.env.BASE ?? "https://safeharbor.8westit.com";
const OUT = process.env.SHOTS ?? "C:/tmp/shots";
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1440, height: 900 },
  deviceScaleFactor: 1.5,
});

const shot = async (name) => {
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/${name}.png` });
  console.log(`shot ${name}`);
};

// 1. login page
await page.goto(`${BASE}/login.php`, { waitUntil: "networkidle" });
await shot("01-login");

// 2. sign in with demo credentials
await page.fill('input[name="password"]', "harbor");
await page.getByRole("button", { name: "Sign in" }).click();
await page.waitForURL(`${BASE}/`);
await page.waitForTimeout(400);
await shot("02-queue");

// 3. keyboard: move, then change priority (optimistic + toast)
await page.keyboard.press("j");
await page.keyboard.press("j");
await page.keyboard.press("p");
await shot("03-queue-keyboard");

// 4. open ticket via Enter
await page.keyboard.press("Enter");
await page.waitForURL(/ticket\.php\?id=\d+/);
await shot("04-ticket");

// 5. reply flow
await page.keyboard.press("r");
await page.keyboard.type("On it — renewing the imaging license now.");
await shot("05-ticket-reply");
await page.keyboard.press("Control+Enter");
await page.waitForTimeout(500);
await shot("06-ticket-sent");

// 6. clients + client
await page.goto(`${BASE}/clients.php`);
await shot("07-clients");
await page.waitForTimeout(400);
await page.keyboard.press("Enter");
await page.waitForURL(/client\.php\?id=\d+/);
await shot("08-client");

// 7. time (start a timer from the queue first)
await page.goto(`${BASE}/`);
await page.waitForTimeout(400);
await page.keyboard.press("e");
await page.waitForTimeout(300);
await page.goto(`${BASE}/time.php`);
await shot("09-time");

// 8. palette
await page.keyboard.press("Control+k");
await page.keyboard.type("print");
await shot("10-palette");

await browser.close();
console.log("done");
