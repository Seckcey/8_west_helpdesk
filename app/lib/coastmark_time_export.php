<?php
/**
 * Receipt-backed, operator-controlled export of approved Safeharbor time.
 *
 * Version 3 separates claim, send, and status. Claims pin source facts in
 * MySQL before a network call; receipts are append-only; status resolves an
 * ambiguous request without replaying it. There is no batch or auto retry.
 */
declare(strict_types=1);

final class CoastmarkTimeExportValidationException extends InvalidArgumentException {}
final class CoastmarkTimeExportConflictException extends RuntimeException {}
final class CoastmarkTimeExportAmbiguousException extends RuntimeException {}

const COASTMARK_TIME_EXPORT_VERSION = 3;
const COASTMARK_TIME_EXPORT_CLIENT_PREFIX = 'milepost-customer:';
const COASTMARK_TIME_EXPORT_MASTER_CUSTOMER_ID = '4ebaeefa-b101-47f8-ac76-e49ab309d272';
const COASTMARK_TIME_EXPORT_FIELDS = [
    'version', 'event', 'event_key', 'predecessor_event_key', 'source_version',
    'tenant_key', 'client_key', 'entry_key', 'entry_id', 'ticket_id',
    'entry_source', 'technician_key', 'worked_at', 'minutes', 'note_sha256',
    'billable', 'approval_status', 'approved_at', 'reviewer_key', 'adjusted_at',
    'adjusted_by_key', 'adjustment_reason_sha256',
];
const COASTMARK_TIME_STATUS_FIELDS = [
    'version', 'event', 'tenant_key', 'event_key', 'payload_sha256',
];

/**
 * Open the dedicated operator-only database identity. It deliberately reuses
 * only the local host/database coordinates, never the web runtime identity.
 *
 * @param array<string,mixed> $database
 * @param array<string,mixed> $config
 */
function coastmark_time_export_database(array $database, array $config): PDO
{
    $host = $database['host'] ?? null;
    $port = $database['port'] ?? null;
    $name = $database['name'] ?? null;
    $charset = $database['charset'] ?? null;
    $runtimeUser = $database['user'] ?? null;
    $user = $config['database_user'] ?? null;
    $password = $config['database_password'] ?? null;
    if (!is_string($host)
        || !in_array($host, ['127.0.0.1', 'localhost'], true)
        || !is_int($port) || $port < 1 || $port > 65535
        || !is_string($name) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1
        || $charset !== 'utf8mb4'
        || !is_string($runtimeUser) || $runtimeUser === ''
        || !is_string($user) || preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/D', $user) !== 1
        || hash_equals($runtimeUser, $user)
        || !is_string($password) || strlen($password) < 24
    ) {
        throw new CoastmarkTimeExportValidationException(
            'Dedicated Coastmark export database configuration is incomplete.',
        );
    }
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    } catch (PDOException $error) {
        throw new CoastmarkTimeExportValidationException(
            'Dedicated Coastmark export database connection is unavailable.',
            0,
            $error,
        );
    }
}

/**
 * Pin the next unclaimed source version for one exact approved entry.
 *
 * Lock order is tenant -> time entry -> actor -> prior claim/adjustment. Adjustment
 * creation uses the same tenant -> time entry -> actor prefix, so races serialize.
 *
 * @param array<string,mixed> $config
 * @return array{claim:array<string,mixed>,payload:array<string,mixed>,replayed:bool}
 */
