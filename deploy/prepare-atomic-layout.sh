#!/usr/bin/env bash
# One-time launcher for converting the legacy real `current` directory into
# immutable releases plus an atomic `current` symlink.
set -Eeuo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER="${SERVER:-milepost-ec2}"
KEY="${KEY:-}"
EXPECTED_SHA="${EXPECTED_SHA:-}"
CURRENT_REVISION="${CURRENT_REVISION:-}"
EXPECTED_HTTP_VHOST_SHA256="${EXPECTED_HTTP_VHOST_SHA256:-}"
EXPECTED_HTTPS_VHOST_SHA256="${EXPECTED_HTTPS_VHOST_SHA256:-}"
EXPECTED_ORIGIN="https://github.com/Seckcey/8_west_helpdesk.git"
CONTROLLER="$ROOT/deploy/prepare-atomic-layout-server.sh"

die() {
  printf 'Safeharbor layout conversion refused: %s\n' "$1" >&2
  exit 1
}

[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || die 'EXPECTED_SHA must be the reviewed controller commit.'
[[ "$CURRENT_REVISION" =~ ^[0-9a-f]{40}$ ]] || die 'CURRENT_REVISION must be the verified live application commit.'
[[ "$EXPECTED_HTTP_VHOST_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'the HTTP vhost digest is required.'
[[ "$EXPECTED_HTTPS_VHOST_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'the HTTPS vhost digest is required.'
for command_name in cmp git mktemp scp sha256sum ssh; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command is missing: $command_name"
done
[[ -f "$CONTROLLER" && ! -L "$CONTROLLER" ]] || die 'layout controller is missing or unsafe.'
[[ "$(git -C "$ROOT" rev-parse --is-inside-work-tree 2>/dev/null || true)" == 'true' ]] || \
  die 'run from an isolated Safeharbor Git checkout.'
actual_origin="$(git -C "$ROOT" remote get-url origin)"
if [[ "$actual_origin" != "$EXPECTED_ORIGIN" && "$actual_origin" != 'git@github.com:Seckcey/8_west_helpdesk.git' ]]; then
  die "unexpected Git origin: $actual_origin"
fi
[[ "$(git -C "$ROOT" rev-parse --verify HEAD)" == "$EXPECTED_SHA" ]] || die 'the checkout is not at EXPECTED_SHA.'
[[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ]] || die 'the checkout is dirty.'
[[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all --ignored)" ]] || \
  die 'the checkout contains ignored files; use a fresh isolated worktree.'
git -C "$ROOT" show "${EXPECTED_SHA}:deploy/prepare-atomic-layout-server.sh" | cmp -s - "$CONTROLLER" || \
  die 'the layout controller does not match EXPECTED_SHA.'

git -C "$ROOT" fetch --prune origin main
[[ "$(git -C "$ROOT" rev-parse --verify refs/remotes/origin/main)" == "$EXPECTED_SHA" ]] || \
  die 'origin/main is not EXPECTED_SHA; review the new main before conversion.'

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes)
[[ -n "$KEY" ]] && SSH_OPTS+=(-i "$KEY")
REMOTE_DIR="$(ssh "${SSH_OPTS[@]}" "$SERVER" 'mktemp -d /tmp/safeharbor-layout.XXXXXX')"
[[ "$REMOTE_DIR" =~ ^/tmp/safeharbor-layout\.[A-Za-z0-9]+$ ]] || die 'the server returned an unsafe staging path.'
cleanup() {
  local status=$?
  trap - EXIT
  set +e
  if [[ "$REMOTE_DIR" =~ ^/tmp/safeharbor-layout\.[A-Za-z0-9]+$ ]]; then
    ssh "${SSH_OPTS[@]}" "$SERVER" "rm -rf -- '$REMOTE_DIR'" >/dev/null 2>&1
  fi
  exit "$status"
}
trap cleanup EXIT

CONTROLLER_SHA256="$(sha256sum "$CONTROLLER" | awk '{print $1}')"
scp "${SSH_OPTS[@]}" "$CONTROLLER" "$SERVER:$REMOTE_DIR/"
ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo env EXPECTED_SHA='$EXPECTED_SHA' CURRENT_REVISION='$CURRENT_REVISION' EXPECTED_CONTROLLER_SHA256='$CONTROLLER_SHA256' EXPECTED_HTTP_VHOST_SHA256='$EXPECTED_HTTP_VHOST_SHA256' EXPECTED_HTTPS_VHOST_SHA256='$EXPECTED_HTTPS_VHOST_SHA256' bash '$REMOTE_DIR/prepare-atomic-layout-server.sh'"

printf 'Safeharbor atomic layout prepared from live revision %s.\n' "$CURRENT_REVISION"
