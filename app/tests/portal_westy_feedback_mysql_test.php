<?php
/** Real MySQL ownership, linkage, replay, race and erasure; synthetic data only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'safeharbor_westy_test';
if(!preg_match('/^safeharbor_westy_test(?:_[a-z0-9_]+)?$/D',$base))exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1';$port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
$user=getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root';$pass=getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$options);
$child=($argv[1]??'')==='--race';
$database=$child?($argv[2]??''):$base.'_feedback_'.bin2hex(random_bytes(4));
if(!preg_match('/^safeharbor_westy_test[a-z0-9_]*_feedback_[a-f0-9]{8}$/D',$database))exit(2);
$connect=static fn()=>new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$user,$pass,$options);
function cfg(string $key,mixed $default=null):mixed{return match($key){'portal_westy.enabled'=>true,'portal_westy.ai_enabled'=>false,default=>$default};}
require __DIR__.'/portal_westy_fixture.php';
require_once __DIR__.'/../lib/portal_westy_feedback.php';
require_once __DIR__.'/../lib/portal_westy_maintenance.php';
if($child){
    $pdo=$connect();$pdo->exec("SET time_zone='+00:00'");
    echo $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";flush();
    try{echo json_encode(['ok'=>true,'feedback'=>portal_westy_feedback_record($pdo,json_decode(base64_decode($argv[3]),true),json_decode(base64_decode($argv[4]),true))]);}
    catch(PortalWestyException $e){echo json_encode(['ok'=>false,'reason'=>$e->reason]);}
    exit;
}
$checks=0;
function check(bool $ok,string $name):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($name);echo "ok $checks - $name\n";}
function denied(callable $call,string $name,?string $reason=null):void{
    try{$call();}catch(Throwable $e){check($reason===null||($e instanceof PortalWestyException&&$e->reason===$reason),$name);return;}
    check(false,$name);
}
try{
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');$pdo=$connect();
    $pdo->exec("SET time_zone='+00:00'");
    portal_westy_fixture_sql($pdo,__DIR__.'/../db/schema.sql');
    // Fresh install and a legacy upgrade produce the same feedback table/guards.
    $migration=__DIR__.'/../db/migrations/portal_westy_reply_feedback_v1.sql';
    $ddl=$pdo->query('SHOW CREATE TABLE portal_westy_reply_feedback')->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec('DROP TABLE portal_westy_reply_feedback');
    portal_westy_fixture_sql($pdo,$migration);
    check($pdo->query('SHOW CREATE TABLE portal_westy_reply_feedback')->fetch(PDO::FETCH_NUM)[1]===$ddl,'fresh and upgrade schemas agree');
    portal_westy_fixture_sql($pdo,$migration);
    check($pdo->query('SHOW CREATE TABLE portal_westy_reply_feedback')->fetch(PDO::FETCH_NUM)[1]===$ddl,'exact migration replay preserves schema');
    $pdo->exec('ALTER TABLE portal_westy_reply_feedback MODIFY actor_subject VARCHAR(65) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    denied(fn()=>portal_westy_fixture_sql($pdo,$migration),'migration refuses existing column drift');
    $pdo->exec('ALTER TABLE portal_westy_reply_feedback MODIFY actor_subject VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    $pdo->exec('DROP TRIGGER portal_feedback_immutable');
    $pdo->exec('CREATE TRIGGER portal_feedback_immutable BEFORE UPDATE ON portal_westy_reply_feedback FOR EACH ROW SET @feedback_test_drift=1');
    denied(fn()=>portal_westy_fixture_sql($pdo,$migration),'migration refuses a changed immutability trigger');
    $pdo->exec('DROP TRIGGER portal_feedback_immutable');portal_westy_fixture_sql($pdo,$migration);
    check($pdo->query('SHOW CREATE TABLE portal_westy_reply_feedback')->fetch(PDO::FETCH_NUM)[1]===$ddl,'interrupted additive creation resumes without replacing existing guards');
    [$a,$b,$c]=portal_westy_fixture_seed($pdo);
    $scope=portal_westy_scope($pdo,$a);$pdo->beginTransaction();$account=portal_westy_account($pdo,$scope,true);$pdo->commit();
    $conversation=$account['conversation_key'];$operation=bin2hex(random_bytes(16));
    $q=$pdo->prepare("INSERT INTO portal_westy_turns(tenant_id,client_id,scope_key,conversation_key,operation_key,state,input_text,reply_json,model_name,reserve_microusd,charged_microusd,created_at,expires_at,finished_at)
        VALUES(1,11,?,?,?,'complete',?,?,'recorded-model',0,0,?,?,?)");
    $q->execute([$scope['key'],$conversation,$operation,'Why did printing stop?',json_encode(['reply'=>"Check the printer queue.\nKeep this exact line: <script> & café.",'sources'=>['requests']]),
        gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+86400),gmdate('Y-m-d H:i:s')]);
    $turnId=(int)$pdo->lastInsertId();
    $state=static fn()=>portal_westy_state($pdo,$a,$conversation);
    $feedback=$state()['turns'][0]['feedback'];
    check($feedback['revision']===0&&$feedback['reaction']==='none','saved legacy reply is rateable without invented attempt data');
    $request=['conversation'=>$conversation,'operation'=>$operation,'response_id'=>$feedback['response_id'],'request_id'=>bin2hex(random_bytes(16)),'revision'=>0,'reaction'=>'up'];
    $up=portal_westy_feedback_record($pdo,$a,$request);
    check($up['revision']===1&&$up['reaction']==='up','owner records helpful');
    check(portal_westy_feedback_record($pdo,$a,$request)===$up,'exact request replay is acknowledged once');
    check((int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback')->fetchColumn()===1,'replay writes one event');
    $event=$pdo->query('SELECT * FROM portal_westy_reply_feedback')->fetch();$snapshot=json_decode($event['context_json'],true);
    check($event['actor_subject']===$a['identity']['subject']&&(int)$event['tenant_id']===1&&(int)$event['binding_id']===$scope['binding'],'event identifies authenticated tenant and actor');
    check($snapshot['prompt']==='Why did printing stop?'&&$snapshot['response']==="Check the printer queue.\nKeep this exact line: <script> & café.",'exact saved prompt and response retained');
    check($snapshot['attempts']===[]&&$snapshot['provider_request_snapshot']===null&&$snapshot['turn_recorded_model']==='recorded-model','historical unknown model version and request snapshot remain unknown');
    check($event['expires_at']===$pdo->query('SELECT expires_at FROM portal_westy_turns')->fetchColumn(),'feedback cannot outlive parent retention');
    $sibling=$a;$sibling['identity']['subject']='t9u999';
    foreach(['another user'=>$sibling,'another customer'=>$b,'another tenant'=>$c] as $name=>$actor)
        denied(fn()=>portal_westy_feedback_record($pdo,$actor,$request),$name.' cannot rate or replay private response','feedback_changed');
    denied(fn()=>portal_westy_feedback_record($pdo,$a,$request+['prompt'=>'Forged text']),'browser cannot supply context','invalid_request');
    denied(fn()=>portal_westy_feedback_record($pdo,$a,array_replace($request,['reaction'=>'down'])),'same key cannot change payload','feedback_changed');
    denied(fn()=>portal_westy_feedback_record($pdo,$a,array_replace($request,['request_id'=>bin2hex(random_bytes(16))])),'stale tab cannot overwrite newer reaction','feedback_changed');
    denied(fn()=>portal_westy_feedback_record($pdo,$a,array_replace($request,['conversation'=>str_repeat('f',32)])),'cross-conversation target refused','feedback_changed');
    denied(fn()=>portal_westy_feedback_record($pdo,$a,array_replace($request,['response_id'=>str_repeat('f',64)])),'fabricated response refused','feedback_changed');
    $down=array_replace($request,['request_id'=>bin2hex(random_bytes(16)),'revision'=>1,'reaction'=>'down']);
    check(portal_westy_feedback_record($pdo,$a,$down)['reaction']==='down','owner can change reaction');
    check(portal_westy_feedback_record($pdo,$a,$request)['reaction']==='down','late retry acknowledges latest state without restoring old reaction');
    $none=array_replace($down,['request_id'=>bin2hex(random_bytes(16)),'revision'=>2,'reaction'=>'none']);
    check(portal_westy_feedback_record($pdo,$a,$none)['revision']===3,'owner can clear reaction with history retained');
    denied(fn()=>$pdo->exec("UPDATE portal_westy_reply_feedback SET reaction='up'"),'stored evidence cannot be rewritten');
    denied(fn()=>$pdo->exec('DELETE FROM portal_westy_reply_feedback'),'unexpired evidence cannot be silently removed');
    // A real continuation uses the same operation; feedback must target its new text.
    $pdo->prepare('UPDATE portal_westy_turns SET reply_json=? WHERE id=?')->execute([json_encode(['reply'=>'The printer queue is now ready.']),$turnId]);
    $next=$state()['turns'][0]['feedback'];
    check($next['response_id']!==$feedback['response_id']&&$next['revision']===0,'continued response gets a new stable response identity');
    denied(fn()=>portal_westy_feedback_record($pdo,$a,$none),'old rendered reply cannot rate a continuation','feedback_changed');
    check(json_decode($pdo->query('SELECT context_json FROM portal_westy_reply_feedback ORDER BY id LIMIT 1')->fetchColumn(),true)===$snapshot,'continuation preserves previously rated snapshot');
    // Real selected metadata is read from the generation receipt, never current settings.
    $pdo->prepare("INSERT INTO portal_westy_ai_attempts(turn_id,sequence,tenant_id,client_id,scope_key,provider,model_name,catalog_version,ai_revision,credential_version,request_fingerprint,state,reserve_microusd,charged_microusd,created_at,finished_at)
        VALUES(?,1,1,11,?,'openai','selected-at-generation','catalog-old',7,2,?,'complete',0,0,?,?)")
        ->execute([$turnId,$scope['key'],str_repeat('a',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);
    $next=$state()['turns'][0]['feedback'];
    $second=array_replace($request,['response_id'=>$next['response_id'],'request_id'=>bin2hex(random_bytes(16))]);
    portal_westy_feedback_record($pdo,$a,$second);
    $recorded=json_decode($pdo->query('SELECT context_json FROM portal_westy_reply_feedback ORDER BY id DESC LIMIT 1')->fetchColumn(),true)['attempts'][0];
    check($recorded['model_name']==='selected-at-generation'&&$recorded['catalog_version']==='catalog-old'&&$recorded['ai_revision']===7&&$recorded['provider_model_version']===null,'generation selection preserved with truthful unknown provider version');
    // A terminal partial response is also real saved output, not a success claim.
    $pdo->exec("UPDATE portal_westy_turns SET state='unavailable',reason_code='interrupted'");
    check($state()['turns'][0]['feedback']!==null,'saved interrupted reply can be rated');
    $pdo->exec("UPDATE portal_westy_turns SET state='pending'");
    check($state()['turns'][0]['feedback']===null,'streaming reply cannot acquire a final reaction');
    denied(fn()=>portal_westy_feedback_record($pdo,$a,$second),'pending continuation refuses old reaction','feedback_changed');
    $pdo->exec("UPDATE portal_westy_turns SET state='complete',reason_code=''");
    // Real concurrent writers must wait for this exact saved turn. Once released,
    // one new revision wins; an exact-key retry acknowledges without a second row.
    foreach([false,true] as $sameKey){
        $before=(int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback')->fetchColumn();
        $current=$state()['turns'][0]['feedback'];
        $first=array_replace($request,['response_id'=>$current['response_id'],'revision'=>$current['revision'],'request_id'=>bin2hex(random_bytes(16)),'reaction'=>'down']);
        $secondRace=$sameKey?$first:array_replace($first,['request_id'=>bin2hex(random_bytes(16)),'reaction'=>'up']);
        $pdo->beginTransaction();$pdo->query('SELECT id FROM portal_westy_turns WHERE id='.$turnId.' FOR UPDATE');
        $workers=[];$ids=[];
        try{
            foreach([$first,$secondRace] as $payload){
                $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--race',$database,base64_encode(json_encode($a)),base64_encode(json_encode($payload))],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if(!is_resource($process))throw new RuntimeException('Race process unavailable');
                fclose($pipes[0]);stream_set_timeout($pipes[1],5);$ids[]=(int)trim(fgets($pipes[1]));
                $workers[]=[$process,$pipes];
            }
            $deadline=microtime(true)+5;$waiting=false;
            while(microtime(true)<$deadline){
                $locks=$admin->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state=\'LOCK WAIT\' AND trx_mysql_thread_id IN ('.implode(',',$ids).')')->fetchColumn();
                if((int)$locks===2){$waiting=true;break;}usleep(100000);
            }
            check($waiting,'both competing feedback writers wait on real MySQL locks');$pdo->commit();
            $results=[];
            foreach($workers as [$process,$pipes]){
                $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                $exit=proc_close($process);
                if($exit!==0||$err!=='')throw new RuntimeException('Race child failed: '.$err.' '.$out);
                check(true,'race child completes without fixture failure');
                $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
            }
            $workers=[];
            check(array_sum(array_column($results,'ok'))===($sameKey?2:1),$sameKey?'exact-key race acknowledges both callers':'different-choice race accepts exactly one writer');
            if(!$sameKey)check(in_array('feedback_changed',array_column($results,'reason'),true),'losing race receives explicit stale-feedback conflict');
            check((int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback')->fetchColumn()===$before+1,'concurrent race writes exactly one event');
        }finally{
            if($pdo->inTransaction())$pdo->rollBack();
            foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
        }
    }
    // Revoke while a request is blocked after its first snapshot read. A locking
    // existence check alone must not allow a stale active-binding snapshot.
    $current=$state()['turns'][0]['feedback'];
    $revokedRequest=array_replace($request,['response_id'=>$current['response_id'],'revision'=>$current['revision'],'request_id'=>bin2hex(random_bytes(16))]);
    $before=(int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback')->fetchColumn();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE customer_portal_bindings SET status='disabled',last_changed_by_user_id=101,status_reason='Synthetic concurrent feedback revocation.' WHERE id=?")
        ->execute([$a['identity']['binding_id']]);
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--race',$database,base64_encode(json_encode($a)),base64_encode(json_encode($revokedRequest))],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    try{
        if(!is_resource($process))throw new RuntimeException('Revocation race process unavailable');
        fclose($pipes[0]);stream_set_timeout($pipes[1],5);$id=(int)trim(fgets($pipes[1]));
        $waiting=false;$deadline=microtime(true)+5;
        while(microtime(true)<$deadline){
            if((int)$admin->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id=".$id)->fetchColumn()===1){$waiting=true;break;}
            usleep(100000);
        }
        check($waiting,'feedback request waits behind a real binding revocation');$pdo->commit();
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($process)===0&&$err==='','revocation race process completes');$process=null;
        $result=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        check($result===['ok'=>false,'reason'=>'sign_in'],'revocation committed during lock wait refuses the stale actor');
        check((int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback')->fetchColumn()===$before,'concurrent revocation writes no private feedback');
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($process)){proc_terminate($process);proc_close($process);}
    }
    denied(fn()=>portal_westy_feedback_record($pdo,$a,$second),'revoked binding cannot rate or replay','sign_in');
    portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'active','Restore synthetic fixture.');
    // Expiry hides and erases both original and reaction context.
    $pdo->exec("UPDATE portal_westy_turns SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND");
    denied(fn()=>portal_westy_feedback_record($pdo,$a,$second),'expired saved reply cannot be rated','feedback_changed');
    $otherScope=portal_westy_scope($pdo,$b);$pdo->beginTransaction();$otherAccount=portal_westy_account($pdo,$otherScope,true);$pdo->commit();
    $otherOperation=bin2hex(random_bytes(16));
    $q=$pdo->prepare("INSERT INTO portal_westy_turns(tenant_id,client_id,scope_key,conversation_key,operation_key,state,input_text,reply_json,model_name,reserve_microusd,charged_microusd,created_at,expires_at)
        VALUES(1,12,?,?,?,'complete','Other private prompt',?,'other-recorded-model',0,0,?,?)");
    $q->execute([$otherScope['key'],$otherAccount['conversation_key'],$otherOperation,json_encode(['reply'=>'Other private reply.']),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+86400)]);
    $otherTurn=(int)$pdo->lastInsertId();$otherFeedback=portal_westy_state($pdo,$b)['turns'][0]['feedback'];
    portal_westy_feedback_record($pdo,$b,['conversation'=>$otherAccount['conversation_key'],'operation'=>$otherOperation,
        'response_id'=>$otherFeedback['response_id'],'request_id'=>bin2hex(random_bytes(16)),'revision'=>0,'reaction'=>'up']);
    // Exact-scope erasure is the supported immediate-delete path.
    $count=(int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback WHERE turn_id='.$turnId)->fetchColumn();
    $dry=portal_westy_maintain($pdo,false,$scope);
    check($dry['feedback_content']===$count,'erasure dry run counts private feedback without mutating');
    $erased=portal_westy_maintain($pdo,true,$scope);
    check($erased['feedback_content']===$count&&(int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback WHERE turn_id='.$turnId)->fetchColumn()===0,'exact erasure removes all feedback context with parent text');
    check(portal_westy_state($pdo,$a)['turns']===[],'erased prompt and reply disappear together');
    check(portal_westy_state($pdo,$b)['turns'][0]['feedback']['reaction']==='up','exact erasure preserves another user and customer');
    $pdo->exec('UPDATE portal_westy_turns SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id='.$otherTurn);
    check(portal_westy_maintain($pdo,false)['feedback_content']===1,'scheduled dry run also counts newly expired parent context');
    check(portal_westy_maintain($pdo,true)['feedback_content']===1,'scheduled maintenance erases feedback with its expired parent');
    check((int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback')->fetchColumn()===0,'no private feedback context survives parent erasure');
    echo "PASS $checks feedback checks\n";
} finally {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
