# Where things stand

**Last verified overall: 2026-08-09** against production, not from memory.
The suite SSO sections below were separately verified on 2026-08-24. The
Customer Service Tools and database sections were verified again on
2026-08-26; the rest of this page was not re-audited during those focused
closeouts.

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
| Migrations 001–011 | **All applied** to production |
| Versioned service goals | **Live** through Safeharbor PR #37 / merge `1796f57` |
| Approval-grade technician time | **Live** through Safeharbor PR #40 / merge `b0a6760` |
| Approved time → Coastmark draft lines | **Deployed dark** through Safeharbor PR #42 / merge `ad7fb1c` and Coastmark PR #54 / merge `8a7e951`; both global gates are off and there are zero mappings/imports |
| Time-provenance bridge | **Live** through PR #38 / merge `ceba5a4` |
| Anything "shipping dark" | Phase 4's sender/receiver only; both are deployed default-off while existing service-intake gates remain live |
| Customer ticket-summary portal (Phase 5A) | **Source candidate only** on `codex/safeharbor-customer-portal`; not merged, registered, mapped, migrated, enabled, or deployed |

## Customer Service Tools development

Phase 1 shipped through Safeharbor PR #37 / merge
`1796f57d0dede4eff298a6a710098999ba7e9670`. It corrects the existing
first-response semantics without a migration. `tickets.sla_due_at` remains an
elapsed-time **response** deadline: the queue and ticket view now compare the
first `kind='tech'` message to that deadline, a resolved ticket with no
technician response is never called met, and Reports measures decided response
outcomes rather than resolution time. `service_goals_test.php` pins the lamp
states, tenant/period isolation, notes-not-responses rule, and report
denominator in hermetic SQLite. Merged sources and their survivors are shown
as neutral `Merged history` and excluded from attainment because the current
merge operation moves messages without retaining original response
provenance.

Phase 2A shipped in the same merge. It adds append-only policy versions and
per-priority targets through migration `010_service_goal_policies.sql`. Every
new ticket path captures one exact target ID, open time, and deadline; client
tier, priority, waiting, retry, refire, and reply changes do not rebase that
snapshot. Historical tickets stay unversioned because their creation-time tier
cannot be proven. Existing tenants receive Standard v1 (480 elapsed minutes)
and Premium v1 (120 elapsed minutes); tenants created later receive the same
defaults lazily on first ticket creation. Unsupported business clocks and
waiting-pause modes fail visibly. Business calendars, holidays, pause
execution, resolution clocks, policy-management UI, and explicit rebase
history remain later work. Migration 010 is applied in production: four
policy versions and sixteen targets cover both current tenants, all 275
historical tickets truthfully retain a NULL target, and all four database
immutability guards are present. The deployed `service_goals.php` and
`ticket.php` hashes match merged source. A production-runtime transaction
canary resolved a v1 target and exact deadline, then rolled back without
leaving a ticket.

The time-provenance bridge shipped through PR #38 / merge `ceba5a4`. It
removes the legacy merge rewrite of `time_entries.ticket_id`, refreshes the
authenticated tenant into every session, and changes no schema. Production
received it before migration 011, preserving a rollback-compatible boundary.

The scratch SSO fixture also now follows the real
`suite_sso_attempt()` → `current_user()` request path: untouched `origin/main`
reproduced its stale avatar-session failure at 30 checks / 1 failure, while the
merged release passed 30 / 0 before the remaining database suites ran.

Phase 3 is live through PR #40 / merge
`b0a67608af42dcd980c72f269f42fb4df04c024e`. It centralizes every timer,
reply, suggestion, and
seed write behind one idempotent time-entry service. Migration 011 snapshots
tenant/client ownership, keeps all legacy work pending, allows only one
pending-to-approved/rejected review, records immutable database events, and
prevents merges from rewriting time provenance. Owners/admins review pending
work; techs cannot. Reports and the restricted CSV use only approved billable
rows, and neither surface posts or invoices anything. The browser keeps a
stopped timer locally until the server acknowledges that exact entry key, so
offline retry and a stale reply do not lose time. Authentication stamps the
database-resolved tenant on every request, and each touched time/report
consumer binds directly to the authenticated user's tenant; regression gates
cover the prior tenant-1 fallback failure.