function coastmark_time_export_claim(
    PDO $pdo,
    string $tenantSlug,
    int $entryId,
    string $expectedEntryKey,
    int $actorUserId,
    array $config,
    ?string $eventKey = null,
): array {
    if ($entryId < 1 || $actorUserId < 1) {
        throw new CoastmarkTimeExportValidationException('Entry id and actor user id must be positive.');
    }
    if (($config['claim_enabled'] ?? false) !== true) {
        throw new CoastmarkTimeExportValidationException('Coastmark export claiming is disabled.');
    }
    coastmark_time_export_key($tenantSlug, 64, 'Tenant slug');
    coastmark_time_export_key($expectedEntryKey, 64, 'Entry key');
    coastmark_time_export_assert_allowlisted_tenant($tenantSlug, $config);
    $eventKey ??= 'safeharbor-time:' . bin2hex(random_bytes(16));
    coastmark_time_export_event_key($eventKey);

    $operation = function () use (
        $pdo, $tenantSlug, $entryId, $expectedEntryKey, $actorUserId, $config, $eventKey,
    ): array {
        $lock = coastmark_time_export_lock_clause($pdo);
        $query = $pdo->prepare("SELECT id FROM tenants WHERE slug = ?{$lock}");
        $query->execute([$tenantSlug]);
        $tenantId = (int) ($query->fetchColumn() ?: 0);
        if ($tenantId < 1) {
            throw new CoastmarkTimeExportValidationException('Tenant was not found.');
        }

        $query = $pdo->prepare(
            "SELECT e.id,e.tenant_id,e.client_id,e.ticket_id,e.entry_key,e.source,
                    e.worked_at,e.minutes,e.note,e.billable,e.approval_status,
                    e.user_id,e.reviewed_by_user_id,e.reviewed_at,
                    t.slug AS tenant_slug,reviewer.id AS reviewer_exists,
                    binding.customer_id,binding.status AS customer_status
               FROM time_entries e
               JOIN tenants t ON t.id=e.tenant_id
               JOIN clients c ON c.id=e.client_id AND c.tenant_id=e.tenant_id
               JOIN users technician
                 ON technician.id=e.user_id AND technician.tenant_id=e.tenant_id
               LEFT JOIN users reviewer
                 ON reviewer.id=e.reviewed_by_user_id AND reviewer.tenant_id=e.tenant_id
               LEFT JOIN suite_customer_sync_bindings binding
                 ON binding.tenant_id=e.tenant_id AND binding.client_id=e.client_id
              WHERE e.tenant_id=? AND e.id=? AND e.entry_key=? LIMIT 1{$lock}"
        );
        $query->execute([$tenantId, $entryId, $expectedEntryKey]);
        $entry = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($entry)) {
            throw new CoastmarkTimeExportValidationException(
                'No time entry matched the explicit tenant, id, and entry key.',
            );
        }
        coastmark_time_export_assert_entry($entry, $config);

        $query = $pdo->prepare(
            "SELECT id,role,is_active FROM users
              WHERE tenant_id=? AND id=? LIMIT 1{$lock}"
        );
        $query->execute([$tenantId, $actorUserId]);
        $actor = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($actor)
            || (int) $actor['is_active'] !== 1
            || !in_array((string) $actor['role'], ['owner', 'admin'], true)
        ) {
            throw new CoastmarkTimeExportValidationException(
                'Claim actor must be an active owner or admin in this tenant.',
            );
        }

        $query = $pdo->prepare(
            "SELECT * FROM coastmark_time_export_claims
              WHERE tenant_id=? AND time_entry_id=?
              ORDER BY source_version DESC LIMIT 1{$lock}"
        );
        $query->execute([$tenantId, $entryId]);
        $latestClaim = $query->fetch(PDO::FETCH_ASSOC);
        $latestClaim = is_array($latestClaim) ? $latestClaim : null;

        $query = $pdo->prepare(
            "SELECT * FROM time_entry_approval_adjustments
              WHERE tenant_id=? AND time_entry_id=?
              ORDER BY version_no DESC LIMIT 1{$lock}"
        );
        $query->execute([$tenantId, $entryId]);
        $latestAdjustment = $query->fetch(PDO::FETCH_ASSOC);
        $maximumVersion = is_array($latestAdjustment) ? (int) $latestAdjustment['version_no'] : 0;

        if ($latestClaim !== null && (int) $latestClaim['source_version'] === $maximumVersion) {
            $payload = coastmark_time_export_decode_claim_payload($latestClaim);
            $expected = coastmark_time_export_payload_for_version(
                $pdo,
                $entry,
                $maximumVersion,
                (string) $latestClaim['event_key'],
                coastmark_time_export_predecessor_key($pdo, $latestClaim),
            );
            if ($payload !== $expected
                || !hash_equals((string) $latestClaim['payload_sha256'], coastmark_time_export_payload_hash($expected))
            ) {
                throw new CoastmarkTimeExportConflictException(
                    'The stored claim conflicts with immutable source facts.',
                );
            }
            return ['claim' => $latestClaim, 'payload' => $payload, 'replayed' => true];
        }

        $sourceVersion = $latestClaim === null ? 0 : ((int) $latestClaim['source_version']) + 1;
        if ($sourceVersion > $maximumVersion) {
            throw new CoastmarkTimeExportConflictException('No unclaimed source version exists.');
        }

        $payload = coastmark_time_export_payload_for_version(
            $pdo,
            $entry,
            $sourceVersion,
            $eventKey,
            $latestClaim === null ? null : (string) $latestClaim['event_key'],
        );
        $payloadHash = coastmark_time_export_payload_hash($payload);
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $insert = $pdo->prepare(
            'INSERT INTO coastmark_time_export_claims
                (tenant_id,time_entry_id,source_version,event_key,predecessor_claim_id,
                 payload_sha256,payload_json,created_by_user_id,created_at)
             VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            $tenantId, $entryId, $sourceVersion, $eventKey, $latestClaim['id'] ?? null,
            $payloadHash, $payloadJson, $actorUserId,
        ]);
        $claim = coastmark_time_export_find_claim($pdo, $tenantId, (int) $pdo->lastInsertId());
        if ($claim === null) {
            throw new RuntimeException('The export claim could not be read back.');
        }
        return ['claim' => $claim, 'payload' => $payload, 'replayed' => false];
    };
    try {
        return coastmark_time_export_transaction($pdo, $operation);
    } catch (PDOException $error) {
        if (!coastmark_time_export_is_claim_race($error)) throw $error;
        // A concurrent claimant may have won the immutable source-version row.
        // Re-read once; the normal exact-replay branch must now converge on it.
        return coastmark_time_export_transaction($pdo, $operation);
    }
}

/** @param array<string,mixed> $entry @param array<string,mixed> $config */
function coastmark_time_export_assert_entry(array $entry, array $config): void
{
    if ((int) $entry['billable'] !== 1 || (string) $entry['approval_status'] !== 'approved') {
        throw new CoastmarkTimeExportValidationException('Only approved billable time can be claimed.');
    }
    if ($entry['reviewed_by_user_id'] === null
        || $entry['reviewed_at'] === null
        || $entry['reviewer_exists'] === null
    ) {
        throw new CoastmarkTimeExportValidationException('Approved time is missing reviewer evidence.');
    }
    if ($entry['customer_id'] === null) {
        throw new CoastmarkTimeExportValidationException('Time entry client has no Milepost customer binding.');
    }
    if ((string) $entry['customer_status'] !== 'active') {
        throw new CoastmarkTimeExportValidationException('Milepost customer binding is not active.');
    }
    $clientKey = coastmark_time_export_customer_client_key((string) $entry['customer_id']);
    $allowed = coastmark_time_export_allowlist($config['client_keys'] ?? [], 128, 'client key');
    if (!in_array($clientKey, $allowed, true)) {
        throw new CoastmarkTimeExportValidationException('Client key is not allowlisted.');
    }
}

