# Hardware staff notification release — November 5, 2026

The automatic missing-contact staff fallback delivered the stored hardware
options for case 3096 to Frankie. The normal scheduled attempt at **09:40:02 UTC
(1:40:02 AM PST)** received Graph HTTP 202. Connected Outlook search matched the
subject, configured Safeharbor sender, recorded recipient and November 5 date;
the message was received at **09:40:04 UTC** and was unread when checked. The
search returned metadata and a matching preview; no full body was fetched or
message marked read. Mailbox receipt does not establish human review, hardware
purchase, installation, device repair or customer acceptance of those actions.

## Source and checks

[PR #284](https://github.com/Seckcey/8_west_helpdesk/pull/284) installed the separate
staff notice and immutable attempts, migration 038, and the optional signed
`hardware_staff_status` read contract. Reviewed `b33a595` merged as
`072c9b19551f9405ae0b3c67513a57e575008a1c` with an identical tree. Required PHP
and browser checks passed on the [PR head](https://github.com/Seckcey/8_west_helpdesk/actions/runs/41996855928)
and [merged main](https://github.com/Seckcey/8_west_helpdesk/actions/runs/41997918022).
The stock publisher ran once at 09:07:13–09:07:21 UTC. All 421 runtime files and
the four new history triggers were verified. Migration 038 is additive, with
no backfill or runtime grant change; its SHA-256 is
`aba55b6c33d2b98cbc82978bb08524032923de96592496f672feebac576b190c`.

The normal dispatcher created staff notice 1 and held queue 34. Its first four
attempts received definitive Graph 404 rejections through the default mailbox.
Read-only mailbox probes found `MailboxNotEnabledForRESTAPI` for that mailbox
and HTTP 200 for the existing enabled Safeharbor support mailbox. The default
sender also serves legacy intake, so the correction was limited to staff notices.

[PR #285](https://github.com/Seckcey/8_west_helpdesk/pull/285) selects the existing
support Graph configuration and preserves structured submission evidence.
Reviewed `ca2736f` merged as **`789eaca0bf922c5aa26789a8b4bb47b053afbff7`**,
identical tree `880d051cd60e864cc54660cb1a278d78c317d124`. A fake provider first
reproduced the default-mailbox failure on the baseline. The corrected source
passed four sender/evidence cases, 59 staff checks, 58 hardware checks, 32 mail
evidence checks and the support-mail regression on Coastline. Temporary test
containers, volumes and networks were removed and the original test lock released.

The [PR validation](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42001880440)
passed after one browser-only rerun. The initial failure was an aborted navigation
in the unchanged Companion double-click sign-out fixture; the same test passed
on main and on the rerun. The [merged-main validation](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42003555762)
passed both jobs and all 20 steps before publication. No merge or release bypass
was used. The frozen signed status contract, schema and other mail paths were
unchanged by the sender correction.

## Publication and recovery

The unchanged stock publisher ran once at **09:39:49–09:39:55 UTC**, after exact
main checks and an agreed Safeharbor-only host window. It changed four files
(three cache-version substitutions and the staff library), added one test and
removed no files. No migration, configuration, grant or cron edit was applied,
and no new database dump was made. All **422** runtime files matched the prepared
Git release. Origin login and stylesheet checks returned HTTP 200; the private
Safeharbor service was active. No shared Apache restart occurred.

The protected source inverse is:

```text
/srv/8west/apps/safeharbor/deploy-state/backups/20261105T093953Z-072c9b19551f-to-789eaca0bf92
```

Readback verified exactly four original files totaling 62,636 bytes. The source
archive SHA-256 is `29a53c7fa6467fc7db18f6f296cff7c4ada7933d691c887a267f52150a468bd5`.
Normal retention removed only the reviewed October 22 recovery directory ending
`0548f3ea5118-to-4021f4507f47`, including its 942,080-byte source archive and
302,699-byte old database archive. The other nine directory identities were
unchanged; ten backups remain after adding this release.

Both recent protected database copies remain and passed hash and gzip readback:

| Backup | Database SHA-256 |
| --- | --- |
| `20261104T132518Z-7be64ab4fb69-to-3f3b128ab508` | `02fcd6b386cdb56f1f3f5f746b9e3aa91a9eeb644956feda984473d243adb185` |
| `20261105T090717Z-3f3b128ab508-to-072c9b19551f` | `69dadda1e603e04ec940be9aa6701b3a8a04e6c09515720d6187e9ce7c0d37ce` |

The second copy was captured by the earlier 038 publication with its required
stock database recovery. That release retained seven replaced source files
(556,041 bytes) and removed only its reviewed October 21 source backup. Neither
release claimed a scratch database restore. Required stock recovery and retention
were reconciled with the existing release-completion authority; no extra backup
campaign was run.

Source rollback retains migration 038, queue 34 and all completed attempts.
Do not restore an old database over later writes, lower the held queue's
`max_attempts=0`, reset counters or replay this accepted submission. Protected
configuration, all six cron files, timers and original host mutex identity
`30:26` were preserved. No paused-job or in-progress marker remained. The
Safeharbor host window was released after verification for Milepost's next release.

## Actual notification and preserved history

At 09:40:02 UTC the normal cron used the existing queue and frozen message;
attempt 5 was accepted. Staff receipt 1 is `provider_accepted`, queue 34 has
its normal `sent_at`, and the four earlier failed attempts are unchanged.
No manual dispatcher call, queue insertion, reset, contact change or separate
operator send was needed. Queue body SHA-256 remains
`82163390c1214ac624fd8ae8498a23495a88f37b07bd71032eabceaa9bb88033`.

Original hardware notice 1 remains `no_device_contact`, with one verification,
zero device-contact sends, recommendation message 339038 and missing-contact
message 339039. Case 3096 remains open, assigned to Frankie and human owned at
workflow version 7. All nine messages, six workflow receipts and two grants
retain their pre-release digests. The signed read status reports the separate
staff result without exposing the email address or message body. Milepost owns
its status cache and learning integration; this record does not claim those
separate components were deployed or that a device was repaired.

Operator evidence retains the one-invocation logs, exact pre/postflight manifests,
source and database readbacks, queue/attempt snapshots and connected Outlook
receipt. Mailbox identifiers and protected configuration remain outside Git.
