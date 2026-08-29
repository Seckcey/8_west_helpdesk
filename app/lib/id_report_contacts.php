<?php
/**
 * Operator-only 8 West ID report-contact snapshot client.
 *
 * This module is loaded by db/manage_business_reports.php only. Cron,
 * generation, and delivery never load it or make this network request.
 */
declare(strict_types=1);

const ID_REPORT_CONTACT_ENDPOINT = 'https://id.8westit.com/api/svc/report-contact.php';
const ID_REPORT_CONTACT_PATH = '/api/svc/report-contact.php';
const ID_REPORT_CONTACT_SERVICE = 'safeharbor-reports';
const ID_REPORT_CONTACT_REQUEST_CONTEXT = '8west-id-report-contact-request-v1';
const ID_REPORT_CONTACT_RESPONSE_CONTEXT = '8west-id-report-contact-response-v1';
const ID_REPORT_CONTACT_MAX_RESPONSE_BYTES = 4096;
const ID_REPORT_CONTACT_MAX_HEADER_BYTES = 8192;
const ID_REPORT_CONTACT_TIMESTAMP_TOLERANCE = 300;
const ID_REPORT_CONTACT_UUID_V4 =
    '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
const ID_REPORT_CONTACT_TENANT_KEY = '/\Aewid-t[1-9][0-9]{0,9}\z/D';
const ID_REPORT_CONTACT_TENANT_SLUG = '/\A[a-z0-9][a-z0-9-]{0,63}\z/D';
const ID_REPORT_CONTACT_CLIENT_KEY = '/\Asafeharbor-client:[1-9][0-9]{0,9}\z/D';
const ID_REPORT_CONTACT_CUSTOMER_KEY =
    '/\Amilepost-customer:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
const ID_REPORT_CONTACT_MASTER_CUSTOMER_ID = '4ebaeefa-b101-47f8-ac76-e49ab309d272';

final class IdReportContactValidationException extends InvalidArgumentException {}
final class IdReportContactGateException extends RuntimeException {}
final class IdReportContactTransportException extends RuntimeException {}

function id_report_contact_tenant_key_valid(string $tenantKey): bool
{
    if (preg_match(ID_REPORT_CONTACT_TENANT_KEY, $tenantKey) !== 1) {
        return false;
    }
    $id = filter_var(substr($tenantKey, 6), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 4_294_967_295],
    ]);
    return is_int($id);
}

/**
 * @return array{
 *   enabled:bool,
 *   endpoint:string,
 *   hmac_secret:string,
 *   tenant_bindings:array<string,string>,
 *   client_bindings:array<string,string>,
 *   customer_bindings:array<string,string>,
 *   timeout_seconds:int
 * }
 */
