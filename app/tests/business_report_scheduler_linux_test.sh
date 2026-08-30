#!/usr/bin/env bash
# Executed Linux lifecycle tests for the Safeharbor report scheduler controls.
set -euo pipefail
umask 077

if [[ "$(uname -s)" != 'Linux' ]]; then
    printf 'SKIP: business-report scheduler behavior tests require Linux.\n'
    exit 0
fi
[[ "${EUID}" -eq 0 ]] || {
    printf 'FAIL: run this isolated test with sudo/root.\n' >&2
    exit 77
}
/usr/bin/id www-data >/dev/null 2>&1 || {
    printf 'FAIL: www-data test identity is unavailable.\n' >&2
    exit 67
}

readonly REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
readonly MANAGER_SOURCE="$REPO_ROOT/deploy/manage-business-report-scheduler.sh"
readonly TEMPLATE_SOURCE="$REPO_ROOT/deploy/safeharbor-business-reports.cron"
readonly HASHER_SOURCE="$REPO_ROOT/deploy/hash-safeharbor-app-artifact.sh"
readonly WRAPPER_SOURCE="$REPO_ROOT/app/cron/run_business_reports.sh"
readonly DEPLOYER_SOURCE="$REPO_ROOT/deploy/remote-install-safeharbor-app.sh"
readonly SANDBOX="$(/usr/bin/mktemp -d /tmp/safeharbor-scheduler-test.XXXXXXXX)"
background_pids=()

cleanup() {
    local pid
    for pid in "${background_pids[@]}"; do
        /usr/bin/kill "$pid" 2>/dev/null || true
    done
    case "$SANDBOX" in
        /tmp/safeharbor-scheduler-test.*) /usr/bin/rm -rf -- "$SANDBOX" ;;
        *) printf 'REFUSED unsafe cleanup path: %s\n' "$SANDBOX" >&2 ;;
    esac
}
trap cleanup EXIT
/usr/bin/chown root:root -- "$SANDBOX"
/usr/bin/chmod 0755 -- "$SANDBOX"

readonly APP_PARENT="$SANDBOX/srv/8west/apps/safeharbor"
readonly APP_ROOT="$APP_PARENT/current"
readonly CONTROL_SOURCE="$SANDBOX/control-source"
readonly EVIDENCE_SOURCE="$SANDBOX/activation-evidence"
readonly CRON_DIR="$SANDBOX/etc/cron.d"
readonly CRON_ACTIVE="$CRON_DIR/safeharbor-business-reports"
readonly CRON_DISABLED="$CRON_DIR/safeharbor-business-reports.disabled"
readonly ACTIVATION_RECORD="$SANDBOX/etc/safeharbor/business-report-scheduler.activation"
readonly DEPLOY_LOCK_DIR="$SANDBOX/var/lib/safeharbor-report-scheduler"
readonly DEPLOY_LOCK="$DEPLOY_LOCK_DIR/business-reports-deploy.lock"
readonly RELEASE_RECORD_DIR="$DEPLOY_LOCK_DIR/releases"
readonly CURRENT_RELEASE_RECORD="$RELEASE_RECORD_DIR/current-app-artifact.manifest"
STAGED_HASHER=''
readonly RELEASE_A='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
readonly RELEASE_C='cccccccccccccccccccccccccccccccccccccccc'
readonly TEST_ENV=(
    SAFEHARBOR_SCHEDULER_TEST_ROOT="$SANDBOX"
    SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER=root
)

passes=0
check() {
    local label="$1"
    shift
    if "$@"; then
        passes=$((passes + 1))
        printf 'ok %d - %s\n' "$passes" "$label"
    else
        printf 'not ok %d - %s\n' "$((passes + 1))" "$label" >&2
        exit 1
    fi
}

run_manager() {
    /usr/bin/env "${TEST_ENV[@]}" /usr/bin/bash \
        "$CONTROL_SOURCE/manage-business-report-scheduler.sh" "$@"
}

expect_manager_failure() {
    local label="$1"
    local needle="$2"
    shift 2
    local output status
    set +e
    output="$(run_manager "$@" 2>&1)"
    status=$?
    set -e
    [[ "$status" -ne 0 && "$output" == *"$needle"* ]] || {
        printf 'unexpected manager result for %s (status %s): %s\n' \
            "$label" "$status" "$output" >&2
        exit 1
    }
    passes=$((passes + 1))
    printf 'ok %d - %s\n' "$passes" "$label"
}

