# Westy desktop integration candidate

The combined source has passed its portal, device, desktop and AI checks. It is
not deployed or accepted on a Windows endpoint. It includes the Milepost desktop
service, ordinary-user companion, portal shell integration and selected-provider
adapter. The existing project coordinator owns integration and release sequencing;
see [the final integration evidence and release gates](WESTY_INTEGRATION_2026-10-04.md).

## Customer flow

Open Westy from the Windows tray and choose Connect Westy. Its embedded portal
opens the existing 8 West ID sign-in in the system browser. Explicitly approving
the connection permits a one-use server-side identity handoff to that exact
embedded browser session. Passwords, OIDC tokens, provider credentials, agent
credentials, and browser cookies are never copied into the native executor.

Write the request in the portal or companion chat. After the response completes,
Computer control can continue that same request on a connected computer. Choose
the computer and allowed HTTPS website origins, then approve a target window in
the native consent dialog. Progress remains in the originating conversation.
Stop in the portal, local Stop, Ctrl+Alt+F12, or ordinary human input ends control.
Passwords, MFA, UAC and elevated applications require the person.

## Integration interfaces

* Include `app/lib/portal_desktop_controls.php` in the existing portal shell and
  render `portal_desktop_controls()` outside the chat composer form.
* Emit `westy-conversation` with the current conversation and latest completed
  original operation. The controls pin that operation when starting a task.
* The controls emit `westy-desktop-resume` once after local consent. The existing
  stream renderer posts `action=desktop_resume`, `operation`, and `conversation`
  to `/portal/westy.php` with the portal CSRF header. It must not submit a new
  message, model, tenant, native task, or automatically retry an interrupted call.
* Use `portal_desktop_context($pdo,$context,$conversation,$operation)` for resume
  and Stop. The lookup requires the private actor scope, current portal-session
  hash, origin, conversation, and original operation; ambiguous matches return
  no desktop authority. Browser fields never select native task identity.
* Expose `portal_westy_desktop_definitions()` only after the selected provider's
  catalog confirms typed function calls with image input. Dispatch through
  `portal_westy_desktop_dispatch()`. Its `public_result` can be logged; its
  `private_observation` and `image_png` are transient provider input and must not
  be persisted, emitted to the browser, or given to a generic tool logger.
* The AI worker owns original-prompt loading, one paid continuation per native
  task, usage reconciliation, stream rendering, and exact original-operation
  Stop. Desktop execution receipts do not prove completion of the user's goal.

## Configuration and migrations

Protected migration integration and canonical schema updates are reviewed and
included in the combined source; production application remains gated by the
documented recovery procedure. The additive migration is
`app/db/migrations/desktop_portal_sessions_v1.sql`; it creates only
`portal_desktop_handoffs` and `portal_desktop_bindings`. Do not silently apply it
on request startup or use a production database for the test fixture.

The server-only `desktop_companion` configuration requires `endpoint` equal to
`https://support.8westit.com/api/svc/desktop_sessions.php` and an independently
provisioned 64-character hexadecimal `service_secret` shared with that dedicated
Milepost service. Provision credentials through the existing protected procedure;
never put values in source, browser JavaScript, native configuration or logs.
This contract cannot reuse generic service authorization.

Run `app/cron/desktop_sessions_prune.php` every minute as the application user to
erase expired handoff identities and private session bindings. Handoffs expire
after five minutes; consumed identity JSON is erased immediately. The native web
session is rotated before use and passes the current revocation and customer
binding checks. A valid cached login alone cannot bypass those checks.

The deployment-owned wrapper in Milepost's
[cleanup runbook](https://github.com/Seckcey/8westit_webapp/blob/main/docs/WESTY_DESKTOP_CLEANUP_OPERATIONS.md)
runs this command as `www-data` with an independent one-minute systemd timer.
It continues when Milepost's transient mount fails. Direct cron execution alone
does not supply the required readiness receipt. Both apps read the same fixed
`/run/8west-desktop-cleanup` location on the shared host and require both jobs to
have two consecutive successful completions, with receipts no older than 90
seconds from this boot. Missing, stale, malformed, incomplete, failed or
full-batch cleanup returns `cleanup_unavailable` before new desktop requests can
reach the transport. Stop/state/result retain normal authorization and transport
checks but bypass this health gate. Ordinary chat, human takeover/write holds,
session lifetimes and tenant boundaries are unchanged. Recovery requires real
successful cleanup; there is no manual health override or new external alerting.
The wrapper/units and matching application readers require the separately
authorized backend release; these source changes do not install or enable them.

## Verification and remaining gates

`app/tests/desktop_return_path_test.php` exercises the narrow OIDC return path.
`app/tests/desktop_handoff_mysql_test.php` uses the real portal authentication,
revocation cache and private binding helpers against a disposable MySQL schema.
It refuses to run unless `MILEPOST_TEST_DB_NAME` is a `milepost_*test*` name and
`MILEPOST_TEST_DB_ALLOW_DROP=YES`; all identities and feed entries are synthetic.
The established `app/tests/portal_login_test.php` remains part of the regression
checks. Container execution belongs on the isolated Coastline test host.

`portal_desktop_context` ambiguity is an explicit `desktop_unavailable` error: Stop
must never report success after choosing no task from multiple exact matches.
The private screenshot's width/height are its original physical window pixels;
provider resizing must map coordinates back to that source size.

`app/tests/desktop_migration_mysql_test.php` exercises the protected migration,
original backup/intent/receipt checks, partial/drift/replay refusal, selected
database identity and the full canonical fresh schema. See the desktop section
of `deploy/README.md` for the release and restricted cleanup grants.

Combined AI/device/desktop source checks have passed at the exact integrated
candidate documented above. Remaining acceptance includes genuine
browser-to-companion sign-in, normal approval delay,
selected Windows app and browser behavior, session lock/multiuser/UAC, signed
installer upgrade/repair/rollback/uninstall/reboot, and a production mount and
rollback plan. No live install, migration, paid call, or endpoint control is
authorized by this document.
