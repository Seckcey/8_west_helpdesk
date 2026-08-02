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

function intake_message(string $fromEmail, string $fromName, string $subjectText, string $bodyText): string
{
    $fromEmail = mb_strtolower(trim(utf8_clean($fromEmail)));
    $fromName = utf8_clean($fromName);
    $subjectText = utf8_clean($subjectText);
    $bodyText = utf8_clean($bodyText);
    if ($fromName === '') $fromName = ucfirst(strtok($fromEmail, '@'));
    $isAuto = intake_is_auto_mail($fromEmail, $subjectText);
    $bodyText = trim(mb_substr(strip_quoted_reply($bodyText), 0, 8000));
    if ($bodyText === '') $bodyText = '(no text body)';

    // Threading: [#123] → reply on that ticket
    if (preg_match('/\[#(\d+)\]/', $subjectText, $m)) {
        $tid = (int)$m[1];
        $tq = db()->prepare('SELECT t.*, c.sla_tier FROM tickets t JOIN clients c ON c.id = t.client_id WHERE t.id = ? AND t.tenant_id = ?');
        $tq->execute([$tid, tenant_id()]);
        if ($ticket = $tq->fetch()) {
            db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
                ->execute([$tid, $fromName, 'client', $bodyText]);
            if (in_array($ticket['status'], ['waiting', 'resolved'], true)) {
                db()->prepare("UPDATE tickets SET status = 'open' WHERE id = ?")->execute([$tid]);
            }
            if (!empty($ticket['assignee_id'])) {
                $aq = db()->prepare('SELECT email FROM users WHERE id = ?');
                $aq->execute([(int)$ticket['assignee_id']]);
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
        // unknown ticket id → fall through to a fresh ticket
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
    $kq->execute([tenant_id(), $fromEmail]);
    if ($row = $kq->fetch()) {
        $contactId = (int)$row['id'];
        $clientId  = (int)$row['client_id'];
    } else {
        $domain = mb_substr((string)strrchr($fromEmail, '@'), 1);
        $cq = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND domain = ? AND domain != ""');
        $cq->execute([tenant_id(), $domain]);
        $clientId = $cq->fetch()['id'] ?? null;
        if (!$clientId) $clientId = intake_client_id();
        $clientId = (int)$clientId;
        db()->prepare('INSERT INTO contacts (client_id, name, email) VALUES (?,?,?)')
            ->execute([$clientId, $fromName, $fromEmail]);
        $contactId = (int)db()->lastInsertId();
    }

    $tq = db()->prepare('SELECT sla_tier FROM clients WHERE id = ?');
    $tq->execute([$clientId]);
    $hours = ($tq->fetch()['sla_tier'] ?? 'standard') === 'premium' ? 2 : 8;
    $subjectClean = trim(preg_replace('/\s*(re|fwd?):\s*/i', '', $subjectText)) ?: '(no subject)';

    db()->prepare('INSERT INTO tickets (tenant_id, client_id, contact_id, subject, priority, channel, sla_due_at) VALUES (?,?,?,?,?,"email",DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))')
        ->execute([tenant_id(), $clientId, $contactId, mb_substr($subjectClean, 0, 190), 'normal', $hours]);
    $tid = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
        ->execute([$tid, $fromName, 'client', $bodyText]);
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
        ->execute([$tid, 'Safeharbor', 'system', 'Ticket created from email · SLA response due in ' . $hours . ' hours'
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
function intake_client_id(): int
{
    $q = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND name = "Email Intake"');
    $q->execute([tenant_id()]);
    if ($row = $q->fetch()) return (int)$row['id'];
    db()->prepare('INSERT INTO clients (tenant_id, name, domain, sla_tier, notes) VALUES (?,?,"","standard",?)')
        ->execute([tenant_id(), 'Email Intake', 'Catch-all for tickets emailed by unknown senders. Reassign to the right client and it will learn their domain next time.']);
    return (int)db()->lastInsertId();
}

/** HTML → plaintext (crude but serviceable for intake bodies). */
function html_to_text(string $html): string
{
    $html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
    $html = str_replace(['</p>', '</div>', '</li>', '</tr>'], "\n", $html);
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}
