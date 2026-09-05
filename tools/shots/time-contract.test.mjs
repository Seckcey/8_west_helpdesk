import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const APP_JS = await readFile(path.join(ROOT, 'app/public/assets/js/app.js'), 'utf8');
const TIME_PHP = await readFile(path.join(ROOT, 'app/public/time.php'), 'utf8');
const ADJUSTMENT_API_PHP = await readFile(
  path.join(ROOT, 'app/public/api/time_entry_adjustment.php'),
  'utf8',
);
const ORIGIN = 'http://safeharbor.test';
const TIMER_KEY = 'safeharbor.timer.v2.7:11';
const OTHER_SCOPE_KEY = 'safeharbor.timer.v2.8:11';

const PAGE = `<!doctype html>
<html><head><meta charset="utf-8"><title>Safeharbor time contract</title></head>
<body data-active="time" data-tenant-id="7" data-user-id="11" data-csrf="test-csrf">
  <button id="mobile-nav-toggle" aria-expanded="false">Menu</button>
  <aside id="suite-sidebar"><button class="mobile-nav-close">Close navigation</button><a href="/time.php">Time</a></aside>
  <button class="mobile-nav-backdrop">Close menu backdrop</button>
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
  <section id="time-review-queue">
    <div class="review-row" data-time-entry-id="73">
      <button class="time-review" type="button" data-decision="approved">Approve</button>
      <button class="time-review" type="button" data-decision="rejected">Reject</button>
    </div>
  </section>
  <section id="time-adjustment-ledger">
    <div class="adjustment-row" data-time-entry-id="88">
      <div class="adjustment-original">Original approval: 60m billable · owner · 2026-08-29 10:30:00 UTC</div>
      <div class="adjustment-effective">Effective version 2: 45m internal</div>
      <div class="adjustment-reason">Current adjustment reason: customer goodwill credit</div>
      <details class="adjustment-history">
        <summary>All correction slips (2)</summary>
        <ol>
          <li class="adjustment-history-item">Version 1 · 50m billable · by user #11<div>Reason: duplicate work</div></li>
          <li class="adjustment-history-item">Version 2 · 45m internal · by user #12<div>Reason: customer goodwill credit</div></li>
        </ol>
      </details>
      <button class="time-adjust" type="button"
              data-entry-id="88" data-original-minutes="60" data-original-billable="1"
              data-effective-minutes="45" data-effective-billable="0" data-version="2">Adjust effective value</button>
      <button class="time-billing" type="button" data-entry-id="88" data-billing-action="send">Send to billing</button>
    </div>
    <div class="adjustment-row" data-time-entry-id="89">
      <div class="adjustment-original">Original approval: 20m internal</div>
      <div class="adjustment-effective">Effective version 0 (original): 20m internal</div>
      <button class="time-adjust" type="button"
              data-entry-id="89" data-original-minutes="20" data-original-billable="0"
              data-effective-minutes="20" data-effective-billable="0" data-version="0">Adjust effective value</button>
    </div>
  </section>
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
    if (url.pathname === '/api/timer.php'
        || url.pathname === '/api/time_entry_correction.php'
        || url.pathname === '/api/time_entry_review.php'
        || url.pathname === '/api/time_entry_adjustment.php'
        || url.pathname === '/api/time_entry_billing.php') {
      return responder(route, url.pathname);
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto(`${ORIGIN}/time.php`);
  return { browser, context, page, errors };
}

function adjustmentSuccess(body, overrides = {}) {
  return {
    ok: true,
    adjustment_ack: {
      adjustment_key: body.adjustment_key,
      entry_id: body.entry_id,
      version_no: body.expected_version + 1,
      effective_minutes: body.effective_minutes,
      effective_billable: body.effective_billable,
      adjusted_by_user_id: 11,
      reason: body.reason,
      replayed: false,
      ...overrides,
    },
  };
}

test('mobile navigation opens, closes and returns focus with Escape', async () => {
  const session = await openPage({ responder: () => assert.fail('navigation must not call an API') });
  try {
    const { page } = session;
    const menu = page.getByRole('button', { name: 'Menu', exact: true });
    await menu.click();
    assert.equal(await menu.getAttribute('aria-expanded'), 'true');
    await page.getByRole('button', { name: 'Close navigation', exact: true }).click();
    assert.equal(await menu.getAttribute('aria-expanded'), 'false');
    await menu.click();
    await page.keyboard.press('Escape');
    assert.equal(await menu.getAttribute('aria-expanded'), 'false');
    assert.equal(await menu.evaluate(el => el === document.activeElement), true);
    await menu.click();
    await page.getByRole('button', { name: 'Close menu backdrop' }).click();
    assert.equal(await menu.getAttribute('aria-expanded'), 'false');
    assert.deepEqual(session.errors, []);
  } finally { await session.browser.close(); }
});

test('billing sends only the selected entry and reloads its acknowledged status', async () => {
  const requests = [];
  const session = await openPage({ responder: async (route, pathname) => {
    assert.equal(pathname, '/api/time_entry_billing.php');
    requests.push(route.request().postDataJSON());
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, label: 'In Coastmark · version 2' }) });
  } });
  try {
    const navigation = session.page.waitForEvent('framenavigated');
    await session.page.getByRole('button', { name: 'Send to billing', exact: true }).click();
    await navigation;
    assert.deepEqual(requests, [{ entry_id: 88, action: 'send' }]);
    assert.deepEqual(session.errors, []);
  } finally { await session.browser.close(); }
});

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

test('review retries freeze one normalized decision through network, 5xx, and mismatched acknowledgements', async () => {
  const requests = [];
  let attempt = 0;
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_review.php');
      const body = route.request().postDataJSON();
      requests.push(body);
      attempt += 1;
      if (attempt === 1) return route.abort('failed');
      if (attempt === 2) {
        return route.fulfill({
          status: 503,
          contentType: 'application/json',
          body: JSON.stringify({ error: 'Temporarily unavailable.' }),
        });
      }
      if (attempt === 3) {
        return route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            ok: true,
            review_ack: {
              entry_id: body.entry_id,
              decision: body.decision,
              note: body.note,
              replayed: true,
            },
          }),
        });
      }
      if (attempt === 4) {
        return route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            ok: true,
            review_ack: {
              entry_id: String(body.entry_id),
              decision: body.decision,
              reviewer_user_id: '11',
              note: body.note,
              replayed: true,
            },
          }),
        });
      }
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          review_ack: {
            entry_id: body.entry_id,
            decision: body.decision,
            reviewer_user_id: 11,
            note: body.note,
            replayed: true,
          },
          toast: 'Time rejected',
        }),
      });
    },
  });

  try {
    const { page, errors } = session;
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      prompts += 1;
      await dialog.accept('  Missing customer notes  ');
    });

    const row = page.locator('.review-row');
    const approve = row.locator('[data-decision="approved"]');
    const reject = row.locator('[data-decision="rejected"]');
    for (let i = 0; i < 4; i += 1) {
      await reject.click();
      await page.waitForFunction(() => {
        const row = document.querySelector('.review-row');
        const retry = row?.querySelector('[data-decision="rejected"]');
        const alternate = row?.querySelector('[data-decision="approved"]');
        return retry?.disabled === false
          && alternate?.disabled === true;
      });
      assert.equal(await row.count(), 1, 'an uncertain or mismatched reply must retain the row');
      assert.equal(await reject.isEnabled(), true, 'the exact frozen action must remain retryable');
      assert.equal(await approve.isDisabled(), true, 'an alternate decision must stay unavailable');
    }

    await reject.click();
    await page.locator('#time-review-queue .empty').waitFor();
    assert.equal(await row.count(), 0, 'a matching replay receipt clears the row');
    assert.equal(prompts, 1, 'retries must not ask for changed review facts');
    assert.equal(requests.length, 5);
    assert.deepEqual(requests[0], {
      entry_id: 73,
      decision: 'rejected',
      note: 'Missing customer notes',
    });
    requests.slice(1).forEach((request) => assert.deepEqual(request, requests[0]));
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('an overlong rejection note stays editable and never reaches the server', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_review.php');
      requests.push(route.request().postDataJSON());
      return route.fulfill({ status: 500, body: '' });
    },
  });

  try {
    const { page, errors } = session;
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      prompts += 1;
      await dialog.accept('x'.repeat(501));
    });

    const row = page.locator('.review-row');
    const approve = row.locator('[data-decision="approved"]');
    const reject = row.locator('[data-decision="rejected"]');
    await reject.click();
    await page.locator('.toast').filter({ hasText: 'cannot exceed 500 characters' }).waitFor();

    assert.equal(prompts, 1);
    assert.equal(requests.length, 0, 'client validation must not send a known-invalid review');
    assert.equal(await approve.isEnabled(), true);
    assert.equal(await reject.isEnabled(), true);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('a deterministic review validation error reopens the draft for an edited retry', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_review.php');
      const body = route.request().postDataJSON();
      requests.push(body);
      if (requests.length === 1) {
        return route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ error: 'Use an accepted rejection reason.' }),
        });
      }
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          review_ack: {
            entry_id: body.entry_id,
            decision: body.decision,
            reviewer_user_id: 11,
            note: body.note,
            replayed: false,
          },
          toast: 'Time rejected',
        }),
      });
    },
  });

  try {
    const { page, errors } = session;
    const answers = ['First server-rejected note', 'Edited accepted note'];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      await dialog.accept(answers[prompts]);
      prompts += 1;
    });

    const row = page.locator('.review-row');
    const approve = row.locator('[data-decision="approved"]');
    const reject = row.locator('[data-decision="rejected"]');
    await reject.click();
    await page.locator('.toast').filter({ hasText: 'Use an accepted rejection reason.' }).waitFor();
    assert.equal(await approve.isEnabled(), true, 'a deterministic validation error must reopen both choices');
    assert.equal(await reject.isEnabled(), true);

    await reject.click();
    await page.locator('#time-review-queue .empty').waitFor();
    assert.equal(prompts, 2, 'the retry must ask for an edited note');
    assert.equal(requests.length, 2);
    assert.equal(requests[0].note, answers[0]);
    assert.equal(requests[1].note, answers[1]);
    assert.notDeepEqual(requests[1], requests[0]);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('a review conflict refreshes to reconcile instead of enabling another decision', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_review.php');
      requests.push(route.request().postDataJSON());
      return route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Review state changed.' }),
      });
    },
  });

  try {
    const { page, errors } = session;
    page.on('dialog', async (dialog) => dialog.accept('Already handled'));
    const reloaded = page.waitForEvent('load');
    await page.locator('[data-decision="rejected"]').click();
    await page.locator('.toast').filter({ hasText: 'refreshing' }).waitFor();
    assert.equal(await page.locator('[data-decision="approved"]').isDisabled(), true);
    assert.equal(await page.locator('[data-decision="rejected"]').isDisabled(), true);
    await reloaded;
    assert.equal(requests.length, 1, 'a conflict must not silently retry another action');
    assert.deepEqual(requests[0], {
      entry_id: 73,
      decision: 'rejected',
      note: 'Already handled',
    });
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('approved adjustment UI labels original evidence separately and accepts only an exact typed receipt', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_adjustment.php');
      const body = route.request().postDataJSON();
      requests.push(body);
      const response = adjustmentSuccess(body);
      response.toast = 'Approved time adjusted';
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(response),
      });
    },
  });

  try {
    const { page, errors } = session;
    assert.match(TIME_PHP, /WHERE e\.tenant_id = \? AND e\.approval_status = 'approved'/);
    assert.match(TIME_PHP, /AND e\.id = \? LIMIT 1/);
    assert.match(TIME_PHP, /Original approval:/);
    assert.match(TIME_PHP, /Effective version/);
    assert.match(TIME_PHP, /time_entry_adjustment_history_by_entry/);
    assert.match(TIME_PHP, /All correction slips/);
    assert.match(TIME_PHP, /Extra time must be logged as a new pending entry/);
    assert.match(ADJUSTMENT_API_PHP, /TIME_ENTRY_ADJUSTMENT_ROLES/);
    assert.match(ADJUSTMENT_API_PHP, /'adjustment_ack' => \[/);
    assert.match(ADJUSTMENT_API_PHP, /'version_no' => \(int\) \$adjustment\['version'\]/);
    const row = page.locator('.adjustment-row[data-time-entry-id="88"]');
    assert.match(await row.locator('.adjustment-original').innerText(), /Original approval: 60m billable/);
    assert.match(await row.locator('.adjustment-effective').innerText(), /Effective version 2: 45m internal/);
    assert.match(await row.locator('.adjustment-reason').innerText(), /customer goodwill credit/);
    assert.match(await row.locator('.adjustment-history summary').innerText(), /All correction slips \(2\)/);
    await row.locator('.adjustment-history summary').click();
    assert.deepEqual(
      await row.locator('.adjustment-history-item').allInnerTexts(),
      [
        'Version 1 · 50m billable · by user #11\nReason: duplicate work',
        'Version 2 · 45m internal · by user #12\nReason: customer goodwill credit',
      ],
    );

    const answers = ['30', 'I', 'Customer-approved reduction'];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      await dialog.accept(answers[prompts]);
      prompts += 1;
    });

    await row.locator('.time-adjust').click();
    await page.locator('.toast').filter({ hasText: 'Approved time adjusted' }).waitFor();
    assert.equal(prompts, 3);
    assert.equal(requests.length, 1);
    assert.deepEqual(requests[0], {
      entry_id: 88,
      adjustment_key: requests[0].adjustment_key,
      expected_version: 2,
      effective_minutes: 30,
      effective_billable: false,
      reason: 'Customer-approved reduction',
    });
    assert.match(requests[0].adjustment_key, /^adjustment:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('approved adjustment retries freeze one cryptographic key and payload through network, 5xx, and malformed success', async () => {
  const requests = [];
  let attempt = 0;
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_adjustment.php');
      const body = route.request().postDataJSON();
      requests.push(body);
      attempt += 1;
      if (attempt === 1) return route.abort('failed');
      if (attempt === 2) {
        return route.fulfill({
          status: 503,
          contentType: 'application/json',
          body: JSON.stringify({ error: 'Temporarily unavailable.' }),
        });
      }
      if (attempt === 3) {
        return route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify(adjustmentSuccess(body, {
            version_no: String(body.expected_version + 1),
          })),
        });
      }
      const response = adjustmentSuccess(body, { replayed: true });
      response.toast = 'Adjustment already saved';
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(response),
      });
    },
  });

  try {
    const { page, errors } = session;
    const answers = ['25', 'B', 'Contract credit'];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      await dialog.accept(answers[prompts]);
      prompts += 1;
    });

    const button = page.locator('.time-adjust[data-entry-id="88"]');
    for (let index = 0; index < 3; index += 1) {
      await button.click();
      await page.waitForFunction(() => {
        const control = document.querySelector('.time-adjust[data-entry-id="88"]');
        return control?.disabled === false && Boolean(control.dataset.adjustmentPayload);
      });
      assert.equal(prompts, 3, 'an uncertain retry must never ask for edited facts');
    }

    await button.click();
    await page.locator('.toast').filter({ hasText: 'Adjustment already saved' }).waitFor();
    assert.equal(requests.length, 4);
    requests.slice(1).forEach((request) => assert.deepEqual(request, requests[0]));
    assert.match(requests[0].adjustment_key, /^adjustment:/);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('a deterministic adjustment validation error clears the draft for an edited key and payload', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_adjustment.php');
      const body = route.request().postDataJSON();
      requests.push(body);
      if (requests.length === 1) {
        return route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ error: 'Use a more specific adjustment reason.' }),
        });
      }
      const response = adjustmentSuccess(body);
      response.toast = 'Edited adjustment saved';
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(response),
      });
    },
  });

  try {
    const { page, errors } = session;
    const answers = ['40', 'B', 'First reason', '35', 'I', 'Specific approved reason'];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      assert.equal(dialog.type(), 'prompt');
      await dialog.accept(answers[prompts]);
      prompts += 1;
    });

    const button = page.locator('.time-adjust[data-entry-id="88"]');
    await button.click();
    await page.locator('.toast').filter({ hasText: 'Use a more specific adjustment reason.' }).waitFor();
    assert.equal(await button.isEnabled(), true);
    assert.equal(await button.getAttribute('data-adjustment-payload'), '');

    await button.click();
    await page.locator('.toast').filter({ hasText: 'Edited adjustment saved' }).waitFor();
    assert.equal(prompts, 6);
    assert.equal(requests.length, 2);
    assert.notEqual(requests[1].adjustment_key, requests[0].adjustment_key);
    assert.equal(requests[0].effective_minutes, 40);
    assert.equal(requests[0].reason, 'First reason');
    assert.equal(requests[1].effective_minutes, 35);
    assert.equal(requests[1].effective_billable, false);
    assert.equal(requests[1].reason, 'Specific approved reason');
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('an approved adjustment version conflict refreshes to reconcile and never offers a stale retry', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_adjustment.php');
      requests.push(route.request().postDataJSON());
      return route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Approved time version changed.' }),
      });
    },
  });

  try {
    const { page, errors } = session;
    const answers = ['30', 'I', 'Version conflict test'];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      await dialog.accept(answers[prompts]);
      prompts += 1;
    });

    const button = page.locator('.time-adjust[data-entry-id="88"]');
    const reloaded = page.waitForEvent('load');
    await button.click();
    await page.locator('.toast').filter({ hasText: 'Refreshing to reconcile' }).waitFor();
    assert.equal(await button.isDisabled(), true);
    await reloaded;
    assert.equal(prompts, 3);
    assert.equal(requests.length, 1);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});

test('the browser blocks added minutes and never offers billable for an originally internal approval', async () => {
  const requests = [];
  const session = await openPage({
    responder: async (route, pathname) => {
      assert.equal(pathname, '/api/time_entry_adjustment.php');
      const body = route.request().postDataJSON();
      requests.push(body);
      const response = adjustmentSuccess(body);
      response.toast = 'Internal adjustment saved';
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(response),
      });
    },
  });

  try {
    const { page, errors } = session;
    const answers = ['21', '10', 'Duplicate internal work'];
    const questions = [];
    let prompts = 0;
    page.on('dialog', async (dialog) => {
      questions.push(dialog.message());
      await dialog.accept(answers[prompts]);
      prompts += 1;
    });

    const button = page.locator('.time-adjust[data-entry-id="89"]');
    await button.click();
    await page.locator('.toast').filter({ hasText: 'cannot add time' }).waitFor();
    assert.equal(requests.length, 0);

    await button.click();
    await page.locator('.toast').filter({ hasText: 'Internal adjustment saved' }).waitFor();
    assert.equal(prompts, 3, 'internal time asks for minutes and reason only after the invalid first attempt');
    assert.equal(questions.some((question) => /billing/i.test(question)), false);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].effective_minutes, 10);
    assert.equal(requests[0].effective_billable, false);
    assert.equal(requests[0].expected_version, 0);
    assert.deepEqual(errors, []);
  } finally {
    await session.browser.close();
  }
});
