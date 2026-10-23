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
The no-argument explicit mode is unchanged.

**Scope.** The union of two sets, deduplicated by binding:
- the explicit approved `customer_ids` scope, with its approved `tenant_actors`;
- every lineage with an immutable created or restored receipt. A house lineage acts as
  `tenant_actors['8west']`. A managed provider's lineage acts as that provider's current admitted
  owner; a withdrawn provider is refused.

**Claims.** A candidate still needs containment or restoration. Each claim locks the singleton
`managed_customer_lifecycle_progress` row and takes the next eligible binding ID after
`last_binding_id`, wrapping once and skipping bindings already claimed in this run. It advances the
cursor and commits before any blocking database or HTTP action. The existing apply/restore functions
then run under a configuration scoped to exactly that UUID and actor, and recheck the actor, current
source and receipts under their own locks.

**Authority at action (A141).** The scheduled provenance is carried into the action through its
guard seam. For an admitted lineage, right after the tenant and actor locks and before any write,
the action rechecks the current managed provider with shared locks (`suite_managed_provider(..., true)`):
it must still be current, for the same tenant, with the scheduled actor as its owner. The binding must
still carry an immutable admitted receipt. A withdrawal, lease expiry or owner change while the proof
is being fetched, or while the action waits on that lock, therefore refuses with nothing written. The
explicit scope and the no-argument mode keep their earlier checks.

**Restoration routing (A88/A152).** It is chosen before any proof is requested or any receipt is
written. Scheduled restoration is an identity/latch operation for the approved scope and admitted
lineages alike, so it always uses the schema-3 identity-only proof (migration 034, below), which never
writes reports. Approved scope membership is business scope, not report-release intent, and a
report-ID map or list presence never requests a contact release. The full schema-2 contact restore,
which may release an owned report, stays only in the separately run no-argument mode. Neither path
falls back into the other. Without 034 installed, a scheduled restoration is refused before any ID
request or budget use. The identity restore receives the original configuration: its authority is
the actual approved scope or a genuine admission (A151), never a list built around the claimed UUID.

**Failure.** A missing singleton or an unknown cursor commit stops the run with no action. A crash,
kill, timeout or refusal still moves consideration on. The cursor is never authority, status or
completion evidence. Results carry only hashed UUIDs and error digests.

**Bounds.** One run has at most 25 claims and 10 transition reservations in total, and a 30-second
monotonic scheduling deadline. A reservation is taken before the action's proof fetch or locks, so
the run's `reserved` count is attempts, not commits: it bounds writes to at most 10, but a reserved
action may still be deferred, refused or fail, and only `results` shows each outcome. The first claim
is always made; after the deadline no new claim or reservation starts. A restoration's ID request
timeout is capped at the remaining whole seconds, so with less than one second left it is deferred.
The deadline is rechecked after the proof fetch, before the first write and before the receipt
(A143); if it has passed, the action rolls back and reports `deferred` with zero writes. The
30-second deadline governs scheduling only. A database wait or HTTP request already in progress is
bounded only by its own timeout and, in the end, by the wrapper's 45 s TERM and 5 s KILL; nothing
here proves every such wait finishes inside the remaining 30 seconds. For a fixed set of N
candidates, healthy committed claims advance round-robin: every candidate is considered within N
claim-runs, whatever earlier candidates refuse. There is no bound under a total database outage or
unbounded arrivals.

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

### Identity-only restoration (A88/A95/A97, migration 034)

The schema-3 proof has its own request, decoder and fetch functions in
`managed_customer_id_evidence.php`; schema 2 is unchanged. The request is exactly
`{"schema_version":3,"purpose":"identity_lifecycle","customer_id":"<uuid>"}` over the same
endpoint, key, HMAC, nonce, freshness and size limits. The reply is the CLOSED25 object, in this
order, with no contact field and no 24-key alias:

