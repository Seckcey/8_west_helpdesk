#!/usr/bin/env bash
# Install, verify, stop, or remove Safeharbor's one reviewed report cron entry.
# The manager hashes protected config for binding evidence but never prints,
# parses, sources, or otherwise exposes its contents. It never runs a report,
# sends mail, changes application gates, or edits any unrelated cron file.
set -euo pipefail
umask 077

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_SELF="$SCRIPT_DIR/${BASH_SOURCE[0]##*/}"
readonly CRON_SOURCE="$SCRIPT_DIR/safeharbor-business-reports.cron"
readonly ARTIFACT_HASHER="$SCRIPT_DIR/hash-safeharbor-app-artifact.sh"
readonly BUNDLE_MANIFEST="$SCRIPT_DIR/scheduler-bundle.manifest"

readonly TEST_ROOT="${SAFEHARBOR_SCHEDULER_TEST_ROOT:-}"
if [[ -n "$TEST_ROOT" ]]; then
    readonly ROOT_PREFIX="$TEST_ROOT"
    readonly DEPLOY_OWNER="${SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER:-root}"
else
    [[ -z "${SAFEHARBOR_SCHEDULER_TEST_DEPLOY_OWNER:-}" ]] || {
        printf 'SCHEDULER_REFUSED=test-owner-without-test-root\n' >&2
        exit 64
    }
    readonly ROOT_PREFIX=''
    readonly DEPLOY_OWNER='ubuntu'
fi

readonly CRON_ACTIVE="$ROOT_PREFIX/etc/cron.d/safeharbor-business-reports"
# Ubuntu cron ignores /etc/cron.d names containing a dot. This exact name is
# the installed default-off state; tests also assert the naming boundary.
readonly CRON_DISABLED="$ROOT_PREFIX/etc/cron.d/safeharbor-business-reports.disabled"
readonly CONTROL_DIR="$ROOT_PREFIX/etc/safeharbor"
readonly ACTIVATION_RECORD="$CONTROL_DIR/business-report-scheduler.activation"
readonly APP_PARENT="$ROOT_PREFIX/srv/8west/apps/safeharbor"
readonly APP_ROOT="$APP_PARENT/current"
readonly REPORT_RUNNER="$APP_ROOT/cron/business_reports.php"
readonly REPORT_WRAPPER="$APP_ROOT/cron/run_business_reports.sh"
readonly PROTECTED_CONFIG="$APP_ROOT/config/config.php"
readonly DEPLOY_LOCK_DIR="$ROOT_PREFIX/var/lib/safeharbor-report-scheduler"
readonly DEPLOY_LOCK="$DEPLOY_LOCK_DIR/business-reports-deploy.lock"
readonly RELEASE_RECORD_DIR="$DEPLOY_LOCK_DIR/releases"
readonly CURRENT_RELEASE_RECORD="$RELEASE_RECORD_DIR/current-app-artifact.manifest"
readonly RUNTIME_USER='www-data'
readonly RUNTIME_GROUP='www-data'

scheduler_usage() {
    cat >&2 <<'USAGE'
Usage:
  manage-business-report-scheduler.sh preflight
  manage-business-report-scheduler.sh install-disabled
  manage-business-report-scheduler.sh verify active|disabled|stopped|absent
  manage-business-report-scheduler.sh enable \
    --activation-evidence ROOT_ONLY_FILE \
    --expect-tenant-slug TENANT \
    --expect-client-id CLIENT_ID \
    --expect-schedule-key SCHEDULE_KEY \
    --expect-recipient EMAIL
  manage-business-report-scheduler.sh disable
  manage-business-report-scheduler.sh uninstall --confirm-remove-exact-scheduler-files

The source directory must be root:root 0700 and contain only the reviewed
root:root 0600 manager, cron template, complete-artifact hasher, and bundle
manifest. install-disabled does not schedule work. enable binds the exact
reviewed release, immutable deployed-artifact record, protected-config hash,
reports@8westit.com sender canary, and expected tenant/client/schedule/recipient
tuple. disable removes the active cron path but does not kill an in-flight run.
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
        || scheduler_fail "$label-missing-or-symlinked" 72
    [[ "$(/usr/bin/stat -c '%U:%G:%a' -- "$path")" == "$owner:$group:$mode" ]] \
        || scheduler_fail "$label-owner-or-mode-mismatch" 77
}

