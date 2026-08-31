# Managed-customer activation contract

## Status and boundary

This is a source-only, default-off Safeharbor worker. It creates no customer
and does not change Milepost or 8 West ID. It consumes one exact active
`suite_customer_sync_bindings` row that Milepost already owns, then uses a
fresh authenticated 8 West ID schema-2 projection/contact snapshot for that
same permanent non-master customer UUID.

One successful Safeharbor transaction may write only:

- an exact customer portal binding and the existing trigger-owned portal
  events;
- an exact client-scoped ID report-contact binding/snapshot;
- the deterministic version-3 report schedule pair; and
- one immutable redacted activation receipt.

It has no ticket close/status path, technician-time path, invoice/billing
path, endpoint-control path, report generation/delivery path, mail path, or AI
write path. It does not call Coastmark. `managed_customer_activation_write()`
enforces the direct table allowlist in addition to source-shape tests.

The ID schema-1 report-contact request/response behavior in
`app/lib/id_report_contacts.php` is unchanged. Schema 2 has a separate adapter
in `app/lib/managed_customer_id_evidence.php` so a producer-contract change
cannot silently reinterpret v1 evidence.

## Gates

The worker stops before opening the database or making an ID request unless
`managed_customer_activation.enabled` is literal `true`. Enabled
configuration must remain `canary_only=true`, contain at least one exact
lowercase non-master UUID in `customer_ids`, and bind every provider tenant
slug to one exact Safeharbor actor ID in `tenant_actors`. The actor is re-read
under the provider-tenant transaction lock and must still be an active
`owner` or `admin` in that tenant.

The report subsystem provides an independent gate. Before the worker can add
the active schedule version, the exact deterministic schedule key, provider
tenant slug, Safeharbor client key, and authenticated recipient must all be in
the protected `business_reports` allowlists, and `canary_only` must admit the
schedule. Generation and delivery may remain false; activation never runs
either operation.

Defaults are deliberately visible and test-pinned:

```text
schedule key:       managed-weekly-v3:<permanent-lowercase-UUIDv4>
definition:         weekly-client-service-summary version 3
time zone:          America/Los_Angeles
delivery weekday:   3 (ISO Wednesday)
delivery local time:09:00:00
canary:             1
```

The 8 West IT master customer UUID
`4ebaeefa-b101-47f8-ac76-e49ab309d272` is refused in configuration, candidate
selection, evidence validation, application code, the receipt check, and the
database table check.

`batch_size` caps successful activations, not attempted candidates. Each run
scans the deterministic allowlist of at most 25 pending customers, records
redacted refusals, and continues until the success cap is reached or the scan
ends. A persistently refused early customer therefore cannot starve a later
valid customer.

## Exact evidence and refusal rules

The isolated adapter posts canonical bytes
`{"schema_version":2,"customer_id":"<lowercase UUIDv4>"}` to the one fixed
HTTPS report-contact endpoint. The service, positive timestamp, lowercase
UUIDv4 nonce, and lowercase HMAC-SHA256 headers are mandatory. The request
preimage is exactly:

```text
8west-id-report-contact-request-v1
POST
/api/svc/report-contact.php
<timestamp>
<nonce>
<sha256(raw request body)>
```

The authenticated 200 response has the exact 20-member order pinned in
`managed-customer-lifecycle-contract.md`. In addition to the original
customer/source/receipt/contact facts, it includes the exact original Milepost
event UUID and requires active customer and identity tenant status, lifecycle
version 1, positive transition and OAuth session versions, `observed_active` or
`restored`, a lowercase lifecycle evidence hash, and `lifecycle_owned=false`.
Its signature preimage is
`8west-id-report-contact-response-v1`, newline, nonce, newline, and the raw
response-body SHA-256. Duplicate members, reordered members, redirects,
alternate endpoints, non-200 responses, signature mismatches, or generated
times outside the inclusive 300-second request window fail closed.

The normalized handoff retains those lifecycle fields in exact order before
the permanent ID tenant/contact facts, nonce hash, and response hash. Activation
also proves that local Safeharbor source history contains no inactive event;
post-inactive restoration belongs to the separately latched lifecycle contract.

The transaction then rechecks all of these local facts:

1. one exact tenant/client/source-binding ID still names the same UUID,
   authenticated source version, original Milepost event UUID, and `active`
   status, with an exact immutable current receipt and no local inactive event
   anywhere in its history;
