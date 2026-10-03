# Independent MSP workflow admission: October 3, 2026 release

Safeharbor source `54357621559b4880a5e2571e6c6e900f867aa445`
([PR 159](https://github.com/Seckcey/8_west_helpdesk/pull/159)) was deployed on
`milepost-ec2` (`ip-172-31-31-195`) with managed-provider Westy workflow admission
enabled. The existing report scope, customer bindings, mobile/diagnostic settings
and business history were preserved. This records the **15:09:44 UTC** verified
checkpoint, not any later release or a completed real-customer journey.

## Source and bounded window

Exact-source Validate
[37125704133](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37125704133)
and mobile checks
[37125704131](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37125704131)
passed. The installed source package SHA-256 is
`69ab644db4fbf1136a9c78c5457310e10d044a158e7bab1728be58448d3467f4`.

| Artifact | SHA-256 |
|---|---|
| Canonical packaged source | `a9810de89f906592fda811e4ef1495417c2d97887665a81221c44a537e3a506b` |
| Complete deployed application | `11bfc7f746db5b856d8abe018d2cee840df324c292fc9b3beefefda861d8f096` |
| Exact artifact hasher | `517d6d037361ecdf919b0739c693a5fa63a221aee5642127a38303d35f0e9573` |
| Enabled protected configuration | `10d3503c4af0cad7650b95e74e113f36d35846716869d4efc13b94831d299dca` |

The source/config/report window completed in seven seconds at approximately
14:51 UTC, within the reviewed ten-minute bound. Reporting was stopped through
its manager; affected workers drained naturally. Only Safeharbor HTTP was denied,
and only its database account was temporarily locked after zero active connections
were verified. The exact installer then applied the source. No migration, forced
process termination, database restore or shared Apache restart was used.

After source verification, `westy_workflow.managed_providers_enabled = true` was
the only Safeharbor configuration change. Full loaded-array comparison proved
all other values unchanged. The current and immutable release manifests match
the complete deployed application hash. The database account was unlocked, grants
checked byte-for-byte, original vhost restored, and Apache reloaded normally.
Public and TLS-origin login checks returned HTTP 200.

## Reports and existing customers

The scheduler bundle lives at
`/srv/8west/apps/safeharbor/release-controls/20261003-independent-msp-01a10193/enabled`.
Its exact manager verified reporting active. Rebinding changed only the source,
bundle, deployed-artifact, release-marker and protected-config bindings plus the
review time. Original tenant, client, recipient, sender, schedule, archive and
delivery-confirmation evidence were preserved. No canary report was sent.

The permanent report lock remained root:www-data mode 0640, inode 262917.
The original report cron inode was retained in a quarantine file while the manager
installed the current schedule. All other 70 original cron files retained their
bytes, owners, modes and inodes. The ten temporarily held worker schedules and
two existing timers were restored. No held cron files or task-held permanent
locks remained at handoff.

All nine portal bindings remained active. The six previously activated customers
retained Safeharbor bindings 4–9 and ID tenants 11–16 with unchanged owner modes.
Workflow states remained one human-owned, three resolved and eleven needing a
human. Nine submitted/two uncertain report deliveries and three accepted billing
records were preserved. There was no pending mail/chat, new customer device
operation or recent endpoint dispatch. No customer, recipient, provider, owner,
device, purchase or financial record was created for release validation.

## Recovery and acceptance boundary

Root-only recovery evidence remains on production at
`/srv/8west/backups/independent-msp-20261003-01a10193`.
Safeharbor source archive SHA-256:
`ac77d935aabc49bf3dc302c0ca94a93b4302d4ce8d0379f154a62353184bd466`.
Database dump SHA-256:
`159e9ae819dd0ff61948dae9f666dcdbecb044b7d325572f183a70047503da05`.
The real scratch restore matched 68 tables, 160 triggers, six routines and 5,853
rows, then removed its scratch database. Full source bytes/modes/owners/links and
protected configuration were independently checked. The old configuration and
reviewed disabled candidate remain available for bounded activation recovery;
do not restore old customer data or remove permanent locks.

The final cross-app `final-runtime-proof.json` SHA-256 is
`34aeab6fa1ba0180bee7f4a21844f35261bff1a08617e616a7f49ff6520b85b5`.
Companion source was Milepost `801adb7827fa665270af465b8dacce04e35f8290`
and ID `0665fa4f762c18462eb5a62a46f7ba266fba7382`. The coordinator independently
verified runtime core and received the released live/default-branch claims.

The Coastline harness passed current-provider, owner, lease, customer/device
isolation and revoked-service refusal checks. All task test containers were
stopped. A coordinator browser attempt used an existing staff Owner identity and
was refused by customer policy; that is not a customer-path pass. Actual customer
sign-in using the designated client account, a real independent-MSP onboarding
and invitation journey, and physical-device recovery remain separate acceptance
checks. Do not widen customer policy to admit the staff session.

See the [workflow admission contract](independent-msp-customer-portals.md) for
current authority checks and rollback boundaries. This receipt does not activate
the separately owned add-on ordering release.
