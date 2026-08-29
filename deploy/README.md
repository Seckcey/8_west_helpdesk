# Deploying Safeharbor to safeharbor.8westit.com

Public production hostname: **safeharbor.8westit.com** (AWS EC2, Ubuntu 24.04).
Stack: **Apache 2.4 + mod_php (PHP 8.3) + MySQL 8** — identical to Milepost,
which lives on the same box (`support.8westit.com`).

> ⚠️ **Shared box.** Milepost, Coastmark, 8 West ID (ewid), and the company
> sites live here. All Safeharbor work is **additive only**: own app dir
> (`/srv/8west/apps/safeharbor/`), own docroot, own vhost pair, own MySQL
> database + user, own shared-storage dir. Never edit other vhosts, never
> `restart` Apache — `reload` only.
>
> **SSH goes to the ORIGIN IP, not the domain** — safeharbor.8westit.com is
> Cloudflare-proxied, so port 22 on the hostname times out:
> `ssh -i ~/.ssh/milepost.pem ubuntu@<origin-ip>` (Frank has the IP; the
> `.pem` is the Milepost key — never commit it).

## Layout on the server

```
/srv/8west/apps/safeharbor/
  current -> releases/<40-char Git revision>   one atomic pointer
  releases/<40-char Git revision>/            immutable root:www-data app
    REVISION                                 exact reviewed commit
    ARTIFACT_SHA256                           exact Git-archive digest
    RELEASE_DIGEST                            installed-file manifest digest
    config/config.php -> ../../../shared/config/config.php
  shared/config/config.php                    protected root:www-data 640
  shared/attachments/                         mutable uploads; never a release input
/etc/apache2/sites-available/
  safeharbor-8westit.conf             port 80 (HTTPS redirect)
  safeharbor-8westit-le-ssl.conf      port 443 (Let's Encrypt)
/srv/8west/backups/safeharbor/        root-only conversion/release records
```

Apache keeps `DocumentRoot` on `current/public`, while both reviewed
`<Directory>` rules match `releases/*/public` because Apache resolves the
symlink. Releases are never edited after assembly. A failed public health or
login check atomically restores the exact previous link and reloads Apache.

## One-time setup (historical; rebuild requires a reviewed plan)

> **`deploy/setup-server.sh` is retired and now fails before changing
> anything.** Its former implementation created `/var/www/safeharbor` and did
> not reproduce the least-privilege database grants or atomic release layout.
> A rebuild needs a separately reviewed plan. Never use the retired layout or
> the old database-wide `DELETE` grant as a shortcut.

```bash
# On milepost-ec2 as the privileged operator:
# 1. Keep the vhost disabled. Create the database + runtime identity, granting
# schema-wide SELECT/INSERT/UPDATE only. The password goes into config.php.
sudo mysql -e "CREATE DATABASE safeharbor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  CREATE USER 'safeharbor'@'localhost' IDENTIFIED BY '<password>'; \
  GRANT SELECT, INSERT, UPDATE ON safeharbor.* TO 'safeharbor'@'localhost'; \
  FLUSH PRIVILEGES;"

# 2. App dir + server config (copy config.sample.php, fill in the password)
sudo mkdir -p /srv/8west/apps/safeharbor/shared/config
# → write config.php, then:
sudo chown root:www-data /srv/8west/apps/safeharbor/shared/config/config.php
sudo chmod 640 /srv/8west/apps/safeharbor/shared/config/config.php

# From the exact clean repository checkout:
# 3. Load the canonical schema and the three svc-boundary migrations as an operator.
# Never seed production; db/seed.php is a destructive sandbox-only reset.
ssh milepost-ec2 "sudo mysql safeharbor" < app/db/schema.sql
ssh milepost-ec2 "sudo mysql safeharbor" < app/db/migrations/002_svc_intake.sql
ssh milepost-ec2 "sudo mysql safeharbor" < app/db/migrations/009_support_intake.sql
ssh milepost-ec2 "sudo mysql safeharbor" < app/db/migrations/018_ticket_auto_close_eligibility.sql

# Back on milepost-ec2 as the privileged operator:
# 4. Preserve schema-wide SELECT/INSERT/UPDATE and add DELETE only to the eight
# inventoried operational tables after they exist. Never grant database-wide DELETE.
sudo mysql -e "GRANT DELETE ON safeharbor.clients TO 'safeharbor'@'localhost'; \
  GRANT DELETE ON safeharbor.contacts TO 'safeharbor'@'localhost'; \
  GRANT DELETE ON safeharbor.canned_responses TO 'safeharbor'@'localhost'; \
  GRANT DELETE ON safeharbor.email_threads TO 'safeharbor'@'localhost'; \
  GRANT DELETE ON safeharbor.messages TO 'safeharbor'@'localhost'; \
  GRANT DELETE ON safeharbor.svc_rate_buckets TO 'safeharbor'@'localhost'; \
  GRANT DELETE ON safeharbor.svc_support_rate TO 'safeharbor'@'localhost'; \
  GRANT DELETE ON safeharbor.tickets TO 'safeharbor'@'localhost';"

# From the exact clean repository checkout:
# 5. Install the reviewed vhosts, then deploy from a clean detached
# default-branch commit into the already-prepared atomic layout.
scp deploy/apache-safeharbor*.conf milepost-ec2:/tmp/
ssh milepost-ec2 \
  "sudo cp /tmp/apache-safeharbor.conf /etc/apache2/sites-available/safeharbor-8westit.conf && \
   sudo cp /tmp/apache-safeharbor-le-ssl.conf /etc/apache2/sites-available/safeharbor-8westit-le-ssl.conf && \
   sudo apache2ctl configtest && sudo systemctl reload apache2"
EXPECTED_SHA="$RELEASE_SHA" SERVER=milepost-ec2 bash deploy/deploy.sh
```

TLS note: the cert was issued with the box's existing certbot (snap). It lives
at `/etc/letsencrypt/live/safeharbor.8westit.com/` and renews automatically.

## One-time conversion of the legacy real `current` directory

Run this once before the first atomic release. It refuses a symlinked or
already-converted layout, an unexpected vhost byte, an unsafe config, a
non-regular legacy tree entry, or a busy release lock. Supply the exact live
application revision from the latest protected production receipt; do not
guess it.

