# Milepost customer registry -> Safeharbor contract

**Status:** source contract and migration 015 are default-off. Shipping these
files does not enable a receiver, create a service identity, copy a secret,
create a customer, or change an existing client. Production status belongs in
`where-things-stand.md` only after separate runtime verification.

This is the first narrow customer-provisioning seam. Milepost owns the suite
customer identifier and the ordered name/status events. Safeharbor owns the
local client row, tickets, time, service-goal snapshots, portal mappings, and
reports. This receiver never queries Milepost and never writes Coastmark or 8
West ID.

## Closed HTTP and authentication contract

- Destination: `POST /api/svc/customers.php`
- Gate: `suite_customer_sync.enabled === true`; false returns a 404 before
  reading the request body.
- Destination allowlist: the exact payload tenant slug must be present in
  `suite_customer_sync.tenant_slugs`.
- Service identity: `X-8W-Service: milepost-customers`, backed by one active
  `svc_identities` row under that exact Safeharbor tenant.
- Timestamp: `X-8W-Timestamp` is canonical positive Unix seconds (no sign or
  leading zero) and must be within 300 seconds of the Safeharbor clock.
- Signature: `X-8W-Signature` is exactly 64 lower-case hexadecimal characters.
  It is HMAC-SHA256 using the dedicated server-only
  `suite_customer_sync.hmac_secret` over these exact bytes:

```text
safeharbor-suite-customer-sync-v1\n{X-8W-Timestamp}\n{raw request body}
```

The key is destination-specific. It is not a `svc.secrets` key, portal/OIDC
secret, suite-cookie secret, or Milepost alert key. Never put it in Git, chat,
logs, a migration, an event receipt, or a report. Authentication fails closed
if the gate, allowlist, exact identity, fresh timestamp, body size, key, or
signature is wrong.

The request body is at most 8 KiB and is an object with exactly these eight
keys and no extras:

```json
{
  "schema_version": 1,
  "tenant_slug": "8west",
  "customer_id": "11111111-1111-4111-8111-111111111111",
  "source_version": 1,
  "display_name": "8 West IT",
  "status": "active",
  "event_id": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
  "occurred_at": "2026-08-26T12:34:56Z"
}
```

The identifiers must be canonical lower-case UUIDv4 values. `tenant_slug` is
one to 64 lower-case letters, digits, or hyphens. `source_version` is a JSON
integer greater than zero. `display_name` is trimmed, nonblank valid UTF-8, at
most 128 characters, and contains no control character. Status is exactly
`active` or `inactive`. `occurred_at` is a real UTC timestamp at second
precision with the literal `Z` suffix.

The two timestamps have different jobs. The signed header timestamp proves a
fresh HTTP request and always has the +/-300-second window. `occurred_at` is
the durable business-event time: an old event remains valid after an outage,
but a new accepted version may not move backward and no event may be more
than 300 seconds in the future.

## Exact responses

An accepted event and an exact replay both return HTTP 200 with only these
five keys:

```json
{"ok":true,"event_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","customer_id":"11111111-1111-4111-8111-111111111111","source_version":1,"status":"active"}
```

Milepost treats only that exact key set, types, identifiers, version, and
status as delivered. There is no `action`, `outcome`, client id, tenant id, or
display name in the response.

A different event with a stale or skipped version returns HTTP 409 exactly:

```json
{"ok":false,"error":"source_version_conflict","expected_source_version":2}
```

Malformed bodies are HTTP 400. Authentication failures and unknown tenant
slugs are the same HTTP 401 `unauthorized` response. A changed replay of an
existing event id is HTTP 409 `event_id_conflict`; cross-tenant customer-id
reuse is HTTP 409 `customer_tenant_conflict`; an inactive first version is
HTTP 409 `initial_status_conflict`; and regressing event time is HTTP 409
`event_time_conflict`. Those non-version conflicts contain no invented
expected version. All non-exact-200 results remain undelivered on the
Milepost side and follow its bounded retry/dead-letter rules.

## Binding and lifecycle rules

`customer_id` is the stable suite key. It is globally unique in the
Safeharbor binding table and can never move to another Safeharbor tenant or
client.

