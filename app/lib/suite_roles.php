<?php
/** Pure 8 West ID to Safeharbor role mapping. */
declare(strict_types=1);

function safeharbor_suite_local_role(string $suiteRole, string $tenantSlug): ?string
{
    $staffTenant = in_array($tenantSlug, ['8west', 'internal'], true);
    $roles = $staffTenant
        ? ['owner' => 'owner', 'admin' => 'admin', 'tech' => 'tech']
        : ['msp_owner' => 'owner', 'msp_admin' => 'admin', 'msp_tech' => 'tech'];

    return $roles[$suiteRole] ?? null;
}
