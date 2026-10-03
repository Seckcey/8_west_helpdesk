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
function portal_csrf_token():string{return str_repeat('c',64);}
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
$devices=['items'=>[['reference'=>'1:'.str_repeat('a',64),'label'=>'<script>untrusted label</script>','platform'=>'Windows 11','connection'=>'reporting','connection_label'=>'Connected and reporting','connection_help'=>'Fresh check-in received.','last_seen_at'=>'2026-10-03T01:00:00Z','troubleshooting'=>'support_request']],'next_after'=>null];
ob_start();portal_render_devices($a,$devices,[$grant]);$html=ob_get_clean();
check(str_contains($html,'&lt;script&gt;untrusted label&lt;/script&gt;')&&!str_contains($html,'<script>untrusted label</script>'),'device labels are escaped');
check(str_contains($html,'Create Windows setup link')&&str_contains($html,'name="consent"'),'owner sees real consent and enrollment controls');
$viewer=$a;$viewer['identity']['role']='client_viewer';
ob_start();portal_render_devices($viewer,$devices,[$grant]);$html=ob_get_clean();
check(!str_contains($html,'Create Windows setup link')&&!str_contains($html,'value="enrollment_revoke"'),'viewer has no mutation controls');
require __DIR__.'/portal_device_operations_scenarios.php';
echo 'PASS portal devices MySQL: '.$checks." checks\n";
