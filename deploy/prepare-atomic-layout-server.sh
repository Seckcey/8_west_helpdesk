#!/usr/bin/env bash
# Root-side, one-time Safeharbor layout conversion. Every changed production
# byte is covered by an external root-only backup and restored on any failure.
set -Eeuo pipefail
umask 077

APP_ROOT="${APP_ROOT:-/srv/8west/apps/safeharbor}"
BACKUP_ROOT="${BACKUP_ROOT:-/srv/8west/backups/safeharbor}"
LOCK_FILE="${LOCK_FILE:-/run/lock/8west-safeharbor-release.lock}"
HTTP_VHOST="${HTTP_VHOST:-/etc/apache2/sites-available/safeharbor-8westit.conf}"
HTTPS_VHOST="${HTTPS_VHOST:-/etc/apache2/sites-available/safeharbor-8westit-le-ssl.conf}"
PUBLIC_URL="${PUBLIC_URL:-https://safeharbor.8westit.com}"
EXPECTED_SHA="${EXPECTED_SHA:-}"
CURRENT_REVISION="${CURRENT_REVISION:-}"
EXPECTED_CONTROLLER_SHA256="${EXPECTED_CONTROLLER_SHA256:-}"
EXPECTED_HTTP_VHOST_SHA256="${EXPECTED_HTTP_VHOST_SHA256:-}"
EXPECTED_HTTPS_VHOST_SHA256="${EXPECTED_HTTPS_VHOST_SHA256:-}"
TEST_MODE="${SAFEHARBOR_RELEASE_TEST_MODE:-0}"

BACKUP_RECORD=''
RELEASE_PATH=''
RELEASES_CREATED=0
SHARED_CONFIG_DIR_CREATED=0
SHARED_CONFIG_CREATED=0
CURRENT_MOVED=0
MUTATION_STARTED=0
VHOST_MUTATED=0
COMMITTED=0
ATTACHMENTS_STAT=''
CONFIG_SHA256=''

die() {
  printf 'Safeharbor atomic layout refused: %s\n' "$1" >&2
  exit 1
}

if [[ "$TEST_MODE" == '1' ]]; then
  [[ "$APP_ROOT" =~ ^/tmp/safeharbor-release-test\.[A-Za-z0-9._/-]+$ ]] || die 'unsafe disposable APP_ROOT.'
  RELEASE_OWNER="$(id -u)"
  RELEASE_GROUP="$(id -g)"
else
  [[ "$EUID" -eq 0 ]] || die 'layout conversion must run as root.'
  [[ "$APP_ROOT" == '/srv/8west/apps/safeharbor' ]] || die 'APP_ROOT is not the production Safeharbor root.'
  [[ "$BACKUP_ROOT" == '/srv/8west/backups/safeharbor' ]] || die 'BACKUP_ROOT is not the external Safeharbor backup root.'
  [[ "$PUBLIC_URL" == 'https://safeharbor.8westit.com' ]] || die 'PUBLIC_URL is not the production Safeharbor host.'
  RELEASE_OWNER='root'
  RELEASE_GROUP='www-data'
fi

