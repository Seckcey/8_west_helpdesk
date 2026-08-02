#!/usr/bin/env python3
"""
Generate the 8 West Helpdesk planning document (.docx).

Run from the repo root with the project venv:
    ./.venv/Scripts/python.exe tools/build_doc.py

Output: docs/8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx
"""

from docx import Document
from docx.shared import Pt, Inches, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

# ---------------------------------------------------------------------------
# 8 West IT brand tokens (from 8westit.com styles.css)
# ---------------------------------------------------------------------------
NAVY_950 = RGBColor(0x03, 0x10, 0x22)
NAVY_900 = RGBColor(0x06, 0x19, 0x36)
NAVY_800 = RGBColor(0x0B, 0x24, 0x48)
NAVY_700 = RGBColor(0x12, 0x34, 0x5F)
BLUE_500 = RGBColor(0x2D, 0x8C, 0xFF)
BLUE_400 = RGBColor(0x54, 0xA8, 0xFF)
CYAN_300 = RGBColor(0x7D, 0xDC, 0xFF)
GOLD_400 = RGBColor(0xF6, 0xC9, 0x5B)
MINT_300 = RGBColor(0x62, 0xF6, 0xB0)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)

# Paper-friendly derivations for a printed document
INK = RGBColor(0x1A, 0x2B, 0x45)      # body text
MUTED = RGBColor(0x51, 0x62, 0x7C)    # secondary text
LIGHT_FILL = "EAF3FF"                  # table zebra fill (brand --#eaf3ff)
BAND_FILL = "0B2448"                   # dark band fill

FONT = "Inter"
OUTPUT = "docs/8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx"


# ---------------------------------------------------------------------------
# Low-level helpers
# ---------------------------------------------------------------------------
def shade_cell(cell, hex_fill):
    tc_pr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:fill"), hex_fill)
    tc_pr.append(shd)


def set_cell_margins(cell, top=60, bottom=60, left=110, right=110):
    tc_pr = cell._tc.get_or_add_tcPr()
    mar = OxmlElement("w:tcMar")
    for tag, val in (("top", top), ("bottom", bottom), ("start", left), ("end", right)):
        node = OxmlElement(f"w:{tag}")
        node.set(qn("w:w"), str(val))
        node.set(qn("w:type"), "dxa")
        mar.append(node)
    tc_pr.append(mar)


def para_border(paragraph, edge="bottom", color="2D8CFF", size=12, space=4):
    p_pr = paragraph._p.get_or_add_pPr()
    borders = OxmlElement("w:pBdr")
    el = OxmlElement(f"w:{edge}")
    el.set(qn("w:val"), "single")
    el.set(qn("w:sz"), str(size))
    el.set(qn("w:space"), str(space))
    el.set(qn("w:color"), color)
    borders.append(el)
    p_pr.append(borders)


def no_table_borders(table):
    tbl = table._tbl
    tbl_pr = tbl.tblPr
    borders = OxmlElement("w:tblBorders")
    for edge in ("top", "left", "bottom", "right", "insideH", "insideV"):
        el = OxmlElement(f"w:{edge}")
        el.set(qn("w:val"), "none")
        borders.append(el)
    tbl_pr.append(borders)


def run(paragraph, text, size=10.5, bold=False, italic=False, color=INK,
        font=FONT, small_caps=False):
    r = paragraph.add_run(text)
    r.font.name = font
    r.font.size = Pt(size)
    r.font.bold = bold
    r.font.italic = italic
    r.font.color.rgb = color
    if small_caps:
        r.font.small_caps = True
    rpr = r._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:ascii"), font)
    rfonts.set(qn("w:hAnsi"), font)
    return r


# ---------------------------------------------------------------------------
# Content helpers
# ---------------------------------------------------------------------------
def h1(doc, text, numbered=None):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(22)
    p.paragraph_format.space_after = Pt(8)
    label = f"{numbered}.  " if numbered else ""
    run(p, label + text, size=17, bold=True, color=NAVY_800)
    para_border(p, "bottom", "2D8CFF", 14, 6)
    return p


def h2(doc, text):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(14)
    p.paragraph_format.space_after = Pt(4)
    run(p, text, size=13, bold=True, color=NAVY_700)
    return p


def h3(doc, text):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(10)
    p.paragraph_format.space_after = Pt(2)
    run(p, text, size=11, bold=True, color=BLUE_500)
    return p


def eyebrow(doc, text):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(2)
    p.paragraph_format.space_after = Pt(0)
    run(p, text.upper(), size=8.5, bold=True, color=BLUE_400)
    return p


def para(doc, text, size=10.5, color=INK, italic=False, space_after=6,
         bold=False, align=None):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(space_after)
    p.paragraph_format.line_spacing = 1.18
    if align:
        p.alignment = align
    run(p, text, size=size, color=color, italic=italic, bold=bold)
    return p


def rich(doc, parts, space_after=6, size=10.5):
    """parts: list of (text, dict-of-run-kwargs)"""
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(space_after)
    p.paragraph_format.line_spacing = 1.18
    for text, kwargs in parts:
        merged = {"size": size, "color": INK}
        merged.update(kwargs)
        run(p, text, **merged)
    return p


def bullets(doc, items, size=10.5, style="List Bullet"):
    for item in items:
        p = doc.add_paragraph(style=style)
        p.paragraph_format.space_after = Pt(3)
        p.paragraph_format.line_spacing = 1.15
        if isinstance(item, tuple):  # (bold lead, rest)
            run(p, item[0], size=size, bold=True, color=NAVY_700)
            run(p, item[1], size=size, color=INK)
        else:
            run(p, item, size=size, color=INK)


