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
Three controlled Lifestyle canaries now exist, and the latest version of each
schedule is disabled. Archive 1, for key
`8west-lifestyle-weekly-canary-v1`, has SHA-256
`93bbdab3ac0d42e1da95fa93f8c393123749d7d1548f4a2afa62e95317a8b0ce`.
Its attempt ended terminal `uncertain` with outcome `graph_not_trustworthy`
and no provider HTTP status. Archive 2, for key
`8west-lifestyle-weekly-canary-v2`, has SHA-256
`10ed1d4cca108204705cdd9584d9a5bc824c1dbfcfbcfab26fceb2a6cb8562ae`.
Its attempt ended terminal `uncertain` after Microsoft Graph returned HTTP 404
with outcome `graph_send_rejected`. Neither uncertain attempt may be retried.

Archive 3, for key `8west-lifestyle-weekly-canary-v3`, has SHA-256
`d404de18f59d1546f77fd38ec0ff5071b3194124184f8c903c3d8f989381a909`.
Its one attempt returned Microsoft Graph HTTP 202 with outcome
`graph_accepted` and is recorded as `submitted`. Frankie separately confirmed
the report reached the recipient inbox on 2026-08-29. The received content was
for 8 West Lifestyle, covered `2026-08-17T07:00:00Z` through
`2026-08-24T07:00:00Z` (end exclusive), and was generated at
`2026-08-29T03:27:01Z`. That closes recipient confirmation for archive 3; it
does not authorize another send, reopen the disabled schedule, or prove which
mailbox submitted it.

The dedicated `reports@8westit.com` mailbox now exists and has no human
members. Human membership is not required for Safeharbor's app-only Microsoft
Graph submission, but this new sender still needs its own controlled canary:
archive 3 receipt does not by itself prove that `reports@8westit.com` was the
sender. Report generation and delivery remain off, `canary_only` remains true,
and no cron or systemd report scheduler is installed. The reviewed scheduler
bundle described below is source-only and default-off.

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
prepared through the new-customer lane uses an explicit
`milepost-customer:<uuid>` mapping to an ID tenant key. Safeharbor derives that
key from its exact active Milepost customer binding; the historical
`safeharbor-client:<id>` lane is refresh-only legacy compatibility: it requires
an existing client-scoped schedule history and cannot create a new logical
schedule.
Names, domains, local database ids, and email addresses are never new-customer
mapping inputs. Coastmark is not queried and no report action creates, changes,
approves, posts, sends, or pays an invoice.

The ID lookup is not part of cron, generation, or delivery. Only four explicit
operator commands load the client: no-write `plan-customer-from-id`,
`prepare-from-id`, historical `prepare-client-from-id`, and new-customer
`prepare-customer-from-id`. The client sends an exact
HMAC-authenticated POST to
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

`plan-customer-from-id` proves the active owner/admin, exact report target,
active permanent Milepost customer UUID, protected customer-to-ID mapping,
authenticated current contact, existing immutable contact scope/history, and
next disabled schedule version using database reads only. It prints the
customer and recipient SHA-256 digests, never either raw address or report
body. It performs no Graph request and cannot create a definition, binding,
contact snapshot, schedule, archive, delivery, or attempt. Because planning
deliberately takes no write lock, `prepare-customer-from-id` must still lock
and recheck every fact before committing immutable evidence.

`prepare-customer-from-id` performs the same target proof, then requires one
exact active `suite_customer_sync_bindings` row for that provider tenant and
client. It derives `milepost-customer:<uuid>` from that row and resolves only
the corresponding protected mapping to `ewid-t<id>`. An inactive, missing,
malformed, cross-tenant, or unconfigured customer is refused before any ID
request. This is the required lane for new MSP customer onboarding because the
Milepost UUID survives Safeharbor database replacement while a local client id
does not. Milepost customer `4ebaeefa-b101-47f8-ac76-e49ab309d272` is the
reserved 8 West IT master record and is refused in this customer lane; the MSP's
own weekly report continues through tenant-scoped `prepare-from-id`. After the
network lookup, schedule preparation locks and rechecks the same active UUID in
its database transaction with shared read locks in the same tenant-then-binding
order as customer sync. An inactivation cannot race a newly pinned contact,
opposite lock order cannot deadlock the two workflows, and report preparation
receives no update authority over Milepost-owned context.

