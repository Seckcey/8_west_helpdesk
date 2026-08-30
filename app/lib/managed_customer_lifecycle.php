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
const MANAGED_CUSTOMER_LIFECYCLE_MAX_CUSTOMERS = 25;
const MANAGED_CUSTOMER_LIFECYCLE_MAX_BATCH = 25;
const MANAGED_CUSTOMER_LIFECYCLE_WRITE_TABLES = [
    'customer_portal_bindings',
    'business_report_schedule_versions',
    'managed_customer_lifecycle_receipts',
];

class ManagedCustomerLifecycleException extends RuntimeException {}
final class ManagedCustomerLifecycleValidationException extends ManagedCustomerLifecycleException {}
final class ManagedCustomerLifecycleGateException extends ManagedCustomerLifecycleException {}
final class ManagedCustomerLifecycleConflictException extends ManagedCustomerLifecycleException {}
final class ManagedCustomerLifecycleRestoreUnavailableException extends ManagedCustomerLifecycleException {}

/**
 * Keep the current ID schema-2 parser isolated from lifecycle restoration.
 * Schema 2 proves the customer projection/contact facts used by activation,
 * but it does not yet prove a post-inactive lifecycle restoration. A follow-on
 * can add its signed fields here without weakening activation parsing.
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

/** @return never */
function managed_customer_lifecycle_restore_evidence(array $source): never
{
    managed_customer_lifecycle_base_evidence($source);
    throw new ManagedCustomerLifecycleRestoreUnavailableException(
        'Schema-2 contact evidence alone cannot restore a customer after an inactive event.',
    );
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
        || $restorationEnabled !== false
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
            'Managed-customer lifecycle configuration is invalid or restoration is unsupported.',
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
        'restoration_enabled' => false,
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
                       SELECT 1 FROM managed_customer_lifecycle_receipts receipt
                        WHERE receipt.customer_id = binding.customer_id
                          AND receipt.source_version = binding.source_version
                          AND receipt.action = 'reactivation_blocked'
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
    if ($config['enabled'] !== true || $config['restoration_enabled'] !== false) {
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

/** @return list<array<string,mixed>> */
function managed_customer_lifecycle_run(PDO $pdo, array $rawConfig): array
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
            $results[] = managed_customer_lifecycle_apply(
                $pdo,
                $candidate,
                $actorId,
                $config,
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