verify_physical_directory() {
    local path="$1"
    local label="$2"
    [[ -d "$path" && ! -L "$path" \
        && "$(cd -- "$path" && pwd -P)" == "$path" ]] \
        || scheduler_fail "$label-not-exact-physical-directory" 77
}

require_test_root() {
    [[ -n "$TEST_ROOT" ]] || return 0
    [[ "$TEST_ROOT" == /tmp/safeharbor-scheduler-test.* \
        && "$TEST_ROOT" != *'/../'* \
        && "$TEST_ROOT" != *$'\n'* \
        && -d "$TEST_ROOT" \
        && ! -L "$TEST_ROOT" ]] \
        || scheduler_fail 'unsafe-test-root' 64
    [[ "$(/usr/bin/stat -c '%U:%G' -- "$TEST_ROOT")" == 'root:root' ]] \
        || scheduler_fail 'unsafe-test-root-owner' 77
    local mode group_digit other_digit
    mode="$(/usr/bin/stat -c '%a' -- "$TEST_ROOT")"
    group_digit="${mode: -2:1}"
    other_digit="${mode: -1}"
    case "$group_digit$other_digit" in
        *[2367]*) scheduler_fail 'unsafe-test-root-writable' 77 ;;
    esac
}

require_control_source_metadata() {
    verify_metadata "$SCRIPT_DIR" root root 700 'control-source-directory'
    verify_metadata "$SCRIPT_SELF" root root 600 'control-manager'
}

sha256_file() {
    local output
    output="$(/usr/bin/sha256sum -- "$1")" \
        || scheduler_fail "sha256-failed:$1" 74
    printf '%s' "${output%% *}"
}

load_exact_kv_file() {
    local path="$1"
    local destination_name="$2"
    local allowed_csv="$3"
    local label="$4"
    local line key value
    local -a required_keys=()
    local -n destination="$destination_name"

    destination=()
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ -n "$line" && "$line" != *$'\r'* && "$line" == *=* ]] \
            || scheduler_fail "$label-invalid-line" 65
        key="${line%%=*}"
        value="${line#*=}"
        [[ -n "$key" && -n "$value" ]] \
            || scheduler_fail "$label-empty-key-or-value" 65
        case ",$allowed_csv," in
            *",$key,"*) ;;
            *) scheduler_fail "$label-unexpected-key:$key" 65 ;;
        esac
        [[ -z "${destination[$key]+present}" ]] \
            || scheduler_fail "$label-duplicate-key:$key" 65
        destination[$key]="$value"
    done < "$path"

    IFS=',' read -r -a required_keys <<< "$allowed_csv"
    for key in "${required_keys[@]}"; do
        [[ -n "${destination[$key]+present}" ]] \
            || scheduler_fail "$label-missing-key:$key" 65
    done
    [[ "${#destination[@]}" -eq "${#required_keys[@]}" ]] \
        || scheduler_fail "$label-key-count-invalid" 65
}

