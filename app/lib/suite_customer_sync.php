<?php
/**
 * Milepost customer registry -> Safeharbor client provisioning.
 *
 * This is deliberately separate from alert/support intake. The destination
 * tenant is resolved from the signed payload's exact slug; tenant_id() and
 * clients.source_key are never used. One immutable event receipt is created
 * by database trigger for every accepted binding version.
 */
declare(strict_types=1);

if (!function_exists('cfg')) {
    require_once __DIR__ . '/bootstrap.php';
}

const SUITE_CUSTOMER_SYNC_SERVICE = 'milepost-customers';
const SUITE_CUSTOMER_SYNC_SIGNATURE_CONTEXT = 'safeharbor-suite-customer-sync-v1';
const SUITE_CUSTOMER_SYNC_TIMESTAMP_TOLERANCE = 300;
const SUITE_CUSTOMER_SYNC_MAX_BODY_BYTES = 8192;
const SUITE_CUSTOMER_SYNC_UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
const SUITE_CUSTOMER_SYNC_SLUG = '/\A[a-z0-9][a-z0-9-]{0,63}\z/D';

final class SuiteCustomerSyncValidationException extends InvalidArgumentException {}
final class SuiteCustomerSyncGateException extends RuntimeException {}

final class SuiteCustomerSyncConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $expectedSourceVersion = null,
    ) {
        parent::__construct($errorCode);
    }
}

function suite_customer_sync_enabled(): bool
{
    return cfg('suite_customer_sync.enabled', false) === true;
}

/** @return list<string> */
function suite_customer_sync_tenant_allowlist(): array
{
    $raw = cfg('suite_customer_sync.tenant_slugs', []);
    if (!is_array($raw) || count($raw) > 64) {
        return [];
    }
    $result = [];
    foreach ($raw as $slug) {
        if (!is_string($slug)
            || preg_match(SUITE_CUSTOMER_SYNC_SLUG, $slug) !== 1
            || in_array($slug, $result, true)
        ) {
            return [];
        }
        $result[] = $slug;
    }
    return $result;
}

require_once __DIR__ . '/suite_managed_provider.php';

function suite_customer_sync_tenant_allowed(string $tenantSlug): bool
{
    return in_array($tenantSlug, suite_customer_sync_tenant_allowlist(), true);
}

/** @return array{id:int,slug:string}|null */
function suite_customer_sync_resolve_tenant(PDO $pdo, string $tenantSlug): ?array
{
    if (preg_match(SUITE_CUSTOMER_SYNC_SLUG, $tenantSlug) !== 1) {
        return null;
    }
    $query = $pdo->prepare('SELECT id, slug FROM tenants WHERE slug = ? LIMIT 1');
    $query->execute([$tenantSlug]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (string)$row['slug'] !== $tenantSlug) {
        return null;
    }
    return ['id' => (int)$row['id'], 'slug' => (string)$row['slug']];
}

/**
 * Destination-specific HMAC authentication for this one job.
 *
 * The secret is not svc.secrets.milepost and the signed context names this
 * exact Safeharbor receiver/version. The active service identity is resolved
 * under the payload's exact tenant, never the unauthenticated session fallback.
 *
 * @return array{ok:bool,code:int}
 */
function suite_customer_sync_authenticate(
    PDO $pdo,
    int $tenantId,
    string $tenantSlug,
    string $service,
    string $timestamp,
    string $signature,
    string $rawBody,
    ?int $now = null,
): array {
    $deny = static fn(): array => ['ok' => false, 'code' => 401];
    if (!suite_customer_sync_enabled()
        || (!suite_customer_sync_tenant_allowed($tenantSlug) && suite_managed_provider($pdo, $tenantSlug) === null)
        || $tenantId < 1
        || $service !== SUITE_CUSTOMER_SYNC_SERVICE
        || preg_match('/\A[1-9][0-9]{0,11}\z/D', $timestamp) !== 1
        || preg_match('/\A[0-9a-f]{64}\z/D', $signature) !== 1
        || $rawBody === ''
        || strlen($rawBody) > SUITE_CUSTOMER_SYNC_MAX_BODY_BYTES
    ) {
        return $deny();
    }

    $now ??= time();
    $requestTime = (int)$timestamp;
    if (abs($now - $requestTime) > SUITE_CUSTOMER_SYNC_TIMESTAMP_TOLERANCE) {
        return $deny();
    }

    $secret = (string)cfg('suite_customer_sync.hmac_secret', '');
    if (strlen($secret) < 32) {
        return $deny();
    }

    $identity = $pdo->prepare(
        'SELECT identity.id, tenant.slug
           FROM svc_identities identity
           JOIN tenants tenant ON tenant.id = identity.tenant_id
          WHERE identity.tenant_id = ? AND identity.service = ?
            AND identity.is_active = 1
          LIMIT 1'
    );
    $identity->execute([$tenantId, SUITE_CUSTOMER_SYNC_SERVICE]);
    $identityRow = $identity->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identityRow) || (string)$identityRow['slug'] !== $tenantSlug) {
        return $deny();
    }

    $preimage = SUITE_CUSTOMER_SYNC_SIGNATURE_CONTEXT . "\n" . $timestamp . "\n" . $rawBody;
    $expected = hash_hmac('sha256', $preimage, $secret);
    if (!hash_equals($expected, $signature)) {
        return $deny();
    }

    $seenAt = gmdate('Y-m-d H:i:s', $now);
    $seen = $pdo->prepare(
        'UPDATE svc_identities SET last_seen_at = ?
          WHERE tenant_id = ? AND service = ? AND is_active = 1'
    );
    $seen->execute([$seenAt, $tenantId, SUITE_CUSTOMER_SYNC_SERVICE]);
    return ['ok' => true, 'code' => 200];
}

