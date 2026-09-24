# IT 365 stopping point — Safeharbor

Recorded September 24, 2026 UTC (September 23 Pacific).

## Stopping point and enabled behavior

The managed-client onboarding and Cloudline/Logbook integration slices are merged,
deployed and enabled. This is a stopping point for that approved development batch,
not a claim that every eight-app feature or customer acceptance journey is complete.
Documentation merges do not replace the running application revision.

Existing-client activation remains restricted to **8 West Ventures, LLC**;
previous Lifestyle behavior is preserved. New eligible MSP signups are separately
enabled after **2026-09-24 03:56:58 UTC**. The effective production values are:

- Coordinator `include_new_tenants=true`, explicit `tenant_ids=[1]`.
- `managed_clients.enabled=true` and `integrations.enabled=true`.
- ID `suite_workspace_onboarding.enabled=true`, `setup_tenant_ids=[1]`.
- Logbook `EWID_MSP_WORKSPACE_ONBOARDING=true`.

Do not turn these features off as routine closeout or follow a superseded dark-rollout
instruction. Example/configuration defaults describe new installations, not live
production. Deliberate incident rollback remains governed by the release runbooks.
Retired Inbox Watch and the superseded original Westy mail handler are not new features.

## Evidence and its limits

The accepted activation checkpoint is **2026-09-24 03:59 UTC** (September 23 Pacific).
Protected backups, scratch restores, source/image identity checks, scheduled execution
and signed-in acceptance are recorded in the linked activation report. A read-only
closeout recheck on September 24 confirmed the enabled gates, active timer, successful
worker and six workspace receipts. `waiting_for_input` is an honest workspace state:
it does not mean Microsoft consent, agent installation or real delivery has happened.

Two-MSP isolation, lost acknowledgements, retries, subscription/lease expiry, revoked
owners, lifecycle restoration with human holds and absence of financial/message side
effects passed with **isolated synthetic data**. No newly eligible real MSP existed
at activation. Signed-in house-workspace acceptance is separate evidence, not proof
of a new paying customer's entire journey. No customer messages, charges or endpoint
repairs were created for that acceptance.

| Application | Accepted activation revision | Source PR | Exact default-branch CI |
|---|---|---|---|
| Milepost | `a06988d38871442f3633d0d7cd90ff9d6c72f7d3` | [#525](https://github.com/Seckcey/8westit_webapp/pull/525) | [35952560862](https://github.com/Seckcey/8westit_webapp/actions/runs/35952560862) |
| 8 West ID | `ab3553594dc18d1d8f1c2ee586ff639e2812fcbe` | [#131](https://github.com/Seckcey/8_west_id/pull/131) | [35952647766](https://github.com/Seckcey/8_west_id/actions/runs/35952647766) |
| Safeharbor | `d90a608886deed9f8d3768750cd8029f41427fa5` | [#141](https://github.com/Seckcey/8_west_helpdesk/pull/141) | [35952651164](https://github.com/Seckcey/8_west_helpdesk/actions/runs/35952651164) |
| Coastmark | `aef9bf99f71c2430bd1935ec2737cd36c29475b8` | [#95](https://github.com/Seckcey/coastmark/pull/95) | [35851098875](https://github.com/Seckcey/coastmark/actions/runs/35851098875) |
| Control Panel | `ffa5f9bb9fa48e318e0e452f1884ade267de91f4` | [#57](https://github.com/Seckcey/8_west_control_panel/pull/57) | [35851381538](https://github.com/Seckcey/8_west_control_panel/actions/runs/35851381538) |
| Logbook | `befa60acd192524ae6bf051654fe0cf8258a70e8` | [#104](https://github.com/Seckcey/8_west_logbook/pull/104) | [35952817821](https://github.com/Seckcey/8_west_logbook/actions/runs/35952817821) |
| Cloudline | `a301c6ebd604a88e51b6323a01d33fab01049282` | [#229](https://github.com/Seckcey/8_west_cloudline/pull/229) | [35952698084](https://github.com/Seckcey/8_west_cloudline/actions/runs/35952698084) |

[Activation release and recovery](SUITE_INTEGRATION_ACTIVATION_2026_09_23.md). These are the accepted activation revisions; later independent Central
releases may advance ID. Verify its runtime marker and Central release record before release.

## Safeharbor closeout

Provider-owned client, portal binding, report-contact and lifecycle synchronization are active. Per-MSP Logbook exports are registered automatically. The report scheduler is active; Lifestyle records and delivery evidence are preserved.

Remaining: Ventures portal-owner invitation/acceptance, its human support/reply round trip and its actual report delivery remain unproved. The September 18 internal Westy email pilot is separate accepted evidence.

Local references: [Current status](where-things-stand.md), [managed-client contract](managed-client-onboarding-2026-09-23.md), [release procedure](../deploy/README.md).

## Remaining work, with clear ownership

| Next item | Owner / completion evidence |
|---|---|
| First real MSP and portal invitation/acceptance | ID + Milepost + Safeharbor; an actual entitled signup, correct client/contact scope and accepted invitation |
| Ventures human support/reply and report delivery | Safeharbor + customer; real receipt/reply evidence rather than transport health alone |
| Microsoft consent, agents, network credentials, infrastructure enrollment | Customer/admin supplies inputs; owning application verifies operation |
| Offline-payment 2% fee collection | Coastmark financial facts + ID subscription authority; decide next subscription bill versus separate monthly fee invoice, then implement and test |
| Broader Westy repair and provider-failure reporting | Separately scoped work; preserve the accepted Ventures device pilot and existing approval/recovery boundaries |
| Central consumer product | Separate Central project; preserve its PRs, billing state, owner bindings and active release work |

ID owns the monthly suite subscription. Coastmark owns client invoices and the
**2% fee only on collected payment**. Unpaid/sent invoices produce no fee. Existing
Stripe collection follows that rule; offline fee collection is unfinished.

## Development and documentation rules at this checkpoint

- Use `ssh coastline` for container builds/tests in isolated task directories,
  unique Compose projects, synthetic databases and unused loopback ports. Stop
  task containers afterward. Never start Desktop Docker or retained Coastline
  production/rollback stacks. Production remains `ssh milepost-ec2`.
- Verify fresh remote/default branch, dirty state and runtime identity before a
  new change. Preserve unrelated worktrees, private configuration and rollback.
- Start with this record for the suite checkpoint, the linked local status/runbook
  for app-specific behavior, and the dated release record for exact evidence.
  Older audits/plans retain historical value but cannot override the enabled state.
- Central's active website, ID branding and shared suite-UI branches are excluded
  from the IT 365 merge cleanup. Central's agent owns their review and release.
  No pending Central billing PR was found in ID, Milepost or Safeharbor during
  coordination; recheck ownership rather than treating retained branches as work.
- Do not treat an uncompleted product backlog item as a regression or disable a
  completed feature merely to reach a stopping point.
