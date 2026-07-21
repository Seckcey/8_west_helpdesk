<?php
/**
 * Email-to-ticket — poll the intake mailbox every minute via cron:
 *   * * * * * php /srv/8west/apps/safeharbor/current/cron/imap_poll.php
 *
 * How it works:
 *  - Reads UNSEEN mail from the intake mailbox (config: mail.imap).
 *  - Subject contains [#123]  → appends a client message to ticket 123
 *    (and pings the assignee), else
 *  - Creates a new ticket: sender matched to a contact by email; unknown
 *    senders are auto-created as contacts — under their domain's client if
 *    we know it, else under the "Email Intake" catch-all client.
 *  - New tickets get a confirmation reply with the ticket number.
 *  - Processed mail is moved to the "Safeharbor/Processed" folder (created
 *    on demand), so nothing is ever read twice and nothing is deleted.
 *
 * Intake is inert until mail.imap.host is set — safe to deploy first and
 * arm later.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/mailer.php';

$imap = cfg('mail.imap') ?? [];
if (empty($imap['host']) || empty($imap['user'])) {
    exit("[" . gmdate('c') . " imap_poll: not configured, skipping]\n");
}
if (!function_exists('imap_open')) {
    exit("[" . gmdate('c') . " imap_poll: php-imap extension missing]\n");
}

$host     = (string)$imap['host'];
$port     = (int)($imap['port'] ?? 993);
$user     = (string)$imap['user'];
$pass     = (string)($imap['pass'] ?? '');
$mailbox  = (string)($imap['mailbox'] ?? 'INBOX');
$folder   = (string)($imap['processed_folder'] ?? 'Safeharbor/Processed');
$address  = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}' . $mailbox;

$box = @imap_open($address, $user, $pass, 0, 1);
if (!$box) {
    echo "[" . gmdate('c') . " imap_poll: connect failed: " . imap_last_error() . "]\n";
    exit(1);
}

$ids = imap_search($box, 'UNSEEN') ?: [];
$made = 0;
$appended = 0;

foreach ($ids as $uid) {
    $msgNo = (int)$uid;
    $header = imap_headerinfo($box, $msgNo);
    if (!$header) continue;

    $subject = imap_mime_header_decode($header->subject ?? '');
    $subjectText = '';
    foreach ($subject as $part) $subjectText .= $part->text;

    $fromEmail = '';
    $fromName = '';
    if (!empty($header->from[0])) {
        $fromEmail = mb_strtolower(($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? ''));
        $fromName  = (string)($header->from[0]->personal ?? '');
        if ($fromName !== '') {
            $decoded = imap_mime_header_decode($fromName);
            $fromName = '';
            foreach ($decoded as $p) $fromName .= $p->text;
        }
    }
    if ($fromName === '') $fromName = ucfirst(strtok($fromEmail, '@'));

    $body = imap_fetch_text($box, $msgNo);
    $bodyText = trim(mb_substr(strip_quoted_reply($body), 0, 8000));
    if ($bodyText === '') $bodyText = '(no text body)';

    // Threading: [#123] in the subject → reply on that ticket
    if (preg_match('/\[#(\d+)\]/', $subjectText, $m)) {
        $tid = (int)$m[1];
        $tq = db()->prepare('SELECT t.*, c.sla_tier FROM tickets t JOIN clients c ON c.id = t.client_id WHERE t.id = ? AND t.tenant_id = ?');
        $tq->execute([$tid, tenant_id()]);
        if ($ticket = $tq->fetch()) {
            db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
                ->execute([$tid, $fromName, 'client', $bodyText]);
            if ($ticket['status'] === 'waiting' || $ticket['status'] === 'resolved') {
                db()->prepare("UPDATE tickets SET status = 'open' WHERE id = ?")->execute([$tid]);
            }
            // ping the assignee by email
            if (!empty($ticket['assignee_id'])) {
                $aq = db()->prepare('SELECT email, full_name FROM users WHERE id = ?');
                $aq->execute([(int)$ticket['assignee_id']]);
                if ($tech = $aq->fetch()) {
                    mail_queue(
                        $tech['email'],
                        '[#' . $tid . '] Client replied: ' . $ticket['subject'],
                        "Client reply from {$fromName} ({$fromEmail}):\n\n" . wordwrap($bodyText, 78)
                          . "\n\nOpen: https://" . ($_SERVER['HTTP_HOST'] ?? 'safeharbor.8westit.com') . "/ticket.php?id=" . $tid,
                        $tid
                    );
                }
            }
            $appended++;
            mark_processed($box, $msgNo, $imap, $folder);
            continue;
        }
        // unknown ticket id → fall through and create a fresh ticket
    }

    // New ticket: match sender to a contact, else to a client by domain
    $contactId = null;
    $clientId = null;
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
        // auto-create the contact so future mail threads cleanly
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
        ->execute([$tid, 'Safeharbor', 'system', 'Ticket created from email · SLA response due in ' . $hours . ' hours']);

    mail_queue(
        $fromEmail,
        '[#' . $tid . '] ' . $subjectClean,
        "Hi {$fromName},\n\nWe've got it — ticket #{$tid} is open and a tech will reply within your SLA window.\n\n"
          . "Reply to this email any time to add to the thread (keep [#{$tid}] in the subject).\n"
          . "— Safeharbor by 8 West IT",
        $tid
    );
    $made++;
    mark_processed($box, $msgNo, $imap, $folder);
}

imap_close($box);
if ($ids) echo "[" . gmdate('c') . " imap_poll: {$made} tickets created, {$appended} replies appended of " . count($ids) . " mails]\n";

/* ------------------------------------------------------------------ */

