# Safeharbor App

The Safeharbor help desk — **plain PHP 8.3 + MySQL + Apache (mod_php)**, built
with the exact conventions of Milepost (same layout, same vhost shape, same
bootstrap style). No framework, no bundler, no build step.

**Live:** https://safeharbor.8westit.com · **Demo sign-in:** `frankie@8westit.com` / `harbor`

## Layout (mirrors Milepost)

```
config/config.sample.php   host config template (config.php is server-only, gitignored)
db/schema.sql              MySQL schema (utf8mb4 / InnoDB, tenant-scoped)
db/seed.php                CLI demo seed — php db/seed.php
db/migrations/             numbered SQL migrations (as needed)
lib/bootstrap.php          config, PDO, helpers (h, rel_time, sla_info, json_out)
lib/auth.php               session auth (bcrypt; 8 West ID SSO seam)
lib/render.php             chrome layout + UI partials + palette data island
public/                    Apache docroot (page-per-file, like Milepost)
  index.php                Queue (j/k · Enter · s/p/a/e · 1-5 filters)
  ticket.php               Ticket detail (thread, reply PRG, rail actions)
  ticket_new.php           New ticket (SLA auto-set from client tier)
  clients.php, client.php  Clients + "answer the phone smart" screen
  client_new.php, client_edit.php  Client CRUD (+ contacts, safe delete)
  users.php                Team — user management (owner/admin add, deactivate)
  time.php                 Timer + suggested entries + today's entries
  login.php, logout.php    Session auth (CSRF-protected like all forms/APIs)
  api/ticket_action.php    Optimistic field updates (strict whitelists)
  api/timer.php            Time-entry logging
  assets/css/app.css       Hand-written design system, semantic tokens
                           (dark / light / system themes)
  assets/js/app.js         Keyboard model, ⌘K palette, timer, theme switch,
                           toasts (vanilla)
  assets/brand/            Logos (synced from ../../brand by deploy.sh)
```

## Conventions

- Every page: `require_once ../lib/render.php; enforce_https(); $user = require_login();`
  → queries → `page_top($user, $title, $active)` → HTML → `page_bottom(palette_data())`
- Every string echoed through `h()`. Every query prepared. UTC everywhere.
- Every entity carries `tenant_id` (Phase 0 = single seeded tenant; SSO claim later).
- API endpoints: JSON in/out via `json_out()`, whitelist-validated fields.
- Keyboard model (client-side): `j/k` move · `↵` open · `s` status · `p` priority ·
  `a` assign · `e` timer · `r` reply · `g q/t/c` navigate · `⌘K` palette.
- Themes: dark / light / system — inside the user menu (and on login),
  persisted in localStorage; CSS semantic tokens per theme, dark mode lifted
  one notch brighter than the original abyss-navy.
- User menu: ONE home for identity + preferences — the lower-left sidebar
  card (opens upward: theme, My profile, Team, sign out). No duplicate
  topbar avatar. profile.php: own name + password change. The 8 West ID
  SSO swap later touches lib/auth.php only — the menu stays as-is and gains
  suite account switching.
- CSRF: every POST form carries `csrf_field()`, every API checks the
  `X-CSRF` header (helpers in lib/auth.php).

## Develop

There is no local build. Edit, lint, deploy, verify on the server:

```bash
find app -name "*.php" -print0 | xargs -0 -n1 php -l     # lint
KEY=~/.ssh/milepost.pem bash deploy/deploy.sh            # deploy (fast)
cd tools/shots && node walkthrough.mjs                   # visual verification
```

The demo data can always be reset on the server:
`cd /srv/8west/apps/safeharbor/current && php db/seed.php`
