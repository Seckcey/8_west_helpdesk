<?php
/** Real customer bindings with a captured, synthetic Milepost transport. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1') exit(2);
$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'safeharbor_westy_test';
if (!preg_match('/^safeharbor_westy_test(?:_[a-z0-9_]+)?$/D',$base)) exit(2);
$database=$base.'_employee_'.bin2hex(random_bytes(3));
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1'; $port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root',getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE `'.$database.'`'); $pdo->exec('USE `'.$database.'`');
register_shutdown_function(static function()use($pdo,$database):void{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE `'.$database.'`');});
$settings=['portal_devices'=>['enabled'=>true,'endpoint'=>'https://support.8westit.com/api/svc/customer_portal.php',
    'secret'=>str_repeat('e',64),'diagnostics_enabled'=>true]];
function cfg(string $key,mixed $default=null):mixed {global $settings;$v=$settings;foreach(explode('.',$key) as $part){if(!is_array($v)||!array_key_exists($part,$v))return $default;$v=$v[$part];}return $v;}
require_once __DIR__.'/../lib/portal_auth.php';
session_save_path(sys_get_temp_dir()); portal_session_start(); $_SESSION[PORTAL_CSRF_KEY]=str_repeat('c',64);
register_shutdown_function(static function():void{if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});
require __DIR__.'/portal_westy_fixture.php';
require __DIR__.'/../lib/portal_devices_render.php';
require __DIR__.'/../lib/portal_device_operations_render.php';
require_once __DIR__.'/../lib/portal_westy_desktop.php';
portal_westy_fixture_sql($pdo,__DIR__.'/../db/schema.sql'); [$owner,$other,$foreign]=portal_westy_fixture_seed($pdo);
$uuid=static fn(int $n):string=>sprintf('00000000-0000-4000-8000-%012d',$n);
foreach([$owner,$other,$foreign] as $context){$i=$context['identity'];
    $pdo->prepare('INSERT INTO suite_customer_sync_bindings(tenant_id,customer_id,client_id,source_version,display_name,status,last_event_id,last_occurred_at,last_request_sha256) VALUES(?,?,?,1,?,"active",?,?,?)')
        ->execute([$i['tenant_id'],$uuid($i['client_id']),$i['client_id'],$context['binding']['client_name'],$uuid(100+$i['client_id']),gmdate('Y-m-d H:i:s'),str_repeat('a',64)]);
}
$checks=0;
function employee_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function employee_refused(callable $run):bool{try{$run();return false;}catch(PortalDevicesException){return true;}}
$employee=$owner; $employee['identity']['role']='client_staff'; $employee['identity']['display_name']='Verified employee';
$viewer=$employee; $viewer['identity']['role']='client_viewer';
employee_check(portal_devices_can_manage($owner)&&!portal_devices_can_manage($employee),'employee cannot manage access');
employee_check(portal_devices_can_operate($employee)&&!portal_devices_can_operate($viewer),'employee operates; viewer stays read only');
employee_check(portal_westy_desktop_definitions($pdo,$viewer)===[],'viewer receives no desktop execution definitions');
$grant=['reference'=>str_repeat('a',32),'state'=>'pending','employee'=>null,'created_at'=>'2026-10-09T03:00:00Z','expires_at'=>'2026-10-11T03:00:00Z'];
$response=$grant+['token'=>str_repeat('b',64)]; $calls=0; $sent=null;
$transport=static function($url,$body,$headers)use(&$calls,&$sent,&$response):array{
    $calls++;$sent=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>$response],JSON_THROW_ON_ERROR)];
};
$input=['device_reference'=>'1:'.str_repeat('c',64),'request_key'=>str_repeat('d',32)];
employee_check(portal_devices_request($pdo,$owner,'device_access_create',$input,$transport)===$response,'owner can issue one device link');
employee_check($sent['scope']===portal_devices_scope($pdo,$owner),'scope comes from current binding');
foreach([$employee,$viewer] as $limited)foreach(['device_access_create','device_access_list','device_access_revoke','enrollment_create'] as $action){
    $before=$calls;employee_check(employee_refused(fn()=>portal_devices_request($pdo,$limited,$action,$input,$transport))&&$calls===$before,'limited role cannot manage links/enrollment');
}
$response=array_replace($grant,['state'=>'active','employee'=>'Verified employee']);
employee_check(portal_devices_request($pdo,$employee,'device_access_redeem',['token'=>str_repeat('b',64),'display_name'=>'Spoofed administrator'],$transport)===$response,'employee redeems link');
employee_check($sent['input']['display_name']==='Verified employee'&&$sent['scope']['role']==='client_staff','label and role are server selected');
employee_check(portal_devices_request($pdo,$viewer,'device_access_redeem',['token'=>str_repeat('b',64)],$transport)===$response,'viewer may receive read-only access');
$before=$calls;employee_check(employee_refused(fn()=>portal_devices_request($pdo,$viewer,'repair_propose',[],$transport))&&$calls===$before,'viewer cannot request operation');
$operation=['reference'=>str_repeat('2',32),'recipe'=>'spooler_restart','title'=>'Restart the Windows print service','impact'=>'Printing pauses.',
    'device_reference'=>$input['device_reference'],'state'=>'awaiting_approval','created_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),'can_approve'=>true,'approval_fingerprint'=>str_repeat('f',64),'result'=>null];
$response=$operation;
employee_check(portal_devices_request($pdo,$employee,'repair_propose',$input+['health_reference'=>str_repeat('4',32)],$transport)===$operation,'employee operation reaches scoped service');
employee_check($sent['scope']['subject']===$employee['identity']['subject'],'operation keeps immutable employee subject');
foreach([
    $grant+['token'=>'short'],array_replace($grant,['state'=>'active'])+['token'=>str_repeat('b',64)],
    array_replace($grant,['employee'=>"bad\nlabel"])+['token'=>null],$grant+['token'=>null,'subject'=>'private'],
] as $bad)employee_check(employee_refused(fn()=>portal_devices_result('device_access_create',$bad)),'invalid link projection refused');
employee_check(employee_refused(fn()=>portal_devices_result('device_access_list',['items'=>[$grant+['token'=>str_repeat('b',64)]],'next_after'=>null])),'list cannot disclose token');
employee_check(employee_refused(fn()=>portal_devices_result('device_access_revoke',$grant)),'pending state cannot claim successful revoke');
employee_check(employee_refused(fn()=>portal_devices_result('device_access_redeem',$grant)),'pending state cannot claim successful acceptance');
$response=['items'=>[$grant],'next_after'=>2];
employee_check(portal_devices_request($pdo,$owner,'device_access_list',['device_reference'=>$input['device_reference'],'after'=>0],$transport)===$response,'bounded paginated list accepted');
$response=$grant+['token'=>str_repeat('b',64)];
$revokeTransport=static function($u,$b,$h)use($pdo,$owner,$transport):array{
    portal_transition_binding($pdo,$owner['identity']['binding_id'],'northwind-preview',1,11,101,'disabled','Synthetic revocation during access link response.');
    return $transport($u,$b,$h);
};
employee_check(employee_refused(fn()=>portal_devices_request($pdo,$owner,'device_access_create',$input,$revokeTransport)),'binding change suppresses returned credential');
portal_transition_binding($pdo,$owner['identity']['binding_id'],'northwind-preview',1,11,101,'active','Synthetic binding restored.');
$device=['reference'=>$input['device_reference'],'label'=>'<script>computer</script>','platform'=>'Windows','connection'=>'reporting',
    'connection_label'=>'Reporting','connection_help'=>'Current check-in.','last_seen_at'=>gmdate('Y-m-d\TH:i:s\Z'),'troubleshooting'=>'support_request'];
ob_start();portal_render_devices($owner,['items'=>[$device],'next_after'=>null],[]);$html=ob_get_clean();
employee_check(str_contains($html,'computer-access.php?device=')&&!str_contains($html,'<script>computer</script>'),'owner assignment entry and escaped device label');
ob_start();portal_render_devices($employee,['items'=>[$device],'next_after'=>null],[]);$html=ob_get_clean();
employee_check(!str_contains($html,'computer-access.php?device=')&&!str_contains($html,'Create Windows setup link'),'employee has no assignment/enrollment controls');
ob_start();portal_render_device_help($employee,$device,['available'=>true,'eligibility'=>['can_check'=>true,'can_propose_repair'=>true,'reason'=>'ready'],'items'=>[$operation]]);$html=ob_get_clean();
employee_check(str_contains($html,'Approve this repair'),'employee can review authorized operation');
echo "PASS $checks employee portal binding, transport and presentation assertions\n";