declare -A BUNDLE=()
require_source() {
    local line actual entry
    local schedule_lines=0
    local source_entries=0

    require_control_source_metadata
    verify_metadata "$CRON_SOURCE" root root 600 'reviewed-cron-source'
    verify_metadata "$ARTIFACT_HASHER" root root 600 'artifact-hasher'
    verify_metadata "$BUNDLE_MANIFEST" root root 600 'bundle-manifest'
    while IFS= read -r -d '' entry; do
        case "${entry##*/}" in
            manage-business-report-scheduler.sh|safeharbor-business-reports.cron|hash-safeharbor-app-artifact.sh|scheduler-bundle.manifest)
                source_entries=$((source_entries + 1))
                ;;
            *) scheduler_fail 'control-source-directory-has-unexpected-entry' 65 ;;
        esac
    done < <(/usr/bin/find "$SCRIPT_DIR" -mindepth 1 -maxdepth 1 -print0)
    [[ "$source_entries" -eq 4 ]] \
        || scheduler_fail 'control-source-directory-entry-count-invalid' 65
    load_exact_kv_file \
        "$BUNDLE_MANIFEST" BUNDLE \
        'schema,release_sha,manager_sha256,cron_sha256,hasher_sha256,source_artifact_sha256,deployed_artifact_sha256,release_marker_sha256,wrapper_sha256,runner_sha256' \
        'bundle-manifest'

    [[ "${BUNDLE[schema]}" == 'safeharbor-business-report-scheduler-bundle-v2' ]] \
        || scheduler_fail 'bundle-manifest-schema-invalid' 65
    [[ "${BUNDLE[release_sha]}" =~ ^[0-9a-f]{40}$ ]] \
        || scheduler_fail 'bundle-manifest-release-invalid' 65
    for line in manager_sha256 cron_sha256 hasher_sha256 \
        source_artifact_sha256 deployed_artifact_sha256 release_marker_sha256 \
        wrapper_sha256 runner_sha256; do
        [[ "${BUNDLE[$line]}" =~ ^[0-9a-f]{64}$ ]] \
            || scheduler_fail "bundle-manifest-hash-invalid:$line" 65
    done
    actual="$(sha256_file "$SCRIPT_SELF")"
    [[ "$actual" == "${BUNDLE[manager_sha256]}" ]] \
        || scheduler_fail 'control-manager-digest-mismatch' 65
    actual="$(sha256_file "$CRON_SOURCE")"
    [[ "$actual" == "${BUNDLE[cron_sha256]}" ]] \
        || scheduler_fail 'reviewed-cron-source-digest-mismatch' 65
    actual="$(sha256_file "$ARTIFACT_HASHER")"
    [[ "$actual" == "${BUNDLE[hasher_sha256]}" ]] \
        || scheduler_fail 'artifact-hasher-digest-mismatch' 65

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

verify_deployed_tree_metadata() {
    local path unexpected

    unexpected="$(/usr/bin/find "$APP_ROOT" -xdev ! -type d ! -type f -print -quit)"
    [[ -z "$unexpected" ]] \
        || scheduler_fail 'deployment-tree-has-unsupported-file-type' 77
    while IFS= read -r -d '' path; do
        verify_metadata "$path" "$DEPLOY_OWNER" "$RUNTIME_GROUP" 2750 \
            'deployment-directory'
    done < <(/usr/bin/find "$APP_ROOT" -xdev -type d -print0)
    while IFS= read -r -d '' path; do
        verify_metadata "$path" "$DEPLOY_OWNER" "$RUNTIME_GROUP" 640 \
            'deployment-file'
    done < <(/usr/bin/find "$APP_ROOT" -xdev -type f -print0)
}

