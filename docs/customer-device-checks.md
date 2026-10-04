# Customer computer checks

The [October 3 production receipt](customer-device-checks-release-2026-10-03.md)
records the installed source and enabled diagnostics gates. Genuine customer
page acceptance and an explicitly approved physical check/repair remain pending.

**Your devices → Computer checks** shows a single computer's health-check and
repair history. The customer must check the consent box and submit a POST to run
a Windows health check. It reads memory usage, free system-drive space and print
service status. It does not read files or change settings.

A fresh stopped print-service result can offer **Review print-service repair**.
That creates a proposal without dispatching a command. The page shows the exact
step, target, impact, expiry and two follow-up observations. **Approve this repair**
requires a separate confirmation by the original requester with the same current
identity session. Owners/admins can authorize work; staff/viewers can read status.
The separately enabled [Westy workspace](customer-workspace.md) can start a requested
read-only check/preview in chat; page views and refreshes never dispatch work.
Repairs always require a separate exact human approval.

Safeharbor derives provider/customer/actor/role from its current authenticated
context and active binding. The fixed signed Milepost service independently
checks the immutable customer identity and current authorization. Browser fields
cannot select scope, supply commands, or borrow technician authority. Projections
reject extra fields, malformed approvals and nonnumeric measurements. They contain
no raw device logs or scripts. Every page rechecks the complete identity after
service calls; POSTs require CSRF. Successful writes redirect to the operation
status so a browser refresh does not submit another form.

The repair pauses printing while the service restarts and preserves pending
print jobs. It has no automatic rollback. A successful command exit starts
verification; two separate fresh observations must show the service running.
**Service verified** still asks the person to try printing. Unknown outcomes
require support review and are never represented as successful repair or
automatically repeated. This feature does not close tickets, order antivirus,
take payment, or grant arbitrary-command or model-approval authority.

The latest readings use the newest completed observation, including the final
running-service observation from a verified repair. An earlier stopped reading
cannot replace it or offer the obsolete repair again. Milepost supplies current
check/repair eligibility from its authority and execution controls. A current
support hold removes both new-check and repair controls; an old `needs_help`
history entry does not permanently disable checks after genuine resolution.

## Rollout and containment

For a new installation, keep `portal_devices.diagnostics_enabled=false` until
the matching Milepost protected migration, source and reconciliation cron are
installed and verified.
Enrollment uses the existing independent `portal_devices.enabled` gate and
dedicated service key; no new key or identity is needed for computer checks.
The Milepost release must preserve the provider allowlist and existing staff
workflow/queue reservations. Historical unresolved work may need operator review
before a customer can start a check; reassignment alone does not clear it.

Enable this flag only alongside Milepost's `customer_portal.diagnostics_enabled`
after current identity and device mapping checks. A real check or repair remains
an explicit action by the customer on this page. Verify signed-in desktop/mobile
acceptance separately from source/CI and synthetic tests. Disabling the two
diagnostics flags contains new customer work without disabling enrollment or
support requests. Retain Milepost's durable jobs/receipts and uncertain outcomes.

The portal device MySQL test covers actual bindings, transport roles, closed
projections and review rendering. The browser suite renders the actual PHP views
on desktop/mobile and tests required consent, exact form fields, read-only roles,
unknown/success outcomes, navigation, overflow and console health. Milepost's
companion suite tests actual signed HTTP, device polling, result handling,
reconciliation and prior-runtime refusal. No customer command is run by these tests.
