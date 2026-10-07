<?php
/** Protected populated-row extension of the original receipted tool-run ledger. */
declare(strict_types=1);
if(!defined('SH_TR_LIBRARY_ONLY'))define('SH_TR_LIBRARY_ONLY',true);
require_once __DIR__.'/endpoint_tool_runs_migration.php';
const SH_TRV2_PATH='app/db/migrations/endpoint_tool_runs_v2.sql';
const SH_TRV2_SHA256='306378171ab85ad88d19db7638773bb55a5ef243c4750e09abd515792fff78cb';
const SH_TRV2_SIZE=317;
const SH_TRV2_CATALOG_SHA256='d462753b098643c232cc019186bd79d20bd892c9d0a139c62665180bbcb70280';
function sh_trv2_payload(string $file):string
{
    if(!is_file($file)||is_link($file)||filesize($file)!==SH_TRV2_SIZE||!hash_equals(SH_TRV2_SHA256,hash_file('sha256',$file)))throw new RuntimeException('V2 payload mismatch');
    return file_get_contents($file);
}
function sh_trv2_catalog():array
{
    $file=__DIR__.'/endpoint_tool_runs_v2_migration_catalog.json';
    if(!is_file($file)||is_link($file)||!hash_equals(SH_TRV2_CATALOG_SHA256,hash_file('sha256',$file)))throw new RuntimeException('V2 catalog mismatch');
    return json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
}
function sh_trv2_snapshot(PDO $pdo,string $database):array
{
    if(preg_match('/^[A-Za-z0-9_]{1,64}$/D',$database)!==1||$pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Database identity mismatch');
    $catalog=sh_trv2_catalog();
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_westy_tool_runs'");
    if((int)$q->fetchColumn()!==1)return ['state'=>'DRIFT','rows'=>0,'data_sha256'=>''];
    $create=$pdo->query('SHOW CREATE TABLE portal_westy_tool_runs')->fetch(PDO::FETCH_NUM)[1];$hash=hash('sha256',$create);
    $state=hash_equals($catalog['before'],$hash)?'T0':(hash_equals($catalog['after'],$hash)?'T1':'DRIFT');
    $triggers=$pdo->query("SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='portal_westy_tool_runs' ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC);
    if($triggers!==$catalog['triggers'])$state='DRIFT';
    $rows=(int)$pdo->query('SELECT COUNT(*) FROM portal_westy_tool_runs')->fetchColumn();$digest='';
    if($state!=='DRIFT'){
        $columns=[];foreach($catalog['columns'] as $column){if(preg_match('/^[a-z0-9_]+$/D',$column)!==1)throw new RuntimeException('Catalog projection invalid');$columns[]='`'.$column.'`';}
        $h=hash_init('sha256');$q=$pdo->query('SELECT '.implode(',',$columns).' FROM portal_westy_tool_runs ORDER BY turn_id');
        while($row=$q->fetch(PDO::FETCH_ASSOC))hash_update($h,serialize($row)."\n");$digest=hash_final($h);
    }
    return ['state'=>$state,'rows'=>$rows,'data_sha256'=>$digest];
}
function sh_trv2_apply(PDO $pdo,string $database,string $file,array $before):array
{
    $sql=sh_trv2_payload($file);
    if($pdo->inTransaction()||$before['state']!=='T0'||sh_trv2_snapshot($pdo,$database)!==$before)throw new RuntimeException('Preflight changed or DDL already applied');
    $pdo->exec($sql);$after=sh_trv2_snapshot($pdo,$database);
    if($after['state']!=='T1'||$after['rows']!==$before['rows']||!hash_equals($after['data_sha256'],$before['data_sha256'])
        ||(int)$pdo->query('SELECT COUNT(*) FROM portal_westy_tool_runs WHERE processes_json IS NOT NULL')->fetchColumn()!==0)throw new RuntimeException('V2 postflight mismatch; retain freeze and original evidence');
    return $after;
}
function sh_trv2_evidence(string $root,string $database):array
{
    if(!sh_tr_receipt_valid($root,$database))throw new RuntimeException('Original v1 receipt or backup missing');
    $file=$root.'/tool-runs-v2-intent.json';
    if(!is_file($file)||is_link($file)||filesize($file)>4096)throw new RuntimeException('Original v2 intent missing');
    $intent=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    if(array_keys($intent)!==['contract','database','target','payload','backup','backup_sha256','backup_size','rows','data_sha256']
        ||$intent['contract']!=='safeharbor-tool-runs-migration-v2'||$intent['database']!==$database||$intent['payload']!==SH_TRV2_SHA256
        ||!is_string($intent['target'])||preg_match('/^[0-9a-f]{40}$/D',$intent['target'])!==1||$intent['backup']!=='tool-runs-v2-before.sql'
        ||!is_int($intent['backup_size'])||$intent['backup_size']<1||!is_int($intent['rows'])||$intent['rows']<0
        ||!is_string($intent['backup_sha256'])||preg_match('/^[0-9a-f]{64}$/D',$intent['backup_sha256'])!==1
        ||!is_string($intent['data_sha256'])||preg_match('/^[0-9a-f]{64}$/D',$intent['data_sha256'])!==1)throw new RuntimeException('Intent identity mismatch');
    $backup=$root.'/'.$intent['backup'];
    if(!is_file($backup)||is_link($backup)||filesize($backup)!==$intent['backup_size']||!hash_equals($intent['backup_sha256'],hash_file('sha256',$backup)))throw new RuntimeException('Original backup integrity mismatch');
    return $intent;
}
function sh_trv2_receipt(array $intent):array
{
    return ['contract'=>'safeharbor-tool-runs-migration-receipt-v2','intent_sha256'=>hash('sha256',json_encode($intent,JSON_UNESCAPED_SLASHES)),
        'state'=>'T1','rows'=>$intent['rows'],'data_sha256'=>$intent['data_sha256']];
}
function sh_trv2_receipt_valid(string $root,string $database):bool
{
    $intent=sh_trv2_evidence($root,$database);$file=$root.'/tool-runs-v2-receipt.json';
    return is_file($file)&&!is_link($file)&&filesize($file)<=4096&&json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR)===sh_trv2_receipt($intent);
}
if(defined('SH_TRV2_LIBRARY_ONLY'))return;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    $sub=$argv[1]??'';$args=[];
    for($i=2;$i<count($argv);$i+=2){
        if(!in_array($argv[$i],['--app-root','--expected-db','--evidence-root','--target','--confirm'],true)||!isset($argv[$i+1])||isset($args[$argv[$i]]))throw new RuntimeException('Invalid arguments');
        $args[$argv[$i]]=$argv[$i+1];
    }
    if(!in_array($sub,['plan','apply'],true)||!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('Root CLI required');
    $app=realpath($args['--app-root']??'');if(!$app||!is_file($app.'/config/config.php'))throw new RuntimeException('App config absent');
    $config=require $app.'/config/config.php';$database=$config['db']['name']??null;
    if(!is_string($database)||$database!==($args['--expected-db']??null)||preg_match('/^[A-Za-z0-9_]{1,64}$/D',$database)!==1)throw new RuntimeException('Database confirmation mismatch');
    $pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $file=dirname(__DIR__).'/'.SH_TRV2_PATH;sh_trv2_payload($file);
    if($sub==='plan'){echo json_encode(sh_trv2_snapshot($pdo,$database),JSON_THROW_ON_ERROR),"\n";exit;}
    if(($args['--confirm']??'')!=='APPLY SAFEHARBOR ENDPOINT TOOL RUNS V2 MIGRATION'||preg_match('/^[0-9a-f]{40}$/D',$args['--target']??'')!==1)throw new RuntimeException('Exact target and confirmation required');
    $root=realpath($args['--evidence-root']??'');
    if(!$root||$root==='/'||$root===$app||str_starts_with($root,$app.'/')||is_link($args['--evidence-root'])||fileowner($root)!==0||(fileperms($root)&0777)!==0700)throw new RuntimeException('Private external evidence root required');
    $lockPath=$root.'/tool-runs-migration.lock';if(is_link($lockPath))throw new RuntimeException('Lock path refused');
    $lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Migration locked');chmod($lockPath,0600);
    foreach(['suite:managed-provider-migration','safeharbor:tool-runs-migration'] as $name){$q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Database migration locked');}
    if(!sh_tr_receipt_valid($root,$database))throw new RuntimeException('Original v1 migration evidence required');
    $before=sh_trv2_snapshot($pdo,$database);
    if($before['state']==='T1'){
        if(!sh_trv2_receipt_valid($root,$database))throw new RuntimeException('Unreceipted final schema requires operator recovery');
        echo "already applied; exact v2 schema and original receipts, intent and backups verified\n";exit;
    }
    if($before['state']!=='T0')throw new RuntimeException('Exact predecessor required');
    foreach(['tool-runs-v2-before.sql','tool-runs-v2-intent.json','tool-runs-v2-receipt.json'] as $name)
        if(file_exists($root.'/'.$name)||is_link($root.'/'.$name))throw new RuntimeException('Prior attempt requires operator recovery');
    $backup=$root.'/tool-runs-v2-before.sql';$old=umask(0077);$fd=fopen($backup,'x');umask($old);if(!$fd)throw new RuntimeException('Backup reservation failed');chmod($backup,0600);
    $process=proc_open(['/usr/bin/mysqldump','--protocol=SOCKET','--socket=/var/run/mysqld/mysqld.sock','--user=root','--single-transaction','--routines','--triggers','--events','--databases',$database],
        [0=>['file','/dev/null','r'],1=>$fd,2=>['file','/dev/null','w']],$pipes);
    $status=is_resource($process)?proc_close($process):-1;$synced=fflush($fd)&&fsync($fd);fclose($fd);clearstatcache(true,$backup);
    if($status!==0||!$synced||filesize($backup)<1)throw new RuntimeException('Backup failed; no DDL issued');
    $intent=['contract'=>'safeharbor-tool-runs-migration-v2','database'=>$database,'target'=>$args['--target'],'payload'=>SH_TRV2_SHA256,
        'backup'=>'tool-runs-v2-before.sql','backup_sha256'=>hash_file('sha256',$backup),'backup_size'=>filesize($backup),'rows'=>$before['rows'],'data_sha256'=>$before['data_sha256']];
    sh_tr_write($root.'/tool-runs-v2-intent.json',$intent);sh_trv2_evidence($root,$database);
    sh_trv2_apply($pdo,$database,$file,$before);
    sh_tr_write($root.'/tool-runs-v2-receipt.json',sh_trv2_receipt($intent));
    echo "tool-runs v2 schema exact; populated legacy rows and content preserved; original backup, intent and receipt retained\n";
}catch(Throwable){fwrite(STDERR,"tool-runs v2 migration refused; retain protected original evidence and write freeze for operator recovery\n");exit(1);}
