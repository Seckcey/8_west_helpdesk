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
function cfg(string $key,mixed $default=null):mixed{
    if(array_key_exists($key,$GLOBALS['feedbackReviewConfig']??[]))return $GLOBALS['feedbackReviewConfig'][$key];
    return match($key){'portal_westy.enabled'=>true,'portal_westy.ai_enabled'=>false,default=>$default};
}
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
    // Operator review uses real issuer verification and the same immutable events.
    require_once __DIR__.'/../lib/jwt.php';
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    if($key===false)throw new RuntimeException('Synthetic RSA key unavailable');
    $details=openssl_pkey_get_details($key);
    $jwksDirectory=sys_get_temp_dir().'/feedback-jwks-'.bin2hex(random_bytes(5));
    if(!mkdir($jwksDirectory,0700))throw new RuntimeException('Could not create private feedback JWKS directory');
    $jwksCache=$jwksDirectory.'/jwks.json';
    $jwksEnvelope=json_encode(['schema_version'=>1,'source'=>'https://id.example.test/.well-known/jwks.json',
        'fetched_at'=>time(),'jwks'=>['keys'=>[['kty'=>'RSA','alg'=>'RS256','kid'=>'feedback-test',
        'n'=>b64url_encode($details['rsa']['n']),'e'=>b64url_encode($details['rsa']['e'])]]]],
        JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(file_put_contents($jwksCache,$jwksEnvelope)!==strlen($jwksEnvelope)||!chmod($jwksCache,0600)){
        throw new RuntimeException('Could not write private feedback JWKS envelope');
    }
    $GLOBALS['feedbackReviewConfig']=['suite'=>['issuer'=>'https://id.example.test','token_algorithms'=>['RS256'],
        'jwks_url'=>'https://id.example.test/.well-known/jwks.json','jwks_cache_path'=>$jwksCache]];
    $claims=['iss'=>'https://id.example.test','sub'=>'t9u101','email'=>'owner@example.test','exp'=>time()+600,
        '8west:tenant'=>'preview-provider','8west:role'=>'msp_owner','8west:products'=>['safeharbor','milepost'],'8west:session_version'=>'1.1'];
    $sign=static function(array $claims)use($key):string{
        $head=b64url_encode(json_encode(['alg'=>'RS256','kid'=>'feedback-test','typ'=>'JWT']));
        $body=b64url_encode(json_encode($claims));openssl_sign($head.'.'.$body,$signature,$key,OPENSSL_ALGO_SHA256);
        return $head.'.'.$body.'.'.b64url_encode($signature);
    };
    $token=$sign($claims);$pdo->exec("UPDATE users SET suite_subject='t9u101' WHERE id=101");
    $customerId='11111111-1111-4111-8111-111111111111';$otherId='22222222-2222-4222-8222-222222222222';
    $sync=$pdo->prepare("INSERT INTO suite_customer_sync_bindings(tenant_id,customer_id,client_id,source_version,display_name,status,
        last_event_id,last_occurred_at,last_request_sha256) VALUES(?,?,?,1,?,'active',?,UTC_TIMESTAMP(),?)");
    foreach([[1,11,$customerId],[1,12,$otherId],[2,21,'33333333-3333-4333-8333-333333333333']] as [$tenant,$client,$uuid])
        $sync->execute([$tenant,$uuid,$client,$pdo->query('SELECT name FROM clients WHERE id='.$client)->fetchColumn(),$uuid,hash('sha256',$uuid)]);
    $snapshotFeed=['mode'=>'versioned','generated_at'=>time(),'revoked'=>[],'authorizations'=>['t9u101'=>'1.1']];
    $feed=static function()use(&$snapshotFeed){return $snapshotFeed;};
    $visible=[$customerId];$scopeCalls=0;
    $transport=static function($url,$bearer,$body)use(&$visible,&$scopeCalls,$token,$claims):array{
        ++$scopeCalls;
        check($url===PORTAL_FEEDBACK_SCOPE_ENDPOINT&&$bearer===$token&&json_decode($body,true)===['contract'=>PORTAL_FEEDBACK_SCOPE_CONTRACT],
            'scope callback uses only canonical origin and original verified bearer');
        return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_FEEDBACK_SCOPE_CONTRACT,
            'subject'=>$claims['sub'],'tenant_slug'=>$claims['8west:tenant'],'customer_ids'=>$visible])];
    };
    $reviewRequest=['contract'=>PORTAL_FEEDBACK_REVIEW_CONTRACT,'action'=>'list',
        'filters'=>array_fill_keys(['model','model_version','task','outcome','reaction','from','to'],''),
        'sort'=>'date_desc','page'=>1,'page_size'=>50];
    $review=static fn(array $r)=>portal_feedback_review($pdo,$token,$r,$transport,$feed);
    $reviewDenied=static function(callable $run,string $name,string $reason):void{
        try{$run();}catch(PortalFeedbackReviewException $e){check($e->reason===$reason,$name);return;}check(false,$name);
    };
    $rows=$review($reviewRequest);$encoded=json_encode($rows);
    $evidence=getenv('SAFEHARBOR_FEEDBACK_REVIEW_EVIDENCE');
    if(is_string($evidence)&&$evidence!=='')file_put_contents($evidence,json_encode(['request'=>$reviewRequest,'response'=>$rows],JSON_THROW_ON_ERROR));
    check(count($rows['items'])===2&&$scopeCalls===2,'latest response revisions reviewed only after two current scope checks');
    check($rows['row_count']===2&&!$rows['truncated']&&!$rows['has_more'],'review reports actual count and completeness');
    $old=array_values(array_filter($rows['items'],fn($r)=>$r['response_id']===$feedback['response_id']))[0];
    check($old['reaction']==='none'&&$old['revision']===3&&$old['model']==='recorded-model','clear remains the latest reaction and history is not replayed');
    check(!str_contains($encoded,'Why did printing stop')&&!str_contains($encoded,'Check the printer')&&!str_contains($encoded,'saved_reply')
        &&!str_contains($encoded,$token)&&!str_contains($encoded,'prompt'),'operator output contains metadata and no private text, tools or token');
    check(count(array_filter($rows['items'],fn($r)=>$r['context_available']===false&&$r['context_unavailable_reason']==='no_existing_conversation_access'
        &&$r['model_version']===null&&is_string($r['id'])&&strlen($r['reacted_at'])===20))===2,'context authority and provider build remain truthfully unavailable');
    foreach(['date_desc','date_asc','model_asc','reaction_asc','task_asc','outcome_asc'] as $sort)
        check(count($review(array_replace($reviewRequest,['sort'=>$sort]))['items'])===2,'whitelisted ordering works: '.$sort);
    $filter=array_replace($reviewRequest['filters'],['model'=>'selected-at-generation','model_version'=>'unknown','reaction'=>'up',
        'task'=>'saved_portal_turn','outcome'=>'complete','from'=>gmdate('Y-m-d'),'to'=>gmdate('Y-m-d')]);
    $filtered=$review(array_replace($reviewRequest,['filters'=>$filter]));
    check(count($filtered['items'])===1&&$filtered['items'][0]['catalog_version']==='catalog-old'&&$filtered['items'][0]['ai_revision']===7,
        'same immutable generating model/catalog/revision drives filters');
    check($review(array_replace($reviewRequest,['filters'=>array_replace($filter,['reaction'=>'down'])]))['items']===[],'reaction filters use latest revisions');
    $pageOne=$review(array_replace($reviewRequest,['page_size'=>1]));$pageTwo=$review(array_replace($reviewRequest,['page'=>2,'page_size'=>1]));
    check($pageOne['has_more']&&$pageOne['truncated']&&!$pageTwo['has_more']&&$pageOne['items'][0]['id']!==$pageTwo['items'][0]['id'],'bounded stable pages do not duplicate the tied date');
    $export=$review(array_replace($reviewRequest,['action'=>'export','page_size'=>1000]));
    check($export['items']===$rows['items']&&$export['row_count']===2,'export uses identical filtered scope and metadata projection');
    $clipped=$review(array_replace($reviewRequest,['action'=>'export','page_size'=>1]));
    check($clipped['row_count']===1&&$clipped['truncated'],'bounded export explicitly reports truncation and actual rows');
    foreach([['page'=>0],['page_size'=>51],['action'=>'export','page_size'=>1001],['sort'=>'id; DROP TABLE users'],['customer_ids'=>[$otherId]],['action'=>'export','page'=>2],
        ['filters'=>array_replace($reviewRequest['filters'],['from'=>'2026-02-30'])],
        ['filters'=>array_replace($reviewRequest['filters'],['model_version'=>'invented-build'])]] as $bad)
        $reviewDenied(fn()=>$review(array_replace($reviewRequest,$bad)),'closed invalid review request refused','invalid_request');
    foreach([['aud'=>'other'],['nonce'=>'x'],['iss'=>'https://other.test'],['exp'=>time()-1],['8west:products'=>['milepost']],
        ['8west:session_version'=>'2.1'],['8west:tenant'=>'other-provider'],['8west:role'=>'client_owner']] as $bad)
        $reviewDenied(fn()=>portal_feedback_review_actor($pdo,$sign(array_replace($claims,$bad)),$feed),'wrong token type/issuer/product/role/tenant/version refused',
            isset($bad['8west:tenant'])||isset($bad['8west:role'])?'not_allowed':'sign_in');
    $reviewDenied(fn()=>portal_feedback_review_actor($pdo,$token.'x',$feed),'tampered signature refused','sign_in');
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=101');
    $reviewDenied(fn()=>$review($reviewRequest),'inactive exact operator refused','not_allowed');$pdo->exec('UPDATE users SET is_active=1 WHERE id=101');
    $snapshotFeed['revoked']=['t9u101'=>true];$reviewDenied(fn()=>$review($reviewRequest),'current operator revocation refused','sign_in');$snapshotFeed['revoked']=[];
    $reviewDenied(fn()=>portal_feedback_review_actor($pdo,$token,static fn()=>null),'unavailable admission feed refused','authorization_unavailable');
    $GLOBALS['feedbackReviewConfig']['suite.mfa_policy_mode']='enforce';
    $reviewDenied(fn()=>$review($reviewRequest),'configured MFA enforcement reused','sign_in');
    $GLOBALS['feedbackReviewConfig']['suite.mfa_policy_mode']='report';
    $visible=[];check($review($reviewRequest)['items']===[],'no remotely visible customers never falls back to all tenant feedback');$visible=[$customerId];
    $wrongScope=static fn()=>['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_FEEDBACK_SCOPE_CONTRACT,
        'subject'=>'t9u999','tenant_slug'=>'preview-provider','customer_ids'=>[$customerId]])];
    $reviewDenied(fn()=>portal_feedback_review($pdo,$token,$reviewRequest,$wrongScope,$feed),'callback actor mismatch refused','scope_unavailable');
    $reviewDenied(fn()=>portal_feedback_review($pdo,$token,$reviewRequest,static fn()=>['status'=>302,'body'=>'{}'],$feed),'callback redirect refused','scope_unavailable');
    $changes=0;$scopeChanged=static function($url,$bearer,$body)use(&$changes,$transport,&$visible){if(++$changes===2)$visible=[];return $transport($url,$bearer,$body);};
    $reviewDenied(fn()=>portal_feedback_review($pdo,$token,$reviewRequest,$scopeChanged,$feed),'customer removal during source round trip refuses output','scope_changed');$visible=[$customerId];
    $changes=0;$revokedDuringScope=static function($url,$bearer,$body)use(&$changes,$transport,&$snapshotFeed){
        $response=$transport($url,$bearer,$body);if(++$changes===2)$snapshotFeed['revoked']=['t9u101'=>true];return $response;
    };
    $reviewDenied(fn()=>portal_feedback_review($pdo,$token,$reviewRequest,$revokedDuringScope,$feed),'operator revoked during final callback cannot receive output','sign_in');
    $snapshotFeed['revoked']=[];
    $pdo->prepare("UPDATE customer_portal_bindings SET status='disabled',last_changed_by_user_id=101,status_reason='Synthetic review revocation.' WHERE id=?")
        ->execute([$a['identity']['binding_id']]);
    check($review($reviewRequest)['items']===[],'disabled local customer binding is excluded');
    portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'active','Restore review fixture.');
    $reviewExpiry=gmdate('Y-m-d H:i:s',time()+86400);$pdo->exec('UPDATE portal_westy_turns SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id='.$turnId);
    check($review($reviewRequest)['items']===[],'expired parent hides all immutable reaction context before maintenance');
    $pdo->prepare('UPDATE portal_westy_turns SET expires_at=? WHERE id=?')->execute([$reviewExpiry,$turnId]);
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
    $visible=[$customerId,$otherId];$remaining=$review($reviewRequest);
    check($remaining['row_count']===1&&$remaining['items'][0]['customer_id']===$otherId,'operator erasure projection preserves only the other authorized customer');
    $pdo->prepare("UPDATE suite_customer_sync_bindings SET source_version=2,status='inactive',last_event_id=?,last_request_sha256=?
        WHERE tenant_id=1 AND client_id=12")->execute(['44444444-4444-4444-8444-444444444444',str_repeat('b',64)]);
    check($review($reviewRequest)['items']===[],'inactive managed customer cannot expose retained feedback');
    $visible=[$customerId,'33333333-3333-4333-8333-333333333333'];
    check($review($reviewRequest)['items']===[],'cross-provider UUID cannot acquire another provider record');
    $pdo->exec('UPDATE portal_westy_turns SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id='.$otherTurn);
    check(portal_westy_maintain($pdo,false)['feedback_content']===1,'scheduled dry run also counts newly expired parent context');
    check(portal_westy_maintain($pdo,true)['feedback_content']===1,'scheduled maintenance erases feedback with its expired parent');
    check((int)$pdo->query('SELECT COUNT(*) FROM portal_westy_reply_feedback')->fetchColumn()===0,'no private feedback context survives parent erasure');
    echo "PASS $checks feedback checks\n";
} finally {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    if(isset($jwksCache)){if(is_file($jwksCache))unlink($jwksCache);rmdir($jwksDirectory);}
    $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
