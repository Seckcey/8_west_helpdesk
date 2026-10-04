# Westy portal and companion integration - October 4, 2026

Status: assembled candidate; production and actual Windows acceptance pending.
The existing 8 West IT 365 coordinator owns integration and release.

## Customer behavior

Questions asked in the portal keep their answers in that portal conversation.
Westy can use reviewed Milepost device diagnostics and repairs, and a connected,
paired Windows companion for locally authorized desktop tasks. Questions asked
in the companion receive their answers there. Both retain the original cloud
conversation, device scope, task and central action/approval/outcome records.
Reconnect cannot create a duplicate task or replay an uncertain action.

Supported installed device capabilities are allowed by default; tenant settings
can restrict them. Dangerous changes require the exact human approval. Desktop
input additionally needs the current user's selected window and local consent.
The portal discovers connected companion availability automatically and does
not instruct customers to open an uninstalled companion. Stop remains available
for active work even when the companion disconnects.

MSP owners configure provider, model, effort and API key in 8 West ID settings or
MSP onboarding, with Skip AI available. Managed customers never supply an AI key.
Ordinary support and device workflows remain usable without AI or a companion.

## Source assembly

The private merge `6175b1fbfd2e756a162b5c6c09d17a73de071d03` retains the device
and desktop component histories, endpoint availability correction `fcce93d1`,
and frozen tenant-AI component `1c711d39` from PR 169.

The coordinator accepted the endpoint's two-file correction `9d9c1300` as
`a8ac45fc`: an expired request retains its original typed text while the browser
reconciles its receipt, and the setup download link keeps readable contrast.
Tenant-AI follow-ups `ed51cb3c`, `02ceceac` and `15665e30` are included as
`aa7ed9c`, `8167716` and `111ad04`. They bound text delivery, exercise absent
optional native schema explicitly in disposable fixtures, and document measured
throughput. No protected controls, schemas, SDK or ID limiter changed.

The Astra owner resolved three shared files. The standalone AI migration exactly
matches the reviewed AI component, including its exact-schema guard and 90-day
plus 60-second retention boundary. Device instructions retain the reviewed
hardware observation and read-only-versus-repair reservation guidance. The fresh
schema preserves every original device/desktop DDL byte and the complete final
AI block; the independent desktop block precedes AI so the strict-loader `DO 0`
terminator remains at EOF. The coordinator independently verified these bytes.

## Validation and release boundaries

Each component passed its own relevant checks. Final AI PR 169 passed hosted PHP,
browser contracts and customer mobile isolation at exact `1c711d39`. The focused
native-helper overlay at `00ad6073` passed workspace 94, real PHP HTTP streaming,
concurrent Stop, logout/revocation and mutable-AI delivery 176 checks. Its log SHA
is `a78ba188e991da77d111efd2276954ca4a1d702b5c6716318e76f2a172d96c99`.
The later AI schema change adds only the harmless fresh-schema terminator.
The rendered companion availability fixture passed 24 checks at desktop/mobile
widths. These proofs use isolated databases and synthetic providers.

Current customer and MSP AI authority is checked before output/persistence and
final accounting. Once authority is lost, restoring a connection cannot resume
the same attempt. Earlier valid text and known usage or unknown reservations
remain recorded. Ordinary Stop does not require optional native schema; genuine
native attempts still refuse unavailable authority rather than claim cancellation.

The earlier aggregate `d199b9a` failed three checks: an absent-native-schema
fixture now included that schema, the download link's contrast, and retention of
typed text during delayed expiry reconciliation. The accepted follow-ups address
these findings without weakening assertions. Final combined hosted CI remains
required before merge.

Actual signed-ID, real-cURL synthetic streams reproduced exhaustion of the
1,200-resolution-per-application-per-minute limit before the correction. The
first complete UTF-8 delta now emits immediately; later text is buffered up to
128 bytes or 100 milliseconds, while non-delivering heartbeats use the existing
one-second liveness check. Every actual save, emission, tool and final delivery
still checks fresh authority. Failed delivery discards pending text, retains
known usage or unknown reservations, and never retries the paid attempt.

The corrected single 3,366-byte reply completed in 8.80 seconds with a 225 ms
first delta and 143 signed resolutions. Two genuine concurrent replies completed
in 8.79/8.83 seconds with 286 combined resolutions and 8.49 seconds of overlap.
An actual ID disable during another fixture suppressed all later text while
retaining its unknown reservation. Visible and stored reply hashes matched.
Final evidence includes ledger 89, delivery 318, workspace 115 and HTTP
Stop/logout/revocation checks. The raw final log SHA is
`c7849423f0db38beb8c6037aa446b0e4da6a65baea584153c05f5231bdbd5f1e`.
These are bounded synthetic throughput proofs, not production provider latency
or general capacity results. No paid calls or actual customer repairs were used.

Release requires final ID/Milepost coupling, reviewed bindings and protected
configuration, source-gap and in-flight checks, held locks, complete recovery
evidence and actual scoped writer closure. Follow the schema-only window in
[TENANT_AI_RELEASE.md](TENANT_AI_RELEASE.md), explicit accepted reopening, the
separately reviewed exact receipt DELETE grant, then a fresh source-release
window. Do not change an original intent to accommodate later grants or source.

Existing accepted Milepost agents/installers can support a server-first release
after those gates pass. Every newly built full/lite Windows package includes the
unaccepted companion, so signing/publication/version-target changes remain held
until an assigned isolated standard-user Windows machine passes native acceptance.
Build and fixture results do not establish actual browser control or installation.
