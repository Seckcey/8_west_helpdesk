# Westy mail conversation release — 2026-09-18

This receipt records the dedicated Westy mailbox conversation release. It does
not claim that live email delivery is complete.

## Deployed release

- [PR #127](https://github.com/Seckcey/8_west_helpdesk/pull/127), source `0ac42798d295d460b20c7019febf664db84111ea`
- [Exact-main Validate `35322076683`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/35322076683) passed
- Production: `milepost-ec2:/srv/8west/apps/safeharbor/current`
- Source artifact: `6d2ae878b4ee30ba5fc794e21bdcc99ab2e52bb569877b595e4bd8de5919355c`
- Deployed artifact: `706541117e6ae9c43d70bf99e1d6b6f1141e4720afa691dd01c85773ed4343a3`

Migrations 027 and 028 were applied and replayed. The protected backup
`/srv/8west/backups/safeharbor/20260918T074717Z-pre-westy-mail-roundtrip`
was scratch-restored. All 49 existing tables had unchanged existing-column
data hashes. The resulting database has 60 tables, 143 triggers and two new
mail-health functions; the new mail tables are empty.

## Runtime state

The dedicated Westy configuration is installed. Sending and poll/auto-ack are
off, and no new worker is scheduled. The existing report scheduler is active
again at `08:04:39Z` with its prior scope and configuration. Unrelated apps,
finance and the laptop were untouched.

Case 614 remains open, human-owned and tied to contact 13. Old Draft #3 is
unchanged after its uncertain provider HTTP 404 at `06:29:58Z`; it was not
retried and is not delivery evidence. The signed-in live email page shows
`From: westy@8westit.com` and `To: frank@8westit.com`, with new actions
disabled and the old uncertain history visible.

## Mail identity and remaining work

The shared unlicensed mailbox is `westy@8westit.com`. Entra app
`7c96b56b-23d6-4028-8fe9-67b52e955d50` is in verified 8 West tenant
`1ab02053-6433-44d9-b030-73e4dfb39577`. Exchange `Mail.Read` and `Mail.Send`
are scoped to Westy only; Westy is verified true and Frank/reports are false.

The certificate public file is ready but not registered. No Graph positive or
negative result, mailbox connection, or real send/reply/approved-acknowledgment
roundtrip has been proved. Remaining work is limited to:

1. Register the public certificate through the required browser handoff.
2. Connect the mailbox.
3. Run one actual email → reply → approved acknowledgment test and read the
   actual inbound headers during that test.

The completed verification set includes Coastline 115 core/MySQL checks
(including concurrency, SIGKILL, revocation and duplicate protection), 51
transport checks, 25 poller checks, 8 cursor checks, 32 old-email checks, 60
SSO checks, 50 customer-sync checks, full CI, and synthetic desktop and 390px
phone UI acceptance. Task test containers and agent-created temporary test tabs
were cleaned up; user tabs remain open.
