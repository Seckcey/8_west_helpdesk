import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The first-response tag in a browser, on the real stylesheet: one queue row for each of its seven
// states, built from the real rules (app/tests/ticket_queue_test.php --render). The words are
// checked in PHP; this checks what a person sees: red only for what is late now, quiet text once a
// ticket is answered, nothing blinking, every state readable in both themes and on a phone.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const FIX = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/ticket_queue_test.php'), '--render'], { encoding: 'utf8' }));
const STATES = ['healthy', 'at_risk', 'breached', 'met', 'late', 'closed', 'excluded'];

const html = (theme) => `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Queue</title><style>${CSS}</style></head>
<body data-active="queue"><main class="content"><div class="page">
<div class="page-head"><div><h1 class="page-title">Queue</h1></div></div>
${FIX.tag_rows}</div></main></body></html>`;

async function fixture(t, { width = 1440, theme = 'dark' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  await page.setContent(html(theme));
  await page.waitForFunction(() => [...document.querySelectorAll('.trow')].every((row) => Number(getComputedStyle(row).opacity) > 0.99));
  return page;
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_TAG_SHOTS) return;
  await mkdir(process.env.SAFEHARBOR_TAG_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_TAG_SHOTS, `${name}.png`), fullPage: true });
}

// What each tag looks like: its fill, its text and dot colours, and the contrast of its words
// against whatever is painted behind them.
const looks = (page) => page.evaluate(() => {
  const rgba = (value) => {
    const n = (value.match(/[\d.]+/g) || []).map(Number);
    return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 };
  };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  const behind = (el) => {
    const layers = [];
    for (let node = el; node; node = node.parentElement) layers.push(rgba(getComputedStyle(node).backgroundColor));
    return layers.reverse().reduce((under, top) => over(top, under), { r: 255, g: 255, b: 255, a: 1 });
  };
  const luminance = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
  return [...document.querySelectorAll('.trow-sla .sla')].map((tag) => {
    const style = getComputedStyle(tag);
    const dot = getComputedStyle(tag.querySelector('.sla-dot'));
    return {
      state: tag.className.replace('sla sla-', ''),
      words: tag.textContent.trim(),
      filled: rgba(style.backgroundColor).a > 0,
      fill: style.backgroundColor,
      ink: style.color,
      dot: dot.backgroundColor,
      moving: dot.animationName !== 'none' || style.animationName !== 'none',
      contrast: contrast(over(rgba(style.color), behind(tag)), behind(tag)),
      clipped: tag.scrollWidth > tag.clientWidth,
      height: Math.round(tag.getBoundingClientRect().height),
    };
  });
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: red only for what is late now, and an answered ticket is quiet text with a dot`, async (t) => {
    const page = await fixture(t, { theme });
    const tags = await looks(page);
    assert.deepEqual(tags.map((tag) => tag.state), STATES);
    const by = Object.fromEntries(tags.map((tag) => [tag.state, tag]));

    // Three filled tags, and they are three different tags: plain, caution, urgent.
    assert.equal(by.healthy.filled && by.at_risk.filled && by.breached.filled && by.excluded.filled, true);
    assert.equal(new Set([by.healthy.fill, by.at_risk.fill, by.breached.fill]).size, 3);
    assert.equal(new Set([by.healthy.ink, by.at_risk.ink, by.breached.ink]).size, 3);
    // Merged is the plain tag too, and so is a ticket closed without a reply: it is not late now.
    assert.deepEqual([by.excluded.fill, by.excluded.ink], [by.healthy.fill, by.healthy.ink]);
    assert.deepEqual([by.closed.fill, by.closed.ink], [by.healthy.fill, by.healthy.ink]);
    assert.equal(by.closed.words, 'Closed without a reply');

    // Answered: no fill, the same quiet text for both, and only the dot tells them apart.
    assert.equal(by.met.filled || by.late.filled, false);
    assert.equal(by.met.ink, by.late.ink);
    assert.notEqual(by.met.dot, by.late.dot);
    assert.notEqual(by.met.dot, by.met.ink);
    assert.notEqual(by.late.dot, by.late.ink);
    // Answered late is not the urgent look in any part.
    assert.notEqual(by.late.ink, by.breached.ink);
    assert.notEqual(by.late.dot, by.breached.dot);

    for (const tag of tags) {
      assert.equal(tag.moving, false, `${tag.state} does not blink`);
      assert.ok(tag.contrast >= 4.5, `${tag.state} reads at ${tag.contrast.toFixed(2)} to 1`);
      assert.equal(tag.clipped, false, `${tag.state} shows all its words`);
      assert.equal(tag.height, 22, `${tag.state} is a 22px tag`);
    }
    await shot(page, `first-response-1440-${theme}`);
  });
}

test('phone: every tag shows all its words and the page does not scroll sideways', async (t) => {
  for (const theme of ['dark', 'light']) {
    const page = await fixture(t, { width: 390, theme });
    const tags = await looks(page);
    assert.deepEqual(tags.map((tag) => tag.state), STATES);
    for (const tag of tags) {
      assert.equal(tag.clipped, false, `${tag.state} shows all its words`);
      assert.ok(tag.contrast >= 4.5, `${tag.state} reads at ${tag.contrast.toFixed(2)} to 1`);
    }
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await shot(page, `first-response-390-${theme}`);
  }
});
