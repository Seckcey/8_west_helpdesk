<?php
/**
 * Operator-controlled export of one approved Safeharbor time fact to
 * Coastmark's draft-only receiver.
 *
 * The sender never supplies a rate, tax, invoice number, posting instruction,
 * raw note, ticket subject, or review note. Coastmark remains authoritative
 * for every financial fact and for the immutable import receipt.
 */
declare(strict_types=1);

final class CoastmarkTimeExportValidationException extends InvalidArgumentException {}
final class CoastmarkTimeExportTransportException extends RuntimeException {}

const COASTMARK_TIME_EXPORT_FIELDS = [
    'version',
    'event',
    'tenant_key',
    'client_key',
    'entry_key',
    'entry_id',
    'ticket_id',
    'entry_source',
    'technician_key',
    'worked_at',
    'minutes',
    'note_sha256',
    'billable',
    'approval_status',
    'approved_at',
    'reviewer_key',
];

/**
 * Build the exact version-1 fact for one explicitly identified time entry.
 *
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function coastmark_time_export_payload(
    PDO $pdo,
    string $tenantSlug,
    int $entryId,
    string $expectedEntryKey,
    array $config,
): array {
    if ($entryId < 1) {
        throw new CoastmarkTimeExportValidationException('Entry id must be positive.');
    }
    coastmark_time_export_key($tenantSlug, 64, 'Tenant slug');
    coastmark_time_export_key($expectedEntryKey, 64, 'Entry key');

    $tenantAllowlist = coastmark_time_export_allowlist(
        $config['tenant_slugs'] ?? [],
        64,
        'tenant slug',
    );
    if (!in_array($tenantSlug, $tenantAllowlist, true)) {
        throw new CoastmarkTimeExportValidationException('Tenant is not allowlisted for Coastmark time export.');
    }

    $query = $pdo->prepare(
        "SELECT e.id, e.client_id, e.ticket_id, e.entry_key, e.source, e.worked_at,
                e.minutes, e.note, e.billable, e.approval_status,
                e.user_id, e.reviewed_by_user_id, e.reviewed_at,
                t.slug AS tenant_slug,
                reviewer.id AS reviewer_exists
           FROM time_entries e
           JOIN tenants t ON t.id = e.tenant_id
           JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
           JOIN users technician
             ON technician.id = e.user_id AND technician.tenant_id = e.tenant_id
           LEFT JOIN users reviewer
             ON reviewer.id = e.reviewed_by_user_id AND reviewer.tenant_id = e.tenant_id
          WHERE e.id = ? AND e.entry_key = ? AND t.slug = ?
          LIMIT 1"
    );
    $query->execute([$entryId, $expectedEntryKey, $tenantSlug]);
    $entry = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($entry)) {
        throw new CoastmarkTimeExportValidationException(
            'No time entry matched the explicit tenant, id, and entry key.',
        );
    }
    if ((int) $entry['billable'] !== 1 || (string) $entry['approval_status'] !== 'approved') {
        throw new CoastmarkTimeExportValidationException(
            'Only approved billable time can be exported to Coastmark.',
        );
    }
    if ($entry['reviewed_by_user_id'] === null
        || $entry['reviewed_at'] === null
        || $entry['reviewer_exists'] === null
    ) {
        throw new CoastmarkTimeExportValidationException('Approved time is missing reviewer evidence.');
    }

    $clientKey = 'safeharbor-client:' . (int) $entry['client_id'];
    coastmark_time_export_key($clientKey, 128, 'Client key');
    $clientAllowlist = coastmark_time_export_allowlist(
        $config['client_keys'] ?? [],
        128,
        'client key',
    );
    if (!in_array($clientKey, $clientAllowlist, true)) {
        throw new CoastmarkTimeExportValidationException(
            'Client key is not allowlisted for Coastmark time export.',
        );
    }

    $source = (string) $entry['source'];
    if (!in_array($source, ['timer', 'reply', 'suggestion', 'legacy'], true)) {
        throw new CoastmarkTimeExportValidationException('Time entry source is unsupported.');
    }

    $payload = [
        'version' => 1,
        'event' => 'safeharbor.time_entry.approved',
        'tenant_key' => (string) $entry['tenant_slug'],
        'client_key' => $clientKey,
        'entry_key' => (string) $entry['entry_key'],
        'entry_id' => (int) $entry['id'],
        'ticket_id' => (int) $entry['ticket_id'],
        'entry_source' => $source,
        'technician_key' => 'safeharbor-user:' . (int) $entry['user_id'],
        'worked_at' => coastmark_time_export_timestamp((string) $entry['worked_at'], 'Worked at'),
        'minutes' => (int) $entry['minutes'],
        'note_sha256' => hash('sha256', (string) $entry['note']),
        'billable' => true,
        'approval_status' => 'approved',
        'approved_at' => coastmark_time_export_timestamp((string) $entry['reviewed_at'], 'Approved at'),
        'reviewer_key' => 'safeharbor-user:' . (int) $entry['reviewed_by_user_id'],
    ];
    if ($payload['minutes'] < 1 || $payload['minutes'] > 1440) {
        throw new CoastmarkTimeExportValidationException('Minutes are outside the approved export range.');
    }
    if (strcmp($payload['approved_at'], $payload['worked_at']) < 0) {
        throw new CoastmarkTimeExportValidationException('Approval cannot predate the recorded work.');
    }
    return $payload;
}

/** @param array<string, mixed> $payload */
function coastmark_time_export_payload_hash(array $payload): string
{
    coastmark_time_export_assert_payload_shape($payload);
    return hash('sha256', json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ));
}

