# Suite SSO deployment acceptance — Safeharbor

## 2026-10-16 — Suite v2.1 runtime release

### 2026-10-16 19:07 PDT — normal runtime recovery

Current status: **DEPLOYED_NORMAL_RUNTIME_ACCEPTED_PROVIDER_CUSTOMER_PENDING**.
ID's current native receipt `manual-forward-release-current.json` has SHA-256
`9ade6e3e29d31b70536dcea0c2e35340570a09ec9ea5074d7082e92f08f6b3e1`.
All three producer failures are resolved after minimum existing role-configuration
corrections. Both cleanup jobs naturally exited 0 at 19:06 PDT with count 1 and
healthy false, then at 19:07 PDT with count 2 and healthy true under the existing
rule. Onboarding's natural 19:05 and 19:06 PDT runs exited 0 with Result success.
No manual job runs, failure reset, source changes or new tests were used.

The cause was stale `www-data` runtime identities in cleanup service/state and
eleven known onboarding command bindings. ID corrected only those existing role
settings, two tmpfiles rows and fixed job/receipt ownership. Receipt bytes,
modes and inodes were preserved; other configuration stayed unchanged.
Correction caller and lock FDs closed normally.
Before-capture lacked prior Result, so no preexisting-failure claim is made.
Application/runtime acceptance, active new-bound reports and custody closure
below are unchanged. Canonical UNKNOWN and the separate successful manual record
remain distinct; cross-filesystem repair remains a follow-up. Frankie-owned
provider/customer acceptance is pending.

### Initial failed snapshot — historical, superseded by recovery above

The original `4e3dd4f740fe4245debfa8aa8225d6517c8ba55f539f2ef72cf8d3305f8b9392`
receipt and its failures remain preserved below; they are not the current status.

Status: **DEPLOYED_WITH_THREE_FAILED_PRODUCERS**, from ID's final native receipt
for operation `fe6a424b14cc46c19f2d796b9d81aa3a`. This is a deployed manual
forward continuation, not a fully healthy suite or an ACCEPTED canonical run.

All four selected private FPM services are active. Actual runtime revisions are
Safeharbor `9d092fa4b46670b49555d0fed5a57f31ddce9212`, Milepost
`09e5c2adbc5699c484bd8d2b20351fb9ef633b15`, and ID
`76e04342fabfd4303c62589d9a9732c37e552483`. Website selects
`runtime-isolation-20261017-fe6a424b14cc46c19f2d796b9d81aa3a`.
The controller source is `dcede8b81ca414457d6c7b399ca0ec584fe7e43e`.
Worker UID/GID pairs are 1601/1601, 1602/1602, 1603/1603 and 1604/1604 for
Milepost, Safeharbor, ID and Website; private cache/session directories are
0700 under their matching application identity. The inquiry processor uses
1605 and its intended scheduled run returned native exit 0.

All five required public HTTPS paths returned 200 with their expected markers
at 6:59 PM Pacific on October 16, including Website's inquiry endpoint.
Website's private HTTP response was 301 enforcing HTTPS and is not claimed as
a passing private HTTP check. The first actual A91 invocation remains a
native-exit-3 `base_mode` refusal. The second separately authorized invocation
returned native exit 0 with `worker_read=1`; no further private-input read,
hash or installer invocation is part of this closeout.

The canonical carrier's failed current-link record remains UNKNOWN. Website's
successful manual current-link continuation and twelve selected rows are
separate execution evidence. The cross-filesystem link failure remains a known
generic-tool follow-up; no source fix or new test campaign is part of this
release closeout. Earlier failed joined runs and their coverage limits remain
historical failures.

Normal Milepost and Safeharbor cold commits each returned native exit 0.
Safeharbor's complete deployed artifact is
`2d9ac33b025fc1d7f9a3d59fdeb1b7d961e95c76c412919bed1a983ab70f1767`;
its immutable record under `/var/lib/safeharbor-report-scheduler/releases` is
`app-artifact.9d092fa4b46670b49555d0fed5a57f31ddce9212.2d9ac33b025fc1d7f9a3d59fdeb1b7d961e95c76c412919bed1a983ab70f1767.manifest`.
This supersedes the earlier `6dcf3960` installed-source observation. Merged
PR #238 source `d4ad65df` has the same executable bytes as installed `9d092fa4`;
its later status documentation is not a different deployed application.

