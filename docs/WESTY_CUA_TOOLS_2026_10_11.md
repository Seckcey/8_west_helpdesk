# Westy computer use: typed Cua tools — October 11, 2026

PR #219 is merged and deployed at
`102b35d7c5f0b7215e4c09e5dd1293ca0697f24b`. Its reviewed source was completed
from the yielded `claude/westy-cua-fix` commit `2889326` plus its eight uncommitted
files, then frozen at `e291fe7`. Frankie's actual Notepad/browser retest remains
pending. Synthetic checks and portal rendering do not prove desktop acceptance.

## October 12 production receipt

Colonel applied the accepted source through the unchanged receiver at 09:52 UTC
(2:52 AM Pacific). The saved runtime receipt was observed at
`2026-10-12T10:03:03.730651Z`. Exact-main Validate38227731286 passed all four jobs;
the installed source and current/immutable records match.

| Actual runtime binding | Value |
| --- | --- |
| Installed source | `102b35d7c5f0b7215e4c09e5dd1293ca0697f24b` |
| Clean source artifact | `6ccdfa54b35b312d16dfa5313bcd0a15a16373b516c68b2c77f3351ec4994d50` |
| Complete deployed artifact | `ef570c87a8cb154e016bdc23b16bf438934e4b8715fa47c7c6b7ed916d858f5b` |
| Current/immutable record digest | `a07e67e928ffc7a8b0d6f9710920cf77afad39b09e04f518a5a485ac87e2a7ed` |
| Protected configuration digest | `98a05f288e56c8535b8ea8897e03ce0e6827f9cfb34abe919531ff0b9e40aef1` |
| Active report evidence | `567f4706206f8abc3d58633d54218a63cf52a749ed31b8899672195eb757c8c0` |
| Unchanged cron digest | `0ccf6eb37945c35b30cd62fa752ca5ada155a88c12644cf517950c720b4068b6` |
| Actual monthly ceiling | `20000000` micro-USD ($20), per client per UTC calendar month |

The separately approved configuration change preserved all outside-span bytes
and unrelated PHP values, including charged/reserved history, hourly/daily limits
and enable flags. The unchanged manager verifies reports active; original private
delivery scope, Graph/recipient/archive evidence and clocks were preserved. The
configuration change has its own valid activation binding and was not admitted
by relaxing the source-only rebind controller.

The private saved `actual-runtime-receipt.json` digest is
`dff6fb1edcd796750415c1593aba1ede9565a3e508c3c81473446d6b82ac0e99`.
Colonel reports signed-in portal rendering verified and its own suite/app/report
descriptors released with SSH closed at `2026-10-12T10:04:11Z`. This is owner
execution evidence, not another coordinator's production rerun or proof of global
lock freedom. The source window is closed.

The prior browser/Notepad acceptance result remains a historical failure. A fresh
retest of this release and Agent2.0.0 remain pending; no live provider/Companion
postcondition or active AI profile is inferred from portal rendering. This backend
release requires no new installer. Operational-client editing in PR #218 and
branding activation retain their separate release/acceptance boundaries.

## Evidence and diagnosis

What was observed, and where it comes from:

- **Before R1/R2:** MAIN's read-only Milepost timeline is recorded in relay task
  `T-20261011-01-westy-cua-diagnosis`, `review.md`, 13:57 Pacific entry. All three October 11 tasks
  returned `tools/list`, were recorded as executed, and were stopped by `origin_stop` about two
  seconds later. The Companion and the driver answered; Safeharbor ended the run.
- **After R1/R2 (5d7e6ad):** Frankie's retest showed Computer list Complete, Connect to computer
  active, eight "Device tool · returned" rows, then "This reply was interrupted. Check the device
  activity before asking again." Its exact reason code was not recorded.
- **Upstream Cua on its own:** Frankie reports the upstream Cua Driver worked on the laptop when
  run directly. The method used was not recorded.

