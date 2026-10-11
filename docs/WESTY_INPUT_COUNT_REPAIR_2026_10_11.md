# Westy input-count repair — October 11, 2026

R1/R2 are a source candidate. MAIN owns the grouped release and tracker;
deployment and Frankie's real browser/Notepad acceptance remain pending.

## Customer behavior

A large text message or returned tool catalog can exceed Westy's conservative
byte estimate while still fitting the selected model's actual input limit. R1
asks that provider's existing token-count endpoint when this text-only estimate
exceeds the effective configured/model limit. Small text keeps its existing
fast path. Images and documents keep their existing provider-counted path.

The actual count must still fit the same limit, including the existing
2,048-token or five-percent margin, whichever is larger. Counter failures and
invalid counts retain their existing checks. The 12 MiB request-body cap,
catalog, credentials, model selection and genuine budget are unchanged.

R2 preserves the known adapter `RuntimeException('input_limit')` reason in the
portal loop. Its customer message is: “This request grew too large for one
reply. Saved device work is shown below; it has not been retried.” Other generic
failures and their exception text remain masked. Saved paid receipts, usage,
in-flight accounting, discarded provisional output, Stop, device ownership,
selection and replay behavior are unchanged. The repair does not resubmit an
uncertain command or confirm that a computer action completed.

The reported Cua tool-list-then-stop behavior motivated these fixes. The exact
failing request size was not retained, so source correctness and synthetic
tests do not establish that this was the live failure's cause or that the
customer path now succeeds.

## Source provenance and validation

The three production files are `app/lib/tenant_ai/tenant_ai_provider.php`,
`app/lib/portal_westy_tenant_ai.php` and the single error-label addition in
`app/public/assets/js/portal-westy.js`. Existing regression cases live in
`app/tests/tenant_ai_catalog_test.php` and
`app/tests/portal_westy_cua_cases.php`; the latter runs through the existing
continuation fixture. No driver, framework, helper or CI registration is added.

The [vendor manifest](tenant-ai-vendor-manifest.json) records the provider patch
separately as `provider_input_count_patch`, ID
`westy-input-count-2026-10-11.1`. Its canonical-LF SHA-256 is
`19ac3e43d96ef2fe011de556a3f06fdd9ba15b683a016e2472884e31c0e5be0a`.
The original upstream provider hash remains
`db55a1fdd400698b709c498ff5e497189ca609e9e3c88f011afc396925083268`.
The upstream commit, credential catalog, entire `upstream_files` map and
existing contract-patch metadata remain unchanged. The existing catalog test
checks every adapter's actual canonical-LF bytes, pins both reviewed patch
hashes and requires every other adapter to equal its original upstream bytes.

MAIN runs the normal existing PHP 8.3 catalog, continuation and lint checks at
the final grouped source/documentation head. Normal PR review, required CI and
Safeharbor integration gates apply. A temporary runner that bypasses the
provenance checks is not validation.

## Release and acceptance

MAIN must account for the exact candidate versus the current live release and
use the established report-preserving deployment procedure and retained
rollback. This source repair requires no schema, key, configuration, package,
device or signing change. No real provider request or device acceptance is
claimed by this source preparation.

After the release owner verifies the installed files and reporting state,
Frankie's real signed-in browser/Notepad workflow remains the acceptance gate.
An input-limit message is a refusal to continue that reply; any previously
dispatched device work retains its own recorded outcome and Stop status.
