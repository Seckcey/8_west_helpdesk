#!/usr/bin/env bash
# Print one deterministic SHA-256 for the complete Safeharbor application
# artifact. The protected server-only config is the sole excluded path. File
# names, directory names, and file bytes are bound without printing any of
# them; ownership and modes are verified separately by the scheduler manager.
set -euo pipefail

artifact_fail() {
    printf 'SAFEHARBOR_ARTIFACT_HASH_REFUSED=%s\n' "$1" >&2
    exit "${2:-1}"
}

[[ "$#" -eq 1 ]] || artifact_fail 'usage:hash-safeharbor-app-artifact.sh APP_ROOT' 64
readonly APP_ROOT="$1"

for required_tool in /usr/bin/find /usr/bin/sha256sum /usr/bin/sort; do
    [[ -x "$required_tool" ]] \
        || artifact_fail "required-tool-missing:$required_tool" 69
done

[[ "$APP_ROOT" == /* && -d "$APP_ROOT" && ! -L "$APP_ROOT" \
    && "$(cd -- "$APP_ROOT" && pwd -P)" == "$APP_ROOT" ]] \
    || artifact_fail 'application-root-not-exact-physical-directory' 77

unexpected="$(/usr/bin/find "$APP_ROOT" -xdev ! -type d ! -type f -print -quit)"
[[ -z "$unexpected" ]] \
    || artifact_fail 'application-tree-has-unsupported-file-type' 77

first_entry="$(/usr/bin/find "$APP_ROOT" -xdev -mindepth 1 \
    ! -path "$APP_ROOT/config/config.php" -print -quit)"
[[ -n "$first_entry" ]] || artifact_fail 'application-artifact-empty' 65

digest="$({
    /usr/bin/find "$APP_ROOT" -xdev -mindepth 1 \
        ! -path "$APP_ROOT/config/config.php" \
        \( -type d -o -type f \) -printf '%P\0' \
        | /usr/bin/sort -z \
        | while IFS= read -r -d '' relative_path; do
            absolute_path="$APP_ROOT/$relative_path"
            if [[ -d "$absolute_path" ]]; then
                printf 'D\0%s\0' "$relative_path"
                continue
            fi
            file_hash="$(/usr/bin/sha256sum -- "$absolute_path")"
            file_hash="${file_hash%% *}"
            [[ "$file_hash" =~ ^[0-9a-f]{64}$ ]] \
                || artifact_fail 'file-hash-invalid' 74
            printf 'F\0%s\0%s\0' "$relative_path" "$file_hash"
        done
} | /usr/bin/sha256sum)" || artifact_fail 'artifact-hash-failed' 74
digest="${digest%% *}"
[[ "$digest" =~ ^[0-9a-f]{64}$ ]] || artifact_fail 'artifact-hash-invalid' 74
printf '%s\n' "$digest"
