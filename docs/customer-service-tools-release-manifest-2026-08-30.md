# Customer Service Tools release manifest

**Status:** reviewed, default-off release-candidate checklist. All four current
candidate heads have green immutable-head CI and no open review P0/P1. Do not
merge, migrate, configure, deploy, or run a canary from this document. A later
dependency merge, rebase, or commit invalidates the affected row and requires
fresh exact-head CI and review.

This document is the one cross-application checklist for the Customer Service
Tools release. It does not contain secrets and it is not production
authorization.

## What this release does

In plain language:

1. Milepost creates the customer record and puts two sealed messages in its
   outbox: one for Safeharbor and one for 8 West ID.
2. 8 West ID owns the customer's sign-in, tenant, roles, product grants,
   report address, and optional first-owner invitation.
3. Safeharbor owns tickets, portal pages, response clocks, approved time,
   correction history, weekly report archives, and delivery evidence.
4. Coastmark may turn approved Safeharbor time into draft invoice lines. A
   human still reviews the draft. This release cannot approve, send, post,
   charge, collect payment, or write a ledger entry automatically.

The reserved 8 West IT master UUID is never treated as a downstream managed
customer or billing subject. The first controlled customer is 8 West Lifestyle.
AI cannot close a ticket;
the only automatic close path remains an untouched, exact Milepost telemetry
alert that receives a real source-recovery event.

## Service-goal version 2 is already scheduled

This cross-application release must not republish or rebase the already-live
service-goal decision. Tenant `8west` has two complete version-2 policies that
become effective together at `2026-08-31 07:00:00Z`, which is Monday midnight
Pacific:

- Standard: Low 480, Normal 240, High 120, Urgent 60 elapsed minutes.
- Premium: Low 240, Normal 120, High 60, Urgent 30 elapsed minutes.

Existing tickets retain their creation-time response target. Only tickets
created on or after the boundary select version 2.

## Immutable release inputs

Record all four candidate heads again immediately before any merge. A later
commit invalidates the corresponding CI and review row.

| Application | Default branch and audited base | Candidate | Exact head | Exact CI | Review |
|---|---|---|---|---|---|
| Safeharbor | `main` at `7d4035d90e3ac5783ea47a68fd10ba2c7fdd4da7` | draft PR #94 | `044177007ba4d8a1a6db610ef7d68419057fecec` | run `33330270824` passed | GO, no P0/P1 |
| Milepost | `main` at `a9321e824ee3a95b94540f06e0b64191e9c065b1` | draft PR #427 | `930abd4aa2240e8e0f09f845b6427270c33808ac` | run `33316156001` passed | GO, no P0/P1; refresh after any #428 integration |
| 8 West ID | `master` at `28d54b6756433af86c87ae36f1e9f71db56a4f49` | integrated draft PR #88 | `613f7f2dd7e6bd31fb2d3530b7a3c5c87ad8da01` | run `33327718777` passed | GO, no P0/P1; refresh after any #86/#80 integration |
| Coastmark 365 | `main` at `6f8bc25d207bd22e20ca064c0ded12f1a8108240` | draft PR #62 | `c288b5798394da7184370a04516e5f1e894cc6a0` | run `33330450457` passed | GO, no P0/P1 |

The current 8 West ID PR #88 contains independently approved atomic head
`aaefcc53bce56d8dfe4892c0810e9168c9cbb35a` and the customer feature stack
ending at `e117c48e0ac5a5e77e7ec1c61aaee3a38bd5e690`. Green historical heads are
not substitutes for the exact integrated row above.

## Coordinated dependency order before release

These are overlap rules, not merge authorization:

1. In 8 West ID, standalone atomic-safety PR #86 must land before Milepost
   launch-handoff PR #80. PR #88 must then rebase onto that exact `master`, drop
   the already-landed #86 history, preserve its customer-service work, and
   combine both reviewed policy changes in `app/lib/product_policy.php`.
2. In Milepost, standalone identity repair PR #428 may land before the full
   customer-service aggregate PR #427. PR #427 already contains component PR
   #422; after #428, rebase #427, drop the three patch-ID twins, remove stale
   release-vehicle wording, and preserve the customer-owned CI, config, and
   documentation context.
