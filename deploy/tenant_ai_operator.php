<?php
/** Root-only child of tenant_ai_window.py. No web/config fallback or arbitrary SQL. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0){http_response_code(404);exit(2);}
define('TAI_RELEASE_LIBRARY_ONLY',true);
define('TAI_WRITERS_LIBRARY_ONLY',true);
require __DIR__.'/tenant_ai_release.php';
require __DIR__.'/tenant_ai_writers.php';

function tai_operator_system(string $evidence,string $intentHash):void
{
    $process=proc_open(['/usr/bin/python3',__DIR__.'/tenant_ai_window.py','verify-system',
        '--evidence',$evidence,'--intent-sha256',$intentHash],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('system closure verifier unavailable');
    fclose($pipes[0]);
    if(proc_close($process)!==0)throw new RuntimeException('system closure is not verified');
}

function tai_operator_grants(array $facts):array
{
    $result=[];
    foreach(array_unique([...$facts['writers'],...$facts['global']]) as $key)$result[$key]=$facts['accounts'][$key]['grants_sha256'];
    ksort($result);return $result;
}

function tai_operator_no_other_admin(array $facts):void
{
    $names=[];foreach($facts['global'] as $key)$names[$facts['accounts'][$key]['user']]=true;
    foreach($facts['connections'] as $connection)
        if((int)$connection['ID']!==(int)$facts['identity']['connection_id']&&isset($names[$connection['USER']]))
            throw new RuntimeException('other administrator connection is active');
}

function tai_operator_artifact(string $root,string $name):array
{
    $path=$root.'/'.$name;
    if(!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{1,95}\z/D',$name)||!is_file($path)||is_link($path)
        ||realpath($path)!==$path||fileowner($path)!==0||(fileperms($path)&0777)!==0600||filesize($path)<1)
        throw new RuntimeException('protected artifact absent');
    return ['file'=>$name,'size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
}

try {
    $input=stream_get_contents(STDIN,65537);
    if(!is_string($input)||strlen($input)>65536)throw new RuntimeException('bounded operator request required');
    $request=json_decode($input,true,32,JSON_THROW_ON_ERROR);
    $action=$request['action']??null;
    $profile=json_decode(file_get_contents(__DIR__.'/tenant_ai_freeze_profile.json'),true,32,JSON_THROW_ON_ERROR);
    if(gethostname()!==$profile['host']||$profile['app']!==TAI_RELEASE_APP)throw new RuntimeException('operator host/profile mismatch');
    $held=[];
    foreach($request['lock_fds']??[] as $path=>$descriptor){
        if(!is_int($descriptor)||$descriptor<3||$descriptor>1024)throw new RuntimeException('invalid inherited descriptor');
        $stream=fopen('php://fd/'.$descriptor,'r+');if($stream===false)throw new RuntimeException('descriptor unavailable');$held[$path]=$stream;
    }
    tai_release_verify_locks($held,TAI_RELEASE_LOCK_PATHS,TAI_RELEASE_LOCK_OWNERS);
    $pdo=new PDO('mysql:unix_socket='.$profile['mysql_socket'].';dbname='.$profile['database'].';charset=utf8mb4','root','',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $writerProfile=tai_writer_profile(TAI_RELEASE_APP);
    $facts=tai_writer_inventory($pdo,$profile['database']);
    $identity=tai_writer_assert_reviewed($facts,$writerProfile);
    tai_operator_no_other_admin($facts);
    if($action==='inspect') {
        echo json_encode(['identity'=>$identity,'server_version'=>$facts['identity']['version'],
            'accounts'=>array_intersect_key($facts['accounts'],array_flip($writerProfile['writers'])),
            'grants'=>tai_operator_grants($facts)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        exit;
    }
    $root=$request['evidence']??'';
    if(!is_string($root)||realpath($root)!==$root||!is_dir($root)||is_link($root)||fileowner($root)!==0||(fileperms($root)&0777)!==0700)
        throw new RuntimeException('external root-only evidence directory required');
    $intentRaw=tai_release_private_file($root.'/freeze-intent.json');
    if(!is_string($request['intent_sha256']??null)||!hash_equals($request['intent_sha256'],hash('sha256',$intentRaw)))throw new RuntimeException('original freeze intent changed');
    $intent=json_decode($intentRaw,true,32,JSON_THROW_ON_ERROR);
    if(($intent['contract']??null)!=='tenant-ai-freeze-intent-v1'||$intent['app']!==TAI_RELEASE_APP
        ||$intent['database']['identity']!==$identity||$intent['database']['grants']!==tai_operator_grants($facts))
        throw new RuntimeException('database or grant inventory changed from original intent');
    foreach($intent['source_files'] as $relative=>$hash)
        if(!hash_equals($hash,hash_file('sha256',$intent['candidate'].'/'.$relative)))throw new RuntimeException('reviewed release controls changed');
    $verify=static function(string $stage)use($pdo,$profile,$writerProfile,$intent,$root,$request):array{
        tai_operator_system($root,$request['intent_sha256']);
        $current=tai_writer_inventory($pdo,$profile['database']);
        $identity=tai_writer_assert_frozen($current,$writerProfile);
        if(tai_operator_grants($current)!==$intent['database']['grants'])throw new RuntimeException('writer grants changed');
        return ['identity'=>$identity,'freeze_id'=>$intent['freeze_id'],'writers_closed'=>true];
    };
    if($action==='lock-account'||$action==='unlock-account') {
        tai_operator_system($root,$request['intent_sha256']);
        $key=$request['account']??'';
        if(!is_string($key)||!in_array($key,$writerProfile['writers'],true))throw new RuntimeException('unreviewed account mutation');
        $original=$intent['database']['accounts'][$key];
        if($action==='unlock-account'&&$original['locked']!==false)throw new RuntimeException('pre-existing account lock must be preserved');
        if($action==='unlock-account') {
            $accept=tai_release_private_file($root.'/unfreeze-acceptance.json');
            $accepted=json_decode($accept,true,16,JSON_THROW_ON_ERROR);
            if(($accepted['intent_sha256']??null)!==$request['intent_sha256']||($accepted['accepted']??null)!==true)
                throw new RuntimeException('explicit original-intent reopen acceptance required');
        }
        $account=$pdo->quote($original['user']).'@'.$pdo->quote($original['host']);
        $pdo->exec('ALTER USER '.$account.' ACCOUNT '.($action==='lock-account'?'LOCK':'UNLOCK'));
        $fresh=tai_writer_inventory($pdo,$profile['database']);
        if($fresh['accounts'][$key]['locked']!==($action==='lock-account'))throw new RuntimeException('account state not established');
        echo "{\"ok\":true}\n";exit;
    }
    if($action==='verify') {echo json_encode($verify('operator'),JSON_THROW_ON_ERROR)."\n";exit;}
    if($action==='backup-accounts') {
        $verify('before_account_backup');$sql="-- Protected original account recovery evidence. Never execute automatically.\n";
        foreach($writerProfile['writers'] as $key){
            $account=$facts['accounts'][$key];$name=$pdo->quote($account['user']).'@'.$pdo->quote($account['host']);
            $row=$pdo->query('SHOW CREATE USER '.$name)->fetch(PDO::FETCH_NUM);$sql.=$row[1].";\n";
            foreach($pdo->query('SHOW GRANTS FOR '.$name)->fetchAll(PDO::FETCH_COLUMN) as $grant)$sql.=$grant.";\n";
            $sql.='ALTER USER '.$name.' ACCOUNT '.($intent['database']['accounts'][$key]['locked']?'LOCK':'UNLOCK').";\n";
        }
        $stream=fopen($root.'/accounts.sql','x');if(!$stream)throw new RuntimeException('account backup already exists');
        try {chmod($root.'/accounts.sql',0600);if(fwrite($stream,$sql)!==strlen($sql)||!fflush($stream)||!fsync($stream))throw new RuntimeException('account backup incomplete');}
        finally{fclose($stream);}
        $verify('after_account_backup');echo "{\"ok\":true}\n";exit;
    }
    if($action==='apply'||$action==='verify-final') {
        if($action==='apply')$verify('before_proof');
        $capture=json_decode(tai_release_private_file($root.'/capture.json'),true,32,JSON_THROW_ON_ERROR);
        if(($capture['intent_sha256']??null)!==$request['intent_sha256']||$capture['identity']!==$identity)throw new RuntimeException('capture differs from closed window');
        foreach($capture['artifacts'] as $name=>$record){
            $actual=tai_operator_artifact($root,$name);ksort($actual);ksort($record);
            if($actual!==$record)throw new RuntimeException('closed-window backup changed');
        }
        $files=[];foreach(TAI_RELEASE_SOURCE_FILES as $file)$files[$file]=$intent['source_files'][$file];
        $proof=['contract'=>'tenant-ai-closed-window-v1','app'=>TAI_RELEASE_APP,'target'=>$intent['target'],
            'identity'=>$identity,'freeze_id'=>$intent['freeze_id'],'source_files'=>$files,
            'backup'=>tai_operator_artifact($root,'database.sql'),'restore'=>tai_operator_artifact($root,'restore-proof.json')];
        $proofPath=$root.'/closed-window-proof.json';
        if(file_exists($proofPath)){
            if(json_decode(tai_release_private_file($proofPath),true,32,JSON_THROW_ON_ERROR)!==$proof)throw new RuntimeException('original closed-window proof differs');
        }else {
            if($action==='verify-final')throw new RuntimeException('original closed-window proof absent');
            tai_release_write($proofPath,$proof);
        }
        if($action==='verify-final'){
            tai_release_proof($proofPath,$intent['target'],$identity,$intent['candidate']);
            $migrationRaw=tai_release_private_file($root.'/tenant-ai-intent.json');
            $expected=['contract'=>'tenant-ai-migration-intent-v1','app'=>TAI_RELEASE_APP,'target'=>$intent['target'],'identity'=>$identity,
                'proof_sha256'=>hash_file('sha256',$proofPath),'payload_sha256'=>TAI_MIGRATION_SHA256,'catalog_sha256'=>TAI_CATALOG_SHA256];
            $receipt=['contract'=>'tenant-ai-migration-receipt-v1','intent_sha256'=>hash('sha256',$migrationRaw),'state'=>'FINAL'];
            if(json_decode($migrationRaw,true,16,JSON_THROW_ON_ERROR)!==$expected
                ||json_decode(tai_release_private_file($root.'/tenant-ai-receipt.json'),true,16,JSON_THROW_ON_ERROR)!==$receipt
                ||!tai_migration_snapshot($pdo)['schema_ok'])throw new RuntimeException('final receipt/schema does not match this window');
            echo "{\"final_verified\":true}\n";exit;
        }
        echo json_encode(tai_release_apply($pdo,$intent['candidate'],$intent['target'],$proofPath,$held,$verify),JSON_THROW_ON_ERROR)."\n";exit;
    }
    throw new RuntimeException('unknown operator action');
} catch(Throwable) {
    fwrite(STDERR,"Tenant AI operator refused; preserve the original journal, backup and app freeze.\n");exit(1);
}