/**
 * @param array<string, mixed> $payload
 * @return array{body:string,headers:list<string>,payload_sha256:string}
 */
function coastmark_time_export_request(array $payload, string $service, string $secret, int $timestamp): array
{
    coastmark_time_export_assert_payload_shape($payload);
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $service) !== 1) {
        throw new CoastmarkTimeExportValidationException('Service identity is invalid.');
    }
    if (strlen($secret) < 32) {
        throw new CoastmarkTimeExportValidationException('Coastmark export secret is not configured.');
    }
    if ($timestamp < 1_000_000_000 || $timestamp > 9_999_999_999) {
        throw new CoastmarkTimeExportValidationException('Signing timestamp is invalid.');
    }

    $body = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    return [
        'body' => $body,
        'headers' => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-8W-Service: ' . $service,
            'X-8W-Timestamp: ' . $timestamp,
            'X-8W-Signature: ' . $signature,
        ],
        'payload_sha256' => coastmark_time_export_payload_hash($payload),
    ];
}

/**
 * Send exactly once. Ambiguous failures are deliberately returned to the
 * operator; repeating the same entry key is safe because Coastmark is the
 * idempotency authority.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $config
 * @param null|callable(string,list<string>,string,int):array{status:int,body:string} $transport
 * @return array{action:string,import_id:int,invoice_id:int,invoice_line_id:int,payload_sha256:string}
 */
