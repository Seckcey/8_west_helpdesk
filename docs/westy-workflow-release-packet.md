# Safeharbor Westy release packet

Prepared September 7, 2026. This packet authorizes nothing by itself. No
production migration, new schedule, gate activation, ticket mutation or billing
delivery has occurred. The proposed release is [PR 112](https://github.com/Seckcey/8_west_helpdesk/pull/112).
Use its final green merged SHA, not the historical candidate SHA below.

## Verified source and live baseline

Candidate application and integration receipt fixes at
`ff1fe6bd68c0589b12e73e42c1c582308a8682f6` passed the full
[Validate run 34106424046](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34106424046).
The final candidate additionally makes the pre-migration card read quietly
return no card and adds its regression test. Require the final PR-head checks.

Read-only production inventory on the `milepost-ec2` alias verified:

| Fact | Observed value |
| --- | --- |
| Host | `ip-172-31-31-195` |
| App path | `/srv/8west/apps/safeharbor/current` |
| Live release | `56fced51319875e60041cf51655fde5208981a58` |
| Source artifact SHA-256 | `be18f131fbdf47d7411ccfebcedb5ca00374c3b94573a259e7a7aae4af92a147` |
| Deployed artifact SHA-256 | `7ed18347efe8234a926c55b3aa922cf58298cf14f0099f51bbc63c55a31d76f7` |
| Protected config metadata | `ubuntu:www-data`, mode `640` |
| Database | `safeharbor`: 44 base tables, 87 triggers |
| New workflow objects | All three tables, thirteen triggers and health function absent |
| New config gates | `westy_workflow` and `westy_billing_handoff` disabled or absent |
| Schedules | Existing business-report cron active; `/etc/cron.d/safeharbor-westy-billing` absent |

The release identity was read from the protected
`/var/lib/safeharbor-report-scheduler/releases/current-app-artifact.manifest`.
Reconfirm these facts at execution time. A change requires refreshing this
packet; do not apply against an assumed baseline. Protected config and grants
must stay in the root-only record, never chat or Git.

## Exact database scope

Archive the Git blob `app/db/migrations/025_westy_workflow.sql` from the approved
release. Its exact SHA-256 is
`701bfa5ff6a052785bcb504672b1cede74c8d35f635931cd59a15d7a85a93046`.
Working-tree Windows newline hashes are not the release artifact hash.

The migration creates three InnoDB tables: `westy_workflows`,
`westy_workflow_receipts`, and `westy_billing_outbox`. It adds thirteen permanent
triggers:

- `trg_westy_receipt_immutable_update`, `trg_westy_receipt_immutable_delete`
- `trg_westy_workflow_identity_update`, `trg_westy_workflow_immutable_delete`
- `trg_westy_billing_identity_update`, `trg_westy_billing_immutable_delete`
- `trg_westy_ticket_takeover`
- `trg_westy_message_insert_takeover`, `trg_westy_message_update_takeover`, `trg_westy_message_delete_takeover`
- `trg_westy_time_insert_takeover`, `trg_westy_time_update_takeover`, `trg_westy_time_delete_takeover`

It creates and removes one temporary privilege probe,
`trg_westy_025_preflight`; that trigger must be absent afterward. The three new
enforced checks are `ck_westy_workflow_version`, `ck_westy_workflow_resolution`,
and `ck_westy_billing_payload`. Scoped unique keys and foreign keys are defined
in the exact migration blob and verified against the disposable MySQL test.

It also installs the read-only `SQL SECURITY DEFINER` function
`safeharbor.westy_workflow_schema_health()`. Keep its migration definer account
available. After the last migration replay, grant only:

```sql
GRANT EXECUTE ON FUNCTION safeharbor.westy_workflow_schema_health
TO 'safeharbor'@'localhost';
```

This adds no `TRIGGER`, `CREATE`, database-wide `EXECUTE`, or other DDL privilege
to the runtime. Replaying the migration drops/recreates the function, so verify
and reapply this single-function grant after replay. The health function must
return `1` under the actual runtime identity; absence of the grant or any
required guard stops the receiver/worker.

With the observed baseline unchanged, postflight totals are 47 base tables and
100 triggers. All three new tables start empty. Existing business-table row
counts/digests, existing trigger definitions and protected config must match
their preflight records. The migration creates no service identity or client.

## Execution and rollback boundaries

Use [the existing deployment runbook](../deploy/README.md#protected-backup-and-safeharbor-only-write-freeze).
First take a new root-only application/config/database/grants/release-manifest
backup. Database export **must include `--triggers --routines --events`**. Hash
all files and restore into one exact allowlisted scratch database. Prove that
restore, then run migration 025 and replay against the scratch copy before the
production change window. The CI random-database test is additional evidence,
not a replacement for restoring the actual backup.

For the combined release, stop only Safeharbor's existing report scheduler
through its root-owned control bundle and verify `stopped`. Preserve its
current activation evidence. Deny only the verified Safeharbor SSL docroot,
config-test and reload Apache; never stop/restart shared Apache or touch the
Milepost vhost. Lock only `'safeharbor'@'localhost'`, require zero connections,
then apply/replay the archived migration as the operator. Preserve both outputs.
Do not load the complete canonical schema over production.

After successful postflight and the narrow function grant, deploy the clean
detached merged SHA with:

```bash
SERVER=milepost-ec2 DEST=/srv/8west/apps/safeharbor/current bash deploy/deploy.sh
```

The deploy script assembles exact Git bytes, preserves `config/config.php`,
uses the report/deploy lock, verifies its complete artifact, and writes the
protected release manifest. It does not execute SQL. Extraction is non-atomic;
keep the Safeharbor-only freeze until hashes and PHP checks pass. Restore the
exact vhost and account state. Restore the already-approved report schedule
using evidence for the new release artifact; do not leave it stopped or expand
its recipient/customer scope. Recheck signed-in staff navigation and the
existing live report/time integrations.

If anything fails after DDL starts, retain the scoped freeze and investigate
the exact partial shape. Do not restore an old database over newer help-desk
writes. Rollback disables both new gates and any new worker, restores the prior
application, and retains all three new tables/history/guards. Never reset
`auto_close_eligible` on a workflow ticket. A database restore needs a separate
review of data written since backup.

## Can application source ship before migration?

Yes, the default-off source is compatible, if a separate source-only release
is chosen. An isolated copy of the actual API and worker was executed with
synthetic config containing no new gates and no database configuration: the
API returned `not found` and the worker exited successfully without database
access or output. The ticket-card regression uses an empty pre-migration
database and returns no card. CSS/JavaScript act on the card only when present.
Historical cards remain visible if a previously activated workflow is disabled.

This does not activate troubleshooting or billing and does not install schema.
The same clean-source deploy and existing report-scheduler handling still
apply. The combined feature release remains migration-first; never enable a
new gate before schema/privilege/canary proof.

## Gates, preview and first customer

Leave both new config blocks disabled. Activation requires a reviewed exact
provider tenant slug, permanent customer UUID and existing active binding;
Safeharbor also needs an active `milepost-workflow` service identity for that
tenant. Store separate workflow and billing HMAC secrets only in protected
configuration. The billing destination is fixed to Coastmark's production
`/api/integrations/safeharbor/billing-handoffs` route and service
`safeharbor-billing`. Milepost's controller, command approval, and optional
escalation-assignee mapping are separate gates. Coastmark's billing receipt and
post-approval send policy are separately configured in its own application.

There is **no dry-run argument** on `cron/westy_billing_dispatch.php`; invoking
an enabled worker attempts delivery. For an after-migration read-only preview,
inspect exact scoped IDs, states and hashes instead:

```sql
SELECT westy_workflow_schema_health();
SELECT t.slug,w.customer_id,w.ticket_id,w.workflow_key,w.state AS workflow_state,
       b.id AS outbox_id,b.state AS billing_state,b.detail_code,b.attempts,
       b.payload_sha256,b.next_attempt_at
FROM westy_billing_outbox b
JOIN westy_workflows w ON w.tenant_id=b.tenant_id AND w.id=b.workflow_id
JOIN tenants t ON t.id=b.tenant_id
ORDER BY b.id LIMIT 20;
```

The first controlled workflow must prove claim acknowledgement before device
work, exact-command human approval, successful linked agent-job evidence,
fresh recovery of the original alert, and human takeover/escalation stopping
further work. Closure must leave the billing outbox waiting until actual
approved billable time has its current accepted v3 export receipt. Existing
time-export controls and pricing mappings stay unchanged. Do not invent time,
rates or invoice recipients to make the canary succeed.

Only after the exact customer handoff canary is approved should the optional
CLI schedule be installed. Each run handles at most ten oldest due entries,
with bounded attempts/backoff and byte-identical uncertain retries. Accepted
receipts are terminal; blocked items require investigation. An accepted
handoff exposes Coastmark review links and never means an invoice was sent.

## Retained synthetic proof

The actual signed Milepost/Safeharbor harness passed 89 checks and actual
Coastmark HTTP receiver import/handoff/replay passed 13 assertions. The frozen
billing request SHA-256 is
`40a6f4d9d3e8bfbf575f0c76b7300341393c1c327ded5833ff5a644a2ddcb076`.
The [acceptance record](westy-workflow-acceptance-2026-09-07.md) and
[fixture manifest](contracts/interop-20260907/manifest.json) contain exact
library hashes and receipts. Fixture keys are public test material and must
never be configured in production.
