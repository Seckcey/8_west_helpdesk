#!/usr/bin/env bash
# Root-side Safeharbor release controller. It consumes one verified Git archive,
# prepares an immutable release, and atomically changes only the current link.
set -Eeuo pipefail
umask 077

APP_ROOT="${APP_ROOT:-/srv/8west/apps/safeharbor}"
BACKUP_ROOT="${BACKUP_ROOT:-/srv/8west/backups/safeharbor}"
LOCK_FILE="${LOCK_FILE:-/run/lock/8west-safeharbor-release.lock}"
HTTP_VHOST="${HTTP_VHOST:-/etc/apache2/sites-available/safeharbor-8westit.conf}"
HTTPS_VHOST="${HTTPS_VHOST:-/etc/apache2/sites-available/safeharbor-8westit-le-ssl.conf}"
PUBLIC_URL="${PUBLIC_URL:-https://safeharbor.8westit.com}"
EXPECTED_SHA="${EXPECTED_SHA:-}"
EXPECTED_ARTIFACT_SHA256="${EXPECTED_ARTIFACT_SHA256:-}"
EXPECTED_CONTROLLER_SHA256="${EXPECTED_CONTROLLER_SHA256:-}"
TEST_MODE="${SAFEHARBOR_RELEASE_TEST_MODE:-0}"
ARTIFACT="${1:-}"

STATE_DIR=''
CANDIDATE_DIR=''
TARGET_DIR=''
SWITCHED=0
COMMITTED=0
OLD_TARGET=''
OLD_RELATIVE=''
ATTACHMENTS_STAT=''
CONFIG_SHA256=''
BACKUP_RECORD=''

die() {
  printf 'Safeharbor atomic release refused: %s\n' "$1" >&2
  exit 1
}

if [[ "$TEST_MODE" == '1' ]]; then
  [[ "$APP_ROOT" =~ ^/tmp/safeharbor-release-test\.[A-Za-z0-9._/-]+$ ]] || \
    die 'test APP_ROOT is outside the allowed disposable prefix.'
  RELEASE_OWNER="$(id -u)"
  RELEASE_GROUP="$(id -g)"
else
  [[ "$EUID" -eq 0 ]] || die 'the server release controller must run as root.'
  [[ "$APP_ROOT" == '/srv/8west/apps/safeharbor' ]] || die 'APP_ROOT is not the production Safeharbor root.'
  [[ "$BACKUP_ROOT" == '/srv/8west/backups/safeharbor' ]] || die 'BACKUP_ROOT is not the external Safeharbor backup root.'
  [[ "$PUBLIC_URL" == 'https://safeharbor.8westit.com' ]] || die 'PUBLIC_URL is not the production Safeharbor host.'
  RELEASE_OWNER='root'
  RELEASE_GROUP='www-data'
fi

