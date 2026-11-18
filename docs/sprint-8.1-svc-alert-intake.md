> ## ⚠️ Historical spec — superseded in part. Read this first.
>
> This is the **2026-07-28 design record** for Phase 8.1, kept for its
> reasoning and its wire contract. It is not a statement of current state.
> Corrected 2026-08-02 against the code in this repo:
>
> - **"implementation not started" is false.** Sprint 8.1.1 shipped and is
>   deployed dark. The code is `app/public/api/svc/alerts.php`,
>   `app/lib/svc_auth.php`, `app/lib/svc_intake.php`,
>   `app/db/migrations/002_svc_intake.sql`, tests in
>   `app/tests/svc_intake_test.php`. Those files, not this document, are the
>   current source of truth for behaviour.
> - **§7 "Explicitly out of scope (v1)" is wrong about auto-resolve.**
>   Auto-closing an untouched ticket when its alert clears at source is
>   **shipped**, not deferred, and there is no `svc_alert_autoresolve` flag —
>   the founder rule of 2026-07-30 replaced it. §2 of this document already
>   records the rule correctly; §7 was never updated and now contradicts it.
>   The pre-018 deployed behaviour treated `status='open'` as untouched.
>   Migration 018 and matching release `e7ee521` went live on 2026-08-27 and
>   tighten that rule to a one-use database capability that human/customer
>   messages, ticket edits, merges, and time entries permanently consume. See
>   `ticket-auto-close-ownership-contract.md` for the recorded production gate.
> - **Migration numbering hazard.** `002_svc_intake.sql` collides on the
>   number 002 with `002_westy_onboarding.sql`, and the applied-so-far lists
>   in `AGENTS.md` and `deploy/README.md` name only the westy one. Check the
>   live schema for `tickets.external_key` and `svc_identities` before
>   assuming this migration ran.
> - Section 2 includes the S11 receiver contract for customer IDs, network
>   alert keys and offline triage. The rollout sections describe
>   the original project. See [current deployment notes](where-things-stand.md).
> - **Still true:** `svc.enabled` defaulting to
>   `false` in `config.sample.php`, and the endpoint answering `404` while
>   the flag is off. Sprint 8.1.2 (the Milepost emitter) had not shipped as
>   of 2026-08-02 per `AGENTS.md`; that is an assertion about another repo
>   and is not verifiable from here.
> - **Suite context has moved on.** Mission Control is now a live, flagship
>   product (§7's last bullet still reads as though it were future work), and
>   the suite identity contract this document borrows HMAC conventions from
>   has been corrected — see `docs/suite-sso-contract.md`.

# Phase 8.1 — Milepost → Safeharbor Alert Intake (Path B)

**Status:** spec for review (Frankie + Mary) · implementation not started
**Decision:** Path B — signed, structured service-to-service intake under
`api/svc/*`, chosen by Frankie on 2026-07-28 over reusing the email pipeline.
**Scope:** one direction only. Milepost emits alert lifecycle events;
Safeharbor turns them into tickets and keeps them in sync. Safeharbor never
calls back into Milepost, never remediates, never writes to the Milepost DB.

When a client server throws a critical alert at 2 AM, the ticket should
already exist when the tech opens the queue — no email scraping, no copy-paste,
no lost alerts. That is the whole point of this phase.

---

## 1. What already exists (verified in this repo and in prod)

| Building block | State | Reuse |
|---|---|---|
| `tickets.channel` ENUM | already contains `'alert'` | Path B tickets use `channel = 'alert'` — no ENUM change needed |
| `clients` / `contacts` | name + domain matching exists in `lib/intake.php` | mirror the catch-all pattern (`intake_client_id()`) as `svc_intake_client_id()` |
| `messages.kind = 'system'` | used for intake provenance lines | alert lifecycle lines ("opened at source", "resolved at source") |
| `mail_queue` | outbound notifications for assignees | optional "critical alert ticket created" notice — same queue, no new mail path |
| HMAC-SHA256 + `hash_equals` | `lib/jwt.php` codec style | same primitives, same constant-time compare for the svc signature |
| Milepost `alerts` table | `id, agent_id, rule_key, metric_key, instance, severity, status, message, opened_at, updated_at, resolved_at` (field map verified in prod, Milepost PR #151) | everything the emitter needs is already read-model proven |
| Milepost cron + outbox pattern | `cron/` + `mail_queue` convention | emitter uses a `svc_outbox` table drained by cron — no synchronous coupling |

Deliberately **not** reused: the email intake (`lib/intake.php`) stays exactly
as it is. Path B is a second front door with a badge reader, not a rebuild of
the first one.

## 2. Wire contract

One endpoint per event family, page-per-file per house style:

```
POST /api/svc/alerts.php        Host: safeharbor.8westit.com (TLS only)
X-8W-Service: milepost
X-8W-Timestamp: 1785283200
X-8W-Signature: <hex hmac_sha256(secret, "{timestamp}\n{raw_body}")>
Content-Type: application/json
```

```json
{
  "event": "opened",
  "external_key": "alert:42117",
  "occurred_at": "2026-07-28T10:41:00Z",
  "client": { "name": "Acme Dental", "customer_id": null },
  "endpoint": { "hostname": "ACME-DC01", "display_name": "Acme DC (primary)" },
  "alert": {
    "rule_key": "disk_free",
    "metric_key": "disk.percent_free",
    "instance": "C:",
    "severity": "critical",
    "message": "C: free space 4.1% (threshold 10%)"
  }
}
```

| Field | Rules |
|---|---|
| `event` | `opened` · `resolved` (v1). Unknown values → `422`. |
| `external_key` | `alert:<n>` identifies a device alert, `netalert:<n>` a network availability alert, and `netrule:<n>` a network rule alert. Each uses a decimal ID from 1 through 18446744073709551615 with no leading zero. The whole key identifies the ticket within its tenant. |
| `occurred_at` | ISO-8601 UTC. Skew > 24 h → `422`. |
| `client.customer_id` | Optional lowercase UUIDv4 in the signed body on either event. On creation, a usable ID selects its bound Safeharbor client in the receiving tenant. |
| `client.name` | Used for routing only if the ID is absent or JSON null. The first case-insensitive match by ascending Safeharbor client ID wins. An empty or unmatched name goes to **Milepost Intake**. |
| `alert.triage` | Optional JSON list of fixed findings on an `opened` event whose `alert.rule_key` is `offline`. See the field rules below. |
| `alert.severity` | `info` → `low`, `warning` → `normal`, `critical` → `urgent`. Unknown/missing → `normal`. High is reserved for human triage. |

On creation, Safeharbor looks up a supplied ID in the receiving tenant's customer
registry. The binding must be active and pass the managed-customer operational
check. The bound client supplies the ticket's service goal, even if another
client matches the name. Any failed ID check routes to the unbound **Milepost
Intake** client. The first system message records the reason with one of these
sentences. Each starts with `Routing: sent to Milepost Intake because `.

| Reason | Remainder of the sentence |
|---|---|
| `customer_id_malformed` | the customer id from Milepost was not a valid id. |
| `customer_binding_not_found` | Safeharbor has no customer with the id Milepost sent. |
| `customer_binding_inactive` | that customer is not active in Safeharbor. |
| `customer_not_operational` | that customer is not currently serviceable in Safeharbor. |
| `client_name_not_matched` | no Safeharbor client has the name Milepost sent. |

A binding in another tenant produces `customer_binding_not_found`. Re-fires
and recoveries keep the original client, subject, priority and captured goal.

**Milepost Intake** is the oldest client with that exact name that has no
customer binding and no source key. It is created on first use. Editing its
notes or domain does not change which client it is. A managed customer that
happens to carry the same name is never used as the catch-all.

Offline triage appears in the staff system message after the `message:` line.
The customer portal displays only customer and staff replies. In wire order,
these are the accepted finding objects.

| `code` | Other required fields | Staff sentence |
|---|---|---|
| `client_devices_offline` | `offline` and `total` are JSON integers from 2 to 9999 inclusive, with `offline <= total`. | Triage: {offline} of {total} computers for this client stopped reporting at about the same time. Check the site's power and internet first. |
| `after_job` | `kind` is `reboot`, `patch_install`, or `agent_update`. | Triage: this computer went quiet within an hour of a restart sent from Milepost. / Triage: this computer went quiet within an hour of an update install sent from Milepost. / Triage: this computer went quiet within an hour of a Milepost agent update. |
| `reboot_was_pending` | None | Triage: a restart was pending when this computer last reported. |
| `never_checked_in` | None | Triage: this computer has never reported to Milepost. |

Safeharbor considers the first four list positions. It keeps the first valid
finding for each code and prints accepted findings in the order above. Unknown,
incomplete, nested, or extra fields discard that finding. A malformed list
adds no lines. The ticket still opens, and the complete system message fits in
8,000 characters. Both applications share the [wire vectors](contracts/svc-alert-vectors.json).

**Signature:** `HMAC_SHA256(secret, timestamp + "\n" + raw_body)`, hex.
Timestamp must be within ±300 s of server time (replay window). Compare with
`hash_equals`. Any failure → `401` with a generic body; no detail leaks.

**Responses:** `200 {"ok":true,"ticket":N,"action":"created|updated|ignored"}`
· `401` bad auth · `422` bad payload · `429` over rate · `405` non-POST
· `500` database or other unexpected fault, eligible for an outbox retry.

**Behavior by event:**

- `opened`, unknown `external_key` → create ticket: `channel='alert'`,
  mapped priority, subject `[{severity}] {rule_key} on {hostname}` (190 cap),
  first message = alert detail (`kind='system'`), first-response deadline
  and policy target captured from the routed client and ticket priority.
- `opened`, known `external_key` → append a `system` line "Alert re-fired at
  {occurred_at}". Never duplicates and never rewrites ticket subject/priority;
  after creation those are human triage fields.
- `resolved`, known key → behavior follows ticket state (**founder rule
  2026-07-30**, shipped in PR #3, supersedes the v1 "status unchanged"
  default and the unbuilt `svc_alert_autoresolve` switch):
  - `netalert:` and `netrule:` tickets start with automatic-close eligibility
    set to zero under migration 018. Recovery adds the source note to an open
    ticket. Staff close it. Westy workflow claims accept only `alert:` keys.
  - ticket still `open` **and its migration-018 one-use eligibility remains**
    (the database has recorded no human/customer/timer/mutation ownership) → append `system` line
    "Resolved at source at … Ticket auto-closed." **and close it**
    (`status='resolved'`, `resolved_at` stamped). Machine alerts that clear
    themselves must not leave open tickets forever; the full paper trail
    stays inside the ticket.
  - ticket ineligible, including an Open ticket a human assigned, reprioritized,
    noted, replied to, merged, or logged time against, and every
    `in_progress` / `waiting` ticket → append the `system` line only; the human
    still closes it.
  - ticket already `resolved` → `200 {"action":"ignored"}`, no duplicate
    line (replay-safe). A late `opened` re-fire on a closed ticket appends
    provenance ("…after close") but never re-opens — a true new open
    arrives under a new `external_key`.
- `resolved`, unknown key → `200 {"action":"ignored"}` (resolve raced ahead
  of open; not an error).

**Limits:** body ≤ 16 KB · 120 requests/min per service identity · identity
keys are validated exactly; display strings are truncated to schema widths ·
every string through `h()` on render.

## 3. Server-side changes (Safeharbor)

**Migration `app/db/migrations/002_svc_intake.sql`** (additive, MySQL 8 —
no `ADD COLUMN IF NOT EXISTS`):

```sql
ALTER TABLE tickets
  ADD COLUMN external_key VARCHAR(64) NULL AFTER channel,
  ADD UNIQUE KEY uq_tickets_tenant_extkey (tenant_id, external_key);

CREATE TABLE IF NOT EXISTS svc_identities (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  service      VARCHAR(32)  NOT NULL,           -- 'milepost'
  display_name VARCHAR(64)  NOT NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_svc_tenant_service (tenant_id, service),
  CONSTRAINT fk_svc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Secrets are **not** in the DB — they live only in server `config/config.php`
(house rule), keyed by service:

```php
// config/config.php (server only, never git — sample gets EXAMPLE_* placeholders)
'svc' => [
    'enabled' => false,                       // kill switch, default OFF
    'secrets' => ['milepost' => 'EXAMPLE_SVC_HMAC_SECRET'],
],
```

**New files:** `app/public/api/svc/alerts.php` (endpoint) ·
`app/lib/svc_auth.php` (signature + timestamp + rate limit) ·
`app/lib/svc_intake.php` (normalize, route, idempotent upsert — mirrors
`lib/intake.php` structure). Endpoint refuses all requests with `404` while
`svc.enabled` is false, so the code can ship dark.

## 4. Emitter-side changes (Milepost repo — separate PR)

- `lib/svc_notify.php`: enqueue + HMAC POST (same codec style as the suite
  SSO signer).
- `svc_outbox` table: `id, event JSON, attempts, next_attempt_at,
  delivered_at`. Alert open/resolve paths enqueue a row — **zero synchronous
  HTTP in the request path**.
- `cron/svc_dispatch.php`: drains due rows with backoff (1/5/15 min, then
  dead-letters after 10 attempts into an operator-visible log line).
- Config (server-only): `svc_enabled` (default off), `svc_endpoint`,
  `svc_hmac_secret`. Same secret value as Safeharbor's `svc.secrets.milepost`.
- Alert severities and keys sent exactly as stored in `alerts` — no
  re-derivation emitter-side.

## 5. Sprints and exit criteria

### Sprint 8.1.1 — Safeharbor intake (this repo)
Migration + `svc_auth` + endpoint + intake lib + catch-all client.
**Exit:** hermetic PHP test suite on the EC2 (same style as Milepost's
`portal/tests/`) covering: bad sig 401, stale timestamp 401, open→ticket,
re-open→update-no-dup, resolve→system-line, resolve-unknown→ignored,
disabled-flag 404, rate limit 429. `php -l` clean. Deployed dark.

### Sprint 8.1.2 — Milepost emitter (Milepost repo)
`svc_notify` + `svc_outbox` + dispatch cron + config.
**Exit:** unit-level tests for enqueue/sign/retry; `svc_enabled=false`
deploy leaves prod behavior byte-identical.

### Sprint 8.1.3 — End-to-end, one real rule
Flip both kill switches on for **one** alert rule (suggestion: `disk_free`
critical on a non-critical server), watch a full open/re-fire/resolve cycle
land correctly, then enable for all rules.
**Exit:** real alert → ticket in < 60 s; re-fire updates not duplicates;
resolve appends; `svc_outbox` empties; operator spot-check in the UI
(visual verify per house rule 5).

## 6. Security and ops rules (carried from house rules)

1. TLS only, HMAC pinned to SHA-256, `hash_equals`, ±300 s replay window,
   idempotency key unique per tenant.
2. Secrets in server `config.php` only — never git, never chat, never ticket
   bodies. Rotation = new value in both configs, restart nothing.
3. Kill switches default **off** on both sides; shipping dark is expected.
4. No write-back, no remote commands, no remediation — Safeharbor receives,
   Milepost sends, that's it.
5. Enforce the rate limit and body cap. A failed write rolls back and
   returns a generic 500 so the sender can retry. Return `200 {"action":"ignored"}`
   for a recovery with an unknown key or an already resolved ticket.
6. UTC everywhere; `occurred_at` is source-of-truth time from Milepost,
   `created_at` stays Safeharbor server time.

## 7. Explicitly out of scope (v1)

- Auto-resolving tickets when alerts resolve (flag exists in design, off,
  later decision).
- Bi-directional sync (ticket status → alert), ack-from-ticket, assignment
  pushback to Milepost.
- Other emitters (backup jobs, network gear). The contract is written so a
  second service = one row in `svc_identities` + one config secret, but none
  is planned in 8.1.
- Customer-visible ticket surfacing in Mission Control — that is Phase 8.2
  territory (MC reads Safeharbor, not this pipeline).
