# Westy customer workspace

The 1.26.9 local-control follow-up in
[Safeharbor PR #184](https://github.com/Seckcey/8_west_helpdesk/pull/184) and
[Milepost PR #625](https://github.com/Seckcey/8westit_webapp/pull/625) adds independent
sessions, cross-turn task recovery, application launch and persistent optional
execution-guard settings. Its source and release boundaries are documented in
[Westy general tools](WESTY_GENERAL_TOOLS.md). This source is not yet a deployed
backend or signed installer; Frankie owns laptop installation and acceptance.

The preceding October 7 [companion acceptance repair](WESTY_ACCEPTANCE_REPAIR_2026-10-07.md)
was deployed at Safeharbor `979f9f2ae7055e029aa467a8784e6e9dd20acdd9`, adding
bounded approved-session renewal, truthful approval/connection status and one
current output card per process. Its private Full 1.26.8 package was signed and
staged, with corrected physical laptop acceptance still unclaimed.

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

The provider adapter consumes live text events and sends the first complete text
callback immediately. Later callbacks are coalesced up to 128 bytes or 100 ms,
checked on arriving text and transport progress callbacks. A callback is never
split; one larger than the byte bound goes directly through the output guards.
Each successful round explicitly flushes its remaining text before tool output
or completion. Failed or cancelled rounds discard buffered text. This does not
animate a completed response. Eligible MSPs use their current 8 West ID provider,
model and effort selection; the separately bound internal fallback remains
`gpt-6-luna`, low reasoning, Standard tier, `store=false`.
Encrypted reasoning items may be carried between the bounded stateless tool
rounds in server memory; they never enter the transcript, logs or browser stream.
There are five provider rounds per request, 1,200 output tokens per round, a 64 KB
request cap, and a 150-second wall-clock generation deadline. After an ordinary
tool's fifth-round result, the server saves the real tool messages and yields a
continuation within the existing run budget instead of replaying completed calls.
The existing business/subject caps and worst-case cost reservation still apply.

The server reserves a private turn once, saves partial text and tool intent before
emitting it, and releases the PHP session lock before network work. It rechecks
the original session, current identity generation and exact active customer
binding during generation, before dispatch and before final disclosure. Session
and MSP AI authority checks remain fresh and unthrottled before persistence,
emission, tool dispatch and final accounting. Only idle provider progress uses
the existing one-second heartbeat throttle; it never authorizes output. A paid
round receipt is captured before the final flush, so refusing that flush retains
known usage. Sticky authority loss cannot flush or replay pending text. Session
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

The general companion tools and durable receipt flow are documented in
[Westy general tools](WESTY_GENERAL_TOOLS.md). Independent diagnostic sources use
separate calls. A malformed plan rejected before execution returns structured
feedback so Westy can correct it within the existing bounds; uncertain execution
is never retried by that mechanism. The customer script URL changes with its
contents so a normal reload retrieves the current continuation behavior.

`portal_westy.tools_enabled` defaults to true when unset; an explicitly saved false
remains false. This applies to every MSP and its clients. The legacy device-check
and repair functions are
`list_computers`, `read_computer_status`, `start_health_check`,
`prepare_temp_cleanup`, and `propose_print_repair`. Customer owners/admins may
request read-only checks/previews in chat. The selected reference is resolved
against the scoped device list. Other roles retain their existing read permissions.
Every service request uses the fixed HTTPS Milepost endpoint, existing dedicated
HMAC credential and server-derived provider/customer/subject/session/role. No
model/browser field supplies a command or changes the customer scope.

Installed compatible v2 routes additionally expose general commands, file operations,
task discovery/recovery and browser/native desktop tools described in the linked
general-tools contract. They are not limited to those five legacy functions.
In **Computer tool permissions**, Allow is the default for new authorized work
when an earlier outcome is unknown; Deny enables that blanket guard only by the
user's choice. Earlier unknown receipts remain intact, and no uncertain command
is replayed. The user's sufficiently specific request authorizes its scope; an
extra confirmation is needed only for material work beyond that scope or the
supported Windows elevation step when Windows requires it.

The model has no approval function and receives no approval fingerprint. A human
must review the named computer, exact impact and preview and press the separate
approval control. The current device name must load before approval is offered.
The operation must belong to this subject's retained conversation and remain
approved by Milepost for the same current subject/session/role. CSRF is mandatory.
Changes, expiry, revocation, ownership holds and mismatched service projections
fail closed. These controls apply to the separate legacy reviewed repair proposal;
they do not add a second approval to an already-authorized v2 request. A cached
receipt never supplies an active approval control.

Temporary cleanup starts with a read-only preview. Milepost binds the proposed
script hash to that exact inventory and cutoff, then verifies a later health
observation after the agent reports actual removal counts. Print-service repair
still requires a fresh stopped-service observation and two separated running
observations. Neither result proves the person's overall problem is solved.
Unsupported operations lead to accurate guidance or Contact support.

Cancel operation withdraws only work confirmed undelivered under the delivery
locks. Delivered or ambiguous work shows cancellation requested/support review,
retains its execution fence and is never silently repeated. Stop reply and Cancel
operation are separate actions. Text claiming that a legacy proposal is approved
does not press its approval control, send tickets/email, make purchases or change
access. General v2 commands use the actual authorized request and current device
authority described above.

## Original workspace rollout and current follow-up

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
a pilot list or alter provider allowlists. Other default-off sample values protect an
unmigrated installation and are not the requested final activation state.

For the 1.26.9 follow-up, the existing v2 schema and cleanup receipts are prerequisites,
not new migrations. Preserve original provider approvals and protected report/service
configuration. The owner accepts the existing working recovery backup and owns all
post-release tests. No new pre-update backup, separate backup verification or broad
manual acceptance suite is added. Source/CI, deployed runtime, signing/staging and
owner physical acceptance must be reported separately.

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
- `php app/tests/portal_westy_ai_delivery_mysql_test.php`: whole-callback byte/time
  bounds, sticky MSP authority loss, buffered and final-flush revocation, retained
  paid receipts, failed-round discard and no automatic replay.
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

The separate October 4 signed-limiter regression used synthetic credentials and
provider SSE on Coastline: Safeharbor `02ceceacf28c4d84b3986dd5c824778dc9d2eeaf`
(app tree `e9752cda2b31fc86cdc6d57c18b978780006d51d`) and 8 West ID
`fef56b06238988b42c1068574d5c8b3fd714e001`. Each reply contained 3,366 bytes in
842 provider callbacks. The actual SDK parser and cURL progress path ran against
isolated synthetic HTTP origins; the actual signed ID endpoint and 1,200-request
limiter were unchanged. No paid provider or production request ran in this test.

| Case | First visible text | Completion | Signed authority requests |
| --- | --- | --- | --- |
| One reply | 225 ms | 8.805 s, complete | 143 |
| Two concurrent MSPs | 259 / 244 ms | 8.792 / 8.834 s, both complete | 286 combined |

The concurrent provider intervals overlapped for 8.494 seconds; actual cURL
progress callbacks numbered 953 for the single run and 965/953 for the pair.
All authority responses were signed HTTP 200 and all complete visible/stored
reply hashes matched. Actual ID connection removal stopped a third run with
`ai_changed`, no post-removal marker stored or delivered, and the unknown usage
reservation retained. This is bounded synthetic throughput evidence, not a
general capacity or public-proxy guarantee. Before this correction, the same
reply shape exhausted the limiter before completing, even without transport
progress callbacks.

Private ledgers passed 89 checks; delivery boundaries passed 318; workspace/tool
ordering passed 115; real HTTP buffered Stop/logout/revocation passed. The new
newline fixture compares decoded transcript text, since JSON escapes newlines.
Only that fixture assertion changed after the first two suites passed. Task
containers, network and volume were removed and the shared mutex verified free.
The coordinator handoff retains the measurement harness and raw evidence:
`stream-fix-r4.log` SHA-256
`c7849423f0db38beb8c6037aa446b0e4da6a65baea584153c05f5231bdbd5f1e`;
`rate-results-r4/report.json` SHA-256
`b363172e30420dad94318fd37e8df0b54685d00482e740f40013abb30c52a0d9`.
