# 8 West ID — Suite SSO Contract

One identity for the whole suite: **Safeharbor**, **Milepost**, **Coastmark**,
and the **8 West IT 365 Control Panel**. A user signs in once with **8 West ID** and lands
in any product with the same tenant context. This document is the contract each
app implements against.

This document governs Safeharbor's **technician/staff suite-cookie surface**.
The separately gated customer ticket-summary portal uses the issuer's maintained
`oidc_v1` authorization-code profile and
`docs/customer-portal-contract.md` as its authority. It does not replace or
silently migrate staff sign-in.

> **Currency.** Corrected 2026-08-29 against `app/lib/auth.php`,
> `app/lib/jwt.php`, `app/config/config.sample.php` and
> `app/tests/suite_sso_test.php` in this repo, plus current Milepost `main`.
> Revisions of this file before
> that date specified obsolete **`/oauth2/*` OpenID Connect paths that were
> never built** and a status table that no longer matched any app. The separate
> customer-portal boundary was reconciled here on 2026-08-26; do not use this
> staff-cookie contract as portal-client documentation.

## Protocol — as built

8 West ID (**https://id.8westit.com**, repo `8_west_id`) issues a signed
**RS256 JWT** in an HttpOnly cookie scoped to `.8westit.com` after sign-in.
This legacy suite-cookie profile has no redirect handshake or token exchange.
Each shared-cookie app reads the cookie as the visitor arrives, verifies the
signature with the issuer's public JWKS, and starts its own ordinary local
session. The bounded legacy-token drain ended at `2026-08-24T04:17:47Z`; all
four shared-cookie consumers now accept RS256 only.

| Setting | Value |
|---|---|
| Issuer (`iss`) | `https://id.8westit.com` |
| Transport | cookie `ewid_token` · HttpOnly · domain `.8westit.com` |
| Signature | **RS256** — asymmetric; issuer private key stays on the ID host |
| Accepted algorithms | `RS256` only since `2026-08-24T04:17:47Z` |
| Verification | app-side; `jwt_verify_suite_reason()` in `app/lib/jwt.php`, exact `kid` from issuer JWKS |
| Safeharbor entry point | `suite_sso_attempt()` in `app/lib/auth.php`, called from `require_login()` and `public/login.php` |
| Safeharbor config | the `suite` block (`issuer`, `sso_secret`, `cookie_name`, `token_algorithms`, `jwks_url`, `jwks_cache_path`, `revocation_cache_path`, `session_version_mode`) in server-only `config/config.php` |

**8 West ID is not Keycloak** and does not front one. It is a first-party PHP
issuer in `Seckcey/8_west_id`.

### The obsolete staff `/oauth2/*` design was never built

Every revision of this contract up to 2026-08-02 opened with an OpenID Connect
protocol section — authorization code + PKCE, `GET /oauth2/authorize`,
`POST /oauth2/token`, `GET /oauth2/userinfo`, `GET /.well-known/jwks.json`,
scopes `openid profile email tenant`.

**Those exact `/oauth2/*` prototype paths were absent when verified on
2026-08-02.** The current issuer later added an additive OIDC profile under its
documented `/oauth/*` routes plus `/.well-known/openid-configuration`, and its
public JWKS now also supports the RS256 suite cookie. Safeharbor's staff surface
still uses the legacy cookie profile; do not infer a staff OIDC migration.

Those `/oauth2/*` paths were a design, never an implementation. Nothing in any
suite app calls them. They are named here only so the next person who finds
that table in git history recognises it — do not write an OIDC client against
this document.

Safeharbor now also contains a **separate customer-portal OIDC client** under
`lib/eightwestid/` and `public/portal/`. Its source and the issuer prerequisite
are deployed dark, but production has zero registered Safeharbor OIDC clients,
empty client fields, and zero portal bindings/events. The portal remains 404
and cookie-free while `portal.enabled=false`. Its exact claims, role gate,
binding, revocation, and canary rules live only in
`docs/customer-portal-contract.md`.

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
| `8west:session_version` | string | Exact `user-generation.tenant-generation` authorization version. New cookies carry it. `compat` permits an older cookie only before this consumer has observed a signed versioned feed; the final production setting is `strict`. |
| `8west:theme` | string | Suite-wide UI theme: `dark` · `light` · `system` |
| `8west:avatar` | string | Suite-wide avatar URL |
| `8west:auth_policy` | string | Authentication-event contract; currently `suite-mfa-v1` |
| `auth_time` | int | Unix time of the primary authentication event |
| `amr` | string[] | Authentication methods used. Password flows include `pwd`; a passkey may be the sole method. |
| `8west:mfa_authenticated` | bool | Accepted MFA evidence exists for this authentication event |
| `8west:mfa_time` | int/null | Unix time the accepted MFA evidence was originally proved |
| `8west:mfa` | bool | Enrollment only; not current authentication proof |

`sub` and `email` are both mandatory — a token missing either is refused
(`missing_claims`).

The suite cookie is a distinct token type, not an OIDC ID token. It therefore
contains no `aud`, `nonce`, or `azp`; the presence of any one refuses the token
as `token_type_invalid`, even when the issuer and signature are valid. The JWT
header may omit `crit` or carry an explicit empty JSON array. Object-shaped or
nonempty `crit` values are unsupported and refused.

`8west:products` must be a JSON array of at most 64 unique canonical product
keys (`a-z`, digits, and underscore, beginning with a letter, up to 32
characters each). This structural gate remains open to a future canonical key;
Safeharbor's separate entitlement gate still requires the exact `safeharbor`
key. An object encoding, mixed type, duplicate, malformed key, or oversized
list is refused before tenant, user, or session writes.

Allowed MFA methods are `otp`, `recovery`, `mfa_trusted_device`, and `passkey`.
A passkey counts as MFA-capable evidence without a separate password method.
The complete authentication-method set is closed: `pwd` plus those four MFA
methods. Any unrecognized `amr` value refuses the authentication event, even
when it appears beside a valid passkey or another recognized method, and even
while MFA policy monitoring is set to `off` or `report`. The claim must be a
JSON array; object-shaped encodings, including an empty object, are invalid.

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
   snapshot on every authenticated request through a 60-second cache. An
   explicit `revoked` entry deactivates the local user and destroys that
   session. Once the signed snapshot includes `authorizations`, Safeharbor also
   requires the local session's subject and exact cookie session version to be
   present; absence or mismatch destroys only the local session and requests a
   fresh ID sign-in. A revoked-only snapshot retains the legacy behavior only
   while `session_version_mode=compat` and this host has never observed either
   versioned field. That observation is authenticated in the private cache and
   cannot roll back to legacy; the completed rollout sets `strict`, which also
   survives cache deletion or a replacement host. Other consumers need their
   own equivalent enforcement. Both `revoked` and `authorizations` must be JSON
   arrays containing JSON objects. A signed versioned response with an
   object-shaped container fails closed and still commits the one-way v2 latch;
   a later correctly signed legacy response cannot restore compatibility mode.
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
| `unexpected_algorithm` | `jwt_verify_reason()` | algorithm is not pinned, or `crit` is object-shaped/nonempty |
| `bad_signature` | `jwt_verify_reason()` | HMAC mismatch — usually a wrong or unset shared secret |
| `bad_payload` | `jwt_verify_reason()` | body is not JSON object |
| `token_type_invalid` | `jwt_verify_reason()` | an OIDC-only `aud`, `nonce`, or `azp` claim appeared in the suite cookie |
| `wrong_issuer` | `jwt_verify_reason()` | `iss` is not the configured issuer |
| `expired_token` | `jwt_verify_reason()` | `exp` in the past |
| `missing_claims` | `jwt_verify_reason()` | `sub` or `email` absent |
| `amr_invalid` | `jwt_verify_reason()` | `amr` is not a bounded unique JSON array of recognized methods |
| `products_invalid` | `jwt_verify_reason()` | `8west:products` has the wrong container or an invalid, duplicate, or oversized key list |
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
| Safeharbor staff surface (this repo) | `safeharbor` | **live in production** at `2f64cdf`; RS256-only identity verification, local-session-first central logout and signed revocation-list enforcement | **`sub`** (`users.suite_subject`), with a one-time email backfill for pre-suite accounts |
| Coastmark 365 (`coastmark`) | `coastmark` | **live in production** at `8b7b337`; RS256-only identity verification | `sub` |
| Milepost (`8westit_webapp`) | `milepost` | **live in production** at `e99f4ea`; RS256-only identity verification behind the `suite_sso.enabled` kill switch; identity-only shared secret removed | **`sub`** (`users.suite_subject`); one-time claim prefers canonical email, then the legacy email-local-part username, and backfills the subject |
| Cloudline (`missioncontrol`) | `missioncontrol` | **live in production** at `00bbb9d`; RS256-only identity verification and privilege-sensitive revalidation | `sub` |

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

1. **Usable verification config must be set** in server-only
   `config/config.php`. RS256 requires `suite.jwks_url` and a writable cache
   path. Safeharbor still requires `suite.sso_secret` for the independently
   authenticated revocation snapshot. A host that was never
   configured fails every signature — effectively off, but off by accident
   rather than by design. `session_version_mode` is mandatory: missing,
   blank, or unknown values fail closed rather than silently returning to the
   rollout-only compatibility behavior.
2. **Migration `app/db/migrations/007_suite_subject.sql` is applied in
   production.** It was applied migration-first on 2026-08-02 before the code
   deployment; see `docs/suite-sso-deploy-acceptance.md`. It must still be
   applied before or with the code on any rebuilt host.
   Subject mapping reads `users.suite_subject`; PDO runs in
   `ERRMODE_EXCEPTION`, so on a host without the column the first suite
   sign-in throws rather than falling back.

## Deferred after the RS256 cutover

Still open, in rough priority order:

- **Migrate Safeharbor technician sign-in to the issuer's authorization-code
  profile.** The issuer now has a new-app OIDC client kit, but the technician
  surface still uses the legacy cookie transport. The dark customer portal is
  a separate destination/client and is not evidence that staff migration is
  complete. Treat any staff migration as its own reviewed change.
- **Suite-wide sign out.** The rule 4 issuer handoff clears the shared cookie.
  Safeharbor enforces the issuer's HMAC-signed revoked and current-authorization
  inventories for established suite sessions (60-second cache, 15-minute
  maximum snapshot age, logged fail-open on issuer/signature/stale failures).
  A full OIDC logout design remains separate work.
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
  plain-PHP rebuild never carried over. That staff `/oauth2/*` client was never
  written in any language; the later customer-portal `oidc_v1` client is a
  separate reviewed surface.
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
- **2026-08-29.** Shared-cookie sessions began retaining the exact issuer
  authorization generation. Safeharbor accepts the revoked-only feed during
  consumer-first rollout, then requires a subject/version match once the
  signed authorization inventory appears.
