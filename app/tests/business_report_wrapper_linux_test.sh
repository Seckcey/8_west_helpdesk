#!/usr/bin/env bash
# Linux tests for app/cron/run_business_reports.sh, the wrapper cron runs every five minutes.
# Builds a throwaway copy of the deployed layout under /tmp and runs the real wrapper against
# a stub PHP runner and a stub logger. Creates no users, services or cron entries.
set -euo pipefail
umask 077

if [[ "$(uname -s)" != 'Linux' ]]; then
    printf 'SKIP: business-report wrapper tests require Linux.\n'
    exit 0
fi
[[ "${EUID}" -eq 0 ]] || {
    printf 'FAIL: run this isolated test with sudo/root.\n' >&2
    exit 77
}
readonly TEST_RUNTIME_USER="${SAFEHARBOR_RUNTIME_FIXTURE_USER:-www-data}"
[[ "$TEST_RUNTIME_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || exit 64
/usr/bin/id "$TEST_RUNTIME_USER" > /dev/null 2>&1 || {
    printf 'FAIL: runtime test identity %s is unavailable.\n' "$TEST_RUNTIME_USER" >&2
    exit 67
}
readonly TEST_RUNTIME_GROUP="$(/usr/bin/id -gn "$TEST_RUNTIME_USER")"

readonly REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
# The wrapper accepts a test root only under this exact name pattern, owned root:root 0755.
readonly SANDBOX="$(/usr/bin/mktemp -d /tmp/safeharbor-scheduler-test.XXXXXXXX)"
cleanup() {
    case "$SANDBOX" in
        /tmp/safeharbor-scheduler-test.*) /usr/bin/rm -rf -- "$SANDBOX" ;;
        *) printf 'REFUSED unsafe cleanup path: %s\n' "$SANDBOX" >&2 ;;
    esac
    case "${MARKER:-}" in
        /tmp/safeharbor-scheduler-marker.*) /usr/bin/rm -f -- "$MARKER" ;;
    esac
}
trap cleanup EXIT
/usr/bin/chown root:root -- "$SANDBOX"
/usr/bin/chmod 0755 -- "$SANDBOX"

readonly APP_PARENT="$SANDBOX/srv/8west/apps/safeharbor"
readonly APP_ROOT="$APP_PARENT/current"
readonly WRAPPER="$APP_ROOT/cron/run_business_reports.sh"
readonly LOCK_DIR="$SANDBOX/var/lib/safeharbor-report-scheduler"
readonly LOCK="$LOCK_DIR/business-reports-deploy.lock"
readonly LOGGER="$SANDBOX/test-bin/logger"

/usr/bin/install -d -o root -g root -m 0755 -- \
    "$SANDBOX/srv" "$SANDBOX/srv/8west" "$SANDBOX/srv/8west/apps" "$SANDBOX/var" "$SANDBOX/var/lib"
/usr/bin/install -d -o root -g "$TEST_RUNTIME_GROUP" -m 2750 -- \
    "$APP_PARENT" "$APP_ROOT" "$APP_ROOT/cron" "$APP_ROOT/config"
/usr/bin/install -d -o root -g "$TEST_RUNTIME_GROUP" -m 0750 -- "$LOCK_DIR" "$SANDBOX/test-bin"
/usr/bin/install -o root -g "$TEST_RUNTIME_GROUP" -m 0640 -- /dev/null "$LOCK"
/usr/bin/install -o root -g "$TEST_RUNTIME_GROUP" -m 0640 -- \
    "$REPO_ROOT/app/cron/run_business_reports.sh" "$WRAPPER"
# A stub runner: exit status, output size and run time come from the environment.
/usr/bin/printf '%s\n' \
    '<?php' \
    '$bytes = (int)(getenv("SAFEHARBOR_TEST_RUNNER_BYTES") ?: 0);' \
    'if ($bytes > 0) { fwrite(STDOUT, str_repeat("x", $bytes)); }' \
    'fwrite(STDOUT, "wrapper-test-runner\n");' \
    '$marker = getenv("SAFEHARBOR_TEST_MARKER") ?: "";' \
    'if ($marker !== "") { file_put_contents($marker, "report\n", FILE_APPEND); }' \
    'exit((int)(getenv("SAFEHARBOR_TEST_RUNNER_STATUS") ?: 0));' \
    > "$APP_ROOT/cron/business_reports.php"
