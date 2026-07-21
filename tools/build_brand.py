#!/usr/bin/env python3
"""
Generate the Safeharbor brand package (SVG masters).

Safeharbor — the help desk app in the 8 West IT Total Business Suite.
Concept: a harbor light. A gold lamp radiates signal arcs over a sheltered
harbor bowl — "Every client issue, safely ashore." Built entirely from
8westit.com palette tokens; wordmarks are real Inter outlines converted to
paths so the assets render identically everywhere.

Run from repo root:
    ./.venv/Scripts/python.exe tools/build_brand.py

Outputs:
    brand/svg/*.svg          vector masters
    brand/tokens.json        canonical brand tokens (consumed by the app)
"""

import json
import math
from pathlib import Path

from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from fontTools.pens.svgPathPen import SVGPathPen

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "brand" / "svg"
FONT_PATH = ROOT / "tools" / "fonts" / "Inter-Variable.ttf"

# --- 8 West IT tokens (8westit.com styles.css) -----------------------------
NAVY_950 = "#031022"
NAVY_900 = "#061936"
NAVY_800 = "#0B2448"
NAVY_700 = "#12345F"
BLUE_500 = "#2D8CFF"
BLUE_400 = "#54A8FF"
CYAN_300 = "#7DDCFF"
GOLD_400 = "#F6C95B"
TEXT = "#EAF3FF"
MUTED = "#AEBED4"

NAME = "Safeharbor"
TAGLINE = "Every client issue, safely ashore."
ATTRIBUTION = "BY 8 WEST IT, LLC"


# --- Inter text -> SVG paths ------------------------------------------------
def load_font(weight):
    var = TTFont(FONT_PATH)
    return instantiateVariableFont(var, {"wght": weight}, inplace=False)


FONTS = {w: load_font(w) for w in (400, 500, 600, 700)}


def text_paths(text, size, weight=400, tracking_px=0.0):
    """Return (svg, width_px): SVG <path> group for `text` rendered in Inter.

    Glyph outlines are flipped (font y-up -> svg y-down) and laid out along a
    baseline at y=0. Caller positions with a surrounding <g transform>.
    """
    font = FONTS[weight]
    cmap = font.getBestCmap()
    hmtx = font["hmtx"]
    glyph_set = font.getGlyphSet()
    upm = font["head"].unitsPerEm
    scale = size / upm
    tracking_fu = tracking_px / scale

    parts, x_fu = [], 0.0
    for ch in text:
        gname = cmap.get(ord(ch))
        if gname is None:
            x_fu += upm // 3
            continue
        pen = SVGPathPen(glyph_set)
        glyph_set[gname].draw(pen)
        d = pen.getCommands()
        if d:
            parts.append(
                f'<path transform="translate({x_fu * scale:.3f} 0) '
                f'scale({scale:.5f} {-scale:.5f})" d="{d}"/>'
            )
        x_fu += hmtx[gname][0] + tracking_fu
    width = (x_fu - tracking_fu) * scale
    return "".join(parts), width


def text_group(text, size, weight, fill, x, baseline_y, tracking_px=0.0,
               anchor="start", opacity=None):
    body, width = text_paths(text, size, weight, tracking_px)
    if anchor == "middle":
        x -= width / 2
    elif anchor == "end":
        x -= width
    op = f' opacity="{opacity}"' if opacity else ""
    return (
        f'<g transform="translate({x:.2f} {baseline_y:.2f})" fill="{fill}"{op}>'
        f"{body}</g>"
    ), width


# --- mark geometry ------------------------------------------------------------
def polar(cx, cy, r, deg):
    a = math.radians(deg)
    return cx + r * math.cos(a), cy + r * math.sin(a)


def arc_path(cx, cy, r, a0, a1):
    x0, y0 = polar(cx, cy, r, a0)
    x1, y1 = polar(cx, cy, r, a1)
    large = 1 if abs(a1 - a0) > 180 else 0
    return f"M {x0:.2f} {y0:.2f} A {r} {r} 0 {large} 1 {x1:.2f} {y1:.2f}"


# The mark is authored once in a 512x512 space (visual center ~256, 239)
# and placed everywhere via a single group transform.
MARK_CX, MARK_CY = 256.0, 239.0


