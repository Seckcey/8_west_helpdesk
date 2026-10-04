<?php
/** Included by the real binding/transport suite, using synthetic operation projections. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_device_operations_render.php';
$before=$calls;
check(refused(static fn()=>portal_devices_request($pdo,$a,'operations',['device_reference'=>'1:'.str_repeat('a',64)],$transport)),'diagnostics defaults off without a service call');
check($calls===$before,'disabled diagnostics makes no request');
$settings['portal_devices']['diagnostics_enabled']=true;
foreach(['client_staff','client_viewer'] as $role) {
    $limited=$a;$limited['identity']['role']=$role;
    foreach(['health_start','repair_propose','repair_approve'] as $action) {
        check(refused(static fn()=>portal_devices_request($pdo,$limited,$action,[],$transport)),$role.' cannot request '.$action);
    }
}
$operation=['reference'=>str_repeat('2',32),'recipe'=>'spooler_restart','title'=>'Restart the Windows print service',
    'impact'=>'Printing pauses while the service restarts. Pending print jobs are preserved. There is no automatic rollback. Two later health checks verify the service; you still need to confirm that printing works.',
    'device_reference'=>$devices['items'][0]['reference'],'state'=>'awaiting_approval','created_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),'can_approve'=>true,'approval_fingerprint'=>str_repeat('f',64),'result'=>null];
$operationTransport=static function($url,$body,$headers)use($operation,&$seen):array{
    $seen=json_decode($body,true);return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>$operation])];
};
$approved=portal_devices_request($pdo,$a,'repair_propose',['request_key'=>str_repeat('3',32),'device_reference'=>$operation['device_reference'],'health_reference'=>str_repeat('4',32)],$operationTransport);
check($approved===$operation&&$seen['scope']===$scope,'repair service uses the current server-derived customer and actor');
foreach(['extra','fingerprint','can_approve','numeric_result','false_recovery'] as $kind) {
    $bad=$operation;
    if($kind==='extra')$bad['script']='untrusted command';
    if($kind==='fingerprint')$bad['approval_fingerprint']='bad';
    if($kind==='can_approve')$bad['can_approve']='true';
    if($kind==='numeric_result')$bad['result']=['version'=>1,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'memory_used_percent'=>'50','system_disk_free_percent'=>20,'spooler'=>'stopped'];
    if($kind==='false_recovery'){$bad['state']='service_verified';$bad['can_approve']=false;$bad['approval_fingerprint']=null;}
    check(refused(static fn()=>portal_devices_result('repair_propose',$bad)),'operation projection refuses '.$kind);
}
foreach(['health_start','repair_approve'] as $action)check(refused(static fn()=>portal_device_operation_inputs(['action'=>$action])),'no implicit '.$action.' consent');
check(refused(static fn()=>portal_device_operation_inputs(['action'=>'health_start','consent'=>'yes','device_reference'=>['bad'],'request_key'=>str_repeat('1',32)])),'array-shaped device reference refused');
$inputs=portal_device_operation_inputs(['action'=>'repair_approve','consent'=>'yes','reference'=>$operation['reference'],'approval_fingerprint'=>$operation['approval_fingerprint'],'customer_id'=>'injected','script'=>'injected']);
check($inputs===['repair_approve',['reference'=>$operation['reference'],'approval_fingerprint'=>$operation['approval_fingerprint']]],'browser extras cannot become scope or script authority');
$history=['available'=>true,'eligibility'=>['can_check'=>true,'can_propose_repair'=>true,'reason'=>'ready'],'items'=>[$operation]];
ob_start();portal_render_device_help($a,$devices['items'][0],$history);$html=ob_get_clean();
check(str_contains($html,'Approve this repair')&&str_contains($html,'name="consent"')&&str_contains($html,'two follow-up health checks'),'exact repair review includes independent consent and verification impact');
check(!str_contains($html,'<script>untrusted label</script>')&&str_contains($html,'&lt;script&gt;untrusted label&lt;/script&gt;'),'operation page escapes device labels');
ob_start();portal_render_device_help($viewer,$devices['items'][0],$history);$html=ob_get_clean();
check(!str_contains($html,'Approve this repair')&&!str_contains($html,'Run health check'),'viewer has no execution controls');
$history['items'][0]['can_approve']=false;$history['items'][0]['approval_fingerprint']=null;
ob_start();portal_render_device_help($a,$devices['items'][0],$history);$html=ob_get_clean();
check(!str_contains($html,'Approve this repair')&&str_contains($html,'person who requested'),'another requester cannot see approval control');
$history['items'][0]['state']='needs_help';
$history['eligibility']=['can_check'=>false,'can_propose_repair'=>false,'reason'=>'support_review'];
ob_start();portal_render_device_help($a,$devices['items'][0],$history);$html=ob_get_clean();
check(str_contains($html,'Support review needed')&&!str_contains($html,'Approve this repair'),'uncertain repair is not presented as recovery or a retry');
check(!str_contains($html,'Run health check')&&!str_contains($html,'Review print-service repair'),'authoritative current support hold removes both inappropriate actions');
$health=$operation;$health['recipe']='health';$health['state']='completed';$health['can_approve']=false;$health['approval_fingerprint']=null;
$health['result']=['version'=>1,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-30),'memory_used_percent'=>40,'system_disk_free_percent'=>20,'spooler'=>'stopped'];
$verified=$history['items'][0];$verified['state']='service_verified';$verified['result']=array_replace($health['result'],['observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'spooler'=>'running']);
$history=['available'=>true,'eligibility'=>['can_check'=>true,'can_propose_repair'=>false,'reason'=>'repair_cooldown'],'items'=>[$verified,$health]];
ob_start();portal_render_device_help($a,$devices['items'][0],$history);$html=ob_get_clean();
check(str_contains($html,'Running')&&!str_contains($html,'Stopped')&&!str_contains($html,'Review print-service repair'),'verified result supersedes the prior stopped-service observation');
$history['items'][0]['state']='needs_help';$history['items'][0]['result']=null;$history['eligibility']=['can_check'=>true,'can_propose_repair'=>true,'reason'=>'ready'];
ob_start();portal_render_device_help($a,$devices['items'][0],$history);$html=ob_get_clean();
check(str_contains($html,'Run health check'),'historical needs-help alone does not suppress a genuinely available new check');
check(portal_device_operations_result('operations',$history)===$history,'current eligibility passes the closed DTO contract');
$split=$history;$split['eligibility']=['can_check'=>true,'can_propose_repair'=>false,'reason'=>'ready','repair_reason'=>'support_review'];
check(portal_device_operations_result('operations',$split)===$split,'support ownership may reserve repairs while health remains eligible');
ob_start();portal_render_device_help($a,$devices['items'][0],$split);$splitHtml=ob_get_clean();
check(str_contains($splitHtml,'Run health check')&&!str_contains($splitHtml,'Review print-service repair')&&str_contains($splitHtml,'Support reserves changes'),'split eligibility renders its exact boundary');
$split['eligibility']=['can_check'=>false,'can_propose_repair'=>false,'reason'=>'execution_unresolved'];
ob_start();portal_render_device_help($a,$devices['items'][0],$split);$splitHtml=ob_get_clean();
check(!str_contains($splitHtml,'Run health check')&&str_contains($splitHtml,'An earlier command needs a confirmed result'),'unsettled execution has its own explanation');
$split['eligibility']=['can_check'=>false,'can_propose_repair'=>false,'reason'=>'policy_restricted'];
check(portal_device_operations_result('operations',$split)===$split,'explicit capability restriction is accepted as a closed reason');
$ram=array_replace($health['result'],['version'=>2,'memory_total_bytes'=>17179869184,'memory_available_bytes'=>8589934592]);
check(portal_device_health_result_valid($ram),'current health includes usable and available RAM');
foreach([['memory_available_bytes'=>-1],['memory_total_bytes'=>'16'],['memory_available_bytes'=>17179869185]] as $invalid)check(!portal_device_health_result_valid(array_replace($ram,$invalid)),'invalid capacity is refused');
$history['eligibility']['can_check']='yes';
check(refused(static fn()=>portal_device_operations_result('operations',$history)),'malformed current eligibility is refused');
$history=['available'=>true,'eligibility'=>['can_check'=>true,'can_propose_repair'=>true,'reason'=>'ready'],'items'=>[array_replace($health,['state'=>'expired','result'=>null])]];
ob_start();portal_render_device_help($a,$devices['items'][0],$history);$html=ob_get_clean();
check(str_contains($html,'This request expired before starting.')&&str_contains($html,'Run health check')&&!str_contains($html,'Waiting for the computer'),'safe unclaimed expiry presents a new explicit check without a false support hold');
