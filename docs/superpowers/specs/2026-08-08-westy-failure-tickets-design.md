# Westy failure tickets — design

**Date:** 2026-08-08 · **Status:** approved, not yet implemented
**Scope of this build:** Safeharbor only. Milepost and the Control Panel follow
as separate PRs against the wire contract frozen in §7.

When Westy breaks, or when a technician marks an answer as unhelpful, a ticket
opens in **8 West IT's own Safeharbor queue** so our staff can fix Westy. Never
in a customer's queue.

---

## 1. Verified state (checked on the live box, not assumed)

Four things were checked against `safeharbor.8westit.com` before designing.
Three contradicted the repo docs.

| Question | Answer | Evidence |
|---|---|---|
| Is `svc.enabled` off in production? | **No — it is `true`**, with a real 64-char `milepost` secret (not the sample string) | live `config/config.php` |
| Did `002_svc_intake.sql` run? | **Yes, fully** — `tickets.external_key`, unique key `(tenant_id, external_key)`, `svc_identities`, `svc_rate_buckets` all present | `SHOW COLUMNS` / `SHOW INDEX` / `SHOW TABLES` |
| Does 8 West IT exist as a tenant? | **Yes — `id = 1`, slug `8west`, "8 West IT, LLC". It is the only tenant.** | `SELECT * FROM tenants` |
| Where does `westy_chat.php` swallow failures? | `api/westy_chat.php:79-87` | source |

Two further facts that shaped the design:

- **The service pipe is live and busy**, not dark: 59 `channel='alert'` tickets
  since 2026-07-29, newest 2026-08-09 04:45 UTC; `svc_identities.milepost.last_seen_at`
  = 2026-08-09 05:09 UTC. Milepost's Sprint 8.1.2 emitter shipped.
- **Westy has been used twice ever in production**, with zero logged errors.
  There is no live failure data to learn from.

### 1.1 Stale claims to correct in the same PR

`AGENTS.md:18-20` says flipping `svc.enabled` is "remaining before the v1.0
stamp" — it is already flipped. `AGENTS.md:138-140` omits `002_svc_intake.sql`
from the applied list — it is applied. Both get corrected.

### 1.2 The swallow points

| Location | Today's behaviour |
|---|---|
| `westy_chat.php:79-82` | provider returned not-ok → one 255-char `assistant_log` row → 502 |
| `westy_chat.php:84-87` | empty reply → same → 502 |
| `westy_chat.php:60-62` | rate-limit query failure ignored silently |
| `westy_chat.php:95-100` | `westy_log()` swallows its own insert errors |
| `westy.js:216-222` | one quiet retry, then an apology to the tech |

Nobody is ever told. That is what this feature fixes.

### 1.3 The flood lesson, visible in live data

Milepost's `external_key` values are `alert:231`, `alert:232`, `alert:233` — the
**occurrence** id. One nagging memory warning on `FRANKIE_DESKTOP` has therefore
opened six separate tickets in a day. The dedupe machinery works correctly; it
is being handed a key that changes every time.

**Westy's key must describe the problem, never the moment.** This is the single
most important constraint in this design.

---

## 2. Decisions

Pre-decided, not re-opened:

1. **Trigger** — real failures only, plus a "this wasn't helpful" control the
   tech presses on purpose. Never automatic on an "I don't know" answer.
2. **Content** — failures carry technical detail only, no chat text. A
   thumbs-down carries the flagged question and answer, and the tech sees
   exactly what will be sent and can edit or cancel first.
3. **Flood control** — one ticket per problem; repeats update it with a count
   and last-seen, reusing the existing `external_key` dedupe.
4. **Destination** — 8 West IT's own tenant on the live `safeharbor.8westit.com`.

Decided 2026-08-08:

5. **Thumbs-down appears on every Westy answer** — not only after trouble. A
   confidently-wrong answer shows no trouble signal, and that is the dangerous case.
6. **All three apps report** — Safeharbor, Milepost, and the Control Panel —
   but **Safeharbor ships first with the contract frozen**; the other two follow
   as separate PRs.
7. **A new service identity per calling app.** A Westy failure storm must not
   burn the rate-limit budget that real alert intake depends on.
8. **A client row per reporting app** — "Westy — Safeharbor", "Westy — Milepost",
   "Westy — Control Panel".
9. **A provider refusal is not a failure.** `ai.php` returns `refusal => true`
   when the model declines; that is the safety layer working. The tech can still
   thumbs-down it.
10. **Safeharbor reports its own failures in-process** — a direct function call,
    no loopback HTTP, no signing a request to itself.
11. **The tech gets one quiet line**: "I've logged this for the 8 West team."
    No ticket number.

---

## 3. Architecture

Three entry points, one handler, one dedupe table.

