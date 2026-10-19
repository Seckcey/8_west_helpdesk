# Safeharbor — repo guide for agents

**Safeharbor** is the help desk app in **8 West IT 365**, beside **Milepost** (RMM), **Coastmark**
(accounting) and the **8 West IT 365 Control Panel**. Product keys in the `8west:products` claim:
`safeharbor` · `milepost` · `coastmark` · `coastline_control_panel`. Signing up for 8 West IT 365
provisions a client in Milepost, 8 West ID, Safeharbor and Coastmark. Live at
https://safeharbor.8westit.com. Vendor: 8 West IT, LLC.

Safeharbor owns help-desk records and workflows. Milepost supplies tenant, asset and telemetry context
only. Coastmark alone owns financial facts: approved time can become only a *draft* invoice line, never
an approval, posting, sending, payment or ledger entry.

**Before changing a gate or feature switch, read [`docs/where-things-stand.md`](docs/where-things-stand.md)**
(what is live and how to check it on the server).

## Stack

Plain PHP 8.3 + MySQL + Apache, no framework, no bundler, no build step, hand-written CSS and vanilla
JS — the same way as Milepost: a page per file in `public/`, shared code in `lib/`, `db/schema.sql`
plus migrations, server-only `config/config.php`. Do not add a second way of doing any of these.

| Path | What |
|---|---|
| `app/` | The PHP app ([`app/README.md`](app/README.md)): `config/ cron/ db/ lib/ public/ tests/` |
| `brand/` | Logo system and `tokens.json`, the source of brand values |
| `deploy/` | `deploy.sh` and its one-page runbook ([`deploy/README.md`](deploy/README.md)), Apache vhosts |
| `docs/` | `where-things-stand.md`, and the contracts other apps rely on (SSO, support intake, Westy failure reports, portal, reports, approved-time export, customer sync) |
| `tools/` | Brand generators; `tools/shots/` holds the Playwright browser tests |

## Commands

```bash
find app -name "*.php" -print0 | xargs -0 -n1 php -l   # syntax
bash deploy/deploy.sh --check                          # what a deploy would change; changes nothing
bash deploy/deploy.sh                                  # ship merged main (also --rollback, --migrate)
./.venv/Scripts/python.exe tools/build_brand.py        # brand SVG masters + tokens.json
./.venv/Scripts/python.exe tools/rasterize_brand.py    # PNG/ICO (needs tools/resvg/)
```

CI (`.github/workflows/validate.yml`) runs every test on every change: one PHP job against a throwaway
in-memory MySQL, and one browser job. GitHub will not merge a pull request while they are red. To add a
test, add one `php app/tests/<name>_test.php` line to the step that matches what it needs (no settings
file, a database, or a settings file). Tests check behaviour, not wording or file contents.

## Rules

1. **Tenant-scope everything.** `tenant_id` on every table; every string through `h()`; every query
   prepared; UTC everywhere.
2. **Shared server.** Production shares `milepost-ec2` with other apps: change only Safeharbor's own
   folder, vhost pair, database and cron file; Apache `reload`, never `restart`. `config/config.php`,
   keys and the server IP never go into git or chat. SSH through the `milepost-ec2` alias.
3. **Deploy only with `bash deploy/deploy.sh`.** It ships merged `main` only, backs up what it changes
   and undoes a failed deploy by itself. Never edit the server's code by hand. Everything under
   `current/` belongs to the release (a deploy removes files git does not have, except
   `config/config.php`), so uploads live outside it at `/srv/8west/apps/safeharbor/shared/attachments`.
4. **Database changes** ship with `bash deploy/deploy.sh --migrate <file.sql>`, which dumps the
   database first. Write each migration so the previous code still runs on the new schema. Keep
   `db/schema.sql` (the fresh-install copy) in step with every migration. A fresh install needs
   `schema.sql` + `002_svc_intake.sql` + `009_support_intake.sql` (schema.sql carries no `svc_*`
   objects) + `018_ticket_auto_close_eligibility.sql`. Migration numbers are not a reliable order
   (two files are numbered 002).
