<?php
/**
 * Default-off physical containment for exact Milepost-managed customers.
 *
 * The read boundary in managed_customer_status.php is immediate. This worker
 * adds durable physical containment: it disables an active customer portal,
 * appends a disabled version to the deterministic weekly report schedule, and
 * records one immutable receipt. It never deletes help-desk history and has no
 * report-send, billing, ticket-resolution, endpoint-control, or AI authority.
 */
declare(strict_types=1);

require_once __DIR__ . '/managed_customer_activation.php';
require_once __DIR__ . '/managed_customer_status.php';
require_once __DIR__ . '/suite_managed_provider.php';
require_once __DIR__ . '/managed_customer_id_evidence.php';

const MANAGED_CUSTOMER_LIFECYCLE_CONTEXT = 'safeharbor-managed-customer-lifecycle-v1';
const MANAGED_CUSTOMER_LIFECYCLE_RESTORE_CONTEXT = 'safeharbor-managed-customer-lifecycle-restore-v1';
const MANAGED_CUSTOMER_LIFECYCLE_IDENTITY_RESTORE_CONTEXT = 'safeharbor-managed-customer-lifecycle-identity-restore-v1';
const MANAGED_CUSTOMER_LIFECYCLE_MAX_CUSTOMERS = 25;
const MANAGED_CUSTOMER_LIFECYCLE_MAX_BATCH = 25;
const MANAGED_CUSTOMER_LIFECYCLE_WRITE_TABLES = [
    'customer_portal_bindings',
    'business_report_schedule_versions',
    'business_report_id_client_contact_snapshots',
    'managed_customer_lifecycle_receipts',
    'managed_customer_lifecycle_restore_receipts',
];

class ManagedCustomerLifecycleException extends RuntimeException {}
final class ManagedCustomerLifecycleValidationException extends ManagedCustomerLifecycleException {}
final class ManagedCustomerLifecycleGateException extends ManagedCustomerLifecycleException {}
final class ManagedCustomerLifecycleConflictException extends ManagedCustomerLifecycleException {}
/** A scheduled action reached its deadline before writing; it rolled back and is reported as deferred. */
final class ManagedCustomerLifecycleDeferredException extends ManagedCustomerLifecycleException {}

/**
 * Keep the shared strict ID parser isolated from restored-only semantics.
 *
 * @return array<string,mixed>
 */
function managed_customer_lifecycle_base_evidence(array $source): array
{
    try {
        return managed_customer_activation_evidence($source);
    } catch (ManagedCustomerActivationException $error) {
        throw new ManagedCustomerLifecycleValidationException(
            'Authenticated ID schema-2 base evidence is invalid.',
            0,
            $error,
        );
    }
}

/** @return array<string,mixed> */
function managed_customer_lifecycle_restore_evidence(array $source): array
{
    $evidence = managed_customer_lifecycle_base_evidence($source);
    if (($evidence['lifecycle_action'] ?? null) !== 'restored') {
        throw new ManagedCustomerLifecycleGateException(
            'A post-inactive customer requires exact signed restored lifecycle evidence.',
        );
    }
    return $evidence;
}

/**
 * @return array{
 *   enabled:bool,restoration_enabled:bool,customer_ids:list<string>,
 *   tenant_actors:array<string,int>,batch_size:int
 * }
 */
function managed_customer_lifecycle_config(?array $source = null): array
{
    if ($source === null) {
        $source = function_exists('cfg') ? cfg('managed_customer_lifecycle', []) : [];
    }
    if (!is_array($source)) {
        throw new ManagedCustomerLifecycleValidationException(
            'Managed-customer lifecycle configuration is invalid.',
        );
    }
    $enabled = $source['enabled'] ?? false;
    $restorationEnabled = $source['restoration_enabled'] ?? false;
    $customerIds = $source['customer_ids'] ?? [];
    $tenantActors = $source['tenant_actors'] ?? [];
    $batchSize = $source['batch_size'] ?? 5;
    if (!is_bool($enabled)
        || !is_bool($restorationEnabled)
        || ($restorationEnabled && !$enabled)
        || !is_array($customerIds)
        || !array_is_list($customerIds)
        || count($customerIds) > MANAGED_CUSTOMER_LIFECYCLE_MAX_CUSTOMERS
        || !is_array($tenantActors)
        || count($tenantActors) > MANAGED_CUSTOMER_LIFECYCLE_MAX_CUSTOMERS
        || !is_int($batchSize)
        || $batchSize < 1
        || $batchSize > MANAGED_CUSTOMER_LIFECYCLE_MAX_BATCH
    ) {
        throw new ManagedCustomerLifecycleValidationException(
            'Managed-customer lifecycle configuration is invalid.',
        );
    }

    $normalizedCustomers = [];
    foreach ($customerIds as $customerId) {
        if (!is_string($customerId)) {
            throw new ManagedCustomerLifecycleValidationException(
                'Managed-customer lifecycle allowlist is invalid.',
            );
        }
        try {
            $customerId = managed_customer_activation_uuid($customerId);
        } catch (ManagedCustomerActivationException $error) {
            throw new ManagedCustomerLifecycleValidationException(
                'Managed-customer lifecycle allowlist is invalid.',
                0,
                $error,
            );
        }
        if (in_array($customerId, $normalizedCustomers, true)) {
            throw new ManagedCustomerLifecycleValidationException(
                'Managed-customer lifecycle allowlist has a duplicate.',
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
            throw new ManagedCustomerLifecycleValidationException(
                'Managed-customer lifecycle actor allowlist is invalid.',
            );
        }
        $normalizedActors[$tenantSlug] = $actorId;
    }
    if ($enabled && ($normalizedCustomers === [] || $normalizedActors === [])) {
        throw new ManagedCustomerLifecycleValidationException(
            'Enabled managed-customer lifecycle requires exact customer and actor allowlists.',
        );
    }
    return [
        'enabled' => $enabled,
        'restoration_enabled' => $restorationEnabled,
        'customer_ids' => $normalizedCustomers,
        'tenant_actors' => $normalizedActors,
        'batch_size' => $batchSize,
    ];
}

function managed_customer_lifecycle_lock_suffix(PDO $pdo, string $kind = 'update'): string
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') return '';
    return $kind === 'share' ? ' FOR SHARE' : ' FOR UPDATE';
}

/** @return array<string,mixed>|null */
function managed_customer_lifecycle_latest_schedule(
    PDO $pdo,
    int $tenantId,
    string $scheduleKey,
): ?array {
    // Lifecycle never updates schedule history. The exact portal row (or its
    // unique insertion gap) is the per-client exclusive serialization point;
    // this shared current read prevents a concurrent schedule append without
    // granting the runtime UPDATE authority over immutable schedule versions.
    $query = $pdo->prepare(
        'SELECT * FROM business_report_schedule_versions
          WHERE tenant_id = ? AND schedule_key = ?
          ORDER BY version_no DESC LIMIT 1'
        . managed_customer_lifecycle_lock_suffix($pdo, 'share')
    );
    $query->execute([$tenantId, $scheduleKey]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function managed_customer_lifecycle_retryable_database_error(Throwable $error): bool
{
    if (!$error instanceof PDOException) return false;
    $driverCode = is_array($error->errorInfo ?? null)
        ? (int)($error->errorInfo[1] ?? 0)
        : 0;
    return in_array($driverCode, [1062, 1205, 1213], true)
        || (string)$error->getCode() === '40001';
}

/** @return PDOStatement */
function managed_customer_lifecycle_write(PDO $pdo, string $sql, array $values): PDOStatement
{
    if (preg_match('/\A\s*(INSERT\s+INTO|UPDATE)\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $parts) !== 1
        || !in_array(strtolower($parts[2]), MANAGED_CUSTOMER_LIFECYCLE_WRITE_TABLES, true)
        || preg_match('/\b(DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i', $sql) === 1
    ) {
        throw new ManagedCustomerLifecycleGateException(
            'Managed-customer lifecycle attempted a forbidden write.',
        );
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($values);
    return $statement;
}

/** @return list<array<string,mixed>> */
function managed_customer_lifecycle_candidates(PDO $pdo, array $rawConfig): array
{
    $config = managed_customer_lifecycle_config($rawConfig);
    if ($config['enabled'] !== true) {
        throw new ManagedCustomerLifecycleGateException('Managed-customer lifecycle is disabled.');
    }
    $placeholders = implode(',', array_fill(0, count($config['customer_ids']), '?'));
    $binary = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : 'BINARY ';
    $sql = "SELECT binding.id AS binding_id, binding.tenant_id, binding.client_id,
                   binding.customer_id, binding.source_version, binding.status,
                   binding.last_event_id, binding.last_request_sha256,
                   tenant.slug AS provider_tenant_slug
              FROM suite_customer_sync_bindings binding
              JOIN tenants tenant ON tenant.id = binding.tenant_id
             WHERE binding.customer_id IN ({$placeholders})
               AND (
                    (binding.status = 'inactive' AND NOT EXISTS (
                       SELECT 1 FROM managed_customer_lifecycle_receipts receipt
                        WHERE receipt.customer_id = binding.customer_id
                          AND receipt.source_version = binding.source_version
                          AND receipt.action = 'contained'
                    ))
                    OR
                    (binding.status = 'active'
                     AND EXISTS (
                       SELECT 1 FROM suite_customer_sync_events inactive_event
                        WHERE inactive_event.tenant_id = binding.tenant_id
                          AND inactive_event.binding_id = binding.id
                          AND inactive_event.status = 'inactive'
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM managed_customer_lifecycle_restore_receipts restored
                        WHERE restored.customer_id = binding.customer_id
                          AND restored.source_version = binding.source_version
                     ))
               )
             ORDER BY {$binary}binding.customer_id";
    $statement = $pdo->prepare($sql);
    $statement->execute($config['customer_ids']);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** @param array<string,mixed> $row */
function managed_customer_lifecycle_schedule_sha256(array $row): string
{
    $keys = [
        'id', 'tenant_id', 'schedule_key', 'version_no', 'definition_version_id',
        'client_id', 'recipient_email', 'schedule_timezone', 'delivery_weekday',
        'delivery_local_time', 'canary', 'status', 'created_by_user_id', 'reason',
    ];
    $lines = ['safeharbor-managed-customer-schedule-state-v1'];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || (!is_int($row[$key]) && !is_string($row[$key]))) {
            throw new ManagedCustomerLifecycleConflictException('Report schedule state is incomplete.');
        }
        $lines[] = (string)$row[$key];
    }
    return hash('sha256', implode("\n", $lines));
}

/** @param array<string,mixed> $facts */
function managed_customer_lifecycle_receipt_sha256(array $facts): string
{
    $keys = [
        'tenant_id', 'client_id', 'source_binding_id', 'customer_id',
        'source_event_receipt_id', 'source_event_id', 'source_version',
        'source_status', 'action', 'portal_binding_id', 'portal_was_active',
        'portal_before_event_id', 'portal_state_event_id', 'portal_disabled_event_id',
        'portal_state_sha256', 'schedule_key', 'schedule_was_active',
        'schedule_active_version_id', 'schedule_state_version_id',
        'schedule_disabled_version_id', 'schedule_state_sha256', 'actor_user_id',
        'source_request_sha256',
    ];
    $lines = [MANAGED_CUSTOMER_LIFECYCLE_CONTEXT];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $facts)) {
            throw new ManagedCustomerLifecycleValidationException('Lifecycle receipt facts are incomplete.');
        }
        $value = $facts[$key];
        if ($value === null) {
            $lines[] = '-';
        } elseif (is_int($value) || is_string($value)) {
            $lines[] = (string)$value;
        } else {
            throw new ManagedCustomerLifecycleValidationException('Lifecycle receipt facts are invalid.');
        }
    }
    return hash('sha256', implode("\n", $lines));
}