/** @return array<string,mixed> */
function suite_customer_sync_decode(string $rawBody, ?int $now = null): array
{
    if ($rawBody === '' || strlen($rawBody) > SUITE_CUSTOMER_SYNC_MAX_BODY_BYTES) {
        throw new SuiteCustomerSyncValidationException('invalid_body');
    }
    try {
        $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new SuiteCustomerSyncValidationException('invalid_json', 0, $error);
    }
    if (!is_array($payload) || array_is_list($payload)) {
        throw new SuiteCustomerSyncValidationException('invalid_payload');
    }
    if (suite_customer_sync_top_level_member_count($rawBody) !== count($payload)) {
        throw new SuiteCustomerSyncValidationException('duplicate_payload_key');
    }
    return suite_customer_sync_validate($payload, $now);
}

/**
 * Count members in an already JSON-validated top-level object.
 *
 * json_decode() otherwise silently keeps only the last duplicate key. The
 * signer and receiver must never disagree about which eight facts were sent.
 */
function suite_customer_sync_top_level_member_count(string $rawBody): int
{
    $depth = 0;
    $commas = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($rawBody);
    for ($index = 0; $index < $length; $index++) {
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
    return count(json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR)) === 0
        ? 0
        : $commas + 1;
}

/** @param array<string,mixed> $payload @return array<string,mixed> */
function suite_customer_sync_validate(array $payload, ?int $now = null): array
{
    $expectedKeys = [
        'customer_id', 'display_name', 'event_id', 'occurred_at',
        'schema_version', 'source_version', 'status', 'tenant_slug',
    ];
    $actualKeys = array_keys($payload);
    sort($actualKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys) {
        throw new SuiteCustomerSyncValidationException('payload_keys_invalid');
    }
    if (!is_int($payload['schema_version']) || $payload['schema_version'] !== 1) {
        throw new SuiteCustomerSyncValidationException('schema_version_invalid');
    }

    $tenantSlug = $payload['tenant_slug'];
    $customerId = $payload['customer_id'];
    $sourceVersion = $payload['source_version'];
    $displayName = $payload['display_name'];
    $status = $payload['status'];
    $eventId = $payload['event_id'];
    $occurredAt = $payload['occurred_at'];

    if (!is_string($tenantSlug) || preg_match(SUITE_CUSTOMER_SYNC_SLUG, $tenantSlug) !== 1) {
        throw new SuiteCustomerSyncValidationException('tenant_slug_invalid');
    }
    if (!is_string($customerId) || preg_match(SUITE_CUSTOMER_SYNC_UUID_V4, $customerId) !== 1) {
        throw new SuiteCustomerSyncValidationException('customer_id_invalid');
    }
    if (!is_int($sourceVersion) || $sourceVersion < 1) {
        throw new SuiteCustomerSyncValidationException('source_version_invalid');
    }
    if (!is_string($displayName)
        || $displayName === ''
        || $displayName !== trim($displayName)
        || !mb_check_encoding($displayName, 'UTF-8')
        || mb_strlen($displayName, 'UTF-8') > 128
        || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $displayName) === 1
    ) {
        throw new SuiteCustomerSyncValidationException('display_name_invalid');
    }
    if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
        throw new SuiteCustomerSyncValidationException('status_invalid');
    }
    if (!is_string($eventId) || preg_match(SUITE_CUSTOMER_SYNC_UUID_V4, $eventId) !== 1) {
        throw new SuiteCustomerSyncValidationException('event_id_invalid');
    }
    if (!is_string($occurredAt)
        || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $occurredAt) !== 1
        || (int)substr($occurredAt, 0, 4) < 1000
    ) {
        throw new SuiteCustomerSyncValidationException('occurred_at_invalid');
    }
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s\Z',
        $occurredAt,
        new DateTimeZone('UTC'),
    );
    if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $occurredAt) {
        throw new SuiteCustomerSyncValidationException('occurred_at_invalid');
    }
    $now ??= time();
    if ($date->getTimestamp() > $now + SUITE_CUSTOMER_SYNC_TIMESTAMP_TOLERANCE) {
        throw new SuiteCustomerSyncValidationException('occurred_at_future');
    }

    return [
        'schema_version' => 1,
        'tenant_slug' => $tenantSlug,
        'customer_id' => $customerId,
        'source_version' => $sourceVersion,
        'display_name' => $displayName,
        'status' => $status,
        'event_id' => $eventId,
        'occurred_at' => $occurredAt,
        'occurred_at_db' => $date->format('Y-m-d H:i:s'),
    ];
}

