<?php
/** Exact canonical/additive schema and identity guards on an explicitly disposable server. */
declare(strict_types=1);
require __DIR__.'/portal_devices_mysql_test.php';
define('SH_TR_LIBRARY_ONLY',true);require __DIR__.'/../../deploy/endpoint_tool_runs_migration.php';
$payload=dirname(__DIR__,2).'/'.SH_TR_PATH;
check(sh_tr_payload($payload)===file_get_contents($payload),'run migration bytes are pinned');
check(sh_tr_snapshot($pdo,$database)===['state'=>'FINAL','rows'=>0,'errors'=>[]],'canonical schema equals exact additive run catalog and guards');
$pdo->exec('ALTER TABLE portal_westy_tool_runs ADD unexpected INT NULL');
check(sh_tr_snapshot($pdo,$database)['state']==='DRIFT','extra run column refused');
$pdo->exec('ALTER TABLE portal_westy_tool_runs DROP COLUMN unexpected');
$pdo->exec('DROP TRIGGER portal_westy_tool_run_identity_update');
check(sh_tr_snapshot($pdo,$database)['state']==='DRIFT','missing immutable identity guard refused');
$pdo->exec('DROP TABLE portal_westy_tool_runs');
check(sh_tr_snapshot($pdo,$database)['state']==='READY','pristine additive run state');
$statements=smp_sql_statements(file_get_contents($payload));
$pdo->exec($statements[0]);
check(sh_tr_snapshot($pdo,$database)['state']==='DRIFT','partial table without both guards refused');
$denied=false;try{sh_tr_apply($pdo,$database,$payload);}catch(Throwable){$denied=true;}
check($denied,'partial DDL never resumes or overwrites');
$pdo->exec('DROP TABLE portal_westy_tool_runs');
check(sh_tr_apply($pdo,$database,$payload)===['state'=>'FINAL','rows'=>0,'errors'=>[]],'pristine exact additive apply succeeds');
$denied=false;try{sh_tr_apply($pdo,$database,$payload);}catch(Throwable){$denied=true;}
check($denied,'unreceipted final cannot replay DDL');
$scope=portal_westy_scope($pdo,$a);$pdo->beginTransaction();
$conversation=portal_westy_account($pdo,$scope,true)['conversation_key'];$pdo->commit();
$q=$pdo->prepare("INSERT INTO portal_westy_turns(tenant_id,client_id,scope_key,conversation_key,operation_key,state,model_name,reserve_microusd,charged_microusd,created_at,expires_at) VALUES(1,11,?,?,?,'complete','fixture',0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 1 HOUR)");
$operation=bin2hex(random_bytes(16));$q->execute([$scope['key'],$conversation,$operation]);$turn=(int)$pdo->lastInsertId();
$insert=$pdo->prepare("INSERT INTO portal_westy_tool_runs(turn_id,tenant_id,client_id,scope_key,conversation_id,operation_key,origin_channel,origin_session_hash,state,created_at,expires_at) VALUES(?,1,?,?,?,?,'portal',?,'running',UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 30 MINUTE)");
$denied=false;try{$insert->execute([$turn,12,$scope['key'],$conversation,$operation,str_repeat('a',64)]);}catch(PDOException){$denied=true;}
check($denied,'foreign customer cannot bind another turn');
$insert->execute([$turn,11,$scope['key'],$conversation,$operation,str_repeat('a',64)]);
foreach(["client_id=12","origin_session_hash=REPEAT('b',64)","operation_key=REPEAT('b',32)","expires_at=expires_at+INTERVAL 1 SECOND"] as $change){
 $denied=false;try{$pdo->exec('UPDATE portal_westy_tool_runs SET '.$change.' WHERE turn_id='.$turn);}catch(PDOException){$denied=true;}
 check($denied,'immutable run binding '.$change);
}
echo "PASS $checks endpoint continuation migration checks\n";
