<?php
/** Dedicated support mailbox polling; Mail.Read is sufficient. Never marks or deletes mail. */
declare(strict_types=1);
require_once __DIR__ . '/support_addresses.php';

function support_mail_delta_path(): string
{
    return '/mailFolders/inbox/messages/delta?$select=id,subject,from,toRecipients,ccRecipients,bccRecipients,body,receivedDateTime,internetMessageId,conversationId,hasAttachments&$top=25';
}

/** Continuation URLs may only address this mailbox's Inbox on Microsoft Graph. */
function support_mail_continuation(string $url, string $mailbox): string
{
    if (strlen($url) > 16384 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new RuntimeException('invalid-continuation');
    $u = parse_url($url);
    $expected = '/v1.0/users/' . rawurlencode($mailbox) . '/mailFolders/inbox/messages/delta';
    if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || ($u['host'] ?? '') !== 'graph.microsoft.com'
        || isset($u['user']) || isset($u['pass']) || isset($u['port']) || isset($u['fragment'])
        || !in_array(strtolower(rawurldecode($u['path'] ?? '')), [strtolower(rawurldecode($expected)),
            strtolower(rawurldecode(str_replace('/mailFolders/inbox/', "/mailFolders('inbox')/", $expected)))], true)
        || !isset($u['query'])) throw new RuntimeException('invalid-continuation');
    return '/mailFolders/inbox/messages/delta?' . $u['query'];
}

function support_mail_key(string $mailbox, array $message): string
{
    $id = trim((string)($message['internetMessageId'] ?? ''));
    if ($id === '') $id = (string)($message['id'] ?? '');
    if ($id === '') throw new RuntimeException('message-id-missing');
    return 'support-v1:' . hash('sha256', $mailbox . "\n" . $id);
}

/** Commit a page cursor only after all its messages have durable outcomes. */
function support_mail_save_state(string $path, array $state): void
{
    if (is_link($path)) throw new RuntimeException('state-symlink');
    $tmp = tempnam(dirname($path), '.support-');
    if ($tmp === false) throw new RuntimeException('state-write');
    try {
        chmod($tmp, 0600);
        $f = fopen($tmp, 'wb');
        if (!$f) throw new RuntimeException('state-write');
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (fwrite($f, $json) !== strlen($json) || !fflush($f) || !fsync($f)) throw new RuntimeException('state-write');
        } finally { fclose($f); }
        if (!rename($tmp, $path)) throw new RuntimeException('state-write');
    } finally { if (file_exists($tmp)) unlink($tmp); }
}

/** Injected callbacks keep transport and tenant isolation independently testable. */
function support_mail_poll(PDO $pdo, array $config, array $state, callable $get, callable $intake, callable $save, callable $hold): array
{
    $mailbox = mb_strtolower((string)$config['mailbox']);
    $identity = hash('sha256', $mailbox);
    if (isset($state['mailbox_sha256']) && !hash_equals($identity, $state['mailbox_sha256'])) throw new RuntimeException('mailbox-changed');
    $path = $state['next_path'] ?? support_mail_delta_path();
    // Re-validate even a private persisted cursor before attaching a token.
    $path = support_mail_continuation('https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox) . $path, $mailbox);
    $enabled = support_enabled_tenants($pdo, $config);
    $counts = ['created'=>0, 'appended'=>0, 'held'=>0, 'dropped'=>0, 'duplicate'=>0];
    for ($page = 0; $page < 4; $page++) {
        $data = $get($path);
        if (!is_array($data['value'] ?? null)) throw new RuntimeException('invalid-mail-page');
        foreach ($data['value'] as $message) {
            if (isset($message['@removed'])) continue;
            $key = support_mail_key($mailbox, $message);
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('INSERT IGNORE INTO processed_mail (internet_message_id) VALUES (?)');
                $q->execute([$key]);
                if ($q->rowCount() === 0) { $pdo->commit(); $counts['duplicate']++; continue; }
                $tenant = support_recipient_tenant(array_merge((array)($message['toRecipients'] ?? []),
                    (array)($message['ccRecipients'] ?? []), (array)($message['bccRecipients'] ?? [])), $mailbox, $enabled);
                if ($tenant !== null) {
                    $q = $pdo->prepare('SELECT id FROM tenants WHERE id=?'); $q->execute([$tenant]);
                    if ($q->fetchColumn() === false) $tenant = null;
                }
                $result = $tenant === null ? 'held:recipient-not-routable'
                    : $intake($message, $tenant, $config['client_ids_by_tenant'][$tenant] ?? null);
                $kind = explode(':', $result, 2)[0];
                if (!in_array($kind, ['created','appended','held','dropped'], true)) throw new RuntimeException('invalid-intake-result');
                if ($kind === 'held') $hold($key, $result); // no body/address stored in the operator receipt
                $pdo->commit(); $counts[$kind]++;
            } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
        }
        $next = $data['@odata.nextLink'] ?? $data['@odata.deltaLink'] ?? null;
        if (!is_string($next)) throw new RuntimeException('mail-cursor-missing');
        $path = support_mail_continuation($next, $mailbox);
        $save(['mailbox_sha256'=>$identity, 'next_path'=>$path, 'last_success_at'=>gmdate('c')]);
        if (!isset($data['@odata.nextLink'])) break;
    }
    return $counts;
}
