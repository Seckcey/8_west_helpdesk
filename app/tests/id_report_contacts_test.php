<?php
/** Hermetic contract for the operator-only 8 West ID report-contact client. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/id_report_contacts.php';

$idReportChecks = 0;
$idReportFailures = 0;

function id_report_check(bool $condition, string $message): void
{
    global $idReportChecks, $idReportFailures;
    $idReportChecks++;
    if (!$condition) {
        $idReportFailures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function id_report_refuses(callable $operation, string $message): void
{
    try {
        $operation();
        id_report_check(false, $message);
    } catch (IdReportContactValidationException|IdReportContactGateException|IdReportContactTransportException $error) {
        id_report_check(
            !str_contains($error->getMessage(), 'admin@example.test')
                && !str_contains($error->getMessage(), str_repeat('a', 64)),
            $message,
        );
    }
}

/** @param list<string> $headers @return array<string,string> */
function id_report_header_map(array $headers): array
{
    $map = [];
    foreach ($headers as $header) {
        $separator = strpos($header, ':');
        if ($separator !== false) {
            $map[strtolower(trim(substr($header, 0, $separator)))] = trim(substr($header, $separator + 1));
        }
    }
    return $map;
}

/** @return array<string,mixed> */
function id_report_config(array $overrides = []): array
{
    return array_replace([
        'enabled' => true,
        'endpoint' => ID_REPORT_CONTACT_ENDPOINT,
        'hmac_secret' => str_repeat('a', 64),
        'tenant_bindings' => ['8west' => 'ewid-t1'],
        'client_bindings' => ['safeharbor-client:14' => 'ewid-t4'],
        'customer_bindings' => [],
        'timeout_seconds' => 10,
    ], $overrides);
}

/**
 * @param callable(array<string,mixed>):array<string,mixed>|null $mutate
 * @return callable(string,list<string>,string,int,int):array<string,mixed>
 */
