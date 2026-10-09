<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_desktop_sessions.php';
require_once __DIR__.'/../../lib/portal_render.php';
enforce_https();
if(!portal_enabled()){http_response_code(404);exit;}
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
    portal_page_start('Connect your Westy window');
    ?>
<main class="page page-narrow"><h1>Connect your Westy window</h1>
<p>Connect as <?=portal_h($context['identity']['display_name'])?> for <?=portal_h($context['binding']['client_name']??'your business')?>.</p>
<p>Connect only if you just chose Continue in your browser in your own Westy window on your computer. This opens your private chat in that window. Westy follows your saved computer tool permissions for the work you request.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=portal_h(portal_csrf_token())?>"><button class="btn-primary" type="submit">Connect Westy</button></form></main>
<?php portal_page_end();
}catch(Throwable){portal_render_error(503,'Connection not completed','Start again from your Westy window.');}