The operator command resolves the active UUID once, passes that exact value to
the ID contact client, and passes the same value into the transaction recheck;
the contact client never performs a second binding lookup. After preparation,
an inactive Milepost binding does not change the immutable customer identity,
contact evidence, or Safeharbor schedule status. It never automatically disables
an existing report schedule. Safeharbor owns that operational switch: a human
owner/admin must inspect the workflow and append an explicit disabled schedule
version when appropriate.

Migration 017 adds a one-to-one immutable Safeharbor tenant → 8 West ID tenant
binding and append-only contact snapshots. The disabled schedule and its
snapshot are committed in one transaction. A repeat of the same current
contact version and exact schedule is a no-op; a version rollback, binding
change, or same-version/different-address response conflicts. The old manual
`prepare --recipient-email=...` command remains for compatibility, but new MSP
tenant onboarding uses `prepare-from-id`; customer onboarding uses
`prepare-customer-from-id`. Existing local-id schedules retain their immutable
historical evidence and do not migrate implicitly; the legacy local-id command
technically refuses a new schedule history. Once a client has a Milepost
managed-customer binding, neither the manual nor tenant-contact lane can create
a new non-master schedule for it; only the stable-customer command can. The
reserved 8 West IT master record remains on the tenant-contact lane.

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

Definition v1 predates append-only approved-time adjustments and must not
silently change its meaning. New generation therefore fails closed when the
tenant/client window contains an adjustment created by `generated_at` for an
otherwise applicable approved entry. Tenant, client, period, review-time, and
generated-time cutoffs are all exact. Existing archives remain byte-frozen.
A copied v1 contract stored under a later definition ordinal is unsupported
and is refused during both schedule preparation and active reads. A future
definition v2 must publish new reviewed contract bytes before archived reports
may count effective adjusted facts.

Persisted generation locks the exact tenant row before any schedule row or
metric read. Adjustment creation uses that same tenant-first serialization
point. If an adjustment wins, definition v1 waits, sees it, and refuses the
archive; if generation wins, the adjustment waits until the immutable archive
commits. Only after obtaining that lock does persisted generation read the
database UTC clock and establish `generated_at`. A supplied integration-test
clock may move that cutoff forward for deterministic future windows, never
backward before the lock. The no-write dry run intentionally takes no such
lock: it is a best-effort read-only preview and is not proof of what a later
archive will contain while operators are changing approved time.

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
    'customer_bindings' => [],
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
4. the exact `milepost-customer:<uuid> -> ewid-t<id>` contact mapping for new
   customers (or the historical local-id contact mapping for an existing
   schedule), plus the exact schedule key, tenant slug, local schedule client,
   and normalized recipient in the separate execution allowlists;
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

Plan and prepare without sending:

```bash
php app/db/manage_business_reports.php publish-definition \
  --tenant-slug=TENANT --actor-user-id=USER --reason='Publish reviewed v1'

php app/db/manage_business_reports.php prepare-from-id \
  --tenant-slug=TENANT --schedule-key=KEY --client-id=CLIENT \
  --definition-id=DEFINITION \
  --timezone=America/Los_Angeles --delivery-weekday=3 \
  --delivery-local-time=09:00:00 --canary=1 \
  --actor-user-id=USER --reason='Prepare controlled canary'

php app/db/manage_business_reports.php plan-customer-from-id \
  --tenant-slug=PROVIDER --schedule-key=KEY --client-id=CLIENT \
  --definition-id=DEFINITION \
  --timezone=America/Los_Angeles --delivery-weekday=3 \
  --delivery-local-time=09:00:00 --canary=1 \
  --actor-user-id=USER --reason='Plan exact customer canary'

php app/db/manage_business_reports.php prepare-customer-from-id \
  --tenant-slug=PROVIDER --schedule-key=KEY --client-id=CLIENT \
  --definition-id=DEFINITION \
  --timezone=America/Los_Angeles --delivery-weekday=3 \
  --delivery-local-time=09:00:00 --canary=1 \
  --actor-user-id=USER --reason='Prepare exact customer canary'
```

After protected allowlists contain only the exact schedule key, tenant,
client, and recipient, append the active version and perform a
no-write/no-network dry run. This preview is deliberately unlocked and
best-effort; persisted generation rechecks under the tenant serialization lock:

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
`id_report_contacts.tenant_bindings`, historical `client_bindings`, or stable
`customer_bindings` entry; an operator never passes or copies the address on
the command line. The CLI never echoes the recipient address, secret, or
report body. `submitted` must be
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

