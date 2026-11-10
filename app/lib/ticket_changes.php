<?php
/**
 * The changes a technician makes to one ticket's status, priority and owner, each defined once.
 * The menus, the keyboard, the Resolve button (api/ticket_action.php) and "Send and ..." on the
 * ticket page (ticket.php) all come through here, so they cannot drift apart.
 *
 * Every function takes the ticket row the caller already read for its own tenant
 * (WHERE id = ? AND tenant_id = ?) and writes with the same pair. None of them decides who may
 * act: the callers' sign-in checks are unchanged.
 *
 * Needs render.php (STATUS_META, PRIORITY_META) and db().
 */
declare(strict_types=1);

/** Waiting parks a ticket; housekeeping brings it back to Open after this long without a reply. */
const TICKET_WAITING_HOURS = 72;

/**
 * How long the resolve survey and a teammate's "assigned to you" email wait before they are sent.
 * Undo (and any reopen or reassignment inside this window) withdraws them, so a slip of the hand
 * never reaches a customer's or a colleague's inbox. mail_dispatch runs once a minute.
 */
const TICKET_NOTICE_HOLD_SECONDS = 60;

/** Why a held email never left; written to mail_queue.last_error when it is withdrawn. */
const TICKET_SURVEY_WITHDRAWN = 'Cancelled: ticket reopened before its survey was sent';
const TICKET_ASSIGNMENT_WITHDRAWN = 'Cancelled: ticket reassigned before this was sent';
/** Added to the reason when the hold had already run out, so the dispatcher may have had the email in hand. */
const TICKET_NOTICE_LATE = ' (too late to be sure: it may have been sent)';

/**
 * A survey that was asked for and taken back before its email left carries this creation time.
 * It is the record of the withdrawal on the row itself: reports count surveys by the window their
 * creation time falls in, and this time falls in none, so a withdrawn survey is never counted as
 * sent, whether or not the database account may remove the row. Asking again sets the real time.
 */
const TICKET_SURVEY_WITHDRAWN_AT = '1970-01-01 00:00:00';

/** Addresses that are machines, not people. Mail-loop protection: they are never written to. */
function ticket_machine_address(string $email): bool
{
    return preg_match('/^(mailer-daemon|postmaster|no-?reply|donotreply|bounce[s]?|autoreply)@/i', $email) === 1;
}

function ticket_first_name(string $name): string
{
    return explode(' ', trim($name))[0];
}

/**
 * The ticket's contact, with whether mail may go to them: yes, or no because they have no address
 * ("none") or because the address is a machine's ("machine"). Null when the ticket has no contact.
 *
 * @return array{name:string,first_name:string,email:string,mail:string}|null  mail: yes | none | machine
 */
function ticket_contact(array $ticket): ?array
{
    if (empty($ticket['contact_id'])) return null;
    $q = db()->prepare(
        'SELECT k.name, k.email
           FROM contacts k
           JOIN clients c ON c.id = k.client_id
          WHERE k.id = ? AND c.tenant_id = ?'
    );
    $q->execute([(int)$ticket['contact_id'], (int)$ticket['tenant_id']]);
    $contact = $q->fetch();
    if (!$contact) return null;
    $email = trim((string)$contact['email']);
    $name = trim((string)$contact['name']);
    return ['name' => $name, 'first_name' => ticket_first_name($name), 'email' => $email,
        'mail' => $email === '' ? 'none' : (ticket_machine_address($email) ? 'machine' : 'yes')];
}

/**
 * The person a ticket's customer mail goes to, or null when there is nobody a mail may go to
 * (no contact, no address, or a machine address).
 *
 * @return array{name:string,first_name:string,email:string,mail:string}|null
 */
function ticket_mail_contact(array $ticket): ?array
{
    $contact = ticket_contact($ticket);
    return $contact !== null && $contact['mail'] === 'yes' ? $contact : null;
}

/**
 * Who sees a technician's reply on this ticket, and how:
 *   email      a contact whose address can receive mail;
 *   portal     the request was opened in the portal, so it has no contact and no mail is sent;
 *   unmailable a contact whose address is a machine's (never written to);
 *   no_address a contact with no address;
 *   none       nobody is attached.
 * ticket.php uses the same answer to decide whether to queue the reply email.
 *
 * @return array{via:string,first_name:string,contact:?array}  contact is set only for "email"
 */
