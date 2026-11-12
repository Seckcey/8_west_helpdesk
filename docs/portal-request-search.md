# Find a support request

On **Support Requests**, search for words in the subject or a request number (with or
without `#`). Select a status and choose **Apply filters**. The search covers the
authorized request list, including older requests beyond the first 50; it does not
search conversations or internal notes. `%`, `_` and `!` are literal search characters.

**Previous** and **Next** keep the search and status. Applying or clearing filters
starts at the first page. Each page contains at most 50 requests and states its
visible range. Requests retain the existing order: waiting, in progress, received,
then resolved; within a status, newest customer-visible activity first, with request
number breaking ties. Internal notes and staff-only changes do not move a request.

Members and viewers still see only their attributed requests. Owners and admins
retain the client-wide view. The same tenant, client, requester-identity and
merged-ticket exclusions apply to both the rows and counts. The Home overview keeps
its existing summary; **View support requests** opens the searchable list.

## Implementation and validation

`portal_ticket_summary()` accepts an optional final list-options argument. Existing
callers without it retain their original return shape and limit behavior. The
requests controller supplies validated `q`, `status` and `page` values. Search/status
parameters and integer limit/offset values are bound; page size is capped at 50.
Malformed inputs return a 400 response; a valid page beyond the last result clamps
to that last page (or page 1 for an empty result).

The visibility baseline test was committed and passed against the unchanged main
application before implementation; it remains unchanged. The shared paging cases
run on SQLite and the existing disposable MySQL fixture. Browser checks cover
filtering, paging, escaped text, an empty result, viewer behavior without JavaScript,
focus, 44px controls and light/dark desktop/phone captures. No real account, email,
request creation, schema, authentication or permission change is involved.
