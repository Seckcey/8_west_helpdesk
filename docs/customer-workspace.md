# Westy customer workspace

Source contract for the October 4 candidate. Production installation and signed-in
customer acceptance must be recorded separately by the release owner.

`/portal/` is a full-height private Westy workspace. It has a narrow navigation
rail, retained recent chats, an unframed conversation, and a composer visible in
the first desktop/mobile viewport. The original Safeharbor logo, Westy avatar,
Inter font and brand tokens remain in use. Business support requests now live at
`/portal/requests.php`; device, summary and direct support routes remain available.
The workspace has one composer and no duplicate floating chat bubble. The customer
widget on other portal pages shares the same conversation; the staff widget is a
separate surface with its existing permissions.

## Streaming and recovery

The Responses adapter consumes real `response.output_text.delta` events and sends
SSE deltas to the browser immediately. It does not animate a completed response.
The provider remains `gpt-6-luna`, low reasoning, Standard tier, `store=false`.
Encrypted reasoning items may be carried between the bounded stateless tool
rounds in server memory; they never enter the transcript, logs or browser stream.
There are at most five provider rounds, four tool calls, 1,200 output tokens per
round, a 64 KB request cap, and a 150-second wall-clock generation deadline.
The existing business/subject caps and worst-case cost reservation still apply.

The server reserves a private turn once, saves partial text and tool intent before
emitting it, and releases the PHP session lock before network work. It rechecks
the original session, current identity generation and exact active customer
binding during generation, before dispatch and before final disclosure. Session
cookie/cache options are set before SSE headers so PHP can reopen the same session
read-only. The response disables compression and requests no proxy buffering.
Public proxy behavior still requires a real deployment-path test.

Enter sends; Shift+Enter inserts a newline. Sending uses fetch and does not reload
the page. The browser updates existing turn nodes, keeps focus in the composer,
and follows new output only while the reader is near the bottom. Stop persists a
stopped turn and aborts generation; already dispatched device commands retain their
own receipts. Reload reads saved state. Lost responses never resubmit generation
or device work. Pending turns older than 180 seconds appear interrupted. Unknown
provider cost stays reserved. Conversation selection stays within the immutable
subject/customer scope; retention and immutable ticket receipts remain unchanged.

## Device tools and approvals

`portal_westy.tools_enabled` separately enables five closed functions:
`list_computers`, `read_computer_status`, `start_health_check`,
`prepare_temp_cleanup`, and `propose_print_repair`. Customer owners/admins may
request read-only checks/previews in chat. The selected reference is resolved
against the scoped device list. Other roles retain their existing read permissions.
Every service request uses the fixed HTTPS Milepost endpoint, existing dedicated
HMAC credential and server-derived provider/customer/subject/session/role. No
model/browser field supplies a command or changes the customer scope.

The model has no approval function and receives no approval fingerprint. A human
must review the named computer, exact impact and preview and press the separate
approval control. The current device name must load before approval is offered.
The operation must belong to this subject's retained conversation and remain
approved by Milepost for the same current subject/session/role. CSRF is mandatory.
Changes, expiry, revocation, ownership holds and mismatched service projections
fail closed. A cached receipt never supplies an active approval control.

Temporary cleanup starts with a read-only preview. Milepost binds the proposed
script hash to that exact inventory and cutoff, then verifies a later health
observation after the agent reports actual removal counts. Print-service repair
still requires a fresh stopped-service observation and two separated running
observations. Neither result proves the person's overall problem is solved.
Unsupported operations lead to accurate guidance or Contact support.

Cancel operation withdraws only work confirmed undelivered under the delivery
locks. Delivered or ambiguous work shows cancellation requested/support review,
retains its execution fence and is never silently repeated. Stop reply and Cancel
operation are separate actions. Transcript text does not itself approve repairs,
send tickets/email, make purchases, change access or initiate arbitrary commands.

## Release order and acceptance

Deploy this backward-compatible projection reader before the matching Milepost
source starts returning expanded receipts. Preserve all existing portal/identity,
provider, service-key, enrollment, mobile, security and report configuration.
There is no Safeharbor schema migration and no credential rotation.

The release owner must review and apply Milepost's exact additive workspace
constraint migration under the existing write freeze/release lock with verified
backup and restore proof, install its matching recipe/API/reconciler, then enable
`customer_portal.workspace_enabled=true`,
`customer_portal.temp_cleanup_enabled=true`, and
`portal_westy.tools_enabled=true`. Existing `portal_westy.enabled`, `ai_enabled`,
`portal_devices.enabled`, `diagnostics_enabled`, Milepost diagnostics and repair
execution gates must remain enabled for the reviewed rollout. These final gates
cover every legitimately active binding and eligible owner/admin; they do not add
a pilot list or alter provider allowlists. Default-off sample values protect an
unmigrated installation and are not the requested final activation state.

Verify signed-in desktop/mobile first-viewport composition, an actual production
provider delta before completion through Apache/public proxy, Stop, refresh/history,
and a scoped device-status read. Any live diagnostic or destructive cleanup needs
the separately authorized customer/device acceptance; synthetic tests are not that
evidence. Never manufacture a stopped service or delete live files to demonstrate
repair. Record release SHA, flags, scheduler target, health and exact acceptance.

Containment: turn off `portal_westy.tools_enabled` to stop new model dispatch and
approval, and Milepost workspace/temp flags for cancellation/cleanup authority.
For broader containment use existing diagnostics/repair gates. Preserve all jobs,
receipts, delivered fences and schema. Roll back Safeharbor only after Milepost is
again returning the old projection shape; the old reader rejects expanded fields.
Do not reverse constraints once new recipe/state rows exist.

## Executable evidence

- `php app/tests/portal_westy_mysql_test.php`: original private isolation, budgets,
  ticket audience review, receipt immutability, lifecycle and retention.
- `php app/tests/portal_workspace_mysql_test.php`: real ledger/tool orchestration,
  partial persistence, idempotency, SSE frame fragmentation, closed functions,
  extra-command/model-approval rejection, cross-device output, hidden reasoning,
  Stop and cross-customer isolation; includes the existing device boundary suite.
- `php app/tests/portal_stream_http_test.php`: actual portal HTTP entry, CSRF,
  session/revocation/binding middleware and persistence with a synthetic provider;
  first delta must precede completion by over 1.5 seconds, concurrent Stop must
  return under 1.5 seconds, logout/revocation must suppress later deltas.
  `SAFEHARBOR_STREAM_APACHE_TEST=1` runs the same assertions under isolated Apache.
- `node tools/shots/portal-contract.test.mjs`: actual rendered PHP/CSS/JS on
  1440×900 and 390×844, chunked HTTP delivery, no navigation, keyboard input,
  Stop/focus, reload, responsive geometry and existing device/mobile/support flows.
  Nine scenarios include a keyboard-sized mobile viewport, drawer focus, late
  responses after logout, and operation cards for preview/approval, queued work,
  verification/completion, offline refusal and unknown/delivered cancellation.
  `app/tests/fixtures/customer_workspace/operations.json` contains public projections
  exported from the companion Milepost disposable MySQL/poll/result test; these
  synthetic values are also checked by the real Safeharbor projection validator.
  `PORTAL_SCREENSHOT_DIR` saves the corresponding desktop/mobile rendered states.

On October 4 the existing protected credential completed a synthetic, no-tools,
no-customer-data request: 79 visible deltas; first 1,932 ms, last 2,338 ms,
completion 2,437 ms. Only timing/count metadata was retained. This proves the
provider adapter, separately from synthetic HTTP/Apache/browser tests; it does
not prove the future public production route.
