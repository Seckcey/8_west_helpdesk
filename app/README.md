# Safeharbor App

The Safeharbor help desk — **plain PHP 8.3 + MySQL + Apache (mod_php)**, built
with the exact conventions of Milepost (same layout, same vhost shape, same
bootstrap style). No framework, no bundler, no build step.

**Live:** https://safeharbor.8westit.com. Production credentials are not
published; use an authorized 8 West ID or local account.

## Layout (mirrors Milepost)

```
config/config.sample.php   host config template (config.php is server-only, gitignored)
db/schema.sql              MySQL schema (utf8mb4 / InnoDB, tenant-scoped)
db/seed.php                CLI demo seed — php db/seed.php
db/migrations/             numbered SQL migrations (as needed; 007 suite
                           subject applied to production 2026-08-02)
lib/bootstrap.php          config, PDO, helpers (h, rel_time, sla_info, json_out)
lib/service_goals.php      deterministic first-response target lamps + attainment
lib/auth.php               session auth (bcrypt + CSRF) and 8 West ID suite SSO:
                           suite_sso_attempt() verifies the ewid_token cookie,
                           keys the user by the immutable `sub` claim, provisions
                           the tenant and user on first arrival, syncs theme/avatar,
                           and audits every deny via suite_sso_refuse()
lib/revocation.php         signed 8 West ID revocation-list enforcement for
                           established suite sessions (60s cache; logged,
                           bounded fail-open when the issuer is unavailable)
lib/jwt.php                HS256/RS256 verification + exact-kid JWKS loading
lib/render.php             chrome layout + UI partials + palette data island
lib/ai.php                 server-side AI layer (Anthropic/OpenAI via raw cURL;
                           keys server-only; gated dev stub — Milepost port)
lib/westy.php              Westy, the suite assistant: Safeharbor-grounded
                           prompt/onboarding + bubble renderer; shared layout
                           loads from westy.8westit.com/v1
lib/mailer.php             outbound mail (mail_queue + transports:
                           Graph sendMail → SMTP → PHP mail(); Milepost parity)
lib/intake.php             shared inbound logic (threading, contacts, confirms)
lib/svc_auth.php           HMAC + timestamp + rate limit for service-to-service
lib/svc_intake.php         Milepost alert events → tickets (idempotent upsert)
lib/westy_report.php       Westy defects → 8 West IT's OWN queue (one ticket per
                           problem; stored text re-scrubbed on arrival)
lib/svc_support.php        Coastmark + Waypoint support requests → 8 West IT's
                           own queue (one ticket per submission; text stored
                           VERBATIM — deliberately none of westy_report's
                           collapsing). Callers come from server config,
                           `support_intake.sources` — adding the next product
                           is not a code change
tests/                     CLI-only hermetic tests against a scratch database —
                           suite_sso_test.php, svc_intake_test.php,
                           westy_report_test.php, svc_support_test.php
cron/mail_dispatch.php     1-min outbound sender (backoff retries)
cron/graph_poll.php        1-min email-to-ticket via Microsoft Graph
                           (Entra app, Mail.Read; marks read, never deletes)
cron/imap_poll.php         IMAP fallback intake for non-M365 mailboxes
public/                    Apache docroot (page-per-file, like Milepost)
  index.php                Queue (j/k · Enter · s/p/a/e · 1-5 filters)
  ticket.php               Ticket detail (thread, reply PRG, rail actions)
  ticket_new.php           New ticket (elapsed-time response target from client tier)
  clients.php, client.php  Clients + "answer the phone smart" screen
  client_new.php, client_edit.php  Client CRUD (+ contacts, safe delete)
  users.php                Team — user management (owner/admin add, deactivate)
  time.php                 Timer + suggested entries + today's entries
  reports.php              Real numbers: first response, response-target attainment, aging, time,
                           billable by client (+ reports_export.php CSV)
  snippets.php             Saved replies ("/" in the composer; merge fields)
  csat.php                 One-tap resolution survey (token-authed, public)
  attachment.php           Forced-download attachment serving
  login.php, logout.php    Session auth (CSRF-protected like all forms/APIs)
  api/ticket_action.php    Optimistic field updates (strict whitelists)
  api/timer.php            Time-entry logging
  api/westy_chat.php       Westy chat (advise-only; rate-limited via assistant_log)
  api/westy_onboard.php    Marks the first-run welcome as done (users.onboarded_at)
  api/presence.php         Collision-detection heartbeat (viewing/typing chips)
  api/search.php           Deep search (subjects + FULLTEXT bodies, resolved incl.)
  api/ticket_merge.php     Merge a ticket into a survivor (stub left behind)
  api/svc/alerts.php       Signed Milepost alert intake — 404s while svc.enabled
                           is false (ships dark)
  api/svc/westy.php        Signed Westy failure / flagged-answer reports from
                           Milepost + the Control Panel (one ticket per problem)
  api/svc/support.php      Signed human support requests from Coastmark and
                           Waypoint — one ticket each, text verbatim. LIVE
                           since 2026-08-09 (svc.support_enabled is true)
  assets/css/app.css       Hand-written design system, semantic tokens
                           (dark / light / system themes)
  assets/js/app.js         Keyboard model, ⌘K palette, timer, theme switch,
                           toasts (vanilla)
  assets/js/westy.js       Westy bubble: chat + first-run onboarding tour
  assets/img/              Westy avatar (shared suite mascot)
  assets/brand/            Logos (synced from ../../brand by deploy.sh)
```

