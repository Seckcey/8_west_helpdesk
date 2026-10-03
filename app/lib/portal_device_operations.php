<?php
/** Closed customer-operation projections. No model text becomes a command or approval. */
declare(strict_types=1);
require_once __DIR__.'/portal_devices.php';

function portal_device_operations_result(string $action,array $result):array
{
    $fail=static function():never{throw new PortalDevicesException('service_unavailable');};
    if($action==='operations') {
        if(!portal_devices_keys($result,['available','items']) || !is_bool($result['available'])
            || !is_array($result['items']) || !array_is_list($result['items']) || count($result['items'])>25
            || (!$result['available'] && $result['items']!==[])) $fail();
        $rows=$result['items'];
    } else $rows=[$result];
    foreach($rows as $r) {
        if(!is_array($r) || !portal_devices_keys($r,['reference','recipe','title','impact','device_reference','state','created_at','expires_at','can_approve','approval_fingerprint','result'])
            || !is_string($r['reference']) || preg_match('/^[a-f0-9]{32}$/D',$r['reference'])!==1
            || !is_string($r['device_reference']) || preg_match('/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D',$r['device_reference'])!==1
            || !in_array($r['recipe'],['health','spooler_restart'],true)
            || !in_array($r['state'],['awaiting_approval','authorized','queued','verifying','completed','service_verified','needs_help','expired'],true)
            || !is_bool($r['can_approve']) || !portal_devices_timestamp($r['created_at']) || !portal_devices_timestamp($r['expires_at'])) $fail();
        foreach(['title'=>100,'impact'=>600] as $key=>$limit) {
            if(!is_string($r[$key]) || mb_strlen($r[$key])>$limit || preg_match('//u',$r[$key])!==1) $fail();
        }
        if($r['can_approve']) {
            if($r['state']!=='awaiting_approval' || $r['recipe']!=='spooler_restart' || !is_string($r['approval_fingerprint']) || preg_match('/^[a-f0-9]{64}$/D',$r['approval_fingerprint'])!==1) $fail();
        } elseif($r['approval_fingerprint']!==null) $fail();
        if($r['result']!==null) {
            $v=$r['result'];
            if(!is_array($v) || !portal_devices_keys($v,['version','observed_at','memory_used_percent','system_disk_free_percent','spooler'])
                || $v['version']!==1 || !portal_devices_timestamp($v['observed_at'])
                || !in_array($v['spooler'],['running','stopped','startpending','stoppending','paused','pausepending','continuepending','missing'],true)) $fail();
            foreach(['memory_used_percent','system_disk_free_percent'] as $key) {
                if((!is_int($v[$key])&&!is_float($v[$key])) || !is_finite((float)$v[$key]) || $v[$key]<0 || $v[$key]>100) $fail();
            }
        }
        if(($r['recipe']==='health'&&in_array($r['state'],['awaiting_approval','verifying','service_verified'],true))
            || ($r['state']==='completed'&&$r['recipe']!=='health')
            || (in_array($r['state'],['completed','service_verified'],true)&&$r['result']===null)
            || ($r['state']==='service_verified'&&$r['result']['spooler']!=='running')) $fail();
    }
    return $result;
}

function portal_device_operation_inputs(array $post):array
{
    $action=$post['action']??null;
    if(!in_array($action,['health_start','repair_propose','repair_approve'],true)) throw new PortalDevicesException('invalid_request',400);
    if(in_array($action,['health_start','repair_approve'],true)&&($post['consent']??null)!=='yes') throw new PortalDevicesException('operation_consent',400);
    $keys=match($action){'health_start'=>['request_key','device_reference'],'repair_propose'=>['request_key','device_reference','health_reference'],default=>['reference','approval_fingerprint']};
    $input=[];
    foreach($keys as $key) {
        $pattern=match($key){'device_reference'=>'/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D','approval_fingerprint'=>'/^[a-f0-9]{64}$/D',default=>'/^[a-f0-9]{32}$/D'};
        if(!is_string($post[$key]??null)||preg_match($pattern,$post[$key])!==1) throw new PortalDevicesException('invalid_request',400);
        $input[$key]=$post[$key];
    }
    return [$action,$input];
}
