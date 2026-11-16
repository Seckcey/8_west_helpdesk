<?php
/** Runs at the end of portal_westy_cua_cases.php in the same fixture. Every Milepost reply is
 * synthetic. Covers what Safeharbor sends only to a Milepost and Companion that offer it:
 * waiting for the result inside the request, and the read that rides with an action. */
$sid=$context['desktop']['session_id'];
$click=$observe+['element_token'=>'s0000002a:1'];
$afterResponse=['isError'=>false,'structuredContent'=>['elements'=>[['element_index'=>0,'element_token'=>'s0000002b:0','role'=>'button','label'=>'AFTER_PRIVATE Saved']],
    'screenshot_width'=>640,'screenshot_height'=>400],'content'=>[['type'=>'image','mimeType'=>'image/png','data'=>base64_encode('AFTER_PNG')]]];
$final=static fn(?array $observation=null,bool $driverError=false):array=>['state'=>$driverError?'refused':'executed',
    'result'=>['contract'=>'westy-cua-v1','state'=>'returned','driver_error'=>$driverError,'reason'=>null],'response'=>$cuaResponse]
    +($observation===null?[]:['observation'=>$observation]);
// Milepost's state reply decides what may be sent. false leaves the field out, as an older Milepost does.
$offer=static function(mixed $features)use($pdo,$context,$cuaState):array{
    $GLOBALS['service']=static fn($c,string $action,array $input):array=>$cuaState+($features===false?[]:['desktop_features'=>$features]);
    return portal_westy_desktop_definitions($pdo,$context);
};
$described=static fn(array $tools,string $name):string=>array_values(array_filter($tools,static fn(array $tool):bool=>$tool['name']===$name))[0]['description'];
// One service for a whole call: the queue reply, then the receipt replies in order.
$serve=static function(array $queued,array $receipts=[])use($cuaAction):void{
    $GLOBALS['wire']=[];
    $GLOBALS['service']=static function($c,string $action,array $input)use($queued,&$receipts,$cuaAction):array{
        if($action==='cua')return ['action_id'=>$cuaAction]+$queued;
        if($action==='cua_result')return array_shift($receipts)??throw new RuntimeException('Unexpected extra receipt read');
        if($action==='stop')return ['state'=>'stopped'];
        throw new RuntimeException('Unexpected Cua service operation '.$action);
    };
};
$sent=static fn(string $action):array=>array_values(array_filter($GLOBALS['wire'],static fn(array $w):bool=>$w['action']===$action));
$alive=static fn():bool=>true;

// 1. An older Milepost reports no features: nothing new is sent and nothing is described.
$tools=$offer(false);
check(portal_westy_cua_features($sid)===[]&&!str_contains($described($tools,'cua_click'),'screen_after'),'an older Milepost offers nothing new and the tools say nothing new');
$serve(['state'=>'queued'],[$final()]);
$plain=portal_westy_cua_tool($context,'cua_click',$click,$alive);
check($plain['public_result']['state']==='returned'&&array_keys($sent('cua')[0]['input'])===['session_id','task_id','request_key','method','params_json']
    &&array_keys($sent('cua_result')[0]['input'])===['session_id','task_id','action_id']&&!array_key_exists('observed_after',$plain['public_result']),
    'without the features the queue request and the receipt read are exactly as before');
foreach([['cua_wait','cua_observe','something_else'],['cua_observe','cua_wait']] as $reported){
    $offer($reported);check(portal_westy_cua_features($sid)===['cua_wait','cua_observe'],'only the features Safeharbor knows are kept, in its own order');
}
foreach(['cua_wait',null,7,['unknown']] as $reported){$offer($reported);check(portal_westy_cua_features($sid)===[],'a report that names no known feature offers none');}
check(portal_westy_cua_features(str_repeat('e',32))===[],'a pairing that was never reported has no features');

