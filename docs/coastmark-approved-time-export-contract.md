# Safeharbor approved time to Coastmark draft lines

**Status:** version 2 was deployed dark on 2026-08-29; both global gates remain
off and no production mapping, import, draft-line canary, or financial action
occurred. Version 2 replaces the wipe-sensitive local client key with
Milepost's durable customer UUID and adds a permanent 8 West IT master
exclusion. The approved-time-adjustment development slice permanently retires
the Safeharbor v2 send path: even an accidentally enabled configuration stops
before signing or transport. Dry-run inspection remains available. A new v3
with durable Safeharbor export claims/receipts and Coastmark reversal
acknowledgements must replace v2; this is not a feature flag to flip.

## Verified 2026-08-29 production closeout

Safeharbor commit `a42872be1f9cd76ebb0a3b2bad91c16f2c0bb0ff` was deployed to
`milepost-ec2` / `AWS-MILEPOST` at `2026-08-29T03:56:19Z`. Exact-main CI run
[`33232316449`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/33232316449)
passed, and the release artifact SHA-256 is
`65bb61852ee44639465d8d3cabf4b79c97ad2180c86785c6d41bcd1ecb87d15d`.

The verified pre-release backup is
`/srv/8west/backups/safeharbor/20260829T035454Z-pre-approved-time-v2-a42872b`.
Its application, database, protected-config, and grants SHA-256 values are,
respectively:

- `ed2cde4733b605fb34d95dd9282a8c994e65c63a2913426dd858df973c4708bc`;
- `d2fb72683e1f886658630d0a1412721c03bdfa7c445bcf05149f6c5a5e241b3e`;
- `e272ccc44f8200774530fabda634b3d65032805ef360d6f73da3a91afc3fb0f4`;
  and
- `35673b03b5ac36996f9902f7b40a1581486437a6f2a31f5d849b961c4612def0`.

The restore-shape check was `38:4:16:2:4:69`. The retained release receipt is
`/srv/8west/backups/safeharbor/20260829T035454Z-pre-approved-time-v2-a42872b/approved-time-v2-release-receipt-20260829T0356Z.txt`
with SHA-256
`c52e4b13959ccb7d6a4c2df75900fc86ff087b6d54a624a564e01a92b2bf9b73`.

At `2026-08-29T04:12:53Z`, the protected server-only handshake prerequisites
were configured while the sender remained false. Safeharbor now has the
canonical HTTPS receiver endpoint and service identity, tenant allowlist
`[8west]`, and a client allowlist containing only 8 West Lifestyle customer
`f22fc65c-70ca-439e-b703-f85c82da885d`. The 8 West IT master UUID is absent
from the allowlist and remains hard-blocked. The permanent master key and legacy
local-id key were rejected; the canonical Lifestyle key was structurally valid.

The pre-change protected configuration backup is
`/srv/8west/backups/safeharbor/20260829T041253Z-pre-approved-time-handshake/config.php`
with SHA-256
`e272ccc44f8200774530fabda634b3d65032805ef360d6f73da3a91afc3fb0f4`.
The post-change protected configuration SHA-256 is
`e652fef2c69b981e31b571b08408ba0b6a9381d7e746859ae052d531fa053891`.
The retained handshake receipt is
`/srv/8west/backups/safeharbor/20260829T041253Z-pre-approved-time-handshake/approved-time-handshake-receipt-20260829T0413Z.txt`
with SHA-256
`4c30da1b32bd94ba80e7155e070040354c31bac5788484fcb009fe51f99a9238`.

Coastmark's matching service identity, fixed `8west` organization, 300-second
clock-skew limit, and protected secret are configured while its receiver remains
false and returns HTTP 404. The two protected secrets match; neither value was
printed, and no reusable secret digest was recorded. The live Milepost bindings
remain active for 8 West IT customer
`4ebaeefa-b101-47f8-ac76-e49ab309d272` to Safeharbor client 13 and 8 West
Lifestyle customer `f22fc65c-70ca-439e-b703-f85c82da885d` to client 14.

Safeharbor has four pending time entries, including the one-minute billable
canary-preparation entry 10 created through the normal UI on 8 West Lifestyle
ticket 434 and client 14, plus one rejected entry. Entry 10 remains
`approval_status=pending`; no human approval was performed. The retained
canary-preparation receipt is
`/srv/8west/backups/safeharbor/20260829T035454Z-pre-approved-time-v2-a42872b/lifestyle-time-canary-preparation-20260829T0407Z.txt`
with SHA-256
`b10d78db10cacf6a8dda1b4fd3e0828b8b16e20535c4cb494d29744a69ffd59a`.

No time entry was approved, exported, or retried during the release or canary
preparation. Login
returned 200, the root returned 302, the portal returned 200, the scheduler had
zero failures, and the fatal-error scan was clean. Coastmark separately
reverified zero mappings, imports, and Safeharbor source lines; its eight
existing invoices and fourteen journals were unchanged. **This was a
code-and-schema release plus non-financial canary preparation only: it did not
create a draft invoice line, approve or send an invoice, post a journal, open
Checkout, record payment, or perform any other financial action.**

