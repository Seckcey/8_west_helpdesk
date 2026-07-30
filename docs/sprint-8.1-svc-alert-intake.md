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
  "client": { "name": "Acme Dental" },
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
| `external_key` | Stable, unique per alert at the source: `"alert:{milepost_alerts.id}"`. Max 64 chars. Drives idempotency. |
| `occurred_at` | ISO-8601 UTC. Skew > 24 h → `422`. |
| `client.name` | Matched case-insensitively to `clients.name` within the tenant. No match → catch-all client **"Milepost Intake"** (created once, same pattern as Email Intake). Never auto-creates a real client. |
| `severity` | `info` → `low`, `warning` → `normal`, `critical` → `urgent`. Unknown/missing → `normal`. High is reserved for human triage. |

**Signature:** `HMAC_SHA256(secret, timestamp + "\n" + raw_body)`, hex.
Timestamp must be within ±300 s of server time (replay window). Compare with
`hash_equals`. Any failure → `401` with a generic body; no detail leaks.

**Responses:** `200 {"ok":true,"ticket":N,"action":"created|updated|ignored"}`
· `401` bad auth · `422` bad payload · `429` over rate · `405` non-POST.

**Behavior by event:**

- `opened`, unknown `external_key` → create ticket: `channel='alert'`,
  mapped priority, subject `[{severity}] {rule_key} on {hostname}` (190 cap),
  first message = full alert detail (`kind='system'`), SLA from the matched
  client's tier (premium 2 h / standard 8 h — existing convention).
- `opened`, known `external_key` → update in place: refresh severity/message,
  append a `system` line "Alert re-fired at {occurred_at}". Never duplicates.
- `resolved`, known key → behavior follows ticket state (**founder rule
  2026-07-30**, shipped in PR #3, supersedes the v1 "status unchanged"
  default and the unbuilt `svc_alert_autoresolve` switch):
  - ticket still `open` (no human has touched it) → append `system` line
    "Resolved at source at … Ticket auto-closed." **and close it**
    (`status='resolved'`, `resolved_at` stamped). Machine alerts that clear
    themselves must not leave open tickets forever; the full paper trail
    stays inside the ticket.
  - ticket `in_progress` / `waiting` (a tech owns it) → append the `system`
    line only; the human still closes it.
  - ticket already `resolved` → `200 {"action":"ignored"}`, no duplicate
    line (replay-safe). A late `opened` re-fire on a closed ticket appends
    provenance ("…after close") but never re-opens — a true new open
    arrives under a new `external_key`.
- `resolved`, unknown key → `200 {"action":"ignored"}` (resolve raced ahead
  of open; not an error).

**Limits:** body ≤ 16 KB · 120 requests/min per service identity · all string
fields truncated to schema widths · every string through `h()` on render.

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
5. Rate limit + body cap are hard limits, not suggestions. Intake failures
   must never 500 — catch, log, `200 {"action":"ignored"}` only where safe.
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