def mark_paths(glow=True, harbor=True, uid="m"):
    """Safeharbor harbor-light geometry in the canonical 512x512 space."""
    lamp_x, lamp_y = 256.0, 196.0
    parts = []
    if glow:
        parts.append(
            f'<circle cx="{lamp_x}" cy="{lamp_y}" r="150" '
            f'fill="url(#glowGold-{uid})"/>'
        )
    # radiating signal arcs: cyan core -> blue outer
    for r, color, op in ((72, CYAN_300, 1.0), (112, BLUE_400, 0.92),
                         (152, BLUE_500, 0.85)):
        parts.append(
            f'<path d="{arc_path(lamp_x, lamp_y, r, -158, -22)}" fill="none" '
            f'stroke="{color}" stroke-width="19" stroke-linecap="round" '
            f'opacity="{op}"/>'
        )
    # the gold lamp (+ faint halo ring)
    parts.append(f'<circle cx="{lamp_x}" cy="{lamp_y}" r="31" fill="{GOLD_400}"/>')
    parts.append(
        f'<circle cx="{lamp_x}" cy="{lamp_y}" r="44" fill="none" '
        f'stroke="{GOLD_400}" stroke-width="3" opacity="0.35"/>'
    )
    if harbor:
        # harbor bowl: protective outer wall
        parts.append(
            '<path d="M 108 312 C 108 396 176 424 256 424 '
            'C 336 424 404 396 404 312" fill="none" '
            f'stroke="{BLUE_500}" stroke-width="20" stroke-linecap="round"/>'
        )
        # the light-post the lamp stands on (drawn over the bowl)
        parts.append(
            f'<path d="M 245 236 L 267 236 L 274 388 Q 256 396 238 388 Z" '
            f'fill="{TEXT}" opacity="0.92"/>'
        )
    return "".join(parts)


def mark(cx, cy, size, uid="m", glow=True, harbor=True):
    """Place the mark with its visual center at (cx, cy), scaled to `size`."""
    s = size / 512.0
    return (
        f'<g transform="translate({cx - MARK_CX * s:.2f} '
        f'{cy - MARK_CY * s:.2f}) scale({s:.5f})">'
        f'{mark_paths(glow, harbor, uid)}</g>'
    )


def defs(uid="m"):
    return f"""<defs>
  <linearGradient id="tileBg-{uid}" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="{NAVY_800}"/>
    <stop offset="0.55" stop-color="{NAVY_900}"/>
    <stop offset="1" stop-color="{NAVY_950}"/>
  </linearGradient>
  <radialGradient id="glowGold-{uid}" cx="0.5" cy="0.5" r="0.5">
    <stop offset="0" stop-color="{GOLD_400}" stop-opacity="0.20"/>
    <stop offset="0.55" stop-color="{GOLD_400}" stop-opacity="0.06"/>
    <stop offset="1" stop-color="{GOLD_400}" stop-opacity="0"/>
  </radialGradient>
  <linearGradient id="sheen-{uid}" x1="0" y1="0" x2="0.6" y2="1">
    <stop offset="0" stop-color="#FFFFFF" stop-opacity="0.10"/>
    <stop offset="0.4" stop-color="#FFFFFF" stop-opacity="0"/>
  </linearGradient>
</defs>"""


def tile(x, y, size, rx_ratio=0.22, uid="m", with_mark=True, mark_ratio=0.78):
    rx = size * rx_ratio
    out = [
        f'<rect x="{x}" y="{y}" width="{size}" height="{size}" rx="{rx:.0f}" '
        f'fill="url(#tileBg-{uid})"/>',
        f'<rect x="{x}" y="{y}" width="{size}" height="{size}" rx="{rx:.0f}" '
        f'fill="url(#sheen-{uid})"/>',
        f'<rect x="{x + 1}" y="{y + 1}" width="{size - 2}" height="{size - 2}" '
        f'rx="{rx - 1:.0f}" fill="none" stroke="#FFFFFF" stroke-opacity="0.16" '
        f'stroke-width="2"/>',
    ]
    if with_mark:
        out.append(mark(x + size / 2, y + size / 2, size * mark_ratio, uid=uid))
    return "".join(out)


def svg_doc(w, h, body, extra_defs=""):
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" '
        f'viewBox="0 0 {w} {h}">{defs()}{extra_defs}{body}</svg>'
    )


# --- assets ---------------------------------------------------------------
def horizontal(dark=True):
    """Lockup: tile mark + wordmark + tagline + attribution."""
    w, h = 1060, 300
    tsize, tx, ty = 220, 40, 40
    name_size, tag_size, attr_size = 76, 33, 21
    name_fill = TEXT if dark else NAVY_800
    tag_fill = MUTED if dark else "#51627C"
    attr_fill = BLUE_400 if dark else BLUE_500

    name_g, _ = text_group(NAME, name_size, 700, name_fill, tx + tsize + 56,
                           ty + 108)
    tag_g, _ = text_group(TAGLINE, tag_size, 500, tag_fill, tx + tsize + 58,
                          ty + 168)
    attr_g, _ = text_group(ATTRIBUTION, attr_size, 600, attr_fill,
                           tx + tsize + 59, ty + 208, tracking_px=3.2)
    body = tile(tx, ty, tsize, uid="m") + name_g + tag_g + attr_g
    return svg_doc(w, h, body)


