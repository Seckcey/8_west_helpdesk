# Safeharbor — repo guide for agents

Current suite handoff: [IT 365 stopping point](docs/IT_365_STOPPING_POINT_2026_09_24.md). Dated migration and canary notes below preserve history; their off-gate observations are not current production instructions.

**Safeharbor** is the help desk app in the **8 West IT Total Business Suite**,
alongside **Milepost** (RMM), **Coastmark** (accounting), and the **8 West IT
365 Control Panel**. The product keys are the exact strings in the
`8west:products` claim: `safeharbor` · `milepost` · `coastmark` ·
`coastline_control_panel`. Coastmark was split on 2026-08-02 into `Seckcey/coastmark` (the
8 West IT 365 edition) and `Seckcey/coastmark_standalone` (its own marketing,
pricing and Stripe); only the 365 edition is inside the suite contract.
Tagline: *Every client issue, safely ashore.* Vendor: 8 West IT, LLC.

Full product plan: `docs/8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx`
(regenerate via `tools/build_doc.py`). Current state: **v1.0
feature-complete — Sprints 1–5 shipped 2026-08-02**, **live at
https://safeharbor.8westit.com**. Production credentials are not published;
use an authorized 8 West ID or local account.
Remaining before the v1.0 stamp: the Phase 1 dogfood gate (4 weeks on
8 West's real desk, zero data loss, p95 < 300ms).
The Customer Service Tools release is also in production. Versioned service
goals, approval-grade technician time, correction history, the useful customer
portal, managed-customer activation/lifecycle, approved-time draft lines, and
archived weekly reports are installed. The controlled 8 West Lifestyle tests
passed, including inbox delivery and one Coastmark draft line. The portal and
recurring Lifestyle report are intentionally on. The existing Lifestyle
Coastmark transfer connection and staff billing controls are live as of
2026-09-05; eligible new-MSP onboarding is enabled as of September 24; historical existing-client activation remains Ventures-only. See `docs/IT_365_STOPPING_POINT_2026_09_24.md`. Safeharbor owns all
help-desk records and workflows; Milepost supplies tenant/asset/telemetry
context only; Coastmark alone owns financial facts. A transferred time entry
can create only a draft invoice line, never automatic approval, posting,
sending, payment, or ledger activity. Verify the exact state in
`docs/where-things-stand.md` before changing a gate.

**`svc.enabled` is already `true` in production** (verified 2026-08-08):
Milepost's alert emitter shipped, and alert tickets have been arriving since
2026-07-29. Anything that assumes the svc path is dark is out of date.
**`svc.support_enabled` is `true` too** (2026-08-09): Coastmark and Waypoint
both passed their canaries and both have turned their own emitters on, so
partner support tickets are arriving for real customers. Those established
routes are live, but that does **not** make every `api/svc/*` route live:
`customers.php` has its own default-off `suite_customer_sync.enabled` gate,
dedicated identity/key, and tenant allowlist.

**Before starting work here, read `docs/where-things-stand.md`** — one page
saying what is live, what is not, and how to verify each claim yourself rather
than trusting the page. It exists because two sessions built the same Waypoint
feature on the same day; one of them was thrown away.

## Stack — same as Milepost, on purpose

**Plain PHP 8.3 + MySQL + Apache (mod_php). No framework, no bundler, no
build step, hand-written CSS + vanilla JS.** The user directive: one way of
building suite apps, no stray software. Milepost's conventions are the
reference (`/srv/8west/apps/milepost/current` on the box): page-per-file in
`public/`, shared modules in `lib/`, `db/schema.sql` + seeds, server-only
`config/config.php`, Apache vhost pair per product.

## Layout

| Path | What |
|---|---|
| `brand/` | Logo system (SVG masters in `svg/`, rasters in `png/`), `tokens.json` — single source of brand truth |
| `app/` | The PHP app (see `app/README.md`): `config/ db/ lib/ public/` |
| `docs/` | **`where-things-stand.md` (read first — live/not-live status, verifiable)** · Planning docx · `technician-time-corrections-contract.md` · `customer-portal-contract.md` · `business-reports-contract.md` · `coastmark-approved-time-export-contract.md` · `suite-sso-contract.md` (suite-wide staff identity reference) · `coastmark-support-intake-contract.md` (partner support intake, both producers) · `westy-failure-reporting-contract.md` · `sprint-8.1-svc-alert-intake.md` (historical spec) · `open-decision-entitlement-and-subscription.md` · `competitor-research-2026-08.md` |
| `deploy/` | Apache vhost pair, `deploy.sh`, runbook for safeharbor.8westit.com |
| `tools/` | Python generators (brand/doc/tokens); `tools/shots/` = Playwright walkthrough (dev-only) |

## Commands

```bash
# brand (from repo root)
./.venv/Scripts/python.exe tools/build_brand.py       # SVG masters + tokens.json
./.venv/Scripts/python.exe tools/rasterize_brand.py   # PNG/ICO (needs tools/resvg/)
./.venv/Scripts/python.exe tools/sync_tokens.py       # tokens.json reference
./.venv/Scripts/python.exe tools/build_doc.py         # planning docx

# app — no build; lint, deploy, verify
find app -name "*.php" -print0 | xargs -0 -n1 php -l
SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
cd tools/shots && node walkthrough.mjs                # screenshots → C:/tmp/shots
```

## Rules that keep this repo coherent

1. **One stack.** PHP + MySQL + Apache + hand-written assets, Milepost
   conventions. Do not introduce frameworks, bundlers, or a second way of
   doing vhosts/config/deploys.
2. **Never hard-code brand values.** Tokens live in `brand/tokens.json`;
   CSS custom properties at the top of `app/public/assets/css/app.css` mirror
   them (sync by hand, keep in lockstep). Logos are generated by
   `tools/build_brand.py` — don't hand-edit SVGs; deploy.sh copies them into
   `app/public/assets/brand/`.
3. **The 8 West Standard (UX law):** speed is a feature (optimistic updates),
   ⌘K palette reaches everything, full keyboard model, dark-mode-first,
   progressive disclosure, gold = attention, mint = resolved, AI included not
   bolted-on, every feature earns its click.
4. **Tenant scope everything** (`tenant_id` on every table; SSO contract in
   `docs/suite-sso-contract.md`). Every string through `h()`, every query
   prepared, UTC everywhere.
5. **Verify visually.** After UI work, run `tools/shots/walkthrough.mjs`
   against production and read the PNGs.
6. **Shared-box discipline:** additive only on the EC2 host (own app dir,
   docroot, vhost pair, MySQL db/user, own `/etc/cron.d/safeharbor`); Apache
   `reload` never `restart`; `config/config.php` and the `.pem` never enter
   git.
7. **Westy:** the suite assistant (same mascot as Milepost) — app-specific
   knowledge remains in `lib/westy.php` + `lib/ai.php` +
   `api/westy_chat.php` + `assets/js/westy.js`. He ADVISES, never acts; his
   prompt names only REAL UI (update it when the UI changes); AI keys live
   ONLY in server `config/config.php` (`ai` block) and the bubble fails closed
   without them. The separately authorized September 7 controller workflow is
   a default-off service, not a chat permission: `docs/westy-workflow-contract.md`
   owns its migration 025, dedicated `milepost-workflow` identity, exact
   tenant/customer gates, independent recovery proof and human takeover.
   Never route command approval or invoice sending through the chat bubble.
   Shared drag/resize layout is centrally owned in
   `Seckcey/8_west_westy` and consumed from
   `https://westy.8westit.com/v1/westy-layout.js`. A commit to that repo does
   **not** publish: only a reviewed tag published as a GitHub Release may move
   `/v1/`. Keep `#westy-root`, `#westy-bubble`, `#westy-panel`, `.westy-head`,
   and the panel-inside-root positioning contract. Verify
   `data-westy-version`; treat each shared release as suite-wide blast radius.
   Roll back centrally by repointing `/v1/` to a retained frozen version, or
   pin this consumer temporarily to that frozen version.
   **Westy defects open tickets in 8 West IT's OWN queue**, never a customer's
   — `lib/westy_report.php`, contract in
   `docs/westy-failure-reporting-contract.md`. Real failures report themselves;
   a thumbs-DOWN under every answer lets a tech flag a bad one after reviewing
   exactly what will be sent. Failures carry technical detail only — no chat
   text, and the receiver enforces that rather than trusting the caller. One
   ticket per PROBLEM: the key fingerprints a normalised error class, never a
   timestamp or an occurrence id.
   **Thumbs-UP opens no ticket and never will** (`api/westy_thumbs_up.php` →
   `westy_thumbs_up_record()`): tickets are for problems, and a queue full of
   good news is a queue nobody reads. It writes one `assistant_log` row holding
   lengths and the question fingerprint — no chat text, because one click with
   no review box means nobody agreed to send those words anywhere. The
   fingerprint is the SAME one the flag path uses, so praise and complaints
   about a question line up against each other.
8. **Four `api/svc/*` producers, four different jobs — never merge them.**
   `alerts.php` (machine alerts, keyed on the occurrence; only an untouched
   exact `alert:<numeric-id>` created by the signed `milepost` identity may
   auto-close on real recovery),
   `westy.php` (Westy defects, one ticket per PROBLEM, stored text re-scrubbed),
   `support.php` (a person asking 8 West IT for help from inside **Coastmark or
   Waypoint** — more products to come — one ticket per SUBMISSION, text stored
   **verbatim**, `channel='portal'`, contract in
   `docs/coastmark-support-intake-contract.md`), and `customers.php` (the
   default-off Milepost customer registry, which creates one stable local
   client binding and immutable receipts but never a ticket or
   `clients.source_key`; contract in `docs/milepost-customer-sync-contract.md`).
   Customer sync has its own exact `milepost-customers` identity, dedicated
   HMAC context/key, `suite_customer_sync.enabled` gate, and tenant allowlist;
   never route it through generic `svc.secrets` or the already-live `svc`
   gates. The support caller list is server
   config `support_intake.sources` (`support_sources()`, defaults in
   `SUPPORT_SOURCES_DEFAULT`), so **an additional support product costs a
   config line, an identity row and a secret — never a code change**;
   production has no
   `support_intake` block at all today and runs on those defaults.
   **Waypoint is a standalone product outside 8 West IT 365**, so do not assume
   a support caller is a suite app. Westy's scrubbing
   and fingerprinting would destroy a support request, and support's
   store-everything rule would flood the queue with alert repeats — the split
   is the design, not duplication. One identity per producer, one job each:
   `svc_auth.php` only proves *who* is calling, so each endpoint checks that
   the caller is allowed to file *its* kind of thing. Support intake has its
   own kill switch (`svc.support_enabled`) because `svc.enabled` is already
   true in production — **both are now `true`; support intake is live, not
   dark.** The old gap here — a hardcoded caller list — was closed on
   2026-08-09 by salvaging branch `feat/partner-support-sources` (PR #26,
   closed as a duplicate) into PR #30.
9. **8 West ID SSO:** the legacy `ewid_token` cookie is scoped to
   `.8westit.com` and is separate from the issuer's newer OIDC profile.
   Production issuance is RS256; Safeharbor accepts `RS256` only and selects
   RSA keys by exact `kid`
   from the issuer JWKS. Config lives in the **`suite`** block
   (`issuer`, `sso_secret`, `cookie_name`, `token_algorithms`, `jwks_url`,
   `jwks_cache_path`) of server-only `config/config.php`; never put secrets or
   token material in chat/git. **There is no `suite_sso` block and no kill
   switch in this app** (that switch is Milepost's). An unusable verification
   configuration fails every signature. Users are keyed by the immutable `sub` claim
   (`users.suite_subject`, migration 007) with a one-time email backfill —
   never by email. Unknown nonblank tenant slugs are auto-provisioned;
   Safeharbor deliberately admits its own `8west` staff tenant (the role gate
   already refuses customer/unknown roles). Every refusal is audited:
   `suite_sso_refuse()` logs a reason code from `jwt_verify_suite_reason()`
   (`lib/jwt.php`), never the claim payload. After contraction, retain the
   shared secret for Safeharbor's separate revocation-feed HMAC until that
   mechanism has its own reviewed migration. Established suite sessions are
   checked against 8 West ID's signed revocation list on every authenticated
   request through a 60-second cache; an issuer/signature/staleness failure is
   logged and deliberately fails open. Full contract:
   `docs/suite-sso-contract.md`.
10. **Mail pipeline:** transport order Graph → SMTP → PHP mail().
   Outbound = `lib/mailer.php` + `mail_queue` + `cron/mail_dispatch.php`;
   inbound = `cron/graph_poll.php` (O365, Entra app) with
   `cron/imap_poll.php` as non-M365 fallback. Safeharbor and Milepost share
   ONE Entra app registration with APPLICATION permissions (Mail.Send ·
   Mail.Read for intake) — credentials live ONLY in server
   `config/config.php`, synced server-side from Milepost's config (never
   chat/git). Do not reuse those credentials for an unrelated application or
   assume another registration covers this mail pipeline.
   The separately authorized Westy conversation feature is an explicit exception:
   `lib/westy_mail*.php` uses its own certificate application under
   `westy_email.graph` and mailbox-only Exchange RBAC. It must never repoint the
   general mail or business-report sender. Migration 027 owns exact approvals,
   receipts and revocations; 028 owns durable mailbox progression. See
   `docs/westy-mail-conversations.md`. The chat bubble cannot grant email authority.

## Ops lessons written in blood (2026-08-01/02)

- **Pull before you sprint; deploy only from committed main.** Two
  checkouts deployed to the box the same night and silently overwrote
  each other's shared files. `git pull --ff-only` before work, commit,
  then deploy.
- **SSH uses the origin IP, not the domain.** safeharbor.8westit.com is
  Cloudflare-proxied — port 22 times out on the hostname. Deploy with
  `SERVER=ubuntu@<origin-ip> KEY=<milepost.pem> bash deploy/deploy.sh`
  (Frank has the IP + key; the pem never enters git or chat).
- **Migrations are manual and order-specific.** Never infer deploy-first from a
  generic command: 007 and 010 through 016 were migration-first. For a
  schema-changing release, require the exact green default-branch SHA, a fresh
  verified root-only application/config/trigger-inclusive-DB/grant backup and
  scratch restore, the Safeharbor-only endpoint/database write freeze, the
  archived exact Git blob plus replay/postflight, then matching source deploy.
  Never stop shared Apache or remove fail-closed migration guards manually; see
  `deploy/README.md`.
  Recorded as applied: 001 mail_queue · 002 westy/onboarding ·
  003 canned_responses · 004 attachments/threading/resurface ·
  005 presence/merge/fulltext · 006 csat · 007 suite_subject ·
  008 westy_reports · 009 support_intake · 010 versioned service goals ·
  011 approval-grade time · 012 customer portal · 013 business reports ·
  014 guarded policy publication · 015 customer sync ·
  016 time corrections/overlap guards · 017 8 West ID report-contact
  binding/evidence. `db/schema.sql` stays the canonical fresh-install
  copy—keep it and every migration in lockstep. Migration 017 is live through
  PR #57 / release `cef39dd`; its protected contact configs were installed,
  one redacted 8 West IT canary stored the immutable binding/contact evidence,
  and both dedicated contact gates were turned off again after preparation.
  Migration 018 (one-use telemetry auto-close eligibility) is a separate
  migration-first release candidate. It defaults every ticket ineligible and
  permanently clears eligibility on ticket edits, human/customer messages,
  message moves/deletes, and technician time. Its exact ownership and rollback
  contract is `docs/ticket-auto-close-ownership-contract.md`.
  `002_svc_intake.sql` collides on the number 002 with
  `002_westy_onboarding.sql`, so numbering is not a reliable ordering. **It IS
  applied in production** — verified 2026-08-08 by schema, not by this list:
  `tickets.external_key`, the unique key `(tenant_id, external_key)`,
  `svc_identities` and `svc_rate_buckets` all exist. **009 support_intake
  (`clients.source_key` + `svc_support_rate`) IS applied** — run 2026-08-09,
  verified by schema the same day: both objects exist and the ticket/client
  counts were unchanged by it. The rule for every svc_*
  object is the same: `schema.sql` does NOT carry them, so a fresh install
  needs schema.sql + 002 + 009. 007 remains a hard prerequisite for suite sign-in on a
  rebuild: PDO runs `ERRMODE_EXCEPTION`, so `suite_sso_attempt()` throws on a
  host missing `users.suite_subject`.
- **Attachment bytes live OUTSIDE the deploy tree** at
  `/srv/8west/apps/safeharbor/shared/attachments` (www-data 770) because
  deploy.sh re-chmods `current/` every release. Never store uploads
  under `current/`.
- **Every string that reaches the DB from the wild goes through
  `utf8_clean()`** — mis-encoded email bytes 500 a utf8mb4 insert.
- **Westy's prompt names only REAL UI.** Ship a feature → update
  `westy_chat_system_prompt()` in the same PR, or he starts lying.
- **Mail-loop protection is law**: nothing outbound to
  mailer-daemon/postmaster/no-reply-ish senders (intake_is_auto_mail,
  mail_notify_reply, csat_send all guard it). The 2026-08 loop wrote
  335k junk messages before the guard existed.

## Toolchain notes (Windows dev machine)

- Python venv: `python -m venv .venv` + `pip install python-docx fonttools pillow`
- `tools/resvg/resvg.exe` is NOT committed (download resvg-win64 into `tools/resvg/`)
- `tools/fonts/Inter-Variable.ttf` IS committed (OFL) — logo text→path engine
- Local PHP 8.3 CLI is used for `php -l` only; the app runs on the server
- `tools/shots/` has its own package.json (playwright) — dev tooling, not the app