def table(doc, headers, rows, widths=None, zebra=True, header_fill=BAND_FILL,
          size=9.5, header_size=9.5, align=WD_TABLE_ALIGNMENT.CENTER):
    t = doc.add_table(rows=1, cols=len(headers))
    t.style = "Table Grid"
    t.alignment = align
    t.autofit = False
    hdr = t.rows[0].cells
    for i, htext in enumerate(headers):
        shade_cell(hdr[i], header_fill)
        set_cell_margins(hdr[i])
        p = hdr[i].paragraphs[0]
        p.paragraph_format.space_after = Pt(0)
        run(p, htext, size=header_size, bold=True, color=WHITE)
    for r_idx, row in enumerate(rows):
        cells = t.add_row().cells
        for i, val in enumerate(row):
            set_cell_margins(cells[i])
            if zebra and r_idx % 2 == 1:
                shade_cell(cells[i], LIGHT_FILL)
            p = cells[i].paragraphs[0]
            p.paragraph_format.space_after = Pt(0)
            p.paragraph_format.line_spacing = 1.12
            if isinstance(val, tuple):  # (bold, rest)
                run(p, val[0], size=size, bold=True, color=NAVY_700)
                run(p, val[1], size=size, color=INK)
            else:
                run(p, str(val), size=size, color=INK)
    if widths:
        for i, w in enumerate(widths):
            for row in t.rows:
                row.cells[i].width = Inches(w)
    return t


def spacer(doc, pts=6):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(pts)
    run(p, "", size=2)
    return p


def page_break(doc):
    doc.add_page_break()


# ---------------------------------------------------------------------------
# Document sections
# ---------------------------------------------------------------------------
def build_cover(doc):
    for _ in range(5):
        spacer(doc, 12)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run(p, "8 WEST IT  ·  TOTAL BUSINESS SUITE", size=10, bold=True, color=BLUE_400)
    p.paragraph_format.space_after = Pt(30)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run(p, "The Help Desk App", size=40, bold=True, color=NAVY_800)
    p.paragraph_format.space_after = Pt(6)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run(p, "App Concept, Naming Candidates & Phased Rollout Plan", size=15,
        color=NAVY_700)
    p.paragraph_format.space_after = Pt(26)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    para_border(p, "bottom", "F6C95B", 18, 1)
    p.paragraph_format.space_after = Pt(30)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run(p, "The third marker on the route — alongside ", size=11.5, color=MUTED, italic=True)
    run(p, "Milepost", size=11.5, bold=True, color=NAVY_700, italic=True)
    run(p, " (RMM) and ", size=11.5, color=MUTED, italic=True)
    run(p, "Coastmark", size=11.5, bold=True, color=NAVY_700, italic=True)
    run(p, " (accounting).", size=11.5, color=MUTED, italic=True)

    for _ in range(6):
        spacer(doc, 12)

    meta = doc.add_table(rows=4, cols=2)
    meta.alignment = WD_TABLE_ALIGNMENT.CENTER
    no_table_borders(meta)
    rows = [
        ("Prepared for", "8 West IT, LLC — internal planning"),
        ("Chosen name", "Safeharbor — “Every client issue, safely ashore.” (Section 3)"),
        ("Date", "July 21, 2026"),
        ("Status", "v1.1 — name selected; Phase 0 underway"),
    ]
    for i, (k, v) in enumerate(rows):
        c0, c1 = meta.rows[i].cells
        p0 = c0.paragraphs[0]
        p0.alignment = WD_ALIGN_PARAGRAPH.RIGHT
        run(p0, k.upper() + "   ", size=9, bold=True, color=BLUE_500)
        p1 = c1.paragraphs[0]
        run(p1, v, size=9.5, color=INK)
        c0.width = Inches(1.7)
        c1.width = Inches(4.3)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_before = Pt(40)
    run(p, "Confidential — internal planning document. Not for external distribution.",
        size=8.5, italic=True, color=MUTED)
    page_break(doc)


def build_exec_summary(doc):
    eyebrow(doc, "Section 1")
    h1(doc, "Executive Summary")

    para(doc,
         "8 West IT is building a total business suite for managed service providers: "
         "Milepost for RMM and client operations, Coastmark for accounting. This document "
         "plans the third product in that suite — a help desk app — and lays out what it "
         "should be called, what it must do, and how we roll it out without becoming the "
         "kind of bloated software it competes against.")

    h2(doc, "The idea in one paragraph")
    para(doc,
         "A help desk purpose-built for small MSPs (1–25 technicians) that is radically "
         "simpler, cleaner, and faster than ConnectWise, Autotask, or HaloPSA — because it "
         "is designed around the ticket queue technicians actually live in all day, and "
         "because it is natively connected to Milepost (so alerts arrive as tickets with the "
         "device, client, and contract already attached) and to Coastmark (so approved time "
         "becomes an invoice line without a sync job, an export, or a prayer). No competitor "
         "owns the RMM, the ledger, and the help desk. We would.")

    h2(doc, "Why now — the research in four sentences")
    bullets(doc, [
        ("The tools MSPs tolerate are disliked, not loved. ",
         "ConnectWise is called “clunky,” “bloated,” and “the worst thing I’ve worked with”; "
         "its time entry is so disliked that techs batch it on Friday afternoons — a literal "
         "revenue leak. HaloPSA is the best-reviewed and still demands a dedicated admin."),
        ("The 1–25 tech segment is structurally underserved. ",
         "HaloPSA locks out shops under 5 seats; ConnectWise/Autotask are oversized; "
         "Syncro/Atera fit the segment but are shallow on SLAs, contracts, and reporting."),
        ("Nobody has won on interaction design. ",
         "The modern standard (Linear-grade speed, command palette, keyboard-first, clean "
         "dark UI) simply does not exist in MSP tooling. SuperOps is “modern” only relative "
         "to ConnectWise."),
        ("Pricing honesty is a weapon. ",
         "Quote-only pricing, seat minimums, and annual lock-ins are actively resented. "
         "Published per-tech pricing, monthly terms, AI included."),
    ])

    h2(doc, "What this document contains")
    bullets(doc, [
        "The 8 West brand foundation this app inherits (Section 2)",
        "20 name and tagline candidates, with a top-3 recommendation (Section 3)",
        "Market research: what MSPs need, what incumbents get wrong (Section 4)",
        "The product concept and feature blueprint (Sections 5–6)",
        "A five-phase rollout plan from foundation to scale (Section 7)",
        "Pricing direction, success metrics, and risks (Sections 8–10)",
    ])


