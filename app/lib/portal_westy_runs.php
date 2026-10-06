<?php
/** Private, bounded continuations. A receipt can resume inference, never replay execution. */
declare(strict_types=1);
require_once __DIR__.'/portal_westy_shell.php';

function portal_westy_runs_installed(PDO $pdo):bool
{
    try{$pdo->query('SELECT turn_id FROM portal_westy_tool_runs LIMIT 0');return true;}
    catch(PDOException $error){
        if(($error->errorInfo[1]??null)===1146||($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&str_contains($error->getMessage(),'no such table')))return false;
        throw $error;
    }
}
function portal_westy_run_origin():array
{
    $companion=$_SESSION['desktop_companion_session']??null;
    return ['origin_channel'=>portal_desktop_id($companion)?'companion':'portal',
        'origin_session_hash'=>hash('sha256',session_id()),'companion_session'=>portal_desktop_id($companion)?$companion:null];
}
function portal_westy_run_matches(array $run):bool
{
    foreach(portal_westy_run_origin() as $key=>$value)if($run[$key]!==$value)return false;
    return strtotime($run['expires_at'].' UTC')>time();
}
function portal_westy_run_find(PDO $pdo,array $scope,string $operation,bool $lock=false):?array
{
    if(!portal_westy_runs_installed($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM portal_westy_tool_runs WHERE scope_key=? AND tenant_id=? AND client_id=? AND operation_key=?'.($lock?portal_westy_lock($pdo):''));
    $q->execute([$scope['key'],$scope['tenant'],$scope['client'],$operation]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function portal_westy_run_create(PDO $pdo,array $scope,int $turn,string $conversation,string $operation):array
{
    if(!$pdo->inTransaction())throw new LogicException('run requires turn lock');
    $origin=portal_westy_run_origin();
    $q=$pdo->prepare("INSERT INTO portal_westy_tool_runs(turn_id,tenant_id,client_id,scope_key,conversation_id,operation_key,origin_channel,origin_session_hash,companion_session,state,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,'running',?,?)");
    $q->execute([$turn,$scope['tenant'],$scope['client'],$scope['key'],$conversation,$operation,...array_values($origin),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+1800)]);
    return portal_westy_run_find($pdo,$scope,$operation,true);
}

/** Images/UIA remain transient even when crossing a durable shell wait boundary. */
function portal_westy_run_replay(array $messages):string
{
    foreach($messages as &$message){
        if(($message['role']??'')!=='tool')continue;
        $parts=[];
        foreach($message['content']??[] as $part){
            if(($part['type']??'')!=='text')continue;
            if(str_contains($part['text'],'"untrusted_observation"'))$part=['type'=>'text','text'=>'Desktop observation expired. Obtain a fresh observation before acting.'];
            $parts[]=$part;
        }
        $message['content']=$parts?:[['type'=>'text','text'=>'Transient observation expired.']];
    }
    unset($message);
    $wire=json_encode($messages,JSON_THROW_ON_ERROR);
    if(strlen($wire)>131072)throw new PortalWestyException('context_limit');
    return $wire;
}
function portal_westy_run_restore(string $wire):array
{
    $messages=json_decode($wire,true,64,JSON_THROW_ON_ERROR);
    $original=json_decode($wire,false,64,JSON_THROW_ON_ERROR);
    foreach($messages as $index=>&$message)if(($message['role']??null)==='provider')$message['output']=$original[$index]->output;
    unset($message);return $messages;
}
function portal_westy_run_wait(PDO $pdo,array $context,string $operation,array $pending,array $messages):void
{
    $scope=portal_westy_scope($pdo,$context);$replay=portal_westy_run_replay($messages);
    // Serialize with Stop and turn erasure. The intent is durable BEFORE network I/O.
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT state FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$scope['key'],$operation]);
        if($q->fetchColumn()!=='pending')throw new PortalWestyException('stopped');
        $run=portal_westy_run_find($pdo,$scope,$operation,true);
        if(!$run||!portal_westy_run_matches($run)||$run['state']!=='running'||(int)$run['sequence']>=20)throw new PortalWestyException('run_unavailable');
        $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state='waiting',sequence=sequence+1,pending_json=?,replay_json=? WHERE turn_id=? AND scope_key=? AND state='running'");
        $q->execute([json_encode($pending,JSON_THROW_ON_ERROR),$replay,$run['turn_id'],$scope['key']]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function portal_westy_run_result(array $context,array $run,?callable $transport=null):array
{
    $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);
    if(isset($pending['terminal']))return ['ready'=>true,'receipt'=>$pending['terminal']];
    try{
        if(($pending['kind']??'shell')==='shell')$receipt=portal_westy_shell_receipt($context,$pending,$transport);
        else{
            $items=portal_devices_request(db(),$context,'operations',['device_reference'=>$pending['device_reference']],$transport)['items'];$receipt=null;
            foreach($items as $item)if(($item['reference']??null)===$pending['reference']){$receipt=portal_westy_tool_model_result($item);break;}
            if($receipt===null)return ['ready'=>true,'receipt'=>['state'=>'unknown','retry_allowed'=>false]];
        }
        $waiting=in_array($receipt['state']??'', ['queued','claimed','running','authorized','verifying','cancel_requested'],true);
        return ['ready'=>!$waiting,'receipt'=>$receipt];
    }catch(PortalDesktopException|PortalDevicesException $error){
        if($error->reason==='action_unavailable')return ['ready'=>true,'receipt'=>['state'=>'unknown','retry_allowed'=>false,'reason'=>'No execution receipt was found; the intent will not be sent again.']];
        return ['ready'=>false,'receipt'=>['state'=>'unavailable','reason'=>$error->reason,'retry_allowed'=>false]];
    }
}
/** Hold the same turn lock as Stop until the one queue request has returned. */
function portal_westy_run_dispatch(PDO $pdo,array $context,string $operation,array $input,?callable $transport=null):array
{
    $scope=portal_westy_scope($pdo,$context);$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT state FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$scope['key'],$operation]);
        if($q->fetchColumn()!=='pending')throw new PortalWestyException('stopped');
        $run=portal_westy_run_find($pdo,$scope,$operation,true);
        if(!$run||$run['state']!=='waiting'||!portal_westy_run_matches($run))throw new PortalWestyException('stopped');
        try{$receipt=portal_desktop_request($context,'shell_queue',$input,$transport);}
        catch(PortalDesktopException $error){
            $receipt=['state'=>$error->reason==='connection_unknown'?'unknown':'unavailable','reason'=>$error->reason,'retry_allowed'=>false];
            // A lost queue response is reconciled by request key. It is never sent again.
            if($error->reason!=='connection_unknown'){
                $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);$pending['terminal']=$receipt;
                $q=$pdo->prepare('UPDATE portal_westy_tool_runs SET pending_json=? WHERE turn_id=? AND scope_key=?');$q->execute([json_encode($pending,JSON_THROW_ON_ERROR),$run['turn_id'],$scope['key']]);
            }
        }
        $pdo->commit();return $receipt;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function portal_westy_run_public(PDO $pdo,array $context,array $scope,string $operation,?callable $transport=null):?array
{
    $run=portal_westy_run_find($pdo,$scope,$operation);
    if(!$run||!portal_westy_run_matches($run)||$run['state']!=='waiting')return null;
    $result=portal_westy_run_result($context,$run,$transport);
    return ['sequence'=>(int)$run['sequence'],'state'=>'waiting','ready'=>$result['ready'],'receipt'=>$result['receipt']];
}
function portal_westy_run_end(PDO $pdo,array $scope,string $operation,string $state):void
{
    if(!portal_westy_runs_installed($pdo))return;
    $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state=?,replay_json=NULL,pending_json=NULL WHERE scope_key=? AND operation_key=? AND state='running'");
    $q->execute([$state,$scope['key'],$operation]);
}
function portal_westy_run_stop(PDO $pdo,array $context,array $scope,string $operation,?callable $transport=null):void
{
    if(!portal_westy_runs_installed($pdo))return;
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$scope['key'],$operation]);
        $run=portal_westy_run_find($pdo,$scope,$operation,true);
        if(!$run||!portal_westy_run_matches($run)){$pdo->commit();return;}
        $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state='stopped',replay_json=NULL WHERE scope_key=? AND operation_key=? AND state IN ('running','waiting')");$q->execute([$scope['key'],$operation]);
        $q=$pdo->prepare("UPDATE portal_westy_turns SET state='unavailable',reason_code='stopped',finished_at=? WHERE scope_key=? AND operation_key=? AND state IN ('pending','complete')");$q->execute([gmdate('Y-m-d H:i:s'),$scope['key'],$operation]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    if($run['pending_json']!==null){
        $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);
        if(($pending['kind']??'shell')==='shell'){
            try{portal_westy_shell_receipt($context,$pending,$transport,'shell_cancel');}
            catch(Throwable){throw new PortalWestyException('stop_unconfirmed');}
        }
    }
}