The release-ready operations bundle is deliberately separate from normal
deployment:

- `app/cron/run_business_reports.sh` refuses any user except `www-data`, pins
  the exact `ubuntu:www-data` 2750/0640 deployment metadata, and takes a shared
  persistent root-owned lock at
  `/var/lib/safeharbor-report-scheduler/business-reports-deploy.lock` before it
  reads runner code. It preserves PHP's failure status even if the final
  error-log call fails and records output under
  `safeharbor-business-reports`;
- `deploy/remote-install-safeharbor-app.sh` is staged by `deploy/deploy.sh` as
  an exact SHA-256-verified root-only file alongside the reviewed complete-app
  hasher. The local half refuses a dirty Git release and builds the app plus
  derived brand files from that exact commit's Git archive. The remote half refuses
  while the active cron name exists, refuses an exact legacy runner that
  predates locking, takes the exclusive side of the same persistent lock,
  verifies the incoming app in root-only staging against the clean source
  digest before live extraction, preserves the
  protected config, then records the complete post-cache-stamp artifact in a
  root-only current marker plus a uniquely named immutable record. It never
  installs or enables cron. A normal release must therefore stop scheduling
  before the existing non-atomic app extraction;
- `deploy/safeharbor-business-reports.cron` invokes only the wrapper every five
  minutes. The PHP schedule still decides whether a weekly period is due; and
- `deploy/manage-business-report-scheduler.sh` trusts only a root:root 0700
  control directory whose manager, cron template, complete-app hasher, and
  manifest are root:root 0600. The manifest binds the exact release, manager,
  template, hasher, clean source artifact, complete deployed artifact,
  immutable release marker, wrapper, and runner hashes. The manager holds the
  shared deployment lock while it re-hashes the whole app (excluding only the
  separately bound protected config). It also verifies the entire deployed
  tree is `ubuntu:www-data` with directories 2750 and ordinary files 0640,
  contains no symlinks or special files, and is not writable by `www-data`.

Do not run the manager from `/tmp`, a user-owned checkout, or a directory that
the deployment/runtime users can alter. From an exact clean reviewed release,
stage the three control files under `/root`, then create the manifest from the
deploy-created immutable release record without
copying or displaying protected config:

