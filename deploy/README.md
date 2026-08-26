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
SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
```

No build step — the script lints the PHP, syncs brand assets, and streams
`app/` to the server (never overwriting `config/config.php`), then fixes
ownership (`ubuntu:www-data`) and perms (dirs 2750, files 640). Static assets
are cache-busted Milepost-style with `?v=` in `lib/render.php`,
`lib/westy.php`, and `public/login.php`.

**If the release ships a new `db/migrations/NNN_*.sql`, apply it** (deploys
never touch the DB):

```bash
ssh -i ~/.ssh/milepost.pem ubuntu@<origin-ip> "sudo mysql safeharbor" \
  < app/db/migrations/NNN_whatever.sql
```

Recorded as applied to production: 001 (mail_queue) · 002 (westy/onboarding) ·
003 (canned_responses) · 004 (attachments, email_threads, processed_mail,
resurface_at) · 005 (ticket_presence, merged_into_id, FULLTEXT) · 006 (csat) ·
007 (suite_subject) · 008 (Westy reports) · 009 (support intake) ·
**010 (versioned service goals, migration-first on 2026-08-26)** ·
**011 (approval-grade technician time, migration-first on 2026-08-26)**.

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

Migration 012 (`customer_portal`) is **not applied** and its source is not a
deployment instruction. It requires the production 011/composite ownership
keys already in place. Before any later authorized portal release, run
`portal_mysql_test.php` against disposable MySQL 8, take protected application
and database backups, apply 012 with the trigger-capable operator, and verify
the two tables, composite provider tenant/client FK, globally unique identity
slug/client indexes, zero mappings, and seven lifecycle/audit triggers. Deploy
with `portal.enabled=false`; `/portal/` must remain 404. Keep the switch false
and run no portal CLI transition concurrently with the migration or a replay.

Only after a separately reviewed 8 West ID confidential client exists may the
server-only portal issuer/client/callback/cache configuration be populated.
The revocation cache belongs under the private persistent `shared/` tree and
must be writable by the web identity without exposing it through Apache. One
explicit canary mapping then follows prepare-disabled → inspect → explicit
enable while the global switch is still false. Enabling the host and any live
canary require their own authorization. The exact canary, active-session,
revocation, tenant-isolation, and rollback sequence is in
`docs/customer-portal-contract.md`.

The web/cron runtime identity must remain DML-only. It needs `SELECT`,
`INSERT`, `UPDATE`, and `DELETE` on `safeharbor.*`; it must not hold `ALTER`,
`CREATE`, `DROP`, `INDEX`, `REFERENCES`, `TRIGGER`, or `GRANT OPTION` because
those privileges can bypass or remove approval audit guards (`TRUNCATE`
requires `DROP`). Before calling migration 011 complete, preserve the current
grant statement in the protected backup record, then converge the existing
runtime account with the privileged operator:

```sql
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'safeharbor'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON safeharbor.* TO 'safeharbor'@'localhost';
```

Run migrations only through the reviewed `sudo mysql safeharbor` operator
path. Verify the runtime grant afterward and prove it cannot `TRUNCATE
time_entries` or drop an audit trigger; never print or copy the account secret.

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
  (Milepost alert intake HMAC, dark until 8.1.2), and
  `storage.attachments_dir`.
- `/srv/8west/apps/safeharbor/shared/attachments` — attachment bytes,
  `www-data:www-data 770`, created once:
  `sudo mkdir -p /srv/8west/apps/safeharbor/shared/attachments &&
   sudo chown -R www-data:www-data /srv/8west/apps/safeharbor/shared &&
   sudo chmod -R 770 /srv/8west/apps/safeharbor/shared`
  (outside `current/` on purpose — the deploy re-chmods `current/`).

## Smoke test

```bash
curl -s  https://safeharbor.8westit.com/login.php | grep -o '<title>[^<]*'   # Sign in · Safeharbor
curl -sI https://safeharbor.8westit.com/ | head -1                           # 302 → login
cd tools/shots && node walkthrough.mjs                                       # full visual walkthrough
```

## Rollback

```bash
git checkout <older-sha> && SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
```

DB is forward-only (schema.sql is idempotent via IF NOT EXISTS); reseeding
(`php db/seed.php`) resets demo data.
