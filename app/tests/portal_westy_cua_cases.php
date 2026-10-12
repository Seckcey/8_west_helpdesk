<?php
/** Extends the existing provider-loop fixture; all service/driver replies are synthetic. */
$cuaState=$state+['desktop_engine'=>'westy-cua-v1'];$cuaAction=str_repeat('9',32);
$cuaTools=[...PORTAL_WESTY_CUA_TOOLS,'desktop_stop'];
$elements=[['element_index'=>0,'element_token'=>'s0000002a:0','role'=>'window','label'=>'PRIVATE_TITLE Notepad'],
    ['element_index'=>1,'element_token'=>'s0000002a:1','role'=>'document','label'=>'Text editor','value'=>'PRIVATE café 漢字',
        'actions'=>['set_value'],'screenshot_frame'=>['x'=>10,'y'=>40,'w'=>600,'h'=>300]]];
$cuaResponse=['isError'=>false,'structuredContent'=>['elements'=>$elements,'tree_markdown'=>'PRIVATE_MARKDOWN_TREE','screenshot_width'=>640,'screenshot_height'=>400],
    'content'=>[['type'=>'text','text'=>'PRIVATE_MARKDOWN_TEXT'],['type'=>'image','mimeType'=>'image/png','data'=>base64_encode('PRIVATE_PNG')]]];
$cuaDispatches=[];
$setupCua=static function(?array $response=null,bool $driverError=false)use($cuaState,$cuaAction,$cuaResponse,&$cuaDispatches):void{
    $cuaDispatches=[];
    $GLOBALS['service']=static function($context,$action,$input)use($cuaState,$cuaAction,$cuaResponse,$response,$driverError,&$cuaDispatches):array{
        if($action==='state')return $cuaState;
        if($action==='cua'){
            check($input['session_id']===$cuaState['session_id']&&$input['task_id']===$cuaState['task_id'],'Cua dispatch uses server-bound actor/task identity');
            check($input['method']==='tools/call'&&portal_desktop_id($input['request_key']??null),'every curated tool is one exact tools/call intent');
            $cuaDispatches[]=json_decode($input['params_json'],true,32,JSON_THROW_ON_ERROR);
            return ['action_id'=>$cuaAction,'state'=>'queued'];
        }
        if($action==='cua_result')return ['state'=>'executed','result'=>['contract'=>'westy-cua-v1','state'=>'returned','driver_error'=>$driverError,'reason'=>null],
            'response'=>$response??$cuaResponse];
        if($action==='stop')return ['state'=>'stopped'];
        throw new RuntimeException('Unexpected Cua service operation '.$action);
    };
};
$runAt=static function(array $ctx,array $initial,callable $provider,float $deadline,callable $setup)use($pdo,$selection,$messages,$operation):array{
    $GLOBALS['wire']=[];$GLOBALS['waits']=[];$GLOBALS['waitReplays']=[];$setup();
    $saved=[];$work=$initial;
    $output=static function(string $event,array $data)use(&$work):void{if($event==='delta')$work['reply'].=$data['text'];};
    $save=static function()use(&$saved,&$work):void{$saved[]=$work;};
    $result=portal_westy_ai_run($pdo,$ctx,$selection,$messages,$operation,$work,static function():void{},$output,$save,$provider,
        resolver:static fn()=>$selection,deadline:$deadline);
    return ['result'=>$result,'partial'=>$work,'saved'=>$saved,'wire'=>$GLOBALS['wire'],'waits'=>$GLOBALS['waits'],'waitReplays'=>$GLOBALS['waitReplays']];
};
$observe=['pid'=>4242,'window_id'=>991];

// The curated surface: typed tools, a short playbook, the exact upstream call and bounded evidence.
$calls=0;$seenInput=null;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,&$seenInput,$reply,$cuaTools,$observe):array{
    $calls++;
    check(array_values(array_intersect(array_column($options['tools'],'name'),[...$cuaTools,'desktop_cua','desktop_windows','desktop_action']))===$cuaTools,
        'new engine offers the curated tools and Stop, without the raw relay or legacy input tools');
    check(str_contains($system,'Cua Driver')&&str_contains($system,'cua_window_state')&&!str_contains($system,'Desktop action x/y must be relative'),
        'Cua playbook replaces legacy coordinate instructions');
    check(!str_contains($system,'resources/read')&&!str_contains($system,'WINDOWS.md'),'no driver manual is required reading');
    check(str_contains($system,portal_computer_time_instructions()),'computer-local time guidance survives desktop integration');
    check($options['max_output_tokens']===PORTAL_WESTY_AI_MAX_OUTPUT&&$options['max_input_tokens']===PORTAL_WESTY_AI_MAX_INPUT,'rounds use the shared reasoning-sized bounds');
    if($calls===1)return $reply('',[['id'=>'synthetic-cua','name'=>'cua_window_state','arguments'=>$observe]]);
    $seenInput=$input;$body=westy_tenant_ai_body($selected,$system,$input,$options);
    $wire=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    check(str_contains($wire,'s0000002a:1')&&str_contains($wire,'PRIVATE café 漢字')&&str_contains($wire,'@10,40 600x300'),
        'compact controls with tokens, values and screenshot frames reach the selected provider');
    check(!str_contains($wire,'PRIVATE_MARKDOWN'),'the duplicate Markdown tree never reaches the provider');
    $parts=$input[array_key_last($input)]['content'];$images=array_values(array_filter($parts,static fn($p)=>$p['type']==='image'));
    check(count($images)===1&&$images[0]['media_type']==='image/png','the screenshot is supplied as vision input');
    check(!str_contains($parts[0]['text'],base64_encode('PRIVATE_PNG')),'pixel base64 is not duplicated as model text');
    $emit('Synthetic observation received.');return $reply('Synthetic observation received.');
};
$out=$run($context,$partial,$provider,setup:$setupCua);
check($out['result']['ok']&&$calls===2,'actual provider loop consumes the driver reply and continues');
check($cuaDispatches===[['name'=>'get_window_state','arguments'=>['pid'=>4242,'window_id'=>991,'include_screenshot'=>true,'max_elements'=>150,'max_image_dimension'=>1280]]],
    'Westy builds the exact bounded upstream call and never supplies a session');
$tool=$out['partial']['tools'][1]??[];
check(($tool['name']??null)==='cua_window_state'&&$tool['state']==='returned'&&$tool['result']['pid']===4242&&$tool['result']['window_id']===991,
    'public receipt keeps the target identity for a later continuation');
