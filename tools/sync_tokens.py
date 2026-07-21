#!/usr/bin/env python3
"""
Sync brand/tokens.json -> app/src/styles/tokens.css (Tailwind v4 @theme).

Single source of truth: brand/tokens.json. Never hand-edit tokens.css.
Run from repo root:  ./.venv/Scripts/python.exe tools/sync_tokens.py
"""

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOKENS = json.loads((ROOT / "brand" / "tokens.json").read_text(encoding="utf-8"))
OUT = ROOT / "app" / "src" / "styles" / "tokens.css"

HEADER = """/*
 * GENERATED FILE — do not edit by hand.
 * Source: brand/tokens.json  (sync: tools/sync_tokens.py)
 * Safeharbor / 8 West IT brand tokens, mapped into Tailwind v4 theme keys.
 */
"""


def main() -> None:
    c = TOKENS["color"]
    r = TOKENS["radius"]
    lines = [HEADER, "@theme {\n"]
    # colors
    for name, value in (
        ("navy-950", c["navy"]["950"]), ("navy-900", c["navy"]["900"]),
        ("navy-800", c["navy"]["800"]), ("navy-700", c["navy"]["700"]),
        ("blue-500", c["blue"]["500"]), ("blue-400", c["blue"]["400"]),
        ("cyan-300", c["cyan"]["300"]), ("gold-400", c["gold"]["400"]),
        ("mint-300", c["mint"]["300"]),
        ("ink", c["text"]), ("muted", c["muted"]),
        ("muted-strong", c["mutedStrong"]),
        ("card", c["card"]), ("card-strong", c["cardStrong"]),
        ("line", c["border"]),
        # semantic extensions (status system; documented in brand/README)
        ("rose-400", "#FF7A85"),
    ):
        lines.append(f"  --color-{name}: {value};\n")
    # radii
    lines.append(f"  --radius-sm: {r['sm']}px;\n")
    lines.append(f"  --radius-md: {r['md']}px;\n")
    lines.append(f"  --radius-lg: {r['lg']}px;\n")
    # font + shadow
    ff = TOKENS["font"]
    lines.append(f'  --font-sans: "Inter Variable", {ff["family"]}, {ff["fallback"]};\n')
    lines.append(f"  --shadow-card: {TOKENS['shadow']};\n")
    lines.append("}\n")
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text("".join(lines), encoding="utf-8")
    print(f"wrote {OUT.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
