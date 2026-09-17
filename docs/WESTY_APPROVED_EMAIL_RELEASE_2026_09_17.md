# Westy approved advice email — September 17, 2026

## Scope and boundaries

Case 614 is an internal memory alert for 8WV-FRANKIE, Safeharbor tenant 1,
client 16 (8 West Ventures, LLC), suite customer
`d2cd5603-512e-46ab-b4c4-469563695b2e`. Frankie requested documentation without
changing the laptop and an approved advice email to `frank@8westit.com`.
The internal note is saved; the case remains open and human-owned.
A customer contact was created through the normal signed-in client page.

This release adds case-scoped endpoint POC selection, explicit customer POC
fallback, editable memory advice, exact owner/admin approval and a durable
single submission. Contact, customer, evidence or authority changes stop stale
approval. Graph 202 is acceptance, not inbox confirmation. Uncertain sends do
not retry. No computer job, ticket resolution, invoice or time entry is created.
Activation is limited to this internal customer; other customer email scope is off.

## Source and validation

- Implementation PR #125: `3d9f080c4d7b86c90347fcf97926473484866630`.
- PR Validate `35281986979` passed all required jobs.
- Exact-main Validate `35282265375` passed all required jobs.
- Coastline synthetic MySQL: 32 approved-email checks, including competing
  transactions, an actually killed sender, lost response, duplicate prevention,
  revocation, contact change/revert, human evidence and missing-guard refusal.
- A full-suite test exposed a test child repeating CREATE DATABASE while the
  parent held a transaction. The child now connects to the parent-created
  fixture; all 55 service-goal publication and permission-race checks pass.
- Full canonical schema and service intake loaded in a separate synthetic
  preview; desktop/390px review, fallback selection, draft save, unchecked
  approval and missing-provider refusal passed. No synthetic email was sent.
- Own Coastline containers/network and Windows SSH preview tunnel stopped.
  Desktop Docker remained off.

## Protected release record

Production is `milepost-ec2`, hostname `ip-172-31-31-195`, Safeharbor only at
`/srv/8west/apps/safeharbor/current`, database `safeharbor`.
Fresh root-only backup:
`/srv/8west/backups/safeharbor/20260917T222410Z-pre-westy-email`.
Application/config/database/grant checksums passed. The scratch restore proved
47 base tables, 100 triggers and six time entries, matching production; only
that scratch database was removed. Credentials and database contents remain
protected on the host.

Exact migration 026 SHA-256:
`ffe890762c51aa70a98711a0fcf19d3ff71e2e800b70cf207708528e83d37011`.
The Safeharbor-only freeze began at 22:32:31 UTC. Migration and exact replay
passed: 49 base tables, 110 triggers, both new tables initially empty. A
byte-identical data-only dump of all 47 existing tables before/after migration
proved existing business records were unchanged. The runtime received only
EXECUTE on the new health function, which returns 1 under www-data.

The matching clean detached main was deployed under the exclusive report lock.
Source artifact: `95071c50d0fcac4d41da26e2309786b6a7d94ec4d3f92edd2507fa5723fda776`.
Deployed artifact: `14a9b28c13f3963f68d4479f6b238eb91fcdf65a9d42e1bbccf612e46666bdb1`.
The initial activation helper refused the existing wrapped config layout before
writing anything. The corrected helper added only the internal email gate and
proved all other parsed configuration exactly identical. Protected config hash:
`96e591db89808151d9ed8bb6b8e56b4d6291b6c105233d22d6fdfea3fc182efb`.

The exact Safeharbor vhost was restored and the runtime unlocked. Public login
returned 200; Apache/MySQL remained active. Both Safeharbor and Milepost vhost
hashes, the minute cron and the business-report cron matched their pre-release
bytes. The report scheduler passed preflight and returned to active using the
same client-14 Lifestyle recipient/schedule and original confirmed canary facts,
rebound only to the new release/config. No replacement report was sent.

## Real case acceptance and remaining decision

Signed-in Frankie selected contact 13, Frankie Gonzalez / frank@8westit.com,
as the endpoint POC for case 614. No company-wide fallback was invented;
that fallback behavior was verified synthetically. Live desktop and 390px phone
review/approval layouts passed; no browser console errors were captured.

Draft #1 is saved with subject `[#614] Laptop memory alert — your options` and
sender `missioncontrol@8westit.com`. Exact body SHA-256:
`e99afdfc05e9629a242a8e1affcf5d37d2f8d907d67da17d9c9d0a67f70ac486`.
It remains `draft`, with NULL approval, attempt and provider status. The actual
wording is awaiting Frankie's approval; no email has been sent. Case 614 remains
open/human-owned and all six time entries remain. No laptop change or verified
repair is claimed. After approval, submit once and report Microsoft acceptance
separately from the recipient's inbox confirmation; reconcile any uncertainty.

## Rollback

Disable only `westy_email.enabled`; retain immutable drafts/attempts and schema.
Rebind the unchanged report scheduler to the resulting protected config hash.
Do not restore an old database over newer helpdesk writes, resend an uncertain
message through another path, or change Milepost's independent repair controls.
