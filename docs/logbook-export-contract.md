# Solved-ticket knowledge export to Logbook

> **Retired 2026-10-19.** Logbook was discontinued and stopped. This export is
> switched off and service identity 6 is inactive. **November 6 production cleanup:
> the dedicated export chain is removed in `a33dd914`; public export returns 404.**
> Private configuration and service identities remain unchanged.
> The earlier contract below is retained as history, not activation instructions.

## November 6 source removal and recovery boundary

The removal contains exactly these five runtime files:

- `app/public/api/svc/logbook_export.php`
- `app/lib/logbook_export.php`
- `app/lib/suite_logbook_registration.php`
- `app/lib/suite_integration_registry.php`
- `app/cron/suite_integration_inspect.php`

The dedicated export test, its single Validate invocation and the `logbook_export`
sample configuration block are also removed. The registry helper had only the
dedicated export adapter as a consumer. Shared `suite_managed_provider.php` and
`suite_workspace_manifest.php` remain, along with tenant-AI app IDs, product and
entitlement records, OIDC, schema, lifecycle code and historical evidence. No
private configuration, key, service identity, database row, installed helper,
backup or November 18 retirement material is deleted by this source change.

The Safeharbor owner's pre-removal audit found production still at
`48a1790e06b84eb7c540867f92d76dc683b92a16`, with the export disabled for tenant 1,
identity 6 inactive, no registration files and public GET returning 404. The five
installed file hashes matched the source reviewed at `e1cfba387610f5f09d7c8e87a8de1d526843f2a0`.
The inspection CLI can INSERT identities; it was not executed. Repository caller
review found no other consumer; Milepost's owner separately confirmed no caller
of this Safeharbor chain (Milepost's own analogous export is outside this removal).

**ID dependency is installed, not merely merged.** ID PR
[#223](https://github.com/Seckcey/8_west_id/pull/223), merge `baebc339`, removed the
retired integration callers. ID's owner installed the following pair under
`/usr/local/lib/8west-suite-onboarding/` on November 6 at **17:26:29 UTC**
(9:26:29 AM PST). The accepted independent postflight and three natural-cycle
receipts recorded `complete_new`, preserved protected metadata, healthy
`ready=1/pending=0/failed=0/held=0` cycles, and no retired registrations,
credential files or new leases. The old configured helper and configuration
remain **installed and unchanged**. The revised caller pair no longer imports
or invokes the legacy integration; the retained `integrations.enabled=true`
configuration is deliberately ignored by those callers.

| Installed tool | Required SHA-256 |
|---|---|
| `suite_onboarding.py` | `73f59a8a9ec1d4d2d3bbdd8d1ccb1fd22d1ed9f17e19719fcf1c9dd62d1a2019` |
| `managed_client_onboarding.py` | `537dc4416846e537e7901b4f1a91bda69690a61fd9b3904c80991752ce8410af` |

ID's recovery dossier is
`/srv/8west/apps/ewid/deploy-state/tool-backups/20261106T172417Z-baebc339`.
The accepted postflight receipt SHA-256 is
`c8823c8ee44412e0997888ce7b4a6ecb8daa1e77e3a5beb5eeef8dd4cbbdca84`;
the natural-cycle receipt SHA-256 is
`0d69f3c6b83092b58e3f9ce7ca6028ecb82cafecc463cc4cce4c35f3f6346c03`.

**Recovery must retain this same new tool pair.** ID current source `1027430bd94a`
and its supported application inverse `73cf8ba93971` were qualified with that pair.
With Safeharbor's files removed from production, the old tool pair is an
**unsupported recovery**: a whole-host restore must overlay and verify the new
pair **before the onboarding timer resumes**. Keeping an archive does not grant
authority to run the old integration or re-enable Logbook.

Safeharbor's stock `deploy/deploy.sh` keeps private `config/config.php`, includes
deleted runtime files in the deployment backup's `deleted` and `files.list`,
and restores them from `files.tar` during its source inverse. The pre-removal
#298 eight-file and #296 five-file inverse inventories did not contain these
export files or private config. The November 6 removal verified its own exact
deletion/backup inventory and the ID pair above; those older inventories are
not the removal's rollback receipt. Since the stock release ships `app/`,
the actual removal inventory also includes `tests/logbook_export_test.php`
(six files deleted in total) and the changed `config/config.sample.php`; paths
inside `current/` omit the repository's `app/` prefix. No directory is removed.
Restoring Safeharbor source does not
authorize restoring the old ID tools or changing disabled export settings.
Integration, allocation, stock deployment and live postflight followed the
separately approved preservation and release sequence below.

## November 6 production release and preservation

PR [#300](https://github.com/Seckcey/8_west_helpdesk/pull/300) merged as
`a33dd91422128fc97afb299ba2c9918c4cb1a737`; exact-main
[Validate 42264765706](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42264765706)
passed. One stock deployment completed **November 6 at 18:42:32 UTC
(10:42:32 AM PST)**. All 418 prepared runtime files and 23 directories matched,
including the runtime user's reads. Six files were deleted and four replaced:
the sample configuration and three stock asset stamps. No migration ran.

The new stock inverse is
`deploy-state/backups/20261106T184229Z-48a1790e06b8-to-a33dd9142212/`.
Its `files.list` and `files.tar` contain exactly the ten deleted/replaced preimages;
the tar SHA256 is `d4b08357b75443d1bcfa0136e416ee584492e4a598bd340b58023a288c61493e`.
All ten file hashes, numeric ownership, modes and nanosecond timestamps matched the
fresh baseline. The extended ACLs on `public/login.php` and the removed export
endpoint matched. This verifies the archived inverse; no production restore was
performed. An older derived evidence summary rounded four timestamp integers;
comparison of the retained raw captures established no host drift. The actual
inverse was checked against exact integer timestamps.

Before release, the owner explicitly approved preservation and subsequent normal
rotation of `20261104T003940Z-ab4b07690b3f-to-7be64ab4fb69`. A single copy placed
all eleven files, including the historical database snapshot, under
`deploy-state/protected-recovery/` with the same basename. Its sibling custody
receipt SHA256 is
`4e0e1db567e6e4a164e324f1594741348e1e9c866b565c29565fe4289527d7e3`.
The source and copy have separate inodes and matching bytes/metadata. Two
independent witnesses accepted them at **18:31:48 UTC** and **18:32:13 UTC**, while
the original was present. Only then did the separate stock release run.

The stock ten-backup policy aged out only that approved original. Its protected
copy and receipt, the October 23 and November 1 protected sets/receipts, and all
nine remaining original backup sets were unchanged after deployment. Historical
retention does not authorize restoring the database, reactivating the export or
using the unsupported old ID tool pair. No private key, configuration or database
record was changed, and the protected database contents were not downloaded.

Postflight preserved private configuration, all six active cron files, both
timers, the required ID tool pair and the other PHP/Apache service states. Origin
and public login/CSS returned 200, root/portal redirected to sign-in and the
removed export returned 404. Signed-in customer acceptance was not exercised.

Natural reports at **18:45 and 18:50 UTC** recorded `GENERATED=0`, `SUBMITTED=0`,
`UNCERTAIN=0` and `ERRORS=0`; scheduled lifecycle claims were zero. The shared,
portal and due dunning jobs dispatched naturally. Onboarding completed at
**18:43:10** and **18:44:19 UTC**, each with `ready=1/pending=0/failed=0/held=0`;
desktop cleanup completed at **18:43:00** and **18:44:00 UTC** with exit 0.
No job was forced or replayed.

The pre-existing Westy-mail failure to open `cron/westy_mail_poll.php` remained
identical before and after deployment. The natural portal-maintenance runs at
**18:40:02** and **18:50:02 UTC** likewise reported the same missing
`db/maintain_portal_westy.php` input. These actors were not repaired and are not
claimed healthy by this release. Technical handback at **18:50:38 UTC
(10:50:38 AM PST)** verified no retained deployment/adapter process, deploy or
cold-window marker, paused cron or owned lock descriptor. Root-only execution
evidence remains retained; no further host cleanup was performed.

## Historical September contract (retired)

The September 5 release went live with the dedicated source enabled and service
identity 6 registered. Its first scoped Logbook import found no eligible
Safeharbor candidates; zero is a valid source result, not a failed connection.

`POST /api/svc/logbook_export.php` implements Logbook's existing schema-1
`solved_tickets` importer. It reads tickets and messages only; it never creates
knowledge, sends mail, changes a ticket or touches billing. Logbook makes the
quoted text into a draft for a person to review.

Logbook's [network/workflow diagram editor](https://github.com/Seckcey/8_west_logbook/blob/main/docs/operations/diagram-editor-guide.md)
went live in release `053c0ab`; see its [acceptance record](https://github.com/Seckcey/8_west_logbook/blob/main/docs/evidence/diagram-editor-release-2026-09-06.md).
A workflow drawing describes a process. It does not execute Safeharbor ticket
transitions, approve technician time, publish knowledge, send a message or
trigger billing. The editor uses Logbook's existing customer permissions;
it adds no source customer, service identity, mapping or export permission here.
This schema-1 source contract remains unchanged.

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

Historical validation: the now-removed `php app/tests/logbook_export_test.php` exercised the actual SQLite
queries under query-only mode, cross-tenant/customer exclusion, chronology,
internal-note exclusion, paging, inactive sources and the existing wire signature.
