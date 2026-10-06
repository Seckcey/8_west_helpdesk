<?php
/** Real bindings, sessions, private chat and companion handoffs; synthetic signed-service transport. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'safeharbor_westy_test';
if(!preg_match('/^safeharbor_westy_test(?:_[a-z0-9_]+)?$/D',$base))exit(2);
$database=$base.'_access_'.bin2hex(random_bytes(3));
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1';$port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root',getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE `'.$database.'`');$pdo->exec('USE `'.$database.'`');$pdo->exec("SET time_zone='+00:00'");
register_shutdown_function(static function()use($pdo,$database):void{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
$settings=['app_env'=>'dev','portal'=>['issuer'=>'https://id.example.test','revocation_cache_dir'=>sys_get_temp_dir().'/access-test','session_lifetime_seconds'=>28800],
    'portal_devices'=>['enabled'=>true,'endpoint'=>'https://support.8westit.com/api/svc/customer_portal.php','secret'=>str_repeat('e',64)]];
function cfg(string $key,mixed $default=null):mixed {global $settings;$v=$settings;foreach(explode('.',$key) as $part){if(!is_array($v)||!array_key_exists($part,$v))return $default;$v=$v[$part];}return $v;}
require_once __DIR__.'/../lib/portal_auth.php';
require __DIR__.'/portal_westy_fixture.php';
require __DIR__.'/../lib/portal_desktop_sessions.php';
session_save_path(sys_get_temp_dir());portal_session_start();
register_shutdown_function(static function():void{if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});
portal_westy_fixture_sql($pdo,__DIR__.'/../db/schema.sql');[$a,$b,$c]=portal_westy_fixture_seed($pdo);
$uuid=static fn(int $n):string=>sprintf('00000000-0000-4000-8000-%012d',$n);
foreach([$a,$b,$c] as $ctx){$i=$ctx['identity'];$pdo->prepare('INSERT INTO suite_customer_sync_bindings(tenant_id,customer_id,client_id,source_version,display_name,status,last_event_id,last_occurred_at,last_request_sha256) VALUES(?,?,?,1,?,"active",?,?,?)')
    ->execute([$i['tenant_id'],$uuid(1000+$i['client_id']),$i['client_id'],$ctx['binding']['client_name'],$uuid(2000+$i['client_id']),gmdate('Y-m-d H:i:s'),str_repeat('a',64)]);}
$checks=0;
function access_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function access_denied(callable $fn):bool{try{$fn();return false;}catch(PortalDevicesException|PortalWestyException|PortalAuthenticationRejectedException|PortalDesktopException|PortalIdentityUnavailableException){return true;}}
function access_identity(string $role='owner',string $tenant='8west',array $products=['safeharbor']):EightWest\Id\Identity {
    return new EightWest\Id\Identity('t1u1','person@example.test','Synthetic Owner',$tenant,'1','1.1',$products,$role,'owner','light',null,[],
        true,true,time(),time(),['pwd','otp'],'suite-mfa-v1',new EightWest\Id\MfaPolicyResult(true,'compliant'));
}
$choice=['provider_slug'=>'preview-provider','customer_id'=>$uuid(1011),'identity_tenant_slug'=>'northwind-preview','role'=>'client_owner',
    'access'=>['reference'=>str_repeat('1',32),'generation'=>1,'identity_tenant_slug'=>'8west','identity_role'=>'owner']];
$items=[];$seen=[];
$transport=static function(string $url,string $body,array $headers)use(&$items,&$seen):array {
    $seen=[$url,json_decode($body,true),$headers,$body];
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>['items'=>$items]])];
};
access_check(access_denied(fn()=>portal_establish_identity($pdo,access_identity(),null,'/portal/',$transport)),'ungranted owner gets no implicit client access');
$items=[$choice];
access_check(access_denied(fn()=>portal_establish_identity($pdo,access_identity(products:[]),null,'/portal/',$transport)),'membership cannot bypass product entitlement');
$before=session_id();$identity=portal_establish_identity($pdo,access_identity(),null,'/portal/',$transport);
access_check($identity['role']==='owner' && $identity['identity_tenant_slug']==='8west' && $identity['subject']==='t1u1' && $identity['session_version']==='1.1','signed identity role tenant subject and session remain unchanged');
access_check($identity['client_id']===11 && $identity['binding_id']===$a['identity']['binding_id'] && session_id()!==$before,'UUID maps to independent local binding with portal-only session rotation');
access_check(portal_local_identity()!==null && portal_customer_role($identity)==='client_owner','effective customer role is separate from original owner role');
access_check($seen[1]===['action'=>'access_list','scope'=>['subject'=>'t1u1','session_version'=>'1.1','identity_tenant_slug'=>'8west','role'=>'owner'],'input'=>[]],'service discovery contains server identity only');
$headers=[];foreach($seen[2] as $line){[$k,$v]=explode(': ',$line,2);$headers[$k]=$v;}
$preimage=PORTAL_DEVICES_CONTEXT."\nPOST\n/api/svc/customer_portal.php\n".$headers['X-Portal-Timestamp']."\n".$headers['X-Portal-Nonce']."\n".hash('sha256',$seen[3]);
access_check(hash_equals(hash_hmac('sha256',$preimage,str_repeat('e',64)),$headers['X-Portal-Signature']),'dedicated HMAC covers principal and exact body');
$ctx=portal_authenticated_context($pdo,fn()=>false,null,$transport);
access_check($ctx!==null && portal_devices_can_manage($ctx),'active independent membership authorizes customer tools');
$scope=portal_devices_scope($pdo,$ctx,$transport);
access_check($scope['role']==='client_owner' && $scope['identity_tenant_slug']==='northwind-preview' && $scope['access']['identity_role']==='owner','tools carry business scope plus original signed principal');
$chat=portal_westy_scope($pdo,$ctx,false,$transport);
$account=portal_westy_account($pdo,$chat,true);
access_check($account!==null,'membership creates an individually scoped conversation');
foreach(['client_id'=>12,'tenant_id'=>2,'binding_id'=>$b['identity']['binding_id']] as $field=>$value){
    $bad=$identity;$bad[$field]=$value;
    access_check(portal_identity_binding($pdo,$bad,$transport)===null,'tampered '.$field.' cannot redirect customer context');
}
$bad=$choice;$bad['client_id']=11;$items=[$bad];
access_check(access_denied(fn()=>portal_access_choices($pdo,$identity,$transport)),'unexpected local numeric ID in discovery refused');
$bad=$choice;$bad['access']['identity_role']='admin';$items=[$bad];
access_check(access_denied(fn()=>portal_access_choices($pdo,$identity,$transport)),'service role projection cannot replace signed identity role');
$bad=$choice;$bad['customer_id']=$uuid(1012);$items=[$bad];
access_check(portal_access_choices($pdo,$identity,$transport)===[],'mismatched UUID and customer identity binding refused');
$second=$choice;$second['customer_id']=$uuid(1012);$second['identity_tenant_slug']='other-preview';$second['access']['reference']=str_repeat('2',32);
$items=[$choice,$second];$destination='/portal/desktop_authorize.php?pair='.str_repeat('a',32);
access_check(portal_establish_identity($pdo,access_identity(),null,$destination,$transport)===[] && !isset($_SESSION[PORTAL_SESSION_KEY]),'multiple memberships require explicit selection');
access_check($_SESSION[PORTAL_ACCESS_PENDING_KEY]['destination']===$destination,'companion return destination survives customer chooser');
$principal=$_SESSION[PORTAL_ACCESS_PENDING_KEY]['principal'];
access_check(access_denied(fn()=>portal_access_select($pdo,$principal,$choice['access']['reference'],2,$transport)),'stale generation cannot be selected');
$identity=portal_access_select($pdo,$principal,$choice['access']['reference'],1,$transport);$ctx=['identity'=>$identity,'binding'=>$a['binding']];
$items=[$second];
access_check(portal_identity_binding($pdo,$identity,$transport)===null,'revoking selected membership never switches to another client');
access_check(access_denied(fn()=>portal_westy_scope($pdo,$ctx,false,$transport)),'revoked membership loses chat and tool scope');
$items=[$choice];
$service=static function()use(&$items):array{$items=[];return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>['items'=>[],'next_after'=>null]])];};
access_check(access_denied(fn()=>portal_devices_request($pdo,$ctx,'devices',['after'=>0],$service,$transport)),'membership revoked during tool call prevents response disclosure');
$items=[$choice];$items[0]['access']['generation']=3;
$new=portal_access_select($pdo,$principal,$choice['access']['reference'],3,$transport);
$newChat=portal_westy_scope($pdo,['identity'=>$new],false,$transport);
access_check($newChat['key']!==$chat['key'] && portal_westy_account($pdo,$newChat)===null,'regrant cannot acquire prior private conversation or tool intents');
// Real one-time companion handoff stores the approved original membership.
$handoff=bin2hex(random_bytes(16));$pair=bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO portal_desktop_handoffs(handoff_id,pairing_id,browser_session_hash,state,identity_json,created_at,expires_at) VALUES(?,?,?,'approved',?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 5 MINUTE)")
    ->execute([$handoff,$pair,hash('sha256',session_id()),json_encode($identity)]);
access_check(access_denied(fn()=>portal_desktop_handoff_take($pdo,$handoff,fn()=>false,$transport)),'old companion handoff cannot survive membership generation change');
access_check(!isset($_SESSION[PORTAL_SESSION_KEY]) && !isset($_SESSION['desktop_companion_session']),'failed companion handoff retains no customer authority');
portal_session_start();$handoff=bin2hex(random_bytes(16));$pair=bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO portal_desktop_handoffs(handoff_id,pairing_id,browser_session_hash,state,identity_json,created_at,expires_at) VALUES(?,?,?,'approved',?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 5 MINUTE)")
    ->execute([$handoff,$pair,hash('sha256',session_id()),json_encode($new)]);
access_check(portal_desktop_handoff_take($pdo,$handoff,fn()=>false,$transport),'current membership completes exact companion handoff');
access_check($_SESSION[PORTAL_SESSION_KEY]['role']==='owner' && $_SESSION['desktop_companion_session']===$pair,'companion keeps original identity and separate customer permission');
access_check(access_denied(fn()=>portal_desktop_handoff_take($pdo,$handoff,fn()=>false,$transport)),'consumed handoff cannot replay');
// A normal customer identity uses its existing path without contacting membership discovery.
$never=static fn()=>throw new RuntimeException('Legacy path called discovery');
$legacy=portal_establish_identity($pdo,access_identity('client_owner','northwind-preview'),null,'/portal/',$never);
access_check(!isset($legacy['customer_access']) && $legacy['client_id']===11,'legacy customer sign-in remains compatible');
$_SESSION[PORTAL_SESSION_KEY]=$new;
access_check(portal_authenticated_context($pdo,fn()=>true,null,$transport)===null,'central ID revocation still terminates the portal context');
echo "PASS portal customer access MySQL: $checks checks\n";
