<?php
/** Real ledger and customer authority; synthetic mutable MSP authority/provider only. */
declare(strict_types=1);
require __DIR__.'/portal_devices_mysql_test.php';
// Deterministic byte/time boundaries without sleeping or replacing authorization.
(static function():void{
    $now=0;$chunks=[];
    $buffer=new PortalWestyTextBuffer(static function(string $text)use(&$chunks):void{$chunks[]=$text;},
        static function()use(&$now):int{return $now;});
    $buffer->append('First 🌊');
    check($chunks===['First 🌊'],'first complete UTF-8 callback is immediate');
    $buffer->append(str_repeat('é',63));$buffer->append('🌊');
    check($chunks===['First 🌊',str_repeat('é',63)],'byte limit flushes before overflow without splitting callbacks');
    $now=99999999;check(!$buffer->flushDue(),'pending callback remains buffered below 100 ms');
    $now=100000000;check($buffer->flushDue() && $chunks[2]==='🌊','progress flushes pending text at 100 ms');
    $buffer->append('pending');$buffer->append(str_repeat('界',100));
    check(array_slice($chunks,-2)===['pending',str_repeat('界',100)],'oversized callback passes intact after earlier text');
    $buffer->append(str_repeat('a',64));$buffer->append(str_repeat('b',64));
    check(end($chunks)===str_repeat('a',64).str_repeat('b',64),'exact 128-byte boundary flushes in order');
    $buffer->append('discarded');$buffer->discard();$buffer->flush();
    check(!str_contains(implode('',$chunks),'discarded'),'discard never emits pending text');
    $refused=0;$buffer=new PortalWestyTextBuffer(static function()use(&$refused):void{
        if(++$refused>1)throw new RuntimeException('Refused flush');
    });
    $buffer->append('first');$buffer->append('pending');
    try{$buffer->flush();}catch(RuntimeException){}
    $buffer->flush();check($refused===2,'refused flush cannot be replayed');
})();
$settings['portal_westy']=['enabled'=>true,'ai_enabled'=>true,'tools_enabled'=>false,
    'hourly_limit'=>30,'daily_limit'=>500,'monthly_microusd'=>5000000];
$selection=['version'=>1,'app'=>'safeharbor','local_tenant_key'=>'1','tenant_id'=>100,
    'tenant_slug'=>'provider-1','status'=>'active','revision'=>1,'credential_version'=>1,
    'api_key'=>'sk-synthetic-delivery-no-network']+westy_tenant_ai_selection('openai','gpt-6-luna','low');
