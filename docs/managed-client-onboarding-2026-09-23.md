# Automatic managed-client onboarding — September 23, 2026

This slice connects Milepost client entry to ID identity and Safeharbor client,
portal, report-contact and lifecycle records for newly entitled MSP workspaces.
It does not complete the eight-app onboarding project. Production future-signup
enrollment remains off (`include_new_tenants=false`, ID `setup_tenant_ids=[1]`),
and existing-client automation remains pinned to Ventures customer
`d2cd5603-512e-46ab-b4c4-469563695b2e`. Never repurpose master customer
`4ebaeefa-b101-47f8-ac76-e49ab309d272`. Logbook external enrollment remains off.

## Ownership and execution

The root-owned ID coordinator refreshes the eligible workspace manifest before
each of three local registrations and three reconciliation steps. Registration
binds immutable issuer tenant, provider slug and owner subject to the local tenant
and owner. ID requires a verified active, non-revoked MSP owner, subscription-derived
access and effective Milepost/Safeharbor entitlements (including denial overrides).
Only workspaces created after the explicitly configured signup cutoff qualify.
Each replica has a maximum five-minute lease, rechecks its local owner, and denies
access when the lease expires. No runtime account may transfer/delete ownership.
Provider retirement is lease expiry/owner revocation, never registry reassignment.

Milepost preserves the frozen 12-field v2 event, permanent UUID/slug/contact,
sequential versions and transactional outboxes. External client slugs gain an
`m<issuer tenant id>-` namespace before the existing review/apply step. ID preserves
the receipt-bound projector and adds a separate provider-admitted lifecycle routine;
the 16-slot internal canary routine is unchanged. Safeharbor derives the exact
client, portal and report schedule scope from its provider-owned rows plus signed
ID evidence. Global report generation/delivery gates remain mandatory. Report
configuration alone is not a claim that a message was sent.

The coordinator's `managed_clients.enabled` switch defaults false. Its `register`
and `reconcile` maps contain fixed local commands for `id`, `milepost`, `safeharbor`.
Install `managed_client_onboarding.py` beside `suite_onboarding.py`; no shell command
or secret comes from a workspace manifest. Each command is bounded by the existing
90-second timeout and 1 MiB receipt limit; retries resume durable app records.
Identity lifecycle catches up at most ten events per client per tick. Status requires
matching UUID and source version from all three apps; stale status becomes provisioning.
Missing input, failed processing, human holds and verified client setup are distinct.
Verified Milepost/Safeharbor client requirements disappear without clearing later
app integrations, Microsoft consent, credentials or agent installation requirements.

No invoice, payment, fee, fabricated labor, invitation mail or report is created/sent
by this orchestration. ID retains suite-subscription authority. Coastmark financial
collection and the separate offline-payment fee slice are unaffected.

## Migration and release

Use the exact green default-branch commit. Before DDL: verify current deployed SHA,
archive protected application/config, trigger/routine-inclusive database and grants,
verify an actual scratch restore, and freeze only the affected application's writes
and relevant workers. Never stop shared Apache/MySQL. Retain the original rollback
backup and archived exact SQL blob. Do not remove a migration blocker by hand.

`managed_provider_migration.php` is the forward migration applier/verifier. It pins
the SQL and its catalog, compares full SHOW CREATE TABLE definitions (excluding only
AUTO_INCREMENT counters), ordered trigger names/owners/timing/events and normalized
bodies with quoted literals preserved, and the new routine body/security metadata.
Only exact catalogued DDL prefixes can resume. An altered object, unexpected trigger
or populated partial provider registry refuses application. Every DDL step is checked,
existing event/binding counts are preserved, and final replay makes no schema changes.
The Milepost temporary insert blocker survives an interrupted trigger replacement.
ID installs independent provider admission guards before changing either CHECK.

Keep the old foundation migration payloads frozen. Their older live verifiers do
not describe this successor state and must not be used to repair/replay an upgraded
database. Use this forward verifier and retain the older historical receipts/backups.
For ID fresh installs, apply the historical schema/migration chain first, then
`20260923_managed_providers.sql` through the pinned forward applier. Safeharbor's
canonical schema includes migration 030; its forward verifier recognizes that final state.

Production admission registries remain empty for this release. Installing the source
and migrations does not authorize future-MSP rollout. Preserve byte-identical protected
configuration, the Ventures scope, Lifestyle report evidence and Westy boundaries.
Source rollback leaves the additive schema/evidence in place and enrollment off;
never reverse immutable records with destructive SQL.

## Validation and remaining acceptance

The versioned cross-repository harness is in ID `tools/managed-client-acceptance`.
Coastline synthetic tests cover two same-name clients in different MSPs, exact contacts,
lost acknowledgement, replay, immutable ownership, revoked owners/users, subscription
and lease expiry, sequential suspension/restoration with human holds, and 18 clients
without operator canary configuration. All 34 DDL prefixes are tested for recovery,
with replay and deliberate schema/trigger drift refusal. Browser QA covers the new
setup states using synthetic receipts; live sign-in acceptance is read-only.

Logbook and Cloudline per-MSP integration registration and complete eight-app signup
acceptance are the next bounded slice. Future signup enrollment stays disabled until
that broader workflow passes. Release hashes, deployed evidence, backups and exact
worker scope belong in the release closeout record, not inferred from CI alone.

Safeharbor uses `deploy/managed_provider_migration.php` with
`app/db/migrations/030_managed_providers.sql`, then the existing exact artifact
installer. Follow `deploy/README.md` for its endpoint/database write freeze and
scratch restore. Disable and rebind the report scheduler through its protected
release bundle; verify unchanged config, recipient/schedule and prior delivery
proof before re-enabling. Do not run a report merely to prove onboarding.
