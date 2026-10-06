# General computer troubleshooting

Westy can select a diagnostic pipeline, wait for its actual endpoint result, choose a follow-up check and explain the collected evidence. The existing tenant AI provider/model selection, budgets and usage receipts apply to every continuation. No additional subscription or Codex endpoint installation is introduced.

`inspect_computer` accepts a typed diagnostic pipeline. `run_powershell` proposes exact PowerShell and an effect; the ordinary-user Windows companion requires local approval before it runs. Routine diagnostics are automatic under current policy, with a personal “Ask me before each automatic computer check” preference. Screen tools keep their separate local consent and fresh-observation requirements.

Pending work stays in its original chat. Stop cancels waiting continuation and revokes execution where possible; interrupted execution is reported as uncertain. The UI resumes only after a terminal receipt, and the server claims each continuation sequence once under the existing paid-attempt lock. A reconnect reads the saved receipt and does not resend commands. Another customer, sign-in session or companion cannot resume the run. Provider/model or credential revision changes require a new request.

Private replay is bounded to 30 minutes and erased on Stop, completion or expiry. Images and UI Automation observations are removed from durable continuations. The existing maintenance schedule performs cleanup; unhealthy cleanup blocks new execution. Five asynchronous waits fit within the existing six paid-attempt limit, and the final attempt has no further tools.

The additive `endpoint_tool_runs_v1.sql` table binds the original turn, customer, scope, conversation, operation and origin session. Database triggers reject inconsistent scope or changes to that identity. `app/db/schema.sql` contains the same table and triggers. Apply with the protected `deploy/endpoint_tool_runs_migration.php` CLI after its exact payload/catalog verification and a verified backup; use a private external evidence directory. Partial schemas and unreceipted final states require operator recovery. Do not apply DDL through a page request.

The corresponding Milepost release supplies the execution policy, native transport and staff-alert route. Staff alerts retain their existing job, queue, approval and fresh-monitoring recovery contract. This source does not itself prove deployment, signed installation or live endpoint acceptance. Those remain release-owner gates; no speed improvement is claimed without measurements.

Before enabling continuation, the release owner verifies the existing runtime user's grants against the actual database and account. The new table needs ordinary SELECT/INSERT/UPDATE access and a table-specific DELETE grant for expiry and private-data erasure. Do not widen database-wide DELETE or grant schema administration. For an installation using the established `safeharbor` database/account, the additional cleanup grant is:

```sql
GRANT DELETE ON safeharbor.portal_westy_tool_runs TO 'safeharbor'@'localhost';
```

Hosted validation includes the actual root-only CLI backup/apply/repeat, concurrent-owner refusal, missing receipt and backup-tamper checks. The rendered portal was checked at 1440 px and 390 px for wait/resume once, Stop, preference changes, private-state reset and clean console. The full application suite and separately authorized live endpoint acceptance remain release gates.

The repository ACL baseline in `deploy/README.md` grants `safeharbor`@`localhost` schema-wide SELECT/INSERT/UPDATE and DELETE only on inventoried tables. This worker did not inspect or change live grants. The release owner captures `SHOW GRANTS FOR 'safeharbor'@'localhost'` before and after the reviewed change and confirms the configured runtime account resolves to that exact user/host. If it differs, hold the grant and use the verified account mapping; do not create another account.

Keep the existing runtime-account/write freeze through schema installation, the narrow grant, cleanup validation and compatible application deployment. DELETE is needed only to remove expired continuations and erase private replay during an exact-account data deletion. Retain the new table and its permission while either current cleanup or rollback reconciliation still depends on it. After all application and cleanup writers have been reverted, compare the captured baseline; if this release added the DELETE permission, revoke only that permission on `safeharbor.portal_westy_tool_runs` under the same freeze. Never drop the ledger, revoke pre-existing grants, or alter report scheduler credentials as part of rollback.
