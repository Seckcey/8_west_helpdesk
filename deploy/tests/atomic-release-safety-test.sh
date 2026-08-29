#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Git for Windows otherwise copies link targets instead of exercising the
# release-pointer path. Its safe system-file emulation preserves Bash link
# semantics without requiring Windows administrator privileges.
case "$(uname -s)" in
  MINGW*|MSYS*) export MSYS=winsymlinks:sys ;;
esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_ROOT="$(mktemp -d /tmp/safeharbor-release-test.XXXXXX)"
REAL_PHP="$(command -v php)"
COUNT=0

cleanup() {
  case "$TEST_ROOT" in
    /tmp/safeharbor-release-test.*) rm -rf -- "$TEST_ROOT" ;;
  esac
}
trap cleanup EXIT

pass() {
  COUNT=$((COUNT + 1))
  printf 'ok %d - %s\n' "$COUNT" "$1"
}

fail() {
  printf 'not ok %d - %s\n' "$((COUNT + 1))" "$1" >&2
  exit 1
}

create_fake_commands() {
  local fixture="$1"
  local bin="$fixture/bin"
  mkdir -p "$bin"

  cat > "$bin/flock" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'flock %s\n' "$*" >> "$COMMAND_LOG"
[[ "${FLOCK_BUSY:-0}" != '1' ]]
EOF

  cat > "$bin/apache2ctl" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'apache2ctl %s\n' "$*" >> "$COMMAND_LOG"
if [[ "${FAIL_APACHE_ONCE:-0}" == '1' && ! -e "$APACHE_FAILURE_MARKER" ]]; then
  : > "$APACHE_FAILURE_MARKER"
  exit 41
fi
printf 'Syntax OK\n'
EOF

  cat > "$bin/systemctl" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'systemctl %s\n' "$*" >> "$COMMAND_LOG"
if [[ "${FAIL_RELOAD_ONCE:-0}" == '1' && ! -e "$RELOAD_FAILURE_MARKER" ]]; then
  : > "$RELOAD_FAILURE_MARKER"
  exit 42
fi
EOF

  cat > "$bin/curl" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
output='/dev/null'
url=''
while [[ $# -gt 0 ]]; do
  case "$1" in
    --output|-o)
      output="$2"
      shift 2
      ;;
    --write-out|-w|--max-time)
      shift 2
      ;;
    --silent|--show-error)
      shift
      ;;
    *)
      url="$1"
      shift
      ;;
  esac
done
printf 'curl %s\n' "$url" >> "$COMMAND_LOG"
code='302'
body=''
case "$url" in
  */health.php*)
    if [[ "${FAIL_HEALTH:-0}" == '1' ]]; then
      code='503'
      body='{"status":"unavailable"}'
    else
      code='200'
      revision="$(tr -d '\r\n' < "$APP_ROOT_FOR_TEST/current/REVISION")"
      artifact="$(tr -d '\r\n' < "$APP_ROOT_FOR_TEST/current/ARTIFACT_SHA256")"
      digest="$(tr -d '\r\n' < "$APP_ROOT_FOR_TEST/current/RELEASE_DIGEST")"
      body="{\"status\":\"ok\",\"revision\":\"$revision\",\"artifact_sha256\":\"$artifact\",\"release_digest\":\"$digest\"}"
    fi
    ;;
  */login.php)
    code='200'
    body='<title>Sign in · Safeharbor</title>'
    ;;
  */)
    code='302'
    ;;
esac
if [[ "$output" != '/dev/null' ]]; then
  printf '%s' "$body" > "$output"
fi
printf '%s' "$code"
EOF

  cat > "$bin/php" <<EOF
#!/usr/bin/env bash
exec "$REAL_PHP" "\$@"
EOF
  chmod +x "$bin/flock" "$bin/apache2ctl" "$bin/systemctl" "$bin/curl" "$bin/php"
}

write_atomic_vhost() {
  local path="$1"
  local app_root="$2"
  cat > "$path" <<EOF
<VirtualHost *:443>
    DocumentRoot $app_root/current/public
    <Directory $app_root/releases/*/public>
        Require all granted
    </Directory>
    <Directory $app_root/releases/*/public/assets>
        Header always set Cache-Control "public"
    </Directory>
</VirtualHost>
EOF
}

