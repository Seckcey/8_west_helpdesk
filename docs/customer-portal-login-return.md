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

The production receipt below records the completed release. Before installation,
the prior live source was `40a5b3311b1a7e888abe4a7f8333e40b3a501363`;
the initial base `29fda0a522dc15b7cd4e459dadc1a12319de2806` adds only three
documentation files. Recheck the complete gap and exact merged-head CI before
release. There is no schema, protected configuration, issuer, role, customer
binding or scheduler-scope change.

Use the established Safeharbor installer and full-artifact hasher under the
agreed release and report/deploy locks. Assemble the exact merged Git `app/`
tree using `git -c core.autocrlf=false -c core.eol=lf archive`, plus the derived
brand assets from `deploy/deploy.sh`; the tarball must
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

## Production receipt — October 3, 2026

PR [#163](https://github.com/Seckcey/8_west_helpdesk/pull/163) merged as
`f543c1b78e619d7fd80429c8e452c4e3aa70b44a`. Its merged-head Validate
`37149834466` and mobile `37149834457` runs succeeded. The complete merged tree
matched the reviewed PR head. The prior base gap contained documentation only.

The coordinator applied and independently verified this source on
`milepost-ec2` (`ip-172-31-31-195`, instance `i-0aca036629e5f6ea5`) in the
20:16:23–20:16:30 UTC window. The established installer, full-artifact hasher
and original shared release/report locks were used. Apache was gracefully
reloaded; the Safeharbor database account was restored unlocked. No migration
or database restore ran.

| Evidence | SHA-256 |
|---|---|
| Accepted canonical source archive | `c4ac4b2464b4c8622c3f135b9d65c9986090dea41835c3a817cb920074d43f79` |
| Clean source application artifact | `28ce0a48ea3b37deee918aca087befcb24f9838a87a8a84508163c4ffad809b2` |
| Independently measured deployed artifact | `cf1e1ca13207006986698295be946dcd1298849663d2680c9b4d2a63af70cd18` |
| Unchanged protected configuration | `710426607c7e0351e649837e5731fe64a0a5206322881988bfb861427f994b48` |

An initial Windows-generated package had CRLF text files. Independent canonical
Git comparison refused it before any production maintenance or source change.
Equal file sets and all 277 differences were verified as line endings only.
The corrected package was rebuilt with the explicit LF archive settings,
checked against all 324 archived Git blobs before brand derivation, and compared
byte for byte with the coordinator's independently assembled application.
The rejected packet and its handoff are retained as evidence; its hashes must
not be used for deployment.

The accepted package passed 227 PHP syntax checks, 65 signed OIDC/login checks,
73 portal-auth, 44 data, 32 ticket-workflow, 13 archive and 49 Linux scheduler
checks. The earlier real-MySQL run passed 68 binding/session checks. Seven
recovery cases exercised the actual wrapper function with synthetic paths and
command doubles; six rebind tests checked preservation and refusal behavior.
Those recovery tests were not a full production interruption rehearsal.

The live report scheduler verified active. Its cron, protected configuration,
customer/sender/recipient/schedule and delivery evidence were preserved; only
release/artifact/marker/bundle hashes and the review timestamp changed in the
activation record. Customer bindings and immutable report deliveries/attempts
had equal before/after hashes. Runtime grants and both restored Safeharbor and
unrelated support vhosts matched their backups. Public checks returned staff
login 200, unauthenticated portal 302 and an invalid callback 401.

In the actual customer-owner browser, the coordinator removed only the
`safeharbor_portal` cookie and retained the 8 West ID session. Reloading Home
produced portal → ID authorize → portal callback → Home (200), with no manual
sign-in click. Repeating from Your devices returned to Devices (200), showing
the customer's connected computer. The customer remained signed in afterward.
No ticket, order, setup link, device command or permission change was created.
Fresh password/MFA entry and live staff-account refusal were not performed in
this browser; those paths have the automated coverage described above.

Root-only rollback and execution evidence remain at
`/srv/8west/backups/safeharbor/20261003T195000Z-portal-login-01a10344`.
The active report control bundle is
`/srv/8west/apps/safeharbor/release-controls/20261003-portal-login-01a10344`.
This receipt is documentation only and does not require another application
deployment. Future changes must compare against the actual runtime marker.
