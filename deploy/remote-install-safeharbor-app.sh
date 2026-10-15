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

# =============================== R2 cold window: root carrier phases ===============================
# The ordinary path after this block is unchanged. These fixed phases run only when the root cold carrier
# (ID deploy/cold_window.py) runs ITS root-installed copy of this installer with the report lock it already
# holds. No caller-supplied hasher or path, no hot fallback, no broad recursive chmod/chown of the writable
# tree, no automatic restore on an error or signal, no release record before the carrier's acceptance.
#   prepare  same tree/config/scheduler gates, exact payload digest, the replace/create/delete plan, and a
#            root-only copy of the exact current release record
#   apply    exact planned leaves only (deploy owner, runtime group, 0640, new directories 2750), version
#            normalization kept, digest verified before and after stamping; the selected leaves' metadata/ACL
#            must still be the carrier's joined values; held under apply.lock, every directory, temp sibling and
#            leaf effect journaled BEFORE it happens (a failed or lost apply stays accountable, never replayed),
#            and a new directory's identity, type, owner/group/mode and access ACL right after its creation
#   commit   the immutable and current release records, as the ordinary path writes them
#   inverse  the carrier's accepted-current-source leaves with exact prior owner/group/mode AND access ACL;
#            a leaf is restored or removed only while it is still exactly the after-state apply journaled
#            before its rename (identity, bytes, ACL, deploy owner/runtime group/0640, one link; a deleted leaf
#            still absent); a leaf apply never reached keeps its verified prior state, unrewritten; any other
#            leaf is preserved (HOLD) whatever the tree digest says; only journaled temps removed; a created
#            directory is removed only while it is still exactly the journaled one, unchanged and empty, and is
#            otherwise preserved (HOLD), as is one without its creation record; the pre-operation
#            deployed digest must be reproduced exactly, and only then the prior current release record
#            returns (immutable records stay). Refused while an apply may still run.
# The active-report-scheduler gate stays in every phase: the carrier holds that name inside its operation.
if [[ "${1:-}" == '--cold' ]]; then
    [[ "$#" -eq 4 && "$3" == '--cold-slot' ]] || deploy_fail 'cold-invalid-arguments' 64
    readonly COLD_PHASE="$2"
    readonly COLD_SLOT="$4"
    case "$COLD_PHASE" in prepare|apply|commit|inverse) ;; *) deploy_fail 'cold-phase-invalid' 64 ;; esac
    COLD_PREFIX=''
    if [[ -n "${SAFEHARBOR_COLD_TEST_ROOT:-}" ]]; then
        [[ "$SAFEHARBOR_COLD_TEST_ROOT" =~ ^/tmp/safeharbor-cold-test[.][A-Za-z0-9]+$ \
            && -d "$SAFEHARBOR_COLD_TEST_ROOT" && ! -L "$SAFEHARBOR_COLD_TEST_ROOT" \
            && "$(cd -- "$SAFEHARBOR_COLD_TEST_ROOT" && pwd -P)" == "$SAFEHARBOR_COLD_TEST_ROOT" \
            && "$(/usr/bin/stat -c '%U:%G:%a' -- "$SAFEHARBOR_COLD_TEST_ROOT")" == 'root:root:755' ]] \
            || deploy_fail 'cold-unsafe-test-root' 64
        COLD_PREFIX="$SAFEHARBOR_COLD_TEST_ROOT"
    fi
    readonly COLD_PREFIX
    readonly COLD_ADAPTER_DIR="$COLD_PREFIX/usr/local/libexec/8west/cold-window.d/safeharbor"
    readonly COLD_APP_ROOT="$COLD_PREFIX/srv/8west/apps/safeharbor/current"
    readonly COLD_CONFIG="$COLD_APP_ROOT/config/config.php"
    readonly COLD_CRON_ACTIVE="$COLD_PREFIX/etc/cron.d/safeharbor-business-reports"
    readonly COLD_LOCK="$COLD_PREFIX/var/lib/safeharbor-report-scheduler/business-reports-deploy.lock"
    readonly COLD_RECORDS="$COLD_PREFIX/var/lib/safeharbor-report-scheduler/releases"
    readonly COLD_CURRENT_RECORD="$COLD_RECORDS/current-app-artifact.manifest"
    readonly COLD_HASHER="$COLD_ADAPTER_DIR/hash-safeharbor-app-artifact.sh"
    readonly COLD_NORMALIZED=(lib/render.php lib/westy.php public/login.php)

    cold_meta() { /usr/bin/stat -c '%U:%G:%a' -- "$1" 2>/dev/null; }
    cold_sha() { local h; h="$(/usr/bin/sha256sum -- "$1")" || return 1; printf '%s\n' "${h%% *}"; }
    cold_protected_dir() { # <path> <mode>
        [[ -d "$1" && ! -L "$1" && "$(cd -- "$1" && pwd -P)" == "$1" && "$(cold_meta "$1")" == "root:root:$2" ]]
    }

    # The carrier's own installed copy, its slot and its values only.
    here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
    [[ "$here" == "$COLD_ADAPTER_DIR" ]] || deploy_fail 'cold-not-the-installed-adapter' 77
    [[ "$(cold_meta "$COLD_ADAPTER_DIR")" =~ ^root:root:7[0-5][0-5]$ ]] || deploy_fail 'cold-adapter-directory-unprotected' 77
    [[ "${COLD_WINDOW_OPERATION:-}" =~ ^[0-9a-f]{32}$ \
        && "$COLD_SLOT" == "$COLD_PREFIX/var/lib/8west-cold-window/$COLD_WINDOW_OPERATION/safeharbor" \
        && "${COLD_WINDOW_SLOT:-}" == "$COLD_SLOT" ]] || deploy_fail 'cold-slot-not-the-carrier-slot' 77
    cold_protected_dir "$COLD_SLOT" 700 || deploy_fail 'cold-slot-unprotected' 77
    [[ "${SAFEHARBOR_COLD_APP_ROOT:-}" == "/srv/8west/apps/safeharbor/current" ]] || deploy_fail 'cold-app-root-not-fixed' 64
    [[ "${SAFEHARBOR_COLD_RELEASE_SHA:-}" =~ ^[0-9a-f]{40}$ \
        && "${SAFEHARBOR_COLD_SOURCE_ARTIFACT_SHA256:-}" =~ ^[0-9a-f]{64}$ \
        && "${SAFEHARBOR_COLD_HASHER_SHA256:-}" =~ ^[0-9a-f]{64}$ \
        && "${SAFEHARBOR_COLD_HASHER:-}" == "/usr/local/libexec/8west/cold-window.d/safeharbor/hash-safeharbor-app-artifact.sh" \
        && "${SAFEHARBOR_COLD_RUNTIME_GROUP:-}" =~ ^[a-z_][a-z0-9_-]*$ \
        && "${SAFEHARBOR_COLD_DEPLOY_OWNER:-}" =~ ^[a-z_][a-z0-9_-]*$ \
        && "${SAFEHARBOR_COLD_LOCK_FD:-}" =~ ^[0-9]{1,4}$ ]] || deploy_fail 'cold-values-invalid' 64
    /usr/bin/getent group "$SAFEHARBOR_COLD_RUNTIME_GROUP" >/dev/null || deploy_fail 'cold-runtime-group-missing' 77
    /usr/bin/id "$SAFEHARBOR_COLD_DEPLOY_OWNER" >/dev/null 2>&1 || deploy_fail 'cold-deploy-owner-missing' 77
    [[ -f "$COLD_HASHER" && ! -L "$COLD_HASHER" && "$(cold_meta "$COLD_HASHER")" =~ ^root:root:(644|444|755|555)$ \
        && "$(cold_sha "$COLD_HASHER")" == "$SAFEHARBOR_COLD_HASHER_SHA256" ]] || deploy_fail 'cold-hasher-not-pinned' 65

    # The inherited descriptor is the reviewed lock inode, and the carrier really holds it: a separately opened
    # description cannot take the exclusive lock.
    [[ -f "$COLD_LOCK" && ! -L "$COLD_LOCK" \
        && "$(/usr/bin/stat -L -c '%d:%i' -- "/proc/self/fd/$SAFEHARBOR_COLD_LOCK_FD" 2>/dev/null)" == "$(/usr/bin/stat -c '%d:%i' -- "$COLD_LOCK")" ]] \
        || deploy_fail 'cold-lock-descriptor-not-the-report-lock' 75
    if /usr/bin/flock --exclusive --nonblock "$COLD_LOCK" /usr/bin/true; then
        deploy_fail 'cold-report-lock-not-held-by-the-carrier' 75
    fi
    [[ ! -e "$COLD_CRON_ACTIVE" && ! -L "$COLD_CRON_ACTIVE" ]] || deploy_fail 'active-report-scheduler-present' 75
    for cold_runner in www-data "$SAFEHARBOR_COLD_RUNTIME_GROUP"; do
        if /usr/bin/pgrep -u "$cold_runner" -f "^/usr/bin/php $COLD_APP_ROOT/cron/business_reports[.]php$" >/dev/null 2>&1; then
            deploy_fail 'legacy-report-runner-in-flight' 75
        fi
    done
    [[ -d "$COLD_APP_ROOT" && ! -L "$COLD_APP_ROOT" && "$(cd -- "$COLD_APP_ROOT" && pwd -P)" == "$COLD_APP_ROOT" ]] \
        || deploy_fail 'cold-application-root-not-physical' 77
    [[ "$(cold_meta "$COLD_APP_ROOT")" =~ ^${SAFEHARBOR_COLD_DEPLOY_OWNER}:(www-data|${SAFEHARBOR_COLD_RUNTIME_GROUP}):2750$ ]] \
        || deploy_fail 'cold-application-root-metadata' 77
    [[ -f "$COLD_CONFIG" && ! -L "$COLD_CONFIG" \
        && "$(cold_meta "$COLD_CONFIG")" =~ ^${SAFEHARBOR_COLD_DEPLOY_OWNER}:(www-data|${SAFEHARBOR_COLD_RUNTIME_GROUP}):640$ ]] \
        || deploy_fail 'cold-protected-config-metadata' 77

    readonly COLD_PLAN="$COLD_SLOT/plan.tsv" COLD_INVERSE="$COLD_SLOT/inverse.tsv" COLD_PREPARED="$COLD_SLOT/prepare.env"
    readonly COLD_STAGED="$COLD_SLOT/staged" COLD_APPLIED="$COLD_SLOT/apply.env" COLD_DIRS="$COLD_SLOT/created-dirs.list"
    # Partial-effect custody (SH-R2-02): intents journaled before each effect, and the lock an apply holds while any
    # of its processes may still act. The exact current release record as prepare found it (SH-R2-03).
    readonly COLD_PROGRESS="$COLD_SLOT/apply.progress" COLD_APPLY_LOCK="$COLD_SLOT/apply.lock"
    readonly COLD_PRIOR_RECORD="$COLD_SLOT/prior-current-record"
    readonly COLD_ACL_SHAPE='^(user|group|mask|other):[0-9]*:[rwx-]{3}(,(user|group|mask|other):[0-9]*:[rwx-]{3})*$'
    cold_value() { /usr/bin/sed -n "s/^$1=//p" "$2"; }
    cold_normalized() { local n; for n in "${COLD_NORMALIZED[@]}"; do [[ "$1" == "$n" ]] && return 0; done; return 1; }
    # The exact access ACL as the carrier's join records it: numeric entries in getfacl order, comma-joined, or '-'
    # for only the three base entries the mode carries (SH-R2-01).
    cold_acl() {
        local out
        out="$(/usr/bin/getfacl --absolute-names --omit-header --numeric --no-effective -- "$1" | /usr/bin/sed '/^$/d' \
            | /usr/bin/paste -sd, -)" || return 1
        if [[ "$out" =~ ^user::[rwx-]{3},group::[rwx-]{3},other::[rwx-]{3}$ ]]; then printf -- '-\n'; else printf '%s\n' "$out"; fi
    }
    cold_journal() { printf '%s\n' "$1" >> "$COLD_PROGRESS" || deploy_fail 'cold-progress-journal-failed' 74; }
    # True when <file> is exactly one regular single-link leaf with these bytes, owner:group:mode (numeric ids or names)
    # and access ACL and, when given, this physical dev:inode identity, unchanged while it was read.
    cold_leaf_is() { # <file> <sha256> <owner:group:mode> <acl> [dev:inode]
        local seen id links ids names
        [[ -f "$1" && ! -L "$1" ]] || return 1
        seen="$(/usr/bin/stat -c '%d:%i %h %u:%g:%a %U:%G:%a %s %Z' -- "$1")" || return 1
        read -r id links ids names _ <<< "$seen"
        [[ "$links" == 1 && ( "$ids" == "$3" || "$names" == "$3" ) && ( -z "${5:-}" || "$id" == "$5" ) \
            && "$(cold_sha "$1")" == "$2" && "$(cold_acl "$1")" == "$4" \
            && "$(/usr/bin/stat -c '%d:%i %h %u:%g:%a %U:%G:%a %s %Z' -- "$1")" == "$seen" ]]
    }
    cold_verify_inverse_map() {
        local op path sha uid gid mode acl extra want got
        [[ -f "$COLD_INVERSE" && ! -L "$COLD_INVERSE" && -f "$COLD_PLAN" ]] || deploy_fail 'cold-inverse-map-missing' 65
        while IFS=$'\t' read -r op path sha uid gid mode acl extra; do
            [[ -z "$extra" && "$path" != /* && "/$path/" != *'/../'* && "$sha" =~ ^[0-9a-f]{64}$ ]] \
                || deploy_fail 'cold-inverse-row-invalid' 65
            case "$op" in
                restore) [[ "$uid" =~ ^[0-9]+$ && "$gid" =~ ^[0-9]+$ && "$mode" =~ ^0[0-7]{3}$ \
                    && ( "$acl" == - || "$acl" =~ $COLD_ACL_SHAPE ) ]] || deploy_fail 'cold-inverse-metadata-invalid' 65 ;;
                remove) [[ "$uid:$gid:$mode:$acl" == '-:-:-:-' ]] || deploy_fail 'cold-inverse-row-invalid' 65 ;;
                *) deploy_fail 'cold-inverse-operation-invalid' 65 ;;
            esac
        done < "$COLD_INVERSE"
        want="$(/usr/bin/awk -F'\t' '$1=="replace"||$1=="delete"{print "restore\t"$2"\t"$3} $1=="create"{print "remove\t"$2"\t"$3}' "$COLD_PLAN" | LC_ALL=C /usr/bin/sort)"
        got="$(/usr/bin/cut -f1-3 "$COLD_INVERSE" | LC_ALL=C /usr/bin/sort)"
        [[ "$want" == "$got" ]] || deploy_fail 'cold-inverse-map-does-not-cover-the-plan' 65
    }
    cold_install_leaf() { # <leaf-bytes> <relative> -> temp sibling + rename, target metadata, frontend read on public/
        local bytes="$1" relative="$2" target="$COLD_APP_ROOT/$2" current part temp identity digest access created
        local -a parts=()
        current="$COLD_APP_ROOT"
        IFS=/ read -ra parts <<<"$(dirname -- "$relative")"
        for part in "${parts[@]}"; do
            [[ -n "$part" && "$part" != . ]] || continue
            current="$current/$part"
            [[ ! -L "$current" ]] || deploy_fail 'cold-live-parent-symlink' 77
            if [[ ! -e "$current" ]]; then
                # Journaled before it exists: a directory created by a failed or lost apply is still accounted for.
                printf '%s\n' "${current#"$COLD_APP_ROOT"/}" >> "$COLD_DIRS" || deploy_fail 'cold-progress-journal-failed' 74
                /usr/bin/install -d -o "$SAFEHARBOR_COLD_DEPLOY_OWNER" -g "$SAFEHARBOR_COLD_RUNTIME_GROUP" -m 2750 -- "$current"
                if [[ "${current#"$COLD_APP_ROOT"/}" == public || "${current#"$COLD_APP_ROOT"/}" == public/* ]]; then
                    /usr/bin/setfacl -m u:www-data:r-x -- "$current"
                fi
                # Its identity, type, owner/group/mode and access ACL as created, before anything is placed in it: the
                # inverse removes only this exact directory, and one without this record is never assumed to be its own.
                [[ -d "$current" && ! -L "$current" ]] || deploy_fail 'cold-live-parent-not-directory' 77
                created="$(/usr/bin/stat -c $'%d:%i\t%F\t%u:%g:%a' -- "$current")" && access="$(cold_acl "$current")" \
                    || deploy_fail 'cold-progress-journal-failed' 74
                printf 'dir\t%s\t%s\t%s\n' "${current#"$COLD_APP_ROOT"/}" "$created" "$access" >> "$COLD_DIRS" \
                    || deploy_fail 'cold-progress-journal-failed' 74
            fi
            [[ -d "$current" ]] || deploy_fail 'cold-live-parent-not-directory' 77
        done
        [[ ! -L "$target" && ( ! -e "$target" || -f "$target" ) ]] || deploy_fail 'cold-live-target-unsafe' 77
        temp="$target.sh-cold.$$"
        cold_journal "temp"$'\t'"${temp#"$COLD_APP_ROOT"/}"
        /usr/bin/install -o "$SAFEHARBOR_COLD_DEPLOY_OWNER" -g "$SAFEHARBOR_COLD_RUNTIME_GROUP" -m 0640 -- "$bytes" "$temp"
        if [[ "$relative" == public/* ]]; then /usr/bin/setfacl -m u:www-data:r-- -- "$temp"; fi
        # The installed leaf (identity, bytes, access ACL), journaled before the rename makes it live: the inverse acts
        # on this leaf only while it is still exactly this one, and a leaf without the record was never replaced.
        identity="$(/usr/bin/stat -c '%d:%i' -- "$temp")" && digest="$(cold_sha "$temp")" && access="$(cold_acl "$temp")" \
            || deploy_fail 'cold-progress-journal-failed' 74
        cold_journal "installed"$'\t'"$relative"$'\t'"$identity"$'\t'"$digest"$'\t'"$access"
        /usr/bin/mv -f -T -- "$temp" "$target"
    }

    case "$COLD_PHASE" in
        prepare)
            [[ ! -e "$COLD_PLAN" ]] || deploy_fail 'cold-prepare-replayed' 75
            [[ -f "$COLD_SLOT/artifact" && ! -L "$COLD_SLOT/artifact" && "$(cold_meta "$COLD_SLOT/artifact")" == 'root:root:400' ]] \
                || deploy_fail 'cold-artifact-not-the-slot-artifact' 65
            while IFS= read -r entry || [[ -n "$entry" ]]; do
                entry="${entry#./}"
                [[ -n "$entry" && "$entry" != /* && "$entry" != *'\\'* && "/$entry/" != *'/../'* ]] || deploy_fail 'payload-path-invalid' 65
                case "$entry" in app|app/*) ;; *) deploy_fail 'payload-outside-app-root' 65 ;; esac
                case "$entry" in app/config/config.php|app/config/config.php/) deploy_fail 'payload-contains-protected-config' 65 ;; esac
            done < <(/usr/bin/tar -tzf "$COLD_SLOT/artifact")
            /usr/bin/install -d -o root -g root -m 0700 -- "$COLD_STAGED"
            /usr/bin/tar -xzf "$COLD_SLOT/artifact" -C "$COLD_STAGED" --no-same-owner --no-same-permissions
            [[ -z "$(/usr/bin/find "$COLD_STAGED" ! -type d ! -type f -print -quit)" ]] || deploy_fail 'payload-has-unsupported-file-type' 65
            [[ "$(/usr/bin/bash "$COLD_HASHER" "$COLD_STAGED/app")" == "$SAFEHARBOR_COLD_SOURCE_ARTIFACT_SHA256" ]] \
                || deploy_fail 'staged-source-artifact-digest-mismatch' 65
            [[ -f "$COLD_CURRENT_RECORD" ]] || deploy_fail 'cold-current-release-record-missing' 77
            : > "$COLD_PLAN.tmp"
            while IFS= read -r -d '' relative; do
                live="$COLD_APP_ROOT/$relative"
                if [[ -L "$live" ]]; then deploy_fail 'cold-live-leaf-symlink' 77; fi
                if [[ -e "$live" ]]; then
                    [[ -f "$live" && "$(/usr/bin/stat -c '%h' -- "$live")" == 1 ]] || deploy_fail 'cold-live-leaf-not-regular' 77
                    live_sha="$(cold_sha "$live")"
                    if cold_normalized "$relative" || [[ "$live_sha" != "$(cold_sha "$COLD_STAGED/app/$relative")" ]]; then
                        printf 'replace\t%s\t%s\n' "$relative" "$live_sha" >> "$COLD_PLAN.tmp"
                    fi
                else
                    printf 'create\t%s\t%s\n' "$relative" "$(cold_sha "$COLD_STAGED/app/$relative")" >> "$COLD_PLAN.tmp"
                fi
            done < <(cd -- "$COLD_STAGED/app" && /usr/bin/find . -type f -printf '%P\0' | LC_ALL=C /usr/bin/sort -z)
            while IFS= read -r -d '' relative; do
                [[ "$relative" == config/config.php ]] && continue
                if [[ ! -e "$COLD_STAGED/app/$relative" ]]; then
                    printf 'delete\t%s\t%s\n' "$relative" "$(cold_sha "$COLD_APP_ROOT/$relative")" >> "$COLD_PLAN.tmp"
                fi
            done < <(cd -- "$COLD_APP_ROOT" && /usr/bin/find . -xdev -type f -printf '%P\0' | LC_ALL=C /usr/bin/sort -z)
            [[ -z "$(/usr/bin/find "$COLD_APP_ROOT" -xdev ! -type d ! -type f -print -quit)" ]] \
                || deploy_fail 'deployment-tree-has-unsupported-file-type' 77
            /usr/bin/chmod 0600 -- "$COLD_PLAN.tmp"
            /usr/bin/mv -f -T -- "$COLD_PLAN.tmp" "$COLD_PLAN"
            # SH-R2-03: the exact current release record (bytes and owner/group/mode) as this operation found it. The
            # inverse returns it only after the previous source verified; the immutable records are never removed.
            [[ -f "$COLD_CURRENT_RECORD" && ! -L "$COLD_CURRENT_RECORD" && ! -e "$COLD_PRIOR_RECORD" ]] \
                || deploy_fail 'cold-prior-record-not-capturable' 77
            /usr/bin/install -o root -g root -m 0600 -- "$COLD_CURRENT_RECORD" "$COLD_PRIOR_RECORD"
            /usr/bin/cmp -s -- "$COLD_CURRENT_RECORD" "$COLD_PRIOR_RECORD" || deploy_fail 'cold-prior-record-copy-mismatch' 74
            /usr/bin/printf 'config_sha256=%s\nprevious_deployed_sha256=%s\nprevious_record_sha256=%s\nprevious_record_meta=%s\n' \
                "$(cold_sha "$COLD_CONFIG")" "$(cold_value deployed_artifact_sha256 "$COLD_CURRENT_RECORD")" \
                "$(cold_sha "$COLD_PRIOR_RECORD")" "$(/usr/bin/stat -c '%u:%g:%a' -- "$COLD_CURRENT_RECORD")" > "$COLD_PREPARED"
            printf 'SAFEHARBOR_COLD=prepared\n'
            ;;
        apply)
            [[ -f "$COLD_PREPARED" && -f "$COLD_PLAN" && -d "$COLD_STAGED/app" ]] || deploy_fail 'cold-apply-without-prepare' 75
            [[ ! -e "$COLD_APPLIED" && ! -e "$COLD_PROGRESS" && ! -e "$COLD_APPLY_LOCK" ]] || deploy_fail 'cold-apply-replayed' 75
            cold_verify_inverse_map
            [[ "$(cold_sha "$COLD_CONFIG")" == "$(cold_value config_sha256 "$COLD_PREPARED")" ]] || deploy_fail 'protected-config-changed' 74
            [[ "$(/usr/bin/bash "$COLD_HASHER" "$COLD_STAGED/app")" == "$SAFEHARBOR_COLD_SOURCE_ARTIFACT_SHA256" ]] \
                || deploy_fail 'staged-source-artifact-digest-mismatch' 65
            while IFS=$'\t' read -r op relative sha; do
                live="$COLD_APP_ROOT/$relative"
                case "$op" in
                    replace|delete) [[ -f "$live" && ! -L "$live" && "$(cold_sha "$live")" == "$sha" ]] || deploy_fail 'cold-live-drift-since-prepare' 75 ;;
                    create) [[ ! -e "$live" && ! -L "$live" ]] || deploy_fail 'cold-live-drift-since-prepare' 75 ;;
                esac
            done < "$COLD_PLAN"
            # The selected leaves still carry exactly the owner/group/mode and access ACL the carrier joined (SH-R2-01).
            while IFS=$'\t' read -r op relative sha uid gid mode acl; do
                [[ "$op" == restore ]] || continue
                live="$COLD_APP_ROOT/$relative"
                [[ "$(/usr/bin/stat -c '%u:%g:%a' -- "$live")" == "$uid:$gid:${mode#0}" && "$(cold_acl "$live")" == "$acl" ]] \
                    || deploy_fail 'cold-live-drift-since-prepare' 75
            done < "$COLD_INVERSE"
            # SH-R2-02: this apply holds apply.lock for its whole life (every child inherits the descriptor), and each
            # directory, temp sibling and leaf effect is journaled BEFORE it happens.
            exec {cold_apply_fd}>> "$COLD_APPLY_LOCK" || deploy_fail 'cold-apply-lock-failed' 74
            /usr/bin/flock --exclusive --nonblock "$cold_apply_fd" || deploy_fail 'cold-apply-lock-busy' 75
            : > "$COLD_DIRS"
            cold_journal 'begin'
            while IFS=$'\t' read -r op relative sha; do
                cold_journal "leaf"$'\t'"$op"$'\t'"$relative"
                case "$op" in
                    replace|create) cold_install_leaf "$COLD_STAGED/app/$relative" "$relative" ;;
                    delete) /usr/bin/rm -f -- "$COLD_APP_ROOT/$relative" ;;
                esac
            done < "$COLD_PLAN"
            source_actual="$(/usr/bin/bash "$COLD_HASHER" "$COLD_APP_ROOT")" || deploy_fail 'source-artifact-hash-failed' 74
            [[ "$source_actual" == "$SAFEHARBOR_COLD_SOURCE_ARTIFACT_SHA256" ]] || deploy_fail 'source-artifact-digest-mismatch' 65
            version="$(/usr/bin/date -u +%Y%m%d%H%M%S)"
            for relative in "${COLD_NORMALIZED[@]}"; do
                [[ -f "$COLD_APP_ROOT/$relative" ]] || continue
                /usr/bin/sed "s/?v=[0-9A-Za-z]\+/?v=$version/g" "$COLD_APP_ROOT/$relative" > "$COLD_SLOT/normalized.tmp"
                cold_journal "leaf"$'\t'"normalize"$'\t'"$relative"
                cold_install_leaf "$COLD_SLOT/normalized.tmp" "$relative"
                /usr/bin/rm -f -- "$COLD_SLOT/normalized.tmp"
            done
            [[ "$(cold_sha "$COLD_CONFIG")" == "$(cold_value config_sha256 "$COLD_PREPARED")" ]] || deploy_fail 'protected-config-changed' 74
            deployed="$(/usr/bin/bash "$COLD_HASHER" "$COLD_APP_ROOT")" || deploy_fail 'deployed-artifact-hash-failed' 74
            [[ "$deployed" =~ ^[0-9a-f]{64}$ ]] || deploy_fail 'deployed-artifact-hash-invalid' 74
            /usr/bin/printf 'deployed_artifact_sha256=%s\n' "$deployed" > "$COLD_APPLIED"
            printf 'SAFEHARBOR_COLD=applied-not-live\n'
            ;;
        commit)
            [[ -f "$COLD_APPLIED" ]] || deploy_fail 'cold-commit-without-apply' 75
            deployed="$(cold_value deployed_artifact_sha256 "$COLD_APPLIED")"
            [[ "$(/usr/bin/bash "$COLD_HASHER" "$COLD_APP_ROOT")" == "$deployed" ]] || deploy_fail 'deployed-artifact-changed-before-commit' 74
            cold_protected_dir "$COLD_RECORDS" 700 || deploy_fail 'release-record-directory-not-exact' 77
            unique="$COLD_RECORDS/app-artifact.$SAFEHARBOR_COLD_RELEASE_SHA.$deployed.manifest"
            record="$(/usr/bin/mktemp "$COLD_RECORDS/app-artifact.XXXXXXXX.manifest")"
            /usr/bin/printf '%s\n' 'schema=safeharbor-deployed-app-artifact-v1' "release_sha=$SAFEHARBOR_COLD_RELEASE_SHA" \
                "source_artifact_sha256=$SAFEHARBOR_COLD_SOURCE_ARTIFACT_SHA256" "deployed_artifact_sha256=$deployed" \
                "hasher_sha256=$SAFEHARBOR_COLD_HASHER_SHA256" > "$record"
            /usr/bin/chmod 0600 -- "$record"
            if [[ ! -e "$unique" ]]; then
                /usr/bin/mv -T -- "$record" "$unique"
            else
                /usr/bin/cmp -s -- "$record" "$unique" || deploy_fail 'immutable-release-record-conflict' 65
                /usr/bin/unlink -- "$record"
            fi
            current="$(/usr/bin/mktemp "$COLD_RECORDS/current.XXXXXXXX.manifest")"
            /usr/bin/install -o root -g root -m 0600 -- "$unique" "$current"
            /usr/bin/mv -T -- "$current" "$COLD_CURRENT_RECORD"
            /usr/bin/cmp -s -- "$unique" "$COLD_CURRENT_RECORD" || deploy_fail 'current-release-record-content-mismatch' 74
            printf 'SAFEHARBOR_DEPLOY=installed-under-cold-window\n'
            printf 'SAFEHARBOR_RELEASE_SHA=%s\n' "$SAFEHARBOR_COLD_RELEASE_SHA"
            printf 'SAFEHARBOR_DEPLOYED_ARTIFACT_SHA256=%s\n' "$deployed"
            ;;
        inverse)
            [[ -f "$COLD_PREPARED" && -f "$COLD_PLAN" ]] || deploy_fail 'cold-inverse-without-prepare' 75
            cold_verify_inverse_map
            # SH-R2-02: a failed or lost apply is covered, never replayed; while any of its processes may still act
            # (its lock is held) nothing is touched and the outcome stays unknown.
            if [[ -e "$COLD_APPLY_LOCK" ]]; then
                exec {cold_lock_fd}< "$COLD_APPLY_LOCK" || deploy_fail 'cold-apply-lock-unreadable' 75
                /usr/bin/flock --exclusive --nonblock "$cold_lock_fd" || deploy_fail 'cold-apply-may-still-run' 75
            fi
            if [[ -f "$COLD_APPLIED" ]]; then
                printf 'SAFEHARBOR_COLD_APPLY=complete\n'
            elif [[ -e "$COLD_PROGRESS" ]]; then
                printf 'SAFEHARBOR_COLD_APPLY=partial\n'
            else
                printf 'SAFEHARBOR_COLD_APPLY=not-started\n'
            fi
            cold_rc=0
            while IFS=$'\t' read -r op relative sha uid gid mode acl; do
                live="$COLD_APP_ROOT/$relative"
                # The leaf's last record decides, each journaled before the rename or removal it names: none (never
                # reached: only its exact prior state is accepted, and it is left as it is), the apply's installed
                # after-state or delete intent, or this inverse's own earlier restore/removal. Anything else is a later
                # or foreign change and is preserved (HOLD); the tree digest below never runs over it.
                last=''
                if [[ -f "$COLD_PROGRESS" ]]; then
                    last="$(LEAF="$relative" /usr/bin/awk -F'\t' '
                        $1 == "installed" && $2 == ENVIRON["LEAF"] { r = $1 "\t" $3 "\t" $4 "\t" $5 }
                        $1 == "leaf" && $2 == "delete" && $3 == ENVIRON["LEAF"] { r = "deleted" }
                        ($1 == "restored" || $1 == "removed") && $2 == ENVIRON["LEAF"] { r = $1 "\t" $3 }
                        END { print r }' "$COLD_PROGRESS")"
                fi
                IFS=$'\t' read -r kind identity digest access <<< "$last"
                state=held
                case "$op:$kind" in
                    restore:) if cold_leaf_is "$live" "$sha" "$uid:$gid:${mode#0}" "$acl"; then state=done; fi ;;
                    remove:|remove:removed) if [[ ! -e "$live" && ! -L "$live" ]]; then state=done; fi ;;
                    restore:restored) if cold_leaf_is "$live" "$sha" "$uid:$gid:${mode#0}" "$acl" "$identity"; then state=done; fi ;;
                    restore:deleted) if [[ ! -e "$live" && ! -L "$live" ]]; then state=applied; fi ;;
                    restore:installed|remove:installed)
                        if [[ "$identity" =~ ^[0-9]+:[0-9]+$ ]] && cold_leaf_is "$live" "$digest" \
                            "$SAFEHARBOR_COLD_DEPLOY_OWNER:$SAFEHARBOR_COLD_RUNTIME_GROUP:640" "$access" "$identity"; then
                            state=applied
                        fi ;;
                esac
                case "$state" in
                    done) continue ;;
                    held) printf 'SAFEHARBOR_COLD_PRESERVED=%s\n' "$relative" >&2; cold_rc=2; continue ;;
                esac
                if [[ "$op" == restore ]]; then
                    accepted_leaf="$COLD_SLOT/recovery/$relative"
                    [[ -f "$accepted_leaf" && ! -L "$accepted_leaf" && "$(cold_sha "$accepted_leaf")" == "$sha" ]] \
                        || deploy_fail 'cold-accepted-source-leaf-missing' 65
                    [[ ! -L "$live" ]] || deploy_fail 'cold-live-target-unsafe' 77
                    cold_journal "temp"$'\t'"$relative.sh-cold.$$"
                    /usr/bin/install -o "$uid" -g "$gid" -m "$mode" -- "$accepted_leaf" "$live.sh-cold.$$"
                    # The exact prior access ACL (or none) on the temp, before the rename (SH-R2-01).
                    if [[ "$acl" == - ]]; then
                        /usr/bin/setfacl --remove-all -- "$live.sh-cold.$$"
                        /usr/bin/chmod "$mode" -- "$live.sh-cold.$$"
                    else
                        /usr/bin/setfacl --set="$acl" -- "$live.sh-cold.$$"
                    fi
                    identity="$(/usr/bin/stat -c '%d:%i' -- "$live.sh-cold.$$")" || deploy_fail 'cold-progress-journal-failed' 74
                    cold_journal "restored"$'\t'"$relative"$'\t'"$identity"
                    /usr/bin/mv -f -T -- "$live.sh-cold.$$" "$live"
                    [[ "$(cold_sha "$live")" == "$sha" && "$(/usr/bin/stat -c '%u:%g:%a' -- "$live")" == "$uid:$gid:${mode#0}" \
                        && "$(cold_acl "$live")" == "$acl" ]] || deploy_fail 'cold-inverse-not-exact' 74
                else
                    cold_journal "removed"$'\t'"$relative"
                    /usr/bin/rm -f -- "$live"
                fi
            done < "$COLD_INVERSE"
            # Only the temp siblings this operation journaled, as regular files inside the physical app root; any other
            # *.sh-cold.* leaf is foreign or unknown and stays (it then also fails the previous-digest check below).
            if [[ -f "$COLD_PROGRESS" ]]; then
                while IFS=$'\t' read -r kind relative; do
                    [[ "$kind" == temp && -n "$relative" ]] || continue
                    live="$COLD_APP_ROOT/$relative"
                    [[ -e "$live" || -L "$live" ]] || continue
                    if [[ "$relative" != /* && "/$relative/" != *'/../'* && -f "$live" && ! -L "$live" \
                        && "$(cd -- "$(/usr/bin/dirname -- "$live")" && pwd -P)/${live##*/}" == "$live" ]]; then
                        /usr/bin/rm -f -- "$live"
                    else
                        printf 'SAFEHARBOR_COLD_PRESERVED=%s\n' "$relative" >&2
                        cold_rc=2
                    fi
                done < "$COLD_PROGRESS"
            fi
            while IFS= read -r -d '' relative; do
                relative="${relative#"$COLD_APP_ROOT"/}"
                /usr/bin/grep -qxF -- "temp"$'\t'"$relative" "$COLD_PROGRESS" 2>/dev/null \
                    || printf 'SAFEHARBOR_COLD_FOREIGN_TEMP_PRESERVED=%s\n' "$relative" >&2
            done < <(/usr/bin/find "$COLD_APP_ROOT" -xdev -name '*.sh-cold.*' -print0)
            # A created directory (deepest first): its last record decides. None and absent (journaled before creation,
            # never created) or this inverse's own earlier removal and absent: nothing to do. Its creation record:
            # removed only while it is still exactly that directory (identity, type, owner/group/mode, access ACL), empty
            # and unchanged across a re-stat, its removal journaled first. Anything else (replaced, drifted, holding data,
            # a creation without its record, recorded but gone) is preserved (HOLD).
            if [[ -f "$COLD_DIRS" ]]; then
                mapfile -t cold_created < <(/usr/bin/tac -- "$COLD_DIRS")  # read first: the inverse appends to this file
                for relative in "${cold_created[@]}"; do
                    [[ -n "$relative" && "$relative" != *$'\t'* ]] || continue  # a record, never a directory path
                    live="$COLD_APP_ROOT/$relative"
                    last="$(DIR="$relative" /usr/bin/awk -F'\t' '
                        ($1 == "dir" || $1 == "removed") && $2 == ENVIRON["DIR"] { r = $1 "\t" $3 "\t" $4 "\t" $5 "\t" $6 }
                        END { print r }' "$COLD_DIRS")"
                    kind="${last%%$'\t'*}"; recorded="${last#*$'\t'}"
                    state=held
                    if [[ ! -e "$live" && ! -L "$live" ]]; then
                        [[ "$kind" == dir ]] || state=done
                    elif [[ "$kind" == dir && -d "$live" && ! -L "$live" ]]; then
                        seen="$(/usr/bin/stat -c $'%d:%i\t%F\t%u:%g:%a\t%h %s %Y %Z' -- "$live")" || seen=''
                        if [[ -n "$seen" && "${seen%$'\t'*}"$'\t'"$(cold_acl "$live")" == "$recorded" \
                            && -z "$(/usr/bin/find "$live" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" \
                            && "$(/usr/bin/stat -c $'%d:%i\t%F\t%u:%g:%a\t%h %s %Y %Z' -- "$live" 2>/dev/null)" == "$seen" ]]; then
                            printf 'removed\t%s\n' "$relative" >> "$COLD_DIRS" || deploy_fail 'cold-progress-journal-failed' 74
                            if /usr/bin/rmdir -- "$live" 2>/dev/null; then state=done; fi
                        fi
                    fi
                    if [[ "$state" == held ]]; then printf 'SAFEHARBOR_COLD_PRESERVED=%s/\n' "$relative" >&2; cold_rc=2; fi
                done
            fi
            [[ "$(cold_sha "$COLD_CONFIG")" == "$(cold_value config_sha256 "$COLD_PREPARED")" ]] || deploy_fail 'protected-config-changed' 74
            if (( cold_rc == 0 )); then
                [[ "$(/usr/bin/bash "$COLD_HASHER" "$COLD_APP_ROOT")" == "$(cold_value previous_deployed_sha256 "$COLD_PREPARED")" ]] \
                    || deploy_fail 'cold-inverse-did-not-reproduce-the-previous-deployed-artifact' 74
                # SH-R2-03: only now, with the previous source verified, the exact prior current release record returns,
                # and only over the record THIS operation committed; any other record is preserved (HOLD).
                cold_record=unchanged
                if ! /usr/bin/cmp -s -- "$COLD_PRIOR_RECORD" "$COLD_CURRENT_RECORD"; then
                    cold_ours=''
                    if [[ -f "$COLD_APPLIED" ]]; then
                        cold_ours="$COLD_RECORDS/app-artifact.$SAFEHARBOR_COLD_RELEASE_SHA.$(cold_value deployed_artifact_sha256 "$COLD_APPLIED").manifest"
                    fi
                    if [[ -n "$cold_ours" && -f "$cold_ours" && ! -L "$cold_ours" && -f "$COLD_CURRENT_RECORD" && ! -L "$COLD_CURRENT_RECORD" ]] \
                        && /usr/bin/cmp -s -- "$cold_ours" "$COLD_CURRENT_RECORD"; then
                        cold_protected_dir "$COLD_RECORDS" 700 || deploy_fail 'release-record-directory-not-exact' 77
                        IFS=: read -r record_uid record_gid record_mode <<< "$(cold_value previous_record_meta "$COLD_PREPARED")"
                        [[ "$(cold_sha "$COLD_PRIOR_RECORD")" == "$(cold_value previous_record_sha256 "$COLD_PREPARED")" \
                            && "$record_uid:$record_gid" =~ ^[0-9]+:[0-9]+$ && "$record_mode" =~ ^[0-7]{3,4}$ ]] \
                            || deploy_fail 'cold-prior-record-changed' 65
                        current="$(/usr/bin/mktemp "$COLD_RECORDS/current.XXXXXXXX.manifest")"
                        /usr/bin/install -o "$record_uid" -g "$record_gid" -m "0$record_mode" -- "$COLD_PRIOR_RECORD" "$current"
                        /usr/bin/mv -T -- "$current" "$COLD_CURRENT_RECORD"
                        /usr/bin/cmp -s -- "$COLD_PRIOR_RECORD" "$COLD_CURRENT_RECORD" \
                            && [[ "$(/usr/bin/stat -c '%u:%g:%a' -- "$COLD_CURRENT_RECORD")" == "$record_uid:$record_gid:$record_mode" ]] \
                            || deploy_fail 'cold-prior-record-not-restored' 74
                        cold_record=restored
                    else
                        printf 'SAFEHARBOR_COLD_PRESERVED=%s\n' "current-app-artifact.manifest" >&2
                        cold_record=preserved
                        cold_rc=2
                    fi
                fi
                printf 'SAFEHARBOR_COLD_RELEASE_RECORD=%s\n' "$cold_record"
            fi
            printf 'SAFEHARBOR_COLD=%s\n' "$([[ $cold_rc == 0 ]] && echo inverse-exact || echo inverse-hold)"
            exit "$cold_rc"
            ;;
    esac
    exit 0
fi

[[ "$#" -eq 8 \
    && "$1" == '--release-sha' \
    && "$3" == '--artifact-hasher' \
    && "$5" == '--expect-hasher-sha256' \
    && "$7" == '--expect-source-artifact-sha256' ]] \
    || deploy_fail 'invalid-arguments' 64
readonly RELEASE_SHA="$2"
readonly ARTIFACT_HASHER="$4"
readonly EXPECT_HASHER_SHA256="$6"
readonly EXPECT_SOURCE_ARTIFACT_SHA256="$8"
[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] \
    || deploy_fail 'release-sha-invalid' 65
[[ "$EXPECT_HASHER_SHA256" =~ ^[0-9a-f]{64}$ ]] \
    || deploy_fail 'hasher-sha256-invalid' 65
[[ "$EXPECT_SOURCE_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] \
    || deploy_fail 'source-artifact-sha256-invalid' 65

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
readonly RELEASE_RECORD_DIR="$DEPLOY_LOCK_DIR/releases"
readonly CURRENT_RELEASE_RECORD="$RELEASE_RECORD_DIR/current-app-artifact.manifest"
readonly STAGING_DIR="$ROOT_PREFIX/run/safeharbor-deploy"

for required_tool in /usr/bin/bash /usr/bin/cat /usr/bin/chmod /usr/bin/chown \
    /usr/bin/cmp /usr/bin/date /usr/bin/find /usr/bin/flock /usr/bin/install \
    /usr/bin/mktemp /usr/bin/mv /usr/bin/pgrep /usr/bin/sed \
    /usr/bin/rm /usr/bin/sha256sum /usr/bin/sort /usr/bin/stat /usr/bin/tar \
    /usr/bin/unlink; do
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
verify_physical_directory "$STAGING_DIR" 'deploy-staging-directory'
[[ "${ARTIFACT_HASHER%/*}" == "$STAGING_DIR" \
    && "${ARTIFACT_HASHER##*/}" =~ ^hash-safeharbor-app-artifact\.$EXPECT_HASHER_SHA256\.[0-9A-Za-z_-]+\.sh$ ]] \
    || deploy_fail 'artifact-hasher-path-invalid' 65
verify_metadata "$ARTIFACT_HASHER" root root 600 'artifact-hasher'
hasher_actual="$(/usr/bin/sha256sum -- "$ARTIFACT_HASHER")"
hasher_actual="${hasher_actual%% *}"
[[ "$hasher_actual" == "$EXPECT_HASHER_SHA256" ]] \
    || deploy_fail 'artifact-hasher-digest-mismatch' 65
payload="$(/usr/bin/mktemp "$STAGING_DIR/app.XXXXXXXX.tar.gz")" \
    || deploy_fail 'payload-staging-failed' 74
payload_check_dir=''
cleanup_payload() {
    /usr/bin/unlink -- "$payload" 2>/dev/null || true
    if [[ -n "$payload_check_dir" ]]; then
        case "$payload_check_dir" in
            "$STAGING_DIR"/source-check.*)
                /usr/bin/rm -rf -- "$payload_check_dir" 2>/dev/null || true
                ;;
        esac
    fi
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

# Prove the received archive has the complete clean-source digest before it can
# touch the non-atomic live tree. The second post-extraction check below also
# catches stale live paths that the overlay deployment did not replace.
payload_check_dir="$(/usr/bin/mktemp -d "$STAGING_DIR/source-check.XXXXXXXX")" \
    || deploy_fail 'source-check-staging-failed' 74
verify_metadata "$payload_check_dir" root root 700 'source-check-directory'
/usr/bin/tar -xzf "$payload" -C "$payload_check_dir" \
    --no-same-owner --no-same-permissions
staged_source_artifact="$(/usr/bin/bash "$ARTIFACT_HASHER" "$payload_check_dir/app")" \
    || deploy_fail 'staged-source-artifact-hash-failed' 74
[[ "$staged_source_artifact" == "$EXPECT_SOURCE_ARTIFACT_SHA256" ]] \
    || deploy_fail 'staged-source-artifact-digest-mismatch' 65
/usr/bin/rm -rf -- "$payload_check_dir"
payload_check_dir=''

config_before="$(/usr/bin/sha256sum -- "$PROTECTED_CONFIG")"
config_before="${config_before%% *}"
/usr/bin/tar -xzf "$payload" -C "$APP_ROOT" --strip-components=1 \
    --no-same-owner --no-same-permissions
source_artifact_actual="$(/usr/bin/bash "$ARTIFACT_HASHER" "$APP_ROOT")" \
    || deploy_fail 'source-artifact-hash-failed' 74
[[ "$source_artifact_actual" == "$EXPECT_SOURCE_ARTIFACT_SHA256" ]] \
    || deploy_fail 'source-artifact-digest-mismatch' 65
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
deployed_artifact_sha256="$(/usr/bin/bash "$ARTIFACT_HASHER" "$APP_ROOT")" \
    || deploy_fail 'deployed-artifact-hash-failed' 74
[[ "$deployed_artifact_sha256" =~ ^[0-9a-f]{64}$ ]] \
    || deploy_fail 'deployed-artifact-hash-invalid' 74

if path_absent "$RELEASE_RECORD_DIR"; then
    /usr/bin/install -d -o root -g root -m 0700 -- "$RELEASE_RECORD_DIR"
fi
verify_metadata "$RELEASE_RECORD_DIR" root root 700 'release-record-directory'
verify_physical_directory "$RELEASE_RECORD_DIR" 'release-record-directory'
unique_record="$RELEASE_RECORD_DIR/app-artifact.$RELEASE_SHA.$deployed_artifact_sha256.manifest"
record_temp="$(/usr/bin/mktemp "$RELEASE_RECORD_DIR/app-artifact.XXXXXXXX.manifest")" \
    || deploy_fail 'release-record-staging-failed' 74
current_temp=''
cleanup_record() {
    [[ -z "$record_temp" ]] || /usr/bin/unlink -- "$record_temp" 2>/dev/null || true
    [[ -z "$current_temp" ]] || /usr/bin/unlink -- "$current_temp" 2>/dev/null || true
}
trap 'cleanup_record; cleanup_payload' EXIT
/usr/bin/printf '%s\n' \
    'schema=safeharbor-deployed-app-artifact-v1' \
    "release_sha=$RELEASE_SHA" \
    "source_artifact_sha256=$EXPECT_SOURCE_ARTIFACT_SHA256" \
    "deployed_artifact_sha256=$deployed_artifact_sha256" \
    "hasher_sha256=$EXPECT_HASHER_SHA256" \
    > "$record_temp"
/usr/bin/chown root:root -- "$record_temp"
/usr/bin/chmod 0600 -- "$record_temp"
verify_metadata "$record_temp" root root 600 'staged-release-record'

if path_absent "$unique_record"; then
    /usr/bin/mv -T -- "$record_temp" "$unique_record"
    record_temp=''
else
    verify_metadata "$unique_record" root root 600 'immutable-release-record'
    /usr/bin/cmp -s -- "$record_temp" "$unique_record" \
        || deploy_fail 'immutable-release-record-conflict' 65
fi
verify_metadata "$unique_record" root root 600 'immutable-release-record'

current_temp="$(/usr/bin/mktemp "$RELEASE_RECORD_DIR/current.XXXXXXXX.manifest")" \
    || deploy_fail 'current-release-record-staging-failed' 74
/usr/bin/install -o root -g root -m 0600 -- "$unique_record" "$current_temp"
/usr/bin/cmp -s -- "$unique_record" "$current_temp" \
    || deploy_fail 'current-release-record-copy-mismatch' 74
/usr/bin/mv -T -- "$current_temp" "$CURRENT_RELEASE_RECORD"
current_temp=''
verify_metadata "$CURRENT_RELEASE_RECORD" root root 600 'current-release-record'
/usr/bin/cmp -s -- "$unique_record" "$CURRENT_RELEASE_RECORD" \
    || deploy_fail 'current-release-record-content-mismatch' 74

printf 'SAFEHARBOR_DEPLOY=installed-under-exclusive-report-lock\n'
printf 'SAFEHARBOR_RELEASE_SHA=%s\n' "$RELEASE_SHA"
printf 'SAFEHARBOR_DEPLOYED_ARTIFACT_SHA256=%s\n' "$deployed_artifact_sha256"
