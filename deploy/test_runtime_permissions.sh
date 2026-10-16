#!/usr/bin/env bash
# Isolated Linux-only tests; creates no users, services, cron entries or production paths.
set -euo pipefail
[[ "${EUID}" -eq 0 && "$(uname -s)" == Linux ]] || exit 77
readonly ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
/usr/bin/id nobody >/dev/null
/usr/bin/env SAFEHARBOR_RUNTIME_FIXTURE_USER=nobody /usr/bin/bash \
    "$ROOT/app/tests/business_report_scheduler_linux_test.sh"
# Each refusal must be status 64 and carry its own marker, so an unrelated
# usage error (also 64) cannot satisfy it. The wrapper refuses silently.
refused() {
    local needle="$1" status=0 output
    shift
    output="$("$@" 2>&1)" || status=$?
    [[ "$status" -eq 64 && "$output" == *"$needle"* ]] || {
        printf 'FAIL test-identity override refusal: status %s, expected marker %s\n' "$status" "$needle" >&2
        exit 1
    }
}
readonly DEPLOY_ARGS=(--release-sha aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    --artifact-hasher /not-used --expect-hasher-sha256 "$(printf 'a%.0s' {1..64})"
    --expect-source-artifact-sha256 "$(printf 'b%.0s' {1..64})")
refused '' /usr/bin/env SAFEHARBOR_SCHEDULER_TEST_RUNTIME_USER=nobody \
    /usr/bin/bash "$ROOT/app/cron/run_business_reports.sh"
refused 'SCHEDULER_REFUSED=test-owner-without-test-root' \
    /usr/bin/env SAFEHARBOR_SCHEDULER_TEST_RUNTIME_USER=nobody \
    /usr/bin/bash "$ROOT/deploy/manage-business-report-scheduler.sh" preflight
refused 'SAFEHARBOR_DEPLOY_REFUSED=test-owner-without-test-root' \
    /usr/bin/env SAFEHARBOR_SCHEDULER_TEST_RUNTIME_USER=nobody \
    /usr/bin/bash "$ROOT/deploy/remote-install-safeharbor-app.sh" "${DEPLOY_ARGS[@]}"

# A test root that is not root:root 0755 cannot carry an identity override.
readonly WEAK_ROOT="$(/usr/bin/mktemp -d /tmp/safeharbor-scheduler-test.XXXXXXXX)"
trap '/usr/bin/rmdir -- "$WEAK_ROOT"' EXIT
/usr/bin/chmod 0700 -- "$WEAK_ROOT"
refused '' /usr/bin/env SAFEHARBOR_SCHEDULER_TEST_ROOT="$WEAK_ROOT" \
    SAFEHARBOR_SCHEDULER_TEST_RUNTIME_USER=nobody \
    /usr/bin/bash "$ROOT/app/cron/run_business_reports.sh"
refused '' /usr/bin/env SAFEHARBOR_SCHEDULER_TEST_ROOT="$WEAK_ROOT" \
    SAFEHARBOR_SCHEDULER_TEST_RUNTIME_USER=nobody \
    /usr/bin/bash "$ROOT/deploy/manage-business-report-scheduler.sh" preflight
refused 'SAFEHARBOR_DEPLOY_REFUSED=unsafe-test-root' \
    /usr/bin/env SAFEHARBOR_SCHEDULER_TEST_ROOT="$WEAK_ROOT" \
    SAFEHARBOR_SCHEDULER_TEST_RUNTIME_USER=nobody \
    /usr/bin/bash "$ROOT/deploy/remote-install-safeharbor-app.sh" "${DEPLOY_ARGS[@]}"
printf 'PASS 6 test-identity override refusals plus dedicated-UID scheduler suite\n'
