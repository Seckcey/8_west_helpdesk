# Settings and requester privacy — Safeharbor

Work unit: `SETTINGS-ACCESS-20261021`. **Production technical deployment is complete;
Frankie's signed-in customer acceptance remains pending.** Safeharbor's Sergeant
owns integration and supplied the production verification receipt below; the ID
lead sequences the suite release. The shared UI package was synced from the
published, reviewed `v0.4.0` release using
`tools/w365/sync-suite-ui.ps1 -Tag v0.4.0 -Dest app/public/assets/w365`.
No vendor file was hand-edited. This documentation closeout changes no application
code or production state and requires no further production deployment.

Published package: [w365 v0.4.0](https://github.com/Seckcey/8_west_suite_ui/releases/tag/v0.4.0),
tag commit `4b1d7108dcb0a7b053a149e48db142d2fedd180a`.
ZIP SHA-256: `d4000f15baa079cdbcd160ee3a20dfbc4f74c8f188d90d3a61d79e4aadc39537`.
Manifest SHA-256: `8f0e12f0448a04cd01539b59386258dfdb27cd2e515e304ab98e55cec213e3d5`.
All 10 manifest entries and the exact file set are checked by the consumer test;
Git preserves package bytes and the app refreshes asset cache URLs.

## Production technical result — October 21, 2026

Safeharbor's Sergeant applied the release under independently verified Frankie
protected approval `01a161a5-4a44-7c92-9065-918684f06b31`. The deployment history
records `0548f3ea51184beeaf841207044dea602de4d970` →
`4021f4507f4727259d0c67ae2a99ba5beaf97400` at **9:12:59 PM PDT on October 21**
(`2026-10-22T04:12:59Z`). The normal pinned command exited 0:

```bash
bash deploy/deploy.sh 4021f4507f4727259d0c67ae2a99ba5beaf97400 --migrate app/db/migrations/portal_ticket_requesters_v1.sql
```

PR [#255](https://github.com/Seckcey/8_west_helpdesk/pull/255) merged the reviewed
`b2755c7979ef5bdb0a88571958885284379dd2b0` head. Both required PHP/browser jobs
passed on that head and on deployed main `4021f45`
([main Validate](https://github.com/Seckcey/8_west_helpdesk/actions/runs/39714299955)).
Production remains at application source `4021f45`; a later documentation-only
merge is not another runtime release.

The release operator verified:

- **Source/package:** all 386 runtime files match `4021f45` after the normal
  deploy substitutions; the live w365 manifest matches the SHA-256 above.
- **Schema:** the applied 4,605-byte SQL has SHA-256
  `473bf4c45de8992c45e900e8792b0ee20564246eb8b16c78775d82d8555aa1f8`.
  The six non-null columns, primary/foreign keys, checks, index and InnoDB engine
  match the migration. All three actual `BEFORE INSERT`, `BEFORE UPDATE` and
  `BEFORE DELETE` guard bodies were verified. Requester rows were zero at
  postflight; no historical backfill was performed. The runtime principal can
  `SELECT`; existing grants were unchanged.
- **Portal hold:** `portal.enabled` changed from true to false at
  `2026-10-22T04:11:58.570932Z`. All 15 checked origin routes and the public
  request route returned 404, and traffic drained before deployment. After
  postflight it returned to true at `2026-10-22T04:14:41.445624Z`, about 2 minutes
  43 seconds later. Original config bytes, owner and mode were restored exactly.
- **Runtime/jobs:** all six cron files were unchanged; signup and desktop-cleanup
  timers and the canonical PHP-FPM service were active. No deployment/paused
  markers or actual mutex holders remained. The shared release slot was released
  and the ID lead informed.
- **Signed-out probes:** origin and public login, CSS and manifest returned 200;
  portal, requests, new request, reports and guide redirected to sign-in (302).
- **Normal backup:**
  `/srv/8west/apps/safeharbor/deploy-state/backups/20261022T041255Z-0548f3ea5118-to-4021f4507f47`
  contains a 302,699-byte database gzip and 942,080-byte files tar. `gzip -t` and
  `tar -t` passed; these are archive-integrity checks, not a restore exercise.

The full ID `30e70c6` release was live before Safeharbor. The suite owners verified
Coastmark `7c398c1` and Milepost `74a03eea` source deployments as dependencies.
These deployment receipts do not establish signed-in cross-app acceptance.

No failure or inverse occurred. There were no historical deletions, new grants,
secret changes, unrelated config changes, provider/financial operations, or
customer account/ticket/invitation/device actions to manufacture acceptance.
**Frankie still owns genuine signed-in owner/admin/member/viewer acceptance with
existing approved identities.** The old `0548f3e` source remains privacy-incompatible:
before any source inverse set the portal false and keep it false until a qualified
privacy-compatible forward repair; retain all requester and audit history.

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
[`docs/suite-client-company-v1.md`](https://github.com/Seckcey/8westit_webapp/blob/25f880e22e12ffe1a2115fd7d08fce9dcd143e8a/docs/suite-client-company-v1.md) at
`25f880e22e12ffe1a2115fd7d08fce9dcd143e8a` (`Seckcey/8westit_webapp`). ID's
`settings_client_admin.php`/`client-people.php` adapter owns discovery and
per-operation validation through that contract, including original JWT,
subject, target, reference/generation, role, revocation and exact provider/client
binding. Each context/list item requires verified `provider_suite_tenant_id`
from the current managed-provider issuer mapping or the exact acknowledged house
`coastmark_billing_export.tenant_id` / `suite_tenant_id` tuple. The older
`coastmark_billing` tuple is compatible only when the canonical block is absent
or both validated tuples agree. Conflicting or missing/stale/invalid mappings
are refused; consumers must match the issuer namespace to existing immutable
links, without numeric-ID or slug/name inference. Safeharbor adds navigation
only and does not consume this response; this contract revision requires no
Safeharbor code change. A CSA `client_owner` role is never ID
primary-owner proof: appointing/removing administrators and closing a
subscription remain separately reserved to ID's genuine ownership evidence.
The ID adapter is deployed in the full `30e70c6` release; genuine signed-in
cross-app acceptance remains separate and pending.

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
   real saves to manufacture acceptance. Future migration permission remains
   separate; the completed migration/deployment used the recorded protected approval.
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

The portal settings browser matrix has 12 cases; the published staff-menu matrix
has eight. All eight staff screenshots were refreshed with the fixture's explicit
UTF-8 declaration and reopened at reviewed head `b2755c7`. Set
`CUSTOMER_UI_SCREENSHOT_DIR` for
screenshots. These are synthetic render/route proofs, not live SSO, provider,
cross-app, migration or customer acceptance. The separate production technical
receipt above records live source/schema/probe evidence, not signed-in acceptance.
