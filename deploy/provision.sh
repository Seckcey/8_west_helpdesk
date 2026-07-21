#!/usr/bin/env bash
# One-time server provisioning for safeharbor.8westit.com (Ubuntu on EC2).
# Run ON the server:  sudo bash provision.sh
set -euo pipefail

echo "==> Installing nginx + certbot"
apt-get update -y
apt-get install -y nginx certbot python3-certbot-nginx rsync

echo "==> Web root"
mkdir -p /var/www/safeharbor
chown -R ubuntu:ubuntu /var/www/safeharbor

echo "==> nginx site"
cp "$(dirname "$0")/nginx-safeharbor.conf" /etc/nginx/conf.d/safeharbor.conf
nginx -t
systemctl enable --now nginx
systemctl reload nginx

echo "==> Done. Next: deploy the app (deploy.sh), then TLS:"
echo "    sudo certbot --nginx -d safeharbor.8westit.com"
