<?php
/** Automatic recipient-only aliases on the configured support mailbox. */
declare(strict_types=1);

function support_address(int $tenantId, string $mailbox): ?string
{
    $mailbox = mb_strtolower(trim($mailbox));
    if ($tenantId < 1 || !filter_var($mailbox, FILTER_VALIDATE_EMAIL)) return null;
    [$local, $domain] = explode('@', $mailbox, 2);
    if (str_contains($local, '+')) return null;
    $local .= '+w365-' . $tenantId;
    return strlen($local) <= 64 ? $local . '@' . $domain : null;
}

/**
 * Null is unrouteable. Multiple workspace recipients are refused, never
 * guessed from the sender's domain. Untagged mail is held for review.
 * The caller must still prove the returned tenant exists in its database.
 */
function support_recipient_tenant(array $recipients, string $mailbox, array $enabledTenantIds): ?int
{
    $mailbox = mb_strtolower(trim($mailbox));
    if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL)) return null;
    [$local, $domain] = explode('@', $mailbox, 2);
    $matches = [];
    $baseSeen = false;
    foreach ($recipients as $recipient) {
        $address = mb_strtolower(trim((string)($recipient['emailAddress']['address'] ?? '')));
        if ($address === $mailbox) { $baseSeen = true; continue; }
        if (str_starts_with($address, $local . '+w365-') && str_ends_with($address, '@' . $domain)) {
            $tag = substr($address, strlen($local . '+w365-'), -strlen('@' . $domain));
            if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $tag) !== 1) return null;
            $id = (int)$tag;
            if (!in_array($id, $enabledTenantIds, true)) return null;
            $matches[$id] = true;
        }
    }
    if (count($matches) > 1 || $baseSeen) return null;
    if (count($matches) === 1) return (int)array_key_first($matches);
    return null;
}

/** Existing customers are enabled by exact local IDs; new workspaces by cutoff. */
function support_enabled_tenants(PDO $pdo, array $config): array
{
    if (($config['enabled'] ?? null) !== true) return [];
    $ids = array_values(array_filter((array)($config['tenant_ids'] ?? []), static fn($id) => is_int($id) && $id > 0));
    $cutoff = $config['new_tenants_after'] ?? null;
    if (is_string($cutoff) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $cutoff)) {
        $query = $pdo->prepare('SELECT id FROM tenants WHERE created_at >= ?');
        $query->execute([$cutoff]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id;
    }
    return array_values(array_unique($ids));
}

function support_ticket_reply_address(PDO $pdo, int $ticketId, array $config, string $mailbox): ?string
{
    if ($ticketId < 1 || ($config['enabled'] ?? null) !== true) return null;
    $query = $pdo->prepare('SELECT tenant_id, client_id FROM tickets WHERE id = ?');
    $query->execute([$ticketId]);
    $ticket = $query->fetch(PDO::FETCH_ASSOC);
    if (!$ticket || !in_array((int)$ticket['tenant_id'], support_enabled_tenants($pdo, $config), true)) return null;
    $clientGate = $config['client_ids_by_tenant'][(int)$ticket['tenant_id']] ?? null;
    if ($clientGate !== null && (!is_array($clientGate) || !in_array((int)$ticket['client_id'], $clientGate, true))) return null;
    return support_address((int)$ticket['tenant_id'], $mailbox);
}

/** Use the existing mail application's authority with a separate support mailbox. */
function support_graph_config(array $config, ?array $graph): ?array
{
    $mailbox = mb_strtolower(trim((string)($config['mailbox'] ?? '')));
    if (($config['enabled'] ?? null) !== true || $graph === null || support_address(1, $mailbox) === null) return null;
    $graph['sender'] = $mailbox;
    return $graph;
}
