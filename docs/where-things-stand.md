# Where things stand

**Last focused production verification: 2026-08-29** for approved-time
version 2, service-goal history, the Lifestyle report canaries, and portal
activation. Safeharbor is exact PR #72 merge
`a42872be1f9cd76ebb0a3b2bad91c16f2c0bb0ff`; exact-main Validate run
`33232316449` passed. The broader 2026-08-09 inventory and the separately
verified 2026-08-24 suite SSO history remain below; unrelated surfaces were not
re-audited during this focused closeout.

One page for anyone — human or agent — picking this repo up. It answers "is
this thing actually on?" for every moving part, and every claim comes with the
command that proves it. **Check, don't trust.** This file goes stale the moment
somebody ships without updating it, and a stale status page is worse than none:
it is how two sessions came to build the same Waypoint feature on the same day,
one of which was thrown away.

If you change what is live, change this page in the same PR.

---

## The short version

| Thing | State |
|---|---|
| Safeharbor itself | **Live** at https://safeharbor.8westit.com, v1.0 feature-complete |
| Alert intake (`api/svc/alerts.php`) | **Live** since 2026-07-29, Milepost emitting |
| Telemetry automatic-close ownership | **Live through PR #59 / merge `e7ee521` and migration 018**: only an untouched exact signed Milepost alert may close on source recovery; any human/customer work permanently removes that one-use ability |
| Westy defect intake (`api/svc/westy.php`) | Safeharbor's in-process failure and human-reviewed feedback paths are **live**. External receiver hardening is deployed through PR #62 / release `da9560a`, but delivery remains **dark** because no Westy service identity is registered; its authority is exact `milepost-westy` + `app=milepost` + `event=failure` only. |
| Westy thumbs up/down (`assets/js/westy.js`) | **Shipped 2026-08-09** — down files a ticket, up files nothing |
| Partner support intake (`api/svc/support.php`) | **Live** since 2026-08-09, Coastmark and Waypoint both emitting |
| 8 West ID suite SSO | **Live**; canonical first-tenant roles plus RS256 verification deployed through PR #34 |
| Migrations 001–019 | **All applied** to production |
| Versioned service goals | **Live**: v1 baseline through PR #37 / merge `1796f57`; guarded later-version publication through PR #50 / merge `12abd36`; no v2 published. The GET-only staff history view is deployed through PR #65 / release `b02a7a6`; signed-out routing and the 2026-08-29 authenticated Frankie/`8west` production acceptance both passed. |
| Approval-grade technician time | **Live** through base PR #40 / merge `b0a6760` and correction/overlap hardening PR #55 / merge `bb580a2`; migration 016 is applied, while the fresh signed-in 8 West IT correction canary remains open |
| Append-only approved-time adjustment slips | **Development candidate only on `codex/time-approval-adjustments-20260829`; not merged, migrated, or deployed.** Owners/admins add numbered correction slips while original approvals remain immutable. Migration 020 is not applied. |
| Approved time → Coastmark draft lines | **Version 2 is deployed dark; correction-safe version 3 is local review work only.** The live releases are Safeharbor PR #72 / merge `a42872b` and Coastmark PR #57 / merge `c3c553b`; both gates are off and production has zero mappings/imports/draft lines. This isolated branch adds durable Safeharbor claims/receipts, explicit status recovery, and chained correction/reversal facts. It is not pushed, merged, migrated, deployed, configured, or enabled. The permanent 8 West IT master UUID stays hard-blocked. |
| Time-provenance bridge | **Live** through PR #38 / merge `ceba5a4` |
| Anything "shipping dark" | Phase 4's deployed Safeharbor-to-Coastmark v2 sender/receiver remain default-off. Version 3 is local-only and both new claim/send gates also default off. The portal is enabled only for the Lifestyle canary, with authenticated acceptance still open. Report generation/delivery are off and no scheduler exists. Existing service-intake gates remain live. |
| Customer ticket-summary portal (Phase 5A) | **Enabled only for the approved Lifestyle canary**: global config is on and binding id 1 maps the Lifestyle identity tenant to provider tenant 1/client 14. Canonical signed-out routing passed; `support.8westit.com/portal` is 404. Fresh authenticated customer acceptance remains open, so full portal acceptance is not claimed. |
| Scheduled archived business reports (Phase 6) | **Three controlled Lifestyle archives; all three schedules are stopped.** Attempts 1 and 2 are terminal `uncertain` and must never be retried. Attempt 3 returned Graph HTTP 202 / `graph_accepted` and is `submitted`, which is provider acceptance, not inbox proof. Generation/delivery are off, `canary_only` is true, and no scheduler exists. |
| 8 West ID-backed report contact onboarding | **Live through PR #57 / merge `cef39dd` and migration 017**: dedicated protected configs were installed, stable tenant key `ewid-t1` returned one redacted contact-v1 probe, and Safeharbor stored one immutable tenant binding/contact snapshot. Both contact gates are now off after preparation. |
| Client-scoped 8 West ID report contacts | **Live through PR #68 / merge `7bf63ed` and migration 019**: client 14 has one exact 8 West ID binding and immutable contact snapshot. Both contact endpoints are back off after preparation; only the recipient digest is recorded. |
| Lifestyle identity replacement | **Enabled safely**: replacement subject `t4u10` is active; predecessor `t4u7` is permanently inactive, revoked, and quarantined. Deployed Coastmark merge `c9f4409` fails closed on a fresh signed revocation feed before any SSO write/reactivation. |
| Customer propagation | **Partly live, not suite-wide**: Milepost→Safeharbor and Milepost→Logbook receivers are live. Lifestyle still has a historical peer-tenant conflict to reconcile. Coastmark and Control Panel do not auto-provision Milepost customers. |

## Customer Service Tools development

The follow-on alert-ownership hardening is live through Safeharbor PR #59 /
merge `e7ee5218017c88de18cf6f6476f34a1512593852`. Migration 018 adds a
default-off, one-use automatic-close capability.
Only the exact signed `milepost` alert endpoint can create an eligible numeric
`alert:` ticket. Assignment, priority/status edits, client/technician/note
messages, message edits/moves/deletes, logged time, and merge workflow consume
that capability permanently. Re-fires become append-only evidence and do not
rewrite subject or priority. Real recovery auto-closes only a still-eligible
Open ticket in one database transaction; all other recovery events append a
line and leave the ticket for a human. Westy remains advise-only and has no
ticket-status action. Exact migration, canary, and rollback gates are in
`docs/ticket-auto-close-ownership-contract.md`.

The external Westy receiver hardening is deployed through Safeharbor PR #62 /
exact application release
`da9560aabf972a9be1f0027445381ddea378688d`. It admits only the exact
`milepost-westy` service reporting exact `app=milepost` and
`event=failure`, ignores an identical transport replay, counts a later real
occurrence, and creates tickets with `auto_close_eligible=0`. The receiver has
no status writer, so every Westy ticket stays open until a human resolves it.
The production route remains dark because there are zero `milepost-westy`
identity rows; no Control Panel authority was activated.

This was a code-only release with no migration or configuration change. The
root-only rollback record and verified scratch restore are at
`/srv/8west/backups/safeharbor/20260827150954Z-pre-westy-receiver-da9560a`;
the restore proved 35 base tables and 60 triggers. Production matched 136
ordinary tracked app files plus three normalized cache-stamp files, and all
115 live PHP files linted. The exact deployed hashes are
`097f8c543ef7b831d0ac74f6a2df6223231881b1ef86f67e053d0193373dc703`
for `app/lib/westy_report.php` and
`4f3787744d49a215f80eb51123ae0df949928543b8dd6ef1b362b05cd379c4a4`
for `app/public/api/svc/westy.php`.

