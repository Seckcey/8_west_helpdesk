# Staff phone layout

Proposal 18, source changes for review. This document does not establish a production release.

At 700px and below, the staff header retains its menu drawer, search, Time link and shared suite
controls. Time shows a clock icon while idle and the elapsed clock while running. A stopped, unsaved
timer retains its retry indication. Timer storage and logging are unchanged.

Queue rows show the subject and status, then client, first-response tag and owner. When the customer
answered last, "Customer replied" takes the first-response tag's place (a flagged ticket's first reply
has already gone); a card in a list wider than 480px shows both. Long subjects
stay in the link's accessible text and title; opening a ticket shows the full subject. The existing
view strip scrolls sideways and Select still reveals the checkboxes. Time entries show their title
beside duration, with tags and review actions below. The same row helpers serve client pages.

## Tablet widths

From 701 to 960px the rail stays, so the page is only 390 to 650px wide. Ticket rows (the queue and
a client's tickets) follow their list's own width rather than the window's: up to 680px they are the
phone's cards, up to 790px narrower columns, then the full columns. On the queue that means cards up
to about a 995px window; on the client page, whose ticket column sits beside the contacts, cards up to
about 1340px (the list carries `ticket-list`; a browser without container queries uses the window's width
instead: cards up to 960px). Time entries use the phone's layout up to 960px. Team rows put the person and the action
on one line, with role and access underneath, from 761 to 1100px; below that they keep the phone's
three lines, so the name has the width.
In the top bar an idle timer shows only its clock, a running one leaves out the ticket number, and the
search narrows to its key hint, then its magnifier, so nothing wraps however long the person's name.
The phone-only parts (the menu drawer, the Filters button and the ticket's Details sheet) stay at
700px and below. `desk-tablet.test.mjs` checks 701, 768, 820 and 960px.

On a phone, the summary below a ticket's title opens Details in a native modal sheet. The existing
Details column moves into that sheet; there is only one copy of its controls. Closing it restores
focus to the opener. Widening the browser restores the desktop column. Without JavaScript, Details
remains in the document as before.

The bottom Reply and Note buttons select and focus the existing composer; they do not send.
Status and Assign open the existing menus inside Details. Saving still uses the existing endpoint,
payload, CSRF value and error handling. The summary follows successful changes. The action bar
makes room while the reply box has focus. No authentication, authorization, query boundaries,
email behavior, time persistence or shared suite assets change.

## Verification

The browser checks use actual render helpers, stylesheet and app script with synthetic fixtures:

- `queue-views.test.mjs`: two-line rows, selection, filtering, keyboard behavior and phone targets.
- `desk-live-fixes.test.mjs`: idle/running/long-running timer, header fit and stacked Time entries.
- `ticket-customer.test.mjs`: Details focus, one copy of controls, resize, composer shortcuts and
  refused requests with unchanged endpoint, payload and CSRF header.
- `ticket-menus.test.mjs`: successful summary updates, status/owner changes, send menus and Undo.
- `time-page.test.mjs`, `time-contract.test.mjs`, `client-page.test.mjs`: existing behavior and
  light/dark phone layouts.

Set the existing `SAFEHARBOR_QUEUE_SHOTS`, `SAFEHARBOR_FIX_SHOTS`, `SAFEHARBOR_TICKET_SHOTS`,
`SAFEHARBOR_MENU_SHOTS`, `SAFEHARBOR_TIME_SHOTS` and `SAFEHARBOR_CLIENT_SHOTS` output directories
to capture review images. These are local synthetic checks, not customer or production acceptance.