The converter first writes a root-only external archive of the complete real
`current` tree (including the protected config), copies both vhosts exactly,
hashes and verifies all three, and compares the archive back to the live tree.
Only then does it move config to the server-owned shared path, create the
immutable legacy release, switch `current`, and update only the two Safeharbor
vhost directory rules. Any later failure restores the real directory and both
vhosts from that exact backup, reloads Apache, and repeats the public checks.
The attachment directory is never copied, moved, traversed, chmodded, or
chowned; its filesystem identity is checked before and after.

```bash
# Read-only preflight on the server; retain the two exact hashes.
ssh milepost-ec2 \
  "sudo sha256sum /etc/apache2/sites-available/safeharbor-8westit.conf \
   /etc/apache2/sites-available/safeharbor-8westit-le-ssl.conf"

# Run from a fresh clean checkout of the exact green main revision containing
# these controllers. CURRENT_REVISION is the independently verified live SHA.
EXPECTED_SHA="$RELEASE_SHA" \
CURRENT_REVISION="$VERIFIED_LIVE_SHA" \
EXPECTED_HTTP_VHOST_SHA256="$HTTP_VHOST_SHA256" \
EXPECTED_HTTPS_VHOST_SHA256="$HTTPS_VHOST_SHA256" \
SERVER=milepost-ec2 \
bash deploy/prepare-atomic-layout.sh
```

Do not run the converter again after `current` becomes a symlink. Do not
manually create `shared/config`, `releases`, or a replacement link to bypass a
refusal; resolve the unexpected state against the retained root-only record.

## Every release

Safeharbor has no protected deployment workflow. GitHub `Validate` is a test
gate only; the production boundary is this manual operator procedure. Work in a
new detached worktree so a moving branch or unrelated local change cannot enter
the streamed artifact:

```bash
git fetch --prune origin
RELEASE_SHA="$(git rev-parse origin/main)"
git show -s --format='%H %P %s' "$RELEASE_SHA"
gh run list --workflow Validate --branch main --limit 1 \
  --json databaseId,headSha,status,conclusion,url
git worktree add --detach ../safeharbor-release-"${RELEASE_SHA:0:12}" "$RELEASE_SHA"
cd ../safeharbor-release-"${RELEASE_SHA:0:12}"
test "$(git rev-parse HEAD)" = "$RELEASE_SHA"
test -z "$(git status --porcelain)"

# Run the reviewed tests and migration-specific ordering first.
# Deploy only after every required migration postflight passes.
EXPECTED_SHA="$RELEASE_SHA" SERVER=milepost-ec2 bash deploy/deploy.sh
```

The newest green run must name exactly `RELEASE_SHA`; recency alone is not a
gate. `milepost-ec2` is the reviewed origin-IP alias and works with the deploy
script's `IdentitiesOnly` option. Never rely on the script's Cloudflare-hostname
default. Record the exact SHA, CI run, backup record, migration output, and
postdeploy hashes in the root-only release record created by the controller.

The launcher refuses tracked, untracked, and ignored workspace bytes, requires
the exact commit to remain `origin/main`, builds the same Git archive twice,
and verifies both digests before upload. The server verifies the controller and
archive again, extracts away from `current`, rejects runtime config and
non-regular entries, copies only the six reviewed brand blobs from that
archive, lints every PHP file, writes an installed-file manifest, and applies
root-owned read-only permissions. It then creates the external exact artifact
record and changes `current` in one rename. `health.php`, the login title, and
the signed-out root are checked through the public hostname after an Apache
config test and graceful reload.

Local asset query strings use the first 12 characters of the immutable
`REVISION` marker. They therefore change with the reviewed commit without
rewriting PHP after extraction. The separately released shared Westy layout
continues to use its own versioned path.

### Protected backup and Safeharbor-only write freeze

For a schema-changing release, create a new root-only record before any lock or
DDL. Do not reuse an older release's backup:

```bash
ssh milepost-ec2 'sudo bash -se' <<'REMOTE'
set -euo pipefail
umask 077
BACKUP_DIR="/srv/8west/backups/safeharbor/$(date -u +%Y%m%dT%H%M%SZ)-pre-release"
install -d -m 0700 -o root -g root "$BACKUP_DIR"

APP_ROOT=/srv/8west/apps/safeharbor
CURRENT_TARGET="$(readlink -f "$APP_ROOT/current")"
case "$CURRENT_TARGET/" in
  "$APP_ROOT/releases/"*) ;;
  *) printf '%s\n' 'current resolves outside Safeharbor releases' >&2; exit 1 ;;
esac
tar -C "$CURRENT_TARGET" \
  --exclude='./config/config.php' --exclude='./config/config.php.bak*' \
  -czf "$BACKUP_DIR/application.tar.gz" .
install -m 0600 "$APP_ROOT/shared/config/config.php" \
  "$BACKUP_DIR/config.php"
mysqldump --single-transaction --quick --triggers --routines --events \
  --hex-blob --no-tablespaces --set-gtid-purged=OFF safeharbor \
  > "$BACKUP_DIR/database.sql"
mysql -N -B -e "SHOW GRANTS FOR 'safeharbor'@'localhost'" \
  > "$BACKUP_DIR/runtime-grants.sql"
printf 'current_target=%s\nrevision=%s\nartifact_sha256=%s\nrelease_digest=%s\n' \
  "$CURRENT_TARGET" \
  "$(tr -d '\r\n' < "$CURRENT_TARGET/REVISION")" \
  "$(tr -d '\r\n' < "$CURRENT_TARGET/ARTIFACT_SHA256")" \
  "$(tr -d '\r\n' < "$CURRENT_TARGET/RELEASE_DIGEST")" \
  > "$BACKUP_DIR/release-markers.txt"
chmod 0600 "$BACKUP_DIR/release-markers.txt"

tar -tzf "$BACKUP_DIR/application.tar.gz" >/dev/null
grep -q -- '-- Dump completed on' "$BACKUP_DIR/database.sql"
cd "$BACKUP_DIR"
sha256sum application.tar.gz config.php database.sql runtime-grants.sql release-markers.txt \
  > SHA256SUMS
sha256sum -c SHA256SUMS
printf '%s\n' "$BACKUP_DIR"
REMOTE
```

