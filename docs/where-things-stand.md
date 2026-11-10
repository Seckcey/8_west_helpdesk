# Where things stand

What is live in Safeharbor, and how to check it on the server yourself. **Check, don't trust:** if you
change what is live, change this page in the same pull request. Older status notes and release records
are in git history.

## The live version

### Unused Westy helpers and duplicate navigation removed

Current application source is **`25c14565f76836b8c2fa76e115a8dcaf7c5dc709`**,
published by one stock deployment on **November 9, 2026 at 3:13:50 PM PST**
(23:13:50 UTC). [PR #303](https://github.com/Seckcey/8_west_helpdesk/pull/303)
removes the unused legacy JSON guidance module and two helpers from the retired
direct streaming transport. [PR #304](https://github.com/Seckcey/8_west_helpdesk/pull/304)
removes only the duplicate Account shortcut to Your devices. Active Westy
streaming, saved replies/drafts/replay, approved Guide text, roles and the
per-device Computer access action are preserved.

Exact merged-main [Validate 42686491635](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42686491635)
passed both PHP and browser jobs. The release replaced eleven files, deleted
one unused module and added none. All **419 files and 23 directories** matched
the candidate, including all file hashes read as the private runtime user and
215 Guide checks with zero database writes and zero provider requests.
The actual twelve-file inverse preserves original bytes, numeric ownership,
modes, nanosecond timestamps and the login ACL. Configuration, six cron files, both timers, other services and the ID tool
pair were preserved. Origin/public login and styles returned 200. Signed-out
root, Guide and devices returned 302.

The exact approved November 5 09:07:17 UTC database-bearing archive was
protected before release. Two independent physical checks passed while the
original remained. Stock retention then removed only that approved original.
All five protected sets and receipts and the other nine originals stayed exact.
The protected copy retains historical recovery bytes. Verification compared
the archive in place. No database restore or migration ran.
See the [cleanup release record](employee-portal-dashboard.md#november-9-cleanup-release-record)
for inverse identity, retention and acceptance boundaries.

Authenticated production navigation was **not witnessed** because no existing
authorized session was available. Acceptance uses exact live runtime/health and the
reviewed-head synthetic desktop, mobile and role evidence. No fresh sign-in,
mutable walkthrough, provider request or customer action was performed.

The natural 3:15 and 3:20 PM report/shared cycles dispatched and drained.
The actual `8west-desktop-cleanup@safeharbor.service` completed successfully,
including its 3:20 PM run, and onboarding succeeded at 3:20:29 PM. The separate
3:20 PM portal-maintenance input-file error and Westy-mail error matched their
pre-release fingerprints. Both remain recorded as pre-existing errors.
At 3:20:32 PM, the four original locks were free, app workers were quiet, and the runtime,
Apache and both timers were active. Shared stock custody closed at
**23:24:23 UTC / 3:24:23 PM PST** after worker technical handback and app-owner
acceptance. At handback on November 9, the executor committed to recovery
reachability through 00:13:43 UTC on November 10 (4:13:43 PM PST on November 9).
That commitment did not extend the release allocation.

### Approved portal Guide published

The preceding application source was **`27f991ee5ff4a033bc4be38ed4462507711af2cd`**,
published by the stock deployment on **November 9, 2026 at 10:44 AM PST**
(18:44 UTC). [PR #295](https://github.com/Seckcey/8_west_helpdesk/pull/295)
publishes the six unchanged Astra/Max-approved articles through the existing
Guide and supplies matching compact references to active Westy instructions.
Employee, viewer and native Owner/Admin selections follow the signed-in role;
delegated access and Companion retain the existing Guide. No action permissions,
models, tools or transport behavior changed.

Exact merged-main PHP/browser CI passed. The single stock release replaced
fourteen files and added two tests; all **420 files and 23 directories** matched,
including runtime-user hashes and 234 DB/provider-free Guide checks. The actual
rollback preserves fourteen original files, exact timestamps and three ACLs and
removes the two additions. Private configuration, six active cron files, both
timers, other services and the installed ID tool pair were preserved. Public and
origin login/CSS checks passed. The separately approved recovery copy passed two
independent original-plus-copy checks before normal retention removed only its
original. All four protected sets and the other nine original backups stayed
exact. See the [Guide release and acceptance record](portal-guide-pilot.md#november-9-production-release)
for the precise customer and runtime evidence boundaries.

The existing signed-in Member saw all five employee articles and opened Westy's
privacy help link without a provider request. Direct Owner browser verification
was unavailable and is not claimed. The 10:45 and 10:50 AM reports and shared
jobs drained normally; new onboarding and desktop-cleanup cycles succeeded.
Known Westy-mail/portal-maintenance input errors matched their earlier baseline
and were not repaired or replayed. Final checks found no retained deployment
process, lock, marker or paused cron file before technical handback.

### Retired Logbook export removed

The preceding application source was **`a33dd91422128fc97afb299ba2c9918c4cb1a737`**,
published by the stock deployment on **November 6, 2026 at 10:42:32 AM PST**
(18:42:32 UTC). [PR #300](https://github.com/Seckcey/8_west_helpdesk/pull/300)
removes the five unused export runtime files, their dedicated test and sample
configuration block. The export was already disabled; private configuration,
keys, service identities and database records were not changed.

Exact merged-main [Validate](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42264765706)
passed. The single stock release deleted six files and replaced four, including
three normal asset stamps. All **418 files and 23 directories** matched the
prepared release, including all file hashes read as the private runtime user.
The ten-file live rollback archive preserves the original bytes, numeric owners,
modes, exact timestamps and both extended ACLs. Private configuration, all six
active cron files, both timers, other PHP/Apache states and the required ID tool
pair were preserved. Origin and public login/CSS returned 200, root/portal
redirected to sign-in, and the removed export returned 404.

Before deployment, the approved November 4 database-bearing recovery archive was
copied to protected recovery. Two independent witnesses matched all eleven
original/copy files while the original remained present. Normal stock rotation
then removed only that approved original; its protected copy, both older protected
sets and the other nine original backups remained exact. This was historical
preservation, not a database restore or a new database backup. See the
[release and recovery record](logbook-export-contract.md#november-6-production-release-and-preservation).
The natural **10:45 AM and 10:50 AM PST** reports recorded zero generated,
submitted, uncertain and error outcomes; lifecycle claims were zero. Shared jobs
dispatched normally, and two new onboarding and desktop-cleanup cycles succeeded.
The existing Westy-mail and portal-maintenance input-file errors remained unchanged
in the actual pre/post samples, including the 10:50 AM maintenance cycle. They were
not repaired or replayed. Final technical handback at **10:50:38 AM PST** found no
retained deployment process, lock, marker or paused cron file.
Signed-in customer acceptance and the separate Guide work remain outside this release.

### Included: shared launcher v0.5.1

The preceding application source was **`48a1790e06b84eb7c540867f92d76dc683b92a16`**,
published on **November 5, 2026 at 9:28:38 PM PST** (November 6, 05:28:38 UTC).
[PR #298](https://github.com/Seckcey/8_west_helpdesk/pull/298) imports the published
w365 v0.5.1 package through the normal sync tool. Logbook and Cloudline are removed
from the display catalog; active launch routes, durable product keys, entitlement
and role behavior, and all seven marks are preserved.

PR and merged-main PHP/browser CI passed, together with 68 focused renderer checks
and eight synthetic desktop/phone role/theme cases. One stock deployment replaced
exactly eight files: four package files, the shipped renderer/pin test and three
normal asset stamps. All **424 files and 23 directories** matched the prepared
release, including file hashes read as the private runtime user. Origin and public
paths served the exact v0.5.1 manifest and five-product catalog; health checks passed.

On **November 5**, the suite coordinator also verified the actual signed-in staff Queue
drawer in a fresh Chrome tab: Control Panel, Coastmark, Milepost and suite home,
with no Logbook or Cloudline. No link or form was submitted. This is limited
staff/operator drawer-display acceptance; owner, employee, invitation, device
and provider acceptance remain open.

Configuration and all six cron files retained their bytes and metadata; both
timers were restored, other recorded PHP/Apache states were unchanged, and locks
and markers were clear. The natural **9:30 PM** report and shared-worker dispatches
ran, reports logged zero generated/submitted/uncertain/error outcomes, and scheduled
lifecycle work reported zero claims. The existing Westy-mail and portal-maintenance
actor input-file errors were identical in the 9:20 PM and 9:30 PM samples; this
release did not repair or replay those jobs.

The new eight-file inverse, both protected historical recovery sets, the retained
#296 inverse and the single qualified source-only retention age-out were verified.
No schema, configuration or permission change occurred. Genuine signed-in
owner/employee acceptance and the separate Guide, Apache and mail work remain open.
See the [launcher release and rollback record](launcher-package-051.md#november-5-production-release).

### Included: retired unused Westy transports

Application source **`019087648a9d5447031bd067baeb4dd9a6303775`** was
verified on **November 5, 2026 at 8:32:24 PM PST** (November 6, 04:32:24 UTC).
[PR #296](https://github.com/Seckcey/8_west_helpdesk/pull/296) removes only the two
unused direct HTTP transports, `portal_westy_provider()` and
`portal_westy_provider_stream()`. The active tenant-AI dispatcher, body builders,
parsers, text buffer, include order and customer behavior are preserved. The
unpublished Guide draft remains separate. See [the runtime path](TENANT_AI.md#chat-usage-and-replay).

[PR Validate](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42159654655)
and [merged-main Validate](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42160581071)
passed both PHP and browser jobs. Offline tests also passed for the retained
helpers, UTF-8 streaming boundaries, catalog, usage receipts and continuation/Cua
behavior, including all 234 Guide checks in a separate draft-composition tree.
No paid-provider or real customer operation was used for this release.

One stock deployment replaced five files: the two transport files and three
asset-version stamps. All **424 files and 23 directories** matched the prepared
release. Origin login/CSS and public login checks returned 200; the protected
portal redirected to sign-in. Configuration and all six cron files retained
their bytes and metadata; both timers were restored. Only Safeharbor PHP restarted.
Apache and the other app runtimes were unchanged; deployment/report locks and
pause markers were clear. No runtime files were added or removed, and no schema,
configuration or permission changes were applied.

The next natural report cycle at **8:35:02 PM PST** logged zero generated,
submitted, uncertain or error outcomes; scheduled lifecycle work also completed
with zero claims. Shared cron dispatches resumed. The separate Westy mail cron
continued reporting that it could not open `cron/westy_mail_poll.php`; the same
error was present every minute in the inspected 8:24–8:32 PM pre-deployment logs.
That existing job issue was reported separately and was not changed or replayed
by this cleanup.

The source inverse retained from this release is
`deploy-state/backups/20261106T043210Z-754c92d06a8f-to-019087648a9d`.
Its 71,680-byte `files.tar` contains exactly the five previous files, with SHA-256
`09f8a1db22a26f59ed86024072f18309cf1272ab4f5dbf6821434bfd78a4e3eb`.
It returns to `754c92d`, retaining the private-link and requester-privacy behavior;
no database restore is part of this inverse.

Before normal retention, the approved historical recovery directory
`20261101T205815Z-d746c193b83d-to-8e888fffa263` was copied intact under
`deploy-state/protected-recovery/`. Independent readback verified all 11 files,
1,548,109 bytes and preserved metadata. Its custody receipt SHA-256 is
`71306736bac8f921c52851926a658089457a4f5ca12420a523809e561f3e660d`.
Stock retention removed only that original stock directory; ten stock backups
remain. The new protected copy and the previously protected October 23 recovery
were verified unchanged after deployment. This preserves historical recovery;
it is not a new current database backup or a restore test.

### Included: private computer links

Private computer-link source **`754c92d06a8fc905a7ed0d11ae9b295ccef90821`**
was verified on **November 5, 2026 at 1:40:04 PM PST** (21:40:04 UTC).
The compatible reader from [PR #291](https://github.com/Seckcey/8_west_helpdesk/pull/291)
was published and verified first; [PR #292](https://github.com/Seckcey/8_west_helpdesk/pull/292)
then switched newly created computer links to `#token=`. The browser fills the
existing confirmation form and clears that fragment. Redemption still requires
the employee's explicit, CSRF-protected submission. Older query links remain
supported. See the [release and rollback record](EMPLOYEE_COMPUTER_ACCESS.md#november-5-private-link-release).

Both exact-main Validate runs passed. All **424** managed files matched at each
stage. Login, stylesheet and served-script checks passed; all six cron files and
both timers were restored, pause markers cleared and deployment/report locks
released. Both immediate stock inverses and the separately protected historical
recovery copy were verified. No schema, application configuration or Apache
change was applied.

The scoped origin and Cloudflare POST-body handling review completed on November 5:
no configured POST-body capture was found on the inspected ingress/origin paths.
This is configuration/source evidence, not a synthetic POST or a claim about
provider-internal handling; revalidate if logging or ingress configuration changes.
Frankie's signed-in owner/employee acceptance remains open before customer
issuance. Anonymous health and synthetic browser tests do not establish that
acceptance. Apache logging PR #290 remains
draft/held after its malformed-request fixture exposed a synthetic query value
in the preserved error diagnostic; it was not installed.

### Included: employee portal simplification

Application source **`abaf228dd0837327d01e556eae5b3d2666390b5b`**
was published on **November 5, 2026 at 12:34:26 PM PST** (20:34:26 UTC) from
[PR #289](https://github.com/Seckcey/8_west_helpdesk/pull/289).
Employees land in Westy, with Your devices, one Support Requests entry and My
settings. Owner/admin management, viewer restrictions, delegated staff and
Companion entry paths are preserved. See the [portal release record](employee-portal-dashboard.md#november-5-release-record).

Exact-main [Validate 42102282535](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42102282535)
passed. One stock publication replaced 15 files, added/deleted none, and all
**423** runtime files matched the prepared release. At 20:35:40 UTC the runtime,
login and stylesheet checks passed; all six cron files and both timers were
restored, with no held deployment lock or pause marker. No schema or Apache
change was applied. Signed-in employee/owner acceptance remains with Frankie;
synthetic browser coverage and anonymous health checks do not establish it.

### Included: hardware staff fallback

Safeharbor installed migration **038** with `072c9b1` on **November 5, 2026 at
1:07:21 AM PST** (09:07:21 UTC). The staff-only sender correction first shipped
as `789eaca0bf922c5aa26789a8b4bb47b053afbff7` at **1:39:55 AM PST**.
The device-owner sender correction below shipped in
**`6e5e34bef61a8c0a82d191cb17ca7aa65885df66`** at **2:32:38 AM PST**
(10:32:38 UTC). All **423** runtime files matched that prepared release;
the correction remains included in the current portal release above.

The normal **1:40:02 AM PST** tick submitted the existing staff notice through
the configured Safeharbor support mailbox. Graph accepted attempt **5** with
HTTP **202**. Connected Outlook search found the matching hardware-options email
to Frankie, received at **1:40:04 AM PST** (09:40:04 UTC). It was unread when
checked. This verifies mailbox receipt, not human review or a hardware repair.

Case **3096** remains open, assigned to Frankie, and human owned at workflow
version **7**. Original hardware notice **1** remains `no_device_contact`.
Separate staff receipt **1** is `provider_accepted`; queue **34** has its normal
`sent_at`. The four earlier definitive Graph 404 attempts remain unchanged.
The original recommendation, missing-contact note and workflow history are intact.
The frozen queue body SHA-256 is
`82163390c1214ac624fd8ae8498a23495a88f37b07bd71032eabceaa9bb88033`.
No queue reset, manual dispatcher call, contact change or device action was used.

See the [staff fallback guide](westy-hardware-staff-notices.md) and
[November 5 release evidence](westy-hardware-staff-release-2026-11-05.md).

### Included: hardware upgrade outcomes and device-linked notices

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
at **19:14:01 UTC**. The device contact lane had zero queued emails or submissions. The ticket remains open and
human owned; its earlier messages, receipts and grants are preserved.

The outbound cron account was corrected to the existing private runtime account,
`eightwest-safeharbor`, at **19:13:25 UTC**. Its normal next two minute ticks ran
without duplicate notes or attempts. Application configuration and permissions,
other cron entries and the two old sent-mail records are unchanged. This acceptance
covers the missing-contact branch; positive-contact delivery and device repair
remain unproved. Other legacy cron identities need a separate impact review.
See the [release evidence and rollback record](westy-hardware-release-2026-11-04.md).

The device-owner sender correction is installed in `6e5e34b`. It selects the
configured support Graph mailbox independently of an optional ticket Reply-To,
while preserving a valid reply alias and the existing unavailable/disabled
support behavior. Six synthetic dispatcher scenarios passed on the exact LF
source bytes, including a lost provider response with no resend. Exact PR and
merged-main checks passed before publication.

The live inventory had zero linked-device notices awaiting delivery, so no
customer test email or new case was created. Postflight verified the deployed
sender, configured support route, health and unchanged staff delivery history.
This is source and synthetic route acceptance; live device-owner delivery remains
unverified. See the [November 5 release record](westy-hardware-staff-release-2026-11-05.md#device-owner-sender-coverage-at-1032-utc).

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
remain included; the shared menu package is now v0.5.1 as recorded above. Genuine signed-in customer and provider
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
| Logbook resolved-ticket knowledge | **Retired 2026-10-19** with Logbook; private export configuration and inactive service identity 6 are retained. The five-file export chain was **removed from production November 6** in `a33dd914`; public export returns 404. [Removal inventory and required ID recovery pair](logbook-export-contract.md#november-6-source-removal-and-recovery-boundary) |
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
keyboard focus and save/error feedback. That release imported shared UI v0.5.0;
the current launcher package is v0.5.1 as recorded above.
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

**New look (draft, not live; [PR #306](https://github.com/Seckcey/8_west_helpdesk/pull/306)).** The 2026-11
visual redesign changes only how pages look: new colour values behind the same `app.css` property names,
a navy rail with the horizon line, one page-width system (1180px lists, 820px forms), restyled shared
components, a two-column ticket page, two-panel sign-in pages, and the portal rail and Home hero. Keyboard
focus is a 2px `--focus` outline, text fields use a 3:1 `--control-border`, and small controls are 44px
tall at 700px and below. The portal's `app.css`, `portal-security.css` and `portal-mobile.css` now load
with content-hash versions (`portal_asset_version()`), and the CSAT page hashes `app.css` the same way.
No word, behaviour, SQL or auth change. Until it is merged and deployed, production still shows the
previous look. Previews: `tools/shots/preview/README.md`.

**Adding people and first steps (draft, not live; builds on PR #306).** Team opens on its list with one
Invite a teammate button (8 West ID) and folds the local recovery form under Backup sign-in for
emergencies. An empty queue shows owners and administrators a Get set up card, and so does a new
customer's portal Home until a computer exists. Staff sign-in starts with an empty email field and puts
Continue with 8 West ID first. No auth decision, role or schema change.

**Request page and customer emails in plain words (draft, not live).** The portal request page shows
the four status words as a step line under the label Status, one panel "What happens next", and times
such as "today at 9:22 AM" in the viewer's own time zone; it no longer shows the service-goal policy
name, version or minutes. The three customer emails (received, answered, resolved) are reworded in
`lib/customer_mail.php` and carry a link to the request only when the portal is on and that client has
an active portal binding; the sentence says owners and admins can use it, because only they can open an
emailed request in the portal. No rule about who is emailed, or when, changed: a request opened in the
portal still has no email contact (`portal_create_ticket()` stores `contact_id` NULL), so its replies
and its resolution are seen in the portal only and it gets no survey. No SQL or auth change.

**Adding a computer as three steps (draft, not live).** Your devices shows Add a computer as confirm,
install and wait, with one filled button; the consent form, its fields and every device-service call are
unchanged. With a setup file on screen the page offers Copy link and asks `devices.php?watch=1` (the
computer list only, as JSON) every 15 seconds for up to five minutes, then names the new computer when it
appears. Each computer shows its state first as a
tag. The two antivirus request forms moved into their own section below the list. Customer-facing
words say setup link, setup file and add. No SQL, auth or service-contract change.

**Portal Home: one question and requests that say what is happening (draft, not live).** Home's hero is
"Hello, {first name}", "How can we help {client} today?" and two buttons: Ask Westy, and New support
request for exactly the roles that may already create a request (`portal_role_can_write_tickets()`, the
Support Requests page's own check). Requests waiting on the customer come first with a Reply button. On
Home and on Support Requests each request is its subject, one sentence and a tag (Received, In progress,
Waiting for your reply, Resolved). `portal_ticket_summary()` now also returns, under its existing tenant,
client and requester filter, the kind and time of each request's newest customer-visible message, its
first-reply time and its reply goal; it reads no message text or author. No permission, SQL schema or
auth change.

**Ticket menus and reply with a next step (draft, not live).** Status, priority and owner are menus
on the ticket page and, from the keyboard, on the highlighted queue row; nothing cycles any more, so
no step passes through Resolved. The owner menu offers Me, Unassigned and every active teammate. A
Resolve button (Reopen once resolved) sits in the ticket head, every change can be undone from its
toast, and Send reply can also set Waiting or Resolved in the same act. A survey is queued only when
the request says a person chose Resolved, and it, like a teammate's assignment email, is held for 60
seconds so Undo can withdraw it (`app/lib/ticket_changes.php`). No schema, role, permission or
database-grant change: a withdrawn survey's `csat` row is marked with a 1970 creation time (so no
business report counts it as sent) and its email stays in `mail_queue`, marked spent with a "Cancelled: ..."
reason, and the survey row is removed only where the runtime account already may remove `csat` rows.
To check on a server after a deploy: resolve a test ticket that has a contact, confirm one unsent
`mail_queue` row whose `next_try_at` is about a minute ahead, press Undo, and confirm that row now
has `attempts = max_attempts` and a "Cancelled" `last_error`, so the dispatcher will never send it.

**Queue views, filters and paging (draft, not live).** The queue opens on Needs attention instead of
on every ticket, with views for Mine, Unassigned, Waiting on client, All open and Resolved, filters for
client, priority and owner, a choice of order, and 50 tickets a page. Everything is a link in the
address, so Back and bookmarks work, and old `?f=` links keep their meaning (every ticket, or one
status). Needs attention holds every unanswered ticket that is late or within two hours of its goal,
whoever owns it, plus unowned tickets and my own where the customer wrote last; a ticket Westy is
working counts as Westy's. A line under the list counts what the view leaves out, with links, and the
page says when the list changed between pages. Rows say who asked and flag a customer reply; the
bulk-delete checkboxes appear only after Select or `x`. Every key does what it did before. Read-only:
no schema, role, permission or grant change (`app/lib/ticket_queue.php`). How late answers are
coloured is unchanged and still to be decided.

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
