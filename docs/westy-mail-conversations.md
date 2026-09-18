# Reviewed Westy mailbox conversations

Westy sends approved advice from `westy@8westit.com`, records verified replies
on the original case, and can send one separately approved acknowledgment.
Frankie handles requests for new advice or action. Email authority does not
authorize endpoint commands, case closure, purchases, time approval, or invoices.

## Boundaries

- A dedicated certificate app receives only Exchange `Application Mail.Read`
  and `Application Mail.Send`, restricted to the exact Westy mailbox object.
  Do not add tenant-wide Graph mail grants, forwarding, human sign-in, or mailbox
  membership. Keep private keys outside the application release and Git.
- `westy_email.graph` is independent of `mail.graph` and business reports.
  Existing report configuration, scheduler scope, and credentials remain separate.
- The endpoint POC on the case wins. Otherwise the explicitly designated client
  POC wins. No guessed contact or domain-based customer mapping is allowed.
- An operator-verified reply alias may be frozen in a draft with its exact
  contact/customer/mailbox evidence. Staff see the alias before approval. All
  outgoing messages still go to the approved canonical recipient.
- Advice is immutable after saving. Staff approve the saved sender, recipient,
  alias, message, customer/case evidence and application identity. Current
  authority is checked again before the durable claim and before transport.
- The acknowledgment uses a frozen template hash, expires in seven days and has
  a budget of one send. This pilot allows that delegation only on a case already
  owned by a technician. Later staff edits, time entries, source binding changes,
  contact changes, identity changes, or explicit revocation stop it permanently.
  Approval also binds the acknowledgment shown on the review page, so a template
  deployment between review and approval requires a new review.

This bounded pilot permits one conversation per case. A revoked or stale draft
cannot be reset or silently replaced. Frankie handles that exception. Sent Items
reconciliation examines a bounded recent set and preserves the original unknown
state; absence from that set never permits a resend or proves nondelivery.

## Truthful delivery and recovery

The database records intent before the network request. A timeout, killed worker,
or uncertain browser response reads the existing attempt. It never creates a
second attempt. Microsoft HTTP 202 means accepted for processing, not confirmed
recipient inbox delivery. Sent Items evidence also proves no inbox receipt.

Legacy migration-026 attempts remain immutable. Only a stored HTTP 404 rejection
can receive the narrowly defined append-only owner/admin disposition permitting
one newly reviewed conversation. A timeout, HTTP 5xx, or accepted send cannot use
that exception. The old draft's original sender was not retained and must not
be reconstructed from current configuration.

Replies require the exact recipient or approved alias, exact Westy destination,
one unpredictable conversation reference and one case reference. Sender and
Reply-To must agree. Bounces, automatic responses, ambiguous headers, cross-case
references, unverified senders and permission mismatches are held for staff.
Email text is untrusted and cannot approve additional actions.

The pilot's Exchange authentication check is internal-only. The actual internal
reply had `AuthAs: Internal`, a hosted origin and the same Exchange tenant as
the dedicated application, without Internet `compauth`. The reader requires
those unambiguous Exchange assertions and binds the expected tenant to the
Graph application, not message text or a separately entered tenant. Explicit
authentication failures remain held. Enable automatic acknowledgment only after
a real header fixture validates this path and the operator has verified no inbound connector
treats external mail as internal. Connector verification is part of setup and
must be revisited when mail routing changes; elapsed time alone does not stop
the reply reader. Each message still needs the exact tenant, sender, recipient,
case and current reply authority. This bounded pilot does not claim
external-customer automatic reply support.

Microsoft documents [Exchange internal-message classification](https://techcommunity.microsoft.com/blog/exchange/demystifying-and-troubleshooting-hybrid-mail-flow-when-is-a-message-internal/1420838/)
and the [header firewall](https://learn.microsoft.com/en-us/exchange/header-firewall-exchange-2013-help).

Mailbox reads do not mark messages read or delete them. The durable cursor keeps
a fixed time window and continuation position. A page advances only after all
its messages have durable outcomes. Repeating a page after a crash uses receipt
deduplication and never retries a claimed send. Page/message caps limit each run
without abandoning older replies.

## Operator sequence

1. Run isolated PHP and MySQL tests on Coastline. Never start desktop Docker.
2. Merge reviewed changes and require green Validate for the exact main commit.
3. Follow the Safeharbor migration-first release runbook: fresh protected backup,
   scratch restore, Safeharbor-only write freeze, exact migrations 027 and 028,
   replay, narrow runtime grants, matching clean-source deployment and postflight.
4. Preserve and rebind the existing report scheduler manifest and activation
   record when application/config hashes change. Do not change report scope.
5. Configure the dedicated certificate application with all new gates off.
   Run the read-only certificate probe. Require Westy HTTP 200 and an existing
   out-of-scope mailbox HTTP 403. A negative HTTP 404 is not an authorization proof.
6. Enable only the internal customer and exact case/contact pilot. Review the
   saved message and optional acknowledgment. Keep recipient confirmation
   separate from Microsoft acceptance. Observe a real reply before claiming
   intake works, and a real acknowledgment before claiming the round trip works.

`php app/cron/westy_mail_poll.php` is dry-run by default. `--run` still requires
every protected gate. Keep automatic acknowledgment disabled until header proof.
Rollback stops new mail actions and polling; retain all claims, receipts,
revocations, cursors, and the stronger database guards. Never reset an attempt.

## Case 614 checkpoint

The September 17 release is historical. On September 18, the live original
attempt was Draft 3: recipient `frank@8westit.com`, attempted once at
06:29:58 UTC, saved `uncertain` with provider HTTP 404. The mailbox probe reported
`MailboxNotEnabledForRESTAPI` for the old general sender. No inbox receipt or
repair was proved. Case 614 remained open and human-owned; no laptop change or
purchase was made. The new Westy mailbox and scoped application exist, but this
document is not evidence that a new message or acknowledgment has been sent.
