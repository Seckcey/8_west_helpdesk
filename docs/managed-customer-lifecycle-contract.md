# Managed-customer lifecycle containment contract

## What this slice does

This source-only slice makes Milepost's customer status an immediate safety
boundary in Safeharbor. If an exact managed customer is inactive, Safeharbor:

- refuses a new portal sign-in and destroys an existing portal session at its
  next authenticated request;
- refuses report preview and persisted generation;
- omits pending archives from delivery selection;
- rechecks the customer under a shared source-row lock immediately before the
  report transport, so a committed inactive event cannot cross the send
  boundary; and
- preserves every ticket, message, SLA snapshot, time entry, archive, and
  delivery record.

A client with no `suite_customer_sync_bindings` row is legacy/non-Milepost and
keeps its existing behavior. The 8 West IT master UUID remains excluded.

The read boundary does not wait for the worker. A managed client is operational
only while its current source status is `active` and its immutable source event
history contains no `inactive` event. Therefore a later Milepost `active` event
cannot silently reopen a customer that was ever inactive.

## Default-off physical containment

`app/cron/managed_customer_lifecycle.php` is separately default-off. Enabling
it requires an exact list of at most 25 lowercase non-master UUIDs and an exact
provider-tenant-slug to active owner/admin actor map. `batch_size` caps
successful receipts, not examined/refused customers, so an early refusal does
not starve a later customer in the bounded allowlist.

For each current inactive event, or active event with prior inactive history,
one transaction:

1. locks and rechecks the exact current Safeharbor source binding plus immutable
   source receipt;
2. changes an active portal binding to disabled, if and only if it was active;
3. appends one disabled version to the deterministic managed weekly schedule,
   if and only if its latest version was active; and
4. inserts one immutable lifecycle receipt last.

The receipt records source binding/event/receipt/version/status/request hash,
whether each surface was active, exact portal before/after event IDs and
snapshot hash, exact report active/disabled version IDs and state hash, actor,
action, and a canonical whole-receipt digest. If a human had already disabled a
surface, the worker records `was_active=0` and neither claims nor changes it.
That ownership fact is the only authority the restoration path may use.

The direct write allowlist contains only `customer_portal_bindings`,
`business_report_schedule_versions`,
`business_report_id_client_contact_snapshots`,
`managed_customer_lifecycle_receipts`, and
`managed_customer_lifecycle_restore_receipts`. Portal audit events remain
trigger-owned.
There is no report send, ticket resolution, technician-time, Coastmark, billing,
AI-action, or endpoint-control writer.

An exception at any injected stage rolls back all surface and receipt changes.
A lost acknowledgement replays the exact immutable receipt. Competing workers
serialize on the same source binding and converge to one containment plus one
replay.

## Identity proof and restoration latch

The shared schema-2 adapter now requires the exact signed 21-member success
object in this order:

```text
ok, schema_version, customer_id, customer_source_version,
customer_receipt_id, customer_event_id, customer_status, lifecycle_version,
lifecycle_transition_id, lifecycle_action, lifecycle_evidence_sha256,
identity_tenant_status, identity_oauth_session_version, lifecycle_owned,
identity_tenant_key, identity_tenant_slug, contact_version,
weekly_report_email, generated_at, request_nonce
```

It accepts only an authenticated HTTP 200 with `customer_status=active`,
`lifecycle_version=1`, positive integer transition/OAuth versions,
`lifecycle_action` equal to `observed_active` or `restored`, lowercase 64-hex
lifecycle evidence, `identity_tenant_status=active`, and
`lifecycle_owned=false`. The existing nonce, freshness, exact-member-order,
duplicate-member, fixed-endpoint, and response-HMAC rules remain unchanged.
`customer_event_id` is the exact original Milepost UUID stored with the current
ID receipt. The lifecycle evidence digest is opaque ID-owned evidence;
Safeharbor validates and can bind its exact bytes but cannot recompute
ID-private facts.

Continuously active activation rejects any local inactive history. After the
first local inactive event, only signed `lifecycle_action=restored` may clear
the latch, and restoration has its own default-off gate. The signed customer
UUID, source version, and event UUID must match Safeharbor's exact current
active binding and immutable source receipt. The ID tenant mapping, contact,
transition, OAuth session version, nonce-bound response, and opaque lifecycle
receipt/evidence hashes are stored in one immutable restore receipt.

Restoration reopens a portal or schedule only when its current disabled audit
event/version and hash still equal the exact containment receipt that changed
that surface. It appends a fresh disabled schedule and client contact snapshot,
then a fresh active schedule; no report history is changed. A pre-existing or
later human disable/hold remains disabled and is recorded as not restored. The
restore receipt is inserted last, so the read latch clears only when all owned
surface transitions and evidence commit atomically. Any unsigned/non-200,
stale, mismatched, `observed_active`, inactive, lifecycle-owned, old-source,
or ambiguous state stays off.

## Migration and tests

Migration `024_managed_customer_lifecycle.sql` is additive. It creates separate
containment and restoration receipt tables behind enforced install-lock checks,
validates their exact engines, columns, indexes, foreign keys, and checks, then
installs fail-closed insert/update/delete swap guards before replacing permanent
guards. The restore insert guard independently rechecks the current source,
inactive history, ID mapping, owned or preserved portal/schedule state, fresh
contact snapshot, and canonical digest. An interrupted migration remains closed
until an exact replay. A DML-only runtime identity cannot replay the migration.
`app/db/schema.sql` carries the same final tables and trigger behavior.

Focused coverage:

- `managed_customer_lifecycle_test.php`: inactive/legacy boundaries, current
  session lookup, physical containment, human-hold preservation, interruption
  rollback, lost-ack replay, post-inactive active latch, hard scan/batch bounds,
  no-starvation refusal handling, restored-only owner-aware release, human-hold
  preservation, restore interruption/replay, forbidden tables, and exact ID
  evidence refusal;
- `managed_customer_id_evidence_test.php`: exact 21-member signed wire shape and
  every new status/action/type/hash refusal;
- `managed_customer_activation_test.php`: continuously active activation cannot
  consume a customer with inactive history; and
- `managed_customer_lifecycle_mysql_test.php`: real migration replay and
  interrupted-blocker recovery, database immutability, least-privilege runtime,
  competing containment and restoration workers, transaction rollback,
  least-privilege restore, and fresh-schema parity on a disposable MySQL
  database.

No migration, configuration, cron installation, merge, deployment, portal
reactivation, or report delivery is performed by this source slice.
