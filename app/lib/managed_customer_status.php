<?php
/**
 * Read-only operational boundary for Milepost-managed Safeharbor clients.
 *
 * Legacy clients have no suite_customer_sync_bindings row and are deliberately
 * unchanged. A managed client is operational only while its current source
 * status is active and its immutable source history contains no inactive
 * event. The latter rule prevents a later Milepost active event from silently
 * undoing containment without an immutable restored-only receipt bound to the
 * exact current source event.
 */
declare(strict_types=1);

final class ManagedCustomerStatusException extends RuntimeException {}

/**
 * @return array{managed:bool,operational:bool,status:string,source_version:int,has_inactive_history:bool,restored_current_event:bool}
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
    require_once __DIR__ . '/suite_managed_provider.php';
    $q = $pdo->prepare('SELECT slug FROM tenants WHERE id = ?');
    $q->execute([$tenantId]);
    $providerSlug = $q->fetchColumn();
    if (is_string($providerSlug) && suite_managed_provider_enrolled($pdo, $tenantId)) {
        $provider = suite_managed_provider($pdo, $providerSlug, $forShare);
        if ($provider === null) return ['managed'=>true,'operational'=>false,'status'=>'provider_unavailable',
            'source_version'=>0,'has_inactive_history'=>false,'restored_current_event'=>false];
    }
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
                    'restored_current_event' => false,
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
                'restored_current_event' => false,
            ];
        }
    }

    $restoreTableAvailable = true;
    if ($driver === 'sqlite') {
        $restoreTable = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master
              WHERE type='table' AND name='managed_customer_lifecycle_restore_receipts'"
        );
        $restoreTableAvailable = (int)$restoreTable->fetchColumn() === 1;
    }
    $restoreProjection = $restoreTableAvailable
        ? "EXISTS (
             SELECT 1
               FROM managed_customer_lifecycle_restore_receipts restored
              WHERE restored.tenant_id = binding.tenant_id
                AND restored.source_binding_id = binding.id
                AND restored.client_id = binding.client_id
                AND restored.customer_id = binding.customer_id
                AND restored.source_version = binding.source_version
                AND restored.source_event_id = binding.last_event_id
           )"
        : '0';

    $sql = "SELECT binding.status, binding.source_version,
                   EXISTS (
                     SELECT 1
                       FROM suite_customer_sync_events inactive_event
                      WHERE inactive_event.tenant_id = binding.tenant_id
                        AND inactive_event.binding_id = binding.id
                        AND inactive_event.status = 'inactive'
                   ) AS has_inactive_history,
                   {$restoreProjection} AS restored_current_event
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
            'restored_current_event' => false,
        ];
    }
    $status = (string)($row['status'] ?? '');
    $sourceVersion = (int)($row['source_version'] ?? 0);
    $hasInactive = (int)($row['has_inactive_history'] ?? 0) === 1;
    $restored = (int)($row['restored_current_event'] ?? 0) === 1;
    if (!in_array($status, ['active', 'inactive'], true) || $sourceVersion < 1) {
        throw new ManagedCustomerStatusException('Managed-customer source state is invalid.');
    }
    return [
        'managed' => true,
        'operational' => $status === 'active' && (!$hasInactive || $restored),
        'status' => $status,
        'source_version' => $sourceVersion,
        'has_inactive_history' => $hasInactive,
        'restored_current_event' => $restored,
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
