# Safeharbor business-report contract

Status: Phase 6's base and tenant-scoped 8 West IT evidence remain live through
the earlier releases described below. The client-scoped lane is now also live
through Safeharbor PR #68 / exact merge
`7bf63edb9bc0583cf865124b45f002d0717dcab9`; exact-main Validate run
`33226867525` passed before that commit was deployed. Migration 019 was applied
and replayed from exact Git bytes whose SHA-256 is
`5dceec1c10a8e79515da9a3296e5ce0388fcc1e5e2206759e7ac1cadf77efbfe`.
Production has all three migration-019 tables, twelve protected permanent
guards, and zero temporary swap guards. The root-only rollback record is
`/srv/8west/backups/safeharbor/20260829T015329Z-pre-client-report-contact-019`;
its release-receipt SHA-256 is
`a044d06c949c473f1fec13fe76b0fd27d4c37486dd2c365de874d83932923a97`
and its final evidence-manifest SHA-256 is
`24a07bcb4ea347eb65831348df1ac309e7c662d61cf717c27b5729ba5b6b4f6f`.

The controlled 8 West Lifestyle customer lane binds Safeharbor client 14 to
the exact 8 West ID tenant key and has one immutable client-contact snapshot.
The contact lookup endpoints were turned back off after preparation. The only
recipient value recorded here is its SHA-256 digest,
`4f57f85e0af372af6b3b09bf48be8961a23fa0fc371f36a9b2d3a54ee4998ef8`.
Schedule key `8west-lifestyle-weekly-canary-v1` has immutable versions 1
disabled, 2 active, and 3 disabled; version 3 is latest, so the logical
schedule is stopped. One archive was generated and independently recomputed at
SHA-256
`93bbdab3ac0d42e1da95fa93f8c393123749d7d1548f4a2afa62e95317a8b0ce`.
The one delivery attempt ended terminal `uncertain` with outcome
`graph_not_trustworthy` and no provider HTTP status. It must never be retried.
There is no provider-acceptance or inbox-delivery claim. Generation and
delivery are off and no cron or systemd report scheduler is installed.

Root-only Lifestyle canary evidence is at
`/srv/8west/backups/safeharbor/20260829T020451Z-pre-lifestyle-report-canary`.
The canary receipt SHA-256 is
`86fd9852e7defbe1b809895a6bc2529e9fae9f9acea3f1b274b347fa4b524492`.

## Ownership and boundary

Safeharbor owns report definitions, schedule versions, metric computation,
immutable report archives, delivery leases, and delivery-attempt evidence.
Milepost is not queried. 8 West ID owns the tenant's current weekly-report
admin contact and exposes one private, read-only, versioned snapshot to
Safeharbor during explicit operator onboarding. Safeharbor owns the pinned
address and contact-version evidence used by its schedule. A customer schedule
uses an explicit stable `safeharbor-client:<id>` mapping to an ID tenant key;
names, domains, and email addresses are never mapping inputs. Coastmark is not
queried and no report action creates, changes, approves, posts, sends, or pays
an invoice.

The ID lookup is not part of cron, generation, or delivery. Only the explicit
operator commands `prepare-from-id` and `prepare-client-from-id` load the
client. They send an
exact HMAC-authenticated POST to
`https://id.8westit.com/api/svc/report-contact.php`, refuses redirects and
alternate hosts/paths/ports, bounds the response, verifies the response HMAC,
and checks the request nonce, stable `ewid-t<id>` tenant key, exact tenant slug,
contact version, normalized address, and UTC generation time. Secrets, raw
response bodies, and addresses are never logged or printed by the CLI.

`prepare-client-from-id` first proves the active owner/admin, provider tenant,
exact client, and report-definition reach in Safeharbor. It then resolves only
the exact protected `safeharbor-client:<id> -> ewid-t<id>` entry. The returned
ID tenant slug is authenticated output; Safeharbor does not guess it from its
local tenant or client.

Migration 017 adds a one-to-one immutable Safeharbor tenant → 8 West ID tenant
binding and append-only contact snapshots. The disabled schedule and its
snapshot are committed in one transaction. A repeat of the same current
contact version and exact schedule is a no-op; a version rollback, binding
change, or same-version/different-address response conflicts. The old manual
`prepare --recipient-email=...` command remains for compatibility, but new MSP
tenant onboarding uses `prepare-from-id`; customer onboarding uses
`prepare-client-from-id`.

