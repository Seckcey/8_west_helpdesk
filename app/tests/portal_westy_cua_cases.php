<?php
/** Extends the existing provider-loop fixture; all service/driver replies are synthetic. */
$cuaState=$state+['desktop_engine'=>'westy-cua-v1'];$cuaAction=str_repeat('9',32);
$cuaResponse=['isError'=>false,'structuredContent'=>['snapshot_token'=>'PRIVATE_FRESH_TOKEN','actual_text'=>'PRIVATE café 漢字'],
    'content'=>[['type'=>'text','text'=>'PRIVATE actual driver reply'],
        ['type'=>'image','mimeType'=>'image/png','data'=>base64_encode('PRIVATE_PNG')],
        ['type'=>'image','mimeType'=>'image/jpeg','data'=>base64_encode('PRIVATE_JPEG')]]];
$setupCua=static function()use($cuaState,$cuaAction,$cuaResponse):void{
    $GLOBALS['service']=static function($context,$action,$input)use($cuaState,$cuaAction,$cuaResponse):array{
        if($action==='state')return $cuaState;
        if($action==='cua'){
            check($input['session_id']===$cuaState['session_id']&&$input['task_id']===$cuaState['task_id'],'Cua dispatch uses server-bound actor/task identity');
            return ['action_id'=>$cuaAction,'state'=>'queued'];
        }
        if($action==='cua_result')return ['state'=>'executed','result'=>['contract'=>'westy-cua-v1','state'=>'returned','driver_error'=>false,'reason'=>null],'response'=>$cuaResponse];
        if($action==='stop')return ['state'=>'stopped'];
        throw new RuntimeException('Unexpected Cua service operation '.$action);
    };
};
$calls=0;$seenInput=null;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,&$seenInput,$reply):array{
    $calls++;
    check(array_values(array_intersect(array_column($options['tools'],'name'),['desktop_cua','desktop_windows','desktop_action','desktop_stop']))===['desktop_cua','desktop_stop'],
        'new engine offers its own relay and Stop without legacy input tools');
    check(str_contains($system,'Cua Driver')&&!str_contains($system,'Desktop action x/y must be relative'), 'Cua-specific usage replaces legacy coordinate instructions');
    check(str_contains($system,portal_computer_time_instructions()),'computer-local time guidance survives desktop integration');
    if($calls===1)return $reply('',[['id'=>'synthetic-cua','name'=>'desktop_cua','arguments'=>['method'=>'tools/call',
        'params_json'=>'{"name":"get_window_state","arguments":{"pid":123}}']]]);
    $seenInput=$input;$body=westy_tenant_ai_body($selected,$system,$input,$options);
    $wire=json_encode($body,JSON_THROW_ON_ERROR);
    check(str_contains($wire,'PRIVATE_FRESH_TOKEN')&&str_contains($wire,'PRIVATE actual driver reply'),'actual driver text/structured evidence reaches the selected provider');
    $parts=$input[array_key_last($input)]['content'];$images=array_values(array_filter($parts,static fn($p)=>$p['type']==='image'));
    check(count($images)===2&&array_column($images,'media_type')===['image/png','image/jpeg'],'multiple driver images retain their actual MIME types');
    check(!str_contains($parts[0]['text'],base64_encode('PRIVATE_PNG')),'pixel base64 is supplied as vision input without duplicating it as model text');
    $emit('Synthetic observation received.');return $reply('Synthetic observation received.');
};
$out=$run($context,$partial,$provider,setup:$setupCua);
check($out['result']['ok']&&$calls===2,'actual provider loop consumes the driver reply and continues');
check(!str_contains(json_encode($out['saved'],JSON_THROW_ON_ERROR),'PRIVATE_'),'public tool history stores no raw Cua text, tokens or pixels');
$replay=portal_westy_run_replay($seenInput);
check(!str_contains($replay,'PRIVATE_')&&str_contains($replay,'returned'),'existing durable replay redacts Cua evidence while keeping the actual receipt');
check(array_column($out['wire'],'action')===['state','cua','cua_result','state'],'one intended call and one receipt poll reach the service without legacy recovery');
foreach(['known_refusal','lost_reply','stopped'] as $scenario){
    $GLOBALS['wire']=[];$setupCua();$before=$GLOBALS['service'];
    $GLOBALS['service']=static function($context,$action,$input)use($before,$scenario):array{
        if($action==='cua')throw $scenario==='known_refusal'?new PortalDesktopException('tool_restriction',403,true):new PortalDesktopException('connection_unknown');
        return $before($context,$action,$input);
    };
    $result=portal_westy_desktop_dispatch($context,'desktop_cua',['method'=>'tools/list','params_json'=>'{}'],static fn():bool=>$scenario!=='stopped');
    check($result['public_result']['state']===($scenario==='lost_reply'?'unknown':'refused')&&!$result['public_result']['retry_allowed'],'refusal, Stop and uncertain dispatch retain distinct truthful outcomes');
    check(count(array_filter($GLOBALS['wire'],static fn($item)=>$item['action']==='cua'))===($scenario==='stopped'?0:1),'no retry or post-Stop dispatch');
    check(in_array('stop',array_column($GLOBALS['wire'],'action'),true)===($scenario!=='known_refusal'),'uncertain or cancelled task invokes the existing scoped Stop');
}
echo "PASS $checks desktop/Cua continuation assertions (synthetic boundaries only)\n";
