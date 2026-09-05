# Solved-ticket knowledge export to Logbook

The September 5 release is live with the dedicated source enabled and service
identity 6 registered. Its first scoped Logbook import found no eligible
Safeharbor candidates; zero is a valid source result, not a failed connection.
See the [release receipt](customer-journey-release-2026-09-05.md).

`POST /api/svc/logbook_export.php` implements Logbook's existing schema-1
`solved_tickets` importer. It reads tickets and messages only; it never creates
knowledge, sends mail, changes a ticket or touches billing. Logbook makes the
quoted text into a draft for a person to review.

Safeharbor has no separate resolution field. The export uses the latest public
technician reply at or before `resolved_at`, with `confidence=low`. It is a
resolution candidate, not an assertion that the technician wrote an explicit
resolution narrative. Internal notes, customer replies, system messages, merged
ticket stubs, open tickets and inactive customer bindings are excluded. Empty or
oversized candidates are skipped without truncating the technician's words.

Configure `logbook_export` in the existing protected `config.php`: enable it,
supply a dedicated 64-hex secret, exact local `tenant_id`, stable ID-owned
`suite_tenant_id`, and the approved Milepost `customer_ids`. Register the existing
`svc_identities` service name `logbook-export` for that local tenant. Customer
identity comes from `suite_customer_sync_bindings`, never company names. Existing
source bindings and the explicit export scope determine reach. No schema migration is required.

Logbook sends `X-Safeharbor-Logbook`, `X-Safeharbor-Timestamp`,
`X-Safeharbor-Nonce` and `X-Safeharbor-Sign`. The dedicated secret header and
HMAC must match; the signed base is POST, exact path, timestamp, UUID nonce and
SHA256 of the raw body, separated by newlines. The timestamp window is 300
seconds. Repeating an identical valid read within that window is harmless and
returns current data; this route
does not claim a mutating-command nonce ledger.

Request: `{"schema_version":1,"kind":"solved_tickets","cursor":0,"limit":200}`.
The response has `ok`, `schema_version`, `suite_tenant_id`, `tenant_slug`, `kind`,
`items` and `next_cursor`; each item has `ticket_id`, `customer_id`, `title`,
`resolution`, `confidence`, `resolved_at` and `observed_at`. Cursors are ascending
ticket IDs; the final cursor is null. Missing source infrastructure is 503,
invalid authentication is 401, invalid requests are 400 and the disabled route
is 404. No source error is converted to an empty successful page.

For an additional approved customer, coordinate the dedicated key and stable suite
tenant with Logbook, use only the already-recorded customer binding, then enable
the source and Logbook's matching allowlist together. Verify a quoted existing
resolved ticket in Logbook and review the draft there. Do not create or send a
synthetic ticket merely to demonstrate the integration.

Validation: `php app/tests/logbook_export_test.php` exercises the actual SQLite
queries under query-only mode, cross-tenant/customer exclusion, chronology,
internal-note exclusion, paging, inactive sources and the existing wire signature.