```bash
# Run locally from the exact clean reviewed release. Stream bytes directly into
# root-owned host files; never execute a user-owned /tmp copy with sudo.
RELEASE_SHA="$(git rev-parse HEAD)"
test "$RELEASE_SHA" = EXACT_40_HEX_RELEASE_SHA
CONTROL_DIR=/root/safeharbor-report-scheduler-$RELEASE_SHA
MANAGER_SHA="$(sha256sum deploy/manage-business-report-scheduler.sh)"
MANAGER_SHA="${MANAGER_SHA%% *}"
CRON_SHA="$(sha256sum deploy/safeharbor-business-reports.cron)"
CRON_SHA="${CRON_SHA%% *}"
HASHER_SHA="$(sha256sum deploy/hash-safeharbor-app-artifact.sh)"
HASHER_SHA="${HASHER_SHA%% *}"

ssh milepost-ec2 \
  "sudo install -d -o root -g root -m 0700 '$CONTROL_DIR'"
ssh milepost-ec2 \
  "sudo tee '$CONTROL_DIR/manage-business-report-scheduler.sh' >/dev/null \
   && sudo chown root:root '$CONTROL_DIR/manage-business-report-scheduler.sh' \
   && sudo chmod 0600 '$CONTROL_DIR/manage-business-report-scheduler.sh'" \
  < deploy/manage-business-report-scheduler.sh
ssh milepost-ec2 \
  "sudo tee '$CONTROL_DIR/safeharbor-business-reports.cron' >/dev/null \
   && sudo chown root:root '$CONTROL_DIR/safeharbor-business-reports.cron' \
   && sudo chmod 0600 '$CONTROL_DIR/safeharbor-business-reports.cron'" \
  < deploy/safeharbor-business-reports.cron
ssh milepost-ec2 \
  "sudo tee '$CONTROL_DIR/hash-safeharbor-app-artifact.sh' >/dev/null \
   && sudo chown root:root '$CONTROL_DIR/hash-safeharbor-app-artifact.sh' \
   && sudo chmod 0600 '$CONTROL_DIR/hash-safeharbor-app-artifact.sh'" \
  < deploy/hash-safeharbor-app-artifact.sh
ssh milepost-ec2 \
  "printf '%s  %s\n' '$MANAGER_SHA' \
      '$CONTROL_DIR/manage-business-report-scheduler.sh' \
      '$CRON_SHA' '$CONTROL_DIR/safeharbor-business-reports.cron' \
      '$HASHER_SHA' '$CONTROL_DIR/hash-safeharbor-app-artifact.sh' \
   | sudo sha256sum -c"

ssh milepost-ec2 "sudo bash -s -- '$CONTROL_DIR' '$RELEASE_SHA'" <<'REMOTE'
set -euo pipefail
dir="$1"
release="$2"
manager_sha="$(sha256sum "$dir/manage-business-report-scheduler.sh")"
cron_sha="$(sha256sum "$dir/safeharbor-business-reports.cron")"
hasher_sha="$(sha256sum "$dir/hash-safeharbor-app-artifact.sh")"
wrapper_sha="$(sha256sum /srv/8west/apps/safeharbor/current/cron/run_business_reports.sh)"
runner_sha="$(sha256sum /srv/8west/apps/safeharbor/current/cron/business_reports.php)"
marker=/var/lib/safeharbor-report-scheduler/releases/current-app-artifact.manifest
test "$(stat -c '%U:%G:%a' "$marker")" = root:root:600
marker_release="$(sed -n 's/^release_sha=//p' "$marker")"
source_artifact="$(sed -n 's/^source_artifact_sha256=//p' "$marker")"
deployed_artifact="$(sed -n 's/^deployed_artifact_sha256=//p' "$marker")"
marker_hasher="$(sed -n 's/^hasher_sha256=//p' "$marker")"
marker_sha="$(sha256sum "$marker")"
test "$marker_release" = "$release"
test "$marker_hasher" = "${hasher_sha%% *}"
unique="${marker%/*}/app-artifact.$marker_release.$deployed_artifact.manifest"
test "$(stat -c '%U:%G:%a' "$unique")" = root:root:600
cmp -s "$marker" "$unique"
printf '%s\n' \
  schema=safeharbor-business-report-scheduler-bundle-v2 \
  release_sha="$release" \
  manager_sha256="${manager_sha%% *}" \
  cron_sha256="${cron_sha%% *}" \
  hasher_sha256="${hasher_sha%% *}" \
  source_artifact_sha256="$source_artifact" \
  deployed_artifact_sha256="$deployed_artifact" \
  release_marker_sha256="${marker_sha%% *}" \
  wrapper_sha256="${wrapper_sha%% *}" \
  runner_sha256="${runner_sha%% *}" \
  > "$dir/scheduler-bundle.manifest"
chown root:root "$dir/scheduler-bundle.manifest"
chmod 0600 "$dir/scheduler-bundle.manifest"
REMOTE

ssh milepost-ec2 \
  "sudo bash '$CONTROL_DIR/manage-business-report-scheduler.sh' preflight \
   && sudo bash '$CONTROL_DIR/manage-business-report-scheduler.sh' install-disabled \
   && sudo bash '$CONTROL_DIR/manage-business-report-scheduler.sh' verify disabled"
```

These commands are production writes and still require normal release
authorization. They do not run a report or expose/parse protected config. The
manager hashes config only to bind evidence. Before activation, a new canary
must prove that `reports@8westit.com` itself received Graph acceptance and that
the separately addressed recipient confirmed the exact archive. The mailbox
may have no human members because sending is application-only, but that does
not substitute for the sender-specific canary.

Store the canary receipt outside Git in one root:root 0700 directory. Its
root:root 0600 activation file must contain exactly the following keys and real
evidence values; never use placeholders at activation:

