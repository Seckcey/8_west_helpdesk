# Service-goal policy publication contract

Safeharbor owns service-goal policies, target selection, ticket snapshots, and
first-response reporting. This contract adds a controlled publication path for
the existing Standard and Premium policies. It does not add a second policy
owner, a customer-facing editor, a business-hours calendar, resolution goals,
waiting-time pauses, or ticket rebasing.

## Supported policy shape

Every published version is fixed to:

- policy key and display name: `standard` / `Standard` or `premium` / `Premium`;
- exactly four targets: `low`, `normal`, `high`, and `urgent`;
- a whole-number first-response target from 1 through 525,600 minutes (one
  year) for each priority; this technical ceiling prevents a policy value from
  generating an out-of-range MySQL `DATETIME` deadline in normal operation;
- elapsed time, UTC, no waiting pause, and a NULL resolution target;
- an exact future effective time in `YYYY-MM-DDTHH:MM:SSZ` form;
- the next sequential version number;
- one active Safeharbor owner/admin actor in the exact tenant; and
- a nonblank immutable reason of at most 500 characters.

Migration 010's lazy v1 baselines remain the only exception to actor and reason
attribution. They are exactly Standard at 480 minutes and Premium at 120
minutes for all four priorities, effective from the Unix epoch. Existing v1
rows remain NULL-attributed rather than inventing a historical approver.

## Operator surface

There is no web editor or write API. The only supported writer is the
server-side CLI:

```text
php db/manage_service_goals.php inspect --tenant-slug=slug --policy-key=premium

php db/manage_service_goals.php plan --tenant-slug=slug --policy-key=premium \
  --expected-current-version=1 --effective-from=2026-10-01T00:00:00Z \
  --low-minutes=N --normal-minutes=N --high-minutes=N --urgent-minutes=N \
  --actor-user-id=N --reason="approved change record"

php db/manage_service_goals.php publish [the exact plan options] \
  --plan-sha256=the-lowercase-digest-returned-by-plan
```

`inspect` and `plan` do not write. `plan` returns canonical JSON plus its
SHA-256. `publish` begins a transaction, locks the exact tenant, actor, and
latest policy row, rechecks the actor's active owner/admin status, expected
current version, and future effective time, recomputes the canonical plan, and
refuses unless the digest is identical. It then inserts one version and all four
targets, re-reads the exact persisted facts, and commits. Any target failure,
digest change, stale version, or concurrent winner rolls the full transaction
back. The CLI never calls the session-based `tenant_id()` fallback.

The effective time and response minutes are business decisions. Migration and
code deployment deliberately create no v2 policy and do not supply placeholder
values.

## Signed-in history surface

`/service_goals.php` is a GET-only staff view, not another writer. It derives
the tenant only from the active database user returned by `require_login()`;
there is no tenant request parameter. Active Safeharbor owners, admins, and
technicians may read their own tenant's history. Unknown, inactive, and
customer roles fail closed.

The page shows Standard and Premium separately. Each immutable version shows
its exact effective UTC time, current/scheduled/superseded state, all four
first-response targets, and its actor/reason when those facts exist. The state
uses the database UTC clock, so a newly published future version is labelled
scheduled rather than being mistaken for current. Lazy v1 rows are labelled
`Legacy v1 attribution`: no historical approver or reason was recorded, and
the page does not invent one.

The page accepts no GET choice, form, POST, API call, editor, or publish
action. In particular, it never calls the lazy v1 initializer; a tenant with no
policy rows sees an empty history and a read causes no write. It states the
currently supported behavior exactly: elapsed UTC first-response time, no
waiting pause, no resolution goal, and no business-calendar claim. Ticket
snapshots remain unchanged.

## Database invariants

Migration `014_service_goal_policy_publication.sql` is operator-first and
requires trigger privilege. It adds nullable `created_by_user_id` and `reason`
attribution, a tenant-scoped non-cascading actor foreign key and index,
enforced version/attribution checks, an enforced bounded response-range check,
an enforced NULL-resolution check, and permanent INSERT guards. Migration
010's UPDATE/DELETE immutability guards remain in place. Replay validates the
exact foreign-key actions and check expressions/enforcement plus each guard's
table, event, timing, row orientation, and normalized body before accepting it.