/** @param array<string,mixed> $entry @return array<string,mixed> */
function coastmark_time_export_payload_for_version(
    PDO $pdo,
    array $entry,
    int $sourceVersion,
    string $eventKey,
    ?string $predecessorEventKey,
): array {
    coastmark_time_export_event_key($eventKey);
    if ($sourceVersion < 0) {
        throw new CoastmarkTimeExportValidationException('Source version is invalid.');
    }
    $adjustment = null;
    if ($sourceVersion > 0) {
        $query = $pdo->prepare(
            'SELECT version_no,effective_minutes,effective_billable,reason,
                    actor_user_id,created_at
               FROM time_entry_approval_adjustments
              WHERE tenant_id=? AND time_entry_id=? AND version_no=?'
        );
        $query->execute([(int) $entry['tenant_id'], (int) $entry['id'], $sourceVersion]);
        $adjustment = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($adjustment) || $predecessorEventKey === null) {
            throw new CoastmarkTimeExportConflictException(
                'Adjustment claim requires an exact source row and predecessor.',
            );
        }
        coastmark_time_export_event_key($predecessorEventKey);
    } elseif ($predecessorEventKey !== null) {
        throw new CoastmarkTimeExportConflictException('Original approval cannot name a predecessor.');
    }

    $source = (string) $entry['source'];
    if (!in_array($source, ['timer', 'reply', 'suggestion', 'legacy'], true)) {
        throw new CoastmarkTimeExportValidationException('Time entry source is unsupported.');
    }
    $minutes = $adjustment === null ? (int) $entry['minutes'] : (int) $adjustment['effective_minutes'];
    $billable = $adjustment === null ? true : ((int) $adjustment['effective_billable'] === 1);
    if ($minutes < 0 || $minutes > 1440 || ($minutes === 0 && $billable)) {
        throw new CoastmarkTimeExportValidationException('Effective minutes are invalid.');
    }

    $payload = [
        'version' => 3,
        'event' => $sourceVersion === 0
            ? 'safeharbor.time_entry.approved'
            : 'safeharbor.time_entry.adjusted',
        'event_key' => $eventKey,
        'predecessor_event_key' => $predecessorEventKey,
        'source_version' => $sourceVersion,
        'tenant_key' => (string) $entry['tenant_slug'],
        'client_key' => coastmark_time_export_customer_client_key((string) $entry['customer_id']),
        'entry_key' => (string) $entry['entry_key'],
        'entry_id' => (int) $entry['id'],
        'ticket_id' => (int) $entry['ticket_id'],
        'entry_source' => $source,
        'technician_key' => 'safeharbor-user:' . (int) $entry['user_id'],
        'worked_at' => coastmark_time_export_timestamp((string) $entry['worked_at'], 'Worked at'),
        'minutes' => $minutes,
        'note_sha256' => hash('sha256', (string) $entry['note']),
        'billable' => $billable,
        'approval_status' => 'approved',
        'approved_at' => coastmark_time_export_timestamp((string) $entry['reviewed_at'], 'Approved at'),
        'reviewer_key' => 'safeharbor-user:' . (int) $entry['reviewed_by_user_id'],
        'adjusted_at' => $adjustment === null
            ? null
            : coastmark_time_export_timestamp((string) $adjustment['created_at'], 'Adjusted at'),
        'adjusted_by_key' => $adjustment === null
            ? null
            : 'safeharbor-user:' . (int) $adjustment['actor_user_id'],
        'adjustment_reason_sha256' => $adjustment === null
            ? null
            : hash('sha256', (string) $adjustment['reason']),
    ];
    coastmark_time_export_assert_payload_shape($payload);
    return $payload;
}

/** @param array<string,mixed> $payload */
function coastmark_time_export_payload_hash(array $payload): string
{
    coastmark_time_export_assert_payload_shape($payload);
    return hash('sha256', json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ));
}

