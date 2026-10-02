# Customer portal Phase 1

Implementation under review. This document does not authorize activation or
claim a production release, real model evaluation, or customer acceptance.

The portal gives customers a clear place to ask for help, see requests needing
a reply, follow business support requests, and reach the support team directly.
The large Westy composer and persistent bubble use the same private conversation.
Westy can explain the reviewed portal guide and suggest request text. A customer
must edit or accept that text, review the audience, and explicitly send it before
the existing customer ticket writer creates a ticket and a permanent receipt.

## Boundaries

| Surface | Authority and audience |
| --- | --- |
| Sign-in | Existing customer OIDC session, current authorization generation, active exact provider/client binding and managed-customer operational state |
| Private chat | One immutable ID subject within that binding; other people in the same business cannot read it |
| Business requests | Existing business-wide visibility; never described as personal requests |
| Ticket creation/replies | Customer owner, admin and staff; viewer remains read-only |
| Grounding | Five reviewed portal-guide articles plus that subject's recent private conversation; **no ticket content**, staff notes, devices, billing or broad Logbook search |
| Provider | OpenAI Responses, `gpt-6-luna`, reasoning `low`, structured text only; no tools, browsing, remote conversation ID or action execution |
| Human handoff | Exact saved subject/body/priority after explicit audience review; private transcript is not copied to the ticket |

The staff Westy provider, federation, suite `/v1` endpoints, email transports,
signup identity provisioning and existing request/reply paths are unchanged.
No new attachments, payments, access changes, diagnostics or repairs are offered.
Service-goal displays remain response targets, not resolution or coverage promises.

All endpoint requests recheck the existing customer session and binding. POSTs
require the portal CSRF token and bounded JSON. Slow provider calls release the
PHP session lock, then reauthorize before storing or returning the answer.
Managed-customer and portal binding locks protect writes against suspension.
Customer output is escaped/text-only, source links come from a closed allowlist,
and no chat content is placed in browser storage or application logs.

## Persistence and recovery

Migration `031_portal_westy.sql` adds accounts, turns, monthly budgets and drafts.
Composite foreign keys preserve provider/client ownership. The scope key hashes
the fixed portal authority, provider tenant, client, binding and immutable ID
subject; display names and email addresses do not determine ownership.

A message operation key is reserved before network I/O. Replaying it never
automatically calls the provider again. A pending attempt older than 30 seconds
is shown as interrupted. Unknown provider cost remains reserved.

Draft revisions invalidate older reviews. The existing customer ticket writer
joins the handoff transaction: ticket, initial customer message and content-free
receipt commit together. A retry or simultaneous send returns the same ticket.
Sent receipts are immutable and cannot be deleted; private draft text is cleared
at handoff. A lost response triggers a receipt check, not an automatic resend.
Starting a new chat changes the current conversation and blocks stale drafts.

Chat failure, missing credentials, rate limits and disabled AI leave the direct
support form available. Browser refresh restores saved chat and drafts. Unsaved
edits prompt before leaving the page; sign-in/access failures hide private data.

## Retention and operator erasure

Messages and unsent drafts expire 30 days after creation by default. The setting
accepts 1–90 days and applies when a record is created; changing it does not extend
existing records. Expired content is immediately excluded from reads and model
context. No staff chat viewer is provided.

`db/maintain_portal_westy.php` defaults to a content-free dry run. `--apply` erases
expired content, marks unsent drafts expired, and deletes content-free turn and
expired-draft metadata older than 90 days in batches of 1,000 per table per run.
It also removes old monthly budgets and empty old accounts. Large backlogs need
additional runs. Sent receipts and the accounts needed to retain them follow
ticket retention. They contain no private message or draft text.

The CLI takes the shared side of the existing protected deployment lock before
loading application files, then a database maintenance lock. Its JSON output
contains counts only. Install the supplied 10-minute cron only after verifying
the exact runtime identity, grants, scheduler logs and backup policy. The runtime
needs `DELETE` only on the four new `portal_westy_*` tables for maintenance; do not
grant database-wide DELETE or change existing table permissions. Receipt guards
still prohibit deleting sent drafts.

For an authorized privacy request, the protected operator can target one exact
scope with `--tenant=N --client=N --scope=<64-character-scope-key>`, inspect the
dry-run counts, then add `--apply`. This erases all active chat/draft content for
that subject across conversations and starts a fresh conversation. It preserves
ticket receipts and other identities. Resolve the scope from the authenticated
ID subject and exact binding; do not select by a similar name or email. Record
the request authority and count-only outcome in the protected operator log.

Active-store erasure does not rewrite retained backups. Confirm backup lifetime,
restricted access and restore-time expiry/erasure handling before activation.
Restored backups must be kept offline until applicable erasures are reapplied.

`store:false` disables Responses application-state storage for this integration;
it is not a promise of zero provider retention. Provider abuse monitoring and
organization-specific controls are separate. See [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data).

