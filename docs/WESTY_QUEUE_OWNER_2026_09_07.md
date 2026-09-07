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
