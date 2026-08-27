# Safeharbor business-report contract

Status: Phase 6 is deployed dark through Safeharbor PR #46 / merge `209bb42`.
Migration 013 and exact runtime grants are live; both report gates and every
allowlist remain off/empty, with no definition, schedule, archive, delivery,
attempt, recipient, or scheduler. The 8 West ID contact client and
migration-017 immutable evidence tables are an implementation candidate, not a current
production claim. The production canary gates below remain.

## Ownership and boundary

Safeharbor owns report definitions, schedule versions, metric computation,
immutable report archives, delivery leases, and delivery-attempt evidence.
Milepost is not queried. 8 West ID owns the tenant's current weekly-report
admin contact and exposes one private, read-only, versioned snapshot to
Safeharbor during explicit operator onboarding. Safeharbor owns the pinned
address and contact-version evidence used by its schedule. Coastmark is not
queried and no report action creates, changes, approves, posts, sends, or pays
an invoice.

The ID lookup is not part of cron, generation, or delivery. Only
`manage_business_reports.php prepare-from-id` loads the client. It sends an
exact HMAC-authenticated POST to
`https://id.8westit.com/api/svc/report-contact.php`, refuses redirects and
alternate hosts/paths/ports, bounds the response, verifies the response HMAC,
and checks the request nonce, stable `ewid-t<id>` tenant key, exact tenant slug,
contact version, normalized address, and UTC generation time. Secrets, raw
response bodies, and addresses are never logged or printed by the CLI.

Migration 017 adds a one-to-one immutable Safeharbor tenant → 8 West ID tenant
binding and append-only contact snapshots. The disabled schedule and its
snapshot are committed in one transaction. A repeat of the same current
contact version and exact schedule is a no-op; a version rollback, binding
change, or same-version/different-address response conflicts. The old manual
`prepare --recipient-email=...` command remains for compatibility, but new MSP
onboarding uses `prepare-from-id` as the canonical path.

The weekly report contains aggregate operational facts only. It does not read
or render ticket subjects, message bodies, contacts, attachments, technician
notes, review notes, rates, taxes, cents, invoice state, endpoint controls, or
AI output.

## Definition version 1

`weekly-client-service-summary` is an immutable report definition. A schedule
pins one exact definition version and one Safeharbor tenant/client pair. A
logical schedule key can never be reassigned to another client or definition,
or moved to another reporting timezone; use a new key instead.

The window is a local-calendar Monday 00:00 through the following Monday
00:00, converted to UTC and stored start-inclusive/end-exclusive. The runner
generates the oldest missing due period for each active schedule, one period
per invocation, so an outage does not silently skip a week. A later active
version uses its creation time as the due-time catch-up floor: periods whose
scheduled delivery time already passed while the schedule was disabled are not
backfilled. Enabling before a period's scheduled due time may still generate
that previous complete Monday-Sunday window when it becomes due.

Version 1 reports:

- tickets opened and resolved in the window, excluding merged source stubs;
- first technician response for tickets opened in the window, decided only
  from messages at or before `generated_at` and excluding both sides of merged
  histories;
- first-response service-goal attainment from each ticket's immutable
  `service_goal_target_id`/`sla_due_at` snapshot, with the versioned eligible
  denominator, undecided count, and legacy-unversioned exclusions explicit;
- approved, billable technician minutes worked in the window and reviewed by
  `generated_at`, classified as operational approval evidence rather than a
  statement of export or invoice status; and
- CSAT surveys created in the window, with only responses received by
  `generated_at` included in response count and average.

Every query binds the exact Safeharbor tenant and client. Metrics JSON is
encoded once, stored as exact `LONGTEXT` bytes under `JSON_VALID`, and hashed
together with the exact text report. MySQL triggers verify the hash, report
scope, definition, client snapshot, period, and generated timestamp before an
archive can be inserted. Definitions, schedules, and archives are insert-only.

