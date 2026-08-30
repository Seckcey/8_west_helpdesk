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

const MANAGED_CUSTOMER_LIFECYCLE_CONTEXT = 'safeharbor-managed-customer-lifecycle-v1';
const MANAGED_CUSTOMER_LIFECYCLE_RESTORE_CONTEXT = 'safeharbor-managed-customer-lifecycle-restore-v1';
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
        . managed_customer_lifecycle_lock_suffix($pdo)
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
 * @return array<string,mixed>
 */
function managed_customer_lifecycle_apply(
    PDO $pdo,
    array $candidate,
    int $actorUserId,
    array $rawConfig,
    ?callable $fault = null,
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
        // then portal and report rows. This avoids a source/actor lock cycle
        // with an activation candidate selected just before status changed.
        $tenant = $pdo->prepare(
            'SELECT id FROM tenants WHERE id = ? AND slug = ?'
            . managed_customer_lifecycle_lock_suffix($pdo)
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
            . managed_customer_lifecycle_lock_suffix($pdo)
        );
        $actorQuery->execute([$candidateTenantId, $actorUserId]);
        if ((int)$actorQuery->fetchColumn() !== $actorUserId) {
            throw new ManagedCustomerLifecycleGateException(
                'Lifecycle actor must be an active owner or admin in the exact provider tenant.',
            );
        }

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
            . managed_customer_lifecycle_lock_suffix($pdo);
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

        $latest = business_report_latest_schedule(
            $pdo,
            $candidateTenantId,
            $scheduleKey,
            true,
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
        . managed_customer_lifecycle_lock_suffix($pdo)
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
 * evidence. Human/pre-existing holds remain unchanged.
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
            . managed_customer_lifecycle_lock_suffix($pdo)
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
            . managed_customer_lifecycle_lock_suffix($pdo)
        );
        $actor->execute([$candidateTenantId, $actorUserId]);
        if ((int)$actor->fetchColumn() !== $actorUserId) {
            throw new ManagedCustomerLifecycleGateException(
                'Lifecycle restoration actor is not an active owner/admin.',
            );
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
            . managed_customer_lifecycle_lock_suffix($pdo)
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
            . managed_customer_lifecycle_lock_suffix($pdo)
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
        if (is_array($portal) && (string)$portal['status'] === 'active') {
            throw new ManagedCustomerLifecycleConflictException(
                'An unowned active portal cannot clear the inactive latch.',
            );
        }
        if (is_array($portal) && (string)$portal['status'] !== 'disabled') {
            throw new ManagedCustomerLifecycleConflictException(
                'Lifecycle restoration portal state is invalid.',
            );
        }
        $portalBindingId = is_array($portal) ? (int)$portal['id'] : null;
        $portalBeforeEventId = null;
        $portalStateSha256 = null;
        $portalOwner = null;
        $portalCanRestore = false;
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
            $portalCanRestore = is_array($portalOwner)
                && (int)$portalOwner['portal_state_event_id'] === $portalBeforeEventId
                && hash_equals((string)$portalOwner['portal_state_sha256'], $portalStateSha256);
        }

        $latestSchedule = business_report_latest_schedule(
            $pdo,
            $candidateTenantId,
            $scheduleKey,
            true,
        );
        if (is_array($latestSchedule) && (string)$latestSchedule['status'] === 'active') {
            throw new ManagedCustomerLifecycleConflictException(
                'An unowned active report schedule cannot clear the inactive latch.',
            );
        }
        if (is_array($latestSchedule) && (string)$latestSchedule['status'] !== 'disabled') {
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
        $scheduleCanRestore = is_array($scheduleOwner)
            && (int)$scheduleOwner['schedule_state_version_id'] === $scheduleBeforeId
            && hash_equals((string)$scheduleOwner['schedule_state_sha256'], (string)$scheduleStateSha256);

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
            'portal_owner_receipt_id' => is_array($portalOwner) ? (int)$portalOwner['id'] : null,
            'portal_binding_id' => $portalBindingId,
            'portal_before_event_id' => $portalBeforeEventId,
            'portal_active_event_id' => $portalActiveEventId,
            'portal_restored' => $portalCanRestore ? 1 : 0,
            'portal_state_sha256' => $portalStateSha256,
            'schedule_owner_receipt_id' => is_array($scheduleOwner) ? (int)$scheduleOwner['id'] : null,
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
            $contained = managed_customer_lifecycle_apply(
                $pdo,
                $candidate,
                $actorId,
                $config,
            );
            if (($candidate['status'] ?? null) === 'active'
                && $config['restoration_enabled'] === true
            ) {
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
                $results[] = $contained;
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
