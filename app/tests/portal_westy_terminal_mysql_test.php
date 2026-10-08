<?php
/** Real MySQL turn/run locks and ownership, synthetic model and endpoint only. */
declare(strict_types=1);
require __DIR__.'/portal_westy_runs_mysql_test.php';
// The required historical continuation suite leaves the exact v1 predecessor.
portal_westy_fixture_sql($pdo,__DIR__.'/../db/migrations/endpoint_tool_runs_v2.sql');
check(portal_westy_terminal_installed($pdo),'additive v2 column activates general tools');
unset($settings['portal_westy']['tools_enabled']);
check(portal_westy_tools_enabled(),'existing and new users without an override receive globally enabled tools');
$settings['portal_westy']['tools_enabled']=false;
check(!portal_westy_tools_enabled(),'explicit configured tool disable remains honored');
unset($settings['portal_westy']['tools_enabled']);
$generalNames=array_column(portal_westy_ai_tools($pdo,$a+['tool_run'=>['sequence'=>0]],$providerSelection),'name');
check(in_array('exec_command',$generalNames,true)&&in_array('desktop_open',$generalNames,true),'v2 schema with native configuration advertises general execution and desktop activation');
check(str_contains(portal_westy_ai_instructions(array_map(static fn($name)=>['name'=>$name],$generalNames)),'Use exec_command')&&!str_contains(portal_westy_ai_instructions([]),'Use exec_command'),'instructions match actual offered tools and do not advertise exhausted general tools');
$settings['portal_westy']['hourly_limit']=100;$settings['portal_westy']['daily_limit']=1000;$settings['portal_westy']['monthly_microusd']=20000000;
$phase='prepare';$termCalls=[];$nativeReceipt=null;$paid=0;$actualHandle=null;$stopDuringReview=false;$losePrepare=false;$needsApproval=false;
$terminalTransport=static function(string $body,array $headers)use(&$phase,&$termCalls,&$nativeReceipt,&$stopDuringReview,&$losePrepare,&$needsApproval,$pdo,$a,$scope):array{
    $wire=json_decode($body,true);$action=$wire['action'];$input=$wire['input'];$termCalls[]=$action;
    check($wire['scope']['subject']===$a['identity']['subject'],'terminal wire uses authenticated actor');
    if($action==='terminal_queue'){
        check(!$input['approved_fingerprint'],'model cannot approve prepared command');
        check(str_contains($input['authorized_task'],'synthetic'),'actual user request reaches independent review');
        $nativeReceipt=['contract'=>'westy-terminal-v2','action_id'=>str_repeat('b',32),'run_id'=>$input['run_id'],'request_key'=>$input['request_key'],
            'device_id'=>1,'session_id'=>str_repeat('d',32),'execution_generation'=>null,'execution_context'=>'user',
            'state'=>'review_pending','effect'=>'Read synthetic input','fingerprint'=>str_repeat('e',64),
            'approval_fingerprint'=>null,'review'=>['phase'=>'pending','decision'=>'approval','reason'=>'Pending review'],
            'progress'=>null,'finished_at'=>null,'retry_allowed'=>false];
        if($losePrepare)throw new PortalDesktopException('connection_unknown');
    }elseif($action==='terminal_review'){
        if($stopDuringReview){
            $stopDuringReview=false;
            $cancel=static function($b,$h)use(&$nativeReceipt):array{$nativeReceipt['state']='cancelled';return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$nativeReceipt])];};
            portal_westy_run_stop($pdo,$a,$scope,$input['run_id'],$cancel);
            check(!$pdo->inTransaction(),'Stop can acquire turn lock during independent review');
        }elseif($needsApproval&&$input['approved_fingerprint']===null){
            $nativeReceipt['state']='awaiting_approval';$nativeReceipt['approval_fingerprint']=str_repeat('a',64);
            $nativeReceipt['review']=['phase'=>'approval','decision'=>'approval','reason'=>'Exact synthetic approval'];
        }else{$nativeReceipt['state']='queued';$nativeReceipt['approval_fingerprint']=null;$nativeReceipt['review']=['phase'=>'ready','decision'=>'ordinary','reason'=>'Requested work'];}
    }elseif($action==='terminal_input'){
        check($input['chars']==="synthetic input\n",'exact stdin crosses backend');
        $nativeReceipt['progress']['input_sequence']=1;$nativeReceipt['progress']['sequence']=2;
        $nativeReceipt['progress']['stdout']='synthetic input read successfully';
        return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>['state'=>'input_queued','input_sequence'=>1,'retry_allowed'=>false]])];
    }elseif($action==='terminal_cancel'){$nativeReceipt['state']='cancelled';}
    elseif($action!=='terminal_result')throw new RuntimeException('Unexpected endpoint action '.$action);
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$nativeReceipt])];
};
$terminalProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$paid,&$actualHandle,$device):array{
    $paid++;check(in_array('exec_command',array_column($options['tools'],'name'),true),'v2 exposes actual general command tool');
    check(!in_array('inspect_computer',array_column($options['tools'],'name'),true),'v2 uses general runtime instead of fixed catalog');
    check(!str_contains(json_encode($messages),'approval_fingerprint'),'provider does not receive approval capability');
    if($paid===1){$name='exec_command';$args=['device_reference'=>$device,'command'=>'$value = Read-Host; Write-Output $value','working_directory'=>null,'execution_context'=>'user','tty'=>false,'timeout_seconds'=>120,'effect'=>'Read synthetic input'];}
    elseif($paid===2){$last=json_decode(end($messages)['content'][0]['text'],true)['untrusted_result'];$actualHandle=$last['process_id'];
        check($last['state']==='running'&&$last['progress']['exit_code']===null,'actual running process resumes inference without fabricated completion');
        $name='write_stdin';$args=['process_id'=>$actualHandle,'chars'=>"synthetic input\n"];
    }else{check(str_contains(json_encode($messages),'synthetic input read successfully'),'continuation sees acknowledged input output');
        $emit('The synthetic program read the input.');return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];}
    $id='terminal_call_'.$paid;$replay=['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$id,'name'=>$name,'arguments'=>json_encode($args)]]];
    foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
    return ['ok'=>true,'tool_calls'=>[['id'=>$id,'name'=>$name,'arguments'=>$args]],'continuation'=>$replay,
        'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];
};
$sendTerminal=static function(array $r)use($pdo,$a,$terminalProvider,$resolver,$terminalTransport):void{
    portal_westy_message($pdo,$a,$r,$terminalProvider,static fn()=>$a,transport:$terminalTransport,aiResolver:$resolver);
};
$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$request['message']='Run the synthetic input program and provide its requested non-secret input.';
$sendTerminal($request);$run=portal_westy_run_find($pdo,$scope,$request['operation']);
check($paid===1&&$termCalls===['terminal_queue','terminal_review'],'prepare receipt is saved before review exactly once');
$processes=portal_westy_terminal_processes($run);check(count($processes)===1&&!str_contains($run['processes_json'],'Read-Host'),'process ownership contains no command text');
$handle=array_key_first($processes);$otherRun=$run;$otherRun['operation_key']=str_repeat('a',32);
try{portal_westy_terminal_process($otherRun,$handle);check(false,'cross-run handle accepted');}catch(PortalWestyException $e){check($e->reason==='process_unavailable','cross-run handles rejected');}
try{portal_westy_terminal_process($run,str_repeat('0',32));check(false,'forged handle accepted');}catch(PortalWestyException){check(true,'invented handle rejected');}
$_SESSION['desktop_companion_session']=str_repeat('f',32);
try{portal_westy_terminal_process($run,$handle);check(false,'cross-origin handle accepted');}catch(PortalWestyException){check(true,'cross-origin handle rejected');}unset($_SESSION['desktop_companion_session']);
$resume=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$run['conversation_id'],'sequence'=>1];
$sendTerminal($resume);check($paid===1,'queued receipt does not spend another model attempt');
$nativeReceipt['state']='running';$nativeReceipt['progress']=['action_id'=>$nativeReceipt['action_id'],'fingerprint'=>$nativeReceipt['fingerprint'],
    'state'=>'running','sequence'=>0,'input_sequence'=>0,'exit_code'=>null,'stdout'=>'','stderr'=>'','duration_ms'=>0,'truncated'=>false];
