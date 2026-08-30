#!/usr/bin/env bash
# Root-side half of deploy/deploy.sh. It consumes one reviewed app tarball on
# stdin only while the report scheduler is inactive and no report runner holds
# the shared deployment lock. It never installs or enables cron.
set -euo pipefail
umask 077

deploy_fail() {
    printf 'SAFEHARBOR_DEPLOY_REFUSED=%s\n' "$1" >&2
    exit "${2:-1}"
}

[[ "${EUID}" -eq 0 ]] || deploy_fail 'root-required' 77

readonly TEST_ROOT="${SAFEHARBOR_SCHEDULER_TEST_ROOT:-}"
if [[ -n "$TEST_ROOT" ]]; then
    [[ "$TEST_ROOT" == /tmp/safeharbor-scheduler-test.* \
        && "$TEST_ROOT" != *'/../'* \
        && "$TEST_ROOT" != *$'\n'* \
        && -d "$TEST_ROOT" \
        && ! -L "$TEST_ROOT" \
        && "$(/usr/bin/stat -c '%U:%G' -- "$TEST_ROOT")" == 'root:root' ]] \
        || deploy_fail 'unsafe-test-root' 64
    readonly ROOT_PREFIX="$TEST_ROOT"
    readonly DEPLOY_OWNER="${SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER:-root}"
else
    [[ -z "${SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER:-}" ]] \
        || deploy_fail 'test-owner-without-test-root' 64
    readonly ROOT_PREFIX=''
    readonly DEPLOY_OWNER='ubuntu'
fi

readonly CRON_ACTIVE="$ROOT_PREFIX/etc/cron.d/safeharbor-business-reports"
readonly APP_PARENT="$ROOT_PREFIX/srv/8west/apps/safeharbor"
readonly APP_ROOT="$APP_PARENT/current"
readonly PROTECTED_CONFIG="$APP_ROOT/config/config.php"
readonly DEPLOY_LOCK_DIR="$ROOT_PREFIX/var/lib/safeharbor-report-scheduler"
readonly DEPLOY_LOCK="$DEPLOY_LOCK_DIR/business-reports-deploy.lock"
readonly STAGING_DIR="$ROOT_PREFIX/run/safeharbor-deploy"

for required_tool in /usr/bin/cat /usr/bin/chmod /usr/bin/chown /usr/bin/date \
    /usr/bin/find /usr/bin/flock /usr/bin/install /usr/bin/mktemp \
    /usr/bin/pgrep /usr/bin/sed /usr/bin/sha256sum /usr/bin/stat \
    /usr/bin/tar /usr/bin/unlink; do
    [[ -x "$required_tool" ]] || deploy_fail "required-tool-missing:$required_tool" 69
done

path_absent() {
    [[ ! -e "$1" && ! -L "$1" ]]
}

verify_metadata() {
    local path="$1"
    local owner="$2"
    local group="$3"
    local mode="$4"
    local label="$5"

    [[ -e "$path" && ! -L "$path" ]] \
        || deploy_fail "$label-missing-or-symlinked" 72
    [[ "$(/usr/bin/stat -c '%U:%G:%a' -- "$path")" == "$owner:$group:$mode" ]] \
        || deploy_fail "$label-owner-or-mode-mismatch" 77
}

verify_physical_directory() {
    local path="$1"
    local label="$2"
    [[ -d "$path" && ! -L "$path" \
        && "$(cd -- "$path" && pwd -P)" == "$path" ]] \
        || deploy_fail "$label-not-exact-physical-directory" 77
}

path_absent "$CRON_ACTIVE" || deploy_fail 'active-report-scheduler-present' 75
verify_metadata "$APP_PARENT" "$DEPLOY_OWNER" www-data 2750 'application-parent'
verify_metadata "$APP_ROOT" "$DEPLOY_OWNER" www-data 2750 'application-root'
verify_physical_directory "$APP_PARENT" 'application-parent'
verify_physical_directory "$APP_ROOT" 'application-root'
verify_metadata "$PROTECTED_CONFIG" "$DEPLOY_OWNER" www-data 640 'protected-config'

