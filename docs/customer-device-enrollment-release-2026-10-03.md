# Customer device enrollment activation — 2026-10-03 UTC

Safeharbor's **Your devices** page and Windows enrollment are enabled for currently authorized customer identities with active business bindings. Owners and admins can create a four-hour, single-use setup link and revoke unused links; other customer roles can view device status. Existing Westy chat remains available. Customer-triggered diagnostics/repair and add-on ordering are separate follow-on work and are not enabled by this release.

This is the 8 West customer rollout: the production provider allowlist remains `8west`. It does not activate directory, device, repair, or provisioning access for a new independent MSP. End-to-end onboarding of a new MSP remains separate and unproved.

## Production identity and evidence

- Host: `milepost-ec2`, actual `ip-172-31-31-195`; app `/srv/8west/apps/safeharbor/current`.
- Source: `6c35301aa5dfb8c624fd271ff49199520d6fd224`, PR [#152](https://github.com/Seckcey/8_west_helpdesk/pull/152).
- Source artifact SHA-256: `73ce01f504161f3917929d46584caeb73ef290296f66a6cb2d3dfa9a49a67fb6`.
- Installed artifact SHA-256: `6f3a76bbf6ff5917088ae495ce952037309991bf1b3c3cf445004d40f1a4899d`, independently recomputed and matching the current release marker.
- Protected config SHA-256: `2ccb679bcb430d449837f972d755789f134916365d36a3c8c171148d06f2a9a0`. Only the reviewed `portal_devices` block was added; every other loaded setting remains equal to its preserved predecessor.
- Milepost counterpart: `0288c01ba36820028c531d1bf52d51b42f62ac5d`. Protected [migration](https://github.com/Seckcey/8westit_webapp/actions/runs/37093674088), [deployment](https://github.com/Seckcey/8westit_webapp/actions/runs/37093751710), and [392-file post-audit](https://github.com/Seckcey/8westit_webapp/actions/runs/37094127899) all succeeded. The new enrollment table independently verified exact, without drift, and empty.
- Root-only evidence and rollback packet: `/srv/8west/backups/customer-devices-20261003-01a0ff5c`.

Fresh database backups were restored into isolated scratch databases and compared by exact table row bytes, triggers, routines, and events before those scratch databases were removed. Both source archives were extracted and compared against live bytes, modes, and links. Original configuration, grants, vhosts, cron, reporting activation, and release markers remain preserved.

## Maintenance window and permission correction

The portal's bounded maintenance window was `03:44:13Z–03:46:17Z` (124 seconds). Its vhost was temporarily denied, runtime account locked after connections drained, and reporting stopped. Apache was reloaded, not restarted.

The new root-owned release-control directory inherited its parent's setgid bit, producing mode `2700`. The reporting controller requires exactly `0700` and correctly refused it after the source/configuration install. The original failure fallback could not verify reporting through that rejected directory; independent checks confirmed the actual portal remained denied, runtime locked with zero connections, and reporting absent.

A bounded resume pinned the installed source/artifact/configuration and current service state, removed only the inherited setgid bit, and completed the original reporting preflight, disabled installation, portal reopening, and schedule activation. Its SHA-256 is `ea6a48b1843bba102e336faa3463883c1eccd67d2fec39b588a466a0f61dffc1`; the frozen original operator remains at `113225297e0e9cbef31c505654e1cfc15d3bcc2b4a95a8e69d9f6f5bafaf2471`.

Reporting is active and the runtime account unlocked. The original report recipient, scope, delivery evidence, and cron bytes remain preserved. Only the report's release/artifact/configuration binding changed. Runtime grants, both vhosts, and all existing cron files passed comparison against the retained originals. Future control directories must explicitly clear inherited special bits and verify their exact metadata before beginning a service window.

## Verified behavior and remaining acceptance

The installed Safeharbor transport authenticated a signed empty-contract request that Milepost rejected at validation (`400 invalid_request`). Reusing its nonce returned `401 service_replay`, and an unsigned request returned `401 service_authentication`. This proves transport and signature enforcement without fabricating a customer session or issuing a setup link.

Public and certificate-verified origin login/device pages returned 200 with sign-in required. Unauthenticated Westy returned 401 with `sign_in`. Pending recent portal chat turns and pending mail were zero. Existing Milepost PowerShell jobs retained their previous 32 queued/one running categories; this release did not dispatch or alter them.

Before release, all 18 device MySQL tests, 44 portal boundary checks, existing authentication/private-chat regressions, and three desktop/mobile browser flows passed. Screenshots covered desktop, mobile, and setup-link states, including readable button contrast and the Westy button without overlap. PR and main CI passed.

No customer announcement, invitation, setup link, endpoint command, purchase, installation, or new report delivery was performed for release verification. A real customer Windows installation and first heartbeat remain separate acceptance evidence. The worker handed back shared release ownership after verification.
