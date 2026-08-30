<?php
/**
 * Read-only operational boundary for Milepost-managed Safeharbor clients.
 *
 * Legacy clients have no suite_customer_sync_bindings row and are deliberately
 * unchanged. A managed client is operational only while its current source
 * status is active and its immutable source history contains no inactive
 * event. The latter rule prevents a later Milepost active event from silently
 * undoing containment before fresh 8 West ID lifecycle evidence is supported.
 */
declare(strict_types=1);

final class ManagedCustomerStatusException extends RuntimeException {}

/**
 * @return array{managed:bool,operational:bool,status:string,source_version:int,has_inactive_history:bool}
 */
function managed_customer_status(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    bool $forShare = false,
): array {
    if ($tenantId < 1 || $clientId < 1) {
        throw new ManagedCustomerStatusException('Managed-customer scope must be positive.');
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    // Hermetic SQLite suites predating migration 015 intentionally model only
    // the old four-column binding lookup. Production is MySQL and must always
    // use the complete immutable-event boundary below.
    if ($driver === 'sqlite') {
        $columns = $pdo->query("PRAGMA table_info('suite_customer_sync_bindings')")
            ->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn(array $row): string => (string)$row['name'], $columns);
        $eventTable = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='suite_customer_sync_events'"
        );
        if (!in_array('source_version', $columnNames, true)
            || (int)$eventTable->fetchColumn() !== 1
        ) {
            $legacyFixture = $pdo->prepare(
                'SELECT status FROM suite_customer_sync_bindings
                  WHERE tenant_id = ? AND client_id = ? LIMIT 1'
            );
            $legacyFixture->execute([$tenantId, $clientId]);
            $status = $legacyFixture->fetchColumn();
            if ($status === false) {
                return [
                    'managed' => false, 'operational' => true, 'status' => 'legacy',
                    'source_version' => 0, 'has_inactive_history' => false,
                ];
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new ManagedCustomerStatusException('Managed-customer source state is invalid.');
            }
            return [
                'managed' => true,
                'operational' => $status === 'active',
                'status' => (string)$status,
                'source_version' => 1,
                'has_inactive_history' => false,
            ];
        }
    }

    $sql = "SELECT binding.status, binding.source_version,
                   EXISTS (
                     SELECT 1
                       FROM suite_customer_sync_events inactive_event
                      WHERE inactive_event.tenant_id = binding.tenant_id
                        AND inactive_event.binding_id = binding.id
                        AND inactive_event.status = 'inactive'
                   ) AS has_inactive_history
              FROM suite_customer_sync_bindings binding
             WHERE binding.tenant_id = ? AND binding.client_id = ?
             LIMIT 1";
    if ($forShare && $driver !== 'sqlite') {
        $sql .= ' FOR SHARE';
    }
    $query = $pdo->prepare($sql);
    $query->execute([$tenantId, $clientId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [
            'managed' => false,
            'operational' => true,
            'status' => 'legacy',
            'source_version' => 0,
            'has_inactive_history' => false,
        ];
    }
    $status = (string)($row['status'] ?? '');
    $sourceVersion = (int)($row['source_version'] ?? 0);
    $hasInactive = (int)($row['has_inactive_history'] ?? 0) === 1;
    if (!in_array($status, ['active', 'inactive'], true) || $sourceVersion < 1) {
        throw new ManagedCustomerStatusException('Managed-customer source state is invalid.');
    }
    return [
        'managed' => true,
        'operational' => $status === 'active' && !$hasInactive,
        'status' => $status,
        'source_version' => $sourceVersion,
        'has_inactive_history' => $hasInactive,
    ];
}

function managed_customer_operational(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    bool $forShare = false,
): bool {
    return managed_customer_status($pdo, $tenantId, $clientId, $forShare)['operational'];
}
