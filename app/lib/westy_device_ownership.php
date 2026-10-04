<?php
/** Current support ownership only: no ticket, workflow, receipt or customer mutation. */
declare(strict_types=1);
require_once __DIR__.'/westy_workflow.php';

function westy_device_ownership_authenticated(array $settings,array $headers,string $body,?int $now=null): bool
{
    $ts=$headers['timestamp']??'';$signature=$headers['signature']??'';
    return ($headers['service']??'')===WESTY_WORKFLOW_SERVICE
        &&is_string($ts)&&preg_match('/\A[1-9][0-9]{0,11}\z/D',$ts)===1&&abs(($now??time())-(int)$ts)<=300
        &&is_string($signature)&&preg_match('/\A[0-9a-f]{64}\z/D',$signature)===1&&$body!==''&&strlen($body)<=16384
        &&hash_equals(hash_hmac('sha256',"safeharbor-westy-device-ownership-v1\nPOST\n/api/svc/westy_device_ownership.php\n".$ts."\n".$body,$settings['hmac_secret']),$signature);
}

function westy_device_ownership_keys(array $row,array $keys): bool
{ $actual=array_keys($row);sort($actual);sort($keys);return $actual===$keys; }

function westy_device_ownership_request(string $body): array
{
    if($body===''||strlen($body)>16384)throw new InvalidArgumentException('invalid_body');
    try{$p=json_decode($body,true,6,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('invalid_json');}
    if(!is_array($p)||!westy_device_ownership_keys($p,['schema_version','request_key','tenant_slug','workflows'])
        ||$p['schema_version']!==1||!is_string($p['request_key'])||!preg_match(WESTY_WORKFLOW_UUID,$p['request_key'])
        ||!is_string($p['tenant_slug'])||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$p['tenant_slug'])
        ||!is_array($p['workflows'])||!array_is_list($p['workflows'])||count($p['workflows'])<1||count($p['workflows'])>50)throw new InvalidArgumentException('invalid_fields');
    $withoutStrings=preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s','""',$body);
    if(substr_count((string)$withoutStrings,':')!==4+5*count($p['workflows']))throw new InvalidArgumentException('duplicate_fields');
    $seen=[];
    foreach($p['workflows'] as $w){
        if(!is_array($w)||!westy_device_ownership_keys($w,['workflow_key','customer_id','alert_key','ticket_id','expected_version'])
            ||!is_string($w['workflow_key'])||!preg_match(WESTY_WORKFLOW_UUID,$w['workflow_key'])||isset($seen[$w['workflow_key']])
            ||!is_string($w['customer_id'])||!preg_match(WESTY_WORKFLOW_UUID,$w['customer_id'])
            ||!is_string($w['alert_key'])||!preg_match('/^alert:[1-9][0-9]{0,19}$/D',$w['alert_key'])
            ||!is_int($w['ticket_id'])||$w['ticket_id']<1||!is_int($w['expected_version'])||$w['expected_version']<1)throw new InvalidArgumentException('invalid_workflow');
        $seen[$w['workflow_key']]=true;
    }
    return $p;
}

/** The same tenant/customer/ticket/run lock order as the individual workflow status API. */
function westy_device_ownership_receive(PDO $pdo,array $settings,array $p): array
{
    $settings=westy_workflow_settings($settings);westy_workflow_schema_ready($pdo);
    if($pdo->inTransaction())throw new RuntimeException('transaction_ownership_required');
    $pdo->beginTransaction();
    try{
        $lock=westy_workflow_lock($pdo);
        $tenant=westy_workflow_row($pdo,'SELECT id,slug FROM tenants WHERE slug=?'.$lock,[$p['tenant_slug']]);
        if(!$tenant||$tenant['slug']!==$p['tenant_slug'])throw new RuntimeException('unauthorized');
        $tid=(int)$tenant['id'];$house=in_array($p['tenant_slug'],['8west','internal'],true);
        if(!$house){
            $provider=($settings['managed_providers_enabled']??false)===true?suite_managed_provider($pdo,$p['tenant_slug'],true):null;
            if(!$provider||(int)$provider['tenant_id']!==$tid)throw new RuntimeException('unauthorized');
        }
        if(!westy_workflow_row($pdo,'SELECT id FROM svc_identities WHERE tenant_id=? AND service=? AND is_active=1'.$lock,[$tid,WESTY_WORKFLOW_SERVICE]))throw new RuntimeException('unauthorized');
        $states=[];
        foreach($p['workflows'] as $w){
            $binding=westy_workflow_row($pdo,"SELECT client_id FROM suite_customer_sync_bindings WHERE tenant_id=? AND customer_id=? AND status='active'".$lock,[$tid,$w['customer_id']]);
            if(!$binding||!managed_customer_operational($pdo,$tid,(int)$binding['client_id'],true))throw new WestyWorkflowConflict('active_customer_binding_required');
            $ticket=westy_workflow_row($pdo,'SELECT id,client_id,status,external_key FROM tickets WHERE tenant_id=? AND id=?'.$lock,[$tid,$w['ticket_id']]);
            $run=westy_workflow_row($pdo,'SELECT customer_id,ticket_id,alert_key,version,state FROM westy_workflows WHERE tenant_id=? AND workflow_key=?'.$lock,[$tid,$w['workflow_key']]);
            if(!$ticket||!$run||(int)$ticket['client_id']!==(int)$binding['client_id']||(int)$run['ticket_id']!==$w['ticket_id']
                ||$run['customer_id']!==$w['customer_id']||$run['alert_key']!==$w['alert_key']||$ticket['external_key']!==$w['alert_key']
                ||(int)$run['version']<$w['expected_version'])throw new WestyWorkflowConflict('workflow_scope_conflict');
            $states[]=['workflow_key'=>$w['workflow_key'],'customer_id'=>$w['customer_id'],'alert_key'=>$w['alert_key'],
                'ticket_id'=>$w['ticket_id'],'version'=>(int)$run['version'],'state'=>$run['state'],'ticket_status'=>$ticket['status']];
        }
        $pdo->commit();
        return ['ok'=>true,'contract'=>'westy-device-ownership-v1','request_key'=>$p['request_key'],'states'=>$states];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
