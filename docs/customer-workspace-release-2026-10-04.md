# Customer workspace release - October 4, 2026 UTC

The streaming Westy customer workspace is deployed and its reviewed device tools
are enabled for valid active customer bindings. Genuine customer browser
acceptance remains pending; the available browser session is a staff account.

## Source and deployment

| Component | Live source | Verification |
|---|---|---|
| Safeharbor | `cf026f28bb2850b2ea9292d8e3d89802e87b0a42`, [PR 165](https://github.com/Seckcey/8_west_helpdesk/pull/165) | Exact source CI and full deployed artifact verified; source installed at 04:47 UTC |
| Milepost | `5c7ffc0f5c3738822f68935130e58e021add8621`, [PR 585](https://github.com/Seckcey/8westit_webapp/pull/585) | Full 12-job CI passed; protected deployment and independent full deployable-tree audit passed |

The protected [migration](https://github.com/Seckcey/8westit_webapp/actions/runs/37183877401),
[deployment](https://github.com/Seckcey/8westit_webapp/actions/runs/37183958287), and
[post-release audit](https://github.com/Seckcey/8westit_webapp/actions/runs/37184449460)
used the fixed `customer_workspace_recipes` selection. The exact W1 checks and
protected migration receipt were verified with zero customer-operation rows and
an unchanged content digest. Both complete production database backups passed
actual isolated restore proofs before migration. Private backups and receipts
remain on the production backup volume.

`portal_westy.tools_enabled`, `customer_portal.workspace_enabled`, and
`customer_portal.temp_cleanup_enabled` are active. The report scheduler's existing
customer, recipient, schedule, sender canary and source authority were retained
when rebinding its activation record to the reviewed configuration. Its active
verification and preflight passed after the change.

The temporary customer-service route hold is removed. Public and loopback TLS
checks returned the original customer and generic-agent responses. The original
customer-operation cron file was returned by a rename on its own filesystem;
its identity and contents were preserved. Release locks are released. Existing
staff work, command results and device assignments were preserved.

## Enabled behavior and remaining acceptance

The workspace contract covers streamed replies without document navigation,
private history, New chat, Stop, live computer status and health checks, and
approval cards for the reviewed cleanup and print-repair proposals. Cleanup is
limited to eligible older files in `C:\Windows\Temp` and the exact approved
preview; the model cannot approve its own changes. See the
[feature and safety contract](customer-workspace.md).

Source validation included actual Windows cleanup safety cases, synthetic
HTTP/Apache/browser interactions and a real provider streaming canary. The
post-release signed-service check could not reuse a current genuine recorded
customer authorization. It created no customer session or repair operation.

The remaining acceptance requires a genuine customer sign-in: verify a streamed
reply to `hello`, Stop, history, device discovery and the device-action/approval
path. No actual customer cleanup or repair was issued for release verification.
Existing staff support holds can legitimately require review before a new
repair starts. These are acceptance limits, not a claim of completed recovery.
