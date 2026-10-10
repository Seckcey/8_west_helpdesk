<?php
/** Real SH serialization and origin binding; synthetic MP transport has no real desktop access. */
declare(strict_types=1);
require __DIR__.'/portal_westy_terminal_mysql_test.php';
$session=bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,expires_at) VALUES(?,?,?,?,?,?)')
    ->execute([$session,$scope['tenant'],$scope['client'],$a['identity']['subject'],$scope['key'],gmdate('Y-m-d H:i:s',time()+1800)]);
$controlCalls=[];$controlState=null;$loseControl=false;$stopActivation=false;$testSlowPrepare=true;
$controlTransport=static function(string $body,array $headers)use($pdo,$a,$scope,$session,$host,$port,$user,$pass,$database,&$controlCalls,&$controlState,&$loseControl,&$stopActivation,&$testSlowPrepare):array{
    $wire=json_decode($body,true);$action=$wire['action'];$input=$wire['input'];$controlCalls[]=$action;
    if($action==='control_start'){
        check($pdo->inTransaction(),'prepare holds the actual same turn lock as Stop');
        if($testSlowPrepare){
            $other=new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $other->exec('SET SESSION innodb_lock_wait_timeout=1');$other->beginTransaction();
            try{$q=$other->prepare('SELECT state FROM portal_westy_turns WHERE scope_key=? AND operation_key=? FOR UPDATE');$q->execute([$scope['key'],$input['run_id']]);check(false,'Stop bypassed in-flight prepare lock');}
            catch(PDOException $error){check(($error->errorInfo[1]??null)===1205,'concurrent Stop serializes behind slow prepare before activation');}
            finally{$other->rollBack();}$testSlowPrepare=false;
        }
        $controlState=['session_id'=>$session,'device_name'=>'Synthetic computer','task_id'=>bin2hex(random_bytes(16)),
            'conversation_id'=>$input['conversation_id'],'origin_channel'=>$input['origin_channel'],'state'=>'paired','connected'=>true,'expires_at'=>time()+900,'control_version'=>2];
        if($loseControl)throw new PortalDesktopException('connection_unknown');
    }elseif($action==='control_activate'){
        check(!$pdo->inTransaction(),'activation releases turn lock so Stop can proceed');
        $q=$pdo->prepare('SELECT task_id FROM portal_desktop_bindings WHERE session_id=? AND scope_key=?');$q->execute([$session,$scope['key']]);
        check($q->fetchColumn()===$controlState['task_id'],'durable origin binding exists before a native grant is allowed');
        if($stopActivation){
            $stopActivation=false;$cancel=static function($b,$h)use(&$controlState):array{$controlState['state']='stopped';return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$controlState])];};
            portal_westy_run_stop($pdo,$a,$scope,$input['run_id'],$cancel);
        }elseif($controlState['state']!=='stopped')$controlState['state']='active';
    }elseif($action==='control_cancel'||$action==='stop')$controlState['state']='stopped';
    elseif($action!=='control_result'&&$action!=='state')throw new RuntimeException('Unexpected control action '.$action);
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$controlState])];
};
$controlProvider=static function($selection,$system,$messages,$options,$emit,$alive)use($device):array{
    check(in_array('desktop_open',array_column($options['tools'],'name'),true),'computer-capable tenant model receives actual desktop open tool');
    $args=['device_reference'=>$device];$id='open_actual_window';$replay=['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$id,'name'=>'desktop_open','arguments'=>json_encode($args)]]];
    foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
    return ['ok'=>true,'tool_calls'=>[['id'=>$id,'name'=>'desktop_open','arguments'=>$args]],'continuation'=>$replay,
        'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];
};
$sendControl=static fn(array $r)=>portal_westy_message($pdo,$a,$r,$controlProvider,static fn()=>$a,transport:$controlTransport,aiResolver:$resolver);
$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$request['message']='Read the synthetic browser and native application.';
$sendControl($request);$run=portal_westy_run_find($pdo,$scope,$request['operation']);
check($controlCalls===['control_start','control_activate']&&$controlState['state']==='active','ordinary requested control is prepared and activated without local Start');
$bound=portal_desktop_context($pdo,$a,$run['conversation_id'],$run['operation_key']);check($bound['desktop']['task_id']===$controlState['task_id'],'continuation resolves only its exact bound task');
$_SESSION['desktop_companion_session']=str_repeat('f',32);$wrong=portal_desktop_context($pdo,$a,$run['conversation_id'],$run['operation_key']);check(!isset($wrong['desktop']),'other origin cannot inherit portal control');unset($_SESSION['desktop_companion_session']);
check(!isset(portal_desktop_context($pdo,$b,$run['conversation_id'],$run['operation_key'])['desktop']),'other customer cannot use desktop binding');
portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$controlTransport);check($controlState['state']==='stopped','Stop cancels known control task');
$controlCalls=[];$loseControl=true;$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
check($controlCalls===['control_start','control_cancel']&&$controlState['state']==='stopped','lost control prepare never activates or repeats native grant');
$run=portal_westy_run_find($pdo,$scope,$request['operation']);check(portal_westy_run_result($a,$run,$controlTransport)['receipt']['state']==='unknown','lost control receipt remains unknown');
$loseControl=false;$stopActivation=true;$controlCalls=[];$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
check($controlState['state']==='stopped'&&portal_westy_run_find($pdo,$scope,$request['operation'])['state']==='stopped','Stop during activation wins over its late response');

// A real completed continuation releases its control even after pending intent
// has been replaced. Neither provider inference nor input may be replayed.
$controlCalls=[];$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
$completionRun=portal_westy_run_find($pdo,$scope,$request['operation']);$completedTask=$controlState['task_id'];$completionCalls=0;
check(!in_array('stop',$controlCalls,true),'waiting for control retains its live task');
$completionProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$completionCalls):array{
    $completionCalls++;$emit('The synthetic computer task response is complete.');
    return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];
};
$completionRequest=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$completionRun['conversation_id'],'sequence'=>1];
$complete=static fn()=>portal_westy_message($pdo,$a,$completionRequest,$completionProvider,static fn()=>$a,transport:$controlTransport,aiResolver:$resolver);
$complete();
check($controlState['state']==='stopped'&&count(array_filter($controlCalls,static fn($action)=>$action==='stop'))===1,
    'actual final response releases the exact active control task once');