def square(dark=True):
    w = h = 1080
    name_fill = TEXT if dark else NAVY_800
    tag_fill = MUTED if dark else "#51627C"
    attr_fill = BLUE_400 if dark else BLUE_500
    bg = f'<rect width="{w}" height="{h}" fill="{NAVY_950 if dark else "#FFFFFF"}"/>'

    name_g, nw = text_group(NAME, 92, 700, name_fill, w / 2, 800, anchor="middle")
    tag_g, _ = text_group(TAGLINE, 40, 500, tag_fill, w / 2, 872, anchor="middle")
    attr_g, _ = text_group(ATTRIBUTION, 24, 600, attr_fill, w / 2, 950,
                           tracking_px=4.0, anchor="middle")
    body = bg + tile(300, 90, 480, uid="m") + name_g + tag_g + attr_g
    return svg_doc(w, h, body)


def app_tile():
    size = 1024
    body = tile(0, 0, size, rx_ratio=0.223, uid="m", mark_ratio=0.72)
    return svg_doc(size, size, body)


def mark_standalone():
    """Transparent mark only (no tile) for in-app use."""
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" '
        f'viewBox="0 0 512 512">{defs()}{mark_paths()}</svg>'
    )


def favicon():
    """Simplified mark for tiny sizes: lamp + post + arcs, thick strokes."""
    parts = [f'<rect width="64" height="64" rx="14" fill="url(#tileBg-m)"/>']
    lamp_x, lamp_y = 32, 25
    parts.append(
        f'<path d="M 15 44 C 15 53 22 56 32 56 C 42 56 49 53 49 44" '
        f'fill="none" stroke="{BLUE_500}" stroke-width="4.6" '
        f'stroke-linecap="round"/>'
    )
    parts.append(
        f'<path d="M 29.6 30 L 34.4 30 L 35.3 47 Q 32 48.4 28.7 47 Z" '
        f'fill="{TEXT}" opacity="0.92"/>'
    )
    for r, color, w in ((11, CYAN_300, 4.6), (17, BLUE_400, 4.4),
                        (23, BLUE_500, 4.2)):
        parts.append(
            f'<path d="{arc_path(lamp_x, lamp_y, r, -158, -22)}" fill="none" '
            f'stroke="{color}" stroke-width="{w}" stroke-linecap="round"/>'
        )
    parts.append(f'<circle cx="{lamp_x}" cy="{lamp_y}" r="5.4" fill="{GOLD_400}"/>')
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" '
        f'viewBox="0 0 64 64">{defs()}{"".join(parts)}</svg>'
    )


def tokens_json():
    return {
        "product": {"name": NAME, "tagline": TAGLINE,
                    "vendor": "8 West IT, LLC",
                    "family": ["Milepost", "Coastmark", "Safeharbor"]},
        "color": {
            "navy": {"950": NAVY_950, "900": NAVY_900, "800": NAVY_800,
                     "700": NAVY_700},
            "blue": {"500": BLUE_500, "400": BLUE_400},
            "cyan": {"300": CYAN_300},
            "gold": {"400": GOLD_400},
            "mint": {"300": "#62F6B0"},
            "text": TEXT, "muted": MUTED, "mutedStrong": "#D5E2F5",
            "card": "rgba(255,255,255,0.075)",
            "cardStrong": "rgba(255,255,255,0.12)",
            "border": "rgba(255,255,255,0.16)",
        },
        "radius": {"sm": 14, "md": 20, "lg": 28},
        "font": {"family": "Inter", "fallback": "ui-sans-serif, system-ui, sans-serif"},
        "shadow": "0 24px 70px rgba(0,0,0,0.32)",
    }


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    files = {
        "safeharbor-logo-horizontal-dark.svg": horizontal(dark=True),
        "safeharbor-logo-horizontal-light.svg": horizontal(dark=False),
        "safeharbor-logo-square-dark.svg": square(dark=True),
        "safeharbor-logo-square-light.svg": square(dark=False),
        "safeharbor-app-tile.svg": app_tile(),
        "safeharbor-mark.svg": mark_standalone(),
        "favicon.svg": favicon(),
    }
    for name, content in files.items():
        (OUT / name).write_text(content, encoding="utf-8")
        print(f"  wrote brand/svg/{name}")
    (ROOT / "brand" / "tokens.json").write_text(
        json.dumps(tokens_json(), indent=2) + "\n", encoding="utf-8")
    print("  wrote brand/tokens.json")


if __name__ == "__main__":
    main()