function id_report_contact_config(?array $source = null): array
{
    if ($source === null) {
        $source = function_exists('cfg') ? cfg('id_report_contacts', []) : [];
    }
    if (!is_array($source)) {
        throw new IdReportContactValidationException('8 West ID report-contact configuration is invalid.');
    }
    $enabled = $source['enabled'] ?? false;
    $endpoint = $source['endpoint'] ?? ID_REPORT_CONTACT_ENDPOINT;
    $secret = $source['hmac_secret'] ?? '';
    $bindings = $source['tenant_bindings'] ?? [];
    $clientBindings = $source['client_bindings'] ?? [];
    $customerBindings = $source['customer_bindings'] ?? [];
    $timeout = $source['timeout_seconds'] ?? 10;
    if (!is_bool($enabled)
        || !is_string($endpoint)
        || !hash_equals(ID_REPORT_CONTACT_ENDPOINT, $endpoint)
        || !is_string($secret)
        || ($secret !== '' && preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1)
        || !is_array($bindings)
        || count($bindings) > 64
        || !is_array($clientBindings)
        || count($clientBindings) > 256
        || !is_array($customerBindings)
        || count($customerBindings) > 256
        || !is_int($timeout)
        || $timeout < 1
        || $timeout > 30
    ) {
        throw new IdReportContactValidationException('8 West ID report-contact configuration is invalid.');
    }

    $normalizedBindings = [];
    foreach ($bindings as $tenantSlug => $tenantKey) {
        if (!is_string($tenantSlug)
            || preg_match(ID_REPORT_CONTACT_TENANT_SLUG, $tenantSlug) !== 1
            || !is_string($tenantKey)
            || !id_report_contact_tenant_key_valid($tenantKey)
            || in_array($tenantKey, $normalizedBindings, true)
        ) {
            throw new IdReportContactValidationException('8 West ID report-contact tenant binding is invalid.');
        }
        $normalizedBindings[$tenantSlug] = $tenantKey;
    }

    $normalizedClientBindings = [];
    foreach ($clientBindings as $clientKey => $tenantKey) {
        if (!is_string($clientKey)
            || preg_match(ID_REPORT_CONTACT_CLIENT_KEY, $clientKey) !== 1
            || filter_var(substr($clientKey, 18), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 4_294_967_295],
            ]) === false
            || !is_string($tenantKey)
            || !id_report_contact_tenant_key_valid($tenantKey)
            || in_array($tenantKey, $normalizedBindings, true)
            || in_array($tenantKey, $normalizedClientBindings, true)
        ) {
            throw new IdReportContactValidationException('8 West ID report-contact client binding is invalid.');
        }
        $normalizedClientBindings[$clientKey] = $tenantKey;
    }

    $normalizedCustomerBindings = [];
    foreach ($customerBindings as $customerKey => $tenantKey) {
        if (!is_string($customerKey)
            || preg_match(ID_REPORT_CONTACT_CUSTOMER_KEY, $customerKey) !== 1
            || hash_equals(
                'milepost-customer:' . ID_REPORT_CONTACT_MASTER_CUSTOMER_ID,
                $customerKey,
            )
            || !is_string($tenantKey)
            || !id_report_contact_tenant_key_valid($tenantKey)
            || in_array($tenantKey, $normalizedBindings, true)
            || in_array($tenantKey, $normalizedClientBindings, true)
            || in_array($tenantKey, $normalizedCustomerBindings, true)
        ) {
            throw new IdReportContactValidationException('8 West ID report-contact customer binding is invalid.');
        }
        $normalizedCustomerBindings[$customerKey] = $tenantKey;
    }
    if ($enabled
        && ($secret === '' || (
            $normalizedBindings === []
            && $normalizedClientBindings === []
            && $normalizedCustomerBindings === []
        ))
    ) {
        throw new IdReportContactValidationException('Enabled 8 West ID report-contact configuration is incomplete.');
    }

    return [
        'enabled' => $enabled,
        'endpoint' => $endpoint,
        'hmac_secret' => $secret,
        'tenant_bindings' => $normalizedBindings,
        'client_bindings' => $normalizedClientBindings,
        'customer_bindings' => $normalizedCustomerBindings,
        'timeout_seconds' => $timeout,
    ];
}

function id_report_contact_client_key(int $clientId): string
{
    if ($clientId < 1 || $clientId > 4_294_967_295) {
        throw new IdReportContactValidationException('Safeharbor report-contact client id is invalid.');
    }
    return 'safeharbor-client:' . $clientId;
}

function id_report_contact_customer_key(string $customerId): string
{
    if (preg_match(ID_REPORT_CONTACT_UUID_V4, $customerId) !== 1
        || hash_equals(ID_REPORT_CONTACT_MASTER_CUSTOMER_ID, $customerId)
    ) {
        throw new IdReportContactValidationException('Milepost report-contact customer id is invalid.');
    }
    return 'milepost-customer:' . $customerId;
}

function id_report_contact_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-'
        . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}

function id_report_contact_request_preimage(
    string $timestamp,
    string $nonce,
    string $rawBody,
): string {
    return ID_REPORT_CONTACT_REQUEST_CONTEXT . "\nPOST\n" . ID_REPORT_CONTACT_PATH
        . "\n{$timestamp}\n{$nonce}\n" . hash('sha256', $rawBody);
}

/**
 * @return array{timestamp:string,nonce:string,body:string,headers:list<string>}
 */
