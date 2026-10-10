# Westy browser observation recovery

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