check(!str_contains(json_encode($out['saved'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'PRIVATE'),'public tool history stores no screen text, tokens or pixels');
$replay=portal_westy_run_replay($seenInput);
check(!str_contains($replay,'PRIVATE')&&!str_contains($replay,'s0000002a')&&str_contains($replay,'returned'),'existing durable replay redacts Cua evidence while keeping the actual receipt');
check(array_column($out['wire'],'action')===['state','cua','cua_result','state'],'one intended call and one receipt poll reach the service without legacy recovery');

// Model mistakes are corrected in the same reply; nothing reaches the device.
$calls=0;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply):array{
    $calls++;
    $last=$calls===1?null:json_decode($input[array_key_last($input)]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    if($calls===1)return $reply('',[['id'=>'bad-click','name'=>'cua_click','arguments'=>['pid'=>4242,'window_id'=>991,'element_token'=>'s0000002a:1','x'=>5,'y'=>6]]]);
    if($calls===2){
        check($last['result']['state']==='rejected'&&$last['result']['executed']===false&&str_contains($last['result']['guidance'],'either element_token or both x and y'),
            'a malformed call returns correction guidance instead of ending the reply');
        return $reply('',[['id'=>'old-relay','name'=>'desktop_cua','arguments'=>['method'=>'tools/list','params_json'=>'{}']]]);
    }
    check($last['untrusted_result']['reason']==='tool_unavailable'&&in_array('cua_window_state',$last['untrusted_result']['offered_tools'],true),
        'a tool that is not offered is answered with the real offer');
    $emit('Corrected.');return $reply('Corrected.');
};
$out=$run($context,$partial,$provider,setup:$setupCua);
check($out['result']['ok']&&$calls===3&&$cuaDispatches===[]&&!in_array('cua',array_column($out['wire'],'action'),true),
    'neither the rejected nor the unoffered call reaches the device');
check(array_column($out['partial']['tools'],'name')===['desktop_open','cua_click']&&$out['partial']['tools'][1]['state']==='rejected',
    'only the real rejected intent gets a public receipt');

// Exact translation and validation of every curated field.
$rejects=static function(string $tool,mixed $arguments):bool{try{portal_westy_cua_request($tool,$arguments);return false;}catch(PortalWestyCuaRejected){return true;}};
$request=static fn(string $tool,array $arguments):array=>array_intersect_key(portal_westy_cua_request($tool,$arguments),array_flip(['name','arguments']));
check($request('cua_press_keys',$observe+['keys'=>['Ctrl','A']])===['name'=>'hotkey','arguments'=>$observe+['keys'=>['ctrl','a'],'delivery_mode'=>'background']],'a combination becomes one hotkey');
check($request('cua_press_keys',$observe+['keys'=>['Enter']])===['name'=>'press_key','arguments'=>$observe+['key'=>'enter','delivery_mode'=>'background']],'one key becomes press_key');
check($rejects('cua_press_keys',$observe+['keys'=>['pagedwn']])&&$rejects('cua_press_keys',$observe+['keys'=>['ctrl','shift','alt','win','t']]),
    'unknown key names and oversized chords are refused instead of pressing a first letter');
check($request('cua_launch_app',['aumid'=>'Microsoft.WindowsNotepad_8wekyb3d8bbwe!App'])===['name'=>'launch_app','arguments'=>['aumid'=>'Microsoft.WindowsNotepad_8wekyb3d8bbwe!App']],
    'packaged apps launch by exact AUMID');
check($request('cua_launch_app',['path'=>'C:\\Windows\\notepad.exe','arguments'=>['C:\\notes.txt']])===['name'=>'launch_app',
    'arguments'=>['path'=>'C:\\Windows\\notepad.exe','additional_arguments'=>['C:\\notes.txt']]],'an executable path keeps its literal arguments');
check($request('cua_launch_app',['urls'=>['https://example.com/a?b=1']])===['name'=>'launch_app','arguments'=>['urls'=>['https://example.com/a?b=1']]],'web addresses open in the default browser');
foreach([['urls'=>['']],['name'=>'notepad','path'=>'C:\\Windows\\notepad.exe'],[],
    ['path'=>''],['urls'=>['https://example.com'],'arguments'=>['x']],['aumid'=>'not-an-aumid']] as $index=>$launch)
    check($rejects('cua_launch_app',$launch),'empty or ambiguous launch is refused: '.$index);
check($request('cua_type_text',$observe+['text'=>"line one\nline two\tend"])===['name'=>'type_text','arguments'=>$observe+['text'=>"line one\nline two\tend",'delivery_mode'=>'background']],
    'requested multi-line text is typed exactly');
foreach([['text'=>"bell\x07"],['text'=>str_repeat('a',PORTAL_WESTY_CUA_TEXT_BYTES+1)],['text'=>''],['text'=>'x','session'=>'model-chosen'],
    ['text'=>'x','element_token'=>'s0000002a:1','x'=>1,'y'=>2]] as $index=>$type)
    check($rejects('cua_type_text',$observe+$type),'invalid typing is refused before dispatch: '.$index);
check($request('cua_click',['pid'=>4242.0,'window_id'=>991,'x'=>10.5,'y'=>20])===['name'=>'click','arguments'=>['pid'=>4242,'window_id'=>991,'x'=>10.5,'y'=>20,'delivery_mode'=>'background']],
    'pixel clicks keep exact screenshot coordinates and default to background delivery');
check($request('cua_click',$observe+['element_token'=>'s0000002a:1','button'=>'right','delivery_mode'=>'foreground'])['arguments']===
    $observe+['element_token'=>'s0000002a:1','button'=>'right','delivery_mode'=>'foreground'],'an explicit foreground retry is forwarded');
foreach([$observe+['x'=>1],$observe+['element_token'=>'s1:2'],$observe+['element_token'=>'s0000002a:1','delivery_mode'=>'auto'],['pid'=>4242,'x'=>1,'y'=>2],
    $observe+['element_token'=>'s0000002a:1','count'=>4],[4242,991]] as $index=>$click)
    check($rejects('cua_click',$click),'invalid click is refused before dispatch: '.$index);
check($request('cua_window_state',$observe+['query'=>'Address and search bar'])['arguments']===$observe+['query'=>'Address and search bar',
    'include_screenshot'=>true,'max_elements'=>1000,'max_image_dimension'=>1280],'a query widens the walk before its projection');
check($request('cua_set_value',$observe+['element_token'=>'s0000002a:1','value'=>''])['arguments']===$observe+['element_token'=>'s0000002a:1','value'=>''],
    'a field can be replaced, including cleared');
check($request('cua_scroll',$observe+['direction'=>'down','amount'=>3])===['name'=>'scroll','arguments'=>$observe+['direction'=>'down','amount'=>3,'delivery_mode'=>'background']],
    'scroll keeps its bounded amount');
check($request('cua_browser_state',$observe)===['name'=>'get_browser_state','arguments'=>$observe],'a browser window binds by exact pid and window');
check($request('cua_browser_state',['target_id'=>'t-1','tab_id'=>'tab-1','query'=>'Sign in'])['arguments']===
    ['target_id'=>'t-1','tab_id'=>'tab-1','snapshot_format'=>'semantic_v2','query'=>'Sign in'],'a bound tab snapshot uses the semantic page outline');
check($rejects('cua_browser_state',$observe+['tab_id'=>'tab-1'])&&$rejects('cua_browser_state',$observe+['query'=>'x'])&&$rejects('cua_browser_state',[]),
    'binding and snapshot forms are never mixed');
check($request('cua_browser_prepare',$observe)['arguments']===$observe+['strategy'=>['kind'=>'existing_profile']],'preparation uses only the Companion\'s existing-profile grant');
$navigationTab=['target_id'=>'t-1','tab_id'=>'tab-1'];
foreach(['about:blank','about:settings','file:///C:/Windows','file:///C:/My Documents/café.txt',
    'file://server/share/report.txt','https://EXAMPLE.com/A%2Fb?q=café#Part','https://example.com/a b',
    'edge://settings','ms-settings:display','mailto:help@example.com','custom+app:Exact/%2f?Key=Value',
    str_repeat('a',2048)] as $index=>$url)
    check($request('cua_browser_navigate',$navigationTab+['url'=>$url])===
        ['name'=>'browser_navigate','arguments'=>$navigationTab+['url'=>$url]],
        'navigation forwards the exact requested URI for upstream scheme handling: '.$index);
foreach([[],['url'=>null],['url'=>''],['url'=>123],['url'=>[]],['url'=>str_repeat('a',2049)],
    ['url'=>str_repeat('é',1025)],['url'=>"file:///C:/bad\x00name"],['url'=>"https://example.com/\nnext"],
    ['url'=>"about:\tblank"],['url'=>"custom:\x7f"],['url'=>"custom:\xC3\x28"]] as $index=>$navigation)
    check($rejects('cua_browser_navigate',$navigationTab+$navigation),'invalid navigation argument is refused before dispatch: '.$index);
check($request('cua_browser_type',['target_id'=>'t-1','tab_id'=>'tab-1','ref'=>'p4:2','text'=>'hello','replace'=>true])['arguments']===
    ['target_id'=>'t-1','tab_id'=>'tab-1','ref'=>'p4:2','text'=>'hello','replace'=>true],'page typing keeps its exact ref and replace choice');
check(portal_westy_cua_request('cua_type_text',$observe+['text'=>'café'])['public']===$observe+['characters'=>4],'typed text is counted, never stored, in the public receipt');

// Bounded evidence and closed public facts.
$many=['isError'=>false,'structuredContent'=>['elements'=>array_map(static fn(int $i):array=>['element_index'=>$i,'element_token'=>sprintf('s%08x:%d',42,$i),
    'role'=>'listitem','label'=>str_repeat('x',100).$i],range(0,499))],'content'=>[['type'=>'text','text'=>str_repeat('PRIVATE_TREE ',1000)]]];
$evidence=portal_westy_cua_evidence($many);$wire=json_encode($evidence,JSON_UNESCAPED_UNICODE);
check(strlen($wire)<=PORTAL_WESTY_CUA_EVIDENCE_BYTES&&!str_contains($wire,'PRIVATE_TREE'),'a huge tree is bounded and its Markdown duplicate dropped');
check(str_contains(end($evidence['structured']['elements']),'more controls omitted'),'omitted controls are announced, never silently dropped');
check(portal_westy_cua_evidence(['content'=>[['type'=>'text','text'=>'Launched pid 77']]])['text']===['Launched pid 77'],'a text-only driver reply is kept');
$refusal=['isError'=>true,'structuredContent'=>['code'=>'background_unavailable','escalation'=>['recommended'=>'foreground','reason'=>'occluded'],
    'suggestion'=>'PRIVATE suggestion'],'content'=>[['type'=>'text','text'=>'Retry with foreground.']]];
check(portal_westy_cua_facts($refusal)===['driver_code'=>'background_unavailable','driver_escalation'=>'foreground'],'closed driver codes become public facts');
check(portal_westy_cua_facts(['structuredContent'=>['code'=>'PRIVATE text with spaces','effect'=>'Confirmed!']])===[],'free text never becomes a public fact');
$calls=0;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$observe):array{
    $calls++;
    if($calls===1)return $reply('',[['id'=>'occluded','name'=>'cua_click','arguments'=>$observe+['element_token'=>'s0000002a:1']]]);
    $last=json_decode($input[array_key_last($input)]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    check($last['result']['driver_error']===true&&$last['result']['driver_escalation']==='foreground'&&str_contains($last['result']['guidance'],'did not complete')
        &&$last['untrusted_observation']['is_error']===true,'a driver refusal is evidence for the next step, not a reply failure');
    $emit('Retrying in the foreground is needed.');return $reply('Retrying in the foreground is needed.');
};
$out=$run($context,$partial,$provider,setup:static fn()=>$setupCua($refusal,true));
check($out['result']['ok']&&$calls===2&&!str_contains(json_encode($out['saved'],JSON_THROW_ON_ERROR),'PRIVATE'),'a driver error keeps the reply going and its text private');

// Only the newest screenshot travels; older ones are replaced by a note.
$calls=0;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$observe):array{
    $calls++;
    if($calls<=2)return $reply('',[['id'=>'observe-'.$calls,'name'=>'cua_window_state','arguments'=>$observe]]);
    $tools=array_values(array_filter($input,static fn($m)=>($m['role']??null)==='tool'));
    check(array_map(static fn($m)=>count(array_filter($m['content'],static fn($p)=>$p['type']==='image')),$tools)===[0,0,1],'only the newest screenshot travels to the provider');
    check(str_contains(json_encode($tools[1],JSON_THROW_ON_ERROR),'earlier screenshot was omitted'),'a superseded screenshot is replaced by a plain note');
    $emit('Two observations.');return $reply('Two observations.');
};
$out=$run($context,$partial,$provider,setup:$setupCua);
check($out['result']['ok']&&$calls===3&&count($cuaDispatches)===2,'both observations ran once each');

// The attempt yields before its deadline instead of being interrupted.
$late=static fn($selected,$system,$input,$options,$emit,$alive)=>$reply('',[['id'=>'late-observe','name'=>'cua_window_state','arguments'=>$observe]]);
$out=$runAt($context,$partial,$late,microtime(true)+80,$setupCua);
check(($out['result']['waiting']??false)===true&&$out['waits']===[['kind'=>'continuation']]&&count($cuaDispatches)===1,
    'a tool result late in the attempt yields a durable continuation before the deadline');
check($out['partial']['tools'][1]['state']==='returned'&&!str_contains($out['waitReplays'][0],'PRIVATE'),'the finished call keeps its receipt and the yield keeps no screen content');
$out=$runAt($context,$partial,$late,microtime(true)+30,$setupCua);
check($cuaDispatches===[]&&!in_array('cua',array_column($out['wire'],'action'),true)&&count($out['partial']['tools'])===1,
    'a call that cannot finish before the deadline is never sent and gets no public receipt');
check($out['waits']===[['kind'=>'continuation']]&&str_contains($out['waitReplays'][0],'not_sent')&&str_contains($out['waitReplays'][0],'step_time_limit'),
    'the continuation tells the model the call was not sent');
$calls=0;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$observe):array{
    $calls++;if($calls===1)return $reply('',[['id'=>'early-observe','name'=>'cua_window_state','arguments'=>$observe]]);
    $emit('Done early.');return $reply('Done early.');
};
$out=$runAt($context,$partial,$provider,microtime(true)+150,$setupCua);
check($out['result']['ok']&&$calls===2&&$out['waits']===[],'an early tool result continues in the same attempt');
$calls=0;
$generic=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply):array{
    $text='Round '.(++$calls).'.';$emit($text);
    return $reply($text,[['id'=>'generic-'.$calls,'name'=>'desktop_windows','arguments'=>[]]]);
};
$genericYield=$run($context,$partial,$generic);
check($calls===5&&($genericYield['result']['waiting']??false)===true&&count($genericYield['waitReplays'])===1
    &&str_ends_with($genericYield['partial']['reply'],'Round 5.'),'the generic fifth-round yield also writes no trailing separator');