## Delivery truth and state machine

Each archive creates one `pending` delivery and permits one attempt only.
Delivery is separate from the existing retrying `mail_queue`.

```text
pending -> sending -> submitted
                   \-> uncertain
```

The `sending` transition and attempt row are the irreversible send boundary.
Only the exact tuple `submitted`, HTTP `202`, and `graph_accepted` may become
`submitted`. That means Microsoft Graph accepted the submission; it is not
proof that the recipient received or read it. A timeout, exception, malformed
result, non-202 response, or expired send lease becomes terminal `uncertain`.
Safeharbor never retries an uncertain attempt automatically.

Revoked `pending` rows are omitted from scheduled delivery. An expired
`sending` row is different because the send boundary was already crossed: its
archive hash and tenant/client scope are reverified, then it is row-locked and
terminalized as `uncertain` without a network request even if current
allowlists or the schedule version were removed afterward.

Before acquiring a lease, delivery re-verifies the exact archive hash and that
the archived schedule version is still the latest active version. Appending a
disable or reconfiguration therefore revokes any old pending delivery. The
email subject and body come from archived facts, not a later client rename.

## Default-off gates

Fresh and production configuration must begin with:

```php
'id_report_contacts' => [
    'enabled' => false,
    'endpoint' => 'https://id.8westit.com/api/svc/report-contact.php',
    'hmac_secret' => '',
    'tenant_bindings' => [],
    'timeout_seconds' => 10,
],

'business_reports' => [
    'generation_enabled' => false,
    'delivery_enabled' => false,
    'canary_only' => true,
    'tenant_slugs' => [],
    'client_keys' => [],
    'recipient_emails' => [],
    'lease_seconds' => 120,
],
```

Activation requires all of these independently:

1. migration 013 with its fifteen triggers, plus migration 017 with its two
   evidence tables and six separate triggers;
2. an immutable definition published by an active owner/admin;
3. a disabled schedule prepared from the authenticated 8 West ID tenant
   contact for one exact tenant, client, timezone, weekday, local time, and
   canary flag;
4. the exact tenant slug, `safeharbor-client:<id>`, and normalized recipient in
   protected configuration allowlists;
5. an explicit appended `active` schedule version;
6. `generation_enabled=true` before archive generation; and
7. `delivery_enabled=true` plus existing protected Microsoft Graph mail
   configuration before any send boundary.

`canary_only=true` refuses every non-canary schedule even if all other values
are allowlisted.

## Operator workflow

Run migrations only through the trigger-capable operator after a protected,
verified backup:

```bash
sudo mysql safeharbor < app/db/migrations/013_business_reports.sql
sudo mysql safeharbor < app/db/migrations/017_id_report_contact_evidence.sql
```

Migration 017 validates the exact candidate column/default, visible-index,
foreign-key, and enforced-check shape on every replay. It installs six
temporary fail-closed guards before replacing any permanent 017 trigger, so an
interrupted run blocks insert/update/delete on both evidence tables until an
exact replay restores the permanent guards and removes the swaps. The snapshot
insert trigger locks the immutable tenant-binding row before reading contact
history; direct concurrent writers therefore cannot commit one contact version
with different recipients.

The report subsystem needs `SELECT` on source and report tables; `INSERT` on
definition, schedule, archive, ID tenant-binding, and ID contact-snapshot
tables; `UPDATE` on the schedule, ID tenant-binding, and ID contact-snapshot
tables only for
MySQL locking reads while database triggers still reject row mutation; and
`INSERT,UPDATE` on delivery/attempt tables. It needs no report-table `DELETE`,
`ALTER`, `DROP`, or `TRIGGER` authority. Production currently shares one
web/cron identity, so its unrelated
operational deletes are limited to the eight tables inventoried in
`deploy/README.md`; it receives no `DELETE` on any report or history table.
Migration tests exercise the report lifecycle through a narrower temporary
identity with exactly the report-table mutation boundary.

