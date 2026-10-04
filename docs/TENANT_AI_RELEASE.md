# Protected tenant AI release procedure

This is an operator component for the agreed release owner. It is not a deployment approval, a general-purpose installer or a recovery command to run unattended. The source must be committed, reviewed and matched to the exact candidate. The ID and Safeharbor copies share the control flow but pin their own app, SQL catalog, lock paths and live resource profile.

## What the operator controls

| Component | Responsibility |
| --- | --- |
| `tenant_ai_window.py` | Holds actual release-lock descriptors, journals a scoped freeze, captures backups, invokes the protected PHP child and performs only an explicitly accepted reopen. |
| `tenant_ai_freeze.py` | Exact physical file replacement, durable journal, Apache generation/drain, app cron/unit/process checks and HTTP/HTTPS origin closure. |
| `tenant_ai_writers.php` | Fresh SQL privilege/account/session inventory. It never loads app credentials. |
| `tenant_ai_operator.php` | Root Unix-socket DB actions using inherited descriptors and the original freeze intent. |
| `tenant_ai_release.php` | Evidence/source validation, original component intent, pinned additive migration and final receipt. Direct CLI apply is refused. |
| `tenant_ai_scratch.py`, `tenant_ai_restore.py` | Root-only Coastline scratch restore and complete dump comparison with an explicitly pinned per-capture policy. |

The helper never installs application source, changes grants, provisions secrets or bindings, calls an AI provider, publishes packages, kills SQL sessions or automatically reopens after failure. The old portal installer and historical backup helpers are reference material only. Never execute them to install this migration.

## Preconditions

1. Confirm the agreed integration/release owner and current source, CI, production release, configuration inventory and in-flight work. Account for every intervening change. Reserve the existing shared/app locks without replacing their files or owners. These gates also cover companion migrations; this helper applies only the tenant-AI component.
2. Review `tenant_ai_freeze_profile.json` against fresh read-only host evidence. It pins host, vhost/cron/unit hashes and inodes, enabled-vhost link, account identities and lock owners/modes. The recorded profile is a deployment-specific snapshot, not permanent permission. Any drift needs review and a new committed profile before a new window. Never edit a candidate or original intent to force an existing window to resume.
3. The app root's `current` must be the reviewed physical directory. The candidate controls must be physical root-owned files without group/other write access. Use LF bytes from the reviewed Git artifact; the SQL/catalog checks are exact. Stage the candidate separately from `current`, and create an external root-owned 0700 evidence directory. All evidence files are 0600 and must stay outside Git/chat/public logs.
4. Root Unix-socket access, PHP/Python, MySQL dump tooling, Apache/systemd, curl and tar must already be available. Root operation on Coastline must be available for the independent restore. Missing access is a hold, not permission to change host authentication or install an alternate privileged path.
5. Retain a verified rollback for source/config and a complete database/account recovery plan. The new schema is additive, but reverting files does not undo credential/receipt writes. Once DDL may have begun, reopening or recovery needs the owner's explicit evidence-based decision.

## Scope and drain

ID pins the customer-signups, suite-onboarding and customer-owner-invitations timer/service pairs, three selected ID cron lines, its HTTPS vhost and five locks. The existing `.milepost-provisioning.lock` remains owned by UID/GID 33; do not chown or recreate it. Safeharbor pins six canonical cron files (only its three lines in the mixed ventures file), its vhost and three locks. Unrelated cron lines and quarantined cron files remain untouched. Neither profile authorizes stopping unrelated services.

The freeze journals original files, unit states, accounts/grants, database/server UUID, boot ID, candidate hashes and Apache generation before mutation. It changes only the reviewed access block/cron lines, stops only previously active named timers, reloads Apache and waits for old workers and services to exit naturally. New app-scoped cron/systemd definitions require review. HTTP and HTTPS probes, including aliases, must end at 403.

MySQL inventory includes schema wildcard grants, table/column/routine privileges, dynamic/global privileges, roles, proxy grants, enabled events and live connections. Only the reviewed root installation proxy grant is accepted. Internal MySQL principals must remain locked. The tool locks the exact three app writers per profile and waits for existing writer/administrator connections to drain; account locking alone is insufficient. Other administrator connections block the window. It never terminates a session or changes a grant.

## One held window

Run from the reviewed root-owned candidate on its pinned host. Substitute reviewed absolute paths and the exact 40-character candidate commit; these are placeholders, not an executable production command:

```text
python3 CANDIDATE/deploy/tenant_ai_window.py run --candidate CANDIDATE --target COMMIT --evidence EVIDENCE
```

Keep that process and its stdin open. It holds the real exclusive descriptors until `close`, EOF or process exit. Send one JSON object per line and inspect each result:

```json
{"action":"freeze"}
{"action":"verify"}
{"action":"capture"}
```