function coastmark_time_export_send(
    array $payload,
    array $config,
    ?callable $transport = null,
    ?int $timestamp = null,
): array {
    if (($config['enabled'] ?? false) !== true) {
        throw new CoastmarkTimeExportValidationException('Coastmark time export is disabled.');
    }
    $endpoint = trim((string) ($config['endpoint'] ?? ''));
    if (!coastmark_time_export_https_endpoint($endpoint)) {
        throw new CoastmarkTimeExportValidationException(
            'Coastmark export endpoint must be the canonical HTTPS receiver URL.',
        );
    }
    $service = trim((string) ($config['service'] ?? ''));
    $secret = (string) ($config['secret'] ?? '');
    $timeout = max(3, min(30, (int) ($config['timeout_seconds'] ?? 15)));
    $request = coastmark_time_export_request($payload, $service, $secret, $timestamp ?? time());

    $transport ??= 'coastmark_time_export_curl';
    $response = $transport($endpoint, $request['headers'], $request['body'], $timeout);
    $status = (int) ($response['status'] ?? 0);
    $responseBody = (string) ($response['body'] ?? '');
    if (strlen($responseBody) > 16_384) {
        throw new CoastmarkTimeExportTransportException('Coastmark response exceeded the safe limit.');
    }
    if (!in_array($status, [200, 201], true)) {
        throw new CoastmarkTimeExportTransportException(
            "Coastmark returned HTTP {$status}; retry only with the same entry key.",
        );
    }

    try {
        $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new CoastmarkTimeExportTransportException('Coastmark returned an invalid acknowledgement.');
    }
    $expectedAction = $status === 201 ? 'created' : 'ignored';
    if (!is_array($decoded)
        || ($decoded['ok'] ?? null) !== true
        || ($decoded['action'] ?? null) !== $expectedAction
        || ($decoded['source']['tenant_key'] ?? null) !== $payload['tenant_key']
        || ($decoded['source']['entry_key'] ?? null) !== $payload['entry_key']
        || ($decoded['payload_sha256'] ?? null) !== $request['payload_sha256']
    ) {
        throw new CoastmarkTimeExportTransportException('Coastmark acknowledgement did not match the sent entry.');
    }
    $coastmark = $decoded['coastmark'] ?? null;
    if (!is_array($coastmark)) {
        throw new CoastmarkTimeExportTransportException('Coastmark acknowledgement omitted draft identifiers.');
    }
    foreach (['import_id', 'invoice_id', 'invoice_line_id'] as $field) {
        if (!is_int($coastmark[$field] ?? null) || $coastmark[$field] < 1) {
            throw new CoastmarkTimeExportTransportException('Coastmark acknowledgement contained an invalid identifier.');
        }
    }
    return [
        'action' => $expectedAction,
        'import_id' => $coastmark['import_id'],
        'invoice_id' => $coastmark['invoice_id'],
        'invoice_line_id' => $coastmark['invoice_line_id'],
        'payload_sha256' => $request['payload_sha256'],
    ];
}

