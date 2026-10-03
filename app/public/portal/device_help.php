<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_device_operations_render.php';
enforce_https();
if(!portal_enabled()||(cfg('portal_devices',[])['diagnostics_enabled']??false)!==true){http_response_code(404);exit('Not found.');}
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');portal_render_error(405,'Method not allowed','Open computer checks from Your devices.');exit;}
try {
    $context=portal_authenticated_context(db());
    if($context===null){portal_render_login();exit;}
    $reference=$_GET['device']??null;
    if(!is_string($reference)||preg_match('/^([1-9][0-9]{0,9}):[a-f0-9]{64}$/D',$reference,$match)!==1)throw new PortalDevicesException('device_unavailable',404);
    // Seek within this customer's server-bound inventory, then require the complete opaque reference.
    $devices=portal_devices_request(db(),$context,'devices',['after'=>(int)$match[1]-1]);$device=null;
    foreach($devices['items'] as $row){if(hash_equals($row['reference'],$reference)){$device=$row;break;}}
    if($device===null)throw new PortalDevicesException('device_unavailable',404);
    $error=null;
    if($method==='POST') {
        try {
            if(!portal_csrf_valid($_POST['csrf']??null))throw new PortalDevicesException('sign_in',403);
            [$action,$input]=portal_device_operation_inputs($_POST);
            if(isset($input['device_reference'])&&!hash_equals($reference,$input['device_reference']))throw new PortalDevicesException('invalid_request',400);
            if($action==='repair_approve') {
                $current=portal_devices_request(db(),$context,'operations',['device_reference'=>$reference]);$found=false;
                foreach($current['items'] as $r){if($r['reference']===$input['reference']&&$r['device_reference']===$reference)$found=true;}
                if(!$found)throw new PortalDevicesException('approval_changed',409);
            }
            portal_devices_request(db(),$context,$action,$input);
            $fresh=portal_authenticated_context(db());
            if($fresh===null||$fresh['identity']!==$context['identity']){portal_render_login('Please sign in again.');exit;}
            header('Location: /portal/device_help.php?device='.rawurlencode($reference).'#operation-history',true,303);exit;
        }catch(PortalDevicesException $e){$error=portal_devices_error($e->reason);}
    }
    $operations=['available'=>false,'eligibility'=>['can_check'=>false,'can_propose_repair'=>false,'reason'=>'not_available'],'items'=>[]];
    try{$operations=portal_devices_request(db(),$context,'operations',['device_reference'=>$reference]);}
    catch(PortalDevicesException $e){$error??=portal_devices_error($e->reason);}
    $fresh=portal_authenticated_context(db());
    if($fresh===null||$fresh['identity']!==$context['identity']){portal_render_login('Please sign in again.');exit;}
    portal_render_device_help($fresh,$device,$operations,$error);
}catch(PortalDevicesException $e){portal_render_error($e->status,'Computer checks unavailable',$e->reason==='device_unavailable'?'That computer is not available in your business. Return to Your devices.':portal_devices_error($e->reason));}
catch(Throwable $e){error_log('[safeharbor-device-checks] request_failed type='.$e::class);portal_render_error(503,'Computer checks unavailable','Try again shortly or contact support.');}
