<?php
declare(strict_types=1);
require_once __DIR__.'/suite_managed_provider.php';
require_once __DIR__.'/managed_customer_lifecycle.php';
require_once __DIR__.'/managed_customer_id_evidence.php';

/** Existing receipt-bound workers receive scopes derived only from this admitted provider's own rows. */
function managed_client_onboarding(PDO $pdo,string $slug,array $idConfig,?callable $fetch=null): array
{
    $provider=suite_managed_provider($pdo,$slug);
    if($provider===null) throw new RuntimeException('provider not admitted');
    $tenantId=(int)$provider['tenant_id'];$actor=(int)$provider['owner_user_id'];
    $q=$pdo->prepare('SELECT customer_id,client_id,source_version,status FROM suite_customer_sync_bindings
        WHERE tenant_id=? ORDER BY customer_id');
    $q->execute([$tenantId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $fetch??='managed_customer_id_evidence_fetch';$results=[];
    foreach($rows as $row){
        try{
            if(suite_managed_provider($pdo,$slug)===null) throw new RuntimeException('provider eligibility changed');
            $customer=$row['customer_id'];
            $scope=['enabled'=>true,'customer_ids'=>[$customer],'tenant_actors'=>[$slug=>$actor],'batch_size'=>1];
            $report=[];$evidence=null;
            if($row['status']==='active'){
                $evidence=$fetch($customer,$idConfig);
                $report=['canary_only'=>true,'schedule_keys'=>[managed_customer_activation_schedule_key($customer)],
                    'tenant_slugs'=>[$slug],'client_keys'=>['safeharbor-client:'.$row['client_id']],
                    'recipient_emails'=>[$evidence['recipient_email']]];
            }
            $cached=static fn(string $requested,array $unused):array => $requested===$customer && is_array($evidence)
                ? $evidence : throw new RuntimeException('evidence scope mismatch');
            $outcomes=managed_customer_lifecycle_run($pdo,$scope+['restoration_enabled'=>true],$report,$idConfig,$cached);
            if($row['status']==='active'){
                $outcomes=array_merge($outcomes,managed_customer_activation_run($pdo,$scope+['canary_only'=>true],$report,$idConfig,$cached));
            }
            foreach($outcomes as $outcome) if(($outcome['action']??'')==='refused') throw new RuntimeException('client reconciliation refused');
            $q=$pdo->prepare('SELECT COUNT(*) FROM managed_customer_activation_receipts WHERE tenant_id=? AND customer_id=?');
            $q->execute([$tenantId,$customer]);
            $activated=(int)$q->fetchColumn()===1;
            $state=managed_customer_status($pdo,$tenantId,(int)$row['client_id']);
            $status=$row['status']==='inactive' ? 'suspended' : ($activated && $state['operational'] ? 'verified' : 'provisioning');
            if ($status === 'verified') {
                $q=$pdo->prepare('SELECT status FROM customer_portal_bindings WHERE tenant_id=? AND client_id=?');
                $q->execute([$tenantId,(int)$row['client_id']]);
                $portalActive=$q->fetchColumn()==='active';
                $latest=managed_customer_lifecycle_latest_schedule($pdo,$tenantId,managed_customer_activation_schedule_key($customer));
                if (!$portalActive || !is_array($latest) || $latest['status']!=='active') $status='held';
            }
            $results[]=['customer_id'=>$customer,'source_version'=>(int)$row['source_version'],'status'=>$status];
        }catch(Throwable $e){$results[]=['customer_id'=>$row['customer_id'],'source_version'=>(int)$row['source_version'],'status'=>'failed'];}
    }
    return $results;
}
