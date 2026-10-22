<?php
/** Real customer binding/lifecycle schema with a captured, synthetic service transport. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'safeharbor_westy_test';
if(!preg_match('/^safeharbor_westy_test(?:_[a-z0-9_]+)?$/D',$base))exit(2);
$database=$base.'_devices_'.bin2hex(random_bytes(3));
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1';$port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
$user=getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root';$pass=getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'';
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE `'.$database.'`');$pdo->exec('USE `'.$database.'`');$pdo->exec("SET time_zone='+00:00'");
register_shutdown_function(static function()use($pdo,$database):void{$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
$settings=['portal_devices'=>['enabled'=>true,'endpoint'=>'https://support.8westit.com/api/svc/customer_portal.php','secret'=>str_repeat('e',64)]];
function cfg(string $key,mixed $default=null):mixed { global $settings; $v=$settings;foreach(explode('.',$key) as $part){if(!is_array($v)||!array_key_exists($part,$v))return $default;$v=$v[$part];}return $v; }
require_once __DIR__.'/../lib/portal_auth.php';
session_save_path(sys_get_temp_dir());portal_session_start();
$_SESSION[PORTAL_CSRF_KEY]=str_repeat('c',64);
register_shutdown_function(static function():void{if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});
require __DIR__.'/portal_westy_fixture.php';
require __DIR__.'/../lib/portal_devices_render.php';
portal_westy_fixture_sql($pdo,__DIR__.'/../db/schema.sql');
[$a,$b,$c]=portal_westy_fixture_seed($pdo);
$uuid=static fn(int $n):string=>sprintf('00000000-0000-4000-8000-%012d',$n);
foreach([$a,$b,$c] as $context){$i=$context['identity'];$pdo->prepare('INSERT INTO suite_customer_sync_bindings(tenant_id,customer_id,client_id,source_version,display_name,status,last_event_id,last_occurred_at,last_request_sha256) VALUES(?,?,?,1,?,"active",?,?,?)')->execute([$i['tenant_id'],$uuid($i['client_id']),$i['client_id'],$context['binding']['client_name'],$uuid(100+$i['client_id']),gmdate('Y-m-d H:i:s'),str_repeat('a',64)]);}
$checks=0;
function check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function refused(callable $fn):bool{try{$fn();return false;}catch(PortalDevicesException){return true;}}
$scope=portal_devices_scope($pdo,$a);
check($scope['customer_id']===$uuid(11)&&$scope['provider_slug']==='preview-provider','scope derives stable customer and provider from server bindings');
$bad=$a;$bad['identity']['client_id']=12;
check(refused(static fn()=>portal_devices_scope($pdo,$bad)),'substituted local customer fails binding check');
$bad=$a;$bad['identity']['tenant_id']=2;
check(refused(static fn()=>portal_devices_scope($pdo,$bad)),'substituted provider fails binding check');
$bad=$a;$bad['identity']['role']='msp_owner';
check(refused(static fn()=>portal_devices_scope($pdo,$bad)),'staff/provider role cannot enter customer service');
$seen=[];$calls=0;
$transport=static function(string $url,string $body,array $headers)use(&$seen,&$calls):array{
    $calls++;$seen=[$url,json_decode($body,true),$headers];
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>['items'=>[],'next_after'=>null]])];
};
check(portal_devices_request($pdo,$a,'devices',['after'=>0],$transport)===['items'=>[],'next_after'=>null],'exact synthetic device projection accepted');
check($seen[1]['scope']===$scope && !isset($seen[1]['scope']['client_id']),'transport transmits stable scoped identity without local client id');
$headers=[];foreach($seen[2] as $h){[$k,$v]=explode(': ',$h,2);$headers[$k]=$v;}
$body=json_encode($seen[1],JSON_THROW_ON_ERROR);
$preimage=PORTAL_DEVICES_CONTEXT."\nPOST\n/api/svc/customer_portal.php\n".$headers['X-Portal-Timestamp']."\n".$headers['X-Portal-Nonce']."\n".hash('sha256',$body);
check(hash_equals(hash_hmac('sha256',$preimage,str_repeat('e',64)),$headers['X-Portal-Signature']),'dedicated HMAC binds context path timestamp nonce and exact body');
foreach(['client_staff','client_viewer'] as $role){$limited=$a;$limited['identity']['role']=$role;$before=$calls;
    check(refused(static fn()=>portal_devices_request($pdo,$limited,'enrollment_create',['request_key'=>str_repeat('1',32),'platform'=>'windows'],$transport)),$role.' cannot issue enrollment');
    check($calls===$before,'denied role causes no service request');}
$settings['portal_devices']['endpoint']='https://other.invalid/api/svc/customer_portal.php';
check(refused(static fn()=>portal_devices_request($pdo,$a,'devices',['after'=>0],$transport)),'alternate service origin refused');
$settings['portal_devices']['endpoint']=PORTAL_DEVICES_ENDPOINT;
$grant=['reference'=>str_repeat('1',32),'state'=>'ready','created_at'=>'2026-10-03T01:00:00Z','expires_at'=>'2026-10-03T05:00:00Z'];
$linkTransport=static fn($u,$b,$h)=>['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>$grant+['download_url'=>'https://evil.invalid/collect']])];
check(refused(static fn()=>portal_devices_request($pdo,$a,'enrollment_download',['reference'=>$grant['reference']],$linkTransport)),'foreign download URL refused');
check(refused(static fn()=>portal_devices_result('devices',['items'=>[['raw_logs'=>'private']],'next_after'=>null])),'malformed device projection refused');
$revokeTransport=static function($u,$b,$h)use($pdo,$a,$transport):array{
    portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'disabled','Synthetic revocation during transport.');
    return $transport($u,$b,$h);
};
check(refused(static fn()=>portal_devices_request($pdo,$a,'devices',['after'=>0],$revokeTransport)),'binding revoked during service call prevents response disclosure');
portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'active','Synthetic test restored.');
// Protection request (A114): exactly four inputs, exactly reference/computer/state back. It installs nothing.
$prNew=['device_reference'=>'1:'.str_repeat('a',64),'request_key'=>str_repeat('d',32),'computer'=>'new_computer','same_as_device_reference'=>null];
$prResponse=['status'=>200,'reason'=>null,'result'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'recorded']];$prBodies=[];
$prTransport=static function(string $url,string $body,array $headers)use(&$prBodies,&$prResponse):array{
    $prBodies[]=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    return ['status'=>$prResponse['status'],'body'=>json_encode($prResponse['status']===200?['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>$prResponse['result']]:['ok'=>false,'reason'=>$prResponse['reason']])];
};
$prReason=static function(callable $fn):string{try{$fn();return 'accepted';}catch(PortalDevicesException $e){return $e->reason.':'.$e->status;}};
check(($settings['portal_devices']['security_orders_enabled']??false)===false && portal_devices_request($pdo,$a,'protection_request',$prNew,$prTransport)===$prResponse['result'],'owner request is recorded while the reviewed INSTALL seam stays off');
check(end($prBodies)===['action'=>'protection_request','scope'=>$scope,'input'=>$prNew],'exactly the four inputs travel under the server-derived scope');
check(array_key_exists('same_as_device_reference',end($prBodies)['input']) && end($prBodies)['input']['same_as_device_reference']===null,'a new computer sends an actual JSON null');
$admin=$a;$admin['identity']['role']='client_admin';
$prSame=['device_reference'=>'1:'.str_repeat('a',64),'request_key'=>str_repeat('e',32),'computer'=>'same_computer','same_as_device_reference'=>'2:'.str_repeat('b',64)];
$prResponse['result']=['reference'=>str_repeat('8',32),'computer'=>'same_computer','state'=>'recorded'];
check(portal_devices_request($pdo,$admin,'protection_request',$prSame,$prTransport)===$prResponse['result'] && end($prBodies)['input']===$prSame,'admin same-computer request sends the different earlier reference');
// An explicit retry resends the original key and inputs; the producer's replay may report superseded.
$prResponse['result']['state']='superseded';
check(portal_devices_request($pdo,$admin,'protection_request',$prSame,$prTransport)===$prResponse['result'] && $prBodies[count($prBodies)-1]===$prBodies[count($prBodies)-2],'same-key replay resends identical inputs and accepts superseded');
$before=count($prBodies);
foreach(['client_staff','client_viewer'] as $role){$limited=$a;$limited['identity']['role']=$role;
    check($prReason(static fn()=>portal_devices_request($pdo,$limited,'protection_request',$prNew,$prTransport))==='owner_request_refused:403',$role.' cannot send a protection request');}
$delegated=$a;$delegated['identity']['customer_access']=['provider_slug'=>'preview-provider','customer_id'=>$uuid(11),'identity_tenant_slug'=>'northwind-preview','role'=>'client_owner',
    'access'=>['reference'=>str_repeat('a',32),'generation'=>1,'identity_role'=>'client_owner','identity_tenant_slug'=>'northwind-preview']];
check($prReason(static fn()=>portal_devices_request($pdo,$delegated,'protection_request',$prNew,$prTransport))==='owner_request_refused:403','delegated customer access cannot send a protection request');
foreach([
    'a missing fourth input'=>array_diff_key($prNew,['same_as_device_reference'=>true]),
    'an extra input'=>$prNew+['customer_id'=>$uuid(11)],
    'a new computer naming an earlier record'=>['same_as_device_reference'=>'2:'.str_repeat('b',64)]+$prNew,
    'a same computer without an earlier record'=>['computer'=>'same_computer']+$prNew,
    'the target as its own earlier record'=>['computer'=>'same_computer','same_as_device_reference'=>$prNew['device_reference']]+$prNew,
    'an unknown computer value'=>['computer'=>'replacement']+$prNew,
    'a malformed earlier record'=>['computer'=>'same_computer','same_as_device_reference'=>'2:'.str_repeat('b',63)]+$prNew,
    'a non-string earlier record'=>['computer'=>'same_computer','same_as_device_reference'=>2]+$prNew,
    'a malformed device reference'=>['device_reference'=>'0:'.str_repeat('a',64)]+$prNew,
    'an uppercase request key'=>['request_key'=>str_repeat('D',32)]+$prNew,
    'a short request key'=>['request_key'=>str_repeat('d',31)]+$prNew,
    'a non-string request key'=>['request_key'=>123]+$prNew,
] as $name=>$bad) check($prReason(static fn()=>portal_devices_request($pdo,$a,'protection_request',$bad,$prTransport))==='invalid_request:400','protection request refuses '.$name);
check(count($prBodies)===$before,'refused roles and inputs send nothing');
foreach([
    'an extra result field'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'recorded','installed'=>true],
    'a missing state'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer'],
    'an install state'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'installed'],
    'a malformed reference'=>['reference'=>str_repeat('9',31),'computer'=>'new_computer','state'=>'recorded'],
    'a different computer statement'=>['reference'=>str_repeat('9',32),'computer'=>'same_computer','state'=>'recorded'],
] as $name=>$reply){$prResponse['result']=$reply;
    check($prReason(static fn()=>portal_devices_request($pdo,$a,'protection_request',$prNew,$prTransport))==='service_unavailable:503','protection result refuses '.$name);}
foreach([[409,'owner_request_changed','owner_request_changed'],[403,'owner_request_refused','owner_request_refused'],[503,'protection_unavailable','protection_unavailable'],
    [404,'device_unavailable','device_unavailable'],[400,'invalid_request','service_unavailable']] as [$status,$reason,$shown]){
    $prResponse['status']=$status;$prResponse['reason']=$reason;
    check($prReason(static fn()=>portal_devices_request($pdo,$a,'protection_request',$prNew,$prTransport))===$shown.':503','producer refusal '.$reason.' is reported without a result');}
$prResponse=['status'=>200,'reason'=>null,'result'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'recorded']];
$prRevoke=static function($u,$b,$h)use($pdo,$a,$prTransport):array{
    portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'disabled','Synthetic revocation during a protection request.');
    return $prTransport($u,$b,$h);
};
check($prReason(static fn()=>portal_devices_request($pdo,$a,'protection_request',$prNew,$prRevoke))==='sign_in:401','binding revoked during a protection request discloses no result');
portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'active','Synthetic test restored.');
check(array_values(array_unique(array_column($prBodies,'action')))===['protection_request'],'a protection request makes no install, enrollment or order call');
check($prReason(static fn()=>portal_devices_request($pdo,$a,'security_install',['reference'=>str_repeat('1',32),'approval_fingerprint'=>str_repeat('f',64)],$prTransport))==='orders_unavailable:503','the reviewed INSTALL seam stays separate and off');
// SBE employee protection (joint b69 wire): three closed actions behind the same owner/admin gate. ID's signed
// cursor and employee references are opaque: relayed exactly, never decoded. Choosing an employee grants nothing.
$emRef=static fn(int $n):string=>'eyJlbXBsb3llZSI'.$n.'.cGF5bG9hZC0'.$n.'.c2lnbmF0dXJl_'.$n;
$emCursor='eyJjdXJzb3Ii.cGFnZS0y.c2lnbmF0dXJl-2';
$emList=static fn(int $from,int $to,?string $next):array=>['employees'=>array_map(static fn(int $n):array=>['display_name'=>'Employee '.$n,'employee_reference'=>$emRef($n)],range($from,$to)),
    'next_cursor'=>$next,'issued_at'=>time(),'expires_at'=>time()+300];
$emFirst=$emList(1,20,$emCursor);$emLast=$emList(21,21,null);
$prResponse=['status'=>200,'reason'=>null,'result'=>$emFirst];
check(portal_devices_request($pdo,$a,'employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>null],$prTransport)===$emFirst
    &&end($prBodies)===['action'=>'employee_protection_choices','scope'=>$scope,'input'=>['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>null]],'the first employee page sends exactly the computer and a JSON null cursor');
$prResponse['result']=$emLast;
check(portal_devices_request($pdo,$a,'employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>$emCursor],$prTransport)===$emLast
    &&end($prBodies)['input']['cursor']===$emCursor,'the next page relays the original cursor unchanged and reaches the member beyond 20');
$emRequest=['device_reference'=>'1:'.str_repeat('a',64),'request_key'=>str_repeat('5',32),'computer'=>'new_computer','same_as_device_reference'=>null,'employee_reference'=>$emRef(21)];
$prResponse['result']=['reference'=>str_repeat('4',32),'computer'=>'new_computer','state'=>'recorded'];
check(portal_devices_request($pdo,$a,'employee_protection_request',$emRequest,$prTransport)===$prResponse['result']
    &&end($prBodies)===['action'=>'employee_protection_request','scope'=>$scope,'input'=>$emRequest],'an employee request sends exactly the five facts under the server-derived owner scope');
$prResponse['result']['state']='superseded';
check(portal_devices_request($pdo,$a,'employee_protection_status',$emRequest,$prTransport)===$prResponse['result']
    &&end($prBodies)===['action'=>'employee_protection_status','scope'=>$scope,'input'=>$emRequest],'the status readback sends the same five facts and accepts the original receipt');
$prResponse['result']=['reference'=>null,'computer'=>'new_computer','state'=>'absent'];
check(portal_devices_request($pdo,$a,'employee_protection_status',$emRequest,$prTransport)===$prResponse['result'],'a proven absence is exactly a null reference, the original computer and absent');
$big=['device_reference'=>'9999999999:'.str_repeat('a',64),'computer'=>'same_computer','same_as_device_reference'=>'9999999998:'.str_repeat('b',64),
    'employee_reference'=>str_repeat('A',1022).'.'.str_repeat('B',1023).'.'.str_repeat('C',1025)]+$emRequest;
$prResponse['result']=['reference'=>str_repeat('4',32),'computer'=>'same_computer','state'=>'recorded'];
check(strlen($big['employee_reference'])===3072&&portal_devices_request($pdo,$a,'employee_protection_request',$big,$prTransport)===$prResponse['result']
    &&strlen(json_encode(end($prBodies),JSON_THROW_ON_ERROR))<=4096,'the largest five facts fit the unchanged 4096-byte signed request');
$before=count($prBodies);
foreach(['employee_protection_choices'=>['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>null],'employee_protection_request'=>$emRequest,'employee_protection_status'=>$emRequest] as $action=>$input){
    foreach(['client_staff','client_viewer'] as $role){$limited=$a;$limited['identity']['role']=$role;
        check($prReason(static fn()=>portal_devices_request($pdo,$limited,$action,$input,$prTransport))==='owner_request_refused:403',$role.' cannot use '.$action);}
    check($prReason(static fn()=>portal_devices_request($pdo,$delegated,$action,$input,$prTransport))==='owner_request_refused:403','delegated customer access cannot use '.$action);
}
foreach([
    'choices without a cursor'=>['employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64)]],
    'choices with an extra key'=>['employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>null,'customer_id'=>$uuid(11)]],
    'an empty cursor'=>['employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>'']],
    'a non-string cursor'=>['employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>2]],
    'a cursor over 3072 bytes'=>['employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>str_repeat('A',1023).'.'.str_repeat('B',1023).'.'.str_repeat('C',1025)]],
    'a malformed choices computer'=>['employee_protection_choices',['device_reference'=>'0:'.str_repeat('a',64),'cursor'=>null]],
    'the Family four without an employee'=>['employee_protection_request',$prNew],
    'a null employee'=>['employee_protection_request',['employee_reference'=>null]+$emRequest],
    'a blank employee'=>['employee_protection_request',['employee_reference'=>'']+$emRequest],
    'a decoded-looking employee'=>['employee_protection_request',['employee_reference'=>'t9u11']+$emRequest],
    'an employee over 3072 bytes'=>['employee_protection_status',['employee_reference'=>str_repeat('A',1023).'.'.str_repeat('B',1023).'.'.str_repeat('C',1025)]+$emRequest],
    'a same computer without an earlier record'=>['employee_protection_request',['computer'=>'same_computer']+$emRequest],
    'a short key'=>['employee_protection_status',['request_key'=>str_repeat('5',31)]+$emRequest],
    'an extra employee fact'=>['employee_protection_status',$emRequest+['display_name'=>'Employee 21']],
    'an employee on the Family action'=>['protection_request',$emRequest],
] as $name=>[$action,$bad]) check($prReason(static fn()=>portal_devices_request($pdo,$a,$action,$bad,$prTransport))==='invalid_request:400','employee protection refuses '.$name);
check(count($prBodies)===$before,'refused employee actors and inputs send nothing');
$prResponse['status']=200;
foreach([
    'more than 20 employees'=>$emList(1,21,null),
    'a next page after a short page'=>$emList(1,19,$emCursor),
    'a duplicate employee'=>['employees'=>[['display_name'=>'A','employee_reference'=>$emRef(1)],['display_name'=>'B','employee_reference'=>$emRef(1)]]]+$emLast,
    'an extra employee field'=>['employees'=>[['display_name'=>'A','employee_reference'=>$emRef(1),'subject'=>'t9u12']]]+$emLast,
    'a control character label'=>['employees'=>[['display_name'=>"A\x07",'employee_reference'=>$emRef(1)]]]+$emLast,
    'a label over 190 bytes'=>['employees'=>[['display_name'=>str_repeat('é',96),'employee_reference'=>$emRef(1)]]]+$emLast,
    'an empty label'=>['employees'=>[['display_name'=>'','employee_reference'=>$emRef(1)]]]+$emLast,
    'a malformed employee reference'=>['employees'=>[['display_name'=>'A','employee_reference'=>'not-a-token']]]+$emLast,
    'an extra page field'=>$emLast+['role'=>'client_owner'],
    'a text clock'=>['issued_at'=>(string)time()]+$emLast,
    'an expiry before issue'=>['expires_at'=>time()-1,'issued_at'=>time()]+$emLast,
] as $name=>$reply){$prResponse['result']=$reply;
    check($prReason(static fn()=>portal_devices_request($pdo,$a,'employee_protection_choices',['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>null],$prTransport))==='service_unavailable:503','employee choices refuse '.$name);}
foreach([
    ['employee_protection_request','an absence from the request action',['reference'=>null,'computer'=>'new_computer','state'=>'absent']],
    ['employee_protection_status','an absence with a reference',['reference'=>str_repeat('4',32),'computer'=>'new_computer','state'=>'absent']],
    ['employee_protection_status','an absence for another computer statement',['reference'=>null,'computer'=>'same_computer','state'=>'absent']],
    ['employee_protection_status','a receipt without a reference',['reference'=>null,'computer'=>'new_computer','state'=>'recorded']],
    ['employee_protection_status','an install state',['reference'=>str_repeat('4',32),'computer'=>'new_computer','state'=>'installed']],
    ['employee_protection_request','an extra receipt field',['reference'=>str_repeat('4',32),'computer'=>'new_computer','state'=>'recorded','employee'=>'t9u12']],
] as [$action,$name,$reply]){$prResponse['result']=$reply;
    check($prReason(static fn()=>portal_devices_request($pdo,$a,$action,$emRequest,$prTransport))==='service_unavailable:503','employee receipt refuses '.$name);}
foreach([[409,'beneficiary_changed','beneficiary_changed'],[409,'employee_selection_stale','employee_selection_stale'],[503,'employee_selection_unavailable','employee_selection_unavailable'],
    [403,'employee_selection_refused','employee_selection_refused'],[409,'owner_request_changed','owner_request_changed'],[503,'transaction_unavailable','service_unavailable']] as [$status,$reason,$shown]){
    $prResponse['status']=$status;$prResponse['reason']=$reason;
    check($prReason(static fn()=>portal_devices_request($pdo,$a,'employee_protection_request',$emRequest,$prTransport))===$shown.':503','producer refusal '.$reason.' is reported without a result');}
$prResponse['status']=409;$prResponse['reason']='beneficiary_changed';
check($prReason(static fn()=>portal_devices_request($pdo,$admin,'protection_request',$prSame,$prTransport))==='beneficiary_changed:503','the Family request shows the same neutral beneficiary refusal');
foreach(['beneficiary_changed','employee_selection_stale','employee_selection_unavailable','employee_selection_refused','owner_request_refused'] as $reason)
    check(preg_match('/_|beneficiary|attribut|SBE|client_owner|client_admin|enum/i',portal_devices_error($reason))===0,'the '.$reason.' message leaks no code, enum or authority detail');
check(str_contains(portal_devices_error('owner_request_refused'),'account owner or an admin')&&!str_contains(portal_devices_error('owner_request_refused'),'business owner'),'the owner/admin refusal uses neutral account wording');
$prResponse=['status'=>200,'reason'=>null,'result'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'recorded']];
check(array_values(array_unique(array_column($prBodies,'action')))===['protection_request','employee_protection_choices','employee_protection_request','employee_protection_status'],'no employee action makes an install, enrollment or order call');
$devices=['items'=>[['reference'=>'1:'.str_repeat('a',64),'label'=>'<script>untrusted label</script>','platform'=>'Windows 11','connection'=>'reporting','connection_label'=>'Connected and reporting','connection_help'=>'Fresh check-in received.','last_seen_at'=>'2026-10-03T01:00:00Z','troubleshooting'=>'support_request']],'next_after'=>null];
check(portal_devices_result('devices',$devices)===$devices,'legacy device projection remains compatible');
$devices['items'][0]['hardware']=['ram_gb'=>15.8,'observed_at'=>'2026-10-03T01:00:00Z','source'=>'agent_inventory'];
check(portal_devices_result('devices',$devices)===$devices,'RAM fact and capture time pass the closed customer contract');
$ttyDevices=$devices;$ttyDevices['items'][0]['terminal_capabilities']=[
    'user'=>['tty_supported'=>true,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+15)],
    'system'=>['tty_supported'=>false,'observed_at'=>null,'expires_at'=>null]];
check(portal_devices_result('devices',$ttyDevices)===$ttyDevices,'fresh typed TTY capability remains separate for user and SYSTEM');
$bad=$ttyDevices;$bad['items'][0]['terminal_capabilities']['user']['tty_supported']='true';
check(refused(static fn()=>portal_devices_result('devices',$bad)),'model-like text cannot advertise native TTY capability');
$bad=$ttyDevices;$bad['items'][0]['terminal_capabilities']['user']['extra']='anything';
check(refused(static fn()=>portal_devices_result('devices',$bad)),'TTY projection rejects unknown capability fields');
$bad=$ttyDevices;$bad['items'][0]['terminal_capabilities']['system']['observed_at']=gmdate('Y-m-d\TH:i:s\Z');
check(refused(static fn()=>portal_devices_result('devices',$bad)),'unsupported TTY cannot carry a misleading fresh observation');
$bad=$ttyDevices;$bad['items'][0]['terminal_capabilities']['user']['expires_at']=gmdate('Y-m-d\TH:i:s\Z',time()+16);
check(refused(static fn()=>portal_devices_result('devices',$bad)),'TTY projection cannot widen native freshness');
$bad=$ttyDevices;$bad['items'][0]['terminal_capabilities']['user']['observed_at']=gmdate('Y-m-d\TH:i:s\Z',time()-16);
$bad['items'][0]['terminal_capabilities']['user']['expires_at']=gmdate('Y-m-d\TH:i:s\Z',time()-1);
check(portal_devices_result('devices',$bad)['items'][0]['terminal_capabilities']['user']===['tty_supported'=>false,'observed_at'=>null,'expires_at'=>null],'expired TTY capability is removed before model or UI disclosure');
$bad=$ttyDevices;$bad['items'][0]['terminal_capabilities']['user']['observed_at']=gmdate('Y-m-d\TH:i:s\Z',time()+60);
$bad['items'][0]['terminal_capabilities']['user']['expires_at']=gmdate('Y-m-d\TH:i:s\Z',time()+75);
check(!portal_devices_result('devices',$bad)['items'][0]['terminal_capabilities']['user']['tty_supported'],'future-dated capability cannot create support before its actual observation');
foreach([['ram_gb'=>-1],['source'=>'inferred'],['observed_at'=>'not-a-date'],['ram_gb'=>'16'],['private'=>'extra']] as $invalid){
    $bad=$devices;$bad['items'][0]['hardware']=array_replace($bad['items'][0]['hardware'],$invalid);
    check(refused(static fn()=>portal_devices_result('devices',$bad)),'invalid or extra hardware fact is rejected');
}
ob_start();portal_render_devices($a,$devices,[$grant]);$html=ob_get_clean();
check(str_contains($html,'15.8 GB')&&str_contains($html,'Inventory'),'device card shows capacity as a dated inventory fact');
check(str_contains($html,'&lt;script&gt;untrusted label&lt;/script&gt;')&&!str_contains($html,'<script>untrusted label</script>'),'device labels are escaped');
check(str_contains($html,'Create Windows setup link')&&str_contains($html,'name="consent"'),'owner sees real consent and enrollment controls');
$viewer=$a;$viewer['identity']['role']='client_viewer';
ob_start();portal_render_devices($viewer,$devices,[$grant]);$html=ob_get_clean();
check(!str_contains($html,'Create Windows setup link')&&!str_contains($html,'value="enrollment_revoke"'),'viewer has no mutation controls');
check(!str_contains($html,'Protection request')&&!str_contains($html,'Protection for an employee'),'viewer has no protection request controls');
$recorded=['principal'=>[],'input'=>$prNew,'result'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'recorded']];
ob_start();portal_render_devices($a,$devices,[],null,null,null,$recorded);$html=ob_get_clean();
check(str_contains($html,'value="protection_request"')&&preg_match_all('/name="request_key" value="([a-f0-9]{32})"/',$html,$keys)===2&&$keys[1][0]!==$keys[1][1],'owner sees the protection request form with its own fresh server key');
check(str_contains($html,'<option value="1:'.str_repeat('a',64).'">&lt;script&gt;untrusted label&lt;/script&gt;</option>')&&!str_contains($html,'<option value="1:'.str_repeat('a',64).'"><script>'),'request choices use server references and escaped labels');
check(str_contains($html,'Earlier answer, not checked again: the protection request for &lt;script&gt;untrusted label&lt;/script&gt; was saved.')&&!str_contains($html,'value="protection_retry"'),'a saved answer is shown as an earlier answer without a retry');
check(!str_contains($html,'Install on your Windows computer')&&!str_contains($html,'download.php'),'a saved protection request shows no installation');
// A132: plain customer copy, with no internal ledger vocabulary or staff promise inside either protection section.
check(preg_match('~<section class="portal-device-setup" aria-labelledby="protection-heading">.*?</section>\s*<section class="portal-device-setup" aria-labelledby="employee-protection-heading">.*?</section>~s',$html,$copy)===1
    &&substr_count($copy[0],'Sending this request does not install protection or change your bill.')===2
    &&preg_match('/recorded|superseded|start a job|prove a license|will contact|will install/i',strip_tags($copy[0]))===0,'protection copy is plain and makes no installation, billing or staff promise');
$superseded=$recorded;$superseded['result']['state']='superseded';
ob_start();portal_render_devices($a,$devices,[],null,null,null,$superseded);$html=ob_get_clean();
check(str_contains($html,'Earlier answer, not checked again: a newer request for &lt;script&gt;untrusted label&lt;/script&gt; replaced this one.'),'a replaced request is described plainly as an earlier answer');
ob_start();portal_render_devices($a,$devices,[],null,null,null,['result'=>null]+$recorded);$html=ob_get_clean();
check(str_contains($html,'has no confirmed result')&&!str_contains($html,'Earlier answer'),'an unknown outcome is not shown as saved');
check(preg_match('~<form[^>]*>(?:(?!</form>).)*value="protection_retry"(?:(?!</form>).)*</form>~s',$html,$retry)===1&&!str_contains($retry[0],'request_key')&&!str_contains($retry[0],'device_reference'),'the explicit retry carries no browser key or inputs');
ob_start();portal_render_devices($delegated,$devices,[]);$html=ob_get_clean();
check(!str_contains($html,'Protection request')&&!str_contains($html,'Protection for an employee'),'delegated access has no protection request controls');
// SBE picker: an explicit blank choice, escaped labels, the opaque references, and a visible next-page control.
$emPage=['device_reference'=>'1:'.str_repeat('a',64)]+$emFirst;$emPage['employees'][0]['display_name']='<b>Ann</b> & Co';
ob_start();portal_render_devices($a,$devices,[],null,null,null,null,null,$emPage);$html=ob_get_clean();
check(preg_match('~<select name="employee_reference" required>(.*?)</select>~s',$html,$picker)===1&&str_starts_with($picker[1],'<option value="">Choose an employee</option>')
    &&substr_count($picker[1],'<option value="eyJ')===20&&!str_contains($picker[1],'selected'),'the employee picker starts blank, lists all 20 in order and selects nobody');
check(str_contains($picker[1],'<option value="'.$emRef(1).'">&lt;b&gt;Ann&lt;/b&gt; &amp; Co</option>')&&strpos($picker[1],$emRef(1))<strpos($picker[1],$emRef(20)),'employee labels are escaped and keep the producer order');
check(preg_match('~<form[^>]*>(?:(?!</form>).)*value="employee_choices"(?:(?!</form>).)*</form>~s',$html,$more)===1&&str_contains($more[0],'name="cursor" value="'.$emCursor.'"')
    &&str_contains($more[0],'name="device_reference" value="1:'.str_repeat('a',64).'"')&&str_contains($more[0],'More employees'),'a visible next-page control carries the original cursor and computer');
check(preg_match('~<form[^>]*>(?:(?!</form>).)*value="employee_request"(?:(?!</form>).)*</form>~s',$html,$emForm)===1&&str_contains($emForm[0],'name="device_reference" value="1:'.str_repeat('a',64).'"')
    &&preg_match('/name="request_key" value="[a-f0-9]{32}"/',$emForm[0])===1&&!str_contains($emForm[0],'customer')&&!str_contains($emForm[0],'subject'),'the employee request form carries the chosen computer, a fresh key and no identity field');
ob_start();portal_render_devices($a,$devices,[],null,null,null,null,null,['device_reference'=>'1:'.str_repeat('a',64)]+$emLast);$html=ob_get_clean();
check(str_contains($html,'>Employee 21</option>')&&!str_contains($html,'More employees'),'the last page shows the member beyond 20 and no further page');
ob_start();portal_render_devices($a,$devices,[],null,null,null,null,null,['device_reference'=>'1:'.str_repeat('a',64),'employees'=>[],'next_cursor'=>null,'issued_at'=>time(),'expires_at'=>time()+300]);$html=ob_get_clean();
check(str_contains($html,'No employees are listed here.')&&!str_contains($html,'value="employee_request"'),'an empty page offers no request');
ob_start();portal_render_devices($a,$devices,[]);$html=ob_get_clean();
check(str_contains($html,'Show employees')&&str_contains($html,'name="cursor" value=""')&&!str_contains($html,'name="employee_reference"'),'the first page is requested explicitly, with an empty cursor');
$emSlot=['principal'=>[],'input'=>$emRequest,'result'=>null];
ob_start();portal_render_devices($a,$devices,[],null,null,null,null,$emSlot);$html=ob_get_clean();
check(str_contains($html,'has no confirmed result. Checking its status asks about that same request')&&preg_match('~<form[^>]*>(?:(?!</form>).)*value="employee_status"(?:(?!</form>).)*</form>~s',$html,$status)===1
    &&!str_contains($status[0],'request_key')&&!str_contains($status[0],'employee_reference')&&!str_contains($html,'value="employee_retry"'),'an unknown employee request offers only a status check with no browser facts');
ob_start();portal_render_devices($a,$devices,[],null,null,null,null,['result'=>['reference'=>null,'computer'=>'new_computer','state'=>'absent']]+$emSlot);$html=ob_get_clean();
check(str_contains($html,'Earlier answer, not checked again: no request for an employee on &lt;script&gt;')&&preg_match('~<form[^>]*>(?:(?!</form>).)*value="employee_retry"(?:(?!</form>).)*</form>~s',$html,$resend)===1
    &&!str_contains($resend[0],'request_key')&&!str_contains($resend[0],'employee_reference'),'a proven absence offers the same request again with no browser facts');
ob_start();portal_render_devices($a,$devices,[],null,null,null,null,['result'=>['reference'=>str_repeat('4',32),'computer'=>'new_computer','state'=>'recorded']]+$emSlot);$html=ob_get_clean();
check(str_contains($html,'Earlier answer, not checked again: the request for an employee on &lt;script&gt;')&&!str_contains($html,'value="employee_status"')&&!str_contains($html,'value="employee_retry"'),'a saved employee request offers no status check or resend');
// A128: the ACTUAL devices.php controller and portal session, served by PHP's built-in HTTP server with the real
// bootstrap, revocation, binding and CSRF checks and real session files. As in portal_stream_http_test.php, only this
// disposable copy changes one seam: portal_devices_transport, the outbound Milepost network edge, records each signed
// body and answers from scripted replies. A missing reply is a transport failure, i.e. an unknown outcome.
require_once __DIR__.'/../lib/eightwestid/eightwestid.php';
$pcRuntime=sys_get_temp_dir().'/safeharbor-devices-'.bin2hex(random_bytes(8));
foreach(['lib','config','public/portal','public/assets/js','public/assets/css','sessions','cache'] as $pcPart)mkdir($pcRuntime.'/'.$pcPart,0700,true);
$pcSource=realpath(__DIR__.'/../lib');
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pcSource,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $pcFile){$pcPath=$pcRuntime.'/lib/'.substr($pcFile->getPathname(),strlen($pcSource)+1);$pcFile->isDir()?mkdir($pcPath,0700,true):copy($pcFile->getPathname(),$pcPath);}
copy(__DIR__.'/../public/portal/devices.php',$pcRuntime.'/public/portal/devices.php');
// The real page footer hashes these three canonical assets (portal_asset_version), so the disposable runtime carries
// exact copies of the real files: no placeholder or hash stub. The finally below removes them with the runtime.
foreach(['portal.css','portal-devices.css','portal-workspace.css'] as $pcAsset){
    $pcFrom=__DIR__.'/../public/assets/css/'.$pcAsset;$pcTo=$pcRuntime.'/public/assets/css/'.$pcAsset;
    if(!copy($pcFrom,$pcTo)||hash_file('sha256',$pcTo)!==hash_file('sha256',$pcFrom))throw new RuntimeException('Canonical portal CSS unavailable: '.$pcAsset);
}
$pcFooter=[];
foreach(['portal-desktop.js','portal-computer-time.js','portal-westy.js'] as $pcAsset){
    $pcFrom=__DIR__.'/../public/assets/js/'.$pcAsset;$pcTo=$pcRuntime.'/public/assets/js/'.$pcAsset;
    if(!is_file($pcFrom)||!copy($pcFrom,$pcTo)||hash_file('sha256',$pcTo)!==hash_file('sha256',$pcFrom))throw new RuntimeException('Canonical portal asset unavailable: '.$pcAsset);
    $pcFooter[$pcAsset]='<script src="/assets/js/'.$pcAsset.'?v='.substr((string)hash_file('sha256',$pcTo),0,20).'" defer></script>';
}
file_put_contents($pcRuntime.'/config/config.php','<?php return '.var_export(['app_env'=>'dev','force_https'=>false,
    'db'=>['host'=>$host,'port'=>(int)$port,'user'=>$user,'pass'=>$pass,'name'=>$database,'charset'=>'utf8mb4'],
    'portal'=>['enabled'=>true,'issuer'=>'https://id.example.test','client_id'=>'fixture','client_secret'=>str_repeat('x',48),'redirect_uri'=>'https://example.test/portal/callback.php','cookie_secure'=>false,'revocation_cache_dir'=>$pcRuntime.'/cache'],
    'portal_devices'=>['enabled'=>true,'endpoint'=>PORTAL_DEVICES_ENDPOINT,'secret'=>str_repeat('e',64)]],true).';');
$pcLib=file_get_contents($pcRuntime.'/lib/portal_devices.php');
$pcStart=strpos($pcLib,'function portal_devices_transport(');$pcEnd=strpos($pcLib,'function portal_devices_request(');
if($pcStart===false||$pcEnd===false||$pcEnd<$pcStart)throw new RuntimeException('Device transport seam unavailable');
$pcStub=<<<'PHP'
function portal_devices_transport(string $endpoint, string $body, array $headers): array
{
    file_put_contents(dirname(__DIR__).'/mp-requests.log', $body."\n", FILE_APPEND | LOCK_EX);
    $reply = json_decode((string)file_get_contents(dirname(__DIR__).'/mp-replies.json'), true)[json_decode($body, true)['action'] ?? ''] ?? null;
    if (!is_array($reply)) throw new PortalDevicesException('service_unavailable');
    return ['status'=>$reply['status'], 'body'=>json_encode($reply['status'] === 200
        ? ['contract'=>PORTAL_DEVICES_CONTEXT, 'ok'=>true, 'result'=>$reply['result']] : ['ok'=>false, 'reason'=>$reply['reason']])];
}

PHP;
file_put_contents($pcRuntime.'/lib/portal_devices.php',substr($pcLib,0,$pcStart).$pcStub.substr($pcLib,$pcEnd));
$pcCache=new EightWest\Id\FileRevocationCache($pcRuntime.'/cache');
$pcFeed=static fn()=>$pcCache->save("https://id.example.test/oauth/revocations.php\0fixture",['fetched_at'=>time(),'generated_at'=>time(),'count'=>0,'authorization_count'=>2,'revoked'=>[],
    'authorizations'=>[$a['identity']['subject']=>$a['identity']['session_version'],$b['identity']['subject']=>$b['identity']['session_version']]]);
$pcId=bin2hex(random_bytes(16));$pcCsrf=str_repeat('c',64);$pcSessionFile=$pcRuntime.'/sessions/sess_'.$pcId;
// Reads, or replaces then reads, the real session file with PHP's own codec; this process's session is restored.
$pcSession=static function(?array $data=null)use($pcSessionFile):array{
    $saved=$_SESSION;
    if($data!==null){$_SESSION=$data;file_put_contents($pcSessionFile,(string)session_encode());}
    $_SESSION=[];session_decode((string)file_get_contents($pcSessionFile));$read=$_SESSION;$_SESSION=$saved;return $read;
};
$pcSession([PORTAL_SESSION_KEY=>$a['identity'],PORTAL_CSRF_KEY=>$pcCsrf]);
$pcDevices=['items'=>[['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk computer','platform'=>'Windows 11','connection'=>'reporting','connection_label'=>'Connected and reporting',
    'connection_help'=>'Fresh check-in received.','last_seen_at'=>'2026-10-03T01:00:00Z','troubleshooting'=>'support_request'],
    ['reference'=>'2:'.str_repeat('b',64),'label'=>'Warehouse laptop','platform'=>'Windows 11','connection'=>'stale','connection_label'=>'Not checking in',
    'connection_help'=>'Keep this computer online.','last_seen_at'=>'2026-10-02T01:00:00Z','troubleshooting'=>'support_request']],'next_after'=>null];
$pcReplies=['devices'=>['status'=>200,'result'=>$pcDevices],'enrollments'=>['status'=>200,'result'=>['items'=>[]]]];
$pcReply=static function(array $replies)use($pcRuntime):void{file_put_contents($pcRuntime.'/mp-replies.json',json_encode($replies,JSON_THROW_ON_ERROR));};
// Every body this controller sent to Milepost, then only the protection ones (a page view still lists devices).
$pcSent=static fn():array=>is_file($pcRuntime.'/mp-requests.log')?array_map(static fn(string $line):array=>json_decode($line,true,16,JSON_THROW_ON_ERROR),
    array_values(array_filter(explode("\n",(string)file_get_contents($pcRuntime.'/mp-requests.log')),'strlen'))):[];
$pcPosts=static fn():array=>array_values(array_filter($pcSent(),static fn(array $sent):bool=>!in_array($sent['action'],['devices','enrollments'],true)));
$pcLast=static function()use($pcPosts):?array{$posts=$pcPosts();return $posts===[]?null:$posts[count($posts)-1];};
$pcSocket=stream_socket_server('tcp://127.0.0.1:0',$pcErrno,$pcErrstr);$pcAddress=stream_socket_get_name($pcSocket,false);fclose($pcSocket);
// One request to the actual controller, judged as a whole (A182): exactly one devices page closed by its real footer
// assets, no PHP warning, notice, deprecation or fatal in the raw or HTML-normalized page, and nothing unexpected in this
// request's own server-log segment. A closing </html> alone proves nothing: a failed footer is followed by an appended
// error page that has one.
$pcFetch=static function(?array $form=null)use($pcAddress,$pcId,$pcFeed,$pcRuntime,$pcFooter):array{
    $pcFeed();
    $log=$pcRuntime.'/http.log';clearstatcache(true,$log);$offset=is_file($log)?(int)filesize($log):0;
    $c=curl_init('http://'.$pcAddress.'/portal/devices.php');
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Cookie: safeharbor_portal='.$pcId]]);
    if($form!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($form));}
    $html=(string)curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);$failure=curl_error($c);curl_close($c);
    // The built-in server logs each request's completion line; wait for it so the segment covers this whole request.
    $done='~\['.$status.'\]: '.($form===null?'GET':'POST').' /portal/devices\.php$~m';
    for($n=0;$n<100;$n++){clearstatcache(true,$log);$segment=(string)file_get_contents($log,false,null,$offset);if(preg_match($done,$segment)===1)break;usleep(20000);}
    $php='/\b(?:Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error)\s*:/';
    $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $problems=[];
    if($status!==200)$problems[]='HTTP '.$status.($failure!==''?' ('.$failure.')':'');
    if(preg_match($php,$html)===1||preg_match($php,$text)===1||str_contains($html,'xdebug-error'))$problems[]='a PHP diagnostic in the page';
    if(preg_match_all('/<!doctype html>/i',$html)!==1||substr_count($html,'</html>')!==1||substr_count($html,'<title>')!==1
        ||!str_contains($html,'<title>Your devices ')||substr_count($html,'<h1>Your devices</h1>')!==1)$problems[]='not exactly one devices page';
    $at=0;
    foreach($pcFooter as $asset=>$tag){$found=strpos($html,$tag,$at);if($found===false||substr_count($html,$tag)!==1){$problems[]='the real footer asset '.$asset.' is missing';break;}$at=$found;}
    if(preg_match('~'.preg_quote($pcFooter['portal-westy.js'],'~').'\s*</body>\s*</html>\s*\z~',$html)!==1)$problems[]='the real footer does not close the page';
    if(preg_match($done,$segment)!==1)$problems[]='no completion line for this request in the server log';
    if(preg_match('/\bPHP (?:Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error)\b/',$segment)===1)$problems[]='a PHP diagnostic in the server log';
    if(str_contains($segment,'[safeharbor-portal-devices] request_failed'))$problems[]='a controller request_failed in the server log';
    return ['status'=>$status,'html'=>$html,'text'=>$text,'log'=>$segment,'problems'=>$problems];
};
// The first cause, not the last bytes: the earliest PHP diagnostic or request failure in this request's log, else in the
// page, with the page start, the page around that cause and the request's log segment.
$pcDiagnose=static function(array $r):string{
    $cause=$r['problems'][0]??'none';
    foreach([$r['log'],$r['text']] as $source){
        if(preg_match('/(?:PHP )?(?:Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error)\s*:[^\n]*|\[safeharbor-portal-devices\] request_failed[^\n]*/',$source,$m)===1){$cause=trim($m[0]);break;}
    }
    $at=preg_match('/Warning|Notice|Deprecated|Fatal error|Parse error/',$r['html'],$m,PREG_OFFSET_CAPTURE)===1?$m[0][1]:0;
    return json_encode(['http'=>$r['status'],'problems'=>$r['problems'],'first_cause'=>$cause,'page_start'=>substr($r['html'],0,1500),
        'page_at_cause'=>substr($r['html'],max(0,$at-1500),3000),'log'=>substr($r['log'],0,8000)],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
};
$pcHttp=static function(?array $form=null)use($pcFetch,$pcDiagnose,$pcRuntime):array{
    // Before the first real request, the deliberate negative: without one real footer asset the controller still sends
    // the devices heading before the footer fails, which the old status/heading/raw "Warning:" check could accept.
    static $pcNegative=false;
    if(!$pcNegative){
        $pcNegative=true;
        $asset=$pcRuntime.'/public/assets/js/portal-desktop.js';
        if(!rename($asset,$asset.'.withheld'))throw new RuntimeException('Footer asset negative unavailable');
        try{$r=$pcFetch();}finally{rename($asset.'.withheld',$asset);}
        $refused=str_contains($r['html'],'<h1>Your devices</h1>')&&in_array('the real footer asset portal-desktop.js is missing',$r['problems'],true)
            &&str_contains($r['log'],'[safeharbor-portal-devices] request_failed');
        if(!$refused)fwrite(STDERR,'Devices HTTP negative diagnostic: '.$pcDiagnose($r)."\n");
        check($refused,'a partial devices page without a real footer asset is refused, with the controller request failure in its own server log');
    }
    $r=$pcFetch($form);
    if($r['problems']!==[])fwrite(STDERR,'Devices HTTP diagnostic: '.$pcDiagnose($r)."\n");
    check($r['problems']===[],'the actual devices.php controller renders one complete page with its real footer and no PHP diagnostic or request failure');
    return [$r['status'],$r['html']];
};
$pcProcess=proc_open([PHP_BINARY,'-d','session.save_path='.$pcRuntime.'/sessions','-S',$pcAddress,'-t',$pcRuntime.'/public'],
    [0=>['file','/dev/null','r'],1=>['file',$pcRuntime.'/http.log','a'],2=>['file',$pcRuntime.'/http.log','a']],$pcPipes);