write_legacy_vhost() {
  local path="$1"
  local app_root="$2"
  cat > "$path" <<EOF
<VirtualHost *:443>
    DocumentRoot $app_root/current/public
    <Directory $app_root/current/public>
        Require all granted
    </Directory>
    <Directory $app_root/current/public/assets>
        Header always set Cache-Control "public"
    </Directory>
</VirtualHost>
EOF
}

create_artifact() {
  local fixture="$1"
  local source="$fixture/artifact/source"
  mkdir -p "$source/app/config" "$source/app/lib" "$source/app/public" \
    "$source/brand/svg" "$source/brand/png"
  cp "$ROOT/app/lib/release.php" "$source/app/lib/release.php"
  cp "$ROOT/app/public/health.php" "$source/app/public/health.php"
  cat > "$source/app/public/login.php" <<'PHP'
<?php declare(strict_types=1); ?><title>Sign in · Safeharbor</title>
PHP
  cat > "$source/app/config/config.sample.php" <<'PHP'
<?php declare(strict_types=1); return [];
PHP
  printf 'svg-favicon\n' > "$source/brand/svg/favicon.svg"
  printf 'svg-mark\n' > "$source/brand/svg/safeharbor-mark.svg"
  printf 'ico\n' > "$source/brand/png/favicon.ico"
  printf 'apple\n' > "$source/brand/png/apple-touch-icon.png"
  printf 'tile-192\n' > "$source/brand/png/app-tile-192.png"
  printf 'tile-512\n' > "$source/brand/png/app-tile-512.png"
  tar -cf "$fixture/release.tar" -C "$fixture/artifact" source
  ARTIFACT_SHA256="$(sha256sum "$fixture/release.tar" | awk '{print $1}')"
}

create_atomic_fixture() {
  local name="$1"
  FIXTURE="$TEST_ROOT/$name"
  APP_ROOT_FIXTURE="$FIXTURE/app"
  mkdir -p "$APP_ROOT_FIXTURE/releases/legacy/public" "$APP_ROOT_FIXTURE/releases/legacy/config" \
    "$APP_ROOT_FIXTURE/shared/config" "$APP_ROOT_FIXTURE/shared/attachments" "$FIXTURE/backups"
  printf 'protected-config-sentinel\n' > "$APP_ROOT_FIXTURE/shared/config/config.php"
  printf 'attachment-sentinel\n' > "$APP_ROOT_FIXTURE/shared/attachments/keep.txt"
  printf '<title>Sign in · Safeharbor</title>\n' > "$APP_ROOT_FIXTURE/releases/legacy/public/login.php"
  ln -s '../../../shared/config/config.php' "$APP_ROOT_FIXTURE/releases/legacy/config/config.php"
  printf '%s\n' "$(printf '1%.0s' {1..40})" > "$APP_ROOT_FIXTURE/releases/legacy/REVISION"
  printf '%s\n' "$(printf '2%.0s' {1..64})" > "$APP_ROOT_FIXTURE/releases/legacy/ARTIFACT_SHA256"
  printf '%s\n' "$(printf '3%.0s' {1..64})" > "$APP_ROOT_FIXTURE/releases/legacy/RELEASE_DIGEST"
  ln -s releases/legacy "$APP_ROOT_FIXTURE/current"
  write_atomic_vhost "$FIXTURE/http.conf" "$APP_ROOT_FIXTURE"
  write_atomic_vhost "$FIXTURE/https.conf" "$APP_ROOT_FIXTURE"
  create_artifact "$FIXTURE"
  create_fake_commands "$FIXTURE"
  EXPECTED_SHA="$(printf 'a%.0s' {1..40})"
  CONTROLLER_SHA256="$(sha256sum "$ROOT/deploy/release-server.sh" | awk '{print $1}')"
  COMMAND_LOG="$FIXTURE/commands.log"
  : > "$COMMAND_LOG"
  CONFIG_BEFORE="$(sha256sum "$APP_ROOT_FIXTURE/shared/config/config.php" | awk '{print $1}')"
  ATTACHMENTS_BEFORE="$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT_FIXTURE/shared/attachments")"
}

