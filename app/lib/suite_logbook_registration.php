<?php
declare(strict_types=1);
require_once __DIR__.'/suite_integration_registry.php';
require_once __DIR__.'/suite_managed_provider.php';

function suite_logbook_registration(PDO $pdo, string $key): ?array
{
    $row=suite_integration_for_key('/run/8west-suite-integrations/safeharbor',$key);
    if ($row===null) return null;
    $provider=suite_managed_provider($pdo,$row['slug']);
    if ($provider===null || (int)$provider['tenant_id']!==$row['local_id']
        || (int)$provider['issuer_tenant_id']!==$row['tenant_id']
        || $provider['owner_subject']!==$row['owner_subject']) throw new RuntimeException('Integration owner unavailable.');
    return ['enabled'=>true,'secret'=>$row['secret'],'tenant_id'=>$row['local_id'],
        'suite_tenant_id'=>$row['tenant_id'],'customer_ids'=>$row['customers'],'managed'=>true];
}