2. the configured actor is still an active owner/admin in that tenant;
3. the ID tenant key/slug is absent or already bound to that exact client,
   never another tenant/client or the older tenant-wide contact lane;
4. a portal binding is absent or already names that exact ID slug and
   Safeharbor tenant/client; a conflicting slug or client is never overwritten;
5. contact evidence cannot move backward, and the same contact version cannot
   name a different recipient;
6. the deterministic schedule is absent or already has the exact client,
   v3 definition, recipient, Pacific Wednesday 09:00 shape, and canary flag;
   an existing different schedule is never rewritten; and
7. the active schedule inherits the exact evidence-bearing disabled version.

Inactive, master, stale, cross-tenant, cross-client, missing-v3-definition,
unallowlisted, conflicting portal/schedule, or malformed evidence is refused.
Errors emitted by the cron runner are hashes; the address and raw response are
not written to its output.

## Atomicity, replay, and races

The provider tenant row is the first exclusive database lock. Customer-sync,
portal, report, and competing activation work therefore cannot pass the final
recheck in a different order for that tenant. The worker writes the portal,
prepared schedule/contact snapshot, active schedule, and receipt inside the
same transaction. The receipt is inserted last.

- A crash or exception before commit rolls every activation write back.
- A lost acknowledgement after commit is recovered from the immutable receipt.
  Later candidate scans skip that completed customer, make no new ID request or
  write, and let later customers advance through the batch. The operator
  inspects the redacted receipt, active portal binding, and schedule pair.
- A fresh authenticated response has a new nonce and transport digest. Replay
  requires the same UUID, binding/source version and customer receipt ID, ID
  tenant, contact version, actor, and recipient digest; it retains the first committed
  response digest as the durable transport receipt.
- A competing worker that selected the customer before the winner committed
  blocks on the same tenant row, then returns the exact `replayed` result.
  Unique keys and migration-022 guards are a second database boundary.

Migration `022_managed_customer_activation.sql` creates only the immutable
redacted receipt table. It stores the customer UUID because that is the stable
ownership key, and stores the producer's immutable 64-hex customer receipt ID,
but stores only a SHA-256 for the recipient and response-body bytes. Its insert
trigger independently proves the exact active Milepost
binding, owner/admin actor, ID binding, active portal, v3 disabled/active
schedule pair, contact snapshot, and canonical evidence digest. Update and
delete are permanently refused. A fresh-table install check refuses every
insert through the DDL implicit-commit window and is removed only after the
permanent guards verify. Replay verifies exact table/index/foreign-key/check
shape and replaces the three permanent guards behind three temporary
fail-closed blockers.

## Tests and operator sequence

`managed_customer_id_evidence_test.php` pins canonical request bytes, both
HMAC preimages, headers, exact response member order/types, UUID/source/receipt/
tenant/contact/email facts, freshness edge, non-200 refusal, duplicate-member
refusal, redirect refusal, and unchanged schema-1 request bytes.

`managed_customer_activation_test.php` uses hermetic SQLite to prove default
off, master/inactive/cross-tenant/stale refusal, portal conflict, crash rollback
and concurrent replay, semantic exact replay with a fresh authenticated nonce,
completed-customer queue advancement, forbidden write sentinels, and the
Wednesday 09:00 Pacific schedule.

`managed_customer_activation_mysql_test.php` requires an explicitly
acknowledged disposable MySQL server and creates a random database named under
`safeharbor_activation_test*`. It loads fresh schema, applies migration 022
twice, verifies permanent/temporary guards, runs two real competing PHP
workers, tests immutable receipts, injects a mid-transaction crash, replays,
and removes its exact scratch database in `finally`.

Later production work remains separately gated:

1. rebase, independently review, merge, and deploy the ID producer contract;
2. merge this Safeharbor consumer after independent review;
3. take and verify protected backups;
4. apply migration 022 twice with the trigger-capable operator and verify
   `1 / 3 / 0 / 0 / 0` (table / permanent guards / temporary guards /
   install lock / receipts);
5. install separate protected ID and activation configuration with all gates
   still false;
6. plan one exact non-master canary and configure both customer/actor and
   business-report allowlists;
7. separately authorize and run the worker once, inspect the redacted receipt,
   portal binding, schedule pair, and zero report archives/deliveries; and
8. keep report generation and delivery under their existing independent
   release/canary controls.

Migration, configuration, cron installation, activation, report generation,
delivery, merge, and deployment are not authorized by this source slice.