$q=$pdo->prepare('SELECT * FROM portal_desktop_bindings WHERE session_id=?');$q->execute([$session]);$cleared=$q->fetch();
check($cleared['task_id']===null&&$cleared['operation_key']===null&&$cleared['conversation_id']===null&&$cleared['origin_session_hash']===null,
    'confirmed completion clears only the task binding and preserves the pairing');
$q=$pdo->prepare('SELECT reply_json,charged_microusd FROM portal_westy_turns WHERE id=?');$q->execute([$completionRun['turn_id']]);$completedTurn=$q->fetch();
check(str_contains($completedTurn['reply_json'],$completedTask)&&str_contains($completedTurn['reply_json'],'response is complete'),'completion keeps the saved receipt and final answer');
$completedCalls=$controlCalls;$complete();
check($completionCalls===1&&$controlCalls===$completedCalls,'reconnect after cleanup replays neither provider nor desktop request');
$q->execute([$completionRun['turn_id']]);check($q->fetch()===$completedTurn,'completion cleanup does not change paid accounting or receipts');

// A failed finish transaction must still end its own desktop control. The
// fixture trigger exists only in this disposable test database/connection.
foreach(['opening','finishing','unconfirmed'] as $finishFailure){
    $controlCalls=[];$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));
    if($finishFailure!=='opening'){
        $sendControl($request);$failedRun=portal_westy_run_find($pdo,$scope,$request['operation']);
        $failedRequest=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$failedRun['conversation_id'],'sequence'=>1];
    }else $failedRequest=$request;
    $pdo->exec("CREATE TRIGGER westy_fixture_finish_error BEFORE UPDATE ON portal_westy_ai_attempts FOR EACH ROW
        BEGIN IF @westy_fixture_finish_connection=CONNECTION_ID() AND NEW.turn_id=
            (SELECT id FROM portal_westy_turns WHERE operation_key=@westy_fixture_finish_operation LIMIT 1)
        THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic finish failure'; END IF; END");
    $pdo->exec('SET @westy_fixture_finish_connection=CONNECTION_ID()');
    $pdo->prepare('SET @westy_fixture_finish_operation=?')->execute([$request['operation']]);
    $finishTransport=static function(string $body,array $headers)use($finishFailure,&$controlCalls,$controlTransport):array{
        if($finishFailure==='unconfirmed'&&(json_decode($body,true)['action']??null)==='stop'){
            $controlCalls[]='stop';throw new PortalDesktopException('connection_unknown');
        }
        return $controlTransport($body,$headers);
    };
    try{
        portal_westy_message($pdo,$a,$failedRequest,$finishFailure==='opening'?$controlProvider:$completionProvider,
            static fn()=>$a,transport:$finishTransport,aiResolver:$resolver);
        check(false,'synthetic finish error was swallowed');
    }catch(PDOException $error){check(($error->errorInfo[0]??null)==='45000','the original finish failure remains visible');}
    finally{$pdo->exec('SET @westy_fixture_finish_connection=NULL, @westy_fixture_finish_operation=NULL');$pdo->exec('DROP TRIGGER westy_fixture_finish_error');}
    $failedRun=portal_westy_run_find($pdo,$scope,$request['operation']);
    check($controlState['state']===($finishFailure==='unconfirmed'?'active':'stopped')&&$failedRun['state']==='stopped'
        &&count(array_filter($controlCalls,static fn($action)=>$action==='stop'))===1,
        'finish transaction failure attempts exact release once and preserves its true outcome: '.$finishFailure);
    $retainedFailure=json_decode((string)$failedRun['pending_json'],true);
    check($finishFailure==='unconfirmed'?($retainedFailure['task_id']??null)===$controlState['task_id']:$retainedFailure===null,
        'unconfirmed cleanup retains only its bound control identity');
    $q=$pdo->prepare('SELECT state,reserve_microusd,charged_microusd FROM portal_westy_ai_attempts WHERE turn_id=? ORDER BY sequence DESC LIMIT 1');
    $q->execute([$failedRun['turn_id']]);$pendingPaid=$q->fetch();
    check($pendingPaid['state']==='pending'&&$pendingPaid['reserve_microusd']===$pendingPaid['charged_microusd'],
        'control cleanup neither fabricates paid completion nor refunds an uncertain attempt');
    $q=$pdo->prepare('SELECT reply_json FROM portal_westy_turns WHERE id=?');$q->execute([$failedRun['turn_id']]);$savedFailure=$q->fetchColumn();
    check(is_string($savedFailure)&&str_contains($savedFailure,$controlState['task_id']), 'saved partial action receipt survives failed finish and cleanup');
    $callsBefore=$controlCalls;$providerCallsBefore=$completionCalls;
    try{portal_westy_message($pdo,$a,$failedRequest,$completionProvider,static fn()=>$a,transport:$controlTransport,aiResolver:$resolver);}
    catch(PortalWestyException){}
    check($controlCalls===$callsBefore&&$completionCalls===$providerCallsBefore,'reconnect after failed finish replays no inference or desktop request');
    // End this synthetic fixture's pending-turn concurrency slot so independent
    // scenarios can start; retain its paid-attempt row and reserved charge.
    $pdo->prepare("UPDATE portal_westy_turns SET state='unavailable' WHERE id=? AND state='pending'")->execute([$failedRun['turn_id']]);
}

