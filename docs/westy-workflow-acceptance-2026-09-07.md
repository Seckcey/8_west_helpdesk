# Westy workflow candidate acceptance â€” September 7, 2026

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
ticket were checked at 1440Ã—900 and 390Ã—844. Both layouts fit and remained
readable, with no browser warnings/errors. Only navigation occurred; no replies,
notes, timers, assignments or customer records were changed. The tab was closed
and viewport override reset afterward.

The live composer still gives a general email promise on a ticket with no
contact; the candidate fixes that wording and preserves it after switching
between Reply and Internal note. Older catchall alert tickets and historical
overdue work remain visible. They require an operator's business decision,
not automated cleanup. The new ownership/billing card was tested with local
fixtures because the new workflow is not active in production.

## Source release completed, workflow activation remains off

PR #112 merged and its exact-main Validate run
[34107323212](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34107323212)
passed. Clean detached release `8e8cff9cc3c2d0ef29090b2cf761246b7c7f3891`
was deployed with the documented source installer. Its source artifact is
`623b5b815785e51f351d34cb1c72469248486f8aa1e23638cf0a384f08585806`;
its stamped deployed artifact is
`ee42c04d1df3192c44d6c6a228bd8028fe0fd9555a350568c0b28ab31666b022`.

The fresh root-only source backup and retained postflight are under
`/srv/8west/backups/safeharbor/20260907T094124Z-pre-westy-source-8e8cff9cc3c2`.
The existing report schedule was stopped through its reviewed control bundle,
then restored and verified active through the bundle matching the new artifact.
Original sender/recipient confirmation, report scope, config bytes, runtime
grants and cron bytes were preserved. Report manager/cron/hasher/runner bytes
were verified unchanged from the prior live release. Both shared-host vhost
hashes are unchanged; no Apache restart/reload was needed.

Postflight confirmed 44 base tables, 87 triggers, zero migration-025 tables,
zero migration-025 triggers, no new health function, both new gates absent/off,
and no new workflow scheduler. The public workflow route returns 404 and the
actual disabled worker exits quietly as the runtime user. No database mutation,
new service key, customer binding, command, business record or invoice delivery
was performed for this source release.

A new hidden browser tab remained signed in and verified deployed ticket and
Queue navigation. Desktop 1440×900 and stable phone 390×844 layouts passed;
the phone document and viewport both measured 390 pixels. The no-contact reply
hint is now accurate and survives note/reply switching. There were no console
warnings/errors, no form submissions or timers. Owned tabs were closed,
viewport overrides reset, and the local fixture server stopped.

Final source checks include 63 workflow checks, 29 billing checks, disposable
MySQL migration/least-privilege/immutability coverage and three new Chromium
workflow tests, alongside all existing PHP/MySQL/browser checks. The retained
89-check interoperability run predates the extra pre-migration card regression;
its signed request bytes and Coastmark receipt remain unchanged.
