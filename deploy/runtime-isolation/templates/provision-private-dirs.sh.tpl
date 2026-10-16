#!/usr/bin/env bash
# GENERATED OFFLINE CANDIDATE for {{APP}} - private runtime directory check. Not installed.
# Input digest: {{INPUT_SHA256}}
#
# Default: verify only. It prints every mismatch and changes nothing.
# --apply-missing: also creates an ABSENT private tree (a top directory plus its direct children) with the
# exact owner, group and mode, in an order the app UID cannot race: the top is created root:root 0700 under
# a root-owned ancestor chain, the children are created inside it while only root can enter, and only then
# is the top handed over by a second install -d on the directory this run created. It refuses to create
# anything inside a directory that already exists app-owned, because the app UID could swap that path
# between the absence check and the creation. It never adopts, re-owns or re-modes an existing path, never
# recurses into existing trees, and never touches units, packages, accounts, ACLs, cron files or
# application code. Running it at all is a separate, owner-approved live step; generating it authorizes
# nothing.
# Unsupported prerequisites (not enforced here): no other root process, package script or mount changes
# these paths during the run; the app UID holds no capabilities (CAP_DAC_OVERRIDE, CAP_FOWNER, CAP_CHOWN)
# and no root-equivalent helper. Checks of paths that already exist under an app-owned directory are a
# point-in-time read only; the app can change its own tree afterwards.
set -euo pipefail
umask 077
export LC_ALL=C

readonly APP_USER='{{APP_USER}}'
readonly APP_GROUP='{{APP_GROUP}}'
readonly APP_UID={{APP_UID}}
readonly APP_GID={{APP_GID}}
readonly FRONTEND_USER='{{FRONTEND_USER}}'
readonly FRONTEND_GID={{FRONTEND_GID}}
readonly DEPLOY_UID={{DEPLOY_UID}}
readonly APP_ROOT='{{APP_ROOT}}'
readonly SOCKET_DIR='{{SOCKET_DIR}}'
readonly SOCKET='{{SOCKET}}'