Migration 019 adds separate client-binding and client-contact-snapshot tables
plus one immutable `(tenant_id, schedule_key)` contact-scope registry. The
registry is pinned as `MANUAL`, `TENANT`, or `CLIENT` before schedule version
1 and every enable, disable, and reconfiguration version inherits it. Migration
backfills existing histories and refuses missing, orphaned, or mixed scope. It
also refuses a latest active tenant- or client-scoped schedule unless its
recipient matches the newest evidence-bearing version; client scope must match
the evidence client too. Future activation is enforced by both the application
and database trigger, and active/due runtime reads fail closed on any mismatch.
The client binding is immutable and one-to-one; symmetric locking guards stop
an ID tenant key from being claimed in both tenant and client lanes, including
concurrent reverse-order inserts. The disabled schedule and client snapshot
commit atomically. Exact replay is a no-op; a client or ID-tenant rebind,
contact-scope change, contact-version rollback, or same-version/different-
address response conflicts. Migration 017's tables, rows, commands, and
internal 8 West IT canary remain unchanged; migration 019 strengthens the
shared tenant-binding and tenant-snapshot insert guards without converting it.

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

The Graph adapter returns a deliberately small evidence tuple. That tuple
never contains a response body, recipient/sender address, access token, client
credential, or exception text:

- `graph_accepted` carries HTTP `202` and is the only submitted outcome;
- `graph_send_rejected` carries the definite non-202 `sendMail` status;
- `graph_token_rejected` carries the definite non-200 token-endpoint status;
- `graph_token_invalid_response` carries HTTP `200` when the token response is
  structurally unusable; and
- `graph_send_transport_error`, `graph_send_unknown_response`,
  `graph_token_transport_error`, `graph_token_unknown_response`, and
  `graph_payload_invalid` carry HTTP `NULL`.

The report finalizer independently allowlists those exact combinations before
writing immutable evidence. Any malformed or unapproved result becomes
`invalid_transport_outcome` with HTTP `NULL`. The ordinary retrying
`mail_queue` keeps its existing boolean success/failure contract; when Graph
fails, its diagnostic is now the same sanitized category and optional known
HTTP status rather than provider-body text.

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
    'client_bindings' => [],
    'timeout_seconds' => 10,
],

'business_reports' => [
    'generation_enabled' => false,
    'delivery_enabled' => false,
    'canary_only' => true,
    'graph_sender' => '',
    'schedule_keys' => [],
    'tenant_slugs' => [],
    'client_keys' => [],
    'recipient_emails' => [],
    'lease_seconds' => 120,
],
```

Activation requires all of these independently:

1. migration 013 with its fifteen triggers, plus migration 017 with its two
   tenant evidence tables and six separate triggers; customer schedules also
   require migration 019's two client evidence tables, one immutable scope
   registry, and its twelve protected trigger replacements;
2. an immutable definition published by an active owner/admin;
3. a disabled schedule prepared from the authenticated 8 West ID tenant
   contact for one exact tenant, client, timezone, weekday, local time, and
   canary flag;
4. the exact schedule key, tenant slug, `safeharbor-client:<id>`, and
   normalized recipient in protected configuration allowlists;
5. an explicit appended `active` schedule version;
6. `generation_enabled=true` before archive generation; and
7. a normalized, lowercase `business_reports.graph_sender` for a dedicated
   report mailbox plus existing protected Microsoft Graph credentials; and
8. `delivery_enabled=true` before any send boundary.

Report delivery reuses the protected Graph credentials in memory but replaces
the sender with `business_reports.graph_sender`. It never falls back to
`mail.graph.sender`, so configuring report delivery does not change the
ordinary help-desk sender used by ticket mail and inbound Graph polling. A
missing or malformed report sender is refused before a delivery lease or
attempt row is created.

`canary_only=true` refuses every non-canary schedule even if all other values
are allowlisted. The exact schedule-key allowlist prevents another canary for
the same tenant, client, and recipient from being picked up merely because it
shares those three broader routing values.

The present 8 West IT canary is deliberately beyond the fresh-install state:
its exact schedule, tenant, client, and recipient allowlists are installed,
while `generation_enabled=false` and `delivery_enabled=false` still prevent
archive creation and email submission. The contact lookup gates were turned
off after the one redacted onboarding fetch; cron does not need or use them.

## Operator workflow

Run migrations only through the trigger-capable operator after a protected,
verified backup:

```bash
sudo mysql safeharbor < app/db/migrations/013_business_reports.sql
sudo mysql safeharbor < app/db/migrations/017_id_report_contact_evidence.sql
sudo mysql safeharbor < app/db/migrations/019_client_report_contact_evidence.sql
```

Migration 017 validates the exact candidate column/default, visible-index,
foreign-key, and enforced-check shape on every replay. It installs six
temporary fail-closed guards before replacing any permanent 017 trigger, so an
interrupted run blocks insert/update/delete on both evidence tables until an
exact replay restores the permanent guards and removes the swaps. The snapshot
insert trigger locks the immutable tenant-binding row before reading contact
history; direct concurrent writers therefore cannot commit one contact version
with different recipients.

Migration 019 applies the same replay discipline across the scope registry,
schedule insert boundary, both tenant evidence insert boundaries, and the two
client-scoped tables. Twelve temporary swap guards remain in place while its
twelve permanent scope, actor, reach, version, and immutability guards are
replaced. Their exact names, tables, events, timing, and fail-closed signal are
verified before replacement. An interrupted run stays fail-closed until an
exact replay.

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
Client-scoped onboarding additionally needs `SELECT`, `INSERT`, and locking-
read `UPDATE` on the two client evidence tables and the contact-scope registry;
their permanent triggers still reject row updates and deletes.

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

php app/db/manage_business_reports.php prepare-client-from-id \
  --tenant-slug=PROVIDER --schedule-key=KEY --client-id=CLIENT \
  --definition-id=DEFINITION \
  --timezone=America/Los_Angeles --delivery-weekday=3 \
  --delivery-local-time=09:00:00 --canary=1 \
  --actor-user-id=USER --reason='Prepare exact customer canary'
```

