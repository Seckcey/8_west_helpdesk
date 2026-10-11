<?php
/** New-ticket choices use the existing managed-customer lifecycle boundary. */
declare(strict_types=1);

require_once __DIR__ . '/managed_customer_status.php';
require_once __DIR__ . '/suite_managed_provider.php';

final class TicketClientUnavailableException extends RuntimeException {}

/** @return list<array<string,mixed>> */
function ticket_new_clients(PDO $pdo, int $tenantId): array
{
    $tenant = $pdo->prepare('SELECT slug FROM tenants WHERE id = ?');
    $tenant->execute([$tenantId]);
    $slug = $tenant->fetchColumn();
    if (!is_string($slug)) return [];
    if (suite_managed_provider_enrolled($pdo, $tenantId)
        && suite_managed_provider($pdo, $slug) === null) return [];

    // Bulk read projection of managed_customer_operational, not another status.
    // Keep exact-current restoration and inactive history in parity with that
    // canonical predicate (covered by managed_customer_lifecycle_mysql_test).
    $query = $pdo->prepare(
        "SELECT client.id, client.name, client.sla_tier
           FROM clients client
           LEFT JOIN suite_customer_sync_bindings binding
             ON binding.tenant_id = client.tenant_id AND binding.client_id = client.id
          WHERE client.tenant_id = ?
            AND (binding.id IS NULL OR (
              binding.status = 'active' AND binding.source_version > 0 AND (
                NOT EXISTS (
                  SELECT 1 FROM suite_customer_sync_events inactive_event
                   WHERE inactive_event.tenant_id = binding.tenant_id
                     AND inactive_event.binding_id = binding.id
                     AND inactive_event.status = 'inactive'
                ) OR EXISTS (
                  SELECT 1 FROM managed_customer_lifecycle_restore_receipts restored
                   WHERE restored.tenant_id = binding.tenant_id
                     AND restored.source_binding_id = binding.id
                     AND restored.client_id = binding.client_id
                     AND restored.customer_id = binding.customer_id
                     AND restored.source_version = binding.source_version
                     AND restored.source_event_id = binding.last_event_id
                )
              )
            ))
          ORDER BY client.name"
    );
    $query->execute([$tenantId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** @param list<array<string,mixed>> $clients
 *  @return list<array<string,mixed>>
 */
function ticket_new_contacts(PDO $pdo, int $tenantId, array $clients): array
{
    if (!$clients) return [];
    $ids = array_map(static fn(array $client): int => (int)$client['id'], $clients);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare(
        "SELECT contact.id, contact.client_id, contact.name
           FROM contacts contact JOIN clients client ON client.id = contact.client_id
          WHERE client.tenant_id = ? AND client.id IN ({$placeholders})
          ORDER BY contact.name"
    );
    $query->execute([$tenantId, ...$ids]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Call first in the fresh ticket transaction, before any consistent read/write.
 * Tenant -> provider/binding -> client matches customer-sync lock ordering.
 * The tenant lock serializes the current eligibility check and ticket commit
 * with incoming inactive events; a form loaded earlier supplies no authority.
 * @return array<string,mixed>
 */
function ticket_new_client_for_create(PDO $pdo, int $tenantId, int $clientId): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('New-ticket eligibility requires its ticket transaction.');
    }
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR SHARE' : '';
    $tenant = $pdo->prepare('SELECT id FROM tenants WHERE id = ?' . $lock);
    $tenant->execute([$tenantId]);
    if ($tenant->fetchColumn() === false || $clientId < 1
        || !managed_customer_operational($pdo, $tenantId, $clientId, true)) {
        throw new TicketClientUnavailableException('Client unavailable for new tickets.');
    }
    $query = $pdo->prepare('SELECT * FROM clients WHERE tenant_id = ? AND id = ?' . $lock);
    $query->execute([$tenantId, $clientId]);
    $client = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new TicketClientUnavailableException('Client unavailable for new tickets.');
    }
    return $client;
}