/** @param array<string,mixed> $payload */
function coastmark_time_export_assert_payload_shape(array $payload): void
{
    if (array_keys($payload) !== COASTMARK_TIME_EXPORT_FIELDS) {
        throw new CoastmarkTimeExportValidationException('Payload field order or shape is invalid.');
    }
    $version = $payload['source_version'] ?? null;
    $original = is_int($version) && $version === 0;
    if (($payload['version'] ?? null) !== 3
        || !is_int($version)
        || $version < 0
        || ($payload['approval_status'] ?? null) !== 'approved'
        || !is_bool($payload['billable'] ?? null)
        || ($original && $payload['event'] !== 'safeharbor.time_entry.approved')
        || (!$original && $payload['event'] !== 'safeharbor.time_entry.adjusted')
        || ($original && $payload['predecessor_event_key'] !== null)
        || (!$original && !is_string($payload['predecessor_event_key']))
    ) {
        throw new CoastmarkTimeExportValidationException('Payload event chain is invalid.');
    }
    coastmark_time_export_event_key_value($payload['event_key'] ?? null);
    if (!$original) coastmark_time_export_event_key_value($payload['predecessor_event_key']);
    coastmark_time_export_key_value($payload['tenant_key'] ?? null, 64, 'Tenant key');
    coastmark_time_export_client_key_value($payload['client_key'] ?? null);
    coastmark_time_export_key_value($payload['entry_key'] ?? null, 64, 'Entry key');
    coastmark_time_export_key_value($payload['technician_key'] ?? null, 128, 'Technician key');
    coastmark_time_export_key_value($payload['reviewer_key'] ?? null, 128, 'Reviewer key');
    foreach (['entry_id', 'ticket_id'] as $field) {
        if (!is_int($payload[$field] ?? null) || $payload[$field] < 1) {
            throw new CoastmarkTimeExportValidationException("{$field} must be positive.");
        }
    }
    if (!is_int($payload['minutes'] ?? null)
        || $payload['minutes'] < 0
        || $payload['minutes'] > 1440
        || ($payload['minutes'] === 0 && $payload['billable'])
    ) {
        throw new CoastmarkTimeExportValidationException('Effective minutes are invalid.');
    }
    if (!is_string($payload['entry_source'] ?? null)
        || !in_array($payload['entry_source'], ['timer', 'reply', 'suggestion', 'legacy'], true)
    ) {
        throw new CoastmarkTimeExportValidationException('Entry source is invalid.');
    }
    if (!is_string($payload['note_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $payload['note_sha256']) !== 1
    ) {
        throw new CoastmarkTimeExportValidationException('Note digest is invalid.');
    }
    $worked = coastmark_time_export_rfc3339_value($payload['worked_at'] ?? null, 'Worked at');
    $approved = coastmark_time_export_rfc3339_value($payload['approved_at'] ?? null, 'Approved at');
    if ($approved < $worked) {
        throw new CoastmarkTimeExportValidationException('Approval cannot predate work.');
    }
    if ($original) {
        if ($payload['adjusted_at'] !== null
            || $payload['adjusted_by_key'] !== null
            || $payload['adjustment_reason_sha256'] !== null
        ) {
            throw new CoastmarkTimeExportValidationException('Original approval carries adjustment facts.');
        }
    } else {
        $adjusted = coastmark_time_export_rfc3339_value($payload['adjusted_at'] ?? null, 'Adjusted at');
        coastmark_time_export_key_value($payload['adjusted_by_key'] ?? null, 128, 'Adjusted by key');
        if ($adjusted < $approved
            || !is_string($payload['adjustment_reason_sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $payload['adjustment_reason_sha256']) !== 1
        ) {
            throw new CoastmarkTimeExportValidationException('Adjustment evidence is invalid.');
        }
    }
}

/**
 * One explicit delivery attempt. An unresolved dispatch must be checked by
 * status before another explicit send is permitted.
 *
 * @param array<string,mixed> $config
 * @param null|callable(string,list<string>,string,int):array{status:int,body:string} $transport
 * @return array<string,mixed>
 */
function coastmark_time_export_send_claim(
    PDO $pdo,
    int $claimId,
    array $config,
    ?callable $transport = null,
    ?int $timestamp = null,
): array {
    $connection = coastmark_time_export_connection($config);
    $claim = coastmark_time_export_begin_operation($pdo, $claimId, 'dispatch');
    if (isset($claim['terminal'])) {
        return $claim['terminal'];
    }
    $payload = coastmark_time_export_decode_claim_payload($claim);
    $request = coastmark_time_export_request(
        $payload,
        $connection['service'],
        $connection['secret'],
        $timestamp ?? time(),
    );
    $transport ??= 'coastmark_time_export_curl';
    try {
        $response = $transport(
            $connection['endpoint'],
            $request['headers'],
            $request['body'],
            $connection['timeout_seconds'],
        );
    } catch (Throwable $error) {
        coastmark_time_export_append_receipt(
            $pdo, $claim, 'dispatch_result', 'ambiguous', null, null,
            null, null, null, 'transport_outcome_unknown',
        );
        throw new CoastmarkTimeExportAmbiguousException(
            'Delivery outcome is unknown. Run status before any explicit resend.',
            0,
            $error,
        );
    }
    return coastmark_time_export_record_response($pdo, $claim, $response, false);
}

/**
 * Resolve one claim without replaying the financial event.
 *
 * @param array<string,mixed> $config
 * @param null|callable(string,list<string>,string,int):array{status:int,body:string} $transport
 * @return array<string,mixed>
 */
function coastmark_time_export_status_claim(
    PDO $pdo,
    int $claimId,
    array $config,
    ?callable $transport = null,
    ?int $timestamp = null,
): array {
    $connection = coastmark_time_export_connection($config);
    $claim = coastmark_time_export_begin_operation(
        $pdo,
        $claimId,
        'status',
        $connection['timeout_seconds'] + 5,
    );
    if (isset($claim['terminal'])) {
        return $claim['terminal'];
    }
    $payload = [
        'version' => 3,
        'event' => 'safeharbor.time_entry.status',
        'tenant_key' => coastmark_time_export_claim_tenant_slug($pdo, $claim),
        'event_key' => (string) $claim['event_key'],
        'payload_sha256' => (string) $claim['payload_sha256'],
    ];
    coastmark_time_export_assert_status_shape($payload);
    $request = coastmark_time_export_signed_body(
        $payload,
        $connection['service'],
        $connection['secret'],
        $timestamp ?? time(),
    );
    $transport ??= 'coastmark_time_export_curl';
    try {
        $response = $transport(
            $connection['status_endpoint'],
            $request['headers'],
            $request['body'],
            $connection['timeout_seconds'],
        );
    } catch (Throwable $error) {
        coastmark_time_export_append_receipt(
            $pdo, $claim, 'status_result', 'ambiguous', null, null,
            null, null, null, 'status_outcome_unknown',
        );
        throw new CoastmarkTimeExportAmbiguousException(
            'Status outcome is unknown. The event was not resent.',
            0,
            $error,
        );
    }
    return coastmark_time_export_record_response($pdo, $claim, $response, true);
}

/** @param array<string,mixed> $payload @return array{body:string,headers:list<string>,payload_sha256:string} */
function coastmark_time_export_request(
    array $payload,
    string $service,
    string $secret,
    int $timestamp,
): array {
    coastmark_time_export_assert_payload_shape($payload);
    $request = coastmark_time_export_signed_body($payload, $service, $secret, $timestamp);
    $request['payload_sha256'] = coastmark_time_export_payload_hash($payload);
    return $request;
}

/** Old detached send calls stay permanently closed. */
function coastmark_time_export_send(array $payload, array $config): never
{
    throw new CoastmarkTimeExportValidationException(
        'Detached v2 export is retired; receipt/reversal-aware v3 is required before any send. '
        . 'Claim durably and send by claim id.',
    );
}

/** @param array<string,mixed> $payload */
function coastmark_time_export_assert_status_shape(array $payload): void
{
    if (array_keys($payload) !== COASTMARK_TIME_STATUS_FIELDS
        || ($payload['version'] ?? null) !== 3
        || ($payload['event'] ?? null) !== 'safeharbor.time_entry.status'
    ) {
        throw new CoastmarkTimeExportValidationException('Status payload shape is invalid.');
    }
    coastmark_time_export_key_value($payload['tenant_key'] ?? null, 64, 'Tenant key');
    coastmark_time_export_event_key_value($payload['event_key'] ?? null);
    if (!is_string($payload['payload_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $payload['payload_sha256']) !== 1
    ) {
        throw new CoastmarkTimeExportValidationException('Status payload hash is invalid.');
    }
}

/** @param array<string,mixed> $config */
function coastmark_time_export_connection(array $config): array
{
    if (($config['enabled'] ?? false) !== true) {
        throw new CoastmarkTimeExportValidationException('Coastmark time export is disabled.');
    }
    $endpoint = coastmark_time_export_https_endpoint(
        $config['endpoint'] ?? null,
        '/api/integrations/safeharbor/time-entries',
        'Event endpoint',
    );
    $statusEndpoint = coastmark_time_export_https_endpoint(
        $config['status_endpoint'] ?? null,
        '/api/integrations/safeharbor/time-events/status',
        'Status endpoint',
    );
    if (parse_url($endpoint, PHP_URL_HOST) !== parse_url($statusEndpoint, PHP_URL_HOST)) {
        throw new CoastmarkTimeExportValidationException('Event and status endpoints must share a host.');
    }
    $service = $config['service'] ?? null;
    $secret = $config['secret'] ?? null;
    $timeout = $config['timeout_seconds'] ?? null;
    if (!is_string($service)
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $service) !== 1
        || !is_string($secret)
        || strlen($secret) < 32
        || !is_int($timeout)
        || $timeout < 1
        || $timeout > 30
    ) {
        throw new CoastmarkTimeExportValidationException('Connection configuration is incomplete.');
    }
    return [
        'endpoint' => $endpoint,
        'status_endpoint' => $statusEndpoint,
        'service' => $service,
        'secret' => $secret,
        'timeout_seconds' => $timeout,
    ];
}

function coastmark_time_export_https_endpoint(mixed $value, string $path, string $label): string
{
    if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new CoastmarkTimeExportValidationException("{$label} is invalid.");
    }
    $parts = parse_url($value);
    if (!is_array($parts)
        || ($parts['scheme'] ?? null) !== 'https'
        || !isset($parts['host'])
        || ($parts['path'] ?? '') !== $path
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)
    ) {
        throw new CoastmarkTimeExportValidationException("{$label} must be direct canonical HTTPS.");
    }
    return $value;
}

/** @param array<string,mixed> $payload @return array{body:string,headers:list<string>} */
function coastmark_time_export_signed_body(
    array $payload,
    string $service,
    string $secret,
    int $timestamp,
): array {
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $service) !== 1
        || strlen($secret) < 32
        || $timestamp < 1_000_000_000
        || $timestamp > 9_999_999_999
    ) {
        throw new CoastmarkTimeExportValidationException('Signing configuration is invalid.');
    }
    $body = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    return [
        'body' => $body,
        'headers' => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-8W-Service: ' . $service,
            'X-8W-Timestamp: ' . $timestamp,
            'X-8W-Signature: ' . hash_hmac('sha256', $timestamp . "\n" . $body, $secret),
        ],
    ];
}

