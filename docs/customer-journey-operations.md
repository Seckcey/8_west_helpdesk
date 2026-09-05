# Customer journey operations

**Verified read-only: 2026-09-05 UTC.** This runbook describes the implemented
8 West Lifestyle path and how an operator repeats its bounded steps. It does
not replace the cross-application release manifest or authorize a new customer,
recipient, invitation, financial decision, or production configuration change.
The scope is Safeharbor and its explicit service contracts; 8 West ID,
Milepost, and Coastmark retain their own repositories and ownership.

## Current operating state

Safeharbor's immutable production marker names
`7ab6b3adbad6e19aafa3923f48021528bd6c8c63`. The portal home, approved-time
export library, and managed-customer activation library hashes matched that
GitHub release during this check. GitHub `main` at `0cfab321` differs by
documentation only. No release, gate change, report generation, email send,
invitation, ticket write, time claim, or Coastmark request was performed by
this verification.

| Step | Existing evidence | Current scope |
|---|---|---|
| Customer setup | Milepost customer UUID `f22fc65c-70ca-439e-b703-f85c82da885d` reaches the ID projection and existing Safeharbor client 14 without a duplicate | Provider tenant 1 / `8west`; broad activation worker and customer receipt intake are off |
| Customer access | Active portal binding 1 maps ID tenant `8-west-lifestyle` to provider tenant 1 / client 14 | Portal enabled; ticket details, new requests, replies, and archives passed the recorded signed-in canary |
| Support work | Versioned first-response promises, technician time, human approval, and append-only adjustment slips are installed | Normal Safeharbor permissions and exact client scope apply |
| Draft billing | Claim 1 represents approved time entry 10, source version 0; its accepted receipt names Coastmark event 1, draft invoice 9, line 17 | Both Safeharbor export gates and the Coastmark receiver/mapping are off after the controlled test |
| Weekly report | Managed schedule version 2 / row 17 is active for Wednesday 09:00 Pacific | Only the approved Lifestyle schedule; `canary_only=true` |
| Scheduled delivery | Archive 5 covers August 24–31, generated `2026-09-02 16:00:02Z`, submitted `16:00:03Z` | Graph acceptance verified; inbox receipt for archive 5 was not independently verified |

The active schedule key is
`managed-weekly-v3:f22fc65c-70ca-439e-b703-f85c82da885d`. The root-owned
`/etc/cron.d/safeharbor-business-reports` invokes the guarded runner every
five minutes. Report generation and delivery are intentionally enabled for
that exact schedule. Earlier canary schedules remain disabled. Archive 4 has
recorded human inbox confirmation; attempts for archives 1 and 2 remain
terminal `uncertain`. Do not replay any of those historical sends.

The permanent master-customer UUID
`4ebaeefa-b101-47f8-ac76-e49ab309d272` is excluded from downstream activation
and time billing. The 8 West IT provider tenant is not a billable customer
through this seam.

## 1. Prove an existing customer before setting anything up

Use permanent customer UUID and immutable receipt lineage, not company name,
email, domain, or a coincidentally matching numeric ID. For Lifestyle, retain
the existing client and binding. Do not rerun first-customer setup simply to
demonstrate that it works.

On the authorized host, these existing commands only inspect records or
compute an unpersisted report preview. Paths below are relative to the deployed
application root `/srv/8west/apps/safeharbor/current`; in a repository checkout
prefix them with `app/`. Run through the established protected operator account,
never by displaying or copying database credentials.

```bash
php db/manage_portal_client.php inspect \
  --binding-id=1 --identity-tenant=8-west-lifestyle \
  --provider-tenant-id=1 --client-id=14

php db/export_approved_time.php --inspect-claim --claim-id=1

php db/run_business_report.php --tenant-slug=8west \
  --schedule-key=managed-weekly-v3:f22fc65c-70ca-439e-b703-f85c82da885d \
  --dry-run
```

`--inspect-claim` uses the dedicated export database identity and prints IDs,
state, version, and hashes, never notes or payload text. It makes no network
request. Report `--dry-run` does not persist an archive, create a delivery, or
send mail; it prints the computed period, totals, and content hash. A missing
gate or mismatched identity must be investigated rather than bypassed.