run_deployer() {
    local release_sha="$1"
    local source_artifact_sha="$2"
    /usr/bin/env "${TEST_ENV[@]}" /usr/bin/bash "$DEPLOYER_SOURCE" \
        --release-sha "$release_sha" \
        --artifact-hasher "$STAGED_HASHER" \
        --expect-hasher-sha256 "$hasher_sha" \
        --expect-source-artifact-sha256 "$source_artifact_sha"
}

expect_deployer_failure() {
    local label="$1"
    local needle="$2"
    local payload="$3"
    local release_sha="$4"
    local source_artifact_sha="$5"
    local output status
    set +e
    output="$(run_deployer "$release_sha" "$source_artifact_sha" < "$payload" 2>&1)"
    status=$?
    set -e
    [[ "$status" -ne 0 && "$output" == *"$needle"* ]] || {
        printf 'unexpected deploy result for %s (status %s): %s\n' \
            "$label" "$status" "$output" >&2
        exit 1
    }
    passes=$((passes + 1))
    printf 'ok %d - %s\n' "$passes" "$label"
}

/usr/bin/install -d -o root -g root -m 0755 -- \
    "$SANDBOX/etc" "$CRON_DIR" "$SANDBOX/srv" "$SANDBOX/srv/8west" \
    "$SANDBOX/srv/8west/apps" "$SANDBOX/run" "$SANDBOX/var" "$SANDBOX/var/lib"
/usr/bin/install -d -o root -g root -m 0700 -- "$SANDBOX/run/safeharbor-deploy"
/usr/bin/install -d -o root -g www-data -m 2750 -- \
    "$APP_PARENT" "$APP_ROOT" "$APP_ROOT/cron" "$APP_ROOT/config" \
    "$APP_ROOT/lib" "$APP_ROOT/public"
/usr/bin/install -d -o root -g www-data -m 0750 -- "$DEPLOY_LOCK_DIR"
/usr/bin/install -o root -g www-data -m 0640 -- /dev/null "$DEPLOY_LOCK"

/usr/bin/install -o root -g www-data -m 0640 -- "$WRAPPER_SOURCE" \
    "$APP_ROOT/cron/run_business_reports.sh"
/usr/bin/printf '%s\n' \
    '<?php' \
    '$sleep = (int)(getenv("SAFEHARBOR_TEST_RUNNER_SLEEP") ?: 0);' \
    '$bytes = (int)(getenv("SAFEHARBOR_TEST_RUNNER_BYTES") ?: 0);' \
    'if ($sleep > 0) { sleep($sleep); }' \
    'if ($bytes > 0) { fwrite(STDOUT, str_repeat("x", $bytes)); }' \
    'fwrite(STDOUT, "scheduler-test-runner\\n");' \
    'exit((int)(getenv("SAFEHARBOR_TEST_RUNNER_STATUS") ?: 0));' \
    > "$APP_ROOT/cron/business_reports.php"
/usr/bin/printf '%s\n' '<?php return [];' > "$APP_ROOT/config/config.php"
/usr/bin/printf '%s\n' '<?php $asset = "?v=TEST";' > "$APP_ROOT/lib/render.php"
/usr/bin/printf '%s\n' '<?php $asset = "?v=TEST";' > "$APP_ROOT/lib/westy.php"
/usr/bin/printf '%s\n' '<?php function loaded_report_library(): bool { return true; }' \
    > "$APP_ROOT/lib/business_reports.php"
/usr/bin/printf '%s\n' '<?php $asset = "?v=TEST";' > "$APP_ROOT/public/login.php"
/usr/bin/chown -R root:www-data -- "$APP_PARENT"
/usr/bin/find "$APP_PARENT" -type d -exec /usr/bin/chmod 2750 -- {} +
/usr/bin/find "$APP_PARENT" -type f -exec /usr/bin/chmod 0640 -- {} +

/usr/bin/install -d -o root -g root -m 0700 -- "$CONTROL_SOURCE"
/usr/bin/install -o root -g root -m 0600 -- "$MANAGER_SOURCE" \
    "$CONTROL_SOURCE/manage-business-report-scheduler.sh"
