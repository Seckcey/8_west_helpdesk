# Westy ownership in ticket lists

The workflow card named Westy, but Queue and client ticket rows still used the
human assignee column alone and could show an unassigned avatar. Ticket detail
also paired the Westy label with that unassigned avatar. The shared renderer
now shows the Westy service owner consistently without creating a human user.

List enrichment reads current workflow ownership in batches of at most 500
eligible tickets. Tenant, client, ticket status and current human assignment
must all match. Human takeover, escalation, closure or mismatched scope never
displays active Westy ownership. Existing rows/order and truly unassigned work
remain intact. Missing workflow storage before migration is a normal fallback;
other database failures are not suppressed. Historical ownership remains
visible when the integration is disabled.

Validation: 15 executed SQLite/renderer checks; five actual-asset Chromium
checks, including 1440px and 390px queue rows and existing takeover/reply
contracts. Screenshots were inspected after the row animation completed.
Existing workflow (63) and billing (29) checks also passed. Full exact-head and
exact-main CI and deployment receipts are recorded at closeout.

This is a presentation change with read-only queries. It changes no migration,
ticket assignment, ownership receipt, configuration, worker, command or billing
authority. Production workflow activation remains separately controlled by
the [workflow release packet](westy-workflow-release-packet.md).

## Released and verified

[PR 114](https://github.com/Seckcey/8_west_helpdesk/pull/114) merged and source
`3df85419cc5bf48b846b25717071d57a3547048e` was deployed on September 7, 2026.
Exact-head Validate `34111482809` and exact-main Validate
[`34112074770`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34112074770)
passed. The source artifact is
`e056ee0089180c001a79855146e20ced5042960a2d0e15d97a727e20d4ed65cd`;
the complete deployed artifact is
`4f57b4f691f19674fa5877a6f8be91feeaebd90bb59446d058448f8ec2d368f3`.

The verified protected backup is
`/srv/8west/backups/safeharbor/20260907T103622Z-pre-queue-owner-3df85419cc5b`.
Application/config/database, schema, grants, report activation/cron, vhost
hashes and release identity were preserved there. The earlier incomplete
backup at `20260907T103556Z-pre-queue-owner-3df85419cc5b` is not the release
backup. The existing report scheduler was restored and verified active under
`/root/safeharbor-report-scheduler-3df85419cc5bf48b846b25717071d57a3547048e`.
Config, normalized schema, runtime grants, cron bytes, recipient/customer
scope and both vhosts match the preflight record. Production remains at 44
base tables and 87 triggers; migration 025 objects and both new gates remain
absent/off. The disabled billing worker exits successfully without work.

Fresh signed-in Chrome acceptance passed Queue → active ticket #151 → client
#7 at desktop and phone widths. The deployed queue says “Owner”; existing
unassigned tickets remain unassigned. Phone document width is exactly 390px.
The fresh post-release tab reported no console errors or warnings. No forms,
messages, assignments, timers or billing actions were submitted. Active Westy
ownership itself remains covered by synthetic renderer/browser tests until
the separately controlled workflow activation.
