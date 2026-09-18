# Westy mail conversation release — 2026-09-18

The internal email path is live: approved advice from `westy@8westit.com`, a
real reply recorded on the original case, and one approved acknowledgment
accepted by Microsoft and separately confirmed received by Frankie on
September 18. The real internal round trip is complete. This release does not
claim general autonomous advice or external customer reply support.

## Deployed release

- [PR #131](https://github.com/Seckcey/8_west_helpdesk/pull/131), source `e67915b26051e7c54290f8eb8faadc17326a1714`
- [Exact-main Validate `35327015156`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/35327015156) passed on attempt 2. Attempt 1 failed unchanged service-goal test 44's worker-overlap observation; the rerun passed all checks without code changes.
- Production: `milepost-ec2:/srv/8west/apps/safeharbor/current`, verified host `ip-172-31-31-195`, database `safeharbor`
- Deployed artifact: `1d499fe5f9b046ceaf283ee7a84c108266ec9aaa7959b700a1b2b25a6e9974c5`
- Code/config/report-binding backup: `/srv/8west/backups/safeharbor/20260918T090434Z-pre-westy-internal-reply`; all archive checksums passed
- No database migration or Apache/vhost change for PR #131

The actual hosted internal reply has no Internet `compauth`. The reader now
requires one unambiguous Exchange internal/hosted assertion and the exact tenant
of the dedicated Graph app. It still rejects explicit authentication failures,
ambiguous headers and sender/destination/case mismatches. The initial connector
audit is retained; elapsed time no longer creates unnecessary daily setup
reapproval. Per-case response approval still expires and remains revocable.

The preceding [PR #129](https://github.com/Seckcey/8_west_helpdesk/pull/129)
fixed raw spaces in the actual Graph Inbox/window/Sent Items URL ordering:
source `f750815be0f18be4a5cc34faab3dbed56092e567`, exact-main Validate
`35324367063` passed, 28 Coastline checks passed. Its code-only backup is
`/srv/8west/backups/safeharbor/20260918T082953Z-pre-westy-reply-url`.

The original [PR #127](https://github.com/Seckcey/8_west_helpdesk/pull/127)
remains the schema/release foundation:

- Source `0ac42798d295d460b20c7019febf664db84111ea`; exact-main Validate `35322076683` passed
- Source artifact `6d2ae878b4ee30ba5fc794e21bdcc99ab2e52bb569877b595e4bd8de5919355c`
- Deployed artifact `706541117e6ae9c43d70bf99e1d6b6f1141e4720afa691dd01c85773ed4343a3`
- Protected backup `/srv/8west/backups/safeharbor/20260918T074717Z-pre-westy-mail-roundtrip` was scratch-restored
- Migrations 027/028 applied and replayed; all 49 existing tables retained their existing-column data hashes
- Resulting schema: 60 tables, 143 triggers and two new mail-health functions; new mail tables empty at that deployment checkpoint

## Real case 614 round trip

| Event | Evidence (UTC) |
| --- | --- |
| Original failed attempt | Draft #3 remains `uncertain`, HTTP 404, attempted `06:29:58`; separate append-only rejection reconciliation at `08:17:57` |
| Approved new advice | Conversation 1, Westy to `frank@8westit.com`, one send at `08:18:33`, Microsoft HTTP 202 |
| Actual recipient reply | Arrived in Westy's Inbox at `08:41:21`; Frankie independently said he replied |
| Verified case intake | Receipt 1 accepted at `09:05:55`; client message 336198 on case 614 |
| Approved acknowledgment | Attempt 1, receipt 1, submitted at `09:05:55`, Microsoft HTTP 202 |
| Recipient confirmation | Frankie answered "yes I got it" in the implementation task on September 18; confirmation also recorded as an internal note on case 614 |
| Scheduled reader | Successful cron execution at `09:07:01`: processed 0, held none, no uncertain send matches |
| Duplicate check | Post-cron state still exactly one receipt, one client message and one acknowledgment attempt; no attention event |

Protected actual reply fixture SHA-256:
`6ff77fffd3fc196c1cdf3e1233443808b77ce667297ecbcf531264df38b7a104`.
Provider-message SHA-256:
`f0007598f5906bf33a5e95ce0bceb2a56a3116345d372bc2abaf12725dd023f6`.
Imported body SHA-256:
`c002499e647216cd5c36c7c6208a37b8ce2406f6e13021d62ac008153addfc62`.
The protected fixture stays on production outside the app/Git. The stored
receipt and case message have the same body hash. The signed-in case page
shows the real test reply, and the email page shows the accepted receipt and
single acknowledgment attempt.

The actual reply proves the initial advice reached Frankie. HTTP 202 for the
acknowledgment proves Microsoft accepted it, not inbox receipt. Frankie then
separately confirmed "yes I got it" when asked whether that acknowledgment
arrived in his inbox. That human confirmation completes the real round trip;
it is also preserved in an internal note on case 614. No delivery timestamp
is inferred from the later confirmation, and the original provider result is unchanged.
No original message was resent. Case 614 remains open, assigned to Frankie,
contact 13, workflow `human_owned` version 3. The laptop was not changed, no
repair was claimed, and no purchase or financial action was taken.

## Runtime and mailbox scope

The shared unlicensed mailbox is `westy@8westit.com`. Entra app
`7c96b56b-23d6-4028-8fe9-67b52e955d50` is in verified 8 West tenant
`1ab02053-6433-44d9-b030-73e4dfb39577`. Exchange `Mail.Read` and `Mail.Send`
are scoped to Westy only; Westy is in scope and Frank/reports are not.
Certificate SHA-1 `E88A4327F9B88F7CE487E07DC3C73666A92C8238` was registered
by Frankie. A real application probe read Westy's Inbox (HTTP 200) and was denied
Frank's mailbox (HTTP 403). No new mailbox license was purchased.

Sending was enabled at `08:17:45`. The reply reader and approved one-ack path
were enabled at `09:05:49` with protected config hash
`c4dd3ca4ed4a35199be7a2e0f579d1c7ff65e2fdd1b8f4fef717ce962ee5c928`.
The reader starts its durable window at `08:17:45`, before the actual reply.
The original connector audit at `07:01:42` found no inbound connectors; it was
not rewritten as fresh evidence. Sender allowance remains internal-only.

`/etc/cron.d/safeharbor-westy-mail` runs the existing reader each minute as
`www-data`, logging bounded outcomes through `logger -t safeharbor-westy-mail`.
Owner/mode is `root:root:644`; SHA-256 is
`8f6825528e42f8327fa79f96c72be0d14358e5a9a55ab0df652db2f20b2fec6d`.
The template is [deploy/safeharbor-westy-mail.cron](../deploy/safeharbor-westy-mail.cron).
Database locking, durable cursor and receipt/attempt uniqueness remain the
worker's concurrency and crash controls.

Existing reports were restored active after both the code deploy and the
Westy-only config change. Report sender, recipient, tenant, client, schedule,
and retained recipient-confirmation evidence stayed unchanged. No general
mailer, separate app database, financial control, credential or laptop was
modified by this follow-up release.

## Verification and limits

Coastline completed 37 poller checks and 115 core/MySQL checks, including
concurrency, killed workers, duplicate protection and permission revocation.
The actual protected reply passed a read-only candidate/core-parser preview
before activation. Full CI passed for the exact deployed source.
Earlier transport (51), cursor (8), old-email (32), SSO (60), customer-sync (50)
and synthetic desktop/390px browser checks remain recorded; signed-in live
reply and case acceptance now also passed. Task containers and temporary test
tabs were cleaned up; user tabs remain open. Desktop Docker stayed stopped.

This pilot sends one frozen acknowledgment approved through
`2026-09-25 08:18:33`; that one-send budget is now consumed. Later verified
replies can still be recorded, while new advice/action belongs to Frankie.
External-customer reply authentication and open-ended AI correspondence remain
unfinished. The internal round trip, including recipient-confirmed acknowledgment
delivery, is complete. The memory issue remains open for human review; email
delivery is not evidence of a repair.