Restore the dump into one exact allowlisted scratch database, verify the base
table/trigger/time counts, then drop only that scratch database. A readable
archive and a dump-completed marker are necessary but do not replace a restore.
Keep the live config and grant record protected; never print either into chat,
CI, or a public PR.

The shared Apache service must never be stopped or restarted for Safeharbor.
For a reviewed migration-first window, deny only Safeharbor's SSL docroot,
config-test, reload Apache, and prove the public endpoint returns `403`. Preserve
the exact vhost first in the release record:

Before copying or editing that file, resolve the active mapping with
`sudo apache2ctl -S` and require all three exact facts: server name
`safeharbor.8westit.com`, vhost file
`/etc/apache2/sites-available/safeharbor-8westit-le-ssl.conf`, and document
root `/srv/8west/apps/safeharbor/current/public`. Never substitute
`support-8westit-le-ssl.conf`: `support.8westit.com` is Milepost on this shared
host. Hash both vhosts before and after a release so a mistaken target is
detected even if Apache still reloads successfully.

```bash
VHOST=/etc/apache2/sites-available/safeharbor-8westit-le-ssl.conf
BACKUP_DIR=/srv/8west/backups/safeharbor/YYYYMMDDTHHMMSSZ-pre-release
ssh milepost-ec2 "sudo cp --preserve=all '$VHOST' \
  '$BACKUP_DIR/safeharbor-vhost-before-lock.conf' && \
  test \"\$(sudo grep -c 'Require all granted' '$VHOST')\" = 1 && \
  sudo sed -i 's/Require all granted/Require all denied/' '$VHOST' && \
  sudo apache2ctl configtest && sudo systemctl reload apache2"
curl -sS -o /dev/null -w '%{http_code}\n' \
  https://safeharbor.8westit.com/login.php          # exactly 403
```

Then lock the exact Safeharbor runtime account and require zero existing
connections. This blocks Safeharbor's minute mail workers too; other products
on the shared host remain available:

```bash
ssh milepost-ec2 "sudo mysql -e \
  \"ALTER USER 'safeharbor'@'localhost' ACCOUNT LOCK\" && \
  sudo mysql -N -B -e \
  \"SELECT account_locked FROM mysql.user WHERE User='safeharbor' AND Host='localhost'; \
    SELECT COUNT(*) FROM information_schema.processlist WHERE User='safeharbor';\""
# Require exactly Y and 0. Do not start DDL otherwise.
```

Archive the exact Git blob rather than trusting working-tree newline bytes, hash
it, and run the archived copy as the trigger-capable operator. For migration
016 the reviewed blob hash is
`0f07b0da0ab4c9e68308cc3ddf2af4c7e4e2171be81682168202245c8395eb25`:

```bash
BACKUP_DIR=/srv/8west/backups/safeharbor/YYYYMMDDTHHMMSSZ-pre-release
MIGRATION=app/db/migrations/016_time_corrections_overlap.sql
git show "$RELEASE_SHA:$MIGRATION" | ssh milepost-ec2 \
  "sudo bash -c 'umask 077; cat > \
  $BACKUP_DIR/016_time_corrections_overlap.exact.sql'"
ssh milepost-ec2 "sudo sha256sum \
  '$BACKUP_DIR/016_time_corrections_overlap.exact.sql' && \
  sudo bash -c 'mysql --show-warnings safeharbor < \
  $BACKUP_DIR/016_time_corrections_overlap.exact.sql > \
  $BACKUP_DIR/migration-016-first-run.txt'"
```

Replay the same archived file once and preserve both outputs. If any migration
command exits nonzero after DDL begins, keep both locks in place, inspect the
exact partial shape, and replay the same reviewed bytes. Never manually remove
staging/swap guards and never restore an old database over later help-desk
writes as an automatic response.

After the exact postflight and replay pass, deploy the matching clean detached
commit while the endpoint remains locked. Verify application hashes, PHP lint,
config preservation, runtime grants, and Apache syntax. Restore the vhost from
the release record, require its SHA-256 to match the pre-lock value, reload
Apache, unlock `safeharbor@localhost`, and run public plus authenticated smoke.
If DDL never started, an aborted window may restore the vhost and unlock the
account after recording why it stopped.

**If the release ships a new `db/migrations/NNN_*.sql`, follow that
migration's reviewed ordering** (deploys never touch the DB). Migrations 011
through 018 are explicitly migration-first; do not infer deploy-first from the
generic release command:

```bash
git show "$RELEASE_SHA:app/db/migrations/NNN_whatever.sql" | \
  ssh milepost-ec2 "sudo mysql --show-warnings safeharbor"
```

Recorded as applied to production: 001 (mail_queue) · 002 (westy/onboarding) ·
002_svc_intake (alert intake) ·
003 (canned_responses) · 004 (attachments, email_threads, processed_mail,
resurface_at) · 005 (ticket_presence, merged_into_id, FULLTEXT) · 006 (csat) ·
007 (suite_subject) · 008 (Westy reports) · 009 (support intake) ·
**010 (versioned service goals, migration-first on 2026-08-26)** ·
**011 (approval-grade technician time, migration-first on 2026-08-26)** ·
**012 (dark customer portal boundary, migration-first on 2026-08-26)** ·
**013 (versioned archived business reports, migration-first on 2026-08-26)** ·
**014 (guarded service-goal publication, migration-first on 2026-08-27)** ·
**015 (default-off customer sync, migration-first on 2026-08-27)** ·
**016 (time corrections/overlap guards, migration-first on 2026-08-27)** ·
**017 (8 West ID report-contact evidence, migration-first on 2026-08-27)** ·
**018 (ticket automatic-close eligibility, migration-first on 2026-08-27)** ·
**019 (client-scoped report-contact evidence, migration-first on
2026-08-28)**.

The merged time-provenance bridge must be live before migration 011. It keeps
historical time on the source ticket during a merge and gives 011 a
schema-compatible rollback point. Do not apply 011 while production runs code
that rewrites `time_entries.ticket_id`.

