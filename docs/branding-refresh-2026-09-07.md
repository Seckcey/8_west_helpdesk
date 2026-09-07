# Safeharbor branding refresh

Frankie requested coordinated branding across the other 8 West IT 365 apps,
then specified the earlier cursive headline style and a fancier suite endorsement.

## Artwork

The source PNGs are in `brand/png/`; the standard deployment copies them
unchanged into `app/public/assets/brand/`:

- `safeharbor-logo-horizontal-20260907.png`: horizontal app/header artwork.
- `safeharbor-logo-square-20260907.png`: square app artwork, also used by 8 West ID.

The built-in image generation tool created both assets. The typography reference
was the connected West script in the original 8 West horizontal artwork.
The images use a midnight navy background, ivory script with blue edging,
turquoise technology details, an amber sun and the WEST/8 highway shield.
The subtitle reads exactly **8 West IT 365**, with gold numerals and ornament.
These are opaque raster assets; retain their proportions and navy frame.
The previous assets remain available. Browser favicon designs are separate.

## Final image prompts

Horizontal: Preserve the application's new emblem, colors, WEST/8 shield,
navy background and horizontal layout. Replace the headline with bold,
forward-slanting connected cursive brush script, ivory face, dimensional blue
edging and a graceful underline swash, matching the earlier West lettering.
Use the exact product name. Below it place "8 West IT 365" in elegant italic
ivory and gold lettering with 365 gold and delicate balanced gold flourishes.
Keep all lettering legible within safe margins. No extra imagery or wording.

Square: Preserve the matching square emblem and stacked arrangement. Apply
the horizontal version's exact cursive headline and decorative endorsement.
Rebalance lettering only as needed for the square; keep the emblem prominent,
with no overlap between the emblem, headline and endorsement.

## Application integration

The artwork replaces product branding in the existing application layouts.
Responsive CSS preserves image proportions and trims only outer navy space
where a short header requires it. Accessible image/link names identify the app.
No product permissions, customer records or workflow behavior change.
Production acceptance follows below.

## Production acceptance — 2026-09-07 UTC

Frankie's suite branding and cursive typography requests authorized implementation
and release. [PR #110](https://github.com/Seckcey/8_west_helpdesk/pull/110) merged
as `56fced51319875e60041cf51655fde5208981a58`. [Exact default-branch CI](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34089879825) passed before deployment.

The standard canonical-LF deployment recorded deployed artifact `7ed18347efe8234a926c55b3aa922cf58298cf14f0099f51bbc63c55a31d76f7`. The protected config was unchanged. Database backup SHA-256: `354b6c62c35a481d1b80665cae3cb86bee21d15a3010265bb3d1e6cefdd56bfd`. The existing Lifestyle weekly report schedule was rebound to this artifact and verified active at `2026-09-07T06:20:54Z`, preserving the recipient, schedule, report code and original delivery evidence. No report was sent during acceptance.

Protected rollback/backup: `/srv/8west/backups/safeharbor/20260907T061610Z-pre-suite-branding`.

ID tile opened the signed-in Queue. Desktop sidebar and 390 x 844 phone menu displayed the complete cursive logo. Opening and closing the phone menu worked; no console errors were observed.

The live public PNG responses matched the committed files byte for byte.
Artwork review covered lettering, margins and header fit. This was branding
acceptance, not a full regression of unrelated business workflows.