/** @return array<string,mixed>|null */
function managed_customer_lifecycle_existing_receipt(
    PDO $pdo,
    string $customerId,
    int $sourceVersion,
    string $action,
): ?array {
    $query = $pdo->prepare(
        'SELECT * FROM managed_customer_lifecycle_receipts
          WHERE customer_id = ? AND source_version = ? AND action = ?'
        . managed_customer_lifecycle_lock_suffix($pdo, 'share')
    );
    $query->execute([$customerId, $sourceVersion, $action]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @param array<string,mixed> $receipt */
function managed_customer_lifecycle_receipt_valid(array $receipt): bool
{
    $facts = $receipt;
    unset($facts['id'], $facts['evidence_sha256'], $facts['created_at']);
    try {
        $expected = managed_customer_lifecycle_receipt_sha256($facts);
    } catch (Throwable) {
        return false;
    }
    return is_string($receipt['evidence_sha256'] ?? null)
        && hash_equals($expected, (string)$receipt['evidence_sha256']);
}

/**
 * Atomically contain one exact current source event.
 *
 * $guard (the scheduled mode's seam) is called with 'authority' right after the tenant and actor
 * locks, and with 'write' before the first write and again before the receipt insert. It may throw
 * to refuse or defer; the whole transaction then rolls back.
 *
 * @return array<string,mixed>
 */
function managed_customer_lifecycle_apply(
    PDO $pdo,
    array $candidate,
    int $actorUserId,
    array $rawConfig,
    ?callable $fault = null,
    ?callable $guard = null,
    int $retryAttempt = 0,
): array {
    $config = managed_customer_lifecycle_config($rawConfig);
    if ($config['enabled'] !== true) {
        throw new ManagedCustomerLifecycleGateException('Managed-customer lifecycle is disabled.');
    }
    if ($pdo->inTransaction()) {
        throw new ManagedCustomerLifecycleGateException('Lifecycle containment requires its own transaction.');
    }
    $customerId = (string)($candidate['customer_id'] ?? '');
    try {
        $customerId = managed_customer_activation_uuid($customerId);
    } catch (ManagedCustomerActivationException $error) {
        throw new ManagedCustomerLifecycleValidationException('Lifecycle customer UUID is invalid.', 0, $error);
    }
    if (!in_array($customerId, $config['customer_ids'], true)) {
        throw new ManagedCustomerLifecycleGateException('The exact managed customer is not allowlisted.');
    }
    $candidateBindingId = (int)($candidate['binding_id'] ?? 0);
    $candidateTenantId = (int)($candidate['tenant_id'] ?? 0);
    $candidateClientId = (int)($candidate['client_id'] ?? 0);
    $candidateSourceVersion = (int)($candidate['source_version'] ?? 0);
    $candidateTenantSlug = (string)($candidate['provider_tenant_slug'] ?? '');
    if ($candidateBindingId < 1 || $candidateTenantId < 1 || $candidateClientId < 1
        || $candidateSourceVersion < 1
        || ($config['tenant_actors'][$candidateTenantSlug] ?? null) !== $actorUserId
    ) {
        throw new ManagedCustomerLifecycleGateException(
            'Lifecycle candidate or exact provider actor does not match the allowlist.',
        );
    }
    $scheduleKey = managed_customer_activation_schedule_key($customerId);

    $pdo->beginTransaction();
    try {
        // Match activation/report lock order: provider tenant, actor, source,
        // then portal and report rows. Read-only ownership rows use shared
        // locks so the narrow runtime never needs write authority over them;
        // the portal row/gap remains the exclusive per-client lifecycle lock.
        $tenant = $pdo->prepare(
            'SELECT id FROM tenants WHERE id = ? AND slug = ?'
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $tenant->execute([$candidateTenantId, $candidateTenantSlug]);
        if ((int)$tenant->fetchColumn() !== $candidateTenantId) {
            throw new ManagedCustomerLifecycleGateException(
                'The exact lifecycle provider tenant changed.',
            );
        }
        $actorQuery = $pdo->prepare(
            "SELECT id FROM users
              WHERE tenant_id = ? AND id = ? AND is_active = 1
                AND role IN ('owner','admin')"
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $actorQuery->execute([$candidateTenantId, $actorUserId]);
        if ((int)$actorQuery->fetchColumn() !== $actorUserId) {
            throw new ManagedCustomerLifecycleGateException(
                'Lifecycle actor must be an active owner or admin in the exact provider tenant.',
            );
        }
        if ($guard !== null) $guard('authority');

        $sourceSql = "SELECT binding.id AS binding_id, binding.tenant_id, binding.client_id,
                             binding.customer_id, binding.source_version, binding.status,
                             binding.last_event_id, binding.last_request_sha256,
                             tenant.slug AS provider_tenant_slug,
                             receipt.id AS source_event_receipt_id,
                             receipt.event_id AS source_event_id,
                             receipt.request_sha256 AS source_request_sha256
                        FROM suite_customer_sync_bindings binding
                        JOIN tenants tenant ON tenant.id = binding.tenant_id
                        JOIN suite_customer_sync_events receipt
                          ON receipt.tenant_id = binding.tenant_id
                         AND receipt.binding_id = binding.id
                         AND receipt.event_id = binding.last_event_id
                         AND receipt.customer_id = binding.customer_id
                         AND receipt.client_id = binding.client_id
                         AND receipt.source_version = binding.source_version
                         AND receipt.status = binding.status
                         AND receipt.request_sha256 = binding.last_request_sha256
                       WHERE binding.id = ? AND binding.tenant_id = ?
                         AND binding.client_id = ? AND binding.customer_id = ?"
            . managed_customer_lifecycle_lock_suffix($pdo, 'share');
        $sourceQuery = $pdo->prepare($sourceSql);
        $sourceQuery->execute([
            $candidateBindingId, $candidateTenantId, $candidateClientId, $customerId,
        ]);
        $source = $sourceQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source)
            || (int)$source['source_version'] !== $candidateSourceVersion
            || !hash_equals((string)$source['provider_tenant_slug'], $candidateTenantSlug)
            || !hash_equals((string)$source['last_event_id'], (string)($candidate['last_event_id'] ?? ''))
            || !hash_equals((string)$source['last_request_sha256'], (string)($candidate['last_request_sha256'] ?? ''))
        ) {
            throw new ManagedCustomerLifecycleConflictException(
                'The managed-customer source advanced before containment.',
            );
        }

        $sourceStatus = (string)$source['status'];
        $inactiveQuery = $pdo->prepare(
            "SELECT COUNT(*) FROM suite_customer_sync_events
              WHERE tenant_id = ? AND binding_id = ? AND status = 'inactive'"
        );
        $inactiveQuery->execute([$candidateTenantId, $candidateBindingId]);
        $hasInactiveHistory = (int)$inactiveQuery->fetchColumn() > 0;
        if ($sourceStatus === 'inactive') {
            $action = 'contained';
        } elseif ($sourceStatus === 'active' && $hasInactiveHistory) {
            $action = 'reactivation_blocked';
        } else {
            throw new ManagedCustomerLifecycleGateException(
                'An active customer with no inactive history needs no containment.',
            );
        }

        $existing = managed_customer_lifecycle_existing_receipt(
            $pdo,
            $customerId,
            $candidateSourceVersion,
            $action,
        );
        if (is_array($existing)) {
            if (!managed_customer_lifecycle_receipt_valid($existing)) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Existing lifecycle evidence is invalid.',
                );
            }
            $pdo->commit();
            return [
                'action' => 'replayed',
                'lifecycle_action' => $action,
                'customer_id_sha256' => hash('sha256', $customerId),
                'source_version' => $candidateSourceVersion,
                'evidence_sha256' => (string)$existing['evidence_sha256'],
            ];
        }

        $reason = ($action === 'contained'
            ? 'Contain inactive Milepost-managed customer '
            : 'Keep post-inactive Milepost reactivation blocked ')
            . hash('sha256', $customerId)
            . ' source-version=' . $candidateSourceVersion;

        $portalQuery = $pdo->prepare(
            'SELECT * FROM customer_portal_bindings
              WHERE tenant_id = ? AND client_id = ?'
            . managed_customer_lifecycle_lock_suffix($pdo)
        );
        $portalQuery->execute([$candidateTenantId, $candidateClientId]);
        $portal = $portalQuery->fetch(PDO::FETCH_ASSOC);
        $portalBindingId = is_array($portal) ? (int)$portal['id'] : null;
        $portalWasActive = is_array($portal) && (string)$portal['status'] === 'active' ? 1 : 0;
        if (is_array($portal) && !in_array((string)$portal['status'], ['active', 'disabled'], true)) {
            throw new ManagedCustomerLifecycleConflictException('Portal binding state is invalid.');
        }
        $portalBeforeEventId = null;
        if ($portalBindingId !== null) {
            $beforeEvent = $pdo->prepare(
                'SELECT id FROM customer_portal_binding_events
                  WHERE tenant_id = ? AND client_id = ? AND binding_id = ?
                  ORDER BY id DESC LIMIT 1'
                . managed_customer_lifecycle_lock_suffix($pdo, 'share')
            );
            $beforeEvent->execute([$candidateTenantId, $candidateClientId, $portalBindingId]);
            $portalBeforeEventId = (int)$beforeEvent->fetchColumn();
            if ($portalBeforeEventId < 1) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Portal lifecycle evidence is missing before containment.',
                );
            }
        }
        if ($guard !== null) $guard('write');
        $portalDisabledEventId = null;
        if ($portalWasActive === 1) {
            $update = managed_customer_lifecycle_write(
                $pdo,
                "UPDATE customer_portal_bindings
                    SET status = 'disabled', last_changed_by_user_id = ?, status_reason = ?
                  WHERE id = ? AND tenant_id = ? AND client_id = ? AND status = 'active'",
                [$actorUserId, $reason, $portalBindingId, $candidateTenantId, $candidateClientId],
            );
            if ($update->rowCount() !== 1) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Portal containment did not make one exact transition.',
                );
            }
        }
        if ($fault !== null) $fault('after_portal');

        $portalStateEventId = null;
        $portalStateSha256 = null;
        if ($portalBindingId !== null) {
            $portalEvent = $pdo->prepare(
                'SELECT id, snapshot_json FROM customer_portal_binding_events
                  WHERE tenant_id = ? AND client_id = ? AND binding_id = ?
                  ORDER BY id DESC LIMIT 1'
                . managed_customer_lifecycle_lock_suffix($pdo, 'share')
            );
            $portalEvent->execute([$candidateTenantId, $candidateClientId, $portalBindingId]);
            $event = $portalEvent->fetch(PDO::FETCH_ASSOC);
            if (!is_array($event) || !is_string($event['snapshot_json'] ?? null)) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Portal lifecycle evidence is missing.',
                );
            }
            $portalStateEventId = (int)$event['id'];
            $portalStateSha256 = hash('sha256', (string)$event['snapshot_json']);
            if ($portalWasActive === 1) $portalDisabledEventId = $portalStateEventId;
        }

        $latest = managed_customer_lifecycle_latest_schedule(
            $pdo,
            $candidateTenantId,
            $scheduleKey,
        );
        $scheduleWasActive = is_array($latest) && (string)$latest['status'] === 'active' ? 1 : 0;
        $scheduleActiveVersionId = $scheduleWasActive === 1 ? (int)$latest['id'] : null;
        if (is_array($latest) && !in_array((string)$latest['status'], ['active', 'disabled'], true)) {
            throw new ManagedCustomerLifecycleConflictException('Report schedule state is invalid.');
        }
        $scheduleDisabledVersionId = null;
        if ($scheduleWasActive === 1) {
            managed_customer_lifecycle_write(
                $pdo,
                "INSERT INTO business_report_schedule_versions
                    (tenant_id, schedule_key, version_no, definition_version_id,
                     client_id, recipient_email, schedule_timezone, delivery_weekday,
                     delivery_local_time, canary, status, created_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'disabled', ?, ?)",
                [
                    $candidateTenantId, $scheduleKey, (int)$latest['version_no'] + 1,
                    (int)$latest['definition_version_id'], $candidateClientId,
                    (string)$latest['recipient_email'], (string)$latest['schedule_timezone'],
                    (int)$latest['delivery_weekday'], (string)$latest['delivery_local_time'],
                    (int)$latest['canary'], $actorUserId, $reason,
                ],
            );
            $scheduleDisabledVersionId = (int)$pdo->lastInsertId();
            $scheduleQuery = $pdo->prepare(
                'SELECT * FROM business_report_schedule_versions WHERE tenant_id = ? AND id = ?'
            );
            $scheduleQuery->execute([$candidateTenantId, $scheduleDisabledVersionId]);
            $latest = $scheduleQuery->fetch(PDO::FETCH_ASSOC);
        }
        if ($fault !== null) $fault('after_schedule');

        $scheduleStateVersionId = is_array($latest) ? (int)$latest['id'] : null;
        $scheduleStateSha256 = is_array($latest)
            ? managed_customer_lifecycle_schedule_sha256($latest)
            : null;
        $facts = [
            'tenant_id' => $candidateTenantId,
            'client_id' => $candidateClientId,
            'source_binding_id' => $candidateBindingId,
            'customer_id' => $customerId,
            'source_event_receipt_id' => (int)$source['source_event_receipt_id'],
            'source_event_id' => (string)$source['source_event_id'],
            'source_version' => $candidateSourceVersion,
            'source_status' => $sourceStatus,
            'action' => $action,
            'portal_binding_id' => $portalBindingId,
            'portal_was_active' => $portalWasActive,
            'portal_before_event_id' => $portalBeforeEventId,
            'portal_state_event_id' => $portalStateEventId,
            'portal_disabled_event_id' => $portalDisabledEventId,
            'portal_state_sha256' => $portalStateSha256,
            'schedule_key' => $scheduleKey,
            'schedule_was_active' => $scheduleWasActive,
            'schedule_active_version_id' => $scheduleActiveVersionId,
            'schedule_state_version_id' => $scheduleStateVersionId,
            'schedule_disabled_version_id' => $scheduleDisabledVersionId,
            'schedule_state_sha256' => $scheduleStateSha256,
            'actor_user_id' => $actorUserId,
            'source_request_sha256' => (string)$source['source_request_sha256'],
        ];
        $digest = managed_customer_lifecycle_receipt_sha256($facts);
        if ($guard !== null) $guard('write');
        if ($fault !== null) $fault('before_receipt');
        managed_customer_lifecycle_write(
            $pdo,
            'INSERT INTO managed_customer_lifecycle_receipts
                (tenant_id, client_id, source_binding_id, customer_id,
                 source_event_receipt_id, source_event_id, source_version,
                 source_status, action, portal_binding_id, portal_was_active,
                 portal_before_event_id, portal_state_event_id, portal_disabled_event_id,
                 portal_state_sha256, schedule_key, schedule_was_active,
                 schedule_active_version_id, schedule_state_version_id,
                 schedule_disabled_version_id, schedule_state_sha256, actor_user_id,
                 source_request_sha256, evidence_sha256)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [...array_values($facts), $digest],
        );
        if ($fault !== null) $fault('after_receipt');
        $pdo->commit();
        return [
            'action' => $action,
            'customer_id_sha256' => hash('sha256', $customerId),
            'source_version' => $candidateSourceVersion,
            'portal_disabled' => $portalWasActive,
            'report_schedule_disabled' => $scheduleWasActive,
            'evidence_sha256' => $digest,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (managed_customer_lifecycle_retryable_database_error($error)
            && $retryAttempt < 2
        ) {
            // Missing immutable-receipt keys are gap locked. A competing
            // worker can therefore be selected as MySQL's deadlock victim
            // even though one correct transaction commits. Retry the whole
            // transaction a hard-bounded two times; every retry rechecks the
            // current source and committed receipt before making any write.
            usleep(25_000 * ($retryAttempt + 1));
            return managed_customer_lifecycle_apply(
                $pdo,
                $candidate,
                $actorUserId,
                $rawConfig,
                $fault,
                $guard,
                $retryAttempt + 1,
            );
        }
        if ($error instanceof ManagedCustomerLifecycleException
            || $error instanceof BusinessReportException
        ) {
            throw $error;
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            throw new ManagedCustomerLifecycleConflictException(
                'Concurrent lifecycle containment committed conflicting evidence.',
                0,
                $error,
            );
        }
        throw $error;
    }
}

