# Westy input-count repair — October 11, 2026

MAIN's October 11 execution receipt records R1/R2 installed at source
`5d7e6ade7ca9645b37fb68ea799c529b71b333d8`, with original business-report
scheduling restored. Real provider/Cua and Frankie's signed-in browser/Notepad
acceptance remain **Not tested**. MAIN owns the release and tracker.

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

MAIN's exact-head PHP 8.3 checks passed: 48 catalog/input-count checks, 178
desktop/Cua continuation assertions, and lint on both changed production PHP
files and both changed test files. PR #216's exact-head Validate `38181718102`
passed all four jobs. Default Validate `38182054671` attempt 2 passed at the
merged source above. Attempt 1's unchanged service-goal concurrency-observation
assertion failed; only the failed PHP job was retried once. No source/test/CI
change, skip or bypass was used, and the root cause is not asserted. These
synthetic checks do not establish live provider or customer acceptance.

## Release and acceptance

MAIN installed the exact reviewed archive through the unchanged receiver with
exit 0, advancing runtime `55211b9` to `5d7e6ad`. The new manager's
install-disabled, enable and actual verify-active steps succeeded. MAIN reports
that all three production-file hashes match the reviewed source. The original
552 capture and unused retained 497 inverse remain preserved.

| Execution binding | SHA-256 / value |
| --- | --- |
| Installed source | `5d7e6ade7ca9645b37fb68ea799c529b71b333d8` |
| Clean source artifact | `02979eda0a232ffbf6ffc30d53f84a8c7171cc2fe6200c45ca60a7afc81b3369` |
| Actual deployed artifact | `7e8108fbad1efff3f27eef075add680e24af0a9ba38c62552727d2ede686f753` |
| Actual current release record | `e246b87a37a884ca6208e2caf834d97d974467d21df0f285c117d87d6d64c22e` |
| Original 552 capture | `c24c45ddf9796a5c80cfdaece5989ee94171d2dee354ee1d0354452f591106e0` |
| New report bundle | `7430b614c5915c77d3e7b66acf526e945516eb477a910d2d6a0835239ec245a2` |
| New report activation | `c8ef52465b0645a994d3b735c8e670f1d107c6179fc44304503abfcb62defc8d` |
| Unchanged protected config | `cfeed7737ca4c1ee2e59a7d36c69209650b5740d23ff3c8b8df15dcc2fd27601` |
| Unchanged report cron | `0ccf6eb37945c35b30cd62fa752ca5ada155a88c12644cf517950c720b4068b6` |
| Genuine protected-gate review | `2026-10-11T23:27:10Z` (4:27:10 PM Pacific) |

All other original activation fields and approval/provider clocks were
identical; only the five reviewed release/artifact/bundle/protected-review
bindings changed. This release changed no schema, keys or protected config.
MAIN explicitly unlocked and closed its app FD 4 and suite FD 3 at
`2026-10-11T23:30:00Z` (4:30 PM Pacific); session `71332` exited 0 and existing
mutex inode files were retained. This does not establish future foreign-lock
availability. The source/docs freeze ended with MAIN's window closeout.

The saved `receiver-result.json` and `actual-runtime-receipt.json` in the
`safeharbor-r1r2-5d7e6ade-execution-01a12734` evidence folder support these
release-owner receipts. The coordinator checked those saved records; it did
not independently rerun SSH or a provider/device workflow. The site's TLS HTTP
302 anonymous entry establishes availability only.

Real provider/Cua and Frankie's signed-in browser/Notepad workflow remain the
acceptance gate; deployment and report restoration do not satisfy that gate.
An input-limit message is a refusal to continue that reply; any previously
dispatched device work retains its own recorded outcome and Stop status.
