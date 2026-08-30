<?php
/** Exact wire contract for the isolated 8 West ID schema-2 evidence client. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/managed_customer_id_evidence.php';

$evidenceChecks = 0;
$evidenceFailures = 0;
$evidenceSecret = str_repeat('a', 64);
$evidenceCustomer = '11111111-1111-4111-8111-111111111111';
$evidenceNonce = '22222222-2222-4222-8222-222222222222';
$evidenceNow = 1788105600;

function evidence_check(bool $condition, string $message): void
{
    global $evidenceChecks, $evidenceFailures;
    $evidenceChecks++;
    if (!$condition) {
        $evidenceFailures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/** @param class-string<Throwable> $class */
function evidence_refuses(string $class, callable $operation, string $message): void
{
    try {
        $operation();
        evidence_check(false, $message);
    } catch (Throwable $error) {
        evidence_check($error instanceof $class, $message);
    }
}

/** @return array<string,mixed> */
function evidence_document(array $changes = []): array
{
    global $evidenceCustomer, $evidenceNonce, $evidenceNow;
    return array_replace([
        'ok' => true,
        'schema_version' => 2,
        'customer_id' => $evidenceCustomer,
        'customer_source_version' => 7,
        'customer_receipt_id' => str_repeat('b', 64),
        'customer_event_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'customer_status' => 'active',
        'lifecycle_version' => 1,
        'lifecycle_transition_id' => 19,
        'lifecycle_action' => 'observed_active',
        'lifecycle_evidence_sha256' => str_repeat('c', 64),
        'identity_tenant_status' => 'active',
        'identity_oauth_session_version' => 6,
        'lifecycle_owned' => false,
        'identity_tenant_key' => 'ewid-t91',
        'identity_tenant_slug' => 'managed-one',
        'contact_version' => 3,
        'weekly_report_email' => 'reports@managed-one.example',
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $evidenceNow),
        'request_nonce' => $evidenceNonce,
    ], $changes);
}

/** @return array{status:int,headers:array<string,list<string>>,body:string} */
function evidence_response(
    string $rawBody,
    int $status = 200,
    ?string $signature = null,
): array {
    global $evidenceSecret, $evidenceNonce;
    $signature ??= hash_hmac(
        'sha256',
        ID_REPORT_CONTACT_RESPONSE_CONTEXT . "\n{$evidenceNonce}\n" . hash('sha256', $rawBody),
        $evidenceSecret,
    );
    return [
        'status' => $status,
        'headers' => ['x-8w-report-contact-signature' => [$signature]],
        'body' => $rawBody,
    ];
}

