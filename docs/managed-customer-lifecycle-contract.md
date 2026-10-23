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

### The one scheduled mode (A82, migration 033)

`php cron/managed_customer_lifecycle.php --scheduled` is the only mode the shared report job starts.
The no-argument explicit mode and the P9 manual path are unchanged.

**Scope.** The union of two sets, deduplicated by binding:
- the explicit approved `customer_ids` scope, with its approved `tenant_actors`;
- every lineage with an immutable created or restored receipt. A house lineage acts as
  `tenant_actors['8west']`. A managed provider's lineage acts as that provider's current admitted
  owner; a withdrawn provider is refused.

**Claims.** A candidate still needs containment or restoration. Each claim locks the singleton
`managed_customer_lifecycle_progress` row and takes the next eligible binding ID after
`last_binding_id`, wrapping once and skipping bindings already claimed in this run. It advances the
cursor and commits before any blocking database or HTTP action. The existing apply/restore functions
then run under a configuration scoped to exactly that UUID and actor, and recheck the provider,
actor, current source and receipts under their own locks.

**Failure.** A missing singleton or an unknown cursor commit stops the run with no action. A crash,
kill, timeout or refusal still moves consideration on. The cursor is never authority, status or
completion evidence. Results carry only hashed UUIDs and error digests.

**Bounds.** One run has at most 25 claims and 10 applied transitions in total, and a 30-second
monotonic deadline. The first claim is always made; after the deadline no new claim or transition
starts. A restoration's ID request timeout is capped at the remaining whole seconds, so with less
than one second left it is deferred. For a fixed set of N candidates, healthy committed claims
advance round-robin: every candidate is considered within N claim-runs, whatever earlier candidates
refuse. There is no bound under a total database outage or unbounded arrivals.

**Wrapper.** `run_business_reports.sh` starts this one child after the reports, under the same
runtime user, five-minute cadence and shared deploy lock, with `timeout` (45 s TERM, then 5 s KILL).
It reads the child's exit status before logging anything. A missing or unsafe stage, a refusal, a
timeout and a logger failure each get a distinct bounded log line. The report run's exit status
stays the wrapper's exit status.

## Identity proof and restoration latch

The shared schema-2 adapter now requires the exact signed 20-member success
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

Restoration reopens a portal or schedule only when it is currently owned: its
current disabled audit event/version and hash still equal the exact containment
receipt that changed that surface. It appends a fresh disabled schedule and
client contact snapshot, then a fresh active schedule; no report history is
changed. A pre-existing or later human disable/hold remains disabled and is
recorded as not restored. An older containment receipt whose disabled audit or
version is no longer the latest is history, not current ownership.

A surface that is already active and has no current owner (for example a
customer whose inactive event arrived while it was off the lifecycle
allowlist, or a later active version after an earlier owned restore) is
preserved: the receipt records it as not restored against its exact latest
audit event or schedule version and state hash, with no owner receipt and no
transition, prepared, contact or active IDs, and nothing on that surface is
written. A receipt that claims to own an active surface's current state is
ambiguous and refused.

The restore receipt is inserted last, so the read latch clears only when all
owned surface transitions and evidence commit atomically. Any unsigned/non-200,
stale, mismatched, `observed_active`, inactive, lifecycle-owned, old-source,
or ambiguous state stays off.

The worker routes each candidate once. With restoration enabled, an active
candidate with inactive history goes straight to restoration and is not
contained first, because containment would disable the active surfaces that
restoration preserves. Restoration validates the signed exact-current
`restored` evidence before it reads any surface; a refusal leaves the latch and
every surface as they were. Every other candidate is handled as before:
inactive events are contained, and with restoration disabled an active
candidate with inactive history stays `reactivation_blocked`.

The Clients list and client page show a bound client's last received shared
active/inactive state. That label reports the receipt only; it neither clears
nor sets this latch, and it does not reopen a portal, report or schedule.
A same-UUID Milepost restoration (original inactive v2, then active v3) still
needs this restoration path for the exact current v3. Preserving an already
active, never-contained portal or schedule needs migration 032 below.

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

