<?php
/** Shared-engine bridge. Resolution and actor checks remain owned by each host app. */
declare(strict_types=1);
require_once __DIR__.'/tenant_ai_client.php';
require_once __DIR__.'/tenant_ai_provider.php';

function westy_tenant_ai_engine_ready(array $ai,callable $legacyReady): bool
{
    if (($ai['enabled']??false)!==true || !is_callable($ai['tenant_ai_refresh']??null)) return false;
    $state=$ai['tenant_ai']['status']??'';
    return $state==='active' || ($state==='internal_legacy' && $legacyReady());
}

/** Private callbacks recheck the current app actor and binding immediately before/after inference. */
function westy_tenant_ai_with_context(array $ai,callable $dispatch): array
{
    $snapshot=$ai['tenant_ai']??[];$refresh=$ai['tenant_ai_refresh']??null;
    $failure=['ok'=>false,'error'=>'Your MSP’s AI connection needs setup or changed. Your other tools still work.'];
    if (($ai['enabled']??false)!==true || !is_array($snapshot) || !is_int($snapshot['revision']??null)
        || !is_callable($refresh) || !in_array($snapshot['status']??'',['active','internal_legacy'],true)) return $failure;
    $matches=static function(array $current)use($snapshot):bool {
        foreach(['app','local_tenant_key','tenant_id','tenant_slug','status','revision'] as $field)
            if(!array_key_exists($field,$snapshot) || ($current[$field]??null)!==$snapshot[$field])return false;
        if($snapshot['status']==='active')foreach(['provider','model','effort','catalog','credential_version'] as $field)
            if(($current[$field]??null)!==($snapshot[$field]??null))return false;
        return true;
    };
    $receipt=[];
    try {
        $resolved=$refresh('resolve',$snapshot['revision']);
        if(!is_array($resolved) || !$matches($resolved))return $failure;
        $result=$dispatch($resolved);
        if(!is_array($result))return $failure;
        $receipt=array_intersect_key($result,array_flip(['usage','cost_micro_usd','provider','model','ai_revision']));
        $current=$refresh('status',$snapshot['revision']);
        if(!is_array($current) || !$matches($current))return $failure+$receipt;
        return $result;
    } catch(Throwable) { return $failure+$receipt; }
}

function westy_tenant_ai_engine_complete(array $ai,string $system,array $messages,array $schema,callable $legacy): array
{
    return westy_tenant_ai_with_context($ai,static function(array $resolved)use($ai,$system,$messages,$schema,$legacy):array {
        if($resolved['status']==='internal_legacy')return $legacy();
        $result=westy_tenant_ai_complete($resolved,$system,$messages,
            ['schema'=>$schema,'max_output_tokens'=>min(16000,max(1024,(int)($ai['max_tokens']??4000)))]);
        $receipt=['usage'=>$result['usage']??null,'cost_micro_usd'=>$result['cost_micro_usd']??null,
            'provider'=>$resolved['provider'],'model'=>$resolved['model'],'ai_revision'=>$resolved['revision']];
        $data=($result['ok']??false)?json_decode($result['text'],true):null;
        return is_array($data)?['ok'=>true,'data'=>$data]+$receipt
            :['ok'=>false,'error'=>'Your selected AI provider could not complete this request.']+$receipt;
    });
}