```
 Safeharbor tech presses 👎
   └─ POST /api/westy_feedback.php   (session + CSRF)  ─┐
                                                        │
 Safeharbor Westy call fails                            │
   └─ westy_report_failure()  (in-process, never throws)─┼─→ westy_report_record()
                                                        │      │
 Milepost / Control Panel  (later PRs)                   │      ├─ fingerprint
   └─ POST /api/svc/westy.php  (HMAC, svc_auth.php) ─────┘      ├─ westy_reports upsert
                                                                └─ tickets create/update
```

`lib/westy_report.php` is the only place that writes. The three doors differ
only in how they authenticate and where the payload comes from.

**Why a sibling endpoint rather than extending `alerts.php`:** the existing
handler is Milepost-alert-shaped (`endpoint`, `alert.rule_key`, `metric_key`,
`severity`), `svc_system_line()` hardcodes the author `'Milepost'`, and
unmatched sources fall into the "Milepost Intake" client. Westy events do not
fit that mould. The reused parts are `svc_auth.php` (unchanged) and the
`external_key` dedupe — not the alert payload shape.

---

## 4. The fingerprint

The fingerprint is the base key; `external_key` is the fingerprint plus a
generation suffix when a resolved problem returns (§5.3).

```
fingerprint = "westy:" + app + ":" + kind + ":" + substr(sha256(material), 0, 16)
```

`app` ∈ `safeharbor` | `milepost` | `controlpanel`. `kind` ∈ `fail` | `flag`.

**Failure material** — `app | surface | provider | model | error_class | http_status`

`error_class` is the error message normalised so that cosmetic variation
collapses to one problem:

1. lowercase
2. replace every run of digits with `#`
3. replace any single- or double-quoted span with `…`
4. collapse whitespace runs to one space, trim
5. cut to 120 characters

So "Timeout after 30s" and "timeout after 31 s" are one problem, as are two
overload errors that differ only in a request id.

**Flag material** — `app | normalised_question`

Question normalisation: lowercase, strip punctuation, collapse whitespace, cut
to 300 characters. The same bad question flagged by three techs is one ticket.

**Length budget:** `westy:` (6) + app (≤12) + `:` + kind (4) + `:` (1) + hash (16)
= 40 max, plus `:g99` (4) = 44. Fits `VARCHAR(64)` with room to spare.

---

## 5. Data

### 5.1 `db/migrations/008_westy_reports.sql`

008 is the next free number. `db/schema.sql` is updated in lockstep (house rule).

