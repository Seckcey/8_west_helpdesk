<?php
/** Staff-facing controls for the existing approved-time billing connection. */
declare(strict_types=1);
require_once __DIR__ . '/coastmark_time_export.php';

/** Read billing progress for the approved entries already visible to this tenant. */
function coastmark_time_billing_entries(PDO $pdo, int $tenantId, array $entryIds): array
{
    if ($entryIds === []) return [];
    $marks = implode(',', array_fill(0, count($entryIds), '?'));
    $query = $pdo->prepare(
        "SELECT e.id, binding.customer_id, binding.status AS customer_status,
                claim.id AS claim_id, claim.source_version,
                receipt.outcome, receipt.invoice_id
           FROM time_entries e
           LEFT JOIN suite_customer_sync_bindings binding
             ON binding.tenant_id=e.tenant_id AND binding.client_id=e.client_id
           LEFT JOIN coastmark_time_export_claims claim
             ON claim.tenant_id=e.tenant_id AND claim.time_entry_id=e.id
            AND claim.source_version=(SELECT MAX(previous.source_version)
                FROM coastmark_time_export_claims previous
                WHERE previous.tenant_id=e.tenant_id AND previous.time_entry_id=e.id)
           LEFT JOIN coastmark_time_export_receipts receipt
             ON receipt.tenant_id=e.tenant_id AND receipt.claim_id=claim.id
            AND receipt.id=(SELECT MAX(previous.id) FROM coastmark_time_export_receipts previous
                WHERE previous.tenant_id=e.tenant_id AND previous.claim_id=claim.id)
          WHERE e.tenant_id=? AND e.id IN ({$marks})"
    );
    $query->execute(array_merge([$tenantId], $entryIds));
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int)$row['id']] = $row;
    return $rows;
}

/** The same action and state are used by the rendered page and the request handler. */
function coastmark_time_billing_state(array $entry, ?array $billing, int $version, array $config): array
{
    $state = ['label' => 'Not sent to billing', 'action' => null, 'button' => '', 'invoice_url' => null];
    if ($billing !== null && (int)($billing['invoice_id'] ?? 0) > 0) {
        $endpoint = parse_url((string)($config['endpoint'] ?? ''));
        if (is_array($endpoint) && ($endpoint['scheme'] ?? '') === 'https' && !empty($endpoint['host'])) {
            $state['invoice_url'] = 'https://' . $endpoint['host']
                . (isset($endpoint['port']) ? ':' . $endpoint['port'] : '')
                . '/invoices/' . (int)$billing['invoice_id'];
        }
    }
    $outcome = (string)($billing['outcome'] ?? '');
    if (in_array($outcome, ['accepted', 'replayed'], true)) {
        $state['label'] = 'In Coastmark · version ' . (int)$billing['source_version'];
        if ((int)$billing['source_version'] >= $version) return $state;
        $state['label'] .= ' · adjustment waiting';
    } elseif ($outcome === 'manual_exception') {
        $state['label'] = 'Review correction in Coastmark';
        if ((int)$billing['source_version'] >= $version) return $state;
        $state['label'] .= ' · next adjustment waiting';
    } elseif ($outcome === 'conflict') {
        $state['label'] = 'Billing needs attention';
        return $state;
    }
    if (($config['enabled'] ?? false) !== true || ($config['claim_enabled'] ?? false) !== true) {
        if ($outcome === '') $state['label'] = 'Billing connection is not enabled';
        return $state;
    }
    if ($billing === null || $billing['customer_status'] !== 'active'
        || !in_array('milepost-customer:' . (string)$billing['customer_id'], $config['client_keys'] ?? [], true)
        || $billing['customer_id'] === COASTMARK_TIME_EXPORT_MASTER_CUSTOMER_ID
    ) {
        $state['label'] = 'Customer billing connection needed';
        return $state;
    }
    if ((string)$entry['approval_status'] !== 'approved' || (int)$entry['billable'] !== 1) {
        $state['label'] = 'Not billable';
        return $state;
    }
    if (in_array($outcome, ['dispatching', 'checking', 'ambiguous'], true)) {
        return array_replace($state, ['label' => 'Checking delivery needed', 'action' => 'status', 'button' => 'Check billing status']);
    }
    return array_replace($state, [
        'action' => 'send',
        'button' => $outcome !== 'absent' && $version > 0 && !empty($billing['claim_id'])
            ? 'Send next adjustment' : 'Send to billing',
    ]);
}

/** Use the existing claim/send/status operations; the browser supplies no customer, rate or invoice. */
function coastmark_time_billing_operation(
    PDO $pdo, array $user, int $entryId, string $action, array $config, ?callable $transport = null,
): array {
    if (!in_array($action, ['send', 'status'], true)) {
        throw new CoastmarkTimeExportValidationException('Choose Send to billing or Check billing status.');
    }
    $actorQuery = $pdo->prepare('SELECT role,is_active FROM users WHERE tenant_id=? AND id=?');
    $actorQuery->execute([(int)$user['tenant_id'], (int)$user['id']]);
    $actor = $actorQuery->fetch(PDO::FETCH_ASSOC);
    if (!$actor || (int)$actor['is_active'] !== 1 || !in_array($actor['role'], ['owner', 'admin'], true)) {
        throw new CoastmarkTimeExportValidationException('Only an active owner or admin can send approved time to billing.');
    }
    $query = $pdo->prepare(
        'SELECT e.*, tenant.slug AS tenant_slug FROM time_entries e
         JOIN tenants tenant ON tenant.id=e.tenant_id WHERE e.tenant_id=? AND e.id=?'
    );
    $query->execute([(int)$user['tenant_id'], $entryId]);
    $entry = $query->fetch(PDO::FETCH_ASSOC);
    if (!$entry) throw new CoastmarkTimeExportValidationException('Time entry was not found in this workspace.');
    coastmark_time_export_assert_allowlisted_tenant((string)$entry['tenant_slug'], $config);
    $adjustment = $pdo->prepare('SELECT MAX(version_no) FROM time_entry_approval_adjustments WHERE tenant_id=? AND time_entry_id=?');
    $adjustment->execute([(int)$user['tenant_id'], $entryId]);
    $version = (int)$adjustment->fetchColumn();
    $billing = coastmark_time_billing_entries($pdo, (int)$user['tenant_id'], [$entryId])[$entryId] ?? null;
    $state = coastmark_time_billing_state($entry, $billing, $version, $config);
    if ($state['action'] === null && in_array($billing['outcome'] ?? '', ['accepted', 'replayed', 'manual_exception'], true)) {
        return $state;
    }
    if ($state['action'] !== $action) {
        throw new CoastmarkTimeExportValidationException($state['label'] . '. Refresh this entry to see the next action.');
    }
    if ($action === 'status') {
        coastmark_time_export_status_claim($pdo, (int)$billing['claim_id'], $config, $transport);
    } else {
        // Finish an existing attempt before claiming a newer correction version.
        $claimId = (int)($billing['claim_id'] ?? 0);
        if ($claimId === 0 || in_array($billing['outcome'], ['accepted', 'replayed', 'manual_exception'], true)) {
            $claimed = coastmark_time_export_claim(
                $pdo, (string)$entry['tenant_slug'], $entryId, (string)$entry['entry_key'], (int)$user['id'], $config,
            );
            $claimId = (int)$claimed['claim']['id'];
        }
        coastmark_time_export_send_claim($pdo, $claimId, $config, $transport);
    }
    $billing = coastmark_time_billing_entries($pdo, (int)$user['tenant_id'], [$entryId])[$entryId] ?? null;
    return coastmark_time_billing_state($entry, $billing, $version, $config);
}
