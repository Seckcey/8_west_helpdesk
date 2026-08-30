# Safeharbor

**Every client issue, safely ashore.**

Safeharbor is the help desk app in the **8 West IT Total Business Suite** —
alongside **Milepost** (RMM / client operations), **Coastmark** (accounting),
and the **8 West IT 365 Control Panel**. Four products, four
`8west:products` keys: `safeharbor` · `milepost` · `coastmark` ·
`coastline_control_panel`. by 8 West IT, LLC.

> Status: **v1.0 feature-complete (Sprints 1–5 shipped, 2026-08-02)** — **live at
> [safeharbor.8westit.com](https://safeharbor.8westit.com)**. Production
> credentials are not published; use an authorized 8 West ID or local account.
> What remains before the
> v1.0 stamp is the Phase 1 exit gate: 4 consecutive weeks running 8 West's
> real desk, zero data loss, p95 interaction < 300ms.
>
> Shipped: **Westy** (suite assistant — first-run onboarding tour + advise-only
> how-to helper; shared move/resize layout comes from the centrally owned
> `Seckcey/8_west_westy` release at `https://westy.8westit.com/v1/`) · **8 West
> ID SSO** (RS256-only `ewid_token` suite cookie selected by exact JWKS `kid`;
> users keyed by the
> immutable `sub` claim, every deny audited; migration 007 was applied to
> production on 2026-08-02 and `suite.sso_secret` is server-only — see
> [docs/suite-sso-contract.md](docs/suite-sso-contract.md)) · **Milepost alert
> intake** (live signed svc API with replay dedupe and guarded auto-close) · composer with
> internal notes + `/` saved replies + time-at-reply · attachments both ways ·
> conversation threading + inbound dedupe + bounce-loop protection · waiting
> auto-resurface · collision detection with stale-send blocking · merge
> tickets · deep search (message bodies, resolved included) · `?` shortcut
> card · Reports with real numbers + billable CSV · one-click CSAT · versioned
> service-goal snapshots · approval-reviewed technician time.
>
> Customer Service Tools follow-on: service goals and approval-grade time are
> live. Guarded later-version service-goal publication is deployed with no v2
> policy published; real targets and their effective time remain an explicit
> business decision. The operator-only approved-time seam to Coastmark draft
> invoice lines,
> the 8 West ID customer ticket-summary portal, and archived weekly business
> reports are deployed dark behind explicit allowlists and canary gates.
> Safeharbor owns the operational records; Milepost supplies context only;
> Coastmark never auto-posts through this seam; and the current portal exposes
> no billing, ticket detail, or endpoint control. The isolated Phase 5B source
> adds customer create/detail/reply without exposing billing, internal notes,
> AI actions, or endpoint control; it is not merged or deployed. See
> [docs/where-things-stand.md](docs/where-things-stand.md).

## What's here

| Path | What |
|---|---|
| `brand/` | The Safeharbor brand package — logo masters (horizontal, square, app tile, favicon, mark), rasters, usage guide, canonical `tokens.json` |
| `app/` | The live desk plus Customer Service Tools boundaries — plain PHP 8.3 + MySQL + Apache. Queue, Ticket, Clients, versioned Service Goals, approval-grade Time, Reports, ⌘K palette, and dark/default-off integration surfaces. See `app/README.md` |
| `docs/` | **`where-things-stand.md` (current verifiable status)** · product plan (`8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx`) · `milepost-customer-sync-contract.md` · `customer-portal-contract.md` · `business-reports-contract.md` · `coastmark-approved-time-export-contract.md` · `suite-sso-contract.md` · historical and research records |
| `tools/` | Generators: brand builder, rasterizer, token sync, doc builder |

## Quick start

The app runs on the server (no local build). Develop = edit, lint, deploy:

```bash
find app -name "*.php" -print0 | xargs -0 -n1 php -l   # lint
SERVER=ubuntu@<origin-ip> KEY=~/.ssh/milepost.pem bash deploy/deploy.sh
cd tools/shots && node walkthrough.mjs                 # visual verification
```

## Brand

Deep-navy night palette, electric blue → cyan signal, gold harbor lamp —
see `brand/README.md` and `brand/tokens.json`. Product family: wayfinding
markers — **Milepost** marks where you are, **Coastmark** marks where the
business stands, **Safeharbor** brings every issue safely in.
