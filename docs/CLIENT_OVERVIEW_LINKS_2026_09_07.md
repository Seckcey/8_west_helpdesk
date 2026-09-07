# Client overview: clear app links

The client overview previously displayed four “Phase 2” placeholders for
device details and billing. Devices and Balance now explicitly say “Not shown
here” and offer the existing Milepost and Coastmark entry links. The Suite
panel provides the same next steps and explains that the user selects the
client in the destination app. Westy's page guidance matches this behavior.

The URLs are identical to the existing signed-in suite navigation:
`https://support.8westit.com/` and
`https://coastmark.8westit.com/auth/suite`. The destination app continues to
enforce its own sign-in, entitlement and tenant boundaries. These links carry
no client identifier, credentials, billing facts or additional authority.
This page does not claim a device count, account balance or customer mapping.

This is a source-only presentation change. There are no schema, config,
worker, command, invoice or client-data changes. Desktop/phone rendering,
exact CI and the preserved-scheduler release record are recorded at closeout.

## Pre-release checks

Both changed PHP files pass syntax checks. The actual `client.php` was
executed with isolated synthetic read dependencies and the actual app CSS;
no runtime configuration or database was loaded. Chrome rendered the client
overview at 1440×900 and 390×844 with no horizontal overflow, missing
content or console warnings/errors. Both unavailable states, all four app
links and the client-selection instruction were visually inspected.

Clicking the actual View in Milepost link opened the signed-in Milepost
dashboard; View in Coastmark followed its suite entry route to the signed-in
Coastmark dashboard. Neither action carried a client ID or submitted business
data. Owned tabs were closed and viewport overrides reset. The temporary
fixture is outside the repository and its server was stopped after review.

## Released and verified

[PR 116](https://github.com/Seckcey/8_west_helpdesk/pull/116) merged and release
`70cb283f922ea6ab82c8f6f52fdcb97ac4a9f49e` was deployed on September 7, 2026.
Exact-head Validate `34113263638` and exact-main
[`34113633329`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34113633329)
both passed. The source artifact SHA-256 is
`5032a2c2d97e4d98761f0a7a66b4ec04fc2a0b7510b641f737d5d12b33289639`;
the complete deployed artifact SHA-256 is
`78df23e9495e10a5f0ecfd42477b519466d348fafc885b327c34b7061b235de2`.

The fresh verified root-only backup is
`/srv/8west/backups/safeharbor/20260907T105244Z-pre-client-overview-70cb283f922e`.
It preserves application/config/database, normalized schema, grants, scheduler
activation/cron, both shared-host vhost hashes and the preceding release
manifest. The report scheduler was restored and verified active with unchanged
config, runtime grants, cron contents and customer/recipient scope, under
`/root/safeharbor-report-scheduler-70cb283f922ea6ab82c8f6f52fdcb97ac4a9f49e`.
The normalized schema and both vhost hashes match the preflight record.

Production still has 44 base tables and 87 triggers. Migration 025 objects are
absent, both new workflow gates are off, and no new workflow scheduler exists.
The actual disabled billing worker exits quietly. No SQL mutation, new key,
configuration change, ticket/contact/time action or invoice delivery was
performed for this release.

Fresh signed-in Chrome acceptance on the live client #7 page passed at
1440×900 and 390×844: both unavailable states and the four canonical links are
present, all phase placeholders are absent, and the phone document measures
exactly 390px. The settled desktop/phone screenshots were visually inspected;
the tab reported no console errors or warnings. Client → ticket #141 → Queue
navigation passed, and the previously released Owner heading remains intact.
Owned tabs were closed and viewport overrides reset.
