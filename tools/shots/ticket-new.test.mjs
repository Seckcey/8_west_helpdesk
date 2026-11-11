import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// "Log a call": the New ticket form on the real stylesheet and script. The form comes from the real
// helper (app/tests/ticket_new_form_test.php --render); the server is a small stand-in that keeps
// every form it is sent, so what the page posts can be read back.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const CSS = await readFile(path.join(ROOT, 'app/public/assets/css/app.css'), 'utf8');
const FIX = JSON.parse(execFileSync('php', [path.join(ROOT, 'app/tests/ticket_new_form_test.php'), '--render'], { encoding: 'utf8' }));
const ORIGIN = 'http://safeharbor.test';

const shell = (theme, body, { signedIn = true } = {}) => `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Safeharbor</title><style>${CSS}</style></head>
<body data-active="queue"${signedIn ? ' data-tenant-id="1" data-user-id="7"' : ''} data-csrf="fixture"><main class="content">${body}</main>
<div id="palette-root"></div><div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<script id="palette-data" type="application/json">{"tickets":[],"clients":[]}</script><script>${JS}</script></body></html>`;
const formPage = (theme, form, error) => shell(theme, `<div class="page page-narrow page-new-ticket"><div class="page-head"><div><h1 class="page-title">New ticket</h1>
<p class="page-sub">Log a call or a request. Start typing the client's name.</p></div></div>${error ? `<div class="form-error" role="alert">${error}</div>` : ''}
<div class="card form-card">${FIX[form]}</div></div>`);
const PAGES = {
  queue: (theme) => shell(theme, '<div class="page"><h1 class="page-title">Queue</h1><label class="field">Search<input type="text" id="some-box"></label></div>'),
  client: (theme) => shell(theme, '<div class="page"><h1 class="page-title">Bayside Realty</h1><a href="/ticket_new.php?client=12" class="btn-chip" data-new-ticket>New ticket for this client</a></div>'),
  signin: (theme) => shell(theme, '<div class="page"><h1 class="page-title">Sign in</h1></div>', { signedIn: false }),
  ticket: (theme, flash) => shell(theme, `<div class="page page-ticket"><h1 class="ticket-subject">Can't print</h1>${flash}
<div class="ticket-grid"><div class="thread" id="thread" data-ticket-id="1042" data-status="open" data-priority="normal" data-owner="7"></div></div></div>`),
};

async function fixture(t, { width = 1440, theme = 'dark', form = 'form', error = '', start = '/ticket_new.php', flash = '' } = {}) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  const posts = [];   // every form the page sent, as URLSearchParams
  const other = [];   // every api request
  page.on('pageerror', (problem) => errors.push(problem.message));
  await page.route(`${ORIGIN}/**`, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.startsWith('/api/')) {
      other.push(url.pathname);
      return route.fulfill({ contentType: 'application/json', body: '{"ok":true,"others":[]}' });
    }
    if (request.method() === 'POST') {
      posts.push(new URLSearchParams(request.postData() || ''));
      return route.fulfill({ status: 303, headers: { Location: '/ticket.php?id=1042' }, body: '' });
    }
    const body = url.pathname === '/ticket_new.php' ? formPage(theme, url.searchParams.has('client') ? 'form_client' : form, error)
      : url.pathname === '/client.php' ? PAGES.client(theme)
      : url.pathname === '/login.php' ? PAGES.signin(theme)
      : url.pathname === '/ticket.php' ? PAGES.ticket(theme, flash)
      : PAGES.queue(theme);
    return route.fulfill({ contentType: 'text/html', body });
  });
  await page.goto(ORIGIN + start);
  await page.waitForFunction(() => document.readyState === 'complete');
  return { page, errors, posts, other };
}

async function shot(page, name) {
  if (!process.env.SAFEHARBOR_NEW_TICKET_SHOTS) return;
  await page.waitForTimeout(200);
  await mkdir(process.env.SAFEHARBOR_NEW_TICKET_SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.SAFEHARBOR_NEW_TICKET_SHOTS, `${name}.png`), fullPage: true });
}

