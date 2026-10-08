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
    }elseif($action==='control_cancel')$controlState['state']='stopped';
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
$desktopCalls=[];$loseLaunch=true;$lost=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,null,$desktopTransport);
check($desktopCalls===['launch','stop']&&$lost['public_result']['state']==='unknown'&&$lost['public_result']['action_id']===null,'lost initial launch response remains unknown and is never replayed');
$failureCalls=[];$failureMode='refusal';
$failureTransport=static function(string $body)use(&$failureCalls,&$failureMode):array{
    $wire=json_decode($body,true);$action=$wire['action'];$failureCalls[]=$action;
    if($action==='stop')$result=['state'=>'stopped'];
    elseif($action==='launch'&&in_array($failureMode,['refusal','server_error','malformed','unrecognized'],true))
        return ['status'=>$failureMode==='server_error'?503:409,'body'=>json_encode([
            'ok'=>$failureMode==='malformed'?true:false,'reason'=>$failureMode==='unrecognized'?'invented_error':'support_busy'])];
    elseif($action==='observe')return ['status'=>409,'body'=>json_encode(['ok'=>false,'reason'=>'observation_busy'])];
    elseif($action==='launch'||$action==='select')$result=['action_id'=>str_repeat('8',32),'state'=>'queued'];
    elseif($action==='result'&&$failureMode==='receipt_loss')throw new PortalDesktopException('connection_unknown');
    elseif($action==='result')$result=['state'=>'executed','result'=>['observation_available'=>true]];
    elseif($action==='observation'&&$failureMode==='stop_after_execution')$result=['state'=>'pending'];
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
check($selected['public_result']['state']==='executed'&&$selected['public_result']['reason']==='observation_unavailable'
    &&$failureCalls===['select','result','observation'],'lost post-action observation preserves actual OS acceptance without claiming task success');
$failureCalls=[];$failureMode='stop_after_execution';$aliveChecks=0;
$selected=portal_westy_desktop_dispatch($bound,'desktop_select',['inventory_id'=>str_repeat('9',32),'window'=>'123','process_id'=>45],
    static function()use(&$aliveChecks):bool{return ++$aliveChecks<3;},$failureTransport);
check($selected['public_result']['state']==='executed'&&$selected['public_result']['reason']==='task_stopped'
    &&$failureCalls===['select','result','observation','stop'],'explicit Stop after OS acceptance still stops control and preserves the executed receipt');
$failureCalls=[];$stopped=portal_westy_desktop_dispatch($bound,'desktop_stop',[],null,$failureTransport);
check($stopped['public_result']['state']==='stopped'&&$failureCalls===['stop'],'explicit Stop still dispatches exactly once');
$failureCalls=[];$stopped=portal_westy_desktop_dispatch($bound,'desktop_launch',$launchArguments,static fn():bool=>false,$failureTransport);
check($stopped['public_result']['state']==='stopped'&&$failureCalls===['stop'],'Stop before dispatch prevents launch');
$seen=portal_desktop_observation_result(['browser_origin'=>'https://example.test','browser_url'=>'https://example.test/actual/path?q=observed','image_png'=>null]);
check($seen['private_observation']['browser_url']==='https://example.test/actual/path?q=observed'&&!isset($seen['public_result']['observation']['browser_url']),'model receives actually observed browser URL without copying it to durable public metadata');
echo "PASS control v2 MySQL: $checks cumulative assertions\n";