5. **Westy advises, never acts.** His knowledge lives in `lib/westy.php`, `lib/ai.php`,
   `api/westy_chat.php` and `assets/js/westy.js`; his prompt names only real UI, so ship a UI change and
   its prompt update together. AI keys live only in the server's `config/config.php`; the bubble fails
   closed without them. Staff chat never grants command, email or invoice authority; the Westy workflow
   controller is a separate default-off service with its own identity (`docs/westy-workflow-contract.md`);
   the customer workspace may use only the closed tools in `docs/customer-workspace.md`, and repair
   approval is a separate human control. Westy defects open tickets in 8 West IT's own queue, one per problem, with
   technical detail only (`docs/westy-failure-reporting-contract.md`); thumbs-up opens no ticket. The
   shared bubble layout comes from `Seckcey/8_west_westy` and moves only with a reviewed tagged release;
   keep `#westy-root`, `#westy-bubble`, `#westy-panel` and `.westy-head`.
6. **Four `api/svc/*` endpoints, four jobs — never merge them.** `alerts.php` (Milepost machine alerts;
   only an untouched exact signed alert may auto-close on recovery), `westy.php` (Westy defects, text
   re-scrubbed), `support.php` (a person asking for help from Coastmark or Waypoint, text stored
   verbatim, one ticket per submission) and `customers.php` (the default-off Milepost customer
   registry, its own identity, key, gate and tenant allowlist). Each caller has its own identity and
   secret; `svc_auth.php` proves who is calling and each endpoint checks what that caller may file.
   Support callers are server config (`support_intake.sources`): a new product is a config line, an
   identity row and a secret, never a code change. Waypoint is a standalone product, not a suite app.
7. **8 West ID sign-in.** RS256 only, keys chosen by exact `kid` from the issuer JWKS; users are keyed
   by the immutable `sub` claim, never by email. Config lives in the server's `suite` block; there is no
   SSO kill switch in this app. Keep `sso_secret`: the revocation feed still signs with it. Every refusal is logged with a reason code, never the token contents.
   Signed-in sessions are checked against 8 West ID's revocation list through a 60-second cache, which
   deliberately fails open. Contract: `docs/suite-sso-contract.md`.
8. **Mail.** Graph, then SMTP, then PHP mail(); outbound through `lib/mailer.php` and `mail_queue`,
   inbound through `cron/graph_poll.php` (or `cron/imap_poll.php`). Safeharbor and Milepost share one
   Entra app; its credentials live only in the server's config. Westy's mailbox uses its own application and must never repoint the general sender.
   **Mail-loop protection is law:** nothing is ever sent to mailer-daemon, postmaster, no-reply-style
   or bounce addresses (`app/tests/mail_loop_guard_test.php`); a 2026-08 loop wrote 335,000 junk
   messages before the guard existed.
9. **Every string from outside goes through `utf8_clean()`** before it reaches the database;
   mis-encoded mail bytes fail a utf8mb4 insert.
10. **Look and feel.** Brand values come from `brand/tokens.json` (mirrored by hand at the top of
    `app/public/assets/css/app.css`); generated SVGs are never hand-edited. The 8 West Standard: fast,
    keyboard-first, ⌘K reaches everything, dark-mode first, gold means attention, mint means resolved.
    After UI work, look at it in a real browser; `tools/shots/` has Playwright helpers (the walkthrough
    writes tickets, so point it only at a disposable copy).

## Toolchain notes (Windows dev machine)

- Python venv: `python -m venv .venv` then `pip install fonttools pillow`.
- `tools/resvg/resvg.exe` is not committed (download resvg-win64 into `tools/resvg/`);
  `tools/fonts/Inter-Variable.ttf` is (OFL).
- Local PHP 8.3 is for `php -l`; test containers run on Coastline (`ssh coastline`), never on this PC.
