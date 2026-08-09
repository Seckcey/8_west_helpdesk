# Where things stand

**Last verified: 2026-08-09** against production, not from memory.

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
| Migrations 001–009 | **All applied** to production |
| Anything "shipping dark" | **Nothing.** Both `svc.enabled` and `svc.support_enabled` are `true` |

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
  One firm using both products correctly gets two rows.

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

Seven suites in `app/tests/`, all CLI-only and all needing MySQL. **They do not
run on the Windows dev machine** — there is no database and no
`config/config.php` there, so `php -l` is the only local check.

Run them on the EC2 box, in a disposable copy, with the database pinned to the
scratch schema — `fresh_schema()` drops every table in whatever database it
reaches, so the pin is not optional:

1. `tar -czf - --exclude='app/config/config.php' app` into a fresh `/tmp/<dir>`
   on the box, `--strip-components=1`.
2. Write `<dir>/config/config.php` as a wrapper that `require`s the real server
   config and then sets `$c['db']['name'] = 'safeharbor_test'`.
3. `cd /tmp/<dir> && php tests/<suite>.php`.
4. **Delete the directory afterwards** — that wrapper pulls in real credentials.

Last full run, 2026-08-09, all seven back to back on one scratch database,
all green:

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

1. **`SUPPORT_SOURCES` is a constant, not config.** Adding the next product
   needs a code change, a PR and a deploy. The standing direction is that every
   8 West product eventually files support here, so this wants inverting. A
   finished config-driven version sits on branch `feat/partner-support-sources`
   (PR #26, closed as a duplicate of #25) — **salvage it, do not rewrite it.**
2. **`milepost-westy` / `controlpanel-westy` are unregistered.** The Westy
   contract describes them; production has no rows for them.
3. **The `002` numbering collision** (`002_svc_intake` vs
   `002_westy_onboarding`) means migration numbers are not a reliable order.

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