function id_report_contact_request(
    string $tenantKey,
    string $secret,
    ?int $now = null,
    ?string $nonce = null,
): array {
    if (!id_report_contact_tenant_key_valid($tenantKey)
        || preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1
    ) {
        throw new IdReportContactValidationException('8 West ID report-contact request input is invalid.');
    }
    $timestamp = (string)($now ?? time());
    if (preg_match('/\A[1-9][0-9]{0,11}\z/D', $timestamp) !== 1) {
        throw new IdReportContactValidationException('8 West ID report-contact request time is invalid.');
    }
    $nonce ??= id_report_contact_uuid_v4();
    if (preg_match(ID_REPORT_CONTACT_UUID_V4, $nonce) !== 1) {
        throw new IdReportContactValidationException('8 West ID report-contact request nonce is invalid.');
    }
    $body = json_encode(
        ['schema_version' => 1, 'tenant_key' => $tenantKey],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $signature = hash_hmac(
        'sha256',
        id_report_contact_request_preimage($timestamp, $nonce, $body),
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

/** Count top-level JSON members so duplicate keys cannot be hidden by json_decode(). */
function id_report_contact_member_count(string $rawBody): int
{
    $depth = 0;
    $commas = 0;
    $inString = false;
    $escaped = false;
    for ($index = 0, $length = strlen($rawBody); $index < $length; $index++) {
        $byte = $rawBody[$index];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($byte === '\\') {
                $escaped = true;
            } elseif ($byte === '"') {
                $inString = false;
            }
            continue;
        }
        if ($byte === '"') {
            $inString = true;
        } elseif ($byte === '{' || $byte === '[') {
            $depth++;
        } elseif ($byte === '}' || $byte === ']') {
            $depth--;
        } elseif ($byte === ',' && $depth === 1) {
            $commas++;
        }
    }
    $decoded = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
    return is_array($decoded) && $decoded !== [] ? $commas + 1 : 0;
}

function id_report_contact_email(string $value): string
{
    if ($value === ''
        || $value !== strtolower(trim($value))
        || strlen($value) > 190
        || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        || filter_var($value, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new IdReportContactTransportException('8 West ID returned an invalid report-contact document.');
    }
    return $value;
}

function id_report_contact_timestamp(string $value): int
{
    if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) !== 1) {
        throw new IdReportContactTransportException('8 West ID returned an invalid report-contact document.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
        throw new IdReportContactTransportException('8 West ID returned an invalid report-contact document.');
    }
    return $date->getTimestamp();
}

/** @param array<string,list<string>> $headers */
function id_report_contact_response_signature_header(array $headers): string
{
    $values = $headers['x-8w-report-contact-signature'] ?? null;
    if (!is_array($values)
        || count($values) !== 1
        || !is_string($values[0])
        || preg_match('/\A[0-9a-f]{64}\z/D', $values[0]) !== 1
    ) {
        throw new IdReportContactTransportException('8 West ID response authentication was invalid.');
    }
    return $values[0];
}

/**
 * @param array<string,list<string>> $headers
 * @return array{
 *   tenant_key:string,tenant_slug:string,contact_version:int,
 *   recipient_email:string,generated_at:string,generated_at_db:string,
 *   request_nonce_sha256:string,response_sha256:string
 * }
 */
function id_report_contact_decode_response(
    int $status,
    array $headers,
    string $rawBody,
    string $secret,
    string $expectedTenantKey,
    ?string $expectedTenantSlug,
    string $requestNonce,
    int $requestTime,
): array {
    if ($status !== 200
        || $rawBody === ''
        || strlen($rawBody) > ID_REPORT_CONTACT_MAX_RESPONSE_BYTES
        || preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1
        || !id_report_contact_tenant_key_valid($expectedTenantKey)
        || ($expectedTenantSlug !== null
            && preg_match(ID_REPORT_CONTACT_TENANT_SLUG, $expectedTenantSlug) !== 1)
        || preg_match(ID_REPORT_CONTACT_UUID_V4, $requestNonce) !== 1
        || $requestTime < 1
    ) {
        throw new IdReportContactTransportException('8 West ID did not return an exact report-contact snapshot.');
    }

    $signature = id_report_contact_response_signature_header($headers);
    $responsePreimage = ID_REPORT_CONTACT_RESPONSE_CONTEXT . "\n{$requestNonce}\n"
        . hash('sha256', $rawBody);
    if (!hash_equals(hash_hmac('sha256', $responsePreimage, $secret), $signature)) {
        throw new IdReportContactTransportException('8 West ID response authentication was invalid.');
    }

    try {
        $document = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
        $memberCount = id_report_contact_member_count($rawBody);
    } catch (JsonException $error) {
        throw new IdReportContactTransportException(
            '8 West ID returned an invalid report-contact document.',
            0,
            $error,
        );
    }
    $expectedKeys = [
        'ok', 'schema_version', 'tenant_key', 'tenant_slug', 'contact_version',
        'weekly_report_email', 'generated_at', 'request_nonce',
    ];
    if (!is_array($document)
        || array_is_list($document)
        || array_keys($document) !== $expectedKeys
        || $memberCount !== count($expectedKeys)
        || ($document['ok'] ?? null) !== true
        || ($document['schema_version'] ?? null) !== 1
        || ($document['tenant_key'] ?? null) !== $expectedTenantKey
        || !is_string($document['tenant_slug'] ?? null)
        || preg_match(ID_REPORT_CONTACT_TENANT_SLUG, $document['tenant_slug']) !== 1
        || ($expectedTenantSlug !== null && $document['tenant_slug'] !== $expectedTenantSlug)
        || !is_int($document['contact_version'] ?? null)
        || $document['contact_version'] < 1
        || !is_string($document['weekly_report_email'] ?? null)
        || !is_string($document['generated_at'] ?? null)
        || ($document['request_nonce'] ?? null) !== $requestNonce
    ) {
        throw new IdReportContactTransportException('8 West ID returned an invalid report-contact document.');
    }
    $email = id_report_contact_email($document['weekly_report_email']);
    $generated = id_report_contact_timestamp($document['generated_at']);
    if (abs($generated - $requestTime) > ID_REPORT_CONTACT_TIMESTAMP_TOLERANCE) {
        throw new IdReportContactTransportException('8 West ID returned a stale report-contact document.');
    }

    return [
        'tenant_key' => $expectedTenantKey,
        'tenant_slug' => $document['tenant_slug'],
        'contact_version' => $document['contact_version'],
        'recipient_email' => $email,
        'generated_at' => $document['generated_at'],
        'generated_at_db' => gmdate('Y-m-d H:i:s', $generated),
        'request_nonce_sha256' => hash('sha256', $requestNonce),
        'response_sha256' => hash('sha256', $rawBody),
    ];
}

/**
 * @param list<string> $headers
 * @return array{status:int,headers:array<string,list<string>>,body:string,effective_url:string,redirect_count:int}
 */
function id_report_contact_curl(
    string $endpoint,
    array $headers,
    string $body,
    int $timeout,
    int $maxBytes,
): array {
    if (!function_exists('curl_init')) {
        throw new IdReportContactTransportException('PHP cURL is unavailable.');
    }
    if (!hash_equals(ID_REPORT_CONTACT_ENDPOINT, $endpoint)
        || $maxBytes !== ID_REPORT_CONTACT_MAX_RESPONSE_BYTES
    ) {
        throw new IdReportContactValidationException('8 West ID transport scope is invalid.');
    }

    $responseBody = '';
    $responseHeaders = [];
    $bodyOverflow = false;
    $headerBytes = 0;
    $headerOverflow = false;
    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new IdReportContactTransportException('8 West ID request could not be initialized.');
    }
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (
            &$responseHeaders,
            &$headerBytes,
            &$headerOverflow,
        ): int {
            $headerBytes += strlen($line);
            if ($headerBytes > ID_REPORT_CONTACT_MAX_HEADER_BYTES) {
                $headerOverflow = true;
                return 0;
            }
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $value = trim(substr($line, $separator + 1));
                if ($name !== '' && preg_match('/\A[a-z0-9-]+\z/D', $name) === 1) {
                    $responseHeaders[$name][] = $value;
                }
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
            &$responseBody,
            &$bodyOverflow,
            $maxBytes,
        ): int {
            if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                $bodyOverflow = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($curl, $options);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    $redirectCount = (int)curl_getinfo($curl, CURLINFO_REDIRECT_COUNT);
    $errorCode = curl_errno($curl);
    curl_close($curl);

    if ($bodyOverflow || $headerOverflow) {
        throw new IdReportContactTransportException('8 West ID response exceeded its safe limit.');
    }
    if ($ok === false) {
        throw new IdReportContactTransportException(
            "8 West ID request failed before an authenticated response (cURL {$errorCode}).",
        );
    }
    if (!hash_equals($endpoint, $effectiveUrl) || $redirectCount !== 0) {
        throw new IdReportContactTransportException('8 West ID response changed the exact endpoint.');
    }
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $responseBody,
        'effective_url' => $effectiveUrl,
        'redirect_count' => $redirectCount,
    ];
}

/**
 * Fetch one configured tenant's current contact during explicit operator setup.
 *
 * @param callable(string,list<string>,string,int,int):array<string,mixed>|null $transport
 * @return array{
 *   tenant_key:string,tenant_slug:string,contact_version:int,
 *   recipient_email:string,generated_at:string,generated_at_db:string,
 *   request_nonce_sha256:string,response_sha256:string
 * }
 */
function id_report_contact_fetch(
    string $tenantSlug,
    ?array $source = null,
    ?callable $transport = null,
    ?int $now = null,
): array {
    $config = id_report_contact_config($source);
    if ($config['enabled'] !== true) {
        throw new IdReportContactGateException('8 West ID report-contact lookup is disabled.');
    }
    if (preg_match(ID_REPORT_CONTACT_TENANT_SLUG, $tenantSlug) !== 1
        || !array_key_exists($tenantSlug, $config['tenant_bindings'])
    ) {
        throw new IdReportContactGateException('The exact report-contact tenant is not configured.');
    }
    return id_report_contact_fetch_bound_key(
        $config['tenant_bindings'][$tenantSlug],
        $tenantSlug,
        $config,
        $transport,
        $now,
    );
}

/**
 * Fetch the current contact for one exact configured Safeharbor client.
 * The ID tenant slug is authenticated output, never inferred from local data.
 *
 * @param callable(string,list<string>,string,int,int):array<string,mixed>|null $transport
 * @return array{
 *   tenant_key:string,tenant_slug:string,contact_version:int,
 *   recipient_email:string,generated_at:string,generated_at_db:string,
 *   request_nonce_sha256:string,response_sha256:string
 * }
 */
function id_report_contact_fetch_client(
    int $clientId,
    ?array $source = null,
    ?callable $transport = null,
    ?int $now = null,
): array {
    $config = id_report_contact_config($source);
    if ($config['enabled'] !== true) {
        throw new IdReportContactGateException('8 West ID report-contact lookup is disabled.');
    }
    $clientKey = id_report_contact_client_key($clientId);
    if (!array_key_exists($clientKey, $config['client_bindings'])) {
        throw new IdReportContactGateException('The exact report-contact client is not configured.');
    }
    return id_report_contact_fetch_bound_key(
        $config['client_bindings'][$clientKey],
        null,
        $config,
        $transport,
        $now,
    );
}

/** Resolve one client's exact active Milepost customer UUID without guessing. */
function id_report_contact_active_customer_id(
    PDO $pdo,
    string $tenantSlug,
    int $clientId,
): string {
    if (preg_match(ID_REPORT_CONTACT_TENANT_SLUG, $tenantSlug) !== 1
        || $clientId < 1
        || $clientId > 4_294_967_295
    ) {
        throw new IdReportContactValidationException('Managed report-contact customer target is invalid.');
    }

    $statement = $pdo->prepare(
        "SELECT binding.customer_id
           FROM tenants tenant
           JOIN clients client
             ON client.tenant_id = tenant.id
            AND client.id = ?
           JOIN suite_customer_sync_bindings binding
             ON binding.tenant_id = tenant.id
            AND binding.client_id = client.id
            AND binding.status = 'active'
          WHERE tenant.slug = ?"
    );
    $statement->execute([$clientId, $tenantSlug]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1 || !is_string($rows[0]['customer_id'] ?? null)) {
        throw new IdReportContactGateException(
            'The Safeharbor client has no exact active Milepost customer binding.',
        );
    }
    id_report_contact_customer_key($rows[0]['customer_id']);
    return $rows[0]['customer_id'];
}

/**
 * Fetch the current contact for one exact Milepost customer UUID already
 * resolved by the caller. This network client does not query Safeharbor's
 * binding table: protected configuration is keyed by the supplied UUID, never
 * by a wipe-sensitive local id, company name, domain, or email address.
 *
 * @param callable(string,list<string>,string,int,int):array<string,mixed>|null $transport
 * @return array{
 *   tenant_key:string,tenant_slug:string,contact_version:int,
 *   recipient_email:string,generated_at:string,generated_at_db:string,
 *   request_nonce_sha256:string,response_sha256:string
 * }
 */
function id_report_contact_fetch_customer(
    string $customerId,
    ?array $source = null,
    ?callable $transport = null,
    ?int $now = null,
): array {
    $config = id_report_contact_config($source);
    if ($config['enabled'] !== true) {
        throw new IdReportContactGateException('8 West ID report-contact lookup is disabled.');
    }

    $customerKey = id_report_contact_customer_key($customerId);
    if (!array_key_exists($customerKey, $config['customer_bindings'])) {
        throw new IdReportContactGateException('The exact report-contact customer is not configured.');
    }
    return id_report_contact_fetch_bound_key(
        $config['customer_bindings'][$customerKey],
        null,
        $config,
        $transport,
        $now,
        $customerKey,
    );
}

/**
 * @param array<string,mixed> $config
 * @param callable(string,list<string>,string,int,int):array<string,mixed>|null $transport
 * @return array{
 *   tenant_key:string,tenant_slug:string,contact_version:int,
 *   recipient_email:string,generated_at:string,generated_at_db:string,
 *   request_nonce_sha256:string,response_sha256:string
 * }
 */
function id_report_contact_fetch_bound_key(
    string $tenantKey,
    ?string $expectedTenantSlug,
    array $config,
    ?callable $transport = null,
    ?int $now = null,
    ?string $expectedCustomerKey = null,
): array {
    $config = id_report_contact_config($config);
    if ($config['enabled'] !== true) {
        throw new IdReportContactGateException('8 West ID report-contact lookup is disabled.');
    }
    $configured = $expectedCustomerKey !== null
        ? (($config['customer_bindings'][$expectedCustomerKey] ?? null) === $tenantKey)
        : ($expectedTenantSlug === null
            ? in_array($tenantKey, $config['client_bindings'], true)
            : (($config['tenant_bindings'][$expectedTenantSlug] ?? null) === $tenantKey));
    if (!$configured) {
        throw new IdReportContactGateException('The exact report-contact binding is not configured.');
    }
    $requestTime = $now ?? time();
    $request = id_report_contact_request(
        $tenantKey,
        $config['hmac_secret'],
        $requestTime,
    );
    $transport ??= 'id_report_contact_curl';
    $response = $transport(
        $config['endpoint'],
        $request['headers'],
        $request['body'],
        $config['timeout_seconds'],
        ID_REPORT_CONTACT_MAX_RESPONSE_BYTES,
    );
    if (!is_array($response)
        || !is_int($response['status'] ?? null)
        || !is_array($response['headers'] ?? null)
        || !is_string($response['body'] ?? null)
    ) {
        throw new IdReportContactTransportException('8 West ID transport returned an invalid response.');
    }
    if (array_key_exists('effective_url', $response)
        && (!is_string($response['effective_url'])
            || !hash_equals($config['endpoint'], $response['effective_url']))
    ) {
        throw new IdReportContactTransportException('8 West ID response changed the exact endpoint.');
    }
    if (array_key_exists('redirect_count', $response)
        && (!is_int($response['redirect_count']) || $response['redirect_count'] !== 0)
    ) {
        throw new IdReportContactTransportException('8 West ID response changed the exact endpoint.');
    }
    return id_report_contact_decode_response(
        $response['status'],
        $response['headers'],
        $response['body'],
        $config['hmac_secret'],
        $tenantKey,
        $expectedTenantSlug,
        $request['nonce'],
        (int)$request['timestamp'],
    );
}
