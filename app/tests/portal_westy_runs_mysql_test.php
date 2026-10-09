<?php
/** Real scoped ledger with synthetic provider and endpoint transport; no customer device calls. */
declare(strict_types=1);
require __DIR__.'/portal_devices_mysql_test.php';
require __DIR__.'/portal_westy_replay_test.php';
function db():PDO{global $pdo;return $pdo;}
check(portal_westy_runs_installed($pdo),'canonical schema includes durable tool runs');
// Exercise the historical v1 surface explicitly; canonical fresh installs now include v2.
if(portal_westy_terminal_installed($pdo))$pdo->exec('ALTER TABLE portal_westy_tool_runs DROP COLUMN processes_json');
$settings['portal_westy']=['enabled'=>true,'ai_enabled'=>true,'tools_enabled'=>true,'hourly_limit'=>100,'daily_limit'=>500,'monthly_microusd'=>20000000];
$settings['desktop_companion']=['endpoint'=>PORTAL_DESKTOP_ENDPOINT,'service_secret'=>str_repeat('e',64)];
// These are actual readiness receipts, confined to this explicitly disposable container.
$readinessFiles=[];
register_shutdown_function(static function()use(&$readinessFiles):void{foreach($readinessFiles as $file)unlink($file);});
foreach(['milepost','safeharbor'] as $job){
    $dir='/run/8west-desktop-cleanup/'.$job;if(!is_dir($dir))mkdir($dir,0700,true);chmod($dir,0700);
    if(file_exists($dir.'/status.json'))throw new RuntimeException('Refusing to replace existing cleanup authority');
    $readinessFiles[]=$dir.'/status.json';
    file_put_contents($dir.'/status.json',json_encode(['schema'=>1,'job'=>$job,'boot_id'=>trim(file_get_contents('/proc/sys/kernel/random/boot_id')),
        'started_at'=>time()-1,'completed_at'=>time(),'exit_code'=>0,'successful_runs'=>2,'healthy'=>true]));chmod($dir.'/status.json',0600);
}
check(desktop_cleanup_available(),'real isolated readiness receipts validate');
$providerSelection=['version'=>1,'app'=>'safeharbor','local_tenant_key'=>'1','tenant_id'=>100,'tenant_slug'=>'provider-1',
    'status'=>'active','revision'=>1,'credential_version'=>1,'api_key'=>'synthetic-no-network']+westy_tenant_ai_selection('openai','gpt-6-luna','low');
$resolver=static fn()=> $providerSelection;
$legacyNames=array_column(portal_westy_ai_tools($pdo,$a+['tool_run'=>['sequence'=>0]],$providerSelection),'name');
check(!in_array('exec_command',$legacyNames,true)&&!in_array('desktop_open',$legacyNames,true),'pre-v2 schema does not advertise general execution or desktop activation');
check(!str_contains(portal_westy_ai_instructions(array_map(static fn($name)=>['name'=>$name],$legacyNames)),'Use exec_command'),'pre-v2 instructions match the offered historical tools');
$request=['action'=>'message','operation'=>'f1'.sprintf('%08x',time()).bin2hex(random_bytes(11)),
    'message'=>'Find the reason for this synthetic computer memory problem.','conversation'=>portal_westy_state($pdo,$a,aiResolver:$resolver)['conversation']];
