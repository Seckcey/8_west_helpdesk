<?php
/** Real customer binding and lifecycle schema, captured signed mobile transport. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'safeharbor_westy_test';
if(!preg_match('/^safeharbor_westy_test(?:_[a-z0-9_]+)?$/D',$base))exit(2);
$database=$base.'_mobile_'.bin2hex(random_bytes(3));
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1';$port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root',getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE `'.$database.'`');$pdo->exec('USE `'.$database.'`');
register_shutdown_function(static function()use($pdo,$database):void{$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
$settings=['portal_mobile'=>['enabled'=>true],'portal_devices'=>['enabled'=>true,'endpoint'=>'https://support.8westit.com/api/svc/customer_portal.php','secret'=>str_repeat('e',64)]];
function cfg(string $key,mixed $default=null):mixed {global $settings;$v=$settings;foreach(explode('.',$key)as$part){if(!is_array($v)||!array_key_exists($part,$v))return $default;$v=$v[$part];}return $v;}
require __DIR__.'/portal_westy_fixture.php';require __DIR__.'/../lib/portal_mobile.php';
portal_westy_fixture_sql($pdo,__DIR__.'/../db/schema.sql');[$a,$b,$c]=portal_westy_fixture_seed($pdo);
$uuid=static fn(int $n):string=>sprintf('00000000-0000-4000-8000-%012d',$n);
foreach([$a,$b,$c]as$context){$i=$context['identity'];$pdo->prepare('INSERT INTO suite_customer_sync_bindings(tenant_id,customer_id,client_id,source_version,display_name,status,last_event_id,last_occurred_at,last_request_sha256) VALUES(?,?,?,1,?,"active",?,?,?)')->execute([$i['tenant_id'],$uuid($i['client_id']),$i['client_id'],$context['binding']['client_name'],$uuid(100+$i['client_id']),gmdate('Y-m-d H:i:s'),str_repeat('a',64)]);}
$checks=0;
function pm_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function pm_refused(callable $fn):bool{try{$fn();return false;}catch(PortalDevicesException){return true;}}
$seen=[];$calls=0;$projection=['providers'=>['android'=>'setup_required','intune'=>'setup_required'],'items'=>[],'next_offset'=>null];
$transport=static function($url,$body,$headers)use(&$seen,&$calls,$projection):array{$calls++;$seen=[$url,json_decode($body,true),$headers];return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_MOBILE_CONTEXT,'result'=>$projection])];};
pm_check(portal_mobile_request($pdo,$a,'devices',['offset'=>0],$transport)===$projection,'signed inventory accepted');
pm_check($seen[0]===PORTAL_MOBILE_ENDPOINT&&$seen[1]['scope']===portal_devices_scope($pdo,$a),'customer scope derives from authenticated binding');
$headers=[];foreach($seen[2]as$h){[$k,$v]=explode(': ',$h,2);$headers[$k]=$v;}
$body=json_encode($seen[1]);$preimage=PORTAL_MOBILE_CONTEXT."\nPOST\n/api/svc/customer_mobile.php\n".$headers['X-Portal-Timestamp']."\n".$headers['X-Portal-Nonce']."\n".hash('sha256',$body);
pm_check(hash_equals(hash_hmac('sha256',$preimage,str_repeat('e',64)),$headers['X-Portal-Signature']),'mobile HMAC domain separation');
foreach(['client_id'=>12,'tenant_id'=>2,'role'=>'msp_owner']as$field=>$value){$bad=$a;$bad['identity'][$field]=$value;$before=$calls;pm_check(pm_refused(fn()=>portal_mobile_request($pdo,$bad,'devices',['offset'=>0],$transport))&&$calls===$before,'forged context refused '.$field);}
foreach(['wipe','lock','enrollment_create','execute']as$action){$before=$calls;pm_check(pm_refused(fn()=>portal_mobile_request($pdo,$a,$action,[],$transport))&&$calls===$before,'device command refused '.$action);}
$wrongContract=static fn($u,$b,$h)=>['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DEVICES_CONTEXT,'result'=>$projection])];
pm_check(pm_refused(fn()=>portal_mobile_request($pdo,$a,'devices',['offset'=>0],$wrongContract)),'desktop response cannot replace mobile contract');
$revoking=static function(...$args)use($pdo,$a,$transport){portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'disabled','Synthetic revocation during mobile read.');return $transport(...$args);};
pm_check(pm_refused(fn()=>portal_mobile_request($pdo,$a,'devices',['offset'=>0],$revoking)),'mid-request binding revocation prevents disclosure');
echo "Portal mobile MySQL checks: $checks passed\n";
