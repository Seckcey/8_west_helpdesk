<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/suite_workspace_manifest.php';
require_once __DIR__.'/../lib/suite_managed_provider.php';
try {
    [$manifest,$hash]=suite_workspace_manifest_read('safeharbor',$argv);
    $pdo=db(); $provider=suite_managed_provider($pdo,$manifest['slug']);
    if ($provider===null || (int)$provider['issuer_tenant_id']!==$manifest['tenant_id']
        || $provider['owner_subject']!==$manifest['owner']['subject']) throw new RuntimeException('Owner unavailable.');
    $tenantId=(int)$provider['tenant_id'];
    $q=$pdo->prepare('SELECT is_active FROM svc_identities WHERE tenant_id=? AND service=?');
    $q->execute([$tenantId,'logbook-export']); $active=$q->fetchColumn();
    if ($active!==false && (int)$active!==1) throw new RuntimeException('Service revoked.');
    if ($active===false) {
        $q=$pdo->prepare('INSERT INTO svc_identities (tenant_id,service,display_name) VALUES (?,?,?)');
        $q->execute([$tenantId,'logbook-export','Logbook knowledge export']);
    }
    $q=$pdo->prepare("SELECT customer_id FROM suite_customer_sync_bindings WHERE tenant_id=? AND status='active' ORDER BY customer_id");
    $q->execute([$tenantId]);
    echo json_encode(['tenant_id'=>$manifest['tenant_id'],'manifest_sha256'=>$hash,
        'local_id'=>$tenantId,'customers'=>$q->fetchAll(PDO::FETCH_COLUMN)],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable) { fwrite(STDERR,"integration inspection refused\n"); exit(1); }
