<?php
/** Fixed provider transports for an already-resolved tenant credential. Never log payloads. */
declare(strict_types=1);
require_once __DIR__.'/tenant_ai_contract.php';

/** Server-created content only; URLs and provider file IDs are deliberately unsupported. */
function westy_tenant_ai_content(array $parts,string $provider,string $role='user'): array
{
    $out=[];
    foreach($parts as $part){
        $type=$part['type']??null;
        if($type==='text' && is_string($part['text']??null)){
            $out[]=['type'=>$provider==='openai'?($role==='assistant'?'output_text':'input_text'):'text','text'=>$part['text']];
            continue;
        }
        if($role!=='user' || !in_array($type,['image','pdf'],true))throw new InvalidArgumentException('invalid_content');
        $mime=$part['media_type']??null;$data=$part['data']??null;
        if(!is_string($mime) || !is_string($data) || strlen($data)>8388608
            || ($type==='image' && !in_array($mime,['image/png','image/jpeg','image/webp'],true))
            || ($type==='pdf' && $mime!=='application/pdf') || base64_decode($data,true)===false)
            throw new InvalidArgumentException('invalid_content');
        if($provider==='openai'){
            $out[]=$type==='image'?['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.$data,'detail'=>'auto']
                :['type'=>'input_file','filename'=>'document.pdf','file_data'=>'data:application/pdf;base64,'.$data];
        }else{
            $out[]=['type'=>$type==='image'?'image':'document','source'=>['type'=>'base64','media_type'=>$mime,'data'=>$data]];
        }
    }
    if($out===[])throw new InvalidArgumentException('invalid_content');
    return $out;
}

/** Private replay records originate only from this parser, never from browser request bodies. */
function westy_tenant_ai_messages(array $messages,array $selection): array
{
    $provider=$selection['provider'];$out=[];
    if($messages===[] || count($messages)>100)throw new InvalidArgumentException('invalid_messages');
    foreach($messages as $message){
        if(($message['role']??null)==='provider'){
            foreach(['provider','model','effort','revision','credential_version'] as $field){
                if(!array_key_exists($field,$message) || $message[$field]!==($selection[$field]??null))
                    throw new InvalidArgumentException('conversation_configuration_changed');
            }
            if(!is_array($message['output']??null))throw new InvalidArgumentException('invalid_messages');
            if($provider==='openai')array_push($out,...$message['output']);
            else $out[]=['role'=>'assistant','content'=>$message['output']];
        }elseif(($message['role']??null)==='tool'){
            $id=$message['call_id']??null;
            if(!is_string($id) || preg_match('/\A[a-zA-Z0-9_-]{1,200}\z/D',$id)!==1)throw new InvalidArgumentException('invalid_tool_result');
            $content=westy_tenant_ai_content($message['content']??[],$provider);
            $out[]=$provider==='openai'?['type'=>'function_call_output','call_id'=>$id,'output'=>$content]
                :['role'=>'user','content'=>[['type'=>'tool_result','tool_use_id'=>$id,'content'=>$content,'is_error'=>($message['is_error']??false)===true]]];
        }else{
            $role=$message['role']??null;
            if(!in_array($role,['user','assistant'],true))throw new InvalidArgumentException('invalid_messages');
            $out[]=['role'=>$role,'content'=>westy_tenant_ai_content($message['content']??[],$provider,$role)];
        }
    }
    return $out;
}

function westy_tenant_ai_body(array $selection,string $system,array $messages,array $options=[]): array
{
    westy_tenant_ai_selection($selection['provider']??null,$selection['model']??null,$selection['effort']??null);
    $limit=$options['max_output_tokens']??4000;
    if(!is_int($limit) || $limit<1 || $limit>16000 || strlen($system)>100000)throw new InvalidArgumentException('invalid_budget');
    $provider=$selection['provider'];$input=westy_tenant_ai_messages($messages,$selection);
    if($provider==='openai'){
        $body=['model'=>$selection['model'],'instructions'=>$system,'input'=>$input,'max_output_tokens'=>$limit,
            'reasoning'=>['effort'=>$selection['effort']],'store'=>false,'background'=>false,'service_tier'=>'default',
            'include'=>['reasoning.encrypted_content']];
        if(isset($options['schema']))$body['text']=['format'=>['type'=>'json_schema','name'=>'westy_reply','strict'=>true,'schema'=>$options['schema']]];
    }else{
        $body=['model'=>$selection['model'],'system'=>$system,'messages'=>$input,'max_tokens'=>$limit,'service_tier'=>'standard_only'];
        if($selection['effort']!==''){
            $body['thinking']=['type'=>'adaptive'];$body['output_config']=['effort'=>$selection['effort']];
        }
        if(isset($options['schema']))$body['output_config']['format']=['type'=>'json_schema','schema'=>$options['schema']];
    }
    if(!empty($options['tools'])){
        if(!is_array($options['tools']) || count($options['tools'])>64)throw new InvalidArgumentException('invalid_tools');
        foreach($options['tools'] as $tool){
            if(!is_string($tool['name']??null) || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D',$tool['name'])!==1
                || !is_string($tool['description']??null) || !is_array($tool['input_schema']??null))throw new InvalidArgumentException('invalid_tools');
            $body['tools'][]=$provider==='openai'?['type'=>'function','name'=>$tool['name'],'description'=>$tool['description'],
                'parameters'=>$tool['input_schema'],'strict'=>false]:$tool;
        }
        if($provider==='openai')$body['parallel_tool_calls']=false;
        else $body['tool_choice']=['type'=>'auto','disable_parallel_tool_use'=>true];
    }
    if(($options['stream']??false)===true)$body['stream']=true;
    if(strlen(json_encode($body,JSON_THROW_ON_ERROR))>12582912)throw new InvalidArgumentException('input_limit');
    return $body;
}

/** Text uses a conservative byte bound. Media is counted by the selected provider,
 * including extracted PDF text/pages; compressed file size is never a token estimate. */
function westy_tenant_ai_input_bound(array $selection,array $body,?callable $counter=null,int $limit=200000): int
{
    if($limit<1 || $limit>200000)throw new RuntimeException('input_limit');
    westy_tenant_ai_selection($selection['provider']??null,$selection['model']??null,$selection['effort']??null);
    $limit=min($limit,westy_tenant_ai_catalog()[$selection['provider']]['models'][$selection['model']]['max_input_tokens']);
    $hasMedia=static function(mixed $value)use(&$hasMedia):bool {
        if(is_object($value))$value=get_object_vars($value);
        if(!is_array($value))return false;
        if(in_array($value['type']??null,['input_image','input_file','image','document'],true))return true;
        foreach($value as $child)if($hasMedia($child))return true;
        return false;
    };
    if(!$hasMedia($body))$bound=strlen(json_encode($body,JSON_THROW_ON_ERROR))+2048;
    else {
        $keys=$selection['provider']==='openai'
            ? ['model','input','instructions','tools','tool_choice','text','reasoning','parallel_tool_calls']
            : ['model','messages','system','tools','tool_choice','thinking','output_config'];
        $countBody=array_intersect_key($body,array_flip($keys));
        $response=($counter??static fn(array $s,array $b):array=>westy_tenant_ai_provider_http($s,$b,true))($selection,$countBody);
        if(($response['status']??0)!==200 || !is_string($response['body']??null))throw new RuntimeException('input_count_unavailable');
        $count=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR)['input_tokens']??null;
        if(!is_int($count) || $count<0 || $count>200000)throw new RuntimeException('input_limit');
        // Claude documents a small difference between estimate and final count.
        $bound=$count+max(2048,(int)ceil($count*.05));
    }
    if($bound>$limit)throw new RuntimeException('input_limit');
    return $bound;
}