Migration 011 (approval-grade technician time) is also migration-first. Take
an exact database/application backup, run its scratch-MySQL replay and
forbidden-mutation probes, then apply it with the trigger-capable operator
identity before deploying code that reads approval columns. Its compatibility
trigger keeps the previous five-column time writer valid during that narrow
rollout window while separate staging guards fail closed on old merge-style
updates and deletes. Confirm `demo_mode` is false and do not run the demo seed,
manual time-entry DDL, or a second migration concurrently. The postflight must
show all exact columns, seven named
indexes, five tenant-scoped foreign keys, the minute and billable checks, the
immutable event table, and seven permanent triggers; every historical entry
remains `pending`.

Production applied 011 through Safeharbor PR #40 / merge `b0a6760` on
2026-08-26. The protected pre-migration database backup is
`/srv/8west/backups/safeharbor/20260826T223914Z-pre-phase3-migration`; the
preceding full application/database/grant backup is
`/srv/8west/backups/safeharbor/20260826T223441Z-pre-phase3-hardening`.

Production applied migration 012 and deployed its matching source dark through
Safeharbor PR #44 / merge `3bb87fa` on 2026-08-26. Exact-main Validate run
`33024372187` passed, including disposable-MySQL replay and tenant isolation.
PR #48 / merge `748f16ca4648ece2ce767966af46cd2788bd3f7c` then moved the
disabled gate ahead of portal logout method/session handling. The production
postflight proved two InnoDB tables, the exact 10/11 columns, 6/3 indexes, 4/3
tenant-scoped foreign keys, one enforced slug check, seven lifecycle/audit
triggers, and zero bindings/events. The runtime grant remains DML-only.

At the initial dark-release checkpoint, protected config had an explicit
`portal.enabled=false` block at
SHA-256
`c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`.
It contains the fixed issuer and
`https://safeharbor.8westit.com/portal/callback.php`, but its client ID and
secret are empty. The private persistent revocation-cache directory exists
with private permissions and is empty. `/portal/`, login, callback, and both
GET and POST logout returned 404 without setting a session cookie. At that
checkpoint, 8 West ID's `f0ec49b` prerequisite was deployed dark with zero
registered Safeharbor OIDC clients; the later controlled activation below
supersedes those client and routing facts.

The protected rollback record is
`/srv/8west/backups/safeharbor/20260826T234825Z-pre-phase5a-portal`; its verified
application archive SHA-256 is
`e7890c2145636942ab13a3a0dce44ef13749a2647f8e7a2bfdf8119424906131`
and its trigger-inclusive database dump SHA-256 is
`3d316813f6d7524ccdfbd20bb056f1f66c4ab7bd3f69c0df291c66bb0d336819`.
Rollback is code-first while the gate stays false; leave the two empty additive
tables in place because prior code ignores them. A database restore is disaster
recovery only because it would discard writes made after the backup.

The later controlled Lifestyle activation installed the confidential client
values only through the protected operator path, enabled global portal config,
and enabled binding id 1 for the expressly approved identity tenant to provider
tenant 1/client 14. Current protected config SHA-256 is
`e272ccc44f8200774530fabda634b3d65032805ef360d6f73da3a91afc3fb0f4`.
The canonical Safeharbor portal passed signed-out routing; the support-domain
`/portal` path is 404. Fresh authenticated customer acceptance is still open
and must prove isolation, revocation, disable-on-next-request, logout, desktop,
and mobile behavior before the canary is called fully accepted.

The root-only activation record is
`/srv/8west/backups/safeharbor/20260829T021900Z-pre-lifestyle-portal-canary`;
its portal-canary receipt SHA-256 is
`4e6d81f955f582171cea5f78c7ea98f92923e288ef6863c925fa157cfa1a6efc`.
8 West ID replacement subject `t4u10` is enabled; predecessor `t4u7` is
permanently inactive, revoked, and quarantined. The root-only quarantine
receipt SHA-256 is
`56ace0529b430e0f227d7ce9983976d8f1bf45840d05dab0c1aefd583b8e1c04`.
Coastmark merge `c9f44096af80a0a97b8415e3f4037c88867ecfab` is deployed live and
independently fails closed against a fresh signed revocation feed before suite
SSO can write or reactivate anything. Financial counts were unchanged; its
Safeharbor-time receiver remains off/404 and mappings/imports/draft lines remain
zero. It does not connect the portal to billing.
Immediate rollback remains `portal.enabled=false`, followed by disabling the
exact binding if a durable per-customer stop is required. The portal remains
ticket-summary-only and must not gain billing, mutation, or customer endpoint
control through activation. The exact sequence is in
`docs/customer-portal-contract.md`.

Migration 013 is migration-first and trigger-capable-operator-only. Before
application deployment, take and verify protected application, database, and
grant backups; stop concurrent report lifecycle commands; run the disposable
MySQL report suite; apply `013_business_reports.sql`; and require every printed
postflight value to be `1`. Verify five InnoDB tables, exact 10/15/13/12/10
column counts, 27 named indexes, 15 tenant-scoped foreign keys, 15 checks, and
15 permanent lifecycle/immutability triggers. A fresh migration must create
zero definitions, schedules, archives, deliveries, and attempts.

Before migration 013, production's database-wide DML grant included `DELETE`.
The controlled rollout removed the database-wide `DELETE` privilege and now
grants table-level `DELETE` only to the eight runtime paths that actually delete rows:
`clients`, `contacts`, `canned_responses`, `email_threads`, `messages`,
`tickets`, `svc_rate_buckets`, and `svc_support_rate`. Preserve database-wide `SELECT`,
`INSERT`, and `UPDATE`; the report triggers deny updates to immutable report
tables and permit only exact delivery/attempt transitions. Prove ordinary
deletes still work inside rollback-only transactions, prove DELETE is denied
on every report/portal/time-history table, and prove `TRUNCATE`, DDL, trigger,
and grant authority remain absent. Never put the runtime password in a command,
log, evidence file, or Git.

