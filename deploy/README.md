# Deploying Safeharbor to safeharbor.8westit.com

Production host: **safeharbor.8westit.com** (AWS EC2, Ubuntu 24.04, Apache
2.4 + Let's Encrypt via certbot). The app is a static SPA served by Apache.

> ⚠️ **Shared box.** Milepost (`support.8westit.com`) and three other sites
> live on this instance. All Safeharbor deploys are **additive only**:
> our own docroot (`/var/www/safeharbor`) and our own vhost
> (`safeharbor-8westit.conf`). Never edit other vhosts, never `restart`
> Apache — `reload` only. Access: `ssh -i ~/.ssh/milepost.pem ubuntu@safeharbor.8westit.com`
> (the `.pem` is the Milepost key — never commit it).

## 1. Provision the vhost (once)

```bash
# from the repo root on your machine
scp -i ~/.ssh/milepost.pem deploy/apache-safeharbor.conf deploy/setup-server.sh \
    ubuntu@safeharbor.8westit.com:/tmp/
ssh -i ~/.ssh/milepost.pem ubuntu@safeharbor.8westit.com \
    "sudo bash /tmp/setup-server.sh"
```

Creates the docroot, installs + enables the vhost, config-tests, and
gracefully reloads Apache.

## 2. Deploy the app (every release)

```bash
KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
```

Builds `app/dist` and streams it to `/var/www/safeharbor` (Git Bash friendly
— tar over ssh, no rsync required). Old files are removed first, limited to
our own docroot.

## 3. TLS (once, after the first deploy)

```bash
ssh -i ~/.ssh/milepost.pem ubuntu@safeharbor.8westit.com \
    "sudo certbot --apache -d safeharbor.8westit.com --redirect"
```

Certbot generates `safeharbor-8westit-le-ssl.conf` and the 80→443 redirect,
matching the box's existing pattern. Renewal is handled by the existing
certbot timer (`sudo certbot renew --dry-run` to verify).

## 4. Smoke test

```bash
curl -sI https://safeharbor.8westit.com | head -5                    # 200 + security headers
curl -s  https://safeharbor.8westit.com | grep -o '<title>[^<]*'     # Safeharbor
curl -sI https://safeharbor.8westit.com/tickets/1042 | head -1       # 200 (SPA fallback)
curl -sI https://safeharbor.8westit.com/brand/favicon.svg | head -1  # 200
```

## Notes

- **No backend yet** (Phase 0). When the API lands: add a
  `ProxyPass /api/ http://127.0.0.1:8787/` block to this vhost only.
- **Rollback**: redeploy from an older git commit (`git checkout <sha> -- app`,
  rebuild, re-run `deploy.sh`).
- DNS: A record `safeharbor.8westit.com` → the instance (already set).
- Security group: 22/80/443 inbound (already set).