The correct Safeharbor maintenance lock returned `403`, and the restored
Safeharbor SSL vhost finished at SHA-256
`8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43`.
Postflight returned login `200`, root `302`, unauthenticated timer `401`, and
unauthenticated Westy intake `401`; the separate support and ID host roots
returned `302`. The database, protected config, report state, and other dark
integration gates did not change, and the release window produced zero new
fatal or SQL errors.

Phase 1 shipped through Safeharbor PR #37 / merge
`1796f57d0dede4eff298a6a710098999ba7e9670`. It corrects the existing
first-response semantics without a migration. `tickets.sla_due_at` remains an
elapsed-time **response** deadline: the queue and ticket view now compare the
first `kind='tech'` message to that deadline, a resolved ticket with no
technician response is never called met, and Reports measures decided response
outcomes rather than resolution time. `service_goals_test.php` pins the lamp
states, tenant/period isolation, notes-not-responses rule, and report
denominator in hermetic SQLite. Merged sources and their survivors are shown
as neutral `Merged history` and excluded from attainment because the current
merge operation moves messages without retaining original response
provenance.

Phase 2A shipped in the same merge. It adds append-only policy versions and
per-priority targets through migration `010_service_goal_policies.sql`. Every
new ticket path captures one exact target ID, open time, and deadline; client
tier, priority, waiting, retry, refire, and reply changes do not rebase that
snapshot. Historical tickets stay unversioned because their creation-time tier
cannot be proven. Existing tenants receive Standard v1 (480 elapsed minutes)
and Premium v1 (120 elapsed minutes); tenants created later receive the same
defaults lazily on first ticket creation. Unsupported business clocks and
waiting-pause modes fail visibly. Business calendars, holidays, pause
execution, resolution clocks, a policy editor, and explicit rebase
history remain later work. Migration 010 is applied in production: four
policy versions and sixteen targets cover both current tenants, all 275
historical tickets truthfully retain a NULL target, and all four database
immutability guards are present. The deployed `service_goals.php` and
`ticket.php` hashes match merged source. A production-runtime transaction
canary resolved a v1 target and exact deadline, then rolled back without
leaving a ticket.

Phase 2B is live tooling, not a published later business policy. It adds an
operator-only `inspect` / no-write `plan` / digest-bound `publish` CLI for one
exact tenant and Standard or Premium policy. Publication locks and rechecks the
tenant, active owner/admin actor, and latest immutable version, requires an
exact future UTC effective time plus four bounded first-response targets, and
commits one attributed version with exactly four targets or rolls everything
back. Owners/admins may choose a client's tier; technician edits omit the tier
column entirely so a stale form cannot undo a concurrent authorized change.

Migration 014 adds nullable attribution for truthful legacy compatibility, an
exact non-cascading actor foreign key/index, four enforced checks, and two
INSERT guards while retaining the four migration-010 immutability guards. Its
fresh/upgrade/replay suite probes weakened and `NOT ENFORCED` checks,
noncanonical foreign-key actions, no-op triggers, overlapping publishers, both
actor demotion lock orders, rollback, and DML-only migration refusal. The
deployment was migration-first and created zero policy versions. Production still has only
the four v1 rows and sixteen targets; the real four target values and effective
time for any v2 remain an explicit business decision and must not be invented.

The follow-up history surface is a signed-in, tenant-scoped GET page. It shows
Standard and Premium versions, exact effective UTC times, four first-response
targets, current/scheduled/superseded state, and truthful actor/reason evidence.
Lazy v1 is explicitly labelled as legacy attribution because no historical
approver or reason exists. The page has no request-selected tenant, form, POST,
API, editor, publish action, lazy initializer, migration, or configuration. Its
source and hermetic isolation/role/boundary behavior are covered by the
39-check publication suite. The code is deployed through Safeharbor PR #65 /
exact release `b02a7a63d71a4d1532f81aa727b0ede85c2649f3`, and exact-main
Validate run `33088142569` passed. Authenticated production acceptance was
completed on 2026-08-29 by Frankie in the `8west` tenant: the rendered page
showed Standard v1 at 480 minutes for all four priorities and Premium v1 at 120
minutes for all four priorities, with elapsed UTC time, no waiting pause, and
no resolution goal. Those rows matched no-write CLI `inspect` results at
SHA-256 `0d6edc2900823dc1556f75e3e0feb35207fc866b4298e18f6d8ad2fdc8783f1a`
for Standard and
`4816fcd28cc33fafb5a507b60ec1d028ba7c68f4f81f4cfe6be816bdfc7ba3b4`
for Premium. No policy, target, ticket snapshot, migration, or configuration
was written. The root-only acceptance receipt is
`/srv/8west/backups/safeharbor/20260827T154017Z-pre-service-goal-history-b02a7a6/service-goal-signed-in-acceptance-20260829T062933Z.txt`
at SHA-256
`74912b7f39cc9a78ec597a1266ce57525f0f0cc8c95e3efdd473797da6a77f45`.
Version 2 remains intentionally absent until the real targets, effective UTC
time, authorized actor, and reason are supplied and approved.

The code-only release used the root-only backup and receipt at
`/srv/8west/backups/safeharbor/20260827T154017Z-pre-service-goal-history-b02a7a6`.
Its scratch restore proved 35 base tables and 60 triggers. Production matched
137 ordinary tracked application files plus three normalized stamp files, and
all 116 live PHP files linted. The correct Safeharbor maintenance lock returned
`403`. Postflight returned login `200`, root `302`, signed-out
`/service_goals.php` `302`, unauthenticated timer `401`, unauthenticated Westy
intake `401`, portal `404`, and the separate support and ID host roots `302`.

Protected configuration, grants, and database facts were unchanged. The portal,
Coastmark approved-time export, 8 West ID report fetch, report generation, and
report delivery gates remained off. The release window added no `fatal`,
`Uncaught`, or `SQLSTATE` log hits. For transparency, the initial runner
installed the exact files and restored the locks but stopped on an unrecorded
final assertion. An immediate finalization rechecked every postflight assertion
and passed; the protected receipt records both runs.

Release evidence: Safeharbor PR #50 merged as
`12abd36de83cda276e6d96a79e873e2706bb3ff0`; exact-head Validate run
`33036765540` and exact-main run `33036836019` passed, including all 53
disposable-MySQL publication checks. Migration 014 emitted five successful
postflight values and production independently retained four v1 versions,
sixteen targets, zero later/attributed versions, two captured ticket snapshots,
275 historical NULL snapshots, and the one unresolved legacy pending time row.
The policy and ticket digests, DML-only runtime grants, and protected config
hash remained unchanged. All 103 live PHP files lint; Apache syntax, login/root,
and all five cookie-free dark portal routes passed. At that release checkpoint,
report/portal tables and the business-report scheduler were empty/absent. All
129 deployed paths matched the merge: 126 byte-identical plus three normalized
cache-stamp files. The protected rollback record is
`/srv/8west/backups/safeharbor/20260827T033805Z-pre-phase2b-service-goal-publication`.

