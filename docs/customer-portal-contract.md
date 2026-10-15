# Safeharbor customer portal contract

**Plan and billing navigation (source change; release pending):** The existing
customer sidebar, including the Westy workspace and mobile menu, links to
`https://id.8westit.com/customer-billing.php`. Customers can review their plan
and find the existing trial or subscription cancellation there. The link sends
no customer, offer or tenant selector and starts no Checkout. ID rechecks its
own signed-in customer: members retain read-only billing, authorized owners
retain the existing actions, and existing obligations remain accessible when
new sales are off. Staff delegation in Safeharbor does not become billing
authority for the selected client. Portal devices, requests, guide, history and
sign-out remain available. This source change does not activate billing or
record production/customer acceptance.

**October 3 sign-in return repair:** [automatic OIDC handoff and safe page
return](customer-portal-login-return.md) defines the entry, deep-link and retry
behavior. It preserves the identity/customer authorization boundary below;
production acceptance is recorded separately after deployment.

**Phase 1 implementation under review (October 2, 2026):**
[Private Westy and reviewed request handoff](customer-portal-phase1.md) documents
the new customer shell, subject-scoped chat, additive migration 031, retention,
budgets and activation gates. This does not change the production acceptance
record below until a separately verified release is recorded.

**Current status, rechecked 2026-09-05 UTC:** the useful portal is deployed and
enabled for active binding 1, ID tenant `8-west-lifestyle`, provider tenant 1 /
client 14. Recorded fresh signed-in acceptance proved ticket groups, details,
new request, reply, archived-report access, and cross-client refusal. The
Phase 5B/usefulness stack described below has merged in the aggregate release.
See [current status](where-things-stand.md) and
[customer journey operations](customer-journey-operations.md).

## Historical pre-aggregate checkpoint

The next paragraphs preserve the earlier summary-only portal checkpoint;
their unmerged/source-only statements do not describe the current deployment.

**Status:** migration 012 and the reviewed Phase 5A source are live through
PR #68 merge `7bf63edb9bc0583cf865124b45f002d0717dcab9` after exact-main
Validate run `33226867525`. A confidential Safeharbor OIDC client is installed through the
protected operator path without recording its values here. The global portal
gate is enabled, protected config SHA-256 is
`e272ccc44f8200774530fabda634b3d65032805ef360d6f73da3a91afc3fb0f4`, and
binding id 1 actively maps the expressly approved 8 West Lifestyle identity
tenant to Safeharbor provider tenant 1/client 14.

Signed-out acceptance passed on the canonical
`https://safeharbor.8westit.com/portal` surface. The separate
`support.8westit.com/portal` path is 404 and is not a portal alias. On
2026-08-29 Frankie completed a fresh Lifestyle sign-in and confirmed the
authenticated portal opened for the intended customer. That closes the basic
OIDC/customer-display proof, but Frankie correctly rejected the read-only
summary surface as not useful. The deployed surface remains summary-only.

The reviewed Phase 5B source is in draft PR #88. Draft PR #89 adds the exact
versioned-report archive contract beneath it. The stacked
`codex/customer-portal-usefulness-v2-20260830` branch turns those safe building
blocks into a useful customer home: action-first ticket groups, a clear
waiting-for-customer reply flow, and read-only access to fully reverified
weekly service-summary archives. None of those three draft layers is merged or
deployed. They require green review plus explicit release approval before the
live portal changes.

This contract is the authorization boundary. A later feature may add to it
only through a separate review; it must not reinterpret this slice as implied
permission to expose adjacent help-desk data.

## Ownership and non-goals

Safeharbor owns the portal view, the explicit identity-tenant-to-client
binding, the local portal session, ticket reads, and immutable weekly report
archives. 8 West ID owns
OIDC identity, signed tenant/product/role claims, session authorization
generations, and the revocation inventory. The provider tenant/client rows are
Safeharbor facts. No Milepost or Coastmark data is queried.

Deployed Phase 5A exposes only:

- ticket number;
- subject;
- status and priority;
- created, updated, and resolved timestamps; and
- counts by ticket status.

It has no ticket detail route and exposes zero messages, internal notes,
attachments, replies, uploads, contacts, assignees, technician time, service
goal internals, AI, billing, invoice posting, device/endpoint control, or other
tenant context. The only POST is CSRF-protected local/central sign-out.

Phase 5B and the stacked usefulness slice add only these customer-owned
operations:

- `client_owner`, `client_admin`, and `client_staff` may open a ticket for the
  exact mapped provider tenant/client; `client_viewer` remains read-only;
- every new ticket is `channel=portal`, `status=open`, unassigned, has no
  inferred contact, captures its immutable effective response-goal target,
  and starts with a `kind=client` message. An admitted long 8 West ID display
  name is deterministically shortened to a 128-character author label rather
  than blocking that customer from writing;
- ticket detail shows only `kind=client` and `kind=tech` messages. Internal
  notes and system/automation evidence never cross the portal boundary;
- merged source stubs and every survivor with a merged source are hidden from
  the dashboard, detail, and reply paths. Staff merges now lock both ticket
  rows and refuse different customers before moving any conversation. Before
  release, the Lifestyle canary data must be audited/reset so no legacy merge
  history is trusted;
- customer replies are allowed only while a ticket is not resolved. A reply
  returns `waiting` work to `open` for human attention and never resolves it;
- the dashboard separates `waiting` work that needs a customer reply, open or
  in-progress work, and recently resolved work, with an obvious new-request
  action. These are presentation groups over the existing closed ticket field
  list, not new workflow authority;
- `/portal/reports.php` is GET-only. It reads only the exact signed-in provider
  tenant/client's Safeharbor report definition and archive rows. Before any
  customer sees an archive, it rechecks the supported immutable definition,
  canonical JSON and text, content SHA-256, tenant/client source keys, period,
  and generation timestamp. It shows aggregate ticket, response-goal,
  approved operational-time, and CSAT facts plus the escaped exact archive;
  and
- every mutation requires the portal session CSRF token plus a short-lived,
  one-use action nonce. The consumed nonce is durably written and the session
  lock is closed before the database mutation, followed by POST/Redirect/GET.
  An uncertain response tells the customer to inspect the dashboard or thread
  before retrying instead of falsely claiming that nothing was saved.

The useful portal still exposes no attachments, individual technician time or
rates, contacts, assignees, recipients, delivery attempts, AI controls,
billing details, invoice posting, device/endpoint control, internal notes, or
system evidence. The weekly aggregate explicitly says approved operational
time is not invoice or posting status. The portal provides no customer
close/resolve operation. Portal
tickets and replies therefore remain human-owned and cannot gain Milepost's
one-use telemetry auto-close capability.

## Protection request (source only)

This section is source on `claude/T-20261013-04-sh-protection-request`. The
deployed Safeharbor runtime (2c) does not include it, and nothing here records
a deployment or customer acceptance.

**Audience.** Only the account's own owner or admin (literally `client_owner`
or `client_admin`), signed in with their own account. Delegated customer
access, `client_staff` and `client_viewer` see no form. The proxy refuses them
with 403 `owner_request_refused` before any service call; the page says only
the account owner or an admin can send a request.

**Form.** Your devices → Protection request. The choices are the
server-returned device references on the current page, shown by their escaped
labels: the computer, then "A new computer" or "The same computer as an earlier
record" plus that earlier record. The browser never chooses the provider,
customer, identity realm, subject, session or role.

**Wire.** The existing signed `safeharbor-customer-devices-v1` envelope, action
`protection_request`, with the server-derived scope. The input has exactly four
required keys:

- `device_reference`: an opaque `[1-9][0-9]{0,9}:[a-f0-9]{64}` reference;
- `request_key`: 32 lowercase hex characters;
- `computer`: `new_computer` or `same_computer`;
- `same_as_device_reference`: always present. It is JSON `null` for a new
  computer, and a different earlier opaque reference for the same computer.

A missing or extra key, a wrong type, an unknown `computer`, an inconsistent
null/string pair, a self-reference, or a malformed reference or key is refused
with 400 locally, and nothing is sent. Milepost remains the authority for the
reference, the earlier record's current alias, the tenant and the customer. The
scope is rechecked after the transport. The result must be exactly `reference`
(32 hex), `computer` (equal to the one sent) and `state` (`recorded` or
`superseded`); anything else is refused as `service_unavailable`. Milepost's
409 `beneficiary_changed` (the earlier record is linked to a different person)
is shown with a neutral message that names no code, role or authority.

**Recovery.** The page keeps one request per portal session: its original
server key and its four inputs, bound to the signed-in subject, session version
and binding. They are stored as the transport starts, after every local check.