foreach(['continuation',null] as $pendingKind){
    $controlCalls=[];$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
    $q=$pdo->prepare('UPDATE portal_westy_tool_runs SET pending_json=? WHERE scope_key=? AND operation_key=?');
    $q->execute([$pendingKind===null?null:json_encode(['kind'=>$pendingKind]),$scope['key'],$request['operation']]);
    portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$controlTransport);
    check($controlState['state']==='stopped'&&array_slice($controlCalls,-1)===['stop']&&!in_array('control_cancel',$controlCalls,true),
        'Stop finds exact bound control after the pending intent has changed: '.($pendingKind??'null'));
}

foreach(['missing','replacement'] as $bindingChange){
    $request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
    $knownRun=portal_westy_run_find($pdo,$scope,$request['operation']);$replacement=bin2hex(random_bytes(16));
    if($bindingChange==='missing')$pdo->prepare('DELETE FROM portal_desktop_bindings WHERE session_id=?')->execute([$session]);
    else $pdo->prepare('UPDATE portal_desktop_bindings SET task_id=?,operation_key=? WHERE session_id=?')->execute([$replacement,$replacement,$session]);
    $controlCalls=[];
    try{portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$controlTransport);check(false,'known pending task without exact binding falsely confirmed Stop: '.$bindingChange);}
    catch(PortalWestyException $error){check($error->reason==='stop_unconfirmed','missing exact binding retains truthful Stop uncertainty: '.$bindingChange);}
    check($controlCalls===[]&&portal_westy_run_find($pdo,$scope,$request['operation'])['pending_json']===$knownRun['pending_json'],
        'unbound known task keeps its intent and cannot cancel a replacement: '.$bindingChange);
    if($bindingChange==='replacement'){
        $q=$pdo->prepare('SELECT task_id FROM portal_desktop_bindings WHERE session_id=?');$q->execute([$session]);
        check($q->fetchColumn()===$replacement,'replacement binding survives Stop of the old run');
    }else $pdo->prepare('INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,expires_at) VALUES(?,?,?,?,?,?)')
        ->execute([$session,$scope['tenant'],$scope['client'],$a['identity']['subject'],$scope['key'],gmdate('Y-m-d H:i:s',time()+1800)]);
}

