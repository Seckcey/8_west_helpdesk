<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_westy.php';

enforce_https();
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
if(!portal_enabled())json_out(['ok'=>false,'reason'=>'not_found'],404);
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');json_out(['ok'=>false,'reason'=>'method'],405);}
try{
    $receiptKey=$method==='GET'?($_GET['receipt']??null):null;
    $context=portal_authenticated_context(db());
    if($context===null)json_out(['ok'=>false,'reason'=>'sign_in'],401);
    if($method==='POST'){
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]))!=='application/json')json_out(['ok'=>false,'reason'=>'invalid_request'],415);
        if(!portal_csrf_valid($_SERVER['HTTP_X_PORTAL_CSRF'] ?? null))json_out(['ok'=>false,'reason'=>'sign_in'],403);
        if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>40000)json_out(['ok'=>false,'reason'=>'invalid_request'],413);
        $raw=file_get_contents('php://input',false,null,0,40001);
        if(!is_string($raw)||strlen($raw)>40000)json_out(['ok'=>false,'reason'=>'invalid_request'],413);
        $request=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($request))json_out(['ok'=>false,'reason'=>'invalid_request'],400);
        if(!session_write_close())json_out(['ok'=>false,'reason'=>'unavailable'],503);
        switch($request['action'] ?? ''){
            case 'message': portal_westy_message(db(),$context,$request,null,static fn()=>portal_authenticated_context(db()));break;
            case 'save_draft': portal_westy_save_draft(db(),$context,$request);break;
            case 'handoff': portal_westy_handoff(db(),$context,$request);$receiptKey=$request['draft_key'];break;
            case 'new_chat': portal_westy_new_chat(db(),$context,$request);break;
            default: throw new PortalWestyException('invalid_request',400);
        }
    }
    // Recheck before returning any transcript, including after a slow provider call.
    $fresh=portal_authenticated_context(db());
    if($fresh===null || $fresh['identity']['subject']!==$context['identity']['subject']
        || $fresh['identity']['binding_id']!==$context['identity']['binding_id'])json_out(['ok'=>false,'reason'=>'sign_in'],401);
    $state=portal_westy_state(db(),$fresh);
    if($receiptKey!==null)$state['receipt']=portal_westy_receipt(db(),$fresh,$receiptKey);
    session_write_close();
    json_out(['ok'=>true,'state'=>$state]);
}catch(PortalWestyException $error){json_out(['ok'=>false,'reason'=>$error->reason],$error->status);}
catch(PortalDataValidationException){json_out(['ok'=>false,'reason'=>'invalid_request'],400);}
catch(JsonException){json_out(['ok'=>false,'reason'=>'invalid_request'],400);}
catch(PortalIdentityUnavailableException){json_out(['ok'=>false,'reason'=>'identity_unavailable'],503);}
catch(Throwable $error){error_log('[safeharbor-portal-westy] request_failed type='.$error::class);json_out(['ok'=>false,'reason'=>'unavailable'],503);}