- If no confirmed result arrives, the page says so and offers "Retry the same
  request". The retry resends that key and those inputs only: the browser sends
  no key or input for it. There is no automatic retry and no new key.
- Milepost replays a matching key with the same reference and state. It refuses
  changed choices under that key (409 `owner_request_changed`). The page also
  refuses a reused key with different choices before sending.
- A GET reload never POSTs. It shows only the unconfirmed state, or the answer
  received when the request was sent, labelled "Earlier answer, not checked
  again".
- Sending the form again is an explicit new intent with a distinct server key.
  Other portal work is not blocked by an unconfirmed request.
- A destroyed portal session loses the retry. A later new request then follows
  Milepost's one-current-request-per-computer supersession.

**Meaning.** The page tells the customer, in plain words: "Sending this request
does not install protection or change your bill." A saved answer is shown as an
earlier answer that was not checked again: the request "was saved", or "a newer
request … replaced this one". The wire states stay `recorded`/`superseded`; the
copy uses no ledger vocabulary and makes no staff promise. A request does not
install Bitdefender, create a provider job, enroll a computer, take a payment,
or prove entitlement, a license or hardware identity, and it grants no
installation authority.

- The tenant admin/technician reviewed INSTALL path and
  `portal_devices.security_orders_enabled` are unchanged.
- Milepost's `customer_protection.enabled=false` and empty keys gate benefit
  INSTALL admission and start, not this form.
- Safeharbor adds no flag, schema or key. The existing portal, device,
  diagnostics, security and mobile flags are unchanged.
- This request records the requesting owner/admin's subject. Naming an
  employee is the separate employee request below; this form sends no employee
  field.

**Release and rollback.** The Milepost backend comes first: its owner-request
storage, authenticated `protection_request` dispatch and identity compatibility
must be verified before this consumer is deployed or exposed. A later release
uses exact reviewed green main through the established release/report manager,
with a working inverse captured from the current 2c runtime, config and report
controls. Rollback reverts this Safeharbor action first, separately contains
Milepost benefit starts, and retains the immutable request tables and history.
It involves no license cancellation, uninstall or receipt erasure.

## Employee protection request (source only)

This section is source on the same branch. It consumes the joint Small
Business Essentials contract that Milepost implements (producer
`6813c442`). The deployed Safeharbor runtime (2c) does not include it, and
nothing here records a deployment or customer acceptance.

**Audience and authority.** The same gate as above: the account's own owner or
admin, never delegated access, staff or viewers. The requesting owner/admin and
the chosen employee stay separate people. The buyer or owner is never assumed
to be the employee: an owner is the employee only if Milepost lists that person
and the owner explicitly chooses them. The list comes from Milepost, which asks
8 West ID privately. Safeharbor has no ID caller or key of its own.

**Flow.** Your devices → Protection for an employee:

1. Choose a computer and select "Show employees". This sends
   `employee_protection_choices` with exactly `device_reference` and
   `cursor` (JSON `null` for the first page).
2. The page lists at most 20 employees, in Milepost's order, after an explicit
   blank "Choose an employee". Nobody is preselected and there is no
   first-member default. "More employees" sends the original `next_cursor`
   back unchanged, so members beyond 20 are reachable. The page shows when
   the list stops being usable.
3. Choose the employee, "A new computer" or "The same computer as an earlier
   record" (plus that record), and send. This sends
   `employee_protection_request` with exactly five keys: the four Family keys
   plus `employee_reference`.

Display names are escaped text, never identity. Employee references and cursors
are opaque signed tokens (three base64url parts, at most 3072 bytes). They are
relayed exactly and never decoded; signed is not encrypted.

**Wire.** Same envelope, scope and post-transport recheck as above.

- Choices result: exactly `employees`, `next_cursor`, `issued_at` and
  `expires_at`.
  - At most 20 entries, each exactly `display_name` (non-empty, at most 190
    UTF-8 bytes, no control characters) and `employee_reference`.
  - No duplicate references; integer clocks with `expires_at` after
    `issued_at`.
  - A `next_cursor` only after a full page of 20.
- Request result: exactly `reference` (32 hex), `computer` (equal to the one
  sent) and `state` (`recorded` or `superseded`).
- `employee_protection_status` sends the same five original facts. Its result
  is that receipt, or a proven absence: exactly `reference: null`, the
  original `computer`, and `state: absent`.

