<?php
/** Real private ledger and signed service client; synthetic provider/network only. */
declare(strict_types=1);
require __DIR__.'/portal_devices_mysql_test.php';
// Optional native schema is intentionally unavailable in this chat/stop fixture.
$pdo->exec('DROP TABLE portal_desktop_bindings');
$settings['portal_westy']=['enabled'=>true,'ai_enabled'=>true,'tools_enabled'=>true,'api_key'=>'synthetic-only','hourly_limit'=>30,'daily_limit'=>500,'monthly_microusd'=>5000000];
$settings['portal_devices']['enabled']=true;$settings['portal_devices']['diagnostics_enabled']=true;
$device='1:'.str_repeat('a',64);$seenActions=[];$events=[];$providerBodies=[];$scope=portal_devices_scope($pdo,$a);
$operation=['reference'=>str_repeat('b',32),'recipe'=>'temp_preview','title'=>'Preview Windows temporary files','impact'=>'Read-only preview.',
    'device_reference'=>$device,'state'=>'queued','created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),
    'can_approve'=>false,'approval_fingerprint'=>null,'result'=>null,'basis_reference'=>null,'preview'=>null,'can_cancel'=>true];
$transport=static function($url,$body,$headers)use(&$seenActions,&$events,$operation,$scope):array{
    $request=json_decode($body,true);check($url===PORTAL_DEVICES_ENDPOINT&&$request['scope']===$scope,'tools derive the current stable customer scope server-side');$seenActions[]=$request['action'];
    if($request['action']==='devices')check(implode('',array_map(static fn($event)=>$event[0]==='delta'?$event[1]['text']:'',$events))==='Looking for computers.',
        'successful round flushes all text before actual tool dispatch');
    $result=match($request['action']){'devices'=>['items'=>[],'next_after'=>null],'operations'=>['available'=>true,'eligibility'=>['can_check'=>false,'can_propose_repair'=>false,'reason'=>'in_progress'],'items'=>[$operation]],'temp_start'=>$operation,default=>throw new RuntimeException('Unexpected tool action')};
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>$result])];
};
$defs=portal_westy_tool_definitions();
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/customer_workspace/operations.json'),true,32,JSON_THROW_ON_ERROR);
foreach($fixtures['operations'] as $name=>$fixture)check(portal_device_operations_result('repair_approve',$fixture)===$fixture,'actual Milepost synthetic projection accepted: '.$name);
check(array_column($defs,'name')===['list_computers','read_computer_status','start_health_check','prepare_temp_cleanup','propose_print_repair'],'model receives only five reviewed tools and no approval or arbitrary-command capability');
$parser=new PortalWestySseParser();$parsed=[];$stream="event: response.output_text.delta\r\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hello\"}\r\n\r\n: ping\n\ndata: {\"type\":\"response.completed\"}\n\n";
foreach(str_split($stream,1) as $byte)$parser->feed($byte,static function($item)use(&$parsed){$parsed[]=$item;});
check(count($parsed)===2&&$parsed[0]['delta']==='Hello','SSE parser handles real frame and CRLF boundaries split across individual bytes');
$resolver=static function(int $tenant,string $action,?int $revision):array{
    return ['version'=>1,'app'=>'safeharbor','local_tenant_key'=>(string)$tenant,'tenant_id'=>$tenant*100,
        'tenant_slug'=>'provider-'.$tenant,'status'=>'active','revision'=>1,'credential_version'=>1,
        'api_key'=>'sk-synthetic-workspace-no-network']+westy_tenant_ai_selection('openai','gpt-6-luna','low');
};
$request=['operation'=>'f1'.sprintf('%08x',time()).bin2hex(random_bytes(11)),'message'=>'Preview temporary files on the selected computer.','conversation'=>null,'device_reference'=>$device];
$round=0;$provider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$round,&$providerBodies,$device):array{
    $providerBodies[]=westy_tenant_ai_body($selection,$system,$messages,$options);$round++;$alive();
    if($round===1){$emit('Looking');$emit(' for computers.');}
    $output=match($round){1=>[['type'=>'function_call','name'=>'list_computers','call_id'=>'call_list','arguments'=>'{}']],2=>[['type'=>'function_call','name'=>'prepare_temp_cleanup','call_id'=>'call_preview','arguments'=>json_encode(['device_reference'=>$device])]],default=>[]};
    if($round===3){$emit('The preview ');$emit('is queued.');$output[]=['type'=>'message','content'=>[['type'=>'output_text','text'=>'The preview is queued.']]];}
    return westy_tenant_ai_parse($selection,200,['model'=>$selection['model'],'status'=>'completed',
        'output'=>array_merge([['type'=>'reasoning','encrypted_content'=>'synthetic-hidden-reasoning']],$output),
        'usage'=>['input_tokens'=>100,'input_tokens_details'=>['cached_tokens'=>0,'cache_write_tokens'=>0],'output_tokens'=>40]]);
};
$emit=static function($event,$data)use(&$events,$pdo,$request):void{
    $events[]=[$event,$data];
    if($event==='delta'){$q=$pdo->prepare('SELECT state,reply_json FROM portal_westy_turns WHERE operation_key=?');$q->execute([$request['operation']]);$row=$q->fetch();check($row['state']==='pending'&&str_contains(json_decode($row['reply_json'],true)['reply'],$data['text']),'delta is saved and emitted while generation is still pending');}
};
portal_westy_message($pdo,$a,$request,$provider,static fn()=>$a,$emit,$transport,aiResolver:$resolver);
$q=$pdo->prepare('SELECT * FROM portal_westy_turns WHERE operation_key=?');$q->execute([$request['operation']]);$row=$q->fetch();$reply=json_decode($row['reply_json'],true);
check($round===3&&$seenActions===['devices','temp_start']&&$reply['reply']==="Looking for computers.\n\n\n\nThe preview is queued.",
    'real orchestration preserves text and tool order across rounds: '.json_encode([$round,$seenActions,$reply,$row['reason_code']]));