The time-provenance bridge shipped through PR #38 / merge `ceba5a4`. It
removes the legacy merge rewrite of `time_entries.ticket_id`, refreshes the
authenticated tenant into every session, and changes no schema. Production
received it before migration 011, preserving a rollback-compatible boundary.

The scratch SSO fixture also now follows the real
`suite_sso_attempt()` → `current_user()` request path: untouched `origin/main`
reproduced its stale avatar-session failure at 30 checks / 1 failure, while the
merged release passed 30 / 0 before the remaining database suites ran.

Phase 3 is live through PR #40 / merge
`b0a67608af42dcd980c72f269f42fb4df04c024e`. It centralizes every timer,
reply, suggestion, and
seed write behind one idempotent time-entry service. Migration 011 snapshots
tenant/client ownership, keeps all legacy work pending, allows only one
pending-to-approved/rejected review, records immutable database events, and
prevents merges from rewriting time provenance. Owners/admins review pending
work; techs cannot. Reports and the restricted CSV use only approved billable
rows, and neither surface posts or invoices anything. The browser keeps a
stopped timer locally until the server acknowledges that exact entry key, so
offline retry and a stale reply do not lose time. Authentication stamps the
database-resolved tenant on every request, and each touched time/report
consumer binds directly to the authenticated user's tenant; regression gates
cover the prior tenant-1 fallback failure.

Release evidence: GitHub validation passed on exact head `4673bc6`; migration
011 was applied operator-first and independently verified at eleven columns,
seven named indexes, five tenant-scoped foreign keys, two checks, one immutable
event table, seven permanent triggers, and zero staging triggers. The one
legacy time row remains pending and gained one migration audit event without
inferred approval. The deployed critical-file hashes match the merge, all 72
production PHP files lint, Apache syntax is valid, login is 200, root is 302,
and no release-window web fatal was recorded. The server-only config remains
mode `640` with `demo_mode=false`. The runtime database account now has only
`SELECT`, `INSERT`, `UPDATE`, and `DELETE`; disposable probes proved DML and
denied `TRUNCATE`, `CREATE`, `ALTER`, and `TRIGGER`, then removed every probe
object. A real-runtime transaction canary created pending time, refused a tech
review, approved as owner/admin, appeared in the approved-billable report,
replayed without duplication, refused fact mutation, recorded both audit
events, and rolled back to the original one entry / one event. Protected
rollback records are
`/srv/8west/backups/safeharbor/20260826T223441Z-pre-phase3-hardening` and
`/srv/8west/backups/safeharbor/20260826T223914Z-pre-phase3-migration`.

Phase 3 hardening is live through Safeharbor PR #55 / merge
`bb580a293f89deab278473e986c2e1232d2bc0c1`. Exact-main Validate run
`33057438562` passed. Migration 016 adds one nullable append-only correction
pointer, a one-replacement unique guard, and two narrow database-owned
measured-time tables. A correction may point only to the same tenant,
technician, ticket, client, and source row after that row is rejected; the
replacement is a new pending entry and the rejected facts, review, and events
remain unchanged. Owners may still review their own time for a one-person MSP.

Measured intervals use half-open `[start, end)` semantics. Pending and approved
intervals block overlaps for the same tenant and technician; rejected intervals
do not, so their one correction may reuse corrected clock evidence. Persistent
UTC-day guard rows plus a locking read serialize competing inserts before the
overlap decision. The disposable MySQL suite passes 54/54, including a real
two-connection race where the second request waits and then loses after the
first commits; the migration-011 suite is now 98/98, including real
two-connection exact-retry and conflicting-review races. The browser
freezes one correction key and payload before sending, and the operator sees
the rejection, correction link, and replacement status on the Time page.

The fresh root-only rollback record is
`/srv/8west/backups/safeharbor/20260827T092220Z-pre-time-corrections`. Its
application/config/trigger-inclusive-database/runtime-grant `SHA256SUMS`
verified, the archive and dump-completed marker passed, and a scratch restore
proved 31 base tables, 43 pre-migration triggers, and unchanged time/event
counts of `1:1`. A Safeharbor-only Apache lock returned `403` during the
migration window without stopping the shared server.

The initial clean-working-tree migration copy was CRLF-equivalent at SHA-256
`6730158402a6e4539af48fb265bee05bf33e2f4d273bddd7cf299ff666c373c3`;
it is not mislabeled as the exact Git bytes. The separately archived exact Git
blob at SHA-256
`0f07b0da0ab4c9e68308cc3ddf2af4c7e4e2171be81682168202245c8395eb25`
was replayed after locking `safeharbor@localhost` and proving zero connections.
It emitted `1,1,1,1,12,0,0,0`, time facts stayed unchanged, and the account was
unlocked. Final database evidence is `1:2:12:0:1:1:0`: one correction column,
two auxiliary tables, twelve permanent triggers, zero staging triggers, one
time entry, one event, and zero measured-registry rows.

Matching code deployed from a clean detached checkout of the merge. All 128
non-stamp tracked application paths and all three normalized cache-stamp paths
matched it; the protected config stayed byte-identical at
`c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`.
The restored SSL vhost matches its pre-lock copy at
`8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43`.
Public login is `200`, an unauthenticated timer request is `401`, Coastmark
export remains disabled/empty. Business reports were still unconfigured and
empty at that release checkpoint. A fresh signed-in 8 West IT correction canary is still required; no
credentials or identity bypass were used to manufacture it. The exact contract
and canary are in `docs/technician-time-corrections-contract.md`.

Phase 4's deployed version 2 release was deliberately manual and draft-only.
Safeharbor's operator CLI selected one row by exact tenant slug + entry id +
immutable entry key, required approved billable reviewer-backed facts and exact
protected tenant/client allowlists, then sent a versioned HMAC payload to
Coastmark. Its
stable keys are `tenants.slug`, `safeharbor-client:<captured client_id>`, the
stored `entry_key`, and Safeharbor-local numeric user keys. Raw notes, subjects,
messages, people data, rates, tax, cents, invoice numbers, and posting commands
do not cross the seam. There is no scheduler, batch, or automatic retry.

The isolated correction-safe version-3 branch is based on the reviewed PR #84
stack head `6eba809d54c0e6f9cf647bbc0967ed7368fb8abc`. It adds durable
Safeharbor export claims and append-only delivery/status receipts. The
least-privilege CLI reads source facts, while migration-owned insert triggers
lock the tenant and exact time entry in the same order as adjustment creation;
the claim then captures only the next exact immutable source version. Original
approval is version 0; each correction names the exact predecessor event.
Exact claim replay is a no-op and changed facts conflict.

The operator separately sends one exact claim ID. A timeout or uncertain
response blocks redelivery until a signed Coastmark status check says exact,
conflict, or absent. Only absent permits a later explicit resend. There is no
batch, queue, cron, scheduler, loop, or automatic retry. Both claim and send
gates default off, and detached version-2 send remains permanently refused.
This is an intentional stacked dependency: PR #84 must merge and be revalidated
first. The version-3 diff must not be transplanted directly onto current `main`.

Coastmark's separately reviewed version-3 receiver owns the explicit mapping,
agreement rate, tax, payment terms, integer cents, immutable event evidence, and
source-managed draft lines. A correction uses the original Coastmark pricing
snapshot and adds only a signed difference line while the invoice is draft. A
full reversal is a negative adjustment line. If the invoice is no longer draft,
Coastmark records a manual exception and changes no line or total. Exact replay
is a no-op; changed facts conflict. Neither side can approve, post, send, create
Checkout, create a credit, record payment, or touch the ledger through this
integration.

