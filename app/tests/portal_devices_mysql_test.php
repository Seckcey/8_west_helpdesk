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
check(!str_contains($html,'Protection request'),'viewer has no protection request controls');
$recorded=['principal'=>[],'input'=>$prNew,'result'=>['reference'=>str_repeat('9',32),'computer'=>'new_computer','state'=>'recorded']];
ob_start();portal_render_devices($a,$devices,[],null,null,null,$recorded);$html=ob_get_clean();
check(str_contains($html,'value="protection_request"')&&preg_match_all('/name="request_key" value="([a-f0-9]{32})"/',$html,$keys)===2&&$keys[1][0]!==$keys[1][1],'owner sees the protection request form with its own fresh server key');
check(str_contains($html,'<option value="1:'.str_repeat('a',64).'">&lt;script&gt;untrusted label&lt;/script&gt;</option>')&&!str_contains($html,'<option value="1:'.str_repeat('a',64).'"><script>'),'request choices use server references and escaped labels');
check(str_contains($html,'Protection request recorded for &lt;script&gt;')&&!str_contains($html,'value="protection_retry"'),'a recorded answer is shown without a retry');
check(!str_contains($html,'Install on your Windows computer')&&!str_contains($html,'download.php'),'a recorded protection request shows no installation');
ob_start();portal_render_devices($a,$devices,[],null,null,null,['result'=>null]+$recorded);$html=ob_get_clean();
check(str_contains($html,'has no confirmed result')&&!str_contains($html,'Protection request recorded'),'an unknown outcome is not shown as recorded');
check(preg_match('~<form[^>]*>(?:(?!</form>).)*value="protection_retry"(?:(?!</form>).)*</form>~s',$html,$retry)===1&&!str_contains($retry[0],'request_key')&&!str_contains($retry[0],'device_reference'),'the explicit retry carries no browser key or inputs');
ob_start();portal_render_devices($delegated,$devices,[]);$html=ob_get_clean();
check(!str_contains($html,'Protection request'),'delegated access has no protection request controls');
require __DIR__.'/portal_device_operations_scenarios.php';
require __DIR__.'/portal_security_orders_scenarios.php';
echo 'PASS portal devices MySQL: '.$checks." checks\n";
