#!/usr/bin/env bash
# Install, verify, stop, or remove Safeharbor's one reviewed report cron entry.
# This script never reads protected config bytes, changes application gates,
# runs a report, sends mail, or edits any other cron file.
set -euo pipefail
umask 022

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly CRON_SOURCE="$SCRIPT_DIR/safeharbor-business-reports.cron"
readonly CRON_ACTIVE='/etc/cron.d/safeharbor-business-reports'
# Ubuntu cron ignores names containing a dot. This is the installed, default-off
# staging location until an operator supplies every explicit attestation.
readonly CRON_DISABLED='/etc/cron.d/safeharbor-business-reports.disabled'
readonly APP_ROOT='/srv/8west/apps/safeharbor/current'
readonly REPORT_RUNNER="$APP_ROOT/cron/business_reports.php"
readonly REPORT_WRAPPER="$APP_ROOT/cron/run_business_reports.sh"
readonly PROTECTED_CONFIG="$APP_ROOT/config/config.php"
readonly RUNTIME_USER='www-data'

scheduler_usage() {
    cat >&2 <<'USAGE'
Usage:
  manage-business-report-scheduler.sh preflight
  manage-business-report-scheduler.sh install-disabled
  manage-business-report-scheduler.sh verify active|disabled|absent
  manage-business-report-scheduler.sh enable \
    --confirm-recipient-canary-passed \
    --confirm-dedicated-sender-canary-passed \
    --confirm-protected-gates-reviewed
  manage-business-report-scheduler.sh disable
  manage-business-report-scheduler.sh uninstall --confirm-remove-exact-scheduler-files

install-disabled is the only install action and does not schedule a job.
enable is deliberately separate and requires all three exact attestations.
USAGE
    exit 64
}

scheduler_fail() {
    printf 'SCHEDULER_REFUSED=%s\n' "$1" >&2
    exit "${2:-1}"
}

require_root() {
    [[ "${EUID}" -eq 0 ]] || scheduler_fail 'root-required' 77
}

require_source() {
    local line
    local schedule_lines=0

    [[ -f "$CRON_SOURCE" && ! -L "$CRON_SOURCE" ]] \
        || scheduler_fail 'reviewed-cron-source-missing' 66
    while IFS= read -r line || [[ -n "$line" ]]; do
        case "$line" in
            ''|'#'*) ;;
            'SHELL=/bin/bash') ;;
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin') ;;
            'MAILTO=""') ;;
            '*/5 * * * * www-data /usr/bin/bash /srv/8west/apps/safeharbor/current/cron/run_business_reports.sh')
                schedule_lines=$((schedule_lines + 1))
                ;;
            *) scheduler_fail 'reviewed-cron-source-has-unexpected-line' 65 ;;
        esac
    done < "$CRON_SOURCE"
    [[ "$schedule_lines" -eq 1 ]] \
        || scheduler_fail 'reviewed-cron-source-schedule-count-invalid' 65
}

path_absent() {
    [[ ! -e "$1" && ! -L "$1" ]]
}

require_runtime() {
    local path
    for path in /usr/bin/bash /usr/bin/php /usr/bin/logger /usr/bin/id \
        /usr/bin/test /usr/bin/cmp /usr/bin/install /usr/bin/stat \
        /usr/bin/chown /usr/bin/chmod /usr/bin/mv /usr/bin/unlink \
        /usr/sbin/runuser; do
        [[ -x "$path" ]] || scheduler_fail "required-executable-missing:$path" 69
    done

    [[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] \
        || scheduler_fail 'application-root-missing-or-symlinked' 72
    [[ -f "$REPORT_RUNNER" && ! -L "$REPORT_RUNNER" ]] \
        || scheduler_fail 'report-runner-missing-or-symlinked' 72
    [[ -f "$REPORT_WRAPPER" && ! -L "$REPORT_WRAPPER" ]] \
        || scheduler_fail 'report-wrapper-missing-or-symlinked' 72
    [[ -f "$PROTECTED_CONFIG" && ! -L "$PROTECTED_CONFIG" ]] \
        || scheduler_fail 'protected-config-missing-or-symlinked' 78

    /usr/bin/id "$RUNTIME_USER" >/dev/null 2>&1 \
        || scheduler_fail 'runtime-user-missing' 67
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test -r "$REPORT_RUNNER" \
        || scheduler_fail 'runtime-cannot-read-report-runner' 77
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test ! -w "$REPORT_RUNNER" \
        || scheduler_fail 'runtime-can-modify-report-runner' 77
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test -r "$REPORT_WRAPPER" \
        || scheduler_fail 'runtime-cannot-read-report-wrapper' 77
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test ! -w "$REPORT_WRAPPER" \
        || scheduler_fail 'runtime-can-modify-report-wrapper' 77
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test -r "$PROTECTED_CONFIG" \
        || scheduler_fail 'runtime-cannot-read-protected-config' 77
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test ! -w "$PROTECTED_CONFIG" \
        || scheduler_fail 'runtime-can-modify-protected-config' 77

    /usr/bin/bash -n "$REPORT_WRAPPER" \
        || scheduler_fail 'report-wrapper-syntax-invalid' 65
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/php -l "$REPORT_RUNNER" >/dev/null \
        || scheduler_fail 'report-runner-syntax-invalid' 65
}

verify_file() {
    local target="$1"
    local state="$2"

    [[ -f "$target" && ! -L "$target" ]] \
        || scheduler_fail "$state-file-missing-or-symlinked" 1
    [[ "$(/usr/bin/stat -c '%U:%G:%a' "$target")" == 'root:root:644' ]] \
        || scheduler_fail "$state-owner-or-mode-mismatch" 1
    /usr/bin/cmp -s -- "$CRON_SOURCE" "$target" \
        || scheduler_fail "$state-content-mismatch" 1
}

verify_state() {
    local expected="${1:-}"
    case "$expected" in
        active)
            path_absent "$CRON_DISABLED" || scheduler_fail 'both-active-and-disabled-present' 1
            verify_file "$CRON_ACTIVE" 'active'
            ;;
        disabled)
            path_absent "$CRON_ACTIVE" || scheduler_fail 'both-active-and-disabled-present' 1
            verify_file "$CRON_DISABLED" 'disabled'
            ;;
        absent)
            path_absent "$CRON_ACTIVE" && path_absent "$CRON_DISABLED" \
                || scheduler_fail 'scheduler-file-still-present' 1
            ;;
        *) scheduler_usage ;;
    esac
    printf 'SCHEDULER_STATE=%s\n' "$expected"
}

