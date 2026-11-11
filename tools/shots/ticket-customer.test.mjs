import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The ticket page with the customer beside the conversation, on the real stylesheet and script:
// the head with its place in the list and previous/next, one column of message cards, the Details
// column, and the Merge dialog. The page's parts come from the real helpers
// (app/tests/ticket_page_test.php --render); the endpoints are a small stand-in, so every request
// the page makes can be read back.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const FIX = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/ticket_page_test.php'), '--render'], { encoding: 'utf8' }));
const ORIGIN = 'http://safeharbor.test';
const HERE = '/ticket.php?id=1041&in=needs';

const island = (data) => JSON.stringify(data).replace(/</g, '\\u003C');
function html({ theme = 'dark', head = 'head', dupe = false, alone = false } = {}) {
  return `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>#1041</title><style>${CSS}</style></head>
<body data-active="queue" data-tenant-id="1" data-user-id="7" data-csrf="fixture"><main class="content"><div class="page page-ticket">
${FIX[head]}${dupe ? FIX.dupe : ''}
<div class="ticket-grid"><div class="thread" id="thread" data-ticket-id="1041" data-status="open" data-priority="high" data-owner="">
${FIX.messages}
<div class="card reply" id="reply"><form method="post" action="${HERE}&amp;at=2" id="reply-form">
<input type="hidden" name="mode" id="composer-mode" value="reply"><input type="hidden" name="last_message_id" value="4">
<textarea name="reply" id="reply-box" rows="3" placeholder="Write your reply" aria-label="Reply" data-placeholder-reply="Write your reply" data-placeholder-note="Write an internal note" data-stale-mode="reply"></textarea>
<div class="reply-foot"><span class="reply-hint" id="composer-hint" data-audience="Dana gets this by email." data-open-move="" data-note="Only your team sees an internal note.">Dana gets this by email.</span>
<button type="submit" class="btn-primary" id="composer-send">Send reply</button></div></form></div>
</div>${alone ? FIX.details_alone : FIX.details}</div>
<script id="merge-data" type="application/json">${island(alone ? FIX.merge_alone : FIX.merge)}</script></div></main>
<div id="palette-root"></div><div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<script id="palette-data" type="application/json">${island({ tickets: [], clients: [], menus: FIX.menus })}</script><script>${JS}</script></body></html>`;
}

async function fixture(t, { width = 1440, refuse = '', ...page_options } = {}) {
  const body = html(page_options);
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  const page = await context.newPage();
  // The test origin is not https, so the browser offers no clipboard there: stand in for it and
  // keep what the page writes.
  await page.addInitScript(() => {
    window.copied = [];
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: (text) => { window.copied.push(text); return Promise.resolve(); } } });
  });
  const errors = [];
  const merges = [];   // ticket_merge bodies, in order
  const other = [];    // every other api request
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route(`${ORIGIN}/**`, async (route) => {
    const request = route.request();
    const url = request.url();
    if (url.endsWith('/api/ticket_merge.php')) {
      assert.equal(request.method(), 'POST');
      assert.equal(request.headers()['x-csrf'], 'fixture');
      const sent = request.postDataJSON();
      merges.push(sent);
      if (refuse) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, error: refuse }) });
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, toast: `Merged #${sent.source_id} into #${sent.target_id}`, target_id: sent.target_id }) });
    }
    if (url.includes('/api/')) {
      if (!url.endsWith('/api/presence.php')) other.push(url.slice(ORIGIN.length));
      return route.fulfill({ contentType: 'application/json', body: '{"ok":true,"others":[]}' });
    }
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(ORIGIN + HERE);
  await page.waitForFunction(() => document.readyState === 'complete');
  return { page, errors, merges, other };
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_TICKET_SHOTS) return;
  await page.waitForTimeout(250);
  await mkdir(process.env.SAFEHARBOR_TICKET_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_TICKET_SHOTS, `${name}.png`), fullPage: true });
}