function id_report_transport(?callable $mutate = null): callable
{
    return static function (
        string $endpoint,
        array $headers,
        string $requestBody,
        int $timeout,
        int $maxBytes,
    ) use ($mutate): array {
        $headerMap = id_report_header_map($headers);
        $request = json_decode($requestBody, true, 8, JSON_THROW_ON_ERROR);
        id_report_check($endpoint === ID_REPORT_CONTACT_ENDPOINT, 'transport endpoint changed');
        id_report_check($timeout === 10, 'transport timeout changed');
        id_report_check($maxBytes === ID_REPORT_CONTACT_MAX_RESPONSE_BYTES, 'response bound changed');
        id_report_check(
            ($headerMap['x-8w-service'] ?? '') === ID_REPORT_CONTACT_SERVICE,
            'dedicated service identity changed',
        );
        $document = [
            'ok' => true,
            'schema_version' => 1,
            'tenant_key' => $request['tenant_key'],
            'tenant_slug' => $request['tenant_key'] === 'ewid-t4' ? '8-west-lifestyle' : '8west',
            'contact_version' => 7,
            'weekly_report_email' => 'admin@example.test',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', (int)$headerMap['x-8w-timestamp']),
            'request_nonce' => $headerMap['x-8w-nonce'],
        ];
        $response = [
            'status' => 200,
            'headers' => [],
            'body' => '',
            'effective_url' => ID_REPORT_CONTACT_ENDPOINT,
            'redirect_count' => 0,
        ];
        if ($mutate !== null) {
            $changes = $mutate([
                'document' => $document,
                'response' => $response,
                'headers' => $headerMap,
                'request_body' => $requestBody,
            ]);
            $document = $changes['document'] ?? $document;
            $response = array_replace($response, $changes['response'] ?? []);
        }
        if ($response['body'] === '') {
            $response['body'] = json_encode(
                $document,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }
        if ($response['headers'] === []) {
            $preimage = ID_REPORT_CONTACT_RESPONSE_CONTEXT . "\n"
                . $headerMap['x-8w-nonce'] . "\n" . hash('sha256', $response['body']);
            $response['headers'] = [
                'x-8w-report-contact-signature' => [
                    hash_hmac('sha256', $preimage, str_repeat('a', 64)),
                ],
            ];
        }
        return $response;
    };
}

$dark = id_report_contact_config([
    'enabled' => false,
    'endpoint' => ID_REPORT_CONTACT_ENDPOINT,
    'hmac_secret' => '',
    'tenant_bindings' => [],
    'client_bindings' => [],
    'customer_bindings' => [],
    'timeout_seconds' => 10,
]);
id_report_check(
    $dark['enabled'] === false
        && $dark['tenant_bindings'] === []
        && $dark['client_bindings'] === []
        && $dark['customer_bindings'] === [],
    'default-off config changed',
);
foreach ([
    ['enabled' => true, 'hmac_secret' => ''],
    ['enabled' => true, 'tenant_bindings' => [], 'client_bindings' => [], 'customer_bindings' => []],
    ['enabled' => 1],
    ['endpoint' => 'http://id.8westit.com/api/svc/report-contact.php'],
    ['endpoint' => 'https://evil.example/api/svc/report-contact.php'],
    ['endpoint' => 'https://id.8westit.com:444/api/svc/report-contact.php'],
    ['endpoint' => ID_REPORT_CONTACT_ENDPOINT . '?next=evil'],
    ['hmac_secret' => strtoupper(str_repeat('a', 64))],
    ['tenant_bindings' => ['8west' => 'ewid-t1', 'other' => 'ewid-t1']],
    ['tenant_bindings' => ['8WEST' => 'ewid-t1']],
    ['tenant_bindings' => ['8west' => '8west']],
    ['tenant_bindings' => ['8west' => 'ewid-t4294967296']],
    ['client_bindings' => ['client-14' => 'ewid-t4']],
    ['client_bindings' => ['safeharbor-client:0' => 'ewid-t4']],
    ['client_bindings' => ['safeharbor-client:4294967296' => 'ewid-t4']],
    ['client_bindings' => ['safeharbor-client:14' => 'ewid-t1']],
    ['client_bindings' => [
        'safeharbor-client:14' => 'ewid-t4',
        'safeharbor-client:15' => 'ewid-t4',
    ]],
    ['customer_bindings' => ['customer:01234567-89ab-4def-8abc-0123456789ab' => 'ewid-t5']],
    ['customer_bindings' => ['milepost-customer:01234567-89AB-4def-8abc-0123456789ab' => 'ewid-t5']],
    ['customer_bindings' => ['milepost-customer:01234567-89ab-4def-8abc-0123456789ab' => 'ewid-t1']],
    ['customer_bindings' => ['milepost-customer:01234567-89ab-4def-8abc-0123456789ab' => 'ewid-t4']],
    [
        'client_bindings' => [],
        'customer_bindings' => [
            'milepost-customer:' . ID_REPORT_CONTACT_MASTER_CUSTOMER_ID => 'ewid-t5',
        ],
    ],
    [
        'client_bindings' => [],
        'customer_bindings' => [
            'milepost-customer:01234567-89ab-4def-8abc-0123456789ab' => 'ewid-t5',
            'milepost-customer:11234567-89ab-4def-8abc-0123456789ab' => 'ewid-t5',
        ],
    ],
    ['timeout_seconds' => 31],
] as $override) {
    id_report_refuses(
        static fn() => id_report_contact_config(id_report_config($override)),
        'unsafe client configuration was accepted',
    );
}

$nonceA = id_report_contact_uuid_v4();
$nonceB = id_report_contact_uuid_v4();
id_report_check(
    preg_match(ID_REPORT_CONTACT_UUID_V4, $nonceA) === 1 && $nonceA !== $nonceB,
    'nonce generator is not UUIDv4 and unique',
);
$fixedNonce = '123e4567-e89b-42d3-a456-426614174000';
$request = id_report_contact_request('ewid-t1', str_repeat('a', 64), 1_800_000_000, $fixedNonce);
$requestHeaders = id_report_header_map($request['headers']);
id_report_check(
    $request['body'] === '{"schema_version":1,"tenant_key":"ewid-t1"}',
    'request JSON bytes changed',
);
$expectedRequestPreimage = ID_REPORT_CONTACT_REQUEST_CONTEXT
    . "\nPOST\n" . ID_REPORT_CONTACT_PATH
    . "\n1800000000\n{$fixedNonce}\n" . hash('sha256', $request['body']);
id_report_check(
    hash_equals(
        hash_hmac('sha256', $expectedRequestPreimage, str_repeat('a', 64)),
        $requestHeaders['x-8w-signature'],
    ),
    'domain-separated request HMAC changed',
);

$snapshot = id_report_contact_fetch('8west', id_report_config(), id_report_transport(), 1_800_000_000);
id_report_check(
    $snapshot['tenant_key'] === 'ewid-t1'
        && $snapshot['tenant_slug'] === '8west'
        && $snapshot['contact_version'] === 7
        && $snapshot['recipient_email'] === 'admin@example.test'
        && $snapshot['generated_at_db'] === '2027-01-15 08:00:00'
        && preg_match('/\A[0-9a-f]{64}\z/D', $snapshot['request_nonce_sha256']) === 1
        && preg_match('/\A[0-9a-f]{64}\z/D', $snapshot['response_sha256']) === 1,
    'authenticated snapshot was not normalized exactly',
);
$clientSnapshot = id_report_contact_fetch_client(
    14,
    id_report_config(),
    id_report_transport(),
    1_800_000_000,
);
id_report_check(
    $clientSnapshot['tenant_key'] === 'ewid-t4'
        && $clientSnapshot['tenant_slug'] === '8-west-lifestyle'
        && $clientSnapshot['contact_version'] === 7
        && $clientSnapshot['recipient_email'] === 'admin@example.test',
    'client binding did not return the exact authenticated ID tenant snapshot',
);

$customerId = '01234567-89ab-4def-8abc-0123456789ab';
$customerKey = id_report_contact_customer_key($customerId);
id_report_check(
    $customerKey === 'milepost-customer:' . $customerId,
    'Milepost customer binding key changed',
);
id_report_refuses(
    static fn() => id_report_contact_customer_key(ID_REPORT_CONTACT_MASTER_CUSTOMER_ID),
    '8 West IT master customer was accepted in the customer-report lane',
);
$customerPdo = new PDO('sqlite::memory:');
$customerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$customerPdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');
$customerPdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
$customerPdo->exec(
    'CREATE TABLE suite_customer_sync_bindings (
        tenant_id INTEGER NOT NULL,
        client_id INTEGER NOT NULL,
        customer_id TEXT NOT NULL,
        status TEXT NOT NULL
    )'
);
$customerPdo->exec("INSERT INTO tenants (id, slug) VALUES (1, '8west')");
$customerPdo->exec('INSERT INTO clients (id, tenant_id) VALUES (14, 1)');
$customerPdo->prepare(
    'INSERT INTO suite_customer_sync_bindings
        (tenant_id, client_id, customer_id, status) VALUES (1, 14, ?, ?)'
)->execute([$customerId, 'active']);
$customerConfig = id_report_config([
    'client_bindings' => [],
    'customer_bindings' => [$customerKey => 'ewid-t4'],
]);
$resolvedCustomerId = id_report_contact_active_customer_id($customerPdo, '8west', 14);
id_report_check(
    $resolvedCustomerId === $customerId,
    'active customer resolver did not return the exact permanent UUID',
);
$customerSnapshot = id_report_contact_fetch_customer(
    $resolvedCustomerId,
    $customerConfig,
    id_report_transport(),
    1_800_000_000,
);
id_report_check(
    $customerSnapshot['tenant_key'] === 'ewid-t4'
        && $customerSnapshot['tenant_slug'] === '8-west-lifestyle'
        && $customerSnapshot['contact_version'] === 7
        && $customerSnapshot['recipient_email'] === 'admin@example.test',
    'stable Milepost customer binding did not return the exact ID contact',
);
id_report_refuses(
    static fn() => id_report_contact_active_customer_id(
        $customerPdo,
        '8west',
        15,
    ),
    'client without a managed-customer binding was accepted',
);
id_report_refuses(
    static fn() => id_report_contact_active_customer_id(
        $customerPdo,
        'other',
        14,
    ),
    'managed customer binding escaped its Safeharbor tenant',
);
$customerPdo->exec("UPDATE suite_customer_sync_bindings SET status = 'inactive'");
id_report_refuses(
    static fn() => id_report_contact_active_customer_id(
        $customerPdo,
        '8west',
        14,
    ),
    'inactive managed customer was accepted for report onboarding',
);
$customerPdo->exec("UPDATE suite_customer_sync_bindings SET status = 'active'");
id_report_refuses(
    static fn() => id_report_contact_fetch_customer(
        $customerId,
        id_report_config(['client_bindings' => [], 'customer_bindings' => []]),
        id_report_transport(),
    ),
    'unconfigured managed customer was accepted',
);

