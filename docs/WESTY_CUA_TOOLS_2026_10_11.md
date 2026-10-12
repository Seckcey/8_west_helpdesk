# Westy computer use: typed Cua tools — October 11, 2026

Source candidate on branch `claude/westy-cua-fix`. Not deployed. Frankie's laptop
acceptance (Notepad and browser) is the gate; synthetic tests do not prove it.

## Why computer use stopped

The Companion and the pinned Cua Driver 0.34.0 worked. Safeharbor's tool loop did not:

- The model had one raw MCP relay, `desktop_cua`, and was told to read the driver's tool
  catalog plus `SKILL.md`, `RUNTIME.md`, `WINDOWS.md` and `BROWSER.md` (about 92 KB) before
  acting. That used every round of an attempt.
- Every continuation expires screen observations by design, so the reading was lost and had to
  start again.
- Each round allowed 1,200 output tokens. Reasoning counts against that allowance, so replies
  could be cut off (`provider_incomplete`).
- An attempt that passed its 150-second deadline ended as `interrupted`.
- `interrupted`, `provider_incomplete`, `tool_invalid`, `context_limit` and several other
  reasons all showed the same "This reply was interrupted…" message.

## What changed

| Area | Change |
| --- | --- |
| Tools | `desktop_cua` is no longer offered. Thirteen validated tools replace it: `cua_list_windows`, `cua_launch_app`, `cua_window_state`, `cua_click`, `cua_type_text`, `cua_set_value`, `cua_press_keys`, `cua_scroll`, `cua_browser_state`, `cua_browser_prepare`, `cua_browser_navigate`, `cua_browser_click`, `cua_browser_type`. `desktop_stop` is unchanged. |
| Translation | `app/lib/portal_westy_cua.php` checks every field, then builds the exact upstream `tools/call` (`get_window_state`, `click`, `type_text`, `press_key`/`hotkey`, `scroll`, `set_value`, `launch_app`, `list_windows`, `get_browser_state`, `browser_*`). Unknown fields, including `session`, are refused. The Companion still injects its own session. Only named keys the driver knows are sent, because the driver presses the first character of an unknown key name. |
| Observation | `get_window_state` is bounded (150 controls, 1,000 with a query, 1,280-pixel screenshot). The model receives one line per control instead of JSON plus a duplicate Markdown tree. Evidence per call is capped at 24 KB. Only the newest screenshot is sent to the provider. |
| Playbook | The device instructions carry a short find, observe, act, verify playbook for Windows and the browser. No driver manual is required reading. |
| Time | After a tool result, the attempt yields a durable continuation when less than 90 seconds remain. A Cua call is never sent with less than 62 seconds left; the model is told it was not sent and sends it again after the continuation. |
| Mistakes | Invalid arguments return `rejected` with correction guidance. A call to a tool that is no longer offered returns the real offer. Neither ends the reply, and neither reaches the device. |
| Model | The internal tenant uses GPT-6.1 Sol at medium effort (was GPT-6 Luna at low). Tenants with an 8 West ID AI selection are unchanged. |
| Allowance | Each round allows 8,000 output tokens, and the reserve uses the same number. |
| Failure names | The adapter's closed reason codes (`input_count_unavailable`, `provider_invalid`, `provider_limit`, `provider_refused`) are kept instead of becoming `provider_unavailable`. Each failed attempt writes one content-free `westy_ai_failure` line (operation, sequence, round, reason, exception class) to the PHP error log. The portal shows a message for every reason plus "Reason code: …". |
| Stale turns | A pending turn is shown as interrupted after 180 seconds measured from its newest attempt, not from the first one. |

No schema, migration, protected configuration, vendor-pinned `tenant_ai/*` file, Milepost or
Companion change.

## Privacy and authority

- Window titles, control labels and values, page text, typed text and screenshots stay in the
  provider request for the current attempt only. Public receipts keep the state, the action id,
  closed driver codes, pid and window_id, and the count of typed characters.
- The existing durable replay expires every Cua observation. A continuation therefore starts
  with a fresh `cua_window_state`.
- The model can no longer call driver tools outside the curated set (for example `set_config`,
  `kill_app`, clipboard or recording tools), which the raw relay allowed.
- Browser and launch permissions are still enforced by Milepost and the Companion.
  `cua_browser_prepare` uses only the Companion's existing-profile grant. The playbook requires
  telling the person before connecting to their browser.

## Cost and limits

- A five-round Sol attempt reserves 1,225,600 micro-USD (about $1.23) before provider I/O; unused
  reserve is refunded. Typical computer tasks should cost well under a dollar each.
- The default `portal_westy.monthly_microusd` is 5,000,000 ($5). Admission refuses an attempt
  when spend plus its reserve exceeds the budget, so a $5 budget allows only a handful of computer
  tasks a month. The configuration accepts at most 20,000,000 ($20); a larger value makes Westy
  unavailable.
- `hourly_limit` counts attempts, and computer tasks now use several continuations.

## Validation (synthetic)

- Local PHP 8.3: `portal_westy_desktop_continuation_test.php` (275 assertions including the new
  Cua cases), `tenant_ai_catalog_test.php`, `portal_westy_ai_receipt_test.php`,
  `portal_westy_desktop_diagnostics_test.php`, `portal_westy_desktop_recovery_test.php`,
  `portal_westy_replay_test.php`, `portal_computer_time_test.php` and the desktop and workflow
  tests without a database.
- Coastline, disposable MySQL 8.0.46 and PHP 8.3: `portal_westy_mysql_test`,
  `portal_westy_ai_delivery_mysql_test`, `portal_westy_runs_mysql_test`,
  `portal_westy_control_mysql_test`, `portal_westy_terminal_mysql_test`,
  `portal_westy_feedback_mysql_test`, `portal_workspace_mysql_test`, `portal_devices_mysql_test`,
  `portal_employee_device_access_mysql_test`, `portal_access_mysql_test`,
  `desktop_renewal_mysql_test`, `desktop_handoff_mysql_test`, `portal_stream_http_test` and
  `portal_security_orders_test` all passed.

## Acceptance (Frankie, on the laptop)

1. Notepad: ask Westy to open Notepad, type a sentence, and read it back. Expect rows such as
   "Open app · Done", "Read window · Done", "Type text · Done", and a reply that quotes the text
   it read back. The document stays unsaved.
2. Browser: ask Westy to open a web address and report the address and page heading. Expect the
   reply to give the address shown in the address bar and the page text.
3. If a reply stops, note the "Reason code" line and the time.

## Rollback

Revert the commit. Nothing else needs to be undone.