$providerCalls=0;$queued=0;$receiptState='running';$cancelled=0;$seenMessages=[];
$provider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$providerCalls,&$seenMessages):array{
    $providerCalls++;$seenMessages=$messages;
    check(in_array('inspect_computer',array_column($options['tools'],'name'),true),'general tools available on every resumed round');
    if($providerCalls<=2){
        if($providerCalls===2)check(str_contains(json_encode(end($messages)),'synthetic process evidence'),'second model step uses actual previous output');
        $args=['device_reference'=>'1:'.str_repeat('a',64),'pipeline'=>[['command'=>$providerCalls===1?'Get-Process':'Get-Service','parameters'=>[]]],'effect'=>'Inspect synthetic computer state'];
        $id='call_'.$providerCalls;$call=['id'=>$id,'name'=>'inspect_computer','arguments'=>$args];
        $output=[['type'=>'function_call','call_id'=>$id,'name'=>'inspect_computer','arguments'=>json_encode($args)]];
        $replay=['role'=>'provider','output'=>$output];foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
        $emit('Checking the synthetic computer.');
        return ['ok'=>true,'tool_calls'=>[$call],'continuation'=>$replay,'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>30]];
    }
    check(str_contains(json_encode($messages),'synthetic service evidence'),'final answer sees second real result');
    $emit('The two completed checks support this diagnosis.');
    return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>200,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>30]];
};
$endpoint=static function(string $body,array $headers)use(&$queued,&$receiptState,&$cancelled):array{
    $wire=json_decode($body,true);$action=$wire['action'];
    check($wire['scope']['subject']==='t9u11'&&$wire['scope']['customer_id']===sprintf('00000000-0000-4000-8000-%012d',11),'shell request derives actual customer scope');
    if($action==='shell_queue'){$queued++;$state='queued';}
    elseif($action==='shell_cancel'){$cancelled++;$state='cancelled';}
    else{$state=$receiptState;}
    $receipt=['action_id'=>str_repeat((string)$queued,32),'state'=>$state,'result'=>$state==='completed'?['stdout'=>$queued===1?'synthetic process evidence':'synthetic service evidence','stderr'=>'','exit_code'=>0]:null,'retry_allowed'=>false];
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$receipt])];
};
$runMessage=static function(array $r)use($pdo,$a,$provider,$resolver,$endpoint):void{portal_westy_message($pdo,$a,$r,$provider,static fn()=>$a,transport:$endpoint,aiResolver:$resolver);};
$runMessage($request);$scope=portal_westy_scope($pdo,$a);$run=portal_westy_run_find($pdo,$scope,$request['operation']);
$request['conversation']=$run['conversation_id'];
check($providerCalls===1&&$queued===1&&$run['state']==='waiting'&&(int)$run['sequence']===1,'first asynchronous command suspends without invented result');
$resume=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$run['conversation_id'],'sequence'=>1];
$runMessage($resume);check($providerCalls===1&&$queued===1,'running command causes no provider or endpoint replay');
$_SESSION['desktop_companion_session']=str_repeat('f',32);
try{$runMessage($resume);check(false,'foreign origin resumed');}catch(PortalWestyException $e){check($e->reason==='run_unavailable','companion cannot resume portal-origin run');}
unset($_SESSION['desktop_companion_session']);
$other=portal_westy_scope($pdo,$b);check(portal_westy_run_find($pdo,$other,$request['operation'])===null,'different customer cannot load saved continuation');
$receiptState='completed';$runMessage($resume);
check($providerCalls===2&&$queued===2,'actual result causes next useful model-selected command');
$runMessage($resume);check($providerCalls===2&&$queued===2,'old resume sequence cannot consume next receipt');
$resume['sequence']=2;$runMessage($resume);
check($providerCalls===3&&$queued===2&&portal_westy_run_find($pdo,$scope,$request['operation'])['state']==='complete','final result completes multi-step investigation');
$runMessage($resume);$runMessage($request);check($providerCalls===3&&$queued===2,'reconnect cannot replay completed model or commands');
$q=$pdo->prepare('SELECT reply_json FROM portal_westy_turns WHERE scope_key=? AND operation_key=?');$q->execute([$scope['key'],$request['operation']]);
check(str_contains($q->fetchColumn(),'two completed checks'),'final grounded answer is saved in original conversation');
// Provider replay preserves empty JSON argument objects and removes transient images/UIA.
$replay=[['role'=>'provider','provider'=>'anthropic','model'=>'fixture','effort'=>'','revision'=>1,'credential_version'=>1,
    'output'=>[(object)['type'=>'tool_use','id'=>'inspect','name'=>'list_computers','input'=>(object)[]]]],
    ['role'=>'tool','call_id'=>'inspect','content'=>[['type'=>'image','data'=>'PRIVATE_PIXELS'],['type'=>'text','text'=>'{"untrusted_observation":"PRIVATE_UIA"}']]]];
$saved=portal_westy_run_replay($replay);$restored=portal_westy_run_restore($saved);
check(!str_contains($saved,'PRIVATE_')&&is_object($restored[0]['output'][0]->input),'private replay preserves provider objects and excludes images/UIA');
// Stop a second real run. It suppresses both paid inference continuation and re-queue.
$providerCalls=0;$queued=0;$receiptState='running';$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$runMessage($request);
portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$endpoint);
$resume['operation']=$request['operation'];$resume['sequence']=1;$receiptState='completed';$runMessage($resume);
check($cancelled===1&&$providerCalls===1&&$queued===1,'Stop cancels pending command and prevents late-result continuation');
check(portal_westy_run_find($pdo,$scope,$request['operation'])['replay_json']===null,'Stop erases resumable provider context');