This is the only planned financial seam between Safeharbor and Coastmark.
Safeharbor supplies one immutable operational fact: a specific technician time
entry is approved and billable. Coastmark owns the client/agreement mapping,
rate, quantity pricing, tax, integer cents, draft invoice, approval, posting,
sending, Checkout, payment, and ledger behavior.

The first slice is deliberately operator-run and one-entry-at-a-time. It has no
timer, queue, cron, batch selection, or automatic retry. A dry run proves the
exact source fact and semantic payload digest without making a network request.
Version 2 can no longer send. Keeping its exact dry-run payload makes the
deferred v3 boundary reviewable without allowing a stale financial draft.

## Version 2 payload

`POST /api/integrations/safeharbor/time-entries` receives JSON with these exact
fields in canonical order:

```text
version, event, tenant_key, client_key, entry_key, entry_id, ticket_id,
entry_source, technician_key, worked_at, minutes, note_sha256, billable,
approval_status, approved_at, reviewer_key
```

Load-bearing rules:

- `version=2` and `event=safeharbor.time_entry.approved`. Version 1 is refused;
  production is empty, so there is no legacy import or mapping to preserve.
- `billable=true` and `approval_status=approved`; pending or rejected work is
  refused before any network request.
- Any entry with an append-only Safeharbor approval adjustment is refused.
  Version 2 has one immutable source key, changed facts conflict, and neither
  side yet owns a durable adjustment/reversal acknowledgement. Sending the raw
  original would knowingly create a stale draft fact.
- `tenant_key` is the immutable Safeharbor tenant slug. `client_key` is
  `milepost-customer:<canonical UUIDv4>`, read from the active
  `suite_customer_sync_bindings` row for the exact time-entry client. Both must
  appear on exact protected config allowlists; no local row id, mutable source
  key, name, email, domain, or fuzzy matching is permitted. A missing or
  inactive Milepost binding is refused before signing.
- Milepost customer `4ebaeefa-b101-47f8-ac76-e49ab309d272` is the reserved
  8 West IT master MSP. The sender, Coastmark mapping command, receiver, and
  database constraint all refuse that exact customer. An allowlist or direct
  database write cannot turn the master MSP into a bill-to customer.
- `entry_key` is Safeharbor's immutable idempotency key. The operator must
  supply the same key alongside the entry id, preventing an id-only mistake.
- Technician and reviewer are non-PII Safeharbor-local keys such as
  `safeharbor-user:123`.
- `note_sha256` is the lowercase digest of the note. Raw notes, review notes,
  ticket subjects, message text, contact data, rates, tax, and invoice commands
  never cross this seam.
- Timestamps are whole-second UTC. Minutes remain the approved integer in the
  range 1 through 1440.

The raw JSON body is signed as lowercase hex
`HMAC-SHA256(timestamp + "\n" + body)` with headers `X-8W-Service`,
`X-8W-Timestamp`, and `X-8W-Signature`. The secret is server-only and at least
32 bytes. Safeharbor accepts only a direct HTTPS URL at the canonical receiver
path and never follows redirects.

## Retired send boundary

The historical v2 transport expected all of these gates:

1. `coastmark_time_export.enabled=true` in Safeharbor's protected host config;
2. exact tenant and deterministic client-key allowlists;
3. the canonical HTTPS endpoint, service identity, and secret;
4. a row matching the operator's exact tenant + id + entry key;
5. approved, billable, reviewer-backed, never-adjusted time facts tied to an
   active non-master Milepost customer binding; and
6. an enabled Coastmark-owned mapping to an active client, monthly agreement,
   and time agreement line.

Those gates are no longer sufficient because a correction can be appended
after payload construction or after Coastmark accepts the original. The
Safeharbor `coastmark_time_export_send()` boundary now throws unconditionally
before endpoint validation, signing, or transport. Setting the old protected
`enabled` value cannot bypass that code gate.

The existing Coastmark receiver's 201/200/409 behavior remains historical v2
contract evidence, not permission to call it. Production has zero imports, so
retirement needs no reversal or cleanup.

The receiver returns 409 when an existing source key arrives with changed
facts. It never approves, posts, sends, opens Checkout, records payment, or
touches the ledger. The imported draft remains an ordinary Coastmark operator
decision except that its source-managed evidence and lines are immutable.

## Operator flow

```bash
php app/db/export_approved_time.php \
  --tenant-slug=8west \
  --entry-id=<approved entry id> \
  --entry-key=<exact immutable entry key> \
  --dry-run
```

The dry run prints identifiers and the payload digest, never the note or
secret. `--send` intentionally exits with the v2-retired error and makes no
network request, even if the old sender gate is true.

## Release and rollback

Keep both applications' old global gates false and all Coastmark mappings
disabled. A future v3 release must first add a durable Safeharbor claim/receipt
that serializes against adjustment creation, an append-only Coastmark
adjustment/reversal acknowledgement, ambiguous-delivery recovery rules, and
real two-connection race tests. Only then may a controlled non-master canary be
planned.

Rollback disables both feature gates and the mapping. Never delete an accepted
import, source line, draft, audit event, or later accounting history merely to
roll back transport code.