foreach(['lost','missing_identity','wrong_session','wrong_task','still_active','replacement','expired_binding'] as $mode){
    $controlCalls=[];$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
    $releaseRun=portal_westy_run_find($pdo,$scope,$request['operation']);$releaseTask=$controlState['task_id'];$releaseCalls=0;$replacement=bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE portal_westy_tool_runs SET state='running' WHERE turn_id=?")->execute([$releaseRun['turn_id']]);
    if($mode==='expired_binding')$pdo->prepare('UPDATE portal_desktop_bindings SET expires_at=? WHERE session_id=?')->execute([gmdate('Y-m-d H:i:s',time()-1),$session]);
    $releaseTransport=static function(string $body)use($pdo,$scope,$session,$releaseTask,$mode,$replacement,&$releaseCalls):array{
        $releaseCalls++;$wire=json_decode($body,true);
        check($wire['action']==='stop'&&$wire['input']===['session_id'=>$session,'task_id'=>$releaseTask],'release transmits only immutable exact task identity');
        if($mode==='lost')throw new PortalDesktopException('connection_unknown');
        if($mode==='replacement')$pdo->prepare('UPDATE portal_desktop_bindings SET task_id=?,operation_key=? WHERE session_id=? AND scope_key=?')->execute([$replacement,$replacement,$session,$scope['key']]);
        $result=['session_id'=>$session,'task_id'=>$releaseTask,'state'=>'stopped'];
        if($mode==='missing_identity')unset($result['session_id']);
        if($mode==='wrong_session')$result['session_id']=$replacement;
        if($mode==='wrong_task')$result['task_id']=$replacement;
        if($mode==='still_active')$result['state']='active';
        return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$result])];
    };
    $confirmed=in_array($mode,['replacement','expired_binding'],true);
    try{portal_westy_run_end($pdo,$scope,$request['operation'],'complete',$a,$releaseTransport);check($confirmed,'unconfirmed release must fail: '.$mode);}
    catch(PortalWestyException $error){check(!$confirmed&&$error->reason==='stop_unconfirmed','uncertain release returns stop_unconfirmed: '.$mode);}
    $q=$pdo->prepare('SELECT task_id,operation_key FROM portal_desktop_bindings WHERE session_id=?');$q->execute([$session]);$after=$q->fetch();
    check($releaseCalls===1,'release never retries a lost or mismatched response: '.$mode);
    check($after['task_id']===($mode==='replacement'?$replacement:($confirmed?null:$releaseTask)), 'only a confirmed exact binding is cleared: '.$mode);
    if($mode==='replacement')check($after['operation_key']===$replacement,'replacement run correlation survives the late stop reply');
    $ended=portal_westy_run_find($pdo,$scope,$request['operation']);
    $kept=json_decode((string)$ended['pending_json'],true);
    check($ended['state']==='complete'&&$ended['replay_json']===null&&($confirmed?$kept===null:($kept['task_id']??null)===$releaseTask),
        'uncertain stop retains only known identity without reviving completed inference: '.$mode);
    $pdo->prepare('UPDATE portal_desktop_bindings SET expires_at=? WHERE session_id=?')->execute([gmdate('Y-m-d H:i:s',time()+1800),$session]);
}

