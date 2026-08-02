#!/usr/bin/env bash
# Deploy the Safeharbor PHP app to safeharbor.8westit.com (EC2, Apache).
# No build step (plain PHP, like Milepost) — sync brand assets, stream a
# tarball, untar into the app dir, stamp asset versions for cache-busting.
# config/config.php on the server is NEVER overwritten.
#
# Usage (from the repo root, Git Bash):
#   KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SERVER="${SERVER:-ubuntu@safeharbor.8westit.com}"
DEST="${DEST:-/srv/8west/apps/safeharbor/current}"
KEY="${KEY:-}"

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes)
[[ -n "$KEY" ]] && SSH_OPTS+=(-i "$KEY")

echo "==> Syncing brand assets"
mkdir -p "$ROOT/app/public/assets/brand"
cp "$ROOT/brand/svg/favicon.svg" "$ROOT/brand/svg/safeharbor-mark.svg" "$ROOT/app/public/assets/brand/"
cp "$ROOT/brand/png/favicon.ico" "$ROOT/brand/png/apple-touch-icon.png" \
   "$ROOT/brand/png/app-tile-192.png" "$ROOT/brand/png/app-tile-512.png" \
   "$ROOT/app/public/assets/brand/"

echo "==> Linting PHP"
find "$ROOT/app" -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null

echo "==> Uploading to $SERVER:$DEST"
tar -czf - -C "$ROOT" \
  --exclude='app/config/config.php' \
  app | ssh "${SSH_OPTS[@]}" "$SERVER" \
  "mkdir -p '$DEST' && tar -xzf - -C '$DEST' --strip-components=1 --no-same-owner \
   && sudo chown -R ubuntu:www-data '$DEST' \
   && sudo find '$DEST' -type d -exec chmod 2750 {} + \
   && sudo find '$DEST' -type f -exec chmod 640 {} + \
   && V=\$(date +%Y%m%d%H%M%S) \
   && sudo sed -i \"s/?v=[0-9A-Za-z]\\+/?v=\$V/g\" '$DEST/lib/render.php' '$DEST/lib/westy.php' '$DEST/public/login.php' \
   && echo \"server: deployed (assets v=\$V)\""

echo "==> Live: https://safeharbor.8westit.com"
