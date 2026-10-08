<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ob_start();
$host=getenv('MILEPOST_TEST_DB_HOST');$base=getenv('MILEPOST_TEST_DB_NAME');
if(!$host||!preg_match('/\Amilepost_[a-z0-9_]*test[a-z0-9_]*\z/D',(string)$base)||getenv('MILEPOST_TEST_DB_ALLOW_DROP')!=='YES')exit(2);
$schema=$base.'_shhandoff_'.bin2hex(random_bytes(3));$cache=sys_get_temp_dir().'/sh-handoff-'.bin2hex(random_bytes(5));
$config=['app_env'=>'dev','portal'=>['enabled'=>true,'issuer'=>'https://id.example.test','client_id'=>'handoff-fixture',
 'client_secret'=>str_repeat('s',48),'redirect_uri'=>'https://safeharbor.example.test/portal/callback.php','cookie_secure'=>true,
 'revocation_cache_dir'=>$cache,'reserved_identity_tenant_slugs'=>[]]];
function cfg(string $key,mixed $default=null):mixed{global $config;$v=$config;foreach(explode('.',$key) as $k){if(!is_array($v)||!array_key_exists($k,$v))return $default;$v=$v[$k];}return $v;}
$pdo=new PDO('mysql:host='.$host,'root',getenv('MILEPOST_TEST_DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function db():PDO{global $pdo;return $pdo;}
$pdo->exec('CREATE DATABASE `'.$schema.'`');$pdo->exec('USE `'.$schema.'`');
register_shutdown_function(static function()use($pdo,$schema,$cache){
 if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
 foreach(glob($cache.'/*')?:[] as $file)unlink($file);if(is_dir($cache))rmdir($cache);
});
foreach([
 'CREATE TABLE tenants(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(64))',
 'CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,name VARCHAR(128))',
 'CREATE TABLE suite_managed_providers(tenant_id INT UNSIGNED PRIMARY KEY)',
 'CREATE TABLE customer_portal_bindings(id INT UNSIGNED PRIMARY KEY,identity_tenant_slug VARCHAR(64),tenant_id INT UNSIGNED,client_id INT UNSIGNED,status VARCHAR(24),prepared_by_user_id INT,prepared_at DATETIME,last_changed_by_user_id INT,status_changed_at DATETIME,status_reason VARCHAR(255))',
 'CREATE TABLE suite_customer_sync_bindings(id INT,tenant_id INT,client_id INT,customer_id CHAR(36),source_version INT,last_event_id INT,status VARCHAR(24))',
 'CREATE TABLE suite_customer_sync_events(tenant_id INT,binding_id INT,status VARCHAR(24))',
 'CREATE TABLE managed_customer_lifecycle_restore_receipts(tenant_id INT,source_binding_id INT,client_id INT,customer_id CHAR(36),source_version INT,source_event_id INT)',
] as $sql)$pdo->exec($sql.' ENGINE=InnoDB');
$pdo->exec(file_get_contents(__DIR__.'/../db/migrations/desktop_portal_sessions_v1.sql'));
$pdo->exec("INSERT INTO tenants VALUES(1,'8west'); INSERT INTO clients VALUES(10,1,'Synthetic business'); INSERT INTO customer_portal_bindings VALUES(1,'alpha',1,10,'active',1,UTC_TIMESTAMP(),1,UTC_TIMESTAMP(),'fixture')");
require_once __DIR__.'/../lib/portal_desktop_sessions.php';
$checks=0;
function hs_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function hs_denied(callable $run):bool{try{$run();return false;}catch(PortalDesktopException){return true;}}
function hs_feed(string $version='1.1'):void{global $cache;(new EightWest\Id\FileRevocationCache($cache))->save("https://id.example.test/oauth/revocations.php\0handoff-fixture",
 ['fetched_at'=>time(),'generated_at'=>time(),'count'=>0,'authorization_count'=>1,'revoked'=>[],'authorizations'=>['t10u12'=>$version]]);}
function hs_identity():array{return ['subject'=>'t10u12','session_version'=>'1.1','identity_tenant_slug'=>'alpha','role'=>'client_owner','display_name'=>'Synthetic Person',
 'binding_id'=>1,'tenant_id'=>1,'client_id'=>10,'issued_at'=>time()-30,'expires_at'=>time()+1200];}
function hs_approve_fixture(PDO $pdo,string $id,array $identity):void{$pdo->prepare("UPDATE portal_desktop_handoffs SET state='approved',identity_json=? WHERE handoff_id=?")->execute([json_encode($identity),$id]);}
hs_feed();portal_session_start();$first=session_id();
$id=portal_desktop_handoff_create($pdo,str_repeat('a',32));
hs_check(portal_desktop_handoff_create($pdo,str_repeat('a',32))===$id,'same pair and browser idempotent');
hs_check(!portal_desktop_handoff_take($pdo,$id),'pending handoff cannot authenticate');
hs_approve_fixture($pdo,$id,hs_identity());
session_write_close();session_id('');portal_session_start();
hs_check(hs_denied(fn()=>portal_desktop_handoff_take($pdo,$id)),'other browser cannot consume approved handoff');
session_write_close();session_id($first);portal_session_start();
$handoffCsrf=portal_csrf_token();
hs_check(portal_desktop_handoff_take($pdo,$id),'approved one-use identity creates new session');
hs_check(session_id()!==$first,'native web session id rotated');
hs_check(portal_local_identity()['subject']==='t10u12','verified approved subject preserved');
hs_check($_SESSION['desktop_companion_session']===str_repeat('a',32),'native origin bound to pair');
$row=$pdo->query('SELECT state,identity_json FROM portal_desktop_handoffs')->fetch();
hs_check($row['state']==='consumed'&&$row['identity_json']===null,'handoff consumed and identity bytes erased');
$consumedSession=session_id();$consumedIdentity=portal_local_identity();
hs_check(portal_desktop_handoff_completed($pdo,$id,$handoffCsrf),'lost consume body can be acknowledged by same regenerated session and original page CSRF');
hs_check(!portal_desktop_handoff_completed($pdo,$id,str_repeat('f',64)),'unrelated CSRF cannot acknowledge a completed handoff');
hs_check(!portal_desktop_handoff_completed($pdo,str_repeat('f',32),$handoffCsrf),'another handoff cannot borrow completion');
$completion=$_SESSION['desktop_handoff_completed'];
foreach(['session_hash'=>str_repeat('f',64),'pairing_id'=>str_repeat('f',32),'expires_at'=>time()-1,'identity_hash'=>str_repeat('f',64)] as $field=>$invalid){
    $_SESSION['desktop_handoff_completed']=array_replace($completion,[$field=>$invalid]);
    hs_check(!portal_desktop_handoff_completed($pdo,$id,$handoffCsrf),'completed receipt remains bound to current authority: '.$field);
}
$_SESSION['desktop_handoff_completed']=$completion;
hs_check(portal_desktop_handoff_take($pdo,$id)&&session_id()===$consumedSession&&portal_local_identity()===$consumedIdentity
    &&$pdo->query('SELECT state,identity_json FROM portal_desktop_handoffs')->fetch()===$row,'duplicate status read never mints a second session or reconsumes authority');
$context=portal_authenticated_context($pdo);$scope=portal_westy_scope($pdo,$context);
$pdo->prepare('INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,conversation_id,operation_key,origin_channel,origin_session_hash,task_id,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
 ->execute([str_repeat('a',32),1,10,'t10u12',$scope['key'],str_repeat('1',32),str_repeat('2',32),'companion',hash('sha256',session_id()),str_repeat('3',32),gmdate('Y-m-d H:i:s',time()+300)]);
hs_check(portal_desktop_context($pdo,$context,str_repeat('1',32))['desktop']['operation_key']===str_repeat('2',32),'continuation returns authoritative original operation');
hs_check(portal_desktop_context($pdo,$context,str_repeat('1',32),str_repeat('2',32))['desktop']['task_id']===str_repeat('3',32),'exact operation returns original native task');
hs_check(!isset(portal_desktop_context($pdo,$context,str_repeat('1',32),str_repeat('4',32))['desktop']),'wrong operation cannot obtain native task');
hs_check(!isset(portal_desktop_context($pdo,$context,str_repeat('4',32))['desktop']),'other conversation cannot obtain native task');
$pdo->exec("INSERT INTO portal_desktop_bindings SELECT REPEAT('b',32),tenant_id,client_id,subject,scope_key,conversation_id,operation_key,origin_channel,origin_session_hash,task_id,expires_at FROM portal_desktop_bindings");
hs_check(hs_denied(fn()=>portal_desktop_context($pdo,$context,str_repeat('1',32),str_repeat('2',32))),'ambiguous exact operation raises unavailable for Stop');
$pdo->exec("DELETE FROM portal_desktop_bindings WHERE session_id=REPEAT('b',32)");
$pdo->exec("UPDATE portal_desktop_bindings SET origin_session_hash=REPEAT('0',64)");
hs_check(!isset(portal_desktop_context($pdo,$context,str_repeat('1',32))['desktop']),'other browser session cannot obtain native task');
unset($_SESSION['desktop_handoff_completed']);
hs_check(hs_denied(fn()=>portal_desktop_handoff_take($pdo,$id)),'consumed handoff without exact same-session completion cannot replay');
unset($_SESSION[PORTAL_SESSION_KEY],$_SESSION['desktop_companion_session']);
$id=portal_desktop_handoff_create($pdo,str_repeat('b',32));hs_approve_fixture($pdo,$id,hs_identity());hs_feed('2.1');
hs_check(hs_denied(fn()=>portal_desktop_handoff_take($pdo,$id)),'fresh session-version revocation denies mint');
hs_check(portal_local_identity()===null,'revoked handoff leaves no authenticated native session');
hs_feed();portal_session_start();$id=portal_desktop_handoff_create($pdo,str_repeat('c',32));hs_approve_fixture($pdo,$id,hs_identity());
$pdo->exec("UPDATE customer_portal_bindings SET status='inactive'");
hs_check(hs_denied(fn()=>portal_desktop_handoff_take($pdo,$id)),'fresh customer binding invalidation denies mint');
hs_check(portal_local_identity()===null,'invalid binding leaves no native session');
$pdo->exec("UPDATE customer_portal_bindings SET status='active'");portal_session_start();
$id=portal_desktop_handoff_create($pdo,str_repeat('d',32));hs_approve_fixture($pdo,$id,hs_identity());
$pdo->prepare('UPDATE portal_desktop_handoffs SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE handoff_id=?')->execute([$id]);
hs_check(hs_denied(fn()=>portal_desktop_handoff_take($pdo,$id)),'expired approved handoff denied');
// A real restricted runtime account, not a root-backed mock, must perform cleanup.
// Its only DELETE privileges are the two new temporary-authority tables.
$runtimeUser='desktop_test_'.bin2hex(random_bytes(5));$runtimePassword=bin2hex(random_bytes(24));
$account=$pdo->quote($runtimeUser)."@'%'";
$pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($runtimePassword));
try {
    $pdo->exec('GRANT SELECT,INSERT,UPDATE ON `'.$schema.'`.* TO '.$account);
    foreach(['portal_desktop_bindings','portal_desktop_handoffs'] as $table)
        $pdo->exec('GRANT DELETE ON `'.$schema.'`.`'.$table.'` TO '.$account);
    $runtime=new PDO('mysql:host='.$host.';dbname='.$schema,$runtimeUser,$runtimePassword,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("UPDATE portal_desktop_bindings SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND");
    $before=(int)$pdo->query('SELECT COUNT(*) FROM portal_desktop_handoffs WHERE expires_at>UTC_TIMESTAMP()')->fetchColumn();
    $counts=portal_desktop_prune($runtime);
    hs_check($counts['handoffs']===1&&$counts['bindings']===1,'actual restricted account deletes expired authority');
    hs_check((int)$pdo->query('SELECT COUNT(*) FROM portal_desktop_handoffs')->fetchColumn()===$before,'cleanup preserves live handoffs');
    hs_check(hs_denied(fn()=>portal_desktop_handoff_take($pdo,$id)),'deleted expired handoff cannot replay');
    foreach(['clients','suite_customer_sync_events','managed_customer_lifecycle_restore_receipts'] as $table){
        $denied=false;
        try{$runtime->exec('DELETE FROM `'.$table.'`');}catch(PDOException $e){$denied=($e->errorInfo[1]??null)===1142;}
        hs_check($denied,'runtime cannot delete legacy '.$table);
    }
    $denied=false;
    try{$runtime->exec('CREATE TABLE forbidden_runtime_ddl(id INT)');}catch(PDOException $e){$denied=($e->errorInfo[1]??null)===1142;}
    hs_check($denied,'runtime cannot create schema objects');
    $runtime=null;
} finally {$pdo->exec('DROP USER '.$account);}
portal_destroy_session();echo "PASS $checks actual MySQL handoff/session authority assertions\n";ob_end_flush();
