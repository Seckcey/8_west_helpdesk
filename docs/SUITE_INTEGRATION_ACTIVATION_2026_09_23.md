# Automatic MSP integration registration — 23 September 2026

Current development handoff: [IT 365 stopping point](IT_365_STOPPING_POINT_2026_09_24.md). This file retains the exact activation release evidence.

An eligible external MSP enters its client and contact in Milepost. The local
ID coordinator reconciles canonical identity and Safeharbor admission before
creating Cloudline customers and Logbook source registrations. No operator
credential edits are required for each new MSP. This release has no migration.

## Authority and isolation

Every app command receives a fresh ID eligibility check. Registration binds
the immutable issuer tenant, app-local organization, slug and current owner.
Stable independent random source keys remain in root-only storage, separate
from UI receipts. Root-owned read-only registrations expire after five minutes
unless current eligibility renews them; each source also checks its local owner.
Exact UUID lists limit export scope. Existing internal source configuration is
preserved. An unavailable external registration never falls back to another
tenant's credential.

Cloudline preserves human names, archives and existing Microsoft authority.
Inactive clients lose active export scope; restoring a client does not undo a
human archive or revoked membership. Logbook creates documentation customers
through its existing restricted-role/RLS import path. Only a newly created,
exact canonical customer can receive an automatic confirmed link from the
registration's prior identity evidence. Existing inferred, rejected and human
pairings stay unchanged. This does not edit human documentation or authorize
Microsoft consent.

## Installation and activation

Install all five reviewed app releases before enabling the coordinator. Install
the three ID Python modules under `/usr/local/lib/8west-suite-onboarding/`.
Install `deploy/suite-integrations.tmpfiles.conf` from ID using systemd-tmpfiles
before recreating app containers. Verify Cloudline's application group is 82
and Logbook's is 33. Bind the app-specific registration directories read-only;
Logbook app, queue and scheduler all require the Logbook directory.

Use ID's reviewed `deploy/suite-onboarding.example.json` command map, preserving
the host's explicit existing tenant list and state directory. Enable
`include_new_tenants`, `managed_clients.enabled` and `integrations.enabled`.
Enable ID's `suite_workspace_onboarding.enabled` and Logbook's
`EWID_MSP_WORKSPACE_ONBOARDING`; retain the established signup cutoff and
existing-client scope. Existing-client activation remains Ventures-only.

The coordinator timer and existing ID-to-Milepost provisioner must both run:
ID correctly withholds Milepost entitlement until that provisioner acknowledges
the workspace. Receipt status distinguishes provisioning, failed admission,
human holds and verified clients. Missing agent installation, Microsoft consent
or customer credentials remain customer input.

## Verification and rollback

Synthetic acceptance under ID's `tools/managed-client-acceptance/` covers two
MSPs, actual issuer export, signed Milepost-to-Logbook HTTP, repeat delivery,
stable source keys, exact UUID ownership, human holds, expired/writable
registration denial and revoked owners. The preceding managed-client harness
covers interrupted delivery, trial/subscription/lifecycle changes, revoked
users and absence of invoices, payments, labor and outgoing messages.

Production acceptance requires exact source/image identity, protected backups
with scratch restores, effective enabled flags, worker execution, public and
signed-in checks. Record the actual deployed revisions and evidence in the
release closeout. This source document alone is not production proof.

Rollback uses the protected pre-release app/config/image snapshots and restores
the matching coordinator modules. Preserve stable key storage, customer data,
Microsoft encryption keys, existing cron scope and previous evidence. Do not
restart shared Apache or production databases as part of this code release.

## Deployed and enabled — 24 September 2026, 03:59 UTC (23 September Pacific)

Frankie explicitly authorized Cloudline and Logbook implementation and production activation, superseding the earlier future-signup-off gate. All five application changes are merged and deployed. New eligible MSP signup enrollment, managed clients, per-MSP integration registration and Logbook external-MSP onboarding are **on**. The UTC signup cutoff is **2026-09-24 03:56:58**. Historical customer enrollment remains explicitly scoped to the existing house/Ventures lane.