Missing, extra or malformed inputs, including a blank employee, are refused
locally with 400, and nothing is sent. Milepost's
`employee_selection_unavailable`, `employee_selection_stale`,
`employee_selection_refused`, `beneficiary_changed` and
`owner_request_changed` are shown with neutral messages.

**Size.** The test sends the largest five facts (10-digit device references
and a 3072-byte employee reference). It asserts that the signed body stays
within the unchanged 4096-byte request limit; larger bodies are still refused
before sending. Milepost and ID bound a full page under the unchanged
131072-byte response limit, and Safeharbor's transport still refuses anything
larger. Nothing is truncated and no limit changed.

**Recovery.** The page keeps one employee request per portal session, in its
own slot beside the Family one. It stores the original key and five facts,
bound to the signed-in subject, session version and binding, as the transport
starts.

- **No confirmed result.** The page offers only "Check request status". It
  sends `employee_protection_status` with the stored five facts; the browser
  sends none.
- **A saved answer.** It is shown as an earlier answer, not checked again.
- **A proven absence.** The page offers "Send the same request again", which
  sends the same key and five facts. If the employee list has expired,
  Milepost refuses, and the customer shows the employees again and chooses
  again. That is an explicit new request with a new key.
- **Changed facts under the stored key.** They are refused before sending,
  and Milepost answers 409, never absence.
- **No automatic action.** There is no automatic POST, retry, key rotation or
  reference swap. A GET reload sends nothing.
- **A new choice.** Choosing again is an explicit new intent with its own key.
  It replaces the stored one; there is no blanket guard.

**Meaning.** Choosing an employee gives them no access to the account or the
computer, and no role, invitation or installation authority. A request installs
nothing and changes no bill. The tenant admin/technician reviewed INSTALL path
remains the only way to an installation; Milepost rechecks the beneficiary
there. The literal owner/admin checks, delegated-access refusal, tenant, CSRF
and INSTALL boundaries are unchanged. Safeharbor adds no flag, schema, key or
role.

**Release and rollback.** The order is:

1. ID's member-selection service;
2. Milepost's attribution storage and the three dispatch actions, verified;
3. this consumer.

Each needs its own release admission. Rollback removes the employee exposure
first. It keeps the Family request and Milepost's immutable request and
attribution history, with no license cancellation or erasure.

**Evidence.** `app/tests/portal_devices_mysql_test.php` covers:

- **Proxy and render contracts.** The real binding schema with a captured
  transport, for the Family action and all three employee actions: actors,
  exact wire, results, pagination, size, refusals and copy.
- **The actual controller and session.** The real `app/public/portal/devices.php`
  runs under PHP's built-in HTTP server, with the real bootstrap, revocation,
  binding and CSRF checks and real session files. Only the disposable copy's
  outbound Milepost transport is replaced, by a recorder (the
  `portal_stream_http_test.php` pattern). For Family and employee requests it
  shows:
  - a first unknown outcome stores the original key and facts;
  - an explicit retry or status check sends exactly those facts;
  - a GET reload sends no protection POST;
  - a CSRF failure, locally invalid input, changed facts under the key or a
    changed principal sends nothing.

  This is not Milepost, ID or live signed-in acceptance.

`tools/shots/portal-contract.test.mjs` renders the real PHP UI. Its responses
are mocked with `route.fulfill`, so it proves the rendered forms, picker
paging, submitted fields and reload behavior, not the controller or session.

## OIDC authority

Every vendored file under `lib/eightwestid/` is pinned by exact Git-blob digest
in `portal_auth_test.php`, so any unreviewed drift fails CI visibly. `jwt.php`
and `revocations.php` are the exact maintained 8 West ID
`client-kits/php/eightwestid/` blobs at ID commit
`6cc31c71f9b1f9ac63218c932531ea885237f470`. The other package files
originated at `f0ec49b6106791d0ba658ca16899562fbe5b3b86`.