def build_brand(doc):
    page_break(doc)
    eyebrow(doc, "Section 2")
    h1(doc, "Brand Foundation — What This App Inherits")

    para(doc,
         "The 8 West IT site speaks a consistent visual language: a deep-navy night sky, "
         "electric blue signal light, cyan horizon glow, and a warm gold lamp — with "
         "plain-spoken copy that treats the reader like an adult. The help desk app should "
         "feel like the same world at application scale. These tokens come directly from "
         "8westit.com and become the app's design system.")

    h2(doc, "Palette (from 8westit.com styles.css)")
    table(doc,
          ["Token", "Hex", "Role in the help desk app"],
          [
              ("navy-950", "#031022", "App window frame / deepest background"),
              ("navy-900", "#061936", "Primary app background (dark mode first)"),
              ("navy-800", "#0B2448", "Panels, sidebars, ticket detail surface"),
              ("navy-700", "#12345F", "Hover states, raised cards, table headers"),
              ("blue-500", "#2D8CFF", "Primary actions, links, focus rings"),
              ("blue-400", "#54A8FF", "Secondary actions, chart series"),
              ("cyan-300", "#7DDCFF", "Highlights, active states, AI-assist accents"),
              ("gold-400", "#F6C95B", "Warnings, SLA-at-risk, “needs attention” markers"),
              ("mint-300", "#62F6B0", "Success, resolved, SLA-healthy states"),
              ("text / muted", "#EAF3FF / #AEBED4", "Primary and secondary text on navy"),
          ],
          widths=[1.2, 1.2, 3.6])
    spacer(doc)

    h2(doc, "Design language")
    bullets(doc, [
        ("Typography. ", "Inter throughout; UI body at 13–14px; headings tight and quiet."),
        ("Surfaces. ", "Glassmorphic cards (7–12% white fill, 16% white border), radius "
         "14–28px, one soft shadow — never hard outlines."),
        ("Motion. ", "100–250ms transitions; everything feels instant or says why it isn’t."),
        ("Gradients. ", "Cyan→blue reserved for primary action and brand moments only."),
        ("Voice. ", "Plain, practical, lightly witty. “Settings that don’t require a "
         "decoding ring.” No buzzwords, no enterprise theater."),
    ])

    h2(doc, "The product family naming convention")
    para(doc,
         "Milepost marks where you are on the road. Coastmark marks where you stand on the "
         "coast. Both are wayfinding markers — single words, concrete, calm, and confident. "
         "The help desk name must belong to the same family: a marker, a light, or a "
         "signal that helps travelers (clients) reach safety. Every candidate in Section 3 "
         "was screened against that rule.")
    table(doc,
          ["Product", "Job in the suite", "Marker it represents"],
          [
              ("Milepost", "RMM — device visibility & client operations", "The road marker: where every device stands"),
              ("Coastmark", "Accounting for MSPs", "The coastal marker: where the business stands"),
              ("This app", "Help desk — support intake, work, resolution", "The light that guides every issue safely in"),
          ],
          widths=[1.1, 2.5, 2.4])


