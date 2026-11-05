# Where things stand

What is live in Safeharbor, and how to check it on the server yourself. **Check, don't trust:** if you
change what is live, change this page in the same pull request. Older status notes and release records
are in git history.

## The live version

The [staff fallback for hardware options](westy-hardware-staff-notices.md) is
in source preparation with migration 038. It is a separate receipt for eligible
staff when no device contact is recorded. The existing acceptance below does
not establish staff submission or delivery.

### Live receiver: hardware upgrade outcomes and device-linked notices

Application source **`3f3b128ab508872fb97da6596b86d3e8a827c4cd`** and additive
migration **037** were published on **November 4, 2026, 13:25:16–24 UTC**.
The [schema 3 receiver](westy-hardware-outcomes.md) retains the original unresolved
ticket, human assignment and history, deduplicates unchanged decisions and uses
Milepost's signed current device-contact resolver before customer mail. Missing
contact and unavailable verification are separate outcomes.

At installation, all 418 published files matched the prepared Git release; four new
tables and eight history triggers were installed. Milepost's producer and saved-result
parser correction are now published. **The original hardware case has its upgrade
options and one verified `no_device_contact` notice**, completed by the normal worker
at **19:14:01 UTC**. No email was queued or submitted. The ticket remains open and
human owned; its earlier messages, receipts and grants are preserved.

The outbound cron account was corrected to the existing private runtime account,
`eightwest-safeharbor`, at **19:13:25 UTC**. Its normal next two minute ticks ran
without duplicate notes or attempts. Application configuration and permissions,
other cron entries and the two old sent-mail records are unchanged. This acceptance
covers the missing-contact branch; positive-contact delivery and device repair
remain unproved. Other legacy cron identities need a separate impact review.
See the [release evidence and rollback record](westy-hardware-release-2026-11-04.md).

### Included: assignee-controlled Westy continuation

The continuation receiver first shipped as source
**`7be64ab4fb69e4692be5df7ed735486079aee312`** with migration **036**, at
**4:39:45 PM PST on November 3, 2026**
(`2026-11-04T00:39:45Z`). This replaced the verified running `ab4b0769` release.
The current assignee can record a one-use return intent for an
eligible `needs_human` case without immediately changing assignment or status.
The receiver consumes that intent only for the exact fresh continuation; the
same ticket, original workflow and old execution history remain.