run_atomic_release() {
  local expected_artifact="${1:-$ARTIFACT_SHA256}"
  env \
    PATH="$FIXTURE/bin:$PATH" \
    SAFEHARBOR_RELEASE_TEST_MODE=1 \
    APP_ROOT="$APP_ROOT_FIXTURE" \
    BACKUP_ROOT="$FIXTURE/backups" \
    LOCK_FILE="$FIXTURE/release.lock" \
    HTTP_VHOST="$FIXTURE/http.conf" \
    HTTPS_VHOST="$FIXTURE/https.conf" \
    PUBLIC_URL='https://safeharbor.test' \
    EXPECTED_SHA="$EXPECTED_SHA" \
    EXPECTED_ARTIFACT_SHA256="$expected_artifact" \
    EXPECTED_CONTROLLER_SHA256="$CONTROLLER_SHA256" \
    APP_ROOT_FOR_TEST="$APP_ROOT_FIXTURE" \
    COMMAND_LOG="$COMMAND_LOG" \
    APACHE_FAILURE_MARKER="$FIXTURE/apache-failed" \
    RELOAD_FAILURE_MARKER="$FIXTURE/reload-failed" \
    FAIL_HEALTH="${FAIL_HEALTH:-0}" \
    FLOCK_BUSY="${FLOCK_BUSY:-0}" \
    bash "$ROOT/deploy/release-server.sh" "$FIXTURE/release.tar"
}

