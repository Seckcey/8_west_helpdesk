# Safeharbor

**Every client issue, safely ashore.**

Safeharbor is the help desk app in the **8 West IT Total Business Suite** —
alongside **Mission Control** (the customer-facing 365 surface: AI email
triage, per-user mailboxes, reply drafting and sending), **Milepost** (RMM /
client operations) and **Coastmark** (accounting). Four products, four
`8west:products` keys: `missioncontrol` · `safeharbor` · `milepost` ·
`coastmark`. by 8 West IT, LLC.

> Status: **v1.0 feature-complete (Sprints 1–5 shipped, 2026-08-02)** — **live at
> [safeharbor.8westit.com](https://safeharbor.8westit.com)**
> (demo sign-in `frankie@8westit.com` / `harbor`). What remains before the
> v1.0 stamp is the Phase 1 exit gate: 4 consecutive weeks running 8 West's
> real desk, zero data loss, p95 interaction < 300ms.
>
> Shipped: **Westy** (suite assistant — first-run onboarding tour + advise-only
> how-to helper) · **8 West ID SSO** (HS256 `ewid_token` suite cookie — not
> OIDC; users keyed by the immutable `sub` claim, every deny audited; needs
> migration 007 applied and `suite.sso_secret` set on the host — see
> [docs/suite-sso-contract.md](docs/suite-sso-contract.md)) · **Milepost alert
> intake** (signed svc API, dark until the emitter ships) · composer with
> internal notes + `/` saved replies + time-at-reply · attachments both ways ·
> conversation threading + inbound dedupe + bounce-loop protection · waiting
> auto-resurface · collision detection with stale-send blocking · merge
> tickets · deep search (message bodies, resolved included) · `?` shortcut
> card · Reports with real numbers + billable CSV · one-click CSAT.

## What's here

| Path | What |
|---|---|
| `brand/` | The Safeharbor brand package — logo masters (horizontal, square, app tile, favicon, mark), rasters, usage guide, canonical `tokens.json` |
| `app/` | The app (v1.0 feature-complete, Sprints 1–5) — plain PHP 8.3 + MySQL + Apache, Milepost conventions. Queue, Ticket, Clients, Time, Reports, ⌘K palette. See `app/README.md` |
| `docs/` | Product plan (`8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx`) · `suite-sso-contract.md` (suite-wide identity reference) · `sprint-8.1-svc-alert-intake.md` (historical spec) · `open-decision-entitlement-and-subscription.md` · `competitor-research-2026-08.md` |
| `tools/` | Generators: brand builder, rasterizer, token sync, doc builder |

## Quick start

The app runs on the server (no local build). Develop = edit, lint, deploy:

```bash
find app -name "*.php" -print0 | xargs -0 -n1 php -l   # lint
KEY=~/.ssh/milepost.pem bash deploy/deploy.sh          # deploy to production
cd tools/shots && node walkthrough.mjs                 # visual verification
```

## Brand

Deep-navy night palette, electric blue → cyan signal, gold harbor lamp —
see `brand/README.md` and `brand/tokens.json`. Product family: wayfinding
markers — **Milepost** marks where you are, **Coastmark** marks where the
business stands, **Safeharbor** brings every issue safely in.
