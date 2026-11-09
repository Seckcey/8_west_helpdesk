# Portal Guide pilot

The six approved articles are published in `/portal/guide.php`, with matching
compact references in the current Westy instructions. Application source
`27f991ee5ff4a033bc4be38ed4462507711af2cd` went live on November 9, 2026 at
**10:44 AM Pacific** through the normal stock deployment.
The scoped real signed-in Client Owner/Admin and employee acceptance was
completed on November 9, as recorded below. The protected recovery copy and both
independent custody checks also completed that day before normal rotation of the
approved original. Synthetic tests do not establish real-account acceptance.

The November 5 and 6 entries below preserve the earlier preparation status.
Their pending real-acceptance and publication statements are superseded by the
November 9 records; they are not additional unresolved walkthrough requirements.

## Content and applicability

The immutable approved input is
`safeharbor-kb-pilot-final-v3-astra-max-20261105.md`, SHA-256
`12450997caf825a4bee2d26e61636b7cd01f086a80a678021ec8fa662fff2e68`.
The source/application basis is `754c92d06a8fc905a7ed0d11ae9b295ccef90821`;
the integration starts from documentation-only main
`089178bb8312d058b6944dc58d11bcae08dfa3c0`. No customer article prose is rewritten.
Only approved titles, audience labels, purpose/prerequisites, steps, expected
results and help paragraphs enter the shared structured catalog. Authorship,
source, release and publication notes remain internal to this document and the
review packet, outside the Guide and provider instructions.

| Authenticated context | Approved articles in order |
| --- | --- |
| Direct employee/member (`client_staff`) | E1 sign-in, E2 assigned computers, E3 request, E4 follow/reply, E5 private-link redemption |
| Direct viewer (`client_viewer`) | E2, E4, E5 |
| Original native `client_owner` or `client_admin`, no `customer_access` overlay | E3, E4, E5, O1 issue/manual-share link |
| Delegated customer access or Companion session | Existing Guide |
| No context (legacy Guide callers) | Existing Guide |

The original article author confirmed E5 may be read by native owners as an
employee reference. Its **Employee** heading and full prerequisites remain
unchanged, and it appears above O1 so O1's existing cross-reference is accurate.
This does not make owners eligible to redeem employee links. Existing action
authorization is unchanged. E3 expressly includes owners/admins; E4 explains
their client-wide request visibility.

`portal_guide_articles($context)` selects help from the authenticated original
identity and server Companion state. URL/query/body role claims are ignored.
This selection grants no permissions and adds no action guard. The Guide and
active Westy instruction builders receive the same article IDs and order,
including the initial request-size calculation and each provider round.
`portal_guide_westy_articles()` keeps the existing concise explanations and
adds only exact pilot titles, audience/application labels and Guide anchors.
The expanded approved bodies remain on the Guide page; serializing them into
every model request would consume too much of the existing byte allowance.
No new summary prose is authored. A captured Companion
context remains on the existing Guide even if the current session marker is
absent. No model, tool, billing, recovery or transport behavior is changed.

## Existing routes and compatibility

The existing account and Support Requests Guide links are reused. There is no
new navigation entry, search service, framework, database or feature flag.
The `devices`, `requests`, `updates`, `response`, `summaries`, `privacy` and
`contact` anchors remain. E2 has its own `assigned-computers` anchor so the
existing computer-check diagnostic material is retained. E3/E4 replace the
corresponding short entries only for their applicable audiences. Other legacy
service, privacy and contact content remains unchanged.