# A stub lifecycle child: records its argument, then exits or sleeps as the environment asks.
/usr/bin/printf '%s\n' \
    '<?php' \
    '$marker = getenv("SAFEHARBOR_TEST_MARKER") ?: "";' \
    'if ($marker !== "") { file_put_contents($marker, "lifecycle " . ($argv[1] ?? "-") . "\n", FILE_APPEND); }' \
    'fwrite(STDOUT, "wrapper-test-lifecycle\n");' \
    'sleep((int)(getenv("SAFEHARBOR_TEST_LIFECYCLE_SLEEP") ?: 0));' \
    'exit((int)(getenv("SAFEHARBOR_TEST_LIFECYCLE_STATUS") ?: 0));' \
    > "$APP_ROOT/cron/managed_customer_lifecycle.php"
/usr/bin/printf '%s\n' '<?php return [];' > "$APP_ROOT/config/config.php"
/usr/bin/chown "root:$TEST_RUNTIME_GROUP" -- "$APP_ROOT/cron/business_reports.php" \
    "$APP_ROOT/cron/managed_customer_lifecycle.php" "$APP_ROOT/config/config.php"
/usr/bin/chmod 0640 -- "$APP_ROOT/cron/business_reports.php" \
    "$APP_ROOT/cron/managed_customer_lifecycle.php" "$APP_ROOT/config/config.php"
readonly MARKER="$(/usr/bin/mktemp /tmp/safeharbor-scheduler-marker.XXXXXXXX)"
/usr/bin/chown "$TEST_RUNTIME_USER" -- "$MARKER"
# A stub logger that can fail in each of the ways a real logger can.
/usr/bin/printf '%s\n' \
    '#!/usr/bin/env bash' \
    'priority=""' \
    'while [[ "$#" -gt 0 ]]; do' \
    '  if [[ "$1" == --priority && "$#" -ge 2 ]]; then priority="$2"; shift 2; continue; fi' \
    '  shift' \
    'done' \
    'if [[ "$priority" == user.err && "${SAFEHARBOR_TEST_LOGGER_FAIL_ERROR:-0}" == 1 ]]; then exit 91; fi' \
    'if [[ "$priority" == user.notice ]]; then' \
    '  [[ "${SAFEHARBOR_TEST_LOGGER_CLOSE_EARLY:-0}" == 1 ]] && exit 93' \
    '  if [[ "${SAFEHARBOR_TEST_LOGGER_CLOSE_MIDSTREAM:-0}" == 1 ]]; then /usr/bin/head -c 4096 > /dev/null; exit 94; fi' \
    '  /usr/bin/cat > /dev/null' \
    '  [[ "${SAFEHARBOR_TEST_LOGGER_FAIL_NOTICE:-0}" == 1 ]] && exit 92' \
    'fi' \
    'exit 0' \
    > "$LOGGER"
/usr/bin/chown "root:$TEST_RUNTIME_GROUP" -- "$LOGGER"
/usr/bin/chmod 0750 -- "$LOGGER"

passes=0
check() { # <label> <expected status> <environment assignments...>
    local label="$1" expected="$2" status
    shift 2
    set +e
    /usr/sbin/runuser -u "$TEST_RUNTIME_USER" -- /usr/bin/env \
        SAFEHARBOR_SCHEDULER_TEST_ROOT="$SANDBOX" \
        SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER=root \
        SAFEHARBOR_SCHEDULER_TEST_RUNTIME_USER="$TEST_RUNTIME_USER" \
        SAFEHARBOR_SCHEDULER_TEST_RUNTIME_GROUP="$TEST_RUNTIME_GROUP" \
        SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN="$LOGGER" \
        "$@" /usr/bin/bash "$WRAPPER" > /dev/null 2>&1
    status=$?
    set -e
    if [[ "$status" -eq "$expected" ]]; then
        passes=$((passes + 1))
        printf 'ok %d - %s\n' "$passes" "$label"
    else
        printf 'not ok %d - %s (status %s, expected %s)\n' "$((passes + 1))" "$label" "$status" "$expected" >&2
        exit 1
    fi
}

check 'a successful run exits 0' 0 SAFEHARBOR_TEST_RUNNER_STATUS=0
check 'a failed run keeps the PHP status' 42 SAFEHARBOR_TEST_RUNNER_STATUS=42
check 'final logger failure preserves the PHP runner status' 42 \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_LOGGER_FAIL_ERROR=1
check 'simultaneous PHP and main logger failure preserves PHP status' 42 \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_LOGGER_FAIL_NOTICE=1
check 'early-closing logger cannot contaminate PHP status' 42 \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_RUNNER_BYTES=1048576 SAFEHARBOR_TEST_LOGGER_CLOSE_EARLY=1
check 'mid-stream logger failure cannot contaminate PHP status' 42 \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_RUNNER_BYTES=1048576 SAFEHARBOR_TEST_LOGGER_CLOSE_MIDSTREAM=1
check 'a logger failure after a successful run is reported as 74' 74 \
    SAFEHARBOR_TEST_RUNNER_STATUS=0 SAFEHARBOR_TEST_LOGGER_FAIL_NOTICE=1

