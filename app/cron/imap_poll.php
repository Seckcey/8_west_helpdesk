<?php
/**
 * Email-to-ticket via IMAP — provider-agnostic fallback (cron, 1-min).
 * Production on O365 uses cron/graph_poll.php instead; keep this for any
 * non-Microsoft mailbox (config: mail.imap). Processed mail is moved to
 * the "Safeharbor/Processed" folder (never deleted).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/intake.php';

$imap = cfg('mail.imap') ?? [];
if (empty($imap['host']) || empty($imap['user'])) {
    exit("[" . gmdate('c') . " imap_poll: not configured, skipping]\n");
}
if (!function_exists('imap_open')) {
    exit("[" . gmdate('c') . " imap_poll: php-imap extension missing]\n");
}

$host    = (string)$imap['host'];
$port    = (int)($imap['port'] ?? 993);
$folder  = (string)($imap['processed_folder'] ?? 'Safeharbor/Processed');
$address = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}' . ($imap['mailbox'] ?? 'INBOX');

$box = @imap_open($address, (string)$imap['user'], (string)($imap['pass'] ?? ''), 0, 1);
if (!$box) {
    echo "[" . gmdate('c') . " imap_poll: connect failed: " . imap_last_error() . "]\n";
    exit(1);
}

$ids = imap_search($box, 'UNSEEN') ?: [];
$made = 0;
$appended = 0;

foreach ($ids as $msgNo) {
    $header = imap_headerinfo($box, (int)$msgNo);
    if (!$header) continue;

    $subjectText = '';
    foreach (imap_mime_header_decode($header->subject ?? '') as $part) $subjectText .= $part->text;

    $fromEmail = '';
    $fromName = '';
    if (!empty($header->from[0])) {
        $fromEmail = mb_strtolower(($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? ''));
        foreach (imap_mime_header_decode((string)($header->from[0]->personal ?? '')) as $p) $fromName .= $p->text;
    }

    $body = fetch_text($box, (int)$msgNo);
    $result = intake_message($fromEmail, $fromName, $subjectText, $body);
    str_starts_with($result, 'created:') ? $made++ : $appended++;
    mark_processed($box, (int)$msgNo, $host, $port, $folder);
}

imap_close($box);
if ($ids) echo "[" . gmdate('c') . " imap_poll: {$made} tickets created, {$appended} replies appended of " . count($ids) . " mails]\n";

function fetch_text($box, int $msgNo): string
{
    $structure = imap_fetchstructure($box, $msgNo);
    if ($structure && ($structure->type ?? 0) === 1) {
        $plain = '';
        $html = '';
        foreach ($structure->parts as $i => $part) {
            $text = decode_part(imap_fetchbody($box, $msgNo, (string)($i + 1)), (int)($part->encoding ?? 0));
            if (($part->subtype ?? '') === 'PLAIN' && $plain === '') $plain = $text;
            if (($part->subtype ?? '') === 'HTML' && $html === '') $html = $text;
        }
        if ($plain !== '') return $plain;
        return html_to_text($html);
    }
    $text = decode_part(imap_body($box, $msgNo), (int)($structure->encoding ?? 0));
    if ($structure && mb_strtoupper((string)($structure->subtype ?? '')) === 'HTML') {
        $text = html_to_text($text);
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

function mark_processed($box, int $msgNo, string $host, int $port, string $folder): void
{
    $target = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}' . $folder;
    @imap_createmailbox($box, imap_utf7_encode($target));
    if (!@imap_mail_move($box, (string)$msgNo, imap_utf7_encode($folder))) {
        imap_setflag_full($box, (string)$msgNo, '\\Seen');
    }
    imap_expunge($box);
}
