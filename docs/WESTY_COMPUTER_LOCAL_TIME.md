# Selected-computer time in Westy (next release)

Westy's computer picker now has a time line for the selected computer. The line
uses the timezone reported by that endpoint through Milepost inventory. Switching
computers switches the timezone; the browser, account and tenant timezone never
substitute for it. Unknown/expired evidence says **Computer timezone unavailable ·
times shown in UTC**. Signing out clears the cached device evidence.

The time is calculated from the service's UTC instant and monotonic elapsed time
in the page, using the selected IANA timezone. It is labeled **last reported
timezone** and is not a live measurement of the endpoint clock. The display
updates every 30 seconds without new device requests. A page reload or existing
device-list refresh retrieves current inventory evidence. Native Companion uses
this same portal/WebView surface; it does not use the technician's computer zone.

Westy's computer-list tool also gets deterministic local current-time and
last-check-in strings beside its unchanged UTC facts. Time grounding applies the
selected computer zone to human-readable references, using the offset at the
event instant rather than today's offset. Repeated DST hours include their
different offsets. No computer selected or unavailable timezone means explicitly
labeled UTC. Raw receipts, quoted output, durable replay, execution deadlines and
date-only planning/calendar entries are not rewritten. No command or provider
probe is run just to find a timezone.

## Optional wire contract

The existing `devices` result accepts `computer_time` as absent, null, or:

```json
{
  "schema": "milepost.computer_time.v1",
  "timezone": "America/Los_Angeles",
  "reported_timezone": "Pacific Standard Time",
  "observed_at": "2026-07-01T12:00:00Z",
  "received_at": "2026-07-01T12:00:00Z",
  "expires_at": "2026-07-01T20:00:00Z",
  "server_now": "2026-07-01T12:01:00Z",
  "source": "agent_inventory"
}
```

Only a recognized IANA zone and exact versioned keys are accepted. Evidence
expires eight hours after the server received inventory; endpoint observation
and receipt may differ by at most five minutes. Malformed or stale optional
clock metadata becomes null, without blocking the computer list or authorized
tools. Existing producers and historical rows without the field keep working.
Milepost owns Windows-to-IANA translation and the reported-offset check.

**Deploy this Safeharbor consumer before the Milepost producer.** The previous
strict consumer rejects unknown device fields. On rollback, stop producing the
new field before reverting this consumer. The corresponding Milepost contract
is in [PR #645](https://github.com/Seckcey/8westit_webapp/pull/645).
No new endpoint, schema, credential, permission guard, or provider configuration
is required. Candidate prompt/material approval consequences remain a future
release-review gate; no live approval or proof timestamp is changed here.

## Validation and boundaries

- `php app/tests/portal_computer_time_test.php`: 23 normalization, compatibility,
  actual device-result, model presentation, DST and cross-device checks.
- `node --test tools/shots/computer-time-contract.test.mjs`: five tests covering
  browser-clock independence, timezone selection, DST gaps/repeated hours,
  half-hour offsets, UTC, expiry and the PHP consumer checks.
- Existing `tools/shots/portal-contract.test.mjs`: all 17 browser scenarios
  passed with the new helper asset available; existing scenarios are unchanged.
- Real PHP workspace shell and production JS/CSS rendered through a synthetic
  Playwright router at `http://safeharbor.test/portal/`. Desktop 1100×800 and
  mobile 390×844 passed: Pacific/Eastern switching with browser timezone Tokyo,
  explicit unknown-zone fallback, no clock-line clipping, sign-out clearing,
  no console errors, and GET-only synthetic requests. Screenshots and the
  command transcript are retained as task artifacts, outside this repository.

Browser plugin was unavailable; validation used bundled Playwright/Chromium.
No test contacted a provider, production service or endpoint. These are source
and synthetic UI checks, not deployed/native installer or physical-device
acceptance. The current control repair, reply-action UI, stored UTC history,
employee entitlements, signing and production release remain separately owned.
