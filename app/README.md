# Safeharbor App (Phase 0 prototype)

The clickable prototype for Safeharbor — the help desk app in the 8 West IT
Total Business Suite. Dark-mode-first, keyboard-driven, built on the brand
tokens in `../brand/tokens.json`.

**Stack:** Vite · React 18 · TypeScript · Tailwind CSS v4 · React Router

## Run

```bash
cd app
npm install
npm run dev        # http://localhost:5178
```

Any email signs in (prototype auth stub — the real flow is the 8 West ID SSO
contract in `../docs/suite-sso-contract.md`).

## Verify

```bash
npm run build                          # typecheck + production build
npm run preview -- --port 4173         # serve the build…
node scripts/screenshots.mjs           # …then capture the visual walkthrough
```

## What's in Phase 0

- **App shell** — sidebar (brand + suite placeholders), top bar (search
  trigger, timer widget, user), toast stack
- **The five key screens** — Queue, Ticket, Client(s), Time, ⌘K palette
- **Keyboard model** — `j/k` move · `Enter` open · `s` status · `p` priority ·
  `a` assign · `e` timer · `r` reply · `⌘K` everything
- **Multi-tenant data model** (`src/lib/types.ts`) + seeded 8 West IT tenant
- **Design system** — tokens generated from `../brand/tokens.json` into
  `src/styles/tokens.css` (never hand-edit; run
  `../.venv/Scripts/python.exe ../tools/sync_tokens.py` — from repo root:
  `./.venv/Scripts/python.exe tools/sync_tokens.py`)
- **Brand assets wired in** — favicon.svg/ico, app tiles + manifest, sidebar
  mark, login lockup (from `../brand/`, copied to `public/brand/`)

## Not in Phase 0 (by design)

Backend, real email intake, SLAs engine, automation, AI, mobile. State is
in-memory (optimistic by design) with the timer persisted to localStorage.
See the rollout plan in `../docs/`.