```sql
CREATE TABLE IF NOT EXISTS westy_reports (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         INT UNSIGNED NOT NULL,
  fingerprint       VARCHAR(48)  NOT NULL,   -- base key, no generation suffix
  external_key      VARCHAR(64)  NOT NULL,   -- fingerprint [+ ":g" + generation]
  app               VARCHAR(32)  NOT NULL,
  kind              ENUM('fail','flag') NOT NULL,
  generation        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  ticket_id         INT UNSIGNED NULL,
  system_message_id INT UNSIGNED NULL,       -- the one line rewritten in place
  occurrences       INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detail_json       TEXT NULL,               -- scrubbed envelope, latest occurrence
  PRIMARY KEY (id),
  UNIQUE KEY uq_westy_reports_key (tenant_id, external_key),
  KEY idx_westy_reports_fp (tenant_id, fingerprint, generation),
  KEY idx_westy_reports_ticket (ticket_id),
  CONSTRAINT fk_westy_reports_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Why its own table rather than columns on `tickets`:** `tickets` is shared by
email intake, the portal and alert intake. Count, last-seen and generation are
Westy-specific state and do not belong in a table three other paths write to.

### 5.2 Count, last-seen, and not drowning the ticket

The existing alert handler appends a system line on **every** re-fire —
unbounded. Westy does not.

- The ticket carries **one** system line, whose body is **rewritten in place**
  (`UPDATE messages SET body = ? WHERE id = system_message_id`) on every repeat,
  always showing the current count and last-seen.
- A **new** line is appended only when `occurrences` crosses **5, 25, or 100** —
  so a genuine spike is visible in the thread without 400 lines of noise.
- At 25, the ticket's priority is raised to `high` once.

### 5.3 When a resolved problem comes back

A repeat landing on a **resolved** ticket is news, not noise. The handler bumps
`generation` and opens a **new** ticket under `<fingerprint>:g2`, with a system
line linking back to the previous ticket id. Nothing silently reopens (which
would fight the tech who closed it) and nothing is silently dropped.

Lookup is therefore: find the highest-generation row for the fingerprint; if its
ticket is resolved, start the next generation; otherwise update in place.

### 5.4 Ticket fields

| Field | Failure | Thumbs-down |
|---|---|---|
| `channel` | `alert` | `alert` |
| `priority` | `normal` | `low` |

Both kinds are raised to `high` at 25 occurrences per §5.2 — 25 techs flagging
the same answer is as much a problem as 25 provider failures.

| `client_id` | "Westy — {App}" catch-all, created on demand | same |
| `contact_id` | `NULL` | `NULL` |
| `subject` | `Westy failure — {error_class, 90 chars} ({app})` | `Westy answer flagged — {question, 60 chars} ({app})` |
| `sla_due_at` | via existing `svc_client_sla_hours()` (standard = 8h) | same |
| `external_key` | §4 | §4 |

System-line author is `'Westy'` — `svc_system_line()`'s hardcoded `'Milepost'`
is not reused.

---

## 6. Content rules

### 6.1 Failure tickets — technical only

App, surface, provider, model, error class, HTTP status, staff user reference
(an opaque internal id — never a name or an email address), UTC timestamp,
occurrence count, first/last seen. **No chat text, ever.**

> **Leak risk, named deliberately.** `ai.php:107` and `ai.php:147` build their
> error strings from the *provider's own message*, and some providers echo part
> of the request back inside an error. "No chat text" is therefore **not**
> automatic. Two defences:
>
> 1. The fingerprint is computed from the error **class** (§4), never the raw text.
> 2. `westy_scrub()` runs over any stored detail: strip anything matching an
>    email address, replace quoted spans longer than 40 characters with `…`,
>    cap at 500 characters.
>
> The receiver scrubs even when the emitter claims it already did — defence in
> depth across a repo boundary we do not control.

### 6.2 Thumbs-down tickets — question and answer, tech-approved

The server keeps **no** Westy transcript (`westy.js` holds it in `sessionStorage`
only), so the question and answer come up from the browser. The tech sees the
exact text in an editable panel and presses Send or Cancel. Nothing leaves the
browser unreviewed. The same technical envelope as §6.1 is attached.

The approved question, answer and note are written into the ticket's system line
body (and `detail_json`). When a second tech flags the same question, the line
**keeps the first example** and updates the count and last-seen — the wording
that got flagged first is the one worth reading, and swapping it on every repeat
would make the ticket unstable to review.

---

## 7. Wire contract (frozen — Milepost and the Control Panel code against this)

### `POST /api/svc/westy.php`

Authentication is `lib/svc_auth.php` **unchanged**: hex HMAC-SHA256 over
`"{timestamp}\n{raw_body}"`, sent as `X-8W-Service` / `X-8W-Timestamp` /
`X-8W-Signature`; ±300 s replay window; 16 KB body cap; 120 requests/minute per
identity; failures are a deliberately indistinguishable 401.

Service identities (§2.7) — one row in `svc_identities` and one secret in the
server `config.php` `svc.secrets` block per app:

| App | Service identity |
|---|---|
| Milepost | `milepost-westy` |
| Control Panel | `controlpanel-westy` |
| Safeharbor | *(none — in-process)* |

Deliberately **separate** from the existing `milepost` identity so a Westy
failure storm cannot starve real alert intake of its rate budget.

**Request body**

```json
{
  "event":       "failure" | "flagged",
  "app":         "milepost",
  "surface":     "westy_chat",
  "occurred_at": "2026-08-08T20:11:03Z",
  "user_ref":    "42",

  "failure": {
    "provider":     "anthropic",
    "model":        "claude-opus-4-8",
    "http_status":  529,
    "error_class":  "provider error: overloaded",
    "error_detail": "…scrubbed, <= 500 chars…"
  },

  "flagged": {
    "question": "…",
    "answer":   "…",
    "note":     "…optional, what went wrong…"
  }
}
```

- `failure` is required when `event = "failure"`; `flagged` when `event = "flagged"`.
- **On `event = "failure"` the receiver ignores any `flagged` block entirely.**
  Decision 2 is enforced by the receiver, not trusted to the caller.
- `occurred_at` must be within 24 hours, matching the alert endpoint's rule.
- `user_ref` is an opaque internal id, sent as a string — never a name or an
  email address. Optional; omit rather than invent one.
- `surface` names the part of the app Westy was working in when it broke
  (`westy_chat`, `westy_drafts`, …). It is part of the fingerprint, so the same
  provider error in two different surfaces stays two separate tickets.
- `channel` on the resulting ticket is `alert` — the existing enum value, reused.
  No enum change; the client row is what distinguishes Westy tickets from
  Milepost alerts.

**Responses** — `200 {ok, ticket, action: created|updated|ignored}` ·
`401` bad/absent auth · `404` `svc.enabled` off · `405` non-POST ·
`422` bad payload · `429` over rate · `500` unexpected (caller retries).

### `POST /api/westy_feedback.php` (Safeharbor's own techs)

Session-authed and CSRF-checked, exactly like `westy_chat.php` — **not** the
HMAC door. A logged-in technician uses the front door; the service endpoint is
only for other apps.

Fields: `csrf`, `question` (≤2000), `answer` (≤4000), `note` (≤500). Rate
limited from `assistant_log` with `action = 'westy_feedback'`, reusing the
sliding-window pattern already in `westy_chat.php:50-63`. Returns `{ok: true}`.

---

## 8. Destination and failure-closed behaviour

`tenant_id()` returns **1** when there is no session (`bootstrap.php:122-128`).
That happens to be the 8 West tenant today — by accident, not by design, and
only because exactly one tenant exists. The moment a second tenant is created,
that fallback silently misroutes internal tickets into a customer's queue.

**Therefore:** `westy_report_tenant_id()` resolves the destination explicitly —
`SELECT id FROM tenants WHERE slug = '8west'` — statically cached. If that row
does not exist, the handler **records nothing and logs**. Fails closed. It never
falls back to tenant 1.

Client rows are created on demand, mirroring `svc_intake_client_id()`:
"Westy — Safeharbor" in this build; "Westy — Milepost" and "Westy — Control Panel"
reserved by the contract.

---

## 9. Error handling

- **Reporting must never take Westy down.** `westy_report_failure()` is wrapped
  in `try/catch` at the call site, exactly like the existing `westy_log()`
  discipline (`westy_chat.php:95-100`). A broken reporter degrades to today's
  behaviour, never to a 500 in the chat.
- **Refusals are skipped** (`$r['refusal'] === true`) per decision 9.
- **Empty replies are reported**, with `error_class = 'empty reply'`.
- The svc endpoint returns a clean `500` on an unexpected fault so the caller's
  outbox backs off and retries — no stack trace, no credential on the wire.
- **`svc.enabled` is already `true`**, so `api/svc/westy.php` is live the moment
  it deploys. Before Milepost and the Control Panel ship their emitters it is an
  authenticated endpoint with no callers, which is acceptable and intended.

## 10. Testing

`app/tests/westy_report_test.php`, following `svc_intake_test.php` exactly:
CLI-only, hermetic, against the `safeharbor_test` scratch database, `check()`
assertions, exit 0/1.

Cases:

1. First failure creates one ticket in the `8west` tenant with the right client.
2. The same failure with a different request id and timestamp **updates** the
   same ticket — count 2, one system line, rewritten not appended.
3. Crossing 5 appends exactly one new line; crossing 25 also raises priority once.
4. A repeat after the ticket is resolved opens generation 2 and links back.
5. A refusal reports nothing.
6. A thumbs-down creates a `flag` ticket carrying question and answer.
7. Two techs flagging the same question produce one ticket, count 2.
8. `event = "failure"` carrying a `flagged` block stores no chat text.
9. `westy_scrub()` removes email addresses and long quoted spans.
10. A missing `8west` tenant records nothing and does not throw.
11. `error_class` normalisation collapses digit- and quote-only variants.

Plus `php -l` across the app per the repo's lint command.

## 11. Files

**New** — `app/lib/westy_report.php` · `app/public/api/westy_feedback.php` ·
`app/public/api/svc/westy.php` · `app/db/migrations/008_westy_reports.sql` ·
`app/tests/westy_report_test.php` · `docs/westy-failure-reporting-contract.md`
(§7, extracted for the other two repos).

**Changed** — `app/public/api/westy_chat.php` (report at the two swallow points,
quiet line in the 502) · `app/public/assets/js/westy.js` (the control + review
panel) · `app/public/assets/css/app.css` · `app/lib/westy.php`
(`westy_chat_system_prompt()` must name the new control — house rule: ship a
feature, update the prompt in the same PR, or Westy starts lying) ·
`app/db/schema.sql` · `app/config/config.sample.php` (the two new svc secrets) ·
`AGENTS.md` (§1.1 corrections).

## 12. Out of scope

- **Milepost and Control Panel emitters.** Separate PRs against §7. Milepost's
  half is cheap — `svc_outbox`, `cron/svc_dispatch.php` and a byte-compatible
  signer already exist; the outbox row is keyed to `alert_id` and needs
  generalising. The Control Panel needs a new HMAC signer in Go.
- Any write-back to Westy, any auto-remediation, any change to alert intake.
- A reporting dashboard for Westy defects — the queue is the dashboard.

## 13. Deploy

1. Merge (Frank — Claude cannot merge PRs).
2. `SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh`
3. Stream `008_westy_reports.sql` to `sudo mysql safeharbor` — migrations are
   manual, and the applied list must be updated in `AGENTS.md` this time.
4. Add the two new `svc.secrets` entries and the two `svc_identities` rows only
   when the Milepost / Control Panel emitters actually ship.
