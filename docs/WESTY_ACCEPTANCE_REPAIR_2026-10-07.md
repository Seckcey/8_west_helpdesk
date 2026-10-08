# Westy companion acceptance repair

[PR #183](https://github.com/Seckcey/8_west_helpdesk/pull/183) proposes the
Safeharbor half of the owner's October 7 companion findings, paired with
[Milepost PR #621](https://github.com/Seckcey/8westit_webapp/pull/621).
Functional source is frozen at `d9eeec77bb9c7a22e102aca39770abc0c8a32311`, before
coordinator documentation. This candidate is not deployed. The previous paired
backend release installed Safeharbor `8a4d78a3ade58ce308862a7666421e8c7a57a568`.
The owner installed private Full 1.26.7 on 8WV-FRANKIE, then reported the failures
documented in [Milepost's acceptance record](https://github.com/Seckcey/8westit_webapp/blob/main/docs/WESTY_ACCEPTANCE_FAILURES.md).
The proposed native correction declares Full 1.26.8 / companion 0.1.4; it is not
signed or accepted on the laptop yet.

## Connection and authorization

The approved one-use handoff preserves the original verified identity and its
original deadline before shortening the companion lease. Consumption rotates the
PHP session and records server-only provenance bound to that session and exact
pairing. A companion marker or ordinary authenticated browser page cannot grant
renewal. Legacy handoffs retain their existing limited lifetime.

The browser posts an empty `action=renew` request to `/portal/desktop_sessions.php`
with the current session and CSRF header. It can update connection expiry only
after the existing fresh identity/customer checks and a signed Milepost response.
Milepost separately checks exact actor, binding, current authority and fresh
native opt-in presence. The first recorded authority and original deadline cannot
be replaced by a later login. Invalid, changed, expired or delayed authority
fails closed. Native presence and browser renewal are both necessary.

The normal UI checks for renewal at bounded intervals. Transient connection
failure may still require reconnect. Renewal changes no task, grant, approval,
epoch, execution journal or Stop outcome and never retries computer execution.
It does not extend the original identity's maximum lifetime.

The originating request captures renewal provenance before releasing the PHP
session lock. Streaming liveness, final delivery and the inner human-approval
guard permit only a timely lease update under that same provenance and exact
original identity. Missing or changed provenance, a different pairing/session,
identity mutation, a shortened lease, expiry gap, logout, revocation or Stop still
refuses delivery or dispatch. Ordinary browser sessions keep exact-identity
comparison and cannot borrow the companion exception.

## Tools and presentation

`prepare_temp_cleanup` covers only an explicit request for Windows system temp,
`C:\Windows\Temp`. User/profile or unspecified temporary locations cannot dispatch
that fixed recipe. A refusal returns `temp_scope_mismatch` before transport and
allows a new suitable intent under the existing inference and independent-review
bounds. User-temp work resolves the actual signed-in user's path through the
existing general tools. Dangerous or disruptive actions still require exact
human approval; a preview never deletes files.

Awaiting approvals continue receipt refresh, explain expiry and replace stale
approval status. A support hold describes outstanding workflow ownership rather
than asserting a technician is currently using the computer. Output reads for one
process update one current output/Stop card; original stored tool history remains.
Access loss, conversation change, content erasure and conversation expiry have
different explanations. No another-tab claim is made without evidence.

Milepost separately refuses a retired or closed historical alert handoff. The
historical session and unknown command outcome remain intact; this correction
does not establish a successful new alert diagnosis or repair.

## Validation and release

The worker reported 32 pure renewal assertions, 50 cumulative real handoff/session
assertions, 136 cumulative workspace assertions including the production inner
approval guard, 21 AI receipt checks, actual PHP HTTP streaming cases and
desktop/mobile UI fixtures. Positive timely renewal crosses the captured short
lease; negative cases cover expiry gaps, missing/changed provenance, changed
pair/identity, shortened lease, unapproved extension, Stop, logout and revocation.
Ordinary final-head CI and independent review remain integration gates.

No schema, grant, provider credential, tenant model selection, cache/JWKS policy,
customer billing or report schedule is changed. Backend deployment must use the
existing serialized release procedure and check both cleanup services. A signed
native installer requires its separate source admission and fresh readiness
evidence. The owner accepted the existing recovery backup without a separate
verification and owns installation and subsequent live tests. Synthetic checks,
CI and signing do not establish laptop acceptance.
