# Deploying Safeharbor

One script ships Safeharbor: `deploy/deploy.sh`. Run it from Git Bash in a clone of this repo. It
reaches the server through the `milepost-ec2` SSH alias.

```bash
bash deploy/deploy.sh --check      # what would change; changes nothing
bash deploy/deploy.sh              # ship the newest merged code
bash deploy/deploy.sh --rollback   # undo the last deploy
```

## What a deploy does

1. Packs the commit straight from git. Only code that is on `main` can ship.
   `bash deploy/deploy.sh <commit>` ships an older commit from `main`, which is also how to go back
   more than one step. The server always runs `origin/main`'s copy of `deploy.sh`, so what runs as root
   is the reviewed script, not whatever is checked out (`DEPLOY_LOCAL_SCRIPT=1` overrides that for
   testing a change to the script itself).
2. On the server, takes a lock so only one deploy of any app runs on the box at a time, then backs up
   every file it is about to replace or remove, with owners, modes and ACLs, into
   `/srv/8west/apps/safeharbor/deploy-state/backups/<time>-<from>-to-<to>/`.
3. Pauses the cron files that run Safeharbor code, the 365 sign-up timer
   (`8west-suite-onboarding.timer`) and the every-minute desktop cleanup
   (`8west-desktop-cleanup-safeharbor.timer`), takes the business-report deploy lock, and waits for
   running jobs to finish. After 10 minutes of waiting it stops without changing anything.
4. Stops `8west-php-safeharbor.service`. Only Safeharbor goes offline; every other site keeps running.
5. With `--migrate`, checks there is room, dumps the database into the backup folder, and applies the
   SQL files in the order given. Then it copies the changed files in. Each file gets the owner, mode
   and ACL of the file it replaces (a new file copies a neighbour), so the private-runtime rules stay
   exactly as they are: `ubuntu:eightwest-safeharbor`, folders `2750`, files `0640`, and the web server
   may read only `public/`.
6. Checks that the runtime user can read every new file and that PHP parses it, starts the runtime,
   and loads `https://safeharbor.8westit.com/login.php` and one stylesheet through the server's own
   HTTPS.
7. If any step after the stop fails, it puts the backup back, starts the runtime and says so.
   Paused jobs are always resumed.

`config/config.php` is never written or deleted. The live commit is recorded in
`deploy-state/current.sha`; `deploy-state/history.log` has one line per deploy and
`deploy-state/last-run.log` the full output of the latest run.

## Database changes

Ship a database change together with its code:

```bash
bash deploy/deploy.sh --migrate app/db/migrations/032_example.sql
```

Only `.sql` files are accepted. While Safeharbor is stopped, the script dumps the database, applies
the SQL (it gives up rather than wait more than a minute for a table lock), then ships the code.
Write migrations so the previous code still works with the new schema: add tables and columns, and
leave renames or drops for a later release. `--rollback` puts the code back but never the database;
the dump in the backup folder is there if a person decides the data must go back too. Restoring a
dump is disaster recovery only, because it discards everything written since. Keep
`app/db/schema.sql` in step with the migrations, and never run `php db/seed.php` in production (it
resets demo data).

## Rollback

`bash deploy/deploy.sh --rollback` undoes the most recent deploy from its backup and records the
earlier commit as live. Run it again to step back one more deploy. To return to any older commit on
`main`, deploy that commit.

## If something looks wrong

- `bash deploy/deploy.sh --check` shows the live commit, what a deploy would change, which jobs it
  would pause, what is running right now, and whether the site passes its check.
- A deploy refuses to start while another deploy (of any app) or a cold-window operation is still
  open.
- If your connection drops mid-deploy, the server carries on and finishes or undoes the deploy by
  itself. Run `--check` a minute later to see where it ended.
- If a deploy was killed outright (power cut, out of memory), it leaves
  `deploy-state/in-progress` behind. `--check` warns about it and changes nothing; the next deploy or
  `--rollback` first puts the version from before it back, resumes its paused jobs, and stops so you
  can run your command again.

## Server facts

- Code: `/srv/8west/apps/safeharbor/current`, owned `ubuntu:eightwest-safeharbor`, folders `2750`,
  files `0640`; the web server reads `public/` only, through an ACL. Attachments live outside it in
  `/srv/8west/apps/safeharbor/shared/attachments`.
- Runtime: `8west-php-safeharbor.service`, running as `eightwest-safeharbor`.
- Web: Apache vhosts `safeharbor-8westit.conf` (port 80) and `safeharbor-8westit-le-ssl.conf`
  (port 443). Apache serves every site on the box: `reload` it, never `restart` it.
- SSH: `ssh milepost-ec2`. The alias points at the origin IP; the hostname sits behind Cloudflare
  and its port 22 times out. The key and the IP never go into git.

## Report schedule

Business reports run from one cron file, `/etc/cron.d/safeharbor-business-reports` (its source is
`deploy/safeharbor-business-reports.cron`). Rename it to `safeharbor-business-reports.disabled` to
pause reports; rename it back to resume. A deploy pauses it by itself and waits for a running report.
The old release record in `/var/lib/safeharbor-report-scheduler/releases/` belonged to the retired
installer; nothing reads it any more, and root may delete that folder.

## What the deploy never touches

- `config/config.php`, which holds the database login and the `ai`, `suite` (8 West ID), `svc`, `portal`
  and storage settings. New settings are a hand edit on the server; copy their shape from
  `app/config/config.sample.php`.
- Data outside the release: attachments in `/srv/8west/apps/safeharbor/shared/attachments`, support-mail
  state in `/srv/8west/apps/safeharbor/shared/support-mail`, and the sign-in caches under
  `/var/cache/8west/safeharbor/`. Never store anything under `current/`; the next deploy removes files
  that git does not have.
- The cron files and timers. The deploy only pauses and resumes them.

## Smoke test after a deploy

The script already checks the login page and a stylesheet. By hand:

```bash
curl -s  https://safeharbor.8westit.com/login.php | grep -o '<title>[^<]*'   # Sign in · Safeharbor
curl -sI https://safeharbor.8westit.com/ | head -1                           # 302 to the login page
curl -sS -X POST -H 'Content-Type: application/json' -d '{}' -o /dev/null \
  -w '%{http_code}\n' https://safeharbor.8westit.com/api/timer.php             # 401
```

Then sign in through 8 West ID and open a ticket.

## Rebuilding the server

There is no automated rebuild. A rebuild needs a reviewed plan: least-privilege database grants (no
database-wide `DELETE`), `schema.sql` plus `002_svc_intake.sql`, `009_support_intake.sql` and
`018_ticket_auto_close_eligibility.sql`, the private runtime, and the vhosts in this folder.

## Requester privacy migration

For `portal_ticket_requesters_v1.sql`, follow the
[settings/access release packet](../docs/settings-access-release.md). The
previous application code exposes a wider member view: an ordinary source
rollback is insufficient. Preserve `portal.enabled=false` in the server-only
config across migration, deployment and automatic inverse, and reopen only the
reviewed privacy-compatible source after protected approval and acceptance.
Keep all attribution and audit history; no database restore/delete rollback.