// Every persisted correlation dimension participates in selecting the old task.
foreach(['missing','replacement'] as $bindingChange){
    $request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
    $lostRun=portal_westy_run_find($pdo,$scope,$request['operation']);$oldTask=$controlState['task_id'];$replacement=bin2hex(random_bytes(16));$finishCalls=0;
    $loseAtFinish=static function($selection,$system,$messages,$options,$emit,$alive)use($pdo,$session,$bindingChange,$replacement,&$finishCalls):array{
        $finishCalls++;$emit('The synthetic action receipt remains saved.');
        if($bindingChange==='missing')$pdo->prepare('DELETE FROM portal_desktop_bindings WHERE session_id=?')->execute([$session]);
        else $pdo->prepare('UPDATE portal_desktop_bindings SET task_id=?,operation_key=? WHERE session_id=?')->execute([$replacement,$replacement,$session]);
        return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];
    };
    $resumeLost=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$lostRun['conversation_id'],'sequence'=>1];
    $finishLost=static fn()=>portal_westy_message($pdo,$a,$resumeLost,$loseAtFinish,static fn()=>$a,transport:$controlTransport,aiResolver:$resolver);
    $controlCalls=[];
    try{$finishLost();check(false,'lost final-response binding falsely confirmed release: '.$bindingChange);}
    catch(PortalWestyException $error){check($error->reason==='stop_unconfirmed','actual final response reports unconfirmed release: '.$bindingChange);}
    $ended=portal_westy_run_find($pdo,$scope,$request['operation']);$retained=json_decode($ended['pending_json'],true);
    check($ended['state']==='complete'&&$ended['replay_json']===null&&$retained==['kind'=>'control','session_id'=>$session,'task_id'=>$oldTask],
        'finished inference keeps only the known unreleased control identity: '.$bindingChange);
    $q=$pdo->prepare('SELECT reply_json,charged_microusd FROM portal_westy_turns WHERE id=?');$q->execute([$lostRun['turn_id']]);$paid=$q->fetch();
    check(str_contains($paid['reply_json'],$oldTask)&&str_contains($paid['reply_json'],'receipt remains saved.'),'lost binding cannot discard final response or action receipt');
    $before=$controlCalls;$finishLost();$q->execute([$lostRun['turn_id']]);
    check($finishCalls===1&&$controlCalls===$before&&$q->fetch()===$paid&&!in_array('stop',$before,true)&&!in_array('control_cancel',$before,true),
        'lost final binding replays nothing and cannot stop a replacement: '.$bindingChange);
    if($bindingChange==='replacement'){
        $q=$pdo->prepare('SELECT task_id FROM portal_desktop_bindings WHERE session_id=?');$q->execute([$session]);check($q->fetchColumn()===$replacement,'new task survives old response completion');
    }else $pdo->prepare('INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,expires_at) VALUES(?,?,?,?,?,?)')
        ->execute([$session,$scope['tenant'],$scope['client'],$a['identity']['subject'],$scope['key'],gmdate('Y-m-d H:i:s',time()+1800)]);
}