/usr/bin/install -o root -g root -m 0600 -- "$TEMPLATE_SOURCE" \
    "$CONTROL_SOURCE/safeharbor-business-reports.cron"
/usr/bin/install -o root -g root -m 0600 -- "$HASHER_SOURCE" \
    "$CONTROL_SOURCE/hash-safeharbor-app-artifact.sh"
hasher_sha="$(/usr/bin/sha256sum "$CONTROL_SOURCE/hash-safeharbor-app-artifact.sh")"
hasher_sha="${hasher_sha%% *}"
STAGED_HASHER="$SANDBOX/run/safeharbor-deploy/hash-safeharbor-app-artifact.$hasher_sha.test.sh"
/usr/bin/install -o root -g root -m 0600 -- "$HASHER_SOURCE" "$STAGED_HASHER"

/usr/bin/install -d -o root -g root -m 0700 -- "$EVIDENCE_SOURCE"
/usr/bin/install -d -o root -g root -m 0700 -- "$RELEASE_RECORD_DIR"

kv_value() {
    local key="$1"
    local file="$2"
    /usr/bin/sed -n "s/^${key}=//p" "$file"
}

write_release_record() {
    local release_sha="$1"
    local source_artifact_sha="$2"
    local deployed_artifact_sha unique_record
    deployed_artifact_sha="$(/usr/bin/bash "$STAGED_HASHER" "$APP_ROOT")"
    unique_record="$RELEASE_RECORD_DIR/app-artifact.$release_sha.$deployed_artifact_sha.manifest"
    /usr/bin/printf '%s\n' \
        'schema=safeharbor-deployed-app-artifact-v1' \
        "release_sha=$release_sha" \
        "source_artifact_sha256=$source_artifact_sha" \
        "deployed_artifact_sha256=$deployed_artifact_sha" \
        "hasher_sha256=$hasher_sha" \
        > "$unique_record"
    /usr/bin/chown root:root -- "$unique_record"
    /usr/bin/chmod 0600 -- "$unique_record"
    /usr/bin/install -o root -g root -m 0600 -- \
        "$unique_record" "$CURRENT_RELEASE_RECORD"
}

write_control_bundle() {
    local manager_sha cron_sha wrapper_sha runner_sha marker_sha
    local release_sha source_artifact_sha deployed_artifact_sha
    manager_sha="$(/usr/bin/sha256sum "$CONTROL_SOURCE/manage-business-report-scheduler.sh")"
    manager_sha="${manager_sha%% *}"
    cron_sha="$(/usr/bin/sha256sum "$CONTROL_SOURCE/safeharbor-business-reports.cron")"
    cron_sha="${cron_sha%% *}"
    wrapper_sha="$(/usr/bin/sha256sum "$APP_ROOT/cron/run_business_reports.sh")"
    wrapper_sha="${wrapper_sha%% *}"
    runner_sha="$(/usr/bin/sha256sum "$APP_ROOT/cron/business_reports.php")"
    runner_sha="${runner_sha%% *}"
    marker_sha="$(/usr/bin/sha256sum "$CURRENT_RELEASE_RECORD")"
    marker_sha="${marker_sha%% *}"
    release_sha="$(kv_value release_sha "$CURRENT_RELEASE_RECORD")"
    source_artifact_sha="$(kv_value source_artifact_sha256 "$CURRENT_RELEASE_RECORD")"
    deployed_artifact_sha="$(kv_value deployed_artifact_sha256 "$CURRENT_RELEASE_RECORD")"
    /usr/bin/printf '%s\n' \
        'schema=safeharbor-business-report-scheduler-bundle-v2' \
        "release_sha=$release_sha" \
        "manager_sha256=$manager_sha" \
        "cron_sha256=$cron_sha" \
        "hasher_sha256=$hasher_sha" \
        "source_artifact_sha256=$source_artifact_sha" \
        "deployed_artifact_sha256=$deployed_artifact_sha" \
        "release_marker_sha256=$marker_sha" \
        "wrapper_sha256=$wrapper_sha" \
        "runner_sha256=$runner_sha" \
        > "$CONTROL_SOURCE/scheduler-bundle.manifest"
    /usr/bin/chown root:root -- "$CONTROL_SOURCE/scheduler-bundle.manifest"
    /usr/bin/chmod 0600 -- "$CONTROL_SOURCE/scheduler-bundle.manifest"
}

