# Suite SSO deployment acceptance — Safeharbor

## 2026-08-08 — Central logout release (`PR #15`)

**Authorization:** Frankie authorized safe merge and production deployment for
the suite logout repair.

PR [#15](https://github.com/Seckcey/8_west_helpdesk/pull/15) merged as
`c3c606a289c4069b599dd74006423e31e79c30e4`. Exact-merge Validate run
[`31276123019`](https://github.com/Seckcey/8_west_helpdesk/actions/runs/31276123019)
passed. This was a code-only release: no migration, schema change, or production
configuration edit.

The release was installed from an isolated checkout of the exact merge. The
fresh verified backup is
`/home/ubuntu/backups/safeharbor-20260808T201613Z`, and
`/home/ubuntu/backups/LATEST_SAFEHARBOR` points to it. The production config
remained byte-identical at SHA-256
`d54805158c69c2ca60f36badc0b0c8324025e99349bbbf290a63e400615a9f05`.
Tracked release-file hashes matched, both changed PHP files passed server lint,
and ownership and permissions remained correct. Rollback was not needed.

Post-release acceptance:

- the app root redirects to login and `login.php` returns HTTP 200;
- `logout.php` redirects through the trusted 8 West ID logout endpoint and
  finishes at `https://id.8westit.com/login.php` with HTTP 200;
- the release-window logs contained 12 requests, zero HTTP 5xx responses, zero
  PHP fatals, and no unexpected application errors; and
- expected cookieless suite-SSO denials remained fail-closed and were the only
  deny events observed.

The repository test and live anonymous request prove the redirect contract.
One signed-in browser click remains the final UI acceptance: confirm the local
Safeharbor session cannot be reused after logout and that the visible browser
ends at the hosted 8 West ID login. No real account credential was used during
automated validation.

---

## 2026-08-02 — Initial Suite SSO deployment

**Deployed:** 2026-08-02 18:48, to `safeharbor.8westit.com`
(EC2 `13.52.91.237`, `/srv/8west/apps/safeharbor/current`).
**Commit:** `e85d5a5` (PR #8), which carries PR #5 and PR #7 with it.

## Order, and why it was that order

The migration went **first**, then the code. `007_suite_subject.sql` is
additive and invisible to the old code, so applying it early is free. The
reverse is not: `suite_sso_attempt()` degrades to email matching when the
column is absent, so a code-first deploy would have looked perfectly healthy
while quietly minting a second account for anyone whose address had changed —
the exact failure the change exists to remove.

## What was already live, and what it was doing

Safeharbor has **no kill switch**. `suite_sso_attempt()` is gated only by
`suite.sso_secret` being non-empty, and that secret was installed. Suite SSO
was therefore already live here, running the pre-PR#5 code, whose role
fallback was:

```php
$role = in_array($claims['8west:role'] ?? '', ['owner','admin','tech'], true)
    ? $claims['8west:role'] : 'tech';
```

Every unrecognised role became `tech` — a staff role that can write.
Confirmed against that code directly:

```
old code would have provisioned:
  client_owner -> tech
  client_staff -> tech
  readonly     -> tech
```

A customer identity arriving here became staff. That is what this deploy
closes, which is why it was not left to wait for the joint walk.

## Verification

Run against a scratch database (`safeharbor_test`) on the production host's
own PHP 8.3.6, before the code was allowed near the live tree:

```
19 checks, 0 failures
```

Then, after deploy:

| Check | Result |
| --- | --- |
| `suite_subject` references in live `lib/auth.php` | 4 |
| Staff-tenant fix present | yes |
| `suite_sso_refuse()` deny helper present | yes |
| `config/config.php` untouched | db `safeharbor`, secret intact at 96 chars, `app_env=production` |
| Ownership / permissions | `ubuntu:www-data`, dirs `2750`, files `640` |
| `https://safeharbor.8westit.com/login.php` | 200 |
| Deny logging, live | `suite sso deny: no_cookie` observed in the Apache error log |

Customer roles were separately confirmed refused with nothing provisioned:

```
client_owner   admitted=false users_created=0  OK refused, nothing provisioned
client_staff   admitted=false users_created=0  OK refused, nothing provisioned
```

## What the first tile sign-in will do

8 West ID builds `sub` as `t<tenant_id>u<user_id>`, and Safeharbor's only
tenant is slug `8west` (id 1). All four existing users sit in it with
`suite_subject` NULL, so:

| Person | Token `sub` | Outcome |
| --- | --- | --- |
| frankie@8westit.com | `t1u1` | claims existing user 1, backfills the subject |
| josh@nexgenbuilding.com | `t1u2` | claims existing user 4, backfills the subject |
| frankieggit@gmail.com | `t1u4` | **new** owner account — no matching row exists |

Ana and Marcus are not in 8 West ID and keep bcrypt sign-in only. Josh is
`admin` locally and `owner` in the suite; roles are set at provision time and
not reconciled afterwards, so he stays `admin` here.

## The lockout this deploy nearly shipped

PR #5's reserved-slug rule refused `8west` and `internal` for every arriving
identity. Safeharbor's only tenant *is* `8west`, and users 1, 2 and 4 hold the
safeharbor grant in 8 West ID today — so the tile is a working path, and the
rule would have turned it into `tenant_slug_invalid` for all of them, leaving
only the bcrypt fallback.

The rule was borrowed from Mission Control, where it is correct: Mission
Control applies it **only on its customer branch**, and its staff are global
operators who never reach the check. Safeharbor is tenant scoped for everyone,
so the same line lands on staff. It also guarded nothing here — the role gate
directly above admits only `owner`/`admin`/`tech`, so a customer role can never
reach the slug check at all. Removed in PR #8.

Coastmark deliberately kept its reservation, narrowed to non-staff, because
its `ROLE_MAP` *does* admit customer roles. See `Seckcey/coastmark` PR #13.

## Backups

`/home/ubuntu/backups/safeharbor-20260802-181720/`, pointer at
`/home/ubuntu/backups/LATEST_SAFEHARBOR`:

- `safeharbor-db.sql` — `mysqldump --no-tablespaces --single-transaction`,
  17/17 tables, `Dump completed` marker checked rather than assumed. The first
  attempt printed a tablespaces privilege error **and still exited 0**; that is
  how a truncated backup gets trusted, so it was redone and counted.
- `current.tar.gz` — the app directory as it stood.

## Pre-existing faults noticed in the error log, not caused by this deploy

Worth someone's attention — all predate today and all produce fatal 500s:

- `public/snippets.php:29` and `public/ticket.php:38` — `SQLSTATE[HY000] 1366
  Incorrect string value: '\x97…'`. A Windows-1252 smart quote reaching a
  `utf8mb4` column; pasted text kills the request.
- `public/ticket_new.php:36` — `SQLSTATE[HY093] Invalid parameter number`.
  A placeholder/bind mismatch, so that path fails every time it is hit.