// Existing background health checks also resume from actual receipts, without a native companion.
$settings['portal_devices']['enabled']=true;$settings['portal_devices']['diagnostics_enabled']=true;
$healthCalls=0;$healthQueues=0;$healthReady=false;$device='1:'.str_repeat('a',64);
$healthOperation=['reference'=>str_repeat('e',32),'recipe'=>'health','title'=>'Computer health check','impact'=>'Read-only system status.',
    'device_reference'=>$device,'state'=>'queued','created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600),
    'can_approve'=>false,'approval_fingerprint'=>null,'result'=>null,'basis_reference'=>null,'preview'=>null,'can_cancel'=>true];
$healthTransport=static function($url,$body,$headers)use(&$healthQueues,&$healthReady,$healthOperation):array{
    $request=json_decode($body,true);$receipt=$healthOperation;
    if($healthReady){$receipt['state']='completed';$receipt['can_cancel']=false;$receipt['result']=['version'=>2,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'memory_used_percent'=>42.1,'memory_total_bytes'=>17179869184,'memory_available_bytes'=>8589934592,'system_disk_free_percent'=>55.5,'spooler'=>'running'];}
    if($request['action']==='health_start'){$healthQueues++;$result=$receipt;}
    elseif($request['action']==='operations')$result=['available'=>true,'eligibility'=>['can_check'=>false,'can_propose_repair'=>false,'reason'=>'in_progress'],'items'=>[$receipt]];
    else throw new RuntimeException('Unexpected health request');
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>$result])];
};
$healthProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$healthCalls,$device):array{
    $healthCalls++;
    if($healthCalls===1){
        $args=['device_reference'=>$device];$id='actual_health';
        $replay=['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$id,'name'=>'start_health_check','arguments'=>json_encode($args)]]];
        foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
        return ['ok'=>true,'tool_calls'=>[['id'=>$id,'name'=>'start_health_check','arguments'=>$args]],'continuation'=>$replay,'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>30]];
    }
    check(str_contains(json_encode($messages),'17179869184')&&str_contains(json_encode($messages),'42.1'),'background health model continuation receives actual RAM and usage');
    $emit('The completed check measured 16 GB RAM and 42.1 percent used.');
    return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>150,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>30]];
};
$healthMessage=static function(array $r)use($pdo,$a,$healthProvider,$resolver,$healthTransport):void{portal_westy_message($pdo,$a,$r,$healthProvider,static fn()=>$a,transport:$healthTransport,aiResolver:$resolver);};
$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$request['message']='Check this computer memory.';
$healthMessage($request);$run=portal_westy_run_find($pdo,$scope,$request['operation']);
check($healthCalls===1&&$healthQueues===1&&$run['state']==='waiting','background health waits for result without a premature queued explanation');
$resume=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$run['conversation_id'],'sequence'=>1];
$healthMessage($resume);check($healthCalls===1&&$healthQueues===1,'pending background health does not consume another model turn');
$healthReady=true;$healthMessage($resume);$healthMessage($resume);
check($healthCalls===2&&$healthQueues===1&&portal_westy_run_find($pdo,$scope,$request['operation'])['state']==='complete','completed background health resumes once without requeue');

