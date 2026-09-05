# Safeharbor customer portal contract

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
