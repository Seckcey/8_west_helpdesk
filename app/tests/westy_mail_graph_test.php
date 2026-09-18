<?php
/** Hermetic certificate-authenticated Microsoft Graph transport tests. */
declare(strict_types=1);

$settings = [];
function cfg(string $key, mixed $default = null): mixed
{
    global $settings;
    $value = $settings;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

require_once __DIR__ . '/../lib/westy_mail_graph.php';

$passed = 0;
$failed = 0;
function graph_check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        $failed++;
        echo "FAIL {$name}\n";
    }
}

function graph_b64url_decode(string $value): string|false
{
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
}

/** @param list<array<string,mixed>|Throwable> $responses */
function graph_http_fixture(array $responses, array &$requests): callable
{
    $position = 0;
    return static function (string $method, string $url, array $headers, string $body, int $timeout) use (&$position, $responses, &$requests): array {
        $requests[] = compact('method', 'url', 'headers', 'body', 'timeout');
        if (!array_key_exists($position, $responses)) throw new RuntimeException('Unexpected HTTP request.');
        $response = $responses[$position++];
        if ($response instanceof Throwable) throw $response;
        return $response;
    };
}

function graph_token_response(string $requestId = 'token-request-id'): array
{
    return [
        'http' => 200,
        'body' => json_encode(['access_token' => 'synthetic-access-token', 'expires_in' => 3600], JSON_THROW_ON_ERROR),
        'headers' => ['request-id' => $requestId],
    ];
}

function graph_mail(array $changes = []): array
{
    return array_replace([
        'recipient' => 'approved-recipient@example.test',
        'subject' => 'Approved case update',
        'body_text' => "The reviewed update.\n\nWesty, on behalf of 8 West IT",
        'message_key' => '0123456789abcdef0123456789abcdef',
    ], $changes);
}

/* Build a disposable RSA key/certificate pair. No runtime credential is read. */
$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safeharbor-westy-graph-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('Could not create disposable certificate directory.');
}
$privateKeyPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'private-key.pem';
$certificatePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'certificate.pem';
$opensslConfigPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'openssl.cnf';
$previousOpenSslConfig = getenv('OPENSSL_CONF');
file_put_contents($opensslConfigPath, <<<'OPENSSL'
openssl_conf = openssl_init

[openssl_init]
providers = provider_sect

[provider_sect]
default = default_sect

[default_sect]
activate = 1

[req]
distinguished_name = req_distinguished_name

[req_distinguished_name]
OPENSSL
);
putenv('OPENSSL_CONF=' . $opensslConfigPath);
// Some Windows PHP packages point OpenSSL at a nonexistent machine-wide
// config. Re-exec once so the extension sees this disposable config at process
// startup; Linux containers take the same hermetic path.
if (getenv('SAFEHARBOR_WESTY_GRAPH_TEST_REEXEC') !== '1') {
    putenv('SAFEHARBOR_WESTY_GRAPH_TEST_REEXEC=1');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    passthru($command, $childExit);
    if ($previousOpenSslConfig === false) putenv('OPENSSL_CONF');
    else putenv('OPENSSL_CONF=' . $previousOpenSslConfig);
    putenv('SAFEHARBOR_WESTY_GRAPH_TEST_REEXEC');
    @unlink($opensslConfigPath);
    @rmdir($temporaryDirectory);
    exit($childExit);
}
$key = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'digest_alg' => 'sha256',
]);
if ($key === false) throw new RuntimeException('Could not create disposable RSA key.');
$csr = openssl_csr_new(['commonName' => 'westy-mail-transport.test'], $key, ['digest_alg' => 'sha256']);
if ($csr === false) throw new RuntimeException('Could not create disposable certificate request.');
$certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
if ($certificate === false) throw new RuntimeException('Could not create disposable certificate.');
$privatePem = '';
$certificatePem = '';
if (!openssl_pkey_export($key, $privatePem) || !openssl_x509_export($certificate, $certificatePem)) {
    throw new RuntimeException('Could not export disposable certificate material.');
}
file_put_contents($privateKeyPath, $privatePem);
file_put_contents($certificatePath, $certificatePem);
@chmod($privateKeyPath, 0600);
@chmod($certificatePath, 0644);