write_activation_evidence() {
    local manifest_sha config_sha release_sha deployed_artifact_sha marker_sha
    manifest_sha="$(/usr/bin/sha256sum "$CONTROL_SOURCE/scheduler-bundle.manifest")"
    manifest_sha="${manifest_sha%% *}"
    config_sha="$(/usr/bin/sha256sum "$APP_ROOT/config/config.php")"
    config_sha="${config_sha%% *}"
    release_sha="$(kv_value release_sha "$CONTROL_SOURCE/scheduler-bundle.manifest")"
    deployed_artifact_sha="$(kv_value deployed_artifact_sha256 "$CONTROL_SOURCE/scheduler-bundle.manifest")"
    marker_sha="$(kv_value release_marker_sha256 "$CONTROL_SOURCE/scheduler-bundle.manifest")"
    /usr/bin/printf '%s\n' \
        'schema=safeharbor-business-report-scheduler-activation-v2' \
        "release_sha=$release_sha" \
        "bundle_manifest_sha256=$manifest_sha" \
        "deployed_artifact_sha256=$deployed_artifact_sha" \
        "release_marker_sha256=$marker_sha" \
        "protected_config_sha256=$config_sha" \
        'sender=reports@8westit.com' \
        'tenant_slug=lifestyle-test' \
        'client_id=14' \
        'schedule_key=lifestyle-weekly-canary' \
        'recipient=frank@example.test' \
        'graph_status=202' \
        'graph_accepted_at=2026-08-29T03:27:01Z' \
        'recipient_confirmation=confirmed' \
        'recipient_confirmed_at=2026-08-29T03:32:00Z' \
        'archive_sha256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' \
        'protected_gates_reviewed_at=2026-08-29T03:33:00Z' \
        > "$EVIDENCE_SOURCE/activation.env"
    /usr/bin/chown root:root -- "$EVIDENCE_SOURCE/activation.env"
    /usr/bin/chmod 0600 -- "$EVIDENCE_SOURCE/activation.env"
}

initial_artifact_sha="$(/usr/bin/bash "$STAGED_HASHER" "$APP_ROOT")"
write_release_record "$RELEASE_A" "$initial_artifact_sha"
write_control_bundle
write_activation_evidence

check 'root-owned exact bundle and deployment pass preflight' run_manager preflight

# Keep the lock in the flock parent only. Otherwise the command child inherits
# the descriptor, so killing the recorded holder can leave an orphaned sleep
# holding the lock and make the next independent assertion fail spuriously.
/usr/bin/flock --close --exclusive "$DEPLOY_LOCK" -c '/usr/bin/sleep 30' &
deploy_lock_holder=$!
background_pids+=("$deploy_lock_holder")
/usr/bin/sleep 1
expect_manager_failure 'preflight refuses while a deployment owns the exclusive lock' \
    'deployment-in-progress' preflight
/usr/bin/kill "$deploy_lock_holder"
wait "$deploy_lock_holder" 2>/dev/null || true

/usr/bin/chmod 0770 -- "$CONTROL_SOURCE"
expect_manager_failure 'group-writable control directory is rejected' \
    'control-source-directory-owner-or-mode-mismatch' preflight
/usr/bin/chmod 0700 -- "$CONTROL_SOURCE"

/usr/bin/cp -- "$CONTROL_SOURCE/safeharbor-business-reports.cron" "$SANDBOX/cron.backup"
/usr/bin/printf '%s\n' '# tamper' >> "$CONTROL_SOURCE/safeharbor-business-reports.cron"
expect_manager_failure 'digest mismatch rejects root-owned source tampering' \
    'reviewed-cron-source-digest-mismatch' preflight
/usr/bin/install -o root -g root -m 0600 -- "$SANDBOX/cron.backup" \
    "$CONTROL_SOURCE/safeharbor-business-reports.cron"

/usr/bin/chown www-data:www-data -- "$APP_ROOT/cron/business_reports.php"
expect_manager_failure 'runtime-owned deployment file is rejected' \
    'deployment-file-owner-or-mode-mismatch' preflight