const where = (page) => page.url().slice(ORIGIN.length);
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const settle = (page) => page.waitForTimeout(150);   // long enough for a wrongly sent request or a wrong move to show up
const dialog = (page) => page.getByRole('dialog');
const focused = (page) => page.evaluate(() => (document.activeElement.id || document.activeElement.textContent || '').trim());
// How well some words read against whatever is painted behind them.
const contrast = (page, selector) => page.locator(selector).evaluateAll((nodes) => nodes.map((el) => {
  const rgba = (value) => { const n = (value.match(/[\d.]+/g) || []).map(Number); return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 }; };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  const layers = [];
  for (let node = el; node; node = node.parentElement) layers.push(rgba(getComputedStyle(node).backgroundColor));
  const behind = layers.reverse().reduce((under, top) => over(top, under), { r: 255, g: 255, b: 255, a: 1 });
  const luminance = ({ r, g, b }) => [r, g, b].map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const [hi, lo] = [luminance(over(rgba(getComputedStyle(el).color), behind)), luminance(behind)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}));

test('the conversation is one column of cards, each saying who wrote it and when', async (t) => {
  const { page, errors } = await fixture(t);
  const cards = await page.locator('#thread article.msg').evaluateAll((nodes) => nodes.map((node) => {
    const box = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    // In dark an internal note is the card colour with a caution edge, so the edge counts too.
    return { left: Math.round(box.left), right: Math.round(box.right), role: node.querySelector('.msg-role').textContent, filled: style.backgroundColor + ' ' + style.boxShadow };
  }));
  const thread = await page.locator('#thread').evaluate((node) => { const box = node.getBoundingClientRect(); return { left: Math.round(box.left), right: Math.round(box.right) }; });
  assert.deepEqual(cards.map((card) => card.role), ['Customer', 'Internal note', 'Support']);
  for (const card of cards) assert.deepEqual([card.left, card.right], [thread.left, thread.right], `${card.role} runs the full width of the column`);
  assert.equal(new Set(cards.map((card) => card.filled)).size, 3, 'a customer message, a note and a reply are told apart by their surface');
  assert.ok(thread.right - thread.left <= 760, 'the column is at most 760px wide');
  const time = page.locator('#thread article.msg time').first();
  assert.equal(await time.innerText(), '9:42 AM');
  assert.equal(await time.getAttribute('title'), '18 minutes ago');
  assert.deepEqual(await page.locator('#thread .msg-system').allInnerTexts().then((texts) => texts.map((text) => text.replace(/\s+/g, ' ').trim())),
    ['Merged #1030 (Printer offline) into this ticket 9:58 AM']);
  assert.deepEqual(errors, []);
});