check($genericYield['partial']['tools'][5]['state']==='observed'&&count($genericYield['result']['rounds'])===5,
    'the generic yield retains every actual read receipt and paid round');
$out=$runAt($context,$partial,$late,microtime(true)+PORTAL_WESTY_CUA_DISPATCH_SECONDS-1,$setupCua);
check($cuaDispatches===[]&&$out['waits']===[['kind'=>'continuation']],'one second short of the full request envelope, the call is not sent');
$out=$runAt($context,$partial,$late,microtime(true)+PORTAL_WESTY_CUA_DISPATCH_SECONDS+1,$setupCua);
check(count($cuaDispatches)===1&&$out['partial']['tools'][1]['state']==='returned','with the full envelope left, the call is sent');
// A slow receipt never runs past the attempt deadline: polling stops while a last poll
// and Stop still fit, and the outcome stays honestly unknown. Slow authority checks are
// measured too. This case waits for the real window, about 28 seconds.
$GLOBALS['wire']=[];$setupCua();$before=$GLOBALS['service'];$pollStarts=[];
$GLOBALS['service']=static function($context,$action,$input)use($before,&$pollStarts):array{
    if($action!=='cua_result')return $before($context,$action,$input);
    $pollStarts[]=microtime(true);return ['state'=>'claimed'];
};
$deadline=microtime(true)+PORTAL_WESTY_CUA_DISPATCH_SECONDS+1;
$slow=portal_westy_cua_tool($context,'cua_click',$observe+['element_token'=>'s0000002a:1'],static function():bool{usleep(400000);return true;},null,$deadline);
check($slow['public_result']['state']==='unknown'&&$slow['public_result']['reason']==='result_unknown'&&$slow['public_result']['retry_allowed']===false,
    'a receipt that cannot arrive in time is an unknown outcome, never a success');
