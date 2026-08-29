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
db/migrations/             numbered SQL migrations (010 versioned service
                           goals, 011 approval-grade time, 012 customer portal,
                           013 business reports, 014 guarded policy publication,
                           015 default-off Milepost customer sync, and 016 time
                           corrections/overlap guards, 017 ID report contact,
                           and 018 ticket auto-close are applied; 019 client-
                           scoped report contact is a release candidate)
db/manage_service_goals.php
                           operator-only inspect/plan/publish for one exact
                           tenant + Standard/Premium policy; reviewed digest
                           required and no batch or web writer
db/manage_portal_client.php CLI prepare/inspect/enable/disable for one exact
                           identity tenant slug → provider tenant/client binding
db/manage_business_reports.php
                           owner/admin definition + schedule lifecycle; canonical
                           prepare reads a versioned tenant or exact-client
                           admin contact from 8 West ID but never echoes it
db/run_business_report.php exact dry-run/generate/deliver for one pinned schedule
                           or archive; no batch or automatic retry
lib/bootstrap.php          config, PDO, helpers (h, rel_time, sla_info, json_out)
lib/service_goals.php      versioned target resolver/snapshot + deterministic
                           first-response lamps and attainment
lib/service_goal_policy_admin.php
                           full immutable-history verification, digest-bound
                           publication, exact-four target write, and tier RBAC
lib/time_entries.php       single writer for idempotent pending time + guarded
                           owner/admin approval decisions
lib/coastmark_time_export.php
                           default-off, operator-controlled export of one
                           approved billable time fact to Coastmark drafts
lib/eightwestid/           exact maintained 8 West ID oidc_v1 PHP client
lib/portal_auth.php        separate <=8h OIDC session, exact client roles,
                           bounded fail-closed revocation, active binding recheck
lib/portal_data.php        explicit binding lifecycle + tenant/client-bound,
                           read-only ticket-summary queries
lib/portal_render.php      independent dark customer chrome (no staff session)
lib/business_reports.php   versioned weekly aggregates, oldest-period catch-up,
                           immutable archive hashes, and one-attempt delivery truth
lib/id_report_contacts.php operator-only exact-host/HMAC 8 West ID contact
                           snapshot client with explicit tenant/client maps;
                           never loaded by cron or report runs
lib/auth.php               session auth (bcrypt + CSRF) and 8 West ID suite SSO:
                           suite_sso_attempt() verifies the ewid_token cookie,
                           keys the user by the immutable `sub` claim, provisions
                           the tenant and user on first arrival, refreshes the
                           database-resolved tenant session, syncs theme/avatar,
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
lib/svc_intake.php         Milepost alert events → tickets (idempotent; only
                           untouched signed telemetry may auto-close)
lib/ticket_lifecycle.php   atomic one-use telemetry recovery transition
lib/suite_customer_sync.php
                           dedicated signed Milepost customer registry → stable
                           Safeharbor client binding + immutable receipts; dark
                           unless its independent gate and allowlist are enabled
lib/westy_report.php       Westy defects → 8 West IT's OWN queue (one ticket per
                           problem; stored text re-scrubbed on arrival)
db/export_approved_time.php
                           dry-run/send CLI requiring exact tenant + id + key;
                           never supplies financial facts or posts an invoice
lib/svc_support.php        Coastmark + Waypoint support requests → 8 West IT's
                           own queue (one ticket per submission; text stored
                           VERBATIM — deliberately none of westy_report's
                           collapsing). Callers come from server config,
                           `support_intake.sources` — adding the next product
                           is not a code change
tests/                     CLI contract + scratch-MySQL integration tests —
                           suite_sso_test.php, svc_intake_test.php,
                           ticket_auto_close_contract_test.php,
                           ticket_auto_close_mysql_test.php,
                           westy_report_test.php, svc_support_test.php,
                           intake_service_goal_test.php, time_entries_test.php,
                           time_entries_mysql_test.php,
                           service_goal_policy_admin_test.php,
                           service_goal_policy_mysql_test.php,
                           coastmark_time_export_test.php, portal_auth_test.php,
                           portal_data_test.php, portal_mysql_test.php,
                           business_reports_test.php,
                           business_reports_mysql_test.php,
                           id_report_contacts_test.php,
                           id_report_contacts_mysql_test.php,
                           suite_customer_sync_test.php,
                           suite_customer_sync_mysql_test.php
