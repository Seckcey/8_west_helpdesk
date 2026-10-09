<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_westy_tenant_ai.php';
require_once __DIR__.'/../lib/portal_westy_stream.php';
// Load the exact pure definitions/task functions, replacing only the surrounding
// service boundary. No paid provider, service HTTP request, account or disk DB.
$source=file_get_contents(__DIR__.'/../lib/portal_westy_desktop.php');
foreach([['portal_desktop_id','portal_desktop_transport'],['portal_desktop_task','portal_desktop_observation_result']] as [$first,$next]){
    $start=strpos($source,'function '.$first.'(');$end=strpos($source,'function '.$next.'(',$start+1);
    if($start===false||$end===false)throw new RuntimeException('fixture source boundary changed');
    eval(substr($source,$start,$end-$start));
}
final class PortalDesktopException extends RuntimeException {}
function portal_westy_terminal_installed(PDO $pdo):bool{return true;}
function portal_westy_tools_enabled():bool{return true;}
function portal_westy_tool_definitions():array{return [];}
function portal_westy_terminal_definitions():array{return [];}
function portal_westy_shell_definitions():array{return [];}
function portal_westy_recovery_definitions():array{return [];}
function portal_westy_scope(PDO $pdo,array $context):array{return ['tenant'=>1];}
function portal_westy_config():array{return [];}
function portal_guide_articles():array{return [];}
function portal_desktop_request(array $context,string $action,array $input,?callable $transport=null):array {
    if($action!=='state')throw new RuntimeException('unexpected service operation');
    if($GLOBALS['stateFailure'])throw new RuntimeException('PRIVATE_EXCEPTION_SECRET');
    return $GLOBALS['state'];
}
// Also permits this focused test to run after MAIN preserves the independent
// employee-access check already present on main, without changing this fixture.
function portal_devices_can_operate(array $context):bool{return true;}
$checks=0;
function check(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
$pdo=new PDO('sqlite::memory:');$stateFailure=false;
$task=['session_id'=>str_repeat('a',32),'task_id'=>str_repeat('b',32),'conversation_id'=>str_repeat('c',32),'origin_channel'=>'companion'];
$context=['desktop'=>$task,'tool_run'=>['sequence'=>2],'private'=>'PRIVATE_CONTEXT_SECRET'];
$state=$task+['connected'=>true,'state'=>'active','control_version'=>2];
$selection=['status'=>'active','provider'=>'openai','model'=>'gpt-6-luna','effort'=>'low','revision'=>4,'credential_version'=>1];
$expected=['desktop_windows','desktop_select','desktop_observe','desktop_action','desktop_stop','desktop_launch'];
$tools=portal_westy_ai_tools($pdo,$context,$selection,$reason);
check(array_column($tools,'name')===$expected&&$reason==='available_v2','exact active task retains all desktop tools');
foreach(['session_id','task_id','conversation_id','origin_channel'] as $field){
    $before=$state[$field];$state[$field]='different';
    check(array_column(portal_westy_ai_tools($pdo,$context,$selection,$reason),'name')===['desktop_open']
        &&$reason===$field.'_mismatch','distinguish unchanged identity refusal: '.$field);$state[$field]=$before;
}
$state['connected']=false;portal_westy_ai_tools($pdo,$context,$selection,$reason);check($reason==='disconnected','disconnected state');$state['connected']=true;
$state['state']='stopped';portal_westy_ai_tools($pdo,$context,$selection,$reason);check($reason==='inactive','inactive state');$state['state']='active';
$stateFailure=true;portal_westy_ai_tools($pdo,$context,$selection,$reason);check($reason==='state_request_failed','exception is only a fixed reason');$stateFailure=false;
$unbound=$context;unset($unbound['desktop']);portal_westy_ai_tools($pdo,$unbound,$selection,$reason);check($reason==='unbound','missing task');
$limited=$context;$limited['tool_run']['sequence']=20;check(portal_westy_ai_tools($pdo,$limited,$selection,$reason)===[]&&$reason==='tool_limit','unchanged run limit');
check(portal_westy_ai_tools($pdo,$context,['provider'=>'not-a-provider'],$reason)===[]&&$reason==='unsupported_model','unsupported model retains no desktop tools');
$operation=str_repeat('d',32);$records=[];$sink=static function(string $line)use(&$records):void{$records[]=$line;};
portal_westy_desktop_tools_diagnostic($operation,2,1,[...$tools,...$tools,['name'=>'PRIVATE_TOOL_SECRET','description'=>'PRIVATE_DESCRIPTION_SECRET']],"PRIVATE_REASON_SECRET\n",$sink);
$record=json_decode($records[0],true,32,JSON_THROW_ON_ERROR);
check($record['offered']===$expected&&$record['availability']==='unclassified'&&strlen($records[0])<=1024,'bounded names only, deduplicated and allowlisted');
check(!str_contains($records[0],'PRIVATE_'),'untrusted names, descriptions and reason text never logged');
foreach([['bad',2,1],[$operation,-1,1],[$operation,21,1],[$operation,2,0],[$operation,2,6]] as [$op,$sequence,$round])
    portal_westy_desktop_tools_diagnostic($op,$sequence,$round,$tools,'available_v2',$sink);
check(count($records)===1,'invalid correlation and out-of-bound rounds produce no record');
portal_westy_desktop_tools_diagnostic($operation,2,1,$tools,'available_v2',static function(){throw new RuntimeException('PRIVATE_LOG_FAILURE');});
check(true,'diagnostic failure does not affect work');

// The actual orchestrator must log the actual offered names before its provider
// callback, once. Its provider and resolver here are synthetic local functions.
$log=tempnam(sys_get_temp_dir(),'westy-desktop-diag-');$previous=ini_set('error_log',$log);$calls=0;
try{
    $provider=static function($selected,$system,$messages,$options,$emit,$alive)use(&$calls,$log,$expected):array{
        $calls++;$lines=file($log,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        check(count($lines)===1&&str_contains($lines[0],'"availability":"available_v2"'),'record already exists at provider boundary');
        check(array_column($options['tools'],'name')===$expected,'provider receives the recorded desktop names');
        return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>1,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>1]];
    };
    $partial=[];$noop=static function(...$args):void{};
    $result=portal_westy_ai_run($pdo,$context,$selection,[['role'=>'user','content'=>'PRIVATE_MESSAGE_SECRET']],$operation,
        $partial,$noop,$noop,$noop,$provider,resolver:static fn()=>$selection);
    $text=file_get_contents($log);
    check($result['ok']&&$calls===1&&!str_contains($text,'PRIVATE_'),'one provider callback, no content disclosure or behavior change');
}finally{ini_set('error_log',$previous);unlink($log);}
echo 'PASS '.$checks." desktop tool diagnostic assertions (synthetic provider only)\n";