/usr/bin/chown root:www-data -- "$APP_ROOT/cron/business_reports.php"

/usr/bin/chown www-data:www-data -- "$APP_ROOT/cron"
expect_manager_failure 'runtime-owned deployment directory is rejected' \
    'deployment-directory-owner-or-mode-mismatch' preflight
/usr/bin/chown root:www-data -- "$APP_ROOT/cron"

/usr/bin/chmod 0660 -- "$APP_ROOT/cron/run_business_reports.sh"
expect_manager_failure 'writable deployment file is rejected' \
    'report-wrapper-owner-or-mode-mismatch' preflight
/usr/bin/chmod 0640 -- "$APP_ROOT/cron/run_business_reports.sh"

check 'scheduler installs only at ignored dot-containing name' run_manager install-disabled
check 'disabled scheduler file name contains a cron-ignored dot' \
    /usr/bin/test "${CRON_DISABLED##*/}" = 'safeharbor-business-reports.disabled'
check 'disabled state verifies with no live activation record' run_manager verify disabled

/usr/bin/install -o root -g www-data -m 0640 -- \
    "$APP_ROOT/lib/business_reports.php" "$SANDBOX/business_reports.php.backup"
/usr/bin/printf '%s\n' '// root-owned loaded-library tamper' \
    >> "$APP_ROOT/lib/business_reports.php"
check 'loaded-library tamper preserves reviewed owner and mode' \
    /usr/bin/test "$(/usr/bin/stat -c '%U:%G:%a' "$APP_ROOT/lib/business_reports.php")" = 'root:www-data:640'
expect_manager_failure 'preflight rejects loaded-library content drift' \
    'deployed-artifact-digest-mismatch' preflight
expect_manager_failure 'enable rejects loaded-library content drift' \
    'deployed-artifact-digest-mismatch' enable \
    --activation-evidence "$EVIDENCE_SOURCE/activation.env" \
    --expect-tenant-slug lifestyle-test \
    --expect-client-id 14 \
    --expect-schedule-key lifestyle-weekly-canary \
    --expect-recipient frank@example.test
/usr/bin/install -o root -g www-data -m 0640 -- \
    "$SANDBOX/business_reports.php.backup" "$APP_ROOT/lib/business_reports.php"
check 'restored complete artifact passes preflight' run_manager preflight

/usr/bin/printf '%s\n' '<?php return ["changed" => true];' > "$APP_ROOT/config/config.php"
/usr/bin/chown root:www-data -- "$APP_ROOT/config/config.php"
/usr/bin/chmod 0640 -- "$APP_ROOT/config/config.php"
expect_manager_failure 'activation rejects protected-config digest drift' \
    'activation-config-digest-mismatch' enable \
    --activation-evidence "$EVIDENCE_SOURCE/activation.env" \
    --expect-tenant-slug lifestyle-test \
    --expect-client-id 14 \
    --expect-schedule-key lifestyle-weekly-canary \
    --expect-recipient frank@example.test
/usr/bin/printf '%s\n' '<?php return [];' > "$APP_ROOT/config/config.php"
/usr/bin/chown root:www-data -- "$APP_ROOT/config/config.php"
/usr/bin/chmod 0640 -- "$APP_ROOT/config/config.php"

expect_manager_failure 'enable rejects a mismatched explicit tenant expectation' \
    'activation-tenant-expectation-mismatch' enable \
    --activation-evidence "$EVIDENCE_SOURCE/activation.env" \
    --expect-tenant-slug wrong-tenant \
    --expect-client-id 14 \
    --expect-schedule-key lifestyle-weekly-canary \
    --expect-recipient frank@example.test
check 'failed activation leaves cron disabled' /usr/bin/test ! -e "$CRON_ACTIVE"

check 'exact config-bound canary evidence enables one scheduler' run_manager enable \
    --activation-evidence "$EVIDENCE_SOURCE/activation.env" \
    --expect-tenant-slug lifestyle-test \
    --expect-client-id 14 \
    --expect-schedule-key lifestyle-weekly-canary \
    --expect-recipient frank@example.test
check 'active state revalidates runtime config and evidence' run_manager verify active

readonly PAYLOAD_ROOT="$SANDBOX/payload-source"
/usr/bin/install -d -o root -g root -m 0755 -- \
    "$PAYLOAD_ROOT/app/cron" "$PAYLOAD_ROOT/app/config" \
    "$PAYLOAD_ROOT/app/lib" "$PAYLOAD_ROOT/app/public"
