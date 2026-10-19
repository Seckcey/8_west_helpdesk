#!/usr/bin/env bash
# Deploy Safeharbor to production. This is the one deploy path; deploy/README.md explains it.
#
#   bash deploy/deploy.sh                    ship the newest merged code (origin/main)
#   bash deploy/deploy.sh <commit>           ship a commit that is already on main (also how to go further back)
#   bash deploy/deploy.sh --check            show what would change on the server; change nothing
#   bash deploy/deploy.sh --rollback         undo the most recent deploy from its backup
#   bash deploy/deploy.sh --migrate <file>   also apply this .sql database change, e.g. app/db/migrations/032_x.sql
#                                            (repeatable; applied in the order given, after a database backup)
#
# Run it from Git Bash in a clone of this repo. It reaches the server through the `milepost-ec2` SSH
# alias; DEPLOY_HOST picks another host and DEPLOY_SSH_CONFIG points ssh/scp at another config file.
# The server always runs origin/main's copy of this script (DEPLOY_LOCAL_SCRIPT=1 runs this checkout's).
#
# On the server it backs up every file it will change, pauses the jobs that run Safeharbor code (and the
# 365 sign-up job), stops only Safeharbor's PHP runtime, copies the changed files in with the same owner
# and permissions as the files they replace, starts the runtime again, checks the real HTTPS login page,
# and puts the backup back by itself if anything fails. Every other site on the server stays up.
set -euo pipefail
umask 077
export LC_ALL=C

