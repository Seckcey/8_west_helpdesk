# Safeharbor business-report contract

Status: Phase 6 source contract. Deployment must remain dark until migration
013, exact runtime grants, and the production canary gates below are verified.

## Ownership and boundary

Safeharbor owns report definitions, schedule versions, metric computation,
immutable report archives, delivery leases, and delivery-attempt evidence.
Milepost is not queried. 8 West ID is not a report-recipient directory.
Coastmark is not queried and no report action creates, changes, approves,
posts, sends, or pays an invoice.

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

1. migration 013 and its fifteen triggers;
2. an immutable definition published by an active owner/admin;
3. a disabled schedule prepared for one exact tenant, client, recipient,
   timezone, weekday, local time, and canary flag;
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
```

The report subsystem needs `SELECT` on source and report tables; `INSERT` on
definition, schedule, and archive tables; `UPDATE` on the schedule table for
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

php app/db/manage_business_reports.php prepare \
  --tenant-slug=TENANT --schedule-key=KEY --client-id=CLIENT \
  --definition-id=DEFINITION --recipient-email=recipient@example.com \
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

The CLI never echoes the recipient address or report body. `submitted` must be
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
3. choose one real tenant/client/recipient and record approval outside Git;
4. publish, prepare, allowlist, enable, and dry-run;
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
An earlier code release safely ignores the additive tables, so schema rollback
is disaster-recovery-only. Retain the pre-migration database/application backup
and migration evidence; dropping these tables destroys approval and delivery
history and is not a routine rollback.