function suite_customer_sync_lock_clause(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

/** @param array<string,mixed> $receipt @return array{ok:true,event_id:string,customer_id:string,source_version:int,status:string} */
function suite_customer_sync_success(array $receipt): array
{
    return [
        'ok' => true,
        'event_id' => (string)$receipt['event_id'],
        'customer_id' => (string)$receipt['customer_id'],
        'source_version' => (int)$receipt['source_version'],
        'status' => (string)$receipt['status'],
    ];
}

/** @return array<string,mixed>|null */
function suite_customer_sync_event(PDO $pdo, string $eventId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM suite_customer_sync_events WHERE event_id = ?';
    if ($forUpdate) {
        $sql .= suite_customer_sync_lock_clause($pdo);
    }
    $query = $pdo->prepare($sql);
    $query->execute([$eventId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @param array<string,mixed> $payload */
function suite_customer_sync_receipt_matches(
    array $receipt,
    int $tenantId,
    array $payload,
    string $requestSha256,
): bool {
    return (int)$receipt['tenant_id'] === $tenantId
        && (string)$receipt['customer_id'] === (string)$payload['customer_id']
        && (int)$receipt['source_version'] === (int)$payload['source_version']
        && (string)$receipt['display_name'] === (string)$payload['display_name']
        && (string)$receipt['status'] === (string)$payload['status']
        && (string)$receipt['occurred_at'] === (string)$payload['occurred_at_db']
        && (string)$receipt['request_sha256'] === $requestSha256;
}

/**
 * Apply one already-authenticated event transactionally.
 *
 * @param array<string,mixed> $payload suite_customer_sync_validate() output
 * @return array{ok:true,event_id:string,customer_id:string,source_version:int,status:string}
 */
function suite_customer_sync_receive(PDO $pdo, array $payload, string $requestSha256): array
{
    if ($pdo->inTransaction()) {
        throw new SuiteCustomerSyncGateException('Customer sync owns its transaction.');
    }
    if (preg_match('/\A[0-9a-f]{64}\z/D', $requestSha256) !== 1) {
        throw new SuiteCustomerSyncValidationException('request_digest_invalid');
    }

    $pdo->beginTransaction();
    try {
        // Serialize all registry events for this destination tenant. That makes
        // adjacent source versions deterministic even when outbox workers race.
        $tenant = $pdo->prepare(
            'SELECT id, slug FROM tenants WHERE slug = ?'
            . suite_customer_sync_lock_clause($pdo)
        );
        $tenant->execute([(string)$payload['tenant_slug']]);
        $tenantRow = $tenant->fetch(PDO::FETCH_ASSOC);
        if (!is_array($tenantRow) || (string)$tenantRow['slug'] !== (string)$payload['tenant_slug']) {
            throw new SuiteCustomerSyncGateException('The exact destination tenant was not found.');
        }
        $tenantId = (int)$tenantRow['id'];
        if ($payload['tenant_slug'] !== '8west' && !suite_customer_sync_tenant_allowed($payload['tenant_slug'])
            && suite_managed_provider($pdo, $payload['tenant_slug'], true) === null) {
            throw new SuiteCustomerSyncGateException('provider_not_admitted');
        }

        $existingEvent = suite_customer_sync_event($pdo, (string)$payload['event_id'], true);
        if ($existingEvent !== null) {
            if (!suite_customer_sync_receipt_matches($existingEvent, $tenantId, $payload, $requestSha256)) {
                throw new SuiteCustomerSyncConflictException('event_id_conflict');
            }
            $pdo->commit();
            return suite_customer_sync_success($existingEvent);
        }

        $bindingQuery = $pdo->prepare(
            'SELECT * FROM suite_customer_sync_bindings WHERE customer_id = ?'
            . suite_customer_sync_lock_clause($pdo)
        );
        $bindingQuery->execute([(string)$payload['customer_id']]);
        $binding = $bindingQuery->fetch(PDO::FETCH_ASSOC);

        if (!is_array($binding)) {
            if ((int)$payload['source_version'] !== 1) {
                throw new SuiteCustomerSyncConflictException('source_version_conflict', 1);
            }
            if ((string)$payload['status'] !== 'active') {
                throw new SuiteCustomerSyncConflictException('initial_status_conflict');
            }

            // Name only: domains, contacts, SLA tier, health, notes and
            // clients.source_key are Safeharbor-owned and remain untouched.
            $clientInsert = $pdo->prepare(
                'INSERT INTO clients (tenant_id, name, domain, sla_tier, health)
                 VALUES (?, ?, \'\', \'standard\', \'good\')'
            );
            $clientInsert->execute([$tenantId, (string)$payload['display_name']]);
            $clientId = (int)$pdo->lastInsertId();

            $bindingInsert = $pdo->prepare(
                'INSERT INTO suite_customer_sync_bindings
                    (tenant_id, customer_id, client_id, source_version,
                     display_name, status, last_event_id, last_occurred_at,
                     last_request_sha256)
                 VALUES (?, ?, ?, 1, ?, \'active\', ?, ?, ?)'
            );
            $bindingInsert->execute([
                $tenantId,
                (string)$payload['customer_id'],
                $clientId,
                (string)$payload['display_name'],
                (string)$payload['event_id'],
                (string)$payload['occurred_at_db'],
                $requestSha256,
            ]);
        } else {
            if ((int)$binding['tenant_id'] !== $tenantId) {
                throw new SuiteCustomerSyncConflictException('customer_tenant_conflict');
            }
            $expectedVersion = (int)$binding['source_version'] + 1;
            if ((int)$payload['source_version'] !== $expectedVersion) {
                throw new SuiteCustomerSyncConflictException(
                    'source_version_conflict',
                    $expectedVersion,
                );
            }
            if (strcmp((string)$payload['occurred_at_db'], (string)$binding['last_occurred_at']) < 0) {
                throw new SuiteCustomerSyncConflictException('event_time_conflict');
            }

            $client = $pdo->prepare(
                'SELECT id FROM clients WHERE tenant_id = ? AND id = ?'
                . suite_customer_sync_lock_clause($pdo)
            );
            $client->execute([$tenantId, (int)$binding['client_id']]);
            if ($client->fetchColumn() === false) {
                throw new SuiteCustomerSyncGateException('The bound Safeharbor client was not found.');
            }
            // An inactive source event is a tombstone, not permission to
            // rewrite the retained help-desk client. Preserve its current
            // name until a later active version explicitly reactivates it.
            if ((string)$payload['status'] === 'active') {
                $rename = $pdo->prepare(
                    'UPDATE clients SET name = ? WHERE tenant_id = ? AND id = ?'
                );
                $rename->execute([
                    (string)$payload['display_name'],
                    $tenantId,
                    (int)$binding['client_id'],
                ]);
            }

            $advance = $pdo->prepare(
                'UPDATE suite_customer_sync_bindings
                    SET source_version = ?, display_name = ?, status = ?,
                        last_event_id = ?, last_occurred_at = ?,
                        last_request_sha256 = ?
                  WHERE tenant_id = ? AND id = ? AND source_version = ?'
            );
            $advance->execute([
                (int)$payload['source_version'],
                (string)$payload['display_name'],
                (string)$payload['status'],
                (string)$payload['event_id'],
                (string)$payload['occurred_at_db'],
                $requestSha256,
                $tenantId,
                (int)$binding['id'],
                (int)$binding['source_version'],
            ]);
            if ($advance->rowCount() !== 1) {
                throw new SuiteCustomerSyncConflictException(
                    'source_version_conflict',
                    $expectedVersion,
                );
            }
        }

        // AFTER INSERT/UPDATE on the binding writes the immutable receipt.
        $receipt = suite_customer_sync_event($pdo, (string)$payload['event_id'], false);
        if ($receipt === null
            || !suite_customer_sync_receipt_matches($receipt, $tenantId, $payload, $requestSha256)
        ) {
            throw new SuiteCustomerSyncGateException('The immutable event receipt was not created.');
        }
        $pdo->commit();
        return suite_customer_sync_success($receipt);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