declare -A DEPLOYED_RELEASE=()
verify_deployed_release_record() {
    local actual marker_hash unique_record

    verify_metadata "$RELEASE_RECORD_DIR" root root 700 \
        'release-record-directory'
    verify_physical_directory "$RELEASE_RECORD_DIR" \
        'release-record-directory'
    verify_metadata "$CURRENT_RELEASE_RECORD" root root 600 \
        'current-release-record'
    load_exact_kv_file \
        "$CURRENT_RELEASE_RECORD" DEPLOYED_RELEASE \
        'schema,release_sha,source_artifact_sha256,deployed_artifact_sha256,hasher_sha256' \
        'current-release-record'

    [[ "${DEPLOYED_RELEASE[schema]}" == 'safeharbor-deployed-app-artifact-v1' ]] \
        || scheduler_fail 'current-release-record-schema-invalid' 65
    [[ "${DEPLOYED_RELEASE[release_sha]}" =~ ^[0-9a-f]{40}$ ]] \
        || scheduler_fail 'current-release-record-release-invalid' 65
    for actual in source_artifact_sha256 deployed_artifact_sha256 hasher_sha256; do
        [[ "${DEPLOYED_RELEASE[$actual]}" =~ ^[0-9a-f]{64}$ ]] \
            || scheduler_fail "current-release-record-hash-invalid:$actual" 65
    done

    unique_record="$RELEASE_RECORD_DIR/app-artifact.${DEPLOYED_RELEASE[release_sha]}.${DEPLOYED_RELEASE[deployed_artifact_sha256]}.manifest"
    verify_metadata "$unique_record" root root 600 'immutable-release-record'
    /usr/bin/cmp -s -- "$CURRENT_RELEASE_RECORD" "$unique_record" \
        || scheduler_fail 'immutable-release-record-content-mismatch' 65

    marker_hash="$(sha256_file "$CURRENT_RELEASE_RECORD")"
    [[ "$marker_hash" == "${BUNDLE[release_marker_sha256]}" ]] \
        || scheduler_fail 'release-marker-digest-mismatch' 65
    [[ "${DEPLOYED_RELEASE[release_sha]}" == "${BUNDLE[release_sha]}" ]] \
        || scheduler_fail 'release-marker-release-mismatch' 65
    [[ "${DEPLOYED_RELEASE[source_artifact_sha256]}" == "${BUNDLE[source_artifact_sha256]}" ]] \
        || scheduler_fail 'release-marker-source-artifact-mismatch' 65
    [[ "${DEPLOYED_RELEASE[deployed_artifact_sha256]}" == "${BUNDLE[deployed_artifact_sha256]}" ]] \
        || scheduler_fail 'release-marker-deployed-artifact-mismatch' 65
    [[ "${DEPLOYED_RELEASE[hasher_sha256]}" == "${BUNDLE[hasher_sha256]}" ]] \
        || scheduler_fail 'release-marker-hasher-mismatch' 65

    actual="$(/usr/bin/bash "$ARTIFACT_HASHER" "$APP_ROOT")" \
        || scheduler_fail 'deployed-artifact-hash-failed' 74
    [[ "$actual" =~ ^[0-9a-f]{64}$ ]] \
        || scheduler_fail 'deployed-artifact-hash-invalid' 74
    [[ "$actual" == "${BUNDLE[deployed_artifact_sha256]}" ]] \
        || scheduler_fail 'deployed-artifact-digest-mismatch' 65
}

