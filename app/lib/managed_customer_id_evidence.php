<?php
/**
 * Authenticated 8 West ID schema-2 managed-customer projection/contact client.
 *
 * Schema 1 remains byte- and behavior-preserved in id_report_contacts.php.
 * This adapter shares only its fixed transport limits and small validators;
 * schema-2 request/response construction and exact decoding stay isolated.
 */
declare(strict_types=1);

require_once __DIR__ . '/managed_customer_activation.php';
require_once __DIR__ . '/id_report_contacts.php';

const MANAGED_CUSTOMER_ID_EVIDENCE_SCHEMA = 2;

/**
 * @return array{enabled:bool,endpoint:string,hmac_secret:string,timeout_seconds:int}
 */
function managed_customer_id_evidence_config(?array $source = null): array
{
    if ($source === null) {
        $source = function_exists('cfg') ? cfg('id_report_contacts', []) : [];
    }
    if (!is_array($source)) {
        throw new ManagedCustomerActivationValidationException(
            '8 West ID managed-customer evidence configuration is invalid.',
        );
    }
    $enabled = $source['enabled'] ?? false;
    $endpoint = $source['endpoint'] ?? ID_REPORT_CONTACT_ENDPOINT;
    $secret = $source['hmac_secret'] ?? '';
    $timeout = $source['timeout_seconds'] ?? 10;
    if (!is_bool($enabled)
        || !is_string($endpoint)
        || !hash_equals(ID_REPORT_CONTACT_ENDPOINT, $endpoint)
        || !is_string($secret)
        || ($secret !== '' && preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1)
        || !is_int($timeout)
        || $timeout < 1
        || $timeout > 30
        || ($enabled && $secret === '')
    ) {
        throw new ManagedCustomerActivationValidationException(
            '8 West ID managed-customer evidence configuration is invalid.',
        );
    }
    return [
        'enabled' => $enabled,
        'endpoint' => $endpoint,
        'hmac_secret' => $secret,
        'timeout_seconds' => $timeout,
    ];
}

function managed_customer_id_evidence_request_preimage(
    string $timestamp,
    string $nonce,
    string $rawBody,
): string {
    return ID_REPORT_CONTACT_REQUEST_CONTEXT . "\nPOST\n" . ID_REPORT_CONTACT_PATH
        . "\n{$timestamp}\n{$nonce}\n" . hash('sha256', $rawBody);
}

/** @return array{timestamp:string,nonce:string,body:string,headers:list<string>} */
function managed_customer_id_evidence_request(
    string $customerId,
    string $secret,
    ?int $now = null,
    ?string $nonce = null,
): array {
    $customerId = managed_customer_activation_uuid($customerId);
    if (preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1) {
        throw new ManagedCustomerActivationValidationException(
            '8 West ID managed-customer evidence request input is invalid.',
        );
    }
    $timestamp = (string)($now ?? time());
    if (preg_match('/\A[1-9][0-9]{0,11}\z/D', $timestamp) !== 1) {
        throw new ManagedCustomerActivationValidationException(
            '8 West ID managed-customer evidence request time is invalid.',
        );
    }
    $nonce ??= id_report_contact_uuid_v4();
    if (preg_match(ID_REPORT_CONTACT_UUID_V4, $nonce) !== 1) {
        throw new ManagedCustomerActivationValidationException(
            '8 West ID managed-customer evidence request nonce is invalid.',
        );
    }
    $body = json_encode(
        ['schema_version' => MANAGED_CUSTOMER_ID_EVIDENCE_SCHEMA, 'customer_id' => $customerId],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $signature = hash_hmac(
        'sha256',
        managed_customer_id_evidence_request_preimage($timestamp, $nonce, $body),
        $secret,
    );
    return [
        'timestamp' => $timestamp,
        'nonce' => $nonce,
        'body' => $body,
        'headers' => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-8W-Service: ' . ID_REPORT_CONTACT_SERVICE,
            'X-8W-Timestamp: ' . $timestamp,
            'X-8W-Nonce: ' . $nonce,
            'X-8W-Signature: ' . $signature,
        ],
    ];
}

