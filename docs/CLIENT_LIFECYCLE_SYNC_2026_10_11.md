# Client lifecycle and suite sync — October 11, 2026

Source integrated in PR #214. MAIN records the reviewed runtime
`55211b9a0f027844b8121e6807abe754f0d6601d` installed on October 11, with original
reporting restored. See the [release and recovery evidence](SAFEHARBOR_CLIENT_LIFECYCLE_RELEASE_2026_10_11.md).
The affected customer's actual delivery, signed-in selector and Frankie
acceptance remain **Not tested**; broader relevant-change sync is unfinished.
Tracker `LOCAL-b1eb60ff-c8f5-4f8a-86f4-c23f2c92d6bc` is titled
**When i open a support ticket in safeharbor, it still shows deleted clients**.
The full original requirement remains:

> milepost, safeharbor and coastmark apps should all sync together.  When a user makes a change in one app, the relevent changes need to update in the other 8 west IT 365 apps milepost, safeharbor and coastmark.

This change addresses new staff ticket choices and stale submissions after
Safeharbor receives the customer's lifecycle event. It does not complete the
full requirement. MAIN owns the tracker and shared release sequence; each app
retains its integration and data ownership.

## Existing source contract

Read-only inspection used Safeharbor base `ddebb89`, Milepost
`47118f150db9d3620386c412b97bd44457d39206`, ID default branch `master` at
`67944ca8b510b79dc8867c985fb162f83fc7d898`, and Coastmark
`b1fcd6c341b556b1779e2ab57dc99d1635ff74ef`. Primary checkouts were not changed.
These are source observations, not a receipt for a real customer's deletion.

| Owner / consumer | Existing behavior and boundary |
| --- | --- |
| Milepost / `Seckcey/8westit_webapp` | `portal/public/clients.php` calls `mp_managed_customer_identity_delete_client()`. The tenant transaction retains a stable UUID and freezes the next ordered inactive v1 event before deleting the eligible local client. The reserved reclaim client and assigned-computer/Android ownership checks remain. Already identity-bound customers also freeze their v2 ID event in that transaction. Legacy v1-only customers do not gain an invented ID binding. |
| Safeharbor | Dedicated authenticated `api/svc/customers.php` consumes v1 events by stable customer UUID and contiguous source version. `suite_customer_sync_receive()` locks tenant, binding, then retained client. Inactive changes the binding and immutable receipt, preserving the local client, contacts, tickets, messages and other history. `clients.source_key` remains separate partner-support routing. |
| 8 West ID | Dedicated v2 intake retains immutable binding/receipt history. Its ordered lifecycle reconciler suspends the lifecycle-owned tenant and rotates its OAuth generation; restoration requires the unchanged lifecycle marker/generation. User deletion and operator/security holds are separate. It does not delete downstream help-desk or accounting history. |
| Coastmark | `app/Livewire/ClientsIndex.php` saves local active/inactive status without a propagation hook. Organization-scoped `external_id` / `source_client_key` uses `milepost-customer:<lowercase UUIDv4>` for existing time/billing mapping. Client/agreement/mapping readiness remains financial-owner policy. Historical clients/invoices/agreements and restrictive foreign keys are retained. No canonical customer-deletion propagation endpoint or bidirectional client-edit contract was demonstrated. |

Milepost's destination outboxes have their own delivery gates and ordered
predecessor/replay rules. One destination's receipt does not prove another
destination accepted the event. Delivery may lag a committed Milepost deletion;
this source fix cannot identify a retained legacy duplicate by name or email.

## Safeharbor correction

`app/lib/ticket_clients.php` projects the existing operational predicate in one
tenant-scoped client query after the existing enrolled-provider check. It
excludes inactive customers and active-after-inactive customers without a
restoration receipt for the exact current binding/customer/version/event.
Contacts come only from the returned eligible client IDs and exact tenant.
Unbound local clients retain existing behavior. Query count is independent of
the number of choices; provider checking occurs once for the list.

`app/public/ticket_new.php` calls the canonical
`managed_customer_operational()` through the creation helper in a fresh ticket
transaction. It takes `FOR SHARE` on the tenant before provider/binding/client
reads, matching the sync receiver's tenant-first lock order. If inactive commits
first, ticket submission waits and rejects the client before service-goal,
ticket or message writes. If ticket creation locks first, its complete active
lifetime ticket/message commit precedes the inactive event. Rejected forms
reload eligible client/contact choices and explain that another client is
needed. Client contact, CSRF, assignment and service-goal behavior remain.

This uses lifecycle facts, not a new optional execution guard. It changes no
schema, transport, configuration gate, portal/account/device permission,
financial fact or historical record.

## Verification and remaining work

The existing `managed_customer_lifecycle_mysql_test.php` now checks list parity,
contacts, unbound clients, cross-tenant/missing clients, inactive history,
exact-current and stale restoration, enrolled-provider availability, and both
real concurrent producer/ticket lock orders. All 57 checks passed on a uniquely
scoped synthetic MySQL 8.0.46 instance on `ssh coastline`. Existing hermetic
managed-lifecycle and customer-sync suites passed; customer sync reported
57 checks with zero failures. The changed PHP files passed lint.
The established visual walkthrough passed against the synthetic instance;
focused browser/server checks passed for eligible managed creation, legacy
creation, stale form rejection with unchanged ticket/message/goal counts,
inactive and unrestored customers, foreign/missing client IDs, and a foreign
contact being cleared. Desktop and 390-pixel mobile screenshots were inspected;
the form and error fit without horizontal overflow. Normal deployment brand
assets were staged only in the disposable instance for the final captures.

The broader request still needs an agreed per-field authority and change
contract for relevant edits made in each app, including Coastmark-origin
active/inactive changes, Safeharbor-owned client/contact edits, and any account
or user changes. Those require their own stable identifiers, tenant provenance,
ordered/replay-safe delivery, conflict/restoration rules and compatible consumer
changes. This PR does not introduce that protocol, turn on delivery gates,
backfill legacy bindings, send invitations, or purge records.

For customer acceptance, the release owner must verify the affected
customer's actual Milepost outbox lineage and Safeharbor receipt/current binding,
reconcile delivery failures or an unmapped legacy duplicate through the existing
owner workflow, and verify the real new-ticket user path against the installed
source. Availability checks and source tests do not prove live cross-app
propagation or owner acceptance.
