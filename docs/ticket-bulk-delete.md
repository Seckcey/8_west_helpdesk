# Bulk ticket delete

Owners and admins can permanently delete queue tickets: a handful they tick, or every ticket the
queue page shows. Technicians cannot. The code is `app/lib/ticket_delete.php` (all decisions),
`app/public/api/ticket_bulk_delete.php` (the endpoint) and the queue page; the database change is
migration `035_ticket_bulk_delete.sql`.

## Using it

1. On the Queue, tick tickets (or press `x` on the current row), or tick the box in the column header
   to select every ticket on the page. "Select all" means exactly the rows the page shows, so a
   ticket that arrives or changes afterwards is never included.
2. Press **Delete…** (or `d`). Safeharbor shows how many tickets it will delete, which it must keep
   and why, how many attachment files go and how many queued emails are cancelled. It also warns if
   your own timer is still running on one of them, because that time could no longer be logged.
3. Type `DELETE <count>` exactly and confirm. The delete runs in batches of 100 tickets; keep the tab
   open. **Stop after this batch** stops cleanly. If the server refuses a batch, nothing in it changed;
   if the connection drops, the last batch may or may not have finished. Either way **Try again**
   carries on safely from the last confirmed batch.

One delete can name up to 10,000 tickets.

## What a run may touch

The preview answers with `deletable_ids`, the exact list it can delete, and the run must send that
list back: the endpoint compares its hash with the one stored in the session at preview time, along
with the person, the tenant, the typed phrase and a one-hour expiry. So a run can never reach a ticket
the person did not see, and never go past the count they confirmed. Each batch still re-checks every
ticket under lock; one that picked up protected history since the preview is kept and reported.

## What goes with a ticket

Its replies and internal notes, attachment rows (the files are removed from storage after the batch
commits), its satisfaction survey, and its email-conversation mappings. Its presence rows go by
their `ON DELETE CASCADE`. Queued email that has not been sent is cancelled (`attempts` set to
`max_attempts`, `last_error` "Cancelled: ticket deleted"); mail that had already failed for good
keeps its real error; every mail row lets go of the ticket. An email whose send had already started
still goes out. A Westy failure report that pointed at the ticket loses its pointer and opens a new
ticket next time it fires.

## What is never deleted, and why

| Reason shown | Rule behind it |
|---|---|
| Technician time is logged on it | Time entries are immutable billing evidence; a trigger refuses to remove them |
| Westy troubleshooting history | `westy_workflows` rows are immutable |
| Westy email history | `westy_email_drafts` rows are immutable |
| Westy mailbox history | `westy_mail_conversations` rows are immutable |
| Customer-portal requester record | `portal_ticket_requesters` rows are immutable |
| Customer-portal Westy receipt | sent `portal_westy_drafts` receipts are immutable |
| An attachment is linked to another ticket | its message cannot go while another ticket's attachment row points at it |
| Has attachments / Has a satisfaction survey (database permission not granted yet) | the runtime account lacks DELETE on `attachments` or `csat` (see Grants) |
| Merged with a ticket that must be kept | a merge group goes whole or not at all |
| Merged with a ticket outside this selection | as above: deleting half a merge leaves a dead link or brings a hidden survivor back into the customer portal |

Kept tickets are listed with their reason before anything happens and again at the end. Nothing
forces a guard: no TRUNCATE, no `FOREIGN_KEY_CHECKS=0`, no trigger is dropped, and the id counter is
never reset, so a ticket number is never reused.

## How a batch works

Each batch is one transaction at READ COMMITTED (or the server default when binary logging is
statement-based, where InnoDB refuses READ COMMITTED writes). Every read inside it is a locking read
of committed rows, and READ COMMITTED takes no gap locks, so new tickets, alert intake included, are
never held up while a batch runs. The batch locks its window of tickets through
`uq_tickets_tenant_id`, so a reply, a time entry or Westy history aimed at one of them waits for the
batch and then fails, because the ticket is gone. It pulls in every merge partner and decides what
stays **before touching any row**: removing a message fires Westy triggers that write history which
can never be removed again.

Then, in this order: lock the children, write one `ticket_deletions` row per ticket, remove
attachment rows, surveys and conversation mappings, cancel and detach mail, clear Westy report
pointers, remove messages, remove the tickets. Each row count must match what was locked, or the
batch rolls back. A deadlock or lock timeout is retried up to three times. The attachment files are
removed after COMMIT and outside the retry; if that clean-up itself fails, the names go to the server
log and the batch still reports what it deleted.