// 2. Waiting: one request queues the call and brings its receipt back.
$offer(['cua_wait']);
$serve(['state'=>'executed','receipt'=>$final()]);
$waited=portal_westy_cua_tool($context,'cua_click',$click,$alive);
check($waited['public_result']['state']==='returned'&&array_column($GLOBALS['wire'],'action')===['cua']
    &&$sent('cua')[0]['input']['wait_ms']===PORTAL_WESTY_CUA_HOLD_MS&&!array_key_exists('observe',$sent('cua')[0]['input'])
    &&($waited['private_observation']['structured']??null)!==null&&count($waited['images'])===1,
    'one request queues the call and returns its result, with no receipt polling');
// A wait that ran out: the receipt read waits too, and nothing sleeps between requests.
$serve(['state'=>'queued'],[['state'=>'claimed','result'=>null],$final()]);
$from=microtime(true);$later=portal_westy_cua_tool($context,'cua_click',$click,$alive);$took=microtime(true)-$from;
check($later['public_result']['state']==='returned'&&array_column($GLOBALS['wire'],'action')===['cua','cua_result','cua_result']
    &&$sent('cua_result')[0]['input']['wait_ms']===PORTAL_WESTY_CUA_HOLD_MS&&$sent('cua_result')[1]['input']['wait_ms']===PORTAL_WESTY_CUA_HOLD_MS&&$took<0.15,
    'a call still running is waited for on Milepost, not asked about every 200 ms');
check(is_int($later['public_result']['timing']['queue_ms']??null)&&is_int($later['public_result']['timing']['wait_ms']??null),'the receipt keeps its timing');
// A read that did not answer changed nothing, so the model may read again.
$serve(['state'=>'queued'],[['state'=>'refused','result'=>['reason'=>'cua_read_timeout']]]);
$timedOut=portal_westy_cua_tool($context,'cua_window_state',$observe,$alive);
check($timedOut['public_result']['state']==='refused'&&$timedOut['public_result']['reason']==='cua_read_timeout'&&$timedOut['public_result']['retry_allowed']===true
    &&str_contains($timedOut['public_result']['guidance'],'Read again')&&!in_array('stop',array_column($GLOBALS['wire'],'action'),true),
    'a screen read that did not answer may be asked again and does not stop the task');
$serve(['state'=>'queued'],[['state'=>'refused','result'=>['reason'=>'tool_restriction']]]);
check(portal_westy_cua_tool($context,'cua_click',$click,$alive)['public_result']['retry_allowed']===false,'any other refusal still may not be repeated');

// 3. The read that rides with an action.
$tools=$offer(['cua_wait','cua_observe']);
check(str_contains($described($tools,'cua_click'),'screen_after')&&str_contains($described($tools,'cua_browser_navigate'),'screen_after')
    &&!str_contains($described($tools,'cua_window_state'),'screen_after')&&!str_contains($described($tools,'cua_launch_app'),'screen_after'),
    'only the actions that return the new screen say so');
$serve(['state'=>'executed','receipt'=>$final($afterResponse)]);
$both=portal_westy_cua_tool($context,'cua_click',$click,$alive);
$read=$sent('cua')[0]['input']['observe']??null;
check(is_array($read)&&$read['settle_ms']===350&&json_decode($read['params_json'],true)===['name'=>'get_window_state',
    'arguments'=>['pid'=>4242,'window_id'=>991,'include_screenshot'=>true,'max_elements'=>150,'max_image_dimension'=>1280]],
    'a window action carries a read of the same window, bounded like the model\'s own read');
check($both['public_result']['state']==='returned'&&$both['public_result']['observed_after']===true&&str_contains($both['public_result']['guidance'],'screen_after')
    &&str_contains(json_encode($both['private_observation']['screen_after']),'AFTER_PRIVATE Saved')&&count($both['images'])===2
    &&$both['images'][1]['data']===base64_encode('AFTER_PNG')&&$both['private_observation']['screen_after']['images_attached']===1,
    'the action result and the new screen reach the model together, the new screenshot last');
