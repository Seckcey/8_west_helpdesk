<?php
/**
 * Outbound mail — same pattern as Milepost's lib/mailer.php:
 * producers queue rows in mail_queue; cron/mail_dispatch.php sends them.
 * SMTP via STARTTLS/SSL sockets; falls back to PHP mail() when no host.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Queue an email. Returns the queue id. */
function mail_queue(string $to, string $subject, string $bodyText, ?int $ticketId = null): int
{
    db()->prepare('INSERT INTO mail_queue (to_addr, subject, body_text, ticket_id) VALUES (?,?,?,?)')
        ->execute([$to, $subject, $bodyText, $ticketId]);
    return (int)db()->lastInsertId();
}

/** Send one email NOW (used by the dispatch cron). Returns true on success. */
function mail_send(string $to, string $subject, string $bodyText, ?string &$error = null): bool
{
    $smtp = cfg('mail.smtp') ?? [];
    $host = trim((string)($smtp['host'] ?? ''));
    if ($host === '') {
        // PHP mail() fallback — fine for a quick start, like Milepost.
        $headers = 'From: ' . mail_from_header() . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
        $ok = mail($to, mail_subject($subject), $bodyText, $headers);
        if (!$ok) $error = 'PHP mail() rejected the message';
        return $ok;
    }

    $port    = (int)($smtp['port'] ?? 587);
    $secure  = (string)($smtp['secure'] ?? 'tls'); // 'tls' | 'ssl' | ''
    $user    = (string)($smtp['user'] ?? '');
    $pass    = (string)($smtp['pass'] ?? '');
    $timeout = 20;

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $sock = @stream_socket_client($remote, $errno, $errstr, $timeout);
    if (!$sock) { $error = "connect: $errstr ($errno)"; return false; }
    stream_set_timeout($sock, $timeout);

    $read = static function () use ($sock): string {
        $data = '';
        while (($line = fgets($sock, 512)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break; // last line of reply
        }
        return $data;
    };
    $cmd = static function (string $c) use ($sock, $read): string {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $read(); // banner
    $ehlo = gethostname() ?: 'safeharbor';
    $cmd("EHLO $ehlo");
    if ($secure === 'tls') {
        $r = $cmd('STARTTLS');
        if (!str_starts_with($r, '220')) { fclose($sock); $error = 'STARTTLS refused'; return false; }
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock); $error = 'TLS handshake failed'; return false;
        }
        $cmd("EHLO $ehlo");
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (!str_starts_with($r, '235')) { fclose($sock); $error = 'SMTP auth failed'; return false; }
    }

    $from = cfg('mail.from') ?? ('safeharbor@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $cmd("MAIL FROM:<$from>");
    $cmd("RCPT TO:<$to>");
    $r = $cmd('DATA');
    if (!str_starts_with($r, '354')) { fclose($sock); $error = 'DATA refused: ' . trim($r); return false; }

    $headers = implode("\r\n", [
        'From: ' . mail_from_header(),
        "To: <$to>",
        'Subject: ' . mail_subject($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'safeharbor') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    // dot-stuff + CRLF-normalize
    $body = preg_replace('/\r?\n/', "\r\n", $bodyText);
    $body = preg_replace('/^\./m', '..', $body);
    fwrite($sock, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
    $r = $read();
    $cmd('QUIT');
    fclose($sock);

    if (!str_starts_with($r, '250')) { $error = 'send refused: ' . trim($r); return false; }
    return true;
}

function mail_from_header(): string
{
    $from = cfg('mail.from') ?? 'safeharbor@localhost';
    $name = cfg('mail.from_name') ?? 'Safeharbor';
    return sprintf('%s <%s>', mb_encode_mimeheader($name, 'UTF-8'), $from);
}

/** RFC2047-encode a subject when it contains non-ASCII. */
function mail_subject(string $subject): string
{
    return preg_match('/[^\x20-\x7E]/', $subject) ? mb_encode_mimeheader($subject, 'UTF-8') : $subject;
}

/** Convenience: standard reply notification to a ticket's contact. */
function mail_notify_reply(array $ticket, string $contactEmail, string $authorName, string $replyBody): void
{
    $subject = '[#' . (int)$ticket['id'] . '] ' . $ticket['subject'];
    $body = "Hi — {$authorName} replied to your ticket:\n\n"
          . wordwrap($replyBody, 78) . "\n\n"
          . "Reply to this email to add to the thread, or open the portal (coming soon).\n"
          . "— Safeharbor by 8 West IT";
    mail_queue($contactEmail, $subject, $body, (int)$ticket['id']);
}