Release evidence: GitHub validation passed on exact head `4673bc6`; migration
011 was applied operator-first and independently verified at eleven columns,
seven named indexes, five tenant-scoped foreign keys, two checks, one immutable
event table, seven permanent triggers, and zero staging triggers. The one
legacy time row remains pending and gained one migration audit event without
inferred approval. The deployed critical-file hashes match the merge, all 72
production PHP files lint, Apache syntax is valid, login is 200, root is 302,
and no release-window web fatal was recorded. The server-only config remains
mode `640` with `demo_mode=false`. The runtime database account now has only
`SELECT`, `INSERT`, `UPDATE`, and `DELETE`; disposable probes proved DML and
denied `TRUNCATE`, `CREATE`, `ALTER`, and `TRIGGER`, then removed every probe
object. A real-runtime transaction canary created pending time, refused a tech
review, approved as owner/admin, appeared in the approved-billable report,
replayed without duplication, refused fact mutation, recorded both audit
events, and rolled back to the original one entry / one event. Protected
rollback records are
`/srv/8west/backups/safeharbor/20260826T223441Z-pre-phase3-hardening` and
`/srv/8west/backups/safeharbor/20260826T223914Z-pre-phase3-migration`.

Candidate evidence remains green: all eight server-free CI commands pass,
service goals are 55/55, the provenance bridge is 2/2, approval time is 81/81,
suite SSO is 31/31 on scratch MySQL, and migration/runtime replay is 96/96 on
disposable MySQL 8. Desktop and mobile Playwright probes cover both "request
never arrived" and "commit succeeded but response was lost" timer boundaries.
No authenticated production browser session was available during this release;
the deploy did not reset or bypass identity to manufacture one, so a fresh
signed-in visual acceptance remains an operator follow-up rather than a release
rollback condition.

Phase 4's first release is deliberately manual and draft-only. Safeharbor's
operator CLI selects one row by exact tenant slug + entry id + immutable entry
key, requires approved billable reviewer-backed facts and exact protected
tenant/client allowlists, then sends a versioned HMAC payload to Coastmark. Its
stable keys are `tenants.slug`, `safeharbor-client:<captured client_id>`, the
stored `entry_key`, and Safeharbor-local numeric user keys. Raw notes, subjects,
messages, people data, rates, tax, cents, invoice numbers, and posting commands
do not cross the seam. There is no scheduler, batch, or automatic retry.

Coastmark's separately reviewed receiver owns the explicit mapping, agreement
rate, tax, integer cents, immutable import evidence, and one source-managed line
on a dedicated draft. Exact replay is a no-op; changed facts conflict. Neither
side can approve, post, send, create Checkout, record payment, or touch the
ledger through this integration.

The code is merged and deployed dark. Safeharbor PR #42 merged as
`ad7fb1ce31066a5e5ac8889a178832f4627d3a0c`; exact-main Validate run
`33022987271` passed. Its three deployed sender/test hashes match the merge,
all 75 production PHP files lint, the protected config remains byte-identical
and mode `640`, the sender gate is disabled/absent, and the real pending legacy
row is refused before any network request. Public login is 200, root is 302,
Apache syntax is valid, and release-window web fatals are zero. The protected
application rollback is
`/srv/8west/backups/safeharbor/20260826T232259Z-pre-phase4-sender/application.tgz`
(SHA-256 `13f893c330de2e2a1f8b50dee98e6d5f47f9722cd5eee06ec17ba173be9bc631`).

Coastmark PR #54 merged as
`8a7e95169e54063a6fe21373ceab9df6ca860360`; exact-main CI run
`33022976937` passed both quality and PostgreSQL. Production runs image
`coastmark-app:git8a7e95169e54` with migration batch 16 applied and no pending
migration. Both new tables have forced RLS and exactly one tenant policy;
immutable evidence/line triggers and least-privilege runtime table/sequence
grants passed. The receiver gate, secret, and fixed organization remain
unset/disabled, the endpoint returns 404 internally and publicly, and mappings,
imports, and Safeharbor source lines all remain zero. Existing client, invoice,
line, journal, payment, credit-memo, and billing-run counts did not change. The
verified rollback pair is
`/var/backups/coastmark/coastmark-20260826T232622Z.dump` (SHA-256
`1dcb5e20e27c3c0a1a7207bf801982a7f31a71b11b4c8d333a986a9c21702158`) and
`/var/backups/coastmark/coastmark-20260826T232622Z.private.tgz` (SHA-256
`d848b963e0594b14411173871ca559515cecff8ee1fa16b0947a2297781a4680`).

Production currently has zero Coastmark agreements and agreement lines, so no
truthful mapping target exists yet. The remaining Phase 4 acceptance gate is an
explicit Coastmark-owned client/agreement/rate/tax decision followed by one
disabled-then-enabled mapping and a controlled 201/200 canary. The exact
contract and rollback gates are in
[`coastmark-approved-time-export-contract.md`](coastmark-approved-time-export-contract.md).