check($deadline-microtime(true)>=PORTAL_WESTY_CUA_POLL_RESERVE-1,'polling ends while a last poll and Stop still fit before the deadline');
check($pollStarts!==[]&&max($pollStarts)+PORTAL_WESTY_CUA_POLL_RESERVE<$deadline,'no poll starts inside the reserve, even after a slow authority check');
check(count(array_filter($GLOBALS['wire'],static fn($w)=>$w['action']==='cua'))===1&&count(array_filter($GLOBALS['wire'],static fn($w)=>$w['action']==='cua_result'))>1
    &&in_array('stop',array_column($GLOBALS['wire'],'action'),true),'the call is sent once, polled, and followed by the existing scoped Stop');
// The time left is checked again at the moment of sending.
$GLOBALS['wire']=[];$setupCua();
$unsent=portal_westy_cua_tool($context,'cua_click',$observe+['element_token'=>'s0000002a:1'],static fn():bool=>true,null,microtime(true)+30);
check($unsent===['not_sent'=>true]&&$cuaDispatches===[]&&!in_array('cua',array_column($GLOBALS['wire'],'action'),true),
    'with too little time left at the moment of sending, nothing is sent');
$savedStates=[];$GLOBALS['wire']=[];$GLOBALS['waits']=[];$GLOBALS['waitReplays']=[];$setupCua();$work=$partial;
$result=portal_westy_ai_run($pdo,$context,$selection,$messages,$operation,$work,static function():void{},
    static function(string $event,array $data)use(&$work):void{if($event==='delta')$work['reply'].=$data['text'];},
    static function()use(&$work,&$savedStates):void{usleep(600000);$savedStates[]=$work['tools'][array_key_last($work['tools'])]['state']??null;},
    $late,resolver:static fn()=>$selection,deadline:microtime(true)+PORTAL_WESTY_CUA_DISPATCH_SECONDS+0.3);
check(($result['waiting']??false)===true&&in_array('dispatching',$savedStates,true)&&$cuaDispatches===[]&&count($work['tools'])===1
    &&str_contains($GLOBALS['waitReplays'][0]??'','not_sent'),'a slow row save uses up the margin, so the call is not sent and the model is told so');
// A call that ends near the deadline yields at once: the final accounting saves its row,
// and the resumed attempt adds its own paragraph break.
$provider=static function($selected,$system,$input,$options,$emit,$alive)use($reply,$observe):array{
    $emit('Looking.');return $reply('Looking.',[['id'=>'yield-observe','name'=>'cua_window_state','arguments'=>$observe]]);
};
$out=$runAt($context,$partial,$provider,microtime(true)+80,$setupCua);
check(($out['result']['waiting']??false)===true&&$out['partial']['tools'][1]['state']==='returned'&&end($out['saved'])['tools'][1]['state']==='dispatching'
    &&str_ends_with($out['partial']['reply'],'Looking.'),'before a yield the finished row is left to the final accounting and no separator is written');
// The final accounting really replaces the saved dispatching row. Stop or lost
// authority still wins delivery, and an unknown device result stays unknown.
$finishSource=file_get_contents(__DIR__.'/../lib/portal_westy_tenant_ai.php');
eval('namespace WestyAdmissionFixture; use \\PDO; use \\Throwable; use \\LogicException; '
    .substr($finishSource,strpos($finishSource,'function portal_westy_ai_finish(')));
