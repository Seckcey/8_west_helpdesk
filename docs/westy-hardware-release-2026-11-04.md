# Hardware outcome receiver release — November 4, 2026

The schema 3 hardware outcome receiver and migration 037 are installed as
`3f3b128ab508872fb97da6596b86d3e8a827c4cd`. The unchanged stock publisher ran once
from 13:25:16 to 13:25:24 UTC and exited successfully. Milepost producer publication
and the actual conditional ticket/notice outcome remain pending.

## Source and validation

[PR #280](https://github.com/Seckcey/8_west_helpdesk/pull/280) was normally squash
merged from reviewed head `1357871d78d10a78956f997bb878abbdd8eb1138`. Its merged tree
is identical to the reviewed tree. Required PHP and browser checks passed on both
the [PR head](https://github.com/Seckcey/8_west_helpdesk/actions/runs/41834971738)
and [merged main](https://github.com/Seckcey/8_west_helpdesk/actions/runs/41836713267).
No merge bypass was used.

Focused coverage passed: 58 hardware MySQL checks, 34 parser/contact checks,
32 fake Graph/loopback SMTP checks, 87 ticket-retention checks, 166 legacy SQLite
workflow checks, 242 legacy MySQL workflow checks and support-mail regressions.
Actual Milepost payload/authentication/resolver/signing code interoperated with
Safeharbor's parser, receiver, status and contact consumer against synthetic data.
The fixtures do not establish production email delivery. Test containers, volumes
and shared test locks were released.

The complete running-source gap was reviewed. Previous runtime `7be64ab4` was
followed by documentation-only `18fb78e` (PR #279), which changed no app, brand,
publisher or CI input, then PR #280. The deployment changed ten files, added six,
and removed no application files. All 418 published files match Git after the
stock brand copies and three cache-version substitutions.

## Schema, recovery and cleanup

`app/db/migrations/037_westy_hardware_outcomes.sql` has SHA256
`981defe33b8c3e810f046e5ee1cf7b2d7afec8ae75b29c736bb844705efc928c`.
Four additive tables and eight immutable-history triggers are present. The ordinary
runtime identity retained its existing SELECT/INSERT/UPDATE permissions; no grant
or configuration change was required. Root read-only metadata recorded all eight
trigger names, target tables, timings and statement hashes because the ordinary
runtime account cannot list them.

The protected stock backup is:

```text
/srv/8west/apps/safeharbor/deploy-state/backups/20261104T132518Z-7be64ab4fb69-to-3f3b128ab508
```

Its source archive contains exactly the ten replaced files, 611,724 original bytes
plus metadata, verified against the immediate preflight. Archive SHA256:
`f767c644fa7dc422394c42f3c75e6ef59294f22aa8424621bd6c1d50a94fe753`.
The 235,042-byte database dump includes triggers, routines and events. Its SHA256 is
`02fcd6b386cdb56f1f3f5f746b9e3aa91a9eeb644956feda984473d243adb185`;
gzip readback passed. This release did not perform a scratch database restore.

Normal retention removed only the inspected October 19 source backup
`20261019T091900Z-9d092fa4b466-to-53c9fbb4d136`. Its archive was 71,680 bytes.
The other nine prior backup directories and their metadata remained unchanged;
the new backup supplies the immediate source inverse to `7be64ab4`.

Source rollback retains all four new tables, receipts, findings, notice attempts
and held `mail_queue` rows. Old dispatchers skip their `max_attempts=0` rows.
Never restore the database over later writes, drop accepted history or replay an
uncertain email submission as a source rollback.

Configuration bytes and metadata were unchanged. All six cron files were restored
with their original inode, bytes and metadata, and both previously active timers
were restored. The runtime, origin login and stylesheet passed. No interrupted,
cold-window, paused-job or upload artifacts remained, and the original host mutex
was released before handing the host window back to Milepost.

## Acceptance boundary

The original owner-directed case remained open, assigned to its technician and
`human_owned` at version 7. Its six workflow receipts, two continuation grants
and seven messages were unchanged. An authenticated browser refresh confirmed
the same ticket and owner decision; no Return action was performed.

All new hardware tables were empty after installation. The actual producer must
refresh authoritative workflow status before recording the hardware outcome.
The current device has no verified linked contact: acceptance therefore requires
the same-ticket upgrade options and one `no_device_contact` result, with no guessed
recipient or email-provider call. That production outcome is still pending.

The detailed operator receipt is `hardware-037-production-complete.json`, SHA256
`c3034bf32e5ebeecd0a4cf54d7a28a51a5bcda1f35af7d3bbf5b1e873e2e428e`.