```text
ok, schema_version, purpose, customer_id, provider_tenant_slug, customer_source_version,
customer_receipt_id, customer_event_id, initial_receipt_id, customer_status, lifecycle_version,
lifecycle_transition_id, lifecycle_action, lifecycle_evidence_sha256, identity_tenant_status,
identity_oauth_session_version, lifecycle_owned, identity_tenant_key, identity_tenant_slug,
generated_at, request_nonce, issuer_suite_tenant_id, projection_binding_sha256, proof_scope,
restoration_anchor
```

`proof_scope` is exactly `identity_lifecycle_only`. `restoration_anchor` is NULL or exactly
`{source_version, receipt_id, event_id, transition_id, evidence_sha256}`; duplicate members are
refused at the top level and inside the anchor. The current facts stay the actual current ones.
Anchor consistency (A155), checked by the parser, the restore and the v3 validator: a `restored`
current action is its own anchor, so all five anchor facts equal the signed current version,
receipt, event, transition and evidence; an `observed_active` current needs an anchor strictly
earlier in both version and transition; a `restored` current with no anchor is refused. Only
supplied signed facts are compared; nothing ID-private is computed.

`managed_customer_lifecycle_identity_restore()` clears one post-inactive latch with that proof.
Its authority (A151) is either the UUID's membership in the original approved `customer_ids` scope,
acting as its approved `tenant_actors` actor, or an immutable admitted (created/restored) receipt on
the binding, acting as the current managed provider's owner (or the house actor). An approved legacy
lineage whose frozen receipts carry no admission tag, such as Lifestyle, qualifies by scope alone;
its receipts are never retagged. Under its locks it also requires:
- the active provider tenant and owner/admin actor;
- the current issuer: the current managed provider for its slug (the provider row read with shared
  locks), or for `8west` the nonsecret `id_report_contacts.tenant_bindings` mapping;
- a complete contiguous local history 1..current, with the anchor as the active version directly
  after the latest inactive one; a newer inactive version invalidates an older anchor;
- an existing report-ID map, when present, matching the signed key and slug. An absent map is never
  invented; the signed key is kept in `id_projection_tenant_key`.

It never reads or writes report contact data or schedules. The schedule's actual latest version and
state hash are recorded with `schedule_restored = 0`, so report presence, a human hold or an
owned-disabled report never blocks identity and is never released here; releasing an owned report
needs the separate full schema-2 operation, and a later same-event release after an identity-only
receipt is a separate held operation. A currently owned disabled portal is re-enabled under the same
ownership/current-hash rules as above; an absent portal stays absent. The digest is the closed domain
`safeharbor-managed-customer-lifecycle-identity-restore-v1`, whose fields include the proof scope,
initial receipt, provider and issuer, projection, all five anchor facts and the transport hashes. A
lost acknowledgement replays the committed receipt; a changed proof for the same event conflicts.

#### P9 Lifestyle identity-only restoration (accepted source form and completed runtime record)

The Safeharbor owner accepted this source form at `15f7f68b`. It is retained as runbook history;
the one-time runtime completion is recorded below and must not be rerun. Source acceptance alone
did not authorize execution. The ordinary `--scheduled` run is **not** this packet
(A166). That run unions the explicit scope with every admitted candidate. It ignores `batch_size`,
and it can claim 25 and reserve 10. P9 instead calls the existing functions once, for exactly one
client.

Preconditions, all owner-applied and owner-verified:
- the protected current tuple and Lifestyle's actual preserved-active 0/0 state are verified under
  the existing sender and lifecycle custody before this call; a different state returns to owner
  review without running this packet;
- migrations 033 and 034 are applied, with the v3 validator exact;
- ID serves the schema-3 identity purpose for the Lifestyle UUID through its protected identity list;
  the old contact `customer_ids` are unchanged;
- `managed_customer_lifecycle` has `enabled` and `restoration_enabled` true, the Lifestyle UUID in the
  original approved `customer_ids`, and `tenant_actors['8west']` set to the reviewed active house
  owner/admin;
- `id_report_contacts` is enabled, with `tenant_bindings['8west']` naming the house tenant key whose
  number is the issuer ID signs.

