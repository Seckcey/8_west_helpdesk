<?php
/** Optional ID settings-context v1; caller supplies already verified claims. */
declare(strict_types=1);

function safeharbor_settings_context(mixed $doc, string $subject, string $tenant, string $tenantId): array
{
    if (!is_array($doc) || ($doc['version'] ?? null) !== 1
        || ($doc['subject'] ?? null) !== $subject || $subject === ''
        || ($doc['workspace']['tenant'] ?? null) !== $tenant || $tenant === ''
        || ($doc['workspace']['tenant_id'] ?? null) !== $tenantId || $tenantId === ''
        || !in_array($doc['workspace']['kind'] ?? null, ['staff', 'msp', 'client'], true)
        || !is_array($doc['panels'] ?? null) || !array_is_list($doc['panels'])
        || !in_array($doc['primary_owner_status'] ?? null, ['verified', 'held', 'not_applicable'], true)
        || !is_array($doc['capabilities'] ?? null)) return [];
    $keys = array_keys($doc); sort($keys);
    if ($keys !== ['capabilities', 'panels', 'primary_owner_status', 'subject', 'version', 'workspace']) return [];
    $keys = array_keys($doc['workspace']); sort($keys);
    if ($keys !== ['kind', 'tenant', 'tenant_id']) return [];
    $keys = array_keys($doc['capabilities']); sort($keys);
    if ($keys !== ['appoint_client_admins', 'close_subscription', 'manage_people']) return [];
    foreach ($doc['capabilities'] as $value) if (!is_bool($value)) return [];
    foreach ($doc['panels'] as $panel) {
        if (!in_array($panel, ['my_settings', 'client_admin', 'technician_workspace', 'msp_admin', 'platform_operations'], true)) return [];
    }
    return $doc;
}

function safeharbor_settings_panel_url(string $panel): string
{
    if (!in_array($panel, ['client_admin', 'technician_workspace', 'msp_admin', 'platform_operations'], true)) {
        throw new InvalidArgumentException('Unknown settings panel');
    }
    return 'https://id.8westit.com/admin.php?panel=' . $panel;
}