/** @param array<string,mixed> $facts */
function managed_customer_lifecycle_restore_receipt_sha256(array $facts): string
{
    $keys = [
        'tenant_id', 'client_id', 'source_binding_id', 'customer_id',
        'source_event_receipt_id', 'source_event_id', 'source_version',
        'source_request_sha256', 'id_customer_receipt_id',
        'id_customer_status', 'id_lifecycle_version', 'id_lifecycle_transition_id',
        'id_lifecycle_action', 'id_lifecycle_evidence_sha256',
        'id_identity_tenant_status', 'id_oauth_session_version',
        'id_lifecycle_owned', 'id_tenant_key', 'identity_tenant_slug',
        'contact_version', 'recipient_sha256', 'id_response_generated_at',
        'id_request_nonce_sha256', 'id_response_sha256',
        'portal_owner_receipt_id', 'portal_binding_id', 'portal_before_event_id',
        'portal_active_event_id', 'portal_restored', 'portal_state_sha256',
        'schedule_owner_receipt_id', 'schedule_key', 'schedule_before_version_id',
        'schedule_prepared_version_id', 'contact_snapshot_id',
        'schedule_active_version_id', 'schedule_restored', 'schedule_state_sha256',
        'actor_user_id',
    ];
    $lines = [MANAGED_CUSTOMER_LIFECYCLE_RESTORE_CONTEXT];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $facts)) {
            throw new ManagedCustomerLifecycleValidationException(
                'Lifecycle restoration receipt facts are incomplete.',
            );
        }
        $value = $facts[$key];
        if ($value === null) {
            $lines[] = '-';
        } elseif (is_int($value) || is_string($value)) {
            $lines[] = (string)$value;
        } else {
            throw new ManagedCustomerLifecycleValidationException(
                'Lifecycle restoration receipt facts are invalid.',
            );
        }
    }
    return hash('sha256', implode("\n", $lines));
}

/** @param array<string,mixed> $receipt */
function managed_customer_lifecycle_restore_receipt_valid(array $receipt): bool
{
    $facts = $receipt;
    unset($facts['id'], $facts['evidence_sha256'], $facts['created_at']);
    try {
        $expected = managed_customer_lifecycle_restore_receipt_sha256($facts);
    } catch (Throwable) {
        return false;
    }
    return is_string($receipt['evidence_sha256'] ?? null)
        && hash_equals($expected, (string)$receipt['evidence_sha256']);
}

