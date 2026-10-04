<?php
/** Real ledger and customer authority; synthetic mutable MSP authority/provider only. */
declare(strict_types=1);
require __DIR__.'/portal_devices_mysql_test.php';
$settings['portal_westy']=['enabled'=>true,'ai_enabled'=>true,'tools_enabled'=>false,
    'hourly_limit'=>30,'daily_limit'=>500,'monthly_microusd'=>5000000];
$selection=['version'=>1,'app'=>'safeharbor','local_tenant_key'=>'1','tenant_id'=>100,
    'tenant_slug'=>'provider-1','status'=>'active','revision'=>1,'credential_version'=>1,
    'api_key'=>'sk-synthetic-delivery-no-network']+westy_tenant_ai_selection('openai','gpt-6-luna','low');
$receipt=$pdo->prepare('SELECT t.reply_json,t.state,a.charged_microusd,a.reserve_microusd,a.usage_json
    FROM portal_westy_turns t JOIN portal_westy_ai_attempts a ON a.turn_id=t.id WHERE t.operation_key=?');
foreach(['revision','removed','unavailable','exception'] as $change)foreach(['stream','finish'] as $phase){
    $current=$selection;$calls=0;$events=[];$restored=false;
    $resolver=static function(int $tenant,string $action,?int $revision)use(&$current):array{
        check($tenant===1,'delivery authority resolves the server-owned provider MSP');
        if($current===null)throw new RuntimeException('Synthetic authority outage');
        return $current;
    };
    $changeAuthority=static function()use($change,$selection,&$current):void{
        $current=match($change){'revision'=>array_replace($selection,['revision'=>2]),
            'removed'=>['status'=>'needs_setup','revision'=>2],
            'unavailable'=>['status'=>'unavailable'],default=>null};
    };
    $request=['operation'=>'f1'.sprintf('%08x',time()).bin2hex(random_bytes(11)),
        'message'=>'Synthetic in-flight AI authority check.',
        'conversation'=>portal_westy_state($pdo,$a,aiResolver:$resolver)['conversation']];
    $provider=static function($ai,$system,$messages,$options,$emit,$alive)use(&$calls,$phase,$changeAuthority,&$current,$selection):array{
        ++$calls;$emit('Previously authorized text.');
        if($phase==='stream'){
            $changeAuthority();
            // Exercise sticky cancellation when the connection returns while
            // the delivery exception unwinds, before accounting finishes.
            try{$emit('FORBIDDEN_AFTER_AI_CHANGE');}
            finally{$current=$selection;}
        }
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
    check($phase==='stream'
        ? $usage['known']===false && $row['charged_microusd']===$row['reserve_microusd']
        : $restored && $usage['known']===true && (int)$row['charged_microusd']===170
            && (int)$row['charged_microusd']<(int)$row['reserve_microusd'],
        'unknown reservation or exact paid receipt survives authority loss: '.$change.'/'.$phase);
    $current=$selection;
    portal_westy_message($pdo,$a,$request,$provider,static fn()=>$a,$emit,aiResolver:$resolver);
    check($calls===1,'restored authority cannot retry the same operation: '.$change.'/'.$phase);
}
echo 'PASS tenant AI delivery boundary: '.$checks." checks\n";