$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendControl($request);
$exactRun=portal_westy_run_find($pdo,$scope,$request['operation']);$unexpectedStops=0;
$noStop=static function()use(&$unexpectedStops):array{$unexpectedStops++;throw new RuntimeException('Unexpected cleanup request');};
foreach(['tenant_id'=>2,'client_id'=>12,'scope_key'=>str_repeat('f',64),'operation_key'=>str_repeat('f',32),
    'conversation_id'=>str_repeat('f',32),'origin_channel'=>'companion','origin_session_hash'=>str_repeat('f',64)] as $field=>$otherValue){
    $q=$pdo->prepare('SELECT '.$field.' FROM portal_desktop_bindings WHERE session_id=?');$q->execute([$session]);$original=$q->fetchColumn();
    $pdo->prepare('UPDATE portal_desktop_bindings SET '.$field.'=? WHERE session_id=?')->execute([$otherValue,$session]);
    check(!portal_westy_control_release($pdo,$a,$exactRun,$noStop),'different binding dimension cannot be stopped: '.$field);
    $pdo->prepare('UPDATE portal_desktop_bindings SET '.$field.'=? WHERE session_id=?')->execute([$original,$session]);
}
try{portal_westy_control_release($pdo,$b,$exactRun,$noStop);check(false,'different customer accepted cleanup');}
catch(PortalWestyException $error){check($error->reason==='stop_unconfirmed','different customer cannot release an old run');}
$_SESSION['desktop_companion_session']=str_repeat('f',32);
try{portal_westy_control_release($pdo,$a,$exactRun,$noStop);check(false,'different origin accepted cleanup');}
catch(PortalWestyException $error){check($error->reason==='stop_unconfirmed','different origin cannot release an old run');}
unset($_SESSION['desktop_companion_session']);
$ambiguousSession=bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,conversation_id,operation_key,origin_channel,origin_session_hash,task_id,expires_at) SELECT ?,tenant_id,client_id,subject,scope_key,conversation_id,operation_key,origin_channel,origin_session_hash,task_id,expires_at FROM portal_desktop_bindings WHERE session_id=?')->execute([$ambiguousSession,$session]);
try{portal_westy_control_release($pdo,$a,$exactRun,$noStop);check(false,'ambiguous binding accepted cleanup');}
catch(PortalWestyException $error){check($error->reason==='stop_unconfirmed','ambiguous binding cannot choose a computer to stop');}
$pdo->prepare('DELETE FROM portal_desktop_bindings WHERE session_id=?')->execute([$ambiguousSession]);
check($unexpectedStops===0,'mismatched run or binding performs no network request');
portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$controlTransport);

$desktopCalls=[];$loseLaunch=false;$launchArguments=['inventory_id'=>bin2hex(random_bytes(16)),
    'application'=>'notepad.exe','arguments'=>['synthetic literal file name.txt']];
$desktopTransport=static function(string $body)use(&$desktopCalls,&$loseLaunch,$bound,$launchArguments):array{
    $wire=json_decode($body,true);$desktopCalls[]=$wire['action'];
    if($wire['action']==='state')$result=$bound['desktop']+['connected'=>true,'state'=>'active','control_version'=>2];
    elseif($wire['action']==='launch'){
        foreach($launchArguments as $key=>$value)check($wire['input'][$key]===$value,'launch preserves literal '.$key);
        if($loseLaunch)throw new PortalDesktopException('connection_unknown');
        $result=['action_id'=>str_repeat('7',32),'state'=>'queued'];
    }elseif($wire['action']==='result')$result=['state'=>'executed','result'=>['reason'=>'application_started','observation_available'=>false]];
    elseif($wire['action']==='stop')$result=['state'=>'stopped'];
    else throw new RuntimeException('Unexpected desktop fixture action');
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$result])];
};
$definitions=array_column(portal_westy_desktop_definitions($pdo,$bound,$desktopTransport),null,'name');
check(isset($definitions['desktop_launch'])&&in_array('right_click',$definitions['desktop_action']['input_schema']['properties']['kind']['enum'],true),'actual active v2 control advertises launch and ordinary pointer actions');
$desktopCalls=[];$launched=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,null,$desktopTransport);
check($desktopCalls===['launch','result']&&$launched['public_result']['state']==='executed'
    &&$launched['public_result']['reason']==='application_started_discover_window','OS launch acceptance requires subsequent real window observation');
check($launched['public_result']===['state'=>'executed','action_id'=>str_repeat('7',32),'reason'=>'application_started_discover_window',
    'action_reason'=>'application_started'],'executed launch keeps its native reason separate from the discovery guidance');