command="${1:-}"
[[ -n "$command" ]] || scheduler_usage
shift

case "$command" in
    preflight)
        [[ "$#" -eq 0 ]] || scheduler_usage
        require_root
        require_source
        require_runtime
        printf 'SCHEDULER_PREFLIGHT=PASS\n'
        ;;
    install-disabled)
        [[ "$#" -eq 0 ]] || scheduler_usage
        require_root
        require_source
        require_runtime
        path_absent "$CRON_ACTIVE" && path_absent "$CRON_DISABLED" \
            || scheduler_fail 'scheduler-file-already-present' 1
        /usr/bin/install -o root -g root -m 0644 -- "$CRON_SOURCE" "$CRON_DISABLED"
        verify_state disabled
        ;;
    verify)
        [[ "$#" -eq 1 ]] || scheduler_usage
        require_source
        verify_state "$1"
        ;;
    enable)
        [[ "$#" -eq 3 ]] || scheduler_usage
        [[ "$1" == '--confirm-recipient-canary-passed' \
            && "$2" == '--confirm-dedicated-sender-canary-passed' \
            && "$3" == '--confirm-protected-gates-reviewed' ]] || scheduler_usage
        require_root
        require_source
        require_runtime
        path_absent "$CRON_ACTIVE" || scheduler_fail 'active-scheduler-already-present' 1
        verify_file "$CRON_DISABLED" 'disabled'
        /usr/bin/mv -- "$CRON_DISABLED" "$CRON_ACTIVE"
        /usr/bin/chown root:root -- "$CRON_ACTIVE"
        /usr/bin/chmod 0644 -- "$CRON_ACTIVE"
        verify_state active
        ;;
    disable)
        [[ "$#" -eq 0 ]] || scheduler_usage
        require_root
        path_absent "$CRON_DISABLED" || scheduler_fail 'disabled-scheduler-already-present' 1
        # Stopping execution is more important than proving content first. Move
        # the one exact active path out of cron even if it drifted, preserve it
        # for review, and report the mismatch instead of deleting evidence.
        [[ -e "$CRON_ACTIVE" || -L "$CRON_ACTIVE" ]] \
            || scheduler_fail 'active-file-missing' 1
        /usr/bin/mv -- "$CRON_ACTIVE" "$CRON_DISABLED"
        if [[ -f "$CRON_SOURCE" && ! -L "$CRON_SOURCE" \
            && -f "$CRON_DISABLED" && ! -L "$CRON_DISABLED" ]] \
            && /usr/bin/cmp -s -- "$CRON_SOURCE" "$CRON_DISABLED"; then
            /usr/bin/chown root:root -- "$CRON_DISABLED"
            /usr/bin/chmod 0644 -- "$CRON_DISABLED"
            verify_state disabled
        else
            printf 'SCHEDULER_STATE=disabled-unverified-preserved\n'
        fi
        ;;
    uninstall)
        [[ "$#" -eq 1 && "$1" == '--confirm-remove-exact-scheduler-files' ]] \
            || scheduler_usage
        require_root
        require_source
        if [[ -e "$CRON_ACTIVE" || -L "$CRON_ACTIVE" ]]; then
            verify_file "$CRON_ACTIVE" 'active'
            /usr/bin/unlink -- "$CRON_ACTIVE"
        fi
        if [[ -e "$CRON_DISABLED" || -L "$CRON_DISABLED" ]]; then
            verify_file "$CRON_DISABLED" 'disabled'
            /usr/bin/unlink -- "$CRON_DISABLED"
        fi
        verify_state absent
        ;;
    *) scheduler_usage ;;
esac
