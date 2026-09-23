<?php
/**
 * Shared inbound-email intake. Both polls (cron/graph_poll.php for O365,
 * cron/imap_poll.php as the provider-agnostic fallback) feed normalized
 * messages here: sender, subject, plaintext body.
 *
 * Behavior:
 *  - subject contains [#123] → append a client message to that ticket
 *    (re-open waiting/resolved, ping the assignee by mail)
 *  - otherwise → create a ticket: sender matched to a contact by email;
 *    unknown senders auto-create a contact — under their domain's client
 *    if known, else under the "Email Intake" catch-all client
 *  - new tickets get a confirmation reply carrying [#id] for threading
 */
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/attachments.php';

/**
 * Bounce / auto-mail detection — the mail-loop killer. A confirmation sent
 * to a mailer-daemon bounces, the bounce creates a ticket, its confirmation
 * bounces again, forever (field-found on production 2026-08-01: a 9-day-old
 * "Undeliverable" loop). Never send ANY outbound to these senders.
 */
function intake_is_auto_mail(string $fromEmail, string $subjectText): bool
{
    if (preg_match('/^(mailer-daemon|postmaster|no-?reply|donotreply|bounce[s]?|autoreply)@/i', $fromEmail)) return true;
    if (preg_match('/^(undeliverable|undelivered|delivery (status|has failed)|mail delivery|returned mail|failure notice|auto(matic|-?)\s*(reply|response)|out of office)/i', trim($subjectText))) return true;
    return false;
}

