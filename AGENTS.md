# Safeharbor — repo guide for agents

**Safeharbor** is the help desk app in the **8 West IT Total Business Suite**
for MSPs, alongside **Milepost** (RMM) and **Coastmark** (accounting).
Tagline: *Every client issue, safely ashore.* Vendor: 8 West IT, LLC.

Full product plan: `docs/8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx`
(regenerate via `tools/build_doc.py`). Current state: **Phase 0 complete**
(foundation + design system + clickable prototype).

Production host: **https://safeharbor.8westit.com** (AWS EC2, nginx, static
SPA for now). Deployment kit + runbook: `deploy/`.

## Layout

| Path | What |
|---|---|
| `brand/` | Logo system (SVG masters in `svg/`, rasters in `png/`), `tokens.json` — the single source of brand truth |
| `app/` | Vite + React 18 + TS + Tailwind v4 prototype (Phase 0) |
| `docs/` | Planning docx, `suite-sso-contract.md` |
| `deploy/` | nginx conf, provision + deploy scripts, runbook for safeharbor.8westit.com |
| `tools/` | Python generators (see below); `.venv/` is the project venv |

## Commands (from repo root)

```bash
# brand
./.venv/Scripts/python.exe tools/build_brand.py       # SVG masters + tokens.json
./.venv/Scripts/python.exe tools/rasterize_brand.py   # PNG/ICO (needs tools/resvg/)
./.venv/Scripts/python.exe tools/sync_tokens.py       # tokens.json -> app CSS
./.venv/Scripts/python.exe tools/build_doc.py         # planning docx

# app
cd app && npm install && npm run dev                  # develop (port 5178)
cd app && npm run build                               # typecheck + build
cd app && npm run preview -- --port 4173 &            # then:
cd app && node scripts/screenshots.mjs                # visual walkthrough -> C:/tmp/shots
```

## Rules that keep this repo coherent

1. **Never hard-code brand values.** Colors/radii/font come from
   `brand/tokens.json` → `tools/sync_tokens.py` → `app/src/styles/tokens.css`
   (generated, don't hand-edit). Semantic additions (e.g. rose for SLA breach)
   go through `sync_tokens.py` too.
2. **Logos are generated, not edited.** Change `tools/build_brand.py`, rerun
   it, then `rasterize_brand.py`, then re-copy into `app/public/brand/`.
3. **The 8 West Standard (UX law):** speed is a feature (optimistic updates),
   ⌘K palette reaches everything, full keyboard model, dark-mode-first,
   progressive disclosure, gold = attention, mint = resolved, AI included not
   bolted-on, every feature earns its click.
4. **Tenant scope everything.** Every entity carries `tenantId`
   (see `app/src/lib/types.ts` + `docs/suite-sso-contract.md`).
5. **Verify visually.** After UI work, run the screenshot walkthrough and read
   the PNGs (`app/scripts/screenshots.mjs`, Playwright chromium).
6. Planning doc changes go through `tools/build_doc.py` (python-docx), not by
   editing the .docx.

## Toolchain notes (Windows)

- Python venv: `python -m venv .venv` + `pip install python-docx fonttools pillow`
- `tools/resvg/resvg.exe` is NOT committed — download `resvg-win64.zip`
  (github.com/linebender/resvg releases) into `tools/resvg/`
- `tools/fonts/Inter-Variable.ttf` IS committed (OFL) — the logo generator
  converts Inter wordmarks to vector paths
- Edge headless is unusably slow here for rasterization; use resvg and
  Playwright chromium instead