The issuer prerequisite through `ed49db95` is deployed in production. The
maintained-kit revision above is the reviewed client update for this release
and changes no client, role, or grant by itself. The later controlled
activation registered one production Safeharbor client and moved its values
only through protected server configuration. The replacement
Lifestyle customer identity `t4u10` is enabled; predecessor `t4u7` is
permanently inactive, revoked, and quarantined. The root-only quarantine
receipt is
`/srv/8west/backups/identity-quarantine/20260829T011053Z/quarantine-receipt-20260829T015737Z.txt`
with SHA-256
`56ace0529b430e0f227d7ce9983976d8f1bf45840d05dab0c1aefd583b8e1c04`.
Coastmark merge `c9f44096af80a0a97b8415e3f4037c88867ecfab` is deployed live and adds the separate
fail-closed fresh-revocation check before its suite SSO may write or reactivate
anything. Financial counts were unchanged, its Safeharbor-time receiver remains
off/404, and mappings, imports, and draft lines remain zero; it does not give
the portal billing access.

The confidential authorization-code flow is the maintained `oidc_v1` contract:

- issuer exactly `https://id.8westit.com`, enforced by production code (dev/test
  URLs remain injectable);
- discovery `/.well-known/openid-configuration` and JWKS
  `/.well-known/jwks.json`;
- authorization `/oauth/authorize.php`, token `/oauth/token.php`, and
  client-authenticated revocation inventory `/oauth/revocations.php`;
- exact registered HTTPS callback, PKCE S256, transaction state + nonce,
  `client_secret_basic`, RS256, and scopes `openid profile email`;
- exact product entitlement `safeharbor` and role contract `suite_roles_v1`;
- exact accepted application roles `client_owner`, `client_admin`,
  `client_staff`, and `client_viewer`; and
- fixed central logout `<issuer>/logout.php` after local-session destruction.

The kit validates the broader suite role vocabulary because other applications
use it. Safeharbor then applies the closed four-role customer gate above.
Staff, MSP, viewer aliases, recovery roles, and unknown roles are not admitted
to this portal. All four accepted customer roles may read their mapped
business. Only the three explicit customer writer roles above may create or
reply; `client_viewer` fails closed as read-only.

The maintained issuer kit still validates its full signed identity-assurance
contract, including verified email, subject/numeric-tenant consistency, and
bounded presentation claims. Safeharbor does not use email, domain, display
name, avatar, preferences, or the informational numeric `8west:tenant_id`
claim to choose a binding or grant local portal access. That local decision
uses immutable `sub`, the exact `8west:session_version`, the normalized
`8west:tenant` slug, exact product, and exact client role.

## Explicit binding lifecycle

Migration `012_customer_portal.sql` creates no mapping. The CLI is the only
application writer and accepts numeric Safeharbor provider tenant/client ids,
an active provider owner/admin actor, the normalized identity tenant slug, and
an operator reason. It never accepts or infers email, domain, client name,
`source_key`, or a numeric identity tenant claim.

The sequence is always separate commands:

```text
php db/manage_portal_client.php prepare --identity-tenant=example-co --provider-tenant-id=1 --client-id=42 --actor-user-id=7 --reason="Prepared for controlled review."
php db/manage_portal_client.php inspect --binding-id=9 --identity-tenant=example-co --provider-tenant-id=1 --client-id=42
php db/manage_portal_client.php enable --binding-id=9 --identity-tenant=example-co --provider-tenant-id=1 --client-id=42 --actor-user-id=7 --reason="Exact mapping inspected and approved."
```

The example is fictional. Do not reuse its ids or create a mapping merely to
test a deployment.

`prepare` can only insert `disabled`. `inspect` requires the binding id plus
every expected slug/provider/client fact. `enable` and `disable` repeat those
facts and are idempotent on an already-matching state. A globally unique
identity slug maps to exactly one provider tenant/client, and that exact
provider tenant/client may have only one identity mapping. Blank, malformed,
non-normalized, duplicate, cross-provider, `8west`, and `internal` mappings are
refused. `portal.reserved_identity_tenant_slugs` can add a future centrally
established reservation without replacing the fixed set.

The database enforces a composite `(tenant_id, client_id)` foreign key,
active owner/admin actors, disabled preparation, immutable binding facts,
explicit status transitions, no binding deletion, and immutable lifecycle
events. Disabling never deletes history. Any binding history intentionally
prevents deletion of its client; do not bypass that boundary with foreign-key
checks disabled.

## Local session and active-request checks

The portal uses the fixed `safeharbor_portal` PHP session cookie scoped to
`/portal`, independent from Safeharbor's technician session. Production
requires `Secure`, `HttpOnly`, and `SameSite=Lax`. The server-side session
stores the exact subject/session-version pair, identity tenant slug, exact
client role, binding id, provider tenant id, client id, display name, and an
absolute issued/expiry pair. It cannot exceed eight hours.

