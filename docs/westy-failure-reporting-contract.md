# Westy failure reporting — wire contract

**Frozen 2026-08-08.** Receiver shipped in Safeharbor; Milepost and the Control
Panel implement the emitter side against this document.

Full design and reasoning: `docs/superpowers/specs/2026-08-08-westy-failure-tickets-design.md`.

When Westy fails, or a technician marks an answer unhelpful, a ticket opens in
**8 West IT's own Safeharbor queue** so our staff can fix Westy. Never in a
customer's queue. One ticket per **problem**; repeats update it with a count and
a last-seen.

---

## 1. Endpoint

```
POST https://safeharbor.8westit.com/api/svc/westy.php
```

Authentication is the existing service scheme (`lib/svc_auth.php`), **byte-identical
to `api/svc/alerts.php`** — if you already sign alert traffic, you already sign this:

| Header | Value |
|---|---|
| `X-8W-Service` | your registered service identity (§2) |
| `X-8W-Timestamp` | unix seconds, within ±300 s of ours |
| `X-8W-Signature` | lowercase hex `HMAC-SHA256("{timestamp}\n{raw_body}", secret)` |

Body must be JSON, non-empty, **≤ 16384 bytes**. Rate limit **120 requests per
minute per identity**, fixed window.

Every auth failure is the same generic `401` — unknown service, inactive
identity, stale timestamp and bad signature are deliberately indistinguishable.

## 2. Service identities

| App | Identity | Notes |
|---|---|---|
| Milepost | `milepost-westy` | **Not** the existing `milepost` identity |
| Control Panel | `controlpanel-westy` | new HMAC signer in Go |
| Safeharbor | *(none)* | reports in-process, never over the wire |

Separate identities are the point: one rate-limit budget each, so a Westy
failure storm can never starve real alert intake. Each needs a row in
`svc_identities` and a secret in Safeharbor's server `config.php` under
`svc.secrets` — server-side only, never git, never chat.

## 3. Request body

```json
{
  "event":       "failure",
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
  }
}
```

```json
{
  "event":       "flagged",
  "app":         "milepost",
  "surface":     "westy_chat",
  "occurred_at": "2026-08-08T20:11:03Z",
  "user_ref":    "42",

  "flagged": {
    "question": "How do I merge two tickets?",
    "answer":   "Open the Tools menu and choose Combine.",
    "note":     "There is no Tools menu."
  }
}
```

| Field | Rules |
|---|---|
| `event` | `failure` or `flagged`. Anything else → 422. |
| `app` | `milepost` or `controlpanel`. Unknown → 422. Sets which client row the ticket lands in. |
| `surface` | where Westy was working: `westy_chat`, `westy_drafts`, … `[a-z0-9_]`, ≤32. Defaults to `westy_chat`. **Part of the fingerprint** — the same error in two surfaces stays two tickets. |
| `occurred_at` | any `strtotime`-parseable stamp, within **24 hours**. Omit to mean now. |
| `user_ref` | optional opaque internal id, sent as a string, ≤32 chars of `[A-Za-z0-9_.:-]`. **Never a name or an email address.** Omit rather than invent one. |
| `failure.*` | required when `event = failure`. At least one of `error_class` / `error_detail` must be non-empty. |
| `flagged.*` | required when `event = flagged`. `question` and `answer` must both be non-empty (≤2000 / ≤4000); `note` optional (≤500). |

### Rules the receiver enforces — do not rely on your side alone

- **A `failure` event's `flagged` block is dropped on the floor.** Failures
  carry technical detail only. You cannot attach chat text to a failure no
  matter what you send.
- **All stored text is scrubbed again on arrival**: email addresses are replaced
  with `[email]`, quoted spans longer than 40 characters become `"…"`, and the
  result is capped at 500 characters. Scrub on your side too — this is defence
  in depth across a repo boundary, not permission to send raw text.
- A provider **refusal** is not a failure. Do not report it; the safety layer
  declining a request is not Westy breaking. A technician can still flag it.

## 4. Responses

| Code | Meaning | What the emitter should do |
|---|---|---|
| `200` | handled — `{ok:true, ticket:<id\|null>, action:"created"\|"updated"\|"ignored"}` | mark the outbox row done |
| `401` | bad or absent auth | stop and alert a human; retrying will not help |
| `404` | `svc.enabled` is off on the receiver | stop; nothing is wrong with your payload |
| `405` | not a POST | fix the caller |
| `422` | bad payload | dead-letter it; retrying identical bytes will not help |
| `429` | over rate | back off, retry later |
| `500` | unexpected fault | back off and retry — nothing was lost |

`action: "ignored"` means the receiver could not resolve 8 West IT's tenant and
**deliberately recorded nothing**. It fails closed rather than guessing a
destination. Treat it as delivered; do not retry.

## 5. Flood control — the part that is easy to get wrong

The receiver dedupes on a fingerprint of the **problem**:

- failure → `app | surface | provider | model | error_class | http_status`
- flagged → `app | normalised question`

`error_class` is normalised before hashing: lowercased, every run of digits
replaced with `#`, quoted spans replaced with `…`, whitespace collapsed, cut to
120 characters. So "Timeout after 30s" and "timeout after 31 s" are one problem,
as are two overload errors differing only in a request id.

**Emit one event per genuine occurrence and let the receiver collapse them.**
Do not pre-aggregate, and do not put a timestamp, request id, or occurrence
number in `error_class` — that is exactly the mistake the alert path made, where
keys like `alert:231` / `alert:232` opened six tickets for one memory warning.

Repeats update one ticket: the count and last-seen are rewritten in place, a
fresh line is appended only at 5, 25 and 100 occurrences, and priority is raised
to `high` at 25. If the ticket has been resolved and the problem returns, a new
generation opens (`…:g2`) linked back to the closed one — nothing silently
reopens.

## 6. Emitter guidance

**Milepost** already has everything needed: `lib/svc_notify.php` (signing),
`svc_outbox` + `cron/svc_dispatch.php` (retry `[1,5,15]` minutes, dead-letter at
10 attempts). The outbox row is keyed to `alert_id`, so it needs generalising to
carry a Westy payload — that is the bulk of the work.

**The Control Panel** needs a new HMAC signer in Go; `auth.go` already imports
`hmac`. The signature is over `timestamp + "\n" + rawBody`, hex-encoded, compared
constant-time on our side.

Both: enqueue on the failure path, never block the chat on the send, and never
let a broken reporter take the assistant down. Reporting is best-effort by
design.
