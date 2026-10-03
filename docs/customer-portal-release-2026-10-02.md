# Customer portal release — October 2, 2026

The Phase 1 customer dashboard and migration 031 are deployed. The owner
approved the Safeharbor-only window, which ran from 6:02:11 to 6:03:28 PM
Pacific on October 2 (01:02:11–01:03:28 UTC on October 3): 77 seconds.
Private Westy chat and AI remain disabled. Signed-in customer acceptance
of the upgraded dashboard is still pending.

## Reviewed source and validation

- Implementation: [PR #148](https://github.com/Seckcey/8_west_helpdesk/pull/148).
- Exact source: `6413e7a1ba48b748d8e7e31c23423bca721076bc`.
- Exact-main [Validate 37077971776](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37077971776)
  passed, including 59 portal database checks and 21 browser contracts.
- Canonical source artifact SHA-256:
  `699d97deca580111b766105cabca1ac8870fee9ae2353dd555fb39ad7d952ee6`.
- Migration 031 Git-blob SHA-256:
  `9bd8542057df1dc9215bc1c75642ecfd4deb56067547cb53c7a91a234514d612`.

The source gap from production `c1064992526b39e4c93c771b589d13e8ce11af59`
is the single reviewed portal PR. The matching application, brand, deployment
and test bytes were tested before merge; the final pre-merge changes were
factual documentation only. See the [feature contract](customer-portal-phase1.md).

The fresh protected recovery record is
`/srv/8west/backups/safeharbor/20261003T004649Z-portal031-release-01a0fea0`
(October 2 Pacific). Its scratch restore reproduced 64 tables, 156 triggers,
six routines and no events, with all 2,380 row bytes matching the backup.
Exact migration 031 first apply and replay then passed in that scratch
database: 68 tables, 160 triggers, four empty new tables and all original
data unchanged. The scratch database was removed. Customer data remained
on the production host inside the protected recovery procedure.

## Release boundaries

The verified target is Safeharbor on `milepost-ec2`, hostname
`ip-172-31-31-195`, at `/srv/8west/apps/safeharbor/current`. Migration 031
first apply and exact replay passed in production under the exclusive lock.
The database now has 68 tables and 160 triggers; all four new tables are empty.
Every original data row was byte-identical across the frozen migration window.

The deployed artifact SHA-256 is
`9b0b6b933fe52873908d752b4a46d61c3db4fc38b65f8f7c535a5222aa9c51ab`.
An independent complete-app hash matches the protected current release marker
at `/var/lib/safeharbor-report-scheduler/releases/current-app-artifact.manifest`.
Apache and MySQL are active, the runtime account is unlocked, and both the
login and customer portal return HTTP 200. The coordinator also checked the
rendered customer sign-in page.

Protected configuration, runtime grants, both Safeharbor and Milepost SSL
vhosts, and report cron bytes match the fresh backup. Both `portal_westy`
switches remain false, the dedicated key is absent, the pilot list is empty,
and no private-chat maintenance cron was installed. The report scheduler was
restored and independently verified active using the new root-only bundle at
`/srv/8west/apps/safeharbor/release-controls/20261003-portal031-01a0fea0/candidate`.
The original authorized delivery receipt and schedule scope were preserved;
no replacement canary report was sent and uncertain deliveries were not retried.

The deployment follows the [runbook](../deploy/README.md): fresh protected
backup and scratch restore, Safeharbor-only endpoint/runtime write pause,
exclusive migration lock through apply/replay/postflight, explicit lock
handoff and revalidation, then the unchanged installer with its own exclusive
lock. A failed or interrupted phase requires exact-state recovery before
opening the write boundary. Application rollback retains the additive schema;
an old database must not overwrite newer support work.

## Remaining acceptance

The authorized customer account's immutable identity, active customer-owner
role and active provider/client binding were verified. It must still complete
ordinary sign-in for the live dashboard check. The coordinator's pre-release
browser session returned `local_policy_rejected` and did not establish that
account; it is not release acceptance or evidence of a new regression. No
account reset, alternate identity, forged session, test ticket or outbound
message was used for this deployment check.

Private chat and Luna activation remain separate: a dedicated protected
credential, actual model eligibility and synthetic live evaluation, maintenance
and retention/backup-erasure policy, and the exact pilot mapping must be
accepted before enabling either switch. Synthetic tests do not constitute
customer research or participant acceptance.
