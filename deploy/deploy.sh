#!/usr/bin/env bash
# Deploy Safeharbor to production. This is the one deploy path; deploy/README.md explains it.
#
#   bash deploy/deploy.sh                    ship the newest merged code (origin/main)
#   bash deploy/deploy.sh <commit>           ship a commit that is already on main (also how to go further back)
#   bash deploy/deploy.sh --check            show what would change on the server; change nothing
#   bash deploy/deploy.sh --rollback         undo the most recent deploy from its backup
#   bash deploy/deploy.sh --migrate <file>   also apply this database change, e.g. app/db/migrations/032_x.sql
#                                            (repeatable; applied in the order given, after a database backup)
#
# Run it from Git Bash in a clone of this repo. It reaches the server through the `milepost-ec2` SSH
# alias; DEPLOY_HOST picks another host and DEPLOY_SSH_CONFIG points ssh/scp at another config file.
#
# On the server it backs up every file it will change, pauses the jobs that run Safeharbor code (and the
# 365 sign-up job), stops only Safeharbor's PHP runtime, copies the changed files in with the same owner
# and permissions as the files they replace, starts the runtime again, checks the real HTTPS login page,
# and puts the backup back by itself if anything fails. Every other site on the server stays up.
set -euo pipefail
umask 077

# ---- Safeharbor settings ---------------------------------------------------------------------------
APP=safeharbor
APP_ROOT=/srv/8west/apps/safeharbor/current
AREAS=(.)                         # what the release owns inside APP_ROOT (. means all of it)
KEEP=(config/config.php)          # server-only files inside those areas: never written, never deleted
EXEC_FROM_GIT=0                   # every Safeharbor file stays 0640; its shell scripts run through bash
RUNTIME_UNIT=8west-php-safeharbor.service
RUNTIME_USER=eightwest-safeharbor
STATE_DIR=/srv/8west/apps/safeharbor/deploy-state
DB_NAME=safeharbor
SITE=safeharbor.8westit.com
CHECK_PAGE=/login.php
CHECK_TEXT='<title>Sign in · Safeharbor</title>'
CHECK_ASSET=/assets/css/app.css
SHARED_TIMERS=(8west-suite-onboarding.timer)   # the 365 sign-up job creates clients in every linked app
# The business-report runner holds the shared side of this lock while a report runs.
REPORT_LOCK=/var/lib/safeharbor-report-scheduler/business-reports-deploy.lock
# Where the previous deploy tool recorded the live commit; read only while current.sha does not exist.
OLD_RELEASE_RECORD=/var/lib/safeharbor-report-scheduler/releases/current-app-artifact.manifest

git_paths() { echo app brand; }   # what goes into the release archive

prepare_release() { # <stage>: shape the unpacked archive into what the server runs, and set NEW
  local stage="$1" f
  install -d "$stage/app/public/assets/brand"
  for f in svg/favicon.svg svg/safeharbor-mark.svg png/favicon.ico png/apple-touch-icon.png \
           png/app-tile-192.png png/app-tile-512.png \
           png/safeharbor-logo-horizontal-transparent-20260909.png png/safeharbor-logo-square-20260907.png; do
    cp -- "$stage/brand/$f" "$stage/app/public/assets/brand/"
  done
  # Browsers fetch fresh CSS and JavaScript whenever this version tag changes.
  sed -i "s/?v=[0-9A-Za-z]\+/?v=${RELEASE_SHA:0:12}/g" \
    "$stage/app/lib/render.php" "$stage/app/lib/westy.php" "$stage/app/public/login.php"
  NEW="$stage/app"
}

extra_checks() { :; }             # Safeharbor has no app-specific reason to refuse a deploy

# ---- Everything below this line is the same in every 8 West app's deploy script -------------------

die() { printf 'deploy: %s\n' "$*" >&2; exit 1; }

