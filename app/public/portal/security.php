<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_security_orders_render.php';
enforce_https();
if(!portal_enabled() || (cfg('portal_devices',[])['security_orders_enabled']??false)!==true){http_response_code(404);exit('Not found.');}
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');portal_render_error(405,'Method not allowed','Open Secure Plus from Your devices.');exit;}
try {
    $context=portal_authenticated_context(db());if($context===null){portal_render_login();exit;}
    $reference=$_GET['device']??null;
    if(!is_string($reference)||preg_match('/^([1-9][0-9]{0,9}):[a-f0-9]{64}$/D',$reference,$match)!==1)throw new PortalDevicesException('device_unavailable',404);
    $inventory=portal_devices_request(db(),$context,'devices',['after'=>(int)$match[1]-1]);$device=null;
    foreach($inventory['items'] as $item)if(hash_equals($item['reference'],$reference))$device=$item;
    if($device===null)throw new PortalDevicesException('device_unavailable',404);
    $error=null;$orders=null;
    if($method==='POST') {
        try {
            if(!portal_csrf_valid($_POST['csrf']??null))throw new PortalDevicesException('sign_in',403);
            [$action,$input]=portal_security_order_inputs($_POST);
            if(isset($input['device_reference']) && !hash_equals($reference,$input['device_reference']))throw new PortalDevicesException('invalid_request',400);
            if(isset($input['reference'])) {
                $current=portal_devices_request(db(),$context,'security_orders',['device_reference'=>$reference]);$found=false;
                foreach($current['items'] as $order)if($order['reference']===$input['reference'] && $order['device_reference']===$reference)$found=true;
                if(!$found)throw new PortalDevicesException('approval_changed',409);
            }
            portal_devices_request(db(),$context,$action,$input);
            $fresh=portal_authenticated_context(db());
            if($fresh===null||$fresh['identity']!==$context['identity']){portal_render_login('Please sign in again.');exit;}
            header('Location: /portal/security.php?device='.rawurlencode($reference),true,303);exit;
        }catch(PortalDevicesException $e){$error=portal_security_order_error($e->reason);}
    }
    try {
        $result=portal_devices_request(db(),$context,'security_orders',['device_reference'=>$reference]);
        $orders=array_values(array_filter($result['items'],static fn(array $order):bool=>$order['device_reference']===$reference));
    }catch(PortalDevicesException $e){$error??=portal_security_order_error($e->reason);}
    $fresh=portal_authenticated_context(db());
    if($fresh===null||$fresh['identity']!==$context['identity']){portal_render_login('Please sign in again.');exit;}
    portal_render_security_orders($fresh,$device,$orders,$error);
}catch(PortalDevicesException $e){portal_render_error($e->status,'Secure Plus unavailable',$e->reason==='device_unavailable'?'That computer is not available in your business. Return to Your devices.':portal_security_order_error($e->reason));}
catch(Throwable $e){error_log('[safeharbor-security-orders] request_failed type='.$e::class);portal_render_error(503,'Secure Plus unavailable','Try again shortly or contact support.');}
