# Customer portal release — October 2, 2026

## Current state: private Westy chat enabled

At 7:19:54 PM Pacific on October 2 (02:19:54 UTC on October 3), private
Westy chat and reviewed ticket handoff were enabled for every valid active
customer binding. No per-customer pilot allowlist remains. Existing signup
can admit future verified customers without another chat configuration edit.
This portal/chat rollout requires no endpoint-agent update.

- All-customer access: [PR #150](https://github.com/Seckcey/8_west_helpdesk/pull/150),
  deployed source `e210e7fe8060e9507cdfd73504b58d0f698ba0f2`.
- Exact-main [Validate 37087100917](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37087100917)
  passed; the expanded focused portal suite passed 65 checks.
- Source artifact SHA-256:
  `379135243a722e7ef6f012c3246e9b111d4cee7797b0d730e5bd8f81bf937056`.
- Full deployed artifact SHA-256, independently recomputed:
  `d4ca6f98aeb1b37fe5bbc0ffd0c17dac41e123d83addedaf43387a41453245cf`.

The source-only window ended at 02:08:14 UTC. A fresh protected backup and
scratch restore reproduced 68 tables, 160 triggers, six routines, no events
and all 2,380 saved rows before release. Recovery material is under
`/srv/8west/backups/safeharbor/20261003T015448Z-portal-all-customers-e210-release-01a0fea0`.
No further schema migration was needed for this access update.

The owner installed a dedicated protected credential. Eight bounded synthetic
requests to `gpt-6-luna` passed manual review, including ordinary guidance,
human handoff, unclear problems, outages/security, instruction attacks,
cross-customer/private-note refusal, secret non-echo and unsupported claims.
Requests used low effort, Standard service tier, `store:false` and no tools.
No customer data was used. This verifies provider access and these cases;
it does not replace authenticated customer acceptance or a broad quality study.

Activation preserves the key and unrelated configuration. Both Westy switches
are true, with 30 requests per hour per person, 500 per day per business and
a $5 monthly application cap per business. These are application limits, not
a claim about an external provider account hard cap. The final protected
configuration SHA-256 is
`d990048cbb1e4e505ca30fbde53d646baa344f703c9e5ca9a2ae4717d4235df9`.

The runtime received DELETE permission only on the four private-chat tables.
The maintenance cron runs every ten minutes as the application account;
runtime dry-run and apply checks passed. New chat and unsent draft content
expires after 30 days, with content-free metadata cleanup after 90 days.
Sent ticket receipts follow ticket retention. Manual protected rollback
backups have no scheduled expiry: they remain until their owner authorizes
disposal. Restore offline, then apply expiry and all recorded subject erasures
before reopening access; unresolved erasure history blocks reopening.

The first activation rolled back when a Python HTTP probe received an edge
403. Public curl and TLS-verified origin checks returned the correct results;
no security policy changed. A reviewed resume reconciled the retained grants
and enabled chat. Both public and origin login/portal return 200, while
unauthenticated chat returns 401 with `sign_in`. The existing report scheduler
is active with unchanged cron, recipient scope and delivery evidence. No
reports, invitations or announcements were sent for this activation.
Protected activation evidence is in the backup's `chat-activation/` directory.

Coverage remains distinct from activation: nine current ordinary Milepost
customers have matching Safeharbor directory records, but only Lifestyle,
Ventures and Mike Tricker currently have portal bindings. Lifestyle and Mike
have active customer-owner identities; Ventures has no customer user yet.
Six existing businesses still need receipt-bound portal identity provisioning.
No name matching, invented reporting contact or staff-session substitution is
accepted. Device enrollment, device repair and add-on purchasing are separate
implementation work. The live sign-in and reply check with the authorized test
customer is still pending; no completed real repair is claimed.

## Initial dashboard release (historical checkpoint)

The Phase 1 customer dashboard and migration 031 are deployed. The owner
approved the Safeharbor-only window, which ran from 6:02:11 to 6:03:28 PM
Pacific on October 2 (01:02:11–01:03:28 UTC on October 3): 77 seconds.
At this initial checkpoint, private Westy chat and AI were disabled and
signed-in customer acceptance was pending. The current activation above
supersedes those initial switch and credential states.

### Initial source and validation

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

### Initial release boundaries

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

### Acceptance at the initial checkpoint

The authorized customer account's immutable identity, active customer-owner
role and active provider/client binding were verified. It must still complete
ordinary sign-in for the live dashboard check. The coordinator's pre-release
browser session returned `local_policy_rejected` and did not establish that
account; it is not release acceptance or evidence of a new regression. No
account reset, alternate identity, forged session, test ticket or outbound
message was used for this deployment check.

Private chat and Luna activation were separate at this checkpoint: a dedicated protected
credential, actual model eligibility and synthetic live evaluation, maintenance
and retention/backup-erasure policy were required before enabling either
switch. The current activation above records their completion and the owner's
later all-customer scope. Synthetic tests do not constitute customer research
or participant acceptance.