# On your computer: pack the release straight from git and hand it to the server.
run_local() {
  local mode=deploy target='' migrations=() m i=0
  while (( $# )); do
    case "$1" in
      --check) mode=check ;;
      --rollback) mode=rollback ;;
      --migrate) [[ $# -ge 2 ]] || die '--migrate needs a file'; migrations+=("$2"); shift ;;
      -h|--help) sed -n '2,18p' "${BASH_SOURCE[0]}"; exit 0 ;;
      -*) die "unknown option: $1 (try --help)" ;;
      *) [[ -z "$target" ]] || die 'give at most one commit'; target="$1" ;;
    esac
    shift
  done
  if [[ "$mode" == rollback && ( -n "$target" || ${#migrations[@]} -gt 0 ) ]]; then
    die '--rollback takes no commit and no --migrate'
  fi

  local repo host sha=none remote rc=0
  local ssh=(ssh -o BatchMode=yes) scp=(scp -q -o BatchMode=yes)
  repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  host="${DEPLOY_HOST:-milepost-ec2}"
  if [[ -n "${DEPLOY_SSH_CONFIG:-}" ]]; then ssh+=(-F "$DEPLOY_SSH_CONFIG"); scp+=(-F "$DEPLOY_SSH_CONFIG"); fi
  WORK="$(mktemp -d)"
  trap 'rm -rf -- "$WORK"' EXIT
  tr -d '\r' < "${BASH_SOURCE[0]}" > "$WORK/deploy.sh"     # a Windows checkout may carry CRLF endings

  if [[ "$mode" != rollback ]]; then
    git -C "$repo" fetch --quiet origin main
    sha="$(git -C "$repo" rev-parse --verify --quiet "${target:-origin/main}^{commit}")" \
      || die "unknown commit: ${target:-origin/main}"
    git -C "$repo" merge-base --is-ancestor "$sha" origin/main \
      || die "${sha:0:12} is not on main; only merged code is deployed"
    echo "==> Packing $APP ${sha:0:12} from git"
    # shellcheck disable=SC2046  # git_paths is a fixed list of words
    git -C "$repo" -c core.autocrlf=false -c core.eol=lf archive --format=tar.gz "$sha" $(git_paths) \
      > "$WORK/release.tar.gz"
    for m in "${migrations[@]}"; do
      git -C "$repo" cat-file -e "$sha:$m" 2> /dev/null || die "$m is not part of ${sha:0:12}"
      i=$((i + 1))
      git -C "$repo" show "$sha:$m" > "$WORK/migration-$(printf '%02d' "$i")-${m##*/}"
    done
  fi

  remote="$("${ssh[@]}" "$host" "mktemp -d /tmp/deploy-$APP.XXXXXXXX")" || die "cannot reach $host"
  "${scp[@]}" "$WORK"/* "$host:$remote/"
  "${ssh[@]}" "$host" "sudo bash $remote/deploy.sh --on-server $mode $sha $remote" || rc=$?
  if (( rc == 255 )); then
    echo "deploy: the connection to $host dropped. The server finishes (or undoes) the deploy by itself;" >&2
    echo "deploy: run 'bash deploy/deploy.sh --check' in a minute to see where it ended." >&2
  fi
  exit "$rc"
}

# On the server, as root. Messages go to the screen and to $STATE_DIR/last-run.log.
say() { printf '%s\n' "$*" >> "$STATE_DIR/last-run.log"; printf '%s\n' "$*" 2> /dev/null || true; }
fail() { say "FAILED: $*"; exit 1; }
record() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >> "$STATE_DIR/history.log"; }

run_server() {
  MODE="$1" RELEASE_SHA="$2" UPLOAD="$3"
  [[ $EUID -eq 0 ]] || die 'the server half must run as root'
  [[ "$UPLOAD" == /tmp/deploy-"$APP".* && -d "$UPLOAD" && ! -L "$UPLOAD" ]] || die "unexpected upload folder: $UPLOAD"
  PHASE=prepare APP_STOPPED=0 BACKUP='' PAUSED_CRON=() STOPPED_TIMERS=()
  if [[ ! -d "$STATE_DIR" ]]; then install -d -m 0700 "$STATE_DIR"; fi
  if [[ ! -d "$STATE_DIR/backups" ]]; then install -d -m 0700 "$STATE_DIR/backups"; fi
  : > "$STATE_DIR/last-run.log"
  trap on_exit EXIT
  trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM; trap '' PIPE

  exec 8> "/run/lock/8west-deploy-$APP.lock"
  flock -n 8 || fail "another $APP deploy is already running"
  restore_leftover_pauses
  local f
  for f in /var/lib/8west-cold-window/"$APP".active /var/lib/8west-cold-window/suite.active; do
    [[ ! -e "$f" ]] || fail "a cold-window operation is still open ($f); finish or close it first"
  done
  [[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || fail "$APP_ROOT is missing"
  systemctl cat "$RUNTIME_UNIT" > /dev/null 2>&1 || fail "$RUNTIME_UNIT is not installed"
  extra_checks

  case "$MODE" in
    check) server_check ;;
    deploy) server_deploy ;;
    rollback) server_rollback ;;
    *) fail "unknown mode: $MODE" ;;
  esac
}

# If anything stops the script part-way, leave the app running on a known version with its jobs resumed.
on_exit() {
  local rc=$?
  trap - EXIT
  set +e
  if [[ "$PHASE" == files ]]; then
    say 'Something went wrong while the files were changing. Putting the previous version back.'
    roll_back_files
    record "FAILED $RELEASE_SHA (stopped part-way; previous version restored)"
  fi
  if (( APP_STOPPED )); then start_app || say "WARNING: $RUNTIME_UNIT did not start again. Look at the server now."; fi
  if [[ "$PHASE" == migrate ]]; then
    say "A database change failed. The app is back on its previous code. Database backup: $BACKUP/database.sql.gz"
  fi
  resume_jobs
  rm -rf -- "$UPLOAD"
  (( rc == 0 )) || say "Stopped with exit code $rc. Full log: $STATE_DIR/last-run.log"
  exit "$rc"
}

server_check() {
  local m f t
  say "Live version:   $(live_version)"
  say "Target version: $RELEASE_SHA"
  stage_release
  make_plan
  report_plan
  for m in "$UPLOAD"/migration-*.sql; do
    if [[ -f "$m" ]]; then say "Database change to apply: ${m##*/}"; fi
  done
  say 'Jobs that would pause during the deploy:'
  while IFS= read -r f; do say "  cron file  $f"; done < <(app_cron_files)
  for t in "${SHARED_TIMERS[@]}"; do
    if systemctl is-active --quiet "$t"; then say "  timer      $t"; fi
  done
  while IFS= read -r f; do say "  running now (a deploy waits for it): $f"; done < <(pgrep -af -- "${APP_ROOT%/*}/" || true)
  say "Runtime $RUNTIME_UNIT: $(systemctl is-active "$RUNTIME_UNIT" || true)"
  if healthy; then say 'Site check right now: passing'; else say 'Site check right now: FAILING'; fi
  say "Free space for backups: $(df -h --output=avail "$STATE_DIR" | tail -n 1 | tr -d ' ')"
  say 'Nothing was changed.'
}

server_deploy() {
  local from m db=''
  local migrations=()
  from="$(live_version)"
  stage_release
  make_plan
  for m in "$UPLOAD"/migration-*.sql; do
    if [[ -f "$m" ]]; then migrations+=("$m"); fi
  done
  say "Deploying $APP ${RELEASE_SHA:0:12} (live now: ${from:0:12})"
  report_plan
  if [[ ! -s "$PLAN/changed" && ! -s "$PLAN/added" && ! -s "$PLAN/deleted" \
        && ! -s "$PLAN/newdirs" && ! -s "$PLAN/olddirs" && ${#migrations[@]} -eq 0 ]]; then
    write_state "$RELEASE_SHA"
    say 'The server already runs exactly this release. Nothing to do.'
    return 0
  fi

  BACKUP="$STATE_DIR/backups/$(date -u +%Y%m%dT%H%M%SZ)-${from:0:12}-to-${RELEASE_SHA:0:12}"
  make_backup "$from"
  if (( ${#migrations[@]} )); then
    db="$(db_name)"
    say "Backing up database $db"
    mysqldump --single-transaction --quick --routines --triggers --events "$db" | gzip -c > "$BACKUP/database.sql.gz"
  fi
  pause_jobs
  stop_app
  if (( ${#migrations[@]} )); then
    PHASE=migrate
    for m in "${migrations[@]}"; do
      say "Applying database change ${m##*/}"
      mysql "$db" < "$m"
    done
  fi
  PHASE=files
  apply_files
  verify_files || fail 'the new files did not pass the read and PHP syntax check'
  start_app
  if wait_healthy; then
    PHASE=finished
    write_state "$RELEASE_SHA"
    touch "$BACKUP/deployed"
    resume_jobs
    prune_backups
    record "deployed $from -> $RELEASE_SHA"
    say "Live: https://$SITE (${RELEASE_SHA:0:12}). Backup kept in $BACKUP"
  else
    say "The new version failed its check (https://$SITE$CHECK_PAGE). Putting the previous version back."
    roll_back_files
    PHASE=finished
    record "FAILED $from -> $RELEASE_SHA (check failed; previous version restored)"
    exit 1
  fi
}

server_rollback() {
  local live b='' d
  live="$(live_version)"
  for d in "$STATE_DIR"/backups/*-to-*/; do
    d="${d%/}"
    if [[ -f "$d/deployed" && ! -f "$d/rolled-back" ]]; then b="$d"; fi
  done
  [[ -n "$b" ]] || fail 'there is no deploy backup to roll back to'
  [[ "$(cat "$b/to")" == "$live" ]] \
    || fail "the live version (${live:0:12}) is not the one the newest backup undoes ($(cut -c1-12 < "$b/to"))"
  RELEASE_SHA="$(cat "$b/from")" BACKUP="$b"
  say "Rolling $APP back from ${live:0:12} to ${RELEASE_SHA:0:12} using $b"
  pause_jobs
  stop_app
  PHASE=files
  undo_backup "$b"
  start_app
  PHASE=finished
  touch "$b/rolled-back"
  write_state "$RELEASE_SHA"
  if wait_healthy; then
    say "Rolled back. Live: https://$SITE (${RELEASE_SHA:0:12})"
  else
    say "WARNING: rolled back, but https://$SITE fails its check. Look at it now."
  fi
  if [[ -f "$b/database.sql.gz" ]]; then
    say "The database was not changed back. If that deploy's database change must go, restore $b/database.sql.gz by hand."
  fi
  resume_jobs
  record "rolled back $live -> $RELEASE_SHA"
}

stage_release() {
  local stage="$UPLOAD/stage"
  install -d -m 0700 "$stage"
  tar -xzf "$UPLOAD/release.tar.gz" -C "$stage" --no-same-owner --preserve-permissions
  prepare_release "$stage"
  [[ -d "$NEW" ]] || fail 'the release archive is empty'
}

# Only plain files and folders with ordinary names may live in the parts the release owns.
check_tree() { # <root>
  local area
  for area in "${AREAS[@]}"; do
    [[ -e "$1/$area" ]] || continue
    if find "$1/$area" -mindepth 1 \( \( ! -type f ! -type d \) -o -name $'*[\n\t\\\\]*' \) -print -quit | grep -q .; then
      fail "$1/$area holds a link, special file or unusual file name; sort that out by hand first"
    fi
  done
}

tree_list() { # <root> <f|d>: sorted paths inside the owned areas, minus KEEP
  local area pre
  for area in "${AREAS[@]}"; do
    [[ -d "$1/$area" ]] || continue
    pre=''
    if [[ "$area" != . ]]; then pre="$area/"; fi
    find "$1/$area" -mindepth 1 -type "$2" -printf "$pre%P\n"
  done | { grep -vxF -f <(printf '%s\n' "${KEEP[@]}") || true; } | LC_ALL=C sort
}

file_hashes() { # <root> <list>: one sha256 per listed file, in list order
  (cd "$1" && tr '\n' '\0' < "$2" | xargs -0 -r sha256sum -- | cut -c1-64)
}

make_plan() {
  PLAN="$UPLOAD/plan"
  install -d -m 0700 "$PLAN"
  check_tree "$APP_ROOT"
  check_tree "$NEW"
  tree_list "$APP_ROOT" f > "$PLAN/live.f"
  tree_list "$APP_ROOT" d > "$PLAN/live.d"
  tree_list "$NEW" f > "$PLAN/new.f"
  tree_list "$NEW" d > "$PLAN/new.d"
  if comm -12 "$PLAN/live.f" "$PLAN/new.d" | grep -q . || comm -12 "$PLAN/live.d" "$PLAN/new.f" | grep -q .; then
    fail 'a path changes between file and folder in this release; deploy that one by hand'
  fi
  comm -13 "$PLAN/live.f" "$PLAN/new.f" > "$PLAN/added"
  comm -23 "$PLAN/live.f" "$PLAN/new.f" > "$PLAN/deleted"
  comm -13 "$PLAN/live.d" "$PLAN/new.d" > "$PLAN/newdirs"
  comm -23 "$PLAN/live.d" "$PLAN/new.d" > "$PLAN/olddirs"
  comm -12 "$PLAN/live.f" "$PLAN/new.f" > "$PLAN/common"
  paste <(file_hashes "$APP_ROOT" "$PLAN/common") <(file_hashes "$NEW" "$PLAN/common") "$PLAN/common" \
    | awk -F '\t' '$1 != $2 { print $3 }' > "$PLAN/changed"
}

report_plan() {
  local kind p n
  say "Files: $(wc -l < "$PLAN/changed") changed, $(wc -l < "$PLAN/added") added, $(wc -l < "$PLAN/deleted") removed." \
      "Folders: $(wc -l < "$PLAN/newdirs") added, $(wc -l < "$PLAN/olddirs") removed."
  for kind in changed added deleted; do
    while IFS= read -r p; do say "  $kind  $p"; done < <(head -n 25 "$PLAN/$kind")
    n="$(wc -l < "$PLAN/$kind")"
    if (( n > 25 )); then say "  ... and $((n - 25)) more $kind"; fi
  done
}

# Keep a copy of everything this deploy will replace or remove, with owners, modes and ACLs.
make_backup() { # <from sha>
  install -d -m 0700 "$BACKUP"
  cp -- "$PLAN/added" "$PLAN/changed" "$PLAN/deleted" "$PLAN/newdirs" "$PLAN/olddirs" "$BACKUP/"
  printf '%s\n' "$1" > "$BACKUP/from"
  printf '%s\n' "$RELEASE_SHA" > "$BACKUP/to"
  cat "$PLAN/olddirs" "$PLAN/changed" "$PLAN/deleted" > "$BACKUP/files.list"
  tar --create --file="$BACKUP/files.tar" --acls --xattrs --numeric-owner --no-recursion \
    --directory="$APP_ROOT" --verbatim-files-from --files-from="$BACKUP/files.list"
  say "Backed up $(wc -l < "$BACKUP/files.list") files and folders to $BACKUP"
}

# Put back exactly what a deploy replaced or removed, and remove what it added.
undo_backup() { # <backup dir>
  local b="$1" area p
  for area in "${AREAS[@]}"; do
    find "$APP_ROOT/$area" -xdev -type f -name '.deploy-tmp-*' -delete 2> /dev/null || true
  done
  while IFS= read -r p; do rm -f -- "$APP_ROOT/$p"; done < "$b/added"
  while IFS= read -r p; do rmdir -- "$APP_ROOT/$p" 2> /dev/null || true; done < <(LC_ALL=C sort -r "$b/newdirs")
  tar --extract --file="$b/files.tar" --acls --xattrs --numeric-owner --same-owner --preserve-permissions \
    --directory="$APP_ROOT"
}

roll_back_files() {
  APP_STOPPED=1
  systemctl stop "$RUNTIME_UNIT" || true
  undo_backup "$BACKUP"
  if start_app && wait_healthy; then
    say 'The previous version is running again.'
  else
    say "WARNING: the previous version does not pass its check either. Look at https://$SITE now."
  fi
}

copy_meta() { # <reference> <target>: same owner, group, mode and ACL as the reference
  chown --reference="$1" -- "$2"
  chmod --reference="$1" -- "$2"
  getfacl --absolute-names --omit-header -- "$1" | setfacl --set-file=- -- "$2"
}

neighbour_file() { # <dir>: an existing file to copy metadata from (same folder first, then upwards)
  local d="$1" f
  while :; do
    f="$(find "$d" -maxdepth 1 -type f ! -name '.deploy-tmp-*' -print -quit)"
    if [[ -n "$f" ]]; then printf '%s\n' "$f"; return 0; fi
    [[ "$d" != "$APP_ROOT" && "$d" == "$APP_ROOT"/* ]] || return 1
    d="${d%/*}"
  done
}

apply_files() {
  local p dst tmp ref
  while IFS= read -r p; do
    mkdir -- "$APP_ROOT/$p"
    copy_meta "$(dirname -- "$APP_ROOT/$p")" "$APP_ROOT/$p"
  done < "$PLAN/newdirs"
  while IFS= read -r p; do
    dst="$APP_ROOT/$p"
    tmp="${dst%/*}/.deploy-tmp-$$-${dst##*/}"
    cp -- "$NEW/$p" "$tmp"
    if [[ -f "$dst" ]]; then
      ref="$dst"
    else
      ref="$(neighbour_file "${dst%/*}")" || fail "no file to copy permissions from for $p"
    fi
    copy_meta "$ref" "$tmp"
    if (( EXEC_FROM_GIT )); then
      if [[ -x "$NEW/$p" ]]; then chmod ug+x -- "$tmp"; else chmod a-x -- "$tmp"; fi
    fi
    mv -fT -- "$tmp" "$dst"
  done < <(cat "$PLAN/changed" "$PLAN/added")
  while IFS= read -r p; do rm -f -- "$APP_ROOT/$p"; done < "$PLAN/deleted"
  while IFS= read -r p; do rmdir -- "$APP_ROOT/$p"; done < <(LC_ALL=C sort -r "$PLAN/olddirs")
  say 'The changed files are in place.'
}

verify_files() { # the app's own user must be able to read every new file, and PHP must parse
  local p
  while IFS= read -r p; do
    runuser -u "$RUNTIME_USER" -- test -r "$APP_ROOT/$p" || { say "$RUNTIME_USER cannot read $p"; return 1; }
    if [[ "$p" == *.php ]] && ! runuser -u "$RUNTIME_USER" -- php -l "$APP_ROOT/$p" > /dev/null 2>&1; then
      say "PHP syntax error in $p"
      return 1
    fi
  done < <(cat "$PLAN/changed" "$PLAN/added")
}

app_cron_files() { # cron files that run this app's code
  local f
  for f in /etc/cron.d/*; do
    [[ -f "$f" && ! -L "$f" && "${f##*/}" != *.* ]] || continue
    if grep -qF -- "${APP_ROOT%/*}/" "$f"; then printf '%s\n' "$f"; fi
  done
}

# A deploy that was killed outright cannot resume what it paused; the next run does it instead.
restore_leftover_pauses() {
  local f t
  for f in /etc/cron.d/*.paused-by-deploy; do
    [[ -f "$f" ]] && grep -qF -- "${APP_ROOT%/*}/" "$f" || continue
    if [[ ! -e "${f%.paused-by-deploy}" ]]; then
      mv -T -- "$f" "${f%.paused-by-deploy}"
      say "Resumed ${f%.paused-by-deploy}, left paused by an interrupted deploy"
    fi
  done
  if [[ -f "$STATE_DIR/paused-timers" ]]; then
    while IFS= read -r t; do
      if [[ -n "$t" ]] && systemctl start "$t"; then say "Resumed $t, left paused by an interrupted deploy"; fi
    done < "$STATE_DIR/paused-timers"
    rm -f -- "$STATE_DIR/paused-timers"
  fi
}

jobs_busy() { # true while this app's code runs outside its web runtime, or the sign-up job runs
  local t unit
  pgrep -f -- "${APP_ROOT%/*}/" > /dev/null && return 0
  for t in "${SHARED_TIMERS[@]}"; do
    unit="$(systemctl show -p Unit --value "$t" 2> /dev/null || true)"
    if [[ -n "$unit" ]] && systemctl is-active --quiet "$unit"; then return 0; fi
  done
  return 1
}

pause_jobs() {
  local f t waited=0
  while IFS= read -r f; do
    mv -T -- "$f" "$f.paused-by-deploy"
    PAUSED_CRON+=("$f")
    say "Paused cron file $f"
  done < <(app_cron_files)
  for t in "${SHARED_TIMERS[@]}"; do
    systemctl is-active --quiet "$t" || continue
    printf '%s\n' "$t" >> "$STATE_DIR/paused-timers"
    systemctl stop "$t"
    STOPPED_TIMERS+=("$t")
    say "Paused $t"
  done
  if [[ -n "${REPORT_LOCK:-}" && -f "$REPORT_LOCK" ]]; then
    exec 7< "$REPORT_LOCK"
    say 'Waiting for any running business report to finish'
    flock -x -w 600 7 || fail 'a business report is still running after 10 minutes'
  fi
  while jobs_busy; do
    (( waited < 600 )) || fail "jobs are still running after 10 minutes: $(pgrep -af -- "${APP_ROOT%/*}/" | head -n 5 | tr '\n' ';')"
    if (( waited % 60 == 0 )); then say 'Waiting for running jobs to finish...'; fi
    sleep 5
    waited=$((waited + 5))
  done
}

resume_jobs() {
  local f t
  for f in "${PAUSED_CRON[@]}"; do
    if [[ -e "$f" ]]; then
      say "WARNING: $f exists again; leaving $f.paused-by-deploy for a person to sort out"
    elif mv -T -- "$f.paused-by-deploy" "$f"; then
      say "Resumed cron file $f"
    fi
  done
  for t in "${STOPPED_TIMERS[@]}"; do
    if systemctl start "$t"; then say "Resumed $t"; else say "WARNING: could not start $t again"; fi
  done
  PAUSED_CRON=() STOPPED_TIMERS=()
  rm -f -- "$STATE_DIR/paused-timers"
  exec 7<&- 2> /dev/null || true
}

stop_app() {
  say "Stopping $RUNTIME_UNIT (only $APP goes offline)"
  APP_STOPPED=1
  systemctl stop "$RUNTIME_UNIT"
}

start_app() {
  systemctl start "$RUNTIME_UNIT" && systemctl is-active --quiet "$RUNTIME_UNIT" || return 1
  APP_STOPPED=0
  say "Started $RUNTIME_UNIT"
}

healthy() { # the real HTTPS login page and one stylesheet, asked of this server directly
  local page
  page="$(curl -fsS --max-time 10 --resolve "$SITE:443:127.0.0.1" "https://$SITE$CHECK_PAGE" 2> /dev/null)" || return 1
  grep -qF -- "$CHECK_TEXT" <<< "$page" || return 1
  curl -fsS --max-time 10 -o /dev/null --resolve "$SITE:443:127.0.0.1" "https://$SITE$CHECK_ASSET" 2> /dev/null
}

wait_healthy() {
  local i
  for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
    if healthy; then return 0; fi
    sleep 2
  done
  return 1
}

live_version() {
  local v=''
  if [[ -f "$STATE_DIR/current.sha" ]]; then
    v="$(tr -d '\n' < "$STATE_DIR/current.sha")"
  elif [[ -n "$OLD_RELEASE_RECORD" && -f "$OLD_RELEASE_RECORD" ]]; then
    v="$(sed -n 's/^release_sha=//p' "$OLD_RELEASE_RECORD")"
  fi
  [[ "$v" =~ ^[0-9a-f]{40}$ ]] || v=unknown
  printf '%s\n' "$v"
}

write_state() { # <sha>
  printf '%s\n' "$1" > "$STATE_DIR/.current.sha.new"
  chown --reference="$STATE_DIR" -- "$STATE_DIR/.current.sha.new"
  chmod 0644 -- "$STATE_DIR/.current.sha.new"
  mv -fT -- "$STATE_DIR/.current.sha.new" "$STATE_DIR/current.sha"
}

db_name() { # printed on stdout, so problems are reported on stderr
  local name="$DB_NAME"
  if [[ -z "$name" ]]; then
    name="$(php -r 'echo (require $argv[1])["db"]["name"] ?? "";' "$APP_ROOT/config/config.php")"
  fi
  if [[ ! "$name" =~ ^[A-Za-z0-9_]+$ ]]; then
    say 'FAILED: could not tell which database this app uses' >&2
    exit 1
  fi
  printf '%s\n' "$name"
}

prune_backups() { # keep the ten newest deploy backups
  local d
  while IFS= read -r d; do
    if [[ -n "$d" ]]; then rm -rf -- "$d"; fi
  done < <(find "$STATE_DIR/backups" -mindepth 1 -maxdepth 1 -type d -name '*-to-*' | LC_ALL=C sort | head -n -10)
}

if [[ "${1:-}" == --on-server ]]; then
  shift
  run_server "$@"
else
  run_local "$@"
fi