## Conventions

- Every page: `require_once ../lib/render.php; enforce_https(); $user = require_login();`
  → queries → `page_top($user, $title, $active)` → HTML → `page_bottom(palette_data())`
- Every string echoed through `h()`. Every query prepared. UTC everywhere.
- Every entity carries `tenant_id` (8 West ID sign-ins resolve it from the
  `8west:tenant` claim and create the tenant row if the slug is new; local
  sign-ins + cron use the seeded tenant 1).
- API endpoints: JSON in/out via `json_out()`, whitelist-validated fields.
- Keyboard model (client-side): `j/k` move · `↵` open · `1–5` filters ·
  `s` status · `p` priority · `a` assign · `e` timer · `r` reply ·
  `n` internal note · `/` saved replies · `g q/t/c` navigate ·
  `⌘K` palette (3+ chars = deep search over message bodies, resolved
  included) · `?` shortcut card.
- Composer: Reply/Internal note tabs on one box (notes never email, never
  change status); `/` inserts saved replies with merge fields resolved
  ({contact.first_name} {client.name} {ticket.id} {tech.first_name});
  paperclip attaches ≤5 files ≤15MB; a running timer's minutes log
  themselves on Send (billable toggle); a stale thread BLOCKS the send and
  preserves the draft.
- Collision detection: 20s presence heartbeats paint "viewing/typing…"
  chips in the ticket header (api/presence.php).
- Merge: rail button or the duplicate banner (same contact, 48h) —
  messages/files/time move to the survivor, the source becomes a linked
  resolved stub (merged_into_id).
- Waiting = parked with a 72h leash (resurface_at); housekeeping
  (piggybacked on mail_dispatch) reopens it; any client reply clears it.
- Resolving a ticket with a human contact emails a one-tap CSAT survey
  (csat.php, token-authed). Machine addresses never get mail — the
  bounce-loop guards in intake/mailer are law.
- Every wild string is `utf8_clean()`ed before insert (mis-encoded email
  bytes 500 a utf8mb4 write otherwise).
- Westy: advise-only, fails closed without server AI config; his prompt in
  `lib/westy.php` names only REAL UI — update it in the same change as any UI
  you ship. Shared drag/resize layout is owned by `Seckcey/8_west_westy`, not
  this repo, and loads from `https://westy.8westit.com/v1/westy-layout.js`.
  Only a reviewed tag published as a GitHub Release may move `/v1/`; a commit
  alone must not propagate. Keep the consumer DOM contract intact:
  `#westy-root`, `#westy-bubble`, `#westy-panel`, `.westy-head`, with the panel
  positioned inside the root. The script stamps `data-westy-version` for
  verification; a bad shared release affects every `/v1/` consumer and is
  rolled back centrally to a frozen version.
- Themes: dark / light / system — inside the user menu (and on login),
  persisted in localStorage; CSS semantic tokens per theme, dark mode lifted
  one notch brighter than the original abyss-navy.
- User menu: ONE home for identity + preferences — the lower-left sidebar
  card (opens upward: theme, My profile, Team, sign out). No duplicate
  topbar avatar. profile.php: own name + password change. 8 West ID SSO is
  implemented in lib/auth.php (legacy suite cookie, now RS256 with bounded
  HS256 overlap; separate from OIDC — see
  docs/suite-sso-contract.md); the menu shows the central 8 West ID avatar
  and theme, both refreshed from the token on every request. Established suite
  sessions are checked against 8 West ID's signed revocation list on every
  authenticated request through a 60-second cache.
- CSRF: every POST form carries `csrf_field()`, every API checks the
  `X-CSRF` header (helpers in lib/auth.php).

## Develop

There is no local build. Edit, lint, deploy, verify on the server:

```bash
find app -name "*.php" -print0 | xargs -0 -n1 php -l     # lint
SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
cd tools/shots && node walkthrough.mjs                   # visual verification
```

The demo data can always be reset on the server:
`cd /srv/8west/apps/safeharbor/current && php db/seed.php`