cron/mail_dispatch.php     1-min outbound sender (backoff retries)
cron/business_reports.php  independently gated report generation + one-attempt
                           Graph submission; deliberately not mail_queue
cron/graph_poll.php        1-min email-to-ticket via Microsoft Graph
                           (Entra app, Mail.Read; marks read, never deletes)
cron/imap_poll.php         IMAP fallback intake for non-M365 mailboxes
public/                    Apache docroot (page-per-file, like Milepost)
  index.php                Queue (j/k · Enter · s/p/a/e · 1-5 filters)
  ticket.php               Ticket detail (thread, reply PRG, rail actions)
  ticket_new.php           New ticket (captures exact effective policy version/target)
  clients.php, client.php  Clients + "answer the phone smart" screen
  client_new.php, client_edit.php  Client CRUD (+ contacts, safe delete)
  users.php                Team — user management (owner/admin add, deactivate)
  time.php                 Reliable timer + suggestions + own entries +
                           owner/admin pending review queue
  reports.php              Real numbers: first response, response-target attainment, aging,
                           approved billable time (+ owner/admin CSV)
  snippets.php             Saved replies ("/" in the composer; merge fields)
  csat.php                 One-tap resolution survey (token-authed, public)
  attachment.php           Forced-download attachment serving
  login.php, logout.php    Session auth (CSRF-protected like all forms/APIs)
  portal/                  Default-off customer OIDC surface: read-only ticket
                           summaries + POST/CSRF logout; no detail or mutation API
  api/ticket_action.php    Optimistic field updates (strict whitelists)
  api/timer.php            Idempotent pending timer/suggestion submission
  api/time_entry_review.php Owner/admin approve/reject transition
  api/westy_chat.php       Westy chat (advise-only; rate-limited via assistant_log)
  api/westy_onboard.php    Marks the first-run welcome as done (users.onboarded_at)
  api/presence.php         Collision-detection heartbeat (viewing/typing chips)
  api/search.php           Deep search (subjects + FULLTEXT bodies, resolved incl.)
  api/ticket_merge.php     Merge a ticket into a survivor (stub left behind)
  api/svc/alerts.php       Signed Milepost alert intake — live; 404s only while
                           the svc.enabled kill switch is false
  api/svc/westy.php        Signed Westy failure / flagged-answer reports from
                           Milepost + the Control Panel (one ticket per problem)
  api/svc/support.php      Signed human support requests from Coastmark and
                           Waypoint — one ticket each, text verbatim. LIVE
                           since 2026-08-09 (svc.support_enabled is true)
  api/svc/customers.php    Dedicated Milepost customer-registry receiver;
                           strict v1 HMAC/event contract, independently gated
                           default-off; never reuses clients.source_key
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
  paperclip attaches ≤5 files ≤15MB; a running timer freezes on Send and
  clears locally only after the server acknowledges that exact idempotency key
  (billable toggle); a stale thread BLOCKS the send and preserves both draft
  and timer.
- Collision detection: 20s presence heartbeats paint "viewing/typing…"
  chips in the ticket header (api/presence.php).
- Merge: rail button or the duplicate banner (same contact, 48h) —
  messages/files move to the survivor and the source becomes a linked resolved
  stub (`merged_into_id`). Time remains on that source so its captured
  ticket/client provenance cannot change.
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
- Customer portal: independent from staff auth and dark unless the host sets
  `portal.enabled=true`. It admits only exact `client_*` roles carrying the
  `safeharbor` entitlement, resolves a normalized identity tenant slug only
  through an explicit active CLI binding, and binds every ticket read to both
  provider tenant and client. See `docs/customer-portal-contract.md`; never
  infer a mapping from email/domain/name or enable a live business as a test.
  Production has an explicit disabled portal block with the fixed issuer and
  callback, empty client ID/secret, a private empty revocation-cache directory,
  zero bindings/events, and no authenticated customer canary. The 8 West ID
  prerequisite is deployed dark but has zero registered Safeharbor OIDC
  clients. Every portal route, including GET and POST logout, remains
  cookie-free 404 while disabled. This surface shows ticket summaries only; it
  has no billing, ticket detail, mutation, or customer endpoint control.