id_report_refuses(
    static fn() => id_report_contact_fetch('8west', id_report_config(['enabled' => false]), id_report_transport()),
    'disabled client made a request',
);
id_report_refuses(
    static fn() => id_report_contact_fetch('other', id_report_config(), id_report_transport()),
    'unbound tenant was accepted',
);
id_report_refuses(
    static fn() => id_report_contact_fetch_client(15, id_report_config(), id_report_transport()),
    'unbound client was accepted',
);
id_report_refuses(
    static fn() => id_report_contact_fetch_client(
        14,
        id_report_config(),
        id_report_transport(static fn(array $state): array => [
            'document' => array_replace($state['document'], ['tenant_slug' => 'Customer One']),
        ]),
        1_800_000_000,
    ),
    'client lookup accepted a malformed authenticated ID tenant slug',
);

$badMutations = [
    static fn(array $state): array => ['response' => ['status' => 302]],
    static fn(array $state): array => ['response' => ['effective_url' => 'https://evil.example/']],
    static fn(array $state): array => ['response' => ['redirect_count' => 1]],
    static fn(array $state): array => ['response' => [
        'headers' => ['x-8w-report-contact-signature' => [str_repeat('0', 64)]],
    ]],
    static fn(array $state): array => ['response' => [
        'headers' => ['x-8w-report-contact-signature' => [str_repeat('0', 64), str_repeat('1', 64)]],
    ]],
    static fn(array $state): array => ['document' => array_replace($state['document'], ['tenant_key' => 'ewid-t2'])],
    static fn(array $state): array => ['document' => array_replace($state['document'], ['tenant_slug' => 'other'])],
    static fn(array $state): array => ['document' => array_replace($state['document'], ['contact_version' => 0])],
    static fn(array $state): array => ['document' => array_replace($state['document'], ['weekly_report_email' => 'Admin@Example.TEST'])],
    static fn(array $state): array => ['document' => array_replace($state['document'], ['generated_at' => '2027-01-15T08:05:01Z'])],
    static fn(array $state): array => ['document' => array_replace($state['document'], ['request_nonce' => '123e4567-e89b-42d3-a456-426614174000'])],
    static fn(array $state): array => ['document' => array_merge($state['document'], ['extra' => true])],
    static fn(array $state): array => ['response' => ['body' => str_repeat('x', ID_REPORT_CONTACT_MAX_RESPONSE_BYTES + 1)]],
    static fn(array $state): array => ['response' => ['body' => '{"ok":true,"ok":true}']],
];
foreach ($badMutations as $mutation) {
    id_report_refuses(
        static fn() => id_report_contact_fetch(
            '8west',
            id_report_config(),
            id_report_transport($mutation),
            1_800_000_000,
        ),
        'tampered or stale response was accepted',
    );
}