Two rules for anyone changing this code:

- Messages are removed by a literal list of ticket ids. Their `AFTER DELETE` trigger (migration 018)
  updates `tickets`, so a form that joins or subqueries `tickets` fails with MySQL error 1442. The
  MySQL test proves this.
- CI reads every production PHP file's text for SQL deletes and compares the tables with the runtime
  account's DELETE grants (`app/tests/business_reports_test.php`). Do not write the delete verb before
  any other table name in this code, comments included.

The MySQL test also lists every foreign key to `tickets`; a new table that points at tickets fails
it until someone decides whether its rows go with the ticket, let go of it, or keep it. Its lock
probe fails if the batch ever takes gap locks again.

## Known limits

- **A customer email that lands in the same second as its ticket's batch can be lost.** The mail
  poller (`cron/graph_poll.php`) records a message as processed before it files it; if the ticket
  is deleted while the reply waits on the batch's lock, filing fails and the poller will not retry.
  The fix belongs in the poller: record and file in one transaction, as `support_mail_poll` does.
- **Westy's triggers on message deletes look tickets up by `ticket_id` alone,** while
  `westy_workflows` and `westy_mail_conversations` are indexed by `(tenant_id, ticket_id)`, so each
  removed message scans both tables. They are small today, and READ COMMITTED keeps the scans from
  holding locks they do not need; an index on `ticket_id` in each would remove the scans.

## Grants

The runtime account already has `SELECT, INSERT, UPDATE` on the whole database, so the new
`ticket_deletions` table needs nothing. The delete needs two new table grants, and nothing broader:

```sql
GRANT DELETE ON safeharbor.attachments TO 'safeharbor'@'localhost';
GRANT DELETE ON safeharbor.csat TO 'safeharbor'@'localhost';
```

Check with `SHOW GRANTS FOR 'safeharbor'@'localhost';`. Until both exist, tickets with an attachment
or a survey are kept with the "permission not granted yet" reason and everything else still goes;
the order of grant and deploy does not matter.

## The record

`ticket_deletions` holds one insert-only row per deleted ticket: tenant, run key, ticket and client
ids, channel, status, alert key, merge link, created and resolved times, how many messages and
attachments went, the survey score, how many emails were cancelled, who deleted it and when. No
subjects, bodies or file names. Triggers refuse UPDATE and DELETE. Read-only checks:

```sql
SELECT run_key, COUNT(*), MIN(deleted_at), MAX(deleted_at) FROM ticket_deletions GROUP BY run_key;
SELECT channel, COUNT(*) FROM ticket_deletions WHERE run_key = '<run key>' GROUP BY channel;
```

The server log has one `[safeharbor-ticket-delete]` line per batch (run, tenant, actor and counts)
and one line per attachment file it could not remove. Find leftover files by comparing the file
names in `/srv/8west/apps/safeharbor/shared/attachments` with `SELECT stored_name FROM attachments`.

## Other apps

- **Milepost alerts** find their ticket by alert key. A `resolved` event for a deleted ticket is
  ignored; an `opened` event less than 24 hours old opens a new ticket with a new number.
- **Email**: a customer who replies to an old email about a deleted ticket starts a new ticket.
- **Coastmark** imports approved time, and tickets with time are never deleted.
- **Westy workflows** keep their tickets (see the table).

## Rollout and clearing the current queue

1. Merge with both CI jobs green.
2. Release owner: apply the two grants above, then `bash deploy/deploy.sh --check` and
   `bash deploy/deploy.sh --migrate app/db/migrations/035_ticket_bulk_delete.sql` (it dumps the
   database first; note the backup path). If the clear happens on another day, take a fresh dump
   first.
3. Smoke test: an owner sees checkboxes on the Queue; a technician does not.
4. To clear the queue: Queue → press `1` (All) → tick the header box → **Delete…** → check the
   preview → type the phrase → keep the tab open until it says Deleted.

Read-only production counts on October 31, 2026: 2,947 tickets, of which 18 must stay (3 with
technician time, 15 with Westy workflows). One ticket has an attachment and one has a survey; with
the grants in place the preview should offer 2,929, or 2,927 without them. Afterwards, check that the
tenant's ticket count equals the kept count and that `ticket_deletions` for the run key holds the
deleted count.