/usr/bin/install -o root -g root -m 0644 -- \
    "$APP_ROOT/cron/run_business_reports.sh" "$PAYLOAD_ROOT/app/cron/run_business_reports.sh"
/usr/bin/install -o root -g root -m 0644 -- \
    "$APP_ROOT/cron/business_reports.php" "$PAYLOAD_ROOT/app/cron/business_reports.php"
/usr/bin/install -o root -g root -m 0644 -- \
    "$APP_ROOT/lib/render.php" "$PAYLOAD_ROOT/app/lib/render.php"
/usr/bin/install -o root -g root -m 0644 -- \
    "$APP_ROOT/lib/westy.php" "$PAYLOAD_ROOT/app/lib/westy.php"
/usr/bin/install -o root -g root -m 0644 -- \
    "$APP_ROOT/lib/business_reports.php" "$PAYLOAD_ROOT/app/lib/business_reports.php"
/usr/bin/install -o root -g root -m 0644 -- \
    "$APP_ROOT/public/login.php" "$PAYLOAD_ROOT/app/public/login.php"
source_artifact_a="$(/usr/bin/bash "$STAGED_HASHER" "$PAYLOAD_ROOT/app")"
/usr/bin/tar -czf "$SANDBOX/app.tar.gz" -C "$PAYLOAD_ROOT" app
expect_deployer_failure 'non-atomic deploy refuses while scheduler is active' \
    'active-report-scheduler-present' "$SANDBOX/app.tar.gz" \
    "$RELEASE_A" "$source_artifact_a"

# Reproduce the dangerous both-path drift, then prove disable moves the active
# entry regardless and does not terminate an already-running lock holder.
/usr/bin/install -o root -g root -m 0644 -- "$TEMPLATE_SOURCE" "$CRON_DISABLED"
/usr/bin/flock --close --shared "$DEPLOY_LOCK" -c '/usr/bin/sleep 30' &
lock_holder=$!
background_pids+=("$lock_holder")
/usr/bin/sleep 1
check 'test in-flight report lock is alive before disable' /usr/bin/kill -0 "$lock_holder"
disable_output="$(run_manager disable)"
[[ "$disable_output" == *'SCHEDULER_IN_FLIGHT=not-stopped-check-deploy-lock'* ]] || {
    printf 'disable did not report its in-flight boundary: %s\n' "$disable_output" >&2
    exit 1
}
passes=$((passes + 1))
printf 'ok %d - emergency disable documents in-flight boundary\n' "$passes"
check 'both-path emergency disable always removes active cron' /usr/bin/test ! -e "$CRON_ACTIVE"
check 'emergency disable does not kill the in-flight holder' /usr/bin/kill -0 "$lock_holder"
/usr/bin/kill "$lock_holder"
wait "$lock_holder" 2>/dev/null || true
check 'active cron evidence is preserved under a unique root-only name' \
    /usr/bin/test "$(/usr/bin/find "$CRON_DIR" -name 'safeharbor-business-reports.quarantine.*' | /usr/bin/wc -l)" -eq 1
check 'activation evidence is preserved under a unique root-only name' \
    /usr/bin/test "$(/usr/bin/find "$SANDBOX/etc/safeharbor" -name 'business-report-scheduler.activation.preserved.*' | /usr/bin/wc -l)" -eq 1
check 'stopped state verifies after emergency disable' run_manager verify stopped
check 'pre-existing exact disabled copy still verifies' run_manager verify disabled

/usr/sbin/runuser -u www-data -- /usr/bin/env SAFEHARBOR_TEST_RUNNER_SLEEP=30 \
    /usr/bin/php "$APP_ROOT/cron/business_reports.php" >/dev/null 2>&1 &
legacy_runner=$!
background_pids+=("$legacy_runner")
/usr/bin/sleep 1
expect_deployer_failure 'deploy refuses a pre-locking legacy report process' \
    'legacy-report-runner-in-flight' "$SANDBOX/app.tar.gz" \
    "$RELEASE_A" "$source_artifact_a"
/usr/bin/kill "$legacy_runner"
wait "$legacy_runner" 2>/dev/null || true

