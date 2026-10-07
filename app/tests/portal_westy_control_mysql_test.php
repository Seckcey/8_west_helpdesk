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
echo "PASS control v2 MySQL: $checks cumulative assertions\n";
