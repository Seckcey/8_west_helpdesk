<?php
/** Protected additive desktop migration. CLI operations never accept arbitrary SQL. */
declare(strict_types=1);
const SH_DS_PATH='app/db/migrations/desktop_portal_sessions_v1.sql';
const SH_DS_SHA256='cb2108ae631591beef03b7dec77a7d9feefab097467781e5c402112eda2ae57c';
const SH_DS_SIZE=1445;
const SH_DS_CATALOG_SHA256='ea52c4c99d74562c320dc4371a54ca440950ff053827160281ec82a0d1670a5a';
const SH_DS_TABLES=['portal_desktop_handoffs','portal_desktop_bindings'];

function sh_ds_payload(string $file):string
{
    if(!is_file($file)||is_link($file)||filesize($file)!==SH_DS_SIZE||!hash_equals(SH_DS_SHA256,hash_file('sha256',$file)))throw new RuntimeException('payload mismatch');
    return file_get_contents($file);
}
function sh_ds_catalog():array
{
    $file=__DIR__.'/desktop_sessions_migration_catalog.json';
    if(!is_file($file)||!hash_equals(SH_DS_CATALOG_SHA256,hash_file('sha256',$file)))throw new RuntimeException('catalog mismatch');
    return json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
}
function sh_ds_snapshot(PDO $pdo,string $expectedDatabase):array
{
    if(!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$expectedDatabase)||$pdo->query('SELECT DATABASE()')->fetchColumn()!==$expectedDatabase)throw new RuntimeException('selected database mismatch');
    $expected=sh_ds_catalog();$present=0;$rows=0;$errors=[];
    foreach(SH_DS_TABLES as $table){
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
        if((int)$q->fetchColumn()===0)continue;
        $present++;$create=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
        if(!hash_equals($expected[$table],hash('sha256',$create)))$errors[]=$table.' schema mismatch';
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');$q->execute([$table]);
        if((int)$q->fetchColumn()!==0)$errors[]=$table.' unexpected trigger';
        $rows+=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    if($present!==0&&$present!==2)$errors[]='partial schema requires operator recovery';
    return ['state'=>$errors?'DRIFT':($present===2?'FINAL':'READY'),'rows'=>$rows,'errors'=>$errors];
}
function sh_ds_apply(PDO $pdo,string $database,string $file):array
{
    $sql=sh_ds_payload($file);
    if($pdo->inTransaction()||sh_ds_snapshot($pdo,$database)['state']!=='READY')throw new RuntimeException('non-pristine migration state');
    $pdo->exec("SET time_zone = '+00:00'");$pdo->exec('SET NAMES utf8mb4');$pdo->exec($sql);
    $post=sh_ds_snapshot($pdo,$database);
    if($post['state']!=='FINAL'||$post['rows']!==0)throw new RuntimeException('postflight mismatch; retain backup and freeze');
    return $post;
}
function sh_ds_write(string $path,array $value):void
{
    $fd=fopen($path,'x');if($fd===false)throw new RuntimeException('existing evidence must not be overwritten');
    try{chmod($path,0600);$bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        if(fwrite($fd,$bytes)!==strlen($bytes)||!fflush($fd)||!fsync($fd))throw new RuntimeException('evidence write incomplete');
    }finally{fclose($fd);}
}
function sh_ds_evidence(string $root,string $database):array
{
    $path=$root.'/desktop-intent.json';
    if(!is_file($path)||is_link($path))throw new RuntimeException('original intent absent');
    $intent=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if(array_keys($intent)!==['contract','database','target','payload','backup','backup_sha256','backup_size']
        ||$intent['contract']!=='safeharbor-desktop-migration-v1'||$intent['database']!==$database||$intent['payload']!==SH_DS_SHA256
        ||!preg_match('/\A[0-9a-f]{40}\z/D',$intent['target'])||$intent['backup']!=='desktop-before.sql'
        ||!is_int($intent['backup_size'])||$intent['backup_size']<1||!preg_match('/\A[0-9a-f]{64}\z/D',$intent['backup_sha256']))throw new RuntimeException('intent identity mismatch');
    $backup=$root.'/'.$intent['backup'];
    if(!is_file($backup)||is_link($backup)||filesize($backup)!==$intent['backup_size']||!hash_equals($intent['backup_sha256'],hash_file('sha256',$backup)))throw new RuntimeException('backup integrity mismatch');
    return $intent;
}
function sh_ds_receipt_valid(string $root,string $database):bool
{
    $intent=sh_ds_evidence($root,$database);$file=$root.'/desktop-receipt.json';
    if(!is_file($file)||is_link($file))return false;
    $receipt=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    return $receipt===['contract'=>'safeharbor-desktop-migration-receipt-v1','intent_sha256'=>hash('sha256',json_encode($intent,JSON_UNESCAPED_SLASHES)),'state'=>'FINAL','initial_rows'=>0];
}

