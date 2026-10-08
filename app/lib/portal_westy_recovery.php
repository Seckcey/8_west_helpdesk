<?php
/** Actor-owned recovery across turns. These tools cannot restart an old task. */
declare(strict_types=1);

function portal_westy_recovery_definitions(): array
{
    $device=['type'=>'string','description'=>'Exact device reference from list_computers.'];
    $task=['type'=>'string','description'=>'Exact task_id from list_tasks. This is a prior action, not authority to replay it.'];
    $definitions=[
        ['list_tasks','Inspect your recent command tasks on this computer, including prior turns and connections. Read execution_state separately from outcome: lease_released means the native runtime no longer holds that action lease; unknown still means its effects are not proven. A recovered final receipt provides actual output and exit evidence without replaying commands. Also returns your saved execution_guard preference.', ['device_reference'=>$device]],
        ['read_task','Read the current receipt and actual output of an exact prior task. Does not repeat or resume execution. After a lost connection, use this to recover evidence before deciding on a new independent action.', ['device_reference'=>$device,'task_id'=>$task]],
        ['attach_task','Attach to your exact still-running command from an earlier turn or connection. Returns a process_id usable with read_process, write_stdin and stop_process in this task. This never restarts a process or replays a command; stopped or unknown work cannot be attached.', ['device_reference'=>$device,'task_id'=>$task]],
        ['cancel_task','Request cancellation of an exact task owned by this user. Read its receipt afterward; unknown is not confirmed cancellation. Never restarts a stopped task.', ['device_reference'=>$device,'task_id'=>$task]],
    ];
    return array_map(static fn(array $d):array=>['name'=>$d[0],'description'=>$d[1],
        'input_schema'=>['type'=>'object','properties'=>$d[2],'required'=>array_keys($d[2]),'additionalProperties'=>false]],$definitions);
}

function portal_westy_recovery_tool(PDO $pdo, array $context, array $call, string $operation, ?callable $transport=null): array
{
    $name=$call['name']??null;$args=$call['arguments']??null;
    $keys=$name==='list_tasks'?['device_reference']:['device_reference','task_id'];
    if(!in_array($name,['list_tasks','read_task','attach_task','cancel_task'],true)||!is_array($args)||!portal_devices_keys($args,$keys)
        ||!is_string($args['device_reference'])||!preg_match('/\A[1-9][0-9]{0,9}:[a-f0-9]{64}\z/D',$args['device_reference'])
        ||($name!=='list_tasks'&&!portal_desktop_id($args['task_id'])))throw new PortalWestyException('tool_invalid');
    $scope=portal_westy_scope($pdo,$context);$run=portal_westy_run_find($pdo,$scope,$operation);
    if(!$run||!portal_westy_run_matches($run)||$run['state']!=='running')throw new PortalWestyException('stopped');
    $input=['device_reference'=>$args['device_reference']];
    if($name!=='list_tasks')$input['action_id']=$args['task_id'];
    $action=match($name){'list_tasks'=>'terminal_tasks','read_task','attach_task'=>'terminal_task_result','cancel_task'=>'terminal_task_cancel'};
    try{$result=portal_desktop_request($context,$action,$input,$transport);}
    catch(PortalDesktopException $error){return ['state'=>'unavailable','reason'=>$error->reason,'retry_allowed'=>false,
        'guidance'=>'No new execution was requested. Read the existing task again when its connection is available.'];}
    // Identity is revalidated by the transport and again before returning private output.
    if(portal_westy_scope($pdo,$context)['key']!==$scope['key'])throw new PortalWestyException('sign_in',401);
    $project=static function(array $receipt):array {
        if(($receipt['contract']??null)!=='westy-terminal-v2'||!portal_desktop_id($receipt['action_id']??null))throw new PortalWestyException('tool_invalid');
        return ['task_id'=>$receipt['action_id']]+portal_westy_terminal_model_result($receipt);
    };
    if($name==='list_tasks'){
        if(!is_array($result['tasks']??null)||count($result['tasks'])>20)throw new PortalWestyException('tool_invalid');
        return ['tasks'=>array_map($project,$result['tasks']),'limit'=>20,'retry_allowed'=>false,
            'execution_guard'=>$result['execution_guard']??'allow'];
    }
    if(($result['action_id']??null)!==$args['task_id'])throw new PortalWestyException('tool_invalid');
    $projected=$project($result);
    if($name==='attach_task'&&($result['state']??null)==='running'){
        foreach(['action_id','run_id','conversation_id','session_id'] as $field)
            if(!portal_desktop_id($result[$field]??null))throw new PortalWestyException('tool_invalid');
        if(!in_array($result['origin_channel']??null,['portal','companion'],true)||!is_int($result['device_id']??null)
            ||$result['device_id']!==(int)explode(':',$args['device_reference'],2)[0]
            ||!in_array($result['execution_context']??null,['user','system'],true)||!array_key_exists('execution_generation',$result)
            ||($result['execution_generation']!==null&&(!is_int($result['execution_generation'])||$result['execution_generation']<0)))throw new PortalWestyException('tool_invalid');
        $pdo->beginTransaction();
        try{
            $run=portal_westy_run_find($pdo,$scope,$operation,true);
            if(!$run||!portal_westy_run_matches($run)||$run['state']!=='running')throw new PortalWestyException('stopped');
            $processes=portal_westy_terminal_processes($run);$handle=null;
            foreach($processes as $known=>$process)if($process['action_id']===$result['action_id']){
                portal_westy_terminal_validate_receipt($result,$process);
                foreach(['conversation_id','origin_channel'] as $field)
                    if($process[$field]!==$result[$field])throw new PortalWestyException('process_unavailable');
                $handle=$known;
            }
            if($handle===null){
                if(count($processes)>=20)throw new PortalWestyException('tool_limit');
                $handle=bin2hex(random_bytes(16));
                $processes[$handle]=array_intersect_key($result,array_flip(['run_id','conversation_id','origin_channel','action_id','session_id','device_id','execution_context','execution_generation']))
                    +['owner_run_id'=>$operation,'device_reference'=>$args['device_reference'],'checked_at'=>microtime(true),'settled'=>false];
                $q=$pdo->prepare('UPDATE portal_westy_tool_runs SET processes_json=? WHERE scope_key=? AND turn_id=?');
                $q->execute([json_encode($processes,JSON_THROW_ON_ERROR),$scope['key'],$run['turn_id']]);
            }
            $pdo->commit();return $projected+['process_id'=>$handle,'attached'=>true];
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
    return $projected+['execution_guard'=>$result['execution_guard']??'allow'];
}
