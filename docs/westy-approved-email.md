# Westy approved advice email

## Scope

Frankie authorized evidence-based documentation of case 614 without changing the
laptop, and a human-approved email path: endpoint POC first, then client POC.
The first recipe is memory-capacity advice. It does not diagnose faulty RAM,
promise upgrade compatibility, quote prices, approve a purchase or claim repair.
The September 17 case note records 96.3% usage at the original alert, source
recovery, continuing alerts reported by Frankie, and the unresolved diagnosis.
It leaves the ticket open and transfers it to human ownership.

This is a separate staff-controlled page, not permission for the chat bubble.
No endpoint command, automatic case closure, time entry, invoice or report is
created. The existing mail queue and report delivery code remain unchanged.

## Normal use

On an eligible alert case, choose **Review Westy advice email**. An owner/admin
can confirm a same-customer contact as the **endpoint POC for this case**, using
the ticket contact, or explicitly designate a **client POC fallback**. The latter
is an append-only customer-scoped designation. Clearing an endpoint assignment
allows fallback; an invalid existing contact refuses sending rather than routing
around it. An empty route blocks sending. This slice does not invent or synchronize
a fleet-wide endpoint contact registry; endpoint designation is case-scoped.

Staff may edit and save the conservative starting draft. Owner/admin approval
shows the exact recipient, configured sender, subject and full body, and requires
an explicit checkbox and **Approve and send once**. Subject references must remain
bound to this exact case so replies use existing intake threading. A new draft
supersedes prior unsent drafts. Revocation remains available while sending is off.

Saving and viewing do not send. Approval uses existing CSRF/session boundaries and
a single-use 15-minute server review. The saved draft expires for approval after
24 hours. Current active staff role, signed identity revocation feed, exact tenant,
active customer binding, case, contact, sender and evidence are rechecked. Identity
feed failure stops mail even where ordinary browsing may remain available.
Contact, ticket, new evidence, author permission or client-POC changes revoke
unsent drafts; full snapshots also catch altered/deleted evidence and binding changes.

## One send and truthful results

The server locks the tenant, staff, ticket, workflow and contact. It commits an
immutable approval/attempt record before contacting Microsoft Graph. A second
transaction rechecks authority and holds the relevant database locks through
submission. A concurrent approval or a killed sender cannot initiate another
attempt. This first slice allows only one attempted advice email per case.
It does not use the legacy queue's automatic retry or SMTP/PHP fallback.

Graph HTTP 202 means **Microsoft accepted — inbox receipt unverified**. A lost
response or process crash means **result unknown — do not resend**. Reload reads
that same durable result. Provider investigation or actual recipient evidence is
required to resolve uncertainty; there is deliberately no resend button. No
background email worker or automatic draft generator is enabled by this feature.

## Migration and release

Migration `026_westy_approved_email.sql` adds `westy_client_poc` and
`westy_email_drafts`, ten guards and a definer health function. Replay preserves
all rows. The runtime retains its existing DML rights and needs only
`EXECUTE ON FUNCTION safeharbor.westy_email_schema_health`; it gets no DDL or
trigger privileges. Canonical schema contains the same definitions.

Use the documented Safeharbor-only freeze, verified application/config/trigger
backup and scratch restore, exact green-main archived SQL, replay, matching source
deploy and restored report scheduler. Do not stop shared Apache or touch another
application database. Keep the feature off until schema and source acceptance.
Activation is `westy_email.enabled=true` plus exact tenant/customer allowlists.
Initial live scope is the existing internal customer only. A rollback disables
this switch and retains the tables, immutable attempts and unchanged legacy mail
and report paths. Never retry an uncertain send through another transport.

## Verification and current release state

Coastline's unique `westy-email-20260917` project runs synthetic MySQL only.
32 actual database checks cover fallback, missing/wrong customer contacts, roles,
exact approval, duplicate and concurrent sends, revoked identity, killed sender,
lost response, permission/contact change-and-revert, human evidence, disabled
scope, immutable content/history, migration replay and missing guard refusal.
The full canonical schema plus service intake migration loaded into a separate
synthetic preview. Desktop/390px phone review, fallback selection, draft save,
unchecked refusal, missing-provider refusal and retained unsent draft passed.
No test email left the isolated environment and desktop Docker was not used.

PR #125 is live as `3d9f080` after exact-main CI, protected backup/restore,
migration/replay and signed-in desktop/phone acceptance. The internal customer
scope is enabled. Case 614 uses the explicitly authorized endpoint contact
`frank@8westit.com`; saved Draft #1 awaits exact-wording approval with no send
attempt. No laptop command is authorized here. See the
[September 17 release record](WESTY_APPROVED_EMAIL_RELEASE_2026_09_17.md).
