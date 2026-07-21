#!/usr/bin/env python3
"""
Rasterize the Safeharbor SVG brand masters to PNG/ICO via resvg
(tools/resvg/resvg.exe — deterministic, offline; text is already paths).

Run from repo root:
    ./.venv/Scripts/python.exe tools/rasterize_brand.py

Inputs:  brand/svg/*.svg
Outputs: brand/png/*.png, brand/png/favicon.ico
"""

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SVG = ROOT / "brand" / "svg"
PNG = ROOT / "brand" / "png"
RESVG = ROOT / "tools" / "resvg" / "resvg.exe"

# (svg source, output name, pixel width)  — height follows aspect ratio
TARGETS = [
    ("safeharbor-logo-horizontal-dark.svg", "safeharbor-logo-horizontal-dark.png", 1060),
    ("safeharbor-logo-horizontal-dark.svg", "safeharbor-logo-horizontal-dark@2x.png", 2120),
    ("safeharbor-logo-horizontal-light.svg", "safeharbor-logo-horizontal-light.png", 1060),
    ("safeharbor-logo-horizontal-light.svg", "safeharbor-logo-horizontal-light@2x.png", 2120),
    ("safeharbor-logo-square-dark.svg", "safeharbor-logo-square-dark.png", 1080),
    ("safeharbor-logo-square-light.svg", "safeharbor-logo-square-light.png", 1080),
    ("safeharbor-app-tile.svg", "app-tile-1024.png", 1024),
    ("safeharbor-app-tile.svg", "app-tile-512.png", 512),
    ("safeharbor-app-tile.svg", "app-tile-192.png", 192),
    ("safeharbor-app-tile.svg", "apple-touch-icon.png", 180),
    ("favicon.svg", "favicon-32.png", 32),
    ("favicon.svg", "favicon-16.png", 16),
    ("safeharbor-mark.svg", "safeharbor-mark.png", 512),
]


def rasterize(src: Path, out: Path, width: int) -> bool:
    result = subprocess.run(
        [str(RESVG), "--width", str(width), str(src), str(out)],
        capture_output=True, text=True, timeout=60)
    if result.returncode != 0 or not out.exists():
        print(f"  FAILED {out.name}: {result.stderr.strip()[:200]}")
        return False
    print(f"  wrote brand/png/{out.name} ({out.stat().st_size:,} bytes)")
    return True


def make_ico() -> bool:
    from PIL import Image
    imgs = []
    for size in (16, 32, 48):
        src = PNG / f"favicon-{size}.png"
        if not src.exists() and not rasterize(SVG / "favicon.svg", src, size):
            return False
        imgs.append(Image.open(src).convert("RGBA"))
    imgs[0].save(PNG / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)],
                 append_images=imgs[1:])
    print(f"  wrote brand/png/favicon.ico ({(PNG / 'favicon.ico').stat().st_size:,} bytes)")
    return True


def main():
    if not RESVG.exists():
        sys.exit(f"resvg not found at {RESVG} — see tools/README.md")
    PNG.mkdir(parents=True, exist_ok=True)
    ok = True
    for src, out, width in TARGETS:
        ok = rasterize(SVG / src, PNG / out, width) and ok
    ok = make_ico() and ok
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
