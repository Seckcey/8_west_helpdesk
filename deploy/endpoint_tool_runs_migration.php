<?php
/** Protected additive tool-runs migration. CLI operations never accept arbitrary SQL. */
declare(strict_types=1);
if(!defined('SMP_MIGRATION_LIBRARY_ONLY'))define('SMP_MIGRATION_LIBRARY_ONLY',true);
require_once __DIR__.'/managed_provider_migration.php';
const SH_TR_PATH='app/db/migrations/endpoint_tool_runs_v1.sql';
const SH_TR_SHA256='17d3f47d1a2a46baee50614e374e0d1b28fb63f8b61f13e8b8c02c52a218142a';
const SH_TR_SIZE=2620;
const SH_TR_CATALOG_SHA256='ecc571f59f0ee744c9f28221a81de8efecb1f5409955a37a848d4ffd5416dab5';
const SH_TR_TABLES=['portal_westy_tool_runs'];

function sh_tr_payload(string $file):string
{
    if(!is_file($file)||is_link($file)||filesize($file)!==SH_TR_SIZE||!hash_equals(SH_TR_SHA256,hash_file('sha256',$file)))throw new RuntimeException('payload mismatch');
    return file_get_contents($file);
}
function sh_tr_catalog():array
{
    $file=__DIR__.'/endpoint_tool_runs_migration_catalog.json';
    if(!is_file($file)||!hash_equals(SH_TR_CATALOG_SHA256,hash_file('sha256',$file)))throw new RuntimeException('catalog mismatch');
    return json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
}
function sh_tr_snapshot(PDO $pdo,string $expectedDatabase):array
{
    if(!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$expectedDatabase)||$pdo->query('SELECT DATABASE()')->fetchColumn()!==$expectedDatabase)throw new RuntimeException('selected database mismatch');
    $expected=sh_tr_catalog();$present=0;$rows=0;$errors=[];
    foreach(SH_TR_TABLES as $table){
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
        if((int)$q->fetchColumn()===0)continue;
        $present++;$create=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
        if(!hash_equals($expected[$table],hash('sha256',$create)))$errors[]=$table.' schema mismatch';
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');$q->execute([$table]);
        if((int)$q->fetchColumn()!==2)$errors[]=$table.' trigger count mismatch';
        $q=$pdo->query("SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='portal_westy_tool_runs' ORDER BY TRIGGER_NAME");
        if($q->fetchAll(PDO::FETCH_ASSOC)!==$expected['triggers'])$errors[]='tool run trigger mismatch';
        $rows+=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    if($present!==0&&$present!==1)$errors[]='partial schema requires operator recovery';
    return ['state'=>$errors?'DRIFT':($present===1?'FINAL':'READY'),'rows'=>$rows,'errors'=>$errors];
}
function sh_tr_apply(PDO $pdo,string $database,string $file):array
{
    $sql=sh_tr_payload($file);
    if($pdo->inTransaction()||sh_tr_snapshot($pdo,$database)['state']!=='READY')throw new RuntimeException('non-pristine migration state');
    $pdo->exec("SET time_zone = '+00:00'");$pdo->exec('SET NAMES utf8mb4');foreach(smp_sql_statements($sql) as $statement)$pdo->exec($statement);
    $post=sh_tr_snapshot($pdo,$database);
    if($post['state']!=='FINAL'||$post['rows']!==0)throw new RuntimeException('postflight mismatch; retain backup and freeze');
    return $post;
}
function sh_tr_write(string $path,array $value):void
{
    $fd=fopen($path,'x');if($fd===false)throw new RuntimeException('existing evidence must not be overwritten');
    try{chmod($path,0600);$bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        if(fwrite($fd,$bytes)!==strlen($bytes)||!fflush($fd)||!fsync($fd))throw new RuntimeException('evidence write incomplete');
    }finally{fclose($fd);}
}
function sh_tr_evidence(string $root,string $database):array
{
    $path=$root.'/tool-runs-intent.json';
    if(!is_file($path)||is_link($path))throw new RuntimeException('original intent absent');
    $intent=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if(array_keys($intent)!==['contract','database','target','payload','backup','backup_sha256','backup_size']
        ||$intent['contract']!=='safeharbor-tool-runs-migration-v1'||$intent['database']!==$database||$intent['payload']!==SH_TR_SHA256
        ||!preg_match('/\A[0-9a-f]{40}\z/D',$intent['target'])||$intent['backup']!=='tool-runs-before.sql'
        ||!is_int($intent['backup_size'])||$intent['backup_size']<1||!preg_match('/\A[0-9a-f]{64}\z/D',$intent['backup_sha256']))throw new RuntimeException('intent identity mismatch');
    $backup=$root.'/'.$intent['backup'];
    if(!is_file($backup)||is_link($backup)||filesize($backup)!==$intent['backup_size']||!hash_equals($intent['backup_sha256'],hash_file('sha256',$backup)))throw new RuntimeException('backup integrity mismatch');
    return $intent;
}
function sh_tr_receipt_valid(string $root,string $database):bool
{
    $intent=sh_tr_evidence($root,$database);$file=$root.'/tool-runs-receipt.json';
    if(!is_file($file)||is_link($file))return false;
    $receipt=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    return $receipt===['contract'=>'safeharbor-tool-runs-migration-receipt-v1','intent_sha256'=>hash('sha256',json_encode($intent,JSON_UNESCAPED_SLASHES)),'state'=>'FINAL','initial_rows'=>0];
}

