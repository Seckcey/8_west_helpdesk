# September 14 internal Westy pilot

Safeharbor source `d011ae0c67cf6c5fb3f418d2e70ad489c4b8c7ca`
([PR 123](https://github.com/Seckcey/8_west_helpdesk/pull/123)) is live on
`milepost-ec2`, hostname `ip-172-31-31-195`. Exact-main
[Validate 34821938670](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34821938670)
passed. No Safeharbor migration was needed.

The normal exact-source deploy installed artifact SHA-256
`4bb88c5eee57685b684397e17a5b9e0cbf1b35cad2ccfa3e541fec5754ab4a46`.
The protected marker is
`/var/lib/safeharbor-report-scheduler/releases/current-app-artifact.manifest`.
The application, config, database, grants and prior scheduler evidence were
backed up under `/srv/8west/backups/safeharbor/20260914-pre-westy-pilot`.
Deployment and configuration changes ran while the report scheduler was stopped.
Its release controls and protected activation record were rebound to the new
artifact/config. Preflight and `verify active` passed; cron bytes and authorized
report scope match the original. No new report email was used as a release test.

## Activated scope

The dedicated `milepost-workflow` identity, workflow gate and billing-handoff
gate are enabled only for the internal customer's exact active suite binding.
Milepost restricts execution to 8WV-FRANKIE. Frankie is the exception owner.
Separate protected workflow and billing keys authenticate the paired seams.
The covered-service reference matches the new internal Coastmark agreement,
revision 1, service `routine_support`, standing until revoked. Credentials and
customer identity values remain in protected operator records, not this source.

`/etc/cron.d/safeharbor-westy-billing` runs `cron/westy_billing_dispatch.php`
each minute as www-data with a nonblocking lock and dedicated log. Fresh
scheduled ticks were observed at 10:14, 10:15 and 10:16 UTC. The existing business
report schedule remained active during final acceptance.

Correctly signed, deliberately invalid requests reached both receiving apps'
payload validation (422); incorrect signatures returned 401. No valid synthetic
incident or financial payload was submitted to production. At 10:17 UTC,
Safeharbor had zero workflow and billing-outbox rows; Coastmark had zero new
service receipts. Its nine invoices, fifteen journal entries and one billing
run exactly matched the restored pre-release snapshot by count and row digest.

Signed-in Queue acceptance passed without a framework error or captured console
errors. The production covered-service ticket card cannot yet be claimed as
accepted on a real case: no eligible pilot incident has occurred. Its earlier
desktop/phone fixture tests and signed cross-app integration tests remain the
source-level evidence. Milepost's real read-only performance commissioning job
4371 succeeded; that is not a closed or repaired incident.

## Stop and recovery

Disable `westy_workflow.enabled` and the dedicated billing gate/worker to stop
new workflow continuation or handoffs. Preserve rows, event keys and any accepted
receipts so uncertain delivery can reconcile safely. Human takeover remains an
application-owned stop. Disabling one service must not alter existing alert
intake, approved-time imports or business reports.

Use the retained app/config/database evidence with the normal exact-source
deploy and scheduler manager for recovery. Any protected config or application
change requires a newly matching report activation record before enabling that
scheduler. Do not restore old databases over new production activity.

Next are shared approved-repair execution, confirmed customer and owner delivery,
and reviewed lessons. This pilot does not send invoices, invent human time or
establish full end-to-end incident acceptance. Task-created browser tabs are closed.
