#!/usr/bin/env bash
# Deploy the Safeharbor SPA to safeharbor.8westit.com.
#
# Usage (from the repo root, Git Bash/WSL):
#   SERVER=ubuntu@<EC2-IP> KEY=~/.ssh/8west.pem bash deploy/deploy.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SERVER="${SERVER:?Set SERVER=user@host (e.g. SERVER=ubuntu@54.1.2.3)}"
DEST="${DEST:-/var/www/safeharbor}"
KEY="${KEY:-}"

SSH_OPTS=(-o StrictHostKeyChecking=accept-new)
[[ -n "$KEY" ]] && SSH_OPTS+=(-i "$KEY")

echo "==> Building Safeharbor"
cd "$ROOT/app"
npm ci --no-audit --no-fund
npm run build

echo "==> Uploading to $SERVER:$DEST"
ssh "${SSH_OPTS[@]}" "$SERVER" "mkdir -p '$DEST'"
rsync -az --delete -e "ssh ${SSH_OPTS[*]}" "$ROOT/app/dist/" "$SERVER:$DEST/"

echo "==> Live: https://safeharbor.8westit.com"