Production applied migration 013 and deployed its matching source dark through
Safeharbor PR #46 / merge `209bb42` on 2026-08-26. Exact-head Validate run
`33027071016` and exact-main run `33027147921` passed, including disposable
MySQL 8 migration replay, exact archive bytes, database guards, and the
least-privilege lifecycle. Production postflight proved five InnoDB tables,
the exact 10/15/13/12/10 column counts, 27 indexes, 15 tenant-scoped foreign
keys, 15 checks, 15 triggers, and zero definitions, schedules, archives,
deliveries, or attempts. At that rollout the protected config was
byte-identical at
`dd165dff89e9f048e8bfd0ff06b1c3f636b452abf9569e045f5925db5fe7de81`.
The later portal-only dark scaffold changed the whole-file hash to
`c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`
without adding a report block or scheduler, so both report gates and every
allowlist remain inert. The runtime grant is database-wide
`SELECT,INSERT,UPDATE` plus `DELETE` only on the eight operational tables
above.

The protected rollback record is
`/srv/8west/backups/safeharbor/20260827T003557Z-pre-phase6-business-reports`.
Its verified application and trigger-inclusive database SHA-256 values are
`b9972bd06e414947fc57a8dc30c11528b710de7d864b318c2bd5534fb5b1c7c2`
and `90e4bad9b5f474fe4555129dd649e2f48de45016c99090ab8f9d5ae9ce00da26`.
Four stale protected config backups found in the non-pruning deploy tree were
moved, without reading them, into that record's root-only quarantine; the live
tree now has zero stale config backups. All 121 comparable app files—excluding
the protected config and three intentional asset-version stamps—match the
merged artifact byte-for-byte. Roll code back by deploying a prior exact
merged release through the normal config-excluding path. Never unpack this
application archive wholesale over `current`; any disaster-recovery extraction
must exclude `config/config.php` and every `config.php.bak*`.

Migration 014 (`014_service_goal_policy_publication.sql`) is migration-first,
additive, and trigger-capable-operator-only. It adds nullable actor/reason
attribution to the existing policy-version table, an exact non-cascading actor
foreign key/index, four enforced checks, and two INSERT guards while retaining
migration 010's four immutable UPDATE/DELETE guards. It publishes no policy,
changes no ticket, and remains compatible with the previous application during
the migration-first window.

Before applying 014, require exact-head MySQL 8 CI green, a clean release
artifact, and a verified root-only application/config/database/grant backup.
Record and preserve the current policy and ticket-snapshot digests; production
must still have four v1 policy versions, sixteen targets, zero later versions,
two captured-ticket snapshots, and 275 truthful historical NULL snapshots.
Apply the exact merged migration through `sudo mysql safeharbor` and require all
five emitted postflight values to equal `1`: attribution columns, actor index,
actor foreign key, publication checks, and publication triggers. Recheck the
unchanged counts/digests and DML-only runtime grants before deploying code.
MySQL DDL auto-commits, so any nonzero migration exit is a stop: keep the old
application live, inspect the exact partial shape, and repair/replay. Do not
automatically restore a database dump over later help-desk writes.

Deploy only an exact merged, clean worktree after that postflight. Do not run
`manage_service_goals.php plan` or `publish` during release. A v2 requires the
real approved four response targets, exact future UTC effective time, active
owner/admin actor, reason, and separately reviewed plan digest. Rollback is
code-first while leaving the additive 014 shape in place; database restoration
is disaster recovery only. The full policy and canary contract is in
`docs/service-goal-policy-contract.md`.

Production applied migration 014 and deployed its matching source through
Safeharbor PR #50 / merge
`12abd36de83cda276e6d96a79e873e2706bb3ff0` on 2026-08-27. Exact-head
Validate run `33036765540` and exact-main run `33036836019` passed; the
disposable MySQL suite completed 53/53 migration, drift, rollback,
serialization, and runtime-privilege checks. The applied migration SHA-256 is
`4062c65c985022d1c7ef07a3c0e127221437f3bd46cd1b79eb263480a388bc20`.

Production postflight proved two attribution columns, the exact two-part actor
index/FK with `NO ACTION`, four enforced checks, and six policy guards. Four v1
versions, sixteen targets, zero later/attributed versions, two captured ticket
snapshots, 275 historical NULL snapshots, and the one unresolved legacy
pending time row remain unchanged. The policy/ticket digests, DML-only runtime
grant digest, and protected config hash remained unchanged. All 103 live PHP
files lint, Apache syntax and public login/root smoke pass, and all five dark
portal routes remain cookie-free 404. Portal/report tables are empty and no
business-report cron exists. All 129 deployed paths match the exact merge: 126
byte-identical plus three normalized cache-stamp files. The verified root-only
rollback record is
`/srv/8west/backups/safeharbor/20260827T033805Z-pre-phase2b-service-goal-publication`.
Its application archive, trigger-inclusive database dump, and manifest
SHA-256 values are
`8d892f03b4cf5eb4042ceaf2cb877e23c1fffb601f5e7f82c1cbb2aaded60d88`,
`21b8c62bba6a5455163a45b175038d0056fa6970691d4b78e1d7cba0e9ff8b17`,
and `8776fbb6f1e69ba1969b38dc8cb0e5005f6819608a0d7a3a0a9d78c0da8b2ff1`.
The preserved policy, ticket-snapshot, runtime-grant, and config SHA-256
values are
`5eff8af39aeb1e28a4538654b2f790230147e070d50c45fcad5ce4eaa396e6f3`,
`aec2812c424abb222ad9574412fc2d56ef1c3074d9c31f44c04efb8a3b9853e4`,
`35673b03b5ac36996f9902f7b40a1581486437a6f2a31f5d849b961c4612def0`,
and `c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`.
No v2 policy was published.

Migration 016 (`016_time_corrections_overlap.sql`) is migration-first,
additive, and trigger-capable-operator-only. It adds one immutable nullable
correction pointer, one tenant-scoped unique key and self-foreign key, plus two
database-owned InnoDB guard tables. Twelve permanent triggers preserve the
existing append-only approval history, require a replacement to match one
rejected parent inside the same tenant/technician/ticket/client/source, and
serialize measured-time overlap decisions through persistent UTC-day locks.

Before applying 016, require the exact-head Validate run green, including the
disposable two-connection overlap race. Take and verify root-only application,
trigger-inclusive database, config, and grant backups; record the current
`time_entries`/`time_entry_events` counts and migration 011's seven guards.
Stop concurrent time writes, apply the exact merged migration through the
trigger-capable operator. Require correction column/index/FK and auxiliary
structure flags of `1`, exactly twelve permanent triggers, zero staging
triggers, and equal measured parent/registry counts. Re-run it once and prove
the time facts/counts are unchanged. Only then deploy code that selects
`corrects_time_entry_id`.

