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
That is the ownership fact a later restoration implementation must honor.

The direct write allowlist contains only `customer_portal_bindings`,
`business_report_schedule_versions`, and
`managed_customer_lifecycle_receipts`. Portal audit events remain trigger-owned.
There is no report send, ticket resolution, technician-time, Coastmark, billing,
AI-action, or endpoint-control writer.

An exception at any injected stage rolls back all surface and receipt changes.
A lost acknowledgement replays the exact immutable receipt. Competing workers
serialize on the same source binding and converge to one containment plus one
replay.

## Identity proof and restoration latch

The shared schema-2 adapter now requires the exact signed 20-member success
object in this order:

```text
ok, schema_version, customer_id, customer_source_version,
customer_receipt_id, customer_status, lifecycle_version,
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
The lifecycle evidence digest is opaque ID-owned evidence; Safeharbor validates
and can bind its exact bytes but cannot recompute ID-private facts.

Continuously active activation rejects any local inactive history. After the
first local inactive event, only signed `lifecycle_action=restored` may ever
clear the latch. This slice deliberately does **not** clear it: restoration
configuration is fixed false and schema-2 contact proof by itself throws a
restore-unavailable error. The follow-on must additionally prove how the ID
customer receipt maps to Safeharbor's exact latest source receipt, store the
fresh lifecycle fields, and restore only when the latest portal audit and
schedule IDs still equal this containment receipt. Any unsigned/non-200,
stale, mismatched, `observed_active`, inactive, lifecycle-owned, or human-held
state stays off.

## Migration and tests

Migration `024_managed_customer_lifecycle.sql` is additive. It creates the
receipt table behind an enforced install-lock check, validates its exact engine,
columns, indexes, foreign keys, and checks, then installs fail-closed
insert/update/delete swap guards before replacing permanent guards. It verifies
the permanent trigger shapes and removes the swap/install blockers only after
postflight. An interrupted migration remains closed until an exact replay. A
DML-only runtime identity cannot replay the migration. `app/db/schema.sql`
carries the same final table and trigger behavior.

Focused coverage:

- `managed_customer_lifecycle_test.php`: inactive/legacy boundaries, current
  session lookup, physical containment, human-hold preservation, interruption
  rollback, lost-ack replay, post-inactive active latch, hard scan/batch bounds,
  no-starvation refusal handling, forbidden tables, and exact expanded ID
  evidence refusal;
- `managed_customer_id_evidence_test.php`: exact 20-member signed wire shape and
  every new status/action/type/hash refusal;
- `managed_customer_activation_test.php`: continuously active activation cannot
  consume a customer with inactive history; and
- `managed_customer_lifecycle_mysql_test.php`: real migration replay and
  interrupted-blocker recovery, database immutability, least-privilege runtime,
  competing workers, transaction rollback, and fresh-schema parity on a
  disposable MySQL database.

No migration, configuration, cron installation, merge, deployment, portal
reactivation, or report delivery is performed by this source slice.