test('customer text stays text everywhere on the page', async (t) => {
  const { page, errors } = await fixture(t, { dupe: true });
  assert.equal(await page.locator('h1.ticket-subject').innerText(), 'Can\'t print to the <front> office printer');
  assert.match(await page.locator('.ticket-sub').innerText(), /^#1041 for Fixture <Dental>, opened 20 minutes ago by phone from Dana Whitfield$/);
  assert.equal(await page.locator('.rail-person').innerText(), 'Dana <Whitfield>');
  assert.equal(await page.locator('front, dental, whitfield, mailbox, .msg-body b, .rail-client-note i').count(), 0);
  await page.getByRole('button', { name: 'Merge', exact: true }).click();
  assert.equal(await dialog(page).locator('mailbox, dental').count(), 0);
  assert.match(await dialog(page).innerText(), /#1038 New starter needs a <mailbox>/);
  assert.match(await dialog(page).innerText(), /Find an open ticket for Fixture <Dental>/);
  assert.deepEqual(errors, []);
});

test('opened from a list: the head says "2 of 6", and ] and [ step to the next and previous ticket', async (t) => {
  const { page, errors } = await fixture(t);
  assert.equal(await page.locator('.ticket-pos').innerText(), '2 of 6');
  assert.equal(await page.getByRole('link', { name: 'Next ticket in Needs attention' }).getAttribute('href'), '/ticket.php?id=1044&in=needs');
  await page.keyboard.press(']');
  await page.waitForURL(`${ORIGIN}/ticket.php?id=1044&in=needs`);
  await page.waitForFunction(() => document.readyState === 'complete');
  await page.keyboard.press('[');
  await page.waitForURL(`${ORIGIN}/ticket.php?id=1036&in=needs`);
  await page.waitForFunction(() => document.readyState === 'complete');
  // In the reply box the same keys are letters.
  await page.locator('#reply-box').click();
  await page.keyboard.type('see [1] and ]');
  await settle(page);
  assert.equal(where(page), '/ticket.php?id=1036&in=needs');
  assert.equal(await page.locator('#reply-box').inputValue(), 'see [1] and ]');
  assert.deepEqual(errors, []);
});

test('the first ticket in a list has nothing before it, and a ticket opened without a list has no counter or arrows', async (t) => {
  let { page, errors } = await fixture(t, { head: 'head_first' });
  assert.equal(await page.locator('.ticket-pos').innerText(), '1 of 6');
  assert.equal(await page.locator('a.ticket-step').count(), 1);
  assert.equal(await page.locator('.ticket-step-off').count(), 1);
  await page.keyboard.press('[');
  await settle(page);
  assert.equal(where(page), HERE, '[ does nothing at the top of the list');
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t, { head: 'head_plain' }));
  assert.equal(await page.locator('.ticket-pos, .ticket-step').count(), 0);
  assert.equal(await page.getByRole('link', { name: 'Queue', exact: true }).getAttribute('href'), '/');
  await page.keyboard.press(']');
  await page.keyboard.press('[');
  await settle(page);
  assert.equal(where(page), HERE, 'neither key goes anywhere');
  assert.deepEqual(errors, []);
});

test('Merge opens a dialog: find a ticket, read one line about it, confirm', async (t) => {
  const { page, errors, merges } = await fixture(t);
  await page.getByRole('button', { name: 'Merge', exact: true }).click();
  await page.getByRole('heading', { name: 'Merge #1041 into another ticket' }).waitFor();
  assert.equal(await focused(page), 'merge-search');
  assert.equal(await dialog(page).getByRole('option').count(), 3);
  assert.equal(await dialog(page).getByRole('button', { name: 'Merge', exact: true }).isDisabled(), true, 'nothing can be merged before a ticket is picked');
  await shot(page, 'merge-dialog-1440-dark');

  await page.keyboard.type('disk');
  assert.deepEqual(await dialog(page).getByRole('option').allInnerTexts().then((texts) => texts.map((text) => text.replace(/\s+/g, ' ').trim())),
    ['#1040 Disk space low on FD-01 Waiting, opened yesterday from a Milepost alert.']);
  await page.keyboard.press('Enter');
  assert.equal(await page.locator('.merge-summary').innerText(), '#1040 Disk space low on FD-01: Waiting, opened yesterday from a Milepost alert.');
  const go = dialog(page).getByRole('button', { name: 'Merge into #1040' });
  assert.equal(await go.isEnabled(), true);
  assert.equal(await focused(page), 'Merge into #1040', 'picking moves to the button that confirms');
  assert.deepEqual(merges, [], 'picking a ticket merges nothing');
  await page.keyboard.press('Enter');
  await page.waitForURL(`${ORIGIN}/ticket.php?id=1040`);
  assert.deepEqual(merges, [{ source_id: 1041, target_id: 1040 }]);
  assert.deepEqual(errors, []);
});

