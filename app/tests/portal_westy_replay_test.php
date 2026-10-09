<?php
/** Actual replay serializer; synthetic data only, no bootstrap, database or transport. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_westy_runs.php';

$replayChecks=0;
$assertReplay=static function(bool $ok,string $message)use(&$replayChecks):void{
    $replayChecks++;if(!$ok)throw new RuntimeException($message);
};
$receipts=[
    ['state'=>'executed','action_id'=>str_repeat('a',32),'reason'=>'application_started_discover_window'],
    ['state'=>'executed','action_id'=>str_repeat('b',32),'reason'=>'observation_unavailable'],
    ['state'=>'executed','reason'=>'observation_unavailable','action_id'=>str_repeat('d',32),'action_reason'=>'text_not_confirmed'],
    ['state'=>'executed','observation'=>['observation_id'=>str_repeat('e',32),'available'=>true,'reason'=>null],
        'action_id'=>str_repeat('f',32),'action_reason'=>'text_mismatch'],
    ['state'=>'refused','action_id'=>null,'reason'=>'controller_surface'],
    ['state'=>'unknown','action_id'=>str_repeat('c',32),'reason'=>'connection_unknown','retry_allowed'=>false],
];
foreach($receipts as $receipt)foreach([null,['elements'=>[['name'=>'PRIVATE_UIA']], 'browser_url'=>'PRIVATE_URL']] as $observation){
    $messages=[['role'=>'tool','call_id'=>'receipt','content'=>[
        ['type'=>'text','text'=>json_encode(['result'=>$receipt,'untrusted_observation'=>$observation],JSON_THROW_ON_ERROR)],
        ['type'=>'image','media_type'=>'image/png','data'=>'PRIVATE_PIXELS'],
    ]]];
    $wire=portal_westy_run_replay($messages);$restored=portal_westy_run_restore($wire);
    $saved=json_decode($restored[0]['content'][0]['text'],true);
    $assertReplay(($saved['result']??null)===$receipt,'replay retains the exact durable action receipt: '.$receipt['state']);
    $assertReplay(!str_contains($wire,'PRIVATE_')&&!array_key_exists('untrusted_observation',$saved),'replay removes transient evidence, including a null envelope');
    $assertReplay(($saved['observation_status']??null)==='expired'&&is_string($saved['guidance']??null),'replay asks for fresh evidence without changing action state');
    $assertReplay(portal_westy_run_replay($restored)===$wire,'replay redaction is stable across repeated waits');
}
foreach(['{"untrusted_observation":"PRIVATE_UIA"}', '{"result":{},"untrusted_observation":"PRIVATE_UIA",',
    '{"result":"PRIVATE_UIA","untrusted_observation":null}'] as $invalid){
    $wire=portal_westy_run_replay([['role'=>'tool','content'=>[['type'=>'text','text'=>$invalid]]]]);
    $assertReplay(!str_contains($wire,'PRIVATE_')&&str_contains($wire,'expired'),'malformed observation envelope cannot retain private contents');
}
$ordinary=[['role'=>'tool','call_id'=>'plain','content'=>[['type'=>'text','text'=>'{"state":"completed","stdout":"synthetic output"}']]]];
$assertReplay(portal_westy_run_restore(portal_westy_run_replay($ordinary))===$ordinary,'ordinary shell receipt remains unchanged');
$provider=[['role'=>'provider','output'=>[(object)['type'=>'tool_use','input'=>(object)[]]]]];
$restored=portal_westy_run_restore(portal_westy_run_replay($provider));
$assertReplay(is_object($restored[0]['output'][0]->input),'provider empty argument objects retain their JSON type');
echo "PASS replay: $replayChecks assertions\n";