After protected allowlists contain only the exact schedule key, tenant,
client, and recipient, append the active version and perform a
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

The canonical commands obtain the address from an exact protected
`id_report_contacts.tenant_bindings` or `client_bindings` entry; an operator
never passes or copies the address on the command line. The CLI never echoes
the recipient address, secret, or report body. `submitted` must be
reported to operators as provider acceptance only. Recipient confirmation is a
separate acceptance fact.

## Scheduler and canary sequence

`app/cron/business_reports.php` is bounded and protected by a MySQL advisory
lock. Enumeration skips every active schedule whose exact schedule key,
tenant, client, recipient, or canary flag is outside protected configuration.
Generation and pending/expired delivery enumeration are independent, so an
archive created while delivery is off can be handled after an explicit
delivery gate change. Installing a cron entry is a separate production action;
the repository deploy does not edit crontab.

Controlled rollout order:

1. deploy migration and code with both gates false and empty allowlists;
2. verify zero definitions, schedules, archives, deliveries, and attempts;
3. choose one real tenant/client, verify its 8 West ID tenant contact and
   protected stable-key binding, and record approval outside Git;
4. publish, prepare from ID, inspect the pinned key/version/digest, allowlist
   that exact schedule/tenant/client/recipient tuple, enable, and dry-run;
5. enable generation only, create one archive, inspect its exact hash and
   aggregate content, then leave delivery off;
6. enable delivery for that exact canary, run one pinned delivery, record
   provider submission evidence, and obtain separate recipient confirmation;
7. disable the schedule immediately on mismatch or uncertainty; and
8. only after the canary is accepted, install the reviewed scheduler entry and
   observe at least one scheduled period before expanding an allowlist.

## Current controlled-canary checkpoint

The earlier 8 West IT preparation remains immutable historical evidence. The
separate 8 West Lifestyle customer canary completed the migration, contact,
archive, and one-attempt delivery safety checks described in the status above.
Its logical schedule is stopped by latest version 3, both report gates and the
contact endpoints are off, and no scheduler exists.

The terminal delivery record is deliberate evidence, not a retry queue. The
send boundary was crossed, but there is no trustworthy Graph result and no
provider HTTP status, so the record is `uncertain` with outcome
`graph_not_trustworthy`. Never resubmit that archive automatically or manually
as though the first attempt were known not to have happened. A future canary
requires a new archive and a separately reviewed authorization window. Even an
exact Graph 202 would prove provider acceptance only; this closeout has neither
provider acceptance nor recipient confirmation.

## Rollback and retention

The fastest stop is protected configuration with both gates false, followed by
an appended disabled schedule version. Do not delete or rewrite report history.
An earlier code release safely ignores migration 017's two additive tables,
migration 019's three additive tables, and migration 013's five additive tables,
so schema rollback is
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

For the schedule-key release, use the newer verified rollback record at
`/srv/8west/backups/safeharbor/20260827T143419Z-pre-report-schedule-key-gate`.
Its application archive excludes the protected config, its trigger-inclusive
database dump passed a scratch restore, and its root-only receipt records the
exact config/code/vhost hashes. Roll code and protected config back together
only while generation and delivery remain false; do not restore its database
over later help-desk writes except for disaster recovery.
