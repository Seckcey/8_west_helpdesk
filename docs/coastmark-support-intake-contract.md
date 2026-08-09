# Product support intake — wire contract

*(File keeps its `coastmark-` name so existing links keep working; the contract
is not Coastmark-only. See §2.)*

**Frozen 2026-08-09.** Receiver shipped in Safeharbor. **Coastmark and Waypoint
both implement the emitter side against this document.**

> **Waypoint added 2026-08-09.** Waypoint is a **standalone product outside
> 8 West IT 365** with its own customers — a second producer, not a variant of
> Coastmark. It gets its own service identity, its own secret and its own
> `clients.source_key` prefix. Nothing here is suite-specific: a producer needs
> a registered service identity and nothing else. Do not reach for the suite
> SSO contract to reason about one.

A tenant admin inside Coastmark clicks "Get help", types a question, and a
ticket opens in **8 West IT's own Safeharbor queue** — filed under a client row
for their organisation, in their own words. One click, one ticket. Our staff
reply on the ticket; the requester gets that reply as an email and can just hit
reply. Coastmark builds no second inbox, and we never call back into Coastmark.

This is a **third** producer under `api/svc/*`, deliberately not either of the
existing two:

| Endpoint | Shaped for | Why it is wrong for this |
|---|---|---|
| `api/svc/alerts.php` | machine alerts | maps severities, auto-closes tickets when the source says the problem cleared |
| `api/svc/westy.php` | Westy defect reports | collapses reports onto a fingerprint of the *problem* and re-scrubs stored text — it would merge two admins' questions and blank out anything quoted |
| **`api/svc/support.php`** | **a person asking for help** | **no dedupe, no scrubbing, verbatim text, a real reply path** |

---

## 1. Endpoint

```
POST https://safeharbor.8westit.com/api/svc/support.php
```

Authentication is the existing service scheme (`lib/svc_auth.php`),
**byte-identical to `api/svc/alerts.php` and `api/svc/westy.php`**:

| Header | Value |
|---|---|
| `X-8W-Service` | your own identity — `coastmark-support` or `waypoint-support` (§2) |
| `X-8W-Timestamp` | unix seconds, within ±300 s of ours |
| `X-8W-Signature` | lowercase hex `HMAC-SHA256("{timestamp}\n{raw_body}", secret)` |

Body must be JSON, non-empty, **≤ 16384 bytes**. Rate limits: **120 requests
per minute per identity** (existing, shared across all your tenants) **plus a
per-tenant cap of our own** — see §7.

Every auth failure is the same generic `401` — unknown service, inactive
identity, stale timestamp, bad signature, and *"that identity is not allowed to
file support requests"* are deliberately indistinguishable.

## 2. Service identity

| App | Identity | `clients.source_key` prefix | Notes |
|---|---|---|---|
| Coastmark (365 edition) | `coastmark-support` | `coastmark:{slug}` | one job only |
| Waypoint (standalone) | `waypoint-support` | `waypoint:{slug}` | one job only; **not** a suite app |

**One identity and one secret per PRODUCT, never a shared key.** A single secret
common to both would make either app a way to post as the other, and revoking
one would revoke both. It also keeps the two products' client rows apart: a
company using Coastmark *and* Waypoint gets one client row per product, so
neither product's tickets silently file under the other.

Its own identity, like `milepost-westy` before it, so a burst of support
requests can never starve alert intake and vice versa. A valid signature from
the `milepost` identity is still a `401` on this door: signing keys prove who
you are, not what you are allowed to file. The allow-list is `SUPPORT_SOURCES`
in `lib/svc_support.php`; **an identity that is not in it gets a `401` even with
a perfect signature**, so a new producer needs that one-line addition shipped
before it can canary.

**Registration and the secret.** 8 West creates the `svc_identities` row and
installs the secret in Safeharbor's server `config.php` under
`svc.secrets.coastmark-support` — server-side only, never git, never chat. We
generate 32 random bytes, hex, and hand them over by one-time secret link. You
store it the same way on your side. Rotation is a new value in both configs,
nothing to restart.

> **Registration detail for whoever runs it:** `svc_auth.php` looks the
> identity up under `tenant_id()`, which is `1` with no session. The row must
> live under tenant 1 (today that is the `8west` tenant). This is existing
> behaviour shared with the other two endpoints, not something this contract
> changes.

## 3. Request body