/**
 * @param array<string,list<string>> $headers
 * @return array{
 *   schema_version:int,customer_id:string,source_version:int,customer_receipt_id:string,
 *   customer_status:string,lifecycle_version:int,lifecycle_transition_id:int,
 *   lifecycle_action:string,lifecycle_evidence_sha256:string,
 *   identity_tenant_status:string,identity_oauth_session_version:int,lifecycle_owned:bool,
 *   tenant_key:string,tenant_slug:string,contact_version:int,recipient_email:string,
 *   generated_at_db:string,request_nonce_sha256:string,response_sha256:string
 * }
 */
function managed_customer_id_evidence_decode_response(
    int $status,
    array $headers,
    string $rawBody,
    string $secret,
    string $expectedCustomerId,
    string $requestNonce,
    int $requestTime,
): array {
    $expectedCustomerId = managed_customer_activation_uuid($expectedCustomerId);
    if ($status !== 200
        || $rawBody === ''
        || strlen($rawBody) > ID_REPORT_CONTACT_MAX_RESPONSE_BYTES
        || preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1
        || preg_match(ID_REPORT_CONTACT_UUID_V4, $requestNonce) !== 1
        || $requestTime < 1
    ) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID did not return an exact managed-customer evidence document.',
        );
    }

    try {
        $signature = id_report_contact_response_signature_header($headers);
    } catch (IdReportContactTransportException $error) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID managed-customer evidence authentication was invalid.',
            0,
            $error,
        );
    }
    $responsePreimage = ID_REPORT_CONTACT_RESPONSE_CONTEXT . "\n{$requestNonce}\n"
        . hash('sha256', $rawBody);
    if (!hash_equals(hash_hmac('sha256', $responsePreimage, $secret), $signature)) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID managed-customer evidence authentication was invalid.',
        );
    }

    try {
        $document = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
        $memberCount = id_report_contact_member_count($rawBody);
    } catch (JsonException $error) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID returned an invalid managed-customer evidence document.',
            0,
            $error,
        );
    }
    $expectedKeys = [
        'ok', 'schema_version', 'customer_id', 'customer_source_version',
        'customer_receipt_id', 'customer_status', 'lifecycle_version',
        'lifecycle_transition_id', 'lifecycle_action', 'lifecycle_evidence_sha256',
        'identity_tenant_status', 'identity_oauth_session_version', 'lifecycle_owned',
        'identity_tenant_key', 'identity_tenant_slug', 'contact_version',
        'weekly_report_email', 'generated_at', 'request_nonce',
    ];
    if (!is_array($document)
        || array_is_list($document)
        || array_keys($document) !== $expectedKeys
        || $memberCount !== count($expectedKeys)
        || ($document['ok'] ?? null) !== true
        || ($document['schema_version'] ?? null) !== MANAGED_CUSTOMER_ID_EVIDENCE_SCHEMA
        || !is_string($document['customer_id'] ?? null)
        || !hash_equals($expectedCustomerId, $document['customer_id'])
        || !is_int($document['customer_source_version'] ?? null)
        || $document['customer_source_version'] < 1
        || !is_string($document['customer_receipt_id'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $document['customer_receipt_id']) !== 1
        || ($document['customer_status'] ?? null) !== 'active'
        || ($document['lifecycle_version'] ?? null) !== 1
        || !is_int($document['lifecycle_transition_id'] ?? null)
        || $document['lifecycle_transition_id'] < 1
        || !in_array($document['lifecycle_action'] ?? null, ['observed_active', 'restored'], true)
        || !is_string($document['lifecycle_evidence_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $document['lifecycle_evidence_sha256']) !== 1
        || ($document['identity_tenant_status'] ?? null) !== 'active'
        || !is_int($document['identity_oauth_session_version'] ?? null)
        || $document['identity_oauth_session_version'] < 1
        || ($document['lifecycle_owned'] ?? null) !== false
        || !is_string($document['identity_tenant_key'] ?? null)
        || !id_report_contact_tenant_key_valid($document['identity_tenant_key'])
        || !is_string($document['identity_tenant_slug'] ?? null)
        || preg_match(ID_REPORT_CONTACT_TENANT_SLUG, $document['identity_tenant_slug']) !== 1
        || !is_int($document['contact_version'] ?? null)
        || $document['contact_version'] < 1
        || !is_string($document['weekly_report_email'] ?? null)
        || !is_string($document['generated_at'] ?? null)
        || ($document['request_nonce'] ?? null) !== $requestNonce
    ) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID returned an invalid managed-customer evidence document.',
        );
    }
    try {
        $email = id_report_contact_email($document['weekly_report_email']);
        $generated = id_report_contact_timestamp($document['generated_at']);
    } catch (IdReportContactTransportException $error) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID returned an invalid managed-customer evidence document.',
            0,
            $error,
        );
    }
    if (abs($generated - $requestTime) > ID_REPORT_CONTACT_TIMESTAMP_TOLERANCE) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID returned stale managed-customer evidence.',
        );
    }

    return [
        'schema_version' => MANAGED_CUSTOMER_ID_EVIDENCE_SCHEMA,
        'customer_id' => $expectedCustomerId,
        'source_version' => $document['customer_source_version'],
        'customer_receipt_id' => $document['customer_receipt_id'],
        'customer_status' => $document['customer_status'],
        'lifecycle_version' => $document['lifecycle_version'],
        'lifecycle_transition_id' => $document['lifecycle_transition_id'],
        'lifecycle_action' => $document['lifecycle_action'],
        'lifecycle_evidence_sha256' => $document['lifecycle_evidence_sha256'],
        'identity_tenant_status' => $document['identity_tenant_status'],
        'identity_oauth_session_version' => $document['identity_oauth_session_version'],
        'lifecycle_owned' => $document['lifecycle_owned'],
        'tenant_key' => $document['identity_tenant_key'],
        'tenant_slug' => $document['identity_tenant_slug'],
        'contact_version' => $document['contact_version'],
        'recipient_email' => $email,
        'generated_at_db' => gmdate('Y-m-d H:i:s', $generated),
        'request_nonce_sha256' => hash('sha256', $requestNonce),
        'response_sha256' => hash('sha256', $rawBody),
    ];
}

