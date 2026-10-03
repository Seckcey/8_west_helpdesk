# Six-customer portal activation — October 3, 2026

Six existing 8 West businesses now have active Safeharbor portal bindings,
bringing the active total to nine. Runtime verification finished at
**05:49:59 Pacific (12:49:59 UTC)**; the IT 365 coordinator independently
accepted it at **05:54:03 Pacific (12:54:03 UTC)**. This advances the identity
activation left pending in the earlier [portal/chat release](customer-portal-release-2026-10-02.md)
and [mobile release](customer-mobile-release-2026-10-03.md) records.

No users, invitations, report contacts or financial records were created.
The business bindings are active; genuine authorized customer sign-in,
navigation and chat-reply acceptance remain pending. HTTP health checks and
staff access cannot establish customer-role acceptance.

## Exact bindings

All rows belong to provider `8west` and originate in Milepost tenant `1`, at
source version `1`. Each uses its immutable customer UUID and the new ID tenant
shown below. Central Test was not adopted into an existing tenant by name.

| Business | Customer UUID | MP client | ID tenant | SH client | SH binding |
| --- | --- | --- | --- | --- | --- |
| RPM | `fe3802dc-bd12-4737-92c0-f13cb8054099` | 1 | 11 | 15 | 4 |
| Thornton Mortgage Inc | `68d732d4-8e39-410e-b2fb-571cade44e4b` | 3 | 12 | 17 | 5 |
| Nexgen Building Group, Inc | `4f88e35f-dde8-4820-b946-4405d8af142e` | 4 | 13 | 18 | 6 |
| Soft Tissue | `d4efcac1-a486-4046-b9b4-115dbc060f07` | 6 | 14 | 20 | 7 |
| Tim O'Grady | `8b8b5604-39ab-4293-a496-6f16bc78e24c` | 7 | 15 | 21 | 8 |
| Central Test - Frank | `a4c63d0a-aa90-4031-8747-d70bc00b1929` | 11 | 16 | 22 | 9 |

Each binding followed canonical portal-only prepare-disabled, exact mapping
inspection and enable operations, completed by 05:46:04 Pacific (12:46:04 UTC).
Each has two trigger-owned binding events (event IDs `7`–`18` in total).
The existing Lifestyle, Ventures and Mike bindings `1`–`3` and their six events
were preserved. Retired Trueskil and internal/stale businesses were excluded.
See the [customer portal contract](customer-portal-contract.md).

ID owns the six nonfinancial business identities and lifecycle transitions;
each has only `safeharbor` and `coastline_control_panel` product access and zero
users. The separate ID cron successfully reconciles all six as `www-data`.
Milepost's exact dispatch additions support future directory updates.
Safeharbor's report-based managed activation/lifecycle allowlists remain
Ventures-only; these six were activated through the explicit portal-only path.

## Runtime and preserved features

- Host: `milepost-ec2`, hostname `ip-172-31-31-195`; application
  `/srv/8west/apps/safeharbor/current`, database `safeharbor`.
- Source remained `d97c8f1488fa7ab010234e635f5dfe786529c5ac`.
- Protected configuration remained byte-identical, SHA-256
  `09c67c20c3c60988f1938293b7fd349cc7c95577e99ef1209c0351629cdec96b`.
- `portal.enabled`, `portal_westy.enabled`, `portal_westy.ai_enabled`,
  `portal_mobile.enabled` and `portal_devices.diagnostics_enabled` remain on.
  The existing Westy credential remains configured. Activation did not change
  the chat model, provider, privacy controls or usage limits.
- No source deployment, DDL, service restart or HTTP maintenance window was
  needed. Merging this documentation does not require redeployment.

The existing [private chat behavior](customer-portal-phase1.md) applies to a
valid active binding after a genuine authorized customer signs in. This receipt
does not claim new device enrollment, a diagnostics/repair run, mobile-provider
connection, add-on purchase or financial action.

## Reports and preservation evidence

The report scheduler remained active; its complete installed artifact and
source/config/recipient tuple were verified without a rebind. Recipient and
schedule values were unchanged and remain private on the host.

| Preserved report evidence | SHA-256 |
| --- | --- |
| Source artifact | `a6a56bbf5f93f8832136069f43e3b71302481d57f38708460c89236053e788ba` |
| Installed artifact | `374f27df7a35dce011c30cc449f681a59f3c8262ff4757c481921ecfc9f12494` |
| Active bundle | `502d4c94c40548e3ee40e567a3ee32396213a7e28f3f5c8100e3df5b31ca713d` |
| Activation file | `5b52e1d335860a33a87e264556a973163c47230c12c9d476f6f331afb402f843` |

The active bundle remains
`/srv/8west/apps/safeharbor/release-controls/20261003-customer-mobile-01a10067/enabled`;
the activation file is `/etc/safeharbor/business-report-scheduler.activation`.
The report lock inode remains `262917`.

All 28 selected cross-application preservation fingerprints matched. Safeharbor
checks include users, the original portal bindings/events, suite-customer sync,
Westy billing outbox and all ten recorded `business_report_*` tables covering
contacts, schedules, archives, deliveries and attempts. This is a bounded
evidence set, not a claim that every application table was unchanged.
All 70 preexisting cron files retained their bytes, owner, mode and inode.

The public portal and TLS-verified origin both returned HTTP 200; Milepost and
ID login routes also passed both checks (six requests total). Apache, MySQL,
cron and the existing signup/onboarding timers were active. All task release
locks were released. Human customer acceptance remains separate from these
service and database checks.

## Immutable evidence and recovery boundary

Protected host directory:
`/srv/8west/apps/milepost/release-controls/20261003-six-customer-execution-01a0fea0`.

| Evidence | SHA-256 |
| --- | --- |
| `final-runtime-proof.json` | `77d8ba75e5f245b7e5e6ca4e325a72a96adc1cccdbfe9e661c1f3ec60de0b26d` |
| `execution-manifest.json` | `5f347ffdfd6925332bd1c47eaddae7bd8bae8109f0dda6ba36962e9be974ce67` |
| Approved post-mobile activation packet | `380aefa786db3f6fc6a27142ae7109bbcd0ceeca90561d0b751840a1e2ac41d9` |

The coordinator independently rehashed all 290 manifest-listed artifacts.
Files are root-owned mode `0600` in a mode `0700` directory. Full receipts,
private configurations and signed confirmations remain on the host. The
approved packet is retained separately under
`/srv/8west/apps/milepost/release-controls/20261003-six-customer-postmobile-01a0fea0`.

This receipt is not permission to repeat activation. On mismatched state, stop
and inspect the exact binding and immutable events before another write.
If access must be contained, use the canonical disable operation for only the
affected new binding. Preserve the original three bindings and report scope.
Repair forward; do not delete accepted identity/binding history or restore an
old database over it. Preserve the report lock and the six new ID lifecycle
lock inodes. Stopping ID lifecycle processing weakens future containment and
is not a routine rollback.
