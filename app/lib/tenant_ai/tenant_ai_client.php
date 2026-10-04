<?php
/** Server-only ID resolver. No endpoint override, redirects, global-key fallback, or persistent cache. */
declare(strict_types=1);
require_once __DIR__.'/tenant_ai_contract.php';

/** Transport injection is for hermetic tests; runtime configuration cannot supply a transport. */
function westy_tenant_ai_resolve(array $config, string $app, string $localKey, string $action = 'resolve',
    ?int $revision = null, ?callable $transport = null): array
{
    $request=westy_tenant_ai_request($app,$localKey,$action,$revision);
    $secret=$config['service_secret']??null;
    if (($config['enabled']??false)!==true || !is_string($secret) || preg_match('/\A[0-9a-f]{64}\z/D',$secret)!==1) {
        return ['status'=>'unavailable'];
    }
    $timestamp=(string)time(); $nonce=bin2hex(random_bytes(16));
    $body=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $headers=['Content-Type: application/json','X-8W-AI-App: '.$app,'X-8W-AI-Timestamp: '.$timestamp,
        'X-8W-AI-Nonce: '.$nonce,'X-8W-AI-Signature: '.westy_tenant_ai_signature('request',$timestamp,$nonce,$body,$secret)];
    try {
        $response=($transport ?? 'westy_tenant_ai_http')($headers,$body);
        $code=$response['status']??0; $raw=$response['body']??''; $sig=$response['signature']??'';
        if (!is_int($code) || !is_string($raw) || strlen($raw)>16384 || !is_string($sig)
            || !westy_tenant_ai_valid_signature($sig,'response',$timestamp,$nonce,$raw,$secret,$code)) return ['status'=>'unavailable'];
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if ($code!==200 || !is_array($data) || ($data['version']??null)!==1 || ($data['app']??null)!==$app
            || ($data['local_tenant_key']??null)!==$localKey || !is_int($data['tenant_id']??null) || $data['tenant_id']<1
            || !is_string($data['tenant_slug']??null) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D',$data['tenant_slug'])!==1
            || !in_array($data['status']??null,['unconfigured','skipped','disabled','active','internal_legacy','revision_changed'],true)
            || !is_int($data['revision']??null) || $data['revision']<0) return ['status'=>'unavailable'];
        if ($data['status']==='internal_legacy' && (($config['internal_tenant_id']??null)!==$data['tenant_id']
            || ($config['internal_local_tenant_key']??null)!==$localKey)) return ['status'=>'unavailable'];
        if ($data['status']==='active') {
            if ($revision!==null && $revision!==$data['revision']) return ['status'=>'revision_changed'];
            $selection=westy_tenant_ai_selection($data['provider']??null,$data['model']??null,$data['effort']??null);
            if (($data['catalog']??null)!==$selection['catalog'] || !is_int($data['credential_version']??null)
                || $data['credential_version']<1) return ['status'=>'unavailable'];
            if ($action==='resolve' && !westy_tenant_ai_api_key($selection['provider'],$data['api_key']??null)) return ['status'=>'unavailable'];
        }
        // A status check can never accidentally send a credential to a presentation caller.
        $fields=['version','app','local_tenant_key','tenant_id','tenant_slug','status','revision'];
        if ($data['status']==='active') {
            $fields=array_merge($fields,['provider','model','effort','catalog','credential_version']);
            if ($action==='resolve') $fields[]='api_key';
        }
        return array_intersect_key($data,array_flip($fields));
    } catch (Throwable) { return ['status'=>'unavailable']; }
}

function westy_tenant_ai_http(array $headers, string $body): array
{
    $raw=''; $signature='';
    $curl=curl_init(WESTY_TENANT_AI_URL);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use (&$signature): int {
            if (strncasecmp($line,'X-8W-AI-Signature:',18)===0) $signature=trim(substr($line,18));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$raw): int {
            if (strlen($raw)+strlen($chunk)>16384) return 0;
            $raw.=$chunk; return strlen($chunk);
        }]);
    $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    return ['status'=>$ok===false?0:$status,'body'=>$raw,'signature'=>$signature];
}

/** Only this allowlist is safe to expose in a browser, report or diagnostic. */
function westy_tenant_ai_public_status(array $resolved): array
{
    return array_intersect_key($resolved,array_flip(['status','provider','model','effort','revision','credential_version']));
}

/** Engine configuration is reconstructed, never merged over a house credential. */
function westy_tenant_ai_engine_config(array $resolved,array $legacy=[]): array
{
    if(($resolved['status']??null)==='internal_legacy')return ['tenant_ai'=>$resolved]+$legacy;
    if(($resolved['status']??null)!=='active')return ['enabled'=>false,'provider'=>'','tenant_ai'=>$resolved];
    return ['enabled'=>true,'provider'=>$resolved['provider'],'model'=>$resolved['model'],'effort'=>$resolved['effort'],
        'max_tokens'=>4000,'timeout'=>90,'tenant_ai'=>$resolved,'allow_stub'=>false];
}