3. Milepost PR #429 has no path or functional overlap and is not a Customer
   Service Tools release input.

After either coordinated rebase, replace the affected table row with the new
exact head, CI run, and independent review. Never release a superseded head.

## Read-only production database preflight

At `2026-08-30T19:25:57Z`, a no-write check through the saved `milepost-ec2`
host alias reported Safeharbor MySQL `8.0.46-0ubuntu0.24.04.3` with server and
connection character set `utf8mb4`, collation `utf8mb4_0900_ai_ci`, and SQL mode
`ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`.

The protected local socket operator has the required DDL, trigger, and
`LOCK TABLES` privileges. The ordinary `safeharbor@localhost` runtime has no
global privilege and no DDL or table-lock grant. The two version-3 export tables
are absent, as expected before migration. Because proving their exact serialized
CHECK hashes requires creating the trusted fixture, the hash-only admission test
requires separately authorized scratch-only execution before release. No
production table, account, grant, configuration value, or row was changed by
this preflight.

## Non-negotiable boundaries

- Safeharbor is the system of record for help-desk and service-delivery facts.
- Milepost supplies tenant, customer, asset, and telemetry context. It does not
  own Safeharbor tickets or billing facts.
- 8 West ID owns identity and preferences. It does not control endpoints,
  create invoices, or alter Safeharbor service history.
- Coastmark owns accounting. It accepts only explicit approved-time events and
  creates review-only drafts. Coastmark is never part of generic customer
  propagation.
- Logbook's existing endpoint/customer import remains a separate integration;
  this release does not claim to create a Logbook customer.
- No autonomous AI action, customer endpoint control, invoice posting, invoice
  sending, payment, checkout, or ledger posting is introduced here.
- No secret, token, mailbox credential, HMAC key, OIDC client secret, database
  password, or private config file may enter Git, logs, chat, or a release
  receipt.

## Release order

The release is installed dark. A successful deployment does not turn on a
worker, sender, receiver, mapping, schedule, invitation, or report.

### 0. Freeze and prove the inputs

1. Fetch each remote and verify the default branch still equals the audited
   base or rebase/re-review the candidate.
2. Require a clean checkout, exact candidate SHA, green CI for that SHA, and an
   independent review with no open P0/P1.
3. Verify the four repository remotes independently. Coastmark and Waypoint
   remain separate; Waypoint is not a release target.
4. Archive exact source artifacts and checksums. Never release from an
   uncommitted checkout.
5. Inventory live application commit, database migration state, scheduler
   state, protected gates, service identities, and runtime grants before the
   first write.

### 1. Prepare every receiver while all gates are off

Receiver-first order prevents a new customer message from being sent to an
application that is not ready to store it.

1. **8 West ID:** use the final integrated atomic release procedure. If the
   receipt migration is not already live, apply
   `20260829_milepost_customer_v2_receipts.sql` first. Then apply, in order:
   `20260829_milepost_customer_identity_projection.sql`,
   `20260830_managed_customer_lifecycle_containment_v1.sql`, and
   `20260830_milepost_customer_owner_invitations.sql`. Deploy matching source
   with every managed-customer gate off.
2. **Safeharbor:** under the Safeharbor-only endpoint/database write freeze,
   first record the exact production MySQL 8.0 patch and relevant character-set
   and collation configuration. On that exact engine, run the trusted hash-only
   CHECK serializer/admission proof without printing raw clauses. Confirm the
   migration operator has the required DDL and `LOCK TABLES` privileges while
   the ordinary runtime identity does not. Stop on any hash or privilege
   mismatch. Then
   apply and replay the exact migration blobs in this mandatory order:
   `020_time_approval_adjustments.sql`,
   `021_coastmark_time_export_v3.sql`,
   `022_managed_customer_activation.sql`,
   `023_business_report_archive_scope.sql`, then
   `024_managed_customer_lifecycle.sql`. Deploy the matching exact source while
   every new gate remains off.
