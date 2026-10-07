<?php
/** Canonical/additive v2 parity and populated immutable-run preservation on a disposable MySQL server. */
declare(strict_types=1);
require __DIR__.'/endpoint_tool_runs_migration_mysql_test.php';
define('SH_TRV2_LIBRARY_ONLY',true);require __DIR__.'/../../deploy/endpoint_tool_runs_v2_migration.php';
$startChecks=$checks;$v2File=dirname(__DIR__,2).'/'.SH_TRV2_PATH;
check(sh_trv2_payload($v2File)===file_get_contents($v2File),'v2 migration payload is exactly pinned');
check(sh_trv2_catalog()['before']===sh_tr_catalog()['portal_westy_tool_runs'],'v2 predecessor remains the frozen v1 catalog');
$before=sh_trv2_snapshot($pdo,$database);
check($before['state']==='T0'&&$before['rows']===1,'populated legacy run is admitted as exact predecessor');
$deny=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}};
check($deny(fn()=>sh_trv2_snapshot($pdo,'wrong_database')),'selected database mismatch refused');
check($deny(fn()=>sh_trv2_apply($pdo,$database,$v2File,array_replace($before,['data_sha256'=>str_repeat('0',64)])))&&sh_trv2_snapshot($pdo,$database)===$before,'changed pre-backup content refuses before DDL');
$after=sh_trv2_apply($pdo,$database,$v2File,$before);
check($after['state']==='T1'&&$after['rows']===$before['rows']&&$after['data_sha256']===$before['data_sha256'],'v2 apply preserves every original populated run value');
check($pdo->query('SELECT processes_json FROM portal_westy_tool_runs')->fetchColumn()===null,'legacy row gains only null process ownership');
check($deny(fn()=>sh_trv2_apply($pdo,$database,$v2File,$before)),'unreceipted final cannot replay DDL');
$pdo->exec("UPDATE portal_westy_tool_runs SET processes_json=JSON_OBJECT('synthetic_handle','opaque-action-id')");
check(sh_trv2_snapshot($pdo,$database)===$after,'later v2 process metadata preserves original column digest');
check($deny(fn()=>$pdo->exec('UPDATE portal_westy_tool_runs SET client_id=12')),'original immutable cross-customer trigger remains enforced');
$freshName=$database.'_v2fresh';$pdo->exec('CREATE DATABASE `'.$freshName.'`');
try{
    $pdo->exec('USE `'.$freshName.'`');portal_westy_fixture_sql($pdo,__DIR__.'/../db/schema.sql');
    $canonical=sh_trv2_snapshot($pdo,$freshName);
    check($canonical['state']==='T1'&&$canonical['rows']===0,'canonical fresh schema matches exact migrated v2 catalog and original triggers');
}finally{$pdo->exec('USE `'.$database.'`');$pdo->exec('DROP DATABASE `'.$freshName.'`');}
$pdo->exec('ALTER TABLE portal_westy_tool_runs ADD unexpected INT NULL');
check(sh_trv2_snapshot($pdo,$database)['state']==='DRIFT','extra-column drift is never treated as final');
$pdo->exec('ALTER TABLE portal_westy_tool_runs DROP COLUMN unexpected');
$pdo->exec('DROP TRIGGER portal_westy_tool_run_identity_update');
check(sh_trv2_snapshot($pdo,$database)['state']==='DRIFT','missing original identity guard is never treated as final');
echo 'PASS '.($checks-$startChecks)." populated tool-run v2 migration checks\n";
