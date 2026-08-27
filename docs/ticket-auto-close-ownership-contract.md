# Ticket automatic-closure ownership

**Status:** release candidate on `codex/guard-alert-auto-close`; migration 018
and matching code are not live until the migration-first production gate is
completed.

## The rule

AI is advisory. Safeharbor never lets Westy resolve a ticket. A ticket opened
by email, portal, phone, support intake, a person, or a Westy report stays open
until a signed-in human resolves it.

There is one narrow exception: an untouched ticket created by the signed
`milepost` telemetry-alert endpoint may close when Milepost sends a valid
`resolved` event for the same exact `alert:<numeric-id>` key. The recovery line
and status transition commit together. Safeharbor never acknowledges,
remediates, or controls the endpoint.

## The one-use capability

`tickets.auto_close_eligible` is `0` by default. Only
`lib/svc_intake.php::svc_alert_handle()` inserts `1`, after the alerts endpoint
has authenticated the exact `milepost` HMAC identity and validated an
`alert:<id>` key in Milepost's `BIGINT UNSIGNED` range (1 through
18446744073709551615). Every other ticket creator omits the column and therefore
receives `0`.

Eligibility is one-way. Database trigger
`trg_tickets_auto_close_before_update` refuses `0 -> 1`, and any ordinary
ticket UPDATE consumes `1 -> 0`. This includes manual status, priority, and
assignment changes. Re-fire events therefore append a Milepost system line;
they no longer rewrite the ticket subject or priority.

The database also consumes eligibility when:

- a client, technician, note, or non-Milepost system message is inserted;
- any existing message is edited, moved, or deleted;
- technician time is logged;
- a merge or any other workflow updates the ticket.

Once consumed, neither a replay nor application code can restore it. The
legacy pre-018 automatic-close sequence is also blocked: an ineligible ticket
cannot receive its `Ticket auto-closed.` machine line, so rolling application
code back cannot bypass the new database boundary. Manual human closure still
works normally.

## Existing ticket backfill

Migration 018 starts every existing row at `0`. On first column creation only,
it grants `1` to a row only if the database can positively prove all of these:

- it is Open, `channel='alert'`, and has an exact numeric `alert:` key;
- it has no contact, assignee, waiting leash, resolution, or merge facts;
- `updated_at = created_at`, proving the ticket row was never changed;
- it has exactly one message: the original Milepost system line;
- it has no technician time and has never been a merge target.

A re-fired or ambiguous historical alert remains ineligible. Replay skips the
backfill entirely, so human work performed after migration can never be
reclassified as untouched.

## Production migration gate

Migration 018 is migration-first. Use an exact green default-branch commit and
the Safeharbor maintenance procedure in `deploy/README.md`.

1. Record the exact default-branch SHA and SHA-256 of
   `018_ticket_auto_close_eligibility.sql`.
2. Take a new root-only application/config/database/grant/trigger backup and
   prove a scratch restore.
3. Record preflight counts without ticket content: Open numeric alerts,
   strict backfill candidates, alerts with non-machine messages or time, and
   the existing trigger manifest.
4. Deny only the Safeharbor vhost and lock only the Safeharbor runtime database
   account; verify zero runtime connections. Do not stop shared Apache/MySQL.
5. Apply the exact Git blob as the privileged operator, then replay it while
   the write freeze remains. Both runs must finish with:
   `auto_close_columns=1`, `auto_close_triggers=6`, `swap_triggers=0`.
6. Prove every eligible row satisfies the migration's exact origin, message,
   time, merge, and open-state predicates. Prove the known human-touched Open
   alert is ineligible without printing its subject or message text.
7. Deploy the matching committed application. Lint the deployed PHP and match
   the release-source hashes for `svc_intake.php`, `ticket_lifecycle.php`, and
   `api/svc/alerts.php`.
8. Restore the exact vhost, unlock the runtime account, and verify public login,
   signed alert intake, Apache errors, and unchanged non-ticket feature gates.
9. Canary with a disposable telemetry alert: open -> re-fire -> real recovery
   auto-closes while untouched. Then open a second canary, add a human note or
   time entry, send recovery, and prove it stays Open for a human. Remove the
   canary records only through an explicitly approved cleanup.

## Rollback

Rollback application code first to the exact retained pre-release artifact;
leave migration 018 and its six permanent triggers installed. This is the safe
rollback: new legacy-created alerts default ineligible, and the database blocks
the legacy auto-close line for them. Previously proven eligible alerts may
still close on genuine recovery, which is allowed by this contract.

Do not drop the column, check, or triggers during an incident. A schema-down
rollback requires a separate maintenance window, a fresh verified backup, and
proof that no eligible rows remain. The fail-safe operational choice is simply
to set all eligibility to `0` through a reviewed operator procedure; automatic
closure then stops while human resolution continues.

## Tests

- `ticket_auto_close_contract_test.php`: executable-code ownership and
  only-one-creator checks.
- `ticket_auto_close_mysql_test.php`: first migration, strict backfill,
  six triggers, note/time/assignment/priority/message-edit/message-delete
  revocation, atomic recovery, manual closure, replay, weakened-partial-state
  refusal, and rollback guard.
- `svc_intake_test.php`: signed identity authority, exact key validation,
  append-only re-fire, untouched close, and human-touched Open behavior.
