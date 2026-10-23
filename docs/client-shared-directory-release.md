# Client shared directory — release receipt

**State: source candidate on branch `claude/T-20261023-01-client-shared-directory`. Not merged,
deployed or verified in production.** This page records what the change does and what must be
true before it ships. Update it when the release owner merges, deploys or checks it.

## What changes

| Surface | Change |
| --- | --- |
| Clients list | Each row keeps its local name, domain, SLA tier and open count, and adds the last received Milepost shared name and active/inactive state. "Local to Safeharbor" appears only when the lookup succeeded and found no binding; a failed lookup or a disagreeing receipt shows "Shared details unavailable". With Milepost's current client directory read for the request, **Businesses** lists linked clients Milepost lists as active (with the last received state beside it when Safeharbor lags) and active Milepost businesses not yet received, and **Retained and local** keeps everything else. An unavailable directory lists every client as before and says so; a verified empty one says that instead. Buttons: **Support** (local client page), **Devices** (linked active clients only), **Billing** (Coastmark's existing entry). |
| Client page | A "Shared client" line with the shared name, state, source version and **Shared details** in Milepost, plus the client's current Milepost directory entry or "retained history". The devices card and the Suite rail open the client's own Milepost page for linked active clients; Balance and invoices open Coastmark's existing entry. |
| Edit client | Adds **Open this client's devices in Milepost** beside the existing shared-details button. |
| New client | States that a client created here is local; shared clients come from Milepost. |
| Westy | The prompt names the Clients sections and buttons and the client-scoped Milepost link. |
| Styles | Client-directory styles only; the stylesheet cache token changes with them. |

The shared name and state come from the existing binding and its matching immutable receipt
(`client_operational_context.php`), never from the editable local name. The directory itself has
no receiver, schema, configuration, cron or permission change. Local fields, privacy rules and
handlers are unchanged, and no new guard is added.

The same branch carries one separate lifecycle change for same-UUID restoration
(`managed_customer_lifecycle.php` plus forward migration
`032_managed_customer_preserved_active_restore.sql`, which replaces only the restore-receipt insert
validator). An exact current restored source event can then clear the latch while keeping an
already-active, never-contained portal and report schedule exactly as they are. The
[lifecycle contract](managed-customer-lifecycle-contract.md) describes it, with its apply and
recovery steps.

## Links and their authority

- **Devices**: `https://support.8westit.com/client.php?provider=<slug>&customer_id=<UUID>`. The
  provider is the binding tenant's own slug. Milepost signs the user in first, then requires that
  slug to be the user's own active tenant and the UUID to be one of that tenant's current clients.
- **Billing**: Coastmark's existing suite entry, `https://coastmark.8westit.com/auth/suite`, where
  the user selects the client. The client-scoped Billing link is a separate, later cut that ships
  only after Coastmark's provider + UUID route and every preserved binding are live.
  - Branch history: commit `6006d577` added that scoped link here before the owner froze this branch
    at the generic-Billing cut. A later forward commit on this branch restores the generic entry,
    with no reset or rewrite.
  - The later cut reuses the `6006d577` diff on its own owner-bound branch. This branch's tip keeps
    the generic entry.
- **Support**: the local Safeharbor client page.
- **Canonical Support target** (source candidate, A48/A51). Other suite apps link to
  `https://safeharbor.8westit.com/client.php?provider=<slug>&customer_id=<UUID>`.
  - **Resolver.** Staff sign-in comes first. The provider only asserts the signed-in user's own
    tenant slug; it never selects a tenant. The UUID must have exactly one binding in that tenant
    whose immutable receipt agrees, and it then opens that client's normal page, retained inactive
    history included.
  - **Refusals.** A malformed, reordered, duplicated, encoded or mixed (`id=`) query, another
    tenant's UUID, an ambiguous or drifted binding, or an unknown UUID gets the same 404 page. A GET
    writes nothing. Numeric `?id=` and the contact POST handlers are unchanged.
  - **Login return.** `login.php` validates `next` before its current-user and 8 West ID branches, so
    an existing or newly admitted staff session returns to it. The customer-role GET handoff still
    goes only to `/portal/`.
  - **8 West ID button.** For a canonical target only, **Continue with 8 West ID** becomes the fixed
    `https://id.8westit.com/launch.php?product=safeharbor&return=<encoded target>`. ID signs the user
    in, refreshes the suite cookie and returns to this `login.php?next=<target>`. Every other `next`
    keeps the plain `https://id.8westit.com` entry.
  - **Release gate.** This must be deployed and checked, with ID's matching launcher, before any
    consumer shows a Support link.
- **Directory read**: Milepost's `suite-client-directory-v1` with this request's verified suite
  cookie, as described in the [customer-sync contract](milepost-customer-sync-contract.md). It is
  never stored, and it decides only the Businesses / Retained and local presentation.

## Release order

1. Milepost's provider + UUID device route and its `suite-client-directory-v1` read are deployed and
   checked first.
2. Then this Safeharbor change deploys with the reviewed database change, database first:
   `bash deploy/deploy.sh --migrate app/db/migrations/032_managed_customer_preserved_active_restore.sql`.
   Applying 032 is its own owner gate. If 032 is held back, the directory part still deploys with
   plain `bash deploy/deploy.sh`; the new restore code then refuses to preserve an active surface,
   which is fail-closed.
3. Check a linked active client, an inactive one, an unbound one and a client of another MSP
   tenant in a real browser on a disposable copy before production.
4. Restoration stays off (`restoration_enabled` false) until the release lead's reviewed window.
5. Migration 033 (schema-2 admission carrier and lifecycle cursor) is a separate later database-first
   gate: `bash deploy/deploy.sh --migrate app/db/migrations/033_suite_customer_sync_admission.sql`.
   Until it is applied, a schema-2 Milepost event is a retryable 500 and the report wrapper's
   lifecycle child refuses for lack of its cursor; neither writes anything. Milepost must not mint
   schema 2 for Safeharbor before 033 is applied. Never replay 015 after 033.
6. Migration 034 (identity-only restore receipts, validator v3) is a further database-first gate after
   032: `bash deploy/deploy.sh --migrate app/db/migrations/034_managed_customer_identity_lifecycle_restore.sql`.
   Until it is applied, the scheduled mode refuses every restoration before any ID request; the
   separately run no-argument schema-2 restore is unaffected. ID must serve the schema-3 identity
   purpose before scheduled restoration is enabled. Never replay 024 or 032 after 034. The P9
   Lifestyle one-off is frozen for owner review in the lifecycle contract.

## Checks to run (owner)

- `php app/tests/client_operational_context_test.php` and the existing customer-sync, lifecycle
  and identity-evidence suites in the normal CI jobs.
- `php app/tests/managed_customer_lifecycle_test.php`, `php app/tests/managed_customer_lifecycle_scheduler_test.php`
  and, on a disposable MySQL server, `php app/tests/managed_customer_lifecycle_mysql_test.php`
  (024 + 032 + the scheduled mode + 034) and `php app/tests/suite_customer_sync_mysql_test.php` (015 + 033).
- `sudo bash app/tests/business_report_wrapper_linux_test.sh` for the wrapper's lifecycle child.
- The browser job, including `node tools/shots/client-shared-directory.test.mjs`, and a visual check
  of the Clients list on desktop and narrow screens.
