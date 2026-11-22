# Safeharbor help catalog

The signed-in Portal guide at `/portal/guide.php` and Westy use the same reviewed
articles. Safeharbor vendors customer catalog `v0.2.0`. The Help page draws from
its 19 Safeharbor and three Westy Companion articles. Edit their Markdown files
under `articles/safeharbor/` and `articles/companion/` in the private catalog.
[Seckcey/8_west_help_catalog](https://github.com/Seckcey/8_west_help_catalog)

## Edit, approve and release an article

1. Set `status` to `draft`, record the author in `drafted_by` and set both
   `approved_by` and `approval_verdict` to `null`. Keep the catalog ID and page
   anchor stable.
   Check the steps and button names in the app as someone with the stated role.
   Record the app commit and date in `last_verified`. Use plain words and keep
   customer data out of the article. Follow the [catalog writing guide](https://github.com/Seckcey/8_west_help_catalog/blob/main/docs/WRITING.md).
2. A different approved model checks the article, records its name in
   `approved_by`, writes a short `approval_verdict` and sets `status` to
   `approved`. Only approved articles enter a release.
3. Set the catalog version in `package.json` and rebuild the indexes. Merge the
   reviewed changes to catalog `main` with its checks passing. The coordinator
   tags that commit `vX.Y.Z`, matching the package version. The release workflow
   publishes separate customer and staff archives.
4. From the root of a Safeharbor checkout, run
   `bash tools/help/sync-help-catalog.sh --tag vX.Y.Z` with the released tag.
   The script checks that the tag points to a commit on catalog `main`, verifies
   the customer archive, and rebuilds it at that commit. Both files must match
   byte for byte before it replaces the copy in `app/help/`.
5. In `app/tests/help_catalog_test.php`, set `HELP_PIN` to the printed
   `manifest sha256` and `HELP_SOURCE_COMMIT` to the printed `source commit`.
   Update the expected catalog version and article count and keep a different
   version in the manifest mismatch test. Run `php app/tests/help_catalog_test.php`
   and `php app/tests/help_drift_test.php`.
   Include both generated files and the test update in the Safeharbor pull
   request. Link the exact catalog commit so the reviewer can check what changed.

## Who sees which articles

`portal_guide_articles($context)` selects the articles for the page, search,
Westy's reads, votes and source links. It uses the signed-in identity and the
server's Companion session. A role in a URL or form cannot change the selection.
Reading an article grants no access to a computer, request or other action.

| Guide context | Full articles shown, in page order |
| --- | --- |
| Employee (`client_staff`) | Sign in, assigned computer, open and follow a request, private computer link, request status, find a request, reply, Westy chat, repair approval, then the three Companion guides |
| Viewer (`client_viewer`) | Assigned computer, follow a request, private computer link, request status, find a request, Westy chat, then the three Companion guides |
| Direct owner or admin | Open and follow a request, private computer link, give an employee access, request status, find a request, reply, weekly service summary, Westy chat, repair approval, add a computer, then the three Companion guides |
| Companion, delegated access (`customer_access`), technicians, or a caller without a recognized role | The six short articles appear first, followed by Companion guides approved for all three customer audiences |

The Companion guides cover connecting the Westy window, chatting and choosing
tool permissions, and signing out on a shared computer, in that order. In a
direct customer session, the page shows full articles for the signed-in role in
catalog order and appends the remaining short articles. A full request, update
or weekly service summary takes the place of the short article at its anchor.
Owners and admins using Companion or delegated access see the legacy selection.
The private-link article keeps its **Employee** label when owners read it as a
reference. The introduction, extra privacy details and Contact section belong
to the page. Existing links to those sections still work.

`app/help/` holds only `customer-index.json` and `help-manifest.json`. A staff
index must never go in `app/help/` or in any loader that portal code uses. Future
desk guides belong in `app/help-staff/` with a separate loader. The portal checks
the customer index's format, byte count and SHA-256 before using it. Approval
and editing notes stay out of the page and out of Westy's input. If the catalog
fails a check, the page shows “Help is unavailable right now.” The Contact
section and chat still render. The widget omits source links and the Guide
hides voting.

The pilot text hash fixture was retired with `v0.2.0`. Article approval and
catalog CI now guard the published wording. Safeharbor tests compare rendered
titles, paragraphs and ordered steps to the vendored index. A separate legacy
fixture still pins the original six short articles.

## When an on-screen label changes

`php app/tests/help_drift_test.php` checks each `ui_labels` entry in the
released Safeharbor and Companion articles against the exact, case-sensitive
text in `app/lib/portal_*.php`, `app/public/portal/*.php`,
`app/public/assets/js/portal-*.js` and `app/public/assets/js/desktop-handoff.js`.
It runs in Safeharbor CI and reports the article ID and each missing label.

When a label changes intentionally, check the new words on the affected screen,
update the article steps and `ui_labels` in the catalog, and have the article
reviewed and released. Re-sync Safeharbor with
`bash tools/help/sync-help-catalog.sh --tag vX.Y.Z` using the new tag, update
the pins, and run the catalog and drift tests with the UI change.

## Search, Westy and source links

Never log search queries from the Guide page or `search_help`. The Guide uses a
CSRF-protected POST to keep search words out of URLs and access logs. Search
checks the title, keywords, summary and body of articles the person can read.
It uses up to eight words from the first 100 characters. The page shows up to
ten matches and `search_help` returns up to five. Ties keep the page order.
A paused Westy chat can temporarily keep the help call and its result in the
private record used to resume that chat.

Westy uses two read-only tools. It calls `search_help` to find a match, then
`read_help_article` for the steps. The text is untrusted reference material and
cannot start an action. If nothing fits, Westy points the person to Support
Requests. A successful read can add a source link. A reply keeps up to five
distinct catalog IDs, and the widget looks up each title and anchor in the
person's current Guide. Search hits alone add no source.

`portal_guide.westy_tools` defaults to `true`. With help on, a round that
cannot offer search can still receive up to three matching summaries as
reference text, without adding sources. Set the switch to `false` to turn off
both help tools and that fallback. People can still open and search the Guide.

## Helpful votes

Direct employees, viewers, owners and admins can choose **Yes** or **No** on a
visible catalog article. `portal_guide_votes` keeps one vote per person scope
and catalog ID, with the catalog version. Voting again updates that row. Each
vote expires **400 days after the last vote**. Westy maintenance deletes expired
rows. Erasing a person's exact tenant, client and Westy scope deletes all votes
in that scope, including votes from someone who has never started a chat.
The controls appear once the votes table is available. [Database deployment](../deploy/README.md#database-changes)
