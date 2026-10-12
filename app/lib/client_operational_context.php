<?php
/** Last accepted shared metadata; this does not claim current Milepost state. */
declare(strict_types=1);

function client_operational_edit_url(string $customerId, int $version): ?string
{
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $customerId) !== 1
        || $version < 1 || $version > 1024) return null;
    return 'https://support.8westit.com/suite_customer_metadata.php?'
        . http_build_query(['customer_id' => $customerId, 'expected_version' => $version], '', '&', PHP_QUERY_RFC3986);
}

/** @return array<string,mixed> */
function client_operational_context(PDO $pdo, int $tenantId, int $clientId): array
{
    $unavailable = ['bound' => null, 'available' => false];
    if ($tenantId < 1 || $clientId < 1) return $unavailable;
    try {
        $query = $pdo->prepare(
            'SELECT binding.*, receipt.id AS receipt_id,
                    receipt.binding_id AS receipt_binding, receipt.tenant_id AS receipt_tenant,
                    receipt.client_id AS receipt_client, receipt.customer_id AS receipt_customer,
                    receipt.source_version AS receipt_version, receipt.display_name AS receipt_name,
                    receipt.status AS receipt_status, receipt.occurred_at AS receipt_time
               FROM suite_customer_sync_bindings binding
               LEFT JOIN suite_customer_sync_events receipt ON receipt.event_id = binding.last_event_id
              WHERE binding.tenant_id = ? AND binding.client_id = ?'
        );
        $query->execute([$tenantId, $clientId]);
        $binding = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($binding)) return ['bound' => false, 'available' => false];
        $unavailable['bound'] = true;
        $version = (int)$binding['source_version'];
        $url = client_operational_edit_url((string)$binding['customer_id'], $version);
        if ($url === null || !isset($binding['receipt_id'])
            || (int)$binding['receipt_binding'] !== (int)$binding['id']
            || (int)$binding['receipt_tenant'] !== $tenantId
            || (int)$binding['receipt_client'] !== $clientId
            || (string)$binding['receipt_customer'] !== (string)$binding['customer_id']
            || (int)$binding['receipt_version'] !== $version
            || (string)$binding['receipt_name'] !== (string)$binding['display_name']
            || (string)$binding['receipt_status'] !== (string)$binding['status']
            || (string)$binding['receipt_time'] !== (string)$binding['last_occurred_at']
            || !in_array($binding['status'], ['active', 'inactive'], true)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', (string)$binding['last_event_id']) !== 1
            || !is_string($binding['display_name']) || trim($binding['display_name']) !== $binding['display_name']
            || preg_match('//u', $binding['display_name']) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $binding['display_name']) === 1
            || mb_strlen($binding['display_name'], 'UTF-8') < 1
            || mb_strlen($binding['display_name'], 'UTF-8') > 128) return $unavailable;
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$binding['last_occurred_at'], new DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d H:i:s') !== $binding['last_occurred_at']) return $unavailable;
        return [
            'bound' => true, 'available' => true, 'customer_id' => $binding['customer_id'],
            'display_name' => $binding['display_name'], 'status' => $binding['status'],
            'source_version' => $version, 'event_id' => $binding['last_event_id'],
            'occurred_at' => $time->format('Y-m-d\TH:i:s\Z'), 'edit_url' => $url,
        ];
    } catch (Throwable) {
        return $unavailable;
    }
}

/**
 * Serialize with the existing sync receiver (tenant, binding, client locks).
 * No bound client's contacts may be removed before its FK refuses deletion.
 */
function client_operational_delete_local(PDO $pdo, int $tenantId, int $clientId): string
{
    if ($tenantId < 1 || $clientId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('local client removal requires its own scoped transaction');
    }
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $tenant = $pdo->prepare('SELECT id FROM tenants WHERE id = ?' . $lock);
        $tenant->execute([$tenantId]);
        if ($tenant->fetchColumn() === false) throw new RuntimeException('client tenant unavailable');
        $binding = $pdo->prepare('SELECT id FROM suite_customer_sync_bindings WHERE tenant_id = ? AND client_id = ?' . $lock);
        $binding->execute([$tenantId, $clientId]);
        if ($binding->fetchColumn() !== false) {
            $pdo->commit();
            return 'bound';
        }
        $client = $pdo->prepare('SELECT id FROM clients WHERE tenant_id = ? AND id = ?' . $lock);
        $client->execute([$tenantId, $clientId]);
        if ($client->fetchColumn() === false) {
            $pdo->commit();
            return 'missing';
        }
        $tickets = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE client_id = ?');
        $tickets->execute([$clientId]);
        if ((int)$tickets->fetchColumn() !== 0) {
            $pdo->commit();
            return 'tickets';
        }
        $pdo->prepare('DELETE FROM contacts WHERE client_id = ?')->execute([$clientId]);
        $pdo->prepare('DELETE FROM clients WHERE id = ? AND tenant_id = ?')->execute([$clientId, $tenantId]);
        $pdo->commit();
        return 'deleted';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
