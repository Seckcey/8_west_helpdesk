<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/suite_workspace_provisioning.php';
require_once __DIR__ . '/../lib/support_addresses.php';
try {
    [$manifest, $hash, $adopt] = suite_workspace_manifest_read('safeharbor', $argv);
    $localTenant = suite_workspace_provision(db(), $manifest, $adopt);
    $supportConfig = (array)cfg('support_addresses', []);
    if (($supportConfig['transport_verified'] ?? null) !== true
        || !in_array($localTenant, support_enabled_tenants(db(), $supportConfig), true)
        || support_address($localTenant, (string)($supportConfig['mailbox'] ?? '')) === null) {
        echo json_encode(['product'=>'safeharbor','tenant_id'=>$manifest['tenant_id'],
            'manifest_sha256'=>$hash,'status'=>'pending','requirements'=>[],
            'tenant_ai'=>['contract'=>1,'local_tenant_key'=>(string)$localTenant]], JSON_THROW_ON_ERROR) . "\n";
    } else {
        suite_workspace_receipt($manifest, $hash, 'safeharbor', ['add_clients'], (string)$localTenant);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "workspace provisioning refused\n");
    exit(1);
}