/** @param array<string,mixed> $changes */
function evidence_decode_refuses(array $changes, string $message): void
{
    global $evidenceSecret, $evidenceCustomer, $evidenceNonce, $evidenceNow;
    $rawBody = json_encode(
        evidence_document($changes),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $response = evidence_response($rawBody);
    evidence_refuses(
        ManagedCustomerActivationGateException::class,
        static fn() => managed_customer_id_evidence_decode_response(
            $response['status'],
            $response['headers'],
            $response['body'],
            $evidenceSecret,
            $evidenceCustomer,
            $evidenceNonce,
            $evidenceNow,
        ),
        $message,
    );
}

$disabled = managed_customer_id_evidence_config([]);
evidence_check($disabled['enabled'] === false, 'schema-2 evidence lookup is not default-off');
evidence_refuses(
    ManagedCustomerActivationValidationException::class,
    static fn() => managed_customer_id_evidence_config(['enabled' => true]),
    'enabled schema-2 evidence lookup accepted an empty secret',
);
evidence_refuses(
    ManagedCustomerActivationValidationException::class,
    static fn() => managed_customer_id_evidence_config([
        'enabled' => true,
        'endpoint' => 'https://id.8westit.com/other',
        'hmac_secret' => str_repeat('a', 64),
    ]),
    'schema-2 evidence lookup accepted a different endpoint',
);

$request = managed_customer_id_evidence_request(
    $evidenceCustomer,
    $evidenceSecret,
    $evidenceNow,
    $evidenceNonce,
);
$expectedBody = '{"schema_version":2,"customer_id":"11111111-1111-4111-8111-111111111111"}';
evidence_check($request['body'] === $expectedBody, 'schema-2 canonical request bytes changed');
$expectedPreimage = "8west-id-report-contact-request-v1\nPOST\n/api/svc/report-contact.php\n"
    . $evidenceNow . "\n" . $evidenceNonce . "\n" . hash('sha256', $expectedBody);
evidence_check(
    managed_customer_id_evidence_request_preimage(
        (string)$evidenceNow,
        $evidenceNonce,
        $expectedBody,
    ) === $expectedPreimage,
    'schema-2 request HMAC preimage changed',
);
$expectedSignature = hash_hmac('sha256', $expectedPreimage, $evidenceSecret);
evidence_check(
    $request['headers'] === [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-8W-Service: safeharbor-reports',
        'X-8W-Timestamp: ' . $evidenceNow,
        'X-8W-Nonce: ' . $evidenceNonce,
        'X-8W-Signature: ' . $expectedSignature,
    ],
    'schema-2 request headers or signature changed',
);
evidence_refuses(
    ManagedCustomerActivationValidationException::class,
    static fn() => managed_customer_id_evidence_request(
        BUSINESS_REPORT_MASTER_CUSTOMER_ID,
        $evidenceSecret,
        $evidenceNow,
        $evidenceNonce,
    ),
    'schema-2 request accepted the master customer UUID',
);
evidence_check(
    id_report_contact_request('ewid-t91', $evidenceSecret, $evidenceNow, $evidenceNonce)['body']
        === '{"schema_version":1,"tenant_key":"ewid-t91"}',
    'schema-1 request behavior changed',
);

$rawBody = json_encode(evidence_document(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$response = evidence_response($rawBody);
$decoded = managed_customer_id_evidence_decode_response(
    200,
    $response['headers'],
    $rawBody,
    $evidenceSecret,
    $evidenceCustomer,
    $evidenceNonce,
    $evidenceNow,
);
evidence_check(
    array_keys($decoded) === [
        'schema_version', 'customer_id', 'source_version', 'customer_receipt_id',
        'customer_event_id',
        'customer_status', 'lifecycle_version', 'lifecycle_transition_id',
        'lifecycle_action', 'lifecycle_evidence_sha256', 'identity_tenant_status',
        'identity_oauth_session_version', 'lifecycle_owned',
        'tenant_key', 'tenant_slug', 'contact_version', 'recipient_email',
        'generated_at_db', 'request_nonce_sha256', 'response_sha256',
    ],
    'normalized schema-2 evidence member order changed',
);
evidence_check(
    $decoded['schema_version'] === 2
        && $decoded['customer_id'] === $evidenceCustomer
        && $decoded['source_version'] === 7
        && $decoded['customer_receipt_id'] === str_repeat('b', 64)
        && $decoded['customer_event_id'] === 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
        && $decoded['customer_status'] === 'active'
        && $decoded['lifecycle_version'] === 1
        && $decoded['lifecycle_transition_id'] === 19
        && $decoded['lifecycle_action'] === 'observed_active'
        && $decoded['lifecycle_evidence_sha256'] === str_repeat('c', 64)
        && $decoded['identity_tenant_status'] === 'active'
        && $decoded['identity_oauth_session_version'] === 6
        && $decoded['lifecycle_owned'] === false
        && $decoded['tenant_key'] === 'ewid-t91'
        && $decoded['tenant_slug'] === 'managed-one'
        && $decoded['contact_version'] === 3
        && $decoded['recipient_email'] === 'reports@managed-one.example'
        && $decoded['generated_at_db'] === gmdate('Y-m-d H:i:s', $evidenceNow)
        && $decoded['request_nonce_sha256'] === hash('sha256', $evidenceNonce)
        && $decoded['response_sha256'] === hash('sha256', $rawBody),
    'schema-2 response did not normalize every exact producer fact',
);

$transportObserved = [];
$fetched = managed_customer_id_evidence_fetch(
    $evidenceCustomer,
    [
        'enabled' => true,
        'endpoint' => ID_REPORT_CONTACT_ENDPOINT,
        'hmac_secret' => $evidenceSecret,
        'timeout_seconds' => 7,
        // Existing schema-1 bindings are deliberately irrelevant to UUID lookup.
        'tenant_bindings' => [],
        'client_bindings' => [],
        'customer_bindings' => [],
    ],
    static function (
        string $endpoint,
        array $headers,
        string $body,
        int $timeout,
        int $maxBytes,
    ) use (&$transportObserved, $rawBody): array {
        $transportObserved = compact('endpoint', 'headers', 'body', 'timeout', 'maxBytes');
        return evidence_response($rawBody) + [
            'effective_url' => ID_REPORT_CONTACT_ENDPOINT,
            'redirect_count' => 0,
        ];
    },
    $evidenceNow,
    $evidenceNonce,
);
evidence_check($fetched === $decoded, 'schema-2 fetch did not return exact decoded evidence');
evidence_check(
    ($transportObserved['endpoint'] ?? null) === ID_REPORT_CONTACT_ENDPOINT
        && ($transportObserved['body'] ?? null) === $expectedBody
        && ($transportObserved['timeout'] ?? null) === 7
        && ($transportObserved['maxBytes'] ?? null) === ID_REPORT_CONTACT_MAX_RESPONSE_BYTES,
    'schema-2 fetch changed the fixed endpoint, body, timeout, or response bound',
);

evidence_decode_refuses(['schema_version' => 1], 'schema-2 decoder accepted schema 1');
evidence_decode_refuses(['customer_id' => '33333333-3333-4333-8333-333333333333'], 'schema-2 decoder accepted another customer UUID');
evidence_decode_refuses(['customer_source_version' => '7'], 'schema-2 decoder accepted a non-integer source version');
evidence_decode_refuses(['customer_source_version' => 0], 'schema-2 decoder accepted source version zero');
evidence_decode_refuses(['customer_receipt_id' => str_repeat('B', 64)], 'schema-2 decoder accepted a noncanonical receipt id');
evidence_decode_refuses(['customer_event_id' => 'DDDDDDDD-dddd-4ddd-8ddd-dddddddddddd'], 'schema-2 decoder accepted a noncanonical source event UUID');
evidence_decode_refuses(['customer_status' => 'inactive'], 'schema-2 decoder accepted an inactive customer');
evidence_decode_refuses(['lifecycle_version' => 2], 'schema-2 decoder accepted another lifecycle version');
evidence_decode_refuses(['lifecycle_transition_id' => 0], 'schema-2 decoder accepted a nonpositive lifecycle transition');
evidence_decode_refuses(['lifecycle_transition_id' => '19'], 'schema-2 decoder accepted a string lifecycle transition');
evidence_decode_refuses(['lifecycle_action' => 'observed_inactive'], 'schema-2 decoder accepted another lifecycle action');
evidence_decode_refuses(['lifecycle_evidence_sha256' => str_repeat('C', 64)], 'schema-2 decoder accepted a noncanonical lifecycle digest');
evidence_decode_refuses(['identity_tenant_status' => 'suspended'], 'schema-2 decoder accepted an inactive identity tenant');
evidence_decode_refuses(['identity_oauth_session_version' => 0], 'schema-2 decoder accepted a nonpositive OAuth generation');
evidence_decode_refuses(['identity_oauth_session_version' => '6'], 'schema-2 decoder accepted a string OAuth generation');
evidence_decode_refuses(['lifecycle_owned' => true], 'schema-2 decoder accepted lifecycle-owned restoration');
evidence_decode_refuses(['identity_tenant_key' => 'ewid-t0'], 'schema-2 decoder accepted an invalid identity tenant key');
evidence_decode_refuses(['identity_tenant_slug' => 'Managed-One'], 'schema-2 decoder accepted a noncanonical identity tenant slug');
evidence_decode_refuses(['contact_version' => 0], 'schema-2 decoder accepted contact version zero');
evidence_decode_refuses(['weekly_report_email' => 'Reports@managed-one.example'], 'schema-2 decoder accepted a noncanonical report email');
evidence_decode_refuses(['request_nonce' => '33333333-3333-4333-8333-333333333333'], 'schema-2 decoder accepted another request nonce');
evidence_decode_refuses(
    ['generated_at' => gmdate('Y-m-d\TH:i:s\Z', $evidenceNow + 301)],
    'schema-2 decoder accepted evidence outside the 300-second freshness window',
);
$edgeBody = json_encode(
    evidence_document(['generated_at' => gmdate('Y-m-d\TH:i:s\Z', $evidenceNow + 300)]),
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
$edgeResponse = evidence_response($edgeBody);
evidence_check(
    managed_customer_id_evidence_decode_response(
        200,
        $edgeResponse['headers'],
        $edgeBody,
        $evidenceSecret,
        $evidenceCustomer,
        $evidenceNonce,
        $evidenceNow,
    )['source_version'] === 7,
    'schema-2 decoder rejected the exact 300-second freshness edge',
);

$reordered = [
    'schema_version' => 2,
    'ok' => true,
    ...array_slice(evidence_document(), 2, null, true),
];
$reorderedBody = json_encode($reordered, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$reorderedResponse = evidence_response($reorderedBody);
evidence_refuses(
    ManagedCustomerActivationGateException::class,
    static fn() => managed_customer_id_evidence_decode_response(
        200,
        $reorderedResponse['headers'],
        $reorderedBody,
        $evidenceSecret,
        $evidenceCustomer,
        $evidenceNonce,
        $evidenceNow,
    ),
    'schema-2 decoder accepted self-signed noncanonical member order',
);

$duplicateBody = preg_replace(
    '/\A\{/',
    '{"ok":true,',
    $rawBody,
    1,
);
if (!is_string($duplicateBody)) throw new RuntimeException('duplicate fixture failed');
$duplicateResponse = evidence_response($duplicateBody);
evidence_refuses(
    ManagedCustomerActivationGateException::class,
    static fn() => managed_customer_id_evidence_decode_response(
        200,
        $duplicateResponse['headers'],
        $duplicateBody,
        $evidenceSecret,
        $evidenceCustomer,
        $evidenceNonce,
        $evidenceNow,
    ),
    'schema-2 decoder accepted a self-signed duplicate JSON member',
);

evidence_refuses(
    ManagedCustomerActivationGateException::class,
    static fn() => managed_customer_id_evidence_decode_response(
        200,
        ['x-8w-report-contact-signature' => [str_repeat('0', 64)]],
        $rawBody,
        $evidenceSecret,
        $evidenceCustomer,
        $evidenceNonce,
        $evidenceNow,
    ),
    'schema-2 decoder accepted an invalid response HMAC',
);
foreach ([401, 404, 409, 503] as $status) {
    evidence_refuses(
        ManagedCustomerActivationGateException::class,
        static fn() => managed_customer_id_evidence_decode_response(
            $status,
            $response['headers'],
            $rawBody,
            $evidenceSecret,
            $evidenceCustomer,
            $evidenceNonce,
            $evidenceNow,
        ),
        "schema-2 decoder accepted HTTP {$status}",
    );
}

evidence_refuses(
    ManagedCustomerActivationGateException::class,
    static fn() => managed_customer_id_evidence_fetch(
        $evidenceCustomer,
        [
            'enabled' => true,
            'endpoint' => ID_REPORT_CONTACT_ENDPOINT,
            'hmac_secret' => $evidenceSecret,
        ],
        static fn() => evidence_response($rawBody) + [
            'effective_url' => ID_REPORT_CONTACT_ENDPOINT . '?redirected=1',
            'redirect_count' => 1,
        ],
        $evidenceNow,
        $evidenceNonce,
    ),
    'schema-2 fetch accepted a changed endpoint or redirect',
);

if ($evidenceFailures > 0) {
    fwrite(STDERR, "managed_customer_id_evidence_test: {$evidenceFailures} failure(s) / {$evidenceChecks} checks\n");
    exit(1);
}
echo "managed_customer_id_evidence_test: ok ({$evidenceChecks} checks)\n";