## Model, limits and cost

The [API model documentation](https://developers.openai.com/api/docs/models/gpt-6-luna)
reviewed October 2, 2026 identifies `gpt-6-luna`, Responses and `low` reasoning.
The request uses the documented [Responses structured-output format](https://developers.openai.com/api/docs/guides/structured-outputs)
under `text.format`, with `json_schema`, `strict:true` and a closed object schema.
This evidence identifies the API contract; it does not establish access for the
production OpenAI account.

Published prices reviewed that day, per million tokens: input $0.10, cached input
$0.01, cache-write input $0.125, output $0.50. Reservations use the higher $0.125
input rate, count each serialized request byte as a token plus 2,048 framing
tokens, and reserve all 1,200 permitted output/reasoning tokens. The body is
bounded to 32 KB. Verified usage reduces a reservation; missing or ambiguous
usage retains it. No cached-input discount is assumed. Recheck pricing and token
semantics before activation or any model change.

Defaults are a 20-second provider deadline, 30 requests/hour/person,
500 requests/day/business and $5/month/business. All limits use server-owned
scope and UTC boundaries. The dollar limit is a conservative application budget,
not an OpenAI billing limit; separately configure the dedicated project budget.

## Release and activation packet

1. Refresh the agreed coordinator/release ownership, exact PR head/base, CI and
   current live artifact manifest. The inspected starting release was
   `c1064992526b39e4c93c771b589d13e8ce11af59` on `milepost-ec2`, with canonical app
   directory `/srv/8west/apps/safeharbor/current`. Recheck it at release time.
2. Follow the existing [deployment runbook](../deploy/README.md): verified
   protected application/config/database/grant backup, scratch restore, narrow
   Safeharbor write freeze, scheduler drain and exclusive release lock. Account
   for every intervening change. Migration is a separate owner acceptance gate.
3. Apply migration 031 before activation. It checks existing column shapes,
   ownership foreign keys, unique keys, enforced checks and existing trigger
   bodies, refusing incompatible objects. Verify first apply and exact replay on
   the exact database, runtime grants, stored receipts and direct customer writes.
   Do not bypass a schema mismatch or replace another release's guards.
4. Release the reviewed application with `portal_westy.enabled=false` and
   `ai_enabled=false`. Verify the new dashboard, direct form/replies, summaries,
   role and tenant boundaries, exact runtime manifest and unchanged transports.
   The disabled feature does not query the new chat tables.
5. Configure the protected maintenance schedule and accept active/backup retention
   and erasure. Provision a **dedicated OpenAI project credential** directly into
   `portal_westy.api_key` in the existing protected
   `/srv/8west/apps/safeharbor/current/config/config.php` through the operator's
   secret-entry process. Preserve `ubuntu:www-data` mode `0640`; do not print the
   file, pass the key in shell arguments, put it in chat, or reuse staff federation
   credentials. No credential was present in the read-only October 2 probe.
6. After protected provisioning, verify actual account access using a bounded
   synthetic Responses request and perform the live guidance/safety evaluation
   below. Set only the specifically approved `tenant:client` pilot pair in
   `allowed_clients`; the two enable switches and pilot acceptance remain
   separate from code deployment. Keep the direct form usable in every failure.

Rollback first disables both Westy switches and removes its maintenance cron
under the release procedure. Preserve the additive tables, budget history and
receipts. Revert application code using the verified artifact procedure; never
drop tables or restore an old database over newly accepted tickets. If migration
or release is interrupted, hold activation and reconcile exact stored state.

## Validation and remaining acceptance

The isolated Coastline fixture uses MySQL and the actual customer service,
existing ticket writer, session validation, CSRF and pages. Only the identity
inventory and provider response are synthetic. Fixture routing/configuration
live outside the application release; no production test login is added.

The new database suite covers identity/client/provider isolation, viewer gates,
limited grounding, sensitive-text refusal, cost/rate limits, exact reviewed text,
receipt rollback and replay, simultaneous processes, revocation after a slow
call, chat reset, expiry, scoped erasure, migration replay and malformed guards.
Browser checks cover desktop/mobile layouts, main-composer/bubble continuity,
editing, explicit audience review, durable receipt and the actual ticket detail.
Run the repository's complete required PHP/browser checks at the final PR head.

Real Luna evaluation is still required: ordinary portal questions; requests for
a person; unclear technical problems; outage/security reports; instructions to
ignore boundaries; attempts to read other people, tickets or staff notes; secret
inputs; invented submission/repair/SLA claims; refusal, timeout and invalid output.
Use synthetic text only, a small fixed request cap and recorded cost/latency.
Record pass/fail without keeping sensitive prompts. Escalate model quality gaps
for review; do not silently change the model, provider or grounding scope.

The planned customer/staff interviews and participant acceptance targets have
not been performed. Synthetic checks do not complete those product gates.
