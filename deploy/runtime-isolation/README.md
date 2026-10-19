# Safeharbor private runtime isolation — offline candidate

`render.mjs` turns one reviewed JSON input into deterministic candidate files for a
dedicated Safeharbor PHP runtime. It is a review tool: Node standard library only,
no framework, dependency, bundler or service. It never creates accounts, changes
permissions, starts or reloads a service, edits cron or deploys, and every output
carries `certified: false` plus the unmet compatibility facts that only a separate,
owner-approved live acceptance can close.

## Selected Linux fixture qualification — October 15, 2026

Source commit `30090fa5e0063dad60c31d5df509e3bd47c6b9eb`, tree
`6a6e38b9c2c1d1388249f2dcf44f85520ad0e92b`, passed all 25 Node cases with
zero failures and zero skips in a disposable, network-isolated Coastline Linux
container. Both `bash -n` checks passed. All 590 exported source files matched
before and after. Test 17 reached its corrected installed-journal assertion
and the later partial inverse, replay refusal, untouched-inode and prior-hash
assertions. The source export manifest SHA-256 was
`ab70eb282515b11144b8c287e5837b56cef7f29a270d4893700ebc291e2200a1`.

The owner-held receipt `sh30090-r6-actual-linux-qualified-20261015.json`
is 17,473 bytes, SHA-256
`5bcb2a3d371c06fd7ef0a4c461f193266c3df8aabb5a219320a04fe2b36283c9`.
The run used the selected image `662773d56e2ad24cfff5d6b17324a4a1ceb39e53c37ff2479973ae46c2bc448c`,
six capabilities (`CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `FSETID`, `SETUID`,
`SETGID`), 768 MiB memory and equal swap ceiling, one CPU, PID limit 128,
a read-only root and no network. The operation completed in 20.982118 seconds
inside its original 600-second window. Native process custody and exact
container/task-directory cleanup passed; the owner's finite claim was released.

This qualifies the selected source fixtures. It does not exercise a production
cold window, the installed FPM/Apache services, real app UID or IPC boundaries,
provider behavior, database/grants or customer acceptance. Those checks remain
separate. Documentation changes after the qualified commit do not change its
code or extend the receipt to a different implementation. Earlier failed runs
remain historical evidence; this pass does not establish their cause.

## Offline commands

### Desktop cleanup readiness and selected runtime identities

The reader uses only `/etc/8west/php/runtime-identities.json`. Its closed JSON
shape is `{schema,apps}`, with schema `8west-selected-runtime-identities-v1`
and exactly `milepost` and `safeharbor` app records, each containing
`{mode,user,group,uid,gid}`. Property order is immaterial; duplicate, unknown,
missing, mixed-mode or malformed fields make existing cleanup readiness
unavailable. Account or file existence never selects the mode or provides a fallback.
Both `legacy` records select `www-data:www-data`, numeric `33:33`, even when
new accounts already exist. Both `isolated` records select their literal
`eightwest-milepost` / `eightwest-safeharbor` user and group names, with the
carrier-resolved positive non-root UID/GID pairs: neither number may be 33,
and both app UIDs and both primary GIDs must differ. Safeharbor checks its
actual effective UID and GID against its selected pair. For already-selected
isolated mode only, runtime NSS lookups validate both fixed user names' UID and
primary GID and both fixed group names' GID against the record. Missing or
mismatched accounts make readiness unavailable; they never choose a mode,
replace record numbers or allocate accounts. The pure parser and metadata
fixtures do not establish real NSS bindings. Supplementary groups
remain part of actual runtime qualification; the reader adds no allowlist.

The selector is a physical single-link `root:root 0644` file, nonempty and at
most 4096 bytes, with only its base ACL. Its ancestors must be physical,
root-owned and nonwritable by other identities. The selected `/etc/8west`
ancestor retains `root:root 0750`: owner `rwx`, base group `r-x`, other `---`,
mask `r-x`, and mandatory selected app UID entries with `--x` (one entry for
legacy 33, two for isolated). Additional root-selected numeric named users may
have `r--`, `--x` or `r-x`, so other workers can coexist without write access.
The actual Website inquiry grant is only `--x`; this reader does not request
listing access for it. Write, named-group, default and unknown entries are refused. ID's selected
ACL policy and full prior/after/inverse journal authorize those additional
users; the closed two-app record does not discover or identify a third app.
`/etc/8west/php` is `root:root 0755`, base ACL
only; other ancestors have base ACLs only. The reader checks protected
ancestry before opening the fixed record, then requires both selected apps'
traversal entries while retaining the same read/traverse-only rule for additional
users. ACL parsing remains bounded without a two-app entry-count cap. The
reader never broadens or provisions these paths.

`/run/8west-desktop-cleanup` is physical `root:root 0755`, base ACL only.
Each job directory and `status.json` must match its selected producer UID
and primary GID. Explicit legacy uses base ACLs only, directory 0700/file 0600.
Isolated directories have exactly owner `rwx`, named peer `--x`, base group
`---`, mask `--x`, other `---` (stat 0710); status files have owner `rw-`,
named peer `r--`, base group `---`, mask `r--`, other `---` (stat 0640).
Extra/default ACLs, group access and peer write grants are rejected. These
mode bits include the ACL mask; they are not evidence of group access alone.

For selector and status ACLs, PHP passes the already-open stream to child
descriptor 3 and executes the fixed argument array
`/usr/bin/getfacl -ncp -- /proc/self/fd/3` without a shell. Each query has a
250 ms deadline, 8192-byte stdout and 1024-byte stderr limits; missing command,
failed query, nonzero exit or any stderr yields unavailable. Descriptor and
named-path device/inode/metadata checks bracket the read. Selector and status
leaf comparisons retain full mode, ownership, link count, size, mtime and ctime
checks. Directories instead compare device/inode, directory type, full mode,
UID and GID, with exact ACL queries repeated before and after the receipt read.
Each repeated ACL query is itself bracketed by stable directory identity checks.
Directory size, mtime, ctime and entry link-count changes from legitimate
previous-receipt or run bookkeeping rotation do not invalidate an unchanged
valid current receipt. Mode, identity and ACL changes still refuse, including
ACL drift with unchanged stat timestamps. Directory ACLs use fixed paths:
root owns the job-directory namespace,
app users cannot replace it, and trusted-root transitions are serialized by
the carrier. This is not an atomic directory-descriptor claim or protection
against malicious root. Producers remain trusted for their own health facts;
peers can only traverse the directory and read its status. Milepost owns the
shared producer, units, mount and tmpfiles changes, including full write/fsync
and exact peer ACL on the temporary status inode before atomic replacement.
Its private previous receipt, run lock and payload retain 0600 without peer ACL.

Both receipts remain mandatory with the original schema, job and actual boot
checks, two successful runs, maximum 45-second run and 90-second freshness,
single-link/regular-file/inode checks, 1024-byte read limit and duplicate-field
refusal. No route guard, cleanup dispatch, replay or task authority is added.

ID owns installation and exact inverse through the existing joined generic
entry. Observed record absence may become the isolated record during
quiescence; create an explicit journaled legacy bootstrap only if new readers
need it before forward. Never fabricate a prior record. Install the selected
record and traversal ACL before new consumers/helpers. On inverse, quiesce
new readers and restore old source/helpers first, then restore or remove only
the operation's exact record/ACL from its journal. This durable public record
does not substitute for ID's private profile or current carrier target.

The existing no-DB readiness test runs with explicit root fixture provisioning
in CI and uses only a random temporary tree. Its explicit own-UID 33 arguments
check metadata, not actual UID 33 or two-UID execution. The existing MySQL runs and
control include chains run as actual `www-data` UID/GID 33 against an explicitly
provisioned legacy selector and job directories; migration commands retain
their existing identity and original checkout. Every fixture preflight guard
names its failing condition. If actual UID 33 cannot read either consumer
script in the original checkout, only those consumers use a task-owned random
`/tmp/safeharbor-readiness-ci.*` copy made by `git archive HEAD`. It contains
tracked source only, no runtime config or untracked files. Only the new task
root receives traversal permissions; the original checkout and runner home
are never re-moded or re-owned. Both scripts must be readable as UID 33 in the
chosen tree, and cleanup verifies the physical task prefix before deleting
only its own copy. They keep the real readiness reader and all existing
assertions. Windows parser/lint checks do not exercise Linux ACLs or fd3.
Exact Linux distinct-UID access, atomic replacement, protected selector and
forward/inverse acceptance remain pending in the one existing ID-owned route.

### Joined source composition — October 15, 2026

The current-main composition retains the qualified `30090fa` cold renderer
logic and templates. The installer cold dispatcher has one later exception:
`prepare` no longer requires the selected future runtime group to exist before
the carrier creates it; `apply`, `commit` and `inverse` retain that prerequisite.
The donor's Linux qualification does not cover this changed prepare behavior.
The added absent-group Linux case has no CI execution result; its local root
skip is retained. Later joined execution and its coverage limits are recorded
in the minimum-release acceptance record.
The composition adds the reviewed `c31fbb9` report,
cron and ordinary-installer UID ports, and includes PR #181's unchanged cache
PHP/tests. Its CI uses the current shards and its config preserves
`portal_westy.tools_enabled=true`. The existing ordinary-installer source test
now expects the dedicated runtime group and public-only frontend ACLs; the
other cold test cases and their refusal checks remain present. The historical
25-case receipt above covers its exact donor, not this composed candidate.
Four renderer fact descriptions now distinguish the composed private-cache,
cron and scheduler source from last-observed live metadata and identify the
carrier-selected current-source inverse. The renderer is therefore not
byte-identical to the cold donor. Fact IDs, held config reads, `certified: false`,
schema, guards and rendering algorithms are unchanged; description accuracy
does not establish runtime acceptance.
Real root/effective-UID/IPC/FPM/session/temp/report/cron/CLI/deploy qualification
must use the existing joined forward/inverse route. A local non-root skip
cannot replace that proof. No host or service change occurs during composition.

Local composition checks passed: all 243 hermetic business-report assertions,
16 changed PHP files through `php -l`, and six shell scripts through `bash -n`.
The 25-case renderer suite on Windows passed 13 cases and explicitly skipped
12 Linux-root cases. Node syntax checks passed. The 14 cache PHP/test blobs
match original PR #181 exactly; the installer cold dispatcher retains `5c21055`
apart from the prepare-group prerequisite exception above, and its ordinary
path matches the reviewed `c31fbb9` UID port. These checks
establish source composition and local behavior only. The October 16
minimum-release decision permits normal source integration while retaining
the existing joined run's three failures and unreached inverse paths. Actual
production compatibility and user-path acceptance remain with ID's existing
release procedure; source integration does not establish an installed boundary.

```bash
node deploy/runtime-isolation/render.mjs --input INPUT.json --check
node deploy/runtime-isolation/render.mjs --input INPUT.json --output /some/new/directory
node --test tests/runtime_isolation.test.mjs
```

`--output` requires a new directory outside the repository. Exit `0` renders, `2`
refuses with every reason as `code: detail`, `64` is a usage error. The input is a
regular file (never a symlink) with a closed schema
(`eightwest-runtime-isolation-input-v1`) and no repeated JSON keys.

## The contract it enforces

- **Identity:** dedicated `eightwest-safeharbor` user and private group, with a
  non-root, non-overflow UID/GID distinct from the frontend, the deploy owner and
  every declared sibling; no supplementary groups. One shared web UID with
  different directory names does not isolate apps.
- **Frontend:** `www-data` joins no app group. A `mod_php` frontend (or any PHP
  handler outside FPM) fails the boundary because the web UID can reach every app
  socket.
- **Code:** `/srv/8west/apps/safeharbor/current` stays `ubuntu:eightwest-safeharbor`
  with `2750` directories and `0640` files. It is never runtime-owned, group- or
  world-writable, or world-readable. The DocumentRoot is exactly its `/public`
  child, and the report-scheduler deploy lock stays outside it at
  `/var/lib/safeharbor-report-scheduler`.
- **Private directories:** `/var/lib/8west-php/safeharbor` with `sessions/` and
  `tmp/` children, plus the existing cache contract: `/var/cache/8west/safeharbor`
  and its `portal-revocations` child, both `0700` and owned by the Safeharbor
  runtime UID, with the same JWKS/revocation path meanings. Shared `/tmp`, code
  overlap and broad roots are refused. No cache configuration is edited.
- **FPM socket:** only `/run/8west-php-safeharbor/fpm.sock`, `root:www-data 0660`,
  in a `root:www-data 0710` directory. TCP listeners and listen ACL options are
  refused. The socket group is never the app group or a sibling group.
- **Handlers:** both `safeharbor.8westit.com` vhosts route PHP only through this
  socket. Another root sent here is refused; static/proxy-only roots get PHP-source
  denial.
- **Cron/CLI:** every script in `app/cron/` is routed to `eightwest-safeharbor` or
  explicitly unrouted with a reason (for example `business_reports.php`, which only
  runs through `run_business_reports.sh`). An unaccounted script is an error.
- **Config and issuer reads:** `config/config.php` is `ubuntu:eightwest-safeharbor
  0640`, never frontend- or world-readable, never public. The suite JWKS, suite
  revocation and portal revocation cache reads must be accounted for and stay held.

An optional `observed` list carries a read-only metadata snapshot of the target.
A configured path that crosses an observed symlink is refused; an existing private
directory with other metadata is reported, never adopted; unobserved paths are
listed as unmeasured.

## Outputs

`pool-safeharbor.conf`, `apache-safeharbor.conf` (an insert, not a complete vhost),
`provision-private-dirs-safeharbor.sh`, `cron-safeharbor.env`, `facts.json` and
`manifest.json`. The provisioning script verifies by default. It refuses when the
identity checks fail, never re-owns or re-modes an existing path, never recurses,
and contains no service, package, account, ACL or permission-change command.
Running it is a separate live step.

### Private-directory creation and its race

`--apply-missing` creates each absent private tree (`/var/lib/8west-php/safeharbor`
with `sessions/` and `tmp/`; `/var/cache/8west/safeharbor` with
`portal-revocations/`) in an order the app UID cannot race:

1. Every ancestor up to `/` must be a physical, root-owned directory without
   group or other write. A symlinked, app-owned, group-writable or sticky
   world-writable ancestor such as `/tmp` refuses before anything is created.
2. The top is created `root:root 0700`, so only root can enter it.
3. Its children are created `eightwest-safeharbor 0700` inside it.
4. Only then is the top handed to `eightwest-safeharbor 0700` by a second
   `install -d` on the directory this run created.

If the top already exists app-owned, `--apply-missing` **refuses** to create a
missing child, because the app UID could swap that path between the absence
check and the creation, and no later check can undo what root did to a swapped
target. Create such a child by a reviewed per-path step while the app identity
is quiesced. A failed check after root-only creation leaves the top
`root:root 0700` for review, which fails closed.

Unsupported prerequisites, which the script does not enforce:

- no other root process, package script or mount changes these paths during
  the run;
- the app UID holds no capabilities (`CAP_DAC_OVERRIDE`, `CAP_FOWNER`,
  `CAP_CHOWN`) and has no root-equivalent helper.

Checks of paths that already exist under an app-owned directory are a
point-in-time read only. The Linux race fixture in
`tests/runtime_isolation.test.mjs` swaps in symlinks as the app UID immediately
before every `install` call. It runs only as root on Linux. A Windows or
non-root skip proves nothing.

## R2 cold window (T-20261013-05 run 19, cold-current-base `6dcf3960`)

The Root-selected R2 contract replaces the earlier first-transition design
(universal zero work before the first signal, an nft admission seal, a
120-second whole attempt, a new pre-update backup copy). The single root carrier
is ID's `deploy/cold_window.py` (installed by root as
`/usr/local/libexec/8west/cold-window`); nothing here is installed or exercised.

- **Producers and frontend.** One 7200-second operation from its first host
  effect: by 15 minutes the carrier holds the report lock (a busy report run
  refuses, it is never taken over), inhibits the named timers, stops only the
  cron dispatcher (running jobs, including the business-report wrapper, keep
  their identity and finish; skipped calendar ticks are recorded, never
  replayed) and adopts a static-503 maintenance generation with the first of two
  planned same-parent pidfd `USR1` reloads. By 45 minutes the captured Apache
  generation, helpers and running scoped jobs have finished naturally, then one
  `WINCH`. Apply, start, loopback acceptance, public reselection and commit by
  90; 90-120 is reserved for the independent inverse; 120 is terminal.
- **Client.** `SAFEHARBOR_DEPLOY_MODE=cold bash deploy/deploy.sh` builds the same
  exact Git app (plus brand assets) as a reproducible archive, prints its digest,
  release SHA and source artifact digest, and sends nothing until
  `SAFEHARBOR_COLD_EXPECT_SHA256` equals the digest Root pinned in the permit.
  It then streams only that archive to `sudo -n /usr/local/libexec/8west/
  cold-window enter safeharbor`; no installer or hasher is uploaded, and `Live`
  is printed only for the carrier's ACCEPTED receipt for this release. The
  ordinary path now also prints `Live` only after the installer's own receipt
  lines for this release.
- **Installer cold phases.** The carrier runs its root-installed copy of
  `remote-install-safeharbor-app.sh --cold PHASE --cold-slot SLOT` with the
  report lock passed as an inherited descriptor (verified to be the reviewed
  inode, and verified held: an independent descriptor cannot take it).
  `prepare` keeps the payload, scheduler, config and tree gates, verifies the
  source artifact digest, writes the replace/create/delete plan and keeps a
  root-only copy of the exact current release record. On a first transition,
  `prepare` validates the carrier-selected runtime-group literal and existing
  legacy `www-data` app/config metadata without requiring that future group
  to exist yet. Staging stays root-owned; pinned controls, deploy-owner,
  scheduler, inherited-lock and source checks remain unchanged. The carrier
  creates identities before adapter `apply` and retains them until adapter
  `inverse` completes. If a before-adapter identity mutation fails after
  prepare, the carrier has not recorded the Safeharbor adapter-dispatch
  marker: it does not call Safeharbor inverse, and app source is untouched.
  Partial identity/report-freeze journal disposition remains the carrier's
  responsibility, not source restoration or permission to replay an unknown
  outcome. `apply`, `commit` and `inverse` still refuse a missing
  runtime group before any effect; the adapter never creates an account,
  infers a different group or falls back. The focused Linux fixture uses a
  genuinely absent future group and existing legacy metadata; local Windows
  skips do not qualify the actual first transition or carrier recovery route.
  `apply`
  writes exactly the planned leaves (deploy owner, runtime group, 0640, new
  directories 2750, frontend read only on public/ leaves it writes), verifies
  the source digest, keeps version normalization and records the deployed
  digest. Before any effect it requires each selected leaf to still carry the
  owner/group/mode and access ACL the carrier joined; it then holds the slot's
  `apply.lock` for its whole life and journals every new directory, temp
  sibling and leaf effect before it happens (an installed leaf's identity,
  bytes and access ACL before its rename; a new directory's identity, type,
  owner/group/mode and access ACL right after its creation, before anything is
  placed in it), so a failed or lost apply stays accountable and is never
  replayed. `commit` writes the immutable and current
  release records exactly as the ordinary path does. There is no broad
  recursive chmod/chown in cold mode. The active-scheduler gate stays in every
  phase: with the report scheduler active, the carrier itself holds its cron
  name and activation record inside the operation (renamed, never deleted)
  before `prepare`, and returns them exactly only while the release record is
  the one they bind; after a new release they stay held for re-enable through
  the scheduler manager with that release's evidence.
- **Accepted current source inverse.** The selected baseline is
  `6dcf396066a133956d93dc049c344e2fcff70df7:app`, tree
  `27e990b0dce9e1eb290d0c6c11aa26b0588e14fd`. The owner-held
  `suite-v21-sh-6dcf-current-source-inverse-20261015.tar` is 9,195,520 bytes,
  SHA-256 `0ceaf69df2bce803113ab32f090dbdbbf133672ed803fb7a4b2ecffa5b0afc13`;
  its `.canonical.manifest.json` sidecar is 107,259 bytes,
  SHA-256 `a43d621c2d181710c9e3944d21883ed86759c1d429799985ff9a0544a4c879f5`.
  The earlier `v2-sh-2c` archive and both of its sidecars are historical,
  not the current inverse. A canonical source archive is not physical prior
  metadata: ID's selected carrier input must join it to the actual baseline
  and preserve the separately captured owner/group/mode/ACL evidence.
  Each replaced/deleted leaf is
  joined to the live baseline; for `lib/render.php`, `lib/westy.php` and
  `public/login.php` the live installer `?v=` stamp is recorded and the exact
  live bytes must be reproducible from the accepted source plus that stamp.
  `inverse` restores those exact bytes and prior owner/group/mode, and the
  exact prior access ACL the carrier recorded as the map's 7th field (a
  public-read or custom entry survives; `-` means none), verified per leaf.
  It restores or removes a leaf only while the leaf is still exactly the
  after-state `apply` journaled: the same identity, bytes and access ACL, a
  regular single-link file with the deploy owner, runtime group and 0640, or a
  deleted leaf still absent. A leaf `apply` never reached (every leaf, when it
  never started) keeps its verified prior state and is not rewritten. Any later
  or foreign change to a leaf (bytes, type, owner/group/mode, ACL, identity, an
  extra link, a recreated deleted leaf) is named and preserved as a HOLD and is
  never overwritten or removed; the digest check that follows then does not
  run, so a matching tree digest cannot hide it. A directory `apply` created is
  removed only while it is still exactly the journaled directory (identity,
  type, owner/group/mode and access ACL), unchanged across a re-stat and empty;
  a replaced, drifted or non-empty directory, or one without its creation
  record, is named and preserved as a HOLD the same way. Before any leaf or
  directory is decided or acted on, its parent chain must be physical (no
  symlinked component, the parent its own physical path inside the app root):
  under a moved ancestor replaced by a symlink, each entry is named as a HOLD
  even when the leaf behind it still matches, and the ancestor and everything
  under it stay untouched. It removes only the temp
  siblings its journal names (a foreign
  `*.sh-cold.*` leaf is reported and kept), refuses while the apply lock is
  still held (an apply process may still act), and must reproduce the previous
  deployed-artifact digest recorded in `current-app-artifact.manifest`. Only
  then does the exact prior current release record return, and only over the
  record this operation committed (anything else is preserved, HOLD); the
  immutable records stay. A leaf outside the accepted source (for
  example a changed derived brand asset) is named and HOLDs; nothing is
  overwritten silently. Attachments, support-mail state, report state and the
  protected config stay in place. An inverse after a failed or lost apply or
  commit runs only when the root owner explicitly selects it through the
  carrier's disposition; it is never automatic.

Source artifact digests, repository trees, app trees and archive SHA-256 values
are distinct identities; none substitutes for another. The old `2c552151`
identities in earlier receipts are historical. The accepted installed-source
receipt and current inverse now bind `6dcf3960`, while `71af0d8` supplies the
current CI baseline. This source work performs no new live-state observation.

## Safeharbor runtime source composition

The reviewed UID/report/cron/ordinary-installer changes from frozen donor
`c31fbb9e` were included in the initial 37-path composition. The newer cold renderer logic,
pool and provisioner remain from `30090fa`/`5c21055`; the installer cold dispatcher
retains that source except for the later prepare-group prerequisite deferral.
The older donor does not overwrite those fixes. The included source:

- `app/cron/run_business_reports.sh`, `deploy/manage-business-report-scheduler.sh`
  and `deploy/remote-install-safeharbor-app.sh` pin `eightwest-safeharbor` and
  allow a test identity only under a physical `root:root 0755`
  `/tmp/safeharbor-scheduler-test.*` root.
- `deploy/safeharbor-business-reports.cron`, `deploy/safeharbor-westy-mail.cron`
  and `deploy/safeharbor-portal-westy.cron` name `eightwest-safeharbor`.
- `app/tests/business_reports_test.php:3115-3130` asserts these pins.
- `app/tests/business_report_scheduler_linux_test.sh` runs under any existing
  runtime UID, and `deploy/test_runtime_permissions.sh` reruns it as `nobody`
  and checks the override refusals.

The ordinary installer grants `www-data` traverse on the app root and read/traverse
on `public/`. Traverse on `/srv/8west/apps/safeharbor` and its ancestors is a
separate reviewed provision (under R2, an exact selected cold-window entry).

The prior deployment used the shared runtime. The composed ordinary identity
checks refuse until a coordinated cold transition makes these inputs agree:

- regroups the tree, config and lock;
- moves the host-only `/etc/cron.d/safeharbor` and `safeharbor-westy-billing`
  entries;
- regenerates the protected scheduler bundle (old activation evidence is never
  edited);
- moves the frontend to FPM.

Actual joined compatibility qualifies the reviewed immutable candidate before
conditional integration acceptance. Normal merge then requires final-main CI
and executable equality; activation remains in the serialized ID-owned route.
This source composition does not perform the transition.

### Freeze-profile pins: phase-correct proposal (not applied)

`deploy/tenant_ai_freeze_profile.json` records **installed** bytes, so it keeps
its current pins until the owner re-observes the host at cutover. The prospective
values below are for review only. They must never replace the installed pins
before those bytes are actually installed.

| Pin | Previously recorded installed value (reconfirm, do not overwrite here) | Target after cutover (if installed verbatim) |
|---|---|---|
| `/etc/cron.d/safeharbor-business-reports` | `0ccf6eb37945c35b30cd62fa752ca5ada155a88c12644cf517950c720b4068b6` | `08906c6d17be1c379f1c5760023a4904f42498cb331772a1dbe7d689a1a384de` |
| `/etc/cron.d/safeharbor-westy-mail` | `8f6825528e42f8327fa79f96c72be0d14358e5a9a55ab0df652db2f20b2fec6d` | `34f78fb9599b99a8a475799253a0fa8b9d91fbcd10e511f2ac5117cd26e13e1a` |
| `/etc/cron.d/safeharbor-portal-westy` | `7acf7b683ca579c085e995adcd75ac029bdb33dd4be6dcd5b193862b9359a7fd` | `6cb8de87be238c112cf203561fcd8c9ced94db1e60d575a4d26e31d61c2a43d1` |
| `/etc/cron.d/safeharbor`, `safeharbor-westy-billing` | `0c5e962a…`, `5f0b091c…` | host-only files with no source template; the owner re-observes them after moving their user field |
| report lock `business-reports-deploy.lock` gid | `33` (`www-data`) | the measured `eightwest-safeharbor` GID (prospective `1602` in samples; not a measured value) |
| both vhosts | `8f56a2dd…` (le-ssl), `8bc24d73…` (port 80) | regenerated from the installed FPM-routed vhost at frontend cutover; no target is proposed |

The target templates are included unchanged from the reviewed donor. The
installed observations above are historical inputs, not a new host probe;
the runtime owner binds the actual installed bytes and metadata at cutover.

### Still held

`app/tests/portal_stream_http_test.php` is outside this exact 37-path composition
and is unchanged from current main. The cache SDK now enforces runtime-UID
ownership. The optional Apache fixture still writes/refreshes cache records
from its parent and then assigns them to `www-data`; those operations must be
reconciled with the assigned runtime UID before using that fixture as cache
acceptance evidence. That separately owned test change must preserve current
child-process/database behavior; no old fixture version is imported here.

## Owner Linux checks (synthetic, isolated, unique resources)

1. `node --test tests/runtime_isolation.test.mjs` **as root on Linux**, with
   util-linux `setpriv`, so that the private-tree race fixture runs. Then run the
   rendered `provision-private-dirs-safeharbor.sh` in verify and `--apply-missing`
   mode against synthetic accounts and an empty synthetic root. Include existing
   mismatched and symlinked paths and an existing app-owned top with a missing
   child, and run `bash -n` on it.
2. `php-fpm8.3 -t` on the rendered pool and a dedicated master: frontend connects,
   `eightwest-safeharbor` workers and sibling UIDs (raw FastCGI included) cannot.
3. `apache2ctl -t` with the insert in a synthetic vhost: PHP through FPM, code
   beside the DocumentRoot denied, no PHP source from static roots.
4. Compare the FPM php.ini upload/post/memory/time limits with the current mod_php
   values so attachment limits are not silently lowered.
5. As root on Linux, with a `www-data` user and `setfacl` present:
   - `app/tests/business_report_scheduler_linux_test.sh` (default `www-data`
     runtime);
   - `deploy/test_runtime_permissions.sh` (the same suite as `nobody`, plus six
     override refusals);
   - `php app/tests/business_reports_test.php`;
   - `bash -n` on the changed shell files;
   - the cold-installer root fixture in `tests/runtime_isolation.test.mjs`
     (it runs only as root on Linux and skips elsewhere).
6. The exact-package finite guest stage for the carrier itself: maintenance
   adoption, dispatcher-only cron stop, 150-second request and job survival,
   independent inverse.

   `app/tests/portal_stream_http_test.php` stays with the private-cache lane.