if(defined('SH_DS_LIBRARY_ONLY'))return;
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
    $file=dirname(__DIR__).'/'.SH_DS_PATH;sh_ds_payload($file);
    if($sub==='plan'){echo json_encode(sh_ds_snapshot($pdo,$database),JSON_THROW_ON_ERROR),"\n";exit;}
    if(($args['--confirm']??'')!=='APPLY SAFEHARBOR DESKTOP SESSIONS MIGRATION'||!preg_match('/\A[0-9a-f]{40}\z/D',$args['--target']??''))throw new RuntimeException('explicit target and confirmation required');
    $root=realpath($args['--evidence-root']??'');
    if(!$root||$root==='/'||$root===$app||str_starts_with($root,$app.'/')||is_link($args['--evidence-root'])
        ||fileowner($root)!==0||(fileperms($root)&0777)!==0700)throw new RuntimeException('private external evidence root required');
    $lock=fopen($root.'/desktop-migration.lock','c');
    if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('migration locked');
    chmod($root.'/desktop-migration.lock',0600);
    if((int)$pdo->query("SELECT GET_LOCK('safeharbor:desktop-migration',0)")->fetchColumn()!==1)throw new RuntimeException('database migration locked');
    $state=sh_ds_snapshot($pdo,$database);
    if($state['state']==='FINAL'){
        if(!sh_ds_receipt_valid($root,$database))throw new RuntimeException('exact final schema needs original valid receipt');
        echo "already applied; original receipt and backup verified\n";exit;
    }
    if($state['state']!=='READY'||file_exists($root.'/desktop-intent.json')||file_exists($root.'/desktop-before.sql')||file_exists($root.'/desktop-receipt.json'))throw new RuntimeException('partial or interrupted work requires operator recovery');
    $backup=$root.'/desktop-before.sql';$fd=fopen($backup,'x');if($fd===false)throw new RuntimeException('backup exists');chmod($backup,0600);
    $process=proc_open(['/usr/bin/mysqldump','--protocol=SOCKET','--socket=/var/run/mysqld/mysqld.sock','--user=root','--single-transaction','--routines','--triggers','--events','--databases',$database],
        [0=>['file','/dev/null','r'],1=>$fd,2=>['file','/dev/null','w']],$pipes);
    $status=is_resource($process)?proc_close($process):-1;fclose($fd);clearstatcache(true,$backup);
    if($status!==0||filesize($backup)<1)throw new RuntimeException('backup failed; no DDL issued');
    $intent=['contract'=>'safeharbor-desktop-migration-v1','database'=>$database,'target'=>$args['--target'],'payload'=>SH_DS_SHA256,
        'backup'=>'desktop-before.sql','backup_sha256'=>hash_file('sha256',$backup),'backup_size'=>filesize($backup)];
    sh_ds_write($root.'/desktop-intent.json',$intent);sh_ds_evidence($root,$database);
    sh_ds_apply($pdo,$database,$file);
    sh_ds_write($root.'/desktop-receipt.json',['contract'=>'safeharbor-desktop-migration-receipt-v1',
        'intent_sha256'=>hash('sha256',json_encode($intent,JSON_UNESCAPED_SLASHES)),'state'=>'FINAL','initial_rows'=>0]);
    echo "desktop schema exact and empty; backup and receipt retained\n";
}catch(Throwable){fwrite(STDERR,"desktop migration refused; retain protected backup, intent and write freeze for operator review\n");exit(1);}
