<?php
declare(strict_types=1);

$staffMailConfig = [];
function cfg(string $key, mixed $default = null): mixed
{
    global $staffMailConfig;
    return $staffMailConfig[$key] ?? $default;
}

require_once __DIR__.'/../lib/westy_hardware_staff.php';

$ordinaryGraph = [
    'tenant_id' => 'fixture-tenant',
    'client_id' => 'fixture-app',
    'client_secret' => 'synthetic',
    'sender' => 'missioncontrol@example.test',
];
$ordinaryUrl = 'https://graph.microsoft.com/v1.0/users/missioncontrol%40example.test/sendMail';
$supportUrl = 'https://graph.microsoft.com/v1.0/users/safeharbor%40example.test/sendMail';
$tokenUrl = 'https://login.microsoftonline.com/fixture-tenant/oauth2/v2.0/token';
$recipient = 'staff@example.test';
$subject = 'Hardware upgrade options';
$body = 'Evaluate a supported upgrade for the recorded hardware constraint.';

foreach ([
    [true, 202, $supportUrl, 'provider_accepted', 202, 'graph_accepted'],
    [true, 404, $supportUrl, 'failed', 404, 'graph_send_rejected'],
    [true, null, $supportUrl, 'uncertain', null, 'graph_send_transport_error'],
    [false, 202, $ordinaryUrl, 'provider_accepted', 202, 'graph_accepted'],
] as [$enabled, $status, $expectedUrl, $outcome, $http, $code]) {
    $staffMailConfig = [
        'mail.graph' => $ordinaryGraph,
        'support_addresses' => ['enabled' => $enabled, 'mailbox' => 'safeharbor@example.test'],
    ];
    $requests = [];
    $post = static function (string $url, array $headers, string $payload) use (
        &$requests, $tokenUrl, $ordinaryUrl, $enabled, $status
    ): array {
        $requests[] = [$url, $payload];
        if ($url === $tokenUrl) {
            return ['http' => 200, 'body' => '{"access_token":"synthetic"}'];
        }
        if ($enabled && $url === $ordinaryUrl) {
            return ['http' => 404, 'body' => '{"error":{"code":"MailboxNotEnabledForRESTAPI"}}'];
        }
        if ($status === null) {
            throw new RuntimeException('Simulated lost provider response after submission');
        }
        return ['http' => $status, 'body' => ''];
    };

    $result = westy_hardware_staff_send($recipient, $subject, $body, $post);
    $urls = array_column($requests, 0);
    if ($urls !== [$tokenUrl, $expectedUrl]) {
        throw new RuntimeException('Staff sender mismatch. Expected '.$expectedUrl
            .' and got '.implode(', ', $urls).' with outcome '.$result['outcome_code']
            .' (HTTP '.($result['provider_http'] ?? 'unknown').').');
    }
    $message = json_decode($requests[1][1], true, 16, JSON_THROW_ON_ERROR)['message'];
    if ($message['toRecipients'] !== [['emailAddress' => ['address' => $recipient]]]
        || $message['subject'] !== $subject || $message['body']['content'] !== $body
        || isset($message['replyTo'])) {
        throw new RuntimeException('Sender selection changed the frozen staff message or its reply route.');
    }
    if ($result !== ['outcome' => $outcome, 'transport' => 'graph', 'provider_http' => $http, 'outcome_code' => $code]) {
        throw new RuntimeException('Unexpected staff submission evidence for '.($status ?? 'lost response').'.');
    }
    if (mailer_graph_config() !== $ordinaryGraph) {
        throw new RuntimeException('Staff sender selection changed the default Graph configuration.');
    }
}

echo "PASS 4 staff Graph sender and submission evidence cases.\n";
