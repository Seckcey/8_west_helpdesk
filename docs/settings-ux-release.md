# Settings UX — deployed October 22, 2026

`SETTINGS-UX-CORE-20261021` is presentation work authorized October 21, 2026.
Safeharbor's Sergeant deployed application source
`c79cf5949165bc135546806ccf12a8c33c33ab48` at **12:07:28 AM PDT on October 22, 2026**
(`2026-10-22T07:07:28Z`). This is the current technical release, replacing
`4021f4507f4727259d0c67ae2a99ba5beaf97400`. **Genuine signed-in customer/provider
acceptance remains pending with Frankie.** Signed-out health checks and synthetic
fixtures do not establish that acceptance.

## Deployment receipt

- [PR #257](https://github.com/Seckcey/8_west_helpdesk/pull/257) merged normally as
  `c79cf5949165bc135546806ccf12a8c33c33ab48`. Its complete tree
  `751a9140a642ab65c8e3dbabaae4a46966f96be8` exactly matches the reviewed and tested
  worker head `50a45f52edb20d8ba299f7f4654682c92a9bd869`.
- Required PHP and browser jobs passed on the reviewed head in
  [run 39736692126](https://github.com/Seckcey/8_west_helpdesk/actions/runs/39736692126)
  and on deployed main in
  [run 39737445023](https://github.com/Seckcey/8_west_helpdesk/actions/runs/39737445023).
- The normal `deploy/deploy.sh` operation exited successfully and recorded the
  `4021f45` → `c79cf59` transition at the timestamp above. Preflight matched the
  reviewed plan: 16 changed files, one added, none removed. The normal postflight
  `--check` at 07:08 UTC reported live and target `c79cf59`, zero file/folder
  differences, an active runtime and a passing site check. No failed deployment
  or automatic inverse occurred.
- The normal changed-file backup is
  `/srv/8west/apps/safeharbor/deploy-state/backups/20261022T070723Z-4021f4507f47-to-c79cf5949165/files.tar`:
  266,240 bytes, root-owned, mode `0600`, with a successful archive listing and
  verified `from` `4021f45` / `to` `c79cf59` records. It retains the prior
  privacy-compatible source for rollback; no new database backup campaign or
  database inverse was performed.
- Protected configuration and all six cron files were byte-identical before and
  after deployment. Configuration ownership/mode stayed
  `ubuntu:eightwest-safeharbor 0640`. The existing enabled portal remained enabled;
  there was no flag change. Safeharbor PHP-FPM, suite sign-up and desktop-cleanup
  timers were active afterward. No in-progress deployment marker or global/report
  lock holder remained; the serialized runtime slot was released to the lead.
- Public and origin login, CSS and package-manifest requests returned `200`.
  `/portal/`, `/portal/requests.php`, `/users.php` and `/profile.php` returned `302`
  to sign-in. The served v0.5.0 manifest matched
  `0f1af949fe0b9cce01014bb8ac63b05af6936c858710a222c875cb8e36f61f0f` on both paths.

Requester schema, all three immutable guards and history were untouched. No
migration, fabricated session, account/settings save, ticket, mail, provider,
financial or device action was used for acceptance. The older `0548f3e` source
remains privacy-incompatible: any inverse to it requires the portal held false
before the inverse and until a qualified forward repair, preserving all history.
The normal prior-source backup above is `4021f45`, not that older source.

The original authorization clock remains `2026-10-22T06:24:11Z`; it was not reset
for source, CI, deployment or documentation follow-up. This factual closeout is
documentation-only. A later documentation merge leaves deployed application
content at `c79cf59` and needs no production redeploy.

## Behavior

- Portal Account uses full-width links/buttons with at least 44px targets,
  destination hints, the trusted current identity, effective role and selected
  client name. Switch client retains the existing client chooser and its checks.
  My settings, Client Admin and Plan and billing identify 8 West ID. Computer
  access, guide and support retain their existing destinations. Sign out is still
  the same CSRF-protected POST. The sidebar scrolls when its contents exceed the
  available height, including the Westy workspace on phones.
- Staff Team uses labeled Technician/Administrator/Owner choices and named,
  keyboard-focusable color radios. Native checked state drives the visible
  selection. Access buttons state Enable/Disable; disabled rows remain readable.
  Role values, account handlers and all current access checks are unchanged.
- Profile and Team use clear destination buttons, Cancel navigation and
  accessible success/error feedback. Local recovery forms remain local; linked
  identities still manage profile/security in ID. Password requirements are
  unchanged. No new confirmation, unsaved-change blocker or execution guard.
- The staff menu uses the canonical Personal/Workspaces grouping and destination
  hint classes. Only an exact local destination receives a current-page marker.
  Settings links use the same tab; existing app-drawer behavior is unchanged.

No authentication, requester attribution, tenant/resource privacy, service
contract, schema, provider, billing or device behavior changes. Existing portal
enablement and AI/workflow gates remain unchanged. Client choices retain their
existing POST values and CSRF; the chooser does not invent a current marker from
an identity that lacks a freshly supplied selected-client context.

## Completed synthetic validation and cleanup

Validation used existing `portal_settings_test.php` and `suite_chrome_test.php`,
then `tools/shots/portal-settings.test.mjs` and `tools/shots/suite-settings.test.mjs`.
The latter also invokes CLI-only `staff_settings_fixture.php`: actual PHP
Team/profile handlers and templates run against synthetic identities and an
in-memory adapter. It cannot access a real configuration, database or account.
This checks UI feedback and submitted fields, not database persistence or SSO.

The browser matrix covers 1440px desktop and 390px mobile, light/dark, portal
member/viewer/admin and Westy workspace, staff admin/technician menus, Team and
local/suite-linked profile controls. It checks target sizes, overflow, keyboard
selection/focus, immediate access toggle, name-save feedback, duplicate-account
and password errors, Cancel, and console/page errors. All browser requests are
intercepted fixtures; no real ID navigation or account operation is performed.

The shared UI was imported through `tools/w365/sync-suite-ui.ps1 -Tag v0.5.0
-Dest app/public/assets/w365`, with no manual vendor edits. The published tag
resolves to `80131ef982e5e8a2c2ca2b76b2ffc985227ce04c`. The downloaded ZIP SHA-256 is
`1ad5263bc71f81e8864c7a8a863d89fac140a7de0971437f86870687dd8f8f13`; the manifest is
`0f1af949fe0b9cce01014bb8ac63b05af6936c858710a222c875cb8e36f61f0f`.
The existing sync tool verified all ten files. No obsolete host menu-button
padding override was present. The menu's current-page state does not change any
destination or authority.

Containers ran only in the worker's isolated Coastline task directory. Focused results after
the package sync: portal settings 26 checks, portal data 61 checks, suite chrome 66
checks, portal browser 12 cases and staff browser 16 cases passed. The initial PR
PHP run found a changed neutral-label expectation; the established `Your account`
fallback was restored and portal data passed. It was not treated as a passing run.
The Browser plugin was unavailable; the repository's existing Playwright workflow
was used. Screenshots are external review artifacts, not production captures.

The final test copy byte-matched all 495 files from the exact `50a45f52` Git archive
(SHA-256 `1537ee81d907fa383c6788ac9d77c64c384810388c238da6e74cb493ac915392`).
Thirty-six final screenshots, logs and source-identity/hash receipts were retained
outside the repository and handed to the coordinator. Earlier local previews are
marked as historical, separate from the final screenshots.

Cleanup was verified before handoff: `shux-01a16251-browser`, the task's temporary
cleanup container and `/tmp/shux-01a16251` were removed. No service port, test
database, test volume or custom network was created. Documentation closeout added
no new test run, runtime operation or temporary resource.
