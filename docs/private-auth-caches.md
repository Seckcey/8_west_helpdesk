# Private authentication caches

Suite JWKS and both revocation caches use a common Unix file primitive. It
requires POSIX, a canonical absolute path with a pre-provisioned same-process-
UID 0700 parent, and regular same-UID 0600 single-link files. Symlinks, unsafe
ancestors, public files and unprovisioned roots are refused. Runtime code never
creates, adopts or chmods a cache directory. Writes use exclusive temporary
files and atomic replacement; the suite revocation writer retains its validated
lock and read-after-lock version latch.

JWKS must come from the configured issuer's HTTPS origin, with certificate and
hostname verification, no redirects and a 256 KiB response limit. The private
envelope binds the exact URL and fetched time. Legacy raw JSON/mtime caches are
not trusted. Existing one-hour freshness, forced unknown-key refresh and the
maximum 24-hour outage grace remain. Revocation signatures, metadata MACs,
version latch, expiry, body limits and authorization rules are unchanged.

## Deployment contract

Provision `/var/cache/8west/safeharbor` as mode 0700 for Safeharbor's isolated
runtime UID, and its `portal-revocations` child likewise. Configure:

- `suite.jwks_cache_path`: `/var/cache/8west/safeharbor/jwks.json`.
- `suite.revocation_cache_path`: `/var/cache/8west/safeharbor/suite-revocations-v3.json`.
- `portal.revocation_cache_dir`: `/var/cache/8west/safeharbor/portal-revocations`.

The vendored PHP client requires an explicit `revocation_cache_dir`, unless
given a reviewed `RevocationCache` adapter. Preserve this file alongside the
vendored `eightwestid.php`, `revocations.php` and `private_file_cache.php` update.
Non-Unix consumers need an equivalent reviewed adapter.

Different directories under one shared web UID do not isolate applications.
The coordinator must review every executable PHP handler and cron identity,
issuer key/config readability and FastCGI socket access before production
activation. Leaving mod_php under the frontend UID while granting that UID
all FPM sockets does not establish isolation. Keep application code runtime-
nonwritable, app identities separate and frontend IPC unavailable to siblings.

Do not copy untrusted old temp caches or drop the one-way version latch. During
the bounded per-app maintenance window, warm the provisioned cache from fresh
authenticated issuer authority and verify its current versioned contract. Any
old snapshot migration must validate its MAC/signature, expiry and latch using
the reviewed verifier and protected configuration. Keep strict mode strict.

Back up exact source/config, loaded handler paths, identities/ACLs, sessions,
cache/latch state and scheduler definitions before the reviewed runtime change.
Preserve attachments, portal sessions, gateway routes and active agent work.
On failure, hold the affected app and restore the exact backed-up state; test
syntax before graceful reload and verify sign-in, revocation and ordinary
tenant/portal access. Record if rollback reopens shared-UID exposure. There is
no schema change or authority to rotate keys in this source candidate.

## Validation

The immutable `cache-tests-v4` snapshot passed 181 Safeharbor component checks:
private IO 38, JWKS policy 24, real loopback TLS 6, runtime revocation IO/races
19, existing revocation policy 94. The full three-repository snapshot passed
585. Archive SHA-256:
`156fb8847c99eacb86b76227abfd7f67d193644b3a3132d8a87f645533926518`.
The network-isolated Coastline container was removed after testing; synthetic
TLS keys existed only in its removed tmpfs.

CI runs all five Safeharbor commands above beside existing suite revocation
validation. On a nonroot runner, private IO reports 31 passes and seven explicit
root-only skips; all 38 were exercised in the isolated root run. The subsequent
skip-accounting edit changes output only. POSIX/OpenSSL/pcntl and TLS checks
fail if their requirements are unavailable.

The later `cache-repair-v5` checkpoint passed private IO as root (38, no skips)
and an ordinary UID (31, seven explicit root-only skips), the signed-envelope
0644 refusal/0600 acceptance pair, and all 60 suite SSO assertions against a
disposable MySQL server. Archive SHA-256:
`99198433f5e8bfa97c89aa49c8cbbb6913e12191c8d7bb5a921487a337e14ae4`.
Receipt SHA-256:
`99e72ba3226898da8372af2dabcde5edb125e4e4aa43a8cd955bdfa6c9ac229d`.
The coordinator verified all 1,152 source files against reviewed Git after
CRLF/LF normalization, retaining distinct raw hashes. All owned containers,
network and volume were removed and the actual shared flock was free.

The v5 receipt retains an initial Milepost child-session environment failure
and its corrected 87/87 rerun. No live source hash was captured between those
two commands. This limitation is not concealed by the archive comparison.
The later `cache-lock-v7` checkpoint passed the corrected source-bound RS256
fixture through the actual production wrapper under root and UID 65534, with
no OpenSSL skip. Portal authentication passed all 74 checks with the two
updated SDK identity pins and the added private-file helper pin. Real loopback
TLS passed six checks. All 1,131 canonical-LF source file hashes matched before
and after all nine commands across both applications. Its PHP-only container
was removed after four seconds and the normal flock release was verified.
Archive SHA-256:
`a6db0f8cc6d0b50519633e2e6866a41f32430c4b52955d8d47e4720b0b6911a2`.
Receipt SHA-256:
`e8f07ed112f7de24ec7040120269f78d31a57189f5443c46c86c2d75e55d401b`.
An earlier v6 launch found its script absent during transfer and created no
lock or container; v7 contains the final approved identity-pin fixture. Full
CI must still pass at the final frozen head.

All-app runtime isolation, private directory provisioning and live source/
config acceptance remain release gates. The optional Apache stream fixture
must refresh caches under the assigned runtime UID after its ownership
handoff; hosted validation currently exercises ordinary PHP mode. Component
evidence closes none of these production gates.
