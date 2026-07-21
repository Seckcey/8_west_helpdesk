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