$yielded=['returned'=>$out,'not_sent'=>$runAt($context,$partial,$late,microtime(true)+30,$setupCua)];
$yielded['unknown']=$runAt($context,$partial,$late,microtime(true)+80,static function()use($setupCua):void{
    $setupCua();$before=$GLOBALS['service'];
    $GLOBALS['service']=static function($context,$action,$input)use($before):array{
        if($action==='cua_result')throw new PortalDesktopException('connection_unknown');
        return $before($context,$action,$input);
    };
});
foreach($yielded as $effect=>$sample)foreach(['deliver','stopped','access_changed'] as $delivery){
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE portal_westy_accounts(scope_key TEXT,tenant_id INT,client_id INT,conversation_key TEXT);
        CREATE TABLE portal_westy_turns(id INT,scope_key TEXT,tenant_id INT,client_id INT,conversation_key TEXT,state TEXT,
            input_text TEXT,expires_at TEXT,reply_json TEXT,charged_microusd INT,input_tokens INT,output_tokens INT,finished_at TEXT,reason_code TEXT);
        CREATE TABLE portal_westy_ai_attempts(id INT,turn_id INT,scope_key TEXT,state TEXT,reserve_microusd INT,sequence INT,
            charged_microusd INT,usage_json TEXT,finished_at TEXT);
        CREATE TABLE portal_westy_budgets(tenant_id INT,client_id INT,month_key TEXT,charged_microusd INT);');
    $scope=['key'=>'scope','tenant'=>1,'client'=>11];$old=json_encode(['reply'=>'Saved before dispatch.','tools'=>[['state'=>'dispatching']]]);
    $db->exec("INSERT INTO portal_westy_accounts VALUES('scope',1,11,'conversation');
        INSERT INTO portal_westy_ai_attempts VALUES(1,1,'scope','pending',10000,1,10000,NULL,NULL);");
    $db->prepare('INSERT INTO portal_westy_turns VALUES(1,?,1,11,?,?,?,?,?,10000,NULL,NULL,NULL,?)')
        ->execute(['scope','conversation',$delivery==='stopped'?'unavailable':'pending','Original authorized request.',
            gmdate('Y-m-d H:i:s',time()+3600),$old,$delivery==='stopped'?'stopped':'']);
    $db->prepare('INSERT INTO portal_westy_budgets VALUES(1,11,?,10000)')->execute([gmdate('Y-m')]);
    WestyAdmissionFixture\portal_westy_ai_finish($db,$scope,1,1,'conversation',gmdate('Y-m'),
        $sample['result']+['data'=>$sample['partial']],$delivery!=='access_changed');
    $stored=$db->query('SELECT state,reply_json,reason_code FROM portal_westy_turns')->fetch();
    if($delivery==='deliver'){
        $receipt=json_decode($stored['reply_json'],true);
        check($stored['state']==='complete'&&$receipt===$sample['partial'],'final accounting durably saves the yielded '.$effect.' receipt');
        check($effect==='not_sent'?count($receipt['tools'])===1:$receipt['tools'][1]['state']===$effect,
            'delivery neither leaves an unsent row nor promotes an uncertain dispatch to completion');
    }else check($stored['state']==='unavailable'&&$stored['reason_code']===$delivery&&$stored['reply_json']===$old,
        'final accounting does not overwrite '.$delivery.' with a late '.$effect.' receipt');
    check($db->query('SELECT state FROM portal_westy_ai_attempts')->fetchColumn()===($delivery==='deliver'?'complete':'unavailable'),
        'the paid attempt reaches its truthful final state even when delivery is suppressed');
}
// Typed text reaches the driver but never stays in a saved continuation.
$typing=static fn($selected,$system,$input,$options,$emit,$alive)=>$reply('',[['id'=>'typed','name'=>'cua_type_text','arguments'=>$observe+['text'=>'PRIVATE_TYPED_MARKER_44']]]);
$out=$runAt($context,$partial,$typing,microtime(true)+80,$setupCua);$replayed=$out['waitReplays'][0]??'';
check(str_contains(json_encode($cuaDispatches,JSON_THROW_ON_ERROR),'PRIVATE_TYPED_MARKER_44')&&!str_contains($replayed,'PRIVATE')
    &&str_contains($replayed,'"name":"cua_type_text"')&&str_contains($replayed,'"call_id":"typed"'),'the saved continuation keeps the call identity without its arguments');
$restored=portal_westy_run_restore(portal_westy_run_replay([['role'=>'provider','output'=>[
    (object)['type'=>'function_call','call_id'=>'o1','name'=>'cua_type_text','arguments'=>'{"text":"PRIVATE_OBJECT_TEXT"}'],
    (object)['type'=>'tool_use','id'=>'o2','name'=>'cua_type_text','input'=>(object)['text'=>'PRIVATE_ANTHROPIC_TEXT']],
    ['type'=>'function_call','call_id'=>'o3','name'=>'list_computers','arguments'=>'{"query":"KEEP_OTHER"}']]]]));
check(!str_contains(json_encode($restored,JSON_THROW_ON_ERROR),'PRIVATE')&&str_contains(json_encode($restored,JSON_THROW_ON_ERROR),'KEEP_OTHER')
    &&$restored[0]['output'][0]->arguments==='{}'&&$restored[0]['output'][1]->input instanceof stdClass,
    'restored and Anthropic-shaped computer-tool calls are redacted the same way; other tools are unchanged');
foreach(['openai','anthropic'] as $vendor)foreach(['cua_type_text','desktop_cua'] as $toolName){
    $selected=['revision'=>4,'credential_version'=>1]+westy_tenant_ai_selection($vendor,
        $vendor==='openai'?'gpt-6.1-sol':'claude-sonnet-5-5','medium');
    $call=$vendor==='openai'?['type'=>'function_call','call_id'=>'private-pair','name'=>$toolName,'arguments'=>'{"text":"private_arguments"}']
        :['type'=>'tool_use','id'=>'private-pair','name'=>$toolName,'input'=>(object)['text'=>'private_arguments']];
    $receipt=['state'=>'unknown','retry_allowed'=>false,'reason'=>'connection_unknown'];
    $original=[['role'=>'user','content'=>[['type'=>'text','text'=>'The original authorized request.']]],
        ['role'=>'provider','output'=>[$call]]+$selected,
        ['role'=>'tool','call_id'=>'private-pair','content'=>[['type'=>'text','text'=>json_encode(['result'=>$receipt,
            'untrusted_observation'=>['value'=>'private_screen_text']])]]]];
    $wire=portal_westy_run_replay($original);$restored=portal_westy_run_restore($wire);
    $body=westy_tenant_ai_body($selected,'Original task authority.',$restored,['tools'=>portal_westy_cua_definitions()]);
    $encoded=json_encode($body,JSON_THROW_ON_ERROR);
    check(!str_contains($encoded,'private_arguments')&&!str_contains($encoded,'private_screen_text')
        &&str_contains($encoded,'The original authorized request.'),'restored '.$vendor.' request keeps authority text without transient arguments/observations');
    check(portal_westy_run_replay($restored)===$wire&&str_contains(json_encode($original),'private_arguments'),
        'durable redaction is idempotent and does not mutate the current '.$vendor.' attempt');
    if($vendor==='openai'){
        check($body['input'][1]->arguments==='{}'&&$body['input'][1]->call_id===$body['input'][2]['call_id']
            &&$body['input'][2]['type']==='function_call_output','OpenAI retains the paired function call and receipt');
    }else{
        check($body['messages'][1]['content'][0]->input instanceof stdClass
            &&get_object_vars($body['messages'][1]['content'][0]->input)===[]
            &&$body['messages'][1]['content'][0]->id===$body['messages'][2]['content'][0]['tool_use_id'],
            'Anthropic retains an empty object and the paired tool_use/tool_result');
    }
    $savedReceipt=json_decode($restored[2]['content'][0]['text'],true);
    check($savedReceipt['result']===$receipt&&$savedReceipt['observation_status']==='expired','uncertain effect survives '.$vendor.' protocol restoration unchanged');
}
// An unreadable driver response after a returned receipt is still only an acknowledgement.
$GLOBALS['wire']=[];$setupCua(['isError'=>false,'content'=>[['type'=>'image','mimeType'=>'image/png','data'=>'not base64!']]]);
$unreadable=portal_westy_cua_tool($context,'cua_click',$observe+['element_token'=>'s0000002a:1'],static fn():bool=>true);
check($unreadable['public_result']['state']==='returned'&&$unreadable['public_result']['reason']==='response_unavailable'
    &&str_contains($unreadable['public_result']['guidance'],'Observe again before reporting')&&!in_array('stop',array_column($GLOBALS['wire'],'action'),true),
    'an unreadable response after a returned receipt has an unknown effect, without Stop or a repeat');
// Only a closed reason code is kept from the relay.
$GLOBALS['wire']=[];$setupCua();$before=$GLOBALS['service'];
$GLOBALS['service']=static function($context,$action,$input)use($before):array{
    return $action==='cua_result'?['state'=>'refused','result'=>['reason'=>'PRIVATE free text from the relay']]:$before($context,$action,$input);
};
$freeText=portal_westy_cua_tool($context,'cua_click',$observe+['element_token'=>'s0000002a:1'],static fn():bool=>true);
check($freeText['public_result']['state']==='refused'&&$freeText['public_result']['reason']==='unrecognized','a reason that is not a closed code is stored as unrecognized');

// A lowercase token can still be private text. Closed vocabularies, not a regex,
// decide which relay/driver facts may enter a public receipt or durable replay.
$GLOBALS['service']=static function($context,$action,$input)use($before):array{
    return $action==='cua_result'?['state'=>'refused','result'=>['reason'=>'private_screen_marker']]:$before($context,$action,$input);
};
$privateCode=portal_westy_cua_tool($context,'cua_click',$observe+['element_token'=>'s0000002a:1'],static fn():bool=>true);
check($privateCode['public_result']['reason']==='unrecognized','a token-shaped private reason is not a public code');
check(portal_westy_cua_facts(['structuredContent'=>['effect'=>'private_effect','code'=>'private_code','route'=>'private_route',
    'escalation'=>['recommended'=>'private_escalation']]])===[],'token-shaped private driver values stay transient');

// Execute the actual dispatcher with a deterministic clock and synthetic boundary
// durations. No production clock seam, network, device or provider is introduced.
$dispatchStart=strpos($desktopSource,'function portal_westy_cua_dispatch(');
$dispatchEnd=strpos($desktopSource,'function portal_westy_desktop_dispatch(',$dispatchStart);
eval('namespace WestyCuaDeadlineFixture; use \\PortalDesktopException; use \\Throwable;'
    .'function microtime(bool $float=true):float{return $GLOBALS["cuaClock"];}'
    .'function usleep(int $micros):void{$GLOBALS["cuaClock"]+=$micros/1000000;}'
    .'function portal_desktop_task(array $context):array{return $context["desktop"];}'
    .'function portal_desktop_id(mixed $value):bool{return \\portal_desktop_id($value);}'
    .'function portal_devices_keys(array $input,array $keys):bool{return \\portal_devices_keys($input,$keys);}'
    .'function portal_westy_cua_response(array $response):array{return \\portal_westy_cua_response($response);}'
    .'function portal_desktop_request(array $context,string $action,array $input,?callable $transport=null):array{'
        .'$GLOBALS["cuaClockCalls"][]=["action"=>$action,"at"=>$GLOBALS["cuaClock"]];'
        .'$GLOBALS["cuaClock"]+=$GLOBALS["cuaRequestSeconds"];'
        .'if($action==="cua_result"&&($GLOBALS["cuaClockReturned"]??false))return ["result"=>["contract"=>"westy-cua-v1","state"=>"returned"],"response"=>["content"=>[]]];'
        .'return $action==="cua"?["action_id"=>str_repeat("9",32)]:["state"=>$action==="stop"?"stopped":"claimed"];}'
    .substr($desktopSource,$dispatchStart,$dispatchEnd-$dispatchStart));
foreach([[false,85.0,8.0,20.0],[true,150.0,32.0,56.0],[true,200.0,32.0,56.0]] as [$membership,$until,$requestSeconds,$authoritySeconds]){
    $GLOBALS['cuaClock']=0.0;$GLOBALS['cuaClockCalls']=[];$GLOBALS['cuaRequestSeconds']=$requestSeconds;$authorityCalls=0;
    $timedContext=$context;if($membership)$timedContext['identity']['customer_access']=[];
    $timed=WestyCuaDeadlineFixture\portal_westy_cua_dispatch($timedContext,['method'=>'tools/call','params_json'=>'{}'],
        static function()use(&$authorityCalls,$authoritySeconds):bool{if($authorityCalls++>0)$GLOBALS['cuaClock']+=$authoritySeconds;return true;},null,$until);
    check($GLOBALS['cuaClock']<$until,'slow authority and scoped receipt/Stop requests finish before the attempt deadline: '.($membership?'membership':'legacy'));
    check($timed['public_result']['state']==='unknown'&&!$timed['public_result']['retry_allowed']
        &&array_column($GLOBALS['cuaClockCalls'],'action')[0]==='cua'
        &&end($GLOBALS['cuaClockCalls'])['action']==='stop','deadline expiry preserves one uncertain dispatch and the scoped Stop');
}
$GLOBALS['cuaClock']=0.0;$GLOBALS['cuaClockCalls']=[];$GLOBALS['cuaRequestSeconds']=0.1;$GLOBALS['cuaClockReturned']=true;
$fastMembership=$context;$fastMembership['identity']['customer_access']=[];
$fast=WestyCuaDeadlineFixture\portal_westy_cua_dispatch($fastMembership,['method'=>'tools/call','params_json'=>'{}'],static fn():bool=>true,null,150.0);
check($fast['public_result']['state']==='returned'&&array_column($GLOBALS['cuaClockCalls'],'action')===['cua','cua_result'],
    'an ordinary timely membership-bound call still dispatches and receives its result within the unchanged attempt deadline');
$GLOBALS['cuaClockReturned']=false;

// A returned receipt whose driver response was lost is an acknowledgement, not a verified outcome.
$withoutResponse=static function()use($setupCua):void{
    $setupCua();$before=$GLOBALS['service'];
    $GLOBALS['service']=static function($context,$action,$input)use($before):array{
        return $action==='cua_result'?['state'=>'executed','result'=>['contract'=>'westy-cua-v1','state'=>'returned','driver_error'=>false,'reason'=>null]]
            :$before($context,$action,$input);
    };
};
$calls=0;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$observe):array{
    $calls++;
    if($calls===1)return $reply('',[['id'=>'lost-response','name'=>'cua_click','arguments'=>$observe+['element_token'=>'s0000002a:1']]]);
    $last=json_decode($input[array_key_last($input)]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    check($last['result']['state']==='returned'&&$last['result']['reason']==='response_unavailable'&&str_contains($last['result']['guidance'],'its effect is unknown')
        &&str_contains($last['result']['guidance'],'Do not repeat it')&&str_contains($last['result']['guidance'],'Observe again before reporting')
        &&$last['untrusted_observation']===['response'=>'unavailable'],
        'the model is told the effect is unknown and must observe before reporting');
    $emit('Checking the screen again.');return $reply('Checking the screen again.');
};
$out=$run($context,$partial,$provider,setup:$withoutResponse);
$lost=$out['partial']['tools'][1];
check($out['result']['ok']&&$calls===2&&$lost['state']==='returned'&&$lost['result']['reason']==='response_unavailable'&&$lost['result']['retry_allowed']===false,
    'the saved receipt keeps the returned state and the lost-response reason');
check(count($cuaDispatches)===1&&!in_array('stop',array_column($out['wire'],'action'),true),'a lost response is neither replayed nor treated as a failed dispatch');

// Supplied field names and key values can carry screen or personal text; receipts never repeat them.
$calls=0;$seenInput=null;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,&$seenInput,$reply,$observe):array{
    $calls++;
    if($calls===1)return $reply('',[['id'=>'marker-field','name'=>'cua_click','arguments'=>$observe+['element_token'=>'s0000002a:1','PRIVATE_SCREEN_MARKER_42'=>1]]]);
    if($calls===2)return $reply('',[['id'=>'marker-key','name'=>'cua_press_keys','arguments'=>$observe+['keys'=>['PRIVATE_KEY_MARKER_43']]]]);
    $seenInput=$input;$emit('Corrected.');return $reply('Corrected.');
};
$out=$run($context,$partial,$provider,setup:$setupCua);
$receipts=array_values(array_filter(portal_westy_run_restore(portal_westy_run_replay($seenInput)),static fn($m)=>($m['role']??null)==='tool'));
check($out['result']['ok']&&$calls===3&&$cuaDispatches===[]&&array_column(array_slice($out['partial']['tools'],1),'state')===['rejected','rejected'],
    'unknown fields and key names are refused before dispatch');