mode=verify
if [[ $# -eq 1 && "$1" == --apply-missing ]]; then
    mode=apply
elif [[ $# -ne 0 ]]; then
    printf 'usage: %s [--apply-missing]\n' "$0" >&2
    exit 64
fi
[[ "$(/usr/bin/uname -s)" == Linux && "${EUID}" -eq 0 ]] \
    || { printf 'refused: root on Linux is required to read exact metadata\n' >&2; exit 77; }

failures=0
fail() { printf 'MISMATCH %s\n' "$*"; failures=$((failures + 1)); }
meta() { /usr/bin/stat -c '%u:%g:%a:%F' -- "$1"; }
# Root may create a path only when every ancestor up to / is a physical root-owned directory that no group
# or other user can write; otherwise an unprivileged process could swap a component during the creation.
# Sticky world-writable directories such as /tmp fail too. ACL entries cannot grant write without the
# group write bit that this reads.
protected_chain() {
    local d
    d="$(dirname -- "$1")"
    while :; do
        if [[ -L "$d" || ! -d "$d" ]]; then fail "$1 ancestor $d is not a physical directory"; return 1; fi
        if [[ "$(/usr/bin/stat -c '%u' -- "$d")" != 0 ]] || (( (8#$(/usr/bin/stat -c '%a' -- "$d") & 8#022) != 0 )); then
            fail "$1 ancestor $d is not root-owned without group or other write"
            return 1
        fi
        [[ "$d" != / ]] || return 0
        d="$(dirname -- "$d")"
    done
}

# ---- identity: dedicated uid/gid, no login shell, no supplementary groups, frontend outside the app group
IFS=: read -r _ _ uid gid _ _ shell <<<"$(/usr/bin/getent passwd "$APP_USER" || true)"
[[ "$uid" == "$APP_UID" && "$gid" == "$APP_GID" ]] || fail "identity $APP_USER is not $APP_UID:$APP_GID"
[[ "$shell" == /usr/sbin/nologin || "$shell" == /bin/false ]] || fail "identity $APP_USER has a login shell"
[[ "$(/usr/bin/getent group "$APP_GROUP" | /usr/bin/cut -d: -f3)" == "$APP_GID" ]] || fail "group $APP_GROUP is not $APP_GID"
[[ "$(/usr/bin/id -G "$APP_USER" 2>/dev/null || true)" == "$APP_GID" ]] || fail "identity $APP_USER has supplementary groups"
for group_id in $(/usr/bin/id -G "$FRONTEND_USER" 2>/dev/null || true); do
    [[ "$group_id" != "$APP_GID" ]] || fail "frontend $FRONTEND_USER belongs to $APP_GROUP"
done
if (( failures )) && [[ "$mode" == apply ]]; then
    printf 'RESULT refused: identity mismatches=%d; nothing was created\n' "$failures"
    exit 1
fi

ensure_root_ancestor() {
    local p="$1"
    if [[ -L "$p" ]]; then fail "$p is a symlink"; return; fi
    if [[ -e "$p" ]]; then
        [[ "$(meta "$p")" == '0:0:755:directory' ]] || fail "$p is $(meta "$p"), expected 0:0:755:directory"
        return
    fi
    protected_chain "$p" || return 0
    if [[ "$mode" == apply ]]; then
        /usr/bin/install -d -o root -g root -m 0755 -- "$p"
        printf 'CREATED %s\n' "$p"
    else
        fail "$p is absent"
    fi
}

# One private tree: a top directory under a protected root ancestor and its direct children, all
# APP_UID:APP_GID 0700 at the end.
ensure_private_tree() {
    local top="$1" child p
    shift
    if [[ -L "$top" ]]; then fail "$top is a symlink"; return; fi
    if [[ -e "$top" ]]; then
        if [[ "$(meta "$top")" != "$APP_UID:$APP_GID:700:directory" ]]; then
            fail "$top is $(meta "$top"); existing paths are never adopted, migrate them by reviewed per-path steps"
            return
        fi
        # The app UID can write here, so nothing is created inside it.
        for child in "$@"; do
            p="$top/$child"
            if [[ -L "$p" ]]; then
                fail "$p is a symlink"
            elif [[ -e "$p" ]]; then
                [[ "$(meta "$p")" == "$APP_UID:$APP_GID:700:directory" ]] \
                    || fail "$p is $(meta "$p"); existing paths are never adopted, migrate them by reviewed per-path steps"
            elif [[ "$mode" == apply ]]; then
                fail "$p is absent under the existing app-owned $top; --apply-missing refuses to create inside an app-writable directory"
            else
                fail "$p is absent"
            fi
        done
        return
    fi
    protected_chain "$top" || return 0
    if [[ "$mode" != apply ]]; then
        fail "$top is absent"
        for child in "$@"; do fail "$top/$child is absent"; done
        return
    fi
    /usr/bin/install -d -o root -g root -m 0700 -- "$top"
    [[ ! -L "$top" && "$(meta "$top")" == '0:0:700:directory' ]] \
        || { fail "$top failed verification after root-only creation; it is left for review"; return; }
    for child in "$@"; do
        p="$top/$child"
        /usr/bin/install -d -o "$APP_UID" -g "$APP_GID" -m 0700 -- "$p"
        [[ ! -L "$p" && "$(meta "$p")" == "$APP_UID:$APP_GID:700:directory" ]] \
            || { fail "$p failed verification inside the root-only $top; $top is left root-owned for review"; return; }
        printf 'CREATED %s\n' "$p"
    done
    # Hand-over: the second install -d acts only on the directory this run created under a protected chain.
    /usr/bin/install -d -o "$APP_UID" -g "$APP_GID" -m 0700 -- "$top"
    [[ ! -L "$top" && "$(meta "$top")" == "$APP_UID:$APP_GID:700:directory" ]] \
        || { fail "$top failed verification after the hand-over"; return; }
    printf 'CREATED %s\n' "$top"
}

verify_code_tree() {
    local hit
    [[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || { fail "$APP_ROOT is not a physical directory"; return; }
    [[ "$(meta "$APP_ROOT")" == "$DEPLOY_UID:$APP_GID:{{CODE_DIR_MODE}}:directory" ]] \
        || fail "$APP_ROOT is $(meta "$APP_ROOT"), expected $DEPLOY_UID:$APP_GID:{{CODE_DIR_MODE}}"
    hit="$(/usr/bin/find "$APP_ROOT" -xdev \( -type l -o -perm -0002 -o -perm -0020 -o -uid "$APP_UID" \) -print -quit)"
    [[ -z "$hit" ]] || fail "application code is a symlink, writable or runtime-owned at $hit"
    hit="$(/usr/bin/find "$APP_ROOT" -xdev -type f ! -perm {{CODE_FILE_MODE}} -print -quit)"
    [[ -z "$hit" ]] || fail "application file mode differs from {{CODE_FILE_MODE}} at $hit"
}

verify_config() {
    local p="$1" owner="$2" group="$3" perm="$4"
    [[ -f "$p" && ! -L "$p" ]] || { fail "$p is not a regular file"; return; }
    [[ "$(meta "$p")" == "$owner:$group:$perm:regular file" ]] || fail "$p is $(meta "$p"), expected $owner:$group:$perm"
}

verify_socket() {
    # The socket directory belongs to the dedicated master; this only reads it when present.
    if [[ -e "$SOCKET_DIR" || -L "$SOCKET_DIR" ]]; then
        [[ ! -L "$SOCKET_DIR" && "$(meta "$SOCKET_DIR")" == "0:$FRONTEND_GID:710:directory" ]] \
            || fail "$SOCKET_DIR is $(meta "$SOCKET_DIR"), expected 0:$FRONTEND_GID:710"
    fi
    if [[ -e "$SOCKET" || -L "$SOCKET" ]]; then
        [[ ! -L "$SOCKET" && "$(meta "$SOCKET")" == "0:$FRONTEND_GID:660:socket" ]] \
            || fail "$SOCKET is $(meta "$SOCKET"), expected 0:$FRONTEND_GID:660 socket"
    fi
}

{{ROOT_ANCESTOR_CHECKS}}
{{PRIVATE_DIR_CHECKS}}
verify_code_tree
{{CONFIG_CHECKS}}
verify_socket

if (( failures )); then
    printf 'RESULT mismatches=%d mode=%s; no existing path was changed\n' "$failures" "$mode"
    exit 1
fi
printf 'RESULT ok mode=%s; a candidate check only, not isolation evidence\n' "$mode"
