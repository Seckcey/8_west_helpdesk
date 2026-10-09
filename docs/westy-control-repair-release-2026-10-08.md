# Westy control repair release — October 8, 2026

Safeharbor's narrow browser/desktop receipt repair is deployed. The original
report schedule is active, and the current source, customer binding, schema and
cleanup checks passed. The paired private 1.26.12 installer was signed and
independently verified by the Milepost release lead. Installation and physical
browser/desktop acceptance remain with Frankie; neither is claimed here.

## Exact source and production release

[Safeharbor PR #194](https://github.com/Seckcey/8_west_helpdesk/pull/194) merged as
`a77615f43420e55d421043e6b851301e06ff2d8a`. Validate `37879403524` and mobile
validation `37879403497` passed at that exact main commit.

Production uses the accepted narrow candidate
`e50ad5198d9267fb1fa469c7a303f365de2b6694`, based directly on the previously
installed `74e36c17942103227e6e6f144adfaaf6e9a921f8`. Its repair deltas match
the reviewed PR. This deliberately excludes the intervening branding, catalog,
clock, feedback-schema and employee work. Those source-only changes remain
production pending; a later documentation merge is not their deployment.

The October 8, 9:45–9:55 PM Pacific window corresponds to October 9,
04:45–04:55 UTC. The existing receiver installed the canonical LF artifact
under the exclusive, nonblocking report/deploy lock. One configured Apache
graceful reload succeeded at 04:48:36 UTC; Apache remained active, and the
public sign-in page returned HTTP 200.

| Evidence | SHA-256 |
| --- | --- |
| Canonical repair archive | `c72aacb36e36e936642212eda889ffbf28d5b9100c225f6463a88929485409f9` |
| Clean source artifact | `79fde8947a1e6584a5907175d851e59fa095cccd1f19533bc1e450e68db84dfc` |
| Actual cache-stamped deployed artifact | `dc16f07cc9195f42925243bdb0106e9db4fff770b2377b35fe29c62887ac0b62` |
| Protected configuration, unchanged | `cfeed7737ca4c1ee2e59a7d36c69209650b5740d23ff3c8b8df15dcc2fd27601` |

The current protected artifact manifest and its immutable release record match.
Full runtime hashing and all three changed PHP file hashes matched the reviewed
candidate. No database migration, configuration change, new backup, provider
retest, device command or second Apache reload was performed.

## Reports and current readiness

The original report activation was captured before stopping new report launches.
The reviewed manager preserved the old cron and activation files. After the
deployment, the reviewed rebind retained the original sender, tenant, customer,
schedule, recipient, Graph acceptance, recipient confirmation and archive
evidence. It changed only the honest release/artifact binding and current
protected-gate review. Reports were restored and independently verified active
at 04:50:02 UTC. The original cron and protected configuration hashes remain
unchanged; no report or canary email was sent.

A genuine root-socket, plan-only schema inspection at 04:49:07 UTC verified the
exact table and both ordered trigger definitions. All four existing tool-run
rows and their original data digest were preserved. The original protected
migration receipt and intent hashes also matched. The application account
verified its required columns at 04:49:17 UTC but could see zero expected
triggers. That visibility limit does not establish trigger absence.

The independently pinned root proof is
`b77b2d5ea5cdeb5da943b9d6d07ef3e71a30224f47c5d27dae8aa3b1e04f4924`.
Its original 1,800-second validity ended at 05:19:07 UTC; it is now historical
evidence, not authority for a new readiness invocation. The copied consumer
retained that limit and all existing source, ownership, identity, selection,
schema and cleanup checks. Its 175 focused proof, binding and explicit-mode
checks passed.

Exactly one application-account read-only review succeeded at 04:58:57 UTC,
before the proof expired. Source, configuration, proof and active report
scheduler were unchanged before and after. The customer binding, existing
schema and cleanup were ready, with zero active tool runs or pending turns.
The selected assistant remained OpenAI / gpt-6-luna / Low, revision 4,
credential version 1.

That invocation made zero AI-provider requests and explicitly reported
`providerNotRetested=true`. The signed identity-service status request remains
a separate HTTP/audit effect. Earlier genuine provider evidence retains its
original source and time; it is not relabeled as a new provider test.
The read-only result's SHA-256 is
`04c232de5e471e74e1e8a4af58445416402c4301aa9dc038beb6a13ce767d021`.

The release lead separately observed ordinary signed-in portal startup requests
returning 200 at 04:50:34–35 UTC, with Westy/tools enabled and the target computer
listed. No chat message or control input was submitted. This is portal-path
evidence, not successful native browser navigation, Notepad editing or laptop
acceptance.

## Private installer and remaining acceptance

[Private signing run 37887789857, attempt 1](https://github.com/Seckcey/8westit_webapp/actions/runs/37887789857)
succeeded at 05:19:49 UTC from trusted main
`6ff485e61a2fb02e37bbc6502714f1453e3d7606`. The release lead independently
verified all 18 bundle files, 11 embedded MSI payloads and three valid
timestamped signatures against native source
`359a3a9df9e39bc2483a825d0da0056f8fd8db9c` and Safeharbor source `e50ad519…`.

The package identifies Agent 1.26.12, Companion 0.1.8 and MSI 1.26.12.0.
The MSI is 114,192,384 bytes with SHA-256
`2dfa3c65afc73ad0cdcb78991dd04057e53b94285c2452d7f3a2e20d7b508874`.
The release lead's independent verification receipt is
`8d9033809ba719002e289a60b86d296ea5478a49e1e11f0dbfcc98771f76d459`.

Staging remains with the existing laptop owner. Frankie owns installation and
all physical tests. The full signed-in-user control goal remains open through
that acceptance. Existing unresolved job 4446, unknown results, security work
and other owners' release claims were preserved.

## Retained recovery and cleanup

The exact canonical rollback source for release `74e36c17…` is retained with
SHA-256 `483c6818f7626636a820fd39b3db55d94ac9dc748fc43d337353775e80c86219`.
Its persistent root-only path is
`/var/lib/8west-release-evidence/westy-control-repair-12612-20261009-01a11d93/closed-source-transports/safeharbor-74-app-deploy-lf.tar.gz`.
The unchanged rollback rebind is retained in that release directory's
`consumed-readiness-sources/scheduler_rollback_rebind.py`.
Application rollback must account for the receiver's overlay behavior: the
repair introduced only `tests/portal_westy_replay_test.php`, with SHA-256
`32a64ff5cbae03a3e3c32c1b963dde9f386b895509cdb111d828575a0c1d7d3c`.
Remove that source file only after verifying its exact owned regular-file
metadata and bytes; changed or unknown material requires investigation.

Use the reviewed receiver/hasher and an exclusive report/deploy lock, preserve
configuration and attachments, record the honest newly cache-stamped rollback
artifact, then use the reviewed rollback rebind and manager to restore the
original captured report tuple. No database/configuration rollback or removal
of the tool-run ledger is authorized by this source rollback. It was not needed.

After the release lead explicitly released the Safeharbor-only temporary
namespace, seven exact helper/proof files and 13 exact transport files were
preserved under the existing root-only release-evidence directory and their
temporary copies were removed at 05:32:55 UTC. The original activation,
persistent report controls, migration evidence, rollback archive and local
receipts remain retained. The shared preflight namespace stays with its
existing owner; the exact staged receiver/hasher remain available for recovery.
No active or unidentified resource was deleted.