The exact invocation runs from the `app/` directory, as the same runtime user and configuration as
the cron child. It is fed on standard input, so no file, CLI flag, configuration key or timer is
added. The three placeholders `P9_LIFESTYLE_UUID`, `P9_TENANT_ID` and `P9_V3_EVENT_ID` come from the
protected packet, and client `14` is fixed:

```php
<?php
declare(strict_types=1);
require 'lib/bootstrap.php';
require 'lib/managed_customer_lifecycle.php';
require_once 'lib/managed_customer_id_evidence.php';

const P9_CUSTOMER = 'P9_LIFESTYLE_UUID';      // protected packet
const P9_TENANT = P9_TENANT_ID;                // protected packet (integer)
const P9_CLIENT = 14;
const P9_VERSION = 3;
const P9_EVENT = 'P9_V3_EVENT_ID';             // protected packet

try {
    $pdo = db();
    // The ORIGINAL configuration, loaded once and never modified. It alone carries authority.
    $config = managed_customer_lifecycle_config(cfg('managed_customer_lifecycle', []));
    // A selection-only in-memory copy for the existing candidate query. It is not authority.
    $selection = array_replace($config, ['customer_ids' => [P9_CUSTOMER], 'batch_size' => 1]);
    $candidates = managed_customer_lifecycle_candidates($pdo, $selection);
    if (count($candidates) !== 1 || $config['restoration_enabled'] !== true
        || !in_array(P9_CUSTOMER, $config['customer_ids'], true)
        || (int)$candidates[0]['tenant_id'] !== P9_TENANT || (int)$candidates[0]['client_id'] !== P9_CLIENT
        || $candidates[0]['customer_id'] !== P9_CUSTOMER || (int)$candidates[0]['source_version'] !== P9_VERSION
        || $candidates[0]['last_event_id'] !== P9_EVENT || $candidates[0]['status'] !== 'active'
        || $candidates[0]['provider_tenant_slug'] !== '8west') {
        fwrite(STDERR, "p9: the candidate is not exactly the packet tuple; nothing done\n");
        exit(1);
    }
    // Before/after evidence for this client's portal, report, contact and receipt rows and for people.
    $surface = static function () use ($pdo): array {
        $rows = [];
        foreach (['customer_portal_bindings', 'customer_portal_binding_events', 'business_report_schedule_versions',
            'business_report_id_client_contact_snapshots', 'business_report_id_client_bindings', 'contacts',
            'managed_customer_lifecycle_receipts'] as $table) {
            $query = $pdo->prepare("SELECT * FROM {$table} WHERE client_id = ? ORDER BY 1");
            $query->execute([P9_CLIENT]);
            $rows[$table] = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        $rows['users'] = $pdo->query('SELECT COUNT(*), MAX(id) FROM users')->fetch(PDO::FETCH_NUM);
        return $rows;
    };
    // The scheduled loop's own semantics for one action: one reservation, a 30-second deadline.
    $deadline = hrtime(true) / 1e9 + MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_SECONDS;
    $remaining = static fn(): float => $deadline - hrtime(true) / 1e9;
    $reserved = 0;
    $mayTransition = static function () use (&$reserved, $remaining): bool {
        if ($reserved >= 1 || $remaining() <= 0) return false;
        $reserved++;
        return true;
    };
    $before = $surface();
    $result = managed_customer_lifecycle_scheduled_act(
        $pdo, $config, $candidates[0], $mayTransition, $remaining, cfg('id_report_contacts', []),
    );
    $after = $surface();
    $receipt = $pdo->prepare("SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts
        WHERE customer_id = ? AND source_version = ? AND id_proof_scope = 'identity_lifecycle_only'");
    $receipt->execute([P9_CUSTOMER, P9_VERSION]);
    $ok = $reserved === 1 && ($result['action'] ?? null) === 'restored'
        && ($result['proof_scope'] ?? null) === 'identity_lifecycle_only'
        && ($result['portal_restored'] ?? null) === 0 && ($result['report_schedule_restored'] ?? null) === 0
        && $after === $before && (int)$receipt->fetchColumn() === 1;
    echo json_encode(['p9' => $ok ? 'restored' : 'unexpected', 'reserved' => $reserved, 'result' => $result,
        'surface_unchanged' => $after === $before], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'p9: refused ' . $error::class . ' ' . hash('sha256', $error->getMessage()) . "\n");
    exit(1);
}
```