const where = (page) => page.url().slice(ORIGIN.length);
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const settle = (page) => page.waitForTimeout(150);   // long enough for a wrongly sent form or a wrong move to show up
const focused = (page) => page.evaluate(() => document.activeElement.id || document.activeElement.name || document.activeElement.tagName);
const box = (page) => page.getByRole('combobox', { name: 'Client' });
const clientOptions = (page) => page.locator('#f-client-list [role="option"]').allInnerTexts().then((texts) => texts.map((text) => text.replace(/\s+/g, ' ').trim()));
const contactsOffered = (page) => page.locator('#f-contact option:not([disabled])').allInnerTexts();
const sent = (post) => Object.fromEntries([...post.entries()].filter(([name]) => name !== 'csrf'));
async function posted(page, posts, count) {
  for (let tries = 0; tries < 150 && posts.length < count; tries++) await page.waitForTimeout(20);
  assert.equal(posts.length, count);
}
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

test('a call from start to finish on the keyboard: client, who asked, subject, what was said, Ctrl+Enter', async (t) => {
  const { page, errors, posts } = await fixture(t);
  assert.equal(await focused(page), 'f-client-box', 'the page opens with the cursor in Client');
  assert.equal(await page.locator('#f-client-list').isHidden(), true, 'no list until something is typed');
  await page.keyboard.type('bays');
  assert.deepEqual(await clientOptions(page), ['Bayside Realty Standard']);
  await page.keyboard.press('Enter');
  assert.equal(await box(page).inputValue(), 'Bayside Realty');
  assert.equal(await page.locator('.call-client-picked').innerText().then((text) => text.replace(/\s+/g, ' ').trim()), 'Bayside Realty Standard');
  assert.equal(await focused(page), 'f-contact', 'picking a client moves on to who asked');
  assert.deepEqual(await contactsOffered(page), ['Choose a contact', 'Dana <Whitfield>', 'Luis Ortega'], 'only that client\'s contacts are offered');
  assert.deepEqual(posts, [], 'Enter in the client box sends nothing');
  await page.locator('#f-contact').selectOption({ label: 'Dana <Whitfield>' });
  await page.locator('#f-subject').fill('Can\'t print to the front office printer');
  await page.locator('#f-said').fill('The front office printer shows offline.');
  await page.locator('#f-told').fill('Restart the printer and try one page.');
  await shot(page, 'new-ticket-filled-1440-dark');
  await page.keyboard.press('Control+Enter');
  await posted(page, posts, 1);
  // The closed "new contact" fields are not part of what is sent.
  assert.deepEqual(sent(posts[0]), { client_id: '12', contact_id: '31', subject: 'Can\'t print to the front office printer', said: 'The front office printer shows offline.',
    told: 'Restart the printer and try one page.', channel: 'phone', priority: 'normal', start_timer: '1' });
  assert.equal(posts[0].get('csrf'), 'fixture');
  assert.equal(posts[0].has('after'), false, 'Ctrl+Enter creates; it does not resolve');
  assert.deepEqual(errors, []);
});

test('typing finds clients by the start of the name first; arrows move, Enter picks, Escape only closes the list', async (t) => {
  const { page, errors, posts } = await fixture(t);
  await page.keyboard.type('ba');
  assert.deepEqual(await clientOptions(page), ['Bay Street Books Standard', 'Bayside Realty Standard', 'Harbor Bakery Standard'],
    'names that start with what was typed, then one with a word that does');
  assert.equal(await box(page).getAttribute('aria-expanded'), 'true');
  assert.equal(await box(page).getAttribute('aria-activedescendant'), 'f-client-option-13');
  await page.keyboard.press('ArrowDown');
  assert.equal(await box(page).getAttribute('aria-activedescendant'), 'f-client-option-12');
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('#f-client-list').isHidden(), true);
  assert.equal(where(page), '/ticket_new.php', 'Escape does not leave the page');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  assert.equal(await box(page).inputValue(), 'Bayside Realty');
  // A name the list does not have.
  await box(page).fill('zzz');
  assert.equal(await page.locator('.call-client-none').innerText(), 'No client matches. Check the spelling, or add the client first.');
  assert.equal(await page.locator('#f-client').inputValue(), '', 'typing over a chosen client un-chooses it');
  assert.deepEqual(await contactsOffered(page), ['Choose a contact']);
  assert.equal(await page.locator('.call-client-picked').isHidden(), true);
  // Customer text is text.
  await box(page).fill('fix');
  assert.deepEqual(await clientOptions(page), ['Fixture <Dental> Premium']);
  assert.equal(await page.locator('#f-client-list dental').count(), 0);
  await page.locator('#f-client-list [role="option"]').click();
  assert.equal(await page.locator('.call-client-picked').innerText().then((text) => text.replace(/\s+/g, ' ').trim()), 'Fixture <Dental> Premium');
  assert.deepEqual(await contactsOffered(page), ['Choose a contact', 'Priya Anand']);
  assert.deepEqual(posts, []);
  assert.deepEqual(errors, []);
});

