<?php
/**
 * Email-to-ticket via Microsoft Graph — poll every minute via cron:
 *   * * * * * php /srv/8west/apps/safeharbor/current/cron/graph_poll.php
 *
 * Reads UNREAD messages from the intake mailbox (mail.graph.sender),
 * feeds them to lib/intake.php, then marks them read (never deletes).
 * Uses the same Entra app registration as outbound sendMail, plus the
 * Mail.Read APPLICATION permission (admin-consented). Inert until the
 * graph block is configured; logs plainly when Mail.Read is missing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/intake.php';

$g = mailer_graph_config();
if ($g === null) {
    exit("[" . gmdate('c') . " graph_poll: not configured, skipping]\n");
}

$err = null;
$token = graph_token($g, $err);
if ($token === null) {
    echo "[" . gmdate('c') . " graph_poll: {$err}]\n";
    exit(1);
}

$url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($g['sender'])
     . "/mailFolders('Inbox')/messages"
     . '?$filter=' . rawurlencode('isRead eq false')
     . '&$top=25&$select=id,subject,from,body,receivedDateTime,internetMessageId,conversationId,hasAttachments';

$r = graph_http_get($url, $token);
if (isset($r['error'])) {
    echo "[" . gmdate('c') . " graph_poll: {$r['error']}]\n";
    exit(1);
}
if ($r['http'] === 403 || $r['http'] === 401) {
    echo "[" . gmdate('c') . " graph_poll: HTTP {$r['http']} — the Entra app needs the Mail.Read APPLICATION permission with admin consent. Outbound (sendMail) is unaffected.]\n";
    exit(0); // not a hard failure; intake just isn't armed yet
}
if ($r['http'] !== 200) {
    echo "[" . gmdate('c') . " graph_poll: HTTP {$r['http']}: " . substr($r['body'], 0, 200) . "]\n";
    exit(1);
}

$j = json_decode($r['body'], true);
$messages = is_array($j['value'] ?? null) ? $j['value'] : [];

$made = 0;
$appended = 0;
$dropped = 0;
$duped = 0;
foreach ($messages as $msg) {
    $fromEmail = (string)($msg['from']['emailAddress']['address'] ?? '');
    $fromName  = (string)($msg['from']['emailAddress']['name'] ?? '');
    if ($fromEmail === '') continue;

    // Ignore our own outbound (confirmations etc.) if it ever lands unread
    if (mb_strtolower($fromEmail) === mb_strtolower($g['sender'])) {
        mark_read($g, $token, (string)$msg['id']);
        continue;
    }

    // Dedupe: a re-delivered or double-polled mail must never intake twice.
    $imid = trim((string)($msg['internetMessageId'] ?? ''));
    if ($imid !== '') {
        $dup = db()->prepare('INSERT IGNORE INTO processed_mail (internet_message_id) VALUES (?)');
        $dup->execute([mb_substr($imid, 0, 255)]);
        if ($dup->rowCount() === 0) {
            $duped++;
            mark_read($g, $token, (string)$msg['id']);
            continue;
        }
    }

    $subject = (string)($msg['subject'] ?? '');
    $body    = (string)($msg['body']['content'] ?? '');
    if (mb_strtolower((string)($msg['body']['contentType'] ?? 'text')) === 'html') {
        $body = html_to_text($body);
    }

    $result = intake_message(
        $fromEmail, $fromName, $subject, $body,
        !empty($msg['hasAttachments']) ? fetch_attachments($g, $token, (string)$msg['id']) : [],
        (string)($msg['conversationId'] ?? '') ?: null
    );
    if (str_starts_with($result, 'created:'))      $made++;
    elseif (str_starts_with($result, 'dropped:'))  $dropped++;
    else                                           $appended++;
    mark_read($g, $token, (string)$msg['id']);
}

if ($messages) echo "[" . gmdate('c') . " graph_poll: {$made} tickets created, {$appended} replies appended, {$dropped} auto-mails dropped, {$duped} duplicates skipped of " . count($messages) . " mails]\n";

/** File attachments for a message: [[name, mime, bytes]…] (≤10MB each, ≤5). */
function fetch_attachments(array $g, string $token, string $msgId): array
{
    $r = graph_http_get(
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($g['sender'])
          . '/messages/' . rawurlencode($msgId) . '/attachments?$top=5',
        $token
    );
    if (isset($r['error']) || $r['http'] !== 200) return [];
    $j = json_decode($r['body'], true);
    $out = [];
    foreach ((array)($j['value'] ?? []) as $a) {
        if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') continue;   // no inline/item refs
        if ((int)($a['size'] ?? 0) > 10 * 1024 * 1024) continue;
        $bytes = base64_decode((string)($a['contentBytes'] ?? ''), true);
        if ($bytes === false || $bytes === '') continue;
        $out[] = ['name' => (string)($a['name'] ?? 'file'), 'mime' => (string)($a['contentType'] ?? ''), 'bytes' => $bytes];
    }
    return $out;
}

function mark_read(array $g, string $token, string $id): void
{
    $ch = curl_init('https://graph.microsoft.com/v1.0/users/' . rawurlencode($g['sender']) . '/messages/' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => '{"isRead":true}',
    ]);
    curl_exec($ch);
    curl_close($ch);
}