check(!str_contains($row['reply_json'],'synthetic-hidden-reasoning')&&!str_contains(json_encode($events),'synthetic-hidden-reasoning'),'encrypted reasoning never enters saved transcript or browser events');
check(str_contains(json_encode($providerBodies[0]['input']),$device),'selected device reaches bounded provider context');
portal_westy_message($pdo,$a,$request,$provider,static fn()=>$a,$emit,$transport,aiResolver:$resolver);check($round===3,'generation replay cannot repeat provider or device work');
$state=portal_westy_state($pdo,$a,null,$transport,aiResolver:$resolver);check($state['turns'][0]['reply']['tools'][1]['operation']['reference']===$operation['reference'],'reload restores the durable receipt and refreshes status without dispatch');
check(portal_westy_state($pdo,$b,null,$transport,aiResolver:$resolver)['turns']===[],'other customer receives no private conversation or receipts');
$entry=[];$save=static function():void{};
foreach([
    ['name'=>'run_powershell','call_id'=>'bad','arguments'=>'{}'],
    ['name'=>'prepare_temp_cleanup','call_id'=>'bad','arguments'=>json_encode(['device_reference'=>$device,'script'=>'Remove-Item anything'])],
    ['name'=>'repair_approve','call_id'=>'bad','arguments'=>'{}']
] as $call){try{portal_westy_tool_call($pdo,$a,$call,$request['operation'],$entry,$save,$transport);$denied=false;}catch(PortalWestyException){$denied=true;}check($denied,'unreviewed command, extra field or model approval rejected before transport');}
$request2=['operation'=>'f1'.sprintf('%08x',time()).bin2hex(random_bytes(11)),'message'=>'Explain this result.','conversation'=>$state['conversation']];
$stopProvider=static function($selection,$system,$messages,$options,$emit,$alive)use($pdo,$a,$request2):array{$emit('Saved partial.');portal_westy_stop($pdo,$a,['operation'=>$request2['operation']]);$alive();throw new RuntimeException('Stop ignored');};
portal_westy_message($pdo,$a,$request2,$stopProvider,static fn()=>$a,null,$transport,aiResolver:$resolver);
$q->execute([$request2['operation']]);$stopped=$q->fetch();check($stopped['state']==='unavailable'&&$stopped['reason_code']==='stopped'&&str_contains($stopped['reply_json'],'Saved partial.'),'stop persists the visible partial and prevents later provider work');
portal_westy_stop($pdo,$a,['operation'=>$request2['operation']]);
check(true,'ordinary chat stop succeeds without native configuration, schema or companion');
if(is_file(__DIR__.'/../lib/portal_desktop_sessions.php')){
    // A durable native attempt changes the stop obligation even if its optional
    // binding schema is unavailable. This synthetic attempt dispatches nothing.
    $pdo->beginTransaction();
    portal_westy_ai_attempt($pdo,(int)$stopped['id'],portal_westy_scope($pdo,$a),$resolver(1,'status',null),1,
        str_repeat('d',32),str_repeat('e',64));
    $pdo->commit();
    try{portal_westy_stop($pdo,$a,['operation'=>$request2['operation']]);$nativeDenied=false;}
    catch(PortalWestyException $error){$nativeDenied=$error->reason==='desktop_unavailable';}
    check($nativeDenied,'missing native schema never claims a durable native task was stopped');
}
$badTransport=static fn()=>['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>array_replace($operation,['device_reference'=>'2:'.str_repeat('c',64)])])];
check(refused(static fn()=>portal_devices_request($pdo,$a,'temp_start',['device_reference'=>$device,'request_key'=>bin2hex(random_bytes(16))],$badTransport)),'cross-device service result is rejected before display');
echo 'PASS streamed workspace: '.$checks." checks\n";
