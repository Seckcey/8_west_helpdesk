# Westy Companion presentation

The embedded Companion uses the authentic Westy horizontal logo, mascot, page
title and favicon. Regular Safeharbor browser pages retain their existing brand.
The presentation helper recognizes only the exact `/portal/desktop.php` entry
path or an existing valid 32-hex-character `desktop_companion_session` marker.
It reads that marker without writing session state or granting authentication,
customer access or computer control. A `companion=1` query alone has no effect.

The logo applies to the embedded header/sidebar and connection error/sign-in
presentation. Both the empty conversation and dynamically rendered assistant
replies use the original mascot. The existing avatar remains the fallback.
Native Windows header, executable and tray artwork are maintained separately in
[Milepost PR #642](https://github.com/Seckcey/8westit_webapp/pull/642).

## Authentic source

[8 West Ventures, LLC — 8WestWesty](https://ggitsecuritycom.sharepoint.com/sites/8WestVenturesLLC/8WVCorporateDocuments/Branding%20Assets/8WestWesty)
in the 8WV Corporate Documents library was verified through SharePoint on
October 8, 2026 Pacific. Connector downloads matched the synced source files.

| Source file | Dimensions | SHA-256 |
| --- | --- | --- |
| `westy-horizontal-logo-v1.png` | 2172 × 724, RGBA | `77495b2260a478ebdd5cc1c92bda1b6748e2df1f041e44227ad18218ff049ab4` |
| `westy-animation-poses/westy-1.png` | 512 × 512, RGBA | `86300229fb0792f20e67ca0bb3e9be7c4decb3a43c059f05ebd098256cb6df35` |

Original bytes are retained under `app/public/assets/brand/westy-companion/`.
These two files are deliberately tracked within the otherwise generated brand
directory; the existing Git release archive includes them unchanged.
No generated substitute, crop, recoloring, or background removal was used.
Existing proportional CSS sizing displays the transparent originals unchanged.

## Checks and delivery

- `php app/tests/portal_companion_branding_test.php`: 52 rendered boundary,
  unchanged-session, original-asset and escaping checks.
- Existing portal data (46), authentication (73), login (65), desktop renewal
  (32), and desktop return-path (9) checks passed in an isolated Coastline copy.
- All 17 existing `tools/shots/portal-contract.test.mjs` browser tests passed.
- Synthetic Playwright rendering verified the ordinary portal, Companion at
  1440×900, 420×640 and 360×480, and its connection error. Original logos,
  empty/reply avatars, New chat, compact navigation/Escape, visible composer,
  and clean browser console were checked. No external service was contacted.

The original incomplete-fixture regression run omitted schema files and failed
those fixture checks; the complete source copy passed without application edits.
This is source/fixture evidence, not a production or physical-device acceptance.
Normal coordinator review and CI are required before integration. A coordinated
later Safeharbor/native release is still needed; this change does not rebuild,
rebind or alter the signed Westy 1.26.11 package. There is no migration, provider
configuration change, session/authentication change or control/recovery change.
