# Settings and requester privacy — Safeharbor

Work unit: `SETTINGS-ACCESS-20261021`. This is a source/staging packet, **not a
production acceptance record**. Safeharbor's Sergeant owns integration; the ID
lead sequences the suite release. The reviewed shared UI release and this
consumer's supported sync are separate dependencies. No vendor candidate is a
published release. No production schema, configuration, grants or customer data
were changed by this worker.

## User behavior

- **My settings** opens 8 West ID for suite-linked staff and portal customers.
  Portal pages adopt verified personal theme, density, contrast, motion, locale,
  time zone and date/clock preferences. Preferences affect presentation only.
  A same-subject verified suite cookie can refresh the OIDC presentation copy.
  Missing or malformed preferences use the existing shared defaults.
- **Client Admin** needs both the effective client owner/admin role and the
  exact verified v1 panel for the bound client. A home workspace context is
  never reinterpreted as a delegated client. Missing/unknown context leaves
  My settings available and grants no new administration link.
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

The pinned OIDC client is unchanged. Safeharbor's adapter reads optional
settings context from the existing RS256 suite cookie, matching its subject,
session version, raw role and identity tenant to the authenticated OIDC session.
The selected binding/effective membership still controls portal authorization.
OIDC-only or older sessions work without the optional administration panel.
The suite cookie is a distinct token type: the existing verifier refuses OIDC
audience/nonce/authorized-party claims on that surface. Navigation is not an ID
authorization grant; ID independently checks each destination.

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
   portal flag. Perform signed-in owner/admin/member/viewer acceptance with
   agreed test identities. Do not create real tickets to manufacture evidence.
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
