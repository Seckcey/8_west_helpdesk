# Milepost customer registry -> Safeharbor contract

**Status:** the source contract and migration 015 are live, and one controlled
8 West IT create canary has crossed the seam. Milepost customer
`4ebaeefa-b101-47f8-ac76-e49ab309d272`, event
`dce77b64-e382-466d-b4e3-5cf5bd7ce74b`, created Safeharbor client 13 with one
binding and one immutable receipt. Safeharbor's receiver remains enabled only
for the exact canary identity and `8west` tenant. The Milepost sender is off
after the one manual tick, and no dispatcher is scheduled. Shipping the source
or migration alone still does not enable this integration; production status
must be established by separate runtime evidence.

This is the first narrow customer-provisioning seam. Milepost owns the suite
customer identifier and the ordered name/status events. Safeharbor owns the
local client row, tickets, time, service-goal snapshots, portal mappings, and
reports. This receiver never queries Milepost and never writes Coastmark or 8
West ID.

## Shared operational editing flow (source candidate)

The client editor separates the **last synced** shared operational name/state
from the local Safeharbor fields. The shared display is read from the exact
current binding and its matching immutable receipt, never from an editable
local name. It shows the accepted source version; it does not prove Milepost's
live state. An older active receipt cannot restore a removed Milepost client.

For a verified binding, **Edit shared client details in Milepost** opens the
fixed `https://support.8westit.com/suite_customer_metadata.php` editor with
only the normalized customer UUID and expected source version. Milepost must
independently authenticate the actual existing requester, verify current
client-management authority and exact tenant/customer scope, and use its own
CSRF-bound editing flow. The link grants no permission. Safeharbor sends no
outbound metadata mutation and borrows no service or feedback credential.
This flow is a source candidate until the corresponding Milepost editor is
released and verified; a link or local save is not synchronization proof.

**Save local changes** keeps existing contact/domain/service-goal/health/notes
and history ownership. A local name override is local-only and can be replaced
by a later canonical active name receipt under the existing receiver contract.
Unbound clients retain local-only editing. Unavailable metadata does not block
local edits or report them as shared completion.

A bound client, even with zero tickets, cannot be removed by the local delete
POST. The handler rechecks the binding before any contact deletion, using the
receiver's tenant-first lock order and one transaction. Stale or forged forms
preserve bound contacts, receipts, and history. Unbound local deletion still
requires a fresh zero-ticket check; a client-delete failure rolls back contact
deletion. Shared inactive state belongs in the canonical editor, which retains
the relationship. Existing exact-current human access-restoration requirements
remain separate from a canonical active receipt.

### Clients directory and per-client app links (source candidate)

The Clients list reads every bound client's last accepted shared name and
active/inactive state in one query, with the same binding/receipt agreement as
the editor, and shows it beside the local name and domain. A client reads
"Local to Safeharbor" only when that lookup succeeded and found no binding. A
failed lookup, or a binding whose receipt disagrees, reads "Shared details
unavailable": a failure is never treated as proof that a client is unbound.
Each row keeps its keyboard-selectable client link and adds three buttons:

- **Support** opens the local client page;
- **Devices** (linked, available, active clients only) opens Milepost's
  `https://support.8westit.com/client.php?provider=<slug>&customer_id=<UUID>`.
  The provider is the binding tenant's own slug, which the receiver requires to
  equal the event's tenant slug. Milepost signs the user in and checks its own
  tenant before resolving anything, so the link carries no authority;
- **Billing** opens Coastmark's
  `https://coastmark.8westit.com/clients?provider=<slug>&customer_id=<UUID>`
  (`client_operational_billing_url()`) for every linked client with an agreeing
  receipt, inactive and removed history included, and for an active Milepost
  business not yet received. Coastmark checks the user's organization and its
  own audited binding first. Local and unreadable rows open Coastmark's
  existing suite entry, where the user selects the client.

The client page shows the same shared line and uses the client-scoped Milepost
link for linked active clients and the client-scoped Coastmark link for every
linked client. These links are presentation only: they must not be released
before Milepost's provider + UUID route is live, and the Coastmark link only
after Coastmark's provider + UUID resolver is live.

#### Current Milepost directory (request-current roster)

`client_operational_roster()` reads Milepost's `suite-client-directory-v1` once
per Clients or client page request and stores nothing:

- **Credential:** the verified current suite cookie, only when its `sub` equals
  this active account's own `users.suite_subject` and its tenant equals this
  account's tenant slug. A local-only account never borrows a browser cookie. The
  token stays in that request's memory and is sent only as the bearer token to the
  fixed `https://support.8westit.com/suite_customer_metadata.php`.
- **Transport:** POST, no redirects or retries, 8 s total and 3 s to connect, at
  most 512 KiB. No token or body is logged.