def build_naming(doc):
    page_break(doc)
    eyebrow(doc, "Section 3")
    h1(doc, "Naming — 20 Candidates with Taglines")

    para(doc,
         "All 20 follow the family rule: single words (or tight compounds) drawn from "
         "wayfinding — roads, coasts, lights, and signals. Taglines are written in the "
         "8 West voice: plain-spoken, practical, quietly confident. Each entry notes why "
         "it fits. Validation comes after the table — nothing here is final until the "
         "checklist in 3.3 is done.")

    h2(doc, "3.1  The candidates")
    names = [
        ("Waymark", "Every ticket knows where it’s going.",
         "The truest sibling to Milepost and Coastmark — a trail marker. Quiet, structural, exact."),
        ("Guidepost", "Help that points the way.",
         "Reads instantly as “help”; a signpost for people who need direction."),
        ("Lamplight", "Support, with the lights on.",
         "The gold-on-navy palette made literal. Warm, calm, human — the anti-NOC."),
        ("Lightkeeper", "Keeping the lights on for every client.",
         "The lighthouse keeper — doubles as the MSP’s core promise to its clients."),
        ("Beaconmark", "A clear signal when clients need help.",
         "Distress-to-rescue maritime signal; compounds like Coastmark."),
        ("Harborlight", "Guide every issue safely in.",
         "The light at the harbor mouth — tickets arrive safely, never lost at sea."),
        ("Northlight", "The fixed point your support can steer by.",
         "North-star constancy; also the cyan aurora glow of the palette."),
        ("Signalpost", "Where every request lands — and gets answered.",
         "Communication plus wayfinding; functional and unambiguous."),
        ("Portlight", "A clear view into every client’s voyage.",
         "Porthole double meaning: a light, and a window onto the client."),
        ("Lantern", "Carry less. See more.",
         "A hand-held light — a simple tool for dark work; modest and useful."),
        ("Safeharbor  ★", "Every client issue, safely ashore.",
         "The destination all support aims for; strongest “resolution” connotation. "
         "★ SELECTED — July 21, 2026."),
        ("Watchpost", "Nothing slips past your desk.",
         "Vigilance; pairs naturally with Milepost monitoring alerts."),
        ("Landmark", "Support your clients can actually find.",
         "Unmissable and recognizable — also “land the mark”: resolve the issue."),
        ("Brightpost", "Clear requests. Brighter outcomes.",
         "Optimistic; mirrors the Milepost cadence."),
        ("Truemark", "The honest record of every promise kept.",
         "SLAs and accountability; “true” signals trust and accuracy."),
        ("Chartmark", "Every issue plotted. Every fix on course.",
         "A nautical chart annotation; analytical sibling to Coastmark."),
        ("Pathlight", "Lighting the route from problem to fixed.",
         "Small, humble lights along a path — journey from open to closed."),
        ("Lookout", "See every request before it becomes a fire.",
         "The crow’s nest; a proactive support posture."),
        ("Anchorlight", "The steady light that’s always on.",
         "The light a ship shows at anchor — quiet 24/7 reliability."),
        ("Channelmark", "Keeping every client in safe water.",
         "Buoys marking the safe channel — the MSP as guide through hazards."),
    ]
    table(doc, ["#", "Name", "Tagline", "Why it fits the family"],
          [(str(i + 1), (n + "  ", ""), tag, why) for i, (n, tag, why) in enumerate(names)],
          widths=[0.35, 1.25, 2.25, 2.15], size=9)
    spacer(doc)

    h2(doc, "3.2  Decision")
    para(doc,
         "Selected July 21, 2026: ", bold=True, color=NAVY_700, space_after=2)
    rich(doc, [("Safeharbor — “Every client issue, safely ashore.”  ",
                {"bold": True, "size": 12, "color": NAVY_800})])
    para(doc,
         "Safeharbor carries the strongest resolution metaphor in the family: the harbor "
         "is where every voyage ends safely — which is precisely the job of a help desk. "
         "It pairs naturally with Coastmark (both coastal), gives the product an "
         "instantly understood promise, and inspired the brand mark already produced: a "
         "gold harbor light radiating over the sheltering harbor bowl (see the brand/ "
         "directory in this repository). Shortlist honored: Waymark (most disciplined "
         "family fit), Lightkeeper (best story), Lamplight (most visual).")
    para(doc,
         "The name Safeharbor is used throughout this document and the product. "
         "Trademark/domain validation (3.3) applies to Safeharbor as the final candidate.",
         italic=True, color=MUTED)

    h2(doc, "3.3  Validation checklist (before any name is final)")
    bullets(doc, [
        "USPTO trademark search (Nice classes 9 & 42) — no conflicts in software/SaaS",
        "Domain acquisition: name.com or getname.com / name.app within budget",
        "Google + GitHub + app-store sweep for existing software products with the name",
        "MSP-community sniff test: post the top 3 in r/msp-adjacent communities for gut reactions",
        "Say-it test: “Open a ticket in ___” must sound natural for all three finalists",
    ])


