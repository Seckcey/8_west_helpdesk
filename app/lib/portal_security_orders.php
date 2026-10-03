<?php
declare(strict_types=1);
require_once __DIR__.'/portal_devices.php';

function portal_security_orders_result(string $action,array $result): array
{
    $fail=static function():never { throw new PortalDevicesException('order_evidence_unavailable'); };
    if ($action==='security_orders' && (!portal_devices_keys($result,['items']) || !is_array($result['items'])
        || !array_is_list($result['items']) || count($result['items'])>50)) $fail();
    foreach($action==='security_orders'?$result['items']:[$result] as $row) {
        if (!is_array($row) || !portal_devices_keys($row,['reference','state','expired','device_reference','offer','gateway_stage','created_at','expires_at',
            'can_approve','can_install','approval_fingerprint','can_continue','can_refresh','error_code','installation','existing_order','superseded','can_review_setup'])
            || !is_string($row['reference']) || preg_match('/^[a-f0-9]{32}$/D',$row['reference'])!==1
            || !is_string($row['device_reference']) || preg_match('/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D',$row['device_reference'])!==1
            || !in_array($row['state'],['planning','review','accepting','accepted','needs_review'],true)
            || !in_array($row['gateway_stage'],[null,'approved','company_creating','company_created','company_ready','package_creating','package_created','ready','unknown','rejected','needs_review'],true)
            || !in_array($row['error_code'],['','billing_setup_required','order_already_exists','execution_consent_expired'],true)
            || !portal_devices_timestamp($row['created_at']) || !portal_devices_timestamp($row['expires_at'])) $fail();
        foreach(['expired','can_approve','can_install','can_continue','can_refresh','existing_order','superseded','can_review_setup'] as $key) if(!is_bool($row[$key])) $fail();
        if ($row['superseded'] && ($row['can_approve'] || $row['can_install'] || $row['can_continue'] || $row['can_refresh'] || $row['can_review_setup'])) $fail();
        if (($row['can_approve']||$row['can_install']) ? (!is_string($row['approval_fingerprint']) || preg_match('/^[a-f0-9]{64}$/D',$row['approval_fingerprint'])!==1)
            : $row['approval_fingerprint']!==null) $fail();
        if (($row['can_approve'] && ($row['state']!=='review'||$row['expired']))
            || ($row['can_install'] && ($row['state']!=='accepted'||$row['gateway_stage']!=='ready'||$row['expired']||$row['installation']!==null))) $fail();
        $offer=$row['offer'];
        if ($offer!==null) {
            if (!is_array($offer) || !portal_devices_keys($offer,['code','name','unit_price_cents','currency','billing_interval','quantity','terms_version','terms','terms_sha256'])
                || $offer['code']!=='secure-plus-v1' || $offer['name']!=='Secure Plus' || $offer['unit_price_cents']!==1500 || $offer['currency']!=='USD'
                || $offer['billing_interval']!=='month' || $offer['quantity']!==1 || $offer['terms_version']!=='2026-10-03-v1'
                || !is_string($offer['terms']) || strlen($offer['terms'])<1 || strlen($offer['terms'])>2048 || preg_match('/[\x00-\x1f\x7f]/',$offer['terms'])
                || !is_string($offer['terms_sha256']) || !hash_equals(hash('sha256',$offer['terms']),$offer['terms_sha256'])) $fail();
        } elseif (in_array($row['state'],['review','accepting','accepted'],true)) $fail();
        $i=$row['installation'];
        if ($i!==null && (!is_array($i) || !portal_devices_keys($i,['state','outcome','reboot_pending','updated_at','protection','mdr','observed_at'])
            || !in_array($i['state'],['queued','claimed','link_delivered','downloading','verified','installing','awaiting_verification','installed','unknown','refused'],true)
            || !is_string($i['outcome']) || !in_array($i['outcome'],['','authorization_changed','ok','already_installed','link_unavailable','download_failed','download_too_large','signature_untrusted_or_invalid',
                'signer_not_allowed','installer_exit_nonzero','installer_timeout','reboot_required','prerequisite_missing','incompatible_security_software','insufficient_disk_space',
                'another_install_in_progress','canceled','unknown_error'],true)
            || !is_bool($i['reboot_pending']) || !portal_devices_timestamp($i['updated_at'])
            || !in_array($i['protection'],['current','attention','unknown'],true)
            || !in_array($i['mdr'],['active','inactive','enrolling','disabling','unknown'],true)
            || ($i['observed_at']!==null && !portal_devices_timestamp($i['observed_at'])))) $fail();
    }
    return $result;
}

function portal_security_order_inputs(array $post): array
{
    $action=$post['action']??null;
    $keys=match($action) {
        'security_review'=>['csrf','action','request_key','device_reference'],
        'security_accept','security_install'=>['csrf','action','reference','approval_fingerprint','consent'],
        'security_continue','security_refresh'=>['csrf','action','reference'],
        default=>throw new PortalDevicesException('invalid_request',400),
    };
    if (!portal_devices_keys($post,$keys)) throw new PortalDevicesException('invalid_request',400);
    if (in_array($action,['security_accept','security_install'],true) && $post['consent']!=='yes') throw new PortalDevicesException('order_consent',400);
    $input=array_diff_key($post,array_flip(['csrf','action','consent']));
    foreach($input as $key=>$value) {
        $pattern=match($key){'device_reference'=>'/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D','approval_fingerprint'=>'/^[a-f0-9]{64}$/D',default=>'/^[a-f0-9]{32}$/D'};
        if (!is_string($value) || preg_match($pattern,$value)!==1) throw new PortalDevicesException('invalid_request',400);
    }
    return [$action,$input];
}

function portal_security_order_error(string $reason): string
{
    return match($reason) {
        'order_consent'=>'Review the price, terms and selected computer, then tick the confirmation to continue.',
        'approval_expired','approval_changed','order_authorization_changed'=>'This approval has expired or its details have changed. Review setup again for the existing order when that option is available.',
        'execution_consent_pending'=>'We are checking the previous confirmation. Its approval window must close before a replacement can start. Check the existing order shortly.',
        'installation_busy'=>'This computer has unfinished work. Contact support before starting another installation.',
        'order_service_unavailable'=>'The response could not be confirmed. Check the existing order below before submitting anything again.',
        'orders_unavailable','order_unavailable','order_evidence_unavailable','installation_unavailable'=>'Security ordering is unavailable right now. Contact support to review its status.',
        'role'=>'Only a business owner or admin can order or install Secure Plus. You can view your business’s status.',
        default=>portal_devices_error($reason),
    };
}
