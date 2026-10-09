<?php
declare(strict_types=1);
require_once __DIR__.'/portal_desktop_sessions.php';

function portal_westy_control_pending(array $context,array $call,string $operation):array
{
    $args=$call['arguments']??null;$run=$context['tool_run']??null;
    if(!is_array($args)||!portal_devices_keys($args,['device_reference'])||!is_string($args['device_reference'])
        ||!preg_match('/\A[1-9][0-9]{0,9}:[a-f0-9]{64}\z/D',$args['device_reference'])||!is_string($call['id']??null)
        ||!preg_match('/\A[a-zA-Z0-9_-]{1,200}\z/D',$call['id'])||!is_array($run)||$run['operation_key']!==$operation
        ||!portal_westy_run_matches($run)||!is_string($context['authorized_task']??null))throw new PortalWestyException('tool_invalid');
    return ['kind'=>'control','call_id'=>$call['id'],'input'=>[
        'run_id'=>$operation,'request_key'=>substr(hash('sha256',$operation.':'.$call['id'].':desktop_open'),0,32),
        'conversation_id'=>$run['conversation_id'],'origin_channel'=>$run['origin_channel'],
        'device_reference'=>$args['device_reference'],'session_id'=>$run['companion_session'],
        'intent'=>$context['authorized_task'],'display_name'=>$context['identity']['display_name']]];
}
function portal_westy_control_dispatch(PDO $pdo,array $context,string $operation,?callable $transport=null):array
{
    $scope=portal_westy_scope($pdo,$context);$pending=null;$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT state FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$scope['key'],$operation]);
        if($q->fetchColumn()!=='pending')throw new PortalWestyException('stopped');
        $run=portal_westy_run_find($pdo,$scope,$operation,true);
        if(!$run||$run['state']!=='waiting'||!portal_westy_run_matches($run))throw new PortalWestyException('stopped');
        $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);
        if(($pending['kind']??null)!=='control')throw new PortalWestyException('run_unavailable');
        $state=portal_desktop_request($context,'control_start',$pending['input'],$transport);
        if(!portal_desktop_id($state['session_id']??null)||!portal_desktop_id($state['task_id']??null)
            ||($state['conversation_id']??null)!==$run['conversation_id']||($state['origin_channel']??null)!==$run['origin_channel']
            ||($state['control_version']??null)!==2)throw new PortalDesktopException('invalid_response');
        $binding=portal_desktop_binding($pdo,$context,$state['session_id']);
        $pdo->prepare('UPDATE portal_desktop_bindings SET task_id=?,conversation_id=?,operation_key=?,origin_channel=?,origin_session_hash=? WHERE session_id=? AND scope_key=?')
            ->execute([$state['task_id'],$run['conversation_id'],$operation,$run['origin_channel'],$run['origin_session_hash'],$binding['session_id'],$scope['key']]);
        $pending['session_id']=$state['session_id'];$pending['task_id']=$state['task_id'];
        $pdo->prepare('UPDATE portal_westy_tool_runs SET pending_json=? WHERE turn_id=? AND scope_key=?')
            ->execute([json_encode($pending,JSON_THROW_ON_ERROR),$run['turn_id'],$scope['key']]);
        $pdo->commit();
        // Only the second phase makes a native grant available. A lost prepare
        // response leaves an idle task; Stop can cancel before slow activation.
        return portal_desktop_request($context,'control_activate',$pending['input'],$transport);
    }catch(PortalDesktopException $error){
        if($pdo->inTransaction())$pdo->rollBack();if($pending===null)throw $error;
        try{portal_desktop_request($context,'control_cancel',$pending['input'],$transport);}catch(Throwable){}
        $receipt=['state'=>$error->reason==='connection_unknown'?'unknown':'unavailable','reason'=>$error->reason,'retry_allowed'=>false];
        $pending['terminal']=$receipt;portal_westy_terminal_save_pending($pdo,$scope,$operation,$pending);return $receipt;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function portal_westy_control_result(array $context,array $pending,?callable $transport=null):array
{
    $state=portal_desktop_request($context,'control_result',$pending['input'],$transport);
    if(($state['session_id']??null)!==($pending['session_id']??null)||($state['task_id']??null)!==($pending['task_id']??null))throw new PortalDesktopException('task_changed');
    $state['retry_allowed']=false;
    return ['ready'=>($state['state']??null)!=='consent_pending','receipt'=>$state];
}

/** Release only this run's bound task. A newer binding must survive a late reply. */
function portal_westy_control_release(PDO $pdo,array $context,array $run,?callable $transport=null):?array
{
    try{
        $where='tenant_id=? AND client_id=? AND scope_key=? AND operation_key=? AND conversation_id=? AND origin_channel=? AND origin_session_hash=?';
        $parameters=[$run['tenant_id'],$run['client_id'],$run['scope_key'],$run['operation_key'],$run['conversation_id'],$run['origin_channel'],$run['origin_session_hash']];
        // Expiry does not erase a task's identity or prove that its control ended.
        try{
            $q=$pdo->prepare('SELECT session_id,task_id FROM portal_desktop_bindings WHERE '.$where.' AND task_id IS NOT NULL LIMIT 2');
            $q->execute($parameters);$bindings=$q->fetchAll(PDO::FETCH_ASSOC);
        }catch(PDOException $error){
            // Ordinary chat also runs on deployments without optional desktop
            // schema. Other database failures cannot prove that control ended.
            if(($error->errorInfo[1]??null)===1146||($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
                &&str_contains($error->getMessage(),'no such table')))return null;
            throw $error;
        }
        if($bindings===[])return null;
        $scope=portal_westy_scope($pdo,$context);
        if($scope['key']!==$run['scope_key']||(int)$run['tenant_id']!==$scope['tenant']
            ||(int)$run['client_id']!==$scope['client']||!portal_westy_run_origin_matches($run))
            throw new PortalWestyException('stop_unconfirmed');
        if(count($bindings)!==1||!portal_desktop_id($bindings[0]['session_id'])||!portal_desktop_id($bindings[0]['task_id']))
            throw new PortalWestyException('stop_unconfirmed');
        $identity=$bindings[0];
        $result=portal_desktop_request($context,'stop',$identity,$transport);
        if(($result['session_id']??null)!==$identity['session_id']||($result['task_id']??null)!==$identity['task_id']
            ||($result['state']??null)!=='stopped')throw new PortalWestyException('stop_unconfirmed');
        $q=$pdo->prepare('UPDATE portal_desktop_bindings SET task_id=NULL,conversation_id=NULL,operation_key=NULL,origin_channel=NULL,origin_session_hash=NULL WHERE '
            .$where.' AND session_id=? AND task_id=?');
        $q->execute([...$parameters,$identity['session_id'],$identity['task_id']]);
        return $identity+['state'=>'stopped'];
    }catch(Throwable){throw new PortalWestyException('stop_unconfirmed');}
}