check(!str_contains(json_encode($both['public_result']),'PRIVATE')&&!str_contains(json_encode($both['public_result']),'AFTER_PNG'),'the public receipt carries no screen content');
foreach([['cua_type_text',$observe+['text'=>'hello'],350,'get_window_state'],['cua_press_keys',$observe+['keys'=>['enter']],350,'get_window_state'],
    ['cua_scroll',$observe+['direction'=>'down'],350,'get_window_state'],['cua_set_value',$click+['value'=>'x'],350,'get_window_state'],
    ['cua_browser_click',['target_id'=>'T1','tab_id'=>'B1','ref'=>'r1'],400,'get_browser_state'],
    ['cua_browser_type',['target_id'=>'T1','tab_id'=>'B1','ref'=>'r1','text'=>'hello'],400,'get_browser_state'],
    ['cua_browser_navigate',['target_id'=>'T1','tab_id'=>'B1','url'=>'https://example.com/'],1500,'get_browser_state']] as [$tool,$arguments,$settle,$reads]){
    $serve(['state'=>'executed','receipt'=>$final($afterResponse)]);portal_westy_cua_tool($context,$tool,$arguments,$alive);
    $read=$sent('cua')[0]['input']['observe']??null;$decoded=is_array($read)?json_decode($read['params_json'],true):null;
    check(($decoded['name']??null)===$reads&&$read['settle_ms']===$settle
        &&($reads==='get_window_state'?$decoded['arguments']['pid']===4242&&$decoded['arguments']['window_id']===991
            :$decoded['arguments']===['target_id'=>'T1','tab_id'=>'B1','snapshot_format'=>'semantic_v2']),
        'the read names the same window or tab as the action: '.$tool);
}
foreach([['cua_window_state',$observe],['cua_list_windows',[]],['cua_launch_app',['name'=>'notepad']],['cua_browser_prepare',$observe],
    ['cua_browser_state',$observe]] as [$tool,$arguments]){
    $serve(['state'=>'executed','receipt'=>$final()]);$alone=portal_westy_cua_tool($context,$tool,$arguments,$alive);
    check(!array_key_exists('observe',$sent('cua')[0]['input'])&&!array_key_exists('observed_after',$alone['public_result']),'a read, a launch and browser setup carry no read: '.$tool);
}
// The read did not come back: the action's result stands and the model is told to look.
$serve(['state'=>'executed','receipt'=>$final()]);
$missing=portal_westy_cua_tool($context,'cua_click',$click,$alive);
check($missing['public_result']['state']==='returned'&&$missing['public_result']['observed_after']===false&&str_contains($missing['public_result']['guidance'],'observe again')
    &&!isset($missing['private_observation']['screen_after'])&&count($missing['images'])===1,'a missing read leaves the action result as it is and asks for a look');
$broken=$afterResponse;$broken['content'][0]['data']='not base64!';
$serve(['state'=>'executed','receipt'=>$final($broken)]);
$unusable=portal_westy_cua_tool($context,'cua_click',$click,$alive);
check($unusable['public_result']['state']==='returned'&&$unusable['public_result']['observed_after']===false&&count($unusable['images'])===1
    &&!in_array('stop',array_column($GLOBALS['wire'],'action'),true),'an unusable read is dropped without touching the action result or stopping the task');
$serve(['state'=>'refused','receipt'=>$final(null,true)]);
$failed=portal_westy_cua_tool($context,'cua_click',$click,$alive);
check($failed['public_result']['driver_error']===true&&!array_key_exists('observed_after',$failed['public_result']),'an action the driver did not complete promises no new screen');
// A pairing whose Companion cannot return the read is never sent one, even when Milepost waits.
$offer(['cua_wait']);$serve(['state'=>'executed','receipt'=>$final()]);portal_westy_cua_tool($context,'cua_click',$click,$alive);
check(!array_key_exists('observe',$sent('cua')[0]['input']),'no read is sent to a Companion that did not offer it');
check(portal_westy_cua_observe('cua_click',['arguments'=>[]])===null&&portal_westy_cua_observe('cua_browser_click',['arguments'=>['target_id'=>'T1']])===null
    &&portal_westy_cua_observe('cua_window_state',['arguments'=>$observe])===null,'an action that names no window or tab, or is itself a read, carries no read');
// Leave the fixture as an older Milepost would.
$offer(false);
