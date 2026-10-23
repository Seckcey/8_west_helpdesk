# Customer portal Home and the Westy route

**Status:** source change on branch `claude/T-20261022-01-client-access-portal`
(base `597f39e`). Not deployed. No signed-in customer, browser or live acceptance
has been recorded for it.

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