# The bounded lifecycle child (A82) runs after the reports with --scheduled; it never changes the
# report exit status, whether it succeeds, refuses, times out or is missing.
# The marker belongs to the runtime user in sticky /tmp, where fs.protected_regular refuses root an
# O_CREAT open of it. Clear the existing file without O_CREAT instead (A143).
clear_marker() { /usr/bin/truncate --no-create --size=0 -- "$MARKER"; }
clear_marker
check 'reports then the scheduled lifecycle child both run' 0 \
    SAFEHARBOR_TEST_RUNNER_STATUS=0 SAFEHARBOR_TEST_MARKER="$MARKER"
[[ "$(/usr/bin/cat -- "$MARKER")" == $'report\nlifecycle --scheduled' ]] || {
    printf 'not ok - the wrapper did not run reports first and then the lifecycle child with --scheduled\n' >&2
    exit 1
}
check 'a refusing lifecycle child leaves a successful report run at 0' 0 \
    SAFEHARBOR_TEST_RUNNER_STATUS=0 SAFEHARBOR_TEST_LIFECYCLE_STATUS=1
check 'a refusing lifecycle child leaves a failed report run at its status' 42 \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_LIFECYCLE_STATUS=1
check 'a timed-out lifecycle child is stopped and the report status stays' 0 \
    SAFEHARBOR_TEST_RUNNER_STATUS=0 SAFEHARBOR_SCHEDULER_TEST_LIFECYCLE_SECONDS=1 SAFEHARBOR_TEST_LIFECYCLE_SLEEP=5
check 'a lifecycle logger failure leaves the report status' 42 \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_LIFECYCLE_STATUS=1 SAFEHARBOR_TEST_LOGGER_FAIL_ERROR=1
check 'an out-of-range lifecycle bound is refused before anything runs' 64 \
    SAFEHARBOR_TEST_RUNNER_STATUS=0 SAFEHARBOR_SCHEDULER_TEST_LIFECYCLE_SECONDS=46
/usr/bin/chmod 0660 -- "$APP_ROOT/cron/managed_customer_lifecycle.php"
clear_marker
check 'a runtime-writable lifecycle child is skipped and reports still run' 0 \
    SAFEHARBOR_TEST_RUNNER_STATUS=0 SAFEHARBOR_TEST_MARKER="$MARKER"
[[ "$(/usr/bin/cat -- "$MARKER")" == 'report' ]] || {
    printf 'not ok - an unsafe lifecycle child ran or the reports did not\n' >&2
    exit 1
}
/usr/bin/chmod 0640 -- "$APP_ROOT/cron/managed_customer_lifecycle.php"
/usr/bin/mv -- "$APP_ROOT/cron/managed_customer_lifecycle.php" "$SANDBOX/lifecycle.parked"
check 'a missing lifecycle stage is skipped and the report status stays' 42 \
    SAFEHARBOR_TEST_RUNNER_STATUS=42
/usr/bin/mv -- "$SANDBOX/lifecycle.parked" "$APP_ROOT/cron/managed_customer_lifecycle.php"

# A deploy holds the exclusive side of the lock; a report run must skip instead of reading code.
exec 8< "$LOCK"
/usr/bin/flock --exclusive --nonblock 8
check 'a run skips while a deploy holds the lock' 75 SAFEHARBOR_TEST_RUNNER_STATUS=0
/usr/bin/flock --unlock 8
exec 8<&-
check 'the run goes ahead once the deploy lets go' 0 SAFEHARBOR_TEST_RUNNER_STATUS=0

# Code the runtime user could rewrite must never run.
/usr/bin/chmod 0660 -- "$WRAPPER"
check 'a runtime-writable wrapper is refused' 77 SAFEHARBOR_TEST_RUNNER_STATUS=0
/usr/bin/chmod 0640 -- "$WRAPPER"
/usr/bin/chmod 0660 -- "$APP_ROOT/cron/business_reports.php"
check 'a runtime-writable runner is refused' 77 SAFEHARBOR_TEST_RUNNER_STATUS=0
/usr/bin/chmod 0640 -- "$APP_ROOT/cron/business_reports.php"
/usr/bin/chmod 0644 -- "$LOCK"
check 'a deploy lock with the wrong mode is refused' 77 SAFEHARBOR_TEST_RUNNER_STATUS=0
/usr/bin/chmod 0640 -- "$LOCK"

printf 'PASS: %d business-report wrapper checks\n' "$passes"
