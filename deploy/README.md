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

## Layout on the server (mirrors Milepost)

```
/srv/8west/apps/safeharbor/current/   the app (deploy target)
  config/config.php                   DB credentials (server-only, ubuntu:www-data 640)
  db/ lib/ public/                    code (deployed from this repo)
/etc/apache2/sites-available/
  safeharbor-8westit.conf             port 80 (HTTPS redirect)
  safeharbor-8westit-le-ssl.conf      port 443 (Let's Encrypt)
```

## One-time setup (already done 2026-07-21; kept for rebuilds)

> **Do not run `deploy/setup-server.sh` as currently written.** It still
> creates the retired `/var/www/safeharbor` path while the authoritative app
> root is `/srv/8west/apps/safeharbor/current`. The script is an outstanding
> rebuild blocker and was deliberately left untouched in this documentation
> closeout. Until it is repaired and reviewed, follow the commands below.

```bash
# 1. MySQL database + user (password goes into config.php below)
sudo mysql -e "CREATE DATABASE safeharbor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  CREATE USER 'safeharbor'@'localhost' IDENTIFIED BY '<password>'; \
  GRANT SELECT, INSERT, UPDATE, DELETE ON safeharbor.* TO 'safeharbor'@'localhost'; \
  FLUSH PRIVILEGES;"

# 2. App dir + server config (copy config.sample.php, fill in the password)
sudo mkdir -p /srv/8west/apps/safeharbor/current/config
# → write config.php, then:
sudo chown -R ubuntu:www-data /srv/8west/apps/safeharbor
sudo chmod 640 /srv/8west/apps/safeharbor/current/config/config.php

# 3. Deploy the code (from your machine), then load schema as an operator.
# Never seed production; db/seed.php is a destructive sandbox-only reset.
KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
sudo mysql safeharbor < /srv/8west/apps/safeharbor/current/db/schema.sql

# 4. Vhosts (files in this directory) + reload
scp -i ~/.ssh/milepost.pem deploy/apache-safeharbor*.conf ubuntu@<origin-ip>:/tmp/
ssh -i ~/.ssh/milepost.pem ubuntu@<origin-ip> \
  "sudo cp /tmp/apache-safeharbor.conf /etc/apache2/sites-available/safeharbor-8westit.conf && \
   sudo cp /tmp/apache-safeharbor-le-ssl.conf /etc/apache2/sites-available/safeharbor-8westit-le-ssl.conf && \
   sudo apache2ctl configtest && sudo systemctl reload apache2"
```

TLS note: the cert was issued with the box's existing certbot (snap). It lives
at `/etc/letsencrypt/live/safeharbor.8westit.com/` and renews automatically.

## Every release

```bash
git pull --ff-only                                   # ALWAYS — parallel agents work this repo
# Run the reviewed tests, backup, and any migration-specific ordering first.
# Deploy only after every required migration postflight passes.
SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
```

No build step — the script lints the PHP, syncs brand assets, and streams
`app/` to the server (never overwriting `config/config.php`), then fixes
ownership (`ubuntu:www-data`) and perms (dirs 2750, files 640). Static assets
are cache-busted Milepost-style with `?v=` in `lib/render.php`,
`lib/westy.php`, and `public/login.php`.

**If the release ships a new `db/migrations/NNN_*.sql`, follow that
migration's reviewed ordering** (deploys never touch the DB). Migrations 011
through 014 are explicitly migration-first; do not infer deploy-first from the
generic release command:

```bash
ssh -i ~/.ssh/milepost.pem ubuntu@<origin-ip> "sudo mysql safeharbor" \
  < app/db/migrations/NNN_whatever.sql
```

Recorded as applied to production: 001 (mail_queue) · 002 (westy/onboarding) ·
003 (canned_responses) · 004 (attachments, email_threads, processed_mail,
resurface_at) · 005 (ticket_presence, merged_into_id, FULLTEXT) · 006 (csat) ·
007 (suite_subject) · 008 (Westy reports) · 009 (support intake) ·
**010 (versioned service goals, migration-first on 2026-08-26)** ·
**011 (approval-grade technician time, migration-first on 2026-08-26)** ·
**012 (dark customer portal boundary, migration-first on 2026-08-26)** ·
**013 (versioned archived business reports, migration-first on 2026-08-26)** ·
**014 (guarded service-goal publication, migration-first on 2026-08-27)**.

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

