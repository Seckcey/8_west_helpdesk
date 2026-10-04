<?php
/** No browser/model scope, credentials, scripts, or approval enter the tool boundary. */
declare(strict_types=1);
require_once __DIR__.'/portal_device_operations.php';

function portal_westy_tools_enabled(): bool
{ return cfg('portal_westy.tools_enabled',false) === true; }

function portal_westy_tool_definitions(): array
{
    $device=['type'=>'string','description'=>'Exact device reference returned by list_computers.'];
    $definitions=[
        ['list_computers','List this customer’s computers, connection status and recorded hardware facts with inventory timestamps. Use returned RAM capacity to answer hardware questions without starting work.',[]],
        ['read_computer_status','Read recorded checks and separate health-check and repair eligibility. A repair hold need not block diagnostics. Never starts or retries work.',['device_reference'=>$device]],
        ['start_health_check','Run a read-only Windows memory, disk-space and print-service check for the requested computer.',['device_reference'=>$device]],
        ['prepare_temp_cleanup','Preview only bounded Windows system temporary files and prepare a separate human approval. Does not delete anything.',['device_reference'=>$device]],
        ['propose_print_repair','Prepare an exact print-service restart approval from a fresh stopped-service diagnosis. Does not restart anything.',['device_reference'=>$device,'health_reference'=>['type'=>'string','description'=>'Completed health operation reference showing the service stopped.']]],
    ];
    return array_map(static fn(array $d):array=>['type'=>'function','name'=>$d[0],'description'=>$d[1],'strict'=>true,
        'parameters'=>['type'=>'object','properties'=>(object)$d[2],'required'=>array_keys($d[2]),'additionalProperties'=>false]],$definitions);
}

