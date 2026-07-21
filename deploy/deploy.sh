#!/usr/bin/env bash
# Deploy the Safeharbor SPA to safeharbor.8westit.com (EC2, Apache).
# Works from Git Bash on Windows (no rsync needed — streams a tarball).
#
# Usage (from the repo root):
#   KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SERVER="${SERVER:-ubuntu@safeharbor.8westit.com}"
DEST="${DEST:-/var/www/safeharbor}"
KEY="${KEY:-}"

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes)
[[ -n "$KEY" ]] && SSH_OPTS+=(-i "$KEY")

echo "==> Building Safeharbor"
cd "$ROOT/app"
npm ci --no-audit --no-fund
npm run build

echo "==> Uploading to $SERVER:$DEST"
# Clean only our own docroot (never anything else on this shared box),
# then stream the build in one SSH session.
tar -czf - -C "$ROOT/app/dist" . | ssh "${SSH_OPTS[@]}" "$SERVER" \
  "mkdir -p '$DEST' && find '$DEST' -mindepth 1 -delete && tar -xzf - -C '$DEST' --no-same-owner && echo 'server: $(ls "$DEST" | wc -l) entries in $DEST'"

echo "==> Live: https://safeharbor.8westit.com"