For a newly approved customer, the dependency order is:

1. Milepost creates the stable UUID and exact source event; its owners control
   the dedicated ID and Safeharbor outboxes.
2. ID inspects/plans and applies only its exact customer projection, roles,
   product grants, lifecycle, and report-contact evidence. An invitation is a
   separate communication, not a side effect to assume.
3. Safeharbor accepts only the allowlisted customer receipt. Its activation
   config fixes `customer_ids`, `tenant_actors`, `canary_only=true`, batch size,
   and the reviewed Wednesday/Pacific schedule. The report config adds the
   independent schedule, tenant, client, and recipient allowlists.
4. A bounded invocation of `php cron/managed_customer_activation.php`
   authenticates ID schema-2 evidence and reconciles portal/contact/schedule
   state atomically. It is a mutating worker with no CLI dry-run. Never run it
   just to inspect a customer. It does not generate or email a report.
5. Validate the exact portal binding and inactive-customer containment before
   enabling any broader service. Keep broad workers off until their exact
   customer scope and operating cadence have been approved.

The activation worker already exists. Broad rollout is an operational scope
decision, not evidence that these features still need to be implemented.
See the [release sequence](customer-service-tools-release-manifest-2026-08-30.md)
and [customer receipt contract](milepost-customer-sync-contract.md).

## 2. Support and human-approved time

The customer uses `https://safeharbor.8westit.com/portal` with a fresh ID
session. Customer admins/users can create a request and reply within their
exact binding; viewers remain read-only. Staff work the ticket in the provider
workspace and record technician time. The active owner/admin reviews the time
before it can become export evidence. A pending or rejected entry cannot be
claimed.

Read-only acceptance can revisit the existing ticket and archive. Creating a
ticket or reply can trigger normal mail, so a no-communication audit must not
submit either. Never request passwords, OTPs, or session material; use the
user's established signed-in browser for protected acceptance.

Corrections are numbered append-only slips. The original approval and note
remain immutable. Keep service-goal snapshots tied to the ticket's creation
time; do not republish v2 or rewrite older tickets while repeating the journey.

## 3. Transfer one approved entry to one draft

### Normal staff workflow

The September 5 release adds the existing billing connection to **Time →
Approved time & billing**. After the customer connection is configured once,
an owner/admin uses these controls:

1. Review and approve the technician's time in Safeharbor.
2. Select **Send to billing** on the approved entry. The source identity and
   current approved version are loaded by the server.
3. **In Coastmark · version N** confirms the recorded receipt. Select
   **Open in Coastmark** to review the draft invoice and its price.
4. After an adjustment, select **Send next adjustment** until the displayed
   billing version matches the effective version. If an invoice is already
   finalized, Coastmark records the correction for manual review.
5. If a request is interrupted, select **Check billing status** to discover
   its outcome before another send.

There is no need to run a server command or change configuration for each
entry. Configuration is a one-time operator setup for the exact existing
customer/agreement mapping. Leave that approved connection enabled for normal
use. **Customer billing connection needed** means the mapping is missing;
**Billing connection is not enabled** means the operator configuration still
needs activation. The master provider remains excluded from customer billing.

The implementation sends time only to a draft or correction review. Prices,
invoice approval, sending and payment stay in Coastmark. The CLI sequence below
is retained for operator diagnosis and historical canary evidence; the temporary
on/off test windows described there are not the normal staff workflow.

### Existing operator contract and historical controlled test

This path is separate from Coastmark's agreement/device-usage billing runs and
from any subscription charged for use of the suite. Safeharbor supplies exact
approved minutes and source identity; it supplies no rate, tax, payment terms,
invoice command, or ledger instruction.

Before the next authorized Lifestyle operation, Coastmark's owner verifies the
existing exact customer/agreement/time-line mapping, current invoice state,
rate/tax/payment-term snapshot, and v3 receiver configuration. Reuse verified
objects; do not recreate the customer, agreement, or mapping from an old test
description. Existing invoice 9 is evidence, not permission to change it.

