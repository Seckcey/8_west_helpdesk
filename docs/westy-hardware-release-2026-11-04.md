# Hardware outcome receiver release — November 4, 2026

The schema 3 hardware outcome receiver and migration 037 are installed as
`3f3b128ab508872fb97da6596b86d3e8a827c4cd`. The unchanged stock publisher ran once
from 13:25:16 to 13:25:24 UTC and exited successfully. Milepost's producer is now
published. The original hardware case received upgrade options at 18:53:15 UTC
and a verified missing-contact notice at 19:14:01 UTC through the normal worker.
The ticket remains unresolved; no email was queued or submitted.

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

## Original case acceptance

Immediately after receiver installation, the original owner-directed case remained
open, assigned to its technician and
`human_owned` at version 7. Its six workflow receipts, two continuation grants
and seven messages were unchanged. An authenticated browser refresh confirmed
the same ticket and owner decision; no Return action was performed.

All new hardware tables were empty after installation. Milepost subsequently
published [PR #777](https://github.com/Seckcey/8westit_webapp/pull/777) and the
saved-result parser correction in [PR #778](https://github.com/Seckcey/8westit_webapp/pull/778),
with runtime `1adccfbf1bd2328fa4e02b2c0e6a8c4052eea79b`. The actual producer
refreshed authoritative workflow status and recorded the outcome from the existing
completed evidence at 18:53:15 UTC. No new device command or cleanup was needed.

The same ticket now contains the measured storage constraint and useful upgrade
options: have a technician assess a larger compatible system drive, preserve
required data through backup and migration, and compare computer replacement if
serviceability or cost warrants. The options do not claim a confirmed drive fit,
price, purchase, installation or amount of reclaimable space. The owner's Stop
and no-further-cleanup decision remain in the original history.

At 19:14:01 UTC, the normal worker verified that the exact device had no designated
email contact and recorded `no_device_contact` with one technician-facing note.
Read-only snapshots after the 19:14 and 19:15 cron ticks verified:

- Exactly one finding, immutable event receipt and notice; one upgrade-options
  note and one missing-contact note. Verification attempts remain one.
- No linked mail queue row, send attempt or hardware provider-attempt row.
- The ticket remains open, assigned to its technician and `human_owned` version 7.
  The earlier seven messages, six workflow receipts and two grants have their
  original digests; the ticket now has nine messages.
- The two old sent-mail rows retain their exact metadata digest, and no unrelated
  unsent mail or due waiting-ticket housekeeping was present.

This is actual missing-contact acceptance. Positive-contact customer delivery and
native device repair remain separate, unproved paths. Observed row counts and the
second scheduled tick establish no duplicate activity here; no deliberate live
event replay was performed. The separate internal-device pilot is still pending
its actual result and accurate ticket outcome.

## Outbound worker identity correction

The original notice stayed pending because `/etc/cron.d/safeharbor` ran the
dispatcher as `www-data`, which could not read the application's private `0640`
files. The existing `eightwest-safeharbor` runtime account could read them, but
could not append to the legacy `www-data` mail log. At 19:13:25 UTC, one outbound
cron line was atomically replaced under the original shared-host mutex:

```cron
* * * * * eightwest-safeharbor /usr/bin/php /srv/8west/apps/safeharbor/current/cron/mail_dispatch.php 2>&1 | /usr/bin/logger -t safeharbor-mail-dispatch
```

The cron file remains `root:root 0644`; its new SHA256 is
`f0ccdd866b0f697bf5efb3190906ce0bed596c0e3ebecf39e8f111786250e553`.
The immediate preflight established zero unrelated unsent mail, including attempted work,
zero due housekeeping and only this hardware notice waiting. System journal
records confirm normal minute ticks at 19:14:01 and 19:15:01 under the private
runtime account. No manual dispatcher or contact-resolver invocation was used.

Application configuration, dispatcher source and permissions, other cron files
and all other lines in this cron file were preserved. Both cron and Safeharbor's
PHP service remained active; neither was restarted. The original host mutex was
released and verified uncontested. Other legacy `www-data` cron consumers were
left unchanged and require their own backlog and identity review.

The already-reviewed packet contains the exact 431-byte original cron text and
metadata, SHA256 `0c5e962a3fc2fd7074c0d9981af0288a3dcc668b1f6d1bdd0149c6d441d13acc`.
Those existing bytes were qualified against the immediate target as the rollback
inverse. No new pre-update backup, retention operation, application/configuration
copy or database backup was made for this correction. A diagnosed cron rollback
must preserve its recorded ownership/mode and all accepted database history;
restoring the old account would also restore its private-file access failure.

Operator receipt: `mail-cron-identity-operation-receipt.json`, SHA256
`7b06e81764257a18c028adfe8b328e02ffe2656d74c32cde814517c178e006c0`.
Two-tick acceptance receipt: `mail-cron-postflight-20261104T191511Z.json`, SHA256
`43a2aaea48328633e0e674e4bf75e10f43fdba5e0949e46373f4102403ab1bea`.
The original reviewed inverse packet is `mail-dispatch-identity-correction-packet.json`,
SHA256 `8885e1c82e807611c9f632a34d21fda6396044ab9f2ee4ba176c1c9002ba98cb`;
its proposed new backup was superseded by the recorded existing-inverse amendment.
These receipts are retained in the release owner's November 3–4 local evidence
directory. This documentation update needs no application republish.

The detailed operator receipt is `hardware-037-production-complete.json`, SHA256
`c3034bf32e5ebeecd0a4cf54d7a28a51a5bcda1f35af7d3bbf5b1e873e2e428e`.
