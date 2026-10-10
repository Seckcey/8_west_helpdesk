# Westy connection renewal and the cached controller

The desktop controller previously used the fixed asset URL
`portal-desktop.js?v=2`. Static responses can remain cached for seven days, so
an already connected browser could keep the older script that predates connection
renewal after the server was updated. The rendered workspace now uses the existing
content-hash asset helper. A changed script produces a changed URL.

This change does not alter renewal authority, proof validation, session expiry,
device selection, Stop or the JavaScript renewal implementation. The current
script already requests renewal while the normal Companion controls are collapsed.

The exact live candidate is `9d1c23c254ab9358d8cf9a7c1422856898d5a74f`, on the
previously reviewed orchestration candidate `2a2cd18c`, then deployed baseline
`a357c917`. Its normal integration counterpart is `049aa636`, on PR 204 merge
`e5a6ede1`. Only the script tag changes production behavior in this follow-up.
No schema or configuration change is needed.

Three real Chromium browser tests pass on both candidate trees, along with 24
existing rendered readiness checks, 32 renewal assertions and PHP lint. The
cache regression uses a real loopback HTTP server and browser cache; restoring
only the fixed URL reproduces the missing renewal. Tests also retain missing-proof
and explicit-refusal behavior. These are synthetic browser checks, not laptop
acceptance.

The affected laptop pairing had valid original renewal proof and fresh native
presence, but no renewal registration at the inspected instant. The exact bytes
cached by that laptop were not inspected. This source defect is therefore a
supported explanation to verify, not a claim that every disconnect has one cause.

After deployment, verify that the served workspace references the current content
URL, that a real signed renewal registers and advances the pairing expiry within
its original authority, and that the laptop remains connected past its former
deadline. A merge, successful browser fixture or cache invalidation alone does
not establish those live results. Deployment and installed-device verification
remain pending for this source change.