/** Counts are disjoint. OpenAI input includes cache reads/writes; Anthropic input excludes them. */
function westy_tenant_ai_usage(string $provider,mixed $usage): ?array
{
    if(!is_array($usage))return null;
    $input=$usage['input_tokens']??null;$output=$usage['output_tokens']??null;
    $cached=$provider==='openai'?($usage['input_tokens_details']['cached_tokens']??null):($usage['cache_read_input_tokens']??0);
    $write=$provider==='openai'?($usage['input_tokens_details']['cache_write_tokens']??null):($usage['cache_creation_input_tokens']??0);
    $write1h=0;
    foreach([$input,$output,$cached,$write] as $n)if(!is_int($n) || $n<0 || $n>2000000)return null;
    if($provider==='openai'){
        if($cached+$write>$input)return null;$input-=$cached+$write;
    }elseif($write>0){
        $write1h=$usage['cache_creation']['ephemeral_1h_input_tokens']??null;
        $write5m=$usage['cache_creation']['ephemeral_5m_input_tokens']??null;
        if(!is_int($write1h) || !is_int($write5m) || $write1h<0 || $write5m<0 || $write1h+$write5m!==$write)return null;
        $write=$write5m;
    }
    return ['input'=>$input,'cached_input'=>$cached,'cache_write'=>$write,'cache_write_1h'=>$write1h,'output'=>$output];
}