Cron and all six previously active timers resumed. Safeharbor's new-bound
reporting preflight, install-disabled, verify-disabled, enable and verify-active
all returned native exit 0 at 6:58 PM Pacific. The original real canary tuple,
delivery/recipient timestamps and archive evidence were preserved; the new
protected activation binds the actual release/artifact/config. No old activation
was replayed and no new mail, canary or report generation was performed.

Three normal scheduled services failed with native exit 1:
`8west-desktop-cleanup@milepost.service`,
`8west-desktop-cleanup@safeharbor.service` and
`8west-suite-onboarding.service`. Their causes and preexisting-versus-regression
status are not qualified. ID owns the bounded comparison of already captured
before-status and error journals, with any authorized minimum runtime-access
configuration correction. Outcomes from the active schedules remain pending;
no manual replay or new investigation campaign is claimed.

Original custody ended naturally with MainPID 0 and all nineteen locks released.
A fresh same-owner root successor reacquired the nineteen genuine locks,
completed the commits and report restoration, and released all nineteen.
The successor caller exited 0, was reaped and closed SSH; final custody closure
was 7:01 PM Pacific. The old slot, UNKNOWN journal, original start and deadline
remain unchanged. Original Website link, ID rollback tree, accepted-source
archives and inverse maps are retained. The installed private output is
preserved; it is not an invitation to replay the installer.

ID retains `manual-forward-release.json`, SHA-256
`4e3dd4f740fe4245debfa8aa8225d6517c8ba55f539f2ef72cf8d3305f8b9392`,
with native acceptance, runtime, producer and all lock-release records.
Root evidence is under
`/root/8west-suite-v21-fe6a424b14cc46c19f2d796b9d81aa3a/manual-disposition`.
This documentation uses that supplied receipt; it performs no new host probe.

Milepost's actual runtime revision is `09e5`; PR #713 is not claimed deployed,
and no GitHub workflow execution is claimed by that runtime identity. Provider
and customer acceptance remain Frankie-owned and pending. This documentation
does not request another provider, mail or canary test.

## 2026-10-16 — Minimum-release source integration

The minimum-release decision admits PR #238 to normal source integration with
its existing CI and review gates. Executable source remains `9d092fa4`, app
tree `04d17821d8b847418ebfe2e2fa7ef1fcee7af0c5`; the integration update changes
documentation only. ID retains the joined deployment and cold-window sequence.
The accepted installed source remains `6dcf3960` until the actual release is
applied and accepted through the established production procedure.

Existing joined run 123 finished with 536 passes, three failures, zero holds
and two paths not reached. Its failed result is retained. The Website worker
observation requested a static route in an on-demand pool; a later captured PHP
request separately showed UID/GID 1604. ID created a regular session file as
1603:1603 mode 0600, but the plain-HTTP fixture did not retain cookie continuity
with production Secure cookies. The exact cookie rejection mechanism is unknown
because response headers were not retained. Neither observation establishes a
need to weaken product cookie security or change pool idle behavior.

The inquiry service's captured `226/NAMESPACE` failure occurred before PHP
because the guest lacked `/srv/8west/secrets`, which its sandbox denies access
to. This is a recorded guest prerequisite gap; the existing production release
procedure owns any applicable missing-directory correction. The selected
native mail-state component separately passed one test with no skips and two
synthetic filesystem subcases. That component does not turn the joined run
green or prove genuine production producer closure. Tail runtime-to-legacy
and FPM-removal paths were not reached.

ID retains the complete output (SHA-256
`b8e13abb01f4a921769febccbfffc0d93a7b6e18eeaa3015f0246529c052974d`)
and evidence index (SHA-256
`1c8fa64cc642021aa875fd4aa0070ec0e84c30893a0e0bb8f9c21c65708dd0ea`).
Final-main CI and executable material equality are source integration gates.
Actual deployment, forward/inverse compatibility and established HTTPS
production acceptance remain release-owner responsibilities. No production
deployment or new test execution is claimed by this documentation update.

