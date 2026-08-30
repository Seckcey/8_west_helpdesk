#!/usr/bin/env bash
# Deploy the Safeharbor PHP app to safeharbor.8westit.com (EC2, Apache).
# No build step (plain PHP, like Milepost) — sync brand assets, stream a
# tarball, untar into the app dir, stamp asset versions for cache-busting.
# config/config.php on the server is NEVER overwritten.
#
# NEVER edit the deployed tree by hand. This script untars straight over
# /srv/8west/apps/safeharbor/current, so a host-side edit is destroyed without
# comment on the next run — and until then production is running code that no
# commit describes and nobody can review or roll back. On 2026-08-02 Milepost
# was found carrying drill-era hot patches in lib/isolation.php and
# lib/tools.php that were not byte-identical to the merged fixes; the live tree
# and main had quietly diverged. Milepost now refuses a full deploy when its
# state file disagrees with the tree. This script has no such guard.
#
# If it runs in production, it is merged. Host-side experiments belong in a
# disposable copy (/tmp), never in the deploy directory.
#
# The untar is also not atomic: a request arriving mid-extraction can read a
# half-written file, which is what produced the one-off
# "SQLSTATE[HY093] Invalid parameter number" in the error log at 02:57:19 on
# 2026-08-02 — not a bind bug; every statement in that file balances. Fixing
# it properly means the release-symlink pattern plus an Apache change, since
# the <Directory> blocks match the resolved path.
#
# Usage (from the repo root, Git Bash):
#   KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SERVER="${SERVER:-ubuntu@safeharbor.8westit.com}"
DEST="${DEST:-/srv/8west/apps/safeharbor/current}"
KEY="${KEY:-}"
REMOTE_INSTALLER="$ROOT/deploy/remote-install-safeharbor-app.sh"

[[ "$DEST" == '/srv/8west/apps/safeharbor/current' ]] || {
  printf 'Refusing non-canonical Safeharbor destination: %s\n' "$DEST" >&2
  exit 64
}
[[ -f "$REMOTE_INSTALLER" && ! -L "$REMOTE_INSTALLER" ]] || {
  printf 'Reviewed remote installer is missing or symlinked.\n' >&2
  exit 66
}

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
bash -n "$REMOTE_INSTALLER"
if find "$ROOT/app" -type l -print -quit | grep -q .; then
  printf 'Refusing an application artifact containing symlinks.\n' >&2
  exit 65
fi

echo "==> Staging exact root-owned deploy helper on $SERVER"
INSTALLER_SHA="$(sha256sum "$REMOTE_INSTALLER")"
INSTALLER_SHA="${INSTALLER_SHA%% *}"
REMOTE_INSTALLER_PATH="/run/safeharbor-deploy/remote-install-safeharbor-app.$INSTALLER_SHA.$$.sh"
REMOTE_INSTALLER_STAGED=0
cleanup_remote_installer() {
  if [[ "$REMOTE_INSTALLER_STAGED" -eq 1 ]]; then
    ssh "${SSH_OPTS[@]}" "$SERVER" \
      "sudo /usr/bin/unlink -- '$REMOTE_INSTALLER_PATH'" >/dev/null 2>&1 || true
  fi
}
trap cleanup_remote_installer EXIT

ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo /usr/bin/install -d -o root -g root -m 0700 -- /run/safeharbor-deploy \
   && sudo /usr/bin/tee '$REMOTE_INSTALLER_PATH' >/dev/null \
   && sudo /usr/bin/chown root:root -- '$REMOTE_INSTALLER_PATH' \
   && sudo /usr/bin/chmod 0600 -- '$REMOTE_INSTALLER_PATH' \
   && test \"\$(sudo /usr/bin/stat -c '%U:%G:%a' -- '$REMOTE_INSTALLER_PATH')\" = root:root:600 \
   && printf '%s  %s\n' '$INSTALLER_SHA' '$REMOTE_INSTALLER_PATH' \
      | sudo /usr/bin/sha256sum -c --status" < "$REMOTE_INSTALLER"
REMOTE_INSTALLER_STAGED=1

echo "==> Uploading to $SERVER:$DEST under the report/deploy lock"
tar -czf - -C "$ROOT" \
  --exclude='app/config/config.php' \
  app | ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo /usr/bin/bash '$REMOTE_INSTALLER_PATH'"

ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo /usr/bin/unlink -- '$REMOTE_INSTALLER_PATH'"
REMOTE_INSTALLER_STAGED=0
trap - EXIT

echo "==> Live: https://safeharbor.8westit.com"
