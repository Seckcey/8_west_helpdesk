# Safeharbor customer portal contract

Phase 5A adds one deliberately narrow customer surface: a dark/default-off,
read-only list of ticket summaries for one explicitly mapped business. It does
not change Safeharbor's technician authentication or auto-provision any local
tenant, client, user, or mapping.

This contract is the authorization boundary. A later feature may add to it
only through a separate review; it must not reinterpret this slice as implied
permission to expose adjacent help-desk data.

## Ownership and non-goals

Safeharbor owns the portal view, the explicit identity-tenant-to-client
binding, the local portal session, and all ticket-summary reads. 8 West ID owns
OIDC identity, signed tenant/product/role claims, session authorization
generations, and the revocation inventory. The provider tenant/client rows are
Safeharbor facts. No Milepost or Coastmark data is queried.

Phase 5A exposes only:

- ticket number;
- subject;
- status and priority;
- created, updated, and resolved timestamps; and
- counts by ticket status.

It has no ticket detail route and exposes zero messages, internal notes,
attachments, replies, uploads, contacts, assignees, technician time, service
goal internals, AI, billing, invoice posting, device/endpoint control, or other
tenant context. The only POST is CSRF-protected local/central sign-out.

## OIDC authority

The vendored files under `lib/eightwestid/` are exact blobs from the maintained
8 West ID `client-kits/php/eightwestid/` package at ID commit
`f0ec49b6106791d0ba658ca16899562fbe5b3b86`. `portal_auth_test.php` pins every
blob, so a local fork fails CI visibly.

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
to this portal. All four accepted customer roles see the same read-only
summaries for their mapped business.

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
4. summary reads whose SQL binds both provider tenant id and client id.

The identity-slug lookup during callback is the single bootstrap query whose
purpose is to discover the explicitly mapped provider ids. The callback then
immediately repeats it with every exact id before establishing a session.

The revocation inventory is reused for 60 seconds. During an issuer outage the
last valid result may be used only through five minutes; beyond that the portal
returns 503 without serving tenant data. Explicit revocation, a missing
subject, or a different session version destroys the local session. A disabled
or changed binding also destroys it on the next request.

## Dark launch and future controlled canary

Source completion is not authorization to register or expose a client. The
production sequence for a later authorized release is:

1. finish and deploy all earlier migration-first phases, including migration
   011 before 012;
2. back up Safeharbor and apply 012 through the trigger-capable reviewed
   operator path while the web identity remains DML-only;
3. deploy this code with `portal.enabled=false` and verify `/portal/` is 404;
4. register one confidential Safeharbor OIDC client in 8 West ID with the exact
   production HTTPS callback, without committing its id or secret;
5. configure the issuer/client/callback/private shared revocation cache while
   keeping the global switch false;
6. choose one expressly approved canary business, prepare its binding disabled,
   inspect the JSON facts/events, then explicitly enable the binding;
7. set `portal.enabled=true` only for the controlled canary window and verify a
   fresh signed-in session for each relevant client-role class, tenant/client
   isolation, revocation, disable-on-next-request, logout, desktop, and mobile;
8. keep canary monitoring read-only. Do not infer permission for a second
   business.

Immediate rollback is `portal.enabled=false`. Disabling the binding is the
durable per-business kill switch and invalidates an open portal session on its
next request. Do not delete audit rows or hand-edit the deployed tree.

## Required gates

Run from the repository root:

```text
find app -name '*.php' -print0 | xargs -0 -n1 php -l
php app/tests/portal_auth_test.php
php app/tests/portal_data_test.php
SAFEHARBOR_PORTAL_TEST_DB=safeharbor_portal_test php app/tests/portal_mysql_test.php
```

The MySQL suite is destructive only in a database whose name begins
`safeharbor_portal_test`. CI supplies disposable MySQL 8 and runs schema,
migration replay, lifecycle/audit, cross-provider refusal, immutable-history,
client-deletion refusal, and ticket-isolation probes.

No real OIDC client, live mapping, migration, merge, or deployment is part of
the source slice. Those remain explicit operational choices.