/** @return array<string,mixed>|null */
function managed_customer_lifecycle_existing_restore_receipt(
    PDO $pdo,
    string $customerId,
    int $sourceVersion,
): ?array {
    $query = $pdo->prepare(
        'SELECT * FROM managed_customer_lifecycle_restore_receipts
          WHERE customer_id = ? AND source_version = ?'
        . managed_customer_lifecycle_lock_suffix($pdo, 'share')
    );
    $query->execute([$customerId, $sourceVersion]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string,mixed>|null */
function managed_customer_lifecycle_surface_owner(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    string $customerId,
    string $surface,
    int|string $surfaceId,
): ?array {
    if ($surface === 'portal') {
        $where = 'portal_was_active = 1 AND portal_binding_id = ?
                  AND portal_disabled_event_id IS NOT NULL';
    } elseif ($surface === 'schedule') {
        $where = 'schedule_was_active = 1 AND schedule_key = ?
                  AND schedule_disabled_version_id IS NOT NULL';
    } else {
        throw new ManagedCustomerLifecycleValidationException(
            'Lifecycle restoration surface is invalid.',
        );
    }
    $query = $pdo->prepare(
        "SELECT * FROM managed_customer_lifecycle_receipts
          WHERE tenant_id = ? AND client_id = ? AND customer_id = ? AND {$where}
          ORDER BY id DESC LIMIT 1" . managed_customer_lifecycle_lock_suffix($pdo, 'share')
    );
    $query->execute([$tenantId, $clientId, $customerId, $surfaceId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    if (!managed_customer_lifecycle_receipt_valid($row)) {
        throw new ManagedCustomerLifecycleConflictException(
            'Lifecycle surface ownership evidence is invalid.',
        );
    }
    return $row;
}

/**
 * Clear one exact post-inactive source latch using signed restored-only ID
 * evidence. Human/pre-existing holds remain unchanged, and an already-active
 * surface with no current containment owner is preserved without any write.
 * $guard has the same 'authority'/'write' stages as in managed_customer_lifecycle_apply().
 *
 * @return array<string,mixed>
 */
function managed_customer_lifecycle_restore(
    PDO $pdo,
    array $candidate,
    array $rawEvidence,
    int $actorUserId,
    array $rawLifecycleConfig,
    array $rawBusinessReportConfig,
    ?callable $fault = null,
    ?callable $guard = null,
    int $retryAttempt = 0,
): array {
    $config = managed_customer_lifecycle_config($rawLifecycleConfig);
    if ($config['enabled'] !== true || $config['restoration_enabled'] !== true) {
        throw new ManagedCustomerLifecycleGateException(
            'Managed-customer lifecycle restoration is disabled.',
        );
    }
    if ($pdo->inTransaction()) {
        throw new ManagedCustomerLifecycleGateException(
            'Lifecycle restoration requires its own transaction.',
        );
    }
    $evidence = managed_customer_lifecycle_restore_evidence($rawEvidence);
    $reportConfig = business_report_config($rawBusinessReportConfig);
    $customerId = (string)$evidence['customer_id'];
    $scheduleKey = managed_customer_activation_schedule_key($customerId);
    $candidateBindingId = (int)($candidate['binding_id'] ?? 0);
    $candidateTenantId = (int)($candidate['tenant_id'] ?? 0);
    $candidateClientId = (int)($candidate['client_id'] ?? 0);
    $candidateSourceVersion = (int)($candidate['source_version'] ?? 0);
    $candidateTenantSlug = (string)($candidate['provider_tenant_slug'] ?? '');
    if (!in_array($customerId, $config['customer_ids'], true)
        || $candidateBindingId < 1
        || $candidateTenantId < 1
        || $candidateClientId < 1
        || $candidateSourceVersion < 1
        || ($candidate['status'] ?? null) !== 'active'
        || !is_string($candidate['customer_id'] ?? null)
        || !hash_equals($customerId, (string)$candidate['customer_id'])
        || $candidateSourceVersion !== (int)$evidence['source_version']
        || !is_string($candidate['last_event_id'] ?? null)
        || !hash_equals((string)$evidence['customer_event_id'], (string)$candidate['last_event_id'])
        || ($config['tenant_actors'][$candidateTenantSlug] ?? null) !== $actorUserId
    ) {
        throw new ManagedCustomerLifecycleGateException(
            'Lifecycle restoration candidate, event, or allowlist does not match.',
        );
    }

    $pdo->beginTransaction();
    try {
        $tenant = $pdo->prepare(
            'SELECT id FROM tenants WHERE id = ? AND slug = ?'
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $tenant->execute([$candidateTenantId, $candidateTenantSlug]);
        if ((int)$tenant->fetchColumn() !== $candidateTenantId) {
            throw new ManagedCustomerLifecycleGateException(
                'The exact lifecycle restoration tenant changed.',
            );
        }
        $actor = $pdo->prepare(
            "SELECT id FROM users
              WHERE tenant_id = ? AND id = ? AND is_active = 1
                AND role IN ('owner','admin')"
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $actor->execute([$candidateTenantId, $actorUserId]);
        if ((int)$actor->fetchColumn() !== $actorUserId) {
            throw new ManagedCustomerLifecycleGateException(
                'Lifecycle restoration actor is not an active owner/admin.',
            );
        }
        if ($guard !== null) $guard('authority');

        $source = $pdo->prepare(
            "SELECT binding.id AS binding_id, binding.tenant_id, binding.client_id,
                    binding.customer_id, binding.source_version, binding.status,
                    binding.last_event_id, binding.last_request_sha256,
                    receipt.id AS source_event_receipt_id,
                    receipt.event_id AS source_event_id,
                    receipt.request_sha256 AS source_request_sha256
               FROM suite_customer_sync_bindings binding
               JOIN suite_customer_sync_events receipt
                 ON receipt.tenant_id = binding.tenant_id
                AND receipt.binding_id = binding.id
                AND receipt.event_id = binding.last_event_id
                AND receipt.customer_id = binding.customer_id
                AND receipt.client_id = binding.client_id
                AND receipt.source_version = binding.source_version
                AND receipt.status = binding.status
                AND receipt.request_sha256 = binding.last_request_sha256
              WHERE binding.id = ? AND binding.tenant_id = ?
                AND binding.client_id = ? AND binding.customer_id = ?"
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $source->execute([
            $candidateBindingId, $candidateTenantId, $candidateClientId, $customerId,
        ]);
        $current = $source->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)
            || (string)$current['status'] !== 'active'
            || (int)$current['source_version'] !== $candidateSourceVersion
            || !hash_equals((string)$current['source_event_id'], (string)$evidence['customer_event_id'])
            || !hash_equals((string)$current['last_event_id'], (string)$evidence['customer_event_id'])
        ) {
            throw new ManagedCustomerLifecycleGateException(
                'Signed lifecycle restoration does not match the exact current source event.',
            );
        }
        $inactive = $pdo->prepare(
            "SELECT COUNT(*) FROM suite_customer_sync_events
              WHERE tenant_id = ? AND binding_id = ? AND status = 'inactive'"
        );
        $inactive->execute([$candidateTenantId, $candidateBindingId]);
        if ((int)$inactive->fetchColumn() < 1) {
            throw new ManagedCustomerLifecycleGateException(
                'Lifecycle restoration requires immutable inactive history.',
            );
        }

        $existing = managed_customer_lifecycle_existing_restore_receipt(
            $pdo,
            $customerId,
            $candidateSourceVersion,
        );
        if (is_array($existing)) {
            if (!managed_customer_lifecycle_restore_receipt_valid($existing)
                || !hash_equals((string)$existing['source_event_id'], (string)$evidence['customer_event_id'])
                || !hash_equals((string)$existing['id_customer_receipt_id'], (string)$evidence['customer_receipt_id'])
                || !hash_equals((string)$existing['id_customer_status'], (string)$evidence['customer_status'])
                || (int)$existing['id_lifecycle_version'] !== (int)$evidence['lifecycle_version']
                || (int)$existing['id_lifecycle_transition_id'] !== (int)$evidence['lifecycle_transition_id']
                || !hash_equals((string)$existing['id_lifecycle_action'], (string)$evidence['lifecycle_action'])
                || !hash_equals((string)$existing['id_lifecycle_evidence_sha256'], (string)$evidence['lifecycle_evidence_sha256'])
                || !hash_equals((string)$existing['id_identity_tenant_status'], (string)$evidence['identity_tenant_status'])
                || (int)$existing['id_oauth_session_version'] !== (int)$evidence['identity_oauth_session_version']
                || (int)$existing['id_lifecycle_owned'] !== (int)$evidence['lifecycle_owned']
                || !hash_equals((string)$existing['id_tenant_key'], (string)$evidence['tenant_key'])
                || !hash_equals((string)$existing['identity_tenant_slug'], (string)$evidence['tenant_slug'])
                || (int)$existing['contact_version'] !== (int)$evidence['contact_version']
                || !hash_equals((string)$existing['recipient_sha256'], hash('sha256', (string)$evidence['recipient_email']))
            ) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Existing restoration evidence conflicts with the signed current event.',
                );
            }
            // A retry uses a fresh nonce/timestamp and therefore a fresh
            // transport digest. Replay compares the immutable lifecycle,
            // source, tenant, and contact semantics above and retains the
            // originally committed signed transport receipt.
            $pdo->commit();
            return [
                'action' => 'restore_replayed',
                'customer_id_sha256' => hash('sha256', $customerId),
                'source_version' => $candidateSourceVersion,
                'portal_restored' => (int)$existing['portal_restored'],
                'report_schedule_restored' => (int)$existing['schedule_restored'],
                'evidence_sha256' => (string)$existing['evidence_sha256'],
            ];
        }

        $idBinding = $pdo->prepare(
            'SELECT * FROM business_report_id_client_bindings
              WHERE tenant_id = ? AND client_id = ?'
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $idBinding->execute([$candidateTenantId, $candidateClientId]);
        $idBindingRow = $idBinding->fetch(PDO::FETCH_ASSOC);
        if (!is_array($idBindingRow)
            || !hash_equals((string)$idBindingRow['id_tenant_key'], (string)$evidence['tenant_key'])
            || !hash_equals((string)$idBindingRow['id_tenant_slug'], (string)$evidence['tenant_slug'])
        ) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration ID tenant mapping is not exact.',
            );
        }

        $reason = 'Restore exact managed customer ' . hash('sha256', $customerId)
            . ' source-version=' . $candidateSourceVersion
            . ' lifecycle-transition=' . (int)$evidence['lifecycle_transition_id'];

        $portalQuery = $pdo->prepare(
            'SELECT * FROM customer_portal_bindings
              WHERE tenant_id = ? AND client_id = ?'
            . managed_customer_lifecycle_lock_suffix($pdo)
        );
        $portalQuery->execute([$candidateTenantId, $candidateClientId]);
        $portal = $portalQuery->fetch(PDO::FETCH_ASSOC);
        if (is_array($portal)
            && !hash_equals((string)$portal['identity_tenant_slug'], (string)$evidence['tenant_slug'])
        ) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration portal identity mapping changed.',
            );
        }
        if (is_array($portal) && !in_array((string)$portal['status'], ['active', 'disabled'], true)) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration portal state is invalid.',
            );
        }
        $portalBindingId = is_array($portal) ? (int)$portal['id'] : null;
        $portalBeforeEventId = null;
        $portalStateSha256 = null;
        $portalOwner = null;
        $portalCanRestore = false;
        $portalPreservedActive = false;
        if ($portalBindingId !== null) {
            $portalEventQuery = $pdo->prepare(
                'SELECT id, snapshot_json FROM customer_portal_binding_events
                  WHERE tenant_id = ? AND client_id = ? AND binding_id = ?
                  ORDER BY id DESC LIMIT 1'
                . managed_customer_lifecycle_lock_suffix($pdo, 'share')
            );
            $portalEventQuery->execute([$candidateTenantId, $candidateClientId, $portalBindingId]);
            $portalEvent = $portalEventQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($portalEvent) || !is_string($portalEvent['snapshot_json'] ?? null)) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration portal audit is missing.',
                );
            }
            $portalBeforeEventId = (int)$portalEvent['id'];
            $portalStateSha256 = hash('sha256', (string)$portalEvent['snapshot_json']);
            $portalOwner = managed_customer_lifecycle_surface_owner(
                $pdo,
                $candidateTenantId,
                $candidateClientId,
                $customerId,
                'portal',
                $portalBindingId,
            );
            // Current ownership: the containment's disabled audit is still the
            // latest audit, with the same hash. An older, superseded containment
            // receipt is history, not authority over a later state.
            $portalCurrentlyOwned = is_array($portalOwner)
                && (int)$portalOwner['portal_state_event_id'] === $portalBeforeEventId
                && hash_equals((string)$portalOwner['portal_state_sha256'], $portalStateSha256);
            $portalPreservedActive = (string)$portal['status'] === 'active';
            if ($portalPreservedActive && $portalCurrentlyOwned) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration portal ownership is ambiguous.',
                );
            }
            $portalCanRestore = $portalCurrentlyOwned;
        }

        $latestSchedule = managed_customer_lifecycle_latest_schedule(
            $pdo,
            $candidateTenantId,
            $scheduleKey,
        );
        if (is_array($latestSchedule)
            && !in_array((string)$latestSchedule['status'], ['active', 'disabled'], true)
        ) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration report schedule state is invalid.',
            );
        }
        $scheduleBeforeId = is_array($latestSchedule) ? (int)$latestSchedule['id'] : null;
        $scheduleStateSha256 = is_array($latestSchedule)
            ? managed_customer_lifecycle_schedule_sha256($latestSchedule)
            : null;
        $scheduleOwner = is_array($latestSchedule)
            ? managed_customer_lifecycle_surface_owner(
                $pdo,
                $candidateTenantId,
                $candidateClientId,
                $customerId,
                'schedule',
                $scheduleKey,
            )
            : null;
        $scheduleCurrentlyOwned = is_array($scheduleOwner)
            && (int)$scheduleOwner['schedule_state_version_id'] === $scheduleBeforeId
            && hash_equals((string)$scheduleOwner['schedule_state_sha256'], (string)$scheduleStateSha256);
        $schedulePreservedActive = is_array($latestSchedule)
            && (string)$latestSchedule['status'] === 'active';
        if ($schedulePreservedActive && $scheduleCurrentlyOwned) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration report schedule ownership is ambiguous.',
            );
        }
        $scheduleCanRestore = $scheduleCurrentlyOwned;

        if ($guard !== null) $guard('write');
        $portalActiveEventId = null;
        if ($portalCanRestore) {
            $portalUpdate = managed_customer_lifecycle_write(
                $pdo,
                "UPDATE customer_portal_bindings
                    SET status='active', last_changed_by_user_id=?, status_reason=?
                  WHERE id=? AND tenant_id=? AND client_id=?
                    AND identity_tenant_slug=? AND status='disabled'",
                [
                    $actorUserId, $reason, $portalBindingId, $candidateTenantId,
                    $candidateClientId, $evidence['tenant_slug'],
                ],
            );
            if ($portalUpdate->rowCount() !== 1) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration portal transition was not exact.',
                );
            }
            $portalActive = $pdo->prepare(
                'SELECT id FROM customer_portal_binding_events
                  WHERE tenant_id=? AND client_id=? AND binding_id=?
                  ORDER BY id DESC LIMIT 1'
            );
            $portalActive->execute([$candidateTenantId, $candidateClientId, $portalBindingId]);
            $portalActiveEventId = (int)$portalActive->fetchColumn();
        }
        if ($fault !== null) $fault('after_restore_portal');

        $schedulePreparedId = null;
        $contactSnapshotId = null;
        $scheduleActiveId = null;
        if ($scheduleCanRestore && is_array($latestSchedule)) {
            $scope = business_report_contact_scope_for_key(
                $pdo,
                $candidateTenantId,
                $scheduleKey,
            );
            if (!is_array($scope)
                || !hash_equals(BUSINESS_REPORT_CONTACT_SCOPE_CLIENT, (string)$scope['scope'])
            ) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration report contact scope is not client ID.',
                );
            }
            $contactHistory = $pdo->prepare(
                'SELECT contact_version, recipient_email
                   FROM business_report_id_client_contact_snapshots
                  WHERE tenant_id=? AND client_id=? AND id_tenant_key=?
                  ORDER BY contact_version DESC, id DESC LIMIT 1'
                . managed_customer_lifecycle_lock_suffix($pdo, 'share')
            );
            $contactHistory->execute([
                $candidateTenantId, $candidateClientId, $evidence['tenant_key'],
            ]);
            $history = $contactHistory->fetch(PDO::FETCH_ASSOC);
            if (is_array($history)
                && (int)$history['contact_version'] > (int)$evidence['contact_version']
            ) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration report contact evidence is stale.',
                );
            }
            if (is_array($history)
                && (int)$history['contact_version'] === (int)$evidence['contact_version']
                && !hash_equals((string)$history['recipient_email'], (string)$evidence['recipient_email'])
            ) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration contact version conflicts.',
                );
            }
            $gateSchedule = $latestSchedule;
            $gateSchedule['tenant_slug'] = $candidateTenantSlug;
            $gateSchedule['recipient_email'] = $evidence['recipient_email'];
            business_report_assert_schedule_gate($gateSchedule, $reportConfig, 'dry_run');

            managed_customer_lifecycle_write(
                $pdo,
                "INSERT INTO business_report_schedule_versions
                    (tenant_id,schedule_key,version_no,definition_version_id,
                     client_id,recipient_email,schedule_timezone,delivery_weekday,
                     delivery_local_time,canary,status,created_by_user_id,reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'disabled',?,?)",
                [
                    $candidateTenantId, $scheduleKey, (int)$latestSchedule['version_no'] + 1,
                    (int)$latestSchedule['definition_version_id'], $candidateClientId,
                    $evidence['recipient_email'], $latestSchedule['schedule_timezone'],
                    (int)$latestSchedule['delivery_weekday'], $latestSchedule['delivery_local_time'],
                    (int)$latestSchedule['canary'], $actorUserId, $reason,
                ],
            );
            $schedulePreparedId = (int)$pdo->lastInsertId();
            managed_customer_lifecycle_write(
                $pdo,
                'INSERT INTO business_report_id_client_contact_snapshots
                    (tenant_id,client_id,schedule_version_id,id_tenant_key,
                     contact_version,recipient_email,response_generated_at,
                     request_nonce_sha256,response_sha256,created_by_user_id,reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $candidateTenantId, $candidateClientId, $schedulePreparedId,
                    $evidence['tenant_key'], (int)$evidence['contact_version'],
                    $evidence['recipient_email'], $evidence['generated_at_db'],
                    $evidence['request_nonce_sha256'], $evidence['response_sha256'],
                    $actorUserId, $reason,
                ],
            );
            $contactSnapshotId = (int)$pdo->lastInsertId();
            if ($fault !== null) $fault('after_restore_contact');
            managed_customer_lifecycle_write(
                $pdo,
                "INSERT INTO business_report_schedule_versions
                    (tenant_id,schedule_key,version_no,definition_version_id,
                     client_id,recipient_email,schedule_timezone,delivery_weekday,
                     delivery_local_time,canary,status,created_by_user_id,reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'active',?,?)",
                [
                    $candidateTenantId, $scheduleKey, (int)$latestSchedule['version_no'] + 2,
                    (int)$latestSchedule['definition_version_id'], $candidateClientId,
                    $evidence['recipient_email'], $latestSchedule['schedule_timezone'],
                    (int)$latestSchedule['delivery_weekday'], $latestSchedule['delivery_local_time'],
                    (int)$latestSchedule['canary'], $actorUserId, $reason,
                ],
            );
            $scheduleActiveId = (int)$pdo->lastInsertId();
        }
        if ($fault !== null) $fault('after_restore_schedule');

        $facts = [
            'tenant_id' => $candidateTenantId,
            'client_id' => $candidateClientId,
            'source_binding_id' => $candidateBindingId,
            'customer_id' => $customerId,
            'source_event_receipt_id' => (int)$current['source_event_receipt_id'],
            'source_event_id' => (string)$current['source_event_id'],
            'source_version' => $candidateSourceVersion,
            'source_request_sha256' => (string)$current['source_request_sha256'],
            'id_customer_receipt_id' => (string)$evidence['customer_receipt_id'],
            'id_customer_status' => (string)$evidence['customer_status'],
            'id_lifecycle_version' => (int)$evidence['lifecycle_version'],
            'id_lifecycle_transition_id' => (int)$evidence['lifecycle_transition_id'],
            'id_lifecycle_action' => (string)$evidence['lifecycle_action'],
            'id_lifecycle_evidence_sha256' => (string)$evidence['lifecycle_evidence_sha256'],
            'id_identity_tenant_status' => (string)$evidence['identity_tenant_status'],
            'id_oauth_session_version' => (int)$evidence['identity_oauth_session_version'],
            'id_lifecycle_owned' => (int)$evidence['lifecycle_owned'],
            'id_tenant_key' => (string)$evidence['tenant_key'],
            'identity_tenant_slug' => (string)$evidence['tenant_slug'],
            'contact_version' => (int)$evidence['contact_version'],
            'recipient_sha256' => hash('sha256', (string)$evidence['recipient_email']),
            'id_response_generated_at' => (string)$evidence['generated_at_db'],
            'id_request_nonce_sha256' => (string)$evidence['request_nonce_sha256'],
            'id_response_sha256' => (string)$evidence['response_sha256'],
            // A preserved active surface has no applicable owner to record.
            'portal_owner_receipt_id' => is_array($portalOwner) && !$portalPreservedActive
                ? (int)$portalOwner['id'] : null,
            'portal_binding_id' => $portalBindingId,
            'portal_before_event_id' => $portalBeforeEventId,
            'portal_active_event_id' => $portalActiveEventId,
            'portal_restored' => $portalCanRestore ? 1 : 0,
            'portal_state_sha256' => $portalStateSha256,
            'schedule_owner_receipt_id' => is_array($scheduleOwner) && !$schedulePreservedActive
                ? (int)$scheduleOwner['id'] : null,
            'schedule_key' => $scheduleKey,
            'schedule_before_version_id' => $scheduleBeforeId,
            'schedule_prepared_version_id' => $schedulePreparedId,
            'contact_snapshot_id' => $contactSnapshotId,
            'schedule_active_version_id' => $scheduleActiveId,
            'schedule_restored' => $scheduleCanRestore ? 1 : 0,
            'schedule_state_sha256' => $scheduleStateSha256,
            'actor_user_id' => $actorUserId,
        ];
        $digest = managed_customer_lifecycle_restore_receipt_sha256($facts);
        if ($guard !== null) $guard('write');
        if ($fault !== null) $fault('before_restore_receipt');
        managed_customer_lifecycle_write(
            $pdo,
            'INSERT INTO managed_customer_lifecycle_restore_receipts
                (tenant_id,client_id,source_binding_id,customer_id,
                 source_event_receipt_id,source_event_id,source_version,source_request_sha256,
                 id_customer_receipt_id,id_customer_status,id_lifecycle_version,
                 id_lifecycle_transition_id,id_lifecycle_action,
                 id_lifecycle_evidence_sha256,id_identity_tenant_status,
                 id_oauth_session_version,id_lifecycle_owned,id_tenant_key,
                 identity_tenant_slug,contact_version,recipient_sha256,
                 id_response_generated_at,id_request_nonce_sha256,id_response_sha256,
                 portal_owner_receipt_id,portal_binding_id,portal_before_event_id,
                 portal_active_event_id,portal_restored,portal_state_sha256,
                 schedule_owner_receipt_id,schedule_key,schedule_before_version_id,
                 schedule_prepared_version_id,contact_snapshot_id,schedule_active_version_id,
                 schedule_restored,schedule_state_sha256,actor_user_id,evidence_sha256)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [...array_values($facts), $digest],
        );
        if ($fault !== null) $fault('after_restore_receipt');
        $pdo->commit();
        return [
            'action' => 'restored',
            'customer_id_sha256' => hash('sha256', $customerId),
            'source_version' => $candidateSourceVersion,
            'portal_restored' => $portalCanRestore ? 1 : 0,
            'report_schedule_restored' => $scheduleCanRestore ? 1 : 0,
            'evidence_sha256' => $digest,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (managed_customer_lifecycle_retryable_database_error($error)
            && $retryAttempt < 2
        ) {
            usleep(25_000 * ($retryAttempt + 1));
            return managed_customer_lifecycle_restore(
                $pdo,
                $candidate,
                $rawEvidence,
                $actorUserId,
                $rawLifecycleConfig,
                $rawBusinessReportConfig,
                $fault,
                $guard,
                $retryAttempt + 1,
            );
        }
        if ($error instanceof ManagedCustomerLifecycleException
            || $error instanceof BusinessReportException
        ) {
            throw $error;
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            throw new ManagedCustomerLifecycleConflictException(
                'Concurrent lifecycle restoration committed conflicting evidence.',
                0,
                $error,
            );
        }
        throw $error;
    }
}

