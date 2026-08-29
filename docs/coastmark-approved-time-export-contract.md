# Safeharbor approved time to Coastmark draft lines

**Status:** the original version-1 seam was deployed dark on 2026-08-26; both
global gates remain off and no production mapping, import, draft-line canary,
or financial action occurred. Version 2 replaces the wipe-sensitive local
client key with Milepost's durable customer UUID and adds a permanent 8 West IT
master exclusion. Shipping version 2 is not permission to enable either gate.

This is the only planned financial seam between Safeharbor and Coastmark.
Safeharbor supplies one immutable operational fact: a specific technician time
entry is approved and billable. Coastmark owns the client/agreement mapping,
rate, quantity pricing, tax, integer cents, draft invoice, approval, posting,
sending, Checkout, payment, and ledger behavior.

The first slice is deliberately operator-run and one-entry-at-a-time. It has no
timer, queue, cron, batch selection, or automatic retry. A dry run proves the
exact source fact and semantic payload digest without making a network request.
A send requires the exact tenant slug, numeric entry id, and idempotency key on
the command line plus all server-side gates below.

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

## Gates and acknowledgement

Every send requires:

1. `coastmark_time_export.enabled=true` in Safeharbor's protected host config;
2. exact tenant and deterministic client-key allowlists;
3. the canonical HTTPS endpoint, service identity, and secret;
4. a row matching the operator's exact tenant + id + entry key;
5. approved, billable, reviewer-backed time facts tied to an active non-master
   Milepost customer binding; and
6. an enabled Coastmark-owned mapping to an active client, monthly agreement,
   and time agreement line.

Coastmark returns 201 for a new immutable import and dedicated draft line, or
200 `ignored` for an exact replay. Safeharbor accepts the acknowledgement only
when tenant key, entry key, semantic payload SHA-256, and positive Coastmark
import/draft/line ids all match. Any timeout, oversized/invalid response,
non-200/201 status, or mismatched acknowledgement is untrusted. There is no
automatic resend; the operator may repeat only the same entry id and entry key.

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

php app/db/export_approved_time.php \
  --tenant-slug=8west \
  --entry-id=<same entry id> \
  --entry-key=<same immutable entry key> \
  --send
```

The dry run prints identifiers and the payload digest, never the note or
secret. A successful send prints only the Coastmark acknowledgement ids and
digest. Console output is acceptance evidence, while Coastmark's immutable
import row, source snapshot, assurance check, audit event, and dedicated draft
line are the durable financial-side record.

## Release and rollback

Ship both applications with their global gates false. Apply Coastmark's
additive migration as the privileged migration identity, prove its forced RLS
and immutable triggers under PostgreSQL, and deploy the receiver before
configuring the Safeharbor sender. Then:

1. apply the additive Coastmark version-2 client-key constraint while mappings
   remain empty, then prove both the old local-id form and the reserved 8 West
   IT master key are rejected;
2. prepare one disabled Coastmark mapping and inspect its organization, client,
   agreement, line, minutes-per-unit, rate, and tax;
3. enable only that mapping and one controlled non-master Milepost customer key;
4. dry-run one already approved, billable canary entry;
5. send it once and repeat the same command to prove 201 then 200/no duplicate;
6. prove one immutable import, one source-managed line, and a still-draft,
   unposted, unsent invoice with no Checkout/payment/journal activity; and
7. disable both global gates and the mapping after the canary until the
   operational billing workflow is accepted.

Rollback disables both feature gates and the mapping. Never delete an accepted
import, source line, draft, audit event, or later accounting history merely to
roll back transport code.
