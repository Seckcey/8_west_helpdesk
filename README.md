# Safeharbor

**Every client issue, safely ashore.**

Safeharbor is the help desk app in the **8 West IT Total Business Suite** for
MSPs — alongside **Milepost** (RMM / client operations) and **Coastmark**
(accounting). by 8 West IT, LLC.

> Status: **Phase 0 complete** — brand system + clickable prototype.
> Next: Phase 1 (core ticketing MVP).

## What's here

| Path | What |
|---|---|
| `brand/` | The Safeharbor brand package — logo masters (horizontal, square, app tile, favicon, mark), rasters, usage guide, canonical `tokens.json` |
| `app/` | The Phase 0 prototype — Vite + React + TypeScript + Tailwind. Queue, Ticket, Clients, Time, ⌘K palette. See `app/README.md` |
| `docs/` | Product plan (`8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx`) + suite SSO contract |
| `tools/` | Generators: brand builder, rasterizer, token sync, doc builder |

## Quick start

```bash
# run the prototype
cd app && npm install && npm run dev        # http://localhost:5178 (any email signs in)
```

## Brand

Deep-navy night palette, electric blue → cyan signal, gold harbor lamp —
see `brand/README.md` and `brand/tokens.json`. Product family: wayfinding
markers — **Milepost** marks where you are, **Coastmark** marks where the
business stands, **Safeharbor** brings every issue safely in.