$rawConfig = [
    'enabled' => true,
    'tenant_id' => '11111111-2222-4333-8444-555555555555',
    'client_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'mailbox' => 'westy@example.test',
    'sender' => 'westy@example.test',
    'private_key_path' => $privateKeyPath,
    'certificate_path' => $certificatePath,
    'timeout_seconds' => 15,
];
$settings = ['westy_email' => ['graph' => $rawConfig]];
$config = westy_mail_graph_config();
graph_check('dedicated config loads when every exact field and certificate match', is_array($config));

$settings = [];
graph_check('missing dedicated config fails closed', westy_mail_graph_config() === null);
$settings = ['mail' => ['graph' => ['enabled' => true]], 'business_reports' => ['graph_sender' => 'other@example.test']];
graph_check('legacy mail and report config cannot enable Westy transport', westy_mail_graph_config() === null);
$settings = ['westy_mail' => ['graph' => $rawConfig]];
graph_check('near-miss westy_mail.graph namespace cannot enable transport', westy_mail_graph_config() === null);
$settings = ['westy_email' => ['graph' => array_replace($rawConfig, ['enabled' => false])]];
graph_check('disabled dedicated config fails closed', westy_mail_graph_config() === null);
$settings = ['westy_email' => ['graph' => $rawConfig]];

$identity = westy_mail_graph_identity($rawConfig);
graph_check('identity contains stable nonsecret exact transport inputs',
    ($identity['tenant_id'] ?? '') === strtolower($rawConfig['tenant_id'])
    && ($identity['client_id'] ?? '') === strtolower($rawConfig['client_id'])
    && ($identity['mailbox'] ?? '') === 'westy@example.test'
    && preg_match('/\A[a-f0-9]{64}\z/D', (string)($identity['certificate_sha256'] ?? '')) === 1);
graph_check('identity never exposes certificate or private-key paths',
    !array_key_exists('private_key_path', $identity)
    && !array_key_exists('certificate_path', $identity)
    && !str_contains(json_encode($identity, JSON_THROW_ON_ERROR), $temporaryDirectory));
graph_check('identity is stable for the same certificate and exact app', $identity === westy_mail_graph_identity($rawConfig));

$badCertificatePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'invalid-certificate.pem';
file_put_contents($badCertificatePath, "not a certificate\n");
graph_check('invalid certificate is refused before transport',
    westy_mail_graph_identity(array_replace($rawConfig, ['certificate_path' => $badCertificatePath])) === []);
graph_check('different sender and mailbox are refused',
    westy_mail_graph_identity(array_replace($rawConfig, ['sender' => 'delegate@example.test'])) === []);
graph_check('non-GUID tenant identity is refused',
    westy_mail_graph_identity(array_replace($rawConfig, ['tenant_id' => 'organizations'])) === []);

/* Successful send, assertion structure, exact recipient, and sent-item default. */
$requests = [];
$http = graph_http_fixture([
    graph_token_response(),
    ['http' => 202, 'body' => '', 'headers' => ['request-id' => 'provider-send-id']],
], $requests);
$result = westy_mail_graph_send($rawConfig, graph_mail(), $http);
graph_check('only Graph 202 is submitted',
    $result === ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'send_accepted', 'provider_request_id' => 'provider-send-id']);
graph_check('successful send crosses exactly one token and one send request', count($requests) === 2);
graph_check('token request uses the exact tenant and certificate client credentials grant',
    ($requests[0]['method'] ?? '') === 'POST'
    && ($requests[0]['url'] ?? '') === 'https://login.microsoftonline.com/11111111-2222-4333-8444-555555555555/oauth2/v2.0/token'
    && str_contains((string)$requests[0]['body'], 'client_assertion_type=urn%3Aietf%3Aparams%3Aoauth%3Aclient-assertion-type%3Ajwt-bearer')
    && !str_contains((string)$requests[0]['body'], 'client_secret'));