Capture produces the original full `database.sql` (rows, triggers, routines and events), `application.tar` (current source/config, ACLs, xattrs and numeric owners), protected original account definitions/grants, `backup-identity.json` and `capture.json`. The same closure proof is checked around capture. Artifact hashes bind this specific window. Account recovery evidence contains secret material and is never displayed. A complete capture can be verified/reused; partial artifacts are retained and never overwritten automatically.

While the original process still holds the locks, the owner transfers only the required protected dump/identity and reviewed controls to a root-owned 0700 Coastline evidence directory over the established protected channel. This documentation does not authorize a transfer. Keep every file 0600. Inspect the locally available immutable MySQL image ID and require the exact captured server version. Do not use desktop Docker or the stopped production rollback stacks.

```text
python3 CONTROLS/tenant_ai_scratch.py propose-policy EVIDENCE/database.sql EVIDENCE/policy.json
```

An empty line policy requires raw equality. Review and pin its SHA-256. MySQL can expand an implicit utf8mb4 character set on a CREATE TABLE character column. If this occurs, preserve the failed comparison, independently review the exact changed column lines and create a new policy filename with explicit `--column-line N` arguments. Only insertion of the literal ` CHARACTER SET utf8mb4` at those complete-source-hash-bound lines is allowed. No generic normalization, row-count substitute, data difference or stored-program difference is accepted. Never reuse another capture's policy.

```text
python3 CONTROLS/tenant_ai_scratch.py restore --backup EVIDENCE/database.sql --identity EVIDENCE/backup-identity.json --policy EVIDENCE/policy.json --policy-sha256 REVIEWED_SHA256 --mysql-image sha256:REVIEWED_IMAGE_ID --output EVIDENCE/restore-proof.json
```

The helper takes the existing Coastline heavy-test mutex, enforces a memory floor, restores in a uniquely labeled network-isolated MySQL container, exports the complete database, compares all bytes under that exact policy, and removes its own container/volume. A daemon/cleanup error cannot count as removal. It writes the success proof only after successful cleanup. Preserve failed exports privately. Transfer the accepted `restore-proof.json` back into the original release evidence directory with its root ownership/mode intact.

In the still-held original window, verify again and apply:

```json
{"action":"verify"}
{"action":"apply"}
```

The PHP child validates actual inherited locks, current writers/drain, all 11 pinned source files, original capture artifacts, independent restore identity and exact schema catalog. It fsyncs the original migration intent before DDL. It can resume only a recognized empty prefix owned by that exact intent. Drift, populated partial state, orphan receipt, unowned partial/final schema, changed evidence and a final schema without its durable receipt all remain closed for recovery review. A complete matching receipt supports idempotent verification.

The release owner performs the separately reviewed source/config installation and acceptance work while keeping the app closed and preserving these controls. None is implied by `apply`. Before reopening, the owner must explicitly accept the exact receipt, and the tool freshly rechecks its original intent/evidence and final schema:

```json
{"action":"unfreeze","accepted":true,"decision":"accepted-release","receipt_sha256":"EXACT_RECEIPT_SHA256"}
{"action":"close"}
```

Reopening unlocks only accounts this intent locked, preserves pre-existing locks, restores exact original cron bytes, restarts only originally active timers, and restores/reloads the original vhost last. It checks all file evidence and unit definitions before reopening writers. An interrupted reopen can resume only the same explicit acceptance and recognized exact pending changes. A changed file, unit, grant, identity or acceptance holds the remaining work for review.

If no component migration intent/receipt exists, an explicitly accepted `abort-before-ddl` can restore the original state. Once either exists, that shortcut is refused. EOF, timeout, failed capture/restore/apply and a closed terminal never automatically reopen the app. Preserve the original journal, backups and source pins. Do not delete locks, fabricate receipts, rerun an old installer, replay uncertain DDL or start a new evidence directory to bypass an incomplete window.

## Validation boundaries

- Root Linux tests cover file ownership, symlinks, stable inherited locks, journal tampering, interrupted exact renames and explicit reopening. Pure comparator tests cover changed records/programs, truncation and policy tampering.
- Real MySQL fixtures cover fresh/replayed schema, recognized empty prefixes, populated partial refusal, exact drift, discovered writer privileges, actual account locks and natural connection drain.
- `test_tenant_ai_operator.py` is opt-in and refuses outside an explicit disposable Coastline container. It uses real PHP, MySQL, inherited descriptors, full capture/independent restore, apply/replay and explicit reopening, with negative evidence/source/receipt/schema cases. Only deployment-specific resource metadata and Apache/systemd/process responses are synthetic. The candidate code and schema are copied into that container; no production configuration or data is read. The fixture's reviewed charset line pins apply only to its synthetic source.
- These tests do not prove live Apache/service closure, current production identity, a paid provider response, live installation or Windows companion acceptance. The release owner must collect those separately before claiming deployment.