# Adoption guard: before the shared/exclusive protocol existed, an old runner
# could survive removal of the cron file without holding this lock. With the
# active name absent, this exact process check is race-free for that one legacy
# transition and never terminates the process.
if /usr/bin/pgrep -u www-data -f \
    "^/usr/bin/php $APP_ROOT/cron/business_reports[.]php$" >/dev/null 2>&1; then
    deploy_fail 'legacy-report-runner-in-flight' 75
fi

if path_absent "$DEPLOY_LOCK_DIR"; then
    /usr/bin/install -d -o root -g www-data -m 0750 -- "$DEPLOY_LOCK_DIR"
fi
verify_metadata "$DEPLOY_LOCK_DIR" root www-data 750 'deploy-lock-directory'
if path_absent "$DEPLOY_LOCK"; then
    /usr/bin/install -o root -g www-data -m 0640 -- /dev/null "$DEPLOY_LOCK"
fi
verify_metadata "$DEPLOY_LOCK" root www-data 640 'deploy-lock'

exec 9<"$DEPLOY_LOCK" || deploy_fail 'deploy-lock-open-failed' 75
/usr/bin/flock --exclusive --nonblock 9 || deploy_fail 'report-runner-in-flight' 75
# Serialize the check with scheduler enable: enable holds the shared side.
path_absent "$CRON_ACTIVE" || deploy_fail 'active-report-scheduler-present' 75

if path_absent "$STAGING_DIR"; then
    /usr/bin/install -d -o root -g root -m 0700 -- "$STAGING_DIR"
fi
verify_metadata "$STAGING_DIR" root root 700 'deploy-staging-directory'
payload="$(/usr/bin/mktemp "$STAGING_DIR/app.XXXXXXXX.tar.gz")" \
    || deploy_fail 'payload-staging-failed' 74
cleanup_payload() {
    /usr/bin/unlink -- "$payload" 2>/dev/null || true
}
trap cleanup_payload EXIT
/usr/bin/cat > "$payload"
verify_metadata "$payload" root root 600 'deploy-payload'

entry_count=0
while IFS= read -r entry || [[ -n "$entry" ]]; do
    entry="${entry#./}"
    [[ -n "$entry" && "$entry" != /* && "$entry" != *'\\'* ]] \
        || deploy_fail 'payload-path-invalid' 65
    case "/$entry/" in
        */../*) deploy_fail 'payload-parent-traversal' 65 ;;
    esac
    case "$entry" in
        app|app/*) ;;
        *) deploy_fail 'payload-outside-app-root' 65 ;;
    esac
    case "$entry" in
        app/config/config.php|app/config/config.php/) \
            deploy_fail 'payload-contains-protected-config' 65 ;;
    esac
    entry_count=$((entry_count + 1))
done < <(/usr/bin/tar -tzf "$payload")
[[ "$entry_count" -gt 0 ]] || deploy_fail 'payload-empty' 65

config_before="$(/usr/bin/sha256sum -- "$PROTECTED_CONFIG")"
config_before="${config_before%% *}"
/usr/bin/tar -xzf "$payload" -C "$APP_ROOT" --strip-components=1 \
    --no-same-owner --no-same-permissions
version="$(/usr/bin/date -u +%Y%m%d%H%M%S)"
/usr/bin/sed -i "s/?v=[0-9A-Za-z]\+/?v=$version/g" \
    "$APP_ROOT/lib/render.php" \
    "$APP_ROOT/lib/westy.php" \
    "$APP_ROOT/public/login.php"
/usr/bin/chown -R "$DEPLOY_OWNER:www-data" -- "$APP_ROOT"
/usr/bin/find "$APP_ROOT" -xdev -type d -exec /usr/bin/chmod 2750 -- {} +
/usr/bin/find "$APP_ROOT" -xdev -type f -exec /usr/bin/chmod 0640 -- {} +

verify_metadata "$PROTECTED_CONFIG" "$DEPLOY_OWNER" www-data 640 'protected-config'
config_after="$(/usr/bin/sha256sum -- "$PROTECTED_CONFIG")"
config_after="${config_after%% *}"
[[ "$config_after" == "$config_before" ]] \
    || deploy_fail 'protected-config-changed' 74

unexpected="$(/usr/bin/find "$APP_ROOT" -xdev ! -type d ! -type f -print -quit)"
[[ -z "$unexpected" ]] || deploy_fail 'deployment-tree-has-unsupported-file-type' 77
printf 'SAFEHARBOR_DEPLOY=installed-under-exclusive-report-lock\n'