$desktopCalls=[];$loseLaunch=true;$lost=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,null,$desktopTransport);
check($desktopCalls===['launch','stop']&&$lost['public_result']['state']==='unknown'&&$lost['public_result']['action_id']===null,'lost initial launch response remains unknown and is never replayed');
$failureCalls=[];$failureMode='refusal';$failureReceipt=['observation_available'=>true];
$inputObservation=['observation_id'=>str_repeat('5',32),'available'=>true,'reason'=>null,'complete'=>true];
$failureTransport=static function(string $body)use(&$failureCalls,&$failureMode,&$failureReceipt,&$inputObservation):array{
    $wire=json_decode($body,true);$action=$wire['action'];$failureCalls[]=$action;
    if($action==='stop')$result=['state'=>'stopped'];
    elseif($action==='launch'&&in_array($failureMode,['refusal','server_error','malformed','unrecognized'],true))
        return ['status'=>$failureMode==='server_error'?503:409,'body'=>json_encode([
            'ok'=>$failureMode==='malformed'?true:false,'reason'=>$failureMode==='unrecognized'?'invented_error':'support_busy'])];
    elseif($action==='observe')return ['status'=>409,'body'=>json_encode(['ok'=>false,'reason'=>'observation_busy'])];
    elseif($action==='launch'||$action==='select'||$action==='action')$result=['action_id'=>str_repeat('8',32),'state'=>'queued'];
    elseif($action==='result'&&$failureMode==='receipt_loss')throw new PortalDesktopException('connection_unknown');
    elseif($action==='result')$result=['state'=>'executed','result'=>$failureReceipt];
    elseif($action==='observation'&&$failureMode==='stop_after_execution')$result=['state'=>'pending'];
    elseif($action==='observation'&&$failureMode==='observed')$result=['state'=>'completed','observation'=>$inputObservation];
    elseif($action==='observation')throw new PortalDesktopException('connection_unknown');
    else throw new RuntimeException('Unexpected recovery fixture action');
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$result])];
};
$refused=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,null,$failureTransport);
check($refused['public_result']['state']==='refused'&&$refused['public_result']['reason']==='support_busy'
    &&$refused['public_result']['action_id']===null&&$failureCalls===['launch'],
    'definitive prequeue 409 is refused without unknown history, Stop or replay');
foreach(['server_error','malformed','unrecognized'] as $failureMode){
    $failureCalls=[];$uncertain=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,null,$failureTransport);
    check($uncertain['public_result']['state']==='unknown'&&$failureCalls===['launch','stop'],
        '5xx or invalid refusal cannot prove no execution: '.$failureMode);
}
$failureCalls=[];$readUnavailable=portal_westy_desktop_dispatch($bound,'desktop_observe',[],null,$failureTransport);
check($readUnavailable['public_result']['state']==='unavailable'&&$failureCalls===['observe'],
    'unavailable read permits fresh evidence within the same task without calling Stop');
$failureCalls=[];$failureMode='receipt_loss';$uncertain=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,null,$failureTransport);
check($uncertain['public_result']['state']==='unknown'&&$uncertain['public_result']['action_id']===str_repeat('8',32)
    &&$failureCalls===['launch','result','stop'],'accepted launch with lost result keeps exact action ID and never replays');
$failureCalls=[];$failureMode='observation_loss';
$selected=portal_westy_desktop_dispatch($bound,'desktop_select',['inventory_id'=>str_repeat('9',32),'window'=>'123','process_id'=>45],null,$failureTransport);
check($selected['public_result']===['state'=>'executed','reason'=>'observation_unavailable','action_id'=>str_repeat('8',32)]
    &&$failureCalls===['select','result','observation'],'lost post-action observation preserves actual OS acceptance without claiming task success');
$failureCalls=[];$failureMode='stop_after_execution';$aliveChecks=0;
$selected=portal_westy_desktop_dispatch($bound,'desktop_select',['inventory_id'=>str_repeat('9',32),'window'=>'123','process_id'=>45],
    static function()use(&$aliveChecks):bool{return ++$aliveChecks<3;},$failureTransport);
check($selected['public_result']===['state'=>'executed','reason'=>'task_stopped','action_id'=>str_repeat('8',32)]
    &&$failureCalls===['select','result','observation','stop'],'explicit Stop after OS acceptance still stops control and preserves the executed receipt');
// The executed native input reason stays separate from guidance and from the
// observation's own reason: input was sent, the requested edit is not verified.
// A receipt without a reason token (older companion) keeps today's exact result.
$typed=['kind'=>'type','observation_id'=>str_repeat('4',32),'x'=>null,'y'=>null,'amount'=>null,'text'=>'synthetic text',
    'key'=>null,'url'=>null,'effect'=>'Type the synthetic text.'];$typedAction=str_repeat('8',32);