```text
schema=safeharbor-business-report-scheduler-activation-v2
release_sha=EXACT_40_HEX_RELEASE_SHA
bundle_manifest_sha256=SHA256_OF_SCHEDULER_BUNDLE_MANIFEST
deployed_artifact_sha256=SHA256_OF_COMPLETE_DEPLOYED_APP_EXCLUDING_PROTECTED_CONFIG
release_marker_sha256=SHA256_OF_CURRENT_IMMUTABLE_RELEASE_MARKER
protected_config_sha256=SHA256_OF_CURRENT_PROTECTED_CONFIG
sender=reports@8westit.com
tenant_slug=EXACT_TENANT_SLUG
client_id=EXACT_SAFEHARBOR_CLIENT_ID
schedule_key=EXACT_SCHEDULE_KEY
recipient=EXACT_CONFIRMED_RECIPIENT
graph_status=202
graph_accepted_at=YYYY-MM-DDTHH:MM:SSZ
recipient_confirmation=confirmed
recipient_confirmed_at=YYYY-MM-DDTHH:MM:SSZ
archive_sha256=EXACT_CONFIRMED_ARCHIVE_SHA256
protected_gates_reviewed_at=YYYY-MM-DDTHH:MM:SSZ
```

The enable command repeats the exact tuple as operator intent and refuses any
artifact/config/release/tuple mismatch:

```bash
sudo bash manage-business-report-scheduler.sh enable \
  --activation-evidence /root/ROOT_ONLY_CANARY_RECORD/activation.env \
  --expect-tenant-slug EXACT_TENANT_SLUG \
  --expect-client-id EXACT_SAFEHARBOR_CLIENT_ID \
  --expect-schedule-key EXACT_SCHEDULE_KEY \
  --expect-recipient EXACT_CONFIRMED_RECIPIENT
sudo bash manage-business-report-scheduler.sh verify active
sudo journalctl -t safeharbor-business-reports --since '15 minutes ago'
```

Emergency disable always moves the active cron name and activation record to
unique root-only evidence paths, even if a dot-disabled file already exists.
This prevents new launches; it does **not** kill or wait for an in-flight PHP
process. Inspect the printed preservation paths and the deployment-lock holder
before changing application files:

```bash
sudo bash manage-business-report-scheduler.sh disable
sudo bash manage-business-report-scheduler.sh verify stopped
```

Every later code deploy must begin from `stopped`; the deploy helper then
refuses an active name or shared-lock holder. A failed source-artifact check
leaves the old marker in place and requires release recovery because extraction
is still non-atomic. After a successful deployment, every old bundle and
activation record is intentionally stale. Rebuild the root-only bundle manifest
from the new immutable marker, rerun preflight, create a new artifact/config-
bound canary record, install disabled, and only then consider a separately
authorized activation. To remove the reviewed disabled control file after stop
(quarantined evidence remains untouched):

```bash
sudo bash manage-business-report-scheduler.sh uninstall \
  --confirm-remove-exact-scheduler-files
sudo bash manage-business-report-scheduler.sh verify absent
```

Controlled rollout order:

1. deploy migration and code with both gates false and empty allowlists;
2. verify zero definitions, schedules, archives, deliveries, and attempts;
3. choose one real tenant/client, verify its 8 West ID tenant contact and
   protected stable-key binding, and record approval outside Git;
4. publish, run the no-write customer plan, prepare from ID, inspect the pinned
   key/version/digest, allowlist that exact schedule/tenant/client/recipient
   tuple, enable, and dry-run;
5. enable generation only, create one archive, inspect its exact hash and
   aggregate content, then leave delivery off;
6. enable delivery for that exact canary, run one pinned delivery, record
   provider submission evidence, and obtain separate recipient confirmation;
7. disable the schedule immediately on mismatch or uncertainty; and
8. only after the canary is accepted, install the reviewed scheduler entry and
   observe at least one scheduled period before expanding an allowlist.

## Current controlled-canary checkpoint

The earlier 8 West IT preparation remains immutable historical evidence. The
separate 8 West Lifestyle customer canaries completed migration, contact,
archive, one-attempt delivery, provider-acceptance, and archive-3 recipient
confirmation checks described above. Attempts 1 and 2 remain terminal
`uncertain` and must never be retried. Archive 3 remains terminal `submitted`;
the inbox confirmation is acceptance evidence, not permission to resubmit it.
The logical schedules are stopped, both report gates and the contact endpoints
are off, and no scheduler is installed.

A future dedicated-sender canary requires a new archive and a separately
reviewed authorization window. Its evidence must show both Graph acceptance
and recipient confirmation while pinning `reports@8westit.com` as the exact
sender. Only then may the source-only scheduler bundle be installed and
activated under the sequence above.

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