/usr/bin/flock --close --shared "$DEPLOY_LOCK" -c '/usr/bin/sleep 30' &
lock_holder=$!
background_pids+=("$lock_holder")
/usr/bin/sleep 1
expect_deployer_failure 'deploy refuses while a report run still holds the shared lock' \
    'report-runner-in-flight' "$SANDBOX/app.tar.gz" \
    "$RELEASE_A" "$source_artifact_a"
/usr/bin/kill "$lock_holder"
wait "$lock_holder" 2>/dev/null || true

live_before_bad_payload="$(/usr/bin/bash "$STAGED_HASHER" "$APP_ROOT")"
expect_deployer_failure 'wrong source digest is rejected before live extraction' \
    'staged-source-artifact-digest-mismatch' "$SANDBOX/app.tar.gz" \
    "$RELEASE_A" 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd'
live_after_bad_payload="$(/usr/bin/bash "$STAGED_HASHER" "$APP_ROOT")"
check 'staged source rejection leaves the live artifact unchanged' \
    /usr/bin/test "$live_after_bad_payload" = "$live_before_bad_payload"

config_before="$(/usr/bin/sha256sum "$APP_ROOT/config/config.php")"
config_before="${config_before%% *}"
deploy_output="$(run_deployer "$RELEASE_A" "$source_artifact_a" < "$SANDBOX/app.tar.gz")"
[[ "$deploy_output" == *'SAFEHARBOR_DEPLOY=installed-under-exclusive-report-lock'* ]] || {
    printf 'unexpected successful deploy output: %s\n' "$deploy_output" >&2
    exit 1
}
passes=$((passes + 1))
printf 'ok %d - disabled scheduler allows exclusive locked deploy\n' "$passes"
config_after="$(/usr/bin/sha256sum "$APP_ROOT/config/config.php")"
config_after="${config_after%% *}"
check 'locked deploy preserves protected config bytes' /usr/bin/test "$config_after" = "$config_before"
expect_manager_failure 'locked deploy invalidates the prior artifact manifest' \
    'release-marker-digest-mismatch' preflight
write_control_bundle
write_activation_evidence
check 'postdeploy artifact-bound bundle and ownership pass preflight' run_manager preflight

/usr/bin/install -d -o root -g www-data -m 2750 -- "$SANDBOX/test-bin"
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
    '  if [[ "${SAFEHARBOR_TEST_LOGGER_CLOSE_MIDSTREAM:-0}" == 1 ]]; then /usr/bin/head -c 4096 >/dev/null; exit 94; fi' \
    '  /usr/bin/cat >/dev/null' \
    '  [[ "${SAFEHARBOR_TEST_LOGGER_FAIL_NOTICE:-0}" == 1 ]] && exit 92' \
    'fi' \
    'exit 0' \
    > "$SANDBOX/test-bin/logger"
/usr/bin/chown root:www-data -- "$SANDBOX/test-bin/logger"
/usr/bin/chmod 0750 -- "$SANDBOX/test-bin/logger"

set +e
/usr/sbin/runuser -u www-data -- /usr/bin/env "${TEST_ENV[@]}" \
    SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN="$SANDBOX/test-bin/logger" \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_LOGGER_FAIL_ERROR=1 \
    /usr/bin/bash "$APP_ROOT/cron/run_business_reports.sh" >/dev/null 2>&1
wrapper_status=$?
set -e
check 'final logger failure preserves the PHP runner status' /usr/bin/test "$wrapper_status" -eq 42

set +e
/usr/sbin/runuser -u www-data -- /usr/bin/env "${TEST_ENV[@]}" \
    SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN="$SANDBOX/test-bin/logger" \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_LOGGER_FAIL_NOTICE=1 \
    /usr/bin/bash "$APP_ROOT/cron/run_business_reports.sh" >/dev/null 2>&1
wrapper_status=$?
set -e
check 'simultaneous PHP and main logger failure preserves PHP status' \
    /usr/bin/test "$wrapper_status" -eq 42

set +e
/usr/sbin/runuser -u www-data -- /usr/bin/env "${TEST_ENV[@]}" \
    SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN="$SANDBOX/test-bin/logger" \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_RUNNER_BYTES=1048576 \
    SAFEHARBOR_TEST_LOGGER_CLOSE_EARLY=1 \
    /usr/bin/bash "$APP_ROOT/cron/run_business_reports.sh" >/dev/null 2>&1