Migration `032_managed_customer_preserved_active_restore.sql` replaces one
guard only, the restore insert validator `trg_mc_restore_before_insert`
(marker `safeharbor-managed-customer-lifecycle-restore-receipt-v2`), adding the
preserved-active branch above for the portal and the schedule. Every other
check is unchanged from 024, and no table, column, key, check or receipt is
changed. Its preflight accepts only the completed 024 guards, a completed 032,
or an interrupted 032. It proves TRIGGER privilege, then installs
`trg_mc_restore_preserve_swap_insert`, which refuses every restore-receipt
insert until the new validator and both unchanged immutability guards pass the
postflight; only then is the swap guard dropped. The swap guard is recognized
only by its exact fail-closed definition: a row-level BEFORE INSERT trigger on
the restore-receipt table whose whole body is that SIGNAL, compared byte for
byte. A marker string alone is not enough. A same-name trigger with any other
body, timing, event or table refuses in the preflight and again before the old
validator is dropped. The no-update/no-delete guards are never touched. An
interrupted run stays closed, and an exact replay converges. Apply it from a
canonical LF checkout, because the comparison is byte-exact.
`app/db/schema.sql` carries the same v2 validator. 024 is never edited.

Apply and recover through the existing deploy path, which backs the database
up, stops the app and its jobs, and applies the database change before the
files (the database change stays applied if the files are rolled back):

```bash
bash deploy/deploy.sh --migrate app/db/migrations/032_managed_customer_preserved_active_restore.sql
```

- Afterwards, `information_schema.TRIGGERS` shows exactly three BEFORE triggers on
  `managed_customer_lifecycle_restore_receipts`: the v2 validator plus
  `trg_mc_restore_no_update` and `trg_mc_restore_no_delete`.
- If it stops part-way, restoration stays refused (the swap guard is in place).
  Running the same command again converges.
- If its preflight refuses, nothing was changed; investigate rather than drop
  guards by hand.
- Never replay 024 after 032: that would reinstall the stricter v1 validator.
  That is fail-closed, and replaying 032 restores v2.
- Either order is fail-closed. New code before 032 is refused by the v1
  validator when it tries to preserve an active surface, and writes nothing.
  Old code after 032 behaves as before: v2 only adds alternatives where v1
  found no match, so it accepts every receipt v1 accepted.
- Applying 032 in production, and enabling restoration for any customer, are
  separate owner-gated steps.

Focused coverage:

- `managed_customer_lifecycle_test.php`: inactive/legacy boundaries, current
  session lookup, physical containment, human-hold preservation, interruption
  rollback, lost-ack replay, post-inactive active latch, hard scan/batch bounds,
  no-starvation refusal handling, restored-only owner-aware release, human-hold
  preservation, restore interruption/replay, forbidden tables, exact ID
  evidence refusal, worker routing (restore-first only when restoration is
  enabled; `reactivation_blocked` otherwise; refused evidence touches nothing),
  zero-write preserved-active restoration, forged current ownership refusal,
  and a later exact restore over an earlier owned cycle;
- `managed_customer_id_evidence_test.php`: exact 20-member signed wire shape and
  every new status/action/type/hash refusal;
- `managed_customer_activation_test.php`: continuously active activation cannot
  consume a customer with inactive history; and
- `managed_customer_lifecycle_mysql_test.php`: real migration replay and
  interrupted-blocker recovery, database immutability, least-privilege runtime,
  competing containment and restoration workers, transaction rollback,
  least-privilege restore, and fresh-schema parity (024 + 032) on a disposable
  MySQL database. For 032 it covers:
  - DML-only refusal, replay, an interrupted swap and a refused unknown state;
  - a wrong same-name swap guard (marker-only body, AFTER timing, other table),
    refused with and without the validator and changing no trigger;
  - exact replay from both interrupted points (validator gone, and v2 present
    with the swap guard);
  - new code refused under 024;
  - preserved-active restoration with byte- and row-count-identical portal,
    events, schedule, contacts and reports, including an ID contact that differs
    from the active schedule's at the same version, which is recorded as evidence
    and never refused;
  - replay, two competing workers and rollback;
  - stale, wrong-event, wrong-UUID and `observed_active` refusals;
  - forged owner or stale audit/version receipts refused by the guard;
  - owned-disabled restoration and human holds under 032.

No migration, configuration, cron installation, merge, deployment, portal
reactivation, or report delivery is performed by this source slice.