The code is merged and deployed dark. Safeharbor PR #42 merged as
`ad7fb1ce31066a5e5ac8889a178832f4627d3a0c`; exact-main Validate run
`33022987271` passed. Its three deployed sender/test hashes match the merge,
all 75 production PHP files lint, the protected config remains byte-identical
and mode `640`, the sender gate is disabled/absent, and the real pending legacy
row is refused before any network request. Public login is 200, root is 302,
Apache syntax is valid, and release-window web fatals are zero. The protected
application rollback is
`/srv/8west/backups/safeharbor/20260826T232259Z-pre-phase4-sender/application.tgz`
(SHA-256 `13f893c330de2e2a1f8b50dee98e6d5f47f9722cd5eee06ec17ba173be9bc631`).

Coastmark PR #54 merged as
`8a7e95169e54063a6fe21373ceab9df6ca860360`; exact-main CI run
`33022976937` passed both quality and PostgreSQL. Production runs image
`coastmark-app:git8a7e95169e54` with migration batch 16 applied and no pending
migration. Both new tables have forced RLS and exactly one tenant policy;
immutable evidence/line triggers and least-privilege runtime table/sequence
grants passed. The receiver gate, secret, and fixed organization remain
unset/disabled, the endpoint returns 404 internally and publicly, and mappings,
imports, and Safeharbor source lines all remain zero. Existing client, invoice,
line, journal, payment, credit-memo, and billing-run counts did not change. The
verified rollback pair is
`/var/backups/coastmark/coastmark-20260826T232622Z.dump` (SHA-256
`1dcb5e20e27c3c0a1a7207bf801982a7f31a71b11b4c8d333a986a9c21702158`) and
`/var/backups/coastmark/coastmark-20260826T232622Z.private.tgz` (SHA-256
`d848b963e0594b14411173871ca559515cecff8ee1fa16b0947a2297781a4680`).

Production currently has zero Coastmark agreements and agreement lines, so no
truthful mapping target exists yet. Frankie approved a test-only Lifestyle rule:
`$145.00/hour`, exact approved minutes with no minimum or round-up, `Net 30`,
and `0%` only for separately itemized pure technician labor; parts, hardware,
software, licenses, and bundles stay separate/manual. Those facts are not
persisted or mapped by this branch. A version-3 canary still requires independently
green MySQL and PostgreSQL gates, an authorized two-repository release, and an
explicit disabled-then-reviewed Coastmark mapping. This branch has not been
pushed, merged, migrated, deployed, mapped, enabled, or called. The exact
contract and rollback gates are in
[`coastmark-approved-time-export-contract.md`](coastmark-approved-time-export-contract.md).

Phase 5A's base was merged and deployed dark through Safeharbor PR #44 / release
merge `3bb87fa`. Disabled-route hardening is deployed through PR #48 / merge
`748f16ca4648ece2ce767966af46cd2788bd3f7c`. The slice adds a separate
`/portal` OIDC session and the
maintained 8 West ID `oidc_v1` PHP kit, accepts only exact `client_owner`,
`client_admin`, `client_staff`, and `client_viewer` roles with the exact
`safeharbor` product, stores the signed subject/session-version pair for at
most eight hours, and fails closed when revocation state is more than five
minutes stale. At that checkpoint, 8 West ID's additive Safeharbor OIDC surface
policy was deployed as `f0ec49b` without a Safeharbor client registration or
customer-role assignment.

Migration 012 creates an immutable explicit binding lifecycle but no binding.
The CLI is prepare-disabled → inspect exact identity slug/provider
tenant/client → explicit enable or disable. Identity slug is globally unique;
the exact provider tenant/client is composite-FK-bound; blank, malformed,
duplicate, cross-provider, `8west`, and `internal` mappings fail closed. Each
authenticated request rechecks revocation plus the active binding, and ticket
summary queries bind both provider tenant and client. The surface shows only
ticket number, subject, status, priority, and timestamps/counts. It has no
messages, attachments, replies, AI, billing, technician-time, endpoint, or
ticket-detail access.

The base release merged as `3bb87fa39abe8973f2a7328c193e311a2c446f88`;
exact-main Validate run `33024372187` passed. PR #48 then moved the disabled
gate ahead of logout method/session handling and pinned that ordering in
`portal_auth_test.php`. Migration 012 was applied through the privileged
operator path and externally verified at two InnoDB tables, 10/11 columns,
6/3 indexes, 4/3 tenant-scoped foreign keys, one enforced slug check, seven
permanent triggers, and zero bindings/events. All prior business row counts and
the runtime identity's DML-only grants remained unchanged.

The base exact merge source deployed with all 93 then-current production PHP
files linting, Apache syntax valid, and 108/108 comparable tracked app-file
hashes matching. The protected config remained byte-identical through that
release and the later Phase 6 rollout at SHA-256
`dd165dff89e9f048e8bfd0ff06b1c3f636b452abf9569e045f5925db5fe7de81`.
The later portal-only dark scaffold deliberately changed the protected config
to SHA-256
`c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`.
At that checkpoint it was `ubuntu:www-data` mode `640`, contained the exact
issuer and callback with empty client fields, and had `portal.enabled=false`.
The private persistent revocation-cache directory existed with private
permissions and was empty. All portal routes returned 404 without `Set-Cookie`;
staff login was 200, root was 302, and the dark closeout introduced no
release-window fatal/parse/uncaught error. The current canary state is recorded
below and supersedes only those config and routing facts.

The protected rollback record is
`/srv/8west/backups/safeharbor/20260826T234825Z-pre-phase5a-portal`.
Its application archive SHA-256 is
`e7890c2145636942ab13a3a0dce44ef13749a2647f8e7a2bfdf8119424906131`;
the trigger-inclusive database dump SHA-256 is
`3d316813f6d7524ccdfbd20bb056f1f66c4ab7bd3f69c0df291c66bb0d336819`.
The verified code-first rollback keeps the portal false and leaves the two
empty additive tables in place; the database dump is disaster recovery only.

The 2026-08-28 controlled activation supersedes that dark checkpoint. One
confidential client was registered and transferred through the protected
operator path without recording its values. Global portal config is enabled at
SHA-256
`e272ccc44f8200774530fabda634b3d65032805ef360d6f73da3a91afc3fb0f4`, and
binding id 1 actively maps the approved Lifestyle identity tenant to provider
tenant 1/client 14. The canonical Safeharbor portal passed signed-out routing;
the support-domain `/portal` path remains 404. Fresh signed-in customer
acceptance, tenant isolation, revocation, disable-on-next-request, logout, and
desktop/mobile verification are still open, so no authenticated customer-data
read is claimed.

The root-only activation record is
`/srv/8west/backups/safeharbor/20260829T021900Z-pre-lifestyle-portal-canary`;
portal receipt SHA-256 is
`4e6d81f955f582171cea5f78c7ea98f92923e288ef6863c925fa157cfa1a6efc`.
8 West ID replacement subject `t4u10` is enabled. Predecessor `t4u7` is
permanently inactive, revoked, and quarantined; the root-only quarantine
receipt is
`/srv/8west/backups/identity-quarantine/20260829T011053Z/quarantine-receipt-20260829T015737Z.txt`
at SHA-256
`56ace0529b430e0f227d7ce9983976d8f1bf45840d05dab0c1aefd583b8e1c04`.
Coastmark exact merge `c9f44096af80a0a97b8415e3f4037c88867ecfab` is deployed live and fails
closed on a fresh signed revocation feed before its suite SSO may write or
reactivate anything. Financial counts were unchanged; the Safeharbor-time
receiver remains off/404, and mappings, imports, and draft lines remain zero.
That is an identity safety fence, not portal billing access.
See `docs/customer-portal-contract.md`.

