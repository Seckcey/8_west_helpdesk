<?php
/** Real desktop dispatcher against a synthetic one-delivery observation service. */
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../lib/portal_westy_desktop.php');
$start=strpos($source,'function portal_desktop_task(');
if($start===false)throw new RuntimeException('fixture source boundary changed');
eval(substr($source,$start));
final class PortalDesktopException extends RuntimeException {
    public function __construct(public string $reason,public int $status=409,public bool $requestRejected=false){parent::__construct($reason);}
}
function portal_desktop_id(mixed $value):bool{return is_string($value)&&preg_match('/\A[a-f0-9]{32}\z/D',$value)===1;}
function portal_devices_keys(array $value,array $keys):bool{sort($keys);$actual=array_keys($value);sort($actual);return $actual===$keys;}
function portal_westy_terminal_installed(PDO $pdo):bool{return true;}
function portal_devices_can_operate(array $context):bool{return true;}
$checks=0;$calls=[];$stored=null;$selected=0;$launched=0;$mode='controller';$receiptReason=null;
$task=['session_id'=>str_repeat('a',32),'task_id'=>str_repeat('b',32),'conversation_id'=>str_repeat('c',32),'origin_channel'=>'companion'];
$context=['desktop'=>$task];$inventory=str_repeat('d',32);$action=str_repeat('e',32);
function check(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function portal_desktop_request(array $context,string $operation,array $input,?callable $transport=null):array {
    global $calls,$stored,$selected,$launched,$mode,$task,$inventory,$action,$receiptReason;
    $calls[]=$operation;
    if($operation==='state')return $task+['connected'=>true,'state'=>'active','control_version'=>2];
    if($operation==='select'){
        $selected++;$stored=['key'=>$action,'observation'=>['observation_id'=>str_repeat('f',32),'available'=>$mode==='ordinary',
            'reason'=>$mode==='controller'?'controller_surface':null,'application'=>'chrome','image_png'=>null]];
        return ['action_id'=>$action];
    }
    if($operation==='result'){
        if($mode==='unknown')return ['state'=>'unknown','result'=>['reason'=>'connection_unknown']];
        return ['state'=>'executed','result'=>['reason'=>$mode==='launch'?'application_started':($mode==='ordinary'?null:'post_observation_unavailable'),
            'observation_available'=>$mode==='ordinary','observation_reason'=>$receiptReason??($mode==='controller'?'controller_surface':null)]];
    }
    if($operation==='observation'){
        if($stored===null||$stored['key']!==$input['request_key'])throw new PortalDesktopException('observation_unavailable');
        $observation=$stored['observation'];$stored=null;
        return ['state'=>'completed','observation'=>$observation];
    }
    if($operation==='windows'){
        if($stored!==null)throw new PortalDesktopException('observation_busy');
        $stored=['key'=>$input['request_key'],'observation'=>['inventory_id'=>$inventory,'observed_at'=>time(),
            'windows'=>[['window'=>'123','process_id'=>45,'application'=>'chrome']]]];return ['state'=>'pending'];
    }
    if($operation==='launch'){
        check($stored===null&&$input['inventory_id']===$inventory,'launch uses consumed fresh inventory');
        check($input['application']==='browser-fixture.exe'&&$input['arguments']===['--new-window','https://example.test/'],'literal requested navigation is retained');
        $launched++;$mode='launch';return ['action_id'=>$action];
    }
    if($operation==='stop')return ['state'=>'stopped'];
    throw new RuntimeException('unexpected service operation');
}
$select=['inventory_id'=>$inventory,'window'=>'123','process_id'=>45];
$first=portal_westy_desktop_dispatch($context,'desktop_select',$select);
check($calls===['select','result','observation']&&$stored===null,'consume attached unavailable observation before a fresh read');
check($selected===1&&$first['public_result']['state']==='executed'&&$first['public_result']['action_id']===$action,'executed selection is recorded once');
check(($first['public_result']['observation_reason']??null)==='controller_surface'&&($first['public_result']['action_reason']??null)==='post_observation_unavailable','keep distinct native action and observation reasons');
check(($first['public_result']['observation']['reason']??null)==='controller_surface'&&($first['public_result']['observation']['available']??null)===false,'unavailable controller view is not misreported as a timeout or successful observation');
check(($first['public_result']['recovery']??null)==='choose_another_window_or_launch_requested_url','ordinary authorized navigation has a supported recovery');
$calls=[];$windows=portal_westy_desktop_dispatch($context,'desktop_windows',[]);
check($calls===['windows','observation']&&$windows['public_result']['inventory_id']===$inventory,'fresh inventory succeeds immediately after consumed controller observation');
$calls=[];$launch=portal_westy_desktop_dispatch($context,'desktop_launch',['inventory_id'=>$inventory,'application'=>'browser-fixture.exe','arguments'=>['--new-window','https://example.test/']]);
check($calls===['launch','result']&&$launched===1&&$launch['public_result']['reason']==='application_started_discover_window','one new navigation launch does not replay the selection or claim page success');
$mode='ordinary';$calls=[];portal_westy_desktop_dispatch($context,'desktop_windows',[]);$next=portal_westy_desktop_dispatch($context,'desktop_select',$select);
check($calls===['windows','observation','select','result','observation']&&$next['public_result']['observation']['available']===true,'new browser target is freshly discovered, selected and observed');
$mode='unknown';$calls=[];$unknown=portal_westy_desktop_dispatch($context,'desktop_select',$select);
check($calls===['select','result']&&$unknown['public_result']['state']==='unknown'&&!isset($unknown['public_result']['recovery']),'uncertain input never becomes a recovery retry');
$mode='launch';$receiptReason='PRIVATE invalid reason';$calls=[];$stored=null;
$old=portal_westy_desktop_dispatch($context,'desktop_launch',['inventory_id'=>$inventory,'application'=>'browser-fixture.exe','arguments'=>['--new-window','https://example.test/']]);
check($calls===['launch','result']&&!str_contains(json_encode($old),'PRIVATE')&&!isset($old['public_result']['observation_reason']),'free-form receipt content is not treated as observation presence');
$definitions=portal_westy_desktop_definitions(new PDO('sqlite::memory:'),$context);
$description=array_column($definitions,'description','name')['desktop_select'];
check(str_contains($description,'controller_surface')&&str_contains($description,'desktop_launch'),'model receives explicit recovery through existing desktop tools');
echo "PASS desktop recovery: $checks assertions\n";