assert_protected_state() {
  [[ "$(sha256sum "$APP_ROOT_FIXTURE/shared/config/config.php" | awk '{print $1}')" == "$CONFIG_BEFORE" ]] || \
    fail "$1 changed protected config"
  [[ "$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT_FIXTURE/shared/attachments")" == "$ATTACHMENTS_BEFORE" ]] || \
    fail "$1 changed attachment-directory identity"
  [[ "$(cat "$APP_ROOT_FIXTURE/shared/attachments/keep.txt")" == 'attachment-sentinel' ]] || \
    fail "$1 changed attachment bytes"
}

create_atomic_fixture atomic-success
if ! run_atomic_release > "$FIXTURE/output.log" 2>&1; then
  cat "$FIXTURE/output.log" >&2
  fail 'exact atomic release should succeed'
fi
[[ "$(readlink -f "$APP_ROOT_FIXTURE/current")" == "$APP_ROOT_FIXTURE/releases/$EXPECTED_SHA" ]] || \
  fail 'success did not atomically select exact release'
[[ "$(cat "$APP_ROOT_FIXTURE/current/REVISION")" == "$EXPECTED_SHA" ]] || fail 'revision marker mismatch'
[[ "$(cat "$APP_ROOT_FIXTURE/current/ARTIFACT_SHA256")" == "$ARTIFACT_SHA256" ]] || fail 'artifact marker mismatch'
[[ -f "$APP_ROOT_FIXTURE/current/.release-files.sha256" ]] || fail 'release file manifest missing'
(cd "$APP_ROOT_FIXTURE/current" && sha256sum -c .release-files.sha256 >/dev/null) || fail 'release manifest does not verify'
[[ "$(readlink -f "$APP_ROOT_FIXTURE/current/config/config.php")" == "$APP_ROOT_FIXTURE/shared/config/config.php" ]] || \
  fail 'release config does not resolve to protected shared config'
assert_protected_state 'successful release'
grep -Fq 'RELEASE_OK revision=' "$FIXTURE/output.log" || fail 'success receipt missing'
grep -Fq '/health.php?' "$COMMAND_LOG" || fail 'exact public health was not checked'
grep -Fq '/login.php' "$COMMAND_LOG" || fail 'public login was not checked'
find "$FIXTURE/backups" -name release.tar -type f | grep -q . || fail 'external exact artifact backup missing'
pass 'exact artifact stages immutably, switches atomically, and preserves protected state'

create_atomic_fixture health-rollback
OLD_TARGET="$(readlink -f "$APP_ROOT_FIXTURE/current")"
FAIL_HEALTH=1
if run_atomic_release > "$FIXTURE/output.log" 2>&1; then
  fail 'failed public health was accepted'
fi
unset FAIL_HEALTH
[[ "$(readlink -f "$APP_ROOT_FIXTURE/current")" == "$OLD_TARGET" ]] || fail 'health failure did not restore exact previous target'
assert_protected_state 'health rollback'
grep -Fq 'restoring the exact previous release' "$FIXTURE/output.log" || fail 'rollback was not reported'
[[ "$(grep -c '^systemctl reload apache2$' "$COMMAND_LOG")" -eq 2 ]] || fail 'rollback did not reload Apache after restoration'
pass 'failed public health automatically restores the exact previous release'

create_atomic_fixture digest-tamper
printf 'tamper\n' >> "$FIXTURE/release.tar"
if run_atomic_release "$ARTIFACT_SHA256" > "$FIXTURE/output.log" 2>&1; then
  fail 'tampered artifact was accepted'
fi
[[ "$(readlink -f "$APP_ROOT_FIXTURE/current")" == "$APP_ROOT_FIXTURE/releases/legacy" ]] || fail 'digest failure changed current'
[[ ! -s "$COMMAND_LOG" ]] || fail 'digest failure reached the release lock or production commands'
assert_protected_state 'digest refusal'
pass 'artifact digest mismatch stops before the production lock or mutation'

create_atomic_fixture lock-contention
FLOCK_BUSY=1
if run_atomic_release > "$FIXTURE/output.log" 2>&1; then
  fail 'release lock contention was accepted'
fi
unset FLOCK_BUSY
[[ "$(readlink -f "$APP_ROOT_FIXTURE/current")" == "$APP_ROOT_FIXTURE/releases/legacy" ]] || fail 'lock contention changed current'
[[ ! -d "$APP_ROOT_FIXTURE/releases/$EXPECTED_SHA" ]] || fail 'lock contention staged a target release'
assert_protected_state 'lock contention'
pass 'release lock contention fails before staging or switching'

create_atomic_fixture runtime-config-archive
printf '<?php return ["secret" => true];\n' > "$FIXTURE/artifact/source/app/config/config.php"
tar -cf "$FIXTURE/release.tar" -C "$FIXTURE/artifact" source
ARTIFACT_SHA256="$(sha256sum "$FIXTURE/release.tar" | awk '{print $1}')"
if run_atomic_release > "$FIXTURE/output.log" 2>&1; then
  fail 'artifact containing runtime config was accepted'
fi
[[ ! -s "$COMMAND_LOG" ]] || fail 'runtime-config archive reached the production lock'
assert_protected_state 'runtime-config refusal'
pass 'artifact containing protected runtime config is rejected before mutation'

create_conversion_fixture() {
  local name="$1"
  FIXTURE="$TEST_ROOT/$name"
  APP_ROOT_FIXTURE="$FIXTURE/app"
  mkdir -p "$APP_ROOT_FIXTURE/current/config" "$APP_ROOT_FIXTURE/current/public" \
    "$APP_ROOT_FIXTURE/current/lib" "$APP_ROOT_FIXTURE/shared/attachments" "$FIXTURE/backups"
  printf 'protected-config-sentinel\n' > "$APP_ROOT_FIXTURE/current/config/config.php"
  printf '<title>Sign in · Safeharbor</title>\n' > "$APP_ROOT_FIXTURE/current/public/login.php"
  printf '<?php return true;\n' > "$APP_ROOT_FIXTURE/current/lib/example.php"
  printf 'attachment-sentinel\n' > "$APP_ROOT_FIXTURE/shared/attachments/keep.txt"
  write_legacy_vhost "$FIXTURE/http.conf" "$APP_ROOT_FIXTURE"
  write_legacy_vhost "$FIXTURE/https.conf" "$APP_ROOT_FIXTURE"
  create_fake_commands "$FIXTURE"
  EXPECTED_SHA="$(printf 'b%.0s' {1..40})"
  CURRENT_REVISION="$(printf 'c%.0s' {1..40})"
  CONTROLLER_SHA256="$(sha256sum "$ROOT/deploy/prepare-atomic-layout-server.sh" | awk '{print $1}')"
  HTTP_SHA256="$(sha256sum "$FIXTURE/http.conf" | awk '{print $1}')"
  HTTPS_SHA256="$(sha256sum "$FIXTURE/https.conf" | awk '{print $1}')"
  COMMAND_LOG="$FIXTURE/commands.log"
  : > "$COMMAND_LOG"
  CURRENT_BEFORE="$(tar -cf - -C "$APP_ROOT_FIXTURE" current | sha256sum | awk '{print $1}')"
  HTTP_BEFORE="$HTTP_SHA256"
  HTTPS_BEFORE="$HTTPS_SHA256"
  ATTACHMENTS_BEFORE="$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT_FIXTURE/shared/attachments")"
}