/**
 * Whether migration 034's identity-only restore columns are installed. Only a positive answer is
 * cached, for the life of that connection object.
 */
function managed_customer_lifecycle_identity_restore_available(PDO $pdo): bool
{
    static $installed = null;
    $installed ??= new WeakMap();
    if (isset($installed[$pdo])) return true;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $available = false;
        foreach ($pdo->query('PRAGMA table_info(managed_customer_lifecycle_restore_receipts)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === 'id_proof_scope') $available = true;
        }
    } else {
        $available = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'managed_customer_lifecycle_restore_receipts'
                AND column_name = 'id_proof_scope'"
        )->fetchColumn() === 1;
    }
    if ($available) $installed[$pdo] = true;
    return $available;
}

/**
 * The closed identity-only digest domain (A88/A95), in the exact field order of the 034 validator.
 * Only the portal and schedule-state facts may be NULL ('-').
 *
 * @param array<string,mixed> $facts
 */
function managed_customer_lifecycle_identity_restore_receipt_sha256(array $facts): string
{
    $keys = [
        'tenant_id', 'client_id', 'source_binding_id', 'customer_id',
        'source_event_receipt_id', 'source_event_id', 'source_version', 'source_request_sha256',
        'id_proof_scope', 'id_customer_receipt_id', 'id_initial_receipt_id',
        'id_customer_status', 'id_lifecycle_version', 'id_lifecycle_transition_id',
        'id_lifecycle_action', 'id_lifecycle_evidence_sha256', 'id_identity_tenant_status',
        'id_oauth_session_version', 'id_lifecycle_owned', 'id_projection_tenant_key',
        'identity_tenant_slug', 'id_provider_tenant_slug', 'id_issuer_suite_tenant_id',
        'id_projection_binding_sha256', 'id_restore_anchor_source_version',
        'id_restore_anchor_receipt_id', 'id_restore_anchor_event_id',
        'id_restore_anchor_transition_id', 'id_restore_anchor_evidence_sha256',
        'id_response_generated_at', 'id_request_nonce_sha256', 'id_response_sha256',
        'portal_owner_receipt_id', 'portal_binding_id', 'portal_before_event_id',
        'portal_active_event_id', 'portal_restored', 'portal_state_sha256',
        'schedule_key', 'schedule_before_version_id', 'schedule_restored',
        'schedule_state_sha256', 'actor_user_id',
    ];
    $nullable = [
        'portal_owner_receipt_id', 'portal_binding_id', 'portal_before_event_id', 'portal_active_event_id',
        'portal_state_sha256', 'schedule_before_version_id', 'schedule_state_sha256',
    ];
    $lines = [MANAGED_CUSTOMER_LIFECYCLE_IDENTITY_RESTORE_CONTEXT];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $facts)) {
            throw new ManagedCustomerLifecycleValidationException(
                'Identity-only restoration receipt facts are incomplete.',
            );
        }
        $value = $facts[$key];
        if ($value === null && in_array($key, $nullable, true)) {
            $lines[] = '-';
        } elseif (is_int($value) || is_string($value)) {
            $lines[] = (string)$value;
        } else {
            throw new ManagedCustomerLifecycleValidationException(
                'Identity-only restoration receipt facts are invalid.',
            );
        }
    }
    return hash('sha256', implode("\n", $lines));
}

/** @param array<string,mixed> $receipt */
function managed_customer_lifecycle_identity_restore_receipt_valid(array $receipt): bool
{
    if (($receipt['id_proof_scope'] ?? null) !== 'identity_lifecycle_only') return false;
    foreach (['id_tenant_key', 'contact_version', 'recipient_sha256', 'schedule_owner_receipt_id',
        'schedule_prepared_version_id', 'contact_snapshot_id', 'schedule_active_version_id'] as $absent) {
        if (($receipt[$absent] ?? null) !== null) return false;
    }
    $facts = $receipt;
    unset($facts['id'], $facts['evidence_sha256'], $facts['created_at']);
    try {
        $expected = managed_customer_lifecycle_identity_restore_receipt_sha256($facts);
    } catch (Throwable) {
        return false;
    }
    return (int)($receipt['schedule_restored'] ?? -1) === 0
        && is_string($receipt['evidence_sha256'] ?? null)
        && hash_equals($expected, (string)$receipt['evidence_sha256']);
}

/**
 * The house issuer from the nonsecret id_report_contacts.tenant_bindings slug mapping, or null.
 *
 * @param array<string,mixed>|null $houseBindings
 */
function managed_customer_lifecycle_house_issuer(?array $houseBindings): ?int
{
    $key = $houseBindings['8west'] ?? null;
    if (!is_string($key) || preg_match('/\Aewid-t([1-9][0-9]{0,9})\z/D', $key, $match) !== 1) return null;
    $issuer = (int)$match[1];
    return $issuer <= 4_294_967_295 ? $issuer : null;
}

/**
 * Clear one exact post-inactive source latch with the signed schema-3 identity-only proof (A88/A95).
 *
 * It never reads or writes report contact data or report schedules. The schedule's actual latest
 * version and state hash are recorded with schedule_restored 0, so report presence, a human hold or
 * an owned-disabled report never blocks identity and is never released here; that release needs the
 * separate full schema-2 contact operation. A currently owned disabled portal is re-enabled under the
 * same ownership/current-hash rules as the legacy restore, and an absent portal stays absent.
 *
 * Authority (A151): the UUID is in the ORIGINAL approved lifecycle `customer_ids` scope of the
 * configuration passed here, acting as its approved `tenant_actors` actor; or the binding carries an
 * immutable admitted receipt, acting as the current managed provider's owner (or the house actor).
 * An approved legacy lineage whose frozen receipts carry no admission tag (Lifestyle) qualifies by
 * scope alone; its receipts are never retagged. Callers must pass the original configuration, never
 * a list fabricated around the claimed UUID.
 *
 * Under the action locks it also requires: the current active provider tenant and owner/admin actor;
 * the current issuer (the current managed provider for its slug, or the house slug mapping); a
 * complete contiguous local history 1..current; the signed anchor as the active version directly after
 * the latest inactive one; the A155 anchor consistency (a current `restored` is its own anchor in all
 * five facts, an `observed_active` anchor is strictly earlier); and an existing report-ID map, when
 * present, matching the signed key and slug.
 * $guard has the same 'authority'/'write' stages as in managed_customer_lifecycle_apply().
 *
 * @param array<string,mixed> $proof from managed_customer_id_identity_decode_response()
 * @param array<string,mixed>|null $houseBindings the id_report_contacts.tenant_bindings mapping
 * @return array<string,mixed>
 */
