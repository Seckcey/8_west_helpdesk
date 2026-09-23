# Central private history release — September 23, 2026 UTC

Safeharbor's dedicated Central history lane is live for the one owner/customer
Frankie explicitly selected: `frank@8westventures.com`, 8 West Ventures, LLC.
Central supplies the signed-in interface. Safeharbor stores the private records
without adding staff tickets, mail, technician work, controller jobs or billing.
No AI inference, assistant writing, diagnostics or device actions were activated.

## Accepted source and environment

[PR 136](https://github.com/Seckcey/8_west_helpdesk/pull/136) is deployed as
`5342996c2e12b6ba225c30032f7ffa5212f5e47f`. PR Validate `35840312687` and
[exact-main Validate 35840799684](https://github.com/Seckcey/8_west_helpdesk/actions/runs/35840799684)
passed. Deployment used a clean detached checkout, canonical LF archive bytes
and the established installer under its exclusive report/deployment lock.

| Evidence | Value |
|---|---|
| Host | `milepost-ec2`, `ip-172-31-31-195`, AWS account `406260045660`, instance `i-0aca036629e5f6ea5`, `us-west-1` |
| Application | `/srv/8west/apps/safeharbor/current` |
| Source artifact SHA-256 | `7a1ca6f154c1d5a8414058bfa6bd191a2b9ae5ba741f0ddeb16753dec59b6b8d` |
| Complete deployed artifact SHA-256 | `3cc63554bb3319b474b154de9f07dcd94e8aadddb5b93289c9c9938fe66aa2aa` |
| Migration 029 exact blob SHA-256 | `ac2a249018a6a9a5dda85c498ac2380f75b3c7e4c29249267a39cc610898b63d` |
| Previous accepted application | `38d93740675a9838c9e353f32d70bb674e04c075` |

The live artifact manifest is
`/var/lib/safeharbor-report-scheduler/releases/current-app-artifact.manifest`.
Documentation-only merges do not change the deployed application identity.

## Protected migration and admission

Root-only recovery material is retained at
`/srv/8west/backups/safeharbor/20260923T090353Z-central-history`: application,
configuration, trigger/routine/event-inclusive database, runtime grants, both
application vhosts, scheduler controls, original cron/activation and checksums.
Checksums passed. A disposable scratch restore reproduced all 60 pre-migration
base tables, 143 triggers, six time entries and eight time-entry events; the
scratch database was then removed. No production data was restored backward.

Only Safeharbor's report schedule and endpoints were paused. Its database
runtime account was locked after existing connections drained. Shared Apache
was reloaded, never stopped or restarted; the Milepost vhost stayed byte-identical.
The archived migration and its replay both passed. The three new tables were
empty and schema health returned 1 before admission. All previous runtime grants
were retained, with only `EXECUTE` on `central_issue_schema_health` added after
the final replay. No schema/trigger or additional DELETE authority was granted.

Admission matched the existing Central/Milepost account and immutable ID subject
to Safeharbor's canonical customer registry: account
`20fae73d-4345-4dc2-9293-9b679c4d2024`, subject `t1u1`, ID tenant `1`, provider
tenant `1`, customer `d2cd5603-512e-46ab-b4c4-469563695b2e`, binding version `1`,
registry row `4`, local client `16` under tenant `1`. The new account was prepared
disabled, then enabled only after exact comparison. Names and email did not
establish ownership. Dedicated history transport and digest keys were generated
and installed only in protected server configuration. The separate assistant
key remains absent and its gate disabled. All unrelated configuration values
were compared and preserved.

The exact previous vhost was restored, the database account unlocked, and the
application reopened at `2026-09-23T09:12:39Z`. The report manager, wrapper and
hasher remained byte-identical. A new root control bundle bound the new source,
artifact and configuration while preserving the original real Graph/inbox
evidence, customer, recipient and schedule. Full preflight and active verification
passed; installed cron bytes match the original. No report or acceptance email
was sent.

## Acceptance and limits

Isolated Coastline verification passed 76 history checks, 50 existing customer
registry checks and 11 real Central-to-Safeharbor MySQL client checks. Coverage
includes fresh schema and migration replay, two-customer/tenant isolation,
concurrent changes and caps, exact/conflicting replay, Unicode limits, missing
guards/privileges, state provenance, erasure and unchanged existing workflow
tables. The integrated client also passed lost-response-after-commit recovery
and local binding revocation during transport. Full repository CI passed.

Live HTTPS signed requests proved an initially empty admitted account, 403 for
wrong owner/ID tenant/binding version, and 404 for an unknown issue. Ordinary
owner OIDC/MFA access in Central passed creation, notes, unresolved outcomes,
customer-reported resolution, reopening, cancellation, fresh-sign-in persistence,
plain-text/JSON downloads and stale-version draft preservation followed by an
explicit reviewed save. The retained synthetic rollout example is issue
`049e9546-5c1b-4015-891d-a79faab0f218`, version 7, cancelled; it represents no
outstanding computer problem or verified repair.

A separate newly created disposable API canary
`92df8f94-71b8-4594-9a57-e45709dc8bd5` verified exact create/erase replay,
assistant denial, erased read 410, cleared title/event text and no resurrection
after replaying its original create. Existing ticket/message/time/mail/workflow/
Coastmark counts were unchanged. Protected evidence is in `live-history-read.json`
and `live-history-erasure.json` in the recovery directory. The retained browser
example was not erased. Central's [release record](https://github.com/Seckcey/8_west_it_central/blob/main/docs/11-history-release.md)
records its final image and responsive browser acceptance.

This proves private customer history only. Model/provider commercial approval,
spending limits, paid access, enrollment/protection and automation are separate
Central packages. Existing suite AI authorization is not automatically Central
commercial authorization.

## Recovery

Disable the new Central history gate and account binding before restoring a
previous application artifact. Preserve the additive tables, guards, immutable
bindings and operation receipts; never rewind accepted customer history to the
pre-migration database. Restore protected configuration and scheduler controls
under the established exclusive procedure, rebind the exact artifact/configuration
and verify the original scope before enabling the scheduler. The complete
previous artifacts and activation evidence remain in the root-only recovery
directory. See the [service contract](central-issues-contract.md) and
[deployment runbook](../deploy/README.md).