/** @return array{status:int,body:string} */
function coastmark_time_export_curl(string $url, array $headers, string $body, int $timeout): array
{
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Cannot initialize Coastmark transport.');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $responseBody = curl_exec($handle);
    if (!is_string($responseBody)) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Coastmark transport failed: ' . $message);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'body' => $responseBody];
}

/** @return array<string,mixed> */
function coastmark_time_export_begin_operation(
    PDO $pdo,
    int $claimId,
    string $kind,
    int $recoveryWaitSeconds = 0,
): array
{
    if ($claimId < 1
        || !in_array($kind, ['dispatch', 'status'], true)
        || $recoveryWaitSeconds < 0
        || $recoveryWaitSeconds > 35
    ) {
        throw new CoastmarkTimeExportValidationException('Claim operation is invalid.');
    }
    return coastmark_time_export_transaction($pdo, function () use (
        $pdo,
        $claimId,
        $kind,
        $recoveryWaitSeconds,
    ): array {
        $lock = coastmark_time_export_lock_clause($pdo);
        $tenantQuery = $pdo->prepare(
            'SELECT tenant_id FROM coastmark_time_export_claims WHERE id=? LIMIT 1'
        );
        $tenantQuery->execute([$claimId]);
        $tenantId = (int) ($tenantQuery->fetchColumn() ?: 0);
        if ($tenantId < 1) {
            throw new CoastmarkTimeExportValidationException('Export claim was not found.');
        }
        $tenantLock = $pdo->prepare("SELECT id FROM tenants WHERE id=? LIMIT 1{$lock}");
        $tenantLock->execute([$tenantId]);
        if ((int) ($tenantLock->fetchColumn() ?: 0) !== $tenantId) {
            throw new CoastmarkTimeExportConflictException('Export claim tenant is missing.');
        }
        $query = $pdo->prepare(
            "SELECT * FROM coastmark_time_export_claims
              WHERE tenant_id=? AND id=? LIMIT 1{$lock}"
        );
        $query->execute([$tenantId, $claimId]);
        $claim = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($claim)) {
            throw new CoastmarkTimeExportValidationException('Export claim was not found.');
        }

        if ($claim['predecessor_claim_id'] !== null) {
            $prior = coastmark_time_export_find_claim(
                $pdo,
                (int) $claim['tenant_id'],
                (int) $claim['predecessor_claim_id'],
                true,
            );
            $priorReceipt = $prior === null
                ? null
                : coastmark_time_export_latest_receipt($pdo, (int) $prior['id'], true);
            if ($priorReceipt === null
                || !in_array(
                    (string) $priorReceipt['outcome'],
                    ['accepted', 'replayed', 'manual_exception'],
                    true,
                )
            ) {
                throw new CoastmarkTimeExportConflictException(
                    'Predecessor must be acknowledged before this correction.',
                );
            }
        }

        $latest = coastmark_time_export_latest_receipt($pdo, $claimId, true);
        if ($latest !== null) {
            $outcome = (string) $latest['outcome'];
            if (in_array($outcome, ['accepted', 'replayed', 'manual_exception'], true)) {
                $claim['terminal'] = coastmark_time_export_receipt_result($claim, $latest);
                return $claim;
            }
            if ($outcome === 'conflict') {
                throw new CoastmarkTimeExportConflictException('Coastmark recorded a permanent conflict.');
            }
            if ($kind === 'dispatch'
                && in_array($outcome, ['dispatching', 'checking', 'ambiguous'], true)
            ) {
                throw new CoastmarkTimeExportAmbiguousException(
                    'A prior dispatch is unresolved. Run status before an explicit resend.',
                );
            }
            if ($kind === 'status'
                && in_array($outcome, ['dispatching', 'checking'], true)
                && !coastmark_time_export_receipt_is_stale($latest, $recoveryWaitSeconds)
            ) {
                throw new CoastmarkTimeExportAmbiguousException(
                    'The prior operation may still be in flight. Wait before checking status.',
                );
            }
        }

        coastmark_time_export_append_receipt(
            $pdo,
            $claim,
            $kind === 'dispatch' ? 'dispatch_started' : 'status_started',
            $kind === 'dispatch' ? 'dispatching' : 'checking',
            null,
            null,
            null,
            null,
            null,
            $kind === 'dispatch' ? 'explicit_operator_dispatch' : 'explicit_operator_status',
        );
        return $claim;
    });
}

