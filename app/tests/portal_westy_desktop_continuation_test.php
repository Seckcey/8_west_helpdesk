<?php
/** Actual orchestration/desktop reads; synthetic provider, authority and service boundaries only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../lib/portal_westy_tenant_ai.php';
require_once __DIR__.'/../lib/portal_westy_stream.php';
$desktopSource=file_get_contents(__DIR__.'/../lib/portal_westy_desktop.php');
foreach([['final class PortalDesktopException','function portal_desktop_transport('],['function portal_desktop_task(',null]] as [$first,$next]){
    $start=strpos($desktopSource,$first);$end=$next===null?strlen($desktopSource):strpos($desktopSource,$next,$start+1);
    if($start===false||$end===false)throw new RuntimeException('Desktop fixture boundary changed');
    eval(substr($desktopSource,$start,$end-$start));
}
$runSource=file_get_contents(__DIR__.'/../lib/portal_westy_runs.php');
$start=strpos($runSource,'function portal_westy_run_replay(');$end=strpos($runSource,'function portal_westy_run_wait(',$start+1);
if($start===false||$end===false)throw new RuntimeException('Replay fixture boundary changed');
eval(substr($runSource,$start,$end-$start));
final class PortalWestyException extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly int $status=503){parent::__construct($reason);}
}
function portal_westy_terminal_installed(PDO $pdo):bool{return true;}
function portal_westy_tools_enabled():bool{return true;}
function portal_westy_tool_definitions():array{return [];}
function portal_westy_terminal_definitions():array{return [['name'=>'exec_command','description'=>'Synthetic command boundary',
    'input_schema'=>['type'=>'object','properties'=>[]]]];}
function portal_westy_shell_definitions():array{return [];}
function portal_westy_recovery_definitions():array{return [];}
function portal_westy_scope(PDO $pdo,array $context,bool $lock=false):array{return $GLOBALS['admissionScope']??['tenant'=>1];}
function portal_westy_config():array{return $GLOBALS['admissionConfig']??[];}
function portal_guide_articles():array{return [];}
function portal_devices_can_operate(array $context):bool{return true;}
function portal_devices_keys(array $input,array $keys):bool{$actual=array_keys($input);sort($actual);sort($keys);return $actual===$keys;}
function portal_westy_run_wait(PDO $pdo,array $context,string $operation,array $pending,array $messages):void
{$GLOBALS['waits'][]=$pending;$GLOBALS['waitReplays'][]=portal_westy_run_replay($messages);}
function portal_desktop_request(array $context,string $action,array $input,?callable $transport=null):array
{
    $GLOBALS['wire'][]=['action'=>$action,'input'=>$input];
    return ($GLOBALS['service'])($context,$action,$input);
}
$checks=0;
function check(bool $condition,string $label):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($label);}
$pdo=new PDO('sqlite::memory:');
$operation=str_repeat('d',32);$callId='open-synthetic';
$task=['session_id'=>str_repeat('a',32),'task_id'=>str_repeat('b',32),
    'conversation_id'=>str_repeat('c',32),'operation_key'=>$operation,'origin_channel'=>'companion'];
$pending=['kind'=>'control','call_id'=>$callId,'session_id'=>$task['session_id'],'task_id'=>$task['task_id'],
    'input'=>['run_id'=>$operation,'conversation_id'=>$task['conversation_id'],'origin_channel'=>'companion']];
$context=['desktop'=>$task,'tool_run'=>['operation_key'=>$operation,'conversation_id'=>$task['conversation_id'],
    'origin_channel'=>'companion','companion_session'=>$task['session_id'],'sequence'=>1,
    'pending_json'=>json_encode($pending,JSON_THROW_ON_ERROR)]];
$state=$task+['state'=>'active','connected'=>true,'control_version'=>2];
$partial=['reply'=>'','tools'=>[['key'=>$callId,'name'=>'desktop_open','state'=>'active','awaiting_run'=>false,'result'=>$state]]];
$selection=['status'=>'active','provider'=>'openai','model'=>'gpt-6-luna','effort'=>'low','revision'=>4,'credential_version'=>1,'catalog'=>'2026-10-04.1'];
$usage=['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20];
$messages=[['role'=>'user','content'=>[['type'=>'text','text'=>'Inspect the requested synthetic browser.']]],
    ['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$callId,'name'=>'desktop_open',
        'arguments'=>json_encode(['device_reference'=>'4:'.str_repeat('f',64)],JSON_THROW_ON_ERROR)]]]+$selection,
    ['role'=>'tool','call_id'=>$callId,'content'=>[['type'=>'text','text'=>json_encode(['untrusted_result'=>$state],JSON_THROW_ON_ERROR)]]]];
$inventory=['inventory_id'=>str_repeat('e',32),'observed_at'=>time(),'windows'=>[
    ['window'=>'123','process_id'=>45,'title'=>'PRIVATE_WINDOW_ONE'],
    ['window'=>'456','process_id'=>46,'title'=>'PRIVATE_WINDOW_TWO']]];
$expected=['desktop_windows','desktop_select','desktop_observe','desktop_action','desktop_stop','desktop_launch'];
$reply=static function(string $text,array $calls=[])use($selection,$usage):array{
    $output=[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>$text]]]];
    foreach($calls as $call)$output[]=['type'=>'function_call','call_id'=>$call['id'],'name'=>$call['name'],
        'arguments'=>json_encode((object)$call['arguments'],JSON_THROW_ON_ERROR)];
    return westy_tenant_ai_parse($selection,200,['model'=>$selection['model'],'status'=>'completed','output'=>$output,
        'usage'=>['input_tokens'=>$usage['input'],'output_tokens'=>$usage['output'],'input_tokens_details'=>['cached_tokens'=>0,'cache_write_tokens'=>0]]]);
};
$run=static function(array $ctx,array $initial,callable $provider,?callable $alive=null,?callable $resolver=null,?callable $setup=null)use($pdo,$selection,$messages,$operation,$state,$inventory):array{
    $GLOBALS['wire']=[];$GLOBALS['waits']=[];$GLOBALS['waitReplays']=[];
    $GLOBALS['service']=static function($context,$action,$input)use($state,$inventory):array{
        if($action==='state')return $state;
        if($action==='windows'){
            check($input['session_id']===$state['session_id']&&$input['task_id']===$state['task_id'],'discovery retains exact active identity');
            check(portal_desktop_id($input['request_key']??null),'discovery is one fresh read intent');
            return ['state'=>'pending'];
        }
        if($action==='observation')return ['state'=>'completed','observation'=>$inventory];
        throw new RuntimeException('Unexpected synthetic service operation: '.$action);
    };
    if($setup)$setup();
    $events=[];$saved=[];$work=$initial;
    $output=static function(string $event,array $data)use(&$events,&$work):void{
        $events[]=[$event,$data];if($event==='delta')$work['reply'].=$data['text'];
    };
    $save=static function()use(&$saved,&$work):void{$saved[]=$work;};
    $result=portal_westy_ai_run($pdo,$ctx,$selection,$messages,$operation,$work,$alive??static function():void{},$output,$save,
        $provider,resolver:$resolver??static fn()=>$selection);
    return ['result'=>$result,'partial'=>$work,'events'=>$events,'saved'=>$saved,'wire'=>$GLOBALS['wire'],'waits'=>$GLOBALS['waits'],'waitReplays'=>$GLOBALS['waitReplays']];
};
$log=tempnam(sys_get_temp_dir(),'westy-desktop-continuation-');$oldLog=ini_set('error_log',$log);
try{
    $calls=0;
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$expected):array{
        $calls++;check($selected['model']==='gpt-6-luna'&&$selected['revision']===4,'approved selection remains unchanged');
        if($calls===1){$emit('PROVISIONAL_UNSUPPORTED_CLAIM');return $reply('PROVISIONAL_UNSUPPORTED_CLAIM');}
        check(str_contains(json_encode($input,JSON_THROW_ON_ERROR),'PRIVATE_WINDOW_ONE'),'continuation receives actual private discovery');
        check(str_contains(json_encode($input,JSON_THROW_ON_ERROR),'PROVISIONAL_UNSUPPORTED_CLAIM'),'paid first continuation remains in private provider history');
        foreach($expected as $name)check(str_contains($system,$name),'current instructions name available tool '.$name);
        $emit('Which of the two real windows should I use?');return $reply('Which of the two real windows should I use?');
    };
    $out=$run($context,$partial,$provider);
    check($calls===2,'text-only desktop-open continuation gets one discovery-backed recovery');
    check($out['result']['ok']===true&&count($out['result']['rounds'])===2,'both paid rounds retain successful receipts');
    check($out['result']['usage']['input']===200&&$out['result']['cost_micro_usd']===2*westy_tenant_ai_cost($selection,$usage),'both paid rounds remain charged');
    check($out['partial']['reply']==='Which of the two real windows should I use?','provisional capability claim is not delivered');
    check(array_column($out['partial']['tools'],'name')===['desktop_open','desktop_windows'],'discovery has its own real public receipt');
    check($out['partial']['tools'][1]['state']==='observed'&&$out['partial']['tools'][1]['result']['window_count']===2,'public receipt records actual discovery outcome');
    check(!str_contains(json_encode($out['saved'],JSON_THROW_ON_ERROR),'PRIVATE_WINDOW'),'private inventory never enters saved public receipts');
    check(count(array_filter($out['wire'],static fn($call)=>$call['action']==='windows'))===1,'discovery is not looped after a second text-only reply');
    check(array_diff(array_column($out['wire'],'action'),['state','windows','observation'])===[],'ambiguous target receives no automatic input, selection, launch or Stop');
    check($out['waits']===[],'recovery stays within the existing paid attempt without another durable continuation');
    $discoveryKey=$out['partial']['tools'][1]['key'];

    // Encode the actual next request and parse real synthetic Responses SSE frames.
    // This checks the new observation context at both adapter boundaries, without HTTP.
    $bodies=[];
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$bodies,$reply,$expected):array{
        $body=westy_tenant_ai_body($selected,$system,$input,$options+['stream'=>true]);$bodies[]=$body;
        if(count($bodies)===2){
            check($body['model']==='gpt-6-luna'&&$body['reasoning']['effort']==='low','actual recovery request preserves approved model and effort');
            check($body['store']===false&&$body['parallel_tool_calls']===false&&!isset($body['tool_choice']),'recovery does not change storage, parallel-call policy or force a tool');
            check(array_values(array_intersect(array_column($body['tools'],'name'),$expected))===$expected,'actual request encodes every currently offered desktop function');
            $last=$body['input'][array_key_last($body['input'])];$data=json_decode($last['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
            check($last['role']==='user'&&$last['content'][0]['type']==='input_text'
                &&$data['server_read_only_discovery']['untrusted_observation']['windows'][0]['title']==='PRIVATE_WINDOW_ONE','adapter sends real discovery as clearly labeled observation context');
            $outputs=array_values(array_filter($body['input'],static fn($item)=>($item['type']??null)==='function_call_output'));
            check(array_column($outputs,'call_id')===['open-synthetic'],'recovery invents no provider call or function result identity');
        }
        $text=count($bodies)===1?'SYNTHETIC_PREMATURE_REFUSAL':'The observed windows need a target choice.';
        $fixture=$reply($text);$parser=new WestyTenantAiStream($selected,$emit,$alive);
        $frames='data: '.json_encode(['type'=>'response.output_text.delta','delta'=>$text],JSON_THROW_ON_ERROR)."\n\n"
            .'data: '.json_encode(['type'=>'response.completed','response'=>['status'=>'completed','model'=>$selected['model'],
                'output'=>$fixture['continuation']['output'],'usage'=>['input_tokens'=>100,'output_tokens'=>20,'input_tokens_details'=>['cached_tokens'=>0,'cache_write_tokens'=>0]]]],JSON_THROW_ON_ERROR)."\n\n";
        $parser->feed(substr($frames,0,17));$parser->feed(substr($frames,17));return $parser->result();
    };
    $out=$run($context,$partial,$provider);
    check(count($bodies)===2&&$out['result']['ok']&&$out['partial']['reply']==='The observed windows need a target choice.','actual SSE parser follows bounded recovery and suppresses its provisional reply');
    check($out['result']['usage']['input']===200,'actual parsed receipts retain both paid rounds');

    // The model, never recovery, chooses the target after seeing the real inventory.
    $calls=0;
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$inventory):array{
        $calls++;
        if($calls===1){$emit('No controls here.');return $reply('No controls here.');}
        if($calls===2){
            check(str_contains(json_encode($input,JSON_THROW_ON_ERROR),$inventory['inventory_id']),'model receives the real fresh inventory identifier');
            return $reply('',[['id'=>'model-chose-target','name'=>'desktop_select',
                'arguments'=>['inventory_id'=>$inventory['inventory_id'],'window'=>'456','process_id'=>46]]]);
        }
        $emit('Verified the requested synthetic window.');return $reply('Verified the requested synthetic window.');
    };
    $out=$run($context,$partial,$provider,setup:static function()use($inventory):void{
        $before=$GLOBALS['service'];
        $GLOBALS['service']=static function($context,$action,$input)use($before,$inventory):array{
            if($action==='select'){
                check($input['window']==='456'&&$input['process_id']===46,'only model-selected target reaches dispatch');
                return ['action_id'=>str_repeat('f',32),'state'=>'queued'];
            }
            if($action==='result')return ['state'=>'executed','result'=>['observation_available'=>true]];
            if($action==='observation'&&$input['request_key']===str_repeat('f',32))return ['state'=>'completed','observation'=>[
                'observation_id'=>str_repeat('9',32),'observed_at'=>time(),'available'=>true,'complete'=>true,
                'application'=>'Synthetic browser','width'=>800,'height'=>600,'image_png'=>null]];
            return $before($context,$action,$input);
        };
    });
    check($calls===3&&$out['result']['ok']&&$out['partial']['tools'][2]['state']==='executed','recovery can continue into a real model-selected tool and verified result');
    check($out['result']['usage']['input']===300&&count($out['result']['rounds'])===3,'recovery and subsequent tool round retain all paid usage');

    // A genuine first tool call bypasses fallback and preserves its progress.
    $calls=0;
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply):array{
        $calls++;
        if($calls===1){$emit('Reading current windows.');return $reply('Reading current windows.',[['id'=>'model-read','name'=>'desktop_windows','arguments'=>[]]]);}
        $emit('Read complete.');return $reply('Read complete.');
    };
    $out=$run($context,$partial,$provider);
    check($calls===2&&array_column($out['partial']['tools'],'key')===['open-synthetic','model-read'],'model-requested discovery is not duplicated');
    check(str_contains($out['partial']['reply'],'Reading current windows.')&&str_contains($out['partial']['reply'],'Read complete.'),'genuine tool-call progress is released');

    // No prose classification: even unrelated wording follows the same single bound.
    $calls=0;$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply):array{
        $calls++;$emit('A text-only answer in any language.');return $reply('A text-only answer in any language.');
    };
    $out=$run($context,$partial,$provider);
    check($calls===2&&count($out['partial']['tools'])===2,'a second no-progress answer is not retried or looped');

    $skipCases=[];
    foreach(['unknown','unavailable','refused','consent_pending','stopped'] as $value){
        $changed=$partial;$changed['tools'][0]['state']=$value;$skipCases['open '.$value]=[$context,$changed];
    }
    $changed=$partial;$changed['tools'][0]['awaiting_run']=true;$skipCases['not yet resumed']=[$context,$changed];
    $changed=$partial;$changed['tools'][0]['result']['task_id']=str_repeat('7',32);$skipCases['receipt belongs to another task']=[$context,$changed];
    $changed=$partial;$changed['tools'][0]['key']='another-open';$skipCases['another provider call']=[$context,$changed];
    $changed=$partial;$changed['tools'][]=['key'=>$discoveryKey,'name'=>'desktop_windows','state'=>'dispatching'];$skipCases['prior read outcome unresolved']=[$context,$changed];
    $changed=$context;unset($changed['desktop']);$skipCases['unbound']=[$changed,$partial];
    $changed=$context;$changed['tool_run']['companion_session']=str_repeat('7',32);$skipCases['other companion']=[$changed,$partial];
    $changed=$context;$changed['tool_run']['operation_key']=str_repeat('7',32);$skipCases['other operation']=[$changed,$partial];
    $changed=$context;$changed['tool_run']['origin_channel']='portal';$skipCases['other origin']=[$changed,$partial];
    $changed=$context;$changed['tool_run']['sequence']=20;$skipCases['existing round limit']=[$changed,$partial];
    foreach(['continuation','terminal','shell'] as $kind){
        $changed=$context;$changed['tool_run']['pending_json']=json_encode(['kind'=>$kind]);$skipCases['other continuation '.$kind]=[$changed,$partial];
    }
    foreach(['session_id','task_id'] as $field){
        $p=$pending;$p[$field]=str_repeat('7',32);$changed=$context;$changed['tool_run']['pending_json']=json_encode($p);
        $skipCases['pending '.$field.' mismatch']=[$changed,$partial];
    }
    $p=$pending;$p['terminal']=['state'=>'unavailable','reason'=>'desktop_review_refused'];$changed=$context;
    $changed['tool_run']['pending_json']=json_encode($p);$skipCases['saved genuine refusal']=[$changed,$partial];
    foreach($skipCases as $label=>[$ctx,$initial]){
        $calls=0;$out=$run($ctx,$initial,$provider);
        check($calls===1&&$out['result']['ok'],'no additional inference for '.$label);
        check(array_diff(array_column($out['wire'],'action'),['state'])===[],'no automatic read for '.$label);
    }

    foreach(['disconnected','inactive','v1','task_changed'] as $mode){
        $calls=0;
        $out=$run($context,$partial,$provider,setup:static function()use($state,$mode):void{
            $altered=$state;
            if($mode==='disconnected')$altered['connected']=false;
            elseif($mode==='inactive')$altered['state']='stopped';
            elseif($mode==='v1')$altered['control_version']=1;
            else $altered['task_id']=str_repeat('7',32);
            $GLOBALS['service']=static fn()=>$altered;
        });
        check($calls===1&&array_column($out['wire'],'action')===['state'],'current unavailable catalog is honored: '.$mode);
    }

    // Real read refusals are fed back once, without disguising or overriding them.
    foreach(['desktop_review_refused','elevation_required','companion_offline','capability_disabled'] as $reason){
        $calls=0;
        $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$reason):array{
            $calls++;if($calls===2)check(str_contains(json_encode($input,JSON_THROW_ON_ERROR),$reason),'actual discovery reason reaches continuation: '.$reason);
            $emit('A precise prerequisite remains.');return $reply('A precise prerequisite remains.');
        };
        $out=$run($context,$partial,$provider,setup:static function()use($reason):void{
            $before=$GLOBALS['service'];$GLOBALS['service']=static function($context,$action,$input)use($before,$reason):array{
                if($action==='windows')throw new PortalDesktopException($reason,403,true);
                return $before($context,$action,$input);
            };
        });
        check($calls===2&&$out['partial']['tools'][1]['state']==='unavailable'
            &&$out['partial']['tools'][1]['result']['reason']===$reason,'real discovery refusal is retained: '.$reason);
        check(array_diff(array_column($out['wire'],'action'),['state','windows'])===[],'refusal triggers no observation retry, grant or mutation');
    }

    $provider=static function($selected,$system,$input,$options,$emit,$alive)use($usage):array{
        $emit('Unfinished provider output.');return ['ok'=>false,'reason'=>'provider_incomplete','usage'=>$usage];
    };
    $out=$run($context,$partial,$provider);
    check(!$out['result']['ok']&&$out['result']['reason']==='provider_incomplete'&&$out['partial']['reply']==='','provider failure is not a recovery trigger');
    check(array_column($out['wire'],'action')===['state']&&count($out['result']['rounds'])===1,'failed provider call is never replayed');

    $provider=static fn($selected)=>westy_tenant_ai_parse($selected,200,['model'=>$selected['model'],'status'=>'completed',
        'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'refusal','refusal'=>'Synthetic formal refusal.']]]],
        'usage'=>['input_tokens'=>100,'output_tokens'=>20,'input_tokens_details'=>['cached_tokens'=>0,'cache_write_tokens'=>0]]]);
    $out=$run($context,$partial,$provider);
    check(!$out['result']['ok']&&$out['result']['reason']==='provider_invalid'&&count($out['result']['rounds'])===1,'formal provider refusal is not rewritten into capability recovery');
    check($out['result']['usage']===$usage&&array_column($out['wire'],'action')===['state'],'formal refusal retains known cost without discovery or a provider retry');

    $stopped=false;$calls=0;
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$stopped,&$calls,$reply):array{
        $calls++;$emit('Held answer.');$stopped=true;return $reply('Held answer.');
    };
    $out=$run($context,$partial,$provider,alive:static function()use(&$stopped):void{if($stopped)throw new PortalWestyException('stopped');});
    check($calls===1&&!$out['result']['ok']&&$out['result']['reason']==='stopped','Stop after the provider round wins over recovery');
    check($out['result']['usage']===$usage&&$out['partial']['reply']===''&&array_column($out['wire'],'action')===['state'],'Stop preserves paid usage and prevents read dispatch or late delivery');

    $stopped=false;$calls=0;
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply):array{
        $calls++;$emit('Held before discovery.');return $reply('Held before discovery.');
    };
    $out=$run($context,$partial,$provider,alive:static function()use(&$stopped):void{if($stopped)throw new PortalWestyException('stopped');},
        setup:static function()use(&$stopped):void{
            $before=$GLOBALS['service'];$GLOBALS['service']=static function($context,$action,$input)use($before,&$stopped):array{
                if($action==='windows')$stopped=true;
                return $before($context,$action,$input);
            };
        });
    check($calls===1&&!$out['result']['ok']&&$out['result']['reason']==='stopped','Stop while discovery is pending prevents another provider round');
    check($out['result']['usage']===$usage&&$out['partial']['reply']===''&&array_column($out['wire'],'action')===['state','windows'],'Stop consumes no late observation and never replays input');

    $changed=false;$calls=0;
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$changed,&$calls,$reply):array{
        $calls++;$emit('Held answer.');$changed=true;return $reply('Held answer.');
    };
    $out=$run($context,$partial,$provider,resolver:static function()use(&$changed,$selection):array{return array_replace($selection,['revision'=>$changed?5:4]);});
    check($calls===1&&!$out['result']['ok']&&$out['result']['reason']==='ai_changed','changed provider authority prevents recovery');
    check($out['partial']['reply']===''&&array_column($out['wire'],'action')===['state'],'authority loss neither dispatches nor delivers held text');

    // Prior uncertain actions are retained; only an independent new read is made.
    $unknown=['key'=>'prior-action','name'=>'desktop_action','state'=>'unknown','result'=>['action_id'=>str_repeat('8',32),'retry_allowed'=>false]];
    $withUnknown=$partial;$withUnknown['tools'][]=$unknown;$calls=0;
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply):array{$calls++;$emit('Fresh read only.');return $reply('Fresh read only.');};
    $out=$run($context,$withUnknown,$provider);
    check($calls===2&&$out['partial']['tools'][1]===$unknown,'independent read preserves unknown action history exactly');
    check(array_diff(array_column($out['wire'],'action'),['state','windows','observation'])===[],'unknown action is never replayed');

    $calls=0;$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply):array{
        $calls++;if($calls===1){$emit('No observation yet.');return $reply('No observation yet.');}
        return $reply('',[['id'=>'model-read-'.$calls,'name'=>'desktop_windows','arguments'=>[]]]);
    };
    $out=$run($context,$partial,$provider);
    check($calls===5&&($out['result']['waiting']??false)===true&&$out['waits']===[['kind'=>'continuation']],'recovery never adds a sixth round or a new run budget');
    check(count($out['result']['rounds'])===5&&$out['result']['usage']['input']===500,'five paid rounds remain fully accounted');
    check(count($out['waitReplays'])===1&&!str_contains($out['waitReplays'][0],'PRIVATE_WINDOW'),'actual durable replay expires automatic and model-requested private inventories');
    $restored=portal_westy_run_restore($out['waitReplays'][0]);$discoveryReceipt=null;
    foreach($restored as $message)if(($message['role']??null)==='user'){
        $data=json_decode($message['content'][0]['text']??'',true);
        if(isset($data['server_read_only_discovery']))$discoveryReceipt=$data['server_read_only_discovery'];
    }
    check($discoveryReceipt['result']['window_count']===2&&$discoveryReceipt['observation_status']==='expired'
        &&!isset($discoveryReceipt['untrusted_observation']),'real restored continuation retains public discovery proof and requires a fresh inventory');

    // One admission regression executes the actual message, binding and run
    // readers against SQLite. Namespace seams replace only external authority
    // and the desktop include boundary; the AI-run seam calls the real runner
    // then stops before accounting/cleanup, which other tests own.
    eval(<<<'PHP'
namespace WestyAdmissionFixture;
final class ReachedRunner extends \RuntimeException {}
function portal_westy_runs_installed(\PDO $pdo):bool{return true;}
function portal_desktop_origin():string{return 'companion';}
function portal_westy_desktop_context(\PDO $pdo,array $context,string $conversation,string $operation):array
{return portal_desktop_context($pdo,$context,$conversation,$operation);}
function portal_westy_ai_run($pdo,$context,$snapshot,$messages,$operation,&$partial,...$arguments):array
{
    $GLOBALS['admissionContext']=$context;
    $GLOBALS['admissionResult']=\portal_westy_ai_run($pdo,$context,$snapshot,$messages,$operation,$partial,...$arguments);
    $GLOBALS['admissionPartial']=$partial;
    throw new ReachedRunner();
}
PHP);
    foreach([
        ['portal_westy.php','portal_westy_lock','portal_westy_state'],
        ['portal_westy.php','portal_westy_require_conversation','portal_westy_save_draft'],
        ['portal_westy_runs.php','portal_westy_run_origin','portal_westy_run_create'],
        ['portal_westy_runs.php','portal_westy_run_result','portal_westy_run_dispatch'],
        ['portal_westy_control.php','portal_westy_control_result','portal_westy_control_cleanup_public'],
        ['portal_desktop_sessions.php','portal_desktop_context','portal_desktop_bind'],
        ['portal_westy_tools.php','portal_westy_tool_model_result','portal_westy_refresh_tools'],
    ] as [$file,$first,$next]){
        $source=file_get_contents(__DIR__.'/../lib/'.$file);$start=strpos($source,'function '.$first.'(');$end=strpos($source,'function '.$next.'(',$start+1);
        if($start===false||$end===false)throw new RuntimeException('Admission fixture source boundary changed');
        eval('namespace WestyAdmissionFixture; use \\PDO; use \\Throwable; use \\PortalWestyException; use \\PortalDesktopException; '.substr($source,$start,$end-$start));
    }
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
    $db->exec('CREATE TABLE portal_westy_accounts(scope_key TEXT UNIQUE,tenant_id INT,client_id INT,binding_id INT,conversation_key TEXT,created_at TEXT);
        CREATE TABLE portal_westy_turns(id INTEGER PRIMARY KEY,tenant_id INT,client_id INT,scope_key TEXT,operation_key TEXT,conversation_key TEXT,
            state TEXT,input_text TEXT,reply_json TEXT,expires_at TEXT,created_at TEXT,reserve_microusd INT,charged_microusd INT,finished_at TEXT);
        CREATE TABLE portal_westy_tool_runs(turn_id INT,tenant_id INT,client_id INT,scope_key TEXT,operation_key TEXT,conversation_id TEXT,
            state TEXT,sequence INT,pending_json TEXT,replay_json TEXT,origin_channel TEXT,origin_session_hash TEXT,companion_session TEXT,expires_at TEXT);
        CREATE TABLE portal_desktop_bindings(session_id TEXT,task_id TEXT,tenant_id INT,client_id INT,scope_key TEXT,conversation_id TEXT,
            operation_key TEXT,origin_channel TEXT,origin_session_hash TEXT,expires_at TEXT);
        CREATE TABLE portal_westy_ai_attempts(id INTEGER PRIMARY KEY,turn_id INT,sequence INT,tenant_id INT,client_id INT,scope_key TEXT,
            provider TEXT,model_name TEXT,catalog_version TEXT,ai_revision INT,credential_version INT,desktop_task_id TEXT,request_fingerprint TEXT,
            state TEXT,reserve_microusd INT,charged_microusd INT,created_at TEXT,usage_json TEXT);
        CREATE TABLE portal_westy_budgets(tenant_id INT,client_id INT,month_key TEXT,charged_microusd INT,UNIQUE(tenant_id,client_id,month_key));');
    $scope=['tenant'=>1,'client'=>11,'binding'=>1,'key'=>str_repeat('6',64)];$GLOBALS['admissionScope']=$scope;
    $GLOBALS['admissionConfig']=['enabled'=>true,'ai_enabled'=>true,'api_key'=>'','retention_days'=>30,'hourly_limit'=>100,'daily_limit'=>500,'monthly_microusd'=>20000000];
    $_SESSION['desktop_companion_session']=$task['session_id'];$expires=gmdate('Y-m-d H:i:s',time()+1800);$now=gmdate('Y-m-d H:i:s');
    $db->prepare('INSERT INTO portal_westy_accounts VALUES(?,?,?,?,?,?)')->execute([$scope['key'],1,11,1,$task['conversation_id'],$now]);
    $waitingPartial=$partial;$waitingPartial['tools'][0]['awaiting_run']=true;
    $db->prepare('INSERT INTO portal_westy_turns VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        1,1,11,$scope['key'],$operation,$task['conversation_id'],'complete','Inspect the requested synthetic browser.',json_encode($waitingPartial),$expires,$now,100,100,$now]);
    $db->prepare('INSERT INTO portal_westy_tool_runs VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        1,1,11,$scope['key'],$operation,$task['conversation_id'],'waiting',1,json_encode($pending),portal_westy_run_replay(array_slice($messages,0,2)),
        'companion',hash('sha256',session_id()),$task['session_id'],$expires]);
    $db->prepare('INSERT INTO portal_desktop_bindings VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
        $task['session_id'],$task['task_id'],1,11,$scope['key'],$task['conversation_id'],$operation,'companion',hash('sha256',session_id()),$expires]);
    $db->prepare('INSERT INTO portal_westy_ai_attempts(turn_id,sequence,scope_key,provider,model_name,ai_revision,credential_version,state,created_at,usage_json) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([1,1,$scope['key'],'openai','gpt-6-luna',4,1,'complete',$now,'{"known":true}']);
    $GLOBALS['wire']=[];$before=$GLOBALS['service'];
    $GLOBALS['service']=static function($context,$action,$input)use($before,$state):array{
        if($action==='control_result')return $state;
        return $before($context,$action,$input);
    };
    $calls=0;$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$db,$reply,$state):array{
        $calls++;
        if($calls===1){
            $row=$db->query('SELECT state,pending_json,replay_json FROM portal_westy_tool_runs')->fetch();
            check($row===['state'=>'running','pending_json'=>null,'replay_json'=>null],'actual admission clears database pending/replay before the first provider call');
            $receipt=json_decode($input[array_key_last($input)]['content'][0]['text'],true);
            check($receipt['untrusted_result']['task_id']===$state['task_id']&&$receipt['untrusted_result']['state']==='active','actual admission supplies the exact completed open receipt');
            $emit('ADMISSION_PROVISIONAL');return $reply('ADMISSION_PROVISIONAL');
        }
        check(str_contains(json_encode($input,JSON_THROW_ON_ERROR),'PRIVATE_WINDOW_ONE'),'actual admitted request reaches fresh recovery inventory');
        $emit('Admitted continuation observed.');return $reply('Admitted continuation observed.');
    };
    $admissionContext=['desktop_renewal_proof'=>null];
    try{
        \WestyAdmissionFixture\portal_westy_message($db,$admissionContext,['action'=>'run_resume','operation'=>$operation,
            'conversation'=>$task['conversation_id'],'sequence'=>1],$provider,aiResolver:static fn()=>$selection);
        check(false,'admission fixture did not reach the runner');
    }catch(\WestyAdmissionFixture\ReachedRunner){}
    $seen=$GLOBALS['admissionContext'];
    check($seen['tool_run']['pending_json']===json_encode($pending)&&$seen['desktop']['operation_key']===$operation,
        'actual admitted context retains the fetched pending receipt and exact binding after the SQL clear');
    check($calls===2&&$GLOBALS['admissionResult']['ok']&&array_column($GLOBALS['wire'],'action')===['control_result','state','state','windows','observation','state'],
        'actual message admission reaches one recovery read and one bounded continuation');
    $saved=json_decode($db->query('SELECT reply_json FROM portal_westy_turns')->fetchColumn(),true);
    check($saved['tools'][0]['awaiting_run']===false&&$saved['tools'][1]['name']==='desktop_windows'
        &&!str_contains($saved['reply'],'ADMISSION_PROVISIONAL')&&!str_contains(json_encode($saved),'PRIVATE_WINDOW'),
        'actual admission updates the awaiting receipt and saves only public discovery and final text');
    unset($GLOBALS['admissionScope'],$GLOBALS['admissionConfig'],$_SESSION['desktop_companion_session']);
}finally{ini_set('error_log',$oldLog);unlink($log);}
echo "PASS $checks desktop continuation assertions (synthetic boundaries only)\n";
