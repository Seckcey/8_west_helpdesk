# Westy computer use: typed Cua tools — October 11, 2026

Source candidate on branch `codex/westy-cua-completion`, imported from the yielded
`claude/westy-cua-fix` commit `2889326` plus its eight uncommitted files. Not deployed. Frankie's laptop
acceptance (Notepad and browser) is the gate; synthetic tests do not prove it.

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
| Time | After a tool result, the attempt yields a durable continuation when less than 90 seconds remain. Dispatch and polling reserve the existing authority, membership, receipt and Stop timeouts described below. A receipt that does not arrive in time is `unknown`, the existing scoped Stop follows, and the call is never repeated automatically. A call that is not sent is answered `not_sent`; after continuation, its arguments must be reconstructed from the original request or fresh observation. Final accounting saves a yielding call's actual row instead of an extra post-call save. A yield writes no paragraph break because resumption adds one. |
| Receipts | A driver reply is a receipt, not proof of the requested outcome. Rows read "Driver replied", "Not completed" (driver error) or "Result unavailable". "Result unavailable" means `returned` with `reason: response_unavailable`: the driver acknowledged the call, but its response was lost. That row explains that the effect is unknown. The model is told not to repeat the call and to observe again before reporting any outcome. |
| Mistakes | Invalid arguments return `rejected` with correction guidance. A call to a tool that is no longer offered returns the real offer. Neither reaches the device. The guidance repeats only server-owned tool and field names, counts and supported formats; supplied field names and values are never echoed. The fifth such call in one turn, counted across continuations, ends the reply as `tool_invalid` instead of spending more paid rounds. |
| Model | Safeharbor's built-in fallback for the internal tenant is now GPT-6.1 Sol at medium effort (was GPT-6 Luna at low). It applies only while that tenant has never saved an 8 West ID AI selection, and then to all of its Westy chat, not only computer use. A saved 8 West ID selection always takes precedence, for every tenant. |
| Allowance | Each round allows up to 8,000 output tokens. When the five-round reserve would not fit the remaining monthly budget, the attempt uses 4,000, 2,000 or the original 1,200 tokens instead, and the reserve uses the same number. A tenant is therefore never refused sooner than before; for example, GPT-6 Astra on the default budget starts at 2,000. |
| Failure names | The adapter's closed reason codes (`input_count_unavailable`, `provider_invalid`, `provider_limit`, `provider_refused`) are kept instead of becoming `provider_unavailable`. Each failed attempt writes one content-free `westy_ai_failure` line (operation, sequence, round, reason, exception class) to the PHP error log. The portal shows a message for every reason plus "Reason code: …". |
| Stale turns | A pending turn is shown as interrupted after 180 seconds measured from its newest attempt, not from the first one. |

No schema, migration, protected configuration, vendor-pinned `tenant_ai/*` file, Milepost or
Companion change.

### Time boundary

The existing attempt deadline remains 150 seconds. For a legacy customer binding, an
authority check can refresh revocations (12 seconds) and AI selection (8 seconds).
Each desktop request can take 8 seconds. The dispatcher reserves 20 seconds for a
poll plus Stop and headroom, and checks for another 20 seconds **before** starting
authority validation. It rechecks the remaining time afterwards. Dispatch needs
at least 48 seconds both before and after its first authority check.

For a `customer_access` binding, each membership read can take 12 seconds.
Reauthorization, Westy scope and AI scope each perform one; each desktop request
performs a scope check both before and after transport. Consequently, authority
can take 56 seconds and a scoped request 32 seconds. The poll/Stop reserve is 68
seconds, and dispatch requires 120 seconds. These are scheduling allowances,
not a promise that the driver will answer. A slow queue can leave time only for
Stop, yielding an unknown outcome. None of these checks skips authentication,
revocation, membership or a user Stop.

The deadline is cooperative: these allowances cover the existing bounded network
calls, with headroom for local work; PHP scheduling or database locks are not a
real-time guarantee. Final accounting and delivery revalidation remain required
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
| `invoke_menu`, `set_window_frame`, `bring_to_front` | Click menu items by element token, use the app's own shortcuts such as `["alt","f"]`, or retry one action with `delivery_mode: foreground`. Moving or resizing a window is not available. |
| `list_apps` | `cua_launch_app` by name or AUMID; `exec_command` for discovery where available. |
| `kill_app` | `["alt","f4"]` with `cua_press_keys` after the person asks; `exec_command` under the existing policy. |
| `clipboard_read`, `clipboard_write` | Select, then `["ctrl","c"]` or `["ctrl","v"]`; `exec_command` in user context. |
| `browser_dialog`, `browser_set_input_files`, `browser_download`, `browser_pointer` | Native window clicks and keys on the dialog, file picker or download bar; `cua_scroll` on the browser window. Not equivalent in every case. |
| Driver configuration, update, recording, history and diagnostic tools | Deliberately not offered; they change or inspect the driver, not the person's task. |

The completion review removed the candidate's additional launch-path and URL
policies. `cua_launch_app` forwards the requested executable path, including a
network or device path, and accepts addresses or file paths for the default app.
The driver and Windows determine whether the requested launch can execute under
the user's existing permissions. `cua_browser_navigate` accepts `about:` pages
as well as `http` and `https`. Empty or malformed argument shapes are still
reported before dispatch; there is no new discretionary permission guard.

The rejection of `file:///` in `cua_browser_navigate` is upstream behavior. Its `browser_navigate`
accepts only `http:`, `https:` and `about:`.

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
- The existing durable replay expires every Cua observation. A continuation therefore starts
  with a fresh `cua_window_state`.
- The saved continuation keeps each Cua call's name and call id, but replaces its
  arguments with `{}`. This includes attempted calls to the removed `desktop_cua`
  relay. OpenAI retains an argument JSON string; Anthropic retains an empty object
  and its paired `tool_use`/`tool_result`. Current in-memory arguments are untouched.
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
  - Frankie approved 20,000,000 on 2026-10-11. Applying it is a protected runtime operation in the
    release procedure; this patch does not change it.
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
  The completed candidate passes **385 cumulative assertions**, with the same
  138-assertion starting suite. Separate diagnostics, recovery and replay suites
  pass 18, 15 and 61 assertions respectively. All seven changed PHP files lint,
  and the changed browser JavaScript passes its syntax check.
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
  receipts", passes at 1440x900 and 390x844. Both saved screenshots were inspected.
  The case covers "Result unavailable" and "Driver replied"; these are synthetic
  receipts, not proof of a live device action.
- **Coastline MySQL:** the preceding author's fourteen-suite report is historical;
  the final application bytes independently pass the affected delivery, run and
  workspace suites with 322, 144 and 133 cumulative checks respectively. Those
  totals include each suite's 84-check device setup and must not be added as
  unique new assertions. The isolated test containers and volumes were removed.
  The completion handoff binds these logs and the original author snapshot to
  the final local commit with a source manifest.
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

There is one existing tenant-wide owner/admin AI model selector. Separate computer
and chat selectors were not implemented by this work. The report that Sol6.1 was
selected in 8 West ID Settings is not independent proof of the active profile or
revision. Changing the saved model, provider, revision or credential while a
continuation waits makes admission fail with `conversation_changed`; its previous
work is not replayed. Reverting the internal fallback from Sol to Luna has the
same model-match consequence for an internal-legacy pending continuation. Finish
or explicitly stop pending work before a planned model/source rollback, then
start a fresh request under the intended selection.