# ---- Safeharbor settings ---------------------------------------------------------------------------
APP=safeharbor
APP_ROOT=/srv/8west/apps/safeharbor/current
AREAS=(.)                         # what the release owns inside APP_ROOT (. means all of it)
KEEP=(config/config.php)          # server-only files inside those areas: never written, never deleted
RUNTIME_UNIT=8west-php-safeharbor.service
RUNTIME_USER=eightwest-safeharbor
STATE_DIR=/srv/8west/apps/safeharbor/deploy-state
DB_NAME=safeharbor
SITE=safeharbor.8westit.com
CHECK_PAGE=/login.php
CHECK_TEXT='<title>Sign in · Safeharbor</title>'
CHECK_ASSET=/assets/css/app.css
# Timers that run Safeharbor code: the 365 sign-up job (it creates clients in every linked app) and the
# every-minute desktop-session cleanup. Cron files that mention the app folder are found automatically.
PAUSE_TIMERS=(8west-suite-onboarding.timer 8west-desktop-cleanup-safeharbor.timer)
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
      -h|--help) sed -n '2,19p' "${BASH_SOURCE[0]}"; exit 0 ;;
      -*) die "unknown option: $1 (try --help)" ;;
      *) [[ -z "$target" ]] || die 'give at most one commit'; target="$1" ;;
    esac
    shift
  done
  if [[ "$mode" == rollback && ( -n "$target" || ${#migrations[@]} -gt 0 ) ]]; then
    die '--rollback takes no commit and no --migrate'
  fi
  for m in "${migrations[@]}"; do
    [[ "$m" == *.sql ]] || die "--migrate takes .sql files only: $m"
  done

  local repo host sha=none remote rc=0 branch="${BRANCH:-main}"
  local ssh=(ssh -o BatchMode=yes) scp=(scp -q -o BatchMode=yes)
  repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  host="${DEPLOY_HOST:-milepost-ec2}"
  if [[ -n "${DEPLOY_SSH_CONFIG:-}" ]]; then ssh+=(-F "$DEPLOY_SSH_CONFIG"); scp+=(-F "$DEPLOY_SSH_CONFIG"); fi
  WORK="$(mktemp -d)"
  trap 'rm -rf -- "$WORK"' EXIT

  git -C "$repo" fetch --quiet origin "$branch"
  if [[ "${DEPLOY_LOCAL_SCRIPT:-}" == 1 ]]; then
    echo "==> Using this checkout's deploy.sh, not origin/$branch's (DEPLOY_LOCAL_SCRIPT=1)"
    tr -d '\r' < "${BASH_SOURCE[0]}" > "$WORK/deploy.sh"   # a Windows checkout may carry CRLF endings
  else
    git -C "$repo" show "origin/$branch:deploy/deploy.sh" > "$WORK/deploy.sh" 2> /dev/null \
      || die "origin/$branch has no deploy/deploy.sh"
  fi

  if [[ "$mode" != rollback ]]; then
    sha="$(git -C "$repo" rev-parse --verify --quiet "${target:-origin/$branch}^{commit}")" \
      || die "unknown commit: ${target:-origin/$branch}"
    git -C "$repo" merge-base --is-ancestor "$sha" "origin/$branch" \
      || die "${sha:0:12} is not on $branch; only merged code is deployed"
    echo "==> Packing $APP ${sha:0:12} from git"
    # shellcheck disable=SC2046  # git_paths is a fixed list of words
    git -C "$repo" -c core.autocrlf=false -c core.eol=lf archive --format=tar.gz "$sha" $(git_paths) \
      > "$WORK/release.tar.gz"
    if declare -F build_locally > /dev/null; then build_locally "$WORK/release.tar.gz"; fi
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
    echo "deploy: lost contact with $host before the deploy reported back. The server finishes or undoes" >&2
    echo "deploy: it on its own; run 'bash deploy/deploy.sh --check' in a minute to see where it ended." >&2
  fi
  exit "$rc"
}

# On the server, as root. Messages go to the screen and to $STATE_DIR/last-run.log.
say() { printf '%s\n' "$*" >> "$STATE_DIR/last-run.log"; printf '%s\n' "$*" 2> /dev/null || true; }
fail() { say "FAILED: $*"; exit 1; }
record() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >> "$STATE_DIR/history.log"; RECORDED=1; }

run_server() {
  MODE="$1" RELEASE_SHA="$2" UPLOAD="$3"
  [[ $EUID -eq 0 ]] || die 'the server half must run as root'
  [[ "$UPLOAD" =~ ^/tmp/deploy-${APP}\.[A-Za-z0-9]{8}$ && -d "$UPLOAD" && ! -L "$UPLOAD" ]] \
    || die "unexpected upload folder: $UPLOAD"
  # One deploy at a time on the whole host: apps share cron files and the sign-up timer.
  exec 8> /run/lock/8west-deploy.lock
  flock -n 8 || die 'another deploy (or what is left of a stopped one) is still running on this server; try again in a minute'

  PHASE=prepare APP_STOPPED=0 BACKUP='' RECORDED=0 MARKED=0 PAUSED_CRON=() STOPPED_TIMERS=()
  if [[ ! -d "$STATE_DIR" ]]; then install -d -m 0700 "$STATE_DIR"; fi
  if [[ ! -d "$STATE_DIR/backups" ]]; then install -d -m 0700 "$STATE_DIR/backups"; fi
  : > "$STATE_DIR/last-run.log"
  trap on_exit EXIT
  trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM; trap '' PIPE

  local f
  for f in /var/lib/8west-cold-window/"$APP".active /var/lib/8west-cold-window/suite.active; do
    [[ ! -e "$f" ]] || fail "a cold-window operation is still open ($f); finish or close it first"
  done
  [[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || fail "$APP_ROOT is missing"
  systemctl cat "$RUNTIME_UNIT" > /dev/null 2>&1 || fail "$RUNTIME_UNIT is not installed"
  extra_checks

  if [[ -f "$STATE_DIR/in-progress" ]]; then
    if [[ "$MODE" == check ]]; then
      say "WARNING: an earlier deploy stopped part-way (backup $(cat "$STATE_DIR/in-progress"))."
      say 'The next deploy or --rollback first puts the version from before it back.'
    else
      recover_interrupted "$(cat "$STATE_DIR/in-progress")"
      return 0
    fi
  else
    restore_leftover_pauses
  fi

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
  trap '' HUP INT TERM
  trap - EXIT
  set +e
  if [[ "$PHASE" == files ]]; then
    say 'Something went wrong while the files were changing. Putting the previous version back.'
    if roll_back_files; then release_marker; else MARKED=0; fi
  elif (( MARKED )); then
    release_marker
  fi
  if (( APP_STOPPED )); then start_app || say "WARNING: $RUNTIME_UNIT did not start again. Look at the server now."; fi
  if [[ "$PHASE" == migrate ]]; then
    say "A database change failed and may be partly applied. The previous code is running again."
    say "Database backup from just before it: $BACKUP/database.sql.gz"
  fi
  resume_jobs
  rm -rf -- "$UPLOAD"
  if (( rc != 0 )); then
    if [[ "$MODE" != check ]] && (( ! RECORDED )); then record "FAILED $MODE $RELEASE_SHA in phase $PHASE (exit $rc)"; fi
    say "Stopped with exit code $rc. Full log: $STATE_DIR/last-run.log"
  fi
  exit "$rc"
}

server_check() {
  local m f t
  say "Live version:   $(live_version)"
  say "Target version: $RELEASE_SHA"
  stage_release
  make_plan
  report_plan
  for m in "$UPLOAD"/migration-*; do
    if [[ -f "$m" ]]; then say "Database change to apply: ${m##*/}"; fi
  done
  say 'Jobs that would pause during the deploy:'
  while IFS= read -r f; do say "  cron file  $f"; done < <(app_cron_files)
  for t in "${PAUSE_TIMERS[@]}"; do
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
  for m in "$UPLOAD"/migration-*; do
    [[ -f "$m" ]] || continue
    [[ "$m" == *.sql ]] || fail "only .sql database changes can be applied: ${m##*/}"
    migrations+=("$m")
  done
  say "Deploying $APP ${RELEASE_SHA:0:12} (live now: ${from:0:12})"
  report_plan
  BACKUP="$STATE_DIR/backups/$(date -u +%Y%m%dT%H%M%SZ)-${from:0:12}-to-${RELEASE_SHA:0:12}"

  if [[ ! -s "$PLAN/changed" && ! -s "$PLAN/added" && ! -s "$PLAN/deleted" \
        && ! -s "$PLAN/newdirs" && ! -s "$PLAN/olddirs" && ${#migrations[@]} -eq 0 ]]; then
    say 'The server already has exactly these files.'
    if ! systemctl is-active --quiet "$RUNTIME_UNIT"; then start_app || fail "$RUNTIME_UNIT does not start"; fi
    healthy || fail "the site fails its check (https://$SITE$CHECK_PAGE) even though no file changes"
    if [[ "$from" != "$RELEASE_SHA" ]]; then
      make_backup "$from"          # an empty record, so --rollback can step back past this deploy
      touch "$BACKUP/deployed"
      write_state "$RELEASE_SHA"
      record "deployed $from -> $RELEASE_SHA (no file changes)"
    fi
    say "Live: https://$SITE (${RELEASE_SHA:0:12})"
    return 0
  fi

  make_backup "$from"
  pause_jobs
  set_marker
  stop_app
  wait_for_jobs 60
  if (( ${#migrations[@]} )); then
    db="$(db_name)"
    backup_database "$db"
    PHASE=migrate
    for m in "${migrations[@]}"; do
      say "Applying database change ${m##*/}"
      mysql --init-command='SET SESSION lock_wait_timeout=60' "$db" < "$m"
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
    release_marker
    resume_jobs
    prune_backups
    record "deployed $from -> $RELEASE_SHA"
    say "Live: https://$SITE (${RELEASE_SHA:0:12}). Backup kept in $BACKUP"
  else
    say "The new version failed its check (https://$SITE$CHECK_PAGE). Putting the previous version back."
    if roll_back_files; then release_marker; else MARKED=0; fi
    PHASE=finished
    if (( ${#migrations[@]} )); then
      say "The database change stays applied; the backup from just before it is $BACKUP/database.sql.gz"
    fi
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
  set_marker
  stop_app
  wait_for_jobs 60
  PHASE=files
  undo_backup "$b" || fail "could not put the files back from $b"
  start_app
  PHASE=finished
  touch "$b/rolled-back"
  write_state "$RELEASE_SHA"
  release_marker
  resume_jobs
  if [[ -f "$b/database.sql.gz" ]]; then
    say "The database was not changed back. If that deploy's database change must go, restore $b/database.sql.gz by hand."
  fi
  if wait_healthy; then
    record "rolled back $live -> $RELEASE_SHA"
    say "Rolled back. Live: https://$SITE (${RELEASE_SHA:0:12})"
  else
    record "rolled back $live -> $RELEASE_SHA, but the site fails its check"
    fail "rolled back, but https://$SITE fails its check. Look at it now."
  fi
}

# A run that was killed outright (power cut, out of memory) left in-progress behind: undo it first.
recover_interrupted() { # <backup dir>
  local b="$1"
  [[ -d "$b" && -f "$b/from" ]] \
    || fail "$STATE_DIR/in-progress points at $b, which is missing; sort the app out by hand, then delete in-progress"
  say "An earlier deploy stopped part-way. Putting the version from before it back first."
  BACKUP="$b" RELEASE_SHA="$(cat "$b/from")" MARKED=1
  APP_STOPPED=1
  systemctl stop "$RUNTIME_UNIT" || true
  PHASE=files
  undo_backup "$b" || fail "could not put the files back from $b"
  start_app
  PHASE=finished
  touch "$b/rolled-back"
  write_state "$RELEASE_SHA"
  release_marker
  restore_leftover_pauses
  if wait_healthy; then
    record "recovered an interrupted deploy; $RELEASE_SHA is live again"
    say "Recovered: ${RELEASE_SHA:0:12} is live again. Run your command again if you still need it."
  else
    record "recovered an interrupted deploy to $RELEASE_SHA, but the site fails its check"
    fail "recovered the files, but https://$SITE fails its check. Look at it now."
  fi
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
  local area pre
  for area in "${AREAS[@]}"; do
    [[ -e "$1/$area" ]] || continue
    pre=''
    if [[ "$area" != . ]]; then pre="$area/"; fi
    # Read the whole list (no early exit), so pipefail cannot turn a found problem into a pass.
    if [[ -n "$(find "$1/$area" -mindepth 1 \( \( ! -type f ! -type d \) -o -name $'*[\n\t\\\\]*' \) \
                  -printf "$pre%P\n" | not_kept)" ]]; then
      fail "$1/$area holds a link, special file or unusual file name; sort that out by hand first"
    fi
  done
}

not_kept() { # drop KEEP paths, and everything inside a KEEP folder, from the list on stdin
  awk 'NR == FNR { if ($0 != "") keep[$0] = 1; next }
       { p = $0
         while (!(p in keep)) {
           i = 0
           for (j = length(p); j > 0; j--) if (substr(p, j, 1) == "/") { i = j; break }
           if (i == 0) { print; next }
           p = substr(p, 1, i - 1)
         } }' <(printf '%s\n' "${KEEP[@]}") -
}

tree_list() { # <root> <f|d>: sorted paths inside the owned areas, minus KEEP
  local area pre
  for area in "${AREAS[@]}"; do
    [[ -d "$1/$area" ]] || continue
    pre=''
    if [[ "$area" != . ]]; then pre="$area/"; fi
    find "$1/$area" -mindepth 1 -type "$2" -printf "$pre%P\n"
  done | not_kept | sort
}

file_hashes() { # <root> <list> <out>: one sha256 per listed file, in list order
  (cd "$1" && tr '\n' '\0' < "$2" | xargs -0 -r sha256sum -- | cut -c1-64) > "$3"
  [[ "$(wc -l < "$3")" == "$(wc -l < "$2")" ]] || fail "could not read every file under $1"
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
  file_hashes "$APP_ROOT" "$PLAN/common" "$PLAN/live.sha"
  file_hashes "$NEW" "$PLAN/common" "$PLAN/new.sha"
  paste "$PLAN/live.sha" "$PLAN/new.sha" "$PLAN/common" | awk -F '\t' '$1 != $2 { print $3 }' > "$PLAN/changed"
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
  if [[ -s "$BACKUP/files.list" ]]; then
    tar --create --file="$BACKUP/files.tar" --acls --xattrs --numeric-owner --no-recursion \
      --directory="$APP_ROOT" --verbatim-files-from --files-from="$BACKUP/files.list"
  fi
  say "Backed up $(wc -l < "$BACKUP/files.list") files and folders to $BACKUP"
}

backup_database() { # <db>: dump it beside the file backup, after checking there is room
  local need have
  need="$(mysql -N -e "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '$1'")"
  have="$(df -B1 --output=avail "$STATE_DIR" | tail -n 1 | tr -d ' ')"
  (( have > need )) || fail "not enough free space to back up database $1 ($have bytes free, about $need needed)"
  say "Backing up database $1"
  mysqldump --single-transaction --quick --routines --triggers --events "$1" | gzip -c > "$BACKUP/database.sql.gz"
}

# Put back exactly what a deploy replaced or removed, and remove what it added.
undo_backup() { # <backup dir>
  local b="$1" area p
  for area in "${AREAS[@]}"; do
    find "$APP_ROOT/$area" -xdev -type f -name '.deploy-tmp-*' -delete 2> /dev/null || true
  done
  while IFS= read -r p; do rm -f -- "$APP_ROOT/$p"; done < "$b/added"
  while IFS= read -r p; do rmdir -- "$APP_ROOT/$p" 2> /dev/null || true; done < <(sort -r "$b/newdirs")
  if [[ -f "$b/files.tar" ]]; then
    tar --extract --file="$b/files.tar" --acls --xattrs --numeric-owner --same-owner --preserve-permissions \
      --directory="$APP_ROOT"
  fi
}

# The in-progress marker names the backup that puts the app back if this run dies part-way.
set_marker() { printf '%s\n' "$BACKUP" > "$STATE_DIR/in-progress"; MARKED=1; }
release_marker() { rm -f -- "$STATE_DIR/in-progress"; MARKED=0; }

roll_back_files() { # true when the files are back as they were before BACKUP's deploy
  local undone=0
  APP_STOPPED=1
  systemctl stop "$RUNTIME_UNIT" || true
  if undo_backup "$BACKUP"; then
    undone=1
    write_state "$(cat "$BACKUP/from")"
  else
    say "WARNING: could not put every file back from $BACKUP"
  fi
  if start_app && wait_healthy; then
    say 'The previous version is running again.'
  else
    say "WARNING: the previous version does not pass its check either. Look at https://$SITE now."
  fi
  (( undone ))
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
    mv -fT -- "$tmp" "$dst"
  done < <(cat "$PLAN/changed" "$PLAN/added")
  while IFS= read -r p; do rm -f -- "$APP_ROOT/$p"; done < "$PLAN/deleted"
  while IFS= read -r p; do rmdir -- "$APP_ROOT/$p"; done < <(sort -r "$PLAN/olddirs")
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
    [[ -f "$f" ]] || continue
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

jobs_busy() { # true while this app's code runs outside its web runtime, or a paused timer's job still runs
  local t unit
  pgrep -f -- "${APP_ROOT%/*}/" > /dev/null && return 0
  for t in "${PAUSE_TIMERS[@]}"; do
    unit="$(systemctl show -p Unit --value "$t" 2> /dev/null || true)"
    if [[ -n "$unit" ]] && systemctl is-active --quiet "$unit"; then return 0; fi
  done
  return 1
}

wait_for_jobs() { # <seconds>
  local waited=0
  while jobs_busy; do
    (( waited < $1 )) || fail "jobs are still running after $1 seconds: $(pgrep -af -- "${APP_ROOT%/*}/" | head -n 5 | tr '\n' ';')"
    if (( waited % 60 == 0 )); then say 'Waiting for running jobs to finish...'; fi
    sleep 5
    waited=$((waited + 5))
  done
}

pause_jobs() {
  local f t
  while IFS= read -r f; do
    mv -T -- "$f" "$f.paused-by-deploy"
    PAUSED_CRON+=("$f")
    say "Paused cron file $f"
  done < <(app_cron_files)
  for t in "${PAUSE_TIMERS[@]}"; do
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
  wait_for_jobs 600
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
  if (( ${#STOPPED_TIMERS[@]} )); then   # only this run's list; a crashed run's list waits for the next deploy
    for t in "${STOPPED_TIMERS[@]}"; do
      if systemctl start "$t"; then say "Resumed $t"; else say "WARNING: could not start $t again"; fi
    done
    rm -f -- "$STATE_DIR/paused-timers"
  fi
  PAUSED_CRON=() STOPPED_TIMERS=()
  { exec 7<&-; } 2> /dev/null || true
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
    v="$(sed -n -e 's/^release_sha=//p' -e '/^[0-9a-f]\{40\}$/p' "$OLD_RELEASE_RECORD" | head -n 1)"
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
    # shellcheck disable=SC2016  # the single quotes keep $argv for PHP, not bash
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
  done < <(find "$STATE_DIR/backups" -mindepth 1 -maxdepth 1 -type d -name '*-to-*' | sort | head -n -10)
}

if [[ "${1:-}" == --on-server ]]; then
  shift
  run_server "$@"
else
  run_local "$@"
fi