parse_str((string)$requests[0]['body'], $tokenForm);
$assertionParts = explode('.', (string)($tokenForm['client_assertion'] ?? ''));
$assertionHeader = count($assertionParts) === 3 ? json_decode((string)graph_b64url_decode($assertionParts[0]), true) : null;
$assertionClaims = count($assertionParts) === 3 ? json_decode((string)graph_b64url_decode($assertionParts[1]), true) : null;
$assertionSignature = count($assertionParts) === 3 ? graph_b64url_decode($assertionParts[2]) : false;
$verify = is_string($assertionSignature)
    ? openssl_verify($assertionParts[0] . '.' . $assertionParts[1], $assertionSignature, $certificatePem, OPENSSL_ALGO_SHA256)
    : 0;
graph_check('PHP 8.3 compatible assertion is documented RS256 with certificate x5t',
    is_array($assertionHeader)
    && ($assertionHeader['alg'] ?? '') === 'RS256'
    && ($assertionHeader['typ'] ?? '') === 'JWT'
    && preg_match('/\A[A-Za-z0-9_-]{27}\z/D', (string)($assertionHeader['x5t'] ?? '')) === 1
    && $verify === 1);
graph_check('assertion binds exact client and token audience for five minutes',
    is_array($assertionClaims)
    && ($assertionClaims['iss'] ?? '') === $rawConfig['client_id']
    && ($assertionClaims['sub'] ?? '') === $rawConfig['client_id']
    && ($assertionClaims['aud'] ?? '') === $requests[0]['url']
    && (int)($assertionClaims['exp'] ?? 0) - (int)($assertionClaims['iat'] ?? 0) === 300
    && preg_match('/\A[0-9a-f-]{36}\z/D', (string)($assertionClaims['jti'] ?? '')) === 1);

$sentPayload = json_decode((string)$requests[1]['body'], true);
graph_check('send is pinned to exact mailbox and approved recipient',
    ($requests[1]['method'] ?? '') === 'POST'
    && ($requests[1]['url'] ?? '') === 'https://graph.microsoft.com/v1.0/users/westy%40example.test/sendMail'
    && ($sentPayload['message']['toRecipients'][0]['emailAddress']['address'] ?? '') === 'approved-recipient@example.test');
graph_check('send saves to Sent Items by documented default and carries correlation header',
    !array_key_exists('saveToSentItems', $sentPayload)
    && ($sentPayload['message']['internetMessageHeaders'][0] ?? null) === [
        'name' => 'X-Westy-Message-Key',
        'value' => '0123456789abcdef0123456789abcdef',
    ]);
graph_check('authorization token is transient and absent from persistable result',
    !str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'synthetic-access-token'));

/* Reply correlation must never permit Graph to select a different replyTo. */
$requests = [];
$result = westy_mail_graph_send(
    $rawConfig,
    graph_mail(['reply_message_id' => 'AAMkAD-safe_graph-message-id_123=']),
    graph_http_fixture([graph_token_response(), ['http' => 202, 'body' => '']], $requests),
);
$replyPayload = json_decode((string)$requests[1]['body'], true);
graph_check('reply correlation still uses normal sendMail instead of provider reply routing',
    $result['outcome'] === 'submitted'
    && str_ends_with((string)$requests[1]['url'], '/sendMail')
    && !str_contains((string)$requests[1]['url'], '/reply')
    && ($replyPayload['message']['toRecipients'][0]['emailAddress']['address'] ?? '') === 'approved-recipient@example.test');
graph_check('reply message id is a bounded custom correlation header',
    ($replyPayload['message']['internetMessageHeaders'][1] ?? null) === [
        'name' => 'X-Westy-Reply-Message-Id',
        'value' => 'AAMkAD-safe_graph-message-id_123=',
    ]);

/* Definitive provider rejection versus an ambiguous crossed send boundary. */
$requests = [];
$result = westy_mail_graph_send($rawConfig, graph_mail(), graph_http_fixture([
    graph_token_response(),
    ['http' => 400, 'body' => '{"error":{"message":"sensitive provider detail"}}', 'headers' => ['request-id' => 'reject-id']],
], $requests));
graph_check('definitive nontransient 4xx send response is rejected',
    $result === ['outcome' => 'rejected', 'provider_http' => 400, 'outcome_code' => 'send_rejected', 'provider_request_id' => 'reject-id']);