The likely causes below come from reading the source at `a9c2eda`. They are code paths that
produce the observed behavior, not traced events:

- The model had one raw MCP relay, `desktop_cua`, and was told to read the driver's tool
  catalog plus `SKILL.md`, `RUNTIME.md`, `WINDOWS.md` and `BROWSER.md` (about 92 KB) before
  acting. That used every round of an attempt.
- Every continuation expires screen observations by design, so the reading was lost and had to
  start again.
- Each round allowed 1,200 output tokens. Reasoning counts against that allowance, so replies
  could be cut off (`provider_incomplete`).
- An attempt that passed its 150-second deadline ended as `interrupted`.
- `interrupted`, `provider_incomplete`, `tool_invalid`, `context_limit` and several other
  reasons all showed the same "This reply was interrupted…" message, so the cause stayed hidden.

## What changed

| Area | Change |
| --- | --- |
| Tools | `desktop_cua` is no longer offered. Thirteen validated tools replace it: `cua_list_windows`, `cua_launch_app`, `cua_window_state`, `cua_click`, `cua_type_text`, `cua_set_value`, `cua_press_keys`, `cua_scroll`, `cua_browser_state`, `cua_browser_prepare`, `cua_browser_navigate`, `cua_browser_click`, `cua_browser_type`. `desktop_stop` is unchanged. |
| Translation | `app/lib/portal_westy_cua.php` checks every field, then builds the exact upstream `tools/call` (`get_window_state`, `click`, `type_text`, `press_key`/`hotkey`, `scroll`, `set_value`, `launch_app`, `list_windows`, `get_browser_state`, `browser_*`). Unknown fields, including `session`, are refused. The Companion still injects its own session. Only named keys the driver knows are sent, because the driver presses the first character of an unknown key name. |
| Observation | `get_window_state` is bounded (150 controls, 1,000 with a query, 1,280-pixel screenshot). The model receives one line per control instead of JSON plus a duplicate Markdown tree. Evidence per call is capped at 24 KB as finally encoded, including quote- or backslash-dense screen text. Only the newest screenshot is sent to the provider. |
| Playbook | The device instructions carry a short find, observe, act, verify playbook for Windows and the browser. No driver manual is required reading. |
| Time | After a tool result, the attempt yields a durable continuation when less than 90 seconds remain. One rule, described below, admits a call and decides every receipt poll. A receipt that does not arrive in time is `unknown`, the existing scoped Stop follows, and the call is never repeated automatically. A call that is not sent is answered `not_sent`; after continuation, its arguments must be reconstructed from the original request or fresh observation. Its row stays in the record as "Not sent" with `reason: step_time_limit` and the time that was left. Final accounting saves a yielding call's actual row instead of an extra post-call save. A yield writes no paragraph break because resumption adds one. |
| Receipts | A driver reply is a receipt, not proof of the requested outcome. Rows read "Driver replied", "Not completed" (driver error) or "Result unavailable". "Result unavailable" means `returned` with `reason: response_unavailable`: the driver acknowledged the call, but its response was lost. That row explains that the effect is unknown. The model is told not to repeat the call and to observe again before reporting any outcome. |
| Mistakes | Invalid arguments return `rejected` with correction guidance. A call to a tool that is no longer offered returns the real offer. Neither reaches the device. The guidance repeats only server-owned tool and field names, counts and supported formats; supplied field names and values are never echoed. The fifth such call in one turn, counted across continuations, ends the reply as `tool_invalid` instead of spending more paid rounds. |
| Model | Safeharbor's built-in fallback for the internal tenant is now GPT-6.1 Sol at medium effort (was GPT-6 Luna at low). It applies only while that tenant has never saved an 8 West ID AI selection, and then to all of its Westy chat, not only computer use. A saved 8 West ID selection always takes precedence, for every tenant. |
| Allowance | Each round allows up to 8,000 output tokens. When the five-round reserve would not fit the remaining monthly budget, the attempt uses 4,000, 2,000 or the original 1,200 tokens instead, and the reserve uses the same number. A tenant is therefore never refused sooner than before; for example, GPT-6 Astra on the default budget starts at 2,000. |
| Failure names | The adapter's closed reason codes (`input_count_unavailable`, `provider_invalid`, `provider_limit`, `provider_refused`) are kept instead of becoming `provider_unavailable`. Each failed attempt writes one content-free `westy_ai_failure` line (operation, sequence, round, reason, exception class) to the PHP error log. The portal shows a message for every reason plus "Reason code: …". |
| Stale turns | A pending turn is shown as interrupted after 180 seconds measured from its newest attempt, not from the first one. |

