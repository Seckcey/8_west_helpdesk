<?php
/** Reviewed catalog compatibility; synthetic signed responses and SSE only, no database or provider calls. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../lib/portal_westy_tenant_ai.php';
$checks=0;
function cfg(string $key,mixed $default=null):mixed{
    return $key==='tenant_ai'?['enabled'=>true,'service_secret'=>str_repeat('a',64)]:$default;
}
function check(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function refuses(callable $run):void{try{$run();}catch(InvalidArgumentException){check(true,'invalid selection rejected');return;}throw new RuntimeException('Expected invalid selection');}
$luna=westy_tenant_ai_selection('openai','gpt-6-luna','low');
check($luna===['provider'=>'openai','model'=>'gpt-6-luna','effort'=>'low','catalog'=>'2026-10-04.1'],'existing selection and wire catalog unchanged');
$sol=westy_tenant_ai_selection('openai','gpt-6.1-sol','medium');
check($sol['catalog']===$luna['catalog'] && WESTY_TENANT_AI_CATALOG_REVIEW==='2026-11-20.1','review marker is separate from credential compatibility');
$solLimits=westy_tenant_ai_catalog()['openai']['models']['gpt-6.1-sol'];
check($solLimits['max_input_tokens']===200000 && $solLimits['max_output_tokens']===16000,'reviewed Sol limits cover the portal input and output allowances');
foreach(['gpt-6.1','gpt-6.1-sol-latest','unreviewed-model'] as $model)refuses(fn()=>westy_tenant_ai_selection('openai',$model,'medium'));
refuses(fn()=>westy_tenant_ai_selection('openai','gpt-6.1-sol','ultra'));
check(westy_tenant_ai_cost($sol,['input'=>200,'cached_input'=>60,'cache_write'=>40,'cache_write_1h'=>0,'output'=>30])===806,'Sol receipt uses reviewed standard input/cache/output rates');
check(westy_tenant_ai_reserve($sol,1000,100)===3500,'Sol reserves the maximum billable input rate');
check(westy_tenant_ai_cost($sol,['input'=>200,'cached_input'=>60,'cache_write'=>40,'cache_write_1h'=>0])===null,'missing usage remains unknown');
check(westy_tenant_ai_cost(westy_tenant_ai_selection('anthropic','claude-sonnet-5-5','medium'),
    ['input'=>0,'cached_input'=>1000000,'cache_write'=>0,'cache_write_1h'=>0,'output'=>0])===100000,'reviewed Sonnet cached-input estimate');
$body=westy_tenant_ai_body($sol,'Synthetic system',[['role'=>'user','content'=>[['type'=>'text','text'=>'Synthetic question']]]],
    ['stream'=>true,'tools'=>[['name'=>'desktop_observe','description'=>'Observe authorized desktop','input_schema'=>['type'=>'object','properties'=>new stdClass()]]],
     'schema'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false]]);
check($body['model']==='gpt-6.1-sol' && $body['reasoning']['effort']==='medium','Responses exact API selection');
check($body['stream']===true && $body['tools'][0]['type']==='function' && $body['text']['format']['type']==='json_schema','streaming typed tools and structured output supported');
check($body['store']===false && $body['service_tier']==='default' && $body['parallel_tool_calls']===false,'existing transport policy retained');

$fixture=['status'=>'active','revision'=>4,'provider'=>'openai','model'=>'gpt-6-luna','effort'=>'low','credential_version'=>1,'catalog'=>'2026-10-04.1'];
$lastRequest=null;
$transport=static function(array $headers,string $body)use(&$fixture,&$lastRequest):array{
    $lastRequest=$body;
    $request=json_decode($body,true,16,JSON_THROW_ON_ERROR);$parsed=[];
    foreach($headers as $header){[$name,$value]=explode(': ',$header,2);$parsed[$name]=$value;}
    $data=['version'=>1,'app'=>'safeharbor','local_tenant_key'=>'17','tenant_id'=>99,'tenant_slug'=>'synthetic-msp']+$fixture;
    if($request['action']==='resolve')$data['api_key']='sk-'.str_repeat('synthetic',8);
    $raw=json_encode($data,JSON_THROW_ON_ERROR);
    return ['status'=>200,'body'=>$raw,'signature'=>westy_tenant_ai_signature('response',$parsed['X-8W-AI-Timestamp'],$parsed['X-8W-AI-Nonce'],$raw,str_repeat('a',64),200)];
};
$resolved=safeharbor_tenant_ai_resolve(17,'status',4,$transport);
check($lastRequest==='{"version":1,"app":"safeharbor","local_tenant_key":"17","action":"status","expected_revision":4}','original no-role status request bytes');
check($resolved['status']==='active' && $resolved['model']==='gpt-6-luna' && $resolved['revision']===4,'existing signed revision 4 resolves unchanged');
check(!isset($resolved['api_key']),'status response cannot expose a key');
$fixture['model']='gpt-6.1-sol';$fixture['effort']='medium';
$resolved=safeharbor_tenant_ai_resolve(17,'resolve',4,$transport);
check($lastRequest==='{"version":1,"app":"safeharbor","local_tenant_key":"17","action":"resolve","expected_revision":4}','original no-role resolve request bytes');
check($resolved['status']==='active' && $resolved['model']==='gpt-6.1-sol','new model requires signed ID selection');
check(safeharbor_tenant_ai_resolve(18,'resolve',4,$transport)['status']==='unavailable','cross-tenant response rejected');
check(safeharbor_tenant_ai_resolve(17,'resolve',5,$transport)['status']==='revision_changed','stale revision rejected');
$baseStatus=safeharbor_tenant_ai_resolve(17,'status',4,$transport);
check(!array_key_exists('spend_limit',$baseStatus) && !array_key_exists('spend_limit',$resolved),'missing spend choice keeps the host budget');
$publicStatus=['status'=>'active','revision'=>4,'provider'=>'openai','model'=>'gpt-6.1-sol','effort'=>'medium','credential_version'=>1];
foreach([[],['reviewer','scribe']] as $overrides){
    $fixture['role_overrides']=$overrides;
    $status=safeharbor_tenant_ai_resolve(17,'status',4,$transport);
    $selection=safeharbor_tenant_ai_resolve(17,'resolve',4,$transport);
    check($status===$baseStatus && $selection===$resolved,'signed role list leaves both base replies identical to the older ID replies');
    check(westy_tenant_ai_public_status($status)===$publicStatus,'portal publishes the original base status fields');
    check(portal_westy_ai_same_selection($baseStatus,$selection) && portal_westy_ai_display($status)===['model'=>'GPT-6.1 Sol','effort'=>'Medium'],
        'portal refresh and display use the base selection');
}
$ai=westy_tenant_ai_engine_config($baseStatus);
$ai['tenant_ai_refresh']=static fn(string $action,int $revision):array=>safeharbor_tenant_ai_resolve(17,$action,$revision,$transport);
$dispatches=0;
$dispatch=static function(array $current)use(&$dispatches,$resolved):array{
    ++$dispatches;
    return ['ok'=>$current===$resolved];
};
check(westy_tenant_ai_with_context($ai,$dispatch)===['ok'=>true] && $dispatches===1,'staff completion survives a change from older to newer ID during refresh');
$fixture['credential_version']=2;
check(westy_tenant_ai_with_context($ai,$dispatch)['ok']===false && $dispatches===1,'staff refresh still rejects a base credential rotation before dispatch');
$fixture['credential_version']=1;
foreach(['status','resolve'] as $action){
    $fixture['spend_limit']='none';
    $unlimited=safeharbor_tenant_ai_resolve(17,$action,4,$transport);
    check(($unlimited['spend_limit']??null)==='none','signed unlimited spend choice survives '.$action);
    $fixture['spend_limit']='other';
    check(!array_key_exists('spend_limit',safeharbor_tenant_ai_resolve(17,$action,4,$transport)),'other spend choices keep the host budget for '.$action);
}
unset($fixture['spend_limit']);
$fixture['role_overrides']=['unknown_role'];
check(safeharbor_tenant_ai_resolve(17,'status',4,$transport)===['status'=>'unavailable'],'SDK validates the signed list before the host omits it');
unset($fixture['role_overrides']);
$fixture['model']='unreviewed-model';
check(safeharbor_tenant_ai_resolve(17,'resolve',4,$transport)['status']==='unavailable','signed unknown ID does not become executable');

$visible='';$alive=0;
$parser=new WestyTenantAiStream($sol,static function(string $text)use(&$visible):void{$visible.=$text;},static function()use(&$alive):void{++$alive;});
$frame=static fn(array $event):string=>'data: '.json_encode($event,JSON_THROW_ON_ERROR)."\n\n";
$delta=$frame(['type'=>'response.output_text.delta','delta'=>'Synthetic reply']);
$parser->feed(substr($delta,0,9));$parser->feed(substr($delta,9));
$response=['status'=>'completed','model'=>'gpt-6.1-sol','output'=>[
    ['type'=>'reasoning','id'=>'synthetic-reasoning','encrypted_content'=>'synthetic-replay-only'],
    ['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'Synthetic reply']]]],
    'usage'=>['input_tokens'=>100,'output_tokens'=>20,'input_tokens_details'=>['cached_tokens'=>40,'cache_write_tokens'=>0]]];
$parser->feed($frame(['type'=>'response.completed','response'=>$response]));
$result=$parser->result();
check($result['ok']===true && $result['text']==='Synthetic reply' && $visible==='Synthetic reply' && $alive===2,'Sol streams visible text through existing parser and authority callback');
check(!str_contains($visible,'synthetic-replay-only'),'reasoning continuation stays off the visible stream');
check($result['usage']===['input'=>60,'cached_input'=>40,'cache_write'=>0,'cache_write_1h'=>0,'output'=>20],'stream receipt retains separate billable categories');
check(westy_tenant_ai_cost($sol,$result['usage'])===324,'streamed Sol receipt cost matches reviewed rates');
$incomplete=new WestyTenantAiStream($sol,static function(string $text):void{},static function():void{});
$incomplete->feed($frame(['type'=>'response.output_text.delta','delta'=>'Partial']));
check($incomplete->result()===['ok'=>false,'reason'=>'provider_incomplete','usage'=>null],'partial stream remains unknown without retry');
$failed=new WestyTenantAiStream($sol,static function(string $text):void{},static function():void{});
$failed->feed($frame(['type'=>'response.failed','response'=>$response]));
check($failed->result()['ok']===false && $failed->result()['usage']===$result['usage'],'failed terminal event retains known paid usage without delivery');

$root=dirname(__DIR__,2);
$manifest=json_decode(file_get_contents($root.'/docs/tenant-ai-vendor-manifest.json'),true,32,JSON_THROW_ON_ERROR);
check($manifest['upstream']==='Seckcey/8_west_westy' && $manifest['commit']==='8db0946f705dd85f31cc5ecacde8a3c9000b2b9d'
    && $manifest['contract']===1 && $manifest['catalog']==='2026-10-04.1','reviewed upstream commit, wire contract and credential catalog');
$upstreamFiles=[
    'app/lib/tenant_ai/tenant_ai_contract.php'=>'2c6da6b49409fbafbedb35abbd2a8996a367f59cd31e57e9aafbd3ed5e3335e0',
    'app/lib/tenant_ai/tenant_ai_client.php'=>'f0a56ba97f91f9bbb0285385ba51e77f310184c65f06724f2834f2034005f830',
    'app/lib/tenant_ai/tenant_ai_provider.php'=>'db55a1fdd400698b709c498ff5e497189ca609e9e3c88f011afc396925083268',
    'app/lib/tenant_ai/tenant_ai_stream.php'=>'8b8640316050d88dc5c5c4af5b39ad6ab4053bd3d7a265edc096d07c0d6b60f0',
    'app/lib/tenant_ai/tenant_ai_engine.php'=>'f6f8afd4f02838a801f2c4e4e62db6de4ea5b0ff3281666c9dbbc0eae1aec009',
];
$providerPath='app/lib/tenant_ai/tenant_ai_provider.php';
$providerHash='19ac3e43d96ef2fe011de556a3f06fdd9ba15b683a016e2472884e31c0e5be0a';
check($manifest['upstream_files']==$upstreamFiles,'all five upstream hashes match the reviewed commit');
check(!array_key_exists('local_patch',$manifest) && !array_key_exists('client_spend_limit_patch',$manifest),'catalog and spend-limit changes are upstream');
$providerPatch=$manifest['provider_input_count_patch']??null;
check(is_array($providerPatch) && $providerPatch['id']==='westy-input-count-2026-10-11.1'
    && $providerPatch['files']===[$providerPath] && $providerPatch['sha256']===$providerHash,'reviewed provider input-count patch is recorded');
$installedFiles=array_replace($upstreamFiles,[$providerPath=>$providerHash]);
check($manifest['files']==$installedFiles,'only the provider differs from the upstream files');
foreach($installedFiles as $path=>$expected){
    $bytes=str_replace("\r\n","\n",file_get_contents($root.'/'.$path));
    check(hash('sha256',$bytes)===$expected,'actual canonical LF hash '.$path);
}
echo "tenant_ai_catalog_test: $checks checks passed\n";

// Text over the conservative byte bound is admitted only by the existing actual count, against the same limit.
$countSelection=$luna;
$countRequests=[];$countReply=null;
$counter=static function(array $selection,array $body)use(&$countRequests,&$countReply):array{$countRequests[]=$body;return $countReply;};
$refusal=static function(callable $run):string{try{$run();}catch(Throwable $error){return $error::class.':'.$error->getMessage();}return 'admitted';};
$catalogText=json_encode(['tools'=>array_fill(0,300,['name'=>'synthetic_tool','description'=>str_repeat('Synthetic catalog text. ',10),
    'inputSchema'=>['type'=>'object','properties'=>['pid'=>['type'=>'integer']]]])],JSON_THROW_ON_ERROR);
$largeBodyFor=static fn(array $selection):array=>westy_tenant_ai_body($selection,'Synthetic system',[['role'=>'user','content'=>[['type'=>'text','text'=>$catalogText]]]],
    ['stream'=>true,'max_output_tokens'=>1200,'tools'=>[['name'=>'desktop_cua','description'=>'Synthetic relay','input_schema'=>['type'=>'object','properties'=>new stdClass()]]]]);
$largeBody=$largeBodyFor($countSelection);
check(strlen(json_encode($largeBody,JSON_THROW_ON_ERROR))+2048>66048,'synthetic text/tool JSON exceeds the conservative byte bound');
$countReply=['status'=>200,'body'=>json_encode(['input_tokens'=>50000],JSON_THROW_ON_ERROR)];
check(westy_tenant_ai_input_bound($countSelection,$largeBody,$counter,66048)===52500,'large text under its actual count is admitted with the existing 5% margin');
check(count($countRequests)===1 && array_keys($countRequests[0])===['model','instructions','input','reasoning','tools','parallel_tool_calls']
    && $countRequests[0]['input']===$largeBody['input'],'one count request carries the full input and only the existing filtered fields');
$countReply=['status'=>200,'body'=>json_encode(['input_tokens'=>63000],JSON_THROW_ON_ERROR)];
check($refusal(fn()=>westy_tenant_ai_input_bound($countSelection,$largeBody,$counter,66048))==='RuntimeException:input_limit','real count over the same limit after its margin is refused');
foreach([[['status'=>503,'body'=>'{}'],'RuntimeException:input_count_unavailable'],[['status'=>200,'body'=>null],'RuntimeException:input_count_unavailable'],
    [['status'=>200,'body'=>'not json'],'JsonException:Syntax error'],[['status'=>200,'body'=>'{"input_tokens":"many"}'],'RuntimeException:input_limit']] as [$countCase,$expectedRefusal]){
    $countReply=$countCase;
    check($refusal(fn()=>westy_tenant_ai_input_bound($countSelection,$largeBody,$counter,66048))===$expectedRefusal,'unavailable or malformed count is refused as before: '.$expectedRefusal);
}
$smallBody=westy_tenant_ai_body($countSelection,'Synthetic system',[['role'=>'user','content'=>[['type'=>'text','text'=>'Synthetic question']]]],['stream'=>true]);
$countRequests=[];
check(westy_tenant_ai_input_bound($countSelection,$smallBody,$counter,66048)===strlen(json_encode($smallBody,JSON_THROW_ON_ERROR))+2048
    && $countRequests===[],'small text keeps the byte bound and makes no count request');
$mediaBody=westy_tenant_ai_body($countSelection,'Synthetic system',[['role'=>'user','content'=>[['type'=>'text','text'=>'Synthetic screenshot'],
    ['type'=>'image','media_type'=>'image/png','data'=>base64_encode('SYNTHETIC_PNG')]]]],['stream'=>true]);
$countReply=['status'=>200,'body'=>json_encode(['input_tokens'=>1000],JSON_THROW_ON_ERROR)];
check(westy_tenant_ai_input_bound($countSelection,$mediaBody,$counter,66048)===3048 && count($countRequests)===1
    && $countRequests[0]['input']===$mediaBody['input'],'small media is still counted by the provider with its image');
$solBody=$largeBodyFor($sol);
$countRequests=[];
$countReply=['status'=>200,'body'=>'{"input_tokens":50000}'];
check(westy_tenant_ai_input_bound($sol,$solBody,$counter,PORTAL_WESTY_AI_MAX_INPUT)===52500
    && count($countRequests)===1 && $countRequests[0]['model']==='gpt-6.1-sol','local provider patch counts large Sol input with the selected model');
$countReply=['status'=>200,'body'=>'{"input_tokens":63000}'];
check($refusal(fn()=>westy_tenant_ai_input_bound($sol,$solBody,$counter,PORTAL_WESTY_AI_MAX_INPUT))==='RuntimeException:input_limit',
    'Sol uses the lower portal limit after applying the count margin');
echo "tenant_ai_catalog_test: $checks checks passed including input-count fallback\n";
