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
