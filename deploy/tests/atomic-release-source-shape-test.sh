#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY="$ROOT/deploy/deploy.sh"
SERVER="$ROOT/deploy/release-server.sh"
PREPARE="$ROOT/deploy/prepare-atomic-layout.sh"
PREPARE_SERVER="$ROOT/deploy/prepare-atomic-layout-server.sh"
COUNT=0

pass() {
  COUNT=$((COUNT + 1))
  printf 'ok %d - %s\n' "$COUNT" "$1"
}

fail() {
  printf 'not ok %d - %s\n' "$((COUNT + 1))" "$1" >&2
  exit 1
}

for script in "$DEPLOY" "$SERVER" "$PREPARE" "$PREPARE_SERVER"; do
  bash -n "$script" || fail "shell syntax failed: $script"
  grep -Fq 'set -Eeuo pipefail' "$script" || fail "strict shell mode missing: $script"
done
pass 'all release controllers parse under Bash strict mode'

grep -Fq 'archive --format=tar --prefix=source/' "$DEPLOY" || fail 'normal deploy does not use exact Git archive'
grep -Fq 'status --porcelain=v1 --untracked-files=all --ignored' "$DEPLOY" || fail 'ignored-file refusal missing'
grep -Fq 'refs/remotes/origin/main' "$DEPLOY" || fail 'exact-main gate missing'
grep -Fq 'EXPECTED_CONTROLLER_SHA256' "$SERVER" || fail 'controller transport digest missing'
pass 'normal deploy is pinned to clean exact-main Git bytes and a verified controller'

grep -Fq 'flock -n 9' "$SERVER" || fail 'normal release lock missing'
grep -Fq 'mv -Tf -- "$PENDING_LINK" "$APP_ROOT/current"' "$SERVER" || fail 'atomic current switch missing'
grep -Fq 'rollback_release' "$SERVER" || fail 'automatic release rollback missing'
grep -Fq 'apache2ctl configtest' "$SERVER" || fail 'Apache config test missing'
grep -Fq 'systemctl reload apache2' "$SERVER" || fail 'Apache graceful reload missing'
grep -Fq '/health.php?revision=' "$SERVER" || fail 'exact public health check missing'
grep -Fq '<title>Sign in · Safeharbor</title>' "$SERVER" || fail 'public login identity check missing'
pass 'normal release is locked, atomic, publicly verified, and automatically reversible'

grep -Fq 'current.tar' "$PREPARE_SERVER" || fail 'legacy application backup missing'
grep -Fq 'tar --acls --xattrs --numeric-owner --compare' "$PREPARE_SERVER" || fail 'backup exactness check missing'
grep -Fq 'restore_layout' "$PREPARE_SERVER" || fail 'conversion restore path missing'
grep -Fq 'EXPECTED_HTTP_VHOST_SHA256' "$PREPARE_SERVER" || fail 'HTTP vhost approval digest missing'
grep -Fq 'EXPECTED_HTTPS_VHOST_SHA256' "$PREPARE_SERVER" || fail 'HTTPS vhost approval digest missing'
grep -Fq 'shared/config/config.php' "$PREPARE_SERVER" || fail 'server-owned shared config path missing'
pass 'one-time conversion has exact external backup, vhost gates, and automatic restore'

if grep -En 'tar .*(-C|--directory)[ =]?[^ ]*current|tar .*\|.*current|sed -i|git (checkout|reset|clean)' \
  "$DEPLOY" "$SERVER" >/dev/null; then
  fail 'normal deploy contains an in-place extraction, source mutation, or checkout mutation'
fi
grep -Fq '[[ ! -e "$SOURCE_DIR/app/config/config.php" ]]' "$SERVER" || fail 'runtime config exclusion missing'
grep -Fq 'stat -c '\''%d:%i:%u:%g:%a'\'' "$APP_ROOT/shared/attachments"' "$SERVER" || fail 'attachment identity guard missing'
grep -Fq 'sha256sum "$SHARED_CONFIG"' "$SERVER" || fail 'protected config byte guard missing'
pass 'normal deploy cannot overwrite config, attachments, or the live source tree'

grep -Fq 'REVISION' "$SERVER" || fail 'revision marker missing'
grep -Fq 'ARTIFACT_SHA256' "$SERVER" || fail 'artifact marker missing'
grep -Fq 'RELEASE_DIGEST' "$SERVER" || fail 'release digest marker missing'
grep -Fq 'safeharbor_asset_url' "$ROOT/app/lib/render.php" || fail 'render assets are not commit-keyed'
grep -Fq 'safeharbor_asset_url' "$ROOT/app/public/login.php" || fail 'login assets are not commit-keyed'
if grep -En 'sed -i.*\?v=|date \+%Y%m%d%H%M%S.*asset' "$DEPLOY" "$SERVER" >/dev/null; then
  fail 'timestamp-based source mutation remains in normal deploy'
fi
pass 'revision and digest markers drive deterministic cache busting without source rewrites'

grep -Fq '<Directory /srv/8west/apps/safeharbor/releases/*/public>' "$ROOT/deploy/apache-safeharbor.conf" || \
  fail 'HTTP vhost template lacks release wildcard'
grep -Fq '<Directory /srv/8west/apps/safeharbor/releases/*/public>' "$ROOT/deploy/apache-safeharbor-le-ssl.conf" || \
  fail 'HTTPS vhost template lacks release wildcard'
pass 'both reviewed Apache templates authorize resolved immutable release paths'

grep -Fq 'intentionally makes no changes' "$ROOT/deploy/setup-server.sh" || fail 'retired legacy setup is not fail-closed'
if grep -Eq 'a2ensite|systemctl|mkdir .*var/www/safeharbor' "$ROOT/deploy/setup-server.sh"; then
  fail 'retired legacy setup still contains a production mutation path'
fi
pass 'retired legacy server bootstrap is fail-closed'

printf '1..%d\n' "$COUNT"