def build_research(doc):
    page_break(doc)
    eyebrow(doc, "Section 4")
    h1(doc, "Market Research — What MSPs Actually Need")

    para(doc,
         "Findings below are compiled from MSP community threads (r/msp), G2/Capterra "
         "review patterns, vendor pricing pages, and independent comparison guides "
         "(sources in the Appendix). They drive every scoping decision in this plan.")

    h2(doc, "4.1  Table stakes — features MSPs treat as non-negotiable")
    table(doc,
          ["Rank", "Capability", "What the community says"],
          [
              ("1", "Email-to-ticket with the MSP’s own domain",
               "The #1 intake channel — “most users want to just email the helpdesk.” NinjaOne is dinged for lacking custom domains."),
              ("2", "Frictionless time tracking tied to billing",
               "The most emotionally charged feature. ConnectWise time entry is so disliked that techs batch it Fridays — a measurable revenue leak."),
              ("3", "SLA policies with breach alerts & business hours",
               "HaloPSA’s most-praised trait: “we finally model real SLAs without workarounds.”"),
              ("4", "Contracts / agreements + recurring billing",
               "The reason PSAs exist. Recurring-invoice automation is the most-praised module in CW and BMS reviews."),
              ("5", "True multi-client separation",
               "One dashboard, isolated client data, per-client SLAs — the MSP-vs-generic-helpdesk dividing line. Zendesk is dinged for lacking it."),
              ("6", "RMM context on tickets",
               "Alert→ticket with device, client, and contract pre-attached is the most-praised trait of unified tools; Automate↔Manage sync pain is legendary."),
          ],
          widths=[0.5, 2.2, 3.3], size=9)
    spacer(doc)
    h3(doc, "Strongly expected (deal-shaping, not deal-breaking)")
    bullets(doc, [
        "White-labeled client portal (tickets, status, invoices, KB) — HaloPSA’s default portal is famously “ships ugly”",
        "Automation rules that a non-engineer can build (IFTTT-simple, not CW workflow weeks)",
        "Reporting: SLA attainment, tech utilization, ticket aging — Syncro loses deals here",
        "Billing handoff (native or QuickBooks/Xero), knowledge base, ticket templates/recurring tickets",
        "Microsoft 365 / Teams intake — the fastest-rising expectation (DeskDay is Teams-first)",
        "Mobile app for triage and time entry",
    ])
    h3(doc, "Emerging expectations (2025–2026)")
    bullets(doc, [
        "AI triage, thread summarization, and draft replies — included in base price, not a $29–$50/agent add-on",
        "CSAT surveys, dispatch/calendar and on-call, approval workflows, chat-first intake, remote session launch from ticket",
    ])

    h2(doc, "4.2  What incumbents get wrong (verbatim)")
    table(doc,
          ["Tool", "The wound we exploit"],
          [
              ("ConnectWise PSA", "“Clunky and outdated… bloated… runs slower.” Time entry “so clunky techs wait until Friday.” ~2-month implementation, weeks of tech training, quote-only pricing, annual lock-in."),
              ("Autotask (Kaseya)", "“Huge and sometimes clunky”; dated UI is a headline con in every review; users begged the 2025 refresh for “a more speedy smooth system, especially for working tickets.”"),
              ("HaloPSA", "Best-reviewed, still: steep learning curve, the “Halo Tax” (a dedicated admin at >15 techs), 5-seat minimum + £3,200 onboarding fee locks out small shops, ugly default portal, SQL needed for advanced reports."),
              ("Syncro", "“We can no longer work around the limitations and bugs.” Thin PSA depth, weak reporting (“export to Excel for anything beyond basics”). Praised mainly for price simplicity."),
              ("SuperOps", "Best modern UI (“fax machine to iPhone” vs CW) but shallow PSA depth, slow bug fixes, ~⅓ the integration catalog, AI “mostly marketing.”"),
              ("Atera / BMS / Zendesk / Freshservice", "Light ticketing, reporting gated to higher tiers, read-only users needing full licenses, resync-prone integrations, contract horror stories, AI sold as an add-on, and — for the generic tools — no MSP-native multi-tenancy or agreements at all."),
          ],
          widths=[1.5, 4.5], size=9)
    spacer(doc)

    h2(doc, "4.3  Pricing landscape (per tech/agent per month)")
    table(doc,
          ["Tool", "Price", "Model notes"],
          [
              ("ConnectWise PSA", "Quote (~$25–35 est.)", "Annual contract; bundles $9K–$85K/yr"),
              ("Autotask", "Quote (~$40–70 est.)", "Kaseya-bundled, annual"),
              ("HaloPSA", "£69 (~$89); tiers $35–$109", "5-seat minimum; all features included"),
              ("Syncro", "$129–$179", "Unlimited endpoints, month-to-month — loved for it"),
              ("SuperOps", "$79–$179 + endpoints", "Per-tech + per-endpoint math"),
              ("Atera", "$129–$209", "Unlimited endpoints; AI copilot extra"),
              ("DeskDay", "from $59", "Cheapest MSP-native; Teams-first"),
              ("Zendesk / Freshservice", "$19–$115 / $19+", "Generic; add-ons and AI surcharges pile up"),
          ],
          widths=[1.5, 1.7, 2.8], size=9)
    spacer(doc)
    para(doc,
         "Preference signal is loud: published pricing (HaloPSA’s most-praised trait), "
         "per-tech with unlimited endpoints (Syncro/Atera’s core appeal), monthly terms, "
         "and all-inclusive tiers. Quote calls and lock-ins are resented.")

    h2(doc, "4.4  The whitespace we occupy")
    bullets(doc, [
        ("The 1–25 tech shop, taken seriously. ", "Every incumbent either locks them out, "
         "overserves them into complexity, or underserves them on PSA depth."),
        ("Architecture, not integrations. ", "Owning Milepost (RMM) and Coastmark (ledger) "
         "deletes the two most-hated categories: sync jobs and billing reconciliation. "
         "Alert→ticket→time→invoice with zero connectors."),
        ("Interaction design as the product. ", "No MSP tool delivers Linear-grade speed, "
         "a command palette, full keyboard control, and a clean dark UI. Techs live in the "
         "queue eight hours a day; the contrast wins demos by itself."),
        ("Neutralize switching costs. ", "Free guided importers from ConnectWise, Autotask, "
         "Syncro, and HaloPSA — the incumbents’ only real moat."),
    ])


