/**
 * Safeharbor visual walkthrough (dev tooling — not part of the app stack).
 *
 *   cd tools/shots && npm install
 *   BASE=<local-or-staging-origin> SH_EMAIL=<account-email> SH_PASSWORD=<account-password> node walkthrough.mjs
 *
 * Required (no defaults; the operator supplies every one):
 *   BASE         target origin, e.g. http://localhost:8080 (no trailing slash)
 *   SH_EMAIL     sign-in email for an account on that target
 *   SH_PASSWORD  sign-in password for that account
 * Optional:
 *   SHOTS        output directory (default C:/tmp/shots)
 *
 * If any required input is missing, the script names it and exits non-zero
 * before launching a browser, creating the output directory or touching
 * the network.
 *
 * Captures: login, queue, keyboard ops, ticket, reply, clients, client,
 * time, palette → PNGs in C:/tmp/shots (or $SHOTS).
 */
import { mkdirSync } from "node:fs";

const BASE = process.env.BASE ?? "";
const EMAIL = process.env.SH_EMAIL ?? "";
const PASSWORD = process.env.SH_PASSWORD ?? "";
const missing = [
  ["BASE", BASE],
  ["SH_EMAIL", EMAIL],
  ["SH_PASSWORD", PASSWORD],
].filter(([, v]) => v.trim() === "").map(([k]) => k);
if (missing.length) {
  console.error(
    `walkthrough: missing required input ${missing.join(", ")}. ` +
      "Set BASE (local or staging origin), SH_EMAIL and SH_PASSWORD as " +
      "environment variables, e.g. BASE=<origin> SH_EMAIL=<email> " +
      "SH_PASSWORD=<password> node walkthrough.mjs",
  );
  process.exit(2);
}

// Loaded only after the input check so a missing input fails fast and clearly.
const { chromium } = await import("playwright");

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

// 2. sign in with the operator-supplied credentials
await page.fill('input[name="email"]', EMAIL);
await page.fill('input[name="password"]', PASSWORD);
await page.getByRole("button", { name: "Sign in" }).click();
await page.waitForURL(`${BASE}/`);
await page.waitForTimeout(400);
await shot("02-queue");

// 3. keyboard: move, then change priority (optimistic + toast)
await page.keyboard.press("j");
await page.keyboard.press("j");
await page.keyboard.press("p");
await shot("03-queue-keyboard");

// 3b. the shared suite cluster: the app drawer at the right end of the topbar.
// (The theme is a central 8 West ID preference now — it is changed at id.8westit.com,
// not by a switch in this app, so there is no theme control here to photograph.)
await page.click("[data-w365-drawer-button]");
await shot("03b-app-drawer");
await page.keyboard.press("Escape");
await page.waitForTimeout(200);

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
await page.keyboard.press("Escape");

// 9. team page (users CRUD)
await page.goto(`${BASE}/users.php`);
await shot("11-team");

// 9b. account menu (right end of the topbar, beside the app drawer) + profile
await page.click("[data-w365-account-button]");
await shot("11b-account-menu");
await page.keyboard.press("Escape");
await page.goto(`${BASE}/profile.php`);
await shot("11c-profile");

// 10. new client form
await page.goto(`${BASE}/client_new.php`);
await shot("12-client-new");

// 11. new ticket form
await page.goto(`${BASE}/ticket_new.php`);
await shot("13-ticket-new");

// 12. a detail page with the suite cluster open (proof the chrome holds off the queue)
await page.goto(`${BASE}/ticket.php?id=102`);
await page.click("[data-w365-drawer-button]");
await shot("14-ticket-app-drawer");
await page.keyboard.press("Escape");

await browser.close();
console.log("done");