test('a client has to be picked: a half-typed name is refused before anything is sent, a full name counts', async (t) => {
  const { page, errors, posts } = await fixture(t);
  await page.keyboard.type('Nobody');
  await page.locator('#f-subject').fill('Printer');
  await page.getByRole('button', { name: 'Create ticket' }).click();
  await settle(page);
  assert.deepEqual(posts, []);
  assert.equal(await box(page).evaluate((input) => input.validationMessage), 'Choose a client from the list.');
  // Typed out in full and left: that is the client.
  await box(page).fill('harbor bakery');
  await page.locator('#f-subject').click();
  assert.equal(await page.locator('#f-client').inputValue(), '14');
  assert.equal(await box(page).inputValue(), 'Harbor Bakery');
  // "Bay", Tab: the first match, and focus moves on.
  await box(page).fill('');
  await box(page).pressSequentially('bays');
  await page.keyboard.press('Tab');
  assert.equal(await page.locator('#f-client').inputValue(), '12');
  assert.equal(await focused(page), 'f-contact');
  // No subject: the browser's own check stops it.
  await page.locator('#f-subject').fill('');
  await page.getByRole('button', { name: 'Create ticket' }).click();
  await settle(page);
  assert.deepEqual(posts, []);
  assert.deepEqual(errors, []);
});

test('a new contact is typed in place, and only while its fields are open', async (t) => {
  const { page, errors, posts } = await fixture(t);
  await page.keyboard.type('bays');
  await page.keyboard.press('Enter');
  await page.locator('#f-contact').selectOption({ label: 'Luis Ortega' });
  await page.getByText('Add a new contact', { exact: true }).click();
  await page.waitForFunction(() => document.activeElement.id === 'f-contact-name');
  assert.equal(await page.locator('#f-contact').isDisabled(), true, 'the list rests while a new contact is being typed');
  assert.equal(await page.locator('#f-contact').inputValue(), '');
  await page.keyboard.type('Lee Okafor');
  await page.locator('#f-contact-email').fill('lee@example.test');
  await page.locator('#f-subject').fill('New starter');
  await shot(page, 'new-ticket-new-contact-1440-dark');
  await page.getByRole('button', { name: 'Create ticket' }).click();
  await posted(page, posts, 1);
  assert.deepEqual(sent(posts[0]), { client_id: '12', contact_name: 'Lee Okafor', contact_email: 'lee@example.test', subject: 'New starter', said: '', told: '',
    channel: 'phone', priority: 'normal', start_timer: '1' });
  assert.deepEqual(errors, []);
});