require_runtime() {
    local path actual
    for path in /usr/bin/bash /usr/bin/php /usr/bin/logger /usr/bin/id \
        /usr/bin/test /usr/bin/cmp /usr/bin/install /usr/bin/stat \
        /usr/bin/chown /usr/bin/chmod /usr/bin/mv /usr/bin/unlink \
        /usr/bin/find /usr/bin/sha256sum /usr/bin/flock /usr/bin/date \
        /usr/sbin/runuser; do
        [[ -x "$path" ]] || scheduler_fail "required-executable-missing:$path" 69
    done

    verify_metadata "$APP_PARENT" "$DEPLOY_OWNER" "$RUNTIME_GROUP" 2750 \
        'application-parent'
    verify_metadata "$APP_ROOT" "$DEPLOY_OWNER" "$RUNTIME_GROUP" 2750 \
        'application-root'
    verify_physical_directory "$APP_PARENT" 'application-parent'
    verify_physical_directory "$APP_ROOT" 'application-root'
    verify_metadata "$REPORT_RUNNER" "$DEPLOY_OWNER" "$RUNTIME_GROUP" 640 \
        'report-runner'
    verify_metadata "$REPORT_WRAPPER" "$DEPLOY_OWNER" "$RUNTIME_GROUP" 640 \
        'report-wrapper'
    verify_metadata "$PROTECTED_CONFIG" "$DEPLOY_OWNER" "$RUNTIME_GROUP" 640 \
        'protected-config'
    verify_metadata "$DEPLOY_LOCK_DIR" root "$RUNTIME_GROUP" 750 \
        'deploy-lock-directory'
    verify_metadata "$DEPLOY_LOCK" root "$RUNTIME_GROUP" 640 \
        'deploy-lock'
    verify_deployed_tree_metadata
    verify_deployed_release_record

    /usr/bin/id "$RUNTIME_USER" >/dev/null 2>&1 \
        || scheduler_fail 'runtime-user-missing' 67
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test -r "$REPORT_RUNNER" \
        || scheduler_fail 'runtime-cannot-read-report-runner' 77
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test -r "$REPORT_WRAPPER" \
        || scheduler_fail 'runtime-cannot-read-report-wrapper' 77
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test -r "$PROTECTED_CONFIG" \
        || scheduler_fail 'runtime-cannot-read-protected-config' 77
    for path in "$APP_PARENT" "$APP_ROOT" "$REPORT_RUNNER" "$REPORT_WRAPPER" \
        "$PROTECTED_CONFIG" "$DEPLOY_LOCK_DIR" "$DEPLOY_LOCK"; do
        /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/test ! -w "$path" \
            || scheduler_fail "runtime-can-modify-deployment:$path" 77
    done

    actual="$(sha256_file "$REPORT_WRAPPER")"
    [[ "$actual" == "${BUNDLE[wrapper_sha256]}" ]] \
        || scheduler_fail 'report-wrapper-digest-mismatch' 65
    actual="$(sha256_file "$REPORT_RUNNER")"
    [[ "$actual" == "${BUNDLE[runner_sha256]}" ]] \
        || scheduler_fail 'report-runner-digest-mismatch' 65
    /usr/bin/bash -n "$REPORT_WRAPPER" \
        || scheduler_fail 'report-wrapper-syntax-invalid' 65
    /usr/sbin/runuser -u "$RUNTIME_USER" -- /usr/bin/php -l "$REPORT_RUNNER" >/dev/null \
        || scheduler_fail 'report-runner-syntax-invalid' 65
}

acquire_deploy_idle_lock() {
    verify_metadata "$DEPLOY_LOCK_DIR" root "$RUNTIME_GROUP" 750 \
        'deploy-lock-directory'
    verify_metadata "$DEPLOY_LOCK" root "$RUNTIME_GROUP" 640 \
        'deploy-lock'
    exec 8<"$DEPLOY_LOCK" \
        || scheduler_fail 'deploy-lock-open-failed' 75
    /usr/bin/flock --shared --nonblock 8 \
        || scheduler_fail 'deployment-in-progress' 75
}

verify_cron_file() {
    local target="$1"
    local state="$2"

    verify_metadata "$target" root root 644 "$state-scheduler"
    /usr/bin/cmp -s -- "$CRON_SOURCE" "$target" \
        || scheduler_fail "$state-content-mismatch" 1
}

