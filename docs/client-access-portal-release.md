# Client access and portal release — October 22, 2026

Safeharbor's technical release is installed. Genuine signed-in customer and
provider acceptance remains with Frankie; the evidence below does not establish it.

## What shipped

- `/portal/` is Home: requester-scoped tickets and counts, assigned computers,
  settings and support actions. Owners and administrators retain their existing
  client-wide view and service summaries. Failed reads say unavailable.
- Westy's existing workspace is at `/portal/?view=westy`; the Home trigger sits
  below the help card in normal flow instead of covering its text.
- A verified, entitled customer reaching the staff login on GET is handed to the
  fixed portal address. Staff sessions, explicit reauthentication and POST behavior
  are preserved. Portal admission still performs its own identity, binding and
  revocation checks.

See [the Home guide](employee-portal-dashboard.md) and
[the customer portal contract](customer-portal-contract.md). This release adds no
migration, configuration, entitlement activation or Westy execution change.

## Source and qualification

[PR #259](https://github.com/Seckcey/8_west_helpdesk/pull/259) was normally
squash-merged as `a63dc4ee43dcf32cb44401e2a0f265959e14b4fa`. Its complete tree
matches reviewed final head `8fa2e45dc4536d0e14ac57bbe1957b7e34aec78c`, which adds
only a factual guide correction to tested source
`71b66a830a0d9e0429f10ece36e57441272a3d82`.

- The owner ran the canonical source on Coastline using patched Ubuntu PHP
  `8.3.6-0ubuntu0.24.04.13`: nine changed PHP files passed lint; six focused PHP
  suites passed 415 checks; three real-MySQL suites passed 95 checks.
- The full portal-contract browser suite passed 22/22 and portal-settings passed
  20/20, with synthetic fixtures at 390px and 1440px. Corrected Home captures were
  inspected for role presentation and Westy overlap. Existing browser checks also
  scroll Sign out into view and verify it is not covered.
- An explicit LF archive matched all 497 archived Git blobs before the complete
  rerun. Its SHA256 was
  `1131efdcf86d0162038f9681a427dd3b73986ae07f32aab14e019f432773385c`.
  Earlier CRLF-converted exports remain historical behavioral evidence only.
- Both required GitHub checks, `php` and `browser`, passed at final head `8fa2e45`
  ([PR run 39909011882](https://github.com/Seckcey/8_west_helpdesk/actions/runs/39909011882))
  and exact merged main `a63dc4e`
  ([default run 39909563516](https://github.com/Seckcey/8_west_helpdesk/actions/runs/39909563516)).

All local fixtures were synthetic. The entry test uses stubbed authentication
around the exact login page and real local HTTP headers; it does not prove JWT
signature verification. Existing suite SSO tests cover that separate boundary.

## Production receipt

After 8 West ID's actual `d80250f02954eab54a04243fa7ae3903738b9879` release and
explicit shared-host slot handoff, the Safeharbor owner ran the established
`deploy/deploy.sh` against exact merged main. The command exited 0 and history
records **2026-10-23T06:31:06Z** (October 22, 11:31:06 PM Pacific):

`c79cf5949165bc135546806ccf12a8c33c33ab48` →
`a63dc4ee43dcf32cb44401e2a0f265959e14b4fa`.

The script changed 11 files, added 1 and removed 0, including normal asset version
stamping. It paused and restored the six relevant cron files and two timers and
restarted only Safeharbor's dedicated PHP runtime. It made no schema, configuration,
portal-flag, Apache or database-service change.

Fresh postflight found:

- `current.sha` exact `a63dc4e` and `deploy.sh --check` reporting 0 changed, added or
  removed files; runtime active and site check passing.
- The server-only configuration's full hash, inode, ownership and mode unchanged;
  all six cron hashes unchanged and both previously active timers restored.
- `portal.enabled=true`; the requester-attribution table and all three immutable
  guards still present.
- New FPM master 2059633 running the fixed executable SHA256
  `58df25cae6fcf7e48c0d7c568047d7061b1c0f9c7785ff5ff1995395e822c13e`.
- Origin and public login/CSS responses 200; unauthenticated root, Home, Westy and
  support-request entry responses 302 to sign-in.
- No unfinished deploy/pause markers or actual host/report lock holder. The shared
  deployment slot was released to the lead.

The normal file rollback is retained and readable at
`/srv/8west/apps/safeharbor/deploy-state/backups/20261023T063103Z-c79cf5949165-to-a63dc4ee43dc/files.tar`.
It returns to privacy-compatible `c79` source without reversing schema or deleting
history. The older pre-requester-privacy source is still unsuitable for an ordinary
rollback; retain the held-repair rules in the
[settings/access receipt](settings-access-release.md).

The owner's synthetic containers, temporary browser image and task scratch roots
were removed after preserving evidence. No live account, provider, ticket,
financial or device action was used as a test. Repository documentation closeout
does not require another production deployment when application bytes are unchanged.