[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || die 'EXPECTED_SHA is not canonical.'
[[ "$CURRENT_REVISION" =~ ^[0-9a-f]{40}$ ]] || die 'CURRENT_REVISION is not canonical.'
[[ "$EXPECTED_CONTROLLER_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'controller digest is not canonical.'
[[ "$EXPECTED_HTTP_VHOST_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'HTTP vhost digest is not canonical.'
[[ "$EXPECTED_HTTPS_VHOST_SHA256" =~ ^[0-9a-f]{64}$ ]] || die 'HTTPS vhost digest is not canonical.'
for command_name in apache2ctl awk chown chmod cp curl date find flock grep ln mkdir mktemp mv readlink rm rmdir sed sha256sum stat systemctl tar; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command is missing: $command_name"
done
[[ "$(sha256sum "${BASH_SOURCE[0]}" | awk '{print $1}')" == "$EXPECTED_CONTROLLER_SHA256" ]] || \
  die 'layout controller changed during transport.'

if [[ ! -e "$LOCK_FILE" ]]; then
  : > "$LOCK_FILE"
fi
[[ -f "$LOCK_FILE" && ! -L "$LOCK_FILE" ]] || die 'release lock is unsafe.'
chmod 0600 "$LOCK_FILE"
exec 9>>"$LOCK_FILE"
flock -n 9 || die 'another Safeharbor release or layout conversion holds the lock.'

[[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || die 'application root is missing or unsafe.'
[[ -d "$APP_ROOT/current" && ! -L "$APP_ROOT/current" ]] || \
  die 'current is not the one-time legacy real directory.'
[[ ! -e "$APP_ROOT/releases" && ! -L "$APP_ROOT/releases" ]] || \
  die 'releases already exists; refuse an ambiguous or repeated conversion.'
[[ -d "$APP_ROOT/shared" && ! -L "$APP_ROOT/shared" ]] || die 'shared directory is missing or unsafe.'
[[ -d "$APP_ROOT/shared/attachments" && ! -L "$APP_ROOT/shared/attachments" ]] || \
  die 'external attachment directory is missing or unsafe.'
[[ ! -e "$APP_ROOT/shared/config" && ! -L "$APP_ROOT/shared/config" ]] || \
  die 'shared config already exists; refuse an ambiguous or repeated conversion.'
LEGACY_CONFIG="$APP_ROOT/current/config/config.php"
[[ -f "$LEGACY_CONFIG" && ! -L "$LEGACY_CONFIG" ]] || die 'legacy protected config is missing or unsafe.'
if find -P "$APP_ROOT/current" \( -type l -o ! -type d ! -type f \) -print -quit | grep -q .; then
  die 'legacy current contains an unsupported filesystem entry.'
fi
for vhost in "$HTTP_VHOST" "$HTTPS_VHOST"; do
  [[ -f "$vhost" && ! -L "$vhost" ]] || die "Apache vhost is missing or unsafe: $vhost"
  [[ "$(grep -Fc "<Directory $APP_ROOT/current/public>" "$vhost")" == '1' ]] || \
    die "Apache vhost does not have one legacy public Directory: $vhost"
  [[ "$(grep -Fc "<Directory $APP_ROOT/current/public/assets>" "$vhost")" == '1' ]] || \
    die "Apache vhost does not have one legacy asset Directory: $vhost"
  [[ "$(grep -Fc "DocumentRoot $APP_ROOT/current/public" "$vhost")" == '1' ]] || \
    die "Apache vhost has an unexpected DocumentRoot: $vhost"
done
[[ "$(sha256sum "$HTTP_VHOST" | awk '{print $1}')" == "$EXPECTED_HTTP_VHOST_SHA256" ]] || die 'HTTP vhost changed after approval.'
[[ "$(sha256sum "$HTTPS_VHOST" | awk '{print $1}')" == "$EXPECTED_HTTPS_VHOST_SHA256" ]] || die 'HTTPS vhost changed after approval.'

ATTACHMENTS_STAT="$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT/shared/attachments")"
CONFIG_SHA256="$(sha256sum "$LEGACY_CONFIG" | awk '{print $1}')"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
[[ ! -e "$BACKUP_ROOT" || ( -d "$BACKUP_ROOT" && ! -L "$BACKUP_ROOT" ) ]] || die 'backup root is unsafe.'
mkdir -p "$BACKUP_ROOT"
chmod 0700 "$BACKUP_ROOT"
BACKUP_RECORD="$BACKUP_ROOT/${timestamp}-pre-atomic-layout-${CURRENT_REVISION:0:12}"
[[ ! -e "$BACKUP_RECORD" ]] || die 'layout backup record already exists.'
mkdir "$BACKUP_RECORD"
chmod 0700 "$BACKUP_RECORD"
mkdir "$BACKUP_RECORD/vhosts"

tar --acls --xattrs --numeric-owner -cpf "$BACKUP_RECORD/current.tar" -C "$APP_ROOT" current
chmod 0600 "$BACKUP_RECORD/current.tar"
CURRENT_ARCHIVE_SHA256="$(sha256sum "$BACKUP_RECORD/current.tar" | awk '{print $1}')"
printf '%s  current.tar\n' "$CURRENT_ARCHIVE_SHA256" > "$BACKUP_RECORD/current.tar.sha256"
cp --preserve=all -- "$HTTP_VHOST" "$BACKUP_RECORD/vhosts/http.conf"
cp --preserve=all -- "$HTTPS_VHOST" "$BACKUP_RECORD/vhosts/https.conf"
printf '%s  vhosts/http.conf\n%s  vhosts/https.conf\n' \
  "$EXPECTED_HTTP_VHOST_SHA256" "$EXPECTED_HTTPS_VHOST_SHA256" > "$BACKUP_RECORD/vhosts.sha256"
printf 'controller_revision=%s\nlegacy_revision=%s\nattachments_identity=%s\n' \
  "$EXPECTED_SHA" "$CURRENT_REVISION" "$ATTACHMENTS_STAT" > "$BACKUP_RECORD/layout-receipt.txt"
chmod 0600 "$BACKUP_RECORD"/*.sha256 "$BACKUP_RECORD/layout-receipt.txt" "$BACKUP_RECORD/vhosts/"*.conf
(
  cd "$BACKUP_RECORD"
  sha256sum -c current.tar.sha256 >/dev/null
  sha256sum -c vhosts.sha256 >/dev/null
)
tar -tf "$BACKUP_RECORD/current.tar" >/dev/null
tar --acls --xattrs --numeric-owner --compare -f "$BACKUP_RECORD/current.tar" -C "$APP_ROOT" >/dev/null

transform_vhost() {
  local source="$1"
  local target="$2"
  local mode owner
  mode="$(stat -c '%a' "$source")"
  owner="$(stat -c '%u:%g' "$source")"
  sed \
    -e "s#<Directory $APP_ROOT/current/public/assets>#<Directory $APP_ROOT/releases/*/public/assets>#" \
    -e "s#<Directory $APP_ROOT/current/public>#<Directory $APP_ROOT/releases/*/public>#" \
    "$source" > "$target"
  [[ "$(grep -Fc "DocumentRoot $APP_ROOT/current/public" "$target")" == '1' ]] || return 1
  [[ "$(grep -Fc "<Directory $APP_ROOT/releases/*/public>" "$target")" == '1' ]] || return 1
  [[ "$(grep -Fc "<Directory $APP_ROOT/releases/*/public/assets>" "$target")" == '1' ]] || return 1
  ! grep -Fq "<Directory $APP_ROOT/current/public" "$target" || return 1
  chown "$owner" "$target"
  chmod "$mode" "$target"
}

STAGED_HTTP="$BACKUP_RECORD/http.atomic.conf"
STAGED_HTTPS="$BACKUP_RECORD/https.atomic.conf"
transform_vhost "$HTTP_VHOST" "$STAGED_HTTP" || die 'could not prepare the atomic HTTP vhost.'
transform_vhost "$HTTPS_VHOST" "$STAGED_HTTPS" || die 'could not prepare the atomic HTTPS vhost.'

public_status() {
  local path="$1"
  local expected="$2"
  local actual
  actual="$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' "${PUBLIC_URL}${path}")"
  [[ "$actual" == "$expected" ]]
}

public_login() {
  local body="$BACKUP_RECORD/login-check.html"
  local actual
  actual="$(curl --silent --show-error --max-time 20 --output "$body" --write-out '%{http_code}' "${PUBLIC_URL}/login.php")"
  [[ "$actual" == '200' ]] || return 1
  grep -Fq '<title>Sign in · Safeharbor</title>' "$body"
  rm -f -- "$body"
}

atomic_copy() {
  local source="$1"
  local destination="$2"
  local pending
  pending="$(mktemp "$(dirname "$destination")/.safeharbor-vhost.XXXXXX")"
  cp --preserve=all -- "$source" "$pending"
  [[ "$(sha256sum "$source" | awk '{print $1}')" == "$(sha256sum "$pending" | awk '{print $1}')" ]] || return 1
  mv -Tf -- "$pending" "$destination"
}

restore_layout() {
  local failed=0
  local restore_parent=''
  local failed_release=''

  printf '%s\n' 'Layout conversion failed; restoring the exact protected backup.' >&2
  if [[ "$VHOST_MUTATED" -eq 1 ]]; then
    atomic_copy "$BACKUP_RECORD/vhosts/http.conf" "$HTTP_VHOST" || failed=1
    atomic_copy "$BACKUP_RECORD/vhosts/https.conf" "$HTTPS_VHOST" || failed=1
  fi

  if [[ "$CURRENT_MOVED" -eq 1 ]]; then
    if [[ -L "$APP_ROOT/current" ]]; then
      rm -f -- "$APP_ROOT/current" || failed=1
    elif [[ -e "$APP_ROOT/current" ]]; then
      printf '%s\n' 'CRITICAL: an unexpected real current path blocks restoration.' >&2
      failed=1
    fi
    if [[ -n "$RELEASE_PATH" && -d "$RELEASE_PATH" && ! -L "$RELEASE_PATH" ]]; then
      failed_release="$APP_ROOT/.failed-layout.${timestamp}.$$"
      mv -T -- "$RELEASE_PATH" "$failed_release" || failed=1
    fi
    restore_parent="$(mktemp -d "$APP_ROOT/.restore-layout.XXXXXX")" || failed=1
    if [[ "$failed" -eq 0 ]]; then
      tar --acls --xattrs --numeric-owner -xpf "$BACKUP_RECORD/current.tar" -C "$restore_parent" || failed=1
      [[ -d "$restore_parent/current" && ! -L "$restore_parent/current" ]] || failed=1
      mv -T -- "$restore_parent/current" "$APP_ROOT/current" || failed=1
    fi
    if [[ -n "$restore_parent" && -d "$restore_parent" ]]; then
      rmdir "$restore_parent" 2>/dev/null || failed=1
    fi
    if [[ -n "$failed_release" && -d "$failed_release" ]]; then
      case "$failed_release" in
        "$APP_ROOT"/.failed-layout.*) rm -rf -- "$failed_release" ;;
        *) failed=1 ;;
      esac
    fi
  fi
  if [[ "$SHARED_CONFIG_CREATED" -eq 1 && -f "$APP_ROOT/shared/config/config.php" ]]; then
    rm -f -- "$APP_ROOT/shared/config/config.php" || failed=1
  fi
  if [[ "$SHARED_CONFIG_DIR_CREATED" -eq 1 && -d "$APP_ROOT/shared/config" ]]; then
    rmdir "$APP_ROOT/shared/config" 2>/dev/null || failed=1
  fi
  if [[ "$RELEASES_CREATED" -eq 1 && -d "$APP_ROOT/releases" ]]; then
    rmdir "$APP_ROOT/releases" 2>/dev/null || failed=1
  fi

  if [[ -d "$APP_ROOT/current" && ! -L "$APP_ROOT/current" ]]; then
    tar --acls --xattrs --numeric-owner --compare -f "$BACKUP_RECORD/current.tar" -C "$APP_ROOT" >/dev/null || failed=1
  else
    failed=1
  fi
  [[ "$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT/shared/attachments")" == "$ATTACHMENTS_STAT" ]] || failed=1
  [[ "$(sha256sum "$APP_ROOT/current/config/config.php" | awk '{print $1}')" == "$CONFIG_SHA256" ]] || failed=1
  if ! apache2ctl configtest >/dev/null || ! systemctl reload apache2; then
    failed=1
  fi
  if ! public_login || ! public_status '/' '302'; then
    failed=1
  fi
  if [[ "$failed" -eq 0 ]]; then
    printf 'restored_at=%s\nRESTORE_OK=1\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$BACKUP_RECORD/layout-receipt.txt"
  else
    printf '%s\n' 'CRITICAL: automatic layout restoration did not pass every exact check.' >&2
  fi
  return "$failed"
}

cleanup() {
  local status=$?
  local restore_failed=0
  trap - EXIT INT TERM HUP
  set +e
  if [[ "$status" -ne 0 && "$COMMITTED" -eq 0 && "$MUTATION_STARTED" -eq 1 ]]; then
    restore_layout || restore_failed=1
  fi
  if [[ "$restore_failed" -ne 0 ]]; then
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

MUTATION_STARTED=1
mkdir "$APP_ROOT/shared/config"
SHARED_CONFIG_DIR_CREATED=1
chmod 0750 "$APP_ROOT/shared/config"
chown "$RELEASE_OWNER:$RELEASE_GROUP" "$APP_ROOT/shared/config"
CONFIG_PENDING="$APP_ROOT/shared/config/.config.php.pending.$$"
cp -- "$LEGACY_CONFIG" "$CONFIG_PENDING"
[[ "$(sha256sum "$CONFIG_PENDING" | awk '{print $1}')" == "$CONFIG_SHA256" ]] || die 'shared config copy changed bytes.'
chown "$RELEASE_OWNER:$RELEASE_GROUP" "$CONFIG_PENDING"
chmod 0640 "$CONFIG_PENDING"
mv -T -- "$CONFIG_PENDING" "$APP_ROOT/shared/config/config.php"
SHARED_CONFIG_CREATED=1

mkdir "$APP_ROOT/releases"
RELEASES_CREATED=1
chmod 2750 "$APP_ROOT/releases"
chown "$RELEASE_OWNER:$RELEASE_GROUP" "$APP_ROOT/releases"
RELEASE_NAME="legacy-${CURRENT_REVISION}-${timestamp}"
RELEASE_PATH="$APP_ROOT/releases/$RELEASE_NAME"
mv -T -- "$APP_ROOT/current" "$RELEASE_PATH"
CURRENT_MOVED=1
rm -f -- "$RELEASE_PATH/config/config.php"
ln -s '../../../shared/config/config.php' "$RELEASE_PATH/config/config.php"
[[ "$(readlink -f "$RELEASE_PATH/config/config.php")" == "$APP_ROOT/shared/config/config.php" ]] || die 'legacy config link is unsafe.'
printf '%s\n' "$CURRENT_REVISION" > "$RELEASE_PATH/REVISION"
printf '%s\n' "$CURRENT_ARCHIVE_SHA256" > "$RELEASE_PATH/ARTIFACT_SHA256"
(
  cd "$RELEASE_PATH"
  find -P . -type f ! -name '.release-files.sha256' ! -name 'RELEASE_DIGEST' -print0 | \
    LC_ALL=C sort -z | xargs -0 sha256sum
) > "$RELEASE_PATH/.release-files.sha256"
LEGACY_RELEASE_DIGEST="$(sha256sum "$RELEASE_PATH/.release-files.sha256" | awk '{print $1}')"
printf '%s\n' "$LEGACY_RELEASE_DIGEST" > "$RELEASE_PATH/RELEASE_DIGEST"
find -P "$RELEASE_PATH" -type d -exec chmod 2750 {} +
find -P "$RELEASE_PATH" -type f -exec chmod 0640 {} +
chmod 0444 "$RELEASE_PATH/REVISION" "$RELEASE_PATH/ARTIFACT_SHA256" "$RELEASE_PATH/RELEASE_DIGEST"
chmod 0440 "$RELEASE_PATH/.release-files.sha256"
chown -R "$RELEASE_OWNER:$RELEASE_GROUP" "$RELEASE_PATH"
chown -h "$RELEASE_OWNER:$RELEASE_GROUP" "$RELEASE_PATH/config/config.php"
(
  cd "$RELEASE_PATH"
  sha256sum -c .release-files.sha256 >/dev/null
)

CURRENT_PENDING="$APP_ROOT/.current.layout.$$"
ln -s "releases/$RELEASE_NAME" "$CURRENT_PENDING"
mv -Tf -- "$CURRENT_PENDING" "$APP_ROOT/current"
[[ "$(readlink -f "$APP_ROOT/current")" == "$RELEASE_PATH" ]] || die 'current did not select the converted legacy release.'

VHOST_MUTATED=1
atomic_copy "$STAGED_HTTP" "$HTTP_VHOST"
atomic_copy "$STAGED_HTTPS" "$HTTPS_VHOST"
apache2ctl configtest >/dev/null
systemctl reload apache2
public_login || die 'public login check failed after layout conversion.'
public_status '/' '302' || die 'public signed-out root check failed after layout conversion.'
[[ "$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT/shared/attachments")" == "$ATTACHMENTS_STAT" ]] || \
  die 'attachment-directory identity changed during conversion.'
[[ "$(sha256sum "$APP_ROOT/shared/config/config.php" | awk '{print $1}')" == "$CONFIG_SHA256" ]] || \
  die 'protected config bytes changed during conversion.'

printf 'completed_at=%s\nlegacy_release=%s\nlegacy_release_digest=%s\nLAYOUT_OK=1\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$RELEASE_NAME" "$LEGACY_RELEASE_DIGEST" >> "$BACKUP_RECORD/layout-receipt.txt"
printf 'LAYOUT_OK legacy_revision=%s release_digest=%s backup=%s\n' \
  "$CURRENT_REVISION" "$LEGACY_RELEASE_DIGEST" "$BACKUP_RECORD"
COMMITTED=1