What it does, and only this:
- One existing candidate query, run over the selection copy. It must return exactly one row: the
  packet's tenant, client 14, the same UUID, the current active source version 3 and its event. The
  UUID must also be in the original approved scope. Any other result exits before any action. No
  durable scheduler cursor is read or moved, and no other UUID is touched.
- One `managed_customer_lifecycle_scheduled_act()` call, with the original untouched
  configuration, the exact candidate and the existing ID configuration. That call reserves one
  transition. It makes one schema-3 request for that UUID, with the timeout capped by the remaining
  time, then one identity-only restore as `tenant_actors['8west']`. The restore keeps the existing
  action locks, the deadline guard and the original-scope authority.

The expected proof is the current restored version with its own anchor. The expected effect is one
restore receipt with `id_proof_scope = identity_lifecycle_only`. For Lifestyle's actual
preserved-active state, the receipt is 0/0: no portal and no report restoration. The before/after
snapshot must be identical, so there are zero portal, report, schedule, contact or person writes.
No invitation or mail path is reachable from this call, and its writes are limited to
`MANAGED_CUSTOMER_LIFECYCLE_WRITE_TABLES`. No receipt is retagged.

The check runs after commit. A difference is evidence for owner review, not a rollback: exit 1 stops
the packet. A rerun after success selects nothing (the restored version has its receipt), so it
exits 1 with nothing done. Read the committed receipt back instead of retrying.

The full-contact no-argument operation stays separate and is not used here. The Milepost v3-first
order, both frozen senders, the exact original SH v2→v3 / ID v2→v3 events and the nonce-armed
UNKNOWN/readback/recovery protocol are unchanged by this step.

P9 runtime record, 2026-10-23 (Safeharbor owner and release-lead observations, not test results):
- **Pre-act exit 255.** The first standard-input launch used a plain `require` for the evidence
  library, which `lib/managed_customer_lifecycle.php` already loads with `require_once`. PHP stopped
  with a duplicate-declaration error, exit 255, before `try`, `db()`, the candidate query, the proof
  fetch or the act. It wrote no restore receipt, and the client's physical state was unchanged.
- **One corrected act.** The only change was that final include, now `require_once` above (loader
  correction SHA-256 `290030e8d206a627ab3468baed0def755fd8aaec23b3e86b7a9ac681e4f094e8`). Every other
  packet byte, the original authority, the one reservation and 30-second deadline, and every
  anchor and deadline check stayed the same. The single real act exited 0.
- **Effect.** Exactly one `identity_lifecycle_only` restore receipt; zero portal and zero report
  restoration surfaces; all five API-anchor checks true; the physical-state aggregate
  `2ab753d68a0ffed277729df47f4a001455ce7b520705e76c3e192c22140824a0` was unchanged.
- **Evidence** is in the Safeharbor visual root: the success readback `csd-sh-p9-success-readback.json`
  (prefix `f6189446`), the corrected-execution record (prefix `56af5c46`) and the loader-correction
  record (prefix `e8891799`). P9 is complete; do not run it again. Read the committed receipt back.

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

Migration `034_managed_customer_identity_lifecycle_restore.sql` makes one atomic ALTER of the
restore-receipt table, with no backfill and no old row rewritten:
- it extends `id_lifecycle_action` to `observed_active`;
- it makes `id_tenant_key`, `contact_version` and `recipient_sha256` nullable;
- it appends the A88 metadata (`id_proof_scope`, `id_initial_receipt_id`, `id_provider_tenant_slug`,
  `id_issuer_suite_tenant_id`, `id_projection_binding_sha256`, `id_projection_tenant_key`) and the
  five `id_restore_anchor_*` columns;
- it adds `ck_mc_restore_proof_scope`. A legacy NULL-scope row is exactly as before: `restored` only,
  non-NULL report-map key, contact version and recipient hash, every new column NULL. An identity
  row has a NULL carrier, contact version and recipient hash, writes no schedule or contact, and has
  every metadata and anchor fact non-NULL and type-valid. There is no `id_contact_mode`.

