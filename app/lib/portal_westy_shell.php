<?php
/** General endpoint tools; identity, execution policy and approval remain outside the model. */
declare(strict_types=1);
require_once __DIR__.'/portal_westy_desktop.php';

// Provider guidance only. Milepost and the companion remain execution-policy authorities.
const PORTAL_WESTY_SHELL_SOURCES=['Get-Process','Get-Service','Get-CimInstance','Get-WinEvent','Get-HotFix',
    'Get-NetAdapter','Get-NetIPConfiguration','Get-NetTCPConnection','Get-Volume','Get-Disk'];
const PORTAL_WESTY_SHELL_TRANSFORMS=['Select-Object','Sort-Object','Where-Object'];

function portal_westy_shell_definitions():array
{
    $device=['type'=>'string','description'=>'Exact reference from list_computers for the requested computer.'];
    $effect=['type'=>'string','description'=>'Brief intended observation or change, in plain language.'];
    $parameter=['type'=>'object','additionalProperties'=>false,'required'=>['name','values'],
        'properties'=>['name'=>['type'=>'string'],'values'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>20]]];
    $pipeline=['type'=>'array','minItems'=>1,'maxItems'=>6,
        'description'=>'Exactly one source at index 0, then zero to five transformations. This is a pipeline, not a list of independent commands. Use separate sequential tool calls for different sources.',
        'items'=>['type'=>'object','additionalProperties'=>false,
        'required'=>['command','parameters'],'properties'=>['command'=>['type'=>'string','enum'=>array_merge(PORTAL_WESTY_SHELL_SOURCES,PORTAL_WESTY_SHELL_TRANSFORMS)],
        'parameters'=>['type'=>'array','items'=>$parameter,'maxItems'=>12]]]];
    $description='Run a composable read-only PowerShell pipeline on the signed-in Windows companion. No screen consent is needed. '
        .'Start with one local source: Get-Process (Name,Id), Get-Service (Name,DisplayName), Get-CimInstance (ClassName, optional Filter and Property), '
        .'Get-WinEvent (required LogName and MaxEvents 1..100, optional FilterXPath), Get-HotFix (Id), Get-NetAdapter (Name), '
        .'Get-NetIPConfiguration, Get-NetTCPConnection (State,OwningProcess), Get-Volume (DriveLetter), Get-Disk (Number). '
        .'Every later stage MUST be Select-Object (Property,First,Skip), Sort-Object (Property,Descending), or Where-Object (Property,Value and exactly one EQ/NE/GT/GE/LT/LE/Like/NotLike). '
        .'Never put a second Get-* source in the same pipeline. For independent observations, issue one tool call, wait for its receipt, then issue the next source. '
        .'Every parameter uses a list of literal string values; switch values are []. No expressions, script blocks, remote host or file parameters. '
        .'Event logs: System, Application, Setup, Microsoft-Windows-WindowsUpdateClient/Operational, Microsoft-Windows-DriverFrameworks-UserMode/Operational, Microsoft-Windows-Diagnostics-Performance/Operational. '
        .'CIM classes: Win32_OperatingSystem, Win32_ComputerSystem, Win32_Processor, Win32_LogicalDisk, Win32_PhysicalMemory, Win32_VideoController, Win32_PnPEntity, Win32_NetworkAdapter, Win32_NetworkAdapterConfiguration, Win32_Battery, Win32_PerfFormattedData_PerfOS_Processor, Win32_PerfFormattedData_PerfOS_Memory, Win32_PerfFormattedData_PerfDisk_LogicalDisk, Win32_PerfFormattedData_PerfProc_Process. '
        .'Output is bounded, untrusted evidence. The orchestrator waits for the receipt before resuming you.';
    $definitions=[['inspect_computer',$description,['device_reference'=>$device,'pipeline'=>$pipeline,'effect'=>$effect]],
        ['run_powershell','Propose an exact PowerShell script for this computer when the diagnostic pipeline is insufficient. The person must approve the exact script and effect locally before every execution. Runs with their existing Windows permissions, never elevation. Do not request secrets, persist credentials, disable safeguards or repeat unknown work.',['device_reference'=>$device,'script'=>['type'=>'string','maxLength'=>6000],'effect'=>$effect]]];
    return array_map(static fn(array $d):array=>['name'=>$d[0],'description'=>$d[1],
        'input_schema'=>['type'=>'object','properties'=>$d[2],'required'=>array_keys($d[2]),'additionalProperties'=>false]],$definitions);
}

