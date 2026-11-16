<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_desktop_sessions.php';
require_once __DIR__.'/../../lib/portal_render.php';
enforce_https();
if(!portal_enabled()){http_response_code(404);exit;}
$context=null;
try{
    $context=portal_authenticated_context(db());
    if($context===null){portal_require_sign_in();exit;}
    $id=$_GET['handoff']??null;
    if(!portal_desktop_id($id))throw new PortalDesktopException('invalid_request',400);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        if(!portal_csrf_valid($_POST['csrf']??null))throw new PortalDesktopException('sign_in',403);
        portal_desktop_handoff_approve(db(),$context,$id);
        portal_page_start('Westy sign-in approved');
        echo '<main class="page page-narrow"><h1>Sign-in approved</h1><p>Return to your Westy window to finish connecting. That window will show when your account and computer tools are ready.</p></main>';
        portal_page_end();exit;
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new PortalDesktopException('method',405);
    // Name the computer, so a person sent someone else's link can tell it isn't theirs. Milepost
    // refuses another company's computer or a pairing already in use here, before anything changes.
    $computer=portal_desktop_preview($context,portal_desktop_handoff_pairing(db(),$id));
    portal_page_start('Connect your Westy window');
    ?>
<main class="page page-narrow"><h1><?=$computer===null?'Connect your Westy window':'Connect Westy on '.portal_h($computer)?></h1>
<p>Connect as <?=portal_h($context['identity']['display_name'])?> for <?=portal_h($context['binding']['client_name']??'your business')?><?=$computer===null?'':' on the computer named <strong>'.portal_h($computer).'</strong>'?>.</p>
<p>Connect only if you just chose Continue in your browser in your own Westy window on <?=$computer===null?'your computer':'that computer'?>. If you didn't, or this isn't the computer in front of you, close this page: someone may have sent you their link. This opens your private chat in that window. Westy follows your saved computer tool permissions for the work you request.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=portal_h(portal_csrf_token())?>"><button class="btn-primary" type="submit">Connect Westy</button></form></main>
<?php portal_page_end();
}catch(Throwable $error){
    // Say why in plain words. The server log keeps only the reason code, the exception class and
    // the client id: never an identity, token, pairing id or exception message.
    $reason=$error instanceof PortalDesktopException?$error->reason:'unexpected';
    error_log('safeharbor desktop authorize refused: reason='.preg_replace('/[^a-z0-9_]/','',$reason)
        .($error instanceof PortalDesktopException?'':' error='.preg_replace('/[^A-Za-z0-9_\\\\]/','',get_class($error)))
        .' client='.(is_array($context)?(int)($context['identity']['client_id']??0):0));
    $refusal=portal_desktop_refusal($reason,is_array($context)?($context['binding']['client_name']??null):null);
    portal_render_error($refusal['status'],$refusal['title'],$refusal['message']);
}
