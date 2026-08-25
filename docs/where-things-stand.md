# Where things stand

**Last verified overall: 2026-08-09** against production, not from memory.
The suite SSO sections below were separately verified on 2026-08-24; the
rest of this page was not re-audited during that focused closeout.

One page for anyone — human or agent — picking this repo up. It answers "is
this thing actually on?" for every moving part, and every claim comes with the
command that proves it. **Check, don't trust.** This file goes stale the moment
somebody ships without updating it, and a stale status page is worse than none:
it is how two sessions came to build the same Waypoint feature on the same day,
one of which was thrown away.

If you change what is live, change this page in the same PR.

---

## The short version

| Thing | State |
|---|---|
| Safeharbor itself | **Live** at https://safeharbor.8westit.com, v1.0 feature-complete |
| Alert intake (`api/svc/alerts.php`) | **Live** since 2026-07-29, Milepost emitting |
| Westy defect intake (`api/svc/westy.php`) | **Live** — receiver shipped; emitters are the other repos' side |
| Westy thumbs up/down (`assets/js/westy.js`) | **Shipped 2026-08-09** — down files a ticket, up files nothing |
| Partner support intake (`api/svc/support.php`) | **Live** since 2026-08-09, Coastmark and Waypoint both emitting |
| 8 West ID suite SSO | **Live**; canonical first-tenant roles plus RS256 verification deployed through PR #34 |
| Migrations 001–009 | **All applied** to production |
| Anything "shipping dark" | **Nothing.** Both `svc.enabled` and `svc.support_enabled` are `true` |

## Customer Service Tools Phase 1 candidate

Development branch `codex/customer-service-tools-phase1` corrects the existing
first-response semantics without a migration. `tickets.sla_due_at` remains an
elapsed-time **response** deadline: the queue and ticket view now compare the
first `kind='tech'` message to that deadline, a resolved ticket with no
technician response is never called met, and Reports measures decided response
outcomes rather than resolution time. `service_goals_test.php` pins the lamp
states, tenant/period isolation, notes-not-responses rule, and report
denominator in hermetic SQLite. Merged sources and their survivors are shown
as neutral `Merged history` and excluded from attainment because the current
merge operation moves messages without retaining original response
provenance. This candidate is **not live** until its branch
is reviewed, merged, and separately authorized for deployment. Business hours,
holidays, pause rules, resolution goals, and policy versioning remain the next
service-goal phase. The scratch SSO fixture also now follows the real
`suite_sso_attempt()` → `current_user()` request path: untouched `origin/main`
reproduced its stale avatar-session failure at 30 checks / 1 failure, while the
candidate passed 30 / 0 before the remaining database suites ran.

## First-tenant suite SSO repair

