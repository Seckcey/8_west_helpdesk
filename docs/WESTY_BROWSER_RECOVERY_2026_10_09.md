# Westy browser observation recovery

## Typed refusals and retained control cleanup

The later controlled Full 1.26.16 attempt opened a new example.com window, then
received an HTTP 400 before another action receipt existed. Safeharbor saved
`unknown / desktop_unavailable` and sent Stop. The original response body and
action arguments were not retained, so the specific rejected parameter is not
known. The attempt remains partial/failed; opening a page does not establish
reading its heading or following its link.

Live-base candidate `2a2cd18c22cff5b33d08e01787320ce70e50a7c6` follows actual
runtime `a357c91748384750d15c40650805db2c5d6e22e3`. Its main-compatible form
is `e8bf5e7434d8b55656bdbe8a83aa357fffe05a50` over `24920bcc`; the main-only
employee device-access check is preserved. The five runtime changes:

- Preserve recognized pre-enqueue refusal reasons, including unsupported keys,
  invalid text/scroll/selection and review failures. A known rejection is not
  recorded as uncertain execution and does not stop the entire task. Appropriate
  guidance permits a corrected new intent, never replay of uncertain input or
  bypass of a review denial.
- Release the exact owned waiting task when AI continuation admission fails,
  while retaining the original error, prior charges, attempts and receipts. A
  newer sequence, another account or another origin remains untouched.
- Expose `desktop_cleanup: {state: "stop_unconfirmed"}` after inference ends if
  actual control release is still unconfirmed. The chat keeps an explicit Stop
  button across refreshes. New authorized work remains available, and stopping
  the old task does not interrupt an unrelated current reply.

Real isolated MySQL checks passed 694 cumulative assertions on the live-base
candidate and 691 on the exact archived main candidate; the difference predates
these changes. Focused desktop suites passed 15 and 18 assertions. The production
workspace browser fixture passed at desktop and phone sizes, including refresh,
failed then confirmed Stop, keyboard access, no replay and an unrelated active
reply. No unexpected browser errors or horizontal overflow were observed.

Existing CI includes the PHP regression cases. The browser fixture runs with
`node --test tools/shots/portal-desktop-cleanup.test.mjs`. All test containers,
volumes and browser processes were cleaned up after evidence retention.
Normal integration, exact production artifact review and live acceptance remain
pending. No native package, schema, provider/model setting or saved permission
choice changes in this slice. Deploy the reviewed live-base artifact, not newer
unrelated main features or an isolated file that depends on those features.

## Earlier observation-slot correction

An actual browser task from installed Full 1.26.14 returned a window inventory
and selected Chrome. The executed selection attached an unavailable observation
with reason controller_surface. Safeharbor returned generic observe_again before
collecting that observation. Milepost therefore retained its one-delivery slot,
and the next observation returned observation_busy. Westy gave up without
opening example.com, reading its heading or reaching a link destination.

The correction collects the attached observation once using the already
executed action's ID. It preserves the action reason and observation reason
separately, and exposes recovery through existing window discovery/selection or
desktop_launch for the requested ordinary URL in a new browser window. It does
not replay the completed selection or uncertain input, add a new permission
guard, require manual retry, or report navigation success from a launch receipt.

Authored source is 159953aae0d623c56c9ba1532deedd274112278a over diagnostic
428f37054c5b88c0310fe64db653c963b71a8b11 and accepted runtime c0bab890.
Unchanged normal-main integration is b3b763c over 5dc3f090, preserving main's
existing account/device operation check. Follow-up source a357c91748384750d15c40650805db2c5d6e22e3,
integrated unchanged as 0ad1a39, explains actual executable-path discovery through
the existing user-context command and process-result tools. It does not assume
Chrome is on PATH or invent an installation path. Focused checks passed: the new
dispatcher regression failed before the fix and passed 15 assertions afterward;
18 diagnostic, 21 AI receipt and 61 replay checks; PHP lint and diff checks.
The actual service-serialization case was added to the existing hosted MySQL
control suite. Its CI result remains separate from locally run synthetic checks.

No Companion display patch is included. A Windows accessibility view reported
old Working/Stop controls after the server completed, while the operator's later
visual observation showed connected status and no active-control warning. This
stale accessibility evidence does not establish a stuck UI or leaked control.

Normal merge, the complete accepted-base production artifact and its exact
application/report manifest bindings remain release gates. Do not deploy the
normal-main desktop file alone over c0bab: that runtime lacks main's unrelated
employee-access helper. The frozen deployed-base source cut preserves existing
branding, configuration, report controls and schema. Record the actual source,
artifact and live state; no production or working-laptop claim follows from
these source tests. The separate native read/transient-connection correction
still requires private signing and installation. The accepted backup and held
MP610/SH181 and unknown job4446 remain unchanged.