/** @param array{status:int,body:string} $response @param array<string,mixed> $claim */
function coastmark_time_export_record_response(
    PDO $pdo,
    array $claim,
    array $response,
    bool $statusOnly,
): array {
    $status = $response['status'] ?? null;
    $body = $response['body'] ?? null;
    if (!is_int($status) || !is_string($body) || strlen($body) > 16_384) {
        coastmark_time_export_append_receipt(
            $pdo, $claim, $statusOnly ? 'status_result' : 'dispatch_result',
            'ambiguous', null, null, null, null, null, 'invalid_response_envelope',
        );
        throw new CoastmarkTimeExportAmbiguousException('Response envelope is invalid.');
    }
    $bodyHash = hash('sha256', $body);
    if ($statusOnly && $status === 404) {
        try {
            $absent = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $absent = null;
        }
        if (!is_array($absent)
            || array_keys($absent) !== ['ok', 'action']
            || ($absent['ok'] ?? null) !== false
            || ($absent['action'] ?? null) !== 'absent'
        ) {
            coastmark_time_export_append_receipt(
                $pdo, $claim, 'status_result', 'ambiguous', 404, $bodyHash,
                null, null, null, 'untrusted_absent_response',
            );
            throw new CoastmarkTimeExportAmbiguousException(
                'Status did not prove that the event is absent.',
            );
        }
        $receipt = coastmark_time_export_append_receipt(
            $pdo, $claim, 'status_result', 'absent', 404, $bodyHash,
            null, null, null, 'event_absent_explicit_resend_permitted',
        );
        return coastmark_time_export_receipt_result($claim, $receipt);
    }
    if ($status === 409) {
        coastmark_time_export_append_receipt(
            $pdo, $claim, $statusOnly ? 'status_result' : 'dispatch_result',
            'conflict', 409, $bodyHash, null, null, null, 'changed_facts_conflict',
        );
        throw new CoastmarkTimeExportConflictException('Coastmark has this key with different facts.');
    }
    if (!in_array($status, [200, 201], true)) {
        coastmark_time_export_append_receipt(
            $pdo,
            $claim,
            $statusOnly ? 'status_result' : 'dispatch_result',
            'ambiguous',
            $status,
            $bodyHash,
            null,
            null,
            null,
            'receiver_outcome_unknown',
        );
        throw new CoastmarkTimeExportAmbiguousException(
            'Response may be post-commit. Run status before any explicit resend.',
        );
    }

    try {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        coastmark_time_export_append_receipt(
            $pdo, $claim, $statusOnly ? 'status_result' : 'dispatch_result',
            'ambiguous', $status, $bodyHash, null, null, null, 'invalid_ack_json',
        );
        throw new CoastmarkTimeExportAmbiguousException('Acknowledgement JSON is invalid.', 0, $error);
    }
    try {
        $ack = coastmark_time_export_validate_ack($claim, $decoded);
    } catch (CoastmarkTimeExportAmbiguousException $error) {
        coastmark_time_export_append_receipt(
            $pdo, $claim, $statusOnly ? 'status_result' : 'dispatch_result',
            'ambiguous', $status, $bodyHash, null, null, null, 'ack_did_not_match_claim',
        );
        throw $error;
    }
    $outcome = match ($ack['action']) {
        'ignored' => 'replayed',
        'manual_exception' => 'manual_exception',
        default => 'accepted',
    };
    $receipt = coastmark_time_export_append_receipt(
        $pdo,
        $claim,
        $statusOnly ? 'status_result' : 'dispatch_result',
        $outcome,
        $status,
        $bodyHash,
        $ack['coastmark_event_id'],
        $ack['invoice_id'],
        $ack['invoice_line_id'],
        $ack['action'],
    );
    return coastmark_time_export_receipt_result($claim, $receipt);
}