check(count($receipts)===3&&!str_contains(json_encode($out['saved'],JSON_THROW_ON_ERROR),'PRIVATE_')&&!str_contains(json_encode($receipts,JSON_THROW_ON_ERROR),'PRIVATE_'),
    'supplied field names and key values never reach public, saved or replayed receipts');
check(str_contains($out['partial']['tools'][1]['result']['guidance'],'cua_click accepts: pid, window_id, element_token')
    &&str_contains($out['partial']['tools'][2]['result']['guidance'],'one printable character'),'guidance names only server-owned fields and formats');

// The internal tenant model and its reservation.
$legacy=portal_westy_ai_selection(['status'=>'internal_legacy','revision'=>3],['api_key'=>'sk-synthetic-no-network']);
check($legacy['status']==='active'&&$legacy['provider']==='openai'&&$legacy['model']==='gpt-6.1-sol'&&$legacy['effort']==='medium'&&$legacy['credential_version']===0,
    'the internal tenant uses GPT-6.1 Sol at medium effort');
check(westy_tenant_ai_reserve($legacy,PORTAL_WESTY_AI_MAX_INPUT,PORTAL_WESTY_AI_MAX_OUTPUT,5)===1225600,'a five-round Sol attempt reserves 1.2256 USD before provider I/O');
$astra=westy_tenant_ai_selection('openai','gpt-6-astra','medium');
check(portal_westy_ai_output_allowance($legacy,5000000)===8000&&portal_westy_ai_output_allowance($astra,5000000)===2000
    &&portal_westy_ai_output_allowance($astra,4428000)===1200&&portal_westy_ai_output_allowance($astra,4427999)===null,
    'the allowance shrinks to fit the remaining budget and is never refused sooner than the original 1,200 tokens');