`fk_mc_restore_id_binding` is preserved and does not apply to a NULL carrier. The validator becomes
v3 (`...-restore-receipt-v3`). Its legacy branch and digest are v2's. Its identity branch checks the
optional map, the provider and issuer, and the anchor against local history, then the new digest
domain. The new scope test, the CHECK's identity branch and the final digest comparison are
NULL-safe (A136): a NULL refuses instead of passing as UNKNOWN. The same swap-guard pattern as 032
(`trg_mc_restore_identity_swap_insert`, exact body only) keeps every restore insert refused until
the postflight passes. The preflight accepts the completed 032 state, an interrupted 034 or a
completed 034, and pins the v3 body and the normalized CHECK. Validator pins are taken over the
stored `ACTION_STATEMENT` as MySQL keeps it (A154). There, a `'\n'` string literal is a real newline,
which the whitespace normalization removes. The v2 pin is the owner-measured engine value
(`fa6a7220…`); the v3 pin is derived the same way. The CHECK pin was verified against MySQL's
stored expression in the engine fixture and the production postflight (`5e63b58a…`).
`app/db/schema.sql` carries the same table and v3 validator. Apply it the same way:

```bash
bash deploy/deploy.sh --migrate app/db/migrations/034_managed_customer_identity_lifecycle_restore.sql
```

Never replay 024 or 032 after 034. 032's preflight recognizes only the v1/v2 validator markers, so
it refuses the v3 validator before any change; 024's behavior over the 034 table shape has not been
traced. New code before 034 refuses admitted restoration before any ID request. Old code after 034
is unchanged: its legacy inserts never name the new columns, so they stay NULL.

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

  For 034 (still in the same file, then fresh parity 024 + 032 + 034) it covers:
  - admitted restoration refused before 034 with no ID request;
  - DML-only refusal, double apply, legacy rows byte-exact and digest-valid;
  - wrong same-name swap guards, both interrupted points and a refused unknown state;
  - the exact identity facts and PHP digest accepted by the v3 validator, with carrier, contact,
    NULL, anchor, provider, issuer, map and digest-domain drift refused;
  - the NULL-safe legacy scope test and digest comparison;
  - the scheduled admitted identity-only restore of a queued restore→rename (current v4
    `observed_active`, anchor v3), which reopens the owned portal, leaves the owned-disabled report
    untouched, and replays;
  - the legacy contact restore still writing its unchanged receipt.
- `managed_customer_id_evidence_test.php` (schema 3): exact request bytes and headers, CLOSED25
  decode with NULL/direct/older anchors, and refusal of every other schema, purpose, scope, owned
  state, issuer, slug, anchor shape, contact field, 24-key alias and duplicate member.
- `managed_customer_lifecycle_test.php` (034 shape in SQLite): zero-write direct restore, replay and
  conflict, the queued rename and a later rename, missing/forged/inactive/gapped/older/foreign
  anchors and issuers, a newer inactive version, no admission, a withdrawn provider, the house
  issuer, an existing versus absent map, an owned-disabled report kept as-is, and the before-write
  stop.
- `managed_customer_lifecycle_scheduler_test.php`: the routing split and the pre-034 refusal; the
  reserved-attempt count (a refused reservation still uses the budget); and the post-fetch deadline
  deferral on both routes.
- A141/A143 at action: `managed_customer_lifecycle_test.php` covers a write-stage deadline deferral
  with zero writes, an admitted provider withdrawn after the scheduled lookup, a current admitted
  owner, and a lineage without admission. `managed_customer_lifecycle_mysql_test.php` covers, through
  the least-privilege runtime, a withdrawal during the proof fetch, a withdrawal committed while the
  action waits on the provider row lock (a real concurrent writer), and a deadline passing during the
  fetch; each leaves no portal, report or restore-receipt write.

No migration, configuration, cron installation, merge, deployment, portal
reactivation, or report delivery is performed by this source slice.
