<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_render.php';
enforce_https();
if(!portal_enabled()){http_response_code(404);exit;}
try {
    if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST'],true)){
        header('Allow: GET, POST');portal_render_error(405,'Method not allowed','Open the customer selection page.');exit;
    }
    portal_session_start();
    $pending=$_SESSION[PORTAL_ACCESS_PENDING_KEY]??null;
    $identity=portal_local_identity();
    if($identity!==null){
        $principal=array_intersect_key($identity,array_flip(['subject','session_version','identity_tenant_slug','role','display_name','issued_at','expires_at']));
        $destination='/portal/';
    } elseif(is_array($pending) && is_int($pending['expires_at']??null) && $pending['expires_at']>time()) {
        $principal=$pending['principal'];$destination=portal_safe_return_path($pending['destination']??'/portal/');
    } else {unset($_SESSION[PORTAL_ACCESS_PENDING_KEY]);portal_require_sign_in();exit;}
    if(($principal['expires_at']??0)<=time() || portal_oidc_client()->isRevoked($principal['subject'],$principal['session_version'])){
        portal_destroy_session();portal_require_sign_in();exit;
    }
    $choices=portal_access_choices(db(),$principal);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
        if(!portal_devices_keys($_POST,['csrf','reference','generation']) || !portal_csrf_valid($_POST['csrf']??null) || !is_string($_POST['reference']??null)
            || !is_string($_POST['generation']??null) || preg_match('/\A[1-9][0-9]{0,9}\z/D',$_POST['generation'])!==1)
            throw new PortalAuthenticationRejectedException('Review your customer selection again.');
        portal_access_select(db(),$principal,$_POST['reference'],(int)$_POST['generation']);
        header('Cache-Control: no-store');header('Location: '.$destination,true,303);exit;
    }
    portal_render_client_choices($principal,$choices);
} catch(Throwable $error) {
    error_log('[safeharbor-portal] customer_access_failed type='.$error::class);
    portal_render_error(403,'Customer access unavailable','Your customer access may have changed. Return to the client selection page to review your current access.');
}
