<?php
/** Included by the real MySQL terminal suite; endpoint/model fixtures are synthetic. */
declare(strict_types=1);
$attachReceipt=['contract'=>'westy-terminal-v2','action_id'=>bin2hex(random_bytes(16)),
    'run_id'=>bin2hex(random_bytes(16)),'conversation_id'=>bin2hex(random_bytes(16)),'origin_channel'=>'companion',
    'request_key'=>bin2hex(random_bytes(16)),'device_id'=>(int)explode(':',$device,2)[0],
    'session_id'=>bin2hex(random_bytes(16)),'execution_generation'=>null,'execution_context'=>'user','state'=>'running',
    'progress'=>['state'=>'running','sequence'=>1,'input_sequence'=>0,'exit_code'=>null,'stdout'=>'waiting for input',
        'stderr'=>'','duration_ms'=>3600000,'truncated'=>false],'retry_allowed'=>false];
$attachWires=[];$attachPaid=0;$attachedHandle=null;$attachStopRace=false;
$attachTransport=static function(string $body)use(&$attachWires,&$attachReceipt,&$attachStopRace,$pdo,$a,$scope,&$request):array{
    $wire=json_decode($body,true);$attachWires[]=$wire;
    check($wire['scope']['subject']===$a['identity']['subject'],'attachment keeps current authenticated actor');
    if($wire['action']==='terminal_task_result'){
        check($wire['input']['action_id']===$attachReceipt['action_id'],'attachment reads exact prior task');
        if($attachStopRace){$attachStopRace=false;portal_westy_run_stop($pdo,$a,$scope,$request['operation']);}
    }else{
        check(in_array($wire['action'],['terminal_input','terminal_result','terminal_cancel'],true),'attachment never queues or reviews a second command');
        foreach(['run_id','conversation_id','origin_channel','action_id'] as $field)
            check($wire['input'][$field]===$attachReceipt[$field],'attached control retains original '.$field);
        if($wire['action']==='terminal_input'){
            check($wire['input']['chars']==="new turn input\n"&&$wire['input']['authorized_task']===$request['message'],'stdin uses literal input and the new actual user request');
            $attachReceipt['progress']['input_sequence']=1;$attachReceipt['progress']['sequence']=2;
            $attachReceipt['progress']['stdout']='new turn input acknowledged';
            return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>['state'=>'input_queued','input_sequence'=>1,'retry_allowed'=>false]])];
        }
        if($wire['action']==='terminal_cancel')$attachReceipt['state']='cancelled';
    }
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$attachReceipt])];
};
$attachProvider=static function($selection,$system,$messages,$options,$emit,$alive)use(&$attachPaid,&$attachedHandle,&$attachReceipt,$device,$fixtureDone,$pdo,$a,$b,$scope,&$request,$attachTransport):array{
    $attachPaid++;
    if($attachPaid===1){
        check(in_array('attach_task',array_column($options['tools'],'name'),true),'model can discover cross-turn attachment');
        $name='attach_task';$args=['device_reference'=>$device,'task_id'=>$attachReceipt['action_id']];$call=['name'=>$name,'arguments'=>$args];
        $fake=static fn(array $receipt)=>static fn()=>['status'=>200,'body'=>json_encode(['ok'=>true,'contract'=>PORTAL_DESKTOP_CONTEXT,'result'=>$receipt])];
        foreach([['contract'=>'invalid'],['device_id'=>$attachReceipt['device_id']+1],['execution_generation'=>'1']] as $bad){
            try{portal_westy_recovery_tool($pdo,$a,$call,$request['operation'],$fake(array_replace($attachReceipt,$bad)));check(false,'malformed attachment accepted');}
            catch(PortalWestyException $error){check($error->reason==='tool_invalid','malformed attachment rejected before persistence');}
        }
        $unknown=portal_westy_recovery_tool($pdo,$a,$call,$request['operation'],$fake(array_replace($attachReceipt,['state'=>'unknown'])));
        check($unknown['state']==='unknown'&&!isset($unknown['process_id'])&&!isset($unknown['attached']),'unknown execution cannot acquire a running-process handle');
        check(portal_westy_terminal_processes(portal_westy_run_find($pdo,$scope,$request['operation']))===[],'invalid and unknown results leave no ownership records');
        try{portal_westy_recovery_tool($pdo,$b,$call,$request['operation'],$attachTransport);check(false,'other customer attached run');}
        catch(PortalWestyException $error){check($error->reason==='stopped','other customer cannot use current run attachment');}
    }elseif($attachPaid===2){
        $last=json_decode(end($messages)['content'][0]['text'],true)['untrusted_result'];$attachedHandle=$last['process_id'];
        check($last['attached']===true&&$last['state']==='running'&&!isset($last['run_id'])&&!isset($last['conversation_id'])&&!isset($last['session_id']),'attachment exposes actual running output and an opaque handle only');
        $again=portal_westy_recovery_tool($pdo,$a,['name'=>'attach_task','arguments'=>['device_reference'=>$device,'task_id'=>$attachReceipt['action_id']]],$request['operation'],$attachTransport);
        check($again['process_id']===$attachedHandle,'reattaching exact live task reuses the same handle');
        $current=portal_westy_run_find($pdo,$scope,$request['operation']);
        $read=portal_westy_terminal_pending($pdo,$a+['authorized_task'=>$request['message']],['id'=>'read_attached','name'=>'read_process','arguments'=>['process_id'=>$attachedHandle,'after_sequence'=>1]],$request['operation']);
        check($read['input']['run_id']===$attachReceipt['run_id']&&$read['input']['conversation_id']===$attachReceipt['conversation_id'],'attached reads address original process identity');
        $other=$current;$other['operation_key']=bin2hex(random_bytes(16));
        try{portal_westy_terminal_process($other,$attachedHandle);check(false,'attached handle crossed owner run');}catch(PortalWestyException){check(true,'attached handle remains owned by the current run');}
        $name='write_stdin';$args=['process_id'=>$attachedHandle,'chars'=>"new turn input\n"];
    }else{
        check(str_contains(json_encode($messages),'new turn input acknowledged'),'new turn sees the real acknowledged input output');
        $emit('The existing synthetic process acknowledged the new input.');return $fixtureDone();
    }
    $id='attach_call_'.$attachPaid;$replay=['role'=>'provider','output'=>[['type'=>'function_call','call_id'=>$id,'name'=>$name,'arguments'=>json_encode($args)]]];
    foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
    return ['ok'=>true,'tool_calls'=>[['id'=>$id,'name'=>$name,'arguments'=>$args]],'continuation'=>$replay,
        'usage'=>['input'=>100,'cached_input'=>0,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20]];
};
$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$request['message']='Provide the requested synthetic input to my still-running process.';
$attachMessage=static fn(array $r)=>portal_westy_message($pdo,$a,$r,$attachProvider,static fn()=>$a,transport:$attachTransport,aiResolver:$resolver);
$attachMessage($request);$run=portal_westy_run_find($pdo,$scope,$request['operation']);
check($attachPaid===2&&$run['state']==='waiting','attached input follows the existing durable wait path');
$attachMessage(['action'=>'run_resume','operation'=>$request['operation'],'conversation'=>$run['conversation_id'],'sequence'=>1]);
check($attachPaid===3&&portal_westy_run_find($pdo,$scope,$request['operation'])['state']==='complete','cross-turn input completes without restarting prior work');
portal_westy_run_stop($pdo,$a,$scope,$request['operation'],$attachTransport);
check($attachReceipt['state']==='cancelled','Stop addresses the attached original process after inference ends');
$attachPaid=0;$attachReceipt['state']='running';$attachStopRace=true;
$request['operation']='f1'.sprintf('%08x',time()).bin2hex(random_bytes(11));$attachMessage($request);
$run=portal_westy_run_find($pdo,$scope,$request['operation']);
check($run['state']==='stopped'&&portal_westy_terminal_processes($run)===[],'Stop during attachment wins before handle persistence');