Phase 6 is deployed dark through Safeharbor PR #46 / merge
`209bb421c2ea883f6434971544dc66556bff6167`. It adds an immutable version-1
weekly client-service definition, append-only schedule
versions, oldest-missing-period catch-up, exact JSON/text archives with SHA-256,
and a one-attempt delivery state machine. Report queries bind the exact
Safeharbor tenant/client and aggregate ticket counts, first-response facts,
versioned service-goal outcomes, approved billable operational minutes, and
CSAT. They do not select ticket subjects, message bodies, contacts,
attachments, technician/review notes, billing facts, endpoint controls, or AI
output.

Generation and delivery have independent global gates plus exact
tenant/client/recipient allowlists; `canary_only` defaults true. The runner is
separate from retrying `mail_queue`. Only an exact Microsoft Graph 202 is
recorded as `submitted`, which means provider acceptance rather than inbox
delivery. Ambiguous or expired send-boundary outcomes are terminal `uncertain`
and never retry automatically. A later schedule version revokes old pending
delivery, while report subjects and bodies remain the verified archived bytes.

Migration 013 defines five tenant-scoped tables, 27 indexes, 15 foreign keys,
15 checks, and 15 actor/state/immutability triggers. The hermetic suite is
50/50 locally; exact-head CI run `33027071016` and exact-main run `33027147921`
passed the destructive-name-guarded MySQL 8 suite that
replays fresh schema plus migration, verifies native exact-byte archive hashes,
exercises database guards and least-privilege runtime DML, and refuses report
history deletion. At the Phase 6 rollout production had all five empty report
tables and 15 triggers. The protected config was byte-identical at
`dd165dff89e9f048e8bfd0ff06b1c3f636b452abf9569e045f5925db5fe7de81`
with no report block. The later explicit disabled portal scaffold changed the
whole-file hash only; it added no report block or report state.

The follow-on 8 West ID report-contact consumer is live through Safeharbor PR
#57 / release `cef39dd190e6488c4aabf7a673eb810b4465a3eb`. Migration 017 is
applied and adds a separate immutable tenant binding and append-only contact-
snapshot table with six new guards, without replacing or weakening any of
migration 013's fifteen report triggers. Its dedicated server-only configs
were installed on both apps. A bounded, redacted probe authenticated stable ID
tenant key `ewid-t1` and contact version 1; both contact gates were turned off
again after preparation. Safeharbor then atomically stored the immutable
binding/contact evidence with definition 1 and schedule 1.

The earlier 8 West IT canary schedule key
`8west-it-weekly-canary-v1` reached version 2 active for Wednesday 09:00 in
`America/Los_Angeles`. PR #61 / merge
`8322266dbcf3eb6956eb9f1ca79a51948002aaec` made the schedule key its own
mandatory gate. Its no-write checkpoint had zero archives, deliveries, and
attempts; root-only evidence remains at
`/srv/8west/backups/safeharbor/20260827T143419Z-pre-report-schedule-key-gate`.

The client-scoped lane is now live through PR #68 / exact merge
`7bf63edb9bc0583cf865124b45f002d0717dcab9` and migration 019. Exact-main
Validate run `33226867525` passed. Production proved three new tables, twelve
protected permanent guards, and zero temporary swap guards from the exact
migration blob SHA-256
`5dceec1c10a8e79515da9a3296e5ce0388fcc1e5e2206759e7ac1cadf77efbfe`.
The root-only release backup is
`/srv/8west/backups/safeharbor/20260829T015329Z-pre-client-report-contact-019`;
receipt SHA-256 is
`a044d06c949c473f1fec13fe76b0fd27d4c37486dd2c365de874d83932923a97`
and final evidence-manifest SHA-256 is
`24a07bcb4ea347eb65831348df1ac309e7c662d61cf717c27b5729ba5b6b4f6f`.

Safeharbor client 14 now has the exact ID tenant binding and one immutable
contact snapshot. The contact endpoints were turned back off after preparation;
only recipient SHA-256
`4f57f85e0af372af6b3b09bf48be8961a23fa0fc371f36a9b2d3a54ee4998ef8`
is recorded. The latest version of each Lifestyle schedule key is disabled.

Archive 1 (`8west-lifestyle-weekly-canary-v1`) has SHA-256
`93bbdab3ac0d42e1da95fa93f8c393123749d7d1548f4a2afa62e95317a8b0ce`;
its attempt is terminal `uncertain` with `graph_not_trustworthy` and no HTTP
status. Archive 2 (`8west-lifestyle-weekly-canary-v2`) has SHA-256
`10ed1d4cca108204705cdd9584d9a5bc824c1dbfcfbcfab26fceb2a6cb8562ae`;
its attempt is terminal `uncertain` with Graph HTTP 404 and
`graph_send_rejected`. Never retry either uncertain attempt. Archive 3
(`8west-lifestyle-weekly-canary-v3`) has SHA-256
`d404de18f59d1546f77fd38ec0ff5071b3194124184f8c903c3d8f989381a909`;
its attempt is `submitted` with Graph HTTP 202 and `graph_accepted`. That is
provider acceptance only, not inbox-delivery proof. Generation and delivery
are off, `canary_only` is true, and no scheduler exists.

The root-only Lifestyle canary record is
`/srv/8west/backups/safeharbor/20260829T020451Z-pre-lifestyle-report-canary`;
receipt SHA-256 is
`86fd9852e7defbe1b809895a6bc2529e9fae9f9acea3f1b274b347fa4b524492`.
The contract and retry boundary are in `docs/business-reports-contract.md`.

The Milepost managed-customer receiver foundation shipped through Safeharbor
PR #52 / merge `d270343dff6bfd3e65e263b693f2687e5f508bd0`, and its receiver is
enabled for only the dedicated signed identity and exact `8west` tenant. It
does not move tickets, messages, time, service goals, portal state, reports,
billing, or endpoint controls. Exact-main Validate run `33047096931` passed.

Migration 015 is applied in production. Its schema has 24 columns, 11 indexes,
10 enforced checks, five foreign keys, and eight lifecycle/immutability
triggers across its two tables. A controlled version-1 `8west` canary created
Safeharbor client 13 for Milepost customer
`4ebaeefa-b101-47f8-ac76-e49ab309d272` and recorded exactly one binding and one
immutable receipt for event `dce77b64-e382-466d-b4e3-5cf5bd7ce74b`. The 277
existing tickets and 541 messages were preserved, and the new client has zero
operational edges. Milepost PR #410 / production release
`bc0110614605f4f615b44d4061147100f0eccf32` then enabled the tenant-wide route
for exact tenant `8west`. Supported rename events v2 and v3 were delivered in
order, restoring the real name `8 West IT`; both sides now finish at source
version 3 with all three immutable receipts. Milepost has exactly one
root-owned one-minute customer dispatcher, and its first scheduled tick found
zero due work. PR #411 / `ae0f97ab7163fd2d8dfd6d8a2674c206c7df6201`
records that activation; exact-main Milepost CI run `33078622748` passed.
That original Safeharbor closeout did not claim Logbook activation. The
protected foundation rollback record is
`/srv/8west/backups/safeharbor/20260827T065222Z-pre-customer-sync-foundation`.
The initial canary and lifecycle contract are in
`docs/milepost-customer-sync-contract.md`; any sender-off/no-scheduler wording
still present there is historical and must not override the later PR #410/#411
activation evidence above.

