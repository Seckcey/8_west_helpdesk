<?php
/** Real root-only CLI: original v1 evidence, populated backup, locks, failed dump and no replay. */
declare(strict_types=1);
if(getenv('SAFEHARBOR_TOOL_RUNS_CLI_TEST')!=='1'||!function_exists('posix_geteuid')||posix_geteuid()!==0)exit(2);
require __DIR__.'/endpoint_tool_runs_migration_mysql_test.php';
define('SH_TRV2_LIBRARY_ONLY',true);require __DIR__.'/../../deploy/endpoint_tool_runs_v2_migration.php';
$startChecks=$checks;$legacyRow=$pdo->query('SELECT * FROM portal_westy_tool_runs')->fetch();
$pdo->exec('DROP TABLE portal_westy_tool_runs');
$scratch=sys_get_temp_dir().'/sh-tool-runs-v2-cli-'.bin2hex(random_bytes(8));mkdir($scratch,0700);
mkdir($scratch.'/app',0700);mkdir($scratch.'/app/config',0700);mkdir($scratch.'/evidence',0700);
file_put_contents($scratch.'/app/config/config.php','<?php return '.var_export(['db'=>['name'=>$database]],true).';');
register_shutdown_function(static function()use($scratch):void{
 foreach(glob($scratch.'/evidence/*') as $file)if(is_file($file)&&!is_link($file))unlink($file);
 unlink($scratch.'/app/config/config.php');rmdir($scratch.'/app/config');rmdir($scratch.'/app');rmdir($scratch.'/evidence');rmdir($scratch);
});
$invoke=static function(int $version,string $sub='apply',array $extra=[],bool $failDump=false)use($scratch,$database):array{
    $cli=dirname(__DIR__,2).'/deploy/endpoint_tool_runs'.($version===2?'_v2':'').'_migration.php';
    $args=[PHP_BINARY,$cli,$sub,'--app-root',$scratch.'/app','--expected-db',$database];
    if($sub==='apply')array_push($args,'--evidence-root',$scratch.'/evidence','--target',str_repeat($version===2?'b':'a',40),'--confirm','APPLY SAFEHARBOR ENDPOINT TOOL RUNS'.($version===2?' V2':'').' MIGRATION');
    array_push($args,...$extra);
    // Limit only this isolated child: the real mysqldump must fail to write, before any ALTER.
    if($failDump)$args=array_merge(['bash','-c','ulimit -f 0; exec "$@"','synthetic-backup-failure'],$args);
    $process=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$err];
};
$v1=$invoke(1);check($v1[0]===0,'actual v1 CLI creates original protected prerequisite evidence: '.$v1[2]);
$cols=implode(',',array_map(static fn($key)=>'`'.$key.'`',array_keys($legacyRow)));
$pdo->prepare('INSERT INTO portal_westy_tool_runs('.$cols.') VALUES('.implode(',',array_fill(0,count($legacyRow),'?')).')')->execute(array_values($legacyRow));
$before=sh_trv2_snapshot($pdo,$database);$evidenceRoot=$scratch.'/evidence';
$plan=$invoke(2,'plan');check($plan[0]===0&&json_decode($plan[1],true)===$before,'root CLI plans exact populated predecessor without DDL');
check($invoke(2,'apply',['--unexpected','value'])[0]!==0&&!file_exists($evidenceRoot.'/tool-runs-v2-before.sql'),'unknown option refuses before backup');
$originalReceipt=$evidenceRoot.'/tool-runs-receipt.json';rename($originalReceipt,$originalReceipt.'.held');
check($invoke(2)[0]!==0&&!file_exists($evidenceRoot.'/tool-runs-v2-before.sql'),'missing original v1 receipt prevents new backup and DDL');rename($originalReceipt.'.held',$originalReceipt);
foreach(['suite:managed-provider-migration','safeharbor:tool-runs-migration'] as $lockName){
    $q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);check((int)$q->fetchColumn()===1,'synthetic migration lock acquired');
    try{check($invoke(2)[0]!==0,'actual v2 CLI respects shared migration lock '.$lockName);}finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}
}
$backup=$evidenceRoot.'/tool-runs-v2-before.sql';$intent=$evidenceRoot.'/tool-runs-v2-intent.json';$receipt=$evidenceRoot.'/tool-runs-v2-receipt.json';
$failed=$invoke(2,'apply',[],true);
check($failed[0]!==0&&sh_trv2_snapshot($pdo,$database)===$before&&!is_file($intent)&&!is_file($receipt),'actual failed dump cannot issue DDL or completion evidence');
check($invoke(2)[0]!==0,'failed backup attempt cannot be silently overwritten');
// The synthetic test owns this empty failed artifact; production requires operator review.
check(is_file($backup)&&filesize($backup)===0,'failed child left only its empty reserved backup');unlink($backup);
$applied=$invoke(2);check($applied[0]===0,'actual v2 CLI backup and populated ALTER succeeds: '.$applied[2]);
$after=sh_trv2_snapshot($pdo,$database);
check($after['state']==='T1'&&$after['rows']===$before['rows']&&$after['data_sha256']===$before['data_sha256'],'executed CLI preserves the populated original projection');
$backupBytes=file_get_contents($backup);$backupHash=hash('sha256',$backupBytes);
check((fileperms($backup)&0777)===0600&&!str_contains($backupBytes,'`processes_json`')&&str_contains($backupBytes,'INSERT INTO `portal_westy_tool_runs`'),'real private backup contains the populated pre-DDL ledger');
check(sh_trv2_receipt_valid($evidenceRoot,$database),'completion receipt matches original intent, backup and v1 evidence');
foreach([$receipt,$intent,$originalReceipt] as $required){rename($required,$required.'.held');check($invoke(2)[0]!==0,'missing original evidence cannot adopt existing v2 DDL');rename($required.'.held',$required);}
file_put_contents($backup,$backupBytes.'changed');check($invoke(2)[0]!==0,'modified original backup refuses receipted repeat');file_put_contents($backup,$backupBytes);
$repeat=$invoke(2);check($repeat[0]===0&&str_contains($repeat[1],'already applied')&&hash_file('sha256',$backup)===$backupHash,'verified repeat preserves original backup and issues no DDL');
$pdo->exec("UPDATE portal_westy_tool_runs SET processes_json=JSON_OBJECT('synthetic','opaque')");check($invoke(2)[0]===0,'later legitimate process ownership preserves migration receipt validity');
$pdo->exec('ALTER TABLE portal_westy_tool_runs ADD unexpected INT NULL');check($invoke(2)[0]!==0,'schema drift refuses even with both original receipts');
echo 'PASS '.($checks-$startChecks)." protected populated tool-run v2 CLI checks\n";
