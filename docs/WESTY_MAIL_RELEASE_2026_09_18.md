# Westy mail conversation release — 2026-09-18

This receipt records the dedicated Westy mailbox conversation release and its
follow-up reply URL fix. It does not claim that live reply intake is complete.

## Deployed release

The current deployed code is [PR #129](https://github.com/Seckcey/8_west_helpdesk/pull/129), source
`f750815be0f18be4a5cc34faab3dbed56092e567`, with exact-main CI
[Validate `35324367063`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/35324367063) passed. The deployed artifact is
`fa0008a19f31bc820b26eb7b9d13f24b2aa5be8bbb6f6255535b6b6f2635e94e`.
This code-only fix changed no database or vhost state. Its 28 Coastline poll
checks passed against the real URL boundary, including raw-space ordering for
Inbox, window and Sent Items; normal full CI was green. The code-only backup is
`/srv/8west/backups/safeharbor/20260918T082953Z-pre-westy-reply-url`.

The earlier PR #127 deployment and migration/backup evidence remain the
release foundation:

- [PR #127](https://github.com/Seckcey/8_west_helpdesk/pull/127), source `0ac42798d295d460b20c7019febf664db84111ea`
- [Exact-main Validate `35322076683`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/35322076683) passed
- Production: `milepost-ec2:/srv/8west/apps/safeharbor/current`
- Source artifact: `6d2ae878b4ee30ba5fc794e21bdcc99ab2e52bb569877b595e4bd8de5919355c`
- Deployed artifact: `706541117e6ae9c43d70bf99e1d6b6f1141e4720afa691dd01c85773ed4343a3`

Migrations 027 and 028 were applied and replayed. The protected backup
`/srv/8west/backups/safeharbor/20260918T074717Z-pre-westy-mail-roundtrip`
was scratch-restored. All 49 existing tables had unchanged existing-column
data hashes. The resulting database has 60 tables, 143 triggers and two new
mail-health functions; the new mail tables were empty at that deployment checkpoint.

## Runtime state

The certificate was registered by Frankie with SHA-1
`E88A4327F9B88F7CE487E07DC3C73666A92C8238`. The application read Westy's
inbox (`200`) and was denied access to Frank's mailbox (`403`). Sending was enabled at
`08:17:45Z` with config hash
`c837d8e1287064b4bcbf5c06aa7385209ec0283b1af3447b0775196222a4d8af`.
Poll/auto-ack remain off and no new worker is scheduled. Reports were restored
active with the exact prior scope and protected config. Unrelated apps, finance
and the laptop were untouched.

Case 614 remains open, human-owned and tied to contact 13. Old Draft #3's
uncertain row is preserved. A separate HTTP 404 reconciliation was appended at
`08:17:57Z`. A new Case 614 conversation from Westy to `frank@8westit.com`
was sent once at `08:18:33Z` and accepted by Microsoft with `202`; inbox
delivery is not proved. The existing one-ack template is approved through
`2026-09-25 08:18:33Z`.

## Mail identity and remaining work

The shared unlicensed mailbox is `westy@8westit.com`. Entra app
`7c96b56b-23d6-4028-8fe9-67b52e955d50` is in verified 8 West tenant
`1ab02053-6433-44d9-b030-73e4dfb39577`. Exchange `Mail.Read` and `Mail.Send`
are scoped to Westy only; Westy is verified true and Frank/reports are false.

The signed-in live email page shows `From: westy@8westit.com` and
`To: frank@8westit.com`. No reply was present in the Westy Inbox as of
`08:31Z`. The deployed window URL GET works and returns zero case replies,
matching the live mailbox state. Remaining work is limited to:

1. Receive an actual reply.
2. Configure the existing reader from the actual inbound headers.
3. Verify acknowledgment and case intake.

The completed verification set includes Coastline 115 core/MySQL checks
(including concurrency, SIGKILL, revocation and duplicate protection), 51
transport checks, 25 poller checks, 8 cursor checks, 32 old-email checks, 60
SSO checks, 50 customer-sync checks, full CI, and synthetic desktop and 390px
phone UI acceptance. Task test containers and agent-created temporary test tabs
were cleaned up; user tabs remain open.