function intake_message(string $fromEmail, string $fromName, string $subjectText, string $bodyText,
                        array $attachments = [], ?string $conversationId = null, ?int $intakeTenantId = null,
                        ?array $allowedClientIds = null): string
{
    $scopeTenantId = $intakeTenantId ?? tenant_id();
    if ($scopeTenantId < 1) throw new InvalidArgumentException('Intake tenant required');
    $fromEmail = mb_strtolower(trim(utf8_clean($fromEmail)));
    $fromName = utf8_clean($fromName);
    $subjectText = utf8_clean($subjectText);
    $bodyText = utf8_clean($bodyText);
    if ($fromName === '') $fromName = ucfirst(strtok($fromEmail, '@'));
    $isAuto = intake_is_auto_mail($fromEmail, $subjectText);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) return 'held:invalid-sender';
    if ($intakeTenantId !== null && $isAuto) return 'dropped:auto-mail';
    // A scoped pilot may accept mail only from contacts of its named clients.
    // Unknown senders must not create catch-all clients outside that scope.
    $senderClients = [];
    if ($intakeTenantId !== null) {
        $sq = db()->prepare('SELECT DISTINCT k.client_id FROM contacts k JOIN clients c ON c.id=k.client_id WHERE c.tenant_id=? AND LOWER(k.email)=?');
        $sq->execute([$scopeTenantId, $fromEmail]);
        $senderClients = array_map('intval', $sq->fetchAll(PDO::FETCH_COLUMN));
        if ($allowedClientIds !== null && array_intersect($senderClients, $allowedClientIds) === []) {
            return 'held:client-outside-scope';
        }
    }
    $bodyText = trim(mb_substr(strip_quoted_reply($bodyText), 0, 8000));
    if ($bodyText === '') $bodyText = '(no text body)';

    // Threading, strongest first: the mail conversation itself (Graph
    // conversationId survives subject edits), then the [#123] subject token.
    $tid = 0;
    if ($conversationId !== null && $conversationId !== '') {
        $cq = db()->prepare('SELECT e.ticket_id FROM email_threads e JOIN tickets t ON t.id=e.ticket_id WHERE e.conversation_id = ? AND t.tenant_id = ?');
        $cq->execute([mb_substr($conversationId, 0, 190), $scopeTenantId]);
        $tid = (int)($cq->fetchColumn() ?: 0);
    }
    if ($tid === 0 && preg_match('/\[#(\d+)\]/', $subjectText, $m)) {
        $tid = (int)$m[1];
    }
    if ($tid > 0) {
        $tq = db()->prepare('SELECT t.*, c.sla_tier FROM tickets t JOIN clients c ON c.id = t.client_id WHERE t.id = ? AND t.tenant_id = ?');
        $tq->execute([$tid, $scopeTenantId]);
        if ($ticket = $tq->fetch()) {
            if ($intakeTenantId !== null && (!in_array((int)$ticket['client_id'], $senderClients, true)
                || ($allowedClientIds !== null && !in_array((int)$ticket['client_id'], $allowedClientIds, true)))) {
                return 'held:thread-sender-mismatch';
            }
            db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
                ->execute([$tid, $fromName, 'client', $bodyText]);
            $mid = (int)db()->lastInsertId();
            intake_store_attachments($tid, $mid, $attachments);
            intake_learn_conversation($tid, $conversationId);
            if (in_array($ticket['status'], ['waiting', 'resolved'], true)) {
                db()->prepare("UPDATE tickets SET status = 'open', resurface_at = NULL WHERE id = ?")->execute([$tid]);
            }
            if (!empty($ticket['assignee_id'])) {
                $aq = db()->prepare('SELECT email FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1');
                $aq->execute([(int)$ticket['assignee_id'], $scopeTenantId]);
                if ($tech = $aq->fetch()) {
                    mail_queue(
                        $tech['email'],
                        '[#' . $tid . '] Client replied: ' . $ticket['subject'],
                        "Client reply from {$fromName} ({$fromEmail}):\n\n" . wordwrap($bodyText, 78)
                          . "\n\nOpen: https://safeharbor.8westit.com/ticket.php?id=" . $tid,
                        $tid
                    );
                }
            }
            return "appended:#{$tid}";
        }
        if ($intakeTenantId !== null) return 'held:thread-outside-scope';
        // Legacy unscoped intake retains its historical fresh-ticket fallback.
    }

    // Bounces/auto-replies that don't belong to an existing ticket are pure
    // noise — drop them (the poll still marks them read). Creating tickets
    // for them is what let the 2026-08 mail loop fill the database.
    if ($isAuto) return 'dropped:auto-mail';

    // Match sender to a contact, else a client by domain, else catch-all
    $kq = db()->prepare(
        'SELECT k.id, k.client_id FROM contacts k JOIN clients c ON c.id = k.client_id
          WHERE c.tenant_id = ? AND k.email = ?'
    );
    $kq->execute([$scopeTenantId, $fromEmail]);
    $matches = $kq->fetchAll();
    if ($allowedClientIds !== null) $matches = array_values(array_filter($matches,
        static fn(array $row): bool => in_array((int)$row['client_id'], $allowedClientIds, true)));
    if ($intakeTenantId !== null && count(array_unique(array_column($matches, 'client_id'))) > 1) return 'held:ambiguous-contact';
    if ($row = ($matches[0] ?? null)) {
        $contactId = (int)$row['id'];
        $clientId  = (int)$row['client_id'];
    } else {
        $domain = mb_substr((string)strrchr($fromEmail, '@'), 1);
        $cq = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND domain = ? AND domain != ""');
        $cq->execute([$scopeTenantId, $domain]);
        $clientId = $cq->fetch()['id'] ?? null;
        if (!$clientId) $clientId = intake_client_id($scopeTenantId);
        $clientId = (int)$clientId;
        db()->prepare('INSERT INTO contacts (client_id, name, email) VALUES (?,?,?)')
            ->execute([$clientId, $fromName, $fromEmail]);
        $contactId = (int)db()->lastInsertId();
    }

    $subjectClean = trim(preg_replace('/\s*(re|fwd?):\s*/i', '', $subjectText)) ?: '(no subject)';
    $goal = service_goal_snapshot_for_new_ticket(
        db(),
        $scopeTenantId,
        $clientId,
        'normal',
    );
    $window = service_goal_window_label($goal['first_response_minutes']);

    db()->prepare(
        'INSERT INTO tickets
            (tenant_id, client_id, contact_id, subject, priority, channel,
             sla_due_at, service_goal_target_id, created_at, updated_at)
         VALUES (?,?,?,?,?,"email",?,?,?,?)'
    )->execute([
        $scopeTenantId,
        $clientId,
        $contactId,
        mb_substr($subjectClean, 0, 190),
        'normal',
        $goal['due_at'],
        $goal['target_id'],
        $goal['opened_at'],
        $goal['opened_at'],
    ]);
    $tid = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
        ->execute([$tid, $fromName, 'client', $bodyText]);
    $mid = (int)db()->lastInsertId();
    intake_store_attachments($tid, $mid, $attachments);
    intake_learn_conversation($tid, $conversationId);
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
        ->execute([$tid, 'Safeharbor', 'system', 'Ticket created from email · Response target: '
            . $goal['display_name'] . ' v' . $goal['version_no'] . ' · due in ' . $window
            . ($isAuto ? ' · auto-mail sender, no confirmation sent' : '')]);

    if (!$isAuto) {
        mail_queue(
            $fromEmail,
            '[#' . $tid . '] ' . $subjectClean,
            "Hi {$fromName},\n\nWe've got it — ticket #{$tid} is open and a tech will reply within your SLA window.\n\n"
              . "Reply to this email any time to add to the thread (keep [#{$tid}] in the subject).\n"
              . "— Safeharbor by 8 West IT",
            $tid
        );
    }
    return "created:#{$tid}";
}