foreach([
    'no observation, native reason'=>['no_observation',['reason'=>'post_observation_unavailable','observation_available'=>false],['action','result'],
        ['state'=>'executed','action_id'=>$typedAction,'reason'=>'observe_again','action_reason'=>'post_observation_unavailable']],
    'no observation, older companion'=>['no_observation',['observation_available'=>false],['action','result'],
        ['state'=>'executed','action_id'=>$typedAction,'reason'=>'observe_again']],
    'observed, mismatched text'=>['observed',['reason'=>'text_mismatch','observation_available'=>true],['action','result','observation'],
        ['state'=>'executed','observation'=>$inputObservation,'action_id'=>$typedAction,'action_reason'=>'text_mismatch']],
    'observed, no reason'=>['observed',['reason'=>null,'observation_available'=>true],['action','result','observation'],
        ['state'=>'executed','observation'=>$inputObservation,'action_id'=>$typedAction]],
    'observation error, unconfirmed text'=>['observation_loss',['reason'=>'text_not_confirmed','observation_available'=>true],['action','result','observation'],
        ['state'=>'executed','reason'=>'observation_unavailable','action_id'=>$typedAction,'action_reason'=>'text_not_confirmed']],
    'observation error, free text is not a reason'=>['observation_loss',['reason'=>'text_mismatch: synthetic echo','observation_available'=>true],['action','result','observation'],
        ['state'=>'executed','reason'=>'observation_unavailable','action_id'=>$typedAction]],
    'Stop after input, mismatched text'=>['stop_after_execution',['reason'=>'text_mismatch','observation_available'=>true],['action','result','observation','stop'],
        ['state'=>'executed','reason'=>'task_stopped','action_id'=>$typedAction,'action_reason'=>'text_mismatch']],
] as $case=>[$failureMode,$failureReceipt,$expectedCalls,$expectedResult]){
    $failureCalls=[];$aliveChecks=0;
    $typedResult=portal_westy_desktop_dispatch($bound,'desktop_action',$typed,$failureMode==='stop_after_execution'
        ?static function()use(&$aliveChecks):bool{return ++$aliveChecks<3;}:null,$failureTransport);
    check($typedResult['public_result']===$expectedResult&&$failureCalls===$expectedCalls
        &&($typedResult['private_observation']??null)===($failureMode==='observed'?$inputObservation:null),
        'executed input keeps its own native reason beside guidance, observation and action ID without replay: '.$case);
}
$availableObservation=$inputObservation;$inputObservation['available']=false;$inputObservation['reason']='controller_surface';
$failureCalls=[];$failureMode='observed';$failureReceipt=['reason'=>'post_observation_unavailable',
    'observation_available'=>false,'observation_reason'=>'controller_surface'];
$selected=portal_westy_desktop_dispatch($bound,'desktop_select',['inventory_id'=>str_repeat('9',32),'window'=>'123','process_id'=>45],null,$failureTransport);
check($failureCalls===['select','result','observation']&&$selected['public_result']['state']==='executed'
    &&$selected['public_result']['action_reason']==='post_observation_unavailable'
    &&$selected['public_result']['observation_reason']==='controller_surface'
    &&$selected['public_result']['observation']===$inputObservation
    &&$selected['public_result']['recovery']==='choose_another_window_or_launch_requested_url',
    'actual service serialization consumes the unavailable controller view and preserves supported recovery without reselecting');
$inputObservation=$availableObservation;
$failureCalls=[];$stopped=portal_westy_desktop_dispatch($bound,'desktop_stop',[],null,$failureTransport);
check($stopped['public_result']['state']==='stopped'&&$failureCalls===['stop'],'explicit Stop still dispatches exactly once');
$failureCalls=[];$stopped=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,static fn():bool=>false,$failureTransport);
check($stopped['public_result']['state']==='stopped'&&$failureCalls===['stop'],'Stop before dispatch prevents launch');
$seen=portal_desktop_observation_result(['browser_origin'=>'https://example.test','browser_url'=>'https://example.test/actual/path?q=observed','image_png'=>null]);
check($seen['private_observation']['browser_url']==='https://example.test/actual/path?q=observed'&&!isset($seen['public_result']['observation']['browser_url']),'model receives actually observed browser URL without copying it to durable public metadata');
echo "PASS control v2 MySQL: $checks cumulative assertions\n";