def build_concept(doc):
    page_break(doc)
    eyebrow(doc, "Section 5")
    h1(doc, "Product Concept")

    h2(doc, "5.1  Positioning statement")
    para(doc,
         "For MSPs of 1–25 technicians who live in the ticket queue all day, Safeharbor is "
         "the help desk in the 8 West IT suite that turns every client signal — email, "
         "portal, chat, or Milepost alert — into a resolved, invoiced outcome with the "
         "fewest clicks in the industry. Unlike ConnectWise and Autotask, it is fast, "
         "quiet, and learnable in an afternoon. Unlike Syncro and Atera, it does not "
         "sacrifice SLA, contract, or reporting depth. Unlike HaloPSA, it needs no "
         "dedicated admin and no minimum seat count.", italic=False)

    h2(doc, "5.2  The 8 West Standard (non-negotiable UX rules)")
    bullets(doc, [
        ("Speed is a feature. ", "Interactions feel instant (optimistic UI); anything over "
         "300ms gets a skeleton or a progress cue — never a freeze."),
        ("Command palette first. ", "Ctrl/Cmd+K reaches every ticket, client, and action "
         "with fuzzy search. Full keyboard model: S status, P priority, A assign, E time entry."),
        ("Progressive disclosure. ", "Dense, calm lists; details reveal on hover/expand; "
         "inline editing everywhere; modals are a last resort."),
        ("Dark-mode-first. ", "The navy palette is the product. Light mode is a theme, "
         "not the default."),
        ("Time capture without thinking. ", "A visible timer on every ticket plus automatic "
         "work-log suggestions; one click turns work into billable time. This directly "
         "attacks the Friday-batch-entry revenue leak."),
        ("AI included, inline, invisible. ", "Triage suggestions, thread summaries, and "
         "draft replies appear where work happens — no add-on SKU, no sparkle-bait."),
        ("Every feature earns its click. ", "A standing complexity budget: nothing ships "
         "that adds a click to the daily queue workflow without removing two elsewhere."),
    ])

    h2(doc, "5.3  Feature blueprint (mapped to rollout phases)")
    table(doc,
          ["Module", "Scope", "Phase"],
          [
              ("Intake", "Email-to-ticket (custom domain), client portal, widget; Teams intake in P3", "P1–P3"),
              ("Ticket core", "Queues, statuses, priorities, assignment, tags, internal notes, attachments, merge/link, @mentions", "P1"),
              ("Clients & contacts", "Multi-tenant client records, contacts, per-client defaults and routing", "P1"),
              ("Time & billing", "One-click timers, auto work-log, billable flags, rates; Coastmark invoice handoff", "P2"),
              ("SLAs", "Policies, business hours, breach warnings (gold) and healthy states (mint)", "P2"),
              ("Client portal", "White-label submit/status/KB; invoices visible via Coastmark", "P2"),
              ("Knowledge base", "Internal + client-facing articles, suggested answers", "P2–P3"),
              ("Milepost bridge", "Alert→ticket with asset context; device panel on ticket; remote session launch", "P2"),
              ("Westy — suite assistant", "The 8 West IT 365 chatbot (shared with Milepost): onboarding tour on first sign-in + advise-only how-to helper on every page; drafting joins P3", "P1"),
              ("Suite SSO", "8 West ID sign-in via the shared suite cookie (Milepost parity); full OIDC flow later", "P1"),
              ("Automation", "Visual rule builder: routing, escalation, status, notifications, templates, recurring tickets", "P3"),
              ("AI assist", "Triage/classify, thread summary, draft reply, KB suggestions — included (Westy grows these)", "P3"),
              ("Reporting", "SLA attainment, first-response, aging, utilization, client health dashboards", "P3"),
              ("CSAT & surveys", "One-tap resolution surveys, trend reporting", "P3"),
              ("Platform", "Public API, webhooks, importers (CW/Autotask/Syncro/Halo)", "P3"),
              ("Mobile", "iOS/Android triage, reply, timer", "P4"),
              ("Dispatch", "Calendar, on-call rotations, approval workflows", "P4"),
              ("Ecosystem", "Integration gallery, M365 deeper sync, SOC 2 program, SAML SSO", "P4"),
          ],
          widths=[1.4, 3.7, 0.9], size=9)
    spacer(doc)

    h2(doc, "5.4  Suite integration — the killer flows")
    bullets(doc, [
        ("Milepost → Safeharbor. ", "A server alert becomes a ticket with the device, client, "
         "site, open alerts, and recent patches already attached. One click opens a remote "
         "session from the ticket. Closing the ticket can auto-resolve the alert."),
        ("Safeharbor → Coastmark. ", "Approved time on a ticket flows to the client’s "
         "agreement; month-end invoicing is a review screen, not a reconciliation project. "
         "No exports, no sync jobs, no “why is QuickBooks different again.”"),
        ("One sign-in, one client record. ", "Suite SSO and a shared client graph: a client "
         "created once exists everywhere — devices in Milepost, invoices in Coastmark, "
         "tickets in Safeharbor."),
    ])


def build_ux(doc):
    page_break(doc)
    eyebrow(doc, "Section 6")
    h1(doc, "UX & Design Direction")

    para(doc,
         "The bar is not “better than ConnectWise.” The bar is the software people choose "
         "when they have a choice: Linear, Height, Help Scout. Every screen below is "
         "described as a promise we can demo against.")

    h2(doc, "6.1  The five screens that matter")
    bullets(doc, [
        ("The Queue (home). ", "One calm, dense list: status chip, priority glyph, client, "
         "subject, age, SLA lamp (mint → gold → red). Zero chrome above it except search. "
         "Keyboard navigable end to end; bulk actions appear only on selection."),
        ("The Ticket. ", "Conversation in the center like a chat thread; client, device "
         "(via Milepost), SLA countdown, and time timer in a slim right rail; every field "
         "editable inline. A tech should resolve a ticket without ever leaving this view."),
        ("The Client. ", "One page per client: open tickets, devices, agreement, recent "
         "invoices (via Coastmark), contacts, and health notes — the “answer the phone "
         "smart” screen."),
        ("The Timer. ", "Persistent, quiet, always one click away; the app suggests time "
         "entries from actual work. The Friday-afternoon time-entry dread is the enemy; "
         "this screen is the weapon."),
        ("The Palette. ", "Ctrl/Cmd+K: jump to any ticket, client, device, or action. "
         "New techs learn the product by using the palette for a day."),
    ])

    h2(doc, "6.2  Brand application rules")
    bullets(doc, [
        "Navy-900 is the canvas; panels float at navy-800 with 16% white hairline borders and 14–20px radii",
        "Blue-500 = go (primary buttons, links); cyan-300 = attention without alarm (active nav, AI hints)",
        "Gold-400 is spent only on SLA risk and warnings; mint-300 only on resolved/healthy — color stays meaningful",
        "One gradient (cyan→blue), reserved for the primary CTA and brand moments",
        "Empty states teach; error messages say what happened and what to do, in plain English",
    ])