Phase 5A is now a dark/default-off source candidate based on current
`origin/main` `419d2ad`. It adds a separate `/portal` OIDC session and the
maintained 8 West ID `oidc_v1` PHP kit, accepts only exact `client_owner`,
`client_admin`, `client_staff`, and `client_viewer` roles with the exact
`safeharbor` product, stores the signed subject/session-version pair for at
most eight hours, and fails closed when revocation state is more than five
minutes stale. 8 West ID's additive Safeharbor OIDC surface policy is merged
there as `f0ec49b`; no production client registration or value is invented in
this repository.

Migration 012 creates an immutable explicit binding lifecycle but no binding.
The CLI is prepare-disabled → inspect exact identity slug/provider
tenant/client → explicit enable or disable. Identity slug is globally unique;
the exact provider tenant/client is composite-FK-bound; blank, malformed,
duplicate, cross-provider, `8west`, and `internal` mappings fail closed. Each
authenticated request rechecks revocation plus the active binding, and ticket
summary queries bind both provider tenant and client. The surface shows only
ticket number, subject, status, priority, and timestamps/counts. It has no
messages, attachments, replies, AI, billing, technician-time, endpoint, or
ticket-detail access.

This is not a live claim. No real OIDC client, mapping, migration, global
enable, merge, or deploy was performed. A later controlled canary requires a
chosen business and production callback/client registration, protected
backup, migration/operator proof, server-only config, exact CLI inspection,
fresh signed-in client-role acceptance, tenant isolation, revocation and
disable-on-next-request proof, and desktop/mobile verification. See
`docs/customer-portal-contract.md`.

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

Applied in production: **001 through 011**, including both files numbered 002.
`009_support_intake` (`clients.source_key`, `svc_support_rate`) was applied
2026-08-09. Migration `010_service_goal_policies.sql` was applied before the
matching code on 2026-08-26. It is additive and leaves historical ticket
pointers NULL.

Migration `011_time_entry_approvals.sql` was applied operator-first on
2026-08-26 before the matching Phase 3 code. Production has the complete
approval schema, seven permanent immutability/audit triggers, and the original
legacy row remains pending. The migration replay and real-runtime rollback
canary evidence are recorded in the Phase 3 section above.
Migration `012_customer_portal.sql` is a source candidate only: it is not
applied, and production has no customer-portal tables or mappings claimed by
this page. It must create zero mappings on a later authorized run.

`db/schema.sql` does **not** carry any `svc_*` object. A fresh install needs
`schema.sql` + `002_svc_intake.sql` + `009_support_intake.sql`.

Verify:

```bash
ssh milepost-ec2 'sudo mysql -N safeharbor -e "SHOW TABLES"'
```

Expect `svc_identities`, `svc_rate_buckets`, `svc_support_rate` and
`westy_reports` among them.

## Tests

Nineteen CLI suites live in `app/tests/`. Eleven server-free contract suites
run in CI, including the hermetic service-goal, approval-time, approved-time
export, portal auth/revocation, and portal read-only/rendering gates. CI also
runs the portal migration/isolation suite on disposable MySQL 8. Existing
integration suites (`suite_sso`,
`svc_intake`, `svc_support`, `westy_report`, `intake_service_goal`, and
`time_entries_mysql`) need MySQL plus a scratch-only `config/config.php`;
`utf8_input` needs the scratch config but does not touch the database.
`portal_mysql_test.php` is standalone, refuses any database name not beginning
`safeharbor_portal_test`, and receives only disposable CI MySQL credentials.
PHP lint and the database-free suites run on the Windows dev machine.

The scratch application identity skips migration 010's four trigger
statements because binary-logged MySQL requires an operator privilege to
create them. Before committing or deploying that migration, run it through
the same privileged operator path used in production and prove all four
policy/target UPDATE and DELETE attempts fail with SQLSTATE `45000`; the final
postflight row must be `1 / 1 / 1 / 1`.

Run them on the EC2 box, in a disposable copy, with the database pinned to the
scratch schema — `fresh_schema()` drops every table in whatever database it
reaches, so the pin is not optional:

1. `tar -czf - --exclude='app/config/config.php' app` into a fresh `/tmp/<dir>`
   on the box, `--strip-components=1`.
2. Write `<dir>/config/config.php` as a wrapper that `require`s the real server
   config and then sets `$c['db']['name'] = 'safeharbor_test'`.
3. `cd /tmp/<dir> && php tests/<suite>.php`.
4. **Delete the directory afterwards** — that wrapper pulls in real credentials.

### The browser behavior still needs a rendered test

`time_entries_test.php` now inspects safety-critical `app.js` source patterns,
but no CLI test executes the rendered JavaScript. A green run therefore is not
behavioral browser proof. That half *is* checkable on the Windows machine, and
it is worth doing whenever `app.js` or `westy.js` changes:

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