function ticket_reply_audience(array $ticket): array
{
    $contact = ticket_contact($ticket);
    if ($contact !== null && $contact['mail'] === 'yes') {
        return ['via' => 'email', 'first_name' => $contact['first_name'], 'contact' => $contact];
    }
    $q = db()->prepare('SELECT 1 FROM portal_ticket_requesters WHERE ticket_id = ? AND tenant_id = ?');
    $q->execute([(int)$ticket['id'], (int)$ticket['tenant_id']]);
    if ($q->fetchColumn() !== false) {
        // The portal writes the requester's name on the request's opening message.
        $nq = db()->prepare(
            "SELECT m.author_name
               FROM messages m
               JOIN tickets t ON t.id = m.ticket_id
              WHERE m.ticket_id = ? AND t.tenant_id = ? AND m.kind = 'client'
              ORDER BY m.id ASC LIMIT 1"
        );
        $nq->execute([(int)$ticket['id'], (int)$ticket['tenant_id']]);
        return ['via' => 'portal', 'first_name' => ticket_first_name((string)$nq->fetchColumn()), 'contact' => null];
    }
    if ($contact !== null) {
        return ['via' => $contact['mail'] === 'machine' ? 'unmailable' : 'no_address', 'first_name' => $contact['first_name'], 'contact' => null];
    }
    return ['via' => 'none', 'first_name' => '', 'contact' => null];
}

/**
 * What the composer says beside the Send button: who gets the reply and how, in words that are
 * true for this ticket. No database work, so the sentences can be checked on their own.
 *
 * @param array{via:string,first_name:string} $audience
 */
function ticket_reply_hint(array $audience): string
{
    $name = $audience['first_name'];
    return match ($audience['via']) {
        'email' => ($name !== '' ? $name : 'The customer') . ' gets this by email.',
        'portal' => ($name !== '' ? $name : 'The customer') . ' sees this in the portal. No email is sent.',
        'unmailable' => ($name !== '' ? $name . '\'s' : 'The contact\'s') . ' address cannot receive mail, so no email is sent. This reply is saved on the ticket only.',
        'no_address' => ($name !== '' ? $name : 'The contact') . ' has no email address, so no email is sent. This reply is saved on the ticket only.',
        default => 'No customer is attached. This reply is saved on the ticket only.',
    };
}

/**
 * Where this ticket's one survey stands:
 *   none       never asked for;
 *   withdrawn  asked for and taken back before its email left (see TICKET_SURVEY_WITHDRAWN_AT),
 *              so a chosen resolve may ask again with the same survey;
 *   asked      anything else: sent, still queued, possibly sent, or answered.
 * Pass $lock inside a transaction to hold the survey row while deciding.
 *
 * @return array{state:string,id:int,token:string}
 */