declare -A ACTIVATION=()
validate_activation_evidence() {
    local evidence="$1"
    local expect_tenant="${2:-}"
    local expect_client="${3:-}"
    local expect_schedule="${4:-}"
    local expect_recipient="${5:-}"
    local evidence_parent config_hash manifest_hash

    evidence_parent="$(cd -- "$(dirname -- "$evidence")" && pwd -P)" \
        || scheduler_fail 'activation-evidence-directory-unavailable' 72
    verify_metadata "$evidence_parent" root root 700 'activation-evidence-directory'
    verify_metadata "$evidence" root root 600 'activation-evidence'
    load_exact_kv_file \
        "$evidence" ACTIVATION \
        'schema,release_sha,bundle_manifest_sha256,deployed_artifact_sha256,release_marker_sha256,protected_config_sha256,sender,tenant_slug,client_id,schedule_key,recipient,graph_status,graph_accepted_at,recipient_confirmation,recipient_confirmed_at,archive_sha256,protected_gates_reviewed_at' \
        'activation-evidence'

    [[ "${ACTIVATION[schema]}" == 'safeharbor-business-report-scheduler-activation-v2' ]] \
        || scheduler_fail 'activation-evidence-schema-invalid' 65
    [[ "${ACTIVATION[release_sha]}" == "${BUNDLE[release_sha]}" ]] \
        || scheduler_fail 'activation-release-mismatch' 65
    manifest_hash="$(sha256_file "$BUNDLE_MANIFEST")"
    [[ "${ACTIVATION[bundle_manifest_sha256]}" == "$manifest_hash" ]] \
        || scheduler_fail 'activation-bundle-digest-mismatch' 65
    [[ "${ACTIVATION[deployed_artifact_sha256]}" == "${BUNDLE[deployed_artifact_sha256]}" ]] \
        || scheduler_fail 'activation-deployed-artifact-mismatch' 65
    [[ "${ACTIVATION[release_marker_sha256]}" == "${BUNDLE[release_marker_sha256]}" ]] \
        || scheduler_fail 'activation-release-marker-mismatch' 65
    [[ "${ACTIVATION[protected_config_sha256]}" =~ ^[0-9a-f]{64}$ ]] \
        || scheduler_fail 'activation-config-digest-invalid' 65
    config_hash="$(sha256_file "$PROTECTED_CONFIG")"
    [[ "${ACTIVATION[protected_config_sha256]}" == "$config_hash" ]] \
        || scheduler_fail 'activation-config-digest-mismatch' 65
    [[ "${ACTIVATION[sender]}" == 'reports@8westit.com' ]] \
        || scheduler_fail 'activation-sender-not-dedicated-reports-mailbox' 65
    [[ "${ACTIVATION[tenant_slug]}" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]] \
        || scheduler_fail 'activation-tenant-invalid' 65
    [[ "${ACTIVATION[client_id]}" =~ ^[1-9][0-9]*$ ]] \
        || scheduler_fail 'activation-client-invalid' 65
    [[ "${ACTIVATION[schedule_key]}" =~ ^[a-z0-9][a-z0-9._:-]{0,190}$ ]] \
        || scheduler_fail 'activation-schedule-invalid' 65
    [[ "${ACTIVATION[recipient]}" =~ ^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,63}$ \
        && "${ACTIVATION[recipient]}" != "${ACTIVATION[sender]}" ]] \
        || scheduler_fail 'activation-recipient-invalid' 65
    [[ "${ACTIVATION[graph_status]}" == '202' ]] \
        || scheduler_fail 'activation-graph-status-invalid' 65
    [[ "${ACTIVATION[recipient_confirmation]}" == 'confirmed' ]] \
        || scheduler_fail 'activation-recipient-confirmation-invalid' 65
    [[ "${ACTIVATION[graph_accepted_at]}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ \
        && "${ACTIVATION[recipient_confirmed_at]}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ \
        && "${ACTIVATION[protected_gates_reviewed_at]}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] \
        || scheduler_fail 'activation-evidence-time-invalid' 65
    [[ "${ACTIVATION[graph_accepted_at]}" < "${ACTIVATION[recipient_confirmed_at]}" \
        && "${ACTIVATION[recipient_confirmed_at]}" < "${ACTIVATION[protected_gates_reviewed_at]}" ]] \
        || scheduler_fail 'activation-evidence-time-order-invalid' 65
    [[ "${ACTIVATION[archive_sha256]}" =~ ^[0-9a-f]{64}$ ]] \
        || scheduler_fail 'activation-archive-digest-invalid' 65

    [[ -z "$expect_tenant" || "${ACTIVATION[tenant_slug]}" == "$expect_tenant" ]] \
        || scheduler_fail 'activation-tenant-expectation-mismatch' 65
    [[ -z "$expect_client" || "${ACTIVATION[client_id]}" == "$expect_client" ]] \
        || scheduler_fail 'activation-client-expectation-mismatch' 65
    [[ -z "$expect_schedule" || "${ACTIVATION[schedule_key]}" == "$expect_schedule" ]] \
        || scheduler_fail 'activation-schedule-expectation-mismatch' 65
    [[ -z "$expect_recipient" || "${ACTIVATION[recipient]}" == "$expect_recipient" ]] \
        || scheduler_fail 'activation-recipient-expectation-mismatch' 65
}

verify_activation_record() {
    verify_metadata "$CONTROL_DIR" root root 700 'scheduler-control-directory'
    validate_activation_evidence "$ACTIVATION_RECORD"
}

verify_state() {
    local expected="${1:-}"
    case "$expected" in
        active)
            path_absent "$CRON_DISABLED" || scheduler_fail 'both-active-and-disabled-present' 1
            verify_cron_file "$CRON_ACTIVE" 'active'
            verify_activation_record
            ;;
        disabled)
            path_absent "$CRON_ACTIVE" || scheduler_fail 'active-scheduler-still-present' 1
            path_absent "$ACTIVATION_RECORD" || scheduler_fail 'activation-record-still-live' 1
            verify_cron_file "$CRON_DISABLED" 'disabled'
            ;;
        stopped)
            path_absent "$CRON_ACTIVE" || scheduler_fail 'active-scheduler-still-present' 1
            path_absent "$ACTIVATION_RECORD" || scheduler_fail 'activation-record-still-live' 1
            ;;
        absent)
            path_absent "$CRON_ACTIVE" && path_absent "$CRON_DISABLED" \
                && path_absent "$ACTIVATION_RECORD" \
                || scheduler_fail 'scheduler-file-still-present' 1
            ;;
        *) scheduler_usage ;;
    esac
    printf 'SCHEDULER_STATE=%s\n' "$expected"
}

