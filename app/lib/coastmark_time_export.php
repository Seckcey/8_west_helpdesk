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

const COASTMARK_TIME_EXPORT_VERSION = 2;
const COASTMARK_TIME_EXPORT_CLIENT_PREFIX = 'milepost-customer:';
const COASTMARK_TIME_EXPORT_MASTER_CUSTOMER_ID = '4ebaeefa-b101-47f8-ac76-e49ab309d272';

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
 * Build the exact version-2 fact for one explicitly identified time entry.
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
                reviewer.id AS reviewer_exists,
                EXISTS (
                    SELECT 1 FROM time_entry_approval_adjustments adjustment
                     WHERE adjustment.tenant_id = e.tenant_id
                       AND adjustment.time_entry_id = e.id
                ) AS has_approval_adjustment,
                customer_binding.customer_id,
                customer_binding.status AS customer_status
           FROM time_entries e
           JOIN tenants t ON t.id = e.tenant_id
           JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
           JOIN users technician
             ON technician.id = e.user_id AND technician.tenant_id = e.tenant_id
           LEFT JOIN users reviewer
             ON reviewer.id = e.reviewed_by_user_id AND reviewer.tenant_id = e.tenant_id
           LEFT JOIN suite_customer_sync_bindings customer_binding
             ON customer_binding.tenant_id = e.tenant_id
            AND customer_binding.client_id = e.client_id
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
    if ((int) ($entry['has_approval_adjustment'] ?? 0) === 1) {
        throw new CoastmarkTimeExportValidationException(
            'Adjusted approved time cannot use the Coastmark v2 export contract.',
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

    if ($entry['customer_id'] === null) {
        throw new CoastmarkTimeExportValidationException(
            'Time entry client has no Milepost customer binding.',
        );
    }
    if ((string) $entry['customer_status'] !== 'active') {
        throw new CoastmarkTimeExportValidationException(
            'Time entry client does not have an active Milepost customer binding.',
        );
    }
    $clientKey = coastmark_time_export_customer_client_key((string) $entry['customer_id']);
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
        'version' => COASTMARK_TIME_EXPORT_VERSION,
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
 * Version 2 is permanently inspection-only once approval adjustments exist.
 *
 * A detached v2 payload has no Safeharbor receipt/claim that can serialize a
 * later correction, and Coastmark has no reversal acknowledgement. Therefore
 * even a fully enabled configuration must stop before signing or transport.
 * A new receipt/reversal-aware v3 must replace this function; this is never a
 * flag to flip.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $config
 * @param null|callable(string,list<string>,string,int):array{status:int,body:string} $transport
 */
function coastmark_time_export_send(
    array $payload,
    array $config,
    ?callable $transport = null,
    ?int $timestamp = null,
): never {
    throw new CoastmarkTimeExportValidationException(
        'Coastmark time export v2 is retired; a receipt/reversal-aware v3 is required before any send.',
    );
}

/** @param array<string, mixed> $payload */
function coastmark_time_export_assert_payload_shape(array $payload): void
{
    if (array_keys($payload) !== COASTMARK_TIME_EXPORT_FIELDS) {
        throw new CoastmarkTimeExportValidationException('Coastmark payload field order or shape is invalid.');
    }
    if (($payload['version'] ?? null) !== COASTMARK_TIME_EXPORT_VERSION
        || ($payload['event'] ?? null) !== 'safeharbor.time_entry.approved'
        || ($payload['billable'] ?? null) !== true
        || ($payload['approval_status'] ?? null) !== 'approved'
    ) {
        throw new CoastmarkTimeExportValidationException('Coastmark payload approval contract is invalid.');
    }
    coastmark_time_export_key_value($payload['tenant_key'] ?? null, 64, 'Tenant key');
    coastmark_time_export_client_key_value($payload['client_key'] ?? null);
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

function coastmark_time_export_customer_client_key(string $customerId): string
{
    if (preg_match(
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
        $customerId,
    ) !== 1) {
        throw new CoastmarkTimeExportValidationException('Milepost customer id is invalid.');
    }
    if (hash_equals(COASTMARK_TIME_EXPORT_MASTER_CUSTOMER_ID, $customerId)) {
        throw new CoastmarkTimeExportValidationException(
            '8 West IT is the master MSP and is never a billable Coastmark customer.',
        );
    }

    return COASTMARK_TIME_EXPORT_CLIENT_PREFIX . $customerId;
}

function coastmark_time_export_client_key_value(mixed $value): string
{
    if (!is_string($value) || !str_starts_with($value, COASTMARK_TIME_EXPORT_CLIENT_PREFIX)) {
        throw new CoastmarkTimeExportValidationException('Client key is invalid.');
    }
    $customerId = substr($value, strlen(COASTMARK_TIME_EXPORT_CLIENT_PREFIX));
    $expected = coastmark_time_export_customer_client_key($customerId);
    if (!hash_equals($expected, $value)) {
        throw new CoastmarkTimeExportValidationException('Client key is invalid.');
    }

    return $value;
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