$sendTerminal($resume);check($paid===1,'pre-start progress cannot resume or release stdin');
$nativeReceipt['progress']['sequence']=1;$sendTerminal($resume);check($paid===2&&$actualHandle===$handle,'running output resumes with exact process handle');
$resume['sequence']=2;$sendTerminal($resume);check($paid===3,'acknowledged stdin resumes the model once');
$sendTerminal($resume);check($paid===3&&count(array_filter($termCalls,static fn($v)=>$v==='terminal_queue'))===1,'reconnect cannot replay general execution');
// A final model reply closes inference, not the original still-running process.
$originalOperation=$request['operation'];
$loadTurn=static function()use($pdo,$scope,$originalOperation):array{
    $q=$pdo->prepare('SELECT operation_key,state,reason_code,reply_json FROM portal_westy_turns WHERE scope_key=? AND operation_key=?');
    $q->execute([$scope['key'],$originalOperation]);$turn=$q->fetch();$turn['reply']=json_decode($turn['reply_json'],true);unset($turn['reply_json']);return $turn;
};
$refreshTerminal=static fn(?callable $transport=null)=>portal_westy_terminal_refresh($pdo,$a,$scope,[$loadTurn()],$transport??$terminalTransport)[0];
$live=$refreshTerminal();
check($live['state']==='complete'&&$live['terminal_active']&&$live['terminal_processes'][$handle]['state']==='running','final reply keeps running process and Stop visible');
check(($live['run']['ready']??false)===false&&$paid===3,'receipt refresh never authorizes another paid continuation');
$nativeReceipt['progress']['sequence']=3;$nativeReceipt['progress']['stdout']='fresh output after final reply';
$live=$refreshTerminal();check($live['terminal_processes'][$handle]['progress']['stdout']==='fresh output after final reply','reload reads fresh output after final reply');
check(str_contains(json_encode($loadTurn()['reply']),'fresh output after final reply'),'updated receipt persists in existing chat after reload');
check(!str_contains(portal_westy_run_find($pdo,$scope,$originalOperation)['processes_json'],'fresh output'),'process ownership ledger remains content-free');
$savedReceipt=$nativeReceipt;$nativeReceipt['device_id']=999;
check($refreshTerminal()['terminal_processes'][$handle]['state']==='unavailable','receipt with changed device identity is refused');$nativeReceipt=$savedReceipt;
$run=portal_westy_run_find($pdo,$scope,$originalOperation);
// Seed elapsed time on this disposable row by replacing it; the production
// immutability trigger correctly refuses changing expiry in place.
$elapsed=$run;$elapsed['created_at']=gmdate('Y-m-d H:i:s',time()-3601);$elapsed['expires_at']=gmdate('Y-m-d H:i:s',time()-1);
$pdo->prepare('DELETE FROM portal_westy_tool_runs WHERE scope_key=? AND operation_key=?')->execute([$scope['key'],$originalOperation]);
$pdo->prepare('INSERT INTO portal_westy_tool_runs (`'.implode('`,`',array_keys($elapsed)).'`) VALUES ('.implode(',',array_fill(0,count($elapsed),'?')).')')->execute(array_values($elapsed));
$expired=portal_westy_run_find($pdo,$scope,$originalOperation);
check(!portal_westy_run_matches($expired)&&portal_westy_terminal_owned_process($expired,$handle)['action_id']===$nativeReceipt['action_id'],'inference expiry preserves only original receipt ownership');
try{portal_westy_terminal_process($expired,$handle);check(false,'expired run accepted execution handle');}catch(PortalWestyException){check(true,'expired execution/input handle remains closed');}
check($refreshTerminal()['terminal_active'],'original expired run can still read unresolved receipt');
require_once __DIR__.'/../lib/portal_westy_maintenance.php';
$pdo->prepare("UPDATE portal_westy_tool_runs SET state='waiting',replay_json=?,pending_json=? WHERE scope_key=? AND operation_key=?")
    ->execute([json_encode(['synthetic_private_replay']),json_encode(['chars'=>'synthetic_private_stdin']),$scope['key'],$originalOperation]);
