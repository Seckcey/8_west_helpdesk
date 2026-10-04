<?php
/** Synthetic MySQL only: exact canonical schema and real protected CLI/backup flow. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MILEPOST_TEST_DB_ALLOW_DROP')!=='YES')exit(2);
$base=getenv('MILEPOST_TEST_DB_NAME');
if(!preg_match('/\Amilepost_[a-z0-9_]*test[a-z0-9_]*\z/D',(string)$base))exit(2);
define('SH_DS_LIBRARY_ONLY',true);require __DIR__.'/../../deploy/desktop_sessions_migration.php';
define('SMP_MIGRATION_LIBRARY_ONLY',true);require __DIR__.'/../../deploy/managed_provider_migration.php';
$repo=dirname(__DIR__,2);$schema=$base.'_shmigration_'.bin2hex(random_bytes(3));$fresh=$schema.'_fresh';
$root=sys_get_temp_dir().'/desktop-migration-'.bin2hex(random_bytes(5));mkdir($root.'/app/config',0700,true);mkdir($root.'/evidence',0700);
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock','root','',$options);
$pdo->exec('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');$pdo->exec('USE '.$schema);
register_shutdown_function(static function()use($pdo,$schema,$fresh,$root){
    $pdo->exec('DROP DATABASE IF EXISTS '.$schema);$pdo->exec('DROP DATABASE IF EXISTS '.$fresh);
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);
});
$checks=0;
function dm_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function dm_denied(callable $run):bool{try{$run();return false;}catch(Throwable){return true;}}
$payload=$repo.'/'.SH_DS_PATH;
dm_check(sh_ds_payload($payload)===file_get_contents($payload),'exact payload bytes accepted');
file_put_contents($root.'/changed.sql',file_get_contents($payload)."\n");
dm_check(dm_denied(fn()=>sh_ds_payload($root.'/changed.sql')),'changed payload rejected');
dm_check(dm_denied(fn()=>sh_ds_snapshot($pdo,$fresh)),'wrong selected database rejected');
$statements=smp_sql_statements(file_get_contents($payload));
foreach([0,1] as $index){
    $pdo->exec($statements[$index]);
    dm_check(sh_ds_snapshot($pdo,$schema)['state']==='DRIFT','each empty partial boundary refused '.$index);
    if($index===0)$pdo->exec("INSERT INTO portal_desktop_handoffs VALUES(REPEAT('a',32),REPEAT('b',32),REPEAT('c',64),'approved',JSON_OBJECT('private','fixture'),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    else $pdo->exec("INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,expires_at) VALUES(REPEAT('a',32),1,1,'fixture',REPEAT('b',64),UTC_TIMESTAMP())");
    dm_check(dm_denied(fn()=>sh_ds_apply($pdo,$schema,$payload)),'populated partial boundary cannot replay '.$index);
    $pdo->exec('DROP TABLE '.SH_DS_TABLES[$index]);
}
$pdo->exec('CREATE TABLE legacy_receipt(id INT PRIMARY KEY,receipt VARCHAR(64))');$pdo->exec("INSERT INTO legacy_receipt VALUES(1,'preserve synthetic evidence')");
file_put_contents($root.'/app/config/config.php','<?php return '.var_export(['db'=>['name'=>$schema]],true).';');
$run=static function(string $sub,array $extra=[])use($repo,$root,$schema):array{
    $command=array_merge([PHP_BINARY,$repo.'/deploy/desktop_sessions_migration.php',$sub,'--app-root',$root.'/app','--expected-db',$schema,
        '--evidence-root',$root.'/evidence','--target',str_repeat('a',40),'--confirm','APPLY SAFEHARBOR DESKTOP SESSIONS MIGRATION'],$extra);
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$pipes);fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);fclose($pipes[1]);return [proc_close($process),$output];
};
dm_check($run('plan')[0]===0&&glob($root.'/evidence/*')===[],'plan reads exact state without backup or DDL');
file_put_contents($root.'/evidence/desktop-receipt.json','{}');
dm_check($run('apply')[0]!==0&&sh_ds_snapshot($pdo,$schema)['state']==='READY'&&!file_exists($root.'/evidence/desktop-before.sql'),'orphan receipt refuses before backup or DDL');
unlink($root.'/evidence/desktop-receipt.json');
[$code,$output]=$run('apply');dm_check($code===0,'real protected apply succeeds: '.$output);
dm_check(sh_ds_snapshot($pdo,$schema)===['state'=>'FINAL','rows'=>0,'errors'=>[]],'exact empty final schema');
$backup=$root.'/evidence/desktop-before.sql';$backupBytes=file_get_contents($backup);
dm_check(str_contains($backupBytes,'preserve synthetic evidence')&&!str_contains($backupBytes,'CREATE TABLE `portal_desktop_handoffs`'),'real backup precedes desktop DDL and retains legacy data');
dm_check(sh_ds_receipt_valid($root.'/evidence',$schema),'receipt bound to original database payload and backup');
dm_check((fileperms($backup)&0777)===0600,'backup remains private');
$receipt=$root.'/evidence/desktop-receipt.json';$receiptBytes=file_get_contents($receipt);$files=glob($root.'/evidence/*');
dm_check($run('apply')[0]===0&&glob($root.'/evidence/*')===$files&&file_get_contents($receipt)===$receiptBytes,'receipted repeat does not rewrite evidence');
rename($receipt,$receipt.'.held');dm_check($run('apply')[0]!==0,'unreceipted exact final refuses');rename($receipt.'.held',$receipt);
file_put_contents($backup,$backupBytes.'changed');dm_check($run('apply')[0]!==0,'changed original backup refuses repeat');file_put_contents($backup,$backupBytes);
file_put_contents($receipt,'{}');dm_check($run('apply')[0]!==0,'mismatched receipt refuses repeat');file_put_contents($receipt,$receiptBytes);
$pdo->exec('ALTER TABLE portal_desktop_bindings ADD unexpected INT NULL');dm_check($run('apply')[0]!==0,'receipted schema drift refuses');$pdo->exec('ALTER TABLE portal_desktop_bindings DROP COLUMN unexpected');
dm_check((int)$pdo->query('SELECT COUNT(*) FROM legacy_receipt')->fetchColumn()===1,'legacy record preserved across all refusal paths');

// Full current canonical bootstrap, including triggers and all prerequisite tables.
$pdo->exec('CREATE DATABASE '.$fresh.' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');$pdo->exec('USE '.$fresh);
foreach(smp_sql_statements(file_get_contents($repo.'/app/db/schema.sql')) as $statement)$pdo->exec($statement);
dm_check(sh_ds_snapshot($pdo,$fresh)===['state'=>'FINAL','rows'=>0,'errors'=>[]],'full fresh canonical bootstrap equals exact additive catalog');
echo "PASS $checks protected Safeharbor desktop migration checks\n";
