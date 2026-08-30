<?php
/**
 * Default-off activation of an exact Milepost-managed Safeharbor customer.
 *
 * ID network authentication is isolated in managed_customer_id_evidence.php.
 * This file owns only bounded candidate selection and one atomic Safeharbor
 * reconciliation transaction. It never sends a report or changes ticket,
 * technician-time, billing, endpoint-control, or AI state.
 */
declare(strict_types=1);

require_once __DIR__ . '/business_reports.php';
require_once __DIR__ . '/portal_data.php';

const MANAGED_CUSTOMER_ACTIVATION_SCHEMA_VERSION = 1;
const MANAGED_CUSTOMER_ACTIVATION_EVIDENCE_SCHEMA = 2;
const MANAGED_CUSTOMER_ACTIVATION_CONTEXT = 'safeharbor-managed-customer-activation-v1';
const MANAGED_CUSTOMER_ACTIVATION_PREFIX = 'managed-weekly-v3:';
const MANAGED_CUSTOMER_ACTIVATION_TIMEZONE = 'America/Los_Angeles';
const MANAGED_CUSTOMER_ACTIVATION_WEEKDAY = 3;
const MANAGED_CUSTOMER_ACTIVATION_LOCAL_TIME = '09:00:00';
const MANAGED_CUSTOMER_ACTIVATION_MAX_CUSTOMERS = 25;
const MANAGED_CUSTOMER_ACTIVATION_MAX_BATCH = 25;
const MANAGED_CUSTOMER_EVENT_UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';

const MANAGED_CUSTOMER_ACTIVATION_WRITE_TABLES = [
    'customer_portal_bindings',
    'business_report_contact_scope_bindings',
    'business_report_id_client_bindings',
    'business_report_schedule_versions',
    'business_report_id_client_contact_snapshots',
    'managed_customer_activation_receipts',
];

class ManagedCustomerActivationException extends RuntimeException {}
final class ManagedCustomerActivationValidationException extends ManagedCustomerActivationException {}
final class ManagedCustomerActivationGateException extends ManagedCustomerActivationException {}
final class ManagedCustomerActivationConflictException extends ManagedCustomerActivationException {}

function managed_customer_activation_uuid(string $value): string
{
    if (preg_match(BUSINESS_REPORT_MILEPOST_CUSTOMER_UUID, $value) !== 1
        || hash_equals(BUSINESS_REPORT_MASTER_CUSTOMER_ID, $value)
    ) {
        throw new ManagedCustomerActivationValidationException(
            'Managed-customer activation requires an exact non-master customer UUID.',
        );
    }
    return $value;
}

function managed_customer_activation_schedule_key(string $customerId): string
{
    return business_report_schedule_key(
        MANAGED_CUSTOMER_ACTIVATION_PREFIX . managed_customer_activation_uuid($customerId),
    );
}

function managed_customer_event_uuid(string $value): string
{
    if (preg_match(MANAGED_CUSTOMER_EVENT_UUID_V4, $value) !== 1) {
        throw new ManagedCustomerActivationValidationException(
            'Managed-customer evidence requires an exact source event UUID.',
        );
    }
    return $value;
}

/**
 * @return array{
 *   enabled:bool,canary_only:bool,customer_ids:list<string>,
 *   tenant_actors:array<string,int>,batch_size:int,
 *   schedule_timezone:string,delivery_weekday:int,delivery_local_time:string
 * }
 */
