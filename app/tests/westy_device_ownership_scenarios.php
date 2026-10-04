<?php
/** Runs inside the real MySQL workflow migration harness. */
declare(strict_types=1);
require_once __DIR__.'/../lib/westy_device_ownership.php';
$ownershipRun=$pdo->query('SELECT * FROM westy_workflows WHERE id=1')->fetch();
$ownershipRequest=['schema_version'=>1,'request_key'=>ww_key(88001),'tenant_slug'=>'8west','workflows'=>[
    ['workflow_key'=>$ownershipRun['workflow_key'],'customer_id'=>$customer,'alert_key'=>$ownershipRun['alert_key'],
        'ticket_id'=>(int)$ownershipRun['ticket_id'],'expected_version'=>(int)$ownershipRun['version']]]];
$ownershipBody=json_encode($ownershipRequest,JSON_THROW_ON_ERROR);
$ownershipHeaders=['service'=>WESTY_WORKFLOW_SERVICE,'timestamp'=>(string)time()];
$ownershipHeaders['signature']=hash_hmac('sha256',"safeharbor-westy-device-ownership-v1\nPOST\n/api/svc/westy_device_ownership.php\n".$ownershipHeaders['timestamp']."\n".$ownershipBody,$settings['hmac_secret']);
ww_check(westy_device_ownership_authenticated($settings,$ownershipHeaders,$ownershipBody),'read-only signature accepts exact body/path/context');
ww_check(!westy_workflow_authenticated($settings,$ownershipHeaders,$ownershipBody),'ownership signature cannot authorize the mutating receiver');
$workflowHeaders=$ownershipHeaders;$workflowHeaders['signature']=hash_hmac('sha256',WESTY_WORKFLOW_CONTEXT."\n".$workflowHeaders['timestamp']."\n".$ownershipBody,$settings['hmac_secret']);
ww_check(!westy_device_ownership_authenticated($settings,$workflowHeaders,$ownershipBody),'workflow signature cannot authorize ownership receiver');
ww_check(!westy_device_ownership_authenticated($settings,$ownershipHeaders,$ownershipBody,time()+301),'expired ownership signature refused');
ww_check(!westy_device_ownership_authenticated($settings,$ownershipHeaders,$ownershipBody.' '),'ownership body substitution refused');
ww_check(westy_device_ownership_request($ownershipBody)===$ownershipRequest,'strict ownership request parsed');
foreach(['{',substr($ownershipBody,0,-1).',"schema_version":1}',str_repeat('x',16385)] as $bad)
    ww_refuses(fn()=>westy_device_ownership_request($bad),'invalid or duplicate ownership fields refused');
foreach([[],array_fill(0,51,$ownershipRequest['workflows'][0]),[$ownershipRequest['workflows'][0],$ownershipRequest['workflows'][0]]] as $rows)
    ww_refuses(fn()=>westy_device_ownership_request(json_encode(array_replace($ownershipRequest,['workflows'=>$rows]))),'empty oversized or duplicate batch refused');
$snap=static function()use($pdo):array{$out=[];foreach(['tickets','westy_workflows','westy_workflow_receipts','messages','westy_billing_outbox'] as $t)$out[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll();return $out;};
$before=$snap();$ownershipResult=westy_device_ownership_receive($pdo,$settings,$ownershipRequest);
ww_check($ownershipResult['states'][0]['ticket_status']==='resolved'&&$snap()===$before,'status projection writes no tickets runs receipts messages or billing evidence');
foreach(['ticket_id'=>99999,'customer_id'=>$otherCustomer,'alert_key'=>'alert:99999','expected_version'=>999999] as $field=>$value){
    $bad=$ownershipRequest;$bad['workflows'][0][$field]=$value;
    ww_refuses(fn()=>westy_device_ownership_receive($pdo,$settings,$bad),'ownership mismatch '.$field.' refused');
}
$bad=$ownershipRequest;$bad['tenant_slug']='msp-two';ww_refuses(fn()=>westy_device_ownership_receive($pdo,$settings,$bad),'external provider cannot use house admission');
$pdo->exec('UPDATE svc_identities SET is_active=0 WHERE tenant_id=1');
ww_refuses(fn()=>westy_device_ownership_receive($pdo,$settings,$ownershipRequest),'inactive service cannot read ownership');
$pdo->exec('UPDATE svc_identities SET is_active=1 WHERE tenant_id=1');
$pdo->exec("INSERT INTO suite_customer_sync_events SELECT tenant_id,id,'inactive' FROM suite_customer_sync_bindings WHERE tenant_id=1");
ww_refuses(fn()=>westy_device_ownership_receive($pdo,$settings,$ownershipRequest),'active source cannot erase inactive customer history');
$pdo->exec('INSERT INTO managed_customer_lifecycle_restore_receipts SELECT tenant_id,id,client_id,customer_id,source_version,last_event_id FROM suite_customer_sync_bindings WHERE tenant_id=1');
ww_check(westy_device_ownership_receive($pdo,$settings,$ownershipRequest)['ok'],'exact current lifecycle restoration permits status');
$pdo->exec('UPDATE suite_customer_sync_bindings SET source_version=2 WHERE tenant_id=1');
ww_refuses(fn()=>westy_device_ownership_receive($pdo,$settings,$ownershipRequest),'old restoration cannot authorize another source version');
$pdo->exec('UPDATE suite_customer_sync_bindings SET source_version=1 WHERE tenant_id=1');