function westy_tenant_ai_parse(array $selection,int $http,array $json,?array $replayOutput=null): array
{
    $error=['ok'=>false,'reason'=>match($http){401,403=>'credentials_refused',404=>'model_unavailable',429=>'quota_unavailable',default=>'provider_unavailable'},'usage'=>null];
    if($http!==200)return $error;
    $provider=$selection['provider'];$model=$json['model']??null;
    // A provider must return the selected exact model, not an unreviewed substitute.
    if($model!==$selection['model'])return ['ok'=>false,'reason'=>'provider_model_mismatch','usage'=>null];
    $usage=westy_tenant_ai_usage($provider,$json['usage']??null);
    $receipt=['usage'=>$usage,'provider'=>$provider,'model'=>$model,
        'cost_micro_usd'=>$usage===null?null:westy_tenant_ai_cost($selection,$usage)];
    $output=$provider==='openai'?($json['output']??null):($json['content']??null);
    if(!is_array($output) || ($provider==='openai' && ($json['status']??null)!=='completed')
        || ($provider==='anthropic' && !in_array($json['stop_reason']??null,['end_turn','tool_use'],true)))
        return ['ok'=>false,'reason'=>'provider_incomplete']+$receipt;
    $text='';$calls=[];
    try{
        foreach($output as $item){
            $type=$item['type']??null;
            if($provider==='openai' && $type==='message'){
                foreach($item['content']??[] as $part){
                    if(($part['type']??null)==='refusal')throw new RuntimeException('refused');
                    if(($part['type']??null)==='output_text')$text.=$part['text'];
                }
            }elseif($provider==='anthropic' && $type==='text')$text.=$item['text'];
            elseif(($provider==='openai' && $type==='function_call') || ($provider==='anthropic' && $type==='tool_use')){
                $id=$provider==='openai'?($item['call_id']??null):($item['id']??null);$name=$item['name']??null;
                $args=$provider==='openai'?json_decode($item['arguments']??'',true,32,JSON_THROW_ON_ERROR)
                    :json_decode(json_encode($item['input']??null,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);
                if(!is_string($id) || preg_match('/\A[a-zA-Z0-9_-]{1,200}\z/D',$id)!==1 || !is_string($name)
                    || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D',$name)!==1 || !is_array($args))throw new RuntimeException('invalid');
                $calls[]=['id'=>$id,'name'=>$name,'arguments'=>$args];
            }
        }
        if(strlen($text)>262144 || !mb_check_encoding($text,'UTF-8') || count($calls)>1 || ($text==='' && $calls===[]))throw new RuntimeException('invalid');
    }catch(Throwable){return ['ok'=>false,'reason'=>'provider_invalid']+$receipt;}
    // Dispatch arguments are normalized arrays; private replay retains the
    // provider's original JSON objects and arrays, including an empty input {}.
    $replay=['role'=>'provider','output'=>array_map(static fn(mixed $item):mixed=>is_object($item)?get_object_vars($item):$item,$replayOutput??$output)];
    foreach(['provider','model','effort','revision','credential_version'] as $field)$replay[$field]=$selection[$field]??null;
    return ['ok'=>true,'text'=>$text,'tool_calls'=>$calls,'continuation'=>$replay,'usage'=>$usage,
        'provider'=>$provider,'model'=>$model,'cost_micro_usd'=>$usage===null?null:westy_tenant_ai_cost($selection,$usage)];
}

/** No retries: a lost response may already have been billed. Fixed transport, standard tier. */
function westy_tenant_ai_complete(array $selection,string $system,array $messages,array $options=[],?callable $transport=null): array
{
    try{
        if(($selection['status']??null)!=='active' || !westy_tenant_ai_api_key($selection['provider']??'',$selection['api_key']??null))
            return ['ok'=>false,'reason'=>'needs_setup','usage'=>null];
        $body=westy_tenant_ai_body($selection,$system,$messages,$options);
        westy_tenant_ai_input_bound($selection,$body,$options['count_transport']??null,$options['max_input_tokens']??200000);
        $response=($transport??'westy_tenant_ai_provider_http')($selection,$body);
        if(!is_string($response['body']??null) || strlen($response['body'])>4194304)return ['ok'=>false,'reason'=>'provider_unavailable','usage'=>null];
        $json=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
        $original=json_decode($response['body'],false,64,JSON_THROW_ON_ERROR);
        $output=$selection['provider']==='openai'?($original->output??null):($original->content??null);
        return is_array($json)?westy_tenant_ai_parse($selection,(int)($response['status']??0),$json,is_array($output)?$output:null):['ok'=>false,'reason'=>'provider_invalid','usage'=>null];
    }catch(Throwable){return ['ok'=>false,'reason'=>'provider_unavailable','usage'=>null];}
}

function westy_tenant_ai_provider_http(array $selection,array $body,bool $countOnly=false): array
{
    $openai=$selection['provider']==='openai';$raw='';
    $endpoint=$openai?'https://api.openai.com/v1/responses':'https://api.anthropic.com/v1/messages';
    if($countOnly)$endpoint.=$openai?'/input_tokens':'/count_tokens';
    $curl=curl_init($endpoint);
    $headers=['Content-Type: application/json'];
    if ($openai) {
        $headers[] = 'Authorization: Bearer ' . $selection['api_key'];
    } else {
        $headers[] = 'x-api-key: ' . $selection['api_key'];
        $headers[] = 'anthropic-version: 2023-06-01';
    }
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$countOnly?15:90,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int{
            if(strlen($raw)+strlen($chunk)>4194304)return 0;$raw.=$chunk;return strlen($chunk);
        }]);
    try{$ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);}finally{curl_close($curl);}
    return ['status'=>$ok===false?0:$http,'body'=>$ok===false?'':$raw];
}

/** Only explicit Test connection invokes this synthetic, bounded paid request. */
function westy_tenant_ai_test_connection(array $selection): array
{
    $result=westy_tenant_ai_complete(['status'=>'active']+$selection,'Reply with exactly OK.',[
        ['role'=>'user','content'=>[['type'=>'text','text'=>'Connection test.']]]],['max_output_tokens'=>1024]);
    if(($result['ok']??false)===true && trim($result['text'])==='OK' && $result['usage']!==null)
        return ['state'=>'valid','reason'=>'ok'];
    $reason=$result['reason']??'test_incomplete';
    if(in_array($reason,['credentials_refused','model_unavailable','quota_unavailable'],true))return ['state'=>'failed','reason'=>$reason];
    return ['state'=>'uncertain','reason'=>'connection_uncertain'];
}