run_conversion() {
  env \
    PATH="$FIXTURE/bin:$PATH" \
    SAFEHARBOR_RELEASE_TEST_MODE=1 \
    APP_ROOT="$APP_ROOT_FIXTURE" \
    BACKUP_ROOT="$FIXTURE/backups" \
    LOCK_FILE="$FIXTURE/release.lock" \
    HTTP_VHOST="$FIXTURE/http.conf" \
    HTTPS_VHOST="$FIXTURE/https.conf" \
    PUBLIC_URL='https://safeharbor.test' \
    EXPECTED_SHA="$EXPECTED_SHA" \
    CURRENT_REVISION="$CURRENT_REVISION" \
    EXPECTED_CONTROLLER_SHA256="$CONTROLLER_SHA256" \
    EXPECTED_HTTP_VHOST_SHA256="$HTTP_SHA256" \
    EXPECTED_HTTPS_VHOST_SHA256="$HTTPS_SHA256" \
    APP_ROOT_FOR_TEST="$APP_ROOT_FIXTURE" \
    COMMAND_LOG="$COMMAND_LOG" \
    APACHE_FAILURE_MARKER="$FIXTURE/apache-failed" \
    RELOAD_FAILURE_MARKER="$FIXTURE/reload-failed" \
    FAIL_RELOAD_ONCE="${FAIL_RELOAD_ONCE:-0}" \
    bash "$ROOT/deploy/prepare-atomic-layout-server.sh"
}

create_conversion_fixture conversion-success
if ! run_conversion > "$FIXTURE/output.log" 2>&1; then
  cat "$FIXTURE/output.log" >&2
  fail 'one-time layout conversion should succeed'
fi
[[ -L "$APP_ROOT_FIXTURE/current" ]] || fail 'conversion did not create current symlink'
[[ "$(readlink -f "$APP_ROOT_FIXTURE/current/config/config.php")" == "$APP_ROOT_FIXTURE/shared/config/config.php" ]] || \
  fail 'conversion did not externalize config'
[[ "$(cat "$APP_ROOT_FIXTURE/shared/config/config.php")" == 'protected-config-sentinel' ]] || fail 'conversion changed config bytes'
[[ "$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT_FIXTURE/shared/attachments")" == "$ATTACHMENTS_BEFORE" ]] || \
  fail 'conversion changed attachment-directory identity'
grep -Fq "<Directory $APP_ROOT_FIXTURE/releases/*/public>" "$FIXTURE/http.conf" || fail 'HTTP vhost was not converted'
grep -Fq "<Directory $APP_ROOT_FIXTURE/releases/*/public>" "$FIXTURE/https.conf" || fail 'HTTPS vhost was not converted'
find "$FIXTURE/backups" -name current.tar -type f | grep -q . || fail 'exact legacy backup missing'
grep -Fq 'LAYOUT_OK legacy_revision=' "$FIXTURE/output.log" || fail 'layout success receipt missing'
pass 'one-time conversion externalizes config and preserves attachments behind an exact backup'

create_conversion_fixture conversion-rollback
FAIL_RELOAD_ONCE=1
if run_conversion > "$FIXTURE/output.log" 2>&1; then
  fail 'injected Apache reload failure was accepted'
fi
unset FAIL_RELOAD_ONCE
[[ -d "$APP_ROOT_FIXTURE/current" && ! -L "$APP_ROOT_FIXTURE/current" ]] || fail 'conversion rollback did not restore real current directory'
[[ "$(tar -cf - -C "$APP_ROOT_FIXTURE" current | sha256sum | awk '{print $1}')" == "$CURRENT_BEFORE" ]] || \
  fail 'conversion rollback did not restore exact current bytes'
[[ "$(sha256sum "$FIXTURE/http.conf" | awk '{print $1}')" == "$HTTP_BEFORE" ]] || fail 'HTTP vhost was not restored exactly'
[[ "$(sha256sum "$FIXTURE/https.conf" | awk '{print $1}')" == "$HTTPS_BEFORE" ]] || fail 'HTTPS vhost was not restored exactly'
[[ ! -e "$APP_ROOT_FIXTURE/releases" ]] || fail 'failed conversion left a releases directory'
[[ ! -e "$APP_ROOT_FIXTURE/shared/config" ]] || fail 'failed conversion left shared config'
[[ "$(stat -c '%d:%i:%u:%g:%a' "$APP_ROOT_FIXTURE/shared/attachments")" == "$ATTACHMENTS_BEFORE" ]] || \
  fail 'conversion rollback changed attachment-directory identity'
grep -R -Fq 'RESTORE_OK=1' "$FIXTURE/backups" || fail 'exact restore receipt missing'
pass 'conversion failure restores the real directory and both Apache files exactly'

printf '1..%d\n' "$COUNT"