def build_phases(doc):
    page_break(doc)
    eyebrow(doc, "Section 7")
    h1(doc, "Phased Rollout Plan")

    para(doc,
         "Five phases, each with a goal, an explicit in/out scope, and exit criteria that "
         "must be true before the next phase starts. Timelines are indicative for a small "
         "focused team and assume Milepost and Coastmark proceed in parallel; integration "
         "phases re-sequence to match their readiness.")

    phases = [
        ("Phase 0 — Foundation & Design System", "Months 0–2",
         "Prove the look and stand up the skeleton.",
         ["In: brand tokens as code (the palette above), app shell, auth & multi-tenant "
          "data model, suite SSO contract, dark-mode-first component library, clickable "
          "prototype of the five key screens",
          "Out: any ticketing logic beyond prototype stubs"],
         "Prototype passes the “8 West Standard” checklist; a tech can keyboard-drive the "
         "demo queue; tokens imported, not hard-coded."),
        ("Phase 1 — Core Ticketing MVP", "Months 2–5",
         "Run 8 West IT’s own support desk on it (dogfood).",
         ["In: email-to-ticket with custom domain, ticket core (queues, statuses, "
          "priorities, assignment, notes, attachments), clients & contacts, notifications, "
          "basic search",
          "In (Sprint 1): 8 West ID suite SSO — the shared suite cookie Milepost ships, "
          "kill-switch gated — and Westy, the suite assistant, as first-run onboarding "
          "guide + advise-only how-to helper (advises, never acts; AI keys server-only)",
          "Out: SLAs, portal, automation, AI drafting, reporting beyond a list view"],
         "8 West IT handles all real client support in-app for 4 consecutive weeks; "
         "zero data-loss incidents; p95 ticket-open interaction under 300ms; a new "
         "user reaches their first answered ticket with no human walkthrough (Westy "
         "carries the onboarding)."),
        ("Phase 2 — MSP Essentials + Suite Integration", "Months 5–8",
         "Become a real MSP tool and wire in Milepost & Coastmark. Private beta with "
         "10–20 design-partner MSPs.",
         ["In: SLA policies + business hours, one-click timers & auto work-log, white-label "
          "client portal, KB basics, Milepost alert→ticket + device panel + remote launch, "
          "Coastmark approved-time→invoice handoff",
          "Out: automation builder, AI assist, mobile, dispatch"],
         "Beta NPS ≥ 40; ≥ 80% of billable time entered same-day (vs. the industry’s "
         "Friday batch); every beta MSP running at least one Milepost and one Coastmark flow."),
        ("Phase 3 — Automation, Intelligence & Public Launch", "Months 8–12",
         "Open the doors with the features that win comparisons.",
         ["In: visual automation builder, AI triage/summary/draft (included), reporting "
          "dashboards, CSAT, templates & recurring tickets, Teams intake, public API + "
          "webhooks, guided importers (CW, Autotask, Syncro, Halo)",
          "Out: native mobile, dispatch/on-call, marketplace"],
         "Self-serve signup → first ticket in under 10 minutes; 50 paying MSPs; "
         "≥ 30% of new logos via importers; published pricing page live."),
        ("Phase 4 — Scale & Ecosystem", "Months 12–18",
         "Deepen without bloating; grow the suite gravity well.",
         ["In: iOS/Android apps, dispatch calendar & on-call, approvals, integration "
          "gallery, advanced reporting, SAML SSO, SOC 2 program start",
          "Out: enterprise feature sprawl — every addition must pass the complexity budget"],
         "250+ paying MSPs; gross logo churn < 3%/month; CSAT ≥ 95%; suite attach rate "
         "(≥ 2 of 3 products) above 60%."),
    ]
    for title, when, goal, scope, exit_criteria in phases:
        h2(doc, title + "  ·  " + when)
        para(doc, "Goal: " + goal, bold=True, color=NAVY_700, space_after=3)
        bullets(doc, scope)
        rich(doc, [("Exit criteria: ", {"bold": True, "color": BLUE_500}),
                   (exit_criteria, {})])
        spacer(doc, 2)


def build_pricing(doc):
    page_break(doc)
    eyebrow(doc, "Section 8")
    h1(doc, "Pricing & Packaging Direction")

    para(doc,
         "Pricing is part of the product and part of the attack. Direction (to be "
         "validated with design partners in Phase 2):")

    table(doc,
          ["Principle", "Decision"],
          [
              ("Published & simple", "One flat per-tech price on the website — indicative $59/tech/month. No quote calls, no “contact sales.”"),
              ("No seat minimums", "A 1-person MSP pays for 1 seat. Direct hit on HaloPSA’s 5-seat floor."),
              ("Monthly terms", "Month-to-month standard; annual optional with discount. No lock-in, ever."),
              ("AI included", "Triage, summaries, drafts in base price. Competitors charge $29–$50/agent extra and are resented for it."),
              ("Unlimited clients", "Per-tech pricing only; adding a 200-endpoint client never changes the bill (mirrors Syncro/Atera’s most-loved trait)."),
              ("Suite gravity", "Milepost + Coastmark + Safeharbor bundle: meaningful discount for the full route — priced so the suite is the obvious choice, never a penalty for buying one app."),
              ("Founding-member program", "Phase 2 design partners: lifetime discount + direct line to the team in exchange for honest abuse."),
          ],
          widths=[1.7, 4.3], size=9.5)
    spacer(doc)
    para(doc,
         "Position check: below Syncro’s $129 and Halo’s ~$89+5-seat minimum, above "
         "Zoho-grade “cheap but not MSP.” The price should read as honest, not cheap.",
         italic=True, color=MUTED)