test('the search finds a ticket by its number, with or without the #, and says when nothing matches', async (t) => {
  const { page, errors } = await fixture(t);
  await page.getByRole('button', { name: 'Merge', exact: true }).click();
  const search = page.getByRole('combobox', { name: 'Find an open ticket for Fixture <Dental>' });
  await search.fill('#1038');
  assert.equal(await dialog(page).getByRole('option').count(), 1);
  await search.fill('36');
  assert.match(await dialog(page).getByRole('option').innerText(), /#1036 High memory use on FD-02/);
  await search.fill('toner');
  assert.equal(await dialog(page).getByRole('option').count(), 0);
  assert.equal(await page.locator('.merge-none').innerText(), 'No open ticket matches. Try its number or a word from its title.');
  await page.keyboard.press('Enter');
  assert.equal(await dialog(page).getByRole('button', { name: 'Merge', exact: true }).isDisabled(), true);
  await search.fill('');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  assert.equal(await search.getAttribute('aria-activedescendant'), 'merge-option-1040', 'the arrows stop at the last ticket');
  await dialog(page).getByRole('option', { name: /#1038/ }).click();
  assert.equal(await dialog(page).getByRole('option', { name: /#1038/ }).getAttribute('aria-selected'), 'true');
  assert.equal(await dialog(page).getByRole('button', { name: 'Merge into #1038' }).isEnabled(), true);
  assert.deepEqual(errors, []);
});

test('the dialog keeps the keyboard: page keys do nothing behind it, Tab stays inside, Escape closes it', async (t) => {
  const { page, errors, merges, other } = await fixture(t);
  const opener = page.getByRole('button', { name: 'Merge', exact: true });
  await opener.click();
  await dialog(page).getByRole('option', { name: /#1036/ }).click();
  await page.keyboard.press('e');
  await page.keyboard.press('s');
  await page.keyboard.press(']');
  await settle(page);
  assert.equal(where(page), HERE);
  assert.equal(await page.getByRole('listbox', { name: 'Status' }).count(), 0, 'no menu opened behind the dialog');
  assert.equal(await page.evaluate(() => Object.keys(localStorage).filter((key) => key.includes('timer')).length), 0, 'no timer started');
  assert.deepEqual(other, []);
  for (let presses = 0; presses < 6; presses++) {
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => !!document.activeElement.closest('[role="dialog"]')), true, 'focus stays in the dialog');
  }
  await page.keyboard.press('Escape');
  assert.equal(await dialog(page).count(), 0);
  assert.equal(await focused(page), 'merge-btn', 'focus goes back to the button that opened it');
  assert.deepEqual(merges, []);
  // Closed, the page's keys work again.
  await page.keyboard.press('s');
  await page.getByRole('listbox', { name: 'Status' }).waitFor();
  assert.deepEqual(errors, []);
});

test('a merge the server refuses says why, in the dialog, and can be tried again', async (t) => {
  const { page, errors, merges } = await fixture(t, { refuse: 'Target #1036 was itself merged — pick its survivor.' });
  await page.getByRole('button', { name: 'Merge', exact: true }).click();
  await dialog(page).getByRole('option', { name: /#1036/ }).click();
  await dialog(page).getByRole('button', { name: 'Merge into #1036' }).click();
  await page.getByRole('alert').filter({ hasText: 'was itself merged' }).waitFor();
  assert.equal(await dialog(page).count(), 1, 'the dialog stays open');
  assert.equal(await dialog(page).getByRole('button', { name: 'Merge into #1036' }).isEnabled(), true);
  assert.equal(where(page), HERE);
  assert.deepEqual(merges, [{ source_id: 1041, target_id: 1036 }]);
  assert.deepEqual(errors, []);
});

test('the duplicate notice sits under the head, and its button opens the dialog with that ticket picked', async (t) => {
  const { page, errors, merges } = await fixture(t, { dupe: true });
  const notice = page.locator('.ticket-callout-caution');
  const order = await page.evaluate(() => {
    const top = (selector) => document.querySelector(selector).getBoundingClientRect().top;
    return [top('.ticket-subject'), top('.ticket-sub'), top('.ticket-callout'), top('.ticket-grid')];
  });
  assert.deepEqual(order, [...order].sort((a, b) => a - b), 'title, sentence, notice, conversation, top to bottom');
  const boxes = await notice.evaluate((node) => {
    const text = node.querySelector('.ticket-callout-text').getBoundingClientRect();
    const button = node.querySelector('button').getBoundingClientRect();
    return { textRight: text.right, buttonLeft: button.left, buttonRight: button.right, right: node.getBoundingClientRect().right };
  });
  assert.ok(boxes.buttonLeft >= boxes.textRight && boxes.right - boxes.buttonRight < 24, 'the button is at the right end of the notice');
  await shot(page, 'ticket-duplicate-1440-dark');
  await notice.getByRole('button', { name: 'Merge into #1036' }).click();
  await page.getByRole('heading', { name: 'Merge #1041 into another ticket' }).waitFor();
  assert.equal(await dialog(page).getByRole('option', { name: /#1036/ }).getAttribute('aria-selected'), 'true');
  assert.equal(await focused(page), 'Merge into #1036');
  assert.deepEqual(merges, [], 'opening the dialog merges nothing');
  await page.keyboard.press('Escape');
  assert.equal(await focused(page), 'merge-dupe-btn');
  assert.deepEqual(errors, []);
});

test('a client with no other open ticket: Details says so, and Merge explains instead of offering a list', async (t) => {
  const { page, errors } = await fixture(t, { alone: true });
  assert.equal(await page.locator('.rail-others').innerText().then((text) => text.replace(/\s+/g, ' ').trim()),
    'Other open tickets for Fixture <Dental> (0) None. This is their only open ticket.');
  await page.getByRole('button', { name: 'Merge', exact: true }).click();
  assert.equal(await page.locator('.merge-empty').innerText(), 'Fixture <Dental> has no other open ticket to merge this one into.');
  assert.equal(await dialog(page).getByRole('combobox').count(), 0);
  assert.deepEqual(await dialog(page).getByRole('button').allInnerTexts(), ['Close']);
  await dialog(page).getByRole('button', { name: 'Close' }).click();
  assert.equal(await dialog(page).count(), 0);
  assert.deepEqual(errors, []);
});

test('Details: the contact with Copy, the client, its note, the other open tickets, and the timer in words', async (t) => {
  const { page, errors } = await fixture(t);
  const rail = page.getByRole('complementary', { name: 'Details' });
  assert.deepEqual(await rail.locator('.rail-prop > .rail-k').allInnerTexts(), ['Status', 'Priority', 'Assigned to', 'First response', 'Your time']);
  assert.equal(await rail.locator('.rail-note').innerText(), 'Standard plan: first reply within 8 hours.');
  await rail.getByRole('button', { name: 'Copy Dana <Whitfield>\'s email address' }).click();
  await page.getByText('Copied.').waitFor();
  assert.deepEqual(await page.evaluate(() => window.copied), ['dana@example.test']);
  assert.equal(await rail.getByRole('link', { name: 'Open client' }).getAttribute('href'), '/client.php?id=11');
  assert.deepEqual(await rail.locator('.rail-ticket .sla').allInnerTexts(), ['Overdue by 5 d', 'Due in 6 h', 'Due in 24 h']);
  assert.equal(await rail.getByRole('link', { name: 'Open in Milepost' }).getAttribute('target'), '_blank');
  const timer = page.locator('#timer-btn');
  assert.equal(await timer.innerText().then((text) => text.replace(/\s+/g, ' ').trim()), 'Start timer E');
  await timer.click();
  assert.equal(await timer.innerText().then((text) => text.replace(/\s+/g, ' ').trim()), 'Stop and log time E');
  assert.deepEqual(errors, []);
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: quiet words still read, at desktop width`, async (t) => {
    const { page, errors } = await fixture(t, { theme, dupe: true });
    for (const selector of ['.msg-role', '.msg-when', '.msg-author', '.ticket-pos', '.ticket-sub', '.ticket-callout-text', '.rail-note', '.rail-quiet',
      '.rail-k', '.rail-ticket-num', '.rail-client-note', '.rail-email']) {
      for (const ratio of await contrast(page, selector)) assert.ok(ratio >= 4.5, `${selector} reads at ${ratio.toFixed(2)} to 1`);
    }
    assert.equal(await fits(page), true);
    await shot(page, `ticket-1440-${theme}`);
    await page.getByRole('button', { name: 'Merge', exact: true }).click();
    await dialog(page).getByRole('option', { name: /#1036/ }).click();
    for (const selector of ['.merge-sub', '.merge-option-sub', '.merge-option-num', '.merge-summary', '.merge-label']) {
      for (const ratio of await contrast(page, selector)) assert.ok(ratio >= 4.5, `${selector} reads at ${ratio.toFixed(2)} to 1`);
    }
    await shot(page, `merge-dialog-picked-1440-${theme}`);
    assert.deepEqual(errors, []);
  });

  test(`${theme}: on a phone nothing overflows, every control is a 44px target, and the dialog fits`, async (t) => {
    const { page, errors } = await fixture(t, { width: 390, theme, dupe: true });
    assert.equal(await fits(page), true);
    const tall = async (locator, name) => {
      const heights = await locator.evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().height));
      assert.ok(heights.length > 0, `${name} is on the page`);
      for (const height of heights) assert.ok(height >= 44, `${name} is ${height}px tall`);
    };
    await tall(page.locator('.ticket-bar .backlink'), 'Queue');
    await tall(page.locator('a.ticket-step'), 'previous and next');
    await tall(page.locator('#merge-btn'), 'Merge');
    await tall(page.locator('#resolve-btn'), 'Resolve');
    await tall(page.locator('#merge-dupe-btn'), 'Merge into the duplicate');
    await tall(page.locator('#timer-btn'), 'the timer button');
    await tall(page.locator('.rail-copy'), 'Copy');
    await tall(page.locator('.rail-ticket'), 'another open ticket');
    await tall(page.getByRole('link', { name: 'Open client' }), 'Open client');
    const steps = await page.locator('a.ticket-step').evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().width));
    for (const width of steps) assert.ok(width >= 44);
    const notice = await page.locator('.ticket-callout').evaluate((node) => ({
      width: node.clientWidth, button: node.querySelector('button').getBoundingClientRect().width,
      below: node.querySelector('button').getBoundingClientRect().top >= node.querySelector('.ticket-callout-text').getBoundingClientRect().bottom,
    }));
    assert.ok(notice.below && notice.button > notice.width * 0.8, 'the notice\'s button is under its words, at full width');
    const cards = await page.locator('#thread article.msg').evaluateAll((nodes) => nodes.map((node) => Math.round(node.getBoundingClientRect().width)));
    assert.equal(new Set(cards).size, 1, 'every message is the same width');
    await shot(page, `ticket-390-${theme}`);

    await page.locator('#merge-btn').click();
    await page.getByRole('heading', { name: 'Merge #1041 into another ticket' }).waitFor();
    const panel = await dialog(page).evaluate((node) => { const box = node.getBoundingClientRect(); return { left: box.left, right: box.right, bottom: box.bottom }; });
    assert.ok(panel.left >= 0 && panel.right <= 390 && panel.bottom <= 900, 'the dialog is inside the screen');
    await tall(dialog(page).getByRole('option'), 'a ticket to pick');
    await tall(dialog(page).getByRole('button'), 'a dialog button');
    await tall(dialog(page).getByRole('combobox'), 'the search box');
    assert.equal(await fits(page), true);
    await shot(page, `merge-dialog-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}
