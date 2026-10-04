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

Final aggregate CI and long/concurrent streaming throughput acceptance remain
required. ID permits 1,200 signed resolutions per application per minute; short
synthetic streams do not establish throughput under that limit. No paid provider
calls or actual customer repairs were issued for these fixture proofs.

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