$sample = require __DIR__ . '/../config/config.sample.php';
$sampleBlock = $sample['id_report_contacts'] ?? null;
id_report_check(
    is_array($sampleBlock)
        && ($sampleBlock['enabled'] ?? null) === false
        && ($sampleBlock['endpoint'] ?? null) === ID_REPORT_CONTACT_ENDPOINT
        && ($sampleBlock['hmac_secret'] ?? null) === ''
        && ($sampleBlock['tenant_bindings'] ?? null) === []
        && ($sampleBlock['client_bindings'] ?? null) === []
        && ($sampleBlock['customer_bindings'] ?? null) === [],
    'sample configuration is not dark, empty, and exact-host pinned',
);
$manager = (string)file_get_contents(__DIR__ . '/../db/manage_business_reports.php');
$runner = (string)file_get_contents(__DIR__ . '/../db/run_business_report.php');
$cron = (string)file_get_contents(__DIR__ . '/../cron/business_reports.php');
$contactLibrary = (string)file_get_contents(__DIR__ . '/../lib/id_report_contacts.php');
$customerPlanEmitterStart = strpos($manager, 'function report_cli_emit_customer_plan(');
$idContactEmitterStart = strpos($manager, 'function report_cli_emit_id_contact(');
$customerPlanEmitter = is_int($customerPlanEmitterStart)
    && is_int($idContactEmitterStart)
    && $idContactEmitterStart > $customerPlanEmitterStart
    ? substr(
        $manager,
        $customerPlanEmitterStart,
        $idContactEmitterStart - $customerPlanEmitterStart,
    )
    : '';
