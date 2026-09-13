# Safeharbor w365 placement release — 13 September 2026 UTC

The app drawer (the 3x3 squares) and the account menu now sit together at the
right end of Safeharbor's topbar, after the timer widget, in the one place every
8 West IT 365 app puts them. The hard-coded "8 West Suite" sidebar list
(Milepost, Coastmark, All apps) and the lower-left identity menu are gone. The
drawer lists only the apps the verified `8west:products` claim licenses, and
only when this account is the person the token describes; a local password
login sees the honest "sign in with 8 West ID" state. The account menu shows the
8 West ID name, email and picture, Suite settings, Safeharbor's own profile page
and Sign out. Frank's suite-wide placement request and the standing safe-release
authorization cover this code-only release.

- PR: [121](https://github.com/Seckcey/8_west_helpdesk/pull/121), squash-merged.
- Application: `67ea9e36941b5ac913edf11eed197bbf1f5e2715`.
- Exact-main Validate: [34763944114](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34763944114), passed.
- Shared package: w365 `0.2.0`, manifest SHA-256
  `f70a308b0646ac03e4e6e6d6cc55e79bde98d591536d43c5b56277e657760e17`, vendored
  under `app/public/assets/w365/` and pinned by `app/tests/w365_vendored_test.php`.
- Content-Security-Policy: the staff app sends none; nothing changed.
- Deployed artifact SHA-256: `195d53bc6be0120bd85abd11ed4e557f0bb8450dcc0908dd9c4c85d8d0b652e1`
  (installed under the exclusive report/deploy lock by the reviewed
  `deploy/deploy.sh` and `remote-install-safeharbor-app.sh`).
- Prior application: `9d57eed6ae5c806210a387bedba5f83e952a18be`.
- Protected backup: `/srv/8west/backups/safeharbor/20260913T170809Z-pre-w365-67ea9e3`
  (application tree excluding the protected config, the config, a
  trigger/routine/event-inclusive database dump and the runtime grants).
- Backup SHA-256: application `4a69352be23ebd996fe6fad3de1f83b1167150896ff330da994c5a5e7f3512cb`,
  database `7ac977a977af8d312e725370b3f1c6b090b4e15a31dfb1fc883ea17d2a5c53a4`,
  config `8ba44c0862a9f9183626961010e6429e8fef93a106952c6602a8e9f8a2c9463f`,
  grants `aac36136c1f36c9014610e2503a9174a281a67e568b4caf69add28cf5cdf5bfc`.

Report scheduler sequence (docs/business-reports-contract.md): the existing
`9d57eed6` control bundle stopped the scheduler at `17:14:21Z` (active cron name
and activation record preserved to their quarantine paths); the deploy ran from
`stopped`; a new root-only control bundle for `67ea9e36941b5ac913edf11eed197bbf1f5e2715` was staged from the
exact clean release, its manifest built from the new immutable release marker,
`preflight` passed, the scheduler was installed disabled, a new activation
record rebound the release/artifact/config fields while retaining the original
real canary evidence, and `enable` with the exact tuple repeated as operator
intent returned `active` at `17:23:54Z`; `verify active` agreed. No report was
generated or sent; both report gates and the schedule scope are unchanged.

Shared Apache was never stopped or restarted for this release. No migration
ran. Public verification: `/login.php` 200; `assets/w365/w365.css` served with
the manifest hash; the launcher, a tile mark and the versioned `app.css` served
from this origin.

Not done here: signed-in acceptance of the drawer and the account menu in a
browser is Frank's, and remains pending. This is not restricted-role pilot
acceptance.
