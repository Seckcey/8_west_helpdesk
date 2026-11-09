# Customer portal Home and the Westy route

## Direct customer browser

An employee who signs in as a member lands in Westy at `/portal/`. The sidebar
offers Westy, Your devices, Support Requests and My settings. Your devices uses
the existing Milepost-filtered list. Westy continues to use its existing private
workspace and device chooser. The `/portal/?view=westy` link still works.

Support Requests groups the recent list, status counts and items waiting for a
reply. Writers use **New support request** there. Viewers keep read-only access.
Client owners and admins find their service summaries under Support Requests
and retain client-wide Home, People, billing and computer management. The
original ticket, form, archive and device URLs remain available under their
existing access checks. The delegated staff chooser and Companion workspace
keep their existing navigation and entry paths.

For a new employee, the client owner or admin uses People in 8 West ID to set up
the account. The separate Computer access link grants the signed-in employee
access to the selected computer. For an employee who already has an account,
the owner or admin can share the computer link directly. 8 West ID enforces
invitation authorization.

The published navigation cleanup removes only the redundant
Account shortcut to Your devices. Owners and admins use **Your devices → Computer
access** on the intended device card. The contextual **Manage computer access**
action on Home and all existing role checks remain unchanged.

## November 9 cleanup release record

[PR #303](https://github.com/Seckcey/8_west_helpdesk/pull/303) and
[PR #304](https://github.com/Seckcey/8_west_helpdesk/pull/304) were published together
as `25c14565f76836b8c2fa76e115a8dcaf7c5dc709` on November 9, 2026 at
**23:13:50 UTC / 3:13:50 PM PST**, replacing `27f991ee5ff4a033bc4be38ed4462507711af2cd`.
One canonical check and one stock deployment completed successfully. No retry,
migration, private configuration change or Apache operation occurred.

The exact merged-main [Validate 42686491635](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42686491635)
passed PHP and browser checks. The scoped navigation fixtures preserve primary
Your devices, the per-device Computer access action, Home's contextual action
and role behavior while removing the Account alias. Reviewed desktop/mobile
fixtures are synthetic evidence. Authenticated production navigation remains
**unwitnessed** because no existing authorized session was available. No new
credentials, sign-in or mutable walkthrough was used. The accepted production
basis is the exact deployed source, runtime verification and signed-out health,
alongside those synthetic role/layout checks.

Postflight verified all 419 files and 23 directories, including reads and hashes
as runtime UID 1602, and 215 shipped Guide checks with zero database writes and
zero provider requests. Eleven replacement files retained their permissions,
ownership and ACLs. The 408 unchanged files retained their full metadata, and
the obsolete module was absent. Private configuration, six cron files, both timers, other service
states and the installed ID tool pair matched the preflight. Public and origin
login and styles returned 200. Root, Guide and devices redirected signed-out users.

The new immediate source inverse is
`deploy-state/backups/20261109T231346Z-27f991ee5ff4-to-25c14565f768`.
Its `files.tar` SHA-256 is
`004c6d4772925d69bb4677ce02e4d32a631850e68f0ebbdede1909942d161b8b`.
Independent verification matched all twelve original files (eleven replacements
plus the deleted module), exact bytes, numeric owners/modes, nanosecond mtimes
and the login ACL. There are no additions to remove and no database snapshot
in this no-migration inverse. No archive extraction or restore was performed.

Before stock retention, the separately approved original
`20261105T090717Z-3f3b128ab508-to-072c9b19551f` was copied into
`deploy-state/protected-recovery/` with its custody receipt. Both independent
physical witnesses matched original and copy while the original was present.
Only that original aged out in normal retention. Its protected copy and receipt,
all four prior protected sets/receipts and the other nine original stock backups
remained exact. The protected copy retains historical recovery bytes.
Verification compared the archive in place. No current database backup or
restore test was performed.

The naturally scheduled 23:15 and 23:20 UTC report/shared work dispatched and
drained. The actual desktop-cleanup target, `8west-desktop-cleanup@safeharbor.service`,
completed successive post-release runs including 23:20. Onboarding succeeded at
23:20:29. The first post-release ten-minute portal-maintenance observation at
23:20 and the scheduled Westy-mail runs retained their missing-input fingerprints.
Both remain recorded as pre-existing errors. No repair or replay was performed.
The lead's 23:20:32 readback found all four original locks free, no app cron
processes, active FPM/Apache/timers and quiet paired services. Worker technical
handback was confirmed with no retained worker resource claim. The retained
root custody evidence was left untouched. Shared stock custody formally closed
at **2026-11-09 23:24:23 UTC / 3:24:23 PM PST** after app-owner acceptance.
At handback on November 9, the executor committed to recovery reachability
through 00:13:43 UTC on November 10 (4:13:43 PM PST on November 9). That commitment
did not extend the release allocation.

## November 5 release record

[PR #289](https://github.com/Seckcey/8_west_helpdesk/pull/289) was published as
`abaf228dd0837327d01e556eae5b3d2666390b5b` on November 5, 2026 at
**20:34:26 UTC / 12:34:26 PM PST**, replacing `6e5e34b`.
Exact PR Validate `42100994634` and
[main Validate 42102282535](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42102282535)
passed. The merge tree matched reviewed head `0b98db17`.

Coastline qualification passed 41 focused browser cases, 300 focused PHP
assertions and 39 actual HTTP assertions with a synthetic MySQL fixture.
Desktop 1440px and mobile 390px captures were inspected. Coverage includes
employee root/index/Westy alias entry, requester privacy, the single request
creation action and viewer refusal. These are synthetic checks, not a customer
sign-in or a live computer-link redemption.

One stock check and one stock deploy replaced 15 files (eight application UI
files, four packaged tests and three normal cache stamps), with no added or
removed file. All 423 installed file hashes matched the prepared release.
Postflight at **20:35:40 UTC** verified login, stylesheet HTTP 200, anonymous
portal HTTP 302, active PHP runtime and Apache, restored six cron files and two
timers, unchanged file metadata, and released locks with no pause/cold markers.
Only Safeharbor's PHP runtime was stopped; no Apache operation or migration ran.
The shared host window was handed back early. Signed-in employee and owner
acceptance remains with Frankie; no customer, mail or device action was submitted.

The new source inverse is
`/srv/8west/apps/safeharbor/deploy-state/backups/20261105T203423Z-6e5e34bef61a-to-abaf228dd083/files.tar`
(245760 bytes, SHA-256
`787b5b72ed78eea445d222925257dac47bdc87ff7b5e1aebd9729f6af1ec201d`).
All 15 archived original files and their ownership/modes were verified, with
exact from/to identities. Routine retention removed only the reviewed
`20261023T063103Z-c79cf5949165-to-a63dc4ee43dc` source inverse; ten remain.
The distinct `20261023T200121Z-a63dc4ee43dc-to-9d1650adc3bb` source/database
recovery was protected and retained. Before the subsequent private-link release,
its exact 11 files were copied with their metadata to
`/srv/8west/apps/safeharbor/deploy-state/protected-recovery/20261023T200121Z-a63dc4ee43dc-to-9d1650adc3bb`.
Independent readback of both copies verified every hash and recorded permission,
owner, timestamp and distinct inode. Safeharbor, the shared-host release lead and
the suite owner accepted the durable mapping. The protected copy is outside stock
rollback discovery and retention; immediate release inverses remain in `backups`.
The sibling `.custody.json` receipt has SHA-256
`594e53eaa19ebccdf4d6174ff4aab72e472d4ad8a34743fb38c173947ffd9988`.
Historical sealed references retain their original paths and use this mapping.
This preserves the accepted historical recovery bytes; it is not a new database
capture, restore test or current-system recovery claim.

The local sealed `safeharbor-employee-portal-publication.json` receipt has
SHA-256 `d0afca49ca6ca0f2bd88abf2fa4d2eb4cc371747e6f7315586b754f56c1fbed2`.
It records the stock output, before/after metadata, 423 runtime hashes and
inverse verification. Private-link transport, invitation permissions and
Apache logging are separate work, not evidence supplied by this UI release.

## October 22 release record

The sections below describe the original Home release. See the
[release receipt](client-access-portal-release.md) for deployment evidence.

## What changed

- `/portal/` is now **Home**: the signed-in person's own overview.
- The Westy workspace moved to **`/portal/?view=westy`**. Only the exact value
  `westy` opens it; any other `view` value, or none, shows Home. The workspace
  itself is unchanged: `portal_render_workspace()` renders the same
  `#portal-chat-home-slot` mount, so streaming, Stop, receipts, chat history and
  the device choice work without a script change. The Companion still renders
  the workspace at `/portal/desktop.php`.
- The sidebar lists **Home** first, then Westy, Your devices, Support requests and,
  for client owners and administrators only, Service summaries. Westy is marked
  current whenever the workspace is rendered, whatever the URL.
- "Ask Westy" on the Support requests page opens `/portal/?view=westy`. The
  "← Help center" back links on ticket, new-request and summary pages now read
  "← Home" and still go to `/portal/`.
- The sidebar Account block keeps every link and role condition. Portal guide and
  Contact support are grouped under a divider, and Sign out is a secondary-style
  button in the same CSRF-protected POST form.
- `login.php` (staff sign-in): on a GET with no staff session and no
  `reauth=1`, a browser whose 8 West ID suite cookie verifies with a
  `client_owner`, `client_admin`, `client_staff` or `client_viewer` role **and**
  the `safeharbor` product is sent to the fixed `/portal/`. Before this change a
  customer who opened the ordinary Safeharbor address was left on the staff
  password form. No `next`, token, cookie or query value is carried over. The
  portal then runs its own 8 West ID sign-in, which rechecks product, role,
  client binding and revocation. Staff sessions, `reauth=1`, POST and
  local-recovery sign-in, and missing or invalid tokens behave as before.

## What Home shows

Home reads data only through the existing helpers, with the signed-in identity:

| Data | Source | Who sees what |
|---|---|---|
| Requests and counts | `portal_ticket_summary(…, 6, identity)` | Members and viewers see only requests recorded as theirs. Owners and admins see the whole client. Counts are the helper's totals. The list shows up to 6. |
| Computers | `portal_devices_request(…, 'devices', ['after'=>0])` | Milepost decides the list from the signed role and subject. Home shows the first 3. |
| Latest service summary | `portal_report_archives(…, 1, identity)` | Client owners and administrators only. |

After those reads the controller rechecks the signed-in context, as
`devices.php` does. If the session was revoked or the binding changed, nothing
is shown and the person is asked to sign in again.

Sections:

- **Welcome**: the person's name, the selected client, their role
  (`portal_role_label`) and one sentence stating what they can see.
- **Start here** buttons: Ask Westy (`/portal/?view=westy`); Write a request
  (only for roles that can write tickets, so never viewers); Your devices; and My
  settings in 8 West ID (`https://id.8westit.com/settings.php`, the existing
  fixed destination).
- **Needs your reply**: requests waiting for the customer, with the true total.
  When the total is larger than the list it says "Showing X of Y". Viewers see
  "Waiting for a reply", because they cannot reply.
- **Recent requests**: active requests first, then recently resolved ones, with
  the four status totals and a link to every request.
- **Your computers**: name, connection state and last check-in.
- Client owners and administrators only: **Service summaries** (latest archive)
  and **Manage {client}** (Client Admin and Plan and billing in 8 West ID, and
  Computer access). These links use the same `portal_client_admin()` condition
  as the sidebar.
- **Need a person?**: Contact support, plus the existing privacy sentence.

### Empty, unavailable and partial states

- No requests: "No support requests yet." For writers it adds a pointer to Westy
  and to writing a request.
- Requests could not be read: "We can't show your support requests right now."
  The page offers Try again and, for writers, Write a request. It never shows
  zero.
- No computers: members are told none is assigned yet and to ask an owner or
  administrator. Owners and administrators are told none is connected yet.
- Computers could not be read, for any reason: "We can't show your computers
  right now. Nothing about them has changed." The page links to Your devices. It
  never shows "no computers".
- More than one page of computers: "More than N computers" and "See all
  computers".
- Summary archive could not be read: "Service summaries are temporarily
  unavailable."

## Accessibility and layout (source estimates)

- Actions are links styled as buttons, at least 64 px tall on phones and 76 px on
  desktop. Card buttons are at least 44 px. Sign out stays a real `<button>`.
- Keyboard focus uses the portal's existing 2 px cyan outline.
- At 390 px the page is one column. At 1440 px the requests sit beside a
  computers and help column.
- Contrast is estimated from the `app.css` theme tokens, not measured in a
  browser:

  | Pair | Dark | Light |
  |---|---|---|
  | Body text on a card | 13.8:1 | 15.4:1 |
  | Hint text on a card | 8.2:1 | 6.2:1 |
  | Links on a card | 6.2:1 | 4.8:1 |
  | Primary action (navy on blue) | 5.7:1 | 5.7:1 |
  | Focus ring against the page | 10.5:1 | 4.0:1 |

  Status text on Home uses the strong muted colour. Gold, mint and blue appear
  only as status dots, because light-theme gold text on white is about 4.5:1.

## Evidence and its limits

- The renderer checks in `app/tests/portal_settings_test.php` and the browser
  checks in `tools/shots/portal-settings.test.mjs` and
  `tools/shots/portal-contract.test.mjs` (Home at 390 and 1440, member, viewer
  and owner/admin) use **synthetic fixtures only**. The authoring worker did not
  run them. On October 22, 2026 (Pacific), the Safeharbor owner qualified source
  `71b66a830a0d9e0429f10ece36e57441272a3d82` on Coastline with patched Ubuntu PHP
  `8.3.6-0ubuntu0.24.04.13`: all nine changed PHP files passed lint, the six
  focused PHP suites passed 415 checks, the three real-MySQL suites passed 95,
  and the full portal-contract and portal-settings browser suites passed 22
  and 20 tests respectively. Auth, login, ticket-workflow, requester-privacy,
  employee-device and customer-access coverage is included in those results.
  Both required GitHub checks (`php` and `browser`) also passed at that source.
- The owner regenerated the test archive with explicit LF settings and verified
  all 497 archived files against their Git blobs before the complete rerun.
  Earlier CRLF-converted exports are retained only as behavioral evidence.
  Fresh desktop and mobile captures show the inline Westy trigger below the
  help card without covering its text. These synthetic checks and captures do
  not establish production sign-in, provider behavior or customer acceptance;
  genuine signed-in acceptance remains with Frankie.
- Home calls Milepost for the device list on every visit, with the existing
  12-second limit. A slow or failed Milepost read delays Home or shows the
  unavailable state; it never blocks the Westy route.
- Not changed: authentication, sessions, roles, the requester filter, device
  authority, Westy execution, the database schema and configuration.
- Fixed: "Open your Westy conversation" in
  `app/lib/portal_device_operations_render.php` now links to
  `/portal/?view=westy`, not Home.