$receipt=$pdo->prepare('SELECT t.reply_json,t.state,a.charged_microusd,a.reserve_microusd,a.usage_json
    FROM portal_westy_turns t JOIN portal_westy_ai_attempts a ON a.turn_id=t.id WHERE t.operation_key=?');
foreach(['revision','removed','unavailable','exception'] as $change)foreach(['stream','buffered','final_flush','finish'] as $phase){
    $current=$selection;$calls=0;$events=[];$restored=false;$returned=false;$postStatus=0;
    $changeAuthority=static function()use($change,$selection,&$current):void{
        $current=match($change){'revision'=>array_replace($selection,['revision'=>2]),
            'removed'=>['status'=>'needs_setup','revision'=>2],
            'unavailable'=>['status'=>'unavailable'],default=>null};
    };
    $resolver=static function(int $tenant,string $action,?int $revision)use(&$current,&$returned,&$postStatus,$phase,$changeAuthority):array{
        check($tenant===1,'delivery authority resolves the server-owned provider MSP');
        // Pass both successful-round checks, then revoke exactly at the final
        // output guard. A cached round check must not authorize the flush.
        if($returned && $action==='status' && ++$postStatus===3 && $phase==='final_flush')$changeAuthority();
        if($current===null)throw new RuntimeException('Synthetic authority outage');
        return $current;
    };
    $request=['operation'=>'f1'.sprintf('%08x',time()).bin2hex(random_bytes(11)),
        'message'=>'Synthetic in-flight AI authority check.',
        'conversation'=>portal_westy_state($pdo,$a,aiResolver:$resolver)['conversation']];
    $provider=static function($ai,$system,$messages,$options,$emit,$alive)use(&$calls,&$returned,$phase,$changeAuthority,&$current,$selection):array{
        ++$calls;$emit('Previously authorized text.');
        if(in_array($phase,['buffered','final_flush'],true))$emit('FORBIDDEN_AFTER_AI_CHANGE');
        if(in_array($phase,['stream','buffered'],true)){
            $changeAuthority();
            // Exercise sticky cancellation when the connection returns while
            // the delivery exception unwinds, before accounting finishes.
            try{
                if($phase==='stream')$emit(str_repeat('FORBIDDEN_AFTER_AI_CHANGE',8));
                else{usleep(110000);$alive();}
            }
            finally{$current=$selection;}
        }
        $returned=true;
        return ['ok'=>true,'tool_calls'=>[],'usage'=>['input'=>900,'cached_input'=>0,
            'cache_write'=>0,'cache_write_1h'=>0,'output'=>160]];
    };
    $reauthorize=static function()use($pdo,$a,$phase,$changeAuthority,&$restored):array{
        // Finish invokes this inside its accounting transaction, after the
        // provider's known usage and final post-round check have returned.
        if($phase==='finish' && $pdo->inTransaction()){$changeAuthority();$restored=true;}
        return $a;
    };
    $emit=static function($event,$data)use(&$events):void{if($event==='delta')$events[]=$data['text'];};
    try{portal_westy_message($pdo,$a,$request,$provider,$reauthorize,$emit,aiResolver:$resolver);$denied=false;}
    catch(PortalWestyException $error){$denied=$error->reason==='ai_changed';}
    check($denied,'changed AI authority returns typed failure: '.$change.'/'.$phase);
    $receipt->execute([$request['operation']]);$row=$receipt->fetch();$usage=json_decode($row['usage_json'],true);
    check($calls===1 && $events===['Previously authorized text.']
        && json_decode($row['reply_json'],true)['reply']==='Previously authorized text.'
        && !str_contains($row['reply_json'],'FORBIDDEN_AFTER_AI_CHANGE') && $row['state']==='unavailable',
        'only text delivered with valid authority remains; no replay: '.$change.'/'.$phase);
    check(in_array($phase,['stream','buffered'],true)
        ? $usage['known']===false && $row['charged_microusd']===$row['reserve_microusd']
        : ($phase==='final_flush' || $restored) && $usage['known']===true && (int)$row['charged_microusd']===170
            && (int)$row['charged_microusd']<(int)$row['reserve_microusd'],
        'unknown reservation or exact paid receipt survives authority loss: '.$change.'/'.$phase);
    $current=$selection;
    portal_westy_message($pdo,$a,$request,$provider,static fn()=>$a,$emit,aiResolver:$resolver);
    check($calls===1,'restored authority cannot retry the same operation: '.$change.'/'.$phase);
}
foreach(['failed_result','exception'] as $failure){
    $events=[];$calls=0;
    $resolver=static fn()=> $selection;
    $request=['operation'=>'f1'.sprintf('%08x',time()).bin2hex(random_bytes(11)),
        'message'=>'Synthetic failed provider round.',
        'conversation'=>portal_westy_state($pdo,$a,aiResolver:$resolver)['conversation']];
    $provider=static function($ai,$system,$messages,$options,$emit,$alive)use($failure,&$calls):array{
        ++$calls;$emit('Visible first.');$emit('UNFLUSHED_FAILED_ROUND');
        if($failure==='exception')throw new RuntimeException('Synthetic provider failure');
        return ['ok'=>false,'reason'=>'provider_unavailable','usage'=>['input'=>900,'cached_input'=>0,
            'cache_write'=>0,'cache_write_1h'=>0,'output'=>160]];
    };
    $emit=static function($event,$data)use(&$events):void{if($event==='delta')$events[]=$data['text'];};
    portal_westy_message($pdo,$a,$request,$provider,static fn()=>$a,$emit,aiResolver:$resolver);
    $receipt->execute([$request['operation']]);$row=$receipt->fetch();$usage=json_decode($row['usage_json'],true);
    check($events===['Visible first.'] && json_decode($row['reply_json'],true)['reply']==='Visible first.'
        && $row['state']==='unavailable','failed provider round discards pending text: '.$failure);
    check($failure==='failed_result' ? $usage['known']===true && (int)$row['charged_microusd']===170
        : $usage['known']===false && $row['charged_microusd']===$row['reserve_microusd'],
        'failure retains known receipt or unknown reservation: '.$failure);
    portal_westy_message($pdo,$a,$request,$provider,static fn()=>$a,$emit,aiResolver:$resolver);
    check($calls===1,'failed round is never automatically replayed: '.$failure);
}
echo 'PASS tenant AI delivery boundary: '.$checks." checks\n";