The database refuses:

- unsupported keys, display names, clocks, time zones, pause modes, or
  resolution targets;
- response targets outside the 1-through-525,600-minute operational range;
- malformed lazy v1 rows;
- skipped or repeated later version numbers;
- effective times that are not later than both the database clock and latest
  published version;
- inactive, technician, missing, or cross-tenant actors; and
- all updates or deletes of policy versions and targets.

MySQL cannot require exactly four child rows at transaction commit without a
different staging model. The CLI transaction is therefore the only supported
writer; it inserts and re-reads all four before commit. Runtime target
resolution already fails visibly on an incomplete effective version instead
of falling back to an older target.

## Ticket and client behavior

New tickets continue to snapshot one immutable target ID, open time, and
deadline. Publishing a policy never updates a ticket. Client-tier, priority,
waiting, retry, refire, reply, and later policy changes do not rebase an
existing ticket.

Owners and admins may choose a client's Standard/Premium tier. A technician may
still create a client and edit its unrelated name, domain, health, and notes,
but a forged `sla_tier` POST is ignored: new clients remain Standard and an
existing client's tier remains unchanged. Both pages retain signed-in session
and CSRF enforcement.

## Release and acceptance gates

Before deployment:

1. Run PHP lint plus the hermetic service-goal, publication, ticket-producer,
   identity, time, portal, and report suites.
2. Run the destructive-name-guarded disposable MySQL suite. It must replay
   fresh schema and the migration, exercise the migration-010 upgrade path,
   verify actor/check/trigger shapes, refuse adversarial same-name drift, prove
   rollback, true overlapping-publisher serialization, and both actor-demotion
   lock orderings, and show the DML-only runtime identity cannot run migration
   014.
3. Apply migration 014 through the trigger-capable operator path, then verify
   two attribution columns, the actor index/FK, four checks, and six permanent
   policy triggers. Existing version/target counts and every ticket's captured
   target/deadline digest must remain unchanged.
4. Deploy code without publishing a policy. A later publication requires the
   real approved response minutes, exact future effective time, actor, reason,
   reviewed plan digest, and a controlled boundary canary.
5. For a history-view code release, use a fresh signed-in 8 West IT staff
   session. Compare both rendered v1 policies and all eight target rows with a
   tenant-scoped read-only database query, prove another tenant cannot be
   selected, and prove version/target counts plus captured ticket target/deadline
   facts did not change. Signed-out access must return to sign-in. No migration
   or protected configuration change belongs to this release.

## GET-only history production release

Safeharbor PR #65 is deployed as exact release
`b02a7a63d71a4d1532f81aa727b0ede85c2649f3`; exact-main Validate run
`33088142569` passed. This was a code-only release with no application
configuration or migration change. The root-only backup and receipt are at
`/srv/8west/backups/safeharbor/20260827T154017Z-pre-service-goal-history-b02a7a6`;
its scratch restore proved 35 base tables and 60 triggers. Production matched
137 ordinary tracked application files plus three normalized stamp files, and
all 116 live PHP files linted.

The correct Safeharbor maintenance lock returned `403`. Postflight returned
login `200`, root `302`, signed-out `/service_goals.php` `302`, unauthenticated
timer `401`, unauthenticated Westy intake `401`, portal `404`, and the separate
support and ID host roots `302`. Protected configuration, grants, and database
facts were unchanged. The portal, Coastmark approved-time export, 8 West ID
report fetch, report generation, and report delivery gates remained off. No new
`fatal`, `Uncaught`, or `SQLSTATE` log hits appeared.

The initial runner installed the exact files and restored the locks but stopped
on an unrecorded final assertion. Immediate finalization rechecked every
postflight assertion and passed; the protected receipt records both runs. This
is deployment and signed-out route evidence only. A fresh signed-in production
8 West IT human still must verify the rendered Standard and Premium history and
all eight target rows before signed-in UI acceptance can be claimed.

No step in this contract authorizes merge, deployment, policy publication, or
production mutation by itself.
