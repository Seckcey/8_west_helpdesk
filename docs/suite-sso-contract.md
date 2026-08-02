# 8 West ID — Suite SSO Contract (Phase 0 draft)

One identity for the whole suite: **Milepost**, **Coastmark**, **Safeharbor**.
A user signs in once with **8 West ID** and lands in any product with the same
tenant context. This document is the contract each app implements against.

## Protocol

OpenID Connect (authorization code flow + PKCE). No implicit flow, no
password grant, ever.

| Setting | Value |
|---|---|
| Issuer | `https://id.8westit.com` |
| Authorization | `GET /oauth2/authorize` |
| Token | `POST /oauth2/token` |
| UserInfo | `GET /oauth2/userinfo` |
| JWKS | `GET /.well-known/jwks.json` |
| Scopes | `openid profile email tenant` |

## Claims

| Claim | Type | Meaning |
|---|---|---|
| `sub` | string | Stable user id across the suite |
| `email` | string | Sign-in email |
| `name` | string | Display name |
| `8west:tenant` | string | **Tenant id** — the MSP account; binds every record in every product |
| `8west:products` | string[] | Licensed products, e.g. `["milepost","coastmark","safeharbor"]` |
| `8west:role` | string | `owner` · `admin` · `tech` · `readonly` |

## Rules

1. **Tenant resolution is claim-driven.** Apps never ask "which MSP are you
   with?" after sign-in — `8west:tenant` decides. A user belonging to
   multiple MSPs picks at 8 West ID, and the claim reflects the choice.
2. **Product gating is entitlement-driven.** `8west:products` controls which
   suite apps accept the token (Safeharbor rejects a token lacking
   `"safeharbor"` with a friendly "add Safeharbor to your suite" screen).
3. **Tokens are short-lived** (15 min access, rotating refresh). Apps must
   tolerate 401 → silent refresh without losing in-flight work (the UI is
   optimistic; sync retries in the background).
4. **Sign-out is suite-wide** (front-channel logout); signing out of one
   product signs out of all three.
5. **Deep links carry context, not credentials.** Cross-product links
   (`Milepost device → Safeharbor ticket`) pass ids only; the receiving app
   re-authorizes with its own token.

## Phase 0 status

`app/src/lib/sso.ts` stubs `beginSuiteSignIn()`; `app/src/lib/session.tsx`
stub-persists the resolved user. The data model is already tenant-scoped
(`tenantId` on every entity), so swapping the stub for the real OIDC client
is a one-module change.

## Phase 1 status (2026-07-22) — JWT cookie implementation LIVE