function managed_customer_lifecycle_identity_restore(
    PDO $pdo,
    array $candidate,
    array $proof,
    int $actorUserId,
    array $rawLifecycleConfig,
    ?array $houseBindings = null,
    ?callable $fault = null,
    ?callable $guard = null,
    int $retryAttempt = 0,
): array {
    $config = managed_customer_lifecycle_config($rawLifecycleConfig);
    if ($config['enabled'] !== true || $config['restoration_enabled'] !== true) {
        throw new ManagedCustomerLifecycleGateException(
            'Managed-customer lifecycle restoration is disabled.',
        );
    }
    if ($pdo->inTransaction()) {
        throw new ManagedCustomerLifecycleGateException(
            'Lifecycle restoration requires its own transaction.',
        );
    }
    $anchor = $proof['restoration_anchor'] ?? null;
    if (($proof['schema_version'] ?? null) !== 3
        || ($proof['proof_scope'] ?? null) !== 'identity_lifecycle_only'
        || ($proof['customer_status'] ?? null) !== 'active'
        || ($proof['lifecycle_version'] ?? null) !== 1
        || ($proof['identity_tenant_status'] ?? null) !== 'active'
        || ($proof['lifecycle_owned'] ?? null) !== false
        || !in_array($proof['lifecycle_action'] ?? null, ['restored', 'observed_active'], true)
        || !is_string($proof['customer_id'] ?? null)
        || !is_int($proof['source_version'] ?? null)
        || !is_int($proof['issuer_suite_tenant_id'] ?? null)
    ) {
        throw new ManagedCustomerLifecycleValidationException(
            'Authenticated ID identity-only proof is invalid.',
        );
    }
    if (!is_array($anchor)
        || array_keys($anchor) !== ['source_version', 'receipt_id', 'event_id', 'transition_id', 'evidence_sha256']
        || !is_int($anchor['source_version']) || $anchor['source_version'] < 2
        || $anchor['source_version'] > $proof['source_version']
        || !is_int($anchor['transition_id'])
        || !managed_customer_id_identity_anchor_consistent([
            'customer_source_version' => $proof['source_version'],
            'customer_receipt_id' => $proof['customer_receipt_id'] ?? null,
            'customer_event_id' => $proof['customer_event_id'] ?? null,
            'lifecycle_transition_id' => $proof['lifecycle_transition_id'] ?? null,
            'lifecycle_evidence_sha256' => $proof['lifecycle_evidence_sha256'] ?? null,
            'lifecycle_action' => $proof['lifecycle_action'],
        ], $anchor)
    ) {
        throw new ManagedCustomerLifecycleGateException(
            'A post-inactive customer requires an exact signed restoration anchor.',
        );
    }
    $customerId = managed_customer_activation_uuid((string)$proof['customer_id']);
    $scheduleKey = managed_customer_activation_schedule_key($customerId);
    $candidateBindingId = (int)($candidate['binding_id'] ?? 0);
    $candidateTenantId = (int)($candidate['tenant_id'] ?? 0);
    $candidateClientId = (int)($candidate['client_id'] ?? 0);
    $candidateSourceVersion = (int)($candidate['source_version'] ?? 0);
    $candidateTenantSlug = (string)($candidate['provider_tenant_slug'] ?? '');
    $houseIssuer = managed_customer_lifecycle_house_issuer($houseBindings);
    // The original approved scope, or else an admission proved under the locks below.
    $approved = in_array($customerId, $config['customer_ids'], true);
    if ($candidateBindingId < 1
        || $candidateTenantId < 1
        || $candidateClientId < 1
        || $candidateSourceVersion < 2
        || ($candidate['status'] ?? null) !== 'active'
        || !is_string($candidate['customer_id'] ?? null)
        || !hash_equals($customerId, (string)$candidate['customer_id'])
        || $candidateSourceVersion !== $proof['source_version']
        || !is_string($candidate['last_event_id'] ?? null)
        || !hash_equals((string)$proof['customer_event_id'], (string)$candidate['last_event_id'])
        || !hash_equals($candidateTenantSlug, (string)$proof['provider_tenant_slug'])
        || (($approved || $candidateTenantSlug === '8west')
            && ($config['tenant_actors'][$candidateTenantSlug] ?? null) !== $actorUserId)
        || ($candidateTenantSlug === '8west' && $houseIssuer !== $proof['issuer_suite_tenant_id'])
    ) {
        throw new ManagedCustomerLifecycleGateException(
            'Identity-only restoration candidate, event, provider, issuer or allowlist does not match.',
        );
    }

    $pdo->beginTransaction();
    try {
        $tenant = $pdo->prepare(
            'SELECT id FROM tenants WHERE id = ? AND slug = ?'
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $tenant->execute([$candidateTenantId, $candidateTenantSlug]);
        if ((int)$tenant->fetchColumn() !== $candidateTenantId) {
            throw new ManagedCustomerLifecycleGateException(
                'The exact lifecycle restoration tenant changed.',
            );
        }
        $actor = $pdo->prepare(
            "SELECT id FROM users
              WHERE tenant_id = ? AND id = ? AND is_active = 1
                AND role IN ('owner','admin')"
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $actor->execute([$candidateTenantId, $actorUserId]);
        if ((int)$actor->fetchColumn() !== $actorUserId) {
            throw new ManagedCustomerLifecycleGateException(
                'Lifecycle restoration actor is not an active owner/admin.',
            );
        }
        if ($guard !== null) $guard('authority');
        if ($candidateTenantSlug !== '8west') {
            // The current admitted provider, under this transaction's shared locks, never a cached one.
            // An admitted lineage acts as that provider's owner; an approved one as its approved actor.
            $provider = suite_managed_provider($pdo, $candidateTenantSlug, true);
            if (!is_array($provider)
                || (int)$provider['tenant_id'] !== $candidateTenantId
                || (!$approved && (int)$provider['owner_user_id'] !== $actorUserId)
                || (int)$provider['issuer_tenant_id'] !== $proof['issuer_suite_tenant_id']) {
                throw new ManagedCustomerLifecycleGateException(
                    'The identity-only restoration provider, owner or issuer is not current.',
                );
            }
        }

        $source = $pdo->prepare(
            "SELECT binding.id AS binding_id, binding.tenant_id, binding.client_id,
                    binding.customer_id, binding.source_version, binding.status,
                    binding.last_event_id, binding.last_request_sha256,
                    receipt.id AS source_event_receipt_id,
                    receipt.event_id AS source_event_id,
                    receipt.request_sha256 AS source_request_sha256
               FROM suite_customer_sync_bindings binding
               JOIN suite_customer_sync_events receipt
                 ON receipt.tenant_id = binding.tenant_id
                AND receipt.binding_id = binding.id
                AND receipt.event_id = binding.last_event_id
                AND receipt.customer_id = binding.customer_id
                AND receipt.client_id = binding.client_id
                AND receipt.source_version = binding.source_version
                AND receipt.status = binding.status
                AND receipt.request_sha256 = binding.last_request_sha256
              WHERE binding.id = ? AND binding.tenant_id = ?
                AND binding.client_id = ? AND binding.customer_id = ?"
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $source->execute([
            $candidateBindingId, $candidateTenantId, $candidateClientId, $customerId,
        ]);
        $current = $source->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)
            || (string)$current['status'] !== 'active'
            || (int)$current['source_version'] !== $candidateSourceVersion
            || !hash_equals((string)$current['source_event_id'], (string)$proof['customer_event_id'])
            || !hash_equals((string)$current['last_event_id'], (string)$proof['customer_event_id'])
        ) {
            throw new ManagedCustomerLifecycleGateException(
                'Signed identity-only restoration does not match the exact current source event.',
            );
        }

        // The complete local history 1..current, its immutable admission and the anchor (A95).
        $historyQuery = $pdo->prepare(
            'SELECT id, event_id, customer_id, client_id, source_version, status, admission
               FROM suite_customer_sync_events
              WHERE tenant_id = ? AND binding_id = ?
              ORDER BY source_version, id'
        );
        $historyQuery->execute([$candidateTenantId, $candidateBindingId]);
        $history = $historyQuery->fetchAll(PDO::FETCH_ASSOC);
        $admitted = false;
        $latestInactive = 0;
        foreach ($history as $index => $event) {
            if ((int)$event['source_version'] !== $index + 1
                || !hash_equals($customerId, (string)$event['customer_id'])
                || (int)$event['client_id'] !== $candidateClientId) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Local customer history is not complete and contiguous.',
                );
            }
            if ($event['admission'] !== null) $admitted = true;
            if ((string)$event['status'] === 'inactive') $latestInactive = $index + 1;
        }
        $currentEvent = $history === [] ? null : $history[array_key_last($history)];
        $anchorEvent = $history[$anchor['source_version'] - 1] ?? null;
        if (!is_array($currentEvent)
            || count($history) !== $candidateSourceVersion
            || (int)$currentEvent['id'] !== (int)$current['source_event_receipt_id']
            || (!$approved && !$admitted)
            || $latestInactive < 1
            || $anchor['source_version'] !== $latestInactive + 1
            || !is_array($anchorEvent)
            || (string)$anchorEvent['status'] !== 'active'
            || !hash_equals((string)$anchorEvent['event_id'], (string)$anchor['event_id'])
        ) {
            throw new ManagedCustomerLifecycleGateException(
                'The signed restoration anchor, approved scope or admission does not match local history.',
            );
        }

        $existing = managed_customer_lifecycle_existing_restore_receipt(
            $pdo,
            $customerId,
            $candidateSourceVersion,
        );
        if (is_array($existing)) {
            $same = static fn(string $column, mixed $value): bool =>
                is_string($existing[$column] ?? null) && is_string($value) && hash_equals($existing[$column], $value);
            if (!managed_customer_lifecycle_identity_restore_receipt_valid($existing)
                || !$same('source_event_id', (string)$proof['customer_event_id'])
                || !$same('id_customer_receipt_id', $proof['customer_receipt_id'] ?? null)
                || !$same('id_initial_receipt_id', $proof['initial_receipt_id'] ?? null)
                || (int)$existing['id_lifecycle_transition_id'] !== (int)($proof['lifecycle_transition_id'] ?? 0)
                || !$same('id_lifecycle_action', $proof['lifecycle_action'])
                || !$same('id_lifecycle_evidence_sha256', $proof['lifecycle_evidence_sha256'] ?? null)
                || (int)$existing['id_oauth_session_version'] !== (int)($proof['identity_oauth_session_version'] ?? 0)
                || !$same('id_projection_tenant_key', $proof['tenant_key'] ?? null)
                || !$same('identity_tenant_slug', $proof['tenant_slug'] ?? null)
                || !$same('id_provider_tenant_slug', $proof['provider_tenant_slug'] ?? null)
                || (int)$existing['id_issuer_suite_tenant_id'] !== $proof['issuer_suite_tenant_id']
                || !$same('id_projection_binding_sha256', $proof['projection_binding_sha256'] ?? null)
                || (int)$existing['id_restore_anchor_source_version'] !== $anchor['source_version']
                || !$same('id_restore_anchor_receipt_id', $anchor['receipt_id'])
                || !$same('id_restore_anchor_event_id', $anchor['event_id'])
                || (int)$existing['id_restore_anchor_transition_id'] !== (int)$anchor['transition_id']
                || !$same('id_restore_anchor_evidence_sha256', $anchor['evidence_sha256'])
            ) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Existing restoration evidence conflicts with the signed identity-only proof.',
                );
            }
            // As in the legacy replay, the originally committed signed transport receipt is retained.
            $pdo->commit();
            return [
                'action' => 'restore_replayed',
                'proof_scope' => 'identity_lifecycle_only',
                'customer_id_sha256' => hash('sha256', $customerId),
                'source_version' => $candidateSourceVersion,
                'portal_restored' => (int)$existing['portal_restored'],
                'report_schedule_restored' => 0,
                'evidence_sha256' => (string)$existing['evidence_sha256'],
            ];
        }

        // An existing local report-ID map is optional; when present it must match exactly.
        $idBinding = $pdo->prepare(
            'SELECT id_tenant_key, id_tenant_slug FROM business_report_id_client_bindings
              WHERE tenant_id = ? AND client_id = ?'
            . managed_customer_lifecycle_lock_suffix($pdo, 'share')
        );
        $idBinding->execute([$candidateTenantId, $candidateClientId]);
        foreach ($idBinding->fetchAll(PDO::FETCH_ASSOC) as $idBindingRow) {
            if (!hash_equals((string)$idBindingRow['id_tenant_key'], (string)$proof['tenant_key'])
                || !hash_equals((string)$idBindingRow['id_tenant_slug'], (string)$proof['tenant_slug'])) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Identity-only restoration report-ID map does not match the signed identity.',
                );
            }
        }

        $reason = 'Restore exact managed customer identity ' . hash('sha256', $customerId)
            . ' source-version=' . $candidateSourceVersion
            . ' lifecycle-transition=' . (int)$proof['lifecycle_transition_id'];

        $portalQuery = $pdo->prepare(
            'SELECT * FROM customer_portal_bindings
              WHERE tenant_id = ? AND client_id = ?'
            . managed_customer_lifecycle_lock_suffix($pdo)
        );
        $portalQuery->execute([$candidateTenantId, $candidateClientId]);
        $portal = $portalQuery->fetch(PDO::FETCH_ASSOC);
        if (is_array($portal)
            && !hash_equals((string)$portal['identity_tenant_slug'], (string)$proof['tenant_slug'])
        ) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration portal identity mapping changed.',
            );
        }
        if (is_array($portal) && !in_array((string)$portal['status'], ['active', 'disabled'], true)) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration portal state is invalid.',
            );
        }
        $portalBindingId = is_array($portal) ? (int)$portal['id'] : null;
        $portalBeforeEventId = null;
        $portalStateSha256 = null;
        $portalOwner = null;
        $portalCanRestore = false;
        $portalPreservedActive = false;
        if ($portalBindingId !== null) {
            $portalEventQuery = $pdo->prepare(
                'SELECT id, snapshot_json FROM customer_portal_binding_events
                  WHERE tenant_id = ? AND client_id = ? AND binding_id = ?
                  ORDER BY id DESC LIMIT 1'
                . managed_customer_lifecycle_lock_suffix($pdo, 'share')
            );
            $portalEventQuery->execute([$candidateTenantId, $candidateClientId, $portalBindingId]);
            $portalEvent = $portalEventQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($portalEvent) || !is_string($portalEvent['snapshot_json'] ?? null)) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration portal audit is missing.',
                );
            }
            $portalBeforeEventId = (int)$portalEvent['id'];
            $portalStateSha256 = hash('sha256', (string)$portalEvent['snapshot_json']);
            $portalOwner = managed_customer_lifecycle_surface_owner(
                $pdo,
                $candidateTenantId,
                $candidateClientId,
                $customerId,
                'portal',
                $portalBindingId,
            );
            $portalCurrentlyOwned = is_array($portalOwner)
                && (int)$portalOwner['portal_state_event_id'] === $portalBeforeEventId
                && hash_equals((string)$portalOwner['portal_state_sha256'], $portalStateSha256);
            $portalPreservedActive = (string)$portal['status'] === 'active';
            if ($portalPreservedActive && $portalCurrentlyOwned) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration portal ownership is ambiguous.',
                );
            }
            $portalCanRestore = $portalCurrentlyOwned;
        }

        // The schedule is read for its actual state only; identity-only never writes it.
        $latestSchedule = managed_customer_lifecycle_latest_schedule(
            $pdo,
            $candidateTenantId,
            $scheduleKey,
        );
        if (is_array($latestSchedule)
            && !in_array((string)$latestSchedule['status'], ['active', 'disabled'], true)
        ) {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration report schedule state is invalid.',
            );
        }
        $scheduleBeforeId = is_array($latestSchedule) ? (int)$latestSchedule['id'] : null;
        $scheduleStateSha256 = is_array($latestSchedule)
            ? managed_customer_lifecycle_schedule_sha256($latestSchedule)
            : null;
        if (is_array($latestSchedule) && (string)$latestSchedule['status'] === 'active') {
            $scheduleOwner = managed_customer_lifecycle_surface_owner(
                $pdo,
                $candidateTenantId,
                $candidateClientId,
                $customerId,
                'schedule',
                $scheduleKey,
            );
            if (is_array($scheduleOwner)
                && (int)$scheduleOwner['schedule_state_version_id'] === $scheduleBeforeId
                && hash_equals((string)$scheduleOwner['schedule_state_sha256'], (string)$scheduleStateSha256)) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration report schedule ownership is ambiguous.',
                );
            }
        }

        if ($guard !== null) $guard('write');

        $portalActiveEventId = null;
        if ($portalCanRestore) {
            $portalUpdate = managed_customer_lifecycle_write(
                $pdo,
                "UPDATE customer_portal_bindings
                    SET status='active', last_changed_by_user_id=?, status_reason=?
                  WHERE id=? AND tenant_id=? AND client_id=?
                    AND identity_tenant_slug=? AND status='disabled'",
                [
                    $actorUserId, $reason, $portalBindingId, $candidateTenantId,
                    $candidateClientId, $proof['tenant_slug'],
                ],
            );
            if ($portalUpdate->rowCount() !== 1) {
                throw new ManagedCustomerLifecycleConflictException(
                    'Lifecycle restoration portal transition was not exact.',
                );
            }
            $portalActive = $pdo->prepare(
                'SELECT id FROM customer_portal_binding_events
                  WHERE tenant_id=? AND client_id=? AND binding_id=?
                  ORDER BY id DESC LIMIT 1'
            );
            $portalActive->execute([$candidateTenantId, $candidateClientId, $portalBindingId]);
            $portalActiveEventId = (int)$portalActive->fetchColumn();
        }
        if ($fault !== null) $fault('after_restore_portal');

        $row = [
            'tenant_id' => $candidateTenantId,
            'client_id' => $candidateClientId,
            'source_binding_id' => $candidateBindingId,
            'customer_id' => $customerId,
            'source_event_receipt_id' => (int)$current['source_event_receipt_id'],
            'source_event_id' => (string)$current['source_event_id'],
            'source_version' => $candidateSourceVersion,
            'source_request_sha256' => (string)$current['source_request_sha256'],
            'id_proof_scope' => 'identity_lifecycle_only',
            'id_customer_receipt_id' => (string)$proof['customer_receipt_id'],
            'id_initial_receipt_id' => (string)$proof['initial_receipt_id'],
            'id_customer_status' => 'active',
            'id_lifecycle_version' => 1,
            'id_lifecycle_transition_id' => (int)$proof['lifecycle_transition_id'],
            'id_lifecycle_action' => (string)$proof['lifecycle_action'],
            'id_lifecycle_evidence_sha256' => (string)$proof['lifecycle_evidence_sha256'],
            'id_identity_tenant_status' => 'active',
            'id_oauth_session_version' => (int)$proof['identity_oauth_session_version'],
            'id_lifecycle_owned' => 0,
            'id_projection_tenant_key' => (string)$proof['tenant_key'],
            'identity_tenant_slug' => (string)$proof['tenant_slug'],
            'id_provider_tenant_slug' => (string)$proof['provider_tenant_slug'],
            'id_issuer_suite_tenant_id' => $proof['issuer_suite_tenant_id'],
            'id_projection_binding_sha256' => (string)$proof['projection_binding_sha256'],
            'id_restore_anchor_source_version' => $anchor['source_version'],
            'id_restore_anchor_receipt_id' => (string)$anchor['receipt_id'],
            'id_restore_anchor_event_id' => (string)$anchor['event_id'],
            'id_restore_anchor_transition_id' => (int)$anchor['transition_id'],
            'id_restore_anchor_evidence_sha256' => (string)$anchor['evidence_sha256'],
            'id_response_generated_at' => (string)$proof['generated_at_db'],
            'id_request_nonce_sha256' => (string)$proof['request_nonce_sha256'],
            'id_response_sha256' => (string)$proof['response_sha256'],
            'portal_owner_receipt_id' => is_array($portalOwner) && !$portalPreservedActive
                ? (int)$portalOwner['id'] : null,
            'portal_binding_id' => $portalBindingId,
            'portal_before_event_id' => $portalBeforeEventId,
            'portal_active_event_id' => $portalActiveEventId,
            'portal_restored' => $portalCanRestore ? 1 : 0,
            'portal_state_sha256' => $portalStateSha256,
            'schedule_key' => $scheduleKey,
            'schedule_before_version_id' => $scheduleBeforeId,
            'schedule_restored' => 0,
            'schedule_state_sha256' => $scheduleStateSha256,
            'actor_user_id' => $actorUserId,
        ];
        $digest = managed_customer_lifecycle_identity_restore_receipt_sha256($row);
        $row['evidence_sha256'] = $digest;
        if ($guard !== null) $guard('write');
        if ($fault !== null) $fault('before_restore_receipt');
        // The report-ID carrier, contact facts and every schedule write column stay NULL (034 CHECK).
        managed_customer_lifecycle_write(
            $pdo,
            'INSERT INTO managed_customer_lifecycle_restore_receipts ('
                . implode(',', array_keys($row)) . ') VALUES ('
                . implode(',', array_fill(0, count($row), '?')) . ')',
            array_values($row),
        );
        if ($fault !== null) $fault('after_restore_receipt');
        $pdo->commit();
        return [
            'action' => 'restored',
            'proof_scope' => 'identity_lifecycle_only',
            'customer_id_sha256' => hash('sha256', $customerId),
            'source_version' => $candidateSourceVersion,
            'portal_restored' => $portalCanRestore ? 1 : 0,
            'report_schedule_restored' => 0,
            'evidence_sha256' => $digest,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (managed_customer_lifecycle_retryable_database_error($error)
            && $retryAttempt < 2
        ) {
            usleep(25_000 * ($retryAttempt + 1));
            return managed_customer_lifecycle_identity_restore(
                $pdo,
                $candidate,
                $proof,
                $actorUserId,
                $rawLifecycleConfig,
                $houseBindings,
                $fault,
                $guard,
                $retryAttempt + 1,
            );
        }
        if ($error instanceof ManagedCustomerLifecycleException) {
            throw $error;
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            throw new ManagedCustomerLifecycleConflictException(
                'Concurrent lifecycle restoration committed conflicting evidence.',
                0,
                $error,
            );
        }
        throw $error;
    }
}