No schema, migration, protected configuration, vendor-pinned `tenant_ai/*` file, Milepost or
Companion change.

### Time boundary

The existing attempt deadline remains 150 seconds. One rule admits a call and decides
every receipt poll: one more poll and the scoped Stop must fit before the deadline,
with 4 seconds of headroom. A call is admitted only when the check before sending, the
queue request, a first poll, the shortest useful receipt wait and Stop also fit. That
wait is 8 seconds for reading, clicking, typing and keys, and 20 seconds for launching
an app, connecting to the browser or loading a page. While requests are fast, a call
needs 53 seconds left (65 for a slow step) with a `customer_access` binding, and 26
seconds (38) with a legacy binding.

A check and a desktop request are budgeted at the longest they have actually taken in
this request, never below a floor: 2 seconds for a legacy binding and 3 seconds for a
`customer_access` binding, whose requests also read membership before and after the
call. Stop is always budgeted at its worst case from the existing timeouts: 8 seconds,
or 32 seconds with the two 12-second membership reads. On October 12 the earlier rule
added every worst-case timeout together (120 seconds to send and more than 124 seconds
to poll for `customer_access`), so only a call in the first 30 seconds of an attempt
could be sent, and the next call was always refused.

While it waits for a receipt, the dispatcher starts a full authority check only while
that check's own worst case still fits: 41 seconds, or 113 seconds for `customer_access`,
because a failed sign-in or AI read is read once more. It asks without force, so a full
check runs at most once a second. The check before sending is always
full. Past that point a light check still reads the kill
switch and the turn's own Stop locally before every poll, and every poll still reads
membership. A person's Stop also reaches the computer through its own scoped Stop,
which marks the pending action cancelled or unknown. None of this skips
authentication, revocation, membership or a user Stop. The model loop starts a call
only with the admission time left; a slow row save can still leave the dispatcher to
refuse it, which shows a brief "Working" row that then reads "Not sent".

A desktop request that fails before its transport call (configuration, the access
check before sending, or an oversize request) is a rejected request: it did not run,
so a Cua call is `refused` with `connection_unavailable` and no scoped Stop, and the
model is told to send it again. After sending, an unreadable access check or reply
keeps the outcome open as before. A receipt read is safe to repeat, so a read that did
not run, was lost in transit, or returned a server error or unreadable body is asked
again while the wait lasts; an explicit refusal or an ended sign-in still ends the wait
with the scoped Stop. On October 13 one failed access check before sending ended
Frankie's Notepad test this way.

After a pause, replayed observations read "expired, not failed", and the playbook tells
the model to observe again and continue instead of reporting, and to scroll when a
control or link is not in view.

The deadline is cooperative. A poll that suddenly takes its own worst case right at
the margin can delay the worst-case Stop by that excess, about 25 seconds for
`customer_access`. That passes the 150-second deadline but stays inside the 180-second
stale-turn limit, and the outcome stays `unknown`. PHP scheduling or database locks
are not a real-time guarantee. Final accounting and delivery revalidation remain required
after the tool loop. A completed **attempt** with a waiting continuation does not
mean an unknown **device action** completed.

## Coverage and alternatives

