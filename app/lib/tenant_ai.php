<?php
/** App-local provider MSP keys only; a managed customer's ID is never the billing tenant. */
declare(strict_types=1);
require_once __DIR__.'/tenant_ai/tenant_ai_engine.php';

function safeharbor_tenant_ai_resolve(int $tenantId,string $action='status',?int $revision=null):array
{
    if($tenantId<1)return ['status'=>'unavailable'];
    $config=cfg('tenant_ai',[]);
    return westy_tenant_ai_resolve(is_array($config)?$config:[],'safeharbor',(string)$tenantId,$action,$revision);
}

function safeharbor_staff_ai_context(?int $expectedUser=null,string $action='status',?int $revision=null):array
{
    try {
        $actor=function_exists('current_user')?current_user():null;
        if(!is_array($actor) || (int)($actor['id']??0)<1 || (int)($actor['tenant_id']??0)<1
            || ($expectedUser!==null && (int)$actor['id']!==$expectedUser))return ['status'=>'unavailable'];
        return safeharbor_tenant_ai_resolve((int)$actor['tenant_id'],$action,$revision);
    }catch(Throwable){return ['status'=>'unavailable'];}
}