- **Expected issuer:** every present source must agree, and at least one must exist:
  the verified `8west:tenant_id` claim, this tenant's own managed-provider
  `issuer_tenant_id`, and for the house only the nonsecret
  `id_report_contacts.tenant_bindings` mapping (`8west` → `ewid-t<N>`). Its HMAC
  contact transport is never loaded. Nothing is inferred from a local numeric id.
- **Reply:** exactly the A14 keys and items, this subject, provider, issuer and
  nonce, an integer `read_at` within the request window ±60 s, and at most 256
  byte-sorted unique customers. Duplicate keys, partial or oversized bodies,
  non-200 responses, timeouts or any other value make the whole roster
  unavailable. The account and provider are read again after the request; any
  change makes it unavailable.
- **Classification:** Businesses lists linked clients that the roster lists
  active with `client_exists`, plus active Milepost businesses Safeharbor has not
  received yet (shown, never created). Everything else stays under Retained and
  local, unchanged, with Support. A linked active canary missing from the roster
  stays retained. Nothing is inactivated or deleted by absence. A verified empty
  roster says so, and an unavailable roster lists every client as before. The
  read writes nothing.

Focused coverage: `app/tests/client_operational_context_test.php` and the
rendered fixture `tools/shots/client-shared-directory.test.mjs`
(`app/tests/client_shared_directory_ui_test.php`); existing
customer-sync/lifecycle fixtures continue to own ordered receipt and restoration
semantics. Runtime convergence and real-user acceptance are separate release
evidence, collected by the named release owner.

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
   history, and all other Safeharbor facts. The permanent `customer_id` remains
   the same. Inactivity never appends a disabled report-schedule version or
   otherwise changes schedule status; Safeharbor owns that switch, and a human
   owner/admin must explicitly disable the Safeharbor schedule when the service
   workflow calls for it.
   Staff new-ticket choices and their contact choices exclude customers that
   fail the existing `managed_customer_operational()` boundary. Submission
   rechecks that same boundary inside the ticket transaction, taking the tenant
   share lock before provider/binding/client reads. A previously loaded form
   cannot create a ticket or message after an inactive event commits.
4. A later sequential `active` version may reactivate the binding and rename
   the same client. This is still the same source customer and local client.
   New-ticket eligibility does not automatically return after inactive history:
   it requires the existing immutable restoration receipt for the exact current
   source binding, customer, version and event. An older receipt cannot revive
   a later source version. Ordinary unbound local clients keep their existing eligibility.
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

The new-ticket list uses a bulk read projection of the canonical operational
predicate, then omits the existing unbound email/alert intake routing records
from manual choices. Purpose matching requires the exact creator name and note,
blank domain, and NULL partner `source_key`; a similar name alone never excludes
a client. Managed bindings and ordinary local/partner records retain their
canonical eligibility. The matcher uses mutable metadata, not an immutable type:
changed signatures remain ordinary choices, and a complete system-metadata copy
cannot be distinguished. Intake creators, operational routing and history remain
unchanged. Only ID/name/tier leave the selector helper; contacts derive from its
final choices. Migration 009 supplies the existing `source_key` field.

The MySQL regression checks retain canonical list/contact parity across lifecycle
transitions and provider availability for the ordinary/managed cohort. Separate
manual-purpose cases cover routing records, ordinary variants, managed and
partner exceptions, history, and stale POST. The server write check calls the
canonical status function and the same purpose matcher after tenant/provider/
binding/client locking, before ticket or message writes. Query count remains
independent of the number of options.

A retained active consumer receipt cannot reveal an undelivered producer
deletion. Diagnose the exact tenant/customer UUID, ordered frozen outbox event,
current delivery scope and receiver receipt before changing eligibility. Preserve
the original event and uncertain transport outcomes; never infer inactivity from
a label or treat a zero failed-attempt counter as proof that no request occurred.
See the [October 11 capability and remaining
sync gaps](CLIENT_LIFECYCLE_SYNC_2026_10_11.md); this consumer correction does not
establish bidirectional sync or prove a production deletion was delivered.

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

## Production canary checkpoint

On 2026-08-27, one manually dispatched version-1 `8west` event created
Safeharbor client 13 and exactly one stable binding/receipt. Existing production
facts stayed at 277 tickets and 541 messages, and the new client has zero
operational edges. No ticket, time entry, service-goal snapshot, portal state,
business-report fact, Coastmark fact, or endpoint-control action was created by
the canary.

The sender was turned off immediately after that one tick. Safeharbor's
receiver stays on only for the exact dedicated identity and tenant allowlist,
and neither side has a customer-sync scheduler. This proves initial creation;
the contract's exact replay, rename, inactive-preservation, and reactivation
checks remain controlled future lifecycle canaries rather than completed
production claims.

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