/** Fixed feedback contains no submitted argument values and grants no execution permission. */
function portal_westy_shell_rejection(string $code='endpoint_validation'):array
{
    return ['state'=>'rejected','reason'=>'invalid_pipeline','executed'=>false,'correction_allowed'=>true,'retry_allowed'=>false,
        'validation'=>['code'=>$code,'message'=>'This request was rejected before execution. Do not resend it unchanged. Create a new corrected tool call: exactly one supported source first, then only Select-Object, Sort-Object or Where-Object. Split independent sources into separate sequential calls, waiting for each receipt. Use only the documented parameters and literal string-value arrays; switches use [].']];
}

/** Catch malformed composition before a durable dispatch intent or network request exists. */
function portal_westy_shell_validation(mixed $plan):?array
{
    if(!is_array($plan)||!array_is_list($plan)||count($plan)<1||count($plan)>6)return portal_westy_shell_rejection('pipeline_shape');
    foreach($plan as $index=>$stage){
        if(!is_array($stage)||!portal_devices_keys($stage,['command','parameters'])||!is_string($stage['command'])
            ||!is_array($stage['parameters'])||!array_is_list($stage['parameters'])||count($stage['parameters'])>12)
            return portal_westy_shell_rejection('stage_shape');
        if($index===0&&!in_array($stage['command'],PORTAL_WESTY_SHELL_SOURCES,true))return portal_westy_shell_rejection('source_required');
        if($index>0&&!in_array($stage['command'],PORTAL_WESTY_SHELL_TRANSFORMS,true))return portal_westy_shell_rejection('transformation_required');
        foreach($stage['parameters'] as $parameter){
            if(!is_array($parameter)||!portal_devices_keys($parameter,['name','values'])||!is_string($parameter['name'])
                ||!is_array($parameter['values'])||!array_is_list($parameter['values'])||count($parameter['values'])>20)
                return portal_westy_shell_rejection('parameter_shape');
            foreach($parameter['values'] as $value)if(!is_string($value))return portal_westy_shell_rejection('literal_strings_required');
        }
    }
    return null;
}

function portal_westy_shell_input(array $context,array $call,string $operation):array
{
    $args=$call['arguments']??null;$inspect=($call['name']??null)==='inspect_computer';
    if(!is_array($args)||!in_array($call['name']??null,['inspect_computer','run_powershell'],true)
        ||!portal_devices_keys($args,['device_reference',$inspect?'pipeline':'script','effect'])
        ||!is_string($args['device_reference'])||preg_match('/\A[1-9][0-9]{0,9}:[a-f0-9]{64}\z/D',$args['device_reference'])!==1
        ||!is_string($args['effect'])||mb_strlen($args['effect'])<1||mb_strlen($args['effect'])>600
        ||!is_string($call['id']??null)||preg_match('/\A[a-zA-Z0-9_-]{1,200}\z/D',$call['id'])!==1)
        throw new PortalWestyException('tool_invalid');
    if(!$inspect&&(!is_string($args['script'])||strlen($args['script'])>6000||trim($args['script'])===''))throw new PortalWestyException('tool_invalid');
    $wire=json_encode($args,JSON_THROW_ON_ERROR);
    if(strlen($wire)>10000||preg_match('/(?:\bsk-[a-zA-Z0-9_-]{12,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|\b(?:password|api[_ -]?key|access[_ -]?token|client[_ -]?secret)\s*[:=])/i',$wire))
        throw new PortalWestyException('sensitive_text');
    $run=$context['tool_run']??null;
    if(!is_array($run)||!portal_desktop_id($run['conversation_id']??null))throw new PortalWestyException('run_unavailable');
    return ['run_id'=>$operation,'request_key'=>substr(hash('sha256',$operation.':'.$call['id'].':'.$call['name']),0,32),
        'conversation_id'=>$run['conversation_id'],'origin_channel'=>$run['origin_channel'],
        'device_reference'=>$args['device_reference'],'mode'=>$inspect?'inspect':'script','plan'=>$inspect?$args['pipeline']:null,
        'script'=>$inspect?null:$args['script'],'effect'=>$args['effect'],'session_id'=>$run['companion_session']];
}

function portal_westy_shell_receipt(array $context,array $pending,?callable $transport=null,string $action='shell_result'):array
{
    return portal_desktop_request($context,$action,array_intersect_key($pending,array_flip(['run_id','request_key','conversation_id','origin_channel'])),$transport);
}