/** Remember which mail conversation a ticket lives in (idempotent). */
function intake_learn_conversation(int $ticketId, ?string $conversationId): void
{
    if ($conversationId === null || $conversationId === '') return;
    try {
        db()->prepare('INSERT IGNORE INTO email_threads (ticket_id, conversation_id) VALUES (?,?)')
            ->execute([$ticketId, mb_substr($conversationId, 0, 190)]);
    } catch (Throwable $e) {
        // threading is an optimization — never let it break intake
    }
}

/** Store inbound attachments [[name, mime, bytes]…] against a message. */
function intake_store_attachments(int $ticketId, int $messageId, array $attachments): void
{
    foreach (array_slice($attachments, 0, ATT_MAX_FILES) as $a) {
        try {
            att_store($ticketId, $messageId, (string)($a['name'] ?? 'file'), (string)($a['mime'] ?? ''), (string)($a['bytes'] ?? ''));
        } catch (Throwable $e) {
            // a bad attachment must never kill the mail
        }
    }
}

/** Strip quoted history from a reply (Outlook/Gmail/plaintext patterns). */
function strip_quoted_reply(string $body): string
{
    $body = preg_replace('/\r\n?/', "\n", (string)$body);
    $cutters = [
        '/^On .{5,120}wrote:\s*$/m',
        '/^-{2,}\s*Original Message\s*-{2,}\s*$/mi',
        '/^>{1}\s?/m',
        '/^From:\s.*\nSent:\s.*\nTo:\s.*\nSubject:\s.*$/m',
        '/^_{5,}\s*$/m',
    ];
    foreach ($cutters as $re) {
        if (preg_match($re, $body, $m, PREG_OFFSET_CAPTURE)) {
            $body = substr($body, 0, $m[0][1]);
        }
    }
    return trim($body);
}

/** The catch-all client for unknown senders (created once). */
function intake_client_id(?int $intakeTenantId = null): int
{
    $scopeTenantId = $intakeTenantId ?? tenant_id();
    $q = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND name = "Email Intake"');
    $q->execute([$scopeTenantId]);
    if ($row = $q->fetch()) return (int)$row['id'];
    db()->prepare('INSERT INTO clients (tenant_id, name, domain, sla_tier, notes) VALUES (?,?,"","standard",?)')
        ->execute([$scopeTenantId, 'Email Intake', 'Catch-all for tickets emailed by unknown senders. Reassign to the right client and it will learn their domain next time.']);
    return (int)db()->lastInsertId();
}

/** HTML → plaintext (crude but serviceable for intake bodies). */
function html_to_text(string $html): string
{
    $html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
    $html = str_replace(['</p>', '</div>', '</li>', '</tr>'], "\n", $html);
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}