[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || die 'EXPECTED_SHA is not canonical.'
[[ "$EXPECTED_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'artifact digest is not canonical.'
[[ "$EXPECTED_CONTROLLER_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'controller digest is not canonical.'
[[ -n "$ARTIFACT" && "$ARTIFACT" == /* && -f "$ARTIFACT" && ! -L "$ARTIFACT" ]] || \
  die 'release artifact must be an absolute regular file.'
for command_name in apache2ctl awk chown chmod cmp cp curl date find flock grep ln mktemp mv php readlink rm sha256sum sort stat systemctl tar; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command is missing: $command_name"
done

controller_digest="$(sha256sum "${BASH_SOURCE[0]}" | awk '{print $1}')"
[[ "$controller_digest" == "$EXPECTED_CONTROLLER_SHA256" ]] || die 'server controller changed during transport.'
artifact_digest="$(sha256sum "$ARTIFACT" | awk '{print $1}')"
[[ "$artifact_digest" == "$EXPECTED_ARTIFACT_SHA256" ]] || die 'release artifact digest mismatch.'
tar -tf "$ARTIFACT" >/dev/null
if tar -tf "$ARTIFACT" | awk '
  /^\// { bad=1 }
  /(^|\/)\.\.?(\/|$)/ { bad=1 }
  END { exit bad ? 0 : 1 }
'; then
  die 'release artifact contains an unsafe path.'
fi
if tar -tf "$ARTIFACT" | grep -Eq '^source/(app/config/config\.php|\.git)(/|$)'; then
  die 'release artifact contains protected or Git runtime state.'
fi

if [[ ! -e "$LOCK_FILE" ]]; then
  : > "$LOCK_FILE"
fi
[[ -f "$LOCK_FILE" && ! -L "$LOCK_FILE" ]] || die 'release lock is unsafe.'
chmod 0600 "$LOCK_FILE"
exec 9>>"$LOCK_FILE"
flock -n 9 || die 'another Safeharbor release or layout conversion holds the lock.'

[[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || die 'application root is missing or unsafe.'
[[ -d "$APP_ROOT/releases" && ! -L "$APP_ROOT/releases" ]] || die 'atomic releases directory is missing or unsafe.'
[[ -d "$APP_ROOT/shared" && ! -L "$APP_ROOT/shared" ]] || die 'shared directory is missing or unsafe.'
[[ -d "$APP_ROOT/shared/attachments" && ! -L "$APP_ROOT/shared/attachments" ]] || \
  die 'external attachment directory is missing or unsafe.'
[[ -d "$APP_ROOT/shared/config" && ! -L "$APP_ROOT/shared/config" ]] || die 'shared config directory is missing or unsafe.'
SHARED_CONFIG="$APP_ROOT/shared/config/config.php"
[[ -f "$SHARED_CONFIG" && ! -L "$SHARED_CONFIG" ]] || die 'shared config is missing or unsafe.'
if [[ "$TEST_MODE" != '1' ]]; then
  [[ "$(stat -c '%U:%G:%a' "$SHARED_CONFIG")" == 'root:www-data:640' ]] || \
    die 'shared config must be root:www-data mode 640.'
fi

[[ -L "$APP_ROOT/current" ]] || die 'current must be the atomic release symlink; run the one-time conversion first.'
OLD_TARGET="$(readlink -f "$APP_ROOT/current")"
case "$OLD_TARGET/" in
  "$APP_ROOT/releases/"*) ;;
  *) die 'current resolves outside the immutable releases directory.' ;;
esac
[[ -d "$OLD_TARGET" && ! -L "$OLD_TARGET" ]] || die 'current release target is missing or unsafe.'
OLD_RELATIVE="releases/${OLD_TARGET#"$APP_ROOT/releases/"}"
[[ "$OLD_RELATIVE" != *$'\n'* ]] || die 'current release target contains an unsafe newline.'
for vhost in "$HTTP_VHOST" "$HTTPS_VHOST"; do
  [[ -f "$vhost" && ! -L "$vhost" ]] || die "Apache vhost is missing or unsafe: $vhost"
  grep -Fq "<Directory $APP_ROOT/releases/*/public>" "$vhost" || die "Apache vhost is not atomic-layout aware: $vhost"
  grep -Fq "<Directory $APP_ROOT/releases/*/public/assets>" "$vhost" || die "Apache asset rule is not atomic-layout aware: $vhost"
done

ATTACHMENTS_STAT="$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT/shared/attachments")"
CONFIG_SHA256="$(sha256sum "$SHARED_CONFIG" | awk '{print $1}')"

verify_preserved_state() {
  [[ "$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT/shared/attachments")" == "$ATTACHMENTS_STAT" ]] || return 1
  [[ "$(sha256sum "$SHARED_CONFIG" | awk '{print $1}')" == "$CONFIG_SHA256" ]] || return 1
  return 0
}

public_status() {
  local path="$1"
  local expected="$2"
  local actual
  actual="$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' "${PUBLIC_URL}${path}")"
  [[ "$actual" == "$expected" ]]
}

public_login() {
  local body="$STATE_DIR/login.html"
  local actual
  actual="$(curl --silent --show-error --max-time 20 --output "$body" --write-out '%{http_code}' "${PUBLIC_URL}/login.php")"
  [[ "$actual" == '200' ]] || return 1
  grep -Fq '<title>Sign in · Safeharbor</title>' "$body"
}

rollback_release() {
  local rollback_link="$APP_ROOT/.current.rollback.$$"
  local failed=0

  printf '%s\n' 'Release verification failed; restoring the exact previous release.' >&2
  rm -f -- "$rollback_link"
  if ! ln -s "$OLD_RELATIVE" "$rollback_link" || ! mv -Tf -- "$rollback_link" "$APP_ROOT/current"; then
    printf '%s\n' 'CRITICAL: atomic previous-release pointer restoration failed.' >&2
    failed=1
  fi
  if [[ "$(readlink -f "$APP_ROOT/current" 2>/dev/null || true)" != "$OLD_TARGET" ]]; then
    printf '%s\n' 'CRITICAL: current does not resolve to the exact previous release.' >&2
    failed=1
  fi
  if ! apache2ctl configtest >/dev/null || ! systemctl reload apache2; then
    printf '%s\n' 'CRITICAL: Apache validation or reload failed during rollback.' >&2
    failed=1
  fi
  if ! public_login || ! public_status '/' '302'; then
    printf '%s\n' 'CRITICAL: previous release failed public rollback checks.' >&2
    failed=1
  fi
  if ! verify_preserved_state; then
    printf '%s\n' 'CRITICAL: protected config or attachment-directory identity changed.' >&2
    failed=1
  fi
  return "$failed"
}

cleanup() {
  local status=$?
  local rollback_failed=0
  trap - EXIT INT TERM HUP
  set +e

  if [[ "$status" -ne 0 && "$SWITCHED" -eq 1 && "$COMMITTED" -eq 0 ]]; then
    rollback_release || rollback_failed=1
  fi
  if [[ -n "$STATE_DIR" && -d "$STATE_DIR" ]]; then
    case "$STATE_DIR" in
      "$APP_ROOT"/.staging.*) rm -rf -- "$STATE_DIR" ;;
      *) rollback_failed=1 ;;
    esac
  fi
  if [[ "$rollback_failed" -ne 0 ]]; then
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

STATE_DIR="$(mktemp -d "$APP_ROOT/.staging.${EXPECTED_SHA}.XXXXXX")"
chmod 0700 "$STATE_DIR"
tar -xf "$ARTIFACT" -C "$STATE_DIR"
SOURCE_DIR="$STATE_DIR/source"
[[ -d "$SOURCE_DIR/app" && -f "$SOURCE_DIR/app/public/login.php" && -f "$SOURCE_DIR/app/public/health.php" ]] || \
  die 'release artifact is missing required application files.'
[[ ! -e "$SOURCE_DIR/app/config/config.php" ]] || die 'release artifact attempted to overwrite protected config.'
if find -P "$SOURCE_DIR" \( -type l -o ! -type d ! -type f \) -print -quit | grep -q .; then
  die 'release artifact contains a non-regular filesystem entry.'
fi

CANDIDATE_DIR="$STATE_DIR/release"
mkdir "$CANDIDATE_DIR"
cp -a -- "$SOURCE_DIR/app/." "$CANDIDATE_DIR/"
mkdir -p "$CANDIDATE_DIR/public/assets/brand"
for asset in \
  brand/svg/favicon.svg \
  brand/svg/safeharbor-mark.svg \
  brand/png/favicon.ico \
  brand/png/apple-touch-icon.png \
  brand/png/app-tile-192.png \
  brand/png/app-tile-512.png; do
  [[ -f "$SOURCE_DIR/$asset" && ! -L "$SOURCE_DIR/$asset" ]] || die "required tracked brand asset is missing: $asset"
  cp -- "$SOURCE_DIR/$asset" "$CANDIDATE_DIR/public/assets/brand/"
done

[[ ! -e "$CANDIDATE_DIR/config/config.php" ]] || die 'candidate unexpectedly contains runtime config.'
ln -s '../../../shared/config/config.php' "$CANDIDATE_DIR/config/config.php"
[[ "$(readlink -f "$CANDIDATE_DIR/config/config.php")" == "$SHARED_CONFIG" ]] || die 'candidate config link does not resolve to shared config.'
printf '%s\n' "$EXPECTED_SHA" > "$CANDIDATE_DIR/REVISION"
printf '%s\n' "$EXPECTED_ARTIFACT_SHA256" > "$CANDIDATE_DIR/ARTIFACT_SHA256"
(
  cd "$CANDIDATE_DIR"
  find -P . -type f ! -name '.release-files.sha256' ! -name 'RELEASE_DIGEST' -print0 | \
    LC_ALL=C sort -z | xargs -0 sha256sum
) > "$CANDIDATE_DIR/.release-files.sha256"
RELEASE_DIGEST="$(sha256sum "$CANDIDATE_DIR/.release-files.sha256" | awk '{print $1}')"
[[ "$RELEASE_DIGEST" =~ ^[0-9a-f]{64}$ ]] || die 'release-tree digest was not canonical.'
printf '%s\n' "$RELEASE_DIGEST" > "$CANDIDATE_DIR/RELEASE_DIGEST"
(
  cd "$CANDIDATE_DIR"
  sha256sum -c .release-files.sha256 >/dev/null
)

find -P "$CANDIDATE_DIR" -type d -exec chmod 2750 {} +
find -P "$CANDIDATE_DIR" -type f -exec chmod 0640 {} +
chmod 0444 "$CANDIDATE_DIR/REVISION" "$CANDIDATE_DIR/ARTIFACT_SHA256" "$CANDIDATE_DIR/RELEASE_DIGEST"
chmod 0440 "$CANDIDATE_DIR/.release-files.sha256"
chown -R "$RELEASE_OWNER:$RELEASE_GROUP" "$CANDIDATE_DIR"
chown -h "$RELEASE_OWNER:$RELEASE_GROUP" "$CANDIDATE_DIR/config/config.php"
find -P "$CANDIDATE_DIR" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
verify_preserved_state || die 'protected config or attachment-directory identity changed during staging.'

TARGET_DIR="$APP_ROOT/releases/$EXPECTED_SHA"
if [[ -e "$TARGET_DIR" || -L "$TARGET_DIR" ]]; then
  [[ -d "$TARGET_DIR" && ! -L "$TARGET_DIR" ]] || die 'existing release target is unsafe.'
  [[ "$(tr -d '\r\n' < "$TARGET_DIR/REVISION")" == "$EXPECTED_SHA" ]] || die 'existing release revision marker differs.'
  [[ "$(tr -d '\r\n' < "$TARGET_DIR/ARTIFACT_SHA256")" == "$EXPECTED_ARTIFACT_SHA256" ]] || die 'existing release artifact marker differs.'
  [[ "$(tr -d '\r\n' < "$TARGET_DIR/RELEASE_DIGEST")" == "$RELEASE_DIGEST" ]] || die 'existing release tree digest differs.'
else
  mv -T -- "$CANDIDATE_DIR" "$TARGET_DIR"
  CANDIDATE_DIR=''
fi

[[ "$(readlink -f "$TARGET_DIR/config/config.php")" == "$SHARED_CONFIG" ]] || die 'target release config link is unsafe.'
(
  cd "$TARGET_DIR"
  sha256sum -c .release-files.sha256 >/dev/null
)
[[ "$(sha256sum "$TARGET_DIR/.release-files.sha256" | awk '{print $1}')" == "$RELEASE_DIGEST" ]] || \
  die 'target release manifest digest differs.'

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_RECORD="$BACKUP_ROOT/${timestamp}-pre-release-${EXPECTED_SHA:0:12}"
[[ ! -e "$BACKUP_ROOT" || ( -d "$BACKUP_ROOT" && ! -L "$BACKUP_ROOT" ) ]] || die 'backup root is unsafe.'
[[ ! -e "$BACKUP_RECORD" ]] || die 'release record already exists.'
mkdir -p "$BACKUP_ROOT"
chmod 0700 "$BACKUP_ROOT"
mkdir "$BACKUP_RECORD"
chmod 0700 "$BACKUP_RECORD"
cp -- "$ARTIFACT" "$BACKUP_RECORD/release.tar"
chmod 0600 "$BACKUP_RECORD/release.tar"
printf '%s  release.tar\n' "$EXPECTED_ARTIFACT_SHA256" > "$BACKUP_RECORD/release.tar.sha256"
printf 'previous_target=%s\nrevision=%s\nartifact_sha256=%s\nrelease_digest=%s\n' \
  "$OLD_RELATIVE" "$EXPECTED_SHA" "$EXPECTED_ARTIFACT_SHA256" "$RELEASE_DIGEST" > "$BACKUP_RECORD/release-receipt.txt"
chmod 0600 "$BACKUP_RECORD/release.tar.sha256" "$BACKUP_RECORD/release-receipt.txt"
(
  cd "$BACKUP_RECORD"
  sha256sum -c release.tar.sha256 >/dev/null
)

PENDING_LINK="$APP_ROOT/.current.${EXPECTED_SHA}.$$"
rm -f -- "$PENDING_LINK"
ln -s "releases/$EXPECTED_SHA" "$PENDING_LINK"
mv -Tf -- "$PENDING_LINK" "$APP_ROOT/current"
SWITCHED=1
[[ "$(readlink -f "$APP_ROOT/current")" == "$TARGET_DIR" ]] || die 'atomic current switch did not select the target release.'

apache2ctl configtest >/dev/null
systemctl reload apache2

HEALTH_FILE="$STATE_DIR/health.json"
health_code="$(curl --silent --show-error --max-time 20 --output "$HEALTH_FILE" --write-out '%{http_code}' \
  "${PUBLIC_URL}/health.php?revision=${EXPECTED_SHA}&nonce=$(date -u +%s)")"
[[ "$health_code" == '200' ]] || die "public health returned HTTP $health_code."
EXPECTED_HEALTH_REVISION="$EXPECTED_SHA" \
EXPECTED_HEALTH_ARTIFACT="$EXPECTED_ARTIFACT_SHA256" \
EXPECTED_HEALTH_DIGEST="$RELEASE_DIGEST" \
php -r '
  $payload = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
  $expected = [
    "status" => "ok",
    "revision" => getenv("EXPECTED_HEALTH_REVISION"),
    "artifact_sha256" => getenv("EXPECTED_HEALTH_ARTIFACT"),
    "release_digest" => getenv("EXPECTED_HEALTH_DIGEST"),
  ];
  if ($payload !== $expected) { exit(1); }
' < "$HEALTH_FILE" || die 'public health did not report the exact release markers.'
public_login || die 'public login check failed.'
public_status '/' '302' || die 'public signed-out root check failed.'
verify_preserved_state || die 'protected config or attachment-directory identity changed after release.'

printf 'completed_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$BACKUP_RECORD/release-receipt.txt"
printf 'RELEASE_OK revision=%s artifact_sha256=%s release_digest=%s previous=%s\n' \
  "$EXPECTED_SHA" "$EXPECTED_ARTIFACT_SHA256" "$RELEASE_DIGEST" "$OLD_RELATIVE"
COMMITTED=1
