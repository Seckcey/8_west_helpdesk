<?php
/** Real scoped ledger with synthetic provider and endpoint transport; no customer device calls. */
declare(strict_types=1);
require __DIR__.'/portal_devices_mysql_test.php';
function db():PDO{global $pdo;return $pdo;}
check(portal_westy_runs_installed($pdo),'canonical schema includes durable tool runs');
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

echo 'PASS general tool continuation MySQL: '.$checks." checks\n";
