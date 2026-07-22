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
     . '&$top=25&$select=id,subject,from,body,receivedDateTime';

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
foreach ($messages as $msg) {
    $fromEmail = (string)($msg['from']['emailAddress']['address'] ?? '');
    $fromName  = (string)($msg['from']['emailAddress']['name'] ?? '');
    if ($fromEmail === '') continue;

    // Ignore our own outbound (confirmations etc.) if it ever lands unread
    if (mb_strtolower($fromEmail) === mb_strtolower($g['sender'])) {
        mark_read($g, $token, (string)$msg['id']);
        continue;
    }

    $subject = (string)($msg['subject'] ?? '');
    $body    = (string)($msg['body']['content'] ?? '');
    if (mb_strtolower((string)($msg['body']['contentType'] ?? 'text')) === 'html') {
        $body = html_to_text($body);
    }

    $result = intake_message($fromEmail, $fromName, $subject, $body);
    str_starts_with($result, 'created:') ? $made++ : $appended++;
    mark_read($g, $token, (string)$msg['id']);
}

if ($messages) echo "[" . gmdate('c') . " graph_poll: {$made} tickets created, {$appended} replies appended of " . count($messages) . " mails]\n";

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