id_report_check(
    str_contains($customerPlanEmitter, "echo 'SCHEDULE_TIMEZONE=' . (string)\$schedule['schedule_timezone']")
        && str_contains($customerPlanEmitter, "echo 'DELIVERY_WEEKDAY=' . (int)\$schedule['delivery_weekday']")
        && str_contains($customerPlanEmitter, "echo 'DELIVERY_LOCAL_TIME=' . (string)\$schedule['delivery_local_time']")
        && str_contains($customerPlanEmitter, "echo 'RECIPIENT_SHA256=' . \$schedule['recipient_sha256']")
        && !str_contains($customerPlanEmitter, 'recipient_email'),
    'customer plan receipt omits exact schedule fields or exposes the address',
);
$customerFetchFunctionStart = strpos(
    $contactLibrary,
    'function id_report_contact_fetch_customer(',
);
$boundFetchFunctionStart = strpos(
    $contactLibrary,
    'function id_report_contact_fetch_bound_key(',
);
$customerFetchFunction = is_int($customerFetchFunctionStart)
    && is_int($boundFetchFunctionStart)
    && $boundFetchFunctionStart > $customerFetchFunctionStart
    ? substr(
        $contactLibrary,
        $customerFetchFunctionStart,
        $boundFetchFunctionStart - $customerFetchFunctionStart,
    )
    : '';
