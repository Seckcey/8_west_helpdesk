# Customer phones, tablets and Macs — production release

On October 3, 2026, the reviewed mobile source and two feature gates were deployed
on `milepost-ec2` (`ip-172-31-31-195`). Safeharbor maintenance ran from 05:04:00
through 05:07:46 Pacific (12:04:00–12:07:46 UTC), **226 seconds**. Reporting is
active with its original scope and delivery evidence.

The Phones, tablets & Macs entry point is enabled for already admitted customers.
Provider setup, genuine signed-in customer acceptance and physical enrollment
remain pending. Intune bindings, Android enterprise mappings and Android device
rows were zero at verification. The owner's tentative Jamf Now signup has no
adapter in this release. No six-customer activation, add-on order, purchase,
device command, enrollment or remote-control session was performed.

## Installed release

- Safeharbor [PR #155](https://github.com/Seckcey/8_west_helpdesk/pull/155):
  `d97c8f1488fa7ab010234e635f5dfe786529c5ac`, following successful exact-main
  [Validate](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37117018837)
  and [mobile](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37117018832) checks.
- Milepost [PR #571](https://github.com/Seckcey/8westit_webapp/pull/571):
  `df5bde839b1bac87ede7e53c7586407bf49f3596`. Its manual
  [audit](https://github.com/Seckcey/8westit_webapp/actions/runs/37121403265),
  [deployment](https://github.com/Seckcey/8westit_webapp/actions/runs/37121489789)
  and [402-file post-audit](https://github.com/Seckcey/8westit_webapp/actions/runs/37121568561)
  succeeded with migration `none`.

Safeharbor's unchanged clean-checkout installer held the existing exclusive
report lock and verified the complete source before writing the actual installed
artifact marker. Source/config were verified again before activation.

| Record | SHA-256 |
| --- | --- |
| Complete Safeharbor source artifact | `a6a56bbf5f93f8832136069f43e3b71302481d57f38708460c89236053e788ba` |
| Actual installed artifact after asset stamping | `374f27df7a35dce011c30cc449f681a59f3c8262ff4757c481921ecfc9f12494` |
| Current release marker, identical to immutable marker | `78e62b08cf4979dcd396a65a569e1b9446f6c362bfa07934b2b477d9e9816eac` |
| Safeharbor protected config after activation | `09c67c20c3c60988f1938293b7fd349cc7c95577e99ef1209c0351629cdec96b` |
| Milepost protected config after activation | `dc776cbce93ee60b73e7baf1a4ffadd57a0b8d90ffff4fefbee69750417d259d` |
| New report bundle | `502d4c94c40548e3ee40e567a3ee32396213a7e28f3f5c8100e3df5b31ca713d` |
| Active report evidence | `5b52e1d335860a33a87e264556a973163c47230c12c9d476f6f331afb402f843` |

Only Safeharbor `portal_mobile.enabled` and Milepost `customer_mobile.enabled`
became true. Full loaded-array comparison proved every other protected setting
unchanged. Existing diagnostics remain enabled and provider access remains
restricted to `['8west']`. These flags expose guidance/status; they do not prove
provider readiness or broaden customer admission.

## Maintenance and the directory-mode refusal

Reports were stopped and allowed to drain before taking the runtime account lock.
The permanent report lock was explicitly handed off to the installer and then
reacquired for verification; no competing descriptor was held across installation.
Only Safeharbor's exact vhost was temporarily denied. No process or job was killed.

The new report-control directories inherited the parent's setgid bit: mode `2700`
instead of required `0700`. The unchanged report-binding validator refused before
creating any bundle or activation evidence. HTTP remained denied, the runtime
account remained locked, and reporting stayed stopped. After proving evidence
paths absent, `chmod 00700` corrected only the three new task-owned directories
to `root:root 0700`. The existing parent, old control directory and permanent lock
were untouched. Only the refused report tail resumed; no source/config replay or
validator weakening occurred. Verify exact directory modes before future windows.

The rebound bundle passed preflight and staged the original schedule disabled.
The exact runtime account was unlocked and unchanged grants verified while HTTP
still returned 403. The original vhost was then restored atomically, Apache was
reloaded, and HTTP 200 was verified before restoring the report's exact original
customer/sender/recipient/schedule/delivery tuple. Reporting verified active at
12:07:46 UTC. No new mail canary was sent.

## Preservation and smoke checks

- All seven identity/enrollment/synchronization table fingerprints remained
  identical. Customer command receipts remained zero. The 4,695 jobs, including
  32 historical queued jobs and one historical running job, were unchanged;
  there was no recent dispatch or creation.
- All 69 pre-existing cron files retained paths, bytes, owners and modes. The
  directory count is now 70 only because the manager preserved the original
  active report file as the ignored evidence file
  `safeharbor-business-reports.quarantine.20261003T120400184065508Z.3717609.1`.
  No active schedule was added. The permanent lock remains inode `262917`.
- Both vhost contents/modes and the exact runtime account/grants were restored.
  The vhost/report cron received only the expected atomic-replacement inodes.
- Both public and certificate-verified origin login probes returned 200. Mobile
  and existing devices pages showed the unauthenticated sign-in boundary, the
  mobile stylesheet returned 200, and the unsigned backend API returned
  `401 service_authentication`. Apache, MySQL and cron were active.
- Final evidence at 12:12:27 UTC showed no pending mail/chat, active report/mail/
  customer-chat worker or Safeharbor DB connection. Deployment locks were released,
  owned scratch databases and task containers were absent, and newly staged
  installer controls were removed. Historical controls owned by other work were
  left intact.

These HTTP checks are not signed-in customer acceptance. Real customer navigation,
provider consent/binding, physical enrollment and the real device's portal record
remain pending. Existing synthetic browser evidence retains its synthetic status.

A supplemental server-side check at 12:21:27 UTC used the protected service
credential without emitting it. A signed unsupported action with empty customer
scope returned `400 invalid_request`; exact replay returned `401 service_replay`.
The parser refused before customer/provider handling. Job (4,695), customer-device
operation (0), repair-execution (17) and customer-repair receipt (0) counts were
unchanged; only the expected nonce row was created. No customer session was
invented and no valid inventory, provider or repair request was made.

## Recovery evidence

Restore-tested source/config and trigger-inclusive SQL backups remain protected
under `/srv/8west/backups/customer-mobile-df5bde8-d97c8f1-01a10067` with their original
capture times. Do not restore those SQL snapshots over later customer history.
For mobile containment, use the reviewed report-drain/maintenance/lock sequence,
disable the frontend before the backend, preserve all other settings, then rebind
report evidence to the resulting configuration and restore the original scope.
Source recovery requires fresh exact source/config and current-work checks;
release-specific controls must not be blindly replayed against later releases.

The operator's `production-release-receipt.json` has SHA-256
`e14716b9930d9df62c51bbcbd2a6e7d634523ce1d0a1b07bc6e77bf25e8d382c`.
It binds the execution logs, refusal/correction, state comparison and cleanup.
The coordinator independently verified live state at 12:09:27 UTC. See the
[Milepost companion record](https://github.com/Seckcey/8westit_webapp/blob/main/docs/CUSTOMER_MOBILE_RELEASE_2026_10_03.md)
and [feature contract](customer-mobile-management.md). Documentation-only closeout
commits do not change the deployed application source above.
The immutable original receipt is supplemented by
`supplemental-signed-smoke-20261003.json`, SHA-256
`46f96a50b63c66a297b27fb5165f99591ef8a51c210ca0184af9ea78931da517`.
