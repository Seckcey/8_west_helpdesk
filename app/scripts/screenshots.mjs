/**
 * Visual walkthrough of the Phase 0 prototype (run against `vite preview`).
 * Usage: node scripts/screenshots.mjs   → PNGs in C:/tmp/shots
 */
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const BASE = process.env.BASE ?? "http://localhost:4173";
const OUT = process.env.SHOTS ?? "C:/tmp/shots";
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1440, height: 900 },
  deviceScaleFactor: 1.5,
});

const shot = async (name) => {
  await page.waitForTimeout(350);
  await page.screenshot({ path: `${OUT}/${name}.png` });
  console.log(`shot ${name}`);
};

// 1. login
await page.goto(`${BASE}/login`, { waitUntil: "networkidle" });
await shot("01-login");

// 2. sign in → queue
await page.getByRole("button", { name: "Sign in" }).click();
await page.waitForURL(`${BASE}/`);
await page.waitForTimeout(300);
await shot("02-queue");

// 3. keyboard: move down twice, bump priority (toast + optimistic row change)
await page.waitForTimeout(500); // let hydration settle before key events
await page.keyboard.press("j");
await page.keyboard.press("j");
await page.keyboard.press("p");
await shot("03-queue-keyboard");

// 4. open ticket via Enter
await page.keyboard.press("Enter");
await page.waitForURL(/tickets\/\d+/);
await shot("04-ticket");

// 5. reply flow: type and send
await page.keyboard.press("r");
await page.keyboard.type("On it — running the imaging license renewal now.");
await shot("05-ticket-reply");
await page.keyboard.press("Control+Enter");
await shot("06-ticket-sent");

// 6. clients
await page.goto(`${BASE}/clients`);
await shot("07-clients");
await page.waitForTimeout(500);
await page.keyboard.press("Enter");
await page.waitForURL(/clients\/cli_/);
await shot("08-client");

// 7. time (start a timer first from the queue shortcut path)
await page.goto(`${BASE}/`);
await page.waitForTimeout(500);
await page.keyboard.press("e");
await page.waitForTimeout(250);
await page.goto(`${BASE}/time`);
await shot("09-time");

// 8. palette
await page.keyboard.press("Control+k");
await page.keyboard.type("print");
await shot("10-palette");

await browser.close();
console.log("done");
