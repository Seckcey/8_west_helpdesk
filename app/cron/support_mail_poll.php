<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/intake.php';
require_once __DIR__ . '/../lib/support_mail_poll.php';
try {
    $supportConfig = (array)cfg('support_addresses', []);
    $graph = support_graph_config($supportConfig, mailer_graph_config());
    if ($graph === null) { echo "support intake disabled\n"; exit; }
    $dir = rtrim((string)($supportConfig['state_directory'] ?? ''), '/');
    if ($dir === '' || is_link($dir) || !is_dir($dir) || !is_writable($dir)
        || (fileperms($dir) & 0007) !== 0) throw new RuntimeException('private-state-directory-required');
    $lockPath = $dir . '/poll.lock';
    if (is_link($lockPath)) throw new RuntimeException('state-symlink');
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
    chmod($lockPath, 0600);
    $statePath = $dir . '/cursor.json';
    if (is_link($statePath)) throw new RuntimeException('state-symlink');
    $state = is_file($statePath) ? json_decode(file_get_contents($statePath), true, 16, JSON_THROW_ON_ERROR) : [];
    $error = null; $token = graph_token($graph, $error);
    if ($token === null) throw new RuntimeException('support-token-unavailable');
    $base = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($graph['sender']);
    $get = static function (string $path) use ($base, $token): array {
        $result = graph_http_get($base . $path, $token);
        if (($result['http'] ?? null) !== 200) throw new RuntimeException('support-mail-read-failed');
        return json_decode($result['body'], true, 64, JSON_THROW_ON_ERROR);
    };
    $intake = static function (array $message, int $tenant, ?array $clients) use ($get, $graph): string {
        $from = (string)($message['from']['emailAddress']['address'] ?? '');
        if (mb_strtolower($from) === $graph['sender']) return 'dropped:self-mail';
        $body = (string)($message['body']['content'] ?? '');
        if (strtolower((string)($message['body']['contentType'] ?? 'text')) === 'html') $body = html_to_text($body);
        $attachments = [];
        if (!empty($message['hasAttachments'])) {
            $result = $get('/messages/' . rawurlencode((string)$message['id']) . '/attachments?$top=5');
            foreach ((array)($result['value'] ?? []) as $a) {
                if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || (int)($a['size'] ?? 0) > 10*1024*1024) continue;
                $bytes = base64_decode((string)($a['contentBytes'] ?? ''), true);
                if ($bytes !== false && $bytes !== '') $attachments[] = ['name'=>$a['name'] ?? 'file','mime'=>$a['contentType'] ?? '', 'bytes'=>$bytes];
            }
        }
        return intake_message($from, (string)($message['from']['emailAddress']['name'] ?? ''),
            (string)($message['subject'] ?? ''), $body, $attachments,
            // Include workspace identity in conversation keys; external IDs are not authority.
            isset($message['conversationId']) ? 'support:' . $tenant . ':' . hash('sha256', $message['conversationId']) : null,
            $tenant, $clients);
    };
    $result = support_mail_poll(db(), $supportConfig, $state, $get, $intake,
        static fn(array $s) => support_mail_save_state($statePath, $s),
        static function (string $key, string $reason) use ($dir): void {
            $path = $dir . '/' . hash('sha256', $key) . '.held.json';
            support_mail_save_state($path, ['message_key'=>$key,'reason'=>$reason,'held_at'=>gmdate('c')]);
        });
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // Provider bodies, addresses and delta tokens must never enter scheduler logs.
    fwrite(STDERR, "support intake requires operator attention\n"); exit(1);
}
