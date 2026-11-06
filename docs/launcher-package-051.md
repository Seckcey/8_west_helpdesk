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

## November 5 production release

[PR #298](https://github.com/Seckcey/8_west_helpdesk/pull/298) merged as
`48a1790e06b84eb7c540867f92d76dc683b92a16`. Its tree equals the tested PR head
`43fef554ff835316404aa2917a019d54ce7cc869`. Both PHP/browser jobs passed on the
[PR](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42165237785) and
[merged main](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42165970481).
All 68 focused renderer checks passed on the exact archived source. Eight additional
synthetic browser cases covered admin/technician, light/dark and 1440/390-pixel
viewports: active destinations, drawer focus/Tab/Escape/outside click, account-menu
keyboard navigation, role boundaries and viewport fit. Representative desktop and
phone captures were visually inspected. Test resources and the local preview closed.

The single stock publication completed at **9:28:38 PM PST on November 5, 2026**
(`2026-11-06T05:28:38Z`), replacing `019087648a9d5447031bd067baeb4dd9a6303775`.
The pre-publication check found a natural onboarding job still running; publication
waited for its completion and a fresh idle snapshot. One stock `--check` and one
publication were executed, with no retry, migration or manual job invocation.

Exactly eight files were replaced: the four w365 package files,
`tests/suite_chrome_test.php`, and the normal asset stamps in `lib/render.php`,
`lib/westy.php` and `public/login.php`. No files or directories were added or removed.
All **424 files and 23 directories** matched the prepared release; the private
runtime user read and hashed all 424 files successfully. Origin and public requests
returned the pinned manifest above and the exact five-product catalog. Login/CSS
health passed and the protected portal redirected to sign-in.

Configuration and all six cron files retained their bytes and metadata. Both
original timers resumed. Only Safeharbor PHP restarted; recorded other PHP/Apache
states and process identities were unchanged. Deployment/report mutex identities
were preserved and available afterward, with no deployment or pause markers.

The **9:30 PM** natural report and all five shared-worker dispatches were observed.
Reports completed with `GENERATED=0`, `SUBMITTED=0`, `UNCERTAIN=0`, `ERRORS=0`;
scheduled lifecycle work reported zero claims/reservations. At **9:30:39 PM PST**,
relevant jobs and lock holders were absent and both timer services were idle/successful.
Portal maintenance also dispatched. Its input-file error for
`db/maintain_portal_westy.php` and the Westy-mail error for `cron/westy_mail_poll.php`
were present in both the 9:20 PM pre-release and 9:30 PM post-release samples.
Those existing actor issues were left unchanged; their dispatch is not success
evidence for maintenance or mailbox processing. Technical custody was returned to
the shared release lead after these checks.

### Rollback and retention

The immediate source inverse is
`deploy-state/backups/20261106T052836Z-019087648a9d-to-48a1790e06b8`.
Its 102,400-byte `files.tar` contains exactly the eight previous files, with SHA-256
`e6eaffa60d98dba8b0d71f1fc057d2b66ff8523773e50e6f7561e74ca8b36aea`.
The normal source inverse returns to `0190876`; it includes no database restore.

Normal keep-ten retention removed only the independently qualified source-only
inverse `20261101T221807Z-8e888fffa263-to-ab4b07690b3f`. Its 307,200-byte archive
was verified against the exact historical source before publication, with no
database content. The following database-bearing
`20261104T003940Z-ab4b07690b3f-to-7be64ab4fb69` remains. All other retained stock
entries were unchanged, including the #296 inverse
`20261106T043210Z-754c92d06a8f-to-019087648a9d`.

Both historical protected-recovery sets and their metadata remained exact:

- November 1 custody receipt:
  `71306736bac8f921c52851926a658089457a4f5ca12420a523809e561f3e660d`.
- October 23 custody receipt:
  `594e53eaa19ebccdf4d6174ff4aab72e472d4ad8a34743fb38c173947ffd9988`.

This establishes source installation and technical verification. Genuine signed-in
employee/owner acceptance remains open. There was no migration, configuration,
feature-switch or permission change, Guide publication, mail repair, or manual
provider/customer operation. The separate Apache and mail work retains its own gates.
