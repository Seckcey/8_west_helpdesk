#!/usr/bin/env bash
# Deploy the Safeharbor PHP app to safeharbor.8westit.com (EC2, Apache).
# No application build step (plain PHP, like Milepost) — assemble the exact Git
# app plus derived brand assets, stream a tarball, untar into the app dir, and
# stamp asset versions for cache-busting.
# config/config.php on the server is NEVER overwritten.
#
# NEVER edit the deployed tree by hand. This script untars straight over
# /srv/8west/apps/safeharbor/current, so a host-side edit is destroyed without
# comment on the next run — and until then production is running code that no
# commit describes and nobody can review or roll back. On 2026-08-02 Milepost
# was found carrying drill-era hot patches in lib/isolation.php and
# lib/tools.php that were not byte-identical to the merged fixes; the live tree
# and main had quietly diverged. Milepost now refuses a full deploy when its
# state file disagrees with the tree. Safeharbor now refuses a dirty local
# release and makes the remote tree match its complete clean-source digest
# before recording the post-cache-stamp deployed artifact. Because extraction
# is still non-atomic, a mismatch requires release recovery rather than an
# automatic rollback.
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
ARTIFACT_HASHER="$ROOT/deploy/hash-safeharbor-app-artifact.sh"

[[ "$DEST" == '/srv/8west/apps/safeharbor/current' ]] || {
  printf 'Refusing non-canonical Safeharbor destination: %s\n' "$DEST" >&2
  exit 64
}
[[ -f "$REMOTE_INSTALLER" && ! -L "$REMOTE_INSTALLER" ]] || {
  printf 'Reviewed remote installer is missing or symlinked.\n' >&2
  exit 66
}
[[ -f "$ARTIFACT_HASHER" && ! -L "$ARTIFACT_HASHER" ]] || {
  printf 'Reviewed application artifact hasher is missing or symlinked.\n' >&2
  exit 66
}

require_clean_release() {
  local status
  status="$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)"
  [[ -z "$status" ]] || {
    printf 'Refusing to deploy a dirty or untracked release checkout.\n' >&2
    exit 65
  }
}

require_clean_release
RELEASE_SHA="$(git -C "$ROOT" rev-parse --verify 'HEAD^{commit}')"
[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || {
  printf 'Refusing an invalid Git release commit.\n' >&2
  exit 65
}

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes)
[[ -n "$KEY" ]] && SSH_OPTS+=(-i "$KEY")

LOCAL_TEMP_BASE="${TMPDIR:-/tmp}"
[[ -d "$LOCAL_TEMP_BASE" && ! -L "$LOCAL_TEMP_BASE" ]] || {
  printf 'Refusing an unsafe local release-staging parent.\n' >&2
  exit 77
}
LOCAL_TEMP_PHYSICAL="$(cd -- "$LOCAL_TEMP_BASE" && pwd -P)"
[[ "$LOCAL_TEMP_PHYSICAL" != '/' && "$LOCAL_TEMP_PHYSICAL" != "$ROOT" ]] || {
  printf 'Refusing a broad or in-repository release-staging parent.\n' >&2
  exit 77
}
release_staging_created="$(mktemp -d "$LOCAL_TEMP_PHYSICAL/safeharbor-release.XXXXXXXX")"
RELEASE_STAGING="$(cd -- "$release_staging_created" && pwd -P)"
[[ -d "$RELEASE_STAGING" && ! -L "$RELEASE_STAGING" \
    && "${RELEASE_STAGING##*/}" == safeharbor-release.* \
    && "$RELEASE_STAGING" != '/' ]] || {
  printf 'Refusing an unsafe local release-staging directory.\n' >&2
  exit 77
}
cleanup_release_staging() {
  if [[ -n "$RELEASE_STAGING" ]]; then
    case "${RELEASE_STAGING##*/}" in
      safeharbor-release.*) rm -rf -- "$RELEASE_STAGING" ;;
      *) printf 'Refusing unsafe release-staging cleanup: %s\n' "$RELEASE_STAGING" >&2 ;;
    esac
  fi
}
trap cleanup_release_staging EXIT

echo "==> Building exact Git release artifact and derived brand assets"
# Produce the same bytes on Windows and Linux without changing checkout settings.
git -C "$ROOT" -c core.autocrlf=false -c core.eol=lf archive --format=tar "$RELEASE_SHA" app brand \
  | tar -xf - -C "$RELEASE_STAGING"
mkdir -p "$RELEASE_STAGING/app/public/assets/brand"
cp "$RELEASE_STAGING/brand/svg/favicon.svg" \
   "$RELEASE_STAGING/brand/svg/safeharbor-mark.svg" \
   "$RELEASE_STAGING/app/public/assets/brand/"
cp "$RELEASE_STAGING/brand/png/favicon.ico" \
   "$RELEASE_STAGING/brand/png/apple-touch-icon.png" \
   "$RELEASE_STAGING/brand/png/app-tile-192.png" \
   "$RELEASE_STAGING/brand/png/app-tile-512.png" \
   "$RELEASE_STAGING/app/public/assets/brand/"

