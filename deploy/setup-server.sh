#!/usr/bin/env bash
# One-time Safeharbor vhost setup — run ON the EC2 server (Ubuntu 24.04).
#
#     sudo bash setup-server.sh
#
# SAFETY: Milepost and three other sites live on this box under Apache.
# This script is additive only: it creates /var/www/safeharbor and ONE new
# vhost file, then reloads (never restarts) Apache. It does not install or
# remove packages, and never touches existing vhosts.
set -euo pipefail

SITE=safeharbor-8westit
SRC="$(cd "$(dirname "$0")" && pwd)"

echo "==> Web root"
mkdir -p /var/www/safeharbor
chown -R ubuntu:ubuntu /var/www/safeharbor

echo "==> Installing vhost ($SITE)"
cp "$SRC/apache-safeharbor.conf" "/etc/apache2/sites-available/$SITE.conf"
a2ensite "$SITE" >/dev/null

echo "==> Config test"
apache2ctl configtest

echo "==> Reloading Apache (graceful, no dropped connections)"
systemctl reload apache2

echo "==> Done. Now deploy the app, then enable TLS:"
echo "      sudo certbot --apache -d safeharbor.8westit.com --redirect"
