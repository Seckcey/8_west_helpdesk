# Westy computer use: typed Cua tools — October 11, 2026

Source candidate on branch `claude/westy-cua-fix`. Not deployed. Frankie's laptop
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
| Time | After a tool result, the attempt yields a durable continuation when less than 90 seconds remain. Each existing desktop request may take 8 seconds, so a Cua call is sent only with at least 48 seconds left: 8 to queue, at least 20 waiting for its receipt, and 20 in reserve. The receipt wait carries the attempt deadline and stops while one more poll, the existing Stop and headroom still fit. A receipt that does not arrive in time is `unknown`, the existing scoped Stop follows, and the call is never repeated. A call that is not sent is answered `not_sent`; the model sends it again after the continuation. |
| Receipts | A driver reply is a receipt, not proof of the requested outcome. Rows read "Driver replied", "Not completed" (driver error) or "Result unavailable". "Result unavailable" means `returned` with `reason: response_unavailable`: the driver acknowledged the call, but its response was lost. That row explains that the effect is unknown. The model is told not to repeat the call and to observe again before reporting any outcome. |
| Mistakes | Invalid arguments return `rejected` with correction guidance. A call to a tool that is no longer offered returns the real offer. Neither reaches the device. The guidance repeats only server-owned tool and field names, counts and supported formats; supplied field names and values are never echoed. The fifth such call in one turn, counted across continuations, ends the reply as `tool_invalid` instead of spending more paid rounds. |
| Model | The internal tenant uses GPT-6.1 Sol at medium effort (was GPT-6 Luna at low). This applies to all of that tenant's Westy chat, not only computer use. Tenants with an 8 West ID AI selection are unchanged. |
| Allowance | Each round allows up to 8,000 output tokens. When the five-round reserve would not fit the remaining monthly budget, the attempt uses 4,000, 2,000 or the original 1,200 tokens instead, and the reserve uses the same number. A tenant is therefore never refused sooner than before; for example, GPT-6 Astra on the default budget starts at 2,000. |
| Failure names | The adapter's closed reason codes (`input_count_unavailable`, `provider_invalid`, `provider_limit`, `provider_refused`) are kept instead of becoming `provider_unavailable`. Each failed attempt writes one content-free `westy_ai_failure` line (operation, sequence, round, reason, exception class) to the PHP error log. The portal shows a message for every reason plus "Reason code: …". |
| Stale turns | A pending turn is shown as interrupted after 180 seconds measured from its newest attempt, not from the first one. |

No schema, migration, protected configuration, vendor-pinned `tenant_ai/*` file, Milepost or
Companion change.

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

Three of the input rules are Westy adapter policies, not upstream or platform limits:

- **Launch URLs:** `cua_launch_app` `urls` accepts only `http` and `https`. Upstream `launch_app`
  also accepts local paths and hands them to the default app.
- **Launch paths:** `cua_launch_app` `path` accepts only a local drive path to an `.exe`, with no
  network share, device path or alternate data stream. Upstream `launch_app` takes any executable
  path.
- **Navigate:** `cua_browser_navigate` accepts `about:blank` and no other `about:` page.

The rejection of `file:///` in `cua_browser_navigate` is upstream behavior. Its `browser_navigate`
accepts only `http:`, `https:` and `about:`.

Local files and scripts remain possible:

- **Open a file in a chosen app:** use `cua_launch_app` with an app path or name plus `arguments`.
  For example, `notepad.exe` with a file path, or `chrome.exe` with a `file:///` address as an
  argument.
- **Run a script:** use `C:\Windows\System32\cmd.exe` with `["/c", "<script path>"]`, or
  `exec_command` where general tools are installed.

Milepost and Companion launch and permission rules apply to all of these.

The Companion's existing preference rules also apply:

- With only browser control or only desktop control allowed, calls without a `pid` are refused,
  for example `cua_list_windows` without one, or `cua_launch_app` with `urls`.
- A call for a process outside the allowed category is refused as well.
- Full permission has no such limit.

This patch adds no new guard or confirmation. Explicit denies, Stop, account and device
boundaries, and unknown outcomes keep their existing behavior.

## Privacy and authority

- Window titles, control labels and values, page text, typed text and screenshots stay in the
  provider request for the current attempt only.
- Public receipts keep only:
  - the state and action id;
  - closed driver codes;
  - pid and window_id;
  - the count of typed characters;
  - server-written guidance.
- The existing durable replay expires every Cua observation. A continuation therefore starts
  with a fresh `cua_window_state`.
- The model can no longer call driver tools outside the curated set (for example `set_config`,
  `kill_app`, clipboard or recording tools), which the raw relay allowed.
- Browser and launch permissions are still enforced by Milepost and the Companion.
  `cua_browser_prepare` uses only the Companion's existing-profile grant. The playbook requires
  telling the person before connecting to their browser.

## Cost and limits

- **Maximum reserve per attempt:** before provider I/O, a five-round Sol attempt holds a maximum
  admission reserve of 1,225,600 micro-USD (about $1.23). The reserve is refunded down to the
  measured cost when the attempt finishes. It is not a typical cost; actual per-task cost has not
  yet been measured with a live provider.
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
  - An independent adversarial review of the first commit found no privacy or contract defect.
    Its five findings (the evidence bound for quote-dense text, launch paths, oversized rejected
    field names, the expensive-model reserve, repeated mistakes) are fixed in the following commit.
  - Safeharbor's review of that commit requested three corrections, all fixed in this patch:
    lost driver responses shown as done, the dispatch margin, and supplied names in rejection
    guidance.
- **Local tests:** PHP 8.3, `portal_westy_desktop_continuation_test.php`, including the Cua cases.
  They cover:
  - the lost-response receipt;
  - the 48-second dispatch boundary;
  - a slow receipt that ends unknown before the deadline;
  - private markers in field names and key values.
- **Browser contract:** `tools/shots/portal-contract.test.mjs`, "general checks wait for
  receipts", at desktop and phone sizes. It covers "Result unavailable" and "Driver replied".
- **Coastline MySQL:** disposable MySQL 8.0.46 and PHP 8.3. The fourteen Westy and desktop suites
  passed at the preceding commit.
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
