import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const APP_JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const ORIGIN = 'http://safeharbor.test';
const TIMER_KEY = 'safeharbor.timer.v2.7:11';
const OTHER_SCOPE_KEY = 'safeharbor.timer.v2.8:11';

const PAGE = `<!doctype html>
<html><head><meta charset="utf-8"><title>Safeharbor time contract</title></head>
<body data-active="time" data-tenant-id="7" data-user-id="11" data-csrf="test-csrf">
  <div id="toasts" aria-live="polite"></div>
  <div id="timer-widget"></div>
  <section id="timer-card">
    <h1 id="timer-title"></h1>
    <p id="timer-sub"></p>
    <span id="timer-clock"></span>
    <button id="timer-stop" type="button">Retry logging</button>
    <button id="timer-discard" type="button">Discard</button>
  </section>
  <button class="time-correct" type="button"
          data-rejected-entry-id="51" data-measured="0" data-minutes="17"
          data-worked-at="2026-08-29T10:00:00Z" data-note="Original rejected work"
          data-billable="0">Correct</button>
  <script src="/assets/js/app.js"></script>
</body></html>`;

async function openPage({ timer, responder }) {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1100, height: 760 } });
  if (timer) {
    await context.addInitScript(({ currentKey, currentTimer, otherKey }) => {
      localStorage.setItem(currentKey, JSON.stringify(currentTimer));
      localStorage.setItem(otherKey, JSON.stringify({
        ticketId: 999,
        tenantId: '8',
        userId: '11',
        entryKey: 'timer:other-tenant',
        state: 'stopped',
        startedAt: 1,
        endedAt: 2,
        submission: { source: 'timer', note: 'other tenant', billable: 0 },
      }));
    }, { currentKey: TIMER_KEY, currentTimer: timer, otherKey: OTHER_SCOPE_KEY });
  }
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/time.php') {
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: PAGE });
    }
    if (url.pathname === '/assets/js/app.js') {
      return route.fulfill({ contentType: 'text/javascript; charset=utf-8', body: APP_JS });
    }
    if (url.pathname === '/api/timer.php' || url.pathname === '/api/time_entry_correction.php') {
      return responder(route, url.pathname);
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto(`${ORIGIN}/time.php`);
  return { browser, context, page, errors };
}