graph_check('provider rejection body never leaves transport result',
    !str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'sensitive provider detail'));

foreach ([408, 409, 425, 429, 500, 503] as $ambiguousHttp) {
    $requests = [];
    $result = westy_mail_graph_send($rawConfig, graph_mail(), graph_http_fixture([
        graph_token_response(), ['http' => $ambiguousHttp, 'body' => 'raw provider error'],
    ], $requests));
    graph_check("HTTP {$ambiguousHttp} after send boundary is uncertain",
        $result['outcome'] === 'uncertain'
        && $result['provider_http'] === $ambiguousHttp
        && $result['outcome_code'] === 'send_uncertain'
        && count($requests) === 2);
}
$requests = [];
$result = westy_mail_graph_send($rawConfig, graph_mail(), graph_http_fixture([
    graph_token_response(), new RuntimeException('socket outcome unknown'),
], $requests));
graph_check('transport exception after send boundary is uncertain with no retry',
    $result['outcome'] === 'uncertain'
    && $result['provider_http'] === null
    && $result['outcome_code'] === 'send_transport_error'
    && count($requests) === 2);

$requests = [];
$result = westy_mail_graph_send($rawConfig, graph_mail(), graph_http_fixture([
    ['http' => 401, 'body' => '{"error":"invalid_client"}', 'headers' => ['request-id' => 'token-reject-id']],
], $requests));
graph_check('token rejection is explicitly not a mail submission',
    $result === ['outcome' => 'not_submitted', 'provider_http' => 401, 'outcome_code' => 'token_rejected', 'provider_request_id' => 'token-reject-id']
    && count($requests) === 1);
$requests = [];
$result = westy_mail_graph_send($rawConfig, graph_mail(), graph_http_fixture([
    ['http' => 503, 'body' => 'unavailable'],
], $requests));
graph_check('token outage is not confused with uncertain mail submission',
    $result['outcome'] === 'not_submitted'
    && $result['provider_http'] === 503
    && $result['outcome_code'] === 'token_unavailable'
    && count($requests) === 1);

/* Local validation never obtains a token or crosses the send boundary. */
$invalidMessages = [
    'recipient control characters' => graph_mail(['recipient' => "victim@example.test\r\nBcc: other@example.test"]),
    'subject control characters' => graph_mail(['subject' => "Approved\r\nBcc: other@example.test"]),
    'message key header' => graph_mail(['message_key' => "bad\r\nheader"]),
    'reply message id header' => graph_mail(['reply_message_id' => "provider-id\r\nX-Bad: yes"]),
    'oversize body' => graph_mail(['body_text' => str_repeat('x', WESTY_MAIL_GRAPH_MAX_BODY_BYTES + 1)]),
];
foreach ($invalidMessages as $label => $invalidMail) {
    $requests = [];
    $result = westy_mail_graph_send($rawConfig, $invalidMail, graph_http_fixture([], $requests));
    graph_check("invalid {$label} is refused before HTTP",
        $result === ['outcome' => 'not_submitted', 'provider_http' => null, 'outcome_code' => 'mail_invalid', 'provider_request_id' => null]
        && $requests === []);
}

/* Bounded read-only mailbox requests and nextLink handling. */
$requests = [];
$result = westy_mail_graph_get(
    $rawConfig,
    'mailFolders/sentitems/messages?$select=id,subject&$top=25',
    graph_http_fixture([
        graph_token_response(),
        [
            'http' => 200,
            'headers' => ['request-id' => 'get-id'],
            'body' => json_encode([
                'value' => [['id' => 'message-1', 'subject' => 'Approved case update']],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/westy%40example.test/mailFolders/sentitems/messages?$select=id%2Csubject&$top=25&$skiptoken=safe-token',
            ], JSON_THROW_ON_ERROR),
        ],
    ], $requests),
);
graph_check('mailbox GET returns decoded success data and a normalized safe next path',
    $result['outcome'] === 'ok'
    && $result['provider_http'] === 200
    && ($result['data']['value'][0]['id'] ?? '') === 'message-1'
    && ($result['next_path'] ?? '') === 'mailFolders/sentitems/messages?$select=id%2Csubject&$top=25&$skiptoken=safe-token');
