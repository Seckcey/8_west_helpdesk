# Customer portal artwork and usability review — October 10, 2026

Source candidate based on main `daf73feb6fa75cdf1222207b4d18d596a693aa49`.
This record establishes source and synthetic rendering evidence. Merge,
deployment and customer acceptance remain separate release steps.

## Customer artwork

The ordinary customer portal uses Frankie's supplied `8-west-it-westy.png`
in its sign-in page, page header, desktop workspace sidebar and error pages.
The artwork depicts the 8 West IT mark with Westy; its alternative text is
`8 West IT with Westy`. Safeharbor page titles, home-link names and product
identity remain accurate. The supplied PNG is copied unchanged:

- Dimensions: 2172 × 724 pixels.
- Size: 1,504,642 bytes.
- SHA-256: `159c7a3946bfbd80091bb061ed95139f4fad29ba7914faea726aeaea6b6d050d`.
- Runtime path: `app/public/assets/brand/8-west-it-westy.png`.

That file is intentionally tracked despite the generated-brand directory's
ignore rule, following the existing tracked Companion artwork convention.
`deploy/deploy.sh` archives the committed `app` and `brand` trees; its derived
brand copy adds existing Safeharbor assets without removing this new PNG.
The release must include the committed artwork, not an untracked local copy.

Existing responsive sizing preserves the supplied aspect ratio without a CSS
change. The compact mobile workspace already hides its horizontal artwork;
its existing header, navigation and composer layout are retained. Desktop
workspace and ordinary desktop/mobile customer headers show the new artwork.
Companion-specific artwork, favicon, avatar and session selection remain as
before. Staff branding in `app/lib/render.php` is unchanged. No customer/MSP
identity, configuration, access, model selection, provider, approval or device
operation changes are included.

## Accepted usability items reviewed

| Item | Current evidence and disposition |
| --- | --- |
| MP-PRE-008, model catalog | The reviewed catalog is already in `app/lib/tenant_ai/tenant_ai_contract.php`, with offline coverage in `app/tests/tenant_ai_catalog_test.php`. No catalog rebuild or selection change. |
| MP-PRE-009, tenant administrator settings | `docs/TENANT_AI.md` assigns provider/model/effort management to the MSP owner or administrator in 8 West ID. Safeharbor consumes the signed active selection. No duplicate customer model selector. |
| MP-PRE-010, compact footer | PR #198 changed two existing `portal-workspace.css` sections. Main already contains the compact composer, footer, status and computer-time spacing, including mobile styling. No further style gap was found in the inspected renders. |
| MP-PRE-012, feedback | PR #191's Copy, Helpful, Not helpful and retry controls are present in `portal-westy.js`, with immutable scoped feedback in `portal_westy_feedback.php`. `docs/portal-westy-reply-feedback.md` retains private context, parent expiry and exact erasure. The accepted operator filtering/export criteria remain unfinished; the existing Milepost hub owns that review UI, and the necessary Safeharbor data/capture seam is pending an agreed contract. No automatic training is authorized. |
| MP-PRE-013, Westy hub | The accepted hub is the Milepost operator hub, owned by Milepost. It does not request a new Safeharbor customer hub or running-version UI. No Safeharbor change. |

The Milepost employee/local-default implementation remains separately owned;
its necessary Safeharbor consumer seam is pending an agreed contract in this
same worker assignment. Shared portal JavaScript is unchanged by this patch.
Frozen Cua integration files and
`docs/where-things-stand.md` are outside this change.

## Validation and handoff

- PHP lint passed for the affected shell and existing branding fixture.
- `php app/tests/portal_companion_branding_test.php`: 59 checks passed,
  including unchanged Companion/session boundaries, ordinary login rendering,
  accurate image attributes and the exact supplied PNG digest.
- The existing browser contract's `customer portal makes support work and
  verified weekly archives easy to use` case passed in an isolated Coastline
  container. It renders actual PHP with synthetic identity/data and checks
  image loading, aspect ratio, alternative text, layout overflow and browser
  console output at 1365 × 850 and 390 × 844.
- Six screenshots were captured and visually read: `login-1365.png`,
  `login-390.png`, `customer-1365.png`, `customer-390.png`,
  `workspace-1365.png` and `workspace-390.png`. The logo fits on the applicable
  surfaces; the compact mobile workspace retains its existing hidden artwork.

Screenshots are outside the source tree in this worker's `portal-branding`
artifact directory. Reproduce them with `PORTAL_BRANDING_EVIDENCE` set to an
output directory and:

```sh
node --test --test-name-pattern='customer portal makes' tools/shots/portal-contract.test.mjs
```

All browser receipts are synthetic. They do not prove production sign-in,
provider execution, package signing, endpoint updates or customer acceptance.
The Safeharbor coordinator reviews and integrates this PR; MAIN retains the
combined release and signing handoff. Frankie owns post-release customer testing.