| App | Deployed revision | PR | Exact default-branch CI |
|---|---|---|---|
| ID | `ab3553594dc18d1d8f1c2ee586ff639e2812fcbe` | [131](https://github.com/Seckcey/8_west_id/pull/131) | [35952647766](https://github.com/Seckcey/8_west_id/actions/runs/35952647766) |
| Milepost | `a06988d38871442f3633d0d7cd90ff9d6c72f7d3` | [525](https://github.com/Seckcey/8westit_webapp/pull/525) | [35952560862](https://github.com/Seckcey/8westit_webapp/actions/runs/35952560862) |
| Safeharbor | `d90a608886deed9f8d3768750cd8029f41427fa5` | [141](https://github.com/Seckcey/8_west_helpdesk/pull/141) | [35952651164](https://github.com/Seckcey/8_west_helpdesk/actions/runs/35952651164) |
| Logbook | `befa60acd192524ae6bf051654fe0cf8258a70e8` | [104](https://github.com/Seckcey/8_west_logbook/pull/104) | [35952817821](https://github.com/Seckcey/8_west_logbook/actions/runs/35952817821) |
| Cloudline | `a301c6ebd604a88e51b6323a01d33fab01049282` | [229](https://github.com/Seckcey/8_west_cloudline/pull/229) | [35952698084](https://github.com/Seckcey/8_west_cloudline/actions/runs/35952698084) |

Milepost's protected audit `35952986068` and deployment `35953079351` passed. All 335 deployable Milepost files, all 299 packaged ID files and the three installed coordinator modules matched the reviewed artifacts. Safeharbor source artifact SHA-256 is `1631f22ebbd5e9fe160af423acc07627b328d29c08746e5be065b6109eb09c4b`; deployed artifact is `185c26550d3eac51d8ef40d61f11aba251c774e1f956345b4c5d59766acdd573`.

### Runtime and recovery

Production remains `ssh milepost-ec2`, host `ip-172-31-31-195`.

| Artifact | SHA-256 image identity |
|---|---|
| Cloudline | `de4b6e3c20ef4df3c77e1faaffd067f183447ae8969c6429813fa2c10ea01441` |
| Logbook app, queue and scheduler | `ecee9117a05c9286396dcdbd63069314ba3376e68804058b149c6b4dc8a03f52` |
| Logbook web | `f014ccf5d1db1cef427d0f1fe6abd8130fc90c01e84a388963ac5f42ea04ee1f` |

- MP/ID/Safeharbor protected backups and successful actual scratch restores: `/srv/8west/backups/suite-integration-activation-20260924T034209Z/{milepost,id,safeharbor}`.
- Cloudline backup and successful actual scratch restore: `/srv/8west/backups/mission-control/suite-onboarding-20260924T035247Z`. Database archive SHA-256: `d4995e510e8d51eb1b2120feca0a27473f60e1c830a663606566b4aac8b07feb`.
- Logbook backup and successful actual scratch restore: `/srv/8west/backups/logbook/suite-onboarding-20260924T035550Z`. Database dump SHA-256: `cd83f4236212639ac853f1d122933a3a4c831203ce8eecace68956ee9d6f8a32`.
- Previous source/config/images remain rollback material. ID's physical rollback is `/srv/8west/apps/ewid/rollback-20260924T034926Z-ab3553594dc1`.
- No migration ran. Logbook retains 29 applied migrations and zero pending; `logbook_app` has no superuser, RLS bypass or table ownership. All six services are healthy. PostgreSQL and ClamAV container identities are unchanged. Cloudline's database container, migrations, runtime and Microsoft encryption material are unchanged.
- Registration mounts are read-only. Root-owned tmpfiles configuration recreates directories on boot. Stable source secrets remain separate from UI receipts and are never recorded here.

### Acceptance

Logbook exact-main CI passed **1,167 tests / 7,529 assertions**. Cloudline's exact-main workflow passed its framework, MariaDB tenancy/migration, production image and release guard checks. Its framework summary had 1,974 passed, 14 warnings and 5 skips (13,617 assertions); warnings/skips are not claimed as passed tests. The new provisioner tests passed. The coordinator suite passed 20 tests.

Isolated two-MSP acceptance proved actual ID eligibility export, signed HTTP client imports, distinct credentials, stable retries, 19 scoped Cloudline clients, exact identity ownership, human holds and expired/writable registration denial. The managed-client harness also passed lost-acknowledgement replay, trial/grace/subscription expiry, suspension/restoration, revoked users, 18 clients without operator canary slots, and no invoices, payments, labor or outgoing messages. Coastline test containers were stopped afterward.

Production acceptance confirmed effective enabled flags, a successful scheduled coordinator tick after activation, six workspace adapters, origin/public exact-release checks and both Cloudline hostnames. Signed-in ID setup, Milepost dashboard, Safeharbor queue, Logbook customers/connections and Cloudline client/Microsoft detail pages passed. Logbook's live Cloudline preview returned one approved existing Microsoft tenant without importing or changing records. Cloudline's `/health/release` requires `?sha=<exact SHA>` by design.

All 15 Safeharbor report/portal evidence tables, including Lifestyle evidence, matched the backup. Existing worker cron files and MP/Safeharbor configuration remained byte-identical. The report scheduler is active with the same scope. Ventures mappings and the master UUID were preserved. Apache's parent stayed running; only graceful reloads were used. No real customer messages, charges or endpoint repairs were issued.

The first Logbook attempt stopped before container recreation because the external-MSP flag was absent rather than explicitly false. The verified resume added that single flag, reused the restored backup and completed the release. Root postflight evidence: `/srv/8west/backups/suite-integration-activation-20260924T034209Z/postflight.json`.

### Remaining product work and customer input

No newly eligible production MSP existed at activation. The two-MSP workflow was proved with isolated synthetic accounts, not a new real paid customer. New signups are enabled; this registration slice has no remaining engineering-off gate. Microsoft administrator consent, device/agent installation and customer credentials remain legitimate setup steps.

This completes the managed-client and Cloudline/Logbook registration/activation slices, not the entire eight-app project. Real portal invitation acceptance, human support/report delivery acceptance and offline-payment fee collection remain separate work. Retired Inbox Watch and broader Westy repair scope were not activated.