The typed tools cover the ordinary loop: find or open a window, read it, click, type or replace
text, press keys and shortcuts, scroll, and read or act on Chrome and Edge pages. Double-click,
right-click and foreground retries use `cua_click` options.

The raw relay also exposed these upstream tools, which are not offered:

| Not offered | Existing alternative |
| --- | --- |
| `drag`, mouse button and cursor tools | Keyboard shortcuts with `cua_press_keys`, `cua_click`, `cua_scroll`. For file moves, `exec_command` where general tools are installed. There is no drag-and-drop. |
| `get_desktop_state` and desktop-scope input | Target listed windows with `cua_list_windows` and `cua_window_state`. Full-display capture and screen-coordinate input are not available. |
| `verify_state`, `zoom` | Observe again with `cua_window_state`, using `query` to narrow it. |
| `invoke_menu`, `set_window_frame`, `bring_to_front` | Click menu items by element token, use the app's own shortcuts such as `["alt","f"]`, or retry one action with `delivery_mode: foreground`. Moving or resizing a window is not available. In modern Windows apps (Notepad, Settings, Calculator) the pinned driver presses a shortcut only through a visible control that offers it, whatever the delivery mode, so a shortcut for an item in a closed menu fails; click the menu, then the item. |
| `list_apps` | `cua_launch_app` by name or AUMID; `exec_command` for discovery where available. |
| `kill_app` | `["alt","f4"]` with `cua_press_keys` after the person asks; `exec_command` under the existing policy. |
| `clipboard_read`, `clipboard_write` | Select, then `["ctrl","c"]` or `["ctrl","v"]` (classic apps and browsers; see the modern-app limit above); `exec_command` in user context. |
| `browser_dialog`, `browser_set_input_files`, `browser_download`, `browser_pointer` | Native window clicks and keys on the dialog, file picker or download bar; `cua_scroll` on the browser window. Not equivalent in every case. |
| Driver configuration, update, recording, history and diagnostic tools | Deliberately not offered; they change or inspect the driver, not the person's task. |

The completion review removed the candidate's additional launch-path and browser
URI policies. `cua_launch_app` forwards the requested executable path, including a
network or device path, and accepts addresses or file paths for the default app.
The driver and Windows determine whether the requested launch can execute under
the user's existing permissions. `cua_browser_navigate` forwards the original URI
without rewriting it or imposing a scheme policy, including `file:`, browser and
default-app forms. The pinned driver, browser and OS determine what is supported
and permitted. Its `url` must be a nonempty UTF-8 string of at most 2048 bytes with
no control characters. Empty or malformed argument shapes are still reported
before dispatch; there is no new discretionary permission guard. The earlier
claim that an upstream contract requires rejecting `file:` was not substantiated
and is withdrawn.

Local files and scripts remain possible:

- **Open a file in a chosen app:** use `cua_launch_app` with an app path or name plus `arguments`.
  For example, `notepad.exe` with a file path, or `chrome.exe` with a `file:///` address as an
  argument.
- **Run a script:** use `C:\Windows\System32\cmd.exe` with `["/c", "<script path>"]`, or
  `exec_command` where general tools are installed.

Milepost and Companion launch and permission rules apply to all of these.

The Companion's existing preference rules also apply (Milepost `agent/WestyDesktop/CuaMcpClient.cs`,
`EnforcePreferences`, at Milepost `origin/main` 564e47b7):

- With only browser control or only desktop control allowed, calls without a `pid` are refused,
  for example `cua_list_windows` without one, or `cua_launch_app` with `urls`.
- A call for a process outside the allowed category is refused as well.
- Full permission has no such limit.

This patch adds no new guard or confirmation. Explicit denies, Stop, account and device
boundaries, and unknown outcomes keep their existing behavior.

## Privacy and authority

- Raw window/page observations, screenshots and Cua call arguments are transient
  inputs for the current attempt. The original user request and the assistant's
  visible reply remain ordinary conversation history, including text the user
  explicitly asks Westy to report. Existing provider continuation metadata remains
  intact; this is not a new retention policy for provider reasoning.