The later cross-application audit confirms that Milepost customer propagation
is live into both Safeharbor and Logbook. It is not yet a suite-wide fan-out:
Lifestyle has a historical peer-tenant conflict that must be reconciled rather
than overwritten, and neither Coastmark nor Control Panel auto-provisions a
Milepost customer. Creating a Milepost customer therefore must not be described
as creating it in every app yet.

## First-tenant suite SSO repair

Safeharbor PR [#31](https://github.com/Seckcey/8_west_helpdesk/pull/31)
merged as `7d607ecb78a1edee800105725302926ff4503045` and is deployed. It
maps the issuer's canonical `msp_owner`, `msp_admin`, and `msp_tech` roles to
Safeharbor's `owner`, `admin`, and `tech` database enum values. `msp_viewer`
and all downstream `client_*` roles remain refused. Existing suite-linked
users have their local role reconciled on sign-in instead of retaining a stale
privilege.

This repair was required because self-serve 8 West ID onboarding issues
`msp_owner`, while the older consumer admitted only `owner`, `admin`, and
`tech`. Production logs recorded `role_not_admitted`; the cookie signature,
product entitlement, and tile URL were not the failure.

The deployed `app/lib/suite_roles.php` SHA-256 is
`5278b8ef09e56fba032a169aa446c756a326bf0f06f793dc1ff609dd39b6fcd3`,
matching the merged source. `suite_roles_test.php`, PHP lint, public login, and
release-window fatal checks passed. Rollback is the verified application and
database backup `/srv/8west/backups/safeharbor/20260812T080037Z`.

The owner-operated tile check was completed during the 2026-08-23 RS256 issuer
cutover: a fresh signed-in 8 West ID session landed in Safeharbor without a
second login form.

## RS256 suite-token verification

Safeharbor PR [#34](https://github.com/Seckcey/8_west_helpdesk/pull/34)
merged as `2f64cdf67aa0e9d1999ddcca5476d48b9fcb9003`; exact-main CI run
`32623126269` passed and that commit is deployed. Production accepts `RS256`
only, loads the issuer's same-origin JWKS, and verified a fresh RS256 cookie by
exact published `kid` after 8 West ID changed issuance at
`2026-08-23T07:04:43Z`.

The suite-wide contraction completed at `2026-08-24T04:17:47Z`, after more
than 21 hours of clean observation. Safeharbor refused a generated legacy
HS256 token with `unexpected_algorithm` and returned the ordinary login page;
public login remained HTTP 200. The configuration backup for that change is
`/srv/8west/backups/safeharbor/20260824T041747Z-pre-rs256-only-config.php`.
Safeharbor retains the shared secret only for the separately HMAC-signed
revocation feed. A fresh post-contraction ID session entered the Safeharbor
queue without another login. The application-release rollback remains
`/srv/8west/backups/safeharbor/20260823T063315Z-pre-2f64cdf67aa0e9d1999ddcca5476d48b9fcb9003`.

## Who is allowed to call us, and are they?

| Identity | Endpoint | Product | Active | Registered |
|---|---|---|---|---|
| `milepost` | `alerts.php` | Milepost (RMM) | yes | 2026-07 |
| `milepost-customers` | `customers.php` | Milepost customer directory | yes, exact `8west` | 2026-08-27 |
| `coastmark-support` | `support.php` | Coastmark, 365 edition | yes | 2026-08-09 |
| `waypoint-support` | `support.php` | Waypoint — **standalone, not a suite app** | yes | 2026-08-09 |

`milepost-westy` and `controlpanel-westy` are **not registered in production
yet**, even though receiver release `da9560a` is deployed. The receiver
authority admits only the first identity and
only for exact `app=milepost` + `event=failure`; the Control Panel identity is
reserved but not authorized. Registration, a protected dedicated secret, and a
Milepost sender canary remain separate production gates.

Verify:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT service, is_active, last_seen_at FROM svc_identities ORDER BY service"'
```

`last_seen_at` is the honest signal: it updates on every successfully signed
request, so a stale timestamp means that emitter has gone quiet.

## Partner support intake — the full picture

Contract: `coastmark-support-intake-contract.md` (filename kept so existing
links work; it covers both producers).

- **Both apps went live 2026-08-09** after canary tickets #205 (Coastmark) and
  #206 (Waypoint) passed on disposable slugs. Both canaries were deleted the
  same day; nothing of them remains.
- **The reply path is proven end to end** — acknowledgement emails carrying
  `[#205]` / `[#206]` arrived, which means a tech's reply reaches the requester
  and their answer threads back onto the ticket.
- **Client row naming is settled.** We append `" ({Product})"` to whatever
  `tenant.display_name` arrives. Both emitter teams checked their real tenant
  data (Coastmark 2 tenants, Waypoint 4, longest names 47 and 30 characters,
  none containing the product name) and confirmed they send the bare company
  name. Rows read "Acme MSP (Coastmark)". **Keep the append — do not add a
  de-duplication guard.** The canary's doubled name was the canary's own fault.
- **Routing is by `clients.source_key`** (`coastmark:{slug}` /
  `waypoint:{slug}`), never by name, so staff can rename a client row freely.
  One firm using both products correctly gets two rows. That key must fit **64
  characters**; since 2026-08-09 an over-long one is refused with a `422`
  rather than truncated, because a cut key would merge two of a product's
  customers onto one client row.
- **The caller list is config, not code** (since 2026-08-09,
  `support_intake.sources`). Adding product number three is a config line, an
  identity row and a secret — no code change, no PR, no deploy of this repo.
  Leave the key out and the built-in defaults apply, which is exactly what
  production does today; an empty list means nobody at all. A malformed entry
  is skipped and named in the error log, so a typo shows up as a log line
  rather than as an unexplained `401` at the emitter.

Verify the schema this depends on:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT id, name, source_key FROM clients WHERE source_key IS NOT NULL"'
```

## Westy thumbs up / thumbs down

Shipped 2026-08-09. Two icon buttons under every real Westy answer, replacing
the old underlined "This wasn't helpful" link. **No migration** — this deploys
as plain code.

The two are deliberately not symmetrical, and that asymmetry is the feature:

- **Thumbs-down** is unchanged from the link it replaced. It opens the review
  box, the technician sees the exact question and answer, edits or cancels, and
  only then does `api/westy_feedback.php` open a ticket.
- **Thumbs-up** is one click and over. No box, no confirmation, and **no
  ticket** — `api/westy_thumbs_up.php` writes a single `assistant_log` row and
  stops. Tickets are for problems; praise that made somebody work would stop
  being praise. The browser fires it and forgets it, so a failure never
  interrupts a technician.

It stores no chat text, only lengths and the question fingerprint — one click
with no review box means nobody read those words and agreed to send them. The
fingerprint is the same one the flag path uses, so the two signals about a
question can be read against each other:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT meta, created_at FROM assistant_log WHERE action = \"westy_thumbs_up\" ORDER BY id DESC LIMIT 10"'
```

`westy_report_test.php` covers this, and the check worth knowing about is
`thumbs_up_creates_no_ticket`. If that ever goes red, the button has grown
teeth.

## Database

Applied in production: **001 through 019**, including both files numbered 002.
`009_support_intake` (`clients.source_key`, `svc_support_rate`) was applied
2026-08-09. Migration `010_service_goal_policies.sql` was applied before the
matching code on 2026-08-26. It is additive and leaves historical ticket
pointers NULL.

Migration `011_time_entry_approvals.sql` was applied operator-first on
2026-08-26 before the matching Phase 3 code. Production has the complete
approval schema, seven permanent immutability/audit triggers, and the original
legacy row remains pending. The migration replay and real-runtime rollback
canary evidence are recorded in the Phase 3 section above.
Migration `016_time_corrections_overlap.sql` was applied migration-first on
2026-08-27 and replayed from the exact merged Git blob. Production has its one
correction column, two InnoDB auxiliary tables, twelve permanent triggers, zero
staging triggers, and zero correction/measured-registry rows before the signed-in
canary. The one legacy time row and event remain unchanged.
Migration `012_customer_portal.sql` was applied operator-first on 2026-08-26.
Production has its two exact tables, seven lifecycle/audit triggers, and
enforced tenant/client ownership constraints. Its initial state had zero
mappings/events and a disabled portal. The later Lifestyle canary has active
binding id 1 and the global gate enabled; authenticated customer acceptance is
still open.
Migration `013_business_reports.sql` was applied operator-first on 2026-08-26.
Production has five exact tenant-scoped tables, 27 indexes, 15 foreign keys,
15 checks, and 15 lifecycle/immutability triggers. It retains the earlier 8 West
IT evidence and now also holds the Lifestyle logical schedule's three immutable
versions, one verified archive, one delivery, and one terminal uncertain
attempt. Latest Lifestyle version 3 is disabled. Generation and delivery are
both disabled, and there is no server scheduler.
Runtime `DELETE` remains limited to the eight inventoried legacy operational
tables.

Migration `015_suite_customer_sync.sql` was applied operator-first on
2026-08-27. Production has its two exact tables, 24 columns, 11 indexes, 10
enforced checks, five foreign keys, and eight triggers. They now hold one exact
8 West IT customer binding and three immutable event receipts for Safeharbor
client 13. The dedicated Safeharbor receiver remains limited to exact tenant
`8west`; Milepost's exact-tenant sender and one-minute dispatcher are live, and
both customer records finish at source version 3 with the real `8 West IT`
name. That migration closeout did not claim Logbook activation; the later
cross-application audit above confirms its receiver is live while the Lifestyle
historical peer-tenant conflict remains open.

Migration `017_id_report_contact_evidence.sql` was applied operator-first on
2026-08-27 and replayed from the exact merged Git blob. Production has its two
evidence tables, six permanent triggers, one immutable `ewid-t1` tenant
binding, and one contact-version-1 snapshot. Both dedicated contact gates are
off after the redacted onboarding probe; the address and HMAC keys remain
protected server-side.

Migration `019_client_report_contact_evidence.sql` was applied and replayed
migration-first on 2026-08-28 from exact PR #68 merge `7bf63ed`. It adds two
client-scoped evidence tables plus one
immutable `(tenant_id,schedule_key)` contact-scope registry: ten foreign-key
constraints and six enforced checks across the three tables. It safely
replaces twelve protected insert/immutability guards behind twelve temporary
fail-closed replay guards, then requires twelve permanent replacements and zero
swaps. Production postflight proved all three tables, twelve permanent guards,
zero swaps, and preserved migration-017 evidence. It now holds the exact client
14 identity binding, one immutable client contact snapshot, and the logical-
schedule scope rows needed by the two report lanes. Both contact endpoints are
off after preparation.

Migration `018_ticket_auto_close_eligibility.sql` was applied and replayed
migration-first on 2026-08-27 from release `e7ee521`'s exact Git blob. It adds
one default-zero ticket column, one enforced check, and six permanent ticket,
message, and time-entry guards. Production proved one column, six guards, zero
temporary swap guards, and 22 strict historical candidates without changing
any pre-existing ticket status. Signed canaries proved untouched recovery
closes and human-touched recovery does not; exact cleanup returned the original
277-ticket/541-message state.

`db/schema.sql` does **not** carry any `svc_*` object. A fresh install needs
`schema.sql` + `002_svc_intake.sql` + `009_support_intake.sql`; the live
automatic-close contract additionally requires migration 018.

Verify:

```bash
ssh milepost-ec2 'sudo mysql -N safeharbor -e "SHOW TABLES"'
```

Expect `svc_identities`, `svc_rate_buckets`, `svc_support_rate` and
`westy_reports` among them.

## Tests

Thirty-three CLI suites live in `app/tests/`. Eighteen server-free contract suites
run in CI, including the hermetic service-goal, approval-time, approved-time
export, portal auth/revocation, portal read-only/rendering, and archived-report
gates plus the Milepost customer-sync contract. CI also runs the portal,
service-goal-policy, business-report, and customer-sync migration/isolation
suites on disposable MySQL 8, plus the signed alert handler and migration-018
automatic-closure guards. The 98-check `time_entries_mysql` suite is now
standalone too: it requires an explicit disposable-server acknowledgement,
accepts only a `safeharbor_time_test*` database base, creates and proves one
random per-run database, removes it in `finally`, exits nonzero when the fixture
or cleanup is unavailable, and runs in Validate with dedicated MySQL credentials.
It holds each pending review row, proves two separate worker connections are
waiting on that exact table, then verifies one exact retry is acknowledged as
a replay while a differently worded/decided loser receives `409`; each race
writes exactly one review audit event.
The separate 54-check `time_corrections_mysql` suite creates another random
scratch database, proves migration 016 from both fresh schema and migration
011, verifies the emitted operator postflight, deliberately interrupts both
the five-trigger auxiliary replacement and seven-trigger parent/audit
replacement to prove ten fail-closed write guards and exact replay recovery,
forks a second PHP/MySQL connection for the losing overlap race, and drops the
exact database afterward. The server-free approval-time suite is 105/105.
The append-only adjustment suite adds 44 server-free checks plus a separately
acknowledged disposable-MySQL fixture. It proves fresh install and upgrade,
exact schema/guard definitions, replay, interrupted guarded replacement,
least-privilege direct DML, immutable original evidence, and real competing
version/key races before removing its exact scratch database.
Existing integration suites (`suite_sso`,
`svc_intake`, `svc_support`, `westy_report`, and `intake_service_goal`) need
MySQL plus a scratch-only `config/config.php`;
`utf8_input` needs the scratch config but does not touch the database.
`portal_mysql_test.php` is standalone, refuses any database name not beginning
`safeharbor_portal_test`, and receives only disposable CI MySQL credentials.
`business_reports_mysql_test.php` similarly refuses names outside
`safeharbor_report_test*`, creates that exact scratch database with CI's MySQL
operator, and proves the report lifecycle again through a temporary
least-privilege identity. It also proves both orders of the persisted-report
versus approved-time-adjustment race: the shared tenant lock decides which
fact wins, and MySQL UTC is captured only after that lock so a waiting report
cannot backdate past a committed correction.
`id_report_contacts_mysql_test.php` separately requires an explicit disposable-
server acknowledgement and a loopback MySQL endpoint. It creates a random
`safeharbor_id_report_test*` database plus random runtime identity and removes
both in an outer `finally`. It builds the report tables from the byte-frozen
migration 013 before first-applying 017; rejects exact index, foreign-key, check-
definition, and check-enforcement drift; interrupts replay after the six
permanent guards are removed and proves all six write classes remain blocked;
then uses two real processes to prove same-version/different-recipient inserts
serialize on the immutable binding row. The canonical prepare path also runs
through the temporary least-privilege identity.
The same suite now first-applies migration 019 after 017, rejects client-table
index/foreign-key/check drift, interrupts and recovers all six client-table
write guards, proves exact replay and version monotonicity, refuses cross-
tenant/client reach and ID-key reuse, and exercises client preparation through
the same delete-free temporary runtime identity. The server-free ID and report
suites separately pin exact config parsing, no-inference routing, atomic
disabled preparation, and idempotent replay.
PHP lint and the database-free suites run on the Windows dev machine.

The scratch application identity skips migration 010's four trigger
statements because binary-logged MySQL requires an operator privilege to
create them. Before committing or deploying that migration, run it through
the same privileged operator path used in production and prove all four
policy/target UPDATE and DELETE attempts fail with SQLSTATE `45000`; the final
postflight row must be `1 / 1 / 1 / 1`.

Run them on the EC2 box, in a disposable copy, with the database pinned to the
scratch schema — `fresh_schema()` drops every table in whatever database it
reaches, so the pin is not optional:

1. `tar -czf - --exclude='app/config/config.php' app` into a fresh `/tmp/<dir>`
   on the box, `--strip-components=1`.
2. Write `<dir>/config/config.php` as a wrapper that `require`s the real server
   config and then sets `$c['db']['name'] = 'safeharbor_test'`.
3. `cd /tmp/<dir> && php tests/<suite>.php`.
4. **Delete the directory afterwards** — that wrapper pulls in real credentials.

### Technician time has a rendered browser test; Westy still needs one

`tools/shots/time-contract.test.mjs` now executes the real `app.js` in pinned
Chromium during Validate. Its twelve contracts prove stopped-timer and
correction retries, exact review and adjustment acknowledgements, a complete
manager-visible correction-slip history, frozen uncertain retries, editable
validation failures, and conflict reconciliation. That is behavioral browser
proof rather than a source-shape assertion.

Westy still has no equivalent rendered contract. That half *is* checkable on
the Windows machine, and it is worth doing whenever `westy.js` changes:

1. Copy `app.css` and `westy.js` into a scratch directory and add a page with
   the same scaffold `lib/westy.php` renders (`#westy-root` and its children,
   `data-onboarded="1"` so the tour stays out of the way).
2. Replace `window.fetch` **before** the `westy.js` tag, returning canned JSON
   and recording every call. The real code path then runs unmodified.
3. Serve it — `php -S 127.0.0.1:8899 -t <dir>` — and drive it in a browser.
   `file://` will not do; the tooling blocks that protocol.

The thumbs work (PR #28) shipped two bugs' worth of proof that this is not
ceremony. Both were invisible to PHP tests and to reading the diff: an author
`display` on the button beat the user agent's `[hidden] { display: none }`, so
neither thumb could hide; and a new `row` parameter turned out to be the same
variable as a `var row` further down the same function, so Cancel reset the
wrong element.

Last full integration run, 2026-08-09, the then-current seven suites ran back
to back on one scratch database, all green:

| Suite | Checks |
|---|---|
| `svc_support_test` | 87 |
| `westy_report_test` | 125 |
| `svc_intake_test` | 47 |
| `utf8_input_test` | 25 |
| `suite_sso_test` | 19 |
| `suite_auth_policy_test` · `suite_logout_test` | pass/fail only |

Two suites had **never once executed** before 2026-08-09 — they died on a
foreign key before their first check, so features shipped with tests that only
looked green. If you inherit a suite you have not personally watched run,
assume it has never run.

## Known open items

These do not block the deployed dark code. They do block activation where
called out, and none may be satisfied by inventing customer or financial facts.

1. **Westy external delivery is not activated.** Production has no
   `milepost-westy` or `controlpanel-westy` identity row. Release `da9560a` is
   deployed and grants only failure-only Milepost authority; a dedicated
   secret, Milepost durable sender, exact `8west` canary, and separate
   production activation are still required. Control Panel remains outside
   this slice.
2. **The `002` numbering collision** (`002_svc_intake` vs
   `002_westy_onboarding`) means migration numbers are not a reliable order.
3. **Production has no `support_intake` block in its config at all.** Support
   intake runs entirely on code defaults — which is fine and deliberate, but it
   means the first person to add product number three will be *creating* that
   block, not editing it. Copy the shape from `config/config.sample.php`.
4. **Technician-time correction acceptance needs a fresh signed-in session.**
   Use the 8 West IT tenant to prove overlap conflict, exact adjacency,
   rejection, one idempotently replayed correction, owner self-approval, and
   unchanged parent/event history. Keep it nonbillable and leave the append-only
   canary as audit evidence; do not bypass identity or enable Coastmark.
5. **The enabled Lifestyle portal needs fresh authenticated acceptance.** The
   confidential client, protected values, global gate, and exact binding id 1
   are live. Frankie must still sign in fresh and prove ticket-summary
   isolation, revocation, logout, and desktop/mobile behavior. Billing, ticket
   detail, mutation, and endpoint control remain out of scope.
6. **The Lifestyle report canary stopped at the uncertain boundary.** Its one
   archive is verified, latest schedule version 3 is disabled, and the only
   attempt is terminal `uncertain` with no trustworthy Graph result or provider
   HTTP status. Never retry that attempt. A future run needs a new archive and
   separate approval; provider acceptance and inbox delivery each need their
   own evidence before any scheduler is installed or allowlist widened.
7. **The correction-safe Coastmark seam is local review work and still needs a
   Coastmark agreement and mapping.** Durable version-3 claims, receipts,
   status recovery, and correction/reversal events are implemented in isolated
   branches, but no production agreement or mapping exists. The approved
   test-only rule is `$145.00/hour`, exact approved minutes, no minimum or
   round-up, `Net 30`, and `0%` for separately itemized pure labor only; excluded
   products remain manual. It may create only
   draft invoice lines; it must never approve, post, send, create Checkout,
   create a credit, record payment, or touch the ledger automatically.
8. **Customer creation is not yet suite-wide.** Milepost propagation is live
   into Safeharbor and Logbook, but Lifestyle has a historical peer-tenant
   conflict. Coastmark and Control Panel have no customer auto-provision lane.
   Reconcile the conflict explicitly; never overwrite it to make counts match.

## Traps that have already cost time

- **`env_file` in Docker Compose is read at container creation.** A
  `docker restart` does not pick up a new secret; only `docker compose up -d`
  does. A correctly installed secret will still return `401` after a mere
  restart. This bit Waypoint during go-live.
- **Never hand-edit the deployed tree.** `deploy.sh` untars straight over
  `current/`, so a host-side edit vanishes without comment on the next release.
- **SSH uses the origin IP, not the domain** — Cloudflare proxies the hostname
  and port 22 times out. The `milepost-ec2` alias handles this.
- **Deploy only from committed `main`.** Two checkouts once overwrote each
  other's files on the same box.
- **Check `git fetch` and open PRs before starting.** Two sessions built
  Waypoint support the same day.
