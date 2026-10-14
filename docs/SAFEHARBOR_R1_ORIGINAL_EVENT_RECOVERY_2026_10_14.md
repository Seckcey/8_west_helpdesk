# Original inactive-event recovery — October 14

One authorized send of the unchanged original Milepost event produced Safeharbor
receipt 16. Client 19 / `8WV-` now projects inactive source version 2, and the
normal signed-in staff New ticket selector omits all three named choices:
`8WV-`, Email Intake and Milepost Intake. Retained client and receipt history
were not deleted. This documentation-only closeout changes no application code.

## Exact original event and receiver proof

The existing Milepost operator reported exit 0, HTTP 200, delivered true,
outcome recorded true, sender entered true and no delivery error. It sent only
original outbox row 14 / event `a634b21e-aa6a-4519-8d61-c55356e2c176`, customer
`fe3e1804-a3e9-4b63-b9aa-8ba3c3fe0396`, source version 2, inactive status and
name `8WV-`. Original raw request SHA-256 remained
`b763911af3c2ca1487efe056119f9c60068bf1db687d56910d0684c7cc820ab5`.
No new event, key or automatic retry was used.

Milepost's durable outbox recheck confirmed row 14 delivered at 14:24:14 UTC,
its original 243-byte payload and exact digest unchanged, the preceding outbox
row 9 retained, and protected configuration unchanged. Its owning release
session exited 0 and released the actual suite lock at 14:28:14 UTC. A later
independent host read found that holder PID and its FLOCK records absent.

Safeharbor's independent read-only snapshot at 14:25:40 UTC (7:25 AM Pacific)
queried the globally exact event first and strictly compared its customer,
tenant, binding, client, name, version, status and original payload hash.

| Field | Actual receipt/projection |
|---|---|
| Immutable receipt | 16 |
| Tenant / binding / client | 1 / 7 / 19 |
| Source version / status | 2 / inactive |
| Original occurred time | September 23, 08:11:20 UTC |
| Received time | October 14, 14:24:14 UTC |
| Current binding event/hash/time | Exact match to the original v2 receipt |
| Managed-customer operational state | false |
| Inactive history / restored-current-event | true / false |

Original immutable receipt 9 remains active v1, event
`fc8179b4-83c7-47c9-aae7-af1f640165b3`, request SHA-256
`d91c9219f178a276018d47c1bd11f5070ca1343a24a5ff0d60cd3cd066c01286`, with its
August 29 occurred/received clocks unchanged. Client 19 / `8WV-` remains present.
The read-only transaction performed SELECTs and rolled back; it did not call
authentication, send another event or write the receiver database.

## Normal signed-in staff selector

The existing authorized staff session opened `ticket_new.php` before the send,
then reloaded it after the exact receiver receipt was verified.

| Exact client | Before | After |
|---|---|---|
| 19 / 8WV- | Present | Absent |
| 6 / Email Intake | Absent | Absent |
| 7 / Milepost Intake | Absent | Absent |

The option count changed from 10 to 9. A label check also found none of the
three names after reload. The intake choices were already hidden by the
reviewed intake-purpose filtering in the installed Safeharbor 2c release;
the original inactive event resolved the remaining 8WV- choice. No option was
changed, no form was submitted and no verification ticket was created.
Before/after selector JSON and the rendered page screenshot are retained in
the owning task's paired-release evidence folder.

## Boundaries

Fresh pre-send receiver state was active v1 with only receipt 9 and no exact
target receipt. The one-event recovery followed the existing operator's final
row, credential, idempotency, transport, active-work and actual mutex gates.
The owner retains the separate Milepost durable outbox/configuration/lock
verification evidence; those checks and release completed before closeout.

The actual Safeharbor runtime remained source
`2c552151580cd9df7a00cb28b24e2dc9304ce1a6`, with its previously verified full
artifact and unchanged protected configuration. No receiver/source change,
new controller, fabricated session, customer hard delete, identity/profile
mutation, provider action or device command was part of Safeharbor's checks.

Historical unknown network outcomes remain unknown; this new exact receipt
does not invent a prior execution result. The receipt and normal selector proof
close the exact original event and these three named new-ticket choices, not
every deleted-client case, physical computer behavior or broader customer v2.