The unused legacy closed guidance body/parser was subsequently removed after
verifying that saved replies and drafts are read directly from persisted fields
(see [chat, usage and replay](TENANT_AI.md#chat-usage-and-replay)). Historical
Guide source IDs remain available. The active tenant-AI request path passes its
authenticated context explicitly. The older streaming body builder accepts
optional context with the same legacy default.

## Verification and publication handoff

`app/tests/portal_guide_test.php` reconstructs approved Markdown from structured
paragraphs/ordered steps and checks exact title/body SHA-256 values extracted
from the immutable input. It also covers audience selection, escaped markup,
metadata exclusion, unchanged legacy sections/source IDs, current instruction
builders and ordinary request size with real tool definitions and history.
The existing MySQL Westy test checks the authenticated owner selection at the
actual injected provider boundary, without calling a paid provider.

`tools/shots/portal-guide.test.mjs` executes the actual Guide controller and
real portal renderers with synthetic authentication. It covers employee,
viewer, owner, admin, delegated, overlaid owner and Companion contexts at
1440×1000 and 390×844, request-to-Guide/contact paths, legacy anchors, role-query
tampering, GET-only/anonymous entry, unchanged navigation, overflow and console
health. All network responses are fixture-controlled.

Validation results and screenshot locations are supplied with the draft PR.
Container verification uses only an isolated, temporary Coastline kit under
the canonical slot and existing heavy mutex; no Docker Desktop or production
browser is used. No real account, invitation, ticket, mail, private link or
device operation is performed.

November 5 synthetic qualification completed at **6:47:56 PM PST**
(November 6, 02:47:56 UTC). All 234 Guide checks and all 14 role/viewport flows
passed on the final presentation candidate. Desktop/mobile screenshots were
inspected; Guide-only ordered-list padding and bold-label weight correct the
global reset's effect on the approved formatting. The existing time, Mac,
desktop diagnostic and continuation/Cua suites also passed. The first hosted
PHP job passed, including actual provider dispatch; final exact-head CI is
recorded in the PR. Two browser-fixture defects (a missing synthetic sign-in
helper and an incorrect assumption about the direct customer's existing Guide
link) were corrected without changing application behavior.

With a 2,000-character first message, real base+Cua definitions serialize to
25,747 bytes on the legacy Guide, 26,505 for employees and 26,376 for owners.
Base+terminal/recovery is 23,777 / 24,535 / 24,406 respectively. The compact
Guide adds 758 / 495 / 629 bytes for employee / viewer / owner. Ordinary base
tools plus six 500-character user/assistant history pairs use 22,115 employee
bytes and 21,986 owner bytes, below the existing 28,000-byte trimming threshold.
The combined tool-definition fixture already exceeds the ordinary 32,000-byte
limit on the unchanged baseline; this integration does not change that limit
or claim to repair that pre-existing condition.

Final Coastline test source tree:
`9e88a7326522f0b360045d337d093dd00040199a` (subsequent documentation records these
results). Final run log SHA-256:
`131540994648af7cad92da344362fc8b4b5467ce4398d9ace3407c39cc671ac3`.
Eight synthetic PNGs and logs are retained outside the repository with the
review handoff. The task's container and temporary kit were removed, no volumes
or ports were created, and both canonical test locks were released and checked.

The current internal suite record supersedes the older release document's
open origin/Cloudflare status: configured POST-body review is complete for the
inspected paths. Actual redemption and signed-in owner/employee acceptance
remain separate and pending. Apache PR #290 and other held work are unchanged.
The draft must not be treated as publication or as evidence that these checks
passed. Invitation-label alignment is a separate change; any resulting
customer article wording update belongs to the original article author.

## November 6 readiness refresh

The draft now includes Safeharbor main
`e1cfba387610f5f09d7c8e87a8de1d526843f2a0`, including the retired transport removal
and launcher v0.5.1. The merge preserves all six approved article hashes and
audiences. The only overlapping implementation file is `portal_westy_stream.php`;
its optional authenticated Guide context remains, and the retired transport
functions remain absent. Full normal Validate runs on the refreshed draft head.

Source comparison also covers the reported serving Settings releases: 8 West ID
`1027430bd94a4424a3dcc9b71c5053121bdc2054` and Milepost
`49f1fee9ca85f090a1fd367bfeb761f5d1e45a85`. ID presents People under Settings while
retaining `users.php` and its `employee-invitations` anchor. Safeharbor's native
Owner/Admin invitation shortcut and all article-facing employee/private-link
labels remain unchanged. Milepost's Settings navigation does not change the
computer-assignment workflow. No approved customer wording change is needed.

These comparisons are source evidence, not a new production probe or real-account
walkthrough. The existing owner/employee acceptance requirement remains: correct
business and computer, manual sharing, signed-in redemption, signed-out sign-in
and reopening the original link, resulting assigned access, and revocation.
Synthetic controller, browser and injected-transport checks establish only their
fixture boundaries; they do not establish live ID sign-in or Milepost assignment.

The later November 6 walkthrough-preparation refresh includes main
`ebbc99edf1391d0cb0eaf1b7926307e4392b91f9`, preserving PR #300's retired export
removal and PR #301's release/recovery records. The recorded deployed application
is `a33dd91422128fc97afb299ba2c9918c4cb1a737`; the intervening main changes are
documentation only. The Guide candidate adds no schema, configuration or service
change. Actual publication still requires the walkthrough evidence, final merged
source/CI and a fresh release-owner check of the live source, jobs, locks and
rollback/retention state. No production preflight or deployment is implied by
this isolated preparation.

An existing ID account invitation is distinct from a Safeharbor computer-access
link. An unused computer-access link is one-use: choose the already-signed-in or
signed-out/sign-in/reopen path before redemption. A single successful first use
does not establish both paths. Preserve any unexercised case explicitly; do not
create another account or link merely to fill an evidence gap. Keep private codes
out of shared notes and screenshots.

## November 9 scoped real-account acceptance

The suite coordinator reported the completed 8 West Ventures walkthrough to the
Safeharbor integration owner. The native Owner identity was independently
established by the ID owner. Frankie attested that the exact test computer's link
was displayed in the native Owner session, shared privately and redeemed manually
in the separate test Member session. These issuance, sharing, redemption and Owner
status steps are human-attested; they are not claimed as new Safeharbor operator
browser observations. The coordinator directly observed the Member's computer
list change from empty to the intended computer, then return to empty after
Frankie confirmed revocation of that exact Owner record. No device command or
repair was performed.

One authorized, labelled support request, **#3108 — Guide walkthrough test
2026-11-09**, was created at **10:14 AM Pacific**. The coordinator verified it in
the employee list. After staff set the request to Waiting through the normal
workflow, the employee saw **Waiting for your reply** and sent one authorized
reply at **10:17 AM Pacific**. The saved confirmation and both conversation
messages persisted, and the status returned to Open. Staff verified the reply
and resolved the same request through the normal workflow. A fresh employee view
showed Resolved, both messages readable and no **Send reply** button. No note,
assignee, priority or unrelated-ticket change was part of this test.

This accepts E1's actual Member entry, business and landing; E2's observed empty,
assigned and revoked visibility; E3/E4's request, reply and resolved experience;
and E5/O1's selected signed-in private-link lifecycle within the evidence above.
It does not claim live viewer execution, an unavailable-device service response,
or a second fresh redemption. Frankie selected the completed signed-in path plus
the existing automated signed-out/sign-in/reopen coverage; that alternate path
remains **automated-only**, not a second real-account success. No second grant is
required by this recorded acceptance disposition.

The six Astra/Max-approved titles, bodies and audiences remain unchanged. This
acceptance record does not itself merge or deploy the Guide. The exact
`20261104T132518Z-7be64ab4fb69-to-3f3b128ab508` historical recovery copy and two
independent original-plus-copy checks must precede normal rotation of only that
approved original. Final source, CI, rollback and live release verification remain
with their existing integration and release owners.

The approved copy-only operation subsequently completed once on November 9 at
**10:24:11 AM Pacific**. The worker's separate readback and two independent
owner checks matched all eleven original/copy files and their metadata, with
distinct copy inodes. Both independent checks occurred while the original
remained present. The three earlier protected recovery sets and receipts were
unchanged. The new custody receipt SHA-256 is
`2ecad7310fbb466b0ef35601bac0dc567f35443ca23c3ad05dd5f6aa3a08d3ff`.
Root-only execution evidence was retained and copy-only technical custody was
handed back. This operation did not deploy the Guide, rotate a backup, restore
data or change application/runtime settings. Normal rotation of only the exact
approved original was subsequently performed by the separately qualified stock
release below.

## November 9 production release

[PR #295](https://github.com/Seckcey/8_west_helpdesk/pull/295) merged as
`27f991ee5ff4a033bc4be38ed4462507711af2cd`. Its tree exactly matched the reviewed
PR head. Exact merged-main [Validate](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42675388815)
passed both PHP and browser jobs. The gap from serving source
`a33dd91422128fc97afb299ba2c9918c4cb1a737` to the preceding main contained only
three factual documentation changes; the application delta was the reviewed
Guide work and the three normal asset stamps.

The single stock preflight ran at **18:43:37–18:43:41 UTC**, followed by one
stock deployment at **18:43:54–18:44:00 UTC**. Both returned zero. The release
replaced fourteen files and added two tests, with no deletions, directory,
schema, private-configuration or cron changes. All **420 files and 23
directories** matched the prepared release; every file was also read and hashed
as the private runtime user. All fourteen replacement owners, groups, modes and
ACLs were preserved. The two new tests inherited the reviewed neighboring
permissions; all 404 unchanged files retained their complete metadata.

The actual inverse is
`20261109T184357Z-a33dd9142212-to-27f991ee5ff4`. Its fourteen preimages match
the physical originals in bytes, numeric owners, modes, nanosecond timestamps
and all three extended ACLs. Its addition-removal list contains exactly the two
new tests. This was a no-migration release: no database dump or restore occurred.
Normal retention removed only the approved `20261104T132518Z` original after
the two independent preservation checks above. Its protected copy, all three
older protected sets and receipts, and the other nine original backups remained
exact. The required installed ID onboarding tool pair remained unchanged; any
whole-host recovery must restore and verify that pair before resuming onboarding.

Private configuration, six active cron files, both timers, other PHP services
and Apache states were unchanged after the deployment resumed its jobs. Origin
and public login, app CSS and portal CSS returned 200; anonymous root and Guide
requests redirected normally. No paused cron file remained. The deployed
DB/provider-free Guide test passed all **234 checks** as the runtime user,
including the six approved title/body hashes, employee/viewer/native Owner/admin
and overlay selection, and matching compact Westy instruction indexes. No
provider request, new grant, ticket, device command or database write was made
by these publication checks. Native Owner UI was unavailable to the supported
browser tools; runtime role fixtures do not constitute a fresh live Owner view.

The suite coordinator directly verified the existing signed-in test Member in
8 West Ventures after publication. **Account → Portal guide** opened the real
Guide with the five employee article titles and their expected anchors:
`sign-in`, `assigned-computers`, `requests`, `updates` and
`private-computer-link`. The Owner-only article was absent. Westy's existing
**Private to you** help link opened `/portal/guide.php#privacy` with the correct
heading and the same Member identity. No provider prompt or new authentication,
ticket, grant or device action was used. Native Owner publication coverage is
the deployed catalog/role evidence above, separate from the earlier ID-verified
and human-attested Owner link lifecycle.

The shared release lead observed the natural **18:45 and 18:50 UTC** reports:
zero generated, submitted, uncertain and error outcomes, with zero lifecycle
claims. Due shared jobs and portal workers dispatched and drained normally. Six
new onboarding cycles completed successfully after publication, including the
18:50:27 cycle; desktop cleanup also succeeded at 18:50. The existing Westy-mail
and first post-release portal-maintenance input-file error fingerprints matched
the pre-release baseline. They were not repaired or replayed, and no new scoped
report/shared-job error appeared.

Final worker checks at **18:50:28–18:50:29 UTC** found the expected live source,
four original mutexes unheld, no deployment marker, no retained stock/copy
process and no paused cron file. The release lead independently verified active
timers and health. Technical custody was handed back after these observations
and Member acceptance. The root-only preservation evidence remains retained.
This documentation closeout changes no application bytes and requires no
additional production deployment.