/** @param array<string,mixed> $claim @return array{action:string,coastmark_event_id:int,invoice_id:int,invoice_line_id:?int} */
function coastmark_time_export_validate_ack(array $claim, mixed $decoded): array
{
    $action = is_array($decoded) ? ($decoded['action'] ?? null) : null;
    $event = is_array($decoded) && is_array($decoded['event'] ?? null) ? $decoded['event'] : [];
    $draft = is_array($decoded) && is_array($decoded['draft'] ?? null) ? $decoded['draft'] : [];
    $lineId = array_key_exists('invoice_line_id', $draft) ? $draft['invoice_line_id'] : false;
    if (!is_array($decoded)
        || ($decoded['ok'] ?? null) !== true
        || !is_string($action)
        || !in_array($action, ['created', 'corrected', 'ignored', 'manual_exception'], true)
        || !hash_equals((string) $claim['event_key'], (string) ($event['event_key'] ?? ''))
        || !hash_equals((string) $claim['payload_sha256'], (string) ($event['payload_sha256'] ?? ''))
        || !is_int($event['coastmark_event_id'] ?? null)
        || $event['coastmark_event_id'] < 1
        || !is_int($draft['invoice_id'] ?? null)
        || $draft['invoice_id'] < 1
        || (!is_null($lineId) && (!is_int($lineId) || $lineId < 1))
        || ($action === 'manual_exception' && $lineId !== null)
        || (in_array($action, ['created', 'corrected'], true) && $lineId === null)
    ) {
        throw new CoastmarkTimeExportAmbiguousException('Acknowledgement does not match the claim.');
    }
    return [
        'action' => $action,
        'coastmark_event_id' => $event['coastmark_event_id'],
        'invoice_id' => $draft['invoice_id'],
        'invoice_line_id' => $lineId,
    ];
}

/** @param array<string,mixed> $claim */
function coastmark_time_export_append_receipt(
    PDO $pdo,
    array $claim,
    string $kind,
    string $outcome,
    ?int $responseStatus,
    ?string $responseSha256,
    ?int $coastmarkEventId,
    ?int $invoiceId,
    ?int $invoiceLineId,
    string $detailCode,
): array {
    $kinds = ['dispatch_started', 'dispatch_result', 'status_started', 'status_result'];
    $outcomes = [
        'dispatching', 'checking', 'accepted', 'replayed', 'absent',
        'ambiguous', 'conflict', 'manual_exception',
    ];
    if (!in_array($kind, $kinds, true)
        || !in_array($outcome, $outcomes, true)
        || preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $detailCode) !== 1
    ) {
        throw new LogicException('Invalid export receipt state.');
    }
    $insert = $pdo->prepare(
        'INSERT INTO coastmark_time_export_receipts
            (tenant_id,claim_id,operation_key,operation_kind,outcome,response_status,
             response_sha256,coastmark_event_id,invoice_id,invoice_line_id,detail_code,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
    );
    $insert->execute([
        (int) $claim['tenant_id'],
        (int) $claim['id'],
        'safeharbor-op:' . bin2hex(random_bytes(16)),
        $kind,
        $outcome,
        $responseStatus,
        $responseSha256,
        $coastmarkEventId,
        $invoiceId,
        $invoiceLineId,
        $detailCode,
    ]);
    $query = $pdo->prepare('SELECT * FROM coastmark_time_export_receipts WHERE id=?');
    $query->execute([(int) $pdo->lastInsertId()]);
    $receipt = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($receipt)) throw new RuntimeException('Receipt could not be read back.');
    return $receipt;
}

/** @param array<string,mixed> $claim @param array<string,mixed> $receipt */
function coastmark_time_export_receipt_result(array $claim, array $receipt): array
{
    return [
        'claim_id' => (int) $claim['id'],
        'event_key' => (string) $claim['event_key'],
        'source_version' => (int) $claim['source_version'],
        'payload_sha256' => (string) $claim['payload_sha256'],
        'outcome' => (string) $receipt['outcome'],
        'coastmark_event_id' => $receipt['coastmark_event_id'] === null
            ? null : (int) $receipt['coastmark_event_id'],
        'invoice_id' => $receipt['invoice_id'] === null ? null : (int) $receipt['invoice_id'],
        'invoice_line_id' => $receipt['invoice_line_id'] === null
            ? null : (int) $receipt['invoice_line_id'],
    ];
}

