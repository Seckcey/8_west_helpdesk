# Deploying Safeharbor to safeharbor.8westit.com

Production host: **safeharbor.8westit.com** (AWS EC2, Ubuntu 24.04).
Stack: **Apache 2.4 + mod_php (PHP 8.3) + MySQL 8** — identical to Milepost,
which lives on the same box (`support.8westit.com`).

> ⚠️ **Shared box.** Milepost and three other sites live here. All Safeharbor
> work is **additive only**: own app dir (`/srv/8west/apps/safeharbor/`), own
> docroot, own vhost pair, own MySQL database + user. Never edit other vhosts,
> never `restart` Apache — `reload` only.
> SSH: `ssh -i ~/.ssh/milepost.pem ubuntu@safeharbor.8westit.com`
> (the `.pem` is the Milepost key — never commit it).

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
KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
```

No build step — the script lints the PHP, syncs brand assets, and streams
`app/` to the server (never overwriting `config/config.php`), then fixes
ownership (`ubuntu:www-data`) and perms (dirs 2750, files 640). Static assets
are cache-busted Milepost-style with `?v=` in `lib/render.php`.

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
