<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_mobile_render.php';
enforce_https();
if(!portal_enabled() || (cfg('portal_mobile',[])['enabled']??false)!==true){http_response_code(404);exit('Not found.');}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');portal_render_error(405,'Method not allowed','Open the mobile device page from your portal.');exit;}
try{
    $context=portal_authenticated_context(db());
    if($context===null){portal_require_sign_in();exit;}
    $platform=is_string($_GET['platform']??null)&&in_array($_GET['platform'],['ios','android','macos'],true)?$_GET['platform']:'ios';
    $ownership=($_GET['ownership']??'')==='company'?'company':'personal';
    $offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1500]]);
    $error=null;$inventory=null;$plan=null;$support=null;
    try{
        $plan=portal_mobile_request(db(),$context,'enrollment_plan',['platform'=>$platform,'ownership'=>$ownership]);
        if(isset($_GET['reference']))$support=portal_mobile_request(db(),$context,'support',['reference'=>$_GET['reference']]);
        $inventory=portal_mobile_request(db(),$context,'devices',['offset'=>$offset===false?0:$offset]);
    }catch(PortalDevicesException $e){$error=$e->reason==='device_unavailable'?'That device is no longer available for this business. Refresh status or contact support.':portal_devices_error($e->reason);}
    $fresh=portal_authenticated_context(db());
    if($fresh===null||$fresh['identity']!==$context['identity']){portal_require_sign_in('Please sign in again.');exit;}
    portal_render_mobile($fresh,$inventory,$plan,$support,$error,$platform,$ownership);
}catch(Throwable){portal_render_error(503,'Mobile status unavailable','Try again shortly or contact support from your portal.');}
