<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_desktop_sessions.php';
enforce_https();header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
try{
    if(!portal_enabled())throw new PortalDesktopException('not_found',404);
    $context=portal_authenticated_context(db());if($context===null)throw new PortalDesktopException('sign_in',401);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new PortalDesktopException('method',405);
    if(!portal_csrf_valid($_SERVER['HTTP_X_PORTAL_CSRF']??null))throw new PortalDesktopException('sign_in',403);
    $raw=file_get_contents('php://input',false,null,0,8193);
    if(!is_string($raw)||strlen($raw)>8192)throw new PortalDesktopException('invalid_request',400);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||!is_string($input['action']??null))throw new PortalDesktopException('invalid_request',400);
    $action=$input['action'];unset($input['action']);
    if($action==='start')$result=portal_desktop_start(db(),$context,$input);
    elseif($action==='list'){
        if($input!==[])throw new PortalDesktopException('invalid_request',400);
        $result=portal_desktop_list(db(),$context);
    }elseif(in_array($action,['state','stop'],true)){
        $expected=$action==='stop'?['session_id','task_id']:['session_id'];
        if(!portal_devices_keys($input,$expected))throw new PortalDesktopException('invalid_request',400);
        portal_desktop_binding(db(),$context,$input['session_id']);$result=portal_desktop_request($context,$action,$input);
    }else{throw new PortalDesktopException('invalid_request',400);}
    $fresh=portal_authenticated_context(db());
    if($fresh===null||$fresh['identity']!==$context['identity'])throw new PortalDesktopException('sign_in',401);
    json_out(['ok'=>true,'result'=>$result]);
}catch(PortalDesktopException $error){json_out(['ok'=>false,'reason'=>$error->reason],$error->status);}
catch(Throwable){json_out(['ok'=>false,'reason'=>'desktop_unavailable'],503);}
