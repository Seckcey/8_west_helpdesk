<?php
/**
 * Read-only link health: Safeharbor's half of linking a suite customer end to end. That means the
 * sync binding that made its Safeharbor client and the customer-portal binding its employees sign
 * in through. It writes nothing and prints no email, note or request hash. Milepost's own check
 * reports the registry, identity binding and deliveries; the two join on customer id and slug.
 */
declare(strict_types=1);

/** @return list<array<string,mixed>> */
function suite_link_health(PDO $pdo): array
{
    $rows = [];
    $synced = $pdo->query(
        'SELECT s.tenant_id, s.customer_id, s.client_id, s.display_name, s.status, s.source_version,
                p.identity_tenant_slug, p.status AS portal_status
           FROM suite_customer_sync_bindings s
           LEFT JOIN customer_portal_bindings p ON p.tenant_id = s.tenant_id AND p.client_id = s.client_id
          ORDER BY s.tenant_id, s.client_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($synced as $row) {
        $portal = $row['identity_tenant_slug'] === null ? 'missing' : (string)$row['portal_status'];
        $rows[] = [
            'tenant_id' => (int)$row['tenant_id'],
            'client_id' => (int)$row['client_id'],
            'customer_id' => (string)$row['customer_id'],
            'name' => (string)$row['display_name'],
            'sync_status' => (string)$row['status'],
            'source_version' => (int)$row['source_version'],
            'identity_tenant_slug' => $row['identity_tenant_slug'] === null ? null : (string)$row['identity_tenant_slug'],
            'portal_binding' => $portal,
            'state' => $row['status'] === 'active' && $portal === 'active' ? 'linked' : 'incomplete',
        ];
    }
    // A portal binding without a synced client (an older hand-made binding) is reported too.
    $unsynced = $pdo->query(
        'SELECT p.tenant_id, p.client_id, p.identity_tenant_slug, p.status
           FROM customer_portal_bindings p
          WHERE NOT EXISTS (SELECT 1 FROM suite_customer_sync_bindings s WHERE s.tenant_id = p.tenant_id AND s.client_id = p.client_id)
          ORDER BY p.tenant_id, p.client_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($unsynced as $row) {
        $rows[] = [
            'tenant_id' => (int)$row['tenant_id'],
            'client_id' => (int)$row['client_id'],
            'customer_id' => null,
            'name' => null,
            'sync_status' => 'missing',
            'source_version' => null,
            'identity_tenant_slug' => (string)$row['identity_tenant_slug'],
            'portal_binding' => (string)$row['status'],
            'state' => 'incomplete',
        ];
    }
    return $rows;
}