/** @return list<array<string,mixed>> */
function managed_customer_lifecycle_run(
    PDO $pdo,
    array $rawConfig,
    array $businessReportConfig = [],
    array $idConfig = [],
    ?callable $fetchEvidence = null,
): array
{
    $config = managed_customer_lifecycle_config($rawConfig);
    if ($config['enabled'] !== true) {
        throw new ManagedCustomerLifecycleGateException('Managed-customer lifecycle is disabled.');
    }
    $results = [];
    $completed = 0;
    foreach (managed_customer_lifecycle_candidates($pdo, $config) as $candidate) {
        $customerId = (string)($candidate['customer_id'] ?? '');
        $customerHash = preg_match(BUSINESS_REPORT_MILEPOST_CUSTOMER_UUID, $customerId) === 1
            ? hash('sha256', $customerId)
            : str_repeat('0', 64);
        try {
            $tenantSlug = (string)($candidate['provider_tenant_slug'] ?? '');
            $actorId = $config['tenant_actors'][$tenantSlug] ?? null;
            if (!is_int($actorId)) {
                throw new ManagedCustomerLifecycleGateException(
                    'No exact owner/admin actor is allowlisted for the provider tenant.',
                );
            }
            if (($candidate['status'] ?? null) === 'active'
                && $config['restoration_enabled'] === true
            ) {
                // Restore first, without containing: containment would disable
                // active surfaces that exact restoration preserves. Restore
                // validates the signed exact-current evidence before any
                // surface is read; a refusal leaves the latch and surfaces as-is.
                if ($fetchEvidence === null) {
                    if (!function_exists('managed_customer_id_evidence_fetch')) {
                        throw new ManagedCustomerLifecycleGateException(
                            'Authenticated ID restoration evidence adapter is unavailable.',
                        );
                    }
                    $fetchEvidence = 'managed_customer_id_evidence_fetch';
                }
                $evidence = $fetchEvidence($customerId, $idConfig);
                if (!is_array($evidence)) {
                    throw new ManagedCustomerLifecycleGateException(
                        'Authenticated ID restoration evidence is invalid.',
                    );
                }
                $results[] = managed_customer_lifecycle_restore(
                    $pdo,
                    $candidate,
                    $evidence,
                    $actorId,
                    $config,
                    $businessReportConfig,
                );
            } else {
                $results[] = managed_customer_lifecycle_apply(
                    $pdo,
                    $candidate,
                    $actorId,
                    $config,
                );
            }
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

// A82 `--scheduled`: one fair, bounded run over the explicit approved scope plus admitted lineages.
const MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_CLAIMS = 25;
const MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_TRANSITIONS = 10;
const MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_SECONDS = 30.0;

/**
 * The bounded scheduled run. Each claim commits the durable cursor before its customer is acted on,
 * so a crash, kill, timeout or refusal still advances consideration. A claim failure (including an
 * unknown cursor commit) stops the run with no action. The first claim is always made; later claims
 * and every transition reservation stop at the 30-second scheduling deadline, and actions receive the
 * remaining time for their own bounds. At most 25 claims and 10 transition reservations per run.
 *
 * A reservation is taken before the action's proof fetch or locks (A141), so `reserved` counts
 * attempts: it bounds writes (at most 10), but a reserved action may still be deferred, refused or
 * fail, and its outcome is only in `results`. It is never a count of committed transitions.
 *
 * @param callable(list<int>):?array $claim
 * @param callable(array,callable():bool,callable():float):array $act
 * @param callable():float $clock monotonic seconds
 * @return array{claims:int,reserved:int,results:list<array<string,mixed>>,stopped?:string}
 */
function managed_customer_lifecycle_scheduled_loop(callable $claim, callable $act, callable $clock): array
{
    $started = $clock();
    $remaining = static fn(): float => MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_SECONDS - ($clock() - $started);
    $reserved = 0;
    $mayTransition = static function () use (&$reserved, $remaining): bool {
        if ($reserved >= MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_TRANSITIONS || $remaining() <= 0) return false;
        $reserved++;
        return true;
    };
    $run = ['claims' => 0, 'reserved' => 0, 'results' => []];
    $claimed = [];
    while ($run['claims'] < MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_CLAIMS
        && $reserved < MANAGED_CUSTOMER_LIFECYCLE_SCHEDULED_TRANSITIONS
        && ($run['claims'] === 0 || $remaining() > 0)) {
        try {
            $candidate = $claim($claimed);
        } catch (Throwable) {
            $run['stopped'] = 'cursor_unavailable';
            break;
        }
        if ($candidate === null) break;
        $claimed[] = (int)$candidate['binding_id'];
        $run['claims']++;
        $customerId = (string)($candidate['customer_id'] ?? '');
        $customerHash = preg_match(BUSINESS_REPORT_MILEPOST_CUSTOMER_UUID, $customerId) === 1
            ? hash('sha256', $customerId)
            : str_repeat('0', 64);
        try {
            $run['results'][] = $act($candidate, $mayTransition, $remaining);
        } catch (Throwable $error) {
            $run['results'][] = [
                'action' => 'refused',
                'customer_id_sha256' => $customerHash,
                'error_class' => $error::class,
                'error_sha256' => hash('sha256', $error->getMessage()),
            ];
        }
    }
    $run['reserved'] = $reserved;
    return $run;
}

/**
 * A141: re-establish a scheduled admitted lineage's authority inside the action transaction, right
 * after its tenant and actor locks and before any write. A managed provider must still be current
 * (read with shared locks), for the same tenant and with the scheduled actor as its owner; the house
 * acts as its configured actor, already locked. The binding must still carry an immutable admitted
 * receipt. A withdrawal, lease expiry or owner change during the proof fetch therefore refuses.
 */
function managed_customer_lifecycle_admitted_authority(PDO $pdo, array $candidate, int $actorUserId): void
{
    $slug = (string)($candidate['provider_tenant_slug'] ?? '');
    $tenantId = (int)($candidate['tenant_id'] ?? 0);
    if ($slug !== '8west') {
        $provider = suite_managed_provider($pdo, $slug, true);
        if (!is_array($provider) || (int)$provider['tenant_id'] !== $tenantId
            || (int)$provider['owner_user_id'] !== $actorUserId) {
            throw new ManagedCustomerLifecycleGateException(
                'The scheduled provider admission or owner is no longer current.',
            );
        }
    }
    $admitted = $pdo->prepare(
        'SELECT COUNT(*) FROM suite_customer_sync_events
          WHERE tenant_id = ? AND binding_id = ? AND admission IS NOT NULL'
    );
    $admitted->execute([$tenantId, (int)($candidate['binding_id'] ?? 0)]);
    if ((int)$admitted->fetchColumn() < 1) {
        throw new ManagedCustomerLifecycleGateException('The scheduled lineage has no immutable admission.');
    }
}

/**
 * Claim the next scheduled binding after the singleton cursor, in stable binding-ID order, wrapping
 * once and skipping bindings already claimed this run, then advance and commit the cursor. Eligible:
 * the explicit approved `customer_ids` scope or a lineage with an immutable created/restored receipt,
 * deduplicated by binding, that still needs containment or restoration. The cursor never proves
 * authority or completion; the action rechecks everything under its own locks.
 *
 * @param list<int> $claimed
 * @return array<string,mixed>|null
 */
function managed_customer_lifecycle_scheduled_claim(PDO $pdo, array $config, array $claimed = []): ?array
{
    if ($pdo->inTransaction()) {
        throw new ManagedCustomerLifecycleGateException('The scheduled claim requires its own transaction.');
    }
    $explicit = $config['customer_ids'] === []
        ? '0 = 1'
        : 'binding.customer_id IN (' . implode(',', array_fill(0, count($config['customer_ids']), '?')) . ')';
    $skip = $claimed === [] ? '' : ' AND binding.id NOT IN (' . implode(',', array_fill(0, count($claimed), '?')) . ')';
    $next = $pdo->prepare(
        "SELECT binding.id AS binding_id, binding.tenant_id, binding.client_id,
                binding.customer_id, binding.source_version, binding.status,
                binding.last_event_id, binding.last_request_sha256,
                tenant.slug AS provider_tenant_slug
           FROM suite_customer_sync_bindings binding
           JOIN tenants tenant ON tenant.id = binding.tenant_id
          WHERE binding.id > ?{$skip}
            AND ({$explicit} OR EXISTS (
                  SELECT 1 FROM suite_customer_sync_events admitted
                   WHERE admitted.tenant_id = binding.tenant_id
                     AND admitted.binding_id = binding.id
                     AND admitted.admission IS NOT NULL))
            AND (
                 (binding.status = 'inactive' AND NOT EXISTS (
                    SELECT 1 FROM managed_customer_lifecycle_receipts receipt
                     WHERE receipt.customer_id = binding.customer_id
                       AND receipt.source_version = binding.source_version
                       AND receipt.action = 'contained'))
                 OR (binding.status = 'active'
                  AND EXISTS (
                    SELECT 1 FROM suite_customer_sync_events inactive_event
                     WHERE inactive_event.tenant_id = binding.tenant_id
                       AND inactive_event.binding_id = binding.id
                       AND inactive_event.status = 'inactive')
                  AND NOT EXISTS (
                    SELECT 1 FROM managed_customer_lifecycle_restore_receipts restored
                     WHERE restored.customer_id = binding.customer_id
                       AND restored.source_version = binding.source_version))
            )
          ORDER BY binding.id LIMIT 1"
    );
    $pdo->beginTransaction();
    try {
        $cursor = $pdo->query(
            'SELECT last_binding_id FROM managed_customer_lifecycle_progress WHERE id = 1'
            . managed_customer_lifecycle_lock_suffix($pdo)
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($cursor) !== 1) {
            throw new ManagedCustomerLifecycleGateException('The scheduled lifecycle cursor is unavailable.');
        }
        $find = static function (int $after) use ($next, $claimed, $config): ?array {
            $next->execute([$after, ...$claimed, ...$config['customer_ids']]);
            $row = $next->fetch(PDO::FETCH_ASSOC);
            $next->closeCursor();
            return is_array($row) ? $row : null;
        };
        $after = (int)$cursor[0];
        $candidate = $find($after) ?? ($after > 0 ? $find(0) : null);
        if ($candidate !== null) {
            $pdo->prepare(
                'UPDATE managed_customer_lifecycle_progress
                    SET last_binding_id = ?, updated_at = ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                        ? "strftime('%Y-%m-%d %H:%M:%f', 'now')" : 'UTC_TIMESTAMP(6)') . '
                  WHERE id = 1'
            )->execute([(int)$candidate['binding_id']]);
        }
        if (!$pdo->commit()) {
            throw new ManagedCustomerLifecycleGateException('The scheduled lifecycle cursor did not commit.');
        }
        return $candidate;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable) {}
        }
        throw $error;
    }
}

