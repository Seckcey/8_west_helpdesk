# 8 West ID — Suite SSO Contract

One identity for the whole suite: **Safeharbor**, **Milepost**, **Coastmark**,
and the **8 West IT 365 Control Panel**. A user signs in once with **8 West ID** and lands
in any product with the same tenant context. This document is the contract each
app implements against.

> **Currency.** Corrected 2026-08-08 against `app/lib/auth.php`,
> `app/lib/jwt.php`, `app/config/config.sample.php` and
> `app/tests/suite_sso_test.php` in this repo, plus current Milepost `main`.
> Revisions of this file before
> that date specified an **OpenID Connect flow that was never built** and a
> status table that no longer matched any app. Read *The OIDC design was never
> built* below before writing a client against anything here.

## Protocol — as built

8 West ID (**https://id.8westit.com**, repo `8_west_id`) issues a signed
**HS256 JWT** in an HttpOnly cookie scoped to `.8westit.com` after password
sign-in. There is no redirect handshake and no token endpoint. Each app reads
the cookie as the visitor arrives, verifies the HMAC with a **shared secret**
held in its server-only config, and starts its own ordinary local session.

| Setting | Value |
|---|---|
| Issuer (`iss`) | `https://id.8westit.com` |
| Transport | cookie `ewid_token` · HttpOnly · domain `.8westit.com` |
| Signature | **HS256** — symmetric, shared secret |
| Verification | app-side; `jwt_verify_reason()` in `app/lib/jwt.php` |
| Safeharbor entry point | `suite_sso_attempt()` in `app/lib/auth.php`, called from `require_login()` and `public/login.php` |
| Safeharbor config | the `suite` block (`issuer`, `sso_secret`, `cookie_name`) in server-only `config/config.php` |

**8 West ID is not Keycloak** and does not front one. It is a first-party PHP
issuer in `Seckcey/8_west_id`.

### The OIDC design was never built

Every revision of this contract up to 2026-08-02 opened with an OpenID Connect
protocol section — authorization code + PKCE, `GET /oauth2/authorize`,
`POST /oauth2/token`, `GET /oauth2/userinfo`, `GET /.well-known/jwks.json`,
scopes `openid profile email tenant`.

**All of those paths return 404 on id.8westit.com. Verified 2026-08-02.**

They were a design, never an implementation. Nothing in any suite app has ever
called them. They are named here only so the next person who finds that table
in git history recognises it — do not write an OIDC client against this
document, it will not connect to anything. The asymmetric upgrade is still the
intended destination; see *Deferred, and why it matters more each time* below.

## Claims

| Claim | Type | Meaning |
|---|---|---|
| `sub` | string | **Immutable** user id across the suite. The only correct identity key (see rule 6). |
| `email` | string | Sign-in email. Mutable — display and contact only. |
| `name` | string | Display name |
| `exp` | int | Expiry (unix seconds); a token past `exp` is refused |
| `8west:tenant` | string | **Tenant slug** — the customer account; binds every record in every product |
| `8west:products` | string[] | Licensed product keys, from `["safeharbor","milepost","coastmark","coastline_control_panel"]` |
| `8west:role` | string | Staff roles (`owner`, `admin`, `tech`, `readonly`) or tenant-aware `msp_*` / `client_*` roles |
| `8west:theme` | string | Suite-wide UI theme: `dark` · `light` · `system` |
| `8west:avatar` | string | Suite-wide avatar URL |
| `8west:auth_policy` | string | Authentication-event contract; currently `suite-mfa-v1` |
| `auth_time` | int | Unix time of the password authentication |
| `amr` | string[] | Authentication methods: `pwd` plus `otp`, `recovery`, or `mfa_trusted_device` |
| `8west:mfa_authenticated` | bool | Accepted MFA evidence exists for this authentication event |
| `8west:mfa_time` | int/null | Unix time the second factor was originally proved |
| `8west:mfa` | bool | Enrollment only; not current authentication proof |

`sub` and `email` are both mandatory — a token missing either is refused
(`missing_claims`).

Safeharbor evaluates authentication-event evidence through
`suite.mfa_policy_mode`. New releases use `report`, which admits the existing
identity while logging a fixed reason such as `contract_missing`. `enforce`
refuses noncompliant evidence before tenant or user provisioning. The maximum
age defaults to 2,592,000 seconds (30 days), matching 8 West ID trusted-browser
expiry.

## Rules

1. **Tenant resolution is claim-driven.** Apps never ask "which account are you
   with?" after sign-in — `8west:tenant` decides. In Safeharbor the tenant row
   is **provisioned on first arrival** if the slug is unknown, so granting the
   tile in 8 West ID is the only step needed to give somebody access; there is
   no per-app setup. A blank slug is refused. Safeharbor admits legacy staff
   roles only in the `8west`/`internal` staff tenants, maps canonical `msp_*`
   operators in customer tenants, and refuses client contacts and unknown roles.
2. **Product gating is entitlement-driven.** `8west:products` controls which
   apps accept the token. Safeharbor requires the literal key `safeharbor`
   (hard-coded in `suite_sso_attempt()`, *not* read from `suite.product` in
   config) and refuses a token without it.
3. **Every deny is audited with a reason code.** Fail closed, and say why —
   see the next section.
4. **Sign-out tears down locally, then hands off to 8 West ID.** Each product
   destroys its own session before redirecting to the configured issuer's
   `/logout.php`; the issuer clears the shared suite cookie and lands on the
   hosted 8 West ID login page. This prevents the current product from
   immediately signing the user back in with the still-valid suite cookie.
   It does **not** directly terminate already-established local sessions in
   other products. Safeharbor separately checks 8 West ID's signed revocation
   list on every authenticated request through a 60-second cache, deactivates
   a revoked local user and destroys that session. Other consumers need their
   own equivalent enforcement.
5. **Deep links carry context, not credentials.** Cross-product links
   (`Milepost device → Safeharbor ticket`) pass ids only; the receiving app
   re-authorizes from its own cookie.
6. **Map the user by `sub`, never by email** (CLAIMS_CONTRACT_V1 rev 2). Email
   is mutable: an app that keys on it hands a user who changes their address a
   brand-new empty account while orphaning the real one, and splits one human
   into several records across the suite. Legacy accounts that predate suite
   entry may be claimed by email **once**, then must be backfilled with the
   subject.
7. **There is no single sign-in page, by design.** Each app renders its own
   login form and starts its own session; the suite cookie is checked as the
   user arrives. Different-looking sign-in screens are the current
   architecture, not a misconfiguration. PHP apps may check the cookie inline,
   so probing for a separate callback path does not establish whether SSO works.

## Deny paths are audited, never silent

An earlier reading of this system — and the unmerged "Phase 1 field notes"
draft — described suite sign-in failures as silent: `suite_sso_attempt()`
returns `false`, the visitor lands on the password form, and nothing records
what happened. **That is no longer true.** Every refusal goes through
`suite_sso_refuse()` in `app/lib/auth.php`, which writes an
`error_log()` line `suite sso deny: <reason>` — with ` sub=<subject>` appended
once the signature has verified.

The visitor is still told nothing specific. The log carries the reason code and
never the claim payload: tenant slugs, roles and addresses must not accumulate
in plaintext in an audit line.

Reason codes, in the order they can occur:

| Reason | Raised by | Means |
|---|---|---|
| `no_cookie` | `suite_sso_attempt()` | no `ewid_token` on the request |
| `malformed_token` | `jwt_verify_reason()` | not three dot-separated segments |
| `bad_signature` | `jwt_verify_reason()` | HMAC mismatch — usually a wrong or unset shared secret |
| `bad_payload` | `jwt_verify_reason()` | body is not JSON object |
| `wrong_issuer` | `jwt_verify_reason()` | `iss` is not the configured issuer |
| `expired_token` | `jwt_verify_reason()` | `exp` in the past |
| `missing_claims` | `jwt_verify_reason()` | `sub` or `email` absent |
| `product_not_entitled` | `suite_sso_attempt()` | `8west:products` lacks this app's key — the most common cause, fixed in the 8 West ID control panel, not in app code |
| `tenant_slug_invalid` | `suite_sso_attempt()` | `8west:tenant` is empty |
| `role_not_admitted` | `suite_sso_attempt()` | role is outside the tenant-aware Safeharbor map, including viewers and downstream client contacts |
| `user_inactive` | `suite_sso_attempt()` | the local account exists but `is_active = 0` |

`jwt_verify()` (the reasonless twin) is still used by
`suite_sso_refresh_claims()`, which only syncs theme and avatar on an already
authenticated request and has no deny to audit.

Note what is **not** in this list: "the tenant row does not exist locally".
Safeharbor no longer bails on an unknown slug — it creates the tenant (rule 1).

## Status by app

| App | Product key | Suite SSO | Maps the user by |
|---|---|---|---|
| Safeharbor (this repo) | `safeharbor` | **live in production**; checked inline, with local-session-first central logout and signed revocation-list enforcement | **`sub`** (`users.suite_subject`), with a one-time email backfill for pre-suite accounts |
| Coastmark 365 (`coastmark`) | `coastmark` | **live in production** at `e3b74da`; local-session-first central logout deployed | `sub` |
| Milepost (`8westit_webapp`) | `milepost` | live, behind the `suite_sso.enabled` kill switch in `portal/lib/auth.php` | **`sub`** (`users.suite_subject`); one-time claim prefers canonical email, then the legacy email-local-part username, and backfills the subject |

Coastmark was split on 2026-08-02: `Seckcey/coastmark` is the 8 West IT 365
edition (mode hard-coded `platform`) and is the row above;
`Seckcey/coastmark_standalone` is the standalone product (mode hard-coded
`standalone`, its own marketing, pricing and Stripe) and is **outside** this
contract. Neither can become the other by configuration.

Milepost's current mapping was rechecked against `8westit_webapp` `main` at
`43f20abb89f82dad0aca3d75d6262669419df8f3` on 2026-08-08. It matches by
subject first; a pre-suite row may be claimed once by canonical `users.email`,
then by the historical username-equals-email-local-part fallback, only while
the subject is null. It backfills `suite_subject` and subsequently syncs an
email change onto the subject-matched row instead of creating another user.

### Safeharbor operational preconditions

Two things gate suite sign-in on a Safeharbor host, and neither is a feature
flag — **Safeharbor has no `suite_sso.enabled` kill switch**; that switch
exists in Milepost only.

1. **`suite.sso_secret` must be set** in server-only `config/config.php` and
   must match 8 West ID's. `config.sample.php` ships `CHANGE_ME`, so a host
   that was never configured fails every signature and denies with
   `bad_signature` — effectively off, but off by accident rather than by
   design.
2. **Migration `app/db/migrations/007_suite_subject.sql` is applied in
   production.** It was applied migration-first on 2026-08-02 before the code
   deployment; see `docs/suite-sso-deploy-acceptance.md`. It must still be
   applied before or with the code on any rebuilt host.
   Subject mapping reads `users.suite_subject`; PDO runs in
   `ERRMODE_EXCEPTION`, so on a host without the column the first suite
   sign-in throws rather than falling back.

## Deferred, and why it matters more each time

Still not built, in rough priority order:

- **Asymmetric signing.** HS256 with a shared secret is symmetric: every app
  holding the key can *mint* suite identities, not merely verify them —
  including arbitrary tenant and role claims. Compromise of the least-defended
  app forges a valid identity for all of them. This is precisely what the
  original OIDC-with-JWKS design avoided (issuer signs with a private key that
  never leaves it; apps verify with a public key and can mint nothing). The
  work is issuer-side, in `8_west_id`, and gets more expensive with every
  consumer added.
- **Authorization-code flow + PKCE, rotating refresh tokens**, and a hosted
  central login page (rule 7 disappears when this lands).
- **Suite-wide sign out / complete consumer-session revocation.** The rule 4
  issuer handoff clears the shared cookie. Safeharbor now enforces the issuer's
  HMAC-signed revocation list for established suite sessions (60-second cache,
  15-minute maximum snapshot age, logged fail-open on issuer/signature/stale
  failures), but every other consumer must implement equivalent enforcement
  before “sign out everywhere” is a suite-wide guarantee. A full OIDC logout
  design remains separate work.
- **Multi-account picker** for a user belonging to more than one customer
  account.
- **Entitlement revocation.** 8 West ID now derives effective products from
  tenant subscription state. Safeharbor continues to enforce the signed
  product claim and revocation list locally. See
  `docs/open-decision-entitlement-and-subscription.md` for the resolved boundary.

The claim shape above is stable, so each of these is issuer-side work rather
than a change to app-side session handling.

## History

- **Phase 0 (design).** This file opened as a Phase 0 draft describing the OIDC
  flow, and pointed at `app/src/lib/sso.ts` / `app/src/lib/session.tsx` as
  stubs to be swapped for a real OIDC client. Safeharbor has no `app/src/` —
  those were TypeScript files from an earlier prototype of the app that the
  plain-PHP rebuild never carried over. The OIDC client was never written in
  any language.
- **Phase 1 (2026-07-22).** The HS256 cookie transport above shipped as a
  lighter stand-in for OIDC, plus `8west:theme` / `8west:avatar` for suite-wide
  user settings.
- **2026-08-02.** Subject mapping, tenant auto-provisioning and audited denies
  landed in Safeharbor; Coastmark was split; this contract was corrected.
- **2026-08-02.** Migration 007 was applied before the Safeharbor SSO code,
  and Safeharbor's signed revocation-list enforcement was later merged.
- **2026-08-08.** Corrected Safeharbor's staff-tenant, migration and
  revocation status; rechecked Milepost's now-sub-based mapping against its
  current `main`.