// The production failure shape, with synthetic arguments: four sources were
// accidentally chained before two transformations. None of that plan may queue.
$sixStages=[
    ['command'=>'Get-CimInstance','parameters'=>[['name'=>'ClassName','values'=>['Win32_OperatingSystem']],['name'=>'Property','values'=>['FreePhysicalMemory','TotalVisibleMemorySize']]]],
    ['command'=>'Get-CimInstance','parameters'=>[['name'=>'ClassName','values'=>['Win32_PerfFormattedData_PerfOS_Processor']],['name'=>'Filter','values'=>["Name='_Total'"]],['name'=>'Property','values'=>['PercentProcessorTime']]]],
    ['command'=>'Get-Volume','parameters'=>[['name'=>'DriveLetter','values'=>['C']]]],
    ['command'=>'Get-Process','parameters'=>[['name'=>'Name','values'=>['*']]]],
    ['command'=>'Sort-Object','parameters'=>[['name'=>'Property','values'=>['WorkingSet64']],['name'=>'Descending','values'=>[]]]],
    ['command'=>'Select-Object','parameters'=>[['name'=>'Property','values'=>['Name','Id','WorkingSet64']],['name'=>'First','values'=>['5']]]],
];
$plans=[[$sixStages[0]],[$sixStages[1]],[$sixStages[2]],array_slice($sixStages,3)];
$fixtureCall=static function(array $selection,string $id,array $plan)use($device):array{
    $args=['device_reference'=>$device,'pipeline'=>$plan,'effect'=>'Read synthetic diagnostic evidence'];
    $replay=['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$id,'name'=>'inspect_computer','arguments'=>json_encode($args)]]];
    foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
    return ['ok'=>true,'tool_calls'=>[['id'=>$id,'name'=>'inspect_computer','arguments'=>$args]],'continuation'=>$replay,
        'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>30]];
};
$fixtureDone=static fn():array=>['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>30]];
$newRequest=static fn():array=>array_replace($request,['operation'=>'f1'.sprintf('%08x',time()).bin2hex(random_bytes(11)),'message'=>'Inspect synthetic memory, processor, disk and process state.']);
$splitCalls=0;$splitQueues=0;$splitKeys=[];
$splitProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$splitCalls,&$splitQueues,$sixStages,$plans,$fixtureCall,$fixtureDone):array{
    $splitCalls++;
    if($splitCalls===1)return $fixtureCall($selection,'bad_composition',$sixStages);
    if($splitCalls===2){
        $feedback=json_decode(end($messages)['content'][0]['text'],true);
        check($splitQueues===0&&$feedback['executed']===false&&$feedback['correction_allowed']===true&&$feedback['retry_allowed']===false,'malformed composition reaches inference as safe feedback with zero dispatches');
        check($feedback['validation']['code']==='transformation_required'&&!str_contains(json_encode($feedback),'_Total'),'feedback explains the structural error without copying submitted arguments');
    }else check(str_contains(json_encode(end($messages)),'synthetic observation '.($splitCalls-2)),'each split source waits for its actual receipt');
    if($splitCalls<=5)return $fixtureCall($selection,'corrected_'.$splitCalls,$plans[$splitCalls-2]);
    $emit('The four completed synthetic observations are available.');return $fixtureDone();
};
$splitEndpoint=static function($body,$headers)use(&$splitQueues,&$splitKeys,$plans):array{
    $wire=json_decode($body,true);
    if($wire['action']==='shell_queue'){
        check($wire['input']['plan']===$plans[$splitQueues],'only one source plus its intended transformations reaches the queue');
        $splitKeys[]=$wire['input']['request_key'];$splitQueues++;$state='queued';
    }else{$state='completed';}
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>['state'=>$state,'result'=>['stdout'=>'synthetic observation '.$splitQueues],'retry_allowed'=>false]])];
};
$splitMessage=static fn(array $r)=>portal_westy_message($pdo,$a,$r,$splitProvider,static fn()=>$a,transport:$splitEndpoint,aiResolver:$resolver);
$splitRequest=$newRequest();$splitMessage($splitRequest);
for($sequence=1;$sequence<=4;$sequence++){
    $splitRun=portal_westy_run_find($pdo,$scope,$splitRequest['operation']);
    check($splitRun['state']==='waiting'&&(int)$splitRun['sequence']===$sequence,'rejection does not consume an asynchronous wait sequence');
    $splitResume=['action'=>'run_resume','operation'=>$splitRequest['operation'],'conversation'=>$splitRun['conversation_id'],'sequence'=>$sequence];
    $splitMessage($splitResume);$splitMessage($splitResume);
}
check($splitCalls===6&&$splitQueues===4&&count(array_unique($splitKeys))===4,'four independent checks finish once with distinct new intents');
$q=$pdo->prepare('SELECT reply_json FROM portal_westy_turns WHERE operation_key=?');$q->execute([$splitRequest['operation']]);$splitReply=json_decode($q->fetchColumn(),true);
check($splitReply['tools'][0]['name']==='inspect_computer'&&$splitReply['tools'][0]['state']==='rejected'&&count($splitReply['tools'])===5,'saved transcript has a named rejected tool, never a blank unavailable row');