// Adversarial screen text cannot break the evidence bound.
$dense=['isError'=>false,'structuredContent'=>['window_title'=>str_repeat('"\\',3000),'elements'=>array_map(static fn(int $i):array=>['element_index'=>$i,
    'element_token'=>sprintf('s%08x:%d',42,$i),'role'=>'text','label'=>str_repeat('"\\',60).$i,'value'=>str_repeat("\"\x01\\",50)],range(0,149))]];
$fields=['isError'=>false,'structuredContent'=>array_combine(array_map(static fn(int $i):string=>'field_'.$i.str_repeat('k',300),range(0,4999)),
    array_fill(0,5000,str_repeat('"',2000)))];
foreach(['quote-dense controls'=>$dense,'thousands of fields'=>$fields,'control characters'=>['content'=>[['type'=>'text','text'=>str_repeat("\x01\x02\"",40000)]]]] as $label=>$hostile){
    $evidence=portal_westy_cua_evidence($hostile,1);
    $embedded=json_encode(['result'=>[],'untrusted_observation'=>$evidence],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    check(portal_westy_cua_bytes($evidence)<=PORTAL_WESTY_CUA_EVIDENCE_BYTES&&strlen($embedded)<=PORTAL_WESTY_CUA_EVIDENCE_BYTES+40,'evidence stays bounded as embedded: '.$label);
}
foreach(['\\\\server\\share\\app.exe','\\\\?\\C:\\long\\app.exe','C:x.exe','C:\\dir/app.exe','C:\\task.bat'] as $path)
    check($request('cua_launch_app',['path'=>$path])['arguments']===['path'=>$path],'launch retains the requested path for the driver/OS: '.$path);
foreach(['file:///C:/notes.txt','C:\\notes.txt','ms-settings:display'] as $address)
    check($request('cua_launch_app',['urls'=>[$address]])['arguments']===['urls'=>[$address]],'default-app launch retains the requested address or file path');
check($request('cua_launch_app',['path'=>'C:\\Program Files\\Vendor App\\app.exe'])['arguments']===['path'=>'C:\\Program Files\\Vendor App\\app.exe'],'an ordinary local program path is accepted');
$bloated=portal_westy_cua_tool($context,'cua_click',$observe+[str_repeat('k',40000)=>1],static fn():bool=>true);
check(strlen($bloated['public_result']['guidance'])<=420&&!str_contains($bloated['public_result']['guidance'],str_repeat('k',100)),'a rejected receipt never stores an oversized field name');

// A model that keeps sending calls that cannot run ends the reply instead of spending rounds.
$calls=0;
$provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,$reply,$observe):array{
    $calls++;return $reply('',[['id'=>'mistake-'.$calls,'name'=>$calls%2?'cua_click':'desktop_cua','arguments'=>$observe]]);
};
$out=$run($context,$partial,$provider,setup:$setupCua);
check(!$out['result']['ok']&&$out['result']['reason']==='tool_invalid'&&$calls===PORTAL_WESTY_AI_MISTAKE_LIMIT&&$cuaDispatches===[],
    'repeated unsendable calls end the reply at the mistake limit without reaching the device');
$prior=$messages;
for($i=0;$i<PORTAL_WESTY_AI_MISTAKE_LIMIT-1;$i++)$prior[]=['role'=>'tool','call_id'=>'old-'.$i,'content'=>[['type'=>'text',
    'text'=>json_encode(['result'=>['state'=>'rejected'],'observation_status'=>'expired'],JSON_THROW_ON_ERROR)]]];
$calls=0;$work=$partial;
$result=portal_westy_ai_run($pdo,$context,$selection,$prior,$operation,$work,static function():void{},static function():void{},static function():void{},
    $provider,resolver:static fn()=>$selection);
check(!$result['ok']&&$result['reason']==='tool_invalid'&&$calls===1,'mistakes saved in the durable replay count after a continuation');