portal_westy_maintain($pdo,true);$expired=portal_westy_run_find($pdo,$scope,$originalOperation);
check($expired!==null&&$expired['state']==='stopped'&&$expired['pending_json']===null&&$expired['replay_json']===null,'maintenance closes expired inference and erases replay/input while retaining original process ownership');
check(!str_contains($expired['processes_json'],'synthetic_private')&&$refreshTerminal()['terminal_processes'][$handle]['state']==='running','expired process status survives cleanup without retained text or a fabricated Stop');
try{$sendTerminal($resume);check(false,'maintenance-expired continuation resumed');}
catch(PortalWestyException $error){check($error->reason==='run_unavailable'&&$paid===3,'maintenance expiry cannot reopen paid continuation');}
$before=count($termCalls);$_SESSION['desktop_companion_session']=str_repeat('f',32);
$foreign=$refreshTerminal();portal_westy_run_stop($pdo,$a,$scope,$originalOperation,$terminalTransport);
check(!isset($foreign['terminal_processes'])&&count($termCalls)===$before,'different origin cannot poll or Stop the original process');unset($_SESSION['desktop_companion_session']);
$revoke=static function($body,$headers)use($terminalTransport,$pdo,$a):array{
    $response=$terminalTransport($body,$headers);
    portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'disabled','Synthetic lifecycle revocation.');return $response;
};
try{$refreshTerminal($revoke);check(false,'revoked lifecycle receipt disclosed');}catch(PortalWestyException $e){check($e->reason==='sign_in','revocation while polling blocks receipt disclosure');}
try{portal_westy_run_stop($pdo,$a,$scope,$originalOperation,$terminalTransport);check(false,'revoked actor stopped process');}catch(PortalWestyException $e){check($e->reason==='sign_in','Stop revalidates current actor');}
portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'active','Synthetic test restored.');
// Stop races an in-flight read. A late running reply cannot restore inference or claim cancellation.
$race=static function($body,$headers)use($terminalTransport,$pdo,$a,$scope,$originalOperation):array{
    $response=$terminalTransport($body,$headers);
    portal_westy_run_stop($pdo,$a,$scope,$originalOperation,$terminalTransport);return $response;
};
$live=$refreshTerminal($race);
check($live['state']==='unavailable'&&$live['terminal_active']&&$live['terminal_processes'][$handle]['state']==='unknown','Stop wins late running receipt and retains unresolved fence');
check(portal_westy_run_find($pdo,$scope,$originalOperation)['state']==='stopped'&&$nativeReceipt['state']==='cancelled','original closed expired run Stop reaches its process without reopening run');
$live=$refreshTerminal();check(!$live['terminal_active']&&$live['terminal_processes'][$handle]['state']==='cancelled','actual final receipt clears live polling');
$before=count($termCalls);$live=$refreshTerminal();check(count($termCalls)===$before&&!$live['terminal_active'],'reload retains final receipt without repeated endpoint polling');
$run=portal_westy_run_find($pdo,$scope,$originalOperation);$processes=portal_westy_terminal_processes($run);$processes[$handle]['next_check_at']=0;
$pdo->prepare('UPDATE portal_westy_tool_runs SET processes_json=? WHERE scope_key=? AND operation_key=?')->execute([json_encode($processes),$scope['key'],$originalOperation]);
$nativeReceipt['content_expired']=true;$nativeReceipt['progress']['stdout']='';$nativeReceipt['progress']['stderr']='';
$live=$refreshTerminal();check($live['terminal_processes'][$handle]['content_expired']&&!str_contains(json_encode($live['reply']),'fresh output after final reply'),'expired receipt removes temporary output from refreshed chat copy');
check($paid===3&&count(array_filter($termCalls,static fn($v)=>$v==='terminal_queue'))===1,'all lifecycle reads and Stop preserve one original execution and paid count');
// Approval is an exact CSRF-origin operation, never a model field or inferred consent.
$paid=0;$needsApproval=true;$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendTerminal($request);
$run=portal_westy_run_find($pdo,$scope,$request['operation']);$public=portal_westy_run_public($pdo,$a,$scope,$request['operation'],$terminalTransport);
check(!$public['ready']&&$public['approval']['command']['command']==='$value = Read-Host; Write-Output $value','origin sees exact command before approval');
try{portal_westy_terminal_approve($pdo,$a,$request['operation'],1,str_repeat('f',64),$terminalTransport);check(false,'changed approval accepted');}catch(PortalWestyException){check(true,'changed approval rejected');}
portal_westy_terminal_approve($pdo,$a,$request['operation'],1,str_repeat('a',64),$terminalTransport);
check($nativeReceipt['state']==='queued','exact approval releases the prepared action');portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$terminalTransport);
// Lost prepare cannot dispatch. Cancellation uses its immutable request key.
$needsApproval=false;$paid=0;$losePrepare=true;$termCalls=[];$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendTerminal($request);
check($termCalls===['terminal_queue','terminal_cancel']&&$nativeReceipt['state']==='cancelled','lost prepare never reviews or dispatches and cancels by request key');
$lostRun=portal_westy_run_find($pdo,$scope,$request['operation']);check(portal_westy_run_result($a,$lostRun,$terminalTransport)['receipt']['state']==='unknown','lost response remains unknown without retry');
check(!isset(portal_westy_run_result($a,$lostRun,$terminalTransport)['receipt']['correction_allowed']),'lost prepare never becomes a correctable pre-execution refusal');
// A Stop arriving while review is pending wins; a late receipt cannot revive it.
$losePrepare=false;$paid=0;$stopDuringReview=true;$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$sendTerminal($request);
check(portal_westy_run_find($pdo,$scope,$request['operation'])['state']==='stopped'&&$nativeReceipt['state']==='cancelled','late review result cannot resurrect a stopped run');
// Only an explicit first queue refusal may guide a new non-TTY intent.
$fixPaid=0;$fixWires=[];$termCalls=[];$losePrepare=false;$stopDuringReview=false;$needsApproval=false;
$fixTransport=static function($body,$headers)use(&$fixWires,$terminalTransport):array{
    $wire=json_decode($body,true);$fixWires[]=$wire;
    if($wire['action']==='terminal_queue'&&$wire['input']['command']['tty'])return ['status'=>409,'body'=>json_encode(['ok'=>false,'reason'=>'terminal_tty_unsupported'])];
    return $terminalTransport($body,$headers);
};
$fixProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$fixPaid,$device):array{
    $fixPaid++;
    if($fixPaid===2){$last=json_decode(end($messages)['content'][0]['text'],true)['untrusted_result'];
        check($last['executed']===false&&$last['correction_allowed']===true&&$last['retry_allowed']===false&&$last['correction']['tty']===false,
            'model receives actionable correction only from the definitive original TTY queue refusal');}
    if($fixPaid>2){check(str_contains(json_encode($messages),'synthetic corrected output'),'fresh non-TTY result reaches continued inference');
        $emit('The synthetic ordinary command completed.');return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];}
    $args=['device_reference'=>$device,'command'=>"Write-Output 'synthetic corrected output'",'working_directory'=>null,'execution_context'=>'user','tty'=>$fixPaid===1,'timeout_seconds'=>120,'effect'=>'Read synthetic corrected output'];
    $id='tty_fresh_intent_'.$fixPaid;$replay=['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$id,'name'=>'exec_command','arguments'=>json_encode($args)]]];
    foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
    return ['ok'=>true,'tool_calls'=>[['id'=>$id,'name'=>'exec_command','arguments'=>$args]],'continuation'=>$replay,
        'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];
};
$fixMessage=static function(array $r)use($pdo,$a,$fixProvider,$resolver,$fixTransport):void{
    portal_westy_message($pdo,$a,$r,$fixProvider,static fn()=>$a,transport:$fixTransport,aiResolver:$resolver);
};
$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$request['message']='Read synthetic corrected output using a suitable command.';$fixMessage($request);
$fixRun=portal_westy_run_find($pdo,$scope,$request['operation']);
check(count($fixWires)===1&&$fixWires[0]['action']==='terminal_queue'&&portal_westy_terminal_processes($fixRun)===[],
    'unsupported initial TTY creates no process ownership, paid endpoint review or cancellation request');
$fixResume=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$fixRun['conversation_id'],'sequence'=>1];$fixMessage($fixResume);
$queues=array_values(array_filter($fixWires,static fn($wire)=>$wire['action']==='terminal_queue'));
check(count($queues)===2&&$queues[0]['input']['request_key']!==$queues[1]['input']['request_key']&&$queues[0]['input']['command']['tty']===true&&$queues[1]['input']['command']['tty']===false,
    'correction creates a fresh reviewed non-TTY intent without replaying or changing the rejected intent');