- Public receipts keep only:
  - the state and action id;
  - a literal allowlisted reason code (other relay values become `unrecognized`),
    and a small allowlist of driver effect/code/route/escalation facts; matching a
    lowercase token pattern alone is insufficient;
  - the `driver_error`, `retry_allowed`, `executed` and `correction_allowed` flags, where they apply;
  - pid and window_id;
  - the count of typed characters;
  - server-written guidance.
- Since October 13 (approved by Frankie), the saved continuation keeps one text summary
  of the newest Cua screen reading, at most 8,000 bytes, marked `summarized` with
  `last_screen`, so a resumed task knows where it was. It can include window titles, control
  labels and values and page text. Screenshots are never kept, every older reading still
  expires, a later pause replaces the summary, and the saved continuation is cleared when
  the run resumes, ends, stops or expires. Readings from the legacy desktop tools still
  expire entirely. The model may click or navigate with an element token or ref listed in
  the summary, observes again if the driver reports it stale, and observes first when the
  outcome of a later call is unknown or the step types or replaces text, and before reporting.
- The saved continuation keeps each Cua call's name and call id. Its arguments keep only
  the `pid`, `window_id`, `target_id` and `tab_id` it addressed (since October 13; browser
  ids only in the driver's minted `bt-<uuid>` and `tab-<uuid>` forms); typed
  text, values, URLs, queries, element tokens and refs are removed. This includes attempted
  calls to the removed `desktop_cua` relay. OpenAI retains an argument JSON string; Anthropic
  retains an object and its paired `tool_use`/`tool_result`. Current in-memory arguments are
  untouched. Before this, every resumed attempt saw its earlier calls as `{}` and opened by
  repeating a call with no `pid`, which was rejected.
- The model can no longer call driver tools outside the curated set (for example `set_config`,
  `kill_app`, clipboard or recording tools), which the raw relay allowed.
- Browser and launch permissions are still enforced by Milepost and the Companion.
  `cua_browser_prepare` uses only the Companion's existing-profile grant. The playbook requires
  telling the person before connecting to their browser.

## Cost and limits

- **Maximum reserve per attempt:** before provider I/O, a five-round Sol attempt holds a maximum
  admission reserve of 1,225,600 micro-USD (about $1.23). When the provider reports usage, the
  reserve is refunded down to the measured cost as the attempt finishes. When usage is unknown,
  for example after an interrupted stream, the full reserve stays charged. It is not a typical
  cost; actual per-task cost has not yet been measured with a live provider.
- **Monthly ceiling:** `portal_westy.monthly_microusd` is a ceiling per client per calendar month
  (UTC). Admission refuses an attempt when that client's spend plus the reserve exceeds it.
  - The default is 5,000,000 ($5), which allows only a handful of computer tasks a month.
  - The configuration accepts 1 to 20,000,000 ($20). A larger value makes Westy unavailable.
  - Frankie approved 20,000,000 on 2026-10-11. Colonel separately applied it in
    production on October 12, as recorded above. The source patch does not change
    the protected configuration or reset charged history.
- **Hourly limit:** `hourly_limit` counts attempts, and computer tasks now use several
  continuations.

## Validation (synthetic)

- **Reviews:**
  - Earlier author reviews and test totals are historical author evidence.
    Safeharbor's review of `b6469e7` requested corrections for lost responses shown
    as done, the dispatch margin and supplied names in rejection guidance.
  - The independent completion review reproduced token-shaped private reason
    leakage, added full authority/membership timing cases, removed the added
    launch restrictions, and covered the removed relay's replay arguments.
- **Local tests:** PHP 8.3, `portal_westy_desktop_continuation_test.php`, including the Cua cases.
  The incoming candidate passed **322 cumulative assertions**, including 138
  pre-existing desktop continuation assertions; this was not 322 new Cua cases.
  The completed candidate passes **408 cumulative assertions**, with the same
  138-assertion starting suite. This rerun includes exact file/browser/custom URI
  forwarding and the retained empty, size, UTF-8 and control-character checks.
  Both PHP files changed by the URI correction pass syntax checks. Before that
  correction, head `86d37e3` passed 385 cumulative assertions plus separate
  diagnostics, recovery and replay suites with 18, 15 and 61 assertions
  respectively; all seven changed PHP files and the browser JavaScript passed
  their syntax checks at that head.
  They cover:
  - the lost-response receipt;
  - actual dispatch and authority/receipt/Stop boundaries, with deterministic
    legacy and `customer_access` timing plus the existing real-time slow fixture;
  - private markers in field names, key values and lowercase relay/driver codes;
  - both provider request formats after durable replay, exact call/result pairing,
    unchanged user authority text, idempotent redaction and preserved uncertainty;
  - final accounting saves returned/unknown rows and removes unsent rows, while
    concurrent Stop or lost delivery authority retains the earlier stored history.
- **Browser contract:** `tools/shots/portal-contract.test.mjs`, "general checks wait for
  receipts", passed at `86d37e3` at 1440x900 and 390x844. Both saved screenshots were inspected.
  The case covers "Result unavailable" and "Driver replied"; these are synthetic
  receipts, not proof of a live device action.
- **Coastline MySQL:** the preceding author's fourteen-suite report is historical;
  head `86d37e3` independently passed the affected delivery, run and workspace
  suites with 322, 144 and 133 cumulative checks respectively. Those
  totals include each suite's 84-check device setup and must not be added as
  unique new assertions. The isolated test containers and volumes were removed.
  The completion handoff binds these logs and the original author snapshot to
  the final local commit with a source manifest.
- **URI correction scope:** only the existing continuation fixture and the two
  changed PHP syntax checks were rerun. The earlier browser and MySQL evidence
  is retained at its actual source head; those suites were not rerun for this
  mapping/schema/documentation correction.
- **Not covered:** none of these test a live provider, the Companion or a real desktop.

## Acceptance (Frankie, on the laptop)

1. Notepad: ask Westy to open Notepad, type a sentence, and read it back.
   - Expect rows such as "Open app · Driver replied", "Read window · Driver replied" and "Type text · Driver replied".
   - Expect a reply that quotes the text it read back after a fresh observation.
   - The document stays unsaved.
2. Browser: ask Westy to open a web address and report the address and page heading. Expect the
   reply to give the address shown in the address bar and the page text.
3. If a reply stops, note the "Reason code" line and the time.

## Rollback

Reverting these commits restores the previous source behavior. It does not undo effects already
made on a device, such as typed text, launched apps or browser state. It does not change protected
runtime operations either: the deployed release, release records, and configuration such as
`portal_westy.monthly_microusd`. MAIN performs those separately.

For the accepted102b release, the compatible source inverse is the retained5d
forward archive. Preserve only the added `lib/portal_westy_cua.php` through the
reviewed physical-file procedure before overlay. The old497 inverse is not valid
for this release. The original5d capture was taken before the budget change;
**recover the budget-original protected configuration through the existing native
procedure before using the unchanged source-only5d inverse controller**. Its old
configuration-hash invariant remains mandatory. Then bind recovery to the actual
source/configuration and a genuine current protected review, preserving original
delivery facts/clocks. Do not restore a stale activation or alter the budget ledger.

There is one existing tenant-wide owner/admin AI model selector. Separate computer
and chat selectors were not implemented by this work. The report that Sol6.1 was
selected in 8 West ID Settings is not independent proof of the active profile or
revision. Changing the saved model, provider, revision or credential while a
continuation waits makes admission fail with `conversation_changed`; its previous
work is not replayed. Reverting the internal fallback from Sol to Luna has the
same model-match consequence for an internal-legacy pending continuation. Finish
or explicitly stop pending work before a planned model/source rollback, then
start a fresh request under the intended selection.
