# Automatic MSP integration registration — 23 September 2026

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