3. **Coastmark:** apply
   `2026_08_29_000200_create_safeharbor_time_v3_events.php` through the reviewed
   Coastmark migration path, then deploy matching source with the Safeharbor
   import gate off and every mapping disabled.
4. Verify each receiver's schema, permanent guards, runtime least privilege,
   public signed-out behavior, health, and zero unexpected writes before
   proceeding.

Every database step requires a fresh protected, trigger-inclusive backup,
verified scratch restore, exact migration artifact hash, quiesced owning
application, and postflight evidence. Shared Apache is never stopped.

### 2. Install the Milepost producer last

1. Apply the managed-customer identity outbox migration through Milepost's
   protected migration mechanism and replay its verifier.
2. Deploy exact PR #427 source with both delivery workers off and all customer
   and tenant allowlists empty.
3. Confirm ordinary customer creation requires an admin. A technician must be
   unable to delete a managed customer or emit an inactive suite event.
4. Confirm creating no customer produces no Safeharbor or ID network call while
   the workers are off; only durable local outbox facts may exist.

### 3. Canary the customer identity path

Use one separately authorized, permanent, non-master 8 West Lifestyle UUID.
Do not use a display name, email address, or domain as identity.

1. Add only that UUID to the Milepost-to-ID and Milepost-to-Safeharbor
   allowlists. Install dedicated secrets through protected configuration.
2. Enable the ID receipt endpoint only. Manually dispatch one exact event and
   verify the same immutable receipt on both sides.
3. Run the ID projector's no-write inspect/plan first. Apply only the
   digest-bound exact customer plan, then prove the tenant, product grants,
   report contact, lifecycle state, and master-tenant exclusion.
4. Enable Safeharbor customer receipt intake for only that UUID. Verify the
   exact immutable local binding, then run managed activation for only that
   UUID with portal and report execution still off.
5. If an owner invitation address was explicitly supplied, run the invitation
   dry-run first. The first send is separate and delivery-tracked. An uncertain
   send remains terminal until a human resolves it.
6. Turn both Milepost workers back off after the manual tick. Do not install a
   schedule until the canary has been reviewed.

### 4. Canary approved Safeharbor time into Coastmark

1. Create the disabled Lifestyle mapping at `$145.00/hour`, exact approved
   minutes, no minimum or round-up, Net 30, and 0% only for a separately
   itemized pure technician-labor line.
2. Enable the Coastmark receiver and that one mapping. Keep Safeharbor
   `claim_enabled=false` and `enabled=false` until receiver verification is
   complete.
3. Enable only `claim_enabled`, create one exact human-run claim, and inspect
   its immutable payload. Then enable sending for one operator-run dispatch.
4. Prove one draft line, exact amount, tax, source hash, receipt, and replay.
   Prove zero approvals, sends, posts, journal entries, payments, checkout
   sessions, or billing-run absorption.
5. Test one correction while the invoice remains a draft. Do not approve, send,
   post, void, or otherwise move the production canary invoice out of draft.
   Rely on the green isolated PostgreSQL suite for the non-draft
   `manual_exception` behavior; any additional fixture requires separate
   authorization and must not mutate a production invoice lifecycle.
6. Turn off both Safeharbor gates and disable the mapping. Preserve all claim,
   receipt, event, line, and exception evidence.

### 5. Canary the useful portal

1. Keep `portal.enabled=false` while the exact 8 West Lifestyle ID client,
   redirect URI, logout URI, and permanent Safeharbor binding are verified.
2. Enable only the Lifestyle binding and portal gate.
3. In a fresh 8 West Lifestyle 8 West ID session, verify ticket groups,
   ticket details, reply, and new-request flows. Every read and write must stay
   inside the exact tenant/client scope.
4. Verify there is no endpoint control, technician-only note, another client,
   billing data, raw email body, attachment path, or secret.
5. Immediate rollback is `portal.enabled=false`; preserve ticket history.

### 6. Canary weekly archived reports

1. Keep report generation and delivery off. Keep `canary_only=true` and
   allowlist only the Lifestyle schedule, tenant, client, and recipient.
