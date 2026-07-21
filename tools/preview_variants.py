#!/usr/bin/env python3
"""Preview mark refinements (post vs. wave) — renders to /tmp for review."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_brand import (arc_path, defs, svg_doc,  # noqa: E402
                         CYAN_300, BLUE_400, BLUE_500, GOLD_400, TEXT)

OUT = Path("/tmp/mark_variants")
OUT.mkdir(exist_ok=True)

LAMP = (256.0, 196.0)


def arcs():
    out = []
    for r, color, op in ((72, CYAN_300, 1.0), (112, BLUE_400, 0.92),
                         (152, BLUE_500, 0.85)):
        out.append(
            f'<path d="{arc_path(LAMP[0], LAMP[1], r, -158, -22)}" fill="none" '
            f'stroke="{color}" stroke-width="19" stroke-linecap="round" '
            f'opacity="{op}"/>')
    out.append(f'<circle cx="{LAMP[0]}" cy="{LAMP[1]}" r="31" fill="{GOLD_400}"/>')
    out.append(f'<circle cx="{LAMP[0]}" cy="{LAMP[1]}" r="44" fill="none" '
               f'stroke="{GOLD_400}" stroke-width="3" opacity="0.35"/>')
    return "".join(out)


def glow():
    return (f'<circle cx="{LAMP[0]}" cy="{LAMP[1]}" r="150" '
            f'fill="url(#glowGold-m)"/>')


def variant_post():
    """Lamp on a light-post rising from the harbor bowl."""
    post = (
        f'<path d="M 245 236 L 267 236 L 274 388 Q 256 396 238 388 Z" '
        f'fill="{TEXT}" opacity="0.92"/>'
    )
    bowl = (
        f'<path d="M 108 312 C 108 396 176 424 256 424 C 336 424 404 396 404 312" '
        f'fill="none" stroke="{BLUE_500}" stroke-width="20" '
        f'stroke-linecap="round"/>'
    )
    return glow() + bowl + post + arcs()


def variant_wave():
    """Original arcs + harbor bowl, but inner bowl swapped for a water wave."""
    bowl = (
        f'<path d="M 108 312 C 108 396 176 424 256 424 C 336 424 404 396 404 312" '
        f'fill="none" stroke="{BLUE_500}" stroke-width="20" '
        f'stroke-linecap="round"/>'
    )
    wave = (
        f'<path d="M 186 366 Q 216 352 246 366 Q 276 380 306 366 Q 322 359 338 364" '
        f'fill="none" stroke="{CYAN_300}" stroke-width="14" '
        f'stroke-linecap="round" opacity="0.9"/>'
    )
    return glow() + bowl + wave + arcs()


def tile_wrap(inner):
    return (
        '<rect width="512" height="512" rx="112" fill="url(#tileBg-m)"/>'
        '<rect width="512" height="512" rx="112" fill="url(#sheen-m))"/>'
        .replace("url(#sheen-m))", "url(#sheen-m)")
        + inner
    )


for name, body in (("post", variant_post), ("wave", variant_wave)):
    (OUT / f"variant_{name}.svg").write_text(
        svg_doc(512, 512, tile_wrap(body())), encoding="utf-8")
    print(f"wrote {OUT}/variant_{name}.svg")
