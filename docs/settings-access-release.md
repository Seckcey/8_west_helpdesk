# Settings and requester privacy — Safeharbor

Work unit: `SETTINGS-ACCESS-20261021`. This is a source/staging packet, **not a
production acceptance record**. Safeharbor's Sergeant owns integration; the ID
lead sequences the suite release. The shared UI package was synced from the
published, reviewed `v0.4.0` release using
`tools/w365/sync-suite-ui.ps1 -Tag v0.4.0 -Dest app/public/assets/w365`.
No vendor file was hand-edited. No production schema, configuration, grants
or customer data were changed by this worker.

Published package: [w365 v0.4.0](https://github.com/Seckcey/8_west_suite_ui/releases/tag/v0.4.0),
tag commit `4b1d7108dcb0a7b053a149e48db142d2fedd180a`.
ZIP SHA-256: `d4000f15baa079cdbcd160ee3a20dfbc4f74c8f188d90d3a61d79e4aadc39537`.
Manifest SHA-256: `8f0e12f0448a04cd01539b59386258dfdb27cd2e515e304ab98e55cec213e3d5`.
All 10 manifest entries and the exact file set are checked by the consumer test;
Git preserves package bytes and the app refreshes asset cache URLs.

## User behavior

- **My settings** opens 8 West ID for suite-linked staff and portal customers.
  Portal pages adopt verified personal theme, density, contrast, motion, locale,
  time zone and date/clock preferences. Preferences affect presentation only.
  A same-subject verified suite cookie can refresh the OIDC presentation copy.
  Missing or malformed preferences use the existing shared defaults.
- **Client Admin** uses the freshly checked selected client owner/admin role,
  including delegated CSA membership. It links only to the fixed
  `https://id.8westit.com/admin.php?panel=client_admin` destination. Home realm
  and home role are unchanged. ID independently discovers the allowed client
  and rechecks authorization on every read/write; no target, token or identity
  authority is added to the URL. Staff/viewer memberships have no such entry.
- Staff see **Technician Workspace**. **MSP Admin** additionally needs the
  verified panel and existing local owner/admin role. Team describes its actual
  scope: local Safeharbor access and recovery accounts. The ID People link also
  requires the verified `manage_people` capability. Suite-linked names and
  passwords cannot be changed through the local profile POST handler.
- Ordinary client staff/viewers see only requests they opened. Owners/admins
  retain client-wide requests and archived service summaries. Viewers remain
  read-only. Historical requests with no requester attribution stay visible
  only to client owners/admins and authorized staff. Names, email addresses,
  contacts and message authors are never used to infer ownership.
- Existing assigned-device and membership checks remain authoritative. Private
  Westy chat remains subject scoped. A reviewed Westy handoff creates one
  attributed ticket; its receipt URL passes the same ticket visibility check.
  Staff search, attachments and CSV export still require their staff session;
  the portal does not gain an attachment/search/export endpoint.

## Identity contract

Frozen ID contract: `Seckcey/8_west_id`,
`de7b9a6485b7b646bcee5f129d900728a7997f5d`,
`docs/settings-access-context-v1.md`. The optional `8west:settings_context`
v1 document is parsed strictly; unknown fields, panels, versions, malformed
capabilities or a different subject/workspace grant no panel. The client
`primary_owner_status=verified` value describes ownership evidence, **not**
whether this actor is the primary owner.

The pinned OIDC client is unchanged. Portal personal preferences come from the
verified OIDC identity or a same-subject RS256 suite cookie. Staff navigation
consumes the optional settings document; it never grants resource access.
The selected binding and fresh effective membership control portal navigation
and request visibility independently of the principal's home settings context.

The accepted delegated Client Admin ingress is Milepost's
`docs/suite-client-company-v1.md` at
`a9ce7f9e19380bed780004ed5a4cd838bb6ae5ce` (`Seckcey/8westit_webapp`). ID's
`settings_client_admin.php`/`client-people.php` adapter owns discovery and
per-operation validation through that contract, including original JWT,
subject, target, reference/generation, role, revocation and exact provider/client
binding. Safeharbor adds navigation only. A CSA `client_owner` role is never ID
primary-owner proof: appointing/removing administrators and closing a
subscription remain separately reserved to ID's genuine ownership evidence.
ID adapter publication/acceptance remains a suite release dependency.

## Additive schema and release hold

`app/db/migrations/portal_ticket_requesters_v1.sql` adds
`portal_ticket_requesters`; `app/db/schema.sql` carries the same fresh-install
definition. Each new portal ticket transaction writes its immutable subject,
identity tenant slug and exact trusted Safeharbor tenant/client. The ticket
and requester row commit together, including nested Westy handoffs. Database
guards reject mismatched insertion, reassignment and deletion. Existing rows
are not backfilled. Replay checks existing shape before replacing guards.

**The old application source is not a privacy-compatible rollback.** Keeping
the additive table alone does not prevent old source from showing client-wide
requests. Release/automatic inverse must retain a portal traffic hold:

1. Under the separately approved protected operator mechanism, record the
   prior `portal.enabled` value and set it to `false` in server-only
   `config/config.php`. Verify the actual portal read/write/login routes return
   404, and let existing requests drain before source/schema changes. The
   established deploy procedure then stops only Safeharbor's runtime and jobs.
2. Refresh release ownership, exact merged head/base/CI, live source gap,
   current schema, in-flight work and verified rollback. Account for every
   intervening change. No new grants or live schema application are implied by
   this document. Staging uses synthetic MySQL with no production connection.
3. Only after the protected migration approval, use the existing procedure:
   `bash deploy/deploy.sh --migrate app/db/migrations/portal_ticket_requesters_v1.sql`.
   It takes its normal database backup before applying the additive migration.
   Deploy only the reviewed merged main candidate. Never run ad hoc live SQL.
4. Confirm the live release identity, table shape and three guards while the
   hold remains in place. Review the exact-head test and consumer package
   receipts, then use the protected mechanism to restore the approved prior
   portal flag. Live signed-in owner/admin/member/viewer acceptance belongs to Frankie,
   using existing approved identities, and remains pending until his evidence.
   Agents must not create real customer tickets/accounts/invitations or perform
   real saves to manufacture acceptance. Migration permission remains separate.
5. On failure or any inverse, keep/reapply the hold **before** restoring old
   source. `deploy.sh` preserves `config/config.php` on deploy and automatic
   inverse. Verify portal denial after the inverse; keep it held until a
   privacy-compatible forward repair is reviewed and accepted. Never restore
   the database, delete requester rows, remove guards or erase audit/history
   as an application rollback. If containment cannot be verified, do not reopen
   customer traffic.

This plan requires a bounded customer portal interruption. Staff help-desk
work and other applications are outside the portal flag; deployments still
follow the existing application runtime/cron procedure. The operator must
record the window, flag changes, exact old/new commits, schema verification
and post-release acceptance in the release handoff.

## Reproducible evidence

All database tests require explicitly disposable synthetic MySQL. Run on
Coastline or CI, never desktop Docker. The existing environment names in CI
provide fixture credentials; no production configuration is used.

| Check | Evidence |
|---|---|
| `portal_settings_test.php` | Personal preferences, strict context, effective/delegated role navigation and local timestamp rendering |
| `suite_chrome_test.php` | Staff My settings/Technician Workspace, optional MSP panel, lost/foreign context removal |
| `portal_ticket_workflow_test.php`, `portal_mysql_test.php` | Two same-name employees, two clients/MSPs; list/count/detail/message/reply scope; legacy/admin behavior; immutable attribution; absent-schema rollback; additive replay and malformed-shape refusal |
| `portal_westy_mysql_test.php` | Private chat/draft isolation, exact handoff attribution, same-client employee receipt refusal, transactional/retry integrity; synthetic provider only |
| `portal_requester_http_test.php` | Actual portal middleware/routes, member/admin/viewer responses, revoked-session refusal, portal-cookie denial on staff routes, portal traffic hold |
| `portal_access_mysql_test.php`, `portal_employee_device_access_mysql_test.php` | Existing delegated membership generation/role checks and assigned-device removal boundaries |
| `tools/shots/portal-settings.test.mjs` | Actual PHP/CSS/JS render, 1440 and 390 px, light/dark portal and Westy workspace, mobile menu and My settings destination; simulated ID endpoint |
| `tools/shots/suite-settings.test.mjs` | Published launcher with actual staff menu: admin/technician, light/dark, desktop/mobile, keyboard focus/arrow/Escape |
| `tools/shots/portal-contract.test.mjs` | Existing portal/browser interaction and authorization regression coverage |

To repeat the inverse demonstration, unpack the reviewed previous source into
a separate disposable directory and set `SAFEHARBOR_SETTINGS_OLD_APP` to its
`app/` path when running `portal_requester_http_test.php`. It swaps source only
in its temporary copy, verifies the same config bytes and 404s under the hold,
then stops its server and removes its synthetic database. This worker tested
the prior source `0548f3ea51184beeaf841207044dea602de4d970` with 34 passing HTTP
checks. CI without that optional old snapshot runs 24 checks.

The new browser matrix has 12 cases. Set `CUSTOMER_UI_SCREENSHOT_DIR` for
screenshots. These are synthetic render/route proofs, not live SSO, provider,
cross-app, migration or customer acceptance. Exact committed head, required
CI status and any remaining shared UI dependency belong in the PR handoff.