id_report_check(
    str_contains($customerFetchFunction, 'string $customerId')
        && !str_contains($customerFetchFunction, 'PDO $pdo')
        && !str_contains($customerFetchFunction, 'id_report_contact_active_customer_id(')
        && substr_count($customerFetchFunction, 'id_report_contact_customer_key($customerId)') === 1,
    'customer contact fetch performs another Safeharbor binding lookup',
);
id_report_check(
    substr_count($manager, 'id_report_contact_fetch(') === 1
        && substr_count($manager, 'id_report_contact_fetch_client(') === 1
        && substr_count($manager, 'id_report_contact_fetch_customer(') === 2
        && str_contains($manager, "if (\$command === 'prepare-from-id')")
        && str_contains($manager, "if (\$command === 'prepare-client-from-id')")
        && str_contains($manager, "if (\$command === 'plan-customer-from-id')")
        && str_contains($manager, "if (\$command === 'prepare-customer-from-id')")
        && str_contains($manager, 'business_report_legacy_client_refresh_target(')
        && str_contains($manager, 'business_report_contact_scope_for_key(')
        && str_contains($manager, 'CONTACT_SCOPE=MANUAL')
        && strpos($manager, 'business_report_legacy_client_refresh_target(')
            < strpos($manager, 'id_report_contact_fetch_client('),
    'operator plan/prepare commands are not the only four network-call boundaries',
);
id_report_check(
    !str_contains($runner, 'id_report_contact')
        && !str_contains($cron, 'id_report_contact')
        && !str_contains($cron, 'prepare-from-id'),
    'generation or delivery can reach the ID contact network client',
);
id_report_check(
    !str_contains($manager, "echo 'RECIPIENT_EMAIL='")
        && !str_contains($manager, "echo \$contact['recipient_email']"),
    'operator output exposes the raw report address',
);
$tenantPrepareStart = strpos($manager, "if (\$command === 'prepare-from-id')");
$clientPrepareStart = strpos($manager, "if (\$command === 'prepare-client-from-id')");
$customerPlanStart = strpos($manager, "if (\$command === 'plan-customer-from-id')");
$customerPrepareStart = strpos($manager, "if (\$command === 'prepare-customer-from-id')");
$transitionStart = strpos($manager, "if (\$command === 'enable' || \$command === 'disable')");
$tenantPrepareBlock = is_int($tenantPrepareStart) && is_int($clientPrepareStart)
    && $clientPrepareStart > $tenantPrepareStart
    ? substr($manager, $tenantPrepareStart, $clientPrepareStart - $tenantPrepareStart)
    : '';
$clientPrepareBlock = is_int($clientPrepareStart) && is_int($customerPlanStart)
    && $customerPlanStart > $clientPrepareStart
    ? substr($manager, $clientPrepareStart, $customerPlanStart - $clientPrepareStart)
    : '';
$customerPlanBlock = is_int($customerPlanStart) && is_int($customerPrepareStart)
    && $customerPrepareStart > $customerPlanStart
    ? substr($manager, $customerPlanStart, $customerPrepareStart - $customerPlanStart)
    : '';
$customerPrepareBlock = is_int($customerPrepareStart) && is_int($transitionStart)
    && $transitionStart > $customerPrepareStart
    ? substr($manager, $customerPrepareStart, $transitionStart - $customerPrepareStart)
    : '';
$customerFetchCallStart = strpos($customerPrepareBlock, 'id_report_contact_fetch_customer(');
$customerFetchCallEnd = is_int($customerFetchCallStart)
    ? strpos($customerPrepareBlock, ');', $customerFetchCallStart)
    : false;
$customerFetchCall = is_int($customerFetchCallStart)
    && is_int($customerFetchCallEnd)
    && $customerFetchCallEnd > $customerFetchCallStart
    ? substr(
        $customerPrepareBlock,
        $customerFetchCallStart,
        $customerFetchCallEnd - $customerFetchCallStart + 2,
    )
    : '';
$customerPlanFetchCallStart = strpos($customerPlanBlock, 'id_report_contact_fetch_customer(');
$customerPlanFetchCallEnd = is_int($customerPlanFetchCallStart)
    ? strpos($customerPlanBlock, ');', $customerPlanFetchCallStart)
    : false;
$customerPlanFetchCall = is_int($customerPlanFetchCallStart)
    && is_int($customerPlanFetchCallEnd)
    && $customerPlanFetchCallEnd > $customerPlanFetchCallStart
    ? substr(
        $customerPlanBlock,
        $customerPlanFetchCallStart,
        $customerPlanFetchCallEnd - $customerPlanFetchCallStart + 2,
    )
    : '';
