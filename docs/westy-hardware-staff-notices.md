# Staff fallback for hardware upgrade options

Safeharbor installed staff fallback with migration 038 in `072c9b1` on
November 5, 2026 at 1:07:21 AM PST (09:07:21 UTC). The first scheduled staff
attempt received a definitive Graph HTTP 404 from the default mailbox. The
[live status](where-things-stand.md#live-hardware-staff-fallback) records the
original case, its separate staff receipt, and the sender correction pending
publication.

The outbound cron discovers eligible existing and new hardware recommendations
after Milepost verifies `no_device_contact`. Staff notices are tracked
separately from the original finding and device contact history.

The current active assigned owner, admin, or technician in the ticket's tenant
takes precedence if their recorded email is a valid person address. Otherwise
Safeharbor selects the sole active owner in that tenant with a valid recorded
person address. Multiple eligible owners are ambiguous. If nobody qualifies, a
separate `no_recipient` receipt records why; no queue row or attempt is created.
The notice never chooses a client, a device contact, a different tenant, or an
address from the recommendation text.

The existing `mail_dispatch.php` minute job discovers missing-contact decisions
and handles known failures or interrupted claims. The pending sender
correction uses the enabled `support_addresses.mailbox` through the existing
Graph app when that route is configured. It uses the ordinary
`mail_send_result` transport when the support route is unavailable before
submission. A support submission records that provider result for the existing
staff retry rules. The message contains the stored summary, upgrade options,
and original ticket link. The staff receipt links to the original notice,
which retains the recommendation and missing-contact message IDs. Each staff
receipt binds one tenant, recipient user ID, role, recorded email and held
`mail_queue` identity. The queue row uses `max_attempts=0` so the legacy
dispatcher skips it, including after source rollback.

The dispatcher saves a claim and attempt before calling the provider, then
rechecks the same unresolved ticket, current recommendation, recipient authority,
and queue content under lock. If the role, address, active state, selected recipient,
or source eligibility changes, the receipt is `suppressed`; it does not move to
another recipient. An accepted or uncertain customer notice is never a staff
fallback candidate. An explicitly known non-submission can retry the same queue
and recipient, with the existing square-minute backoff and at most five
submission attempts. A lost provider response or an interrupted attempt becomes
`uncertain` and cannot automatically resend. A claim still without a result
after 120 seconds is reconciled to uncertain on a normal tick. Provider acceptance
is distinct from inbox delivery, purchase, installation, or repair.

## Signed status for Milepost

`hardware_staff_status` is an optional read action on the existing signed v3
workflow endpoint. It accepts the same nine flat request fields as
`hardware_status` with only the action value changed. The existing v1, v2 and
v3 `hardware_status` response shapes remain exact. The
[machine contract](contracts/westy-workflow-hardware-staff-v3.json) lists
the exact response fields, types and examples. The tenant, customer, alert,
workflow and finding must match. This action does not write a receipt, create
a queue row, send mail or expose the staff email or message contents.

A valid finding returns HTTP 200 with `staff_notice: null` before a staff
receipt exists or when there is no fallback. With a receipt, the original
notice and message IDs remain distinct from the staff receipt and attempt IDs.
`queued` is a saved claim; its attempt outcome, transport and completion time
remain null until recorded. `provider_accepted`, `failed`, `uncertain`,
`no_recipient` and `suppressed` report staff state separately from device
contact. The legacy receiver returns 422 `invalid_fields` for the optional
action. A parsed new action with unavailable staff tables returns 503
`workflow_v3_unavailable`. A missing scoped finding returns 409
`hardware_finding_not_found`. Unsupported or unavailable reads are not evidence
that a prior receipt disappeared.

## Publication and rollback

Apply `app/db/migrations/038_westy_hardware_staff_notices.sql` after 037 through
the normal stock publisher and protected release sequence. The canonical schema
includes the same two tables and four immutable history triggers. Staff history
references the existing tenant-scoped hardware notice, so the existing hardware
ticket retention still protects the case and both kinds of notice; there is
no new direct ticket foreign key. The runtime needs the same existing
schema-scoped SELECT, INSERT and UPDATE on Safeharbor tables and mail queue.
The sender correction is a source-only update using the configured support
mailbox and the existing Graph credentials and cron identity.

The first normal tick after publication can claim eligible historic
`no_device_contact` notices. Check the actual candidate set, sender, assignee
and queue state before the release window and verify the resulting staff receipt
on the normal schedule. For source rollback keep both tables, all attempts and
held queue rows. The old source does not process these rows. Do not lower the
queue hold or replay an uncertain send. The release owner handles migration
recovery and production approval; synthetic acceptance does not establish a live
staff send.