The protected config now has an explicit `portal.enabled=false` block at
SHA-256
`c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`.
It contains the fixed issuer and
`https://safeharbor.8westit.com/portal/callback.php`, but its client ID and
secret are empty. The private persistent revocation-cache directory exists
with private permissions and is empty. `/portal/`, login, callback, and both
GET and POST logout return 404 without setting a session cookie. 8 West ID's
`f0ec49b` prerequisite is deployed dark with zero registered Safeharbor OIDC
clients; do not mistake source readiness for a usable identity client.

The protected rollback record is
`/srv/8west/backups/safeharbor/20260826T234825Z-pre-phase5a-portal`; its verified
application archive SHA-256 is
`e7890c2145636942ab13a3a0dce44ef13749a2647f8e7a2bfdf8119424906131`
and its trigger-inclusive database dump SHA-256 is
`3d316813f6d7524ccdfbd20bb056f1f66c4ab7bd3f69c0df291c66bb0d336819`.
Rollback is code-first while the gate stays false; leave the two empty additive
tables in place because prior code ignores them. A database restore is disaster
recovery only because it would discard writes made after the backup.

Preserve the explicit disabled non-secret scaffold and private empty cache.
Only after a separately reviewed 8 West ID confidential client exists may its
client ID and secret be installed through the protected operator path; never
commit, print, or copy either value into release evidence. Keep the global
switch false while one expressly approved canary mapping follows
prepare-disabled → inspect → explicit enable. A live canary still requires
deliberate global enablement, a fresh signed-in customer session, isolation,
revocation, disable-on-next-request, logout, and desktop/mobile proof. The
current portal is ticket-summary-only and must not gain billing, mutation, or
customer endpoint control through activation. The exact sequence is in
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

Deploy code only after the migration and grant postflight. Keep the protected
`business_reports` block absent or fully default-off with empty allowlists.
The deploy script does not install a scheduler. After one explicitly chosen
recipient canary has passed dry-run, archive, provider-submission, and separate
recipient-confirmation gates, install the reviewed cron entry for
`app/cron/business_reports.php`; observe one scheduled weekly period before
expanding any allowlist. The exact contract, commands, failure semantics, and
rollback are in `docs/business-reports-contract.md`.

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

- `config/config.php` — includes the `ai` block (Westy's provider/key,
  synced from Milepost's config server-side), `suite` (8 West ID issuer,
  `sso_secret`, cookie name — there is no `suite_sso` block and no SSO kill
  switch in this app; an unset secret simply fails every signature), `svc`
  (Milepost alert intake HMAC), the explicit disabled `portal` scaffold, and
  `storage.attachments_dir`. The deploy must preserve the protected file
  byte-for-byte unless the release has a separate exact config mutation
  allowlist and backup.
- `/srv/8west/apps/safeharbor/shared/attachments` — attachment bytes,
  `www-data:www-data 770`, created once:
  `sudo mkdir -p /srv/8west/apps/safeharbor/shared/attachments &&
   sudo chown -R www-data:www-data /srv/8west/apps/safeharbor/shared &&
   sudo chmod -R 770 /srv/8west/apps/safeharbor/shared`
  (outside `current/` on purpose — the deploy re-chmods `current/`).
- `/srv/8west/apps/safeharbor/shared/portal-revocations` — private persistent
  portal revocation cache, currently empty. Keep it outside `current/` and
  inaccessible through Apache; do not use it as evidence that an OIDC client
  or customer session exists.

## Smoke test

```bash
curl -s  https://safeharbor.8westit.com/login.php | grep -o '<title>[^<]*'   # Sign in · Safeharbor
curl -sI https://safeharbor.8westit.com/ | head -1                           # 302 → login
for path in portal/ portal/login.php portal/callback.php portal/logout.php; do
  curl -sS -D - -o /dev/null "https://safeharbor.8westit.com/$path"           # each 404; no Set-Cookie
done
curl -sS -X POST -D - -o /dev/null https://safeharbor.8westit.com/portal/logout.php # 404; no Set-Cookie
cd tools/shots && node walkthrough.mjs                                       # full visual walkthrough
```

## Rollback

```bash
git checkout <older-sha> && SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
```

DB is forward-only (schema.sql is idempotent via IF NOT EXISTS); reseeding
(`php db/seed.php`) resets demo data.