if(defined('SH_TR_LIBRARY_ONLY'))return;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    $sub=$argv[1]??'';$args=[];
    for($i=2;$i<count($argv);$i+=2){
        if(!in_array($argv[$i],['--app-root','--expected-db','--evidence-root','--target','--confirm'],true)||!isset($argv[$i+1])||isset($args[$argv[$i]]))throw new RuntimeException('invalid arguments');
        $args[$argv[$i]]=$argv[$i+1];
    }
    if(!in_array($sub,['plan','apply'],true)||!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('root CLI required');
    $app=realpath($args['--app-root']??'');
    if(!$app||!is_file($app.'/config/config.php'))throw new RuntimeException('app config absent');
    $config=require $app.'/config/config.php';$database=$config['db']['name']??null;
    if(!is_string($database)||$database!==($args['--expected-db']??null)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$database))throw new RuntimeException('database confirmation mismatch');
    // Schema administration is local-root socket only; application secrets are never exported.
    $pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname='.$database.';charset=utf8mb4','root','',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $file=dirname(__DIR__).'/'.SH_TR_PATH;sh_tr_payload($file);
    if($sub==='plan'){echo json_encode(sh_tr_snapshot($pdo,$database),JSON_THROW_ON_ERROR),"\n";exit;}
    if(($args['--confirm']??'')!=='APPLY SAFEHARBOR ENDPOINT TOOL RUNS MIGRATION'||!preg_match('/\A[0-9a-f]{40}\z/D',$args['--target']??''))throw new RuntimeException('explicit target and confirmation required');
    $root=realpath($args['--evidence-root']??'');
    if(!$root||$root==='/'||$root===$app||str_starts_with($root,$app.'/')||is_link($args['--evidence-root'])
        ||fileowner($root)!==0||(fileperms($root)&0777)!==0700)throw new RuntimeException('private external evidence root required');
    $lock=fopen($root.'/tool-runs-migration.lock','c');
    if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('migration locked');
    chmod($root.'/tool-runs-migration.lock',0600);
    if((int)$pdo->query("SELECT GET_LOCK('safeharbor:tool-runs-migration',0)")->fetchColumn()!==1)throw new RuntimeException('database migration locked');
    $state=sh_tr_snapshot($pdo,$database);
    if($state['state']==='FINAL'){
        if(!sh_tr_receipt_valid($root,$database))throw new RuntimeException('exact final schema needs original valid receipt');
        echo "already applied; original receipt and backup verified\n";exit;
    }
    if($state['state']!=='READY'||file_exists($root.'/tool-runs-intent.json')||file_exists($root.'/tool-runs-before.sql')||file_exists($root.'/tool-runs-receipt.json'))throw new RuntimeException('partial or interrupted work requires operator recovery');
    $backup=$root.'/tool-runs-before.sql';$fd=fopen($backup,'x');if($fd===false)throw new RuntimeException('backup exists');chmod($backup,0600);
    $process=proc_open(['/usr/bin/mysqldump','--protocol=SOCKET','--socket=/var/run/mysqld/mysqld.sock','--user=root','--single-transaction','--routines','--triggers','--events','--databases',$database],
        [0=>['file','/dev/null','r'],1=>$fd,2=>['file','/dev/null','w']],$pipes);
    $status=is_resource($process)?proc_close($process):-1;fclose($fd);clearstatcache(true,$backup);
    if($status!==0||filesize($backup)<1)throw new RuntimeException('backup failed; no DDL issued');
    $intent=['contract'=>'safeharbor-tool-runs-migration-v1','database'=>$database,'target'=>$args['--target'],'payload'=>SH_TR_SHA256,
        'backup'=>'tool-runs-before.sql','backup_sha256'=>hash_file('sha256',$backup),'backup_size'=>filesize($backup)];
    sh_tr_write($root.'/tool-runs-intent.json',$intent);sh_tr_evidence($root,$database);
    sh_tr_apply($pdo,$database,$file);
    sh_tr_write($root.'/tool-runs-receipt.json',['contract'=>'safeharbor-tool-runs-migration-receipt-v1',
        'intent_sha256'=>hash('sha256',json_encode($intent,JSON_UNESCAPED_SLASHES)),'state'=>'FINAL','initial_rows'=>0]);
    echo "tool-runs schema exact and empty; backup and receipt retained\n";
}catch(Throwable){fwrite(STDERR,"tool-runs migration refused; retain protected backup, intent and write freeze for operator review\n");exit(1);}