id_report_check(
    substr_count(
        $tenantPrepareBlock,
        "report_cli_emit_id_contact(\$result['id_contact'], BUSINESS_REPORT_CONTACT_SCOPE_TENANT);",
    ) === 1
        && !str_contains($tenantPrepareBlock, 'BUSINESS_REPORT_CONTACT_SCOPE_CLIENT'),
    'prepare-from-id CLI receipt does not report its exact tenant contact scope',
);
id_report_check(
    substr_count(
        $clientPrepareBlock,
        "report_cli_emit_id_contact(\$result['id_contact'], BUSINESS_REPORT_CONTACT_SCOPE_CLIENT);",
    ) === 1
        && !str_contains($clientPrepareBlock, 'BUSINESS_REPORT_CONTACT_SCOPE_TENANT'),
    'prepare-client-from-id CLI receipt does not report its exact client contact scope',
);
id_report_check(
    strpos($clientPrepareBlock, 'business_report_legacy_client_refresh_target(')
        < strpos($clientPrepareBlock, 'id_report_contact_fetch_client('),
    'legacy client-id command can make a network request before proving existing schedule history',
);
id_report_check(
    substr_count(
        $customerPlanBlock,
        "report_cli_emit_id_contact(\$result['id_contact'], BUSINESS_REPORT_CONTACT_SCOPE_CLIENT);",
    ) === 1
        && !str_contains($customerPlanBlock, 'BUSINESS_REPORT_CONTACT_SCOPE_TENANT')
        && strpos($customerPlanBlock, 'business_report_schedule_target(')
            < strpos($customerPlanBlock, 'id_report_contact_active_customer_id(')
        && strpos($customerPlanBlock, 'id_report_contact_active_customer_id(')
            < strpos($customerPlanBlock, 'id_report_contact_fetch_customer(')
        && strpos($customerPlanBlock, 'id_report_contact_fetch_customer(')
            < strpos($customerPlanBlock, 'business_report_plan_customer_schedule_from_id(')
        && substr_count($customerPlanBlock, 'id_report_contact_active_customer_id(') === 1
        && str_contains($customerPlanFetchCall, '$customerId')
        && !str_contains($customerPlanFetchCall, '$pdo')
        && !str_contains($customerPlanBlock, 'business_report_prepare_customer_schedule_from_id(')
        && !str_contains($customerPlanBlock, 'business_report_generate(')
        && !str_contains($customerPlanBlock, 'business_report_deliver(')
        && str_contains($customerPlanBlock, 'NO_DB_WRITE=1')
        && str_contains($customerPlanBlock, 'NO_GRAPH_REQUEST=1')
        && !str_contains($customerPlanBlock, 'recipient_email'),
    'plan-customer-from-id can write, send, expose the address, or skip exact binding rechecks',
);
id_report_check(
    substr_count(
        $customerPrepareBlock,
        "report_cli_emit_id_contact(\$result['id_contact'], BUSINESS_REPORT_CONTACT_SCOPE_CLIENT);",
    ) === 1
        && !str_contains($customerPrepareBlock, 'BUSINESS_REPORT_CONTACT_SCOPE_TENANT')
        && strpos($customerPrepareBlock, 'business_report_schedule_target(')
            < strpos($customerPrepareBlock, 'id_report_contact_active_customer_id(')
        && strpos($customerPrepareBlock, 'id_report_contact_active_customer_id(')
            < strpos($customerPrepareBlock, 'id_report_contact_fetch_customer(')
        && substr_count($customerPrepareBlock, 'id_report_contact_active_customer_id(') === 1
        && str_contains($customerFetchCall, '$customerId')
        && !str_contains($customerFetchCall, '$pdo')
        && str_contains($customerPrepareBlock, 'business_report_prepare_customer_schedule_from_id(')
        && str_contains($customerPrepareBlock, '$customerId,'),
    'prepare-customer-from-id does not prove and report its exact client contact scope',
);

if ($idReportFailures > 0) {
    fwrite(STDERR, "id_report_contacts_test: {$idReportFailures} failure(s) / {$idReportChecks} checks\n");
    exit(1);
}
echo "id_report_contacts_test: {$idReportChecks} checks\n";
