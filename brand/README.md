# Safeharbor Brand Package

**Safeharbor** — the help desk app in the 8 West IT Total Business Suite
(alongside **Milepost** · RMM and **Coastmark** · accounting).

> **Every client issue, safely ashore.**
> by 8 West IT, LLC

## The mark

A harbor light: a gold lamp radiates cyan→blue signal arcs, standing on a
light-post inside the sheltering harbor bowl. It carries the 8 West IT
wayfinding family language (a marker that guides travelers to safety) and the
site's palette: deep-navy night, electric blue, cyan horizon, warm gold lamp.

## Files

### Vector masters (`svg/`) — source of truth, use these whenever possible

| File | Use |
|---|---|
| `safeharbor-logo-horizontal-dark.svg` | Primary lockup (mark + name + tagline + attribution) for dark surfaces |
| `safeharbor-logo-horizontal-light.svg` | Same lockup for light surfaces |
| `safeharbor-logo-square-dark.svg` / `-light.svg` | Stacked lockup — social profiles, splash, login |
| `safeharbor-app-tile.svg` | App icon (mark on navy tile, no text — the OS shows the name) |
| `safeharbor-mark.svg` | Mark only, transparent — in-app chrome, sidebar, watermark |
| `favicon.svg` | Browser/app favicon (modern browsers) |

### Raster (`png/`) — generated, do not edit by hand

`safeharbor-logo-horizontal-*.png` (+`@2x`), `safeharbor-logo-square-*.png`,
`app-tile-1024/512/192.png`, `apple-touch-icon.png` (180),
`favicon-16/32/48.png`, `favicon.ico`, `safeharbor-mark.png`.

## Rules

1. **Clear space** — keep empty space around the mark ≥ the radius of the gold
   lamp on all sides. Nothing enters the harbor.
2. **Backgrounds** — dark variants on `navy-900`/`navy-950` or imagery dark
   enough for the white wordmark; light variants on white/light only.
3. **Don'ts** — don't recolor the lamp (gold is the brand), don't stretch,
   don't add shadows/effects, don't set the wordmark in anything but Inter,
   don't show the arcs without the harbor bowl (the bowl is the "safe").
4. **Attribution** — "BY 8 WEST IT, LLC" stays on lockups used outside the
   product itself (marketing, docs, email). In-app chrome may drop it.
5. **Text in the SVGs is vector outlines** (Inter, OFL-licensed) — assets
   render identically everywhere, no font dependency.

## Palette (`tokens.json`)

Canonical tokens for code consumption live in `tokens.json` (colors, radii,
font, shadow). The app imports these — never hard-code hex values in product
code. Source: 8westit.com `styles.css`.

| Token | Hex | Role |
|---|---|---|
| navy-950 / 900 / 800 / 700 | `#031022` `#061936` `#0B2448` `#12345F` | Depth layers (dark-first UI) |
| blue-500 / 400 | `#2D8CFF` `#54A8FF` | Primary action / secondary |
| cyan-300 | `#7DDCFF` | Active states, signal |
| gold-400 | `#F6C95B` | The lamp — warnings, highlights |
| mint-300 | `#62F6B0` | Resolved / healthy |
| text / muted | `#EAF3FF` / `#AEBED4` | Type on navy |

## Regenerating

```bash
./.venv/Scripts/python.exe tools/build_brand.py      # SVG masters + tokens.json
./.venv/Scripts/python.exe tools/rasterize_brand.py  # PNG/ICO via tools/resvg
```

Requires: `tools/fonts/Inter-Variable.ttf` (OFL,
[google/fonts](https://github.com/google/fonts/tree/main/ofl/inter)) and
`tools/resvg/resvg.exe` ([resvg](https://github.com/linebender/resvg),
not committed — download `resvg-win64.zip` and unzip into `tools/resvg/`).
