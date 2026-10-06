<?php
/** Execute the real protected CLI against a dedicated hosted MySQL socket. */
declare(strict_types=1);
if(getenv('SAFEHARBOR_TOOL_RUNS_CLI_TEST')!=='1'||!function_exists('posix_geteuid')||posix_geteuid()!==0)exit(2);
require __DIR__.'/portal_devices_mysql_test.php';
$pdo->exec('DROP TABLE portal_westy_tool_runs');
$scratch=sys_get_temp_dir().'/sh-tool-runs-cli-'.bin2hex(random_bytes(8));mkdir($scratch,0700);
mkdir($scratch.'/app',0700);mkdir($scratch.'/app/config',0700);mkdir($scratch.'/evidence',0700);
file_put_contents($scratch.'/app/config/config.php','<?php return '.var_export(['db'=>['name'=>$database]],true).';');
register_shutdown_function(static function()use($scratch):void{
 foreach(glob($scratch.'/evidence/*') as $file)if(is_file($file)&&!is_link($file))unlink($file);
 unlink($scratch.'/app/config/config.php');rmdir($scratch.'/app/config');rmdir($scratch.'/app');rmdir($scratch.'/evidence');rmdir($scratch);
});
$cli=dirname(__DIR__,2).'/deploy/endpoint_tool_runs_migration.php';
$invoke=static function(string $sub,array $extra=[])use($cli,$scratch,$database):array{
 $args=[PHP_BINARY,$cli,$sub,'--app-root',$scratch.'/app','--expected-db',$database];
 if($sub==='apply')array_push($args,'--evidence-root',$scratch.'/evidence','--target',str_repeat('a',40),'--confirm','APPLY SAFEHARBOR ENDPOINT TOOL RUNS MIGRATION');
 array_push($args,...$extra);$process=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$err];
};
$plan=$invoke('plan');check($plan[0]===0&&json_decode($plan[1],true)['state']==='READY','actual root CLI plans pristine additive state');
$bad=$invoke('apply',['--unexpected','value']);check($bad[0]!==0&&!file_exists($scratch.'/evidence/tool-runs-before.sql'),'invalid CLI input refuses before backup or DDL');
$lock=fopen($scratch.'/evidence/tool-runs-migration.lock','c');flock($lock,LOCK_EX);
check($invoke('apply')[0]!==0,'actual CLI refuses overlapping migration owner');flock($lock,LOCK_UN);fclose($lock);
$apply=$invoke('apply');check($apply[0]===0&&str_contains($apply[1],'schema exact and empty'),'actual root CLI backs up and applies exact migration');
$backup=$scratch.'/evidence/tool-runs-before.sql';$receipt=$scratch.'/evidence/tool-runs-receipt.json';
check(filesize($backup)>0&&(fileperms($backup)&0777)===0600&&is_file($receipt),'protected CLI retains private backup and receipt');
$hash=hash_file('sha256',$backup);$repeat=$invoke('apply');check($repeat[0]===0&&str_contains($repeat[1],'already applied')&&hash_file('sha256',$backup)===$hash,'actual receipted repeat does not replay DDL or overwrite backup');
rename($receipt,$receipt.'.held');check($invoke('apply')[0]!==0,'actual unreceipted final refuses');rename($receipt.'.held',$receipt);
file_put_contents($backup,'tampered',FILE_APPEND);check($invoke('apply')[0]!==0,'actual CLI detects corrupted backup before accepting final state');
echo "PASS $checks protected tool run CLI checks\n";
