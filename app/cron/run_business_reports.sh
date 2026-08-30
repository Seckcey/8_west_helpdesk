#!/usr/bin/env bash
# Narrow operating-system wrapper for the bounded PHP business-report runner.
# The reviewed cron entry invokes this file as www-data. Report gates,
# allowlists, archive generation, delivery leases, and retry policy remain in
# PHP; this wrapper only pins the runtime and makes every outcome visible in
# the host journal/syslog.
set -euo pipefail
umask 027

readonly EXPECTED_USER='www-data'
readonly APP_ROOT='/srv/8west/apps/safeharbor/current'
readonly PHP_BIN='/usr/bin/php'
readonly LOGGER_BIN='/usr/bin/logger'
readonly REPORT_RUNNER="$APP_ROOT/cron/business_reports.php"
readonly PROTECTED_CONFIG="$APP_ROOT/config/config.php"
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

[[ "$(/usr/bin/id -un)" == "$EXPECTED_USER" ]] \
    || report_scheduler_fail 'refused: expected runtime user www-data' 77
[[ -x "$PHP_BIN" ]] \
    || report_scheduler_fail 'refused: /usr/bin/php is unavailable' 69
[[ -x "$LOGGER_BIN" ]] \
    || report_scheduler_fail 'refused: /usr/bin/logger is unavailable' 69
[[ -d "$APP_ROOT" ]] \
    || report_scheduler_fail 'refused: Safeharbor application root is unavailable' 72
[[ -f "$REPORT_RUNNER" && ! -L "$REPORT_RUNNER" && -r "$REPORT_RUNNER" ]] \
    || report_scheduler_fail 'refused: report runner is not a readable regular file' 72
[[ ! -w "$REPORT_RUNNER" ]] \
    || report_scheduler_fail 'refused: runtime user may modify the report runner' 77
[[ -f "$PROTECTED_CONFIG" && ! -L "$PROTECTED_CONFIG" && -r "$PROTECTED_CONFIG" ]] \
    || report_scheduler_fail 'refused: protected config is not a readable regular file' 78
[[ ! -w "$PROTECTED_CONFIG" ]] \
    || report_scheduler_fail 'refused: runtime user may modify protected config' 77

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
    exit 74
fi
if [[ "$php_status" -ne 0 ]]; then
    "$LOGGER_BIN" --stderr --tag "$LOG_TAG" --priority user.err -- \
        "runner exited with status $php_status"
fi
exit "$php_status"
