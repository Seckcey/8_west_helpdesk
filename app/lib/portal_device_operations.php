<?php
/** Closed customer-operation projections. No model text becomes a command or approval. */
declare(strict_types=1);
require_once __DIR__.'/portal_devices.php';

function portal_device_operations_result(string $action,array $result):array
{
    $fail=static function():never{throw new PortalDevicesException('service_unavailable');};
    if($action==='operations') {
        if(!portal_devices_keys($result,['available','eligibility','items']) || !is_bool($result['available'])
            || !is_array($result['items']) || !array_is_list($result['items']) || count($result['items'])>25
            || (!$result['available'] && $result['items']!==[])) $fail();
        $eligibility=$result['eligibility'];
        $keys=['can_check','can_propose_repair','reason'];
        if(!is_array($eligibility)||!(portal_devices_keys($eligibility,$keys)||portal_devices_keys($eligibility,array_merge($keys,['repair_reason'])))
            ||!is_bool($eligibility['can_check'])||!is_bool($eligibility['can_propose_repair'])
            ||!in_array($eligibility['reason'],['ready','repair_cooldown','read_only','not_available','in_progress','support_review','execution_unresolved','policy_restricted'],true)
            ||($eligibility['can_propose_repair']&&!$eligibility['can_check'])
            ||($eligibility['can_check']!==in_array($eligibility['reason'],['ready','repair_cooldown'],true))
            ||(isset($eligibility['repair_reason'])&&!in_array($eligibility['repair_reason'],['ready','repair_cooldown','support_review','not_available','policy_restricted'],true))
            ||($eligibility['can_propose_repair']!==($eligibility['can_check']&&($eligibility['repair_reason']??$eligibility['reason'])==='ready'))
            ||(!$result['available']&&$eligibility['reason']!=='not_available'))$fail();
        $rows=$result['items'];
    } else $rows=[$result];
    foreach($rows as $r) {
        $legacy=['reference','recipe','title','impact','device_reference','state','created_at','expires_at','can_approve','approval_fingerprint','result'];
        if(!is_array($r) || !(portal_devices_keys($r,$legacy)||portal_devices_keys($r,array_merge($legacy,['basis_reference','preview','can_cancel'])))
            || !is_string($r['reference']) || preg_match('/^[a-f0-9]{32}$/D',$r['reference'])!==1
            || !is_string($r['device_reference']) || preg_match('/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D',$r['device_reference'])!==1
            || !in_array($r['recipe'],['health','spooler_restart','temp_preview','temp_cleanup'],true)
            || !in_array($r['state'],['awaiting_approval','authorized','queued','verifying','completed','service_verified','needs_help','expired','cancelled','cancel_requested','cleanup_verified'],true)
            || !is_bool($r['can_approve']) || !portal_devices_timestamp($r['created_at']) || !portal_devices_timestamp($r['expires_at'])) $fail();
        foreach(['title'=>100,'impact'=>600] as $key=>$limit) {
            if(!is_string($r[$key]) || mb_strlen($r[$key])>$limit || preg_match('//u',$r[$key])!==1) $fail();
        }
        if($r['can_approve']) {
            if($r['state']!=='awaiting_approval' || !in_array($r['recipe'],['spooler_restart','temp_cleanup'],true) || !is_string($r['approval_fingerprint']) || preg_match('/^[a-f0-9]{64}$/D',$r['approval_fingerprint'])!==1) $fail();
        } elseif($r['approval_fingerprint']!==null) $fail();
        if(array_key_exists('can_cancel',$r)){
            if(!is_bool($r['can_cancel'])||($r['can_cancel']&&!in_array($r['state'],['awaiting_approval','authorized','queued','verifying'],true)))$fail();
            if($r['basis_reference']!==null&&(!is_string($r['basis_reference'])||!preg_match('/^[a-f0-9]{32}$/D',$r['basis_reference'])))$fail();
            if($r['preview']!==null&&($r['recipe']!=='temp_cleanup'||!portal_device_temp_result_valid($r['preview'],'temp_preview')))$fail();
        }
        if($r['result']!==null) {
            $v=$r['result'];
            if(str_starts_with($r['recipe'],'temp_')){if(!portal_device_temp_result_valid($v,$r['recipe']))$fail();}
            elseif(!portal_device_health_result_valid($v))$fail();
        }
        if((in_array($r['recipe'],['health','temp_preview'],true)&&in_array($r['state'],['awaiting_approval','verifying','service_verified','cleanup_verified'],true))
            || ($r['state']==='completed'&&!in_array($r['recipe'],['health','temp_preview'],true))
            || (in_array($r['state'],['completed','service_verified','cleanup_verified'],true)&&$r['result']===null)
            || ($r['state']==='service_verified'&&($r['recipe']!=='spooler_restart'||$r['result']['spooler']!=='running'))
            || ($r['state']==='cleanup_verified'&&($r['recipe']!=='temp_cleanup'||!isset($r['result']['health'])))
            || ($r['recipe']==='temp_cleanup'&&(!isset($r['preview'],$r['basis_reference'])))) $fail();
    }
    return $result;
}

function portal_device_health_result_valid(mixed $v):bool
{
    $keys=['version','observed_at','memory_used_percent','system_disk_free_percent','spooler'];
    if(is_array($v)&&($v['version']??null)===2)$keys=array_merge($keys,['memory_total_bytes','memory_available_bytes']);
    if(!is_array($v)||!portal_devices_keys($v,$keys)||!in_array($v['version'],[1,2],true)||!portal_devices_timestamp($v['observed_at'])||!in_array($v['spooler'],['running','stopped','startpending','stoppending','paused','pausepending','continuepending','missing'],true))return false;
    foreach(['memory_used_percent','system_disk_free_percent'] as $key)if((!is_int($v[$key])&&!is_float($v[$key]))||!is_finite((float)$v[$key])||$v[$key]<0||$v[$key]>100)return false;
    if($v['version']===2&&(!is_int($v['memory_total_bytes'])||!is_int($v['memory_available_bytes'])
        ||$v['memory_total_bytes']<=0||$v['memory_total_bytes']>1125899906842624
        ||$v['memory_available_bytes']<0||$v['memory_available_bytes']>$v['memory_total_bytes']))return false;
    return true;
}

function portal_device_temp_result_valid(mixed $v,string $kind):bool
{
    if(!is_array($v))return false;
    if(isset($v['health'])){if($kind!=='temp_cleanup'||!portal_device_health_result_valid($v['health']))return false;unset($v['health']);}
    $keys=$kind==='temp_preview'?['version','kind','observed_at','cutoff','manifest_sha256','eligible_files','eligible_bytes','bounded']:['version','kind','observed_at','manifest_sha256','deleted_files','deleted_bytes','skipped_files'];
    if(!portal_devices_keys($v,$keys)||$v['version']!==1||$v['kind']!==$kind||!portal_devices_timestamp($v['observed_at'])||!is_string($v['manifest_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$v['manifest_sha256']))return false;
    foreach(($kind==='temp_preview'?['eligible_files'=>200,'eligible_bytes'=>268435456]:['deleted_files'=>200,'deleted_bytes'=>268435456,'skipped_files'=>5200]) as $key=>$max)if(!is_int($v[$key])||$v[$key]<0||$v[$key]>$max)return false;
    return $kind!=='temp_preview'||(portal_devices_timestamp($v['cutoff'])&&is_bool($v['bounded']));
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