echo "==> Linting PHP"
find "$RELEASE_STAGING/app" -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null
bash -n "$REMOTE_INSTALLER"
bash -n "$ARTIFACT_HASHER"
bash -n "$RELEASE_STAGING/app/cron/run_business_reports.sh"
if find "$RELEASE_STAGING/app" -type l -print -quit | grep -q .; then
  printf 'Refusing an application artifact containing symlinks.\n' >&2
  exit 65
fi
require_clean_release

HASHER_SHA="$(sha256sum "$ARTIFACT_HASHER")"
HASHER_SHA="${HASHER_SHA%% *}"
SOURCE_ARTIFACT_SHA="$(bash "$ARTIFACT_HASHER" "$RELEASE_STAGING/app")"
[[ "$HASHER_SHA" =~ ^[0-9a-f]{64}$ \
    && "$SOURCE_ARTIFACT_SHA" =~ ^[0-9a-f]{64}$ ]] || {
  printf 'Refusing invalid release artifact hashes.\n' >&2
  exit 74
}
require_clean_release
[[ "$(git -C "$ROOT" rev-parse --verify 'HEAD^{commit}')" == "$RELEASE_SHA" ]] || {
  printf 'Refusing a release checkout whose commit changed during preparation.\n' >&2
  exit 65
}

echo "==> Staging exact root-owned deploy controls on $SERVER"
INSTALLER_SHA="$(sha256sum "$REMOTE_INSTALLER")"
INSTALLER_SHA="${INSTALLER_SHA%% *}"
REMOTE_INSTALLER_PATH="/run/safeharbor-deploy/remote-install-safeharbor-app.$INSTALLER_SHA.$$.sh"
REMOTE_HASHER_PATH="/run/safeharbor-deploy/hash-safeharbor-app-artifact.$HASHER_SHA.$$.sh"
REMOTE_INSTALLER_STAGED=0
REMOTE_HASHER_STAGED=0
cleanup_remote_controls() {
  if [[ "$REMOTE_INSTALLER_STAGED" -eq 1 ]]; then
    ssh "${SSH_OPTS[@]}" "$SERVER" \
      "sudo /usr/bin/unlink -- '$REMOTE_INSTALLER_PATH'" >/dev/null 2>&1 || true
  fi
  if [[ "$REMOTE_HASHER_STAGED" -eq 1 ]]; then
    ssh "${SSH_OPTS[@]}" "$SERVER" \
      "sudo /usr/bin/unlink -- '$REMOTE_HASHER_PATH'" >/dev/null 2>&1 || true
  fi
}
cleanup_deploy_staging() {
  cleanup_remote_controls
  cleanup_release_staging
}
trap cleanup_deploy_staging EXIT

ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo /usr/bin/install -d -o root -g root -m 0700 -- /run/safeharbor-deploy \
   && sudo /usr/bin/tee '$REMOTE_INSTALLER_PATH' >/dev/null \
   && sudo /usr/bin/chown root:root -- '$REMOTE_INSTALLER_PATH' \
   && sudo /usr/bin/chmod 0600 -- '$REMOTE_INSTALLER_PATH' \
   && test \"\$(sudo /usr/bin/stat -c '%U:%G:%a' -- '$REMOTE_INSTALLER_PATH')\" = root:root:600 \
   && printf '%s  %s\n' '$INSTALLER_SHA' '$REMOTE_INSTALLER_PATH' \
      | sudo /usr/bin/sha256sum -c --status" < "$REMOTE_INSTALLER"
REMOTE_INSTALLER_STAGED=1

ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo /usr/bin/tee '$REMOTE_HASHER_PATH' >/dev/null \
   && sudo /usr/bin/chown root:root -- '$REMOTE_HASHER_PATH' \
   && sudo /usr/bin/chmod 0600 -- '$REMOTE_HASHER_PATH' \
   && test \"\$(sudo /usr/bin/stat -c '%U:%G:%a' -- '$REMOTE_HASHER_PATH')\" = root:root:600 \
   && printf '%s  %s\n' '$HASHER_SHA' '$REMOTE_HASHER_PATH' \
      | sudo /usr/bin/sha256sum -c --status" < "$ARTIFACT_HASHER"
REMOTE_HASHER_STAGED=1

echo "==> Uploading to $SERVER:$DEST under the report/deploy lock"
tar -czf - -C "$RELEASE_STAGING" \
  --exclude='app/config/config.php' \
  app | ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo /usr/bin/bash '$REMOTE_INSTALLER_PATH' \
    --release-sha '$RELEASE_SHA' \
    --artifact-hasher '$REMOTE_HASHER_PATH' \
    --expect-hasher-sha256 '$HASHER_SHA' \
    --expect-source-artifact-sha256 '$SOURCE_ARTIFACT_SHA'"

ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo /usr/bin/unlink -- '$REMOTE_INSTALLER_PATH' \
   && sudo /usr/bin/unlink -- '$REMOTE_HASHER_PATH'"
REMOTE_INSTALLER_STAGED=0
REMOTE_HASHER_STAGED=0
cleanup_release_staging
RELEASE_STAGING=''
trap - EXIT

echo "==> Live: https://safeharbor.8westit.com"
