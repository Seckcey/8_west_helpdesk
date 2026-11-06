# Portal Guide pilot — prepublication integration

This candidate integrates the six approved articles into `/portal/guide.php` and
the current Westy instructions. It is draft source work, not a published Guide.
Publication remains held for the already-required real signed-in Client
Owner/Admin and employee acceptance, including private-link redemption,
assignment and revocation. Synthetic tests do not establish that acceptance.

## Content and applicability

The immutable approved input is
`safeharbor-kb-pilot-final-v3-astra-max-20261105.md`, SHA-256
`12450997caf825a4bee2d26e61636b7cd01f086a80a678021ec8fa662fff2e68`.
The source/application basis is `754c92d06a8fc905a7ed0d11ae9b295ccef90821`;
the integration starts from documentation-only main
`089178bb8312d058b6944dc58d11bcae08dfa3c0`. No customer article prose is rewritten.
Only approved titles, audience labels, purpose/prerequisites, steps, expected
results and help paragraphs enter the shared structured catalog. Authorship,
source, release and publication notes remain internal to this document and the
review packet, outside the Guide and provider instructions.

| Authenticated context | Approved articles in order |
| --- | --- |
| Direct employee/member (`client_staff`) | E1 sign-in, E2 assigned computers, E3 request, E4 follow/reply, E5 private-link redemption |
| Direct viewer (`client_viewer`) | E2, E4, E5 |
| Original native `client_owner` or `client_admin`, no `customer_access` overlay | E3, E4, E5, O1 issue/manual-share link |
| Delegated customer access or Companion session | Existing Guide |
| No context (legacy guidance/parser callers) | Existing Guide |

The original article author confirmed E5 may be read by native owners as an
employee reference. Its **Employee** heading and full prerequisites remain
unchanged, and it appears above O1 so O1's existing cross-reference is accurate.
This does not make owners eligible to redeem employee links. Existing action
authorization is unchanged. E3 expressly includes owners/admins; E4 explains
their client-wide request visibility.

`portal_guide_articles($context)` selects help from the authenticated original
identity and server Companion state. URL/query/body role claims are ignored.
This selection grants no permissions and adds no action guard. The Guide and
active Westy instruction builders receive the same article IDs and order,
including the initial request-size calculation and each provider round.
`portal_guide_westy_articles()` keeps the existing concise explanations and
adds only exact pilot titles, audience/application labels and Guide anchors.
The expanded approved bodies remain on the Guide page; serializing them into
every model request would consume too much of the existing byte allowance.
No new summary prose is authored. A captured Companion
context remains on the existing Guide even if the current session marker is
absent. No model, tool, billing, recovery or transport behavior is changed.

## Existing routes and compatibility

The existing account and Support Requests Guide links are reused. There is no
new navigation entry, search service, framework, database or feature flag.
The `devices`, `requests`, `updates`, `response`, `summaries`, `privacy` and
`contact` anchors remain. E2 has its own `assigned-computers` anchor so the
existing computer-check diagnostic material is retained. E3/E4 replace the
corresponding short entries only for their applicable audiences. Other legacy
service, privacy and contact content remains unchanged.

The legacy closed guidance body/parser has no active request caller. Its
context-free Guide call intentionally keeps the existing source-ID enum and
stored-draft parser compatibility; it does not gain pilot owner instructions.
The active tenant-AI request path passes its authenticated context explicitly.
The older streaming body builder accepts optional context with the same
legacy default. No legacy transport modernization is included.

## Verification and publication handoff

`app/tests/portal_guide_test.php` reconstructs approved Markdown from structured
paragraphs/ordered steps and checks exact title/body SHA-256 values extracted
from the immutable input. It also covers audience selection, escaped markup,
metadata exclusion, unchanged legacy sections/source IDs, current instruction
builders and ordinary request size with real tool definitions and history.
The existing MySQL Westy test checks the authenticated owner selection at the
actual injected provider boundary, without calling a paid provider.

`tools/shots/portal-guide.test.mjs` executes the actual Guide controller and
real portal renderers with synthetic authentication. It covers employee,
viewer, owner, admin, delegated, overlaid owner and Companion contexts at
1440×1000 and 390×844, request-to-Guide/contact paths, legacy anchors, role-query
tampering, GET-only/anonymous entry, unchanged navigation, overflow and console
health. All network responses are fixture-controlled.

Validation results and screenshot locations are supplied with the draft PR.
Container verification uses only an isolated, temporary Coastline kit under
the canonical slot and existing heavy mutex; no Docker Desktop or production
browser is used. No real account, invitation, ticket, mail, private link or
device operation is performed.

The current internal suite record supersedes the older release document's
open origin/Cloudflare status: configured POST-body review is complete for the
inspected paths. Actual redemption and signed-in owner/employee acceptance
remain separate and pending. Apache PR #290 and other held work are unchanged.
The draft must not be treated as publication or as evidence that these checks
passed. Invitation-label alignment is a separate change; any resulting
customer article wording update belongs to the original article author.