The migration installs five fail-closed swap guards before replacing the seven
existing approval/audit triggers. If it exits nonzero after that point, do not
restart application traffic or manually remove those blockers: inspect the
exact trigger set and replay the same reviewed migration. Inserts, updates,
deletes, and audit rewrites remain denied until all twelve permanent guards are
restored and the migration removes the five swap guards itself.
An earlier set of five auxiliary swap guards protects guard/registry updates,
deletes, and measured-registry inserts while replay replaces those integrity
triggers; parent measured writes roll back while that swap is incomplete.

Production applied migration 016 and deployed its matching source through
Safeharbor PR #55 / merge
`bb580a293f89deab278473e986c2e1232d2bc0c1` on 2026-08-27. Exact-main
Validate run `33057438562` passed. The verified root-only release record is
`/srv/8west/backups/safeharbor/20260827T092220Z-pre-time-corrections`; its
application, protected config, trigger-inclusive database, and runtime-grant
hash manifest passed. The application archive and dump-completed marker passed,
and a scratch restore proved 31 base tables, 43 pre-migration triggers, and
unchanged time/event counts of `1:1`.

The Safeharbor-only endpoint lock returned `403` during the migration window.
The initial clean-working-tree file had CRLF bytes at SHA-256
`6730158402a6e4539af48fb265bee05bf33e2f4d273bddd7cf299ff666c373c3`;
it applied and replayed successfully but is not called the exact Git blob. The
separately archived exact `git show` bytes hash to
`0f07b0da0ab4c9e68308cc3ddf2af4c7e4e2171be81682168202245c8395eb25`.
They were replayed while `safeharbor@localhost` was account-locked after zero
connections, produced `1,1,1,1,12,0,0,0`, and left time facts unchanged. The
account was unlocked after the postflight.

Final database evidence is `1:2:12:0:1:1:0`: one correction column, two
auxiliary tables, twelve permanent triggers, zero staging triggers, one time
entry, one event, and zero measured-registry rows. Matching code deployed from
a clean detached checkout. All 128 non-stamp tracked application paths and the
three normalized cache-stamp paths matched the merge. The protected config
remained byte-identical at
`c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`.
The restored SSL vhost and its pre-lock copy both hash to
`8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43`;
Apache syntax passed. Login is `200`, an unauthenticated timer request is `401`,
Coastmark export remains disabled/empty, and business reports remain
unconfigured/empty with no scheduler.

The following canary remains open and requires a fresh signed-in 8 West IT
session: log one measured pending row,
prove same-technician overlap conflicts while exact adjacency works, reject it
with a reason, submit and idempotently replay one correction, then approve it.
The rejected parent and both original events must remain unchanged; exactly one
pending/approved child may exist. Keep the Coastmark sender disabled. Roll code
back first while leaving the additive 016 structures and stronger guards in
place; database restore is disaster recovery only. The full gate is in
`docs/technician-time-corrections-contract.md`.

Migration 017 (`017_id_report_contact_evidence.sql`) was applied
migration-first on 2026-08-27 through PR #57 / release
`cef39dd190e6488c4aabf7a673eb810b4465a3eb`. It adds only
`business_report_id_tenant_bindings`,
`business_report_id_contact_snapshots`, and six new immutable guards. It does
not alter any migration-013 table or drop/recreate any of migration 013's
fifteen triggers. An earlier application safely ignores both new tables.
Every exact replay verifies candidate columns/defaults, visible indexes,
foreign keys, and enforced checks. Six temporary swap guards block all writes
while the six permanent 017 triggers are replaced; interrupted replay is
recovered only by rerunning the same archived migration blob. Postflight
requires six permanent guards, zero swaps, and zero privilege-preflight
triggers. Snapshot writers serialize on the immutable binding row.

The recorded release used exact-main CI, the standalone
`safeharbor_id_report_test*` MySQL replay, a scratch-restored root-only
application/config/trigger-inclusive-database/grant backup, archived exact Git
bytes, and an apply-plus-replay through the trigger-capable operator. Current
production has one immutable `ewid-t1` tenant binding and one contact-version-1
snapshot from the redacted 8 West IT onboarding probe. Both dedicated contact
gates are off; the recipient and HMAC material remain protected server-side.
Any replay must preserve those two evidence rows, all migration-013 report rows
and trigger hashes, the six named 017 triggers, and zero swap/preflight
triggers. A fresh rebuild instead starts with zero binding/snapshot rows and
must remain disabled until a separately controlled onboarding canary.

Migration 019 (`019_client_report_contact_evidence.sql`) is applied in
production through Safeharbor PR #68 / exact merge
`7bf63edb9bc0583cf865124b45f002d0717dcab9`; exact-main Validate run
`33226867525` passed before deployment. It requires migrations 013 and 017
first and adds `business_report_id_client_bindings`,
`business_report_id_client_contact_snapshots`, and the immutable
`business_report_contact_scope_bindings` registry. It strengthens the schedule
and tenant-evidence insert guards and installs the six independent client-lane
guards.
The recorded release applied and replayed the exact Git blob at SHA-256
`5dceec1c10a8e79515da9a3296e5ce0388fcc1e5e2206759e7ac1cadf77efbfe`
under the Safeharbor-only write freeze and trigger-capable operator. Postflight
proved three migration-019 tables, twelve protected permanent trigger
replacements, zero swap guards, and preserved migration-017 evidence. The
root-only backup is
`/srv/8west/backups/safeharbor/20260829T015329Z-pre-client-report-contact-019`;
release-receipt SHA-256 is
`a044d06c949c473f1fec13fe76b0fd27d4c37486dd2c365de874d83932923a97`
and final evidence-manifest SHA-256 is
`24a07bcb4ea347eb65831348df1ac309e7c662d61cf717c27b5729ba5b6b4f6f`.

The exact client 14 identity binding and one immutable contact snapshot are now
installed. Names, domains, and email addresses were not mapping inputs. Both
contact endpoints were turned off after preparation. The runtime still needs
locking-read `UPDATE` plus `INSERT` on the scope registry and four ID evidence
tables; permanent triggers reject scope updates/deletes.