Phase 1 ships a lighter transport than full OIDC: 8 West ID
(**https://id.8westit.com**, repo `8_west_id`) issues a signed **HS256 JWT**
in an HttpOnly cookie scoped to `.8westit.com` after password sign-in.
Claims mirror this contract (`sub`, `email`, `name`, `8west:tenant`,
`8west:products`, `8west:role`) plus **`8west:theme`** and
**`8west:avatar`** for suite-wide user settings. Each app verifies the
cookie with the shared secret (server configs only) and starts its normal
local session; unknown users are auto-provisioned (8 West ID is the master
user list).

| App | SSO login | Settings sync | User mapping |
|---|---|---|---|
| Safeharbor | live (`lib/auth.php suite_sso_attempt`) | live (theme + avatar) | email |
| Milepost | live (`portal/lib/auth.php`, `suite_sso.enabled` kill-switch) | live (theme + avatar) | username == email local-part |
| Coastmark | pending (Laravel guard; Microsoft auth stays fallback) | comes free with the guard | email |

Phase 1 deliberately defers to full OIDC: authorization-code + PKCE,
rotating refresh tokens, suite-wide front-channel logout (rule 4 — apps'
local sessions currently persist until their own logout/expiry), and the
`8west:tenant` multi-MSP picker (single tenant `8west` today). The claim
shape is stable, so the upgrade is issuer-side, not app-side.

## Phase 1 field notes (2026-08-02) — read this before debugging a sign-in

Written after tracing "I can't log into all the apps with the same user, and
they all have different sign-in pages" across the live suite.

### The OIDC section above is a plan, not the system

Everything under **Protocol** — `/oauth2/authorize`, `/oauth2/token`,
`/oauth2/userinfo`, `/.well-known/jwks.json` — returns **404 on
id.8westit.com today**. Verified 2026-08-02. That design was never built.
What ships is the Phase 1 cookie transport described below it. Do not write
an OIDC client against this document; it will not connect to anything.

### Where each app stands

| App | Repo | SSO | Maps the user by | Entry mechanism |
|---|---|---|---|---|
| Safeharbor | `8_west_helpdesk` | live | `email` | `suite_sso_attempt()` inside the normal login flow |
| Milepost | `8westit_webapp` | live | username == email local-part | `portal/lib/auth.php`, behind `suite_sso.enabled` |
| Mission Control | `mission_control` | live | **`sub`** (`suite_subject` column) | dedicated `GET /auth/suite` route |
| Coastmark | `coastmark` | **pending** | — | — |

Two consequences worth knowing before you go looking for a bug:

**There is no single sign-in page, by design.** Each app renders its own
login form and starts its own local session; the suite cookie is checked as
the user arrives. So "the sign-in pages all look different" is the current
architecture, not a misconfiguration. A hosted central login is an
issuer-side change (the deferred OIDC upgrade), not an app-side one.

**Probing for `/auth/suite` proves nothing on the PHP apps.** Only Mission
Control uses that route shape. Safeharbor and Milepost check the cookie
inline, so `/auth/suite` correctly 404s there while SSO is working fine.

### Why a sign-in silently falls back to the password form

`suite_sso_attempt()` returns `false` — and the user simply sees the local
login page — in four distinct cases, none of which produce an error message
or a log line:

1. **No cookie, or it fails verification** (wrong/absent shared secret, wrong
   issuer, expired token).
2. **The product is not entitled.** The app requires its own key in
   `8west:products`. A user granted `missioncontrol` but not `safeharbor`
   gets Mission Control by SSO and Safeharbor by password, on the same
   account. This is the most common cause and is fixed in the 8 West ID
   control panel, not in app code.
3. **The tenant row does not exist locally.** Safeharbor resolves
   `8west:tenant` against its own `tenants` table and bails if there is no
   match. A tenant that exists in 8 West ID but was never created in the app
   cannot sign in there.
4. **The user is inactive** in the receiving app.

When debugging, check those four in that order before suspecting the token.

### The user-mapping inconsistency (open issue)

The three live apps identify the same human three different ways: Safeharbor
by `email`, Milepost by the local-part of the email, Mission Control by the
immutable `sub`.

CLAIMS_CONTRACT_V1 rev 2 requires mapping by `sub` and never by email,
precisely because email is mutable — a user who changes their address gets a
**new, empty account** in any app that keys on email, while keeping their
existing account in one that keys on `sub`. The same divergence happens if a
user's address differs even slightly between systems.

Mission Control implements the contract (`users.suite_subject`, with a
one-time email backfill for legacy rows). Safeharbor and Milepost predate
rev 2 and still key on email. Bringing them onto `sub` is the same shape of
change Mission Control already shipped: add a `suite_subject` column, match
on it first, fall back to email once and backfill.

### Shared-secret exposure grows with each app

The Phase 1 transport is **HS256 with a shared secret** — a symmetric key, so
any holder can both verify *and mint* tokens. Every app added puts that key
on another host. Compromise of the least-defended app in the suite would let
an attacker forge a valid identity for **every** app, including tenant and
role claims.

Two apps was a contained risk. This is the reason the original design chose
OIDC with JWKS (asymmetric: the issuer signs with a private key that never
leaves it, apps verify with a public key and can mint nothing). Adding a
fourth app to the shared secret is a reasonable interim step, but the
asymmetric upgrade should be scheduled rather than deferred indefinitely, and
it is issuer-side work in `8_west_id`.