```json
{
  "event":        "support_request",
  "external_key": "cmk:acme-msp:9f2c1d7a4b0e",
  "occurred_at":  "2026-08-09T14:02:11Z",

  "tenant": {
    "slug":         "acme-msp",
    "display_name": "Acme MSP"
  },
  "requester": {
    "name":  "Ada Lovelace",
    "email": "ada@acmemsp.example"
  },

  "subject": "Invoice sync stopped overnight",
  "body":    "Our sync to the ledger stopped at 2am.\n\nThe last invoice through was #4471.",

  "context": {
    "page":        "Billing → Invoices",
    "app_version": "3.4.1"
  }
}
```

| Field | Rules |
|---|---|
| `event` | `support_request`. Anything else → `422`. |
| `external_key` | **Required.** Unique per submission, across all your tenants. `[A-Za-z0-9:._-]`, ≤ 64 chars — `cmk:{slug}:{uuid-hex}` fits. Drives idempotency (§6). |
| `occurred_at` | Any `strtotime`-parseable stamp, within **24 hours**. Omit to mean now. |
| `tenant.slug` | **Required.** `[a-z0-9][a-z0-9-]*`, ≤ 48. Chooses the client row (§4). |
| `tenant.display_name` | ≤ 110. Used to *name* the client row on first sight only. Defaults to the slug. **The field is `display_name`, not `name`** — see the warning below. |

> ⚠️ **`tenant.display_name` fails silently if you get the name wrong.** Sending
> it as `tenant.name` is not an error: the field arrives empty, we fall back to
> the slug, and the client row is created as `"acme-msp (Coastmark)"` instead of
> `"Acme MSP (Coastmark)"`. Because the row is named **once on first sight and
> never rewritten from the wire** (§4 — so our staff can rename clients without
> you overwriting them), the wrong name is permanent for that customer and does
> not self-heal on the next request. Nothing 4xx's and a canary looks green.
> Both emitter teams hit this in review on 2026-08-09. Check the field name
> before your first real request.
| `requester.email` | **Required**, must parse as an address, ≤ 190. This is the reply path — without it nobody can be answered. |
| `requester.name` | ≤ 128, one line. Defaults to the part of the address before the `@`. |
| `subject` | **Required**, non-empty. Stored verbatim, cut to **160** characters. Newlines and control characters are flattened to spaces (it becomes an email Subject header). |
| `body` | **Required**, non-empty. Stored **verbatim**, cut to **8000** characters. Newlines and tabs preserved. |
| `context.page` | Optional, ≤ 190, one line. Recorded on the provenance line, not mixed into the request text. |
| `context.app_version` | Optional, ≤ 32, one line. |

**No priority, urgency or severity field, by design.** Urgency is 8 West's
triage call; every request lands `normal`. If a requester says it is urgent,
that belongs in their words in `body`, where a human reads it.

**Attachments are out of scope for v1.** They are not silently dropped — there
is nowhere to put them. The requester can reply to the acknowledgement email
with files attached and our email intake stores them on the same ticket, which
covers most of the real need.

### Text handling — the difference from `westy.php`

Confirmed and covered by tests: **`subject` and `body` are stored exactly as
sent**, inside the caps above. No email redaction, no quote collapsing, no
normalisation, no fingerprint hashing. The only edits are byte hygiene:
invalid UTF-8 is repaired (`utf8_clean()`, house rule) and control characters
other than newline and tab are removed. Every string is HTML-escaped at render
time, never at storage time.

## 4. Where the ticket lands

- **Tenant:** 8 West IT's own `8west` tenancy, resolved explicitly by slug —
  never a customer's queue, and never via a fallback guess. If that tenant row
  is missing we record **nothing** and answer `200 {"action":"ignored"}`.
- **Client row:** one per tenant **per product**, keyed on `clients.source_key`
  = `{product}:{slug}` (`coastmark:acme-msp`, `waypoint:acme-msp` — separate
  rows), created on first sight and named `"{display_name} ({Product})"`.
  So "Acme MSP has 3 open requests" is a real
  view in our queue. **Routing follows the key, not the name** — our staff can
  rename that client row freely and your next request still lands on it.
- **Ticket:** `channel = 'portal'` (a person typed this — it is not an alert),
  `priority = 'normal'`, `status = 'open'`, standard-tier SLA of 8 hours,
  `external_key` = yours.
- **Thread:** first message is the request body, `kind = 'client'`, authored by
  the requester's name. Second is a `system` provenance line naming Coastmark,
  the organisation, the reply-to address, the page, the app version and your
  reference.

## 5. Responses