## 2026-10-15 — Runtime/cache source composition

The accepted ordinary release receipt records installed source
`6dcf396066a133956d93dc049c344e2fcff70df7`, app tree
`27e990b0dce9e1eb290d0c6c11aa26b0588e14fd`. The owner-held
`SH-6DCF-ORDINARY-RELEASE-RECEIPT.json` is 4,131 bytes, SHA-256
`da53a6f6a0f95499e017566ee4284b268f561a11762ba05d96febeb1f29ccf4d`.
Its release/report/config/lock observations are retained evidence, not a fresh
host probe. Current `71af0d8` has the same app tree; its later CI changes do not
establish a new deployed application release. Older `2c552151` acceptance and
inverse inputs remain historical.

The new source candidate composes current main with the qualified cold donor
`30090fa`/`5c21055`, reviewed `c31fbb9` dedicated-UID report/cron/ordinary
installer ports, and unchanged cache PHP/tests from PR #181 at `8b7f0b2`.
The current CI shards, mandatory failure aggregate, customer-portal source and
`portal_westy.tools_enabled=true` are preserved. The private cache config paths
and CI invocations are included in this candidate; no live config was edited.

Historical component and cold-fixture receipts do not prove this composition's
actual runtime compatibility. The existing ID-owned joined forward/inverse
route must establish effective UID/groups, private roots, frontend IPC/FPM,
sessions/temp, attachments/support mail, report/cron/CLI and deploy behavior.
Use the [selected current source inverse](../deploy/runtime-isolation/README.md)
joined to actual prior metadata; do not substitute an old `2c` archive, reset
unknown delivery state, or invent another backup operation. Reviewed immutable
source may qualify before normal merge, followed by final-main CI and executable
equality. Production application and user-path acceptance remain separate.
This source work grants no credentials, schema/grants, signing or provider action.

## 2026-08-12 — Canonical MSP-role repair (`PR #31`)

Safeharbor PR [#31](https://github.com/Seckcey/8_west_helpdesk/pull/31)
merged as `7d607ecb78a1edee800105725302926ff4503045` with exact-main Validate
green. The release maps the 8 West ID first-tenant roles `msp_owner`,
`msp_admin`, and `msp_tech` into the local database enum, continues to refuse
viewers and downstream client contacts, and reconciles an existing linked
user's local role on sign-in.

The production release used verified rollback backup
`/srv/8west/backups/safeharbor/20260812T080037Z`. The deployed role-module hash
matches the merge, `suite_roles_test.php` passes on the host, login returns
HTTP 200, and no release-window PHP fatal or parse error was found. A real
signed-in tile click remains owner-operated because the available automated
browser sessions were logged out.

---

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

## 2026-08-29 — Versioned suite-session rollout

This release is consumer-first. Safeharbor must be live in compatibility mode
before 8 West ID publishes its authorization inventory or requires a session
version in the shared cookie.

1. Back up the current release, server-only config, and database. Record hashes
   and verify the backup can be read.
2. Create `/srv/8west/apps/safeharbor/shared/suite-revocations` owned by the web
   user with directory mode `0700`. Set the server-only suite config to:
   `revocation_cache_path=/srv/8west/apps/safeharbor/shared/suite-revocations/snapshot-v3.json`
   and `session_version_mode=compat`.
3. Deploy this consumer release. Before the issuer change, prove a fresh suite
   callback, an authenticated read, and a valid signed legacy feed. An
   unavailable or invalid feed must create no tenant, user, role, or session.
4. Deploy the 8 West ID release that adds the exact cookie version and signed
   authorization inventory. Prove a fresh Safeharbor sign-in records the exact
   version, then prove a version change requests fresh sign-in while an explicit
   revocation retains the durable local cutoff.
5. Change only `session_version_mode` to `strict`, restart the PHP service as
   required, delete no cache as part of the change, and repeat the protected
   signed-in read. Strict is the permanent setting; it rejects a legacy feed
   even after cache loss or a rebuilt host.

Rollback the issuer first while every consumer is still in `compat`. Once this
host is `strict`, do not roll the issuer back to the revoked-only feed. Restore
the paired known-good ID and consumer releases instead; never weaken `strict`
as an outage workaround.
