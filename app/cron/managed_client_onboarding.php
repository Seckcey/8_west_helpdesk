<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/suite_workspace_manifest.php';
require_once __DIR__.'/../lib/managed_client_onboarding.php';
try{
    [$manifest,$hash]=suite_workspace_manifest_read('safeharbor',$argv);
    $provider=suite_managed_provider(db(),$manifest['slug']);
    if($provider===null || (int)$provider['issuer_tenant_id']!==$manifest['tenant_id']
        || $provider['owner_subject']!==$manifest['owner']['subject']) throw new RuntimeException('provider unavailable');
    $results=managed_client_onboarding(db(),$manifest['slug'],(array)cfg('id_report_contacts',[]));
    echo json_encode(['tenant_id'=>$manifest['tenant_id'],'manifest_sha256'=>$hash,'clients'=>$results],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"managed client onboarding refused\n");exit(1);}