wrapper_status=$?
set -e
check 'early-closing logger cannot contaminate PHP status' \
    /usr/bin/test "$wrapper_status" -eq 42

set +e
/usr/sbin/runuser -u www-data -- /usr/bin/env "${TEST_ENV[@]}" \
    SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN="$SANDBOX/test-bin/logger" \
    SAFEHARBOR_TEST_RUNNER_STATUS=42 SAFEHARBOR_TEST_RUNNER_BYTES=1048576 \
    SAFEHARBOR_TEST_LOGGER_CLOSE_MIDSTREAM=1 \
    /usr/bin/bash "$APP_ROOT/cron/run_business_reports.sh" >/dev/null 2>&1
wrapper_status=$?
set -e
check 'mid-stream logger failure cannot contaminate PHP status' \
    /usr/bin/test "$wrapper_status" -eq 42

set +e
/usr/sbin/runuser -u www-data -- /usr/bin/env "${TEST_ENV[@]}" \
    SAFEHARBOR_SCHEDULER_TEST_LOGGER_BIN="$SANDBOX/test-bin/logger" \
    SAFEHARBOR_TEST_RUNNER_STATUS=0 SAFEHARBOR_TEST_LOGGER_FAIL_NOTICE=1 \
    /usr/bin/bash "$APP_ROOT/cron/run_business_reports.sh" >/dev/null 2>&1
wrapper_status=$?
set -e
check 'main pipeline logger failure remains an explicit unavailable status' \
    /usr/bin/test "$wrapper_status" -eq 74

readonly PAYLOAD_C_ROOT="$SANDBOX/payload-c-source"
/usr/bin/install -d -o root -g root -m 0755 -- "$PAYLOAD_C_ROOT"
/usr/bin/cp -a -- "$PAYLOAD_ROOT/app" "$PAYLOAD_C_ROOT/app"
/usr/bin/printf '%s\n' '<?php function release_c_library(): bool { return true; }' \
    >> "$PAYLOAD_C_ROOT/app/lib/business_reports.php"
source_artifact_c="$(/usr/bin/bash "$STAGED_HASHER" "$PAYLOAD_C_ROOT/app")"
/usr/bin/tar -czf "$SANDBOX/app-c.tar.gz" -C "$PAYLOAD_C_ROOT" app
deploy_output="$(run_deployer "$RELEASE_C" "$source_artifact_c" < "$SANDBOX/app-c.tar.gz")"
[[ "$deploy_output" == *'SAFEHARBOR_DEPLOY=installed-under-exclusive-report-lock'* ]] || {
    printf 'unexpected second deploy output: %s\n' "$deploy_output" >&2
    exit 1
}
passes=$((passes + 1))
printf 'ok %d - second disabled deployment advances the immutable release record\n' "$passes"
expect_manager_failure 'old artifact manifest cannot preflight after locked deployment' \
    'release-marker-digest-mismatch' preflight
write_control_bundle
check 'new artifact manifest validates the newly deployed release' run_manager preflight
expect_manager_failure 'old activation evidence cannot reactivate after locked deployment' \
    'activation-release-mismatch' enable \
    --activation-evidence "$EVIDENCE_SOURCE/activation.env" \
    --expect-tenant-slug lifestyle-test \
    --expect-client-id 14 \
    --expect-schedule-key lifestyle-weekly-canary \
    --expect-recipient frank@example.test
check 'stale evidence leaves the scheduler disabled' run_manager verify disabled

/usr/bin/chmod 0644 -- "$CONTROL_SOURCE/safeharbor-business-reports.cron"
expect_manager_failure 'control source ownership drift is rejected before operations' \
    'reviewed-cron-source-owner-or-mode-mismatch' verify stopped
/usr/bin/chmod 0600 -- "$CONTROL_SOURCE/safeharbor-business-reports.cron"

check 'uninstall removes only the exact disabled scheduler file' run_manager uninstall \
    --confirm-remove-exact-scheduler-files
check 'absent state verifies while quarantined evidence remains' run_manager verify absent

printf 'PASS: %d scheduler Linux behavior checks\n' "$passes"