// Each failure keeps its own name, and its record never carries content.
$failureLog=tempnam(sys_get_temp_dir(),'westy-cua-failure-');$previousLog=ini_set('error_log',$failureLog);
try{
    foreach([[static fn()=>['ok'=>false,'reason'=>'provider_incomplete','usage'=>null],'provider_incomplete'],
        [static fn()=>throw new RuntimeException('provider_invalid'),'provider_invalid'],
        [static fn()=>throw new RuntimeException('PRIVATE adapter text'),'provider_unavailable'],
        [static fn()=>throw new JsonException('PRIVATE json text'),'provider_unavailable']] as [$failing,$expected]){
        $out=$run($context,$partial,static fn(...$arguments)=>$failing(),setup:$setupCua);
        check(!$out['result']['ok']&&$out['result']['reason']===$expected,'failure keeps its own name: '.$expected);
    }
    $logged=(string)file_get_contents($failureLog);
    check(substr_count($logged,'"event":"westy_ai_failure"')===4&&str_contains($logged,'"reason":"provider_incomplete"')&&str_contains($logged,'"error":"JsonException"'),
        'each failed attempt records one content-free reason');
    check(!str_contains($logged,'PRIVATE'),'failure records never include exception text');
    // The adapter's bounded HTTP facts reach the same line; every provider string is re-projected through closed lists.
    $closed=['ok'=>false,'reason'=>'provider_unavailable','usage'=>null,'http'=>400,'transport'=>0,
        'provider_error'=>['type'=>'invalid_request_error','code'=>'unsupported_value','param'=>'reasoning.effort']];
    $out=$run($context,$partial,static fn(...$arguments)=>$closed,setup:$setupCua);
    $lines=array_values(array_filter(explode("\n",(string)file_get_contents($failureLog)),static fn(string $text):bool=>str_contains($text,'"event":"westy_ai_failure"')));
    $last=(string)end($lines);$line=json_decode(substr($last,(int)strpos($last,'{')),true);
    check(!$out['result']['ok']&&$out['result']['reason']==='provider_unavailable'&&($line['http']??null)===400&&($line['transport']??null)===0
        &&($line['provider_type']??null)==='invalid_request_error'&&($line['provider_code']??null)==='unsupported_value'
        &&($line['provider_param']??null)==='reasoning.effort','a streamed provider failure logs its HTTP status and closed error names on the same line');
    $captured=[];$sink=static function(string $text)use(&$captured):void{$captured[]=$text;};
    $hostile=['ok'=>false,'reason'=>'provider_unavailable','usage'=>null,'message'=>'PRIVATE message','http'=>'PRIVATE http','transport'=>999,
        'provider_error'=>['type'=>'PRIVATE_type sk-PRIVATEKEY','code'=>'PRIVATE-code','param'=>'tools[3].PRIVATE','message'=>'PRIVATE error message','key'=>'sk-PRIVATEKEY']];
    portal_westy_ai_failure_diagnostic($operation,1,1,'provider_unavailable',null,$sink,$hostile);
    $hostileLine=json_decode($captured[0]??'',true);
    check(count($captured)===1&&!str_contains($captured[0],'PRIVATE')&&!str_contains($captured[0],'sk-')&&!isset($hostileLine['http'])&&!isset($hostileLine['transport'])
        &&($hostileLine['provider_type']??null)==='unclassified'&&($hostileLine['provider_code']??null)==='unclassified'&&($hostileLine['provider_param']??null)==='unclassified',
        'a hostile adapter result yields no private text and only unclassified names');
    $captured=[];
    portal_westy_ai_failure_diagnostic($operation,1,1,'provider_unavailable',null,$sink,['http'=>600,'transport'=>-1,'provider_error'=>['param'=>'input[3]']]);
    portal_westy_ai_failure_diagnostic($operation,1,1,'provider_unavailable',null,$sink,['provider_error'=>['param'=>'input[03]']]);
    $edge=array_map(static fn(string $text):array=>json_decode($text,true),$captured);
    check(!isset($edge[0]['http'])&&!isset($edge[0]['transport'])&&($edge[0]['provider_param']??null)==='input[3]'&&($edge[1]['provider_param']??null)==='unclassified'
        &&!isset($edge[1]['provider_type'])&&strlen($captured[0])<=1024,'out-of-range integers are omitted, a bounded array index is kept, and the line stays bounded');
}finally{ini_set('error_log',$previousLog===false?'':$previousLog);unlink($failureLog);}

foreach(['known_refusal','lost_reply','stopped'] as $scenario){
    $GLOBALS['wire']=[];$setupCua();$before=$GLOBALS['service'];
    $GLOBALS['service']=static function($context,$action,$input)use($before,$scenario):array{
        if($action==='cua')throw $scenario==='known_refusal'?new PortalDesktopException('tool_restriction',403,true):new PortalDesktopException('connection_unknown');
        return $before($context,$action,$input);
    };
    $result=portal_westy_cua_tool($context,'cua_list_windows',[],static fn():bool=>$scenario!=='stopped');
    check($result['public_result']['state']===($scenario==='lost_reply'?'unknown':'refused')&&!$result['public_result']['retry_allowed']
        &&!isset($result['private_observation']),'refusal, Stop and uncertain dispatch retain distinct truthful outcomes');
    check(count(array_filter($GLOBALS['wire'],static fn($item)=>$item['action']==='cua'))===($scenario==='stopped'?0:1),'no retry or post-Stop dispatch');
    check(in_array('stop',array_column($GLOBALS['wire'],'action'),true)===($scenario!=='known_refusal'),'uncertain or cancelled task invokes the existing scoped Stop');
}
echo "PASS $checks desktop/Cua continuation assertions (synthetic boundaries only)\n";

// Large but bounded driver replies still reach the actual input count.
$windows=['windows'=>array_map(static fn(int $i):array=>['pid'=>1000+$i,'window_id'=>5000+$i,'app_name'=>'Synthetic app',
    'title'=>str_repeat('Synthetic window title. ',10).$i],range(0,49))];
$windowReply=['isError'=>false,'structuredContent'=>$windows,'content'=>[['type'=>'text','text'=>json_encode($windows,JSON_THROW_ON_ERROR)]]];
foreach([[200,50000,null],[200,63000,'input_limit'],[503,0,'input_count_unavailable']] as [$countStatus,$count,$expectedReason]){
    $calls=0;$counted=0;$label=$expectedReason??'admitted';
    $provider=static function($selected,$system,$input,$options,$emit,$alive)use(&$calls,&$counted,$reply,$countStatus,$count):array{
        $calls++;
        if($calls<=3)return $reply('',[['id'=>'synthetic-windows-'.$calls,'name'=>'cua_list_windows','arguments'=>[]]]);
        // The same preparation as westy_tenant_ai_stream; only the count transport is injected.
        $body=westy_tenant_ai_body($selected,$system,$input,array_replace($options,['stream'=>true]));
        check(strlen(json_encode($body,JSON_THROW_ON_ERROR))+2048>$options['max_input_tokens'],'three bounded window lists exceed the conservative byte bound');
        westy_tenant_ai_input_bound($selected,$body,static function(array $s,array $b)use(&$counted,$countStatus,$count):array{
            $counted++;return ['status'=>$countStatus,'body'=>json_encode(['input_tokens'=>$count],JSON_THROW_ON_ERROR)];
        },$options['max_input_tokens']);
        $emit('Windows read.');return $reply('Windows read.');
    };
    $out=$run($context,$partial,$provider,setup:static fn()=>$setupCua($windowReply));
    check($calls===4&&$counted===1&&($out['result']['ok']?null:$out['result']['reason'])===$expectedReason,'actual count decides the next round: '.$label);
    check(array_column(array_slice($out['partial']['tools'],1),'state')===['returned','returned','returned'],'saved driver receipts are kept: '.$label);
    check(count($cuaDispatches)===3&&!str_contains(json_encode($out['saved'],JSON_THROW_ON_ERROR),'Synthetic window title'),
        'no re-dispatch or raw window titles in public history: '.$label);
    if($expectedReason!==null)check(count($out['result']['rounds'])===3&&$out['result']['usage']===null&&$out['result']['cost_micro_usd']===null,
        'refused round keeps the paid receipts and existing unknown-usage accounting: '.$label);
}
echo "PASS $checks desktop/Cua input-count assertions (synthetic boundaries only)\n";