Safeharbor PR [#31](https://github.com/Seckcey/8_west_helpdesk/pull/31)
merged as `7d607ecb78a1edee800105725302926ff4503045` and is deployed. It
maps the issuer's canonical `msp_owner`, `msp_admin`, and `msp_tech` roles to
Safeharbor's `owner`, `admin`, and `tech` database enum values. `msp_viewer`
and all downstream `client_*` roles remain refused. Existing suite-linked
users have their local role reconciled on sign-in instead of retaining a stale
privilege.

This repair was required because self-serve 8 West ID onboarding issues
`msp_owner`, while the older consumer admitted only `owner`, `admin`, and
`tech`. Production logs recorded `role_not_admitted`; the cookie signature,
product entitlement, and tile URL were not the failure.

The deployed `app/lib/suite_roles.php` SHA-256 is
`5278b8ef09e56fba032a169aa446c756a326bf0f06f793dc1ff609dd39b6fcd3`,
matching the merged source. `suite_roles_test.php`, PHP lint, public login, and
release-window fatal checks passed. Rollback is the verified application and
database backup `/srv/8west/backups/safeharbor/20260812T080037Z`.

The owner-operated tile check was completed during the 2026-08-23 RS256 issuer
cutover: a fresh signed-in 8 West ID session landed in Safeharbor without a
second login form.

## RS256 suite-token verification

Safeharbor PR [#34](https://github.com/Seckcey/8_west_helpdesk/pull/34)
merged as `2f64cdf67aa0e9d1999ddcca5476d48b9fcb9003`; exact-main CI run
`32623126269` passed and that commit is deployed. Production accepts `RS256`
only, loads the issuer's same-origin JWKS, and verified a fresh RS256 cookie by
exact published `kid` after 8 West ID changed issuance at
`2026-08-23T07:04:43Z`.

The suite-wide contraction completed at `2026-08-24T04:17:47Z`, after more
than 21 hours of clean observation. Safeharbor refused a generated legacy
HS256 token with `unexpected_algorithm` and returned the ordinary login page;
public login remained HTTP 200. The configuration backup for that change is
`/srv/8west/backups/safeharbor/20260824T041747Z-pre-rs256-only-config.php`.
Safeharbor retains the shared secret only for the separately HMAC-signed
revocation feed. A fresh post-contraction ID session entered the Safeharbor
queue without another login. The application-release rollback remains
`/srv/8west/backups/safeharbor/20260823T063315Z-pre-2f64cdf67aa0e9d1999ddcca5476d48b9fcb9003`.

## Who is allowed to call us, and are they?

| Identity | Endpoint | Product | Active | Registered |
|---|---|---|---|---|
| `milepost` | `alerts.php` | Milepost (RMM) | yes | 2026-07 |
| `coastmark-support` | `support.php` | Coastmark, 365 edition | yes | 2026-08-09 |
| `waypoint-support` | `support.php` | Waypoint — **standalone, not a suite app** | yes | 2026-08-09 |

`milepost-westy` and `controlpanel-westy` appear in the Westy contract but are
**not registered in production yet** — check before assuming either works.

Verify:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT service, is_active, last_seen_at FROM svc_identities ORDER BY service"'
```

`last_seen_at` is the honest signal: it updates on every successfully signed
request, so a stale timestamp means that emitter has gone quiet.

## Partner support intake — the full picture

Contract: `coastmark-support-intake-contract.md` (filename kept so existing
links work; it covers both producers).

- **Both apps went live 2026-08-09** after canary tickets #205 (Coastmark) and
  #206 (Waypoint) passed on disposable slugs. Both canaries were deleted the
  same day; nothing of them remains.
- **The reply path is proven end to end** — acknowledgement emails carrying
  `[#205]` / `[#206]` arrived, which means a tech's reply reaches the requester
  and their answer threads back onto the ticket.
- **Client row naming is settled.** We append `" ({Product})"` to whatever
  `tenant.display_name` arrives. Both emitter teams checked their real tenant
  data (Coastmark 2 tenants, Waypoint 4, longest names 47 and 30 characters,
  none containing the product name) and confirmed they send the bare company
  name. Rows read "Acme MSP (Coastmark)". **Keep the append — do not add a
  de-duplication guard.** The canary's doubled name was the canary's own fault.
- **Routing is by `clients.source_key`** (`coastmark:{slug}` /
  `waypoint:{slug}`), never by name, so staff can rename a client row freely.
  One firm using both products correctly gets two rows. That key must fit **64
  characters**; since 2026-08-09 an over-long one is refused with a `422`
  rather than truncated, because a cut key would merge two of a product's
  customers onto one client row.
- **The caller list is config, not code** (since 2026-08-09,
  `support_intake.sources`). Adding product number three is a config line, an
  identity row and a secret — no code change, no PR, no deploy of this repo.
  Leave the key out and the built-in defaults apply, which is exactly what
  production does today; an empty list means nobody at all. A malformed entry
  is skipped and named in the error log, so a typo shows up as a log line
  rather than as an unexplained `401` at the emitter.

Verify the schema this depends on:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT id, name, source_key FROM clients WHERE source_key IS NOT NULL"'
```

## Westy thumbs up / thumbs down

Shipped 2026-08-09. Two icon buttons under every real Westy answer, replacing
the old underlined "This wasn't helpful" link. **No migration** — this deploys
as plain code.

The two are deliberately not symmetrical, and that asymmetry is the feature:

- **Thumbs-down** is unchanged from the link it replaced. It opens the review
  box, the technician sees the exact question and answer, edits or cancels, and
  only then does `api/westy_feedback.php` open a ticket.
- **Thumbs-up** is one click and over. No box, no confirmation, and **no
  ticket** — `api/westy_thumbs_up.php` writes a single `assistant_log` row and
  stops. Tickets are for problems; praise that made somebody work would stop
  being praise. The browser fires it and forgets it, so a failure never
  interrupts a technician.

It stores no chat text, only lengths and the question fingerprint — one click
with no review box means nobody read those words and agreed to send them. The
fingerprint is the same one the flag path uses, so the two signals about a
question can be read against each other:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT meta, created_at FROM assistant_log WHERE action = \"westy_thumbs_up\" ORDER BY id DESC LIMIT 10"'
```

`westy_report_test.php` covers this, and the check worth knowing about is
`thumbs_up_creates_no_ticket`. If that ever goes red, the button has grown
teeth.

## Database

Applied in production: **001 through 009**, including both files numbered 002.
`009_support_intake` (`clients.source_key`, `svc_support_rate`) was applied
2026-08-09.

`db/schema.sql` does **not** carry any `svc_*` object. A fresh install needs
`schema.sql` + `002_svc_intake.sql` + `009_support_intake.sql`.

Verify:

```bash
ssh milepost-ec2 'sudo mysql -N safeharbor -e "SHOW TABLES"'
```

Expect `svc_identities`, `svc_rate_buckets`, `svc_support_rate` and
`westy_reports` among them.

## Tests

Eleven CLI suites live in `app/tests/`. Six database-free contract suites now
run in CI, including the hermetic service-goal query test. Four integration
suites (`suite_sso`, `svc_intake`, `svc_support`, and `westy_report`) still need
MySQL plus a scratch-only `config/config.php`; `utf8_input` needs the scratch
config but does not touch the database. PHP lint and the database-free suites
run on the Windows dev machine.

Run them on the EC2 box, in a disposable copy, with the database pinned to the
scratch schema — `fresh_schema()` drops every table in whatever database it
reaches, so the pin is not optional:

1. `tar -czf - --exclude='app/config/config.php' app` into a fresh `/tmp/<dir>`
   on the box, `--strip-components=1`.
2. Write `<dir>/config/config.php` as a wrapper that `require`s the real server
   config and then sets `$c['db']['name'] = 'safeharbor_test'`.
3. `cd /tmp/<dir> && php tests/<suite>.php`.
4. **Delete the directory afterwards** — that wrapper pulls in real credentials.

### The browser half has no suite — drive it by hand

Nothing in `app/tests/` touches `assets/js/`, so a green run says **nothing**
about the chat bubble. That half *is* checkable on the Windows machine, and it
is worth doing whenever `westy.js` changes:

1. Copy `app.css` and `westy.js` into a scratch directory and add a page with
   the same scaffold `lib/westy.php` renders (`#westy-root` and its children,
   `data-onboarded="1"` so the tour stays out of the way).
2. Replace `window.fetch` **before** the `westy.js` tag, returning canned JSON
   and recording every call. The real code path then runs unmodified.
3. Serve it — `php -S 127.0.0.1:8899 -t <dir>` — and drive it in a browser.
   `file://` will not do; the tooling blocks that protocol.

The thumbs work (PR #28) shipped two bugs' worth of proof that this is not
ceremony. Both were invisible to PHP tests and to reading the diff: an author
`display` on the button beat the user agent's `[hidden] { display: none }`, so
neither thumb could hide; and a new `row` parameter turned out to be the same
variable as a `var row` further down the same function, so Cancel reset the
wrong element.

Last full integration run, 2026-08-09, the then-current seven suites ran back
to back on one scratch database, all green:

| Suite | Checks |
|---|---|
| `svc_support_test` | 87 |
| `westy_report_test` | 104 |
| `svc_intake_test` | 47 |
| `utf8_input_test` | 25 |
| `suite_sso_test` | 19 |
| `suite_auth_policy_test` · `suite_logout_test` | pass/fail only |

Two suites had **never once executed** before 2026-08-09 — they died on a
foreign key before their first check, so features shipped with tests that only
looked green. If you inherit a suite you have not personally watched run,
assume it has never run.

## Known open items

Nothing here blocks anyone; all are recorded so they are not rediscovered.

1. **`milepost-westy` / `controlpanel-westy` are unregistered.** The Westy
   contract describes them; production has no rows for them.
2. **The `002` numbering collision** (`002_svc_intake` vs
   `002_westy_onboarding`) means migration numbers are not a reliable order.
3. **Production has no `support_intake` block in its config at all.** Support
   intake runs entirely on code defaults — which is fine and deliberate, but it
   means the first person to add product number three will be *creating* that
   block, not editing it. Copy the shape from `config/config.sample.php`.

## Traps that have already cost time

- **`env_file` in Docker Compose is read at container creation.** A
  `docker restart` does not pick up a new secret; only `docker compose up -d`
  does. A correctly installed secret will still return `401` after a mere
  restart. This bit Waypoint during go-live.
- **Never hand-edit the deployed tree.** `deploy.sh` untars straight over
  `current/`, so a host-side edit vanishes without comment on the next release.
- **SSH uses the origin IP, not the domain** — Cloudflare proxies the hostname
  and port 22 times out. The `milepost-ec2` alias handles this.
- **Deploy only from committed `main`.** Two checkouts once overwrote each
  other's files on the same box.
- **Check `git fetch` and open PRs before starting.** Two sessions built
  Waypoint support the same day.
