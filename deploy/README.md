# Deploying Safeharbor to safeharbor.8westit.com

Production host: **safeharbor.8westit.com** (AWS EC2, Ubuntu 24.04).
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

```bash
# 1. MySQL database + user (password goes into config.php below)
sudo mysql -e "CREATE DATABASE safeharbor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  CREATE USER 'safeharbor'@'localhost' IDENTIFIED BY '<password>'; \
  GRANT ALL PRIVILEGES ON safeharbor.* TO 'safeharbor'@'localhost'; FLUSH PRIVILEGES;"

# 2. App dir + server config (copy config.sample.php, fill in the password)
sudo mkdir -p /srv/8west/apps/safeharbor/current/config
# → write config.php, then:
sudo chown -R ubuntu:www-data /srv/8west/apps/safeharbor
sudo chmod 640 /srv/8west/apps/safeharbor/current/config/config.php

# 3. Deploy the code (from your machine), then schema + seed
KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
sudo mysql safeharbor < /srv/8west/apps/safeharbor/current/db/schema.sql
cd /srv/8west/apps/safeharbor/current && php db/seed.php

# 4. Vhosts (files in this directory) + reload
scp -i ~/.ssh/milepost.pem deploy/apache-safeharbor*.conf ubuntu@safeharbor.8westit.com:/tmp/
ssh -i ~/.ssh/milepost.pem ubuntu@safeharbor.8westit.com \
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

Applied to production so far: 001 (mail_queue) · 002 (westy/onboarding) ·
003 (canned_responses) · 004 (attachments, email_threads, processed_mail,
resurface_at) · 005 (ticket_presence, merged_into_id, FULLTEXT) · 006 (csat).

## Server-side state the deploy does NOT manage

- `config/config.php` — includes the `ai` block (Westy's provider/key,
  synced from Milepost's config server-side), `suite_sso`/`suite` (SSO
  secret), `svc` (Milepost alert intake HMAC, dark until 8.1.2), and
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
git checkout <older-sha> && KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
```

DB is forward-only (schema.sql is idempotent via IF NOT EXISTS); reseeding
(`php db/seed.php`) resets demo data.
