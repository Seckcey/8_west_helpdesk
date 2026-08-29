#!/usr/bin/env bash
# Release one exact, reviewed Safeharbor commit through the server-side atomic
# release controller. No working-tree or ignored byte is included.
set -Eeuo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER="${SERVER:-milepost-ec2}"
KEY="${KEY:-}"
EXPECTED_SHA="${EXPECTED_SHA:-}"
APP_ROOT="/srv/8west/apps/safeharbor"
EXPECTED_ORIGIN="https://github.com/Seckcey/8_west_helpdesk.git"
CONTROLLER="$ROOT/deploy/release-server.sh"

die() {
  printf 'Safeharbor release refused: %s\n' "$1" >&2
  exit 1
}

[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || \
  die 'EXPECTED_SHA must be the approved lowercase 40-character commit.'
for command_name in cmp git mktemp scp sha256sum ssh tar; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command is missing: $command_name"
done
[[ -f "$CONTROLLER" && ! -L "$CONTROLLER" ]] || die 'server release controller is missing or unsafe.'
[[ "$(git -C "$ROOT" rev-parse --is-inside-work-tree 2>/dev/null || true)" == 'true' ]] || \
  die 'run from an isolated Safeharbor Git checkout.'

actual_origin="$(git -C "$ROOT" remote get-url origin)"
if [[ "$actual_origin" != "$EXPECTED_ORIGIN" && "$actual_origin" != 'git@github.com:Seckcey/8_west_helpdesk.git' ]]; then
  die "unexpected Git origin: $actual_origin"
fi
[[ "$(git -C "$ROOT" rev-parse --verify HEAD)" == "$EXPECTED_SHA" ]] || \
  die 'the checkout is not at EXPECTED_SHA.'
[[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ]] || \
  die 'the checkout has tracked or untracked changes.'
[[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all --ignored)" ]] || \
  die 'the checkout contains ignored files; use a fresh isolated worktree.'
git -C "$ROOT" show "${EXPECTED_SHA}:deploy/release-server.sh" | cmp -s - "$CONTROLLER" || \
  die 'the server controller does not match EXPECTED_SHA.'
if git -C "$ROOT" ls-tree -r "$EXPECTED_SHA" | awk '$1 != "100644" && $1 != "100755" { exit 1 }'; then
  :
else
  die 'the reviewed tree contains a non-regular Git entry.'
fi

printf '%s\n' '==> Confirming the approved commit is still exact main'
git -C "$ROOT" fetch --prune origin main
[[ "$(git -C "$ROOT" rev-parse --verify refs/remotes/origin/main)" == "$EXPECTED_SHA" ]] || \
  die 'origin/main is not EXPECTED_SHA; review the new main before releasing.'

STATE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/safeharbor-artifact.${EXPECTED_SHA}.XXXXXX")"
REMOTE_DIR=''
SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes)
[[ -n "$KEY" ]] && SSH_OPTS+=(-i "$KEY")
cleanup() {
  local status=$?
  trap - EXIT
  set +e
  if [[ -n "$REMOTE_DIR" && "$REMOTE_DIR" =~ ^/tmp/safeharbor-release\.[A-Za-z0-9]+$ ]]; then
    ssh "${SSH_OPTS[@]}" "$SERVER" "rm -rf -- '$REMOTE_DIR'" >/dev/null 2>&1
  fi
  case "$STATE_DIR" in
    "${TMPDIR:-/tmp}"/safeharbor-artifact.*) rm -rf -- "$STATE_DIR" ;;
  esac
  exit "$status"
}
trap cleanup EXIT

ARTIFACT="$STATE_DIR/release.tar"
VERIFY_ARTIFACT="$STATE_DIR/release.verify.tar"
git -C "$ROOT" archive --format=tar --prefix=source/ "$EXPECTED_SHA" -o "$ARTIFACT"
git -C "$ROOT" archive --format=tar --prefix=source/ "$EXPECTED_SHA" -o "$VERIFY_ARTIFACT"
cmp -s "$ARTIFACT" "$VERIFY_ARTIFACT" || die 'Git produced a non-deterministic release artifact.'
rm -f -- "$VERIFY_ARTIFACT"
ARTIFACT_SHA256="$(sha256sum "$ARTIFACT" | awk '{print $1}')"
CONTROLLER_SHA256="$(sha256sum "$CONTROLLER" | awk '{print $1}')"
[[ "$ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'artifact digest was not canonical.'
[[ "$CONTROLLER_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'controller digest was not canonical.'
tar -tf "$ARTIFACT" >/dev/null
if tar -tf "$ARTIFACT" | grep -Eq '(^|/)config/config\.php$|(^|/)\.git(/|$)'; then
  die 'the exact Git artifact unexpectedly contains protected runtime state.'
fi

REMOTE_DIR="$(ssh "${SSH_OPTS[@]}" "$SERVER" 'mktemp -d /tmp/safeharbor-release.XXXXXX')"
[[ "$REMOTE_DIR" =~ ^/tmp/safeharbor-release\.[A-Za-z0-9]+$ ]] || \
  die 'the server returned an unsafe staging path.'

printf '%s\n' '==> Uploading the exact reviewed artifact'
scp "${SSH_OPTS[@]}" "$ARTIFACT" "$CONTROLLER" "$SERVER:$REMOTE_DIR/"

printf '%s\n' '==> Running the locked atomic release'
ssh "${SSH_OPTS[@]}" "$SERVER" \
  "sudo env APP_ROOT='$APP_ROOT' EXPECTED_SHA='$EXPECTED_SHA' EXPECTED_ARTIFACT_SHA256='$ARTIFACT_SHA256' EXPECTED_CONTROLLER_SHA256='$CONTROLLER_SHA256' bash '$REMOTE_DIR/release-server.sh' '$REMOTE_DIR/release.tar'"

printf 'Safeharbor release completed: revision=%s artifact_sha256=%s\n' \
  "$EXPECTED_SHA" "$ARTIFACT_SHA256"
