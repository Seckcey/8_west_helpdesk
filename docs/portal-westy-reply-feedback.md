# Private Westy reply actions

Next-release source candidate. This contract does not claim a production
migration, deployment, signed Companion package, or physical acceptance.

The customer Westy workspace adds **Copy**, **Helpful** and **Not helpful** to
saved replies. Copy puts the reply's plain text on the user's clipboard. It
tries the supported browser clipboard, then a local copy fallback, and finally
offers selected, read-only text for manual copying when the browser refuses
both. Copy makes no API request and stores no new context.

A thumb saves the authenticated user's reaction and the exact saved prompt and
response privately in Safeharbor. Selecting the active thumb clears the current
reaction; previous reactions remain in the history until conversation expiry
or exact user erasure. There is no additional confirmation dialog. Buttons have
accessible names, pressed state, keyboard behavior and a visible save result.

This is independent of staff `api/westy_feedback.php` and
`api/westy_thumbs_up.php`. It opens no ticket, sends no email, calls no AI
provider, exports no chat and authorizes no training or fine-tuning. Existing
staff thumbs-up still stores no chat text and creates no ticket.

## Identity and evidence

`portal_westy_turns` is the authority for prompt and reply content. The browser
sends only `conversation`, `operation`, `response_id`, `request_id`, `revision`
and `reaction` to the new POST-only `/portal/westy_feedback.php`. Current portal
authentication, CSRF and the same live customer binding check apply. The
tenant/client/binding and user-grant scope are derived server-side; the browser
cannot submit prompt text, provider metadata, tenant IDs or another actor.

`response_id` is a SHA-256 fingerprint of a versioned snapshot containing the
turn, conversation and operation IDs, saved prompt, saved reply including its
existing sources/tool receipts, turn state/reason and recorded model selection
receipts. The hash identifies a saved response revision, not a new permission.
Any retained conversation owned by the current user may be rated, even when a
different tab has selected another conversation. A resumed turn with changed
reply text or attempt provenance receives a different response identity; earlier
feedback keeps its immutable snapshot. Pending output cannot be rated. Saved
interrupted output can be rated without claiming that the task succeeded.

Generation provenance comes from `portal_westy_ai_attempts`, including provider,
selected model, catalog version, tenant AI revision, sequence, state and original
UTC receipt times. It never comes from current account settings. Existing
receipts do not record the provider's precise model build, so
`provider_model_version` remains null. Historical turns without attempt receipts
retain only their recorded model and an empty attempts list. The snapshot is
labeled `saved_portal_turn`; `provider_request_snapshot` remains null. This
reproduces the saved prompt-response pairing, not an unrecorded full provider
request or a guarantee of deterministic model output. No credentials, tokens or
credential values enter this table.

The append-only `portal_westy_reply_feedback` table stores the authenticated
subject and scope, stable conversation/operation/response identifiers, request
key, monotonically increasing revision, `up`/`down`/`none`, original context,
UTC reaction time and parent expiry. Indexes support scoped review by
tenant/client/user, reaction and time. No new review dashboard, general transcript
viewer or export route is introduced.

The writer locks the customer authority, account and exact turn in the existing
order, then compares the current response identity and reaction revision. A
duplicate request key with the identical payload acknowledges the current saved
reaction without adding an event. Reusing a key for another payload or sending a
stale revision returns `feedback_changed` (409). The browser preserves the exact
request key when an acknowledgment is lost; Retry feedback is idempotent. It
refreshes changed evidence before allowing a new choice and clears private UI
when sign-in or authority is lost.

## Retention and next-release migration

`app/db/migrations/portal_westy_reply_feedback_v1.sql` adds one table and three
triggers; the canonical fresh-install schema includes the same block. Prerequisites
are migration 031 and `20261004_tenant_ai.sql`. Existing turns, AI receipts and
staff feedback are unchanged. The table rejects edits, mismatched turn ownership
and deletion before expiry unless the parent context has been explicitly erased.

The new feedback copies have exactly the parent turn's expiry. Current inherited
portal retention remains authoritative; the feature introduces no longer-lived
private-chat archive. Existing `portal_westy_maintain` dry runs count feedback,
and applied scheduled expiry or exact-scope erasure removes it after clearing
the parent text and before deleting old turn metadata. Runtime permissions need
SELECT and INSERT, plus DELETE on this one table for that existing maintenance
path. UPDATE is rejected by its immutable trigger. Do not grant a general
cross-tenant review or export permission.

Apply the exact merged migration before matching code using Safeharbor's
protected release procedure, verified backup/restore and Safeharbor-only write
freeze. This worker has no live migration or deployment authority. The ordinary
code rollback keeps the additive table and its evidence; before leaving rolled
back code in service, retain the new maintenance routine or otherwise ensure
feedback expiry and exact erasure continue. Do not drop private feedback as an
unreviewed rollback shortcut. The current frozen installer is unaffected.

## Validation

`app/tests/portal_westy_feedback_mysql_test.php` uses an explicitly disposable
MySQL server and a random per-run database, removed in `finally`. It exercises
the real authority checks, saved linkage, generation metadata, replay, stale
revision conflicts, continuations, immutable evidence and exact erasure. Real
competing processes verify different-choice and identical-key races; a separate
binding-revocation race proves an actor cannot use a stale transaction snapshot
after waiting for the authority lock.
`tools/shots/portal-reply-actions.test.mjs` runs the actual PHP workspace renderer
and browser script against synthetic same-origin receipts, testing clipboard,
fallback, manual copy, thumbs, keyboard, reload and lost acknowledgments on
desktop and mobile. These tests make no provider or production requests.

Run the focused suites:

```sh
SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER=1 \
SAFEHARBOR_WESTY_TEST_DB=safeharbor_westy_test \
SAFEHARBOR_WESTY_TEST_PASS=<disposable-only> \
php app/tests/portal_westy_feedback_mysql_test.php
node tools/shots/portal-reply-actions.test.mjs
```

Container verification belongs on Coastline in the task's isolated namespace,
never Docker Desktop. Synthetic browser proof is distinct from production or
physical Companion clipboard acceptance.

The source candidate passed 57 focused MySQL checks, the existing 92-check
private workspace MySQL suite, both desktop/mobile reply-action browser
scenarios and all 17 existing portal browser scenarios. PHP lint passed for
283 files; portal data (46), Companion branding (52), portal authentication
(73), AI receipts (21), CI selection (15), technician-time MySQL (98), business
reports (243) and the integrated model catalog (37) checks passed. The technician-time
race proof requires MySQL performance schema enabled. Browser tests used
the real renderer and JavaScript at 1440×900 and 390×844. The Browser plugin
was unavailable, so the existing Playwright tooling was used. The focused
browser fixture deliberately simulates clipboard refusal, a lost feedback
acknowledgment, a stale revision and sign-out; no unexpected console errors or
external requests occurred. Final GitHub checks and integration remain the app
coordinator's normal gate.
