# Client shared directory — CUT1 and CUT2 release receipt

**State: CUT1 ([PR #261](https://github.com/Seckcey/8_west_helpdesk/pull/261)) is merged as
`9d1650adc3bbb8cd20a1a612cabf29f0c6350205` and, by the Safeharbor owner's observation, live.
CUT2, the client-scoped Coastmark Billing link, is source-ready on
`codex/client-shared-directory-billing-cut2` from that main; it is not deployed.** The reviewed
CUT1 application source is `15f7f68b7f7063b4f0487356bb8ec1f9ce1d087f`; later documentation commits
do not change that application evidence. CUT2 owner qualification passed at
`fb7c71867e546952298efd271b3965aa7c4a8d74`; exact-head hosted CI, merge and production release
remain separate gates.

## What changes

CUT1:

| Surface | Change |
| --- | --- |
| Clients list | Each row keeps its local name, domain, SLA tier and open count, and adds the last received Milepost shared name and active/inactive state. "Local to Safeharbor" appears only when the lookup succeeded and found no binding; a failed lookup or a disagreeing receipt shows "Shared details unavailable". With Milepost's current client directory read for the request, **Businesses** lists linked clients Milepost lists as active (with the last received state beside it when Safeharbor lags) and active Milepost businesses not yet received, and **Retained and local** keeps everything else. An unavailable directory lists every client as before and says so; a verified empty one says that instead. Buttons: **Support** (local client page), **Devices** (linked active clients only), **Billing** (Coastmark's existing entry). |
| Client page | A "Shared client" line with the shared name, state, source version and **Shared details** in Milepost, plus the client's current Milepost directory entry or "retained history". The devices card and the Suite rail open the client's own Milepost page for linked active clients; Balance and invoices open Coastmark's existing entry. |
| Edit client | Adds **Open this client's devices in Milepost** beside the existing shared-details button. |
| New client | States that a client created here is local; shared clients come from Milepost. |
| Westy | The prompt names the Clients sections and buttons and the client-scoped Milepost link. |
| Styles | Client-directory styles only; the stylesheet cache token changes with them. |

CUT2 changes only the Billing targets and their wording:

| Surface | Change |
| --- | --- |
| Clients list | **Billing** opens the client's own Coastmark page for each linked client whose binding and immutable receipt agree, inactive and removed history included, and for an active business in this request's verified Milepost directory that Safeharbor has not received yet. Local, unavailable, drifted and malformed rows keep Coastmark's existing entry. Support and the active-only Devices button are unchanged. |
| Client page | For a linked client, whatever its shared state, Balance reads **View this client in Coastmark** and the Suite rail **Review this client's invoices in Coastmark**, both to its own Coastmark page. Every other client keeps Coastmark's existing entry and wording. |
| Westy | The prompt describes the same Billing targets. |

CUT2 has no receiver, schema, styles, login, configuration, cron or permission change, and the
page handlers are unchanged.

The shared name and state come from the existing binding and its matching immutable receipt
(`client_operational_context.php`), never from the editable local name. The directory itself has
no receiver, schema, configuration, cron or permission change. Local fields, privacy rules and
handlers are unchanged, and no new guard is added.

This CUT1 also includes the related backend changes:

- **032** permits exact same-UUID restoration while preserving already-active surfaces.
- **033** adds the per-event admission carrier and immutable receipt field, plus the singleton
  lifecycle scheduling cursor. Existing receipt rows remain untagged.
- **034** adds identity-only restore receipts and the schema-3 ID proof with its restoration anchor.
  The original approved clients qualify without retagging their history. This path never restores
  reports or writes contacts, schedules, accounts, invitations or mail.
- The existing report wrapper runs one bounded `--scheduled` lifecycle child after the reports.
  It preserves the report exit status. Scheduled restoration uses identity-only proof for both
  the approved scope and admitted lineages; the separately run full-contact operation is unchanged.

The [lifecycle contract](managed-customer-lifecycle-contract.md) owns the exact proof, authority,
deadline, migration recovery and one-client Lifestyle invocation. The accepted source form is
retained as history; the one-time P9 runtime completion is recorded below. Do not rerun P9.

## Links and their authority

- **Devices**: `https://support.8westit.com/client.php?provider=<slug>&customer_id=<UUID>`. The
  provider is the binding tenant's own slug. Milepost signs the user in first, then requires that
  slug to be the user's own active tenant and the UUID to be one of that tenant's current clients.
- **Billing** (CUT2): `https://coastmark.8westit.com/clients?provider=<slug>&customer_id=<UUID>`,
  built only by `client_operational_billing_url()`. The slug must be 1–64 lower-case letters,
  digits or hyphens, starting with a letter or digit, and the UUID a lower-case UUIDv4; anything
  else builds no link.
  - **Linked clients.** The provider is the binding tenant's own slug. The route comes with the same
    binding/receipt agreement as the shared name, so a shared inactive or removed state keeps the
    client's accounting-history route.
  - **Pending businesses.** The route comes only from this request's verified directory for this
    provider.
  - **Fallback.** Local, unavailable (failed lookup), drifted (disagreeing receipt) or malformed
    state opens Coastmark's existing suite entry, `https://coastmark.8westit.com/auth/suite`, where
    the user selects the client. Another tenant's clients never appear, and another provider's
    directory is unavailable.
  - **Authority.** Coastmark itself requires the real staff session, organization, Clients
    permission and its own audited binding. The link grants no authority.
  - **Before CUT2.** CUT1 opens the existing entry for every row.
- **Support**: the local Safeharbor client page.
- **Canonical Support target** (source candidate, A48/A51). Other suite apps link to
  `https://safeharbor.8westit.com/client.php?provider=<slug>&customer_id=<UUID>`.
  - **Resolver.** Staff sign-in comes first. The provider only asserts the signed-in user's own
    tenant slug; it never selects a tenant. The UUID must have exactly one binding in that tenant
    whose immutable receipt agrees, and it then opens that client's normal page, retained inactive
    history included.
  - **Refusals.** A malformed, reordered, duplicated, encoded or mixed (`id=`) query, another
    tenant's UUID, an ambiguous or drifted binding, or an unknown UUID gets the same 404 page. A GET
    writes nothing. Numeric `?id=` and the contact POST handlers are unchanged.
  - **Login return.** `login.php` validates `next` before its current-user and 8 West ID branches, so
    an existing or newly admitted staff session returns to it. The customer-role GET handoff still
    goes only to `/portal/`.
  - **8 West ID button.** For a canonical target only, **Continue with 8 West ID** becomes the fixed
    `https://id.8westit.com/launch.php?product=safeharbor&return=<encoded target>`. ID signs the user
    in, refreshes the suite cookie and returns to this `login.php?next=<target>`. Every other `next`
    keeps the plain `https://id.8westit.com` entry.
  - **Release gate.** This must be deployed and checked, with ID's matching launcher, before any
    consumer shows a Support link.
- **Directory read**: Milepost's `suite-client-directory-v1` with this request's verified suite
  cookie, as described in the [customer-sync contract](milepost-customer-sync-contract.md). It is
  never stored, and it decides only the Businesses / Retained and local presentation.

## Release order

1. Complete the documentation review and normal exact-head CI/merge gates. Deploy only the matching
   committed main release, through the release lead's existing protected procedure and packet.
2. Milepost's provider + UUID device route and `suite-client-directory-v1` read must be live and
   checked before the Safeharbor links and directory are released. The matching ID launcher and
   Safeharbor Support destination must be live before another app publishes the new Support link.
3. In the protected Safeharbor window, apply **032 → 033 → 034, database first**, then deploy the
   matching source. These are all part of this CUT1; there is no directory-only fallback in this
   release. Use the existing `deploy/deploy.sh --migrate` procedure for each exact archived SQL file
   and its documented replay/postflight checks. Never replay 015 after 033, or 024/032 after 034.
4. Keep scheduled restoration off until ID's schema-3 identity purpose and its exact customer
   authority are live and the release lead opens the reviewed restoration window. Applying a
   schema or merging source does not enable that purpose or authorize a restore.
5. Use the accepted **one-client Lifestyle packet**, not the global `--scheduled` run, for P9.
   Fill and verify its protected current tuple and actual preserved-active 0/0 state first.
   The Milepost v3-first order, both frozen senders, exact original SH/ID v2→v3 events and
   nonce-armed UNKNOWN/readback/recovery rules remain controlling. No invitation or financial
   operation belongs to this step.
6. Verify the actual Safeharbor release and relevant user paths, recording runtime and genuine
   signed-in/provider/customer evidence separately from the fixture evidence below.
7. Coastmark's resolver/writer publication and all eight preserved profiles/bindings are a separate
   release-lead stage. Only after its destination is live and verified may the later, narrowly
   scoped Billing CUT2 be prepared on actual merged main. The lead reports that gate green (see
   the production record below), and CUT2 is now prepared on merged main `9d1650ad`. It ships
   only after owner qualification and normal CI/merge, in the lead's serialized source-only
   runtime slot, with no SQL.

The existing runtime configuration, protected jobs, release ownership and approved release packet
remain controlling. This page does not authorize a cron/configuration change, a broad lifecycle run,
account provisioning or a customer/financial action.

## CUT1 source qualification

The Safeharbor owner executed these existing suites on immutable committed source in isolated
Coastline containers. Relevant unchanged input bytes were reused across later documentation and
fixture-only commits; the corrected pure fixture was rerun at `15f7f68b`.

| Check | Result |
| --- | --- |
| Lifecycle, identity evidence and scheduler PHP suites | 101 / 90 / 25 passed |
| Lifecycle MySQL 8.0.46 | 197 passed, including 034 apply/replay/interruption/parity, NULL checks, original unmarked scope, anchor matching, authority races and deadline deferral |
| Customer-sync MySQL 8.0.46 | 88 passed, including 033 interruption/replay and immutable admission |
| Existing Linux report wrapper | 20 passed, including child refusal/timeout and report exit-status preservation |
| Actual-page synthetic renderer / existing Playwright test | 73 renderer checks and 16 browser cases passed |
| Mobile regression control | New layout assertions reject the old CSS in all eight mobile cases; its eight desktop cases pass |
| Shared operational context / staff-login HTTP fixture | 137 / 62 passed |

The 24 captured images cover 1440×1000 and 390×844, light/dark, current/empty/unavailable/wrong-provider
directories and business/retained details. The mobile overlap was corrected and visually reviewed.
Billing was generic in CUT1, and keyboard Support plus synthetic Devices/Billing destinations were checked.

The renderer lifts auth/bootstrap and uses SQLite, a synthetic directory transport and synthetic link
destinations. Captures show the app's inner-scroll viewport, not every scroll position. These checks
do not establish protected normal CLI/runtime behavior, real ID/provider sessions, form saves or
Frankie's customer acceptance. Hosted CI belongs to the actual PR head; its status is recorded on
[PR #261](https://github.com/Seckcey/8_west_helpdesk/pull/261).

## CUT2 source status

The Safeharbor owner qualified immutable application/test source
`fb7c71867e546952298efd271b3965aa7c4a8d74` on an isolated Coastline copy. These existing tests
assert the CUT2 targets:
- `app/tests/client_operational_context_test.php`: the exact route, malformed slug and UUID
  refusals, the route in the agreeing binding/receipt context (inactive included), and no route
  for a drifted receipt, an unreadable or malformed tenant slug, or a failed lookup.
- `app/tests/client_shared_directory_ui_test.php`: each actual-page row's own Billing target for
  every directory case, and the business, retained, inactive Support-target, local and drifted
  client pages.
- `tools/shots/client-shared-directory.test.mjs`: the same row targets in the browser, the exact
  synthetic Coastmark destinations and popup URL, and the client-page links. Its existing mobile
  geometry, keyboard/focus, theme and console/error assertions are unchanged.

Owner results: **154 operational-context checks, 93 actual-page renderer checks and all 16 browser
tests passed**, each with native exit 0 and no skipped or cancelled browser tests. All 505 tracked
Git blobs matched before and after the run. The owner reviewed the 14 changed screenshots; the
other 10 of 24 captures were byte-identical to previously reviewed captures. Desktop/mobile and
light/dark captures showed no new layout finding.

The fixtures use synthetic authentication, SQLite records, directory responses and destination
pages. Captures show the inner-scroll viewport, not every scroll position. These results do not
establish real signed-in/provider/customer acceptance or form-save behavior. The full unchanged
PHP and browser workflows must pass on the exact final PR head, followed by normal merge and
default-branch CI. Production remains CUT1 until the lead's serialized source-only runtime slot.
The canonical source archive SHA-256 is
`ae1af16a4c6ef46a61b9cb11d7c6da50d1328c1564f0481641ce4bc26619f837`; the qualification evidence
archive is `acf650180f86cfe3ef50466edd11d4e2e4d53649a09ccd9bff2df7bd8d2cc657`.

## Production record (owner and lead observations)

These are runtime and signed-in observations reported by the Safeharbor owner and the release lead.
They are not results of this repository's tests.
- **CUT1.** `9d1650ad` is live. Migrations 032 → 033 → 034 passed and all 393 runtime files
  matched. Public login returned 200 with its expected title at `2026-10-23T20:07:25Z`.
- **P9.** One corrected act ran (exit 0), giving receipt 1, portal 0 and report 0. All five anchor
  checks were true, and the physical-state aggregate was unchanged. See the
  [lifecycle contract](managed-customer-lifecycle-contract.md) for the pre-act exit 255 and the
  single `require_once` correction.
- **Valley.** 8 West ID tenant 18 / transition 14 / OAuth 1; Safeharbor client 24 / binding 12 /
  receipt 19. Both were normal HTTP 200 deliveries.
- **Coastmark gate for CUT2.** Coastmark is healthy on `623` (image `653`). Three real principal
  UI Adds made CM8, CM9 and CM10, with audits 406–408. CM3 stays inactive, with one r4 non-link
  audit (409). All 90 financial hash/count groups were unchanged. Real signed-in GETs for all
  eight approved `8west` UUIDs each resolved the linked client.

## Existing verification commands

- `php app/tests/client_operational_context_test.php` and the existing customer-sync, lifecycle
  and identity-evidence suites in the normal CI jobs.
- `php app/tests/managed_customer_lifecycle_test.php`, `php app/tests/managed_customer_lifecycle_scheduler_test.php`
  and, on a disposable MySQL server, `php app/tests/managed_customer_lifecycle_mysql_test.php`
  (024 + 032 + the scheduled mode + 034) and `php app/tests/suite_customer_sync_mysql_test.php` (015 + 033).
- `sudo bash app/tests/business_report_wrapper_linux_test.sh` for the wrapper's lifecycle child.
- The browser job, including `node tools/shots/client-shared-directory.test.mjs`, and a visual check
  of the Clients list on desktop and narrow screens.