function portal_westy_tool_call(PDO $pdo,array $context,array $call,string $turnKey,array &$entry,callable $save,?callable $transport=null): array
{
    if (!portal_westy_tools_enabled()) throw new PortalWestyException('tools_unavailable');
    $name=$call['name']??'';
    $definitions=array_column(portal_westy_tool_definitions(),null,'name');
    if (!is_string($name)||!isset($definitions[$name])||!is_string($call['call_id']??null)
        || !preg_match('/^[a-zA-Z0-9_-]{1,160}$/D',$call['call_id']) || !is_string($call['arguments']??null)
        || strlen($call['arguments'])>2048) throw new PortalWestyException('tool_invalid');
    try {$args=json_decode($call['arguments'],true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new PortalWestyException('tool_invalid');}
    if(!is_array($args)||!portal_devices_keys($args,$definitions[$name]['parameters']['required']))throw new PortalWestyException('tool_invalid');
    foreach($args as $key=>$value) {
        $pattern=$key==='device_reference'?'/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D':'/^[a-f0-9]{32}$/D';
        if(!is_string($value)||preg_match($pattern,$value)!==1)throw new PortalWestyException('tool_invalid');
    }
    $action=match($name){'list_computers'=>'devices','read_computer_status'=>'operations','start_health_check'=>'health_start',
        'prepare_temp_cleanup'=>'temp_start','propose_print_repair'=>'repair_propose'};
    $input=$args;
    if($action==='devices')$input=['after'=>0];
    if(in_array($action,['health_start','temp_start','repair_propose'],true))$input['request_key']=substr(hash('sha256',$turnKey.':'.$call['call_id'].':'.$name),0,32);
    $entry=['key'=>$call['call_id'],'name'=>$name,'state'=>'dispatching','device_reference'=>$args['device_reference']??null,
        'request_key'=>$input['request_key']??null,'operation'=>null,'reason'=>null];
    // Write intent before network I/O. A lost response is displayed as unknown, never replayed.
    $save();
    try {
        $result=portal_devices_request($pdo,$context,$action,$input,$transport);
        $entry['state']='complete';
        if(in_array($action,['health_start','temp_start','repair_propose'],true))$entry['operation']=$result;
        $save();
        // A model can describe approval, but never receives a usable approval capability.
        return portal_westy_tool_model_result($result);
    }catch(PortalDevicesException $e){
        $entry['state']=$e->reason==='service_unavailable'?'unknown':'unavailable';$entry['reason']=$e->reason;$save();
        return ['available'=>false,'reason'=>$e->reason,'outcome'=>$entry['state'],'retry_allowed'=>false];
    }
}

function portal_westy_tool_model_result(array $result): array
{
    foreach($result as $key=>$value){
        if(in_array($key,['approval_fingerprint','request_key'],true))unset($result[$key]);
        elseif(is_array($value))$result[$key]=portal_westy_tool_model_result($value);
    }
    return $result;
}

/** Refresh known receipts, never re-dispatch an ambiguous intent. */
function portal_westy_refresh_tools(PDO $pdo,array $context,array $turns,?callable $transport=null): array
{
    $byDevice=[];
    foreach($turns as &$turn) {
        if(!is_array($turn['reply']['tools']??null))continue;
        foreach($turn['reply']['tools'] as &$tool) {
            if(is_array($tool['operation']??null)){$tool['operation']['can_approve']=false;$tool['operation']['can_cancel']=false;$tool['operation']['approval_fingerprint']=null;}
            unset($tool['proposal']);
            $device=$tool['device_reference']??null;
            if(!is_string($device)||!portal_westy_tools_enabled())continue;
            if(!isset($byDevice[$device])) {
                try {$byDevice[$device]=portal_devices_request($pdo,$context,'operations',['device_reference'=>$device],$transport);}
                catch(PortalDevicesException){$byDevice[$device]=['items'=>[]];}
            }
            foreach($byDevice[$device]['items'] as $operation) {
                if($operation['reference']===($tool['operation']['reference']??null))$tool['operation']=$operation;
                if(($operation['basis_reference']??null)===($tool['operation']['reference']??null)
                    && $operation['recipe']==='temp_cleanup')$tool['proposal']=$operation;
            }
        }
        unset($tool);
    }
    unset($turn);
    return $turns;
}

/** Human-only route: exact receipt in this subject's conversation, then service authority. */
function portal_westy_operation_action(PDO $pdo,array $context,array $request,?callable $transport=null,?callable $reauthorize=null): void
{
    if(!portal_westy_tools_enabled())throw new PortalWestyException('tools_unavailable');
    $scope=portal_westy_scope($pdo,$context);
    $reference=portal_westy_key($request['reference']??null);
    $conversation=portal_westy_key($request['conversation']??null);
    $q=$pdo->prepare('SELECT operation_key,state,input_text,reply_json,reason_code,created_at FROM portal_westy_turns WHERE scope_key=? AND conversation_key=? AND expires_at>? ORDER BY id DESC LIMIT 50');
    $q->execute([$scope['key'],$conversation,gmdate('Y-m-d H:i:s')]);$turns=[];
    foreach($q->fetchAll() as $row){$row['reply']=json_decode((string)$row['reply_json'],true);$turns[]=$row;}
    $matched=null;
    foreach(portal_westy_refresh_tools($pdo,$context,$turns,$transport) as $turn)foreach($turn['reply']['tools']??[] as $tool)
        foreach(['operation','proposal'] as $key)if(($tool[$key]['reference']??null)===$reference)$matched=$tool[$key];
    if($matched===null)throw new PortalWestyException('operation_unavailable');
    $action=$request['action']==='approve_operation'?'repair_approve':'operation_cancel';
    $input=['reference'=>$reference];
    if($action==='repair_approve') {
        if(($request['reviewed']??null)!==true || !$matched['can_approve']
            || !is_string($request['approval_fingerprint']??null)
            || !hash_equals((string)$matched['approval_fingerprint'],$request['approval_fingerprint']))throw new PortalWestyException('approval_changed');
        $input['approval_fingerprint']=$request['approval_fingerprint'];
    }
    if($reauthorize){$fresh=$reauthorize();if(!is_array($fresh)||$fresh['identity']!==$context['identity']||portal_westy_scope($pdo,$fresh)['key']!==$scope['key'])throw new PortalWestyException('sign_in',401);}
    portal_devices_request($pdo,$context,$action,$input,$transport);
}
