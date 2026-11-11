# Saved replies

Open **Saved replies** from the palette, or **Manage saved replies** in a ticket's Saved replies menu.
The library lists each title, the first line and its author. The page's **New saved reply** button opens
one form below the library. **Edit** opens that form with the selected reply; **Cancel** saves nothing.

The preview fills the four supported fields with sample values. Field buttons insert at the cursor,
replacing selected text. Preview text is never sent. A ticket fills those fields with its own current
values only when a reply is inserted into the composer; insertion does not send the ticket reply.
Unknown fields remain literal text. Saved replies remain plain text.

Owners and admins can edit or delete any saved reply in their own workspace. Other signed-in users
can edit or delete only replies they created there. The edit query rechecks that boundary on the
server and never changes the author. Everyone's existing ability to view and add workspace replies
is unchanged. The existing add/delete handlers and CSRF checks are preserved; edit uses the same
`/snippets.php` form endpoint and CSRF check. Direct new/edit links work without JavaScript.

## Verification

- `php app/tests/saved_replies_test.php` executes the actual controller and SQL against throwaway
  in-memory rows: role/author/workspace boundaries, forged IDs, CSRF, validation, unchanged authors,
  add/delete behavior, escaping, and server-rendered direct/error states.
- `node tools/shots/saved-replies.test.mjs` checks the real controller markup and app assets in
  light/dark at 1440/390, editing and insertion, literal preview text, exact form bodies, cancellation,
  direct links without JavaScript, restricted/empty/error states, and the composer management link.
- Existing destructive-confirm and palette-search tests keep the deletion and navigation contracts.
- Set `SAVED_REPLIES_CAPTURE_DIR` to an existing directory for synthetic HTML captures. Set
  `SAVED_REPLIES_SHOTS` for browser screenshots. No live account, mail or database is used.

Static library and editor previews are `tools/shots/preview/saved-replies.html` and
`saved-reply-edit.html`. They load real stylesheets, use made-up data and have no submitting forms.