1. The first accepted version must be version 1 and `active`. Safeharbor
   creates one client and one binding in one transaction. The integration
   supplies only the client name. Safeharbor defaults and continues to own
   domain, source routing, SLA tier, health, notes, contacts, and every other
   operational fact.
2. Every new event must be exactly the previous source version plus one. An
   `active` version may rename the same client. It never creates a replacement
   client.
3. An `inactive` version marks only the binding inactive. It does not delete
   or disable the client. Its source `display_name` is retained in the binding
   and immutable receipt, but it does not rename the retained client. It does
   not delete, retarget, rewrite, or resolve a ticket. It preserves technician
   time, service-goal snapshots/deadlines, contacts, portal history, report
   history, and all other Safeharbor facts.
4. A later sequential `active` version may reactivate the binding and rename
   the same client. This is still the same source customer and local client.
5. Replaying the exact event id and exact raw body returns the original
   five-field receipt, even after later versions have arrived. Reusing an
   event id with different bytes or facts is tampering and is rejected.

`clients.source_key` is reserved for partner-support routing. Customer sync
never reads, sets, clears, infers, or reuses it. Migration 015 instead creates
`suite_customer_sync_bindings` and `suite_customer_sync_events`.

Every accepted binding version causes a database trigger to append one event
receipt containing the exact tenant/customer/client binding, source facts,
business-event time, and SHA-256 of the raw request. Binding identity cannot
change, bindings cannot be deleted, and receipts cannot be updated or deleted.
Foreign keys deliberately prevent a client deletion from orphaning this or
its help-desk history.

## Installation and controlled canary

Migration 015 and `db/schema.sql` are the upgrade and fresh-install copies of
the same two tables and eight lifecycle triggers. The migration is additive
and creates no rows. Apply it through the trigger-capable operator path after
an exact backup, then verify the table, check, foreign-key, index, and trigger
shapes. Do not apply it with the DML-only runtime identity.

Enabling is a separate, reversible configuration change after both apps are
on reviewed commits:

1. Keep `suite_customer_sync.enabled=false` and the allowlist empty while the
   migration and source are verified.
2. Register only the exact active `milepost-customers` service identity under
   the intended destination tenant.
3. Install the same newly generated dedicated key in each app's protected
   host configuration without printing it. Configure the exact HTTPS receiver
   URL in Milepost.
4. Add only the canary tenant slug (`8west` for the owned 8 West IT canary),
   leave the sender off, and prove disabled/unauthorized requests create no
   clients, bindings, or receipts.
5. Enable the Safeharbor receiver first, then the Milepost dispatcher. Create
   one 8 West IT Milepost customer and verify one Safeharbor client, stable
   binding, one receipt, exact replay, rename, and inactive preservation.
6. Confirm no `clients.source_key`, ticket, time, service-goal, portal, report,
   or Coastmark facts changed unexpectedly before widening either allowlist.

The provider slug `8west` in this transport selects the 8 West IT Safeharbor
tenant. It does not create or enable a customer-portal OIDC binding, and it
does not weaken the portal's reserved identity-slug rules.

Rollback is to turn the Milepost dispatcher off and then set
`suite_customer_sync.enabled=false`. Keep the client, binding, and immutable
receipts for reconciliation. Never drop the tables, delete the client, reuse
the customer id elsewhere, or bypass foreign keys as a rollback shortcut.

## Required evidence

- `php app/tests/suite_customer_sync_test.php` covers strict payload and HMAC
  rules, exact replay, conflicts, lifecycle behavior, and preservation in a
  hermetic fixture.
- `php app/tests/suite_customer_sync_mysql_test.php` refuses to run unless
  `SAFEHARBOR_CUSTOMER_SYNC_TEST_DISPOSABLE_SERVER=1`, then creates a random
  per-run `safeharbor_customer_sync_test*` database and runtime account and
  removes both in `finally`; it proves fresh schema plus
  migration 015, trigger/check/FK behavior, DML-only runtime grants, native
  receipts, replay, rename/deactivate preservation, and migration replay.
- CI lints every PHP file and runs both suites. Production acceptance still
  requires matching commit/runtime evidence and the controlled canary above.