/** @return array<string,mixed>|null */
function coastmark_time_export_find_claim(
    PDO $pdo,
    int $tenantId,
    int $claimId,
    bool $lock = false,
): ?array {
    $suffix = $lock ? coastmark_time_export_lock_clause($pdo) : '';
    $query = $pdo->prepare(
        "SELECT * FROM coastmark_time_export_claims
          WHERE tenant_id=? AND id=? LIMIT 1{$suffix}"
    );
    $query->execute([$tenantId, $claimId]);
    $claim = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($claim) ? $claim : null;
}

/** @return array<string,mixed>|null */
function coastmark_time_export_latest_receipt(PDO $pdo, int $claimId, bool $lock = false): ?array
{
    $suffix = $lock ? coastmark_time_export_lock_clause($pdo) : '';
    $query = $pdo->prepare(
        "SELECT * FROM coastmark_time_export_receipts
          WHERE claim_id=? ORDER BY id DESC LIMIT 1{$suffix}"
    );
    $query->execute([$claimId]);
    $receipt = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($receipt) ? $receipt : null;
}

/** @param array<string,mixed> $receipt */
function coastmark_time_export_receipt_is_stale(array $receipt, int $seconds): bool
{
    $created = $receipt['created_at'] ?? null;
    if (!is_string($created)) return false;
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $created,
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $created
    ) {
        return false;
    }
    return $date->getTimestamp() <= time() - $seconds;
}

/** @param array<string,mixed> $claim @return array<string,mixed> */
function coastmark_time_export_decode_claim_payload(array $claim): array
{
    try {
        $payload = json_decode((string) $claim['payload_json'], true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new CoastmarkTimeExportConflictException('Stored claim JSON is invalid.', 0, $error);
    }
    if (!is_array($payload)) throw new CoastmarkTimeExportConflictException('Stored claim is invalid.');
    coastmark_time_export_assert_payload_shape($payload);
    if (!hash_equals((string) $claim['payload_sha256'], coastmark_time_export_payload_hash($payload))) {
        throw new CoastmarkTimeExportConflictException('Stored claim hash does not match.');
    }
    return $payload;
}

/** @param array<string,mixed> $claim */
function coastmark_time_export_predecessor_key(PDO $pdo, array $claim): ?string
{
    if ($claim['predecessor_claim_id'] === null) return null;
    $prior = coastmark_time_export_find_claim(
        $pdo,
        (int) $claim['tenant_id'],
        (int) $claim['predecessor_claim_id'],
    );
    if ($prior === null) throw new CoastmarkTimeExportConflictException('Claim predecessor is missing.');
    return (string) $prior['event_key'];
}

/** @param array<string,mixed> $claim */
function coastmark_time_export_claim_tenant_slug(PDO $pdo, array $claim): string
{
    $query = $pdo->prepare('SELECT slug FROM tenants WHERE id=?');
    $query->execute([(int) $claim['tenant_id']]);
    $slug = $query->fetchColumn();
    if (!is_string($slug) || $slug === '') {
        throw new CoastmarkTimeExportConflictException('Claim tenant is missing.');
    }
    return $slug;
}

/** @param array<string,mixed> $config */
function coastmark_time_export_assert_allowlisted_tenant(string $tenantSlug, array $config): void
{
    $allowed = coastmark_time_export_allowlist($config['tenant_slugs'] ?? [], 64, 'tenant slug');
    if (!in_array($tenantSlug, $allowed, true)) {
        throw new CoastmarkTimeExportValidationException('Tenant is not allowlisted.');
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
    $expected = coastmark_time_export_customer_client_key(
        substr($value, strlen(COASTMARK_TIME_EXPORT_CLIENT_PREFIX)),
    );
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

function coastmark_time_export_event_key(string $value): string
{
    if (preg_match('/\Asafeharbor-time:[0-9a-f]{32}\z/D', $value) !== 1) {
        throw new CoastmarkTimeExportValidationException('Event key is invalid.');
    }
    return $value;
}

function coastmark_time_export_event_key_value(mixed $value): string
{
    if (!is_string($value)) throw new CoastmarkTimeExportValidationException('Event key is invalid.');
    return coastmark_time_export_event_key($value);
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
    if (!is_string($value)) throw new CoastmarkTimeExportValidationException("{$label} is invalid.");
    return coastmark_time_export_key($value, $maximum, $label);
}

function coastmark_time_export_timestamp(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value
    ) {
        throw new CoastmarkTimeExportValidationException("{$label} is not an exact UTC timestamp.");
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
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i:s\Z') !== $value
    ) {
        throw new CoastmarkTimeExportValidationException("{$label} is not a real UTC timestamp.");
    }
    return $date->getTimestamp();
}

function coastmark_time_export_lock_clause(PDO $pdo): string
{
    // The export identity intentionally has SELECT+INSERT only. MySQL locking
    // reads require broader mutation privileges, so the DEFINER triggers own
    // the tenant -> parent/claim serialization immediately before each insert.
    return '';
}

function coastmark_time_export_is_claim_race(PDOException $error): bool
{
    $message = $error->getMessage();
    return str_contains($message, 'Export claim does not follow current source version')
        || str_contains($message, 'uq_cm_export_claim_version');
}

/** @template T @param callable():T $operation @return T */
function coastmark_time_export_transaction(PDO $pdo, callable $operation): mixed
{
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $result = $operation();
        if ($owns) $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