function ticket_survey_state(array $ticket, bool $lock = false): array
{
    $q = db()->prepare('SELECT id, token, created_at, responded_at FROM csat WHERE ticket_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([(int)$ticket['id']]);
    $survey = $q->fetch();
    if (!$survey) return ['state' => 'none', 'id' => 0, 'token' => ''];
    $withdrawn = $survey['responded_at'] === null && (string)$survey['created_at'] === TICKET_SURVEY_WITHDRAWN_AT;
    return ['state' => $withdrawn ? 'withdrawn' : 'asked', 'id' => (int)$survey['id'], 'token' => (string)$survey['token']];
}

/** Whether resolving this ticket now would ask the customer for a rating (once per ticket, ever). */
function ticket_survey_would_send(array $ticket, ?array $contact): bool
{
    if ($contact === null) return false;
    try {
        return ticket_survey_state($ticket)['state'] !== 'asked';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Set a ticket's status.
 *
 * $personChoseIt says a person picked this exact status: a menu choice, the Resolve button, or
 * "Send and resolve". Only then may resolving ask the customer for a rating. A bare status write
 * (an old page still open in a browser, an Undo that puts a status back) never sends one.
 *
 * Leaving Resolved while its survey is still held withdraws that survey.
 *
 * @return array{status:string,changed:bool,survey:string,toast:string}
 *         survey: held | already | withdrawn | late | none
 */
function ticket_change_status(array $ticket, string $status, bool $personChoseIt): array
{
    if (!array_key_exists($status, STATUS_META)) throw new InvalidArgumentException('Unknown ticket status');
    $before = (string)$ticket['status'];
    $label = STATUS_META[$status][0];
    $days = intdiv(TICKET_WAITING_HOURS, 24);
    $resurface = 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . TICKET_WAITING_HOURS . ' HOUR)';
    if ($before === $status) {
        // Nothing to change. "Send and wait" on a ticket that already waits starts its wait again.
        if ($status !== 'waiting') {
            return ['status' => $status, 'changed' => false, 'survey' => 'none', 'toast' => 'Status is already ' . $label . '.'];
        }
        db()->prepare("UPDATE tickets SET resurface_at = $resurface WHERE id = ? AND tenant_id = ? AND status = 'waiting'")
            ->execute([(int)$ticket['id'], (int)$ticket['tenant_id']]);
        return ['status' => $status, 'changed' => false, 'survey' => 'none',
            'toast' => 'Still waiting on the customer. The ' . $days . ' days start again.'];
    }

    $resolved = $status === 'resolved' ? 'NOW()' : 'NULL';
    if ($status !== 'waiting') $resurface = 'NULL';
    db()->prepare("UPDATE tickets SET status = ?, resolved_at = $resolved, resurface_at = $resurface WHERE id = ? AND tenant_id = ?")
        ->execute([$status, (int)$ticket['id'], (int)$ticket['tenant_id']]);

    $survey = 'none';
    if ($status === 'resolved') {
        if ($personChoseIt) $survey = ticket_survey_queue($ticket);
    } elseif ($before === 'resolved') {
        $survey = ticket_survey_withdraw($ticket);
    }

    if ($status === 'resolved') {
        $who = (string)(ticket_mail_contact($ticket)['first_name'] ?? '');
        if ($who === '') $who = 'The customer';
        $toast = match ($survey) {
            'held' => 'Resolved. ' . $who . ' gets a short survey in a minute.',
            'already' => 'Resolved. ' . $who . ' was already asked to rate this ticket.',
            default => 'Resolved. No survey is sent.',
        };
    } elseif ($status === 'waiting') {
        $toast = 'Waiting on the customer. The ticket comes back in ' . $days . ' days if they stay silent.';
    } elseif ($before === 'resolved') {
        $toast = 'Reopened as ' . $label . '.' . match ($survey) {
            'withdrawn' => ' The survey was not sent.',
            'late' => ' The survey may already have been sent.',
            default => '',
        };
    } else {
        $toast = 'Status set to ' . $label . '.';
    }
    return ['status' => $status, 'changed' => true, 'survey' => $survey, 'toast' => $toast];
}

/**
 * CSAT on resolve: the first chosen resolve of a ticket with a real human contact queues the
 * 3-face survey email, held for TICKET_NOTICE_HOLD_SECONDS. Each link opens csat.php with that
 * face picked; nothing is recorded until the customer presses Send there, so mail scanners that
 * open links can't answer for them. Once per ticket (unique key); machine addresses never get one.
 *
 * @return string held | already | none
 */
function ticket_survey_queue(array $ticket): string
{
    $pdo = db();
    $ownsTransaction = false;
    try {
        $contact = ticket_mail_contact($ticket);
        if ($contact === null) return 'none';
        $email = mb_strtolower($contact['email']);

        // The mail row and its hold commit together, so the dispatcher never sees it unheld.
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        $survey = ticket_survey_state($ticket, true);
        if ($survey['state'] === 'withdrawn') {
            // Asked for once and taken back before it left: the same survey is asked now, so now
            // is when it was created as far as any report is concerned.
            $token = $survey['token'];
            $pdo->prepare('UPDATE csat SET created_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$survey['id']]);
        } else {
            $token = bin2hex(random_bytes(20));
            $ins = $pdo->prepare('INSERT IGNORE INTO csat (ticket_id, token) VALUES (?,?)');
            if ($survey['state'] === 'none') $ins->execute([(int)$ticket['id'], $token]);
            if ($survey['state'] !== 'none' || $ins->rowCount() === 0) {   // already asked — resolving again never re-asks
                if ($ownsTransaction) $pdo->commit();
                return 'already';
            }
        }

        require_once __DIR__ . '/mailer.php';   // brings lib/customer_mail.php, which owns the email's words
        $base = 'https://safeharbor.8westit.com/csat.php?t=' . $token;
        $tenantId = (int)($ticket['tenant_id'] ?? 0);
        $mailId = mail_queue(
            $email,
            '[#' . (int)$ticket['id'] . '] Resolved: ' . $ticket['subject'],
            customer_mail_resolved_body(
                $contact['name'],
                (string)$ticket['subject'],
                $base,
                customer_mail_business_name($tenantId),
                customer_mail_portal_link($tenantId, (int)($ticket['client_id'] ?? 0), (int)$ticket['id']),
            ),
            (int)$ticket['id']
        );
        ticket_notice_hold($mailId);
        if ($ownsTransaction) $pdo->commit();
        return 'held';
    } catch (Throwable $e) {
        // surveys must never block resolving (e.g. pre-migration)
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        return 'none';
    }
}

/** A survey email is found again by the link it carries. */
function ticket_survey_mail_like(string $token): string
{
    return '%csat.php?t=' . $token . '&%';
}

/**
 * Take back a survey whose email has not left yet, so a later, real resolve can still ask.
 * A survey that was already sent, or answered, stays exactly as it is.
 *
 * The email is cancelled the way ticket_delete.php cancels mail: the runtime database account may
 * not remove mail_queue rows, so the row stays, marked spent, and the dispatcher skips it. The
 * survey row is marked withdrawn (TICKET_SURVEY_WITHDRAWN_AT), which needs no special grant, and
 * is then removed where the account may remove csat rows (the grant bulk delete documents).
 *
 * @return string withdrawn  the email was still held and will never be sent;
 *                late       it was cancelled after its hold ran out, so it may have been sent: the
 *                           survey stays as asked and is never asked again;
 *                none       nothing was waiting (already sent, answered, or never asked).
 */
function ticket_survey_withdraw(array $ticket): string
{
    $pdo = db();
    $ownsTransaction = false;
    try {
        $q = $pdo->prepare('SELECT id, token FROM csat WHERE ticket_id = ? AND responded_at IS NULL');
        $q->execute([(int)$ticket['id']]);
        $survey = $q->fetch();
        if (!$survey) return 'none';

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        $cancelled = ticket_notice_cancel((int)$ticket['id'], 'body_text', ticket_survey_mail_like((string)$survey['token']), TICKET_SURVEY_WITHDRAWN);
        if ($cancelled === 'held') {
            $pdo->prepare('UPDATE csat SET created_at = ? WHERE id = ? AND responded_at IS NULL')
                ->execute([TICKET_SURVEY_WITHDRAWN_AT, (int)$survey['id']]);
            try {
                $pdo->prepare('DELETE FROM csat WHERE id = ? AND responded_at IS NULL')->execute([(int)$survey['id']]);
            } catch (PDOException $denied) {
                // No such grant on this server: the row stays, marked withdrawn, and is reused later.
            }
        }
        if ($ownsTransaction) $pdo->commit();
        return $cancelled === 'held' ? 'withdrawn' : $cancelled;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        return 'none';
    }
}

/** Hold one queued mail for the undo window. */
function ticket_notice_hold(int $mailId): void
{
    db()->prepare(
        'UPDATE mail_queue SET next_try_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ? AND sent_at IS NULL'
    )->execute([TICKET_NOTICE_HOLD_SECONDS, $mailId]);
}

/**
 * Cancel this ticket's queued-but-unsent mail whose subject or body matches: it is marked spent
 * with the reason, which is what the dispatcher skips. Sent mail is never touched.
 *
 * The dispatcher only picks up mail whose hold has run out, so a row cancelled while still held
 * was certainly never sent. A row whose hold had run out may already be in the dispatcher's hands;
 * it is cancelled too, with TICKET_NOTICE_LATE added to the reason, and reported as "late".
 *
 * @param 'subject'|'body_text' $column
 * @return string held | late | none
 */
function ticket_notice_cancel(int $ticketId, string $column, string $like, string $reason): string
{
    if ($column !== 'subject' && $column !== 'body_text') throw new InvalidArgumentException('Unknown mail column');
    $pdo = db();
    $held = $pdo->prepare(
        "UPDATE mail_queue SET attempts = max_attempts, last_error = ?
          WHERE ticket_id = ? AND sent_at IS NULL AND attempts < max_attempts AND next_try_at > UTC_TIMESTAMP() AND $column LIKE ?"
    );
    $held->execute([$reason, $ticketId, $like]);
    $late = $pdo->prepare(
        "UPDATE mail_queue SET attempts = max_attempts, last_error = ?
          WHERE ticket_id = ? AND sent_at IS NULL AND attempts < max_attempts AND $column LIKE ?"
    );
    $late->execute([$reason . TICKET_NOTICE_LATE, $ticketId, $like]);
    if ($late->rowCount() > 0) return 'late';
    return $held->rowCount() > 0 ? 'held' : 'none';
}

/**
 * @return array{priority:string,changed:bool,toast:string}
 */
function ticket_change_priority(array $ticket, string $priority): array
{
    if (!array_key_exists($priority, PRIORITY_META)) throw new InvalidArgumentException('Unknown ticket priority');
    db()->prepare('UPDATE tickets SET priority = ? WHERE id = ? AND tenant_id = ?')
        ->execute([$priority, (int)$ticket['id'], (int)$ticket['tenant_id']]);
    return ['priority' => $priority, 'changed' => (string)$ticket['priority'] !== $priority,
        'toast' => 'Priority set to ' . PRIORITY_META[$priority][0] . '.'];
}

/**
 * Set a ticket's owner. $value is what the browser sent:
 *   "me"    the signed-in person, or nobody when they already own it (the A key, and the Westy
 *           takeover button);
 *   "none"  nobody;
 *   an id   that teammate, when they are an active member of this tenant; anyone else is refused
 *           and the ticket is left unassigned, exactly as before.
 * A newly assigned teammate is emailed. That email is held for the undo window, and an unsent one
 * is withdrawn when the owner changes again, so only the person who ends up with the ticket hears.
 * $notify is false for an Undo: putting an owner back tells nobody anything.
 *
 * @return array{assignee_id:?int,toast:string}
 */
function ticket_change_assignee(array $ticket, array $user, string $value, bool $notify = true): array
{
    $pdo = db();
    $id = (int)$ticket['id'];
    $tenantId = (int)$ticket['tenant_id'];
    $me = (int)$user['id'];
    $assignee = null;
    if ($value === 'me') {
        $target = ((int)$ticket['assignee_id'] !== $me) ? $me : null;
    } elseif ($value === 'none') {
        $target = null;
    } else {
        $tq = $pdo->prepare('SELECT id, email, full_name FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1');
        $tq->execute([(int)$value, $tenantId]);
        $assignee = $tq->fetch() ?: null;
        $target = $assignee ? (int)$assignee['id'] : null;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE tickets SET assignee_id = ? WHERE id = ? AND tenant_id = ?')->execute([$target, $id, $tenantId]);
        // Anybody about to be told that this ticket is theirs is no longer told.
        ticket_notice_cancel($id, 'subject', '[#' . $id . '] Assigned to you: %', TICKET_ASSIGNMENT_WITHDRAWN);
        $emailed = false;
        if ($notify && $target !== null && $target !== $me && $assignee && (string)$assignee['email'] !== '') {
            require_once __DIR__ . '/mailer.php';
            $mailId = mail_queue(
                (string)$assignee['email'],
                '[#' . $id . '] Assigned to you: ' . $ticket['subject'],
                explode(' ', $user['full_name'])[0] . " assigned you ticket #{$id}:\n\n"
                  . $ticket['subject'] . "\n\n"
                  . 'Open: https://safeharbor.8westit.com/ticket.php?id=' . $id,
                $id
            );
            ticket_notice_hold($mailId);
            $emailed = true;
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($target === null) $toast = 'Unassigned.';
    elseif ($target === $me) $toast = 'Assigned to you.';
    else $toast = 'Assigned to ' . $assignee['full_name'] . '.' . ($emailed ? ' They get an email in a minute.' : '');
    return ['assignee_id' => $target, 'toast' => $toast];
}