function managed_customer_activation_config(?array $source = null): array
{
    if ($source === null) {
        $source = function_exists('cfg') ? cfg('managed_customer_activation', []) : [];
    }
    if (!is_array($source)) {
        throw new ManagedCustomerActivationValidationException(
            'Managed-customer activation configuration is invalid.',
        );
    }
    $enabled = $source['enabled'] ?? false;
    $canaryOnly = $source['canary_only'] ?? true;
    $customerIds = $source['customer_ids'] ?? [];
    $tenantActors = $source['tenant_actors'] ?? [];
    $batchSize = $source['batch_size'] ?? 5;
    $timezone = $source['schedule_timezone'] ?? MANAGED_CUSTOMER_ACTIVATION_TIMEZONE;
    $weekday = $source['delivery_weekday'] ?? MANAGED_CUSTOMER_ACTIVATION_WEEKDAY;
    $localTime = $source['delivery_local_time'] ?? MANAGED_CUSTOMER_ACTIVATION_LOCAL_TIME;
    if (!is_bool($enabled)
        || !is_bool($canaryOnly)
        || !is_array($customerIds)
        || !array_is_list($customerIds)
        || count($customerIds) > MANAGED_CUSTOMER_ACTIVATION_MAX_CUSTOMERS
        || !is_array($tenantActors)
        || count($tenantActors) > MANAGED_CUSTOMER_ACTIVATION_MAX_CUSTOMERS
        || !is_int($batchSize)
        || $batchSize < 1
        || $batchSize > MANAGED_CUSTOMER_ACTIVATION_MAX_BATCH
        || !is_string($timezone)
        || !is_int($weekday)
        || !is_string($localTime)
    ) {
        throw new ManagedCustomerActivationValidationException(
            'Managed-customer activation configuration is invalid.',
        );
    }
    $normalizedCustomers = [];
    foreach ($customerIds as $customerId) {
        if (!is_string($customerId)) {
            throw new ManagedCustomerActivationValidationException(
                'Managed-customer activation customer allowlist is invalid.',
            );
        }
        $customerId = managed_customer_activation_uuid($customerId);
        if (in_array($customerId, $normalizedCustomers, true)) {
            throw new ManagedCustomerActivationValidationException(
                'Managed-customer activation customer allowlist has a duplicate.',
            );
        }
        $normalizedCustomers[] = $customerId;
    }
    $normalizedActors = [];
    foreach ($tenantActors as $tenantSlug => $actorId) {
        if (!is_string($tenantSlug)
            || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $tenantSlug) !== 1
            || !is_int($actorId)
            || $actorId < 1
            || $actorId > 4_294_967_295
        ) {
            throw new ManagedCustomerActivationValidationException(
                'Managed-customer activation actor allowlist is invalid.',
            );
        }
        $normalizedActors[$tenantSlug] = $actorId;
    }
    $timezone = business_report_timezone($timezone);
    $localTime = business_report_time($localTime);
    if ($weekday < 1 || $weekday > 7) {
        throw new ManagedCustomerActivationValidationException(
            'Managed-customer activation delivery weekday is invalid.',
        );
    }
    if ($enabled && (!$canaryOnly || $normalizedCustomers === [] || $normalizedActors === [])) {
        throw new ManagedCustomerActivationValidationException(
            'Enabled managed-customer activation requires canary-only customer and actor allowlists.',
        );
    }
    return [
        'enabled' => $enabled,
        'canary_only' => $canaryOnly,
        'customer_ids' => $normalizedCustomers,
        'tenant_actors' => $normalizedActors,
        'batch_size' => $batchSize,
        'schedule_timezone' => $timezone,
        'delivery_weekday' => $weekday,
        'delivery_local_time' => $localTime,
    ];
}