| Code | Meaning | What the emitter should do |
|---|---|---|
| `200` | handled — `{ok:true, ticket:<id\|null>, action:"created"\|"ignored"}` | mark the outbox row done |
| `401` | bad/absent auth, or an identity not allowed to file support requests | stop and alert a human; retrying will not help |
| `404` | `svc.enabled` or `svc.support_enabled` is off on the receiver | stop; nothing is wrong with your payload |
| `405` | not a POST | fix the caller |
| `422` | bad payload | dead-letter it; retrying identical bytes will not help |
| `429` | over rate (either window) | back off, retry later |
| `500` | unexpected fault | back off and retry — nothing was lost, and the retry is idempotent |

`action: "ignored"` means one of two harmless things: we already have that
`external_key` (your retry worked), or the destination tenant is missing and we
failed closed. Treat both as delivered; do not retry.

## 6. Dedupe — confirmed, there is none

Each submission is its own ticket. Two admins asking the same question in the
same words on the same day get two tickets. Nothing is fingerprinted, nothing
is collapsed, nothing is counted.

The only collapsing is exact idempotency on your key: the existing unique index
`uq_tickets_tenant_extkey (tenant_id, external_key)` covers it, so a network
retry that arrives twice creates one ticket and the second call returns
`{"action":"ignored"}` with the original ticket id. A delivery race that gets
past the read check is caught on the unique key and reported the same way — a
duplicate never becomes a `500`.

**Send a fresh `external_key` for each genuine submission, and reuse the same
one for every retry of that submission.** That single rule is what makes this
safe in both directions.

## 7. Rate limits

Two windows, both fixed:

| Window | Limit | Scope | Enforced by |
|---|---|---|---|
| per minute | 120 | your identity — all your tenants together | existing `svc_rate_buckets` |
| per minute | 20 | one tenant, **within one product** | `svc_support_rate` |
| per day | 100 | one tenant, **within one product** | `svc_support_rate` |

The per-tenant windows are keyed on `(product, slug)`, so a busy Coastmark
customer cannot throttle the same company's Waypoint requests, and the two
products never share a budget.

The per-tenant caps are ours to enforce, deliberately: the shared 120/min would
otherwise let one noisy MSP lock out everybody else's ability to reach us. Both
per-tenant limits are config values we can raise for a specific need.

Throttling in your own UI is still welcome, but it is a different job — it stops
one admin double-clicking Send. It is not a substitute for either cap, and a
retry of an already-stored submission never spends budget on our side.

## 8. The reply path

Ours, by email, exactly as you asked:

1. Request arrives → we create/reuse a contact for `requester.email` under that
   tenant's client row, and queue an acknowledgement: subject `[#123] {subject}`.
2. A tech replies on the ticket → `mail_notify_reply()` emails the contact,
   same `[#123]` subject.
3. The requester replies to that email → `cron/graph_poll.php` (or the IMAP
   fallback) hands it to `lib/intake.php`, which threads it back onto ticket
   123 by the `[#123]` token and re-opens it if it had been closed.

Nothing flows back to Coastmark over the wire, and Coastmark surfaces no
replies. If you ever want the requester to see status inside Coastmark, that is
a separate read-side conversation, not this pipe.

The acknowledgement is suppressed for machine-looking addresses
(`no-reply@`, `postmaster@`, and friends) — mail-loop protection is a house
rule written in blood. The ticket is still created; nobody is emailed.

## 9. Emitter guidance

Follow the pattern Milepost already proved: **enqueue, never block the click.**
A `svc_outbox` row written in the request path, a dispatch cron draining it with
backoff (1/5/15 minutes, dead-letter after 10 attempts into an operator-visible
log line), and a "Get help" form that never fails because we are slow. Reporting
is best-effort by design; a broken reporter must degrade to "we could not send
that just now", never to a 500 in Coastmark.

## 10. Receiver-side files (this repo)

| File | What |
|---|---|
| `app/public/api/svc/support.php` | endpoint: flags, method, size, auth, job check, JSON |
| `app/lib/svc_support.php` | validation, routing, ticket creation, per-tenant rate, ack |
| `app/db/migrations/009_support_intake.sql` | `clients.source_key` + `svc_support_rate` |
| `app/tests/svc_support_test.php` | hermetic tests, including "text survives verbatim" and "no dedupe" |
| `app/config/config.sample.php` | `svc.support_enabled`, the new secret, `support_intake` block |

Ships **dark**: `svc.support_enabled` defaults to `false` and the endpoint
answers `404` until it is turned on, independently of the alert and Westy
paths that are already live.