// A saved endpoint validation refusal is also safe to correct after reconnect.
// Unknown queue responses and post-dispatch receipts never acquire that permission.
foreach(['refused','unknown','dispatched'] as $scenario){
    $calls=0;$queues=0;$reads=0;
    $fixtureProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$calls,$scenario,$fixtureCall,$fixtureDone,$plans):array{
        $calls++;
        if($calls===1)return $fixtureCall($selection,'first_intent',$plans[0]);
        $receipt=json_decode(end($messages)['content'][0]['text'],true)['untrusted_result'];
        if($scenario==='refused'&&$calls===2){
            check($receipt['executed']===false&&$receipt['correction_allowed']===true&&$receipt['retry_allowed']===false,'durable pre-execution refusal resumes with correction feedback');
            return $fixtureCall($selection,'new_corrected_intent',$plans[1]);
        }
        check(!isset($receipt['correction_allowed'])&&!isset($receipt['executed']),'unknown and dispatched receipts never become pre-execution rejections');
        return $fixtureDone();
    };
    $fixtureEndpoint=static function($body,$headers)use(&$queues,&$reads,$scenario):array{
        $wire=json_decode($body,true);
        if($wire['action']==='shell_queue'){
            $queues++;
            if($queues===1&&$scenario==='refused')return ['status'=>400,'body'=>'{"ok":false,"reason":"invalid_pipeline"}'];
            if($scenario==='unknown')return ['status'=>503,'body'=>'{"ok":false,"reason":"invalid_pipeline"}'];
            $receipt=['state'=>'queued'];
        }else{$reads++;$receipt=$scenario==='refused'?['state'=>'completed']:['state'=>'unknown','reason'=>'invalid_pipeline'];}
        return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$receipt+['retry_allowed'=>false]])];
    };
    $fixtureMessage=static fn(array $r)=>portal_westy_message($pdo,$a,$r,$fixtureProvider,static fn()=>$a,transport:$fixtureEndpoint,aiResolver:$resolver);
    $fixtureRequest=$newRequest();$fixtureMessage($fixtureRequest);$fixtureRun=portal_westy_run_find($pdo,$scope,$fixtureRequest['operation']);
    if($scenario==='refused'){
        // Also exercise a pre-fix waiting record, without a new schema or replay.
        $pending=json_decode($fixtureRun['pending_json'],true);$pending['terminal']=['state'=>'unavailable','reason'=>'invalid_pipeline','retry_allowed'=>false];
        $pdo->prepare('UPDATE portal_westy_tool_runs SET pending_json=? WHERE turn_id=?')->execute([json_encode($pending),$fixtureRun['turn_id']]);
    }
    $fixtureResume=['action'=>'run_resume','operation'=>$fixtureRequest['operation'],'conversation'=>$fixtureRun['conversation_id'],'sequence'=>1];
    $fixtureMessage($fixtureResume);$fixtureMessage($fixtureResume);
    if($scenario==='refused'){$fixtureResume['sequence']=2;$fixtureMessage($fixtureResume);$fixtureMessage($fixtureResume);}
    check($queues===($scenario==='refused'?2:1)&&$calls===($scenario==='refused'?3:2),'reconnect consumes receipt once and never resends an existing intent');
    check($reads===1,'recorded refusal requires no endpoint retry; real/unknown result is fetched once');
}

// Repeated malformed model output remains inside the original paid-round bound.
$badCalls=0;$badQueues=0;
$badProvider=static function($selection)use(&$badCalls,$sixStages,$fixtureCall):array{return $fixtureCall($selection,'bad_'.++$badCalls,$sixStages);};
$badTransport=static function()use(&$badQueues):array{$badQueues++;throw new RuntimeException('Invalid plan reached transport');};
$badRequest=$newRequest();portal_westy_message($pdo,$a,$badRequest,$badProvider,static fn()=>$a,transport:$badTransport,aiResolver:$resolver);
check($badCalls===5&&$badQueues===0&&portal_westy_run_find($pdo,$scope,$badRequest['operation'])['state']==='stopped','repeated validation failures stop at the bounded limit without endpoint work');

echo 'PASS general tool continuation MySQL: '.$checks." checks\n";