Deploy code only after the migration and grant postflight. Keep the protected
`business_reports` block absent or fully default-off with empty allowlists on a
fresh environment. The current controlled 8 West IT canary is the explicit
exception: its schedule/tenant/client/recipient allowlists each contain one
exact value while generation and delivery remain false. The deploy script does
not install a scheduler. After one explicitly chosen recipient canary has
passed dry-run, archive, provider-submission, and separate recipient-
confirmation gates, install the reviewed cron entry for
`app/cron/business_reports.php`; observe one scheduled weekly period before
expanding any allowlist. The exact contract, commands, failure semantics, and
rollback are in `docs/business-reports-contract.md`.

The controlled 8 West Lifestyle canary did not pass provider submission or
recipient confirmation. Schedule key `8west-lifestyle-weekly-canary-v1` has
versions 1 disabled, 2 active, and latest version 3 disabled. One archive was
verified at SHA-256
`93bbdab3ac0d42e1da95fa93f8c393123749d7d1548f4a2afa62e95317a8b0ce`.
Its only recipient value in release evidence is SHA-256
`4f57f85e0af372af6b3b09bf48be8961a23fa0fc371f36a9b2d3a54ee4998ef8`.
The single attempt is terminal `uncertain` with outcome
`graph_not_trustworthy` and no provider HTTP status; never retry it. Generation
and delivery are off, both contact endpoints are off, and no cron or systemd
report scheduler is installed.

Root-only canary evidence is at
`/srv/8west/backups/safeharbor/20260829T020451Z-pre-lifestyle-report-canary`;
its receipt SHA-256 is
`86fd9852e7defbe1b809895a6bc2529e9fae9f9acea3f1b274b347fa4b524492`.

The schedule-key gate shipped through PR #61 / exact release
`8322266dbcf3eb6956eb9f1ca79a51948002aaec` on 2026-08-27. Its root-only
backup and scratch-restore proof are at
`/srv/8west/backups/safeharbor/20260827T143419Z-pre-report-schedule-key-gate`.
The deployed tree matched 142 ordinary Git files and three normalized cache-
stamp files; all 115 live PHP files linted, and the protected config remained
preserved. Public acceptance on `safeharbor.8westit.com` passed; the canonical
dry run refused only the incomplete weekly window and left archives,
deliveries, attempts, and mail unchanged.

That receipt also records a process miss: the attempted HTTP maintenance lock
edited the separate support/Milepost vhost. The Safeharbor database account
was locked with zero connections, the wrong vhost was restored exactly, the
actual Safeharbor vhost remained at SHA-256
`8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43`,
and its release window had zero 5xx, fatal, or database errors. This is not
accepted as a successful Safeharbor HTTP lock; future releases must pass the
three-fact vhost preflight above before installation starts.

The Westy receiver hardening then shipped code-only through PR #62 / exact
release `da9560aabf972a9be1f0027445381ddea378688d` on 2026-08-27. Its
root-only rollback record and scratch-restore proof are at
`/srv/8west/backups/safeharbor/20260827150954Z-pre-westy-receiver-da9560a`;
the restore proved 35 base tables and 60 triggers. The deployed tree matched
136 ordinary tracked application files plus three normalized cache-stamp
files, and all 115 live PHP files linted. The exact live hashes are
`097f8c543ef7b831d0ac74f6a2df6223231881b1ef86f67e053d0193373dc703`
for `app/lib/westy_report.php` and
`4f3787744d49a215f80eb51123ae0df949928543b8dd6ef1b362b05cd379c4a4`
for `app/public/api/svc/westy.php`.

This release passed the correct Safeharbor maintenance lock at `403` and
restored the Safeharbor SSL vhost to SHA-256
`8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43`.
Postflight returned login `200`, root `302`, unauthenticated timer `401`, and
unauthenticated Westy intake `401`; the separate support and ID host roots
returned `302`. The database and protected config were unchanged, with zero
new fatal or SQL errors.

The first verification attempt stopped before maintenance mode or code
installation because its checker used the wrong business-report table name;
it changed no live state. The corrected release completed afterward. The
receiver remains dark with zero `milepost-westy` identities. At that release
checkpoint, report generation/delivery, ID report-contact fetch, Coastmark time
export, and the customer portal were off, and no report cron or timer was
installed.

Migration 018 (`018_ticket_auto_close_eligibility.sql`) was applied
migration-first on 2026-08-27 through PR #59 / release
`e7ee5218017c88de18cf6f6476f34a1512593852`. The exact applied Git blob is
18,251 bytes with SHA-256
`cae74bd8348881a36c089d016e0eb76c0b4676341ed0d59e019885f9652ee252`.
Apply and replay each proved one column, six permanent guards, zero swap
guards, and 22 strictly eligible historical alerts; the pre-existing ticket
status digest stayed unchanged. Two signed production canaries proved that an
untouched alert closes on real recovery while a human-noted alert stays Open.
Only the two canary tickets and their six messages were then removed, and the
original 277-ticket/541-message status returned exactly.

For any rebuild or recovery, follow
`docs/ticket-auto-close-ownership-contract.md`: use exact green default-branch
bytes, take and scratch-restore a fresh protected backup, deny only the
Safeharbor vhost, lock only the Safeharbor runtime database account, require
zero runtime connections, and apply plus replay the exact Git blob before
deploying its matching source. Keep both locks until source hashes and PHP lint
match. Roll application code back first while leaving migration 018 and its
fail-closed guards installed; never drop the guards as an incident response.

The web/cron runtime identity must remain DML-only. It needs `SELECT`,
`INSERT`, and `UPDATE` on `safeharbor.*`, plus table-level `DELETE` only on the
eight allowlisted operational tables above; it must not hold `ALTER`,
`CREATE`, `DROP`, `INDEX`, `REFERENCES`, `TRIGGER`, or `GRANT OPTION` because
those privileges can bypass or remove approval audit guards (`TRUNCATE`
requires `DROP`). Preserve the current grant statement in the protected 013
backup record, resolve the exact existing account rather than assuming its
host component, then converge it with the privileged operator. For the current
documented account shape, the SQL is:

```sql
REVOKE DELETE ON safeharbor.* FROM 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.clients TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.contacts TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.canned_responses TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.email_threads TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.messages TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.svc_rate_buckets TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.svc_support_rate TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.tickets TO 'safeharbor'@'localhost';
```

Run migrations only through the reviewed `sudo mysql safeharbor` operator
path. Verify the runtime grants afterward and prove ordinary allowlisted
deletes still work, report/history deletes fail, and the account cannot
`TRUNCATE time_entries` or drop an audit trigger; never print or copy the
account secret.

## Coastmark approved-time sender

Safeharbor's half of the draft-line seam is an operator-only CLI and has no
database migration, scheduler, batch, or automatic retry. Its contract and
canary procedure are in
[`docs/coastmark-approved-time-export-contract.md`](../docs/coastmark-approved-time-export-contract.md).
Deploy it with `coastmark_time_export.enabled=false`, empty tenant/client
allowlists, and no secret. Do not enable it until Coastmark's separately
reviewed receiver migration, forced-RLS/immutability tests, global gate, and one
explicit mapping are ready.

Before any send, run the exact entry as `--dry-run`; the output must contain no
raw note or secret. A canary must prove a 201 create followed by a 200 exact
replay, one immutable Coastmark import/line, and a draft that remains unposted,
unsent, and unrelated to Checkout, payment, or ledger records. Rollback is gate
and mapping disablement, never deletion of accepted financial-side evidence.

`002_svc_intake.sql` collides on 002 with `002_westy_onboarding.sql`, so the
numbering does not order it and its live state is not established by the list
above. Check `tickets.external_key` + `svc_identities` in the live schema.
On a rebuild, 007 remains a hard prerequisite for 8 West ID sign-in — PDO
runs `ERRMODE_EXCEPTION`, so `suite_sso_attempt()` throws if
`users.suite_subject` is absent.

## Shared Westy release boundary

Safeharbor deploys its own prompt, onboarding, chat endpoint and widget code
from this repository. Drag/resize layout is a separate suite-wide asset owned
by `Seckcey/8_west_westy` and loaded from:

```text
https://westy.8westit.com/v1/westy-layout.js
```

Only a reviewed tag published as a GitHub Release in that repository may move
`/v1/`; a commit or branch push alone does not publish. The host pull timer
verifies and installs the release, then atomically repoints `/v1/`. The live
endpoint reported version `1.1.2` with a five-minute cache on 2026-08-08, and
the script stamps `data-westy-version` on `#westy-root` for consumer checks.

This boundary has suite-wide blast radius: every app tracking `/v1/` receives
an approved release inside the cache window. Rollback is central and does not
require a Safeharbor deploy: atomically repoint `/v1/` to a retained frozen
version directory. If Safeharbor alone must be isolated, pin its script URL to
the corresponding immutable path (for example `/1.1.2/westy-layout.js`) in a
separately reviewed app release. Never hand-edit the shared docroot or vendor a
private copy here.

## Server-side state the deploy does NOT manage

- `/srv/8west/apps/safeharbor/shared/config/config.php` — includes the `ai`
  block (Westy's provider/key,
  synced from Milepost's config server-side), `suite` (8 West ID issuer,
  `sso_secret`, cookie name — there is no `suite_sso` block and no SSO kill
  switch in this app; an unset secret simply fails every signature), `svc`
  (Milepost alert intake HMAC), the explicit disabled `portal` scaffold, and
  `storage.attachments_dir`. The deploy must preserve the protected file
  byte-for-byte unless the release has a separate exact config mutation
  allowlist and backup. Every immutable release links to this one root-owned
  file; the normal deploy verifies its digest before and after and never copies
  it into an artifact.
- `/srv/8west/apps/safeharbor/shared/attachments` — attachment bytes,
  `www-data:www-data 770`, created once:
  `sudo mkdir -p /srv/8west/apps/safeharbor/shared/attachments &&
   sudo chown www-data:www-data /srv/8west/apps/safeharbor/shared/attachments &&
   sudo chmod 770 /srv/8west/apps/safeharbor/shared/attachments`.
  Never recursively chmod or chown the whole shared directory: config has a
  separate root-owned boundary. The release controllers only compare the
  attachment directory's filesystem identity; they do not traverse it.
- `/srv/8west/apps/safeharbor/shared/portal-revocations` — private persistent
  portal revocation cache, currently empty. Keep it outside `current/` and
  inaccessible through Apache; do not use it as evidence that an OIDC client
  or customer session exists.

## Smoke test

```bash
curl -sS https://safeharbor.8westit.com/health.php                    # exact revision + digests
curl -s  https://safeharbor.8westit.com/login.php | grep -o '<title>[^<]*'   # Sign in · Safeharbor
curl -sI https://safeharbor.8westit.com/ | head -1                           # 302 → login
curl -sS -X POST -H 'Content-Type: application/json' -d '{}' -o /dev/null \
  -w '%{http_code}\n' https://safeharbor.8westit.com/api/timer.php             # 401
for path in portal/ portal/login.php portal/callback.php portal/logout.php; do
  curl -sS -D - -o /dev/null "https://safeharbor.8westit.com/$path"           # each 404; no Set-Cookie
done
curl -sS -X POST -D - -o /dev/null https://safeharbor.8westit.com/portal/logout.php # 404; no Set-Cookie
cd tools/shots && node walkthrough.mjs                                       # full visual walkthrough
```

## Rollback

Every unsuccessful release automatically restores the exact previous
`current` target while holding the same lock, validates Apache, reloads it, and
repeats the public login/root checks. The failed candidate remains immutable
for diagnosis; no previous release is deleted.

After a release has passed and committed, normal code rollback is forward:
review and merge a revert on `main`, require exact-main CI green, and deploy
that new exact revision through the same controller. This keeps production
equal to reviewed main and avoids an unrecorded hand-edited pointer. A direct
emergency pointer rollback is a separate destructive production operation and
requires its own exact target/digest approval, lock, config/link preflight,
public checks, and receipt; this repository intentionally provides no casual
shortcut for it.

Leave additive migration structures and stronger guards in place when the
reverted code is compatible; migration 016 explicitly supports that boundary.
Restoring a database dump is disaster recovery only because it discards writes
after the backup. Never run `php db/seed.php` in production—it resets demo
data.
