<?php
/**
 * Operator-only, read-only certificate and mailbox-scope probe.
 *
 * Run only after the dedicated certificate and private key have been uploaded
 * outside the release tree and cfg('westy_email.graph') is installed:
 *
 *   php app/tests/westy_mail_graph_certificate_probe.php \
 *     --negative-mailbox=existing-controlled-mailbox-outside-westy-scope@example.com
 *
 * The negative mailbox must exist and be licensed, but must not be in the
 * Entra/Exchange application scope. Only an HTTP 403 proves that boundary;
 * HTTP 404 can mean the test mailbox was wrong and is treated as a failure.
 *
 * This script performs GET requests only, selects only message id, requests at
 * most one row, never marks mail read, and never prints message data, tokens,
 * certificate paths, keys, provider error bodies, or email addresses.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$options = getopt('', ['negative-mailbox:', 'help']);
if (array_key_exists('help', $options)) {
    echo "Usage: php app/tests/westy_mail_graph_certificate_probe.php "
        . "--negative-mailbox=EXISTING_OUT_OF_SCOPE_MAILBOX\n";
    exit(0);
}
$negativeInput = $options['negative-mailbox'] ?? null;
if (!is_string($negativeInput) || $negativeInput === '') {
    fwrite(STDERR, "A real existing out-of-scope --negative-mailbox is required.\n");
    exit(2);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/westy_mail_graph.php';

$graph = westy_mail_graph_config();
if ($graph === null) {
    fwrite(STDERR, "FAIL dedicated Westy Graph certificate configuration is unavailable.\n");
    exit(1);
}
$identity = westy_mail_graph_identity($graph);
$negativeMailbox = westy_mail_graph_email($negativeInput);
if ($negativeMailbox === null || hash_equals($graph['mailbox'], $negativeMailbox)) {
    fwrite(STDERR, "FAIL negative mailbox must be a different valid mailbox.\n");
    exit(2);
}

echo 'Transport identity SHA-256: ' . hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)) . "\n";
echo 'Certificate SHA-256: ' . $identity['certificate_sha256'] . "\n";

$positive = westy_mail_graph_get(
    $graph,
    'mailFolders/inbox/messages?$top=1&$select=id',
);
if (($positive['outcome'] ?? null) !== 'ok' || ($positive['provider_http'] ?? null) !== 200) {
    fwrite(
        STDERR,
        'FAIL exact Westy Inbox read: HTTP '
            . (($positive['provider_http'] ?? null) === null ? 'none' : (string)$positive['provider_http'])
            . ', code ' . (string)($positive['outcome_code'] ?? 'missing') . "\n",
    );
    exit(1);
}
$messageCount = is_array($positive['data']['value'] ?? null) ? count($positive['data']['value']) : 0;
echo 'PASS exact Westy Inbox read: HTTP 200, rows returned ' . min(1, $messageCount)
    . ', request ' . (string)($positive['provider_request_id'] ?? 'unavailable') . "\n";

// Deliberately bypass the normal helper's exact-mailbox URL pin only for this
// fixed, read-only negative authorization probe. No response body is decoded,
// returned, or printed even if the server is mis-scoped and answers 200.
$token = westy_mail_graph_token($graph, 'westy_mail_graph_http');
if (($token['token'] ?? null) === null) {
    fwrite(
        STDERR,
        'FAIL token for negative mailbox check: HTTP '
            . (($token['provider_http'] ?? null) === null ? 'none' : (string)$token['provider_http'])
            . ', code ' . (string)($token['outcome_code'] ?? 'missing') . "\n",
    );
    exit(1);
}

$clientRequestId = westy_mail_graph_uuid();
$negativeUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($negativeMailbox)
    . '/mailFolders/inbox/messages?$top=1&$select=id';
$negative = westy_mail_graph_http(
    'GET',
    $negativeUrl,
    [
        'Authorization: Bearer ' . $token['token'],
        'Accept: application/json',
        'client-request-id: ' . $clientRequestId,
        'return-client-request-id: true',
    ],
    '',
    $graph['timeout_seconds'],
);
$negativeHttp = westy_mail_graph_provider_http($negative);
$negativeRequestId = westy_mail_graph_request_id($negative) ?? $clientRequestId;
unset($token, $negative);

if ($negativeHttp !== 403) {
    fwrite(
        STDERR,
        'FAIL out-of-scope mailbox was not denied with HTTP 403; observed '
            . ($negativeHttp === null ? 'no bounded HTTP response' : 'HTTP ' . $negativeHttp)
            . '. Do not enable polling or sending. Request ' . $negativeRequestId . "\n",
    );
    exit(1);
}

echo 'PASS out-of-scope mailbox denied: HTTP 403, request ' . $negativeRequestId . "\n";
echo "PASS read-only certificate and mailbox-scope probe. No message was sent or marked read.\n";