- Business reports: independent versioned definitions and schedules produce
  exact weekly aggregate archives. Both generation and delivery default off;
  exact schedule/tenant/client/recipient allowlists and `canary_only` apply.
  A Graph 202 means provider-submitted, not recipient-delivered, and ambiguous
  delivery is terminal without automatic retry. Production has migration 013,
  one prepared 8 West IT definition/schedule canary, both execution gates off,
  zero archives, deliveries, or attempts, and no server scheduler.
  See `docs/business-reports-contract.md`.
- ID-backed report onboarding: `prepare-from-id`, historical
  `prepare-client-from-id`, and new-customer `prepare-customer-from-id` are the
  only network call sites and use a dedicated
  default-off HMAC config. Migration 017 is live and stores
  an immutable local-tenant/stable-ID binding plus append-only contact-version
  evidence in the same transaction as the disabled schedule. One redacted
  8 West IT contact canary was stored; both contact gates are off. This path
  cannot generate or send a report. Migration 019 is a not-applied release
  candidate adding an independent exact-client binding/evidence lane and one
  immutable manual/tenant-ID/client-ID scope per logical schedule. Inspect
  reports the exact pinned scope and latest inherited evidence even after
  enable/disable versions. ID-scoped activation and active/due reads fail closed
  unless the current recipient (and client for client scope) matches that latest
  evidence; no path converts the tenant canary or infers identity from
  names/domains/email. New customer preparation derives an exact
  `milepost-customer:<uuid>` key from the active local customer-sync binding;
  the ID contact lookup never selects a customer by Safeharbor's wipe-sensitive
  numeric client id.
  `prepare-client-from-id` now refuses any new logical schedule and can only
  refresh an existing client-scoped ID schedule. A new Milepost-managed client
  also cannot enter through the manual or tenant-contact lane; the reserved
  8 West IT master customer remains explicitly tenant-scoped.
- Milepost customer sync: independent from alert/support intake and dark unless
  `suite_customer_sync.enabled` is exactly true. The signed payload resolves an
  explicitly allowlisted Safeharbor tenant slug and binds one globally stable
  suite customer UUID to one local client. Sequential active versions may
  rename that client. Inactive versions retain the source name in immutable
  sync history without renaming or deleting the client; all operational history
  remains. It never reads or writes `clients.source_key`. See
  `docs/milepost-customer-sync-contract.md`.

## Develop

There is no local build. Edit, lint, deploy, verify on the server:

```bash
find app -name "*.php" -print0 | xargs -0 -n1 php -l     # lint
php app/tests/service_goal_policy_admin_test.php
# destructive only in safeharbor_service_goal_test*: php app/tests/service_goal_policy_mysql_test.php
php app/tests/portal_auth_test.php
php app/tests/portal_data_test.php
# destructive only in safeharbor_portal_test*: php app/tests/portal_mysql_test.php
php app/tests/business_reports_test.php
# destructive only in safeharbor_report_test*: php app/tests/business_reports_mysql_test.php
php app/tests/id_report_contacts_test.php
# requires an acknowledged disposable loopback MySQL server; creates and
# finally removes one random safeharbor_id_report_test* database and user:
# SAFEHARBOR_ID_REPORT_TEST_DISPOSABLE_SERVER=1 php app/tests/id_report_contacts_mysql_test.php
php app/tests/suite_customer_sync_test.php
# destructive only in safeharbor_customer_sync_test*: php app/tests/suite_customer_sync_mysql_test.php
SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
cd tools/shots && node walkthrough.mjs                   # visual verification
```

Demo data may be reset only on a disposable sandbox whose server-only config
explicitly sets `demo_mode` to `true`:
`cd /srv/8west/apps/safeharbor/current && php db/seed.php`.
Production must keep `demo_mode` false; the seed refuses to run there.
The seed does not own portal-binding history: never bind a disposable seeded
client that will later be reset, and never bypass the portal foreign keys.