/**
 * Fetch one exact UUID's authenticated schema-2 projection/contact snapshot.
 *
 * @param callable(string,list<string>,string,int,int):array<string,mixed>|null $transport
 * @return array<string,mixed>
 */
function managed_customer_id_evidence_fetch(
    string $customerId,
    array $source,
    ?callable $transport = null,
    ?int $now = null,
    ?string $nonce = null,
): array {
    $customerId = managed_customer_activation_uuid($customerId);
    $config = managed_customer_id_evidence_config($source);
    if ($config['enabled'] !== true) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID managed-customer evidence lookup is disabled.',
        );
    }
    $requestTime = $now ?? time();
    $request = managed_customer_id_evidence_request(
        $customerId,
        $config['hmac_secret'],
        $requestTime,
        $nonce,
    );
    $transport ??= 'id_report_contact_curl';
    try {
        $response = $transport(
            $config['endpoint'],
            $request['headers'],
            $request['body'],
            $config['timeout_seconds'],
            ID_REPORT_CONTACT_MAX_RESPONSE_BYTES,
        );
    } catch (IdReportContactValidationException|IdReportContactTransportException $error) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID managed-customer evidence transport failed closed.',
            0,
            $error,
        );
    }
    if (!is_array($response)
        || !is_int($response['status'] ?? null)
        || !is_array($response['headers'] ?? null)
        || !is_string($response['body'] ?? null)
        || (array_key_exists('effective_url', $response)
            && (!is_string($response['effective_url'])
                || !hash_equals($config['endpoint'], $response['effective_url'])))
        || (array_key_exists('redirect_count', $response)
            && (!is_int($response['redirect_count']) || $response['redirect_count'] !== 0))
    ) {
        throw new ManagedCustomerActivationGateException(
            '8 West ID managed-customer evidence transport returned an invalid response.',
        );
    }
    return managed_customer_id_evidence_decode_response(
        $response['status'],
        $response['headers'],
        $response['body'],
        $config['hmac_secret'],
        $customerId,
        $request['nonce'],
        (int)$request['timestamp'],
    );
}