/**
 * Act on one claimed binding with the same apply/restore functions as the explicit mode, under a
 * configuration scoped to exactly this UUID and its actual actor. Explicit scope uses the approved
 * `tenant_actors` entry. An admitted lineage uses the configured house actor for `8west`, or else
 * the currently admitted provider's verified owner; a withdrawn provider is refused.
 *
 * Restoration routing (A88/A152), chosen before any proof or receipt: scheduled restoration is an
 * identity/latch operation, for the approved scope and admitted lineages alike, so it always uses the
 * schema-3 identity-only proof (migration 034) and never writes reports. Approved scope membership is
 * business scope, not report-release intent. The full schema-2 contact restore, which may release an
 * owned report, stays only in the separately run no-argument mode; neither path falls back into the
 * other. The identity restore receives the original configuration, so its authority is the actual
 * approved scope or a genuine admission, never the scoped list built here for apply.
 *
 * Current authority and time at action (A141/A143): the action's guard rechecks an admitted
 * lineage's provider, owner and admission under the action locks, and defers (rolling back, zero
 * writes) if the 30-second scheduling deadline has passed after the proof fetch, before the first
 * write or before the receipt. The deadline governs scheduling only; a database wait or HTTP request
 * already in progress is bounded by its own timeout and by the wrapper's 45 s TERM / 5 s KILL.
 *
 * @return array<string,mixed>
 */
function managed_customer_lifecycle_scheduled_act(
    PDO $pdo,
    array $config,
    array $candidate,
    callable $mayTransition,
    callable $remaining,
    array $idConfig = [],
    ?callable $fetchIdentity = null,
): array {
    $customerId = managed_customer_activation_uuid((string)($candidate['customer_id'] ?? ''));
    $slug = (string)($candidate['provider_tenant_slug'] ?? '');
    $explicit = in_array($customerId, $config['customer_ids'], true);
    if ($explicit || $slug === '8west') {
        $actorId = $config['tenant_actors'][$slug] ?? null;
    } else {
        $provider = suite_managed_provider($pdo, $slug);
        $actorId = is_array($provider) ? (int)$provider['owner_user_id'] : null;
    }
    if (!is_int($actorId) || $actorId < 1) {
        throw new ManagedCustomerLifecycleGateException(
            'No exact owner/admin actor or current provider admission for the claimed customer.',
        );
    }
    $scoped = [
        'enabled' => true,
        'restoration_enabled' => $config['restoration_enabled'],
        'customer_ids' => [$customerId],
        'tenant_actors' => [$slug => $actorId],
        'batch_size' => 1,
    ];
    $provenance = $explicit ? 'explicit' : 'admitted';
    $deferred = ['action' => 'deferred', 'customer_id_sha256' => hash('sha256', $customerId),
        'source_version' => (int)$candidate['source_version'], 'provenance' => $provenance];
    $guard = static function (string $stage) use ($pdo, $candidate, $explicit, $actorId, $remaining): void {
        if ($stage === 'authority' && !$explicit) {
            managed_customer_lifecycle_admitted_authority($pdo, $candidate, $actorId);
        } elseif ($stage === 'write' && $remaining() <= 0) {
            throw new ManagedCustomerLifecycleDeferredException('The scheduling deadline passed before the write.');
        }
    };
    try {
        if (($candidate['status'] ?? null) === 'active' && $config['restoration_enabled'] === true) {
            if (!managed_customer_lifecycle_identity_restore_available($pdo)) {
                throw new ManagedCustomerLifecycleGateException(
                    'Identity-only restoration (migration 034) is not installed.',
                );
            }
            $seconds = (int)floor($remaining());
            if ($seconds < 1 || !$mayTransition()) return $deferred;
            $boundedId = $idConfig;
            $boundedId['timeout_seconds'] = max(1, min((int)($idConfig['timeout_seconds'] ?? 10), $seconds));
            $fetchIdentity ??= 'managed_customer_id_identity_fetch';
            $proof = $fetchIdentity($customerId, $boundedId);
            if (!is_array($proof)) {
                throw new ManagedCustomerLifecycleGateException('Authenticated ID identity-only proof is invalid.');
            }
            if ($remaining() <= 0) return $deferred;
            $houseBindings = is_array($idConfig['tenant_bindings'] ?? null) ? $idConfig['tenant_bindings'] : null;
            return managed_customer_lifecycle_identity_restore(
                $pdo, $candidate, $proof, $actorId, $config, $houseBindings, null, $guard,
            ) + ['provenance' => $provenance];
        }
        if (!$mayTransition()) return $deferred;
        return managed_customer_lifecycle_apply($pdo, $candidate, $actorId, $scoped, null, $guard)
            + ['provenance' => $provenance];
    } catch (ManagedCustomerLifecycleDeferredException) {
        return $deferred;
    }
}

/** @return array{claims:int,reserved:int,results:list<array<string,mixed>>,stopped?:string} */
function managed_customer_lifecycle_scheduled_run(
    PDO $pdo,
    array $rawConfig,
    array $idConfig = [],
    ?callable $clock = null,
    ?callable $fetchIdentity = null,
): array {
    $config = managed_customer_lifecycle_config($rawConfig);
    if ($config['enabled'] !== true) {
        throw new ManagedCustomerLifecycleGateException('Managed-customer lifecycle is disabled.');
    }
    return managed_customer_lifecycle_scheduled_loop(
        static fn(array $claimed): ?array => managed_customer_lifecycle_scheduled_claim($pdo, $config, $claimed),
        static fn(array $candidate, callable $mayTransition, callable $remaining): array =>
            managed_customer_lifecycle_scheduled_act(
                $pdo, $config, $candidate, $mayTransition, $remaining, $idConfig, $fetchIdentity,
            ),
        $clock ?? static fn(): float => hrtime(true) / 1e9,
    );
}