Publish and prepare without sending:

```bash
php app/db/manage_business_reports.php publish-definition \
  --tenant-slug=TENANT --actor-user-id=USER --reason='Publish reviewed v1'

php app/db/manage_business_reports.php prepare-from-id \
  --tenant-slug=TENANT --schedule-key=KEY --client-id=CLIENT \
  --definition-id=DEFINITION \
  --timezone=America/Los_Angeles --delivery-weekday=3 \
  --delivery-local-time=09:00:00 --canary=1 \
  --actor-user-id=USER --reason='Prepare controlled canary'
```

After protected allowlists are exact, append the active version and perform a
no-write/no-network dry run:

```bash
php app/db/manage_business_reports.php enable \
  --tenant-slug=TENANT --schedule-key=KEY --expected-version=1 \
  --actor-user-id=USER --reason='Enable controlled canary'

php app/db/run_business_report.php --tenant-slug=TENANT \
  --schedule-key=KEY --dry-run
```

Generation writes the immutable archive but performs no network request:

```bash
php app/db/run_business_report.php --tenant-slug=TENANT \
  --schedule-key=KEY --generate
```

Delivery additionally pins the operator-observed archive id and content hash:

```bash
php app/db/run_business_report.php --tenant-slug=TENANT \
  --schedule-key=KEY --archive-id=ARCHIVE_ID \
  --content-sha256=ARCHIVE_SHA256 --deliver
```

The canonical command obtains the address from the exact protected
`id_report_contacts.tenant_bindings` entry; an operator never passes or copies
the address on the command line. The CLI never echoes the recipient address,
secret, or report body. `submitted` must be
reported to operators as provider acceptance only. Recipient confirmation is a
separate acceptance fact.

## Scheduler and canary sequence

`app/cron/business_reports.php` is bounded and protected by a MySQL advisory
lock. Generation and pending/expired delivery enumeration are independent, so
an archive created while delivery is off can be handled after an explicit
delivery gate change. Installing a cron entry is a separate production action;
the repository deploy does not edit crontab.

Controlled rollout order:

1. deploy migration and code with both gates false and empty allowlists;
2. verify zero definitions, schedules, archives, deliveries, and attempts;
3. choose one real tenant/client, verify its 8 West ID tenant contact and
   protected stable-key binding, and record approval outside Git;
4. publish, prepare from ID, inspect the pinned key/version/digest, allowlist,
   enable, and dry-run;
5. enable generation only, create one archive, inspect its exact hash and
   aggregate content, then leave delivery off;
6. enable delivery for that exact canary, run one pinned delivery, record
   provider submission evidence, and obtain separate recipient confirmation;
7. disable the schedule immediately on mismatch or uncertainty; and
8. only after the canary is accepted, install the reviewed scheduler entry and
   observe at least one scheduled period before expanding an allowlist.

## Rollback and retention

The fastest stop is protected configuration with both gates false, followed by
an appended disabled schedule version. Do not delete or rewrite report history.
An earlier code release safely ignores migration 017's two additive tables and
migration 013's five additive tables, so schema rollback is
disaster-recovery-only. Retain the pre-migration database/application backup
and migration evidence; dropping these tables destroys contact, approval, and
delivery history and is not a routine rollback.

The protected production rollback record is
`/srv/8west/backups/safeharbor/20260827T003557Z-pre-phase6-business-reports`.
The application and trigger-inclusive database archives were verified before
migration and remain root-only. Code-first rollback must deploy a prior exact
merged release through the normal config-excluding mechanism while the gates
remain false. Never unpack the application archive wholesale over `current`:
it contains the protected config and four potentially credential-bearing backup
files later quarantined from the live tree without reading them. Any
disaster-recovery extraction requires an exact code allowlist that excludes
`config/config.php` and every
`config.php.bak*`. Database restoration is disaster recovery only because later
Safeharbor writes must not be discarded casually.
