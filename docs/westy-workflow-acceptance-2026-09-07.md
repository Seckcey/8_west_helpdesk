# Westy workflow candidate acceptance — September 7, 2026

This is source and synthetic acceptance for [PR 112](https://github.com/Seckcey/8_west_helpdesk/pull/112).
Migration 025 and both new gates remain uninstalled/unactivated in production.

## Signed cross-app checks

`tools/contracts/westy-suite-interop.php` takes a separate Milepost checkout and
an output directory. It copies only four tracked PHP libraries into a random
temporary directory with public fixture configuration; it never reads either
app's runtime config and never opens a network connection. Its database is
SQLite in memory. No customer records, endpoint commands, invoice sends or
production service keys are involved.

The harness passed **89 checks**, including the 62-check Safeharbor suite. Real
Milepost payload, signing, recovery-policy and receipt-validation functions ran
against the real Safeharbor authentication, JSON validation and transactional
receiver. Claim, status, progress, independently evidenced resolve, and a second
claim/escalation passed. The configured escalation assigned the actual allowed
fixture technician. Each exact request replay retained its version and receipt;
changing the signed bytes was refused.

The retained [manifest](contracts/interop-20260907/manifest.json) contains the
tested library hashes, exact signed requests and observed receipts. Its key is
explicitly public synthetic test material. These timestamps are historical;
replay tests must fix their test clock or re-sign the same bytes in an isolated
environment. Never submit these fixtures to production.

The same harness used Safeharbor's real approved-time v3 serializer and actual
billing outbox dispatcher to freeze the
[billing request](contracts/interop-20260907/billing-handoff-v1.json):
SHA-256 `40a6f4d9d3e8bfbf575f0c76b7300341393c1c327ded5833ff5a644a2ddcb076`.
Coastmark commit `4354493` consumed these exact bytes through both real Laravel
HTTP receivers. Test
`SafeharborBillingHandoffTest::test_actual_safeharbor_producer_bytes_import_and_replay_through_http_receivers`
passed 13 assertions: approved-time import, billing creation, exact replay,
one correctly priced draft under fixture mapping, and no journal/payment/mail.

This found and corrected a real integration mismatch: creation returns HTTP
201, while replay returns HTTP 200. Safeharbor now validates both appropriate
responses. Its 29 billing checks include the
[actual Coastmark creation receipt](contracts/interop-20260907/coastmark-created-receipt.json).
An accepted receipt means invoice review is ready; it does not mean posted or sent.

## Database and rendered UI

The full existing PHP/MySQL/browser Validate run
[34105946307](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34105946307)
passed on `d1609bf`, including the new disposable MySQL migration/replay,
ownership, frozen outbox and least-privilege tests. Subsequent changes add the
observed HTTP 201 receipt regression and final interoperability artifacts;
their exact PR head must also pass before release.

The MySQL test demonstrated that an ordinary DML identity cannot inspect trigger
metadata through the proposed view. The implemented readiness function runs
under the migration definer and needs EXECUTE only on that one read-only
function. The test proves runtime denial before this grant, readiness afterward,
no CREATE TABLE or trigger metadata privilege, and refusal after a guard or
enforced resolution constraint is removed. Production backup/restore must
include routines as well as triggers. This does not authorize a migration.

Three real Chromium fixture tests use production card PHP, CSS and JavaScript:
successful takeover preserves a reply draft, failed takeover retains ownership
and retry ability, and the 390-pixel layout preserves readable evidence and
accurate no-contact reply guidance. Manual fixture checks covered desktop dark
and phone light appearance, disclosure of proof, and no console errors.

## Read-only production baseline

A fresh hidden in-app browser tab reused the existing signed-in staff session
on `https://safeharbor.8westit.com/`. Queue, Open filtering and an existing alert
ticket were checked at 1440×900 and 390×844. Both layouts fit and remained
readable, with no browser warnings/errors. Only navigation occurred; no replies,
notes, timers, assignments or customer records were changed. The tab was closed
and viewport override reset afterward.

The live composer still gives a general email promise on a ticket with no
contact; the candidate fixes that wording and preserves it after switching
between Reply and Internal note. Older catchall alert tickets and historical
overdue work remain visible. They require an operator's business decision,
not automated cleanup. The new ownership/billing card was tested with local
fixtures because the new workflow is not active in production.
