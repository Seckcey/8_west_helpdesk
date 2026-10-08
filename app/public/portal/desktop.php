<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_desktop_sessions.php';
require_once __DIR__.'/../../lib/portal_render.php';
enforce_https();
if(!portal_enabled()){http_response_code(404);exit;}
try{
    portal_session_start();
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $body=file_get_contents('php://input',false,null,0,2049);
        if(!is_string($body)||strlen($body)>2048)throw new PortalDesktopException('invalid_request',400);
        $input=json_decode($body,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!portal_devices_keys($input,['handoff']))throw new PortalDesktopException('invalid_request',400);
        // A lost consume response can leave this same browser with its new
        // cookie but the old page/CSRF. Only its exact completed receipt may
        // acknowledge readiness; it cannot mint or repeat any authority.
        $csrf=$_SERVER['HTTP_X_PORTAL_CSRF']??null;
        $completed=is_string($csrf)&&portal_desktop_handoff_completed(db(),$input['handoff'],$csrf);
        if(!$completed&&!portal_csrf_valid($csrf))throw new PortalDesktopException('sign_in',403);
        $ready=$completed||portal_desktop_handoff_take(db(),$input['handoff']);
        header('Cache-Control: no-store, private');json_out(['ok'=>true,'ready'=>$ready]);exit;
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new PortalDesktopException('method',405);
    $pair=$_GET['pair']??null;
    if(!portal_desktop_id($pair))throw new PortalDesktopException('invalid_request',400);
    $context=portal_local_identity()===null?null:portal_authenticated_context(db());
    if($context!==null){
        portal_desktop_attach(db(),$context,$pair);
        portal_render_workspace($context);exit;
    }
    $handoff=portal_desktop_handoff_create(db(),$pair);
    portal_page_start('Connect Westy');
    ?>
<main class="page page-narrow" id="desktop-handoff" data-handoff="<?=portal_h($handoff)?>" data-csrf="<?=portal_h(portal_csrf_token())?>">
<h1>Connect Westy</h1><p>Sign in with 8 West ID in your browser to connect this Westy window. Your saved computer tool permissions apply to the work you request.</p>
<a class="btn-primary" target="_blank" rel="noopener noreferrer" href="/portal/desktop_authorize.php?handoff=<?=portal_h($handoff)?>">Continue in your browser</a>
<p id="desktop-handoff-status" role="status">Waiting for sign-in.</p>
</main><script src="/assets/js/desktop-handoff.js" defer></script>
<?php portal_page_end();
}catch(Throwable $error){
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')json_out(['ok'=>false,'reason'=>'connection_unavailable'],503);
    portal_render_error(503,'Westy could not connect','Reconnect from the Westy tray menu and try again.');
}