ensure_control_dir() {
    if path_absent "$CONTROL_DIR"; then
        /usr/bin/install -d -o root -g root -m 0700 -- "$CONTROL_DIR"
    fi
    verify_metadata "$CONTROL_DIR" root root 700 'scheduler-control-directory'
}

quarantine_exact_path() {
    local source="$1"
    local label="$2"
    local target_dir="$3"
    local target_stem="$4"
    local timestamp target attempt output_label

    path_absent "$source" && return 0
    [[ -d "$target_dir" && ! -L "$target_dir" ]] \
        || scheduler_fail "quarantine-directory-unavailable:$label" 74
    timestamp="$(/usr/bin/date -u +%Y%m%dT%H%M%S%NZ)"
    output_label="${label^^}"
    output_label="${output_label//-/_}"
    for attempt in 1 2 3 4 5; do
        target="$target_dir/$target_stem.$timestamp.$$.$attempt"
        if path_absent "$target"; then
            /usr/bin/mv -T -- "$source" "$target"
            path_absent "$source" \
                || scheduler_fail "quarantine-failed:$label" 74
            if [[ -f "$target" && ! -L "$target" ]]; then
                /usr/bin/chown root:root -- "$target"
                /usr/bin/chmod 0600 -- "$target"
            fi
            printf 'SCHEDULER_PRESERVED_%s=%s\n' "$output_label" "$target"
            return 0
        fi
    done
    scheduler_fail "quarantine-name-exhausted:$label" 74
}

command="${1:-}"
[[ -n "$command" ]] || scheduler_usage
shift

require_root
require_test_root
require_control_source_metadata

