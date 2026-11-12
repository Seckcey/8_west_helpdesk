# Weekly service summaries

Client owners and admins can open **Support Requests → Weekly service summaries**.
The list states its existing limit of up to 24 summaries, newest first. Each card
leads with its reporting period and saved request totals.

The detail page shows requests opened and resolved, average first reply, response
goal attainment, approved service time and customer satisfaction. Missing response
metrics say **Not available**; measured zero remains `0 min` or `0%`. Approved time
remains a service fact, not an invoice or billing status.

Creation time uses the portal's existing friendly-time helper and the viewer's
existing regional preferences. The reporting period continues to use the saved
schedule timezone. The previous creation-time formatter already respected viewer
preferences; this change only uses its friendlier presentation.

**Read the exact archived summary** keeps the saved text available unchanged.
**Archive details** is closed initially and exposes the definition version, full
creation timestamp and SHA-256 fingerprint when opened. Archive validation, the
owner/admin access gate, query limits, metrics, storage and billing behavior are
unchanged.

## Validation and review

```text
php app/tests/portal_report_archive_test.php
php app/tests/portal_summary_display_test.php
node tools/shots/portal-summary-display.test.mjs
node tools/shots/portal-contract.test.mjs
```

The existing archive suite retains its tamper and privacy checks; only four label
assertions change. The display suite distinguishes missing data from zero, checks
exact text escaping, saved totals, local creation time and reporting timezone.
The browser suite checks light/dark desktop and phone layouts, closed disclosures,
keyboard opening, long fingerprint wrapping and absence of page errors or writes.
Set `PORTAL_SUMMARY_SHOTS` to capture its 24 images; CI publishes them as
`portal-summary-gallery`.

Run `node tools/shots/preview/build-portal-summaries.mjs` to regenerate the inert
synthetic `portal-summary-list.html` and `portal-summary-detail.html` previews.
They use the real renderer and stylesheets, with no form submissions or real data.