test('stopped timer survives failure and mismatched acknowledgement, then clears only on its exact key', async () => {
  const frozenTimer = {
    ticketId: 42,
    subject: 'Printer issue',
    tenantId: '7',
    userId: '11',
    entryKey: 'timer:fixed-retry-key',
    state: 'stopped',
    startedAt: Date.parse('2026-08-29T09:00:00Z'),
    endedAt: Date.parse('2026-08-29T09:17:00Z'),
    submission: { source: 'timer', note: 'Printer work', billable: 1 },
  };
  const requests = [];
  let attempt = 0;
  const session = await openPage({
    timer: frozenTimer,
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/timer.php');
      requests.push(route.request().postDataJSON());
      attempt += 1;
      if (attempt === 1) return route.abort('failed');
      if (attempt === 2) {
        return route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ ok: true, entry: { entry_key: 'timer:wrong-key' } }),
        });
      }
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ ok: true, entry: { entry_key: frozenTimer.entryKey }, toast: 'Logged' }),
      });
    },
  });

  try {
    const { page, errors } = session;
    assert.equal(await page.title(), 'Safeharbor time contract');
    await assert.doesNotReject(() => page.locator('#timer-stop').waitFor({ state: 'visible' }));
    assert.match(await page.locator('#timer-sub').innerText(), /retry safely/i);

    await page.locator('#timer-stop').click();
    await page.waitForFunction((key) => {
      const value = JSON.parse(localStorage.getItem(key));
      return value?.entryKey === 'timer:fixed-retry-key'
        && document.querySelector('#timer-stop')?.disabled === false;
    }, TIMER_KEY);
    const afterFailure = await page.evaluate((key) => localStorage.getItem(key), TIMER_KEY);
    assert.deepEqual(JSON.parse(afterFailure), frozenTimer);

    await page.locator('#timer-stop').click();
    await page.waitForFunction((key) => {
      const value = JSON.parse(localStorage.getItem(key));
      return value?.entryKey === 'timer:fixed-retry-key'
        && document.querySelector('#timer-stop')?.disabled === false;
    }, TIMER_KEY);
    const afterMismatch = await page.evaluate((key) => localStorage.getItem(key), TIMER_KEY);
    assert.equal(afterMismatch, afterFailure);

    await page.locator('#timer-stop').click();
    await page.waitForFunction((key) => localStorage.getItem(key) === null, TIMER_KEY);
    assert.equal(await page.locator('#timer-widget').innerText(), 'No timer running');
    assert.ok(await page.evaluate((key) => localStorage.getItem(key) !== null, OTHER_SCOPE_KEY));
    assert.deepEqual(requests[1], requests[0]);
    assert.deepEqual(requests[2], requests[0]);
    assert.equal(requests[0].entry_key, frozenTimer.entryKey);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('correction retries keep one frozen key and payload until the matching acknowledgement', async () => {
  const requests = [];
  let attempt = 0;
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_correction.php');
      requests.push(route.request().postDataJSON());
      attempt += 1;
      if (attempt === 1) return route.abort('failed');
      if (attempt === 2) {
        return route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ ok: true, entry: { entry_key: 'correction:wrong-key' } }),
        });
      }
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          entry: { entry_key: requests[0].entry_key },
          toast: 'Correction submitted',
        }),
      });
    },
  });

  try {
    const { page, errors } = session;
    const answers = ['17', 'Corrected printer work', 'I'];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      const answer = answers[prompts];
      prompts += 1;
      await dialog.accept(answer);
    });

    const button = page.locator('.time-correct');
    await button.click();
    await page.waitForFunction(() => {
      const button = document.querySelector('.time-correct');
      return button?.disabled === false && Boolean(button.dataset.correctionPayload);
    });
    const frozen = await button.getAttribute('data-correction-payload');
    assert.equal(prompts, 3);
    assert.equal(JSON.parse(frozen).entry_key, requests[0].entry_key);

    await button.click();
    await page.waitForFunction(() => document.querySelector('.time-correct')?.disabled === false);
    assert.equal(await button.getAttribute('data-correction-payload'), frozen);
    assert.equal(prompts, 3, 'a retry must not ask for edited facts');

    await button.click();
    await page.locator('.toast').filter({ hasText: 'Correction submitted' }).waitFor();
    assert.equal(prompts, 3);
    assert.deepEqual(requests[1], requests[0]);
    assert.deepEqual(requests[2], requests[0]);
    assert.match(requests[0].entry_key, /^correction:/);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('a definitive correction conflict clears the draft so a technician can edit and use a new key', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_correction.php');
      const body = route.request().postDataJSON();
      requests.push(body);
      if (requests.length === 1) {
        return route.fulfill({
          status: 409,
          contentType: 'application/json',
          body: JSON.stringify({ error: 'Measured time overlaps another entry.' }),
        });
      }
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          entry: { entry_key: body.entry_key },
          toast: 'Edited correction submitted',
        }),
      });
    },
  });

  try {
    const { page, errors } = session;
    const answers = ['17', 'First correction', 'I', '18', 'Edited correction', 'B'];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      await dialog.accept(answers[prompts]);
      prompts += 1;
    });

    const button = page.locator('.time-correct');
    await button.click();
    await page.waitForFunction(() => {
      const button = document.querySelector('.time-correct');
      return button?.disabled === false && button.dataset.correctionPayload === '';
    });
    assert.equal(prompts, 3);

    await button.click();
    await page.locator('.toast').filter({ hasText: 'Edited correction submitted' }).waitFor();
    assert.equal(prompts, 6, 'a definitive error must allow the technician to edit every fact');
    assert.equal(requests.length, 2);
    assert.notEqual(requests[1].entry_key, requests[0].entry_key);
    assert.equal(requests[0].note, 'First correction');
    assert.equal(requests[1].note, 'Edited correction');
    assert.equal(requests[0].billable, 0);
    assert.equal(requests[1].billable, 1);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});
