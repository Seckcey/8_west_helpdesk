# Customer portal artwork and usability review — October 10, 2026

Source candidate based on main `2d0ab38be8264d646a1407f1c41c36f6ab333096`,
after MAIN integrated the separately owned Cua PR #211.
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
before. Staff branding in `app/lib/render.php` is unchanged. The two narrowly
owned consumer seams below preserve existing identity, account access,
provider selection, device operation and approval rules.

## Accepted usability items reviewed

| Item | Current evidence and disposition |
| --- | --- |
| MP-PRE-008, model catalog | The reviewed catalog is already in `app/lib/tenant_ai/tenant_ai_contract.php`, with offline coverage in `app/tests/tenant_ai_catalog_test.php`. No catalog rebuild or selection change. |
| MP-PRE-009, tenant administrator settings | `docs/TENANT_AI.md` assigns provider/model/effort management to the MSP owner or administrator in 8 West ID. Safeharbor consumes the signed active selection. No duplicate customer model selector. |
| MP-PRE-010, compact footer | PR #198 changed two existing `portal-workspace.css` sections. Main already contains the compact composer, footer, status and computer-time spacing, including mobile styling. No further style gap was found in the inspected renders. |
| MP-PRE-012, feedback | PR #191's Copy, Helpful, Not helpful and retry controls remain. This candidate adds a bounded metadata review API over the same immutable events for the existing Milepost hub's separately owned filters/export. Capture, fingerprints, history, parent expiry and exact erasure remain as documented in `docs/portal-westy-reply-feedback.md`. No automatic training or private conversation text export. |
| MP-PRE-013, Westy hub | The accepted hub is the Milepost operator hub, owned by Milepost. It does not request a new Safeharbor customer hub or running-version UI. No Safeharbor change. |

Frozen Cua integration files and `docs/where-things-stand.md` are outside the
worker's delta. No schema, authentication, dispatch, Stop or native package
changes are made by this candidate.

## Authenticated local computer default

The existing startup device read captures only the server session's native
pair marker, then proves its current local binding, current signed Milepost
state, exact pair, expiry and opaque `device_reference`. The reference must
appear exactly once in the current permitted device picker with a reporting
connection; the native connection must also be present. A paired, executing
or stopped task can identify the same current computer. Selection does not
start work or reinterpret Stop as a ban on future authorized work.

The initial Companion selection uses that exact reference once. An explicit
other selection or an explicit blank survives refresh. An unlisted, offline,
expired, revoked or mismatched pair does not select another computer. Missing
reference metadata from an older producer is unavailable; ordinary browser
visits retain their existing one-computer convenience. Startup reauthorization
also checks that the pair has not changed during the unlocked read.

The producer is Milepost PR #695, initially reviewed at
`a600d2c62e5097f85f51bc2ee719b832d710cc87`, after its Cua PR #694. It adds the
picker-compatible reference to authenticated bind/state receipts using the
existing current customer binding and HMAC. Its Stop projection is unchanged.
The published head `06e8e45ca19c4962446b8e03b5ada4096f93a10c` adds documentation;
the reviewed PHP delta is unchanged.
This source evidence does not prove an installed Companion or remote-control
employee transport.

## Operator feedback metadata contract

`POST https://safeharbor.8westit.com/api/westy_feedback_review.php` accepts the
original verified suite JWT only in `Authorization: Bearer`. It uses existing
Safeharbor issuer/token-type, entitlement, role, configured MFA and revocation
admission policy, and requires the exact active local immutable-subject user
in the signed tenant. It creates no user or session and has no generic service
identity, extra secret or privilege fallback.

Before querying and again before returning, Safeharbor calls only
`https://support.8westit.com/westy_feedback_scope.php` with that original token,
TLS verification and no redirects. The paired `westy-feedback-scope-v1` reply
must identify the same current operator and tenant and at most 10,000 current
customer UUIDs (512 KiB). Safeharbor intersects those actual visible UUIDs
with its active customer-sync bindings, active portal bindings and existing
managed-customer operational boundary. A missing or invalid callback cannot
fall back to all tenant data. Fresh local actor/feed, scope, lifecycle, parent
retention and erasure checks precede output; changed authority returns an
unavailable/conflict response rather than stale results.

The closed `westy-feedback-review-v1` request has `action` (`list`, the default,
or `export`), `filters`, `sort`, `page` and `page_size`. Filters are `model`,
`model_version` (empty or `unknown`), `task` (empty or `saved_portal_turn`),
`outcome`, `reaction`, `from` and `to`. Dates are inclusive UTC calendar days
of the latest reaction. Sorts are `date_desc`, `date_asc`, `model_asc`,
`reaction_asc`, `task_asc` and `outcome_asc`, with a stable ID tie-break.
List pages are at most 50 rows; export requires page 1 and at most 1,000 rows.
The export selects once with one extra row to establish `has_more`; it does
not stitch changing reactions across network pages. Both return `row_count`
for the actual items and `truncated` equal to `has_more`, with an 8 MiB source
response ceiling. Milepost owns the UI, pre-download cap notice, formula-safe
CSV, 4 MiB CSV ceiling, actual count and narrow-filter/continuation guidance.

Each item identifies the latest immutable revision, including a cleared
`none` reaction, response and retained conversation/operation references,
customer UUID, ISO UTC timestamps and recorded generating provider/model,
catalog and AI revision. No current catalog is substituted. The precise
provider build remains `null`, as the original receipts do not store it.
Task/outcome come from the stored saved-turn context. A short metadata summary
is available; private prompts, replies, tool output, screenshots and tokens
are omitted. `context_available` is false and
`context_unavailable_reason` is `no_existing_conversation_access`: no exact
current conversation access was established for this review. Actor equality
alone does not establish that access. `context_redacted` and
`context_truncated` remain false; no new sanitizer or captured context is
claimed. Existing immutable capture and parent erasure remain unchanged.

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
- Existing startup browser cases passed for unlocked metadata/reauthorization,
  connection recovery and exact local defaults, including explicit blank and
  another-computer selection surviving refresh.
- Existing MySQL handoff/renewal fixture passed 35 handoff checks and 84
  cumulative renewal assertions. It verifies local pair/reference,
  disconnect, expiry, revoked state, older producer, ordinary visit and backend
  refusal alongside its existing renewal/revocation checks.
- Existing MySQL feedback fixture passed 146 cumulative checks. It verifies
  actual RS256 signatures, token
  type/issuer/role/tenant/product/version, active operator, MFA/feed/revocation,
  callback identity/scope and changes during reads, latest clear/history,
  filters/order/pages/export limits, safe metadata projection, disabled or
  inactive customer boundaries, parent expiry and exact erasure. Synthetic
  source wire can be captured with `SAFEHARBOR_FEEDBACK_REVIEW_EVIDENCE` for
  direct paired-consumer validation. No private source text enters that wire.

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