/** @return array<string,mixed> */
function managed_customer_activation_evidence(array $source): array
{
    $expectedKeys = [
        'schema_version', 'customer_id', 'source_version', 'customer_receipt_id',
        'customer_event_id',
        'customer_status', 'lifecycle_version', 'lifecycle_transition_id',
        'lifecycle_action', 'lifecycle_evidence_sha256', 'identity_tenant_status',
        'identity_oauth_session_version', 'lifecycle_owned',
        'tenant_key', 'tenant_slug', 'contact_version', 'recipient_email', 'generated_at_db',
        'request_nonce_sha256', 'response_sha256',
    ];
    if (array_keys($source) !== $expectedKeys
        || ($source['schema_version'] ?? null) !== MANAGED_CUSTOMER_ACTIVATION_EVIDENCE_SCHEMA
        || !is_string($source['customer_id'] ?? null)
        || !is_int($source['source_version'] ?? null)
        || $source['source_version'] < 1
        || !is_string($source['customer_receipt_id'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $source['customer_receipt_id']) !== 1
        || !is_string($source['customer_event_id'] ?? null)
        || preg_match(MANAGED_CUSTOMER_EVENT_UUID_V4, $source['customer_event_id']) !== 1
        || ($source['customer_status'] ?? null) !== 'active'
        || ($source['lifecycle_version'] ?? null) !== 1
        || !is_int($source['lifecycle_transition_id'] ?? null)
        || $source['lifecycle_transition_id'] < 1
        || !in_array($source['lifecycle_action'] ?? null, ['observed_active', 'restored'], true)
        || !is_string($source['lifecycle_evidence_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $source['lifecycle_evidence_sha256']) !== 1
        || ($source['identity_tenant_status'] ?? null) !== 'active'
        || !is_int($source['identity_oauth_session_version'] ?? null)
        || $source['identity_oauth_session_version'] < 1
        || ($source['lifecycle_owned'] ?? null) !== false
        || !is_string($source['tenant_key'] ?? null)
        || preg_match('/\Aewid-t[1-9][0-9]{0,9}\z/D', $source['tenant_key']) !== 1
        || !is_string($source['tenant_slug'] ?? null)
        || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $source['tenant_slug']) !== 1
        || !is_int($source['contact_version'] ?? null)
        || $source['contact_version'] < 1
        || !is_string($source['recipient_email'] ?? null)
        || !is_string($source['generated_at_db'] ?? null)
        || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $source['generated_at_db']) !== 1
        || !is_string($source['request_nonce_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $source['request_nonce_sha256']) !== 1
        || !is_string($source['response_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $source['response_sha256']) !== 1
    ) {
        throw new ManagedCustomerActivationValidationException(
            'Authenticated ID schema-2 managed-customer evidence is invalid.',
        );
    }
    $customerId = managed_customer_activation_uuid($source['customer_id']);
    $source['customer_event_id'] = managed_customer_event_uuid($source['customer_event_id']);
    $recipient = business_report_email($source['recipient_email']);
    if (!hash_equals($recipient, $source['recipient_email'])) {
        throw new ManagedCustomerActivationValidationException(
            'Authenticated ID report contact is not canonical.',
        );
    }
    try {
        $tenantSlug = portal_identity_tenant_slug($source['tenant_slug']);
    } catch (PortalDataValidationException $error) {
        throw new ManagedCustomerActivationValidationException(
            'Authenticated ID portal tenant evidence is invalid.',
            0,
            $error,
        );
    }
    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $source['generated_at_db'],
        new DateTimeZone('UTC'),
    );
    $parseErrors = DateTimeImmutable::getLastErrors();
    if (!$parsed instanceof DateTimeImmutable
        || (is_array($parseErrors)
            && (($parseErrors['warning_count'] ?? 0) !== 0 || ($parseErrors['error_count'] ?? 0) !== 0))
        || $parsed->format('Y-m-d H:i:s') !== $source['generated_at_db']
    ) {
        throw new ManagedCustomerActivationValidationException(
            'Authenticated ID evidence timestamp is invalid.',
        );
    }
    $source['customer_id'] = $customerId;
    $source['tenant_slug'] = $tenantSlug;
    $source['recipient_email'] = $recipient;
    return $source;
}

function managed_customer_activation_lock_suffix(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
}

/** @return PDOStatement */
function managed_customer_activation_write(PDO $pdo, string $sql, array $values): PDOStatement
{
    if (preg_match('/\A\s*(INSERT\s+INTO|UPDATE)\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $parts) !== 1
        || !in_array(strtolower($parts[2]), MANAGED_CUSTOMER_ACTIVATION_WRITE_TABLES, true)
        || preg_match('/\b(DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i', $sql) === 1
    ) {
        throw new ManagedCustomerActivationGateException(
            'Managed-customer activation attempted a forbidden write.',
        );
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($values);
    return $statement;
}

/** @return list<array<string,mixed>> */
function managed_customer_activation_candidates(PDO $pdo, array $config): array
{
    $config = managed_customer_activation_config($config);
    if ($config['enabled'] !== true) {
        throw new ManagedCustomerActivationGateException('Managed-customer activation is disabled.');
    }
    $placeholders = implode(',', array_fill(0, count($config['customer_ids']), '?'));
    $sql = "SELECT binding.id AS binding_id, binding.tenant_id, binding.client_id,
                   binding.customer_id, binding.source_version, binding.display_name,
                   binding.status, binding.last_event_id,
                   binding.last_request_sha256, tenant.slug AS provider_tenant_slug
              FROM suite_customer_sync_bindings binding
              JOIN tenants tenant ON tenant.id = binding.tenant_id
              JOIN clients client
                ON client.tenant_id = binding.tenant_id
               AND client.id = binding.client_id
             WHERE binding.status = 'active'
               AND binding.customer_id IN ({$placeholders})
               AND NOT EXISTS (
                   SELECT 1 FROM suite_customer_sync_events inactive_event
                    WHERE inactive_event.tenant_id = binding.tenant_id
                      AND inactive_event.binding_id = binding.id
                      AND inactive_event.status = 'inactive'
               )
               AND NOT EXISTS (
                   SELECT 1
                     FROM managed_customer_activation_receipts receipt
                    WHERE receipt.customer_id = binding.customer_id
                       OR (receipt.tenant_id = binding.tenant_id
                           AND receipt.client_id = binding.client_id)
               )
             ORDER BY "
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'binding.customer_id'
            : 'BINARY binding.customer_id');
    $statement = $pdo->prepare($sql);
    $statement->execute($config['customer_ids']);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** @param array<string,mixed> $facts */
function managed_customer_activation_receipt_sha256(array $facts): string
{
    $keys = [
        'tenant_id', 'client_id', 'source_binding_id', 'customer_id', 'source_version',
        'customer_receipt_id', 'id_tenant_key', 'identity_tenant_slug', 'contact_version',
        'portal_binding_id', 'schedule_key', 'prepared_schedule_version_id',
        'active_schedule_version_id', 'actor_user_id', 'id_response_sha256',
        'recipient_sha256',
    ];
    $lines = [MANAGED_CUSTOMER_ACTIVATION_CONTEXT];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $facts) || (!is_int($facts[$key]) && !is_string($facts[$key]))) {
            throw new ManagedCustomerActivationValidationException('Activation receipt facts are incomplete.');
        }
        $lines[] = (string)$facts[$key];
    }
    return hash('sha256', implode("\n", $lines));
}

/** @return array<string,mixed>|null */
function managed_customer_activation_existing_receipt(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    string $customerId,
    string $scheduleKey,
): ?array {
    $sql = 'SELECT * FROM managed_customer_activation_receipts
             WHERE customer_id = ? OR (tenant_id = ? AND client_id = ?)
                OR (tenant_id = ? AND schedule_key = ?)'
        . managed_customer_activation_lock_suffix($pdo);
    $statement = $pdo->prepare($sql);
    $statement->execute([$customerId, $tenantId, $clientId, $tenantId, $scheduleKey]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) {
        throw new ManagedCustomerActivationConflictException('Activation receipt ownership is ambiguous.');
    }
    return is_array($rows[0] ?? null) ? $rows[0] : null;
}

/** @param array<string,mixed> $receipt @param array<string,mixed> $facts */
function managed_customer_activation_receipt_matches(PDO $pdo, array $receipt, array $facts): bool
{
    foreach ($facts as $key => $expected) {
        if ($key === 'evidence_sha256') continue;
        $actual = $receipt[$key] ?? null;
        if (is_int($expected)) {
            if ((int)$actual !== $expected) return false;
        } elseif (!is_string($actual) || !hash_equals($expected, $actual)) {
            return false;
        }
    }
    $expectedDigest = managed_customer_activation_receipt_sha256($facts);
    if (!is_string($receipt['evidence_sha256'] ?? null)
        || !hash_equals($expectedDigest, $receipt['evidence_sha256'])
    ) {
        return false;
    }
    $portal = $pdo->prepare(
        "SELECT COUNT(*) FROM customer_portal_bindings
          WHERE id = ? AND tenant_id = ? AND client_id = ?
            AND identity_tenant_slug = ? AND status = 'active'"
    );
    $portal->execute([
        $facts['portal_binding_id'], $facts['tenant_id'], $facts['client_id'],
        $facts['identity_tenant_slug'],
    ]);
    $latest = business_report_latest_schedule($pdo, $facts['tenant_id'], $facts['schedule_key']);
    return (int)$portal->fetchColumn() === 1
        && is_array($latest)
        && (int)$latest['id'] === $facts['active_schedule_version_id']
        && (string)$latest['status'] === 'active';
}

/** @return array<string,mixed> */
function managed_customer_activation_apply(
    PDO $pdo,
    array $candidate,
    array $rawEvidence,
    int $actorUserId,
    array $activationConfig,
    array $businessReportConfig,
    ?callable $fault = null,
): array {
    $config = managed_customer_activation_config($activationConfig);
    if ($config['enabled'] !== true || $config['canary_only'] !== true) {
        throw new ManagedCustomerActivationGateException('Managed-customer activation is disabled.');
    }
    if ($pdo->inTransaction()) {
        throw new ManagedCustomerActivationGateException('Activation requires its own transaction.');
    }
    $evidence = managed_customer_activation_evidence($rawEvidence);
    $customerId = $evidence['customer_id'];
    if (!in_array($customerId, $config['customer_ids'], true)) {
        throw new ManagedCustomerActivationGateException('The exact managed customer is not allowlisted.');
    }
    $scheduleKey = managed_customer_activation_schedule_key($customerId);
    $candidateBindingId = (int)($candidate['binding_id'] ?? 0);
    $candidateTenantId = (int)($candidate['tenant_id'] ?? 0);
    $candidateClientId = (int)($candidate['client_id'] ?? 0);
    $candidateSourceVersion = (int)($candidate['source_version'] ?? 0);
    $candidateTenantSlug = (string)($candidate['provider_tenant_slug'] ?? '');
    if ($candidateBindingId < 1 || $candidateTenantId < 1 || $candidateClientId < 1
        || $candidateSourceVersion < 1
        || ($candidate['status'] ?? null) !== 'active'
        || !is_string($candidate['customer_id'] ?? null)
        || !hash_equals($customerId, $candidate['customer_id'])
        || !is_string($candidate['last_event_id'] ?? null)
        || !hash_equals((string)$evidence['customer_event_id'], (string)$candidate['last_event_id'])
        || !isset($config['tenant_actors'][$candidateTenantSlug])
        || $config['tenant_actors'][$candidateTenantSlug] !== $actorUserId
    ) {
        throw new ManagedCustomerActivationGateException(
            'The candidate, customer, tenant, or actor allowlist does not match.',
        );
    }
    if ($candidateSourceVersion !== $evidence['source_version']) {
        throw new ManagedCustomerActivationGateException(
            'The authenticated ID projection does not match the exact Milepost binding.',
        );
    }

    $suffix = managed_customer_activation_lock_suffix($pdo);
    $pdo->beginTransaction();
    try {
        $tenant = $pdo->prepare('SELECT id, slug FROM tenants WHERE id = ? AND slug = ?' . $suffix);
        $tenant->execute([$candidateTenantId, $candidateTenantSlug]);
        $tenantRows = $tenant->fetchAll(PDO::FETCH_ASSOC);
        if (count($tenantRows) !== 1) {
            throw new ManagedCustomerActivationGateException('The exact provider tenant changed.');
        }
        $actor = $pdo->prepare(
            "SELECT id FROM users
              WHERE tenant_id = ? AND id = ? AND is_active = 1
                AND role IN ('owner','admin')" . $suffix
        );
        $actor->execute([$candidateTenantId, $actorUserId]);
        if ((int)$actor->fetchColumn() !== $actorUserId) {
            throw new ManagedCustomerActivationGateException(
                'The activation actor is not an active owner/admin in the exact tenant.',
            );
        }
        $binding = $pdo->prepare(
            "SELECT binding.id, binding.tenant_id, binding.client_id, binding.customer_id,
                    binding.source_version, binding.display_name, binding.status,
                    binding.last_event_id, binding.last_request_sha256,
                    receipt.id AS source_event_receipt_id
               FROM suite_customer_sync_bindings binding
               JOIN clients client
                 ON client.tenant_id = binding.tenant_id
                AND client.id = binding.client_id
               JOIN suite_customer_sync_events receipt
                 ON receipt.tenant_id = binding.tenant_id
                AND receipt.binding_id = binding.id
                AND receipt.event_id = binding.last_event_id
                AND receipt.customer_id = binding.customer_id
                AND receipt.client_id = binding.client_id
                AND receipt.source_version = binding.source_version
                AND receipt.status = binding.status
                AND receipt.request_sha256 = binding.last_request_sha256
              WHERE binding.id = ? AND binding.tenant_id = ? AND binding.client_id = ?" . $suffix
        );
        $binding->execute([$candidateBindingId, $candidateTenantId, $candidateClientId]);
        $bindingRows = $binding->fetchAll(PDO::FETCH_ASSOC);
        $current = $bindingRows[0] ?? null;
        if (count($bindingRows) !== 1
            || !is_array($current)
            || !is_string($current['customer_id'] ?? null)
            || !hash_equals($customerId, $current['customer_id'])
            || (int)$current['source_version'] !== $candidateSourceVersion
            || !hash_equals('active', (string)$current['status'])
            || !hash_equals((string)$evidence['customer_event_id'], (string)$current['last_event_id'])
            || !hash_equals((string)($candidate['last_event_id'] ?? ''), (string)$current['last_event_id'])
            || !hash_equals(
                (string)($candidate['last_request_sha256'] ?? ''),
                (string)$current['last_request_sha256'],
            )
        ) {
            throw new ManagedCustomerActivationGateException(
                'The exact active Milepost customer binding changed before activation.',
            );
        }
        $inactiveHistory = $pdo->prepare(
            "SELECT COUNT(*) FROM suite_customer_sync_events
              WHERE tenant_id = ? AND binding_id = ? AND status = 'inactive'"
        );
        $inactiveHistory->execute([$candidateTenantId, $candidateBindingId]);
        if ((int)$inactiveHistory->fetchColumn() !== 0) {
            throw new ManagedCustomerActivationGateException(
                'Managed-customer activation cannot clear a prior inactive lifecycle latch.',
            );
        }

        $definition = $pdo->prepare(
            'SELECT * FROM business_report_definition_versions
              WHERE tenant_id = ? AND definition_key = ? AND version_no = 3' . $suffix
        );
        $definition->execute([$candidateTenantId, BUSINESS_REPORT_DEFINITION_KEY]);
        $definitionRows = $definition->fetchAll(PDO::FETCH_ASSOC);
        if (count($definitionRows) !== 1
            || !business_report_definition_supported($definitionRows[0])
        ) {
            throw new ManagedCustomerActivationGateException(
                'The exact version-3 weekly report definition is unavailable.',
            );
        }
        $definitionId = (int)$definitionRows[0]['id'];

        $receipt = managed_customer_activation_existing_receipt(
            $pdo,
            $candidateTenantId,
            $candidateClientId,
            $customerId,
            $scheduleKey,
        );
        if (is_array($receipt)) {
            $facts = [
                'tenant_id' => $candidateTenantId,
                'client_id' => $candidateClientId,
                'source_binding_id' => $candidateBindingId,
                'customer_id' => $customerId,
                'source_version' => $candidateSourceVersion,
                'customer_receipt_id' => $evidence['customer_receipt_id'],
                'id_tenant_key' => $evidence['tenant_key'],
                'identity_tenant_slug' => $evidence['tenant_slug'],
                'contact_version' => $evidence['contact_version'],
                'portal_binding_id' => (int)$receipt['portal_binding_id'],
                'schedule_key' => $scheduleKey,
                'prepared_schedule_version_id' => (int)$receipt['prepared_schedule_version_id'],
                'active_schedule_version_id' => (int)$receipt['active_schedule_version_id'],
                'actor_user_id' => $actorUserId,
                // A fresh authenticated lookup has a new nonce/timestamp and
                // therefore a new response digest. Exact replay means the
                // same bound UUID/source-receipt/contact semantics; retain the
                // originally committed transport receipt.
                'id_response_sha256' => (string)$receipt['id_response_sha256'],
                'recipient_sha256' => hash('sha256', $evidence['recipient_email']),
            ];
            if (!managed_customer_activation_receipt_matches($pdo, $receipt, $facts)) {
                throw new ManagedCustomerActivationConflictException(
                    'Existing activation evidence or current portal/report state conflicts.',
                );
            }
            $pdo->commit();
            return [
                'action' => 'replayed',
                'customer_id_sha256' => hash('sha256', $customerId),
                'schedule_key' => $scheduleKey,
                'portal_binding_id' => $facts['portal_binding_id'],
                'active_schedule_version_id' => $facts['active_schedule_version_id'],
                'evidence_sha256' => (string)$receipt['evidence_sha256'],
            ];
        }

        $portalQuery = $pdo->prepare(
            'SELECT * FROM customer_portal_bindings
              WHERE identity_tenant_slug = ? OR (tenant_id = ? AND client_id = ?)' . $suffix
        );
        $portalQuery->execute([$evidence['tenant_slug'], $candidateTenantId, $candidateClientId]);
        $portalRows = $portalQuery->fetchAll(PDO::FETCH_ASSOC);
        if (count($portalRows) > 1) {
            throw new ManagedCustomerActivationConflictException('Portal binding ownership is ambiguous.');
        }
        $portal = $portalRows[0] ?? null;
        $reason = 'Activate exact Milepost-managed customer ' . hash('sha256', $customerId);
        if (!is_array($portal)) {
            managed_customer_activation_write(
                $pdo,
                "INSERT INTO customer_portal_bindings
                    (identity_tenant_slug, tenant_id, client_id, status,
                     prepared_by_user_id, last_changed_by_user_id, status_reason)
                 VALUES (?, ?, ?, 'disabled', ?, ?, ?)",
                [$evidence['tenant_slug'], $candidateTenantId, $candidateClientId,
                 $actorUserId, $actorUserId, $reason],
            );
            $portalId = (int)$pdo->lastInsertId();
            $portalQuery = $pdo->prepare('SELECT * FROM customer_portal_bindings WHERE id = ?');
            $portalQuery->execute([$portalId]);
            $portal = $portalQuery->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($portal)
            || !hash_equals((string)$portal['identity_tenant_slug'], $evidence['tenant_slug'])
            || (int)$portal['tenant_id'] !== $candidateTenantId
            || (int)$portal['client_id'] !== $candidateClientId
            || !in_array((string)$portal['status'], ['disabled', 'active'], true)
        ) {
            throw new ManagedCustomerActivationConflictException(
                'An existing portal binding belongs to different customer state.',
            );
        }
        $portalId = (int)$portal['id'];
        if ((string)$portal['status'] === 'disabled') {
            managed_customer_activation_write(
                $pdo,
                "UPDATE customer_portal_bindings
                    SET status = 'active', last_changed_by_user_id = ?, status_reason = ?
                  WHERE id = ? AND tenant_id = ? AND client_id = ?
                    AND identity_tenant_slug = ? AND status = 'disabled'",
                [$actorUserId, $reason, $portalId, $candidateTenantId,
                 $candidateClientId, $evidence['tenant_slug']],
            );
        }
        if ($fault !== null) $fault('after_portal');

        $idBinding = $pdo->prepare(
            'SELECT * FROM business_report_id_client_bindings
              WHERE tenant_id = ? AND client_id = ?' . $suffix
        );
        $idBinding->execute([$candidateTenantId, $candidateClientId]);
        $idBindingRow = $idBinding->fetch(PDO::FETCH_ASSOC);
        if (!is_array($idBindingRow)) {
            $keyOwner = $pdo->prepare(
                'SELECT tenant_id, client_id FROM business_report_id_client_bindings
                  WHERE id_tenant_key = ?' . $suffix
            );
            $keyOwner->execute([$evidence['tenant_key']]);
            $tenantOwner = $pdo->prepare(
                'SELECT tenant_id FROM business_report_id_tenant_bindings
                  WHERE id_tenant_key = ?' . $suffix
            );
            $tenantOwner->execute([$evidence['tenant_key']]);
            if ($keyOwner->fetch(PDO::FETCH_ASSOC) !== false || $tenantOwner->fetchColumn() !== false) {
                throw new ManagedCustomerActivationConflictException(
                    'The ID tenant key is already bound to another Safeharbor scope.',
                );
            }
            managed_customer_activation_write(
                $pdo,
                'INSERT INTO business_report_id_client_bindings
                    (tenant_id, client_id, id_tenant_key, id_tenant_slug,
                     created_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$candidateTenantId, $candidateClientId, $evidence['tenant_key'],
                 $evidence['tenant_slug'], $actorUserId, $reason],
            );
        } elseif (!hash_equals((string)$idBindingRow['id_tenant_key'], $evidence['tenant_key'])
            || !hash_equals((string)$idBindingRow['id_tenant_slug'], $evidence['tenant_slug'])
        ) {
            throw new ManagedCustomerActivationConflictException(
                'The Safeharbor client already has conflicting ID tenant evidence.',
            );
        }

        $scope = business_report_contact_scope_for_key($pdo, $candidateTenantId, $scheduleKey);
        if (!is_array($scope)) {
            managed_customer_activation_write(
                $pdo,
                'INSERT INTO business_report_contact_scope_bindings
                    (tenant_id, schedule_key, contact_scope, created_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)',
                [$candidateTenantId, $scheduleKey, BUSINESS_REPORT_CONTACT_SCOPE_CLIENT,
                 $actorUserId, $reason],
            );
        } elseif (!hash_equals(BUSINESS_REPORT_CONTACT_SCOPE_CLIENT, (string)$scope['scope'])) {
            throw new ManagedCustomerActivationConflictException(
                'The deterministic report schedule key has a different contact scope.',
            );
        }

        $history = $pdo->prepare(
            'SELECT contact_version, recipient_email
               FROM business_report_id_client_contact_snapshots
              WHERE tenant_id = ? AND client_id = ? AND id_tenant_key = ?
              ORDER BY contact_version DESC, id DESC LIMIT 1' . $suffix
        );
        $history->execute([$candidateTenantId, $candidateClientId, $evidence['tenant_key']]);
        $historyRow = $history->fetch(PDO::FETCH_ASSOC);
        if (is_array($historyRow)
            && (int)$historyRow['contact_version'] > $evidence['contact_version']
        ) {
            throw new ManagedCustomerActivationConflictException(
                'The authenticated ID report-contact evidence is stale.',
            );
        }
        if (is_array($historyRow)
            && (int)$historyRow['contact_version'] === $evidence['contact_version']
            && !hash_equals((string)$historyRow['recipient_email'], $evidence['recipient_email'])
        ) {
            throw new ManagedCustomerActivationConflictException(
                'The same ID contact version names a different recipient.',
            );
        }

        $latest = business_report_latest_schedule($pdo, $candidateTenantId, $scheduleKey, true);
        $preparedId = 0;
        $activeId = 0;
        $scheduleExact = static function (array $schedule) use (
            $candidateClientId,
            $definitionId,
            $evidence,
            $config,
        ): bool {
            return (int)$schedule['client_id'] === $candidateClientId
                && (int)$schedule['definition_version_id'] === $definitionId
                && hash_equals((string)$schedule['recipient_email'], $evidence['recipient_email'])
                && hash_equals((string)$schedule['schedule_timezone'], $config['schedule_timezone'])
                && (int)$schedule['delivery_weekday'] === $config['delivery_weekday']
                && hash_equals((string)$schedule['delivery_local_time'], $config['delivery_local_time'])
                && (int)$schedule['canary'] === 1;
        };
        if (!is_array($latest)) {
            managed_customer_activation_write(
                $pdo,
                "INSERT INTO business_report_schedule_versions
                    (tenant_id, schedule_key, version_no, definition_version_id,
                     client_id, recipient_email, schedule_timezone, delivery_weekday,
                     delivery_local_time, canary, status, created_by_user_id, reason)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, 1, 'disabled', ?, ?)",
                [$candidateTenantId, $scheduleKey, $definitionId, $candidateClientId,
                 $evidence['recipient_email'], $config['schedule_timezone'],
                 $config['delivery_weekday'], $config['delivery_local_time'],
                 $actorUserId, $reason],
            );
            $preparedId = (int)$pdo->lastInsertId();
            managed_customer_activation_write(
                $pdo,
                'INSERT INTO business_report_id_client_contact_snapshots
                    (tenant_id, client_id, schedule_version_id, id_tenant_key,
                     contact_version, recipient_email, response_generated_at,
                     request_nonce_sha256, response_sha256, created_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$candidateTenantId, $candidateClientId, $preparedId,
                 $evidence['tenant_key'], $evidence['contact_version'],
                 $evidence['recipient_email'], $evidence['generated_at_db'],
                 $evidence['request_nonce_sha256'], $evidence['response_sha256'],
                 $actorUserId, $reason],
            );
            $latestQuery = $pdo->prepare(
                'SELECT * FROM business_report_schedule_versions WHERE tenant_id = ? AND id = ?'
            );
            $latestQuery->execute([$candidateTenantId, $preparedId]);
            $latest = $latestQuery->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($latest) || !$scheduleExact($latest)) {
            throw new ManagedCustomerActivationConflictException(
                'The deterministic report schedule has conflicting facts.',
            );
        }
        if ((string)$latest['status'] === 'disabled') {
            $preparedId = (int)$latest['id'];
            $preparedEvidence = business_report_id_client_contact_for_schedule(
                $pdo,
                $candidateTenantId,
                $preparedId,
                true,
            );
            if (!is_array($preparedEvidence)
                || !hash_equals((string)$preparedEvidence['id_tenant_key'], $evidence['tenant_key'])
                || (int)$preparedEvidence['contact_version'] !== $evidence['contact_version']
                || !hash_equals((string)$preparedEvidence['recipient_email'], $evidence['recipient_email'])
                || !hash_equals((string)$preparedEvidence['response_sha256'], $evidence['response_sha256'])
            ) {
                throw new ManagedCustomerActivationConflictException(
                    'The prepared report schedule has conflicting ID evidence.',
                );
            }
            $gateSchedule = $latest;
            $gateSchedule['tenant_slug'] = $candidateTenantSlug;
            business_report_assert_schedule_gate(
                $gateSchedule,
                business_report_config($businessReportConfig),
                'dry_run',
            );
            if ($fault !== null) $fault('after_schedule_prepared');
            managed_customer_activation_write(
                $pdo,
                'INSERT INTO business_report_schedule_versions
                    (tenant_id, schedule_key, version_no, definition_version_id,
                     client_id, recipient_email, schedule_timezone, delivery_weekday,
                     delivery_local_time, canary, status, created_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [$candidateTenantId, $scheduleKey, (int)$latest['version_no'] + 1,
                 $definitionId, $candidateClientId, $evidence['recipient_email'],
                 $config['schedule_timezone'], $config['delivery_weekday'],
                 $config['delivery_local_time'], 'active', $actorUserId, $reason],
            );
            $activeId = (int)$pdo->lastInsertId();
        } elseif ((string)$latest['status'] === 'active') {
            $activeId = (int)$latest['id'];
            $latestEvidence = business_report_latest_id_client_contact_for_key(
                $pdo,
                $candidateTenantId,
                $scheduleKey,
            );
            if (!is_array($latestEvidence)
                || !hash_equals((string)$latestEvidence['id_tenant_key'], $evidence['tenant_key'])
                || (int)$latestEvidence['contact_version'] !== $evidence['contact_version']
                || !hash_equals((string)$latestEvidence['recipient_email'], $evidence['recipient_email'])
                || !hash_equals((string)$latestEvidence['response_sha256'], $evidence['response_sha256'])
            ) {
                throw new ManagedCustomerActivationConflictException(
                    'The active report schedule has conflicting ID evidence.',
                );
            }
            $preparedId = (int)$latestEvidence['schedule_version_id'];
            $gateSchedule = $latest;
            $gateSchedule['tenant_slug'] = $candidateTenantSlug;
            business_report_assert_schedule_gate(
                $gateSchedule,
                business_report_config($businessReportConfig),
                'dry_run',
            );
        } else {
            throw new ManagedCustomerActivationConflictException(
                'The deterministic report schedule status is invalid.',
            );
        }

        $facts = [
            'tenant_id' => $candidateTenantId,
            'client_id' => $candidateClientId,
            'source_binding_id' => $candidateBindingId,
            'customer_id' => $customerId,
            'source_version' => $candidateSourceVersion,
            'customer_receipt_id' => $evidence['customer_receipt_id'],
            'id_tenant_key' => $evidence['tenant_key'],
            'identity_tenant_slug' => $evidence['tenant_slug'],
            'contact_version' => $evidence['contact_version'],
            'portal_binding_id' => $portalId,
            'schedule_key' => $scheduleKey,
            'prepared_schedule_version_id' => $preparedId,
            'active_schedule_version_id' => $activeId,
            'actor_user_id' => $actorUserId,
            'id_response_sha256' => $evidence['response_sha256'],
            'recipient_sha256' => hash('sha256', $evidence['recipient_email']),
        ];
        $digest = managed_customer_activation_receipt_sha256($facts);
        if ($fault !== null) $fault('before_receipt');
        managed_customer_activation_write(
            $pdo,
            'INSERT INTO managed_customer_activation_receipts
                (tenant_id, client_id, source_binding_id, customer_id, source_version,
                 customer_receipt_id, id_tenant_key, identity_tenant_slug, contact_version,
                 portal_binding_id, schedule_key, prepared_schedule_version_id,
                 active_schedule_version_id, actor_user_id, id_response_sha256,
                 recipient_sha256, evidence_sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [...array_values($facts), $digest],
        );
        if ($fault !== null) $fault('after_receipt');
        $pdo->commit();
        return [
            'action' => 'activated',
            'customer_id_sha256' => hash('sha256', $customerId),
            'schedule_key' => $scheduleKey,
            'portal_binding_id' => $portalId,
            'active_schedule_version_id' => $activeId,
            'evidence_sha256' => $digest,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof ManagedCustomerActivationException
            || $error instanceof BusinessReportException
        ) {
            throw $error;
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            throw new ManagedCustomerActivationConflictException(
                'Concurrent activation committed conflicting ownership evidence.',
                0,
                $error,
            );
        }
        throw $error;
    }
}

/**
 * @param callable(string,array<string,mixed>):array<string,mixed>|null $fetchEvidence
 * @return list<array<string,mixed>>
 */
function managed_customer_activation_run(
    PDO $pdo,
    array $activationConfig,
    array $businessReportConfig,
    array $idConfig,
    ?callable $fetchEvidence = null,
): array {
    $config = managed_customer_activation_config($activationConfig);
    if ($config['enabled'] !== true) {
        throw new ManagedCustomerActivationGateException('Managed-customer activation is disabled.');
    }
    if ($fetchEvidence === null) {
        if (!function_exists('managed_customer_id_evidence_fetch')) {
            throw new ManagedCustomerActivationGateException(
                'The authenticated ID schema-2 evidence adapter is unavailable.',
            );
        }
        $fetchEvidence = 'managed_customer_id_evidence_fetch';
    }
    $results = [];
    $completed = 0;
    foreach (managed_customer_activation_candidates($pdo, $config) as $candidate) {
        $tenantSlug = (string)($candidate['provider_tenant_slug'] ?? '');
        $customerId = (string)($candidate['customer_id'] ?? '');
        $customerHash = preg_match(BUSINESS_REPORT_MILEPOST_CUSTOMER_UUID, $customerId) === 1
            ? hash('sha256', $customerId)
            : str_repeat('0', 64);
        try {
            $actorId = $config['tenant_actors'][$tenantSlug] ?? null;
            if (!is_int($actorId)) {
                throw new ManagedCustomerActivationGateException(
                    'No exact owner/admin actor is allowlisted for the provider tenant.',
                );
            }
            $evidence = $fetchEvidence($customerId, $idConfig);
            if (!is_array($evidence)) {
                throw new ManagedCustomerActivationGateException(
                    'The authenticated ID evidence adapter returned no exact document.',
                );
            }
            $results[] = managed_customer_activation_apply(
                $pdo,
                $candidate,
                $evidence,
                $actorId,
                $config,
                $businessReportConfig,
            );
            $completed++;
            if ($completed >= $config['batch_size']) break;
        } catch (Throwable $error) {
            $results[] = [
                'action' => 'refused',
                'customer_id_sha256' => $customerHash,
                'error_class' => $error::class,
                'error_sha256' => hash('sha256', $error->getMessage()),
            ];
        }
    }
    return $results;
}