Every authenticated request performs, in order:

1. strict local-session shape and absolute-expiry validation;
2. 8 West ID revocation/authorization-inventory validation;
3. an active binding recheck using the exact binding id, identity slug,
   provider tenant id, and client id; and
4. every ticket summary/detail/create/reply and weekly archive list/detail
   operation binding both provider tenant id and client id.

The identity-slug lookup during callback is the single bootstrap query whose
purpose is to discover the explicitly mapped provider ids. The callback then
immediately repeats it with every exact id before establishing a session.

The revocation inventory is reused for 60 seconds. During an issuer outage the
last valid result may be used only through five minutes; beyond that the portal
returns 503 without serving tenant data. Explicit revocation, a missing
subject, or a different session version destroys the local session. A disabled
or changed binding also destroys it on the next request.

## Controlled canary state

Steps 1–7 below are complete. Step 8 has only signed-out routing acceptance;
its fresh authenticated checks and step 9 remain open:

1. finish and deploy all earlier migration-first phases, including migration
   011 before 012;
2. back up Safeharbor and apply 012 through the trigger-capable reviewed
   operator path while the web identity remains DML-only;
3. deploy the Phase 5A source and PR #48 logout gate with
   `portal.enabled=false`; verify `/portal/`, `/portal/login.php`,
   `/portal/callback.php`, and both GET and POST `/portal/logout.php` are 404
   without `Set-Cookie`;
4. deploy the reviewed 8 West ID prerequisite through `ed49db95` without
   creating a client, assigning a role, or changing a grant;
5. install the non-secret disabled scaffold: fixed issuer and callback, empty
   client fields, and a private persistent empty revocation cache;
6. register one confidential Safeharbor OIDC client in 8 West ID with the exact
   production HTTPS callback, without committing or displaying its id or
   secret, then transfer those values through the protected operator path while
   the global gate remains false;
7. choose one expressly approved canary business, prepare its binding disabled,
   inspect the JSON facts/events, then explicitly enable the binding;
8. set `portal.enabled=true` only for the controlled canary window and verify a
   fresh signed-in session for each relevant client-role class, tenant/client
   isolation, revocation, disable-on-next-request, logout, desktop, and mobile;
9. keep canary monitoring read-only. Do not infer permission for a second
   business.

The gate and binding are currently enabled only for the approved Lifestyle
canary. Signed-out canonical routing and a fresh authenticated Lifestyle
session passed; the deployed summary-only experience was rejected as not
useful. The remaining data-isolation, revocation, disable-on-next-request,
logout, and desktop/mobile checks must be repeated after Phase 5B is deployed.
Immediate rollback is `portal.enabled=false`. Disabling the binding is
the durable per-business kill switch and invalidates an open portal session on
its next request. Do not delete audit rows or hand-edit the deployed tree.

The root-only portal activation record is
`/srv/8west/backups/safeharbor/20260829T021900Z-pre-lifestyle-portal-canary`.
Its portal-canary receipt SHA-256 is
`4e6d81f955f582171cea5f78c7ea98f92923e288ef6863c925fa157cfa1a6efc`.

## Required gates

Run from the repository root:

```text
find app -name '*.php' -print0 | xargs -0 -n1 php -l
php app/tests/portal_auth_test.php
php app/tests/portal_data_test.php
php app/tests/portal_ticket_workflow_test.php
php app/tests/portal_report_archive_test.php
node tools/shots/portal-contract.test.mjs
SAFEHARBOR_PORTAL_TEST_DB=safeharbor_portal_test php app/tests/portal_mysql_test.php
```

The MySQL suite is destructive only in a database whose name begins
`safeharbor_portal_test`. CI supplies disposable MySQL 8 and runs schema,
migration replay, lifecycle/audit, cross-provider refusal, immutable-history,
client-deletion refusal, and ticket-isolation probes.

Migration 012, the source, disabled-route hardening, confidential-client
registration, protected client-value installation, one disabled-then-enabled
Lifestyle binding, and global enablement are live. Signed-out routing is
accepted on the canonical Safeharbor domain and refused as 404 on the support
domain. Fresh Lifestyle authentication passed on 2026-08-29. Phase 5B's
create/detail/reply experience and the stacked grouped-dashboard/verified-
archive experience remain source-only until review, release, and fresh
authenticated desktop/mobile acceptance pass.
