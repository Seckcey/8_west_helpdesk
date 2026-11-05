<?php
declare(strict_types=1);

$deviceMailerConfig = [];
function cfg(string $key, mixed $default = null): mixed
{
    global $deviceMailerConfig;
    return $deviceMailerConfig[$key] ?? $default;
}

$ordinaryGraph = [
    'tenant_id' => 'fixture-tenant',
    'client_id' => 'fixture-app',
    'client_secret' => 'synthetic',
    'sender' => 'missioncontrol@example.test',
];
$ordinaryUrl = 'https://graph.microsoft.com/v1.0/users/missioncontrol%40example.test/sendMail';
$supportUrl = 'https://graph.microsoft.com/v1.0/users/safeharbor%40example.test/sendMail';
$tokenUrl = 'https://login.microsoftonline.com/fixture-tenant/oauth2/v2.0/token';
$cases = [
    'linked device owner without a ticket reply route' => [true, false, $supportUrl, 202, 'provider_accepted', 'graph_accepted'],
    'valid ticket reply route' => [true, true, $supportUrl, 202, 'provider_accepted', 'graph_accepted'],
    'disabled support route' => [false, true, $ordinaryUrl, 202, 'provider_accepted', 'graph_accepted'],
    'definitive support rejection' => [true, false, $supportUrl, 404, 'failed', 'graph_send_rejected'],
    'lost support response' => [true, false, $supportUrl, null, 'uncertain', 'graph_send_transport_error'],
    'reply route without Graph transport' => [true, true, null, null, 'failed', 'support_transport_unavailable'],
];

$caseNumber = 500;
foreach ($cases as $name => [$enabled, $replyAllowed, $sendUrl, $http, $outcome, $code]) {
    $graph = $sendUrl === null ? null : $ordinaryGraph;
    $deviceMailerConfig = [
        'mail.graph' => $graph,
        'mail.smtp' => ['host' => 'fixture-disabled://unavailable'],
        'support_addresses' => [
            'enabled' => $enabled,
            'mailbox' => 'safeharbor@example.test',
            'tenant_ids' => [1],
            'client_ids_by_tenant' => [1 => $replyAllowed ? [1] : []],
        ],
    ];
    $calls = [];
    $post = static function (string $url, array $headers, string $payload) use (
        &$calls, $tokenUrl, $ordinaryUrl, $enabled, $http
    ): array {
        $calls[] = [$url, $payload];
        if ($url === $tokenUrl) {
            return ['http' => 200, 'body' => '{"access_token":"synthetic"}'];
        }
        if ($enabled && $url === $ordinaryUrl) {
            return ['http' => 404, 'body' => '{"error":{"code":"MailboxNotEnabledForRESTAPI"}}'];
        }
        if ($http === null) {
            throw new RuntimeException('Simulated lost provider response after submission');
        }
        return ['http' => $http, 'body' => ''];
    };
    $sender = static fn(PDO $pdo, array $notice, string $to, string $subject, string $body): array =>
        westy_hardware_send($pdo, $notice, $to, $subject, $body, $post);

    $finding = hw_case($pdo, ++$caseNumber);
    $receipt = hw_send($pdo, $finding);
    $noticeId = $receipt['notice_id'];
    $state = westy_hardware_dispatch($pdo, $noticeId, hw_settings(), hw_resolver(), $sender);
    $attempt = westy_workflow_row($pdo, 'SELECT * FROM westy_hardware_mail_attempts WHERE tenant_id=1 AND notice_id=?', [$noticeId]);
    $urls = array_column($calls, 0);
    $expectedUrls = $sendUrl === null ? [] : [$tokenUrl, $sendUrl];
    hw_check($urls === $expectedUrls, $name.'. Expected Graph requests '.implode(', ', $expectedUrls)
        .' and got '.implode(', ', $urls).' with outcome '.($attempt['outcome_code'] ?? 'missing')
        .' (HTTP '.($attempt['provider_http'] ?? 'unknown').').');
    hw_check($state === $outcome && $attempt['outcome'] === $outcome && $attempt['outcome_code'] === $code
        && $attempt['transport'] === ($sendUrl === null ? 'none' : 'graph')
        && $attempt['provider_http'] === $http && $attempt['completed_at'] !== null, $name.' saves exact attempt evidence');

    $notice = westy_workflow_row($pdo, 'SELECT * FROM westy_hardware_notices WHERE tenant_id=1 AND id=?', [$noticeId]);
    $queue = westy_workflow_row($pdo, 'SELECT * FROM mail_queue WHERE id=?', [$notice['mail_queue_id']]);
    $recipient = 'device-person@example.test';
    hw_check($queue['to_addr'] === $recipient && str_contains($queue['body_text'], $finding['upgrade_options'])
        && (int)$queue['max_attempts'] === 0 && (int)$queue['attempts'] === 1
        && ($queue['sent_at'] !== null) === ($outcome === 'provider_accepted')
        && $attempt['message_sha256'] === hash('sha256', westy_hardware_json([$recipient, $queue['subject'], $queue['body_text']])),
        $name.' preserves the linked device recipient, held message and attempt hash');
    if ($sendUrl !== null) {
        $message = json_decode($calls[1][1], true, 16, JSON_THROW_ON_ERROR)['message'];
        $reply = $enabled && $replyAllowed ? [['emailAddress' => ['address' => 'safeharbor+w365-1@example.test']]] : null;
        hw_check($message['toRecipients'] === [['emailAddress' => ['address' => $recipient]]]
            && $message['subject'] === $queue['subject'] && $message['body']['content'] === $queue['body_text']
            && ($message['replyTo'] ?? null) === $reply, $name.' sends the held content with the expected reply address');
    }
    if ($outcome === 'uncertain') {
        hw_check(westy_hardware_dispatch($pdo, $noticeId, hw_settings(), hw_resolver(), $sender) === 'uncertain'
            && array_column($calls, 0) === $expectedUrls, 'lost device-owner response does not resubmit');
    }
    hw_check(mailer_graph_config() === $graph, $name.' preserves ordinary Graph configuration');
}

echo 'PASS '.count($cases)." linked device-owner sender and submission evidence cases.\n";