$nativeReceipt['state']='completed';$nativeReceipt['progress']=['sequence'=>1,'input_sequence'=>0,'state'=>'completed','exit_code'=>0,'stdout'=>'synthetic corrected output','stderr'=>'','duration_ms'=>1,'truncated'=>false];
$fixResume['sequence']=2;$fixMessage($fixResume);
check($fixPaid===3&&count(array_filter($fixWires,static fn($wire)=>$wire['action']==='terminal_review'))===1,'ordinary non-TTY correction completes through one fresh review and actual receipt');
$paid=0;$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));
$lateUnsupported=static function($body,$headers)use($terminalTransport):array{
    if(json_decode($body,true)['action']==='terminal_review')return ['status'=>409,'body'=>json_encode(['ok'=>false,'reason'=>'terminal_tty_unsupported'])];
    return $terminalTransport($body,$headers);
};
portal_westy_message($pdo,$a,$request,$terminalProvider,static fn()=>$a,transport:$lateUnsupported,aiResolver:$resolver);
$lateRun=portal_westy_run_find($pdo,$scope,$request['operation']);$lateResult=portal_westy_run_result($a,$lateRun,$lateUnsupported)['receipt'];
check(!isset($lateResult['executed'])&&!isset($lateResult['correction_allowed'])&&$lateResult['retry_allowed']===false,'a later review failure cannot masquerade as the original unexecuted queue refusal');
// Retained ownership must leave before parent retention/metadata deletion: no orphan FK.
$oldOperation=bin2hex(random_bytes(16));$q=$pdo->prepare('SELECT * FROM portal_westy_turns WHERE scope_key=? AND operation_key=?');$q->execute([$scope['key'],$originalOperation]);$oldTurn=$q->fetch();unset($oldTurn['id']);
$oldTurn['operation_key']=$oldOperation;$oldTurn['created_at']=gmdate('Y-m-d H:i:s',time()-91*86400);$oldTurn['expires_at']=gmdate('Y-m-d H:i:s',time()-86400);
$pdo->prepare('INSERT INTO portal_westy_turns (`'.implode('`,`',array_keys($oldTurn)).'`) VALUES ('.implode(',',array_fill(0,count($oldTurn),'?')).')')->execute(array_values($oldTurn));
$oldId=(int)$pdo->lastInsertId();$oldRun=$expired;$oldRun['turn_id']=$oldId;$oldRun['operation_key']=$oldOperation;$oldRun['created_at']=$oldTurn['created_at'];$oldRun['expires_at']=$oldTurn['expires_at'];
$oldProcesses=portal_westy_terminal_processes($oldRun);$oldProcesses[$handle]['run_id']=$oldOperation;$oldRun['processes_json']=json_encode($oldProcesses);
$pdo->prepare('INSERT INTO portal_westy_tool_runs (`'.implode('`,`',array_keys($oldRun)).'`) VALUES ('.implode(',',array_fill(0,count($oldRun),'?')).')')->execute(array_values($oldRun));
portal_westy_maintain($pdo,true);
check(portal_westy_run_find($pdo,$scope,$oldOperation)===null&&!(bool)$pdo->query('SELECT id FROM portal_westy_turns WHERE id='.$oldId)->fetchColumn(),'parent retention deletes preserved process ownership before parent metadata without orphan FK');
check(portal_westy_run_find($pdo,$scope,$originalOperation)!==null,'other retained original-run ownership is not erased by parent cleanup');
portal_westy_maintain($pdo,true,$scope);
check(portal_westy_run_find($pdo,$scope,$originalOperation)===null,'exact-scope erasure removes even retained original-process ownership');
$q=$pdo->prepare('SELECT COUNT(*) FROM portal_westy_tool_runs WHERE scope_key=?');$q->execute([$scope['key']]);check((int)$q->fetchColumn()===0,'exact-scope erasure leaves no run replay or process metadata');
$request['conversation']=portal_westy_account($pdo,$scope)['conversation_key'];
// More than five successful synchronous tools continue from real receipts,
// preserving cost bounds and Stop without repeating the earlier calls.
$continueCalls=0;$recoverCalls=0;
$continuationTransport=static function(string $body)use(&$recoverCalls,$a):array{
    $wire=json_decode($body,true);
    check($wire['action']==='terminal_tasks'&&$wire['scope']['subject']===$a['identity']['subject'],'recovery dispatch uses current actor and performs no execution');
    $recoverCalls++;
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>['tasks'=>[],'execution_guard'=>'allow']])];
};
$continuationProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$continueCalls,$device,$fixtureDone):array{
    $continueCalls++;
    if($continueCalls===6){
        $receipts=array_values(array_filter($messages,static fn($m)=>($m['role']??null)==='tool'));
        check(count($receipts)===5&&count(array_unique(array_column($receipts,'call_id')))===5,'continued inference sees every actual tool receipt exactly once');
        $emit('The five synthetic task inventories are available.');return $fixtureDone();
    }
    $id='recovery_round_'.$continueCalls;$args=['device_reference'=>$device];
    $replay=['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$id,'name'=>'list_tasks','arguments'=>json_encode($args)]]];
    foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
    return ['ok'=>true,'tool_calls'=>[['id'=>$id,'name'=>'list_tasks','arguments'=>$args]],'continuation'=>$replay,
        'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];
};
$continueMessage=static fn(array $r)=>portal_westy_message($pdo,$a,$r,$continuationProvider,static fn()=>$a,transport:$continuationTransport,aiResolver:$resolver);
$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$request['message']='Inspect prior synthetic task receipts and explain the result.';
$continueMessage($request);$run=portal_westy_run_find($pdo,$scope,$request['operation']);
check($continueCalls===5&&$recoverCalls===5&&$run['state']==='waiting','fifth valid tool yields a durable continuation instead of failing the task');
check(portal_westy_run_result($a,$run,$continuationTransport)['ready']===true&&$recoverCalls===5,'ready continuation does not query or replay prior tools');
$resume=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$run['conversation_id'],'sequence'=>1];
$continueMessage($resume);$continueMessage($resume);
check($continueCalls===6&&$recoverCalls===5&&portal_westy_run_find($pdo,$scope,$request['operation'])['state']==='complete','continued model response and reconnect do not repeat tools or paid inference');
$continueCalls=0;$recoverCalls=0;$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));
$continueMessage($request);$run=portal_westy_run_find($pdo,$scope,$request['operation']);
portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$continuationTransport);
$resume=['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$run['conversation_id'],'sequence'=>1];
try{$continueMessage($resume);}catch(PortalWestyException){}
check($continueCalls===5&&$recoverCalls===5&&portal_westy_run_find($pdo,$scope,$request['operation'])['state']==='stopped','Stop wins before continuation and cannot restart paid work');
require __DIR__.'/portal_westy_recovery_mysql_cases.php';
echo "PASS terminal v2 MySQL: $checks cumulative assertions\n";