graph_check('mailbox operation is read-only and exact-host pinned',
    count($requests) === 2
    && ($requests[1]['method'] ?? '') === 'GET'
    && str_starts_with((string)$requests[1]['url'], 'https://graph.microsoft.com/v1.0/users/westy%40example.test/')
    && ($requests[1]['body'] ?? 'not-empty') === '');

$requests = [];
$result = westy_mail_graph_get(
    $rawConfig,
    'https://graph.microsoft.com/v1.0/users/westy%40example.test/mailFolders/sentitems/messages?$top=10&$skiptoken=next-page',
    graph_http_fixture([graph_token_response(), ['http' => 200, 'body' => '{"value":[]}']], $requests),
);
graph_check('safe absolute Graph nextLink is accepted for the exact mailbox',
    $result['outcome'] === 'ok' && count($requests) === 2);

$untrustedPaths = [
    'https://evil.example/v1.0/users/westy%40example.test/messages?$top=10',
    'https://graph.microsoft.com/v1.0/users/other%40example.test/messages?$top=10',
    '//graph.microsoft.com/v1.0/users/westy%40example.test/messages',
    'messages/../other',
    'messages#ignored-fragment',
    'messages?$top=99',
    'messages?$unsupported=value',
];
foreach ($untrustedPaths as $untrustedPath) {
    $requests = [];
    $result = westy_mail_graph_get($rawConfig, $untrustedPath, graph_http_fixture([], $requests));
    graph_check('untrusted mailbox URL is refused before token or GET: ' . $untrustedPath,
        $result['outcome'] === 'not_attempted'
        && $result['outcome_code'] === 'get_path_invalid'
        && $requests === []);
}

$requests = [];
$result = westy_mail_graph_get(
    $rawConfig,
    'messages?$top=10',
    graph_http_fixture([
        graph_token_response(),
        ['http' => 200, 'body' => '{"value":[],"@odata.nextLink":"https://attacker.example/steal"}'],
    ], $requests),
);
graph_check('untrusted nextLink in a provider response fails closed without exposing data',
    $result['outcome'] === 'uncertain'
    && $result['outcome_code'] === 'get_untrusted_next_link'
    && !array_key_exists('data', $result)
    && count($requests) === 2);

$requests = [];
$result = westy_mail_graph_get(
    $rawConfig,
    'messages?$top=10',
    graph_http_fixture([
        graph_token_response(),
        ['http' => 403, 'body' => '{"error":{"message":"private provider detail"}}'],
    ], $requests),
);
graph_check('GET rejection exposes only bounded evidence',
    $result['outcome'] === 'rejected'
    && $result['provider_http'] === 403
    && !str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'private provider detail'));

$probeSource = file_get_contents(__DIR__ . '/westy_mail_graph_certificate_probe.php');
graph_check('operator certificate probe has no send endpoint or mutation verb',
    is_string($probeSource)
    && !str_contains($probeSource, '/sendMail')
    && !preg_match('/[\x27\x22](?:POST|PATCH|PUT|DELETE)[\x27\x22]/', $probeSource)
    && str_contains($probeSource, "'GET'"));
graph_check('operator certificate probe bounds both Inbox reads to one id-only row',
    is_string($probeSource)
    && substr_count($probeSource, 'mailFolders/inbox/messages?$top=1&$select=id') === 2
    && !str_contains($probeSource, '$select=body')
    && !str_contains($probeSource, 'isRead'));

@unlink($badCertificatePath);
@unlink($privateKeyPath);
@unlink($certificatePath);
@unlink($opensslConfigPath);
@rmdir($temporaryDirectory);
if ($previousOpenSslConfig === false) putenv('OPENSSL_CONF');
else putenv('OPENSSL_CONF=' . $previousOpenSslConfig);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