The read-only September 5 Coastmark lookup followed only the existing exact
`source_tenant_key=8west` plus Lifestyle `source_client_key`, then joined the
mapping's organization/client foreign keys. It found mapping 3, organization
1 (`8west`), Coastmark client 7, agreement 1, and time agreement line 1. The
mapping was disabled; that client's `external_id` was NULL. The client had one
active agreement and zero active `source_type=milepost` agreement lines.
These are identity and readiness facts, not authorization to invent a device
price line. An explicit device-customer link must be owned by Coastmark and
must refuse overwriting another external identity. Do not infer it from names
or copy a Safeharbor local client ID into Coastmark.

For an operation already authorized to create a draft, the bounded sequence is:

1. Verify the permanent Lifestyle UUID is the only export client key and the
   master UUID remains refused. Retain the dedicated least-privilege export
   database identity and fixed service endpoints.
2. Coastmark enables only that reviewed mapping and receiver. Safeharbor
   enables claim creation first, while sending remains off.
3. Select the exact approved entry ID, immutable entry key, and active
   owner/admin actor from inspected records. Create one claim, then inspect the
   returned claim ID. Do not reuse entry 10 to manufacture another test charge.
4. Enable sending only for one explicit claim dispatch. Inspect the matching
   receipt and Coastmark's draft line and amount. Neither command approves or
   sends an invoice or invokes a payment provider.
5. Restore the two Safeharbor gates and Coastmark mapping/receiver to their
   approved resting state after a controlled window. Preserve every claim,
   receipt, event, and line.

The existing operator commands are:

```bash
php db/export_approved_time.php --claim --tenant-slug=8west \
  --entry-id="$APPROVED_ENTRY_ID" --entry-key="$APPROVED_ENTRY_KEY" \
  --actor-user-id="$REVIEWED_ACTOR_ID"
php db/export_approved_time.php --inspect-claim --claim-id="$CLAIM_ID"
php db/export_approved_time.php --send --claim-id="$CLAIM_ID"
```

These variables represent inspected and authorized records; unset values must
not be filled with guesses. `--claim` writes an immutable claim without network
traffic. `--send` contacts Coastmark and writes a receipt. A timeout can mean
Coastmark committed; resolve it using the signed status operation:

```bash
php db/export_approved_time.php --status --claim-id="$CLAIM_ID"
```

`--status` makes a network request and records immutable receipt evidence; it
is not a no-write health check. Exact acknowledgement is terminal success,
changed facts are a conflict, and signed absence permits only a later explicit
resend. There is no batch, cron, queue, or automatic retry. Corrections affect
only the existing draft through signed adjustment lines; a finalized invoice
creates a manual exception. See the [v3 export contract](coastmark-approved-time-export-contract.md).

## 4. Observe the existing weekly report

Do not reinstall, re-enable, or manually deliver the current Lifestyle schedule
to prove repeatability. Inspect its next due period, the immutable archive,
delivery status, and attempt timestamp after the existing runner executes.
`submitted` means the provider accepted the message. Only a human recipient
confirmation proves inbox receipt. A terminal uncertain attempt is preserved,
not retried.

Changing the customer, recipient, sender, timing, or schedule scope requires
an exact reviewed configuration and the report scheduler's bound activation
evidence. Existing scheduled operation does not authorize a new canary send.
Use the [report contract](business-reports-contract.md) for that separate step.

## Verification and remaining decisions

The existing 11 hermetic suites passed locally on 2026-09-05 UTC: activation
45 checks, lifecycle 40, ID evidence 43, export 42, time entries 105,
adjustments 44, portal authentication 73, portal data 44, portal ticket workflow
32, portal archives 13, and business reports 243: **724 checks total**. These
tests use isolated fixtures; this run did not execute production database
tests or fresh browser mutations.

No missing Safeharbor implementation was found in this bounded Lifestyle path.
The current follow-up work is to choose the next exact draft-transfer operation
and approved operating cadence, preserve the existing reporting schedule,
and expand customer allowlists only under a concrete rollout decision.
General device-usage billing belongs to Coastmark/Milepost; unrelated payment
connection or live-payment tests are outside this journey verification.

When publishing new application code, repeat the exact-release and migration
checks in [the deployment runbook](../deploy/README.md). A documentation-only
reconciliation does not require a production rebuild or changing a feature gate.
