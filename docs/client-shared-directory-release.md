# Client shared directory — release receipt

**State: source candidate on branch `claude/T-20261023-01-client-shared-directory`. Not merged,
deployed or verified in production.** This page records what the change does and what must be
true before it ships. Update it when the release owner merges, deploys or checks it.

## What changes

| Surface | Change |
| --- | --- |
| Clients list | Each row keeps its local name, domain, SLA tier and open count, and adds the last received Milepost shared name and active/inactive state. "Local to Safeharbor" appears only when the lookup succeeded and found no binding; a failed lookup or a disagreeing receipt shows "Shared details unavailable". Buttons: **Support** (local client page), **Devices** (linked active clients only), **Billing** (the client in Coastmark for linked active clients, otherwise Coastmark's entry). |
| Client page | A "Shared client" line with the shared name, state, source version and **Shared details** in Milepost. The devices and balance cards and the Suite rail open the client's own Milepost and Coastmark pages for linked active clients. |
| Edit client | Adds **Open this client's devices in Milepost** beside the existing shared-details button. |
| New client | States that a client created here is local; shared clients come from Milepost. |
| Westy | The prompt names the new Clients buttons and the client-scoped Milepost and Coastmark links. |
| Styles | Client-directory styles only; the stylesheet cache token changes with them. |

The shared name and state come from the existing binding and its matching immutable receipt
(`client_operational_context.php`), never from the editable local name. No receiver, lifecycle,
schema, configuration, cron or permission changes. Local fields, privacy rules and handlers are
unchanged, and no new guard is added.

## Links and their authority

- **Devices**: `https://support.8westit.com/client.php?provider=<slug>&customer_id=<UUID>`. The
  provider is the binding tenant's own slug. Milepost signs the user in first, then requires that
  slug to be the user's own active tenant and the UUID to be one of that tenant's current clients.
- **Billing**: for linked active clients,
  `https://coastmark.8westit.com/clients?provider=<slug>&customer_id=<UUID>`, built by
  `client_operational_billing_url()` from the same slug and UUID. It opens that client only once
  Coastmark ships its provider + UUID route; until then Coastmark's existing Clients list opens.
  Coastmark checks the user's organization and its own audited binding first. Every other row
  uses Coastmark's existing suite entry, `https://coastmark.8westit.com/auth/suite`.
- **Support**: the local Safeharbor client page.

## Release order

1. Milepost's provider + UUID device route is deployed and checked first.
2. Then this Safeharbor change can deploy with `bash deploy/deploy.sh` (no migration).
3. Check a linked active client, an inactive one, an unbound one and a client of another MSP
   tenant in a real browser on a disposable copy before production.

## Checks to run (owner)

- `php app/tests/client_operational_context_test.php` and the existing customer-sync, lifecycle
  and identity-evidence suites in the normal CI jobs.
- The browser job and a visual check of the Clients list on desktop and narrow screens.