All 412 published files matched the prepared Git release. The installed v2
readiness validator and existing workflow health function passed under the
ordinary runtime identity. Protected configuration and runtime privileges were
unchanged, all paused jobs and timers were restored, and release locks were
released. This is receiver installation evidence; it does not establish a
particular handback, device repair, alert recovery or client rollout. See the
[release record](westy-workflow-resume-release-2026-11-03.md) and
[v2 contract and recovery boundary](westy-workflow-contract.md#v2-assignee-handback).

### Earlier shared-directory release

The shared client directory CUT2 source was **`d746c193b83de33996a037cbd8e747583ccc1db4`**
([PR #262](https://github.com/Seckcey/8_west_helpdesk/pull/262)), live since
**October 23, 2026 at 22:23:12Z**. Full PR and main CI passed; the stock source-only deploy exited
0 and all 393 runtime files matched. The lead restored public access and verified login/title/CSS
at `2026-10-23T22:24:36Z`. A bounded existing staff-session Billing click from Lifestyle client 14
resolved to the exact Coastmark client (Statement 7); local Billing fallback was observed too.
See the [shared directory release receipt](client-shared-directory-release.md) for evidence limits.
This replaced CUT1 `9d1650ad`, which installed migrations 032 → 033 → 034 earlier that day;
CUT2 applies no SQL. The prior portal release `a63dc4ee43dcf32cb44401e2a0f265959e14b4fa`,
deployed at **11:31:06 PM PDT on October 22, 2026** (`2026-10-23T06:31:06Z`), remains included.
Customers now
have Home at `/portal/`, with their requests, computers and next actions; Westy's
existing workspace is at `/portal/?view=westy`. Verified customer visits to the
staff login are handed to the portal. See the
[client access and portal release receipt](client-access-portal-release.md) and
[Home guide](employee-portal-dashboard.md). The earlier settings UX improvements
and shared menu v0.5.0 remain included. Genuine signed-in customer and provider
acceptance remains pending with Frankie. Later documentation-only commits do not
change this application release and need no production redeploy.

```bash
bash deploy/deploy.sh --check
```

prints the live commit, what a deploy would change, which jobs it would pause, and whether the site
passes its check. Every deploy and rollback adds a line to
`/srv/8west/apps/safeharbor/deploy-state/history.log` on the server.

## What is on

| Thing | State |
|---|---|
| Safeharbor | **Live** at https://safeharbor.8westit.com, on its own PHP runtime (`8west-php-safeharbor`) |
| Alert intake (`api/svc/alerts.php`) | **Live** since 2026-07-29; Milepost emitting |
| Automatic close of telemetry tickets | **Live** (migration 018): only an untouched, exact signed Milepost alert may close on recovery; any human or customer work removes that ability for good |
| Westy defect intake (`api/svc/westy.php`) | Safeharbor's own failure reports and reviewed thumbs-down feedback are **live**. External delivery is **dark**: no Westy service identity is registered |
| Westy thumbs up / down | **Live**: down files a ticket after review, up files nothing |
| Partner support intake (`api/svc/support.php`) | **Live** since 2026-08-09; Coastmark and Waypoint emitting |
| 8 West ID sign-in | **Live**, RS256 only |
| Migrations 001–024 | **Applied**. Later migrations shipped with the features they belong to; check for their tables before assuming (below) |
| Versioned service goals | **Live on v2**: Standard 480/240/120/60 minutes, Premium 240/120/60/30, elapsed UTC time, no waiting pause |
| Approval-grade technician time | **Live**, with append-only correction slips (migration 020); original approvals stay immutable |
| Approved time → Coastmark draft lines | **Live for the existing Lifestyle connection**, including the staff Send to billing / Open in Coastmark buttons |
| Logbook resolved-ticket knowledge | **Retired 2026-10-19** with Logbook: `logbook_export.enabled` is `false` and service identity 6 is inactive |
| Customer portal | **Live for the approved Lifestyle binding**; intentionally on. Its 8 West ID revocation cache is `/var/cache/8west/safeharbor/portal-revocations` (`portal.revocation_cache_dir`, corrected 2026-10-20) |
| Scheduled archived business reports | **Live for the Lifestyle schedule** (Wednesday 9:00 AM Pacific); the runner is a cron file run as `eightwest-safeharbor` |
| 8 West ID report contacts | Active for Ventures and eligible provider-owned onboarding |
| New-MSP onboarding, managed-client lifecycle | **On**. Existing-client activation stays Ventures-only. Per-MSP Logbook/Cloudline integration registration retired with those apps on 2026-10-19 |
| Customer propagation | Milepost → 8 West ID → Safeharbor is deployed for eligible MSPs; broad workers stay off |
| Tenant AI (Westy model choice per customer) | **Live**; see `docs/TENANT_AI.md`. A refused customer Westy send logs `[safeharbor-portal-westy] refused reason=…` in the `8west-php-safeharbor` journal, for example `cost_limit` when the customer's monthly budget is used up |
| Westy guidance for Macs | **Live** since 2026-10-19 (`53c9fbb`): on a computer that reports macOS, Westy uses no Windows-only tools and offers steps, Contact support, or RustDesk help after the person accepts. It points people to *Remote support setup* in Westy Companion for Mac, which is **not published yet** |

## Settings UX — current technical release, customer acceptance pending

`SETTINGS-UX-CORE-20261021` shipped through the normal deployment script as
`c79cf59`, replacing `4021f45`. Portal Account now has clear touch targets and
destination/client hints; Team and profile have readable choices, action buttons,
keyboard focus and save/error feedback. The staff menu uses shared UI v0.5.0.
Existing routes, handlers, role/privacy checks and local-versus-suite behavior
are preserved, with no new confirmation prompts, migration or flag change.

PR #257 and deployed-main PHP/browser CI passed. The deployed tree exactly
matched reviewed/tested `50a45f52`. Postflight at 07:08 UTC reported zero file or
folder differences and a passing site check. Public/origin login, CSS and manifest
returned `200`; customer and staff routes redirected to sign-in. Both served
manifests matched the verified v0.5.0 package. Protected config and all six cron
files were unchanged; the portal stayed enabled, PHP-FPM and both timers were
active, and deployment markers/locks were clear. The normal readable changed-file
backup retains privacy-compatible `4021f45`; no rollback occurred.

The [release record](settings-ux-release.md) contains exact commits, CI runs,
backup identity, synthetic evidence and completed test-resource cleanup.
**Signed-out checks and synthetic saves do not replace Frankie's pending genuine
signed-in customer/provider acceptance.**

## Settings/access — October 21 release history and retained privacy limits

`SETTINGS-ACCESS-20261021` first deployed application source
`4021f4507f4727259d0c67ae2a99ba5beaf97400` at **9:12:59 PM PDT on
October 21, 2026** (`2026-10-22T04:12:59Z`). The current `c79cf59` release preserves
its ID My settings/preferences and
immutable portal
requester attribution. Ordinary members see their own requests; client owners
and admins retain client-wide requests and archived reports. See the
[release record](settings-access-release.md) for the operator's protected approval,
exact source/schema/package verification, backups and synthetic validation.
Both required PHP/browser jobs passed on the reviewed PR head and deployed main.
All 386 runtime files matched the deploy-shaped source; the published w365 v0.4.0
manifest matched. The additive requester table and all three guards were verified,
with zero requester rows at postflight and no historical backfill or grant change.

The portal was held for about 2 minutes 43 seconds, then restored to its original
enabled state and exact config bytes. Runtime, timers and cron checks passed;
origin/public login and assets returned 200 and customer routes redirected to
sign-in. Full ID `30e70c6` was live first; suite owners verified Coastmark
`7c398c1` and Milepost `74a03eea` source dependencies. **Genuine signed-in customer
acceptance remains pending with Frankie using existing approved identities.**
The old `0548f3e` source is not privacy-compatible: any source inverse requires
the portal held false before the inverse and until a qualified forward repair,
preserving all history. The settings UX deployment did not change requester
schema, guards or history. Its normal rollback target `4021f45` retains those
privacy protections; the older `0548f3e` does not.

## Who may call us

| Identity | Endpoint | Product | Active |
|---|---|---|---|
| `milepost` | `alerts.php` | Milepost (RMM) | yes |
| `milepost-customers` | `customers.php` | Milepost customer directory | yes, exact tenant `8west` |
| `coastmark-support` | `support.php` | Coastmark, 365 edition | yes |
| `waypoint-support` | `support.php` | Waypoint (standalone, not a suite app) | yes |

`milepost-westy` and `controlpanel-westy` are **not registered**, so external Westy defect delivery is
off. `last_seen_at` updates on every signed request, so a stale value means that emitter has gone quiet:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT service, is_active, last_seen_at FROM svc_identities ORDER BY service"'
```

Partner support facts worth knowing: routing is by `clients.source_key` (`coastmark:{slug}`,
`waypoint:{slug}`), never by name, so staff may rename client rows; a key longer than 64 characters is
refused rather than cut; client rows read "Acme MSP (Coastmark)" by design. Production has no
`support_intake` block in its config and runs on the code defaults, so the first person to add a third
product creates that block (copy its shape from `config/config.sample.php`).

## Checking the database

```bash
ssh milepost-ec2 'sudo mysql -N safeharbor -e "SHOW TABLES"'
```

Expect `svc_identities`, `svc_rate_buckets`, `svc_support_rate` and `westy_reports` among them.

## Open items

1. **Westy external defect delivery is not activated.** It needs a `milepost-westy` identity, its own
   secret, Milepost's sender and a canary on tenant `8west`.
2. **Technician-time correction acceptance** still needs a fresh signed-in check on the 8 West IT
   tenant (overlap conflict, adjacency, rejection, replay, owner self-approval). Keep it nonbillable.
3. **Portal access for another customer** needs its own 8 West ID evidence, binding, isolation,
   revocation and logout check. Do not reset Lifestyle to rerun setup.
4. **Report delivery:** a changed recipient, sender, schedule or customer needs fresh activation
   evidence and separate authorization to email that customer.
5. **Billing:** approve time in Safeharbor, Send to billing, then Open in Coastmark to review the
   draft. Approving, sending, posting and payment stay deliberate Coastmark steps.
6. **Customer creation is not broadly enabled across the suite.** Coastmark and the Control Panel have
   no automatic customer-provisioning lane yet.
7. **Shared client directory: CUT2 Billing is live as `d746c19` (PR #262).** These
   status facts are Safeharbor owner and release-lead observations.
   - **CUT1** (above) shows the shared name and state beside local fields, Businesses and Retained
     and local from Milepost's current client directory, and Support, Devices and Billing buttons.
   - **P9**, the one-client Lifestyle identity-only restoration, is done; do not rerun it. The
     first launch exited 255 before any action. The single corrected act exited 0, with one
     `identity_lifecycle_only` receipt, no portal or report restoration, all five anchor checks
     true and the physical state unchanged.
   - **Valley** is linked: 8 West ID tenant 18 / transition 14 / OAuth 1, and Safeharbor client
     24 / binding 12 / receipt 19, both normal HTTP 200.
   - **CUT2** gives Billing Coastmark's `clients?provider=…&customer_id=…` route for linked
     clients (inactive and removed history included) and verified pending businesses. Coastmark's
     gate is green: its resolver is live and all eight approved UUIDs resolve for signed-in staff.
     Owner qualification passed at `fb7c718` (154 operational, 93 renderer and 16 browser tests),
     followed by full final-head and merged-main CI. One source-only stock deployment exited 0;
     all 393 runtime files matched. Public login/CSS passed after reopen. A bounded actual staff
     Billing click from Lifestyle client 14 resolved to one Coastmark Lifestyle result / Statement 7;
     existing local clients retained the generic entry. This is not customer acceptance.
   - **Owned scheduling restored:** following the lead's hook/common restoration order, all five
     original Safeharbor cron filenames and its previously active cleanup timer were restored;
     both worker holds were released. The second natural cleanup run was healthy and exited 0
     at 22:42:00Z. No manual scheduled lifecycle or P9 rerun was performed.

   See the [release receipt](client-shared-directory-release.md).
8. **Bulk ticket delete is built but not released.** It needs migration 035 and two table grants
   (`DELETE` on `attachments` and `csat`); owners and admins only. Frank asked for the current queue
   to be cleared; with the grants, about 2,929 of the 2,947 tickets go and 18 stay (3 with technician
   time, 15 with Westy workflows). Runbook: [ticket-bulk-delete.md](ticket-bulk-delete.md). After the
   clear, record a receipt here with counts and the run key only.

## Traps that have already cost time

- **Docker Compose reads `env_file` only when the container is created.** `docker restart` does not pick
  up a new secret; `docker compose up -d` does. (This bit Waypoint at go-live.)
- **Never hand-edit the server's code.** The next deploy replaces any file that differs from git; a hand
  edit shows up in `--check` as "changed".
- **SSH through the `milepost-ec2` alias.** The hostname is behind Cloudflare and port 22 times out.
- **Run `git fetch` and look at open pull requests before starting.** Two sessions once built the same
  Waypoint feature on the same day.