if(!is_resource($pcProcess))throw new RuntimeException('HTTP server unavailable');
try{
    for($n=0;$n<100;$n++){$pcProbe=@stream_socket_client('tcp://'.$pcAddress,$pcErrno,$pcErrstr,0.1);if($pcProbe){fclose($pcProbe);break;}usleep(20000);}
    $pcReply($pcReplies);
    $pcPrincipal=[$a['identity']['subject'],$a['identity']['session_version'],$a['identity']['binding_id']];
    // Family: the first unknown outcome stores the ORIGINAL key and four facts.
    $pcForm=['device_reference'=>'1:'.str_repeat('a',64),'request_key'=>str_repeat('7',32),'computer'=>'new_computer','same_as_device_reference'=>''];
    $pcFacts=array_replace($pcForm,['same_as_device_reference'=>null]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_request']+$pcForm);
    check($pcPosts()===[['action'=>'protection_request','scope'=>$scope,'input'=>$pcFacts]],'the controller sends exactly the four facts once, with the server scope and a JSON null');
    check(($pcSession()['portal_protection_request']??null)===['principal'=>$pcPrincipal,'input'=>$pcFacts,'result'=>null],'an unknown first outcome keeps the original key and four facts in the session');
    check(str_contains($html,'has no confirmed result')&&str_contains($html,'value="protection_retry"'),'the controller shows the unknown outcome with an explicit retry');
    $pcViews=count($pcSent());[,$html]=$pcHttp();[,$html]=$pcHttp();
    check(count($pcPosts())===1&&count($pcSent())>$pcViews&&str_contains($html,'has no confirmed result'),'GET reloads list devices but send no protection request');
    [,$html]=$pcHttp(['csrf'=>str_repeat('0',64),'action'=>'protection_retry']);
    check(count($pcPosts())===1&&str_contains($html,portal_devices_error('sign_in')),'a CSRF failure sends nothing');
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_request']+array_replace($pcForm,['request_key'=>str_repeat('8',32),'computer'=>'same_computer']));
    check(count($pcPosts())===1&&str_contains($html,portal_devices_error('protection_choice')),'a locally invalid pair sends nothing');
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_request','customer_id'=>$uuid(12)]+array_replace($pcForm,['request_key'=>str_repeat('8',32)]));
    check(count($pcPosts())===1&&str_contains($html,portal_devices_error('protection_choice')),'a browser-chosen extra field sends nothing');
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_request']+array_replace($pcForm,['device_reference'=>'2:'.str_repeat('b',64)]));
    check(count($pcPosts())===1&&str_contains($html,portal_devices_error('owner_request_changed')),'changed facts under the original key send nothing');
    check(($pcSession()['portal_protection_request']??null)===['principal'=>$pcPrincipal,'input'=>$pcFacts,'result'=>null],'refused attempts leave the original key and facts in place');
    // The explicit retry sends the SAME original facts; the browser supplies none.
    $pcReceipt=['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'recorded'];
    $pcReply($pcReplies+['protection_request'=>['status'=>200,'result'=>$pcReceipt]]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_retry']);
    check(count($pcPosts())===2&&$pcPosts()[1]===$pcPosts()[0],'the explicit retry resends the identical original key and facts');
    check(($pcSession()['portal_protection_request']['result']??null)===$pcReceipt&&str_contains($html,'Earlier answer, not checked again: the protection request for Front desk computer was saved.'),'the answer is stored and shown as an earlier answer');
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_retry']);
    check(count($pcPosts())===2&&str_contains($html,portal_devices_error('protection_retry_unavailable')),'a confirmed request cannot be resent');
    // A changed principal: the slot is discarded and nothing is sent.
    $pcReply($pcReplies);
    $pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_request']+array_replace($pcForm,['request_key'=>str_repeat('6',32)]));
    check(count($pcPosts())===3&&($pcSession()['portal_protection_request']??null)===['principal'=>$pcPrincipal,'input'=>array_replace($pcFacts,['request_key'=>str_repeat('6',32)]),'result'=>null],'a second explicit intent with its own key is unknown');
    $pcState=$pcSession();$pcState[PORTAL_SESSION_KEY]=$b['identity'];$pcSession($pcState);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_retry']);
    check(count($pcPosts())===3&&str_contains($html,portal_devices_error('protection_retry_unavailable'))&&!array_key_exists('portal_protection_request',$pcSession()),'a changed principal discards the stored request and sends nothing');
    $pcSession([PORTAL_SESSION_KEY=>$a['identity'],PORTAL_CSRF_KEY=>$pcCsrf]);
    // SBE: explicit pages, then the five facts; an unknown outcome is checked with the SAME facts.
    $pcReply($pcReplies+['employee_protection_choices'=>['status'=>200,'result'=>$emFirst]]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_choices','device_reference'=>'1:'.str_repeat('a',64),'cursor'=>'']);
    check($pcLast()===['action'=>'employee_protection_choices','scope'=>$scope,'input'=>['device_reference'=>'1:'.str_repeat('a',64),'cursor'=>null]],'the controller asks for the first page with a JSON null cursor');
    check(str_contains($html,'<select name="employee_reference" required><option value="">Choose an employee</option><option value="'.$emRef(1).'">Employee 1</option>')
        &&str_contains($html,'name="cursor" value="'.$emCursor.'"'),'the actual page offers a blank choice and a next-page control');
    $pcReply($pcReplies+['employee_protection_choices'=>['status'=>200,'result'=>$emLast]]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_choices','device_reference'=>'1:'.str_repeat('a',64),'cursor'=>$emCursor]);
    check($pcLast()['input']['cursor']===$emCursor&&str_contains($html,'>Employee 21</option>')&&!str_contains($html,'More employees'),'the next page relays the original cursor and reaches the member beyond 20');
    $pcSends=count($pcPosts());
    $pcEmployeeForm=['device_reference'=>'1:'.str_repeat('a',64),'request_key'=>str_repeat('5',32),'computer'=>'new_computer','same_as_device_reference'=>'','employee_reference'=>$emRef(21)];
    $pcEmployeeFacts=array_replace($pcEmployeeForm,['same_as_device_reference'=>null]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_request']+array_replace($pcEmployeeForm,['employee_reference'=>'']));
    check(count($pcPosts())===$pcSends&&str_contains($html,portal_devices_error('employee_choice')),'a blank employee choice sends nothing');
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_request']+$pcEmployeeForm);
    check(count($pcPosts())===$pcSends+1&&$pcLast()===['action'=>'employee_protection_request','scope'=>$scope,'input'=>$pcEmployeeFacts],'the controller sends exactly the five facts once');
    check(($pcSession()['portal_employee_protection_request']??null)===['principal'=>$pcPrincipal,'input'=>$pcEmployeeFacts,'result'=>null]
        &&($pcSession()['portal_protection_request']??null)===null,'an unknown employee outcome keeps the original key and five facts in their own slot');
    check(str_contains($html,'value="employee_status"')&&!str_contains($html,'value="employee_retry"'),'an unknown employee outcome offers only a status check');
    [,$html]=$pcHttp();
    check(count($pcPosts())===$pcSends+1,'a GET reload sends no employee request or status');
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_request']+array_replace($pcEmployeeForm,['employee_reference'=>$emRef(20)]));
    check(count($pcPosts())===$pcSends+1&&str_contains($html,portal_devices_error('owner_request_changed')),'another employee under the original key sends nothing');
    $pcAbsent=['reference'=>null,'computer'=>'new_computer','state'=>'absent'];
    $pcReply($pcReplies+['employee_protection_status'=>['status'=>200,'result'=>$pcAbsent]]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_status']);
    check(count($pcPosts())===$pcSends+2&&$pcLast()===['action'=>'employee_protection_status','scope'=>$scope,'input'=>$pcEmployeeFacts],'the explicit status check sends the same five facts');
    check(($pcSession()['portal_employee_protection_request']['result']??null)===$pcAbsent&&str_contains($html,'no request for an employee on Front desk computer was saved')
        &&str_contains($html,'value="employee_retry"'),'a proven absence is stored and offers the same request again');
    $pcEmployeeReceipt=['reference'=>str_repeat('4',32),'computer'=>'new_computer','state'=>'recorded'];
    $pcReply($pcReplies+['employee_protection_request'=>['status'=>200,'result'=>$pcEmployeeReceipt]]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_retry']);
    check(count($pcPosts())===$pcSends+3&&$pcLast()===['action'=>'employee_protection_request','scope'=>$scope,'input'=>$pcEmployeeFacts],'sending again after absence uses the same key and five facts');
    check(($pcSession()['portal_employee_protection_request']['result']??null)===$pcEmployeeReceipt&&str_contains($html,'Earlier answer, not checked again: the request for an employee on Front desk computer was saved.'),'the employee answer is stored and shown as an earlier answer');
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'employee_status']);
    check(count($pcPosts())===$pcSends+3&&str_contains($html,portal_devices_error('protection_retry_unavailable')),'a saved employee request is not checked or resent again');
    // Family and employee share the neutral refusal when the earlier record belongs to someone else.
    $pcReply($pcReplies+['protection_request'=>['status'=>409,'reason'=>'beneficiary_changed']]);
    [,$html]=$pcHttp(['csrf'=>$pcCsrf,'action'=>'protection_request']+array_replace($pcForm,['request_key'=>str_repeat('3',32),'computer'=>'same_computer','same_as_device_reference'=>'2:'.str_repeat('b',64)]));
    check(str_contains($html,portal_devices_error('beneficiary_changed'))&&!str_contains($html,'beneficiary_changed'),'the controller shows the neutral refusal without its code');
    check(array_values(array_unique(array_column($pcPosts(),'action')))===['protection_request','employee_protection_choices','employee_protection_request','employee_protection_status'],'the controller sent no install, enrollment or order action');
}finally{
    proc_terminate($pcProcess);proc_close($pcProcess);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pcRuntime,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $pcFile)$pcFile->isDir()?rmdir($pcFile->getPathname()):unlink($pcFile->getPathname());
    rmdir($pcRuntime);
}
require __DIR__.'/portal_device_operations_scenarios.php';
require __DIR__.'/portal_security_orders_scenarios.php';
echo 'PASS portal devices MySQL: '.$checks." checks\n";
