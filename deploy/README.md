# Deploying Safeharbor to safeharbor.8westit.com

Production host: **safeharbor.8westit.com** (AWS EC2). The app is a static
SPA served by nginx; TLS via Let's Encrypt.

## Prerequisites (one-time, AWS console)

1. **Elastic IP** allocated and attached to the instance (so the IP survives reboots).
2. **DNS**: `A` record `safeharbor.8westit.com` → that Elastic IP.
3. **Security group**: inbound 80 + 443 from `0.0.0.0/0` (and 22 from our IPs).

## 1. Provision the server (once)

```bash
# on your machine — copy the kit up
scp -i ~/.ssh/8west.pem -r deploy ubuntu@<EC2-IP>:/tmp/deploy

# on the server
ssh -i ~/.ssh/8west.pem ubuntu@<EC2-IP>
sudo bash /tmp/deploy/provision.sh
```

(Assumes Ubuntu. On Amazon Linux 2023: `dnf install nginx certbot rsync`,
then steps are the same.)

## 2. Deploy the app (every release)

```bash
# from the repo root on your machine
SERVER=ubuntu@<EC2-IP> KEY=~/.ssh/8west.pem bash deploy/deploy.sh
```

Builds `app/dist` (`npm ci && npm run build`) and rsyncs it to
`/var/www/safeharbor` with `--delete` (old hashed assets are removed —
safe, because `index.html` is `no-cache` and assets are content-hashed).

## 3. TLS (once, after the first deploy)

```bash
sudo certbot --nginx -d safeharbor.8westit.com
```

Certbot edits `nginx-safeharbor.conf` in place (adds 443 + redirect) and
sets up auto-renewal. Verify: `sudo certbot renew --dry-run`.

## 4. Smoke test

```bash
curl -I https://safeharbor.8westit.com          # 200, security headers
curl -s https://safeharbor.8westit.com | grep -o "<title>[^<]*"   # Safeharbor
```

Then open the site and sign in (Phase 0: any email).

## Notes

- **No backend yet** (Phase 0). When the API lands (Phase 1+), add a
  `location /api/ { proxy_pass ...; }` block — the SPA calls same-origin.
- **Rollbacks**: keep the previous `dist/` as `dist-prev/` locally;
  re-running `deploy.sh` from the older commit restores it.
- **do NOT** commit PEM keys. `.gitignore` already excludes `*.key` /
  `secrets.*`; keep `8west.pem` out of the repo.