test('closing the new contact puts the list back and sends neither its name nor its address', async (t) => {
  const { page, errors, posts } = await fixture(t);
  await page.keyboard.type('bays');
  await page.keyboard.press('Enter');
  await page.getByText('Add a new contact', { exact: true }).click();
  await page.waitForFunction(() => document.activeElement.id === 'f-contact-name');
  await page.keyboard.type('Lee Okafor');
  await page.getByText('Add a new contact', { exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('#f-contact').disabled);
  assert.equal(await page.locator('#f-contact-name').isDisabled(), true);
  await page.locator('#f-contact').selectOption({ label: 'Luis Ortega' });
  await page.locator('#f-subject').fill('Printer');
  await page.getByRole('button', { name: 'Create and resolve' }).click();
  await posted(page, posts, 1);
  assert.deepEqual(sent(posts[0]), { client_id: '12', contact_id: '32', subject: 'Printer', said: '', told: '', channel: 'phone', priority: 'normal', start_timer: '1', after: 'resolved' });
  assert.deepEqual(errors, []);
});

test('how it came in and the priority are words to pick; the timer box can be unticked', async (t) => {
  const { page, errors, posts } = await fixture(t);
  assert.deepEqual(await page.getByRole('group', { name: 'Came in by' }).getByRole('radio').evaluateAll((radios) => radios.map((radio) => [radio.value, radio.checked])),
    [['phone', true], ['email', false], ['portal', false]]);
  assert.deepEqual(await page.getByRole('group', { name: 'Priority' }).getByRole('radio').evaluateAll((radios) => radios.map((radio) => [radio.value, radio.checked])),
    [['low', false], ['normal', true], ['high', false], ['urgent', false]]);
  await page.keyboard.type('harbor');
  await page.keyboard.press('Enter');
  await page.locator('#f-subject').fill('Till will not open');
  await page.getByRole('radio', { name: 'Email' }).check();
  await page.getByRole('radio', { name: 'Normal' }).focus();
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  assert.equal(await page.getByRole('radio', { name: 'Urgent' }).isChecked(), true, 'the arrow keys move through a row of choices');
  const chip = await page.locator('.seg-item input:checked + span').evaluateAll((spans) => spans.map((span) => span.textContent));
  assert.deepEqual(chip, ['Email', 'Urgent']);
  await page.getByRole('checkbox', { name: 'Start my timer' }).uncheck();
  await page.getByRole('button', { name: 'Create ticket' }).click();
  await posted(page, posts, 1);
  assert.deepEqual(sent(posts[0]), { client_id: '14', contact_id: '', subject: 'Till will not open', said: '', told: '', channel: 'email', priority: 'urgent' });
  assert.deepEqual(errors, []);
});

test('opened for a client: that client is chosen, the cursor is on who asked, and Cancel goes back to the client', async (t) => {
  const { page, errors } = await fixture(t, { start: '/ticket_new.php?client=12' });
  assert.equal(await box(page).inputValue(), 'Bayside Realty');
  assert.equal(await page.locator('#f-client').inputValue(), '12');
  assert.equal(await focused(page), 'f-contact');
  assert.deepEqual(await contactsOffered(page), ['Choose a contact', 'Dana <Whitfield>', 'Luis Ortega']);
  assert.equal(await page.getByRole('link', { name: 'Cancel' }).getAttribute('href'), '/client.php?id=12');
  assert.equal(await page.locator('#ticket-new-form').getAttribute('action'), '/ticket_new.php?client=12');
  assert.deepEqual(errors, []);
});

test('after a refusal everything typed is still there, the new contact is open, and the cursor is left alone', async (t) => {
  const { page, errors } = await fixture(t, { form: 'form_typed', error: 'Add a subject so the ticket can be found later.' });
  assert.equal(await page.getByRole('alert').innerText(), 'Add a subject so the ticket can be found later.');
  assert.equal(await box(page).inputValue(), 'Bayside Realty');
  assert.equal(await page.locator('#f-contact-name').inputValue(), 'Lee "Okafor"');
  assert.equal(await page.locator('#f-contact-name').isEnabled(), true);
  assert.equal(await page.locator('#f-contact').isDisabled(), true);
  assert.equal(await page.locator('#f-said').inputValue(), '</textarea><script>x</script>');
  assert.equal(await page.getByRole('radio', { name: 'Urgent' }).isChecked(), true);
  assert.equal(await page.getByRole('checkbox', { name: 'Start my timer' }).isChecked(), false);
  assert.equal(await focused(page), 'BODY');
  assert.deepEqual(errors, []);
});

test('c opens New ticket from a staff page, but not while typing, and not for someone who is not signed in', async (t) => {
  let { page, errors } = await fixture(t, { start: '/' });
  await page.locator('#some-box').click();
  await page.keyboard.type('cc');
  await settle(page);
  assert.equal(where(page), '/', 'in a text field c is a letter');
  assert.equal(await page.locator('#some-box').inputValue(), 'cc');
  await page.locator('h1').click();
  await page.keyboard.press('c');
  await page.waitForURL(`${ORIGIN}/ticket_new.php`);
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'f-client-box');
  // On the form itself c is the first letter of a client's name.
  await page.keyboard.type('c');
  assert.equal(await box(page).inputValue(), 'c');
  assert.equal(where(page), '/ticket_new.php');
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t, { start: '/client.php?id=12' }));
  await page.keyboard.press('c');
  await page.waitForURL(`${ORIGIN}/ticket_new.php?client=12`);
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t, { start: '/' }));
  await page.keyboard.press('g');
  await page.keyboard.press('c');
  await page.waitForURL(`${ORIGIN}/clients.php`);   // g then c is still Clients
  assert.deepEqual(errors, []);

  ({ page, errors } = await fixture(t, { start: '/login.php' }));
  await page.keyboard.press('c');
  await settle(page);
  assert.equal(where(page), '/login.php');
  assert.deepEqual(errors, []);
});