case "$command" in
    preflight)
        [[ "$#" -eq 0 ]] || scheduler_usage
        require_source
        acquire_deploy_idle_lock
        require_runtime
        printf 'SCHEDULER_PREFLIGHT=PASS\n'
        ;;
    install-disabled)
        [[ "$#" -eq 0 ]] || scheduler_usage
        require_source
        acquire_deploy_idle_lock
        require_runtime
        path_absent "$CRON_ACTIVE" && path_absent "$CRON_DISABLED" \
            && path_absent "$ACTIVATION_RECORD" \
            || scheduler_fail 'scheduler-state-already-present' 1
        /usr/bin/install -o root -g root -m 0644 -- "$CRON_SOURCE" "$CRON_DISABLED"
        verify_state disabled
        ;;
    verify)
        [[ "$#" -eq 1 ]] || scheduler_usage
        require_source
        if [[ "$1" == active ]]; then
            acquire_deploy_idle_lock
            require_runtime
        fi
        verify_state "$1"
        ;;
    enable)
        [[ "$#" -eq 10 \
            && "$1" == '--activation-evidence' \
            && "$3" == '--expect-tenant-slug' \
            && "$5" == '--expect-client-id' \
            && "$7" == '--expect-schedule-key' \
            && "$9" == '--expect-recipient' ]] || scheduler_usage
        evidence="$2"
        expect_tenant="$4"
        expect_client="$6"
        expect_schedule="$8"
        expect_recipient="${10}"
        require_source
        acquire_deploy_idle_lock
        require_runtime
        path_absent "$CRON_ACTIVE" || scheduler_fail 'active-scheduler-already-present' 1
        path_absent "$ACTIVATION_RECORD" || scheduler_fail 'activation-record-already-present' 1
        verify_cron_file "$CRON_DISABLED" 'disabled'
        validate_activation_evidence "$evidence" "$expect_tenant" "$expect_client" \
            "$expect_schedule" "$expect_recipient"
        ensure_control_dir
        /usr/bin/install -o root -g root -m 0600 -- "$evidence" "$ACTIVATION_RECORD"
        /usr/bin/cmp -s -- "$evidence" "$ACTIVATION_RECORD" \
            || scheduler_fail 'activation-record-copy-mismatch' 74
        verify_activation_record
        /usr/bin/mv -- "$CRON_DISABLED" "$CRON_ACTIVE"
        /usr/bin/chown root:root -- "$CRON_ACTIVE"
        /usr/bin/chmod 0644 -- "$CRON_ACTIVE"
        verify_state active
        ;;
    disable)
        [[ "$#" -eq 0 ]] || scheduler_usage
        # Always rename the active cron name to a unique dot-containing ignored
        # name in /etc/cron.d first, even when a dot-disabled file already
        # exists. This same-filesystem move cannot be blocked by a separate
        # evidence-directory problem. Activation evidence is similarly renamed
        # inside its root-only control directory. Neither item is deleted. This
        # prevents new launches but deliberately does not kill or wait for an
        # already-running report process.
        quarantine_exact_path "$CRON_ACTIVE" 'cron-active' \
            "$ROOT_PREFIX/etc/cron.d" 'safeharbor-business-reports.quarantine'
        quarantine_exact_path "$ACTIVATION_RECORD" 'activation-record' \
            "$CONTROL_DIR" 'business-report-scheduler.activation.preserved'
        path_absent "$CRON_ACTIVE" \
            || scheduler_fail 'active-scheduler-still-present' 74
        printf 'SCHEDULER_IN_FLIGHT=not-stopped-check-deploy-lock\n'
        verify_state stopped
        ;;
    uninstall)
        [[ "$#" -eq 1 && "$1" == '--confirm-remove-exact-scheduler-files' ]] \
            || scheduler_usage
        require_source
        path_absent "$CRON_ACTIVE" \
            || scheduler_fail 'disable-active-scheduler-before-uninstall' 1
        path_absent "$ACTIVATION_RECORD" \
            || scheduler_fail 'disable-activation-before-uninstall' 1
        if [[ -e "$CRON_DISABLED" || -L "$CRON_DISABLED" ]]; then
            verify_cron_file "$CRON_DISABLED" 'disabled'
            /usr/bin/unlink -- "$CRON_DISABLED"
        fi
        verify_state absent
        ;;
    *) scheduler_usage ;;
esac
