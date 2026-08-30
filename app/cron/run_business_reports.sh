#!/usr/bin/env bash
# Narrow operating-system wrapper for the bounded PHP business-report runner.
# Report gates, allowlists, archive generation, delivery leases, and retry
# policy remain in PHP. This wrapper pins runtime identity and deployment
# metadata, coordinates with non-atomic deployment, and logs every outcome.
set -euo pipefail
umask 027

readonly EXPECTED_USER='www-data'
readonly TEST_ROOT="${SAFEHARBOR_SCHEDULER_TEST_ROOT:-}"
if [[ -n "$TEST_ROOT" ]]; then
    [[ "$TEST_ROOT" == /tmp/safeharbor-scheduler-test.* \
        && "$TEST_ROOT" != *'/../'* \
        && "$TEST_ROOT" != *$'\n'* \
        && -d "$TEST_ROOT" \
        && ! -L "$TEST_ROOT" ]] || exit 64
    readonly ROOT_PREFIX="$TEST_ROOT"
    readonly DEPLOY_OWNER="${SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER:-root}"
    readonly LOGGER_BIN="${SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN:-/usr/bin/logger}"
else
    [[ -z "${SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER:-}${SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN:-}" ]] \
        || exit 64
    readonly ROOT_PREFIX=''
    readonly DEPLOY_OWNER='ubuntu'
    readonly LOGGER_BIN='/usr/bin/logger'
fi

readonly APP_PARENT="$ROOT_PREFIX/srv/8west/apps/safeharbor"
readonly APP_ROOT="$APP_PARENT/current"
readonly PHP_BIN='/usr/bin/php'
readonly REPORT_RUNNER="$APP_ROOT/cron/business_reports.php"
readonly PROTECTED_CONFIG="$APP_ROOT/config/config.php"
readonly DEPLOY_LOCK_DIR="$ROOT_PREFIX/var/lib/safeharbor-report-scheduler"
readonly DEPLOY_LOCK="$DEPLOY_LOCK_DIR/business-reports-deploy.lock"
readonly LOG_TAG='safeharbor-business-reports'

report_scheduler_fail() {
    local message="$1"
    local status="${2:-1}"

    if [[ -x "$LOGGER_BIN" ]]; then
        "$LOGGER_BIN" --stderr --tag "$LOG_TAG" --priority user.err -- "$message" || true
    else
        printf '%s: %s\n' "$LOG_TAG" "$message" >&2
    fi
    exit "$status"
}

require_metadata() {
    local path="$1"
    local owner="$2"
    local group="$3"
    local mode="$4"
    local label="$5"

    [[ -e "$path" && ! -L "$path" ]] \
        || report_scheduler_fail "refused: $label is missing or symlinked" 72
    [[ "$(/usr/bin/stat -c '%U:%G:%a' -- "$path")" == "$owner:$group:$mode" ]] \
        || report_scheduler_fail "refused: $label owner or mode mismatch" 77
}

require_physical_directory() {
    local path="$1"
    local label="$2"
    [[ -d "$path" && ! -L "$path" \
        && "$(cd -- "$path" && pwd -P)" == "$path" ]] \
        || report_scheduler_fail "refused: $label is not the exact physical directory" 77
}

[[ "$(/usr/bin/id -un)" == "$EXPECTED_USER" ]] \
    || report_scheduler_fail 'refused: expected runtime user www-data' 77
[[ -x "$PHP_BIN" ]] \
    || report_scheduler_fail 'refused: /usr/bin/php is unavailable' 69
[[ -x "$LOGGER_BIN" ]] \
    || report_scheduler_fail 'refused: logger is unavailable' 69
[[ -x /usr/bin/flock ]] \
    || report_scheduler_fail 'refused: /usr/bin/flock is unavailable' 69

require_metadata "$DEPLOY_LOCK_DIR" root www-data 750 'deploy lock directory'
require_metadata "$DEPLOY_LOCK" root www-data 640 'deploy lock'
exec 9<"$DEPLOY_LOCK" \
    || report_scheduler_fail 'refused: deploy lock cannot be opened' 75
/usr/bin/flock --shared --nonblock 9 \
    || report_scheduler_fail 'skipped: Safeharbor deployment is in progress' 75

require_metadata "$APP_PARENT" "$DEPLOY_OWNER" www-data 2750 'application parent'
require_metadata "$APP_ROOT" "$DEPLOY_OWNER" www-data 2750 'application root'
require_physical_directory "$APP_PARENT" 'application parent'
require_physical_directory "$APP_ROOT" 'application root'
require_metadata "$APP_ROOT/cron" "$DEPLOY_OWNER" www-data 2750 'cron directory'
require_metadata "$APP_ROOT/cron/run_business_reports.sh" "$DEPLOY_OWNER" www-data 640 'report wrapper'
require_metadata "$REPORT_RUNNER" "$DEPLOY_OWNER" www-data 640 'report runner'
require_metadata "$PROTECTED_CONFIG" "$DEPLOY_OWNER" www-data 640 'protected config'

cd -- "$APP_ROOT" \
    || report_scheduler_fail 'refused: Safeharbor application root is not accessible' 72
[[ "$(pwd -P)" == "$APP_ROOT" ]] \
    || report_scheduler_fail 'refused: Safeharbor application root resolved unexpectedly' 72

set +e
"$PHP_BIN" "$REPORT_RUNNER" 2>&1 \
    | "$LOGGER_BIN" --stderr --tag "$LOG_TAG" --priority user.notice
pipeline_status=("${PIPESTATUS[@]}")
set -e

php_status="${pipeline_status[0]}"
logger_status="${pipeline_status[1]}"
if [[ "$logger_status" -ne 0 ]]; then
    printf '%s: logger failed with status %s; PHP status was %s\n' \
        "$LOG_TAG" "$logger_status" "$php_status" >&2
    # PHP is the business operation. If both sides fail, preserve that primary
    # failure for cron and operator recovery; 74 is reserved for a logger-only
    # failure after PHP succeeded.
    if [[ "$php_status" -ne 0 ]]; then
        exit "$php_status"
    fi
    exit 74
fi
if [[ "$php_status" -ne 0 ]]; then
    # This final diagnostic is best-effort. A logger failure here must never
    # replace the PHP status that cron and operators use for failure evidence.
    "$LOGGER_BIN" --stderr --tag "$LOG_TAG" --priority user.err -- \
        "runner exited with status $php_status" || {
            printf '%s: unable to log runner status %s\n' \
                "$LOG_TAG" "$php_status" >&2 || true
        }
fi
exit "$php_status"