/** @return array{status:int,body:string} */
function coastmark_time_export_curl(string $endpoint, array $headers, string $body, int $timeout): array
{
    if (!function_exists('curl_init')) {
        throw new CoastmarkTimeExportTransportException('PHP cURL is unavailable.');
    }
    $responseBody = '';
    $overflow = false;
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$overflow): int {
            if (strlen($responseBody) + strlen($chunk) > 16_384) {
                $overflow = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $errorCode = curl_errno($curl);
    curl_close($curl);
    if ($overflow) {
        throw new CoastmarkTimeExportTransportException('Coastmark response exceeded the safe limit.');
    }
    if ($ok === false) {
        throw new CoastmarkTimeExportTransportException(
            "Coastmark request failed before a trustworthy acknowledgement (cURL {$errorCode}).",
        );
    }
    return ['status' => $status, 'body' => $responseBody];
}

/** @param array<string, mixed> $payload */
function coastmark_time_export_assert_payload_shape(array $payload): void
{
    if (array_keys($payload) !== COASTMARK_TIME_EXPORT_FIELDS) {
        throw new CoastmarkTimeExportValidationException('Coastmark payload field order or shape is invalid.');
    }
    if (($payload['version'] ?? null) !== 1
        || ($payload['event'] ?? null) !== 'safeharbor.time_entry.approved'
        || ($payload['billable'] ?? null) !== true
        || ($payload['approval_status'] ?? null) !== 'approved'
    ) {
        throw new CoastmarkTimeExportValidationException('Coastmark payload approval contract is invalid.');
    }
    coastmark_time_export_key_value($payload['tenant_key'] ?? null, 64, 'Tenant key');
    coastmark_time_export_key_value($payload['client_key'] ?? null, 128, 'Client key');
    coastmark_time_export_key_value($payload['entry_key'] ?? null, 64, 'Entry key');
    coastmark_time_export_key_value($payload['technician_key'] ?? null, 128, 'Technician key');
    coastmark_time_export_key_value($payload['reviewer_key'] ?? null, 128, 'Reviewer key');
    foreach (['entry_id', 'ticket_id'] as $field) {
        if (!is_int($payload[$field] ?? null) || $payload[$field] < 1) {
            throw new CoastmarkTimeExportValidationException("{$field} must be a positive integer.");
        }
    }
    if (!is_int($payload['minutes'] ?? null)
        || $payload['minutes'] < 1
        || $payload['minutes'] > 1440
    ) {
        throw new CoastmarkTimeExportValidationException('Minutes are outside the approved export range.');
    }
    if (!is_string($payload['entry_source'] ?? null)
        || !in_array($payload['entry_source'], ['timer', 'reply', 'suggestion', 'legacy'], true)
    ) {
        throw new CoastmarkTimeExportValidationException('Time entry source is unsupported.');
    }
    if (!is_string($payload['note_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $payload['note_sha256']) !== 1
    ) {
        throw new CoastmarkTimeExportValidationException('Note digest is invalid.');
    }
    $workedAt = coastmark_time_export_rfc3339_value($payload['worked_at'] ?? null, 'Worked at');
    $approvedAt = coastmark_time_export_rfc3339_value($payload['approved_at'] ?? null, 'Approved at');
    if ($approvedAt < $workedAt) {
        throw new CoastmarkTimeExportValidationException('Approval cannot predate the recorded work.');
    }
}

/** @return list<string> */
function coastmark_time_export_allowlist(mixed $values, int $maximum, string $label): array
{
    if (!is_array($values)) {
        throw new CoastmarkTimeExportValidationException("Configured {$label} allowlist is invalid.");
    }
    $result = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            throw new CoastmarkTimeExportValidationException("Configured {$label} allowlist is invalid.");
        }
        coastmark_time_export_key($value, $maximum, ucfirst($label));
        if (in_array($value, $result, true)) {
            throw new CoastmarkTimeExportValidationException("Configured {$label} allowlist contains a duplicate.");
        }
        $result[] = $value;
    }
    return $result;
}

function coastmark_time_export_key(string $value, int $maximum, string $label): string
{
    if ($value === ''
        || $value !== trim($value)
        || !mb_check_encoding($value, 'UTF-8')
        || mb_strlen($value, 'UTF-8') > $maximum
        || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
    ) {
        throw new CoastmarkTimeExportValidationException("{$label} is invalid.");
    }
    return $value;
}

function coastmark_time_export_key_value(mixed $value, int $maximum, string $label): string
{
    if (!is_string($value)) {
        throw new CoastmarkTimeExportValidationException("{$label} is invalid.");
    }
    return coastmark_time_export_key($value, $maximum, $label);
}

function coastmark_time_export_timestamp(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value
    ) {
        throw new CoastmarkTimeExportValidationException("{$label} is not an exact UTC database timestamp.");
    }
    return $date->format('Y-m-d\TH:i:s\Z');
}

function coastmark_time_export_https_endpoint(string $endpoint): bool
{
    $parts = parse_url($endpoint);
    return is_array($parts)
        && ($parts['scheme'] ?? null) === 'https'
        && is_string($parts['host'] ?? null)
        && $parts['host'] !== ''
        && ($parts['path'] ?? '') === '/api/integrations/safeharbor/time-entries'
        && (!isset($parts['port']) || $parts['port'] === 443)
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['query'])
        && !isset($parts['fragment']);
}

function coastmark_time_export_rfc3339_value(mixed $value, string $label): int
{
    if (!is_string($value)
        || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value) !== 1
    ) {
        throw new CoastmarkTimeExportValidationException("{$label} is not an exact UTC timestamp.");
    }
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s\Z',
        $value,
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i:s\Z') !== $value
    ) {
        throw new CoastmarkTimeExportValidationException("{$label} is not an exact UTC timestamp.");
    }
    return $date->getTimestamp();
}
