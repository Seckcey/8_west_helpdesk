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
$streaming=false;
$emit=static function(string $event,array $data):void{
    echo 'event: '.$event."\n".'data: '.json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n\n";
    flush();
};
$fail=static function(string $reason,int $status)use(&$streaming,$emit):never{
    if($streaming){$emit('error',['reason'=>$reason]);exit;}
    json_out(['ok'=>false,'reason'=>$reason],$status);
};
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
        $originalSession=session_id();
        if(!session_write_close())json_out(['ok'=>false,'reason'=>'unavailable'],503);
        if(in_array($request['action']??'',['message','desktop_resume','run_resume'],true) && str_contains($_SERVER['HTTP_ACCEPT']??'','text/event-stream')){
            // PHP checks use_cookies before applying session_start options. Set
            // these before the first SSE byte so read-only reopen needs no headers.
            ini_set('session.use_cookies','0');session_cache_limiter('');
            $streaming=true;ignore_user_abort(true);set_time_limit(160);
            header('Content-Type: text/event-stream; charset=utf-8');header('X-Accel-Buffering: no');
            header('Cache-Control: no-store, private, no-transform');
            if(function_exists('apache_setenv'))apache_setenv('no-gzip','1');
            ini_set('zlib.output_compression','0');
            while(ob_get_level()>0)ob_end_clean();
            echo ': '.str_repeat(' ',2048)."\n\n";flush();
            $reauthorize=static fn()=>portal_stream_authenticated_context(db(),$originalSession);
            $conversation=$request['conversation']??null;
            $streamEmit=static function(string $event,array $data)use($emit,&$conversation):void{if($event==='accepted')$conversation=$data['conversation'];$emit($event,$data);};
            portal_westy_message(db(),$context,$request,null,$reauthorize,$streamEmit);
            $fresh=$reauthorize();
            if($fresh===null||$fresh['identity']!==$context['identity'])$fail('sign_in',401);
            $state=portal_westy_state(db(),$fresh,$conversation);
            $check=$reauthorize();if($check===null||$check['identity']!==$context['identity'])$fail('sign_in',401);
            $emit('done',['state'=>$state]);exit;
        }
        switch($request['action'] ?? ''){
            case 'message': case 'desktop_resume': case 'run_resume': portal_westy_message(db(),$context,$request,null,static fn()=>portal_stream_authenticated_context(db(),$originalSession));break;
            case 'diagnostic_preference':
                if(!portal_devices_keys($request,['action','automatic_diagnostics'])||!is_bool($request['automatic_diagnostics']))throw new PortalWestyException('invalid_request',400);
                portal_desktop_request($context,'shell_preferences',['automatic_diagnostics'=>$request['automatic_diagnostics']]);break;
            case 'save_draft': portal_westy_save_draft(db(),$context,$request);break;
            case 'handoff': portal_westy_handoff(db(),$context,$request);$receiptKey=$request['draft_key'];break;
            case 'new_chat': portal_westy_new_chat(db(),$context,$request);break;
            case 'select_chat': portal_westy_select_chat(db(),$context,$request);break;
            case 'stop': portal_westy_stop(db(),$context,$request);break;
            case 'approve_operation': case 'cancel_operation': portal_westy_operation_action(db(),$context,$request,null,static fn()=>portal_stream_authenticated_context(db(),$originalSession));break;
            default: throw new PortalWestyException('invalid_request',400);
        }
    }
    // Recheck before returning any transcript, including after a slow provider call.
    $fresh=portal_authenticated_context(db());
    if($fresh===null || $fresh['identity']['subject']!==$context['identity']['subject']
        || $fresh['identity']['binding_id']!==$context['identity']['binding_id'])json_out(['ok'=>false,'reason'=>'sign_in'],401);
    $state=portal_westy_state(db(),$fresh,isset($_GET['conversation'])?portal_westy_key($_GET['conversation']):null);
    if($method==='GET'&&isset($_GET['devices'])){
        $state['devices']=portal_devices_request(db(),$fresh,'devices',['after'=>0])['items'];
        $check=portal_authenticated_context(db());
        if($check===null||$check['identity']!==$fresh['identity'])$fail('sign_in',401);
    }
    if($method==='GET'&&isset($_GET['diagnostic_preference'])&&portal_westy_runs_installed(db()))
        try{$state['diagnostic_preference']=portal_desktop_request($fresh,'shell_preferences',[]);}catch(PortalDesktopException){}
    if($receiptKey!==null)$state['receipt']=portal_westy_receipt(db(),$fresh,$receiptKey);
    $check=portal_authenticated_context(db());
    if($check===null||$check['identity']!==$context['identity'])$fail('sign_in',401);
    session_write_close();
    json_out(['ok'=>true,'state'=>$state]);
}catch(PortalWestyException|PortalDevicesException|PortalDesktopException $error){$fail($error->reason,$error->status);}
catch(PortalDataValidationException|JsonException){$fail('invalid_request',400);}
catch(PortalIdentityUnavailableException){$fail('identity_unavailable',503);}
catch(Throwable $error){error_log('[safeharbor-portal-westy] request_failed type='.$error::class);$fail('unavailable',503);}