test('a ticket created with "Start my timer" starts the timer on its page; "Created and resolved" offers Undo', async (t) => {
  let { page, errors, other } = await fixture(t, { start: '/ticket.php?id=1042', flash: '<span id="ticket-flash" data-toast="" data-undo-status="" data-start-timer="1" hidden></span>' });
  await page.getByText('Timer started').waitFor();
  const timer = await page.evaluate(() => Object.entries(localStorage).filter(([key]) => key.includes('timer')).map(([, value]) => JSON.parse(value)));
  assert.equal(timer.length, 1);
  assert.equal(timer[0].ticketId, 1042);
  assert.equal(timer[0].subject, 'Can\'t print');
  assert.deepEqual(errors, []);

  ({ page, errors, other } = await fixture(t, { start: '/ticket.php?id=1042',
    flash: '<span id="ticket-flash" data-toast="Created and resolved. Dana gets a short survey in a minute." data-undo-status="open" hidden></span>' }));
  await page.getByText('Created and resolved. Dana gets a short survey in a minute.').waitFor();
  assert.equal(await page.evaluate(() => Object.keys(localStorage).filter((key) => key.includes('timer')).length), 0, 'a resolved ticket starts no timer');
  await page.getByRole('button', { name: /Undo/ }).click();
  for (let tries = 0; tries < 100 && !other.includes('/api/ticket_action.php'); tries++) await page.waitForTimeout(20);
  assert.ok(other.includes('/api/ticket_action.php'), 'Undo asks for the status to be put back');
  assert.deepEqual(errors, []);
});

for (const theme of ['dark', 'light']) {
  test(`${theme}: the form at desktop width reads well and is one 820px column`, async (t) => {
    const { page, errors } = await fixture(t, { theme });
    const width = await page.locator('.page-new-ticket').evaluate((node) => node.getBoundingClientRect().width);
    assert.ok(width <= 820, `the form page is ${width}px wide`);
    await page.keyboard.type('ba');
    for (const selector of ['.field-help', '.seg-item span', '.call-new > summary', '.call-keys', '.call-client-option', '.call-client-option .tier', '.page-sub', '.field > label', '.seg-field > legend']) {
      for (const ratio of await contrast(page, selector)) assert.ok(ratio >= 4.5, `${selector} reads at ${ratio.toFixed(2)} to 1`);
    }
    await shot(page, `new-ticket-finding-1440-${theme}`);
    await page.keyboard.press('Escape');
    assert.equal(await fits(page), true);
    await shot(page, `new-ticket-1440-${theme}`);
    assert.deepEqual(errors, []);
  });

  test(`${theme}: on a phone nothing overflows and every control is a 44px target`, async (t) => {
    const { page, errors } = await fixture(t, { width: 390, theme });
    assert.equal(await fits(page), true);
    const tall = async (locator, name) => {
      const heights = await locator.evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().height));
      assert.ok(heights.length > 0, `${name} is on the page`);
      for (const height of heights) assert.ok(height >= 44, `${name} is ${height}px tall`);
    };
    await tall(box(page), 'the client box');
    await tall(page.locator('#f-contact, #f-subject'), 'a field');
    await tall(page.locator('.seg-item'), 'a choice');
    await tall(page.locator('.call-new > summary'), 'Add a new contact');
    await tall(page.locator('.call-check'), 'Start my timer');
    await tall(page.locator('.call-actions button'), 'a button');
    await tall(page.locator('.call-actions a'), 'Cancel');
    const buttons = await page.locator('.call-actions button').evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().width));
    const formWidth = await page.locator('#ticket-new-form').evaluate((node) => node.clientWidth);
    for (const width of buttons) assert.ok(width > formWidth * 0.9, 'each button takes the full row');
    assert.equal(await page.locator('.call-keys').isHidden(), true, 'no key hint on a phone');
    await page.keyboard.type('ba');
    await tall(page.locator('#f-client-list [role="option"]'), 'a client to pick');
    const list = await page.locator('#f-client-list').evaluate((node) => { const r = node.getBoundingClientRect(); return [r.left, r.right]; });
    assert.ok(list[0] >= 0 && list[1] <= 390, 'the list of clients is inside the screen');
    await shot(page, `new-ticket-finding-390-${theme}`);
    await page.locator('#f-client-list [role="option"]').nth(1).click();
    await page.getByText('Add a new contact', { exact: true }).click();
    await page.waitForFunction(() => document.activeElement.id === 'f-contact-name');
    await tall(page.locator('#f-contact-name, #f-contact-email'), 'a new contact field');
    assert.equal(await fits(page), true);
    await shot(page, `new-ticket-390-${theme}`);
    assert.deepEqual(errors, []);
  });
}