2. Publish report definitions in ordinal order and generate one complete prior
   week. Verify period boundaries, response-goal facts, approved/corrected time,
   ticket totals, archive hash, and portal rendering before any email attempt.
3. Use the separately verified `reports@8westit.com` application-send path.
   The mailbox does not need human members for application-only Graph sending,
   but a new canary must prove both Graph acceptance and recipient receipt.
4. Send only to the tenant's ID-owned admin/report contact. For Lifestyle, the
   approved test recipient is `frank@go8west.com`; the 8 West IT tenant's normal
   admin report address is separate.
5. Treat an uncertain send as terminal and never retry it automatically.
6. Stop the schedule and turn both gates off after the canary. Only after a
   separately reviewed canary may the exact weekly scheduler be installed
   disabled, verified disabled, and later activated under its own evidence
   record.

## Required dark configuration

Before source deployment and again after every canary, prove at least:

- Safeharbor: `portal.enabled=false`, `suite_customer_sync.enabled=false`,
  `coastmark_time_export.claim_enabled=false`,
  `coastmark_time_export.enabled=false`,
  `managed_customer_activation.enabled=false`,
  `managed_customer_lifecycle.enabled=false`,
  `managed_customer_lifecycle.restoration_enabled=false`,
  `business_reports.generation_enabled=false`, and
  `business_reports.delivery_enabled=false`.
- Milepost: `managed_customer_dispatch.enabled=false` and
  `managed_customer_identity_dispatch.enabled=false`, with empty tenant and
  customer allowlists and no scheduled worker.
- 8 West ID: `milepost_customer_provisioning_v2.enabled=false`,
  `milepost_customer_identity_projection.enabled=false`,
  `milepost_customer_identity_lifecycle.enabled=false`,
  `report_contacts.enabled=false`, and
  `managed_customer_owner_invitations.enabled=false`; managed-customer scopes
  are `off` and UUID lists are empty.
- Coastmark: `services.safeharbor_time_import.enabled=false` and every
  Safeharbor mapping disabled.

Existing unrelated production gates are not changed by this release.

## Stop and rollback rules

The first response to any mismatch is to turn off the smallest owning gate and
stop. Do not erase evidence to make a retry look clean.

- **Customer propagation:** disable both Milepost workers. Receipts and outbox
  rows stay immutable. Repair and replay the exact event after review.
- **Identity:** disable the ID endpoint/projector/lifecycle/invitation gates.
  Do not delete a tenant, user, invitation, role, preference, or receipt as a
  rollback.
- **Portal:** disable the global portal gate or exact binding. Do not delete
  tickets, messages, time, or archives.
- **Coastmark:** disable both Safeharbor export gates, the Coastmark receiver,
  and the exact mapping. Never delete accepted evidence or automatically undo a
  posted financial fact.
- **Reports:** disable generation and delivery, remove the active scheduler
  name to its root-only quarantine, and inspect the report/deploy lock. Do not
  retry an uncertain delivery.
- **Application code:** roll back code from an exact reviewed commit only when
  the older code understands every durable fact already written. Keep additive
  schemas and stronger guards. Database restore is disaster recovery, not a
  normal rollback.
- **8 West ID atomic release:** follow only the final reviewed atomic recovery
  procedure and retained release directories. Never reuse the quarantined
  no-Composer helper or improvise symlink cleanup.

## Final acceptance record

Before calling the release complete, attach one redacted record containing:

- exact default-branch merge SHAs and production application markers;
- exact migration artifact hashes, apply/replay results, and permanent-guard
  counts;
- backup and scratch-restore evidence locations and hashes;
- exact protected gate states and scheduler state;
- one customer event/receipt lineage using IDs and hashes, not private text;
- one portal signed-in scope check;
- one Coastmark draft-only financial invariant check;
- one weekly archive hash, Graph acceptance result, and human receipt
  confirmation;
- rollback verification; and
- zero unexpected errors or cross-tenant writes during the canary windows.

Until that record exists, describe the stack as a reviewed, default-off release
candidate—not as a completed production rollout.
