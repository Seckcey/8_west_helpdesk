# Customer computer checks release — October 3, 2026 UTC

Safeharbor's customer computer checks are deployed, and its diagnostics gate and
the matching Milepost gate are enabled. Independent runtime verification passed.
Genuine customer page acceptance and a customer-approved physical check/repair
remain pending. The release issued no device command or repair approval.

The [feature contract](customer-device-checks.md) retains separate consent for
a bounded Windows health check and the exact proposed print-service repair.
Enrollment remains enabled under its independent gate. This release leaves the
`8west` provider scope, existing support cases and six pending customer identity
bindings unchanged. Add-on ordering remains separate implementation work.

## Production identity

| Item | Verified value |
| --- | --- |
| Host | `milepost-ec2`, hostname `ip-172-31-31-195` |
| Application | `/srv/8west/apps/safeharbor/current` |
| Prior source | `6c35301aa5dfb8c624fd271ff49199520d6fd224` |
| Released source | `0adde180fe2bae2a9a699fa81085cc90f3e1621d`, [PR #154](https://github.com/Seckcey/8_west_helpdesk/pull/154) |
| Exact-main CI | [37108339920 — success](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37108339920) |
| Source artifact SHA-256 | `09c6fdd69e189efd591c431621724823f283f33ec880908be2c6a2c030aac8b4` |
| Installed artifact SHA-256 | `74a28d2e2361dba5a9a921a4fe5a12746c15ee29f0544d6762ef7ccb835a6be2` |
| Milepost counterpart | `056e0b2beff9cfb8dc5f7f4ef1cf4e7c7061c99f` |

The complete installed artifact was independently recomputed and matched both
the current and immutable release markers. The prepared exact-commit source,
derived brand assets and PHP lint passed. Safeharbor required no schema change.

Milepost's [exact-main CI](https://github.com/Seckcey/8westit_webapp/actions/runs/37109988521),
[migration](https://github.com/Seckcey/8westit_webapp/actions/runs/37111573793),
[deployment](https://github.com/Seckcey/8westit_webapp/actions/runs/37112122801)
and [398-file post-audit](https://github.com/Seckcey/8westit_webapp/actions/runs/37112177391)
succeeded. Its protected migration receipt matched the exact target/checksum,
and independent schema checks found exact empty S2 without drift.

Milepost [PR #573](https://github.com/Seckcey/8westit_webapp/pull/573) corrected
browser-test synchronization without changing product code. The prior green
base reproduced the immediate assertion race under a controlled delayed body.
The first [audit 37111340839](https://github.com/Seckcey/8westit_webapp/actions/runs/37111340839)
stopped at client input validation because the tooling attestation was missing;
all remote mutation steps were skipped. The corrected
[audit 37111469712](https://github.com/Seckcey/8westit_webapp/actions/runs/37111469712)
passed. The failed attempt and corrected input remain in an immutable amendment.

## Maintenance and restored reporting

Protected source/configuration and trigger-inclusive database backups were
restore-tested before the runtime window. Schema, row bytes, source bytes,
ownership, modes and links matched; scratch databases were removed. Recovery
evidence remains root-only under
`/srv/8west/backups/customer-diagnostics-20261003T075703Z-01a0ff5c`.

The maintenance/report-stop interval was `09:13:43Z–09:15:39Z` (116 seconds).
After stopping the report schedule, the existing exclusive report lock and
absence of a legacy runner were checked before freezing HTTP and the runtime
account. HTTP 403, account lock and zero connections were verified at
`09:13:44Z`; no process or connection was killed. Source extraction succeeded
at `09:13:45Z`, followed by full artifact/configuration/grant verification.

The backend and frontend diagnostics gates were enabled at `09:14:47Z` using
atomic configuration replacements. Loaded comparisons proved that only each
diagnostics boolean changed; original owner/group and mode `0640` were retained.
The empty-ledger reconciler returned zero failures without creating jobs or
receipts; its dedicated Milepost schedule was installed at `09:14:50Z`.

Reporting was rebound to the actual installed artifact and enabled configuration,
then preflighted and installed disabled. The runtime account was unlocked and
its original grants verified while HTTP remained denied. The original `0600`
vhost was restored and HTTP 200 verified at `09:15:37Z`. The original report
scope was restored active at `09:15:39Z`. Apache was reloaded, not restarted.

Report recipient, tenant/customer, schedule and existing real delivery evidence
were preserved; only source/configuration bindings and review time changed.
All 67 pre-existing cron files and both original vhosts matched. The persistent
report lock inode was retained. Both public and TLS-verified origin login routes
returned 200; Apache/MySQL/cron were active, runtime grants unchanged and the
account unlocked. All seven customer identity/enrollment/sync table fingerprints
matched their pre-release values. The coordinator independently verified these
facts and accepted the runtime handoff.

## Verified behavior and pending acceptance

Live signed-empty transport returned `400 invalid_request`; nonce replay returned
`401 service_replay`; unsigned transport returned `401 service_authentication`.
Before/after counts stayed at 4,695 jobs, zero customer command receipts and zero
customer operations. Pending mail and recent pending customer chat were zero.
No announcement, provider action, purchase or new report-delivery canary ran.

The normal browser continuation used a staff Owner session. The customer-role
guard correctly refused it with `local_policy_rejected`; that is not successful
customer acceptance. The genuine customer GET check awaits the correct account.
The selected laptop is expected to show `support_review` because 12 staff-owned
cases remain open. No case was closed, reassigned or altered to bypass that hold.

An actual health check needs explicit customer consent. A repair additionally
needs the original requester's exact approval and later physical verification.
Synthetic role/consent/browser checks and runtime health do not prove those
customer actions occurred.

For containment, disable both diagnostics gates, retain all durable operations,
jobs and uncertain receipts, and stop only the dedicated reconciliation schedule.
Rebind reporting to the actual contained configuration while preserving its
scope. Retain the additive Milepost table; never restore an old database over
new customer history or repeat an uncertain device command. Source recovery
uses the protected release procedure and verified current state. The
[Milepost receipt](https://github.com/Seckcey/8westit_webapp/blob/main/docs/deployment/CUSTOMER_DEVICE_DIAGNOSTICS_2026_10_03.md)
records its migration, workflow and recovery evidence.
