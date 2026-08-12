<?php
/** Pure role-contract regression coverage; no server config or database. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/suite_roles.php';

$expected = [
    ['msp_owner', 'first-customer', 'owner'],
    ['msp_admin', 'first-customer', 'admin'],
    ['msp_tech', 'first-customer', 'tech'],
    ['owner', '8west', 'owner'],
    ['admin', '8west', 'admin'],
    ['tech', 'internal', 'tech'],
];

foreach ($expected as [$suiteRole, $tenantSlug, $localRole]) {
    if (safeharbor_suite_local_role($suiteRole, $tenantSlug) !== $localRole) {
        throw new RuntimeException("Role {$suiteRole} did not map to {$localRole} in {$tenantSlug}.");
    }
}

foreach ([
    ['owner', 'first-customer'],
    ['msp_owner', '8west'],
    ['msp_viewer', 'first-customer'],
    ['client_owner', 'first-customer'],
    ['client_staff', 'first-customer'],
] as [$suiteRole, $tenantSlug]) {
    if (safeharbor_suite_local_role($suiteRole, $tenantSlug) !== null) {
        throw new RuntimeException("Role {$suiteRole} was unexpectedly admitted in {$tenantSlug}.");
    }
}

echo "suite_roles_test: ok\n";