def build_metrics(doc):
    eyebrow(doc, "Section 9")
    h1(doc, "Success Metrics")
    table(doc,
          ["Metric", "Target", "Why it matters"],
          [
              ("Time to first ticket", "< 10 minutes from signup", "Onboarding is the first demo; incumbents take weeks"),
              ("Onboarding to productive", "< 1 day, self-serve", "Kills the #2 incumbent moat (implementation friction)"),
              ("Same-day time entry", "≥ 80% of billable work", "Directly recovers the Friday-batch revenue leak"),
              ("p95 interaction latency", "< 300ms in the queue", "Speed is the brand promise made measurable"),
              ("Beta → launch NPS", "≥ 40 → ≥ 50", "Love, not tolerance — the wedge against incumbents"),
              ("CSAT (end users)", "≥ 95%", "The MSP’s clients feel the product too"),
              ("Importer-sourced logos", "≥ 30% of new accounts", "Proof the switching-cost moat is breached"),
              ("Gross logo churn", "< 3% / month by Phase 4", "Simplicity must retain, not just attract"),
              ("Suite attach rate", "> 60% use ≥ 2 of 3 apps", "The architectural advantage shows up in revenue"),
          ],
          widths=[1.8, 1.8, 2.4], size=9.5)


def build_risks(doc):
    eyebrow(doc, "Section 10")
    h1(doc, "Risks & Mitigations")
    table(doc,
          ["Risk", "Mitigation"],
          [
              ("Scope creep into PSA bloat — becoming the thing we’re replacing",
               "The complexity budget is law: every feature earns its click. Phase exit criteria are enforced. Say no in public, on a principles page."),
              ("Table-stakes gap: “simple” reads as “missing SLAs/contracts”",
               "Phase 2 is explicitly the depth phase — SLAs, time-to-invoice, portal ship before launch. Never launch against Halo without them."),
              ("Migration friction keeps MSPs on incumbents",
               "Importers are a launch feature (P3), not an afterthought; white-glove migration free during beta."),
              ("Suite dependency: Milepost/Coastmark timelines slip",
               "Safeharbor must stand alone — integration flows degrade gracefully to email/CSV; no phase gates on another product’s code."),
              ("AI costs erode margins",
               "Small models for triage/summaries, cached embeddings, per-workspace budgets; measure cost per resolved ticket from day one."),
              ("Small team, big surface area",
               "Ruthless phase discipline; hire the first support engineer at public launch, not after the fire starts."),
              ("Security/compliance expectations (MSP tools are attack surfaces)",
               "Secure-by-default from P0 (MFA, tenant isolation, audit logs); SOC 2 program starts in P4 — earlier if a design partner requires it."),
          ],
          widths=[2.6, 3.4], size=9.5)


def build_appendix(doc):
    page_break(doc)
    eyebrow(doc, "Appendix")
    h1(doc, "Sources & Method")

    para(doc,
         "Brand inputs were extracted directly from 8westit.com (markup + styles.css) on "
         "July 21, 2026 — palette tokens, typography, radii, and copy voice. Market "
         "findings were compiled the same day from the sources below; verbatim community "
         "quotes are cited via the secondary sources that quote the threads with attribution.")
    bullets(doc, [
        "8westit.com — front page, styles.css, Milepost section (brand tokens, voice, suite framing)",
        "flamingo.run — MSP PSA software comparison, HaloPSA review, SuperOps review, Syncro vs Atera, Kaseya BMS review (pricing tables, r/msp quote curation)",
        "rallied.ai — ConnectWise Manage review; “what MSPs actually pay for HaloPSA” (community quotes, Halo pricing tiers and seat minimums)",
        "syncrosecure.com/pricing, zendesk.com/pricing, freshservice.com/pricing, zoho.com/desk/pricing.html — official pricing pages",
        "mspcompared.com — Autotask review (cons, ratings)",
        "xurrent.com — MSP ticketing systems taxonomy (multi-tenant requirement, Zendesk gap)",
        "supportbench.com — helpdesk feature priorities for MSPs",
        "blakecrosley.com — Linear design-principles teardown (speed, palette, keyboard model)",
        "r/msp thread corpus via search snippets (PSA recommendations, Autotask facelift reactions, Syncro switching threads, email-vs-portal intake)",
    ])
    spacer(doc)
    para(doc,
         "Known limitations: Reddit blocked direct fetching during research, so community "
         "quotes are carried via secondary sources that reproduce them verbatim. Zoho Desk "
         "tier prices are approximate. All pricing “estimates” marked as such were not "
         "verifiable against a live vendor page and should be re-checked before external "
         "use.", italic=True, color=MUTED)
    spacer(doc, 10)
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run(p, "— 8 West IT, LLC · Safeharbor planning draft v1.1 · July 2026 —",
        size=9, italic=True, color=MUTED)


# ---------------------------------------------------------------------------
def set_base_styles(doc):
    normal = doc.styles["Normal"]
    normal.font.name = FONT
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = INK
    rpr = normal.element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:ascii"), FONT)
    rfonts.set(qn("w:hAnsi"), FONT)

    for section in doc.sections:
        section.left_margin = Inches(0.9)
        section.right_margin = Inches(0.9)
        section.top_margin = Inches(0.8)
        section.bottom_margin = Inches(0.8)
        footer = section.footer
        fp = footer.paragraphs[0]
        fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
        run(fp, "8 West IT, LLC — Confidential planning draft", size=8, color=MUTED)


def main():
    doc = Document()
    set_base_styles(doc)
    build_cover(doc)
    build_exec_summary(doc)
    build_brand(doc)
    build_naming(doc)
    build_research(doc)
    build_concept(doc)
    build_ux(doc)
    build_phases(doc)
    build_pricing(doc)
    build_metrics(doc)
    build_risks(doc)
    build_appendix(doc)
    doc.save(OUTPUT)
    print(f"Saved {OUTPUT}")


if __name__ == "__main__":
    main()
