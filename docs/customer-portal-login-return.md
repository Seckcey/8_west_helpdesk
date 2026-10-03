# Customer portal sign-in return

## Repair scope — October 3, 2026

The customer portal previously rendered **Customer sign in / Continue with
8 West ID** whenever its own session was absent. An existing 8 West ID session
did not complete Safeharbor's separate OIDC exchange until the customer clicked
Continue. The coordinator reproduced that extra click in the existing customer
browser; it then entered the portal without asking for credentials. A separate
request without cookies also returned the interstitial. The callback always
returned to the portal home, losing a requested ticket or report page.

Signed-out GET requests to customer pages now begin the existing confidential
OIDC flow immediately. An authenticated 8 West ID session can finish the exchange
without another password or a Safeharbor interstitial. Otherwise, 8 West ID owns
sign-in and any required MFA. Safeharbor admits the result only through the
existing signature, issuer, audience, state, nonce, PKCE, product, role and
explicit active customer-binding checks. The shared `ewid_token` and staff
session are not customer credentials. A valid local customer session continues
through its usual revocation and binding rechecks.

The original destination is kept in the portal session alongside the OIDC
transaction state and its five-minute expiry. Only the exact customer page
allowlist is accepted. Staff paths, auth/callback/logout routes, APIs, external
URLs, fragments, traversal, backslashes, controls and excessive nested encoding
fall back to `/portal/`. The callback consumes and revalidates the destination
after OIDC validation. The destination still performs its own customer access
checks; preserving a ticket/report identifier never grants access to that record.
Callbacks from transactions begun before this repair safely return home.

A five-minute host-only, Secure, HttpOnly, SameSite=Lax cookie scoped to `/portal`
marks a pending sign-in. It only suppresses automatic retries and grants no
access. It survives destruction of an invalid local session and clears only
after a destination successfully rechecks revocation and customer binding. If
the new session is rejected, the portal shows an explicit retry link instead of
bouncing repeatedly through ID. A failed callback stays on its existing 401
error page. Discovery/identity outages remain failures, not anonymous access.
POST requests never start automatic sign-in or replay a submitted operation;
the customer must sign in and review/retry their action.

## Validation

`app/tests/portal_login_test.php` exercises the actual OIDC client and PHP attempt
store with a synthetic issuer, generated RSA signing key, and real signatures.
It covers secure authorization parameters, code exchange, one-time callback and
return consumption, deep links, unsafe destinations, loop suppression, POST
retry, expired attempts/tokens, invalid signatures, issuer/state/nonce/audience,
tenant consistency, product and enforced MFA refusal. No live credential is used.

`app/tests/portal_mysql_test.php` additionally establishes real PHP customer
sessions against synthetic MySQL bindings: all four customer roles, existing
sessions, staff/MSP refusal, absent/mismatched bindings, revocation, expired
credentials and identity outages. Existing portal authentication, rendering,
ticket, archive, staff SSO and CI checks remain required.

## Source-only release and acceptance

This document describes the reviewed source behavior, not a production receipt.
The prior live source is `40a5b3311b1a7e888abe4a7f8333e40b3a501363`;
the initial base `29fda0a522dc15b7cd4e459dadc1a12319de2806` adds only three
documentation files. Recheck the complete gap and exact merged-head CI before
release. There is no schema, protected configuration, issuer, role, customer
binding or scheduler-scope change.

Use the established Safeharbor installer and full-artifact hasher under the
agreed release and report/deploy locks. Assemble the exact merged Git `app/`
tree plus the derived brand assets from `deploy/deploy.sh`; the tarball must
contain an `app/` root, not `./app/` or repository-root files. Preserve the
verified application/config/database/grants/marker/scheduler rollback backup.
Pause reports and drain the correct Safeharbor surface before the non-atomic
source replacement. Rebind the report bundle and activation evidence only to
the new source/artifact/marker hashes, preserving the existing protected config,
customer, schedule, sender, recipient and immutable delivery evidence. Control
directories must be root:root 0700 without inherited setgid. Follow the established
[deployment controls](../deploy/README.md) and
[previous source-only recovery procedure](SSO_RECOVERY_2026_09_25.md).

After installation, verify the exact live artifact, unchanged config and report
scope, active scheduler, public entry redirect and callback failure behavior.
The coordinator owns browser acceptance: a real customer with an existing ID
session must reach the portal without the extra click; a fresh unauthenticated
entry must sign in once and return to the intended customer page. Preserve the
user's active sessions, never fabricate production sessions, and distinguish
synthetic test coverage from the actual browser observations.
