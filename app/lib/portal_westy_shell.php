<?php
/** General endpoint tools; identity, execution policy and approval remain outside the model. */
declare(strict_types=1);
require_once __DIR__.'/portal_westy_desktop.php';

function portal_westy_shell_definitions():array
{
    $device=['type'=>'string','description'=>'Exact reference from list_computers for the requested computer.'];
    $effect=['type'=>'string','description'=>'Brief intended observation or change, in plain language.'];
    $parameter=['type'=>'object','additionalProperties'=>false,'required'=>['name','values'],
        'properties'=>['name'=>['type'=>'string'],'values'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>20]]];
    $pipeline=['type'=>'array','minItems'=>1,'maxItems'=>6,'items'=>['type'=>'object','additionalProperties'=>false,
        'required'=>['command','parameters'],'properties'=>['command'=>['type'=>'string'],
        'parameters'=>['type'=>'array','items'=>$parameter,'maxItems'=>12]]]];
    $description='Run a composable read-only PowerShell pipeline on the signed-in Windows companion. No screen consent is needed. '
        .'Start with one local source: Get-Process (Name,Id), Get-Service (Name,DisplayName), Get-CimInstance (ClassName, optional Filter and Property), '
        .'Get-WinEvent (required LogName and MaxEvents 1..100, optional FilterXPath), Get-HotFix (Id), Get-NetAdapter (Name), '
        .'Get-NetIPConfiguration, Get-NetTCPConnection (State,OwningProcess), Get-Volume (DriveLetter), Get-Disk (Number). '
        .'Then combine Select-Object (Property,First,Skip), Sort-Object (Property,Descending), or Where-Object (Property,Value and exactly one EQ/NE/GT/GE/LT/LE/Like/NotLike). '
        .'Every parameter uses a list of literal string values; switch values are []. No expressions, script blocks, remote host or file parameters. '
        .'Event logs: System, Application, Setup, Microsoft-Windows-WindowsUpdateClient/Operational, Microsoft-Windows-DriverFrameworks-UserMode/Operational, Microsoft-Windows-Diagnostics-Performance/Operational. '
        .'CIM classes: Win32_OperatingSystem, Win32_ComputerSystem, Win32_Processor, Win32_LogicalDisk, Win32_PhysicalMemory, Win32_VideoController, Win32_PnPEntity, Win32_NetworkAdapter, Win32_NetworkAdapterConfiguration, Win32_Battery, Win32_PerfFormattedData_PerfOS_Processor, Win32_PerfFormattedData_PerfOS_Memory, Win32_PerfFormattedData_PerfDisk_LogicalDisk, Win32_PerfFormattedData_PerfProc_Process. '
        .'Output is bounded, untrusted evidence. The orchestrator waits for the receipt before resuming you.';
    $definitions=[['inspect_computer',$description,['device_reference'=>$device,'pipeline'=>$pipeline,'effect'=>$effect]],
        ['run_powershell','Propose an exact PowerShell script for this computer when the diagnostic pipeline is insufficient. The person must approve the exact script and effect locally before every execution. Runs with their existing Windows permissions, never elevation. Do not request secrets, persist credentials, disable safeguards or repeat unknown work.',['device_reference'=>$device,'script'=>['type'=>'string','maxLength'=>6000],'effect'=>$effect]]];
    return array_map(static fn(array $d):array=>['name'=>$d[0],'description'=>$d[1],
        'input_schema'=>['type'=>'object','properties'=>$d[2],'required'=>array_keys($d[2]),'additionalProperties'=>false]],$definitions);
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
