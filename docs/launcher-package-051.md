# Shared launcher package v0.5.1

Safeharbor consumes the published [w365 v0.5.1 release](https://github.com/Seckcey/8_west_suite_ui/releases/tag/v0.5.1)
from `Seckcey/8_west_suite_ui`, tagged at
`7b5600ddd41a03ae78fe0845b6bc2c348016222e`.

The catalog now contains Central, Milepost, Safeharbor, Coastmark and the Control
Panel. Logbook and Cloudline are absent from the display catalog, so old claims
for `logbook` or `missioncontrol` no longer produce launcher tiles. Safeharbor
still excludes itself and shows only the other apps in the user's verified
entitlements. Local-login and empty-drawer behavior remain unchanged.

This is a presentation package update. It does not remove durable product keys,
change authentication or role permissions, or delete retained artwork. All seven
mark files remain unchanged. Launcher JavaScript and CSS differ only in version
stamps; the application integration is unchanged.

## Import and verification

The ordinary release sync was used, without local package overrides or manual
edits to generated files:

```powershell
./tools/w365/sync-suite-ui.ps1 -Tag v0.5.1 -Dest app/public/assets/w365
```

- ZIP: `w365-v0.5.1.zip`, 161,410 bytes; SHA-256
  `c9d97ed74f6ed02ad1b15e85589317d53c8fcafda86c9d83ed1db4c9eb392b26`.
- Manifest SHA-256:
  `6e53239e4b4973a7adc0385724b8f3ccc02c88adf3eaa2772907bce129dd74cf`.
- The package contains 11 files: the manifest and its 10 listed entries.
  The consumer test pins the reviewed manifest and verifies every listed file's
  size and SHA-256, including absence of extra or missing files.
- Canonical source [CI](https://github.com/Seckcey/8_west_suite_ui/actions/runs/42161505175)
  and [release workflow](https://github.com/Seckcey/8_west_suite_ui/actions/runs/42161957749)
  succeeded at the tagged commit.

`app/tests/suite_chrome_test.php` checks the actual drawer renderer, retired-only
and mixed entitlements, current-app exclusion, identity refresh, role-specific
settings and the package pin. Its synthetic admin/technician captures include
all active products and both retired claims. `tools/shots/suite-settings.test.mjs`
exercises the published launcher with actual rendered staff menus in light/dark
themes at desktop and phone widths, including keyboard focus and sign-out.
The normal PHP and browser jobs remain the PR gates.

## Release boundary

This source-only sync does not establish production installation or genuine
signed-in employee/owner acceptance. The runtime remains the release recorded in
[where things stand](where-things-stand.md) until the agreed release owner ships
and verifies a later candidate. There is no migration, configuration or feature
switch change, and no mail, Guide publication or provider operation in this work.