/** Strip quoted history from a reply (Outlook/Gmail/plaintext patterns). */
function strip_quoted_reply(string $body): string
{
    $cutters = [
        '/^On .{5,80}wrote:\s*$/m',
        '/^-{2,}\s*Original Message\s*-{2,}\s*$/mi',
        '/^>{1}\s?/m',
        '/^From:\s.*\nSent:\s.*\nTo:\s.*\nSubject:\s.*$/m',
        '/^_{5,}\s*$/m',
    ];
    $body = preg_replace('/\r\n?/', "\n", (string)$body);
    foreach ($cutters as $re) {
        if (preg_match($re, $body, $m, PREG_OFFSET_CAPTURE)) {
            $body = substr($body, 0, $m[0][1]);
        }
    }
    return trim($body);
}

/** Plaintext body, preferring text/plain over HTML-stripped. */
function imap_fetch_text($box, int $msgNo): string
{
    $structure = imap_fetchstructure($box, $msgNo);
    if ($structure && ($structure->type ?? 0) === 1) { // multipart
        $plain = '';
        $html = '';
        foreach ($structure->parts as $i => $part) {
            $text = imap_fetchbody($box, $msgNo, (string)($i + 1));
            $text = decode_part($text, (int)($part->encoding ?? 0));
            if (($part->subtype ?? '') === 'PLAIN' && $plain === '') $plain = $text;
            if (($part->subtype ?? '') === 'HTML' && $html === '') $html = $text;
        }
        if ($plain !== '') return $plain;
        return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    }
    $text = imap_body($box, $msgNo);
    $text = decode_part($text, (int)($structure->encoding ?? 0));
    if ($structure && mb_strtoupper((string)($structure->subtype ?? '')) === 'HTML') {
        $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $text)), ENT_QUOTES, 'UTF-8'));
    }
    return $text;
}

function decode_part(string $text, int $encoding): string
{
    return match ($encoding) {
        3       => base64_decode($text),
        4       => quoted_printable_decode($text),
        default => $text,
    };
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

/** Move a processed message out of the inbox (never delete). */
function mark_processed($box, int $msgNo, array $imap, string $folder): void
{
    $host = (string)$imap['host'];
    $port = (int)($imap['port'] ?? 993);
    $target = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}' . $folder;
    @imap_createmailbox($box, imap_utf7_encode($target));
    if (!@imap_mail_move($box, (string)$msgNo, imap_utf7_encode($folder))) {
        imap_setflag_full($box, (string)$msgNo, '\\Seen');
    }
    imap_expunge($box);
}
