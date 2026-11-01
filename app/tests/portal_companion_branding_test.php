<?php
/** Hermetic rendered branding boundaries; no session, backend or device operations. */
declare(strict_types=1);
function cfg(string $key, mixed $default = null): mixed { return $key==='tenant_branding' ? ($GLOBALS['brand_config']??$default) : $default; }
function portal_csrf_token(): string { return str_repeat('c', 64); }
function portal_safe_return_path(mixed $value): string { return '/portal/'; }
require_once __DIR__.'/../lib/portal_render.php';

$checks = 0;
function brand_check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function brand_render(string $uri, array $session, string $view = 'workspace'): string
{
    $_SERVER['REQUEST_URI'] = $uri;
    $_SESSION = $session;
    $context = ['identity'=>['display_name'=>'Synthetic <Owner>', 'role'=>'client_owner'],
        'binding'=>['client_name'=>'Synthetic business']];
    ob_start();
    if ($view === 'login') portal_render_login();
    elseif ($view === 'error') portal_render_error(503, 'Westy could not connect', 'Reconnect and try again.');
    elseif ($view === 'connect') { portal_page_start('Connect Westy'); portal_page_end(); }
    elseif ($view === 'signed-out') portal_render_signed_out(false);
    else portal_render_workspace($context);
    $html = (string)ob_get_clean();
    brand_check($_SESSION === $session, 'branding must never change session state');
    return $html;
}

$ordinaryLogo = '/assets/brand/8-west-it-westy.png';
$newLogo = '/assets/brand/westy-companion/westy-horizontal-logo-v1.png';
$newAvatar = '/assets/brand/westy-companion/westy-1.png';
$pair = str_repeat('a', 32);
foreach (['/portal/', '/portal/?companion=1', '/portal/desktop.php.extra', '/portal/desktop_authorize.php?handoff=synthetic'] as $uri) {
    $html = brand_render($uri, []);
    brand_check(str_contains($html, '· Safeharbor</title>') && str_contains($html, $ordinaryLogo), 'ordinary portal uses supplied Westy artwork and retains Safeharbor identity');
    brand_check(str_contains($html, 'alt="8 West IT with Westy"') && str_contains($html, 'width="2172" height="724"'), 'ordinary artwork has accurate alternative text and intrinsic dimensions');
    brand_check(!str_contains($html, $newLogo) && !str_contains($html, $newAvatar), 'query flags do not rebrand the portal');
}
foreach (['', 'not-a-pair', ['a'], str_repeat('a', 33)] as $invalid) {
    $html = brand_render('/portal/', ['desktop_companion_session'=>$invalid]);
    brand_check(str_contains($html, '· Safeharbor</title>'), 'malformed companion markers retain ordinary branding');
}
foreach ([['/portal/desktop.php?pair='.$pair, []], ['/portal/', ['desktop_companion_session'=>$pair]],
    ['/portal/devices.php', ['desktop_companion_session'=>$pair]]] as [$uri, $session]) {
    $html = brand_render($uri, $session);
    brand_check(str_contains($html, '· Westy Companion</title>'), 'Companion page title is Westy');
    brand_check(str_contains($html, $newLogo) && !str_contains($html, $ordinaryLogo), 'Companion header and sidebar use authentic artwork');
    brand_check(str_contains($html, 'width="2172" height="724"'), 'horizontal artwork keeps its intrinsic ratio');
    brand_check(str_contains($html, 'data-avatar="'.$newAvatar.'"'), 'dynamic replies receive the Companion avatar');
    brand_check(str_contains($html, '<img src="'.$newAvatar.'" width="64"'), 'empty conversation uses the original mascot');
    brand_check(str_contains($html, 'Synthetic &lt;Owner&gt;'), 'identity escaping is preserved');
    brand_check(str_contains($html, 'id="portal-chat-send"') && str_contains($html, 'id="portal-desktop-stop"'), 'chat and control affordances are preserved');
}
$connect = brand_render('/portal/desktop.php?pair='.$pair, [], 'connect');
brand_check(str_contains($connect, 'Connect Westy · Westy Companion</title>') && str_contains($connect, 'type="image/png"'), 'pre-sign-in Companion has Westy title and favicon');
$error = brand_render('/portal/desktop.php?pair='.$pair, [], 'error');
brand_check(str_contains($error, $newLogo) && !str_contains($error, $ordinaryLogo), 'Companion connection error retains Westy branding');
$signedOut = brand_render('/portal/signed-out.php', [], 'signed-out');
brand_check(str_contains($signedOut, 'Signed out · Westy Companion</title>') && str_contains($signedOut, $newLogo) && !str_contains($signedOut, $ordinaryLogo), 'the signed-out page keeps Westy branding with no session left');
brand_check(!str_contains($signedOut, 'Synthetic'), 'the signed-out page shows nothing about who was signed in');
$ordinaryError = brand_render('/portal/devices.php', [], 'error');
brand_check(str_contains($ordinaryError, $ordinaryLogo) && !str_contains($ordinaryError, $newLogo), 'ordinary errors use the supplied customer artwork');
$login = brand_render('/portal/', [], 'login');
brand_check(str_contains($login, $ordinaryLogo) && str_contains($login, 'Customer portal · Safeharbor</title>'), 'ordinary sign-in uses supplied artwork and retains the actual product name');
brand_check(hash_file('sha256', __DIR__.'/../public'.$ordinaryLogo) === '159c7a3946bfbd80091bb061ed95139f4fad29ba7914faea726aeaea6b6d050d', 'supplied customer PNG bytes are retained');
foreach (['westy-horizontal-logo-v1.png'=>'77495b2260a478ebdd5cc1c92bda1b6748e2df1f041e44227ad18218ff049ab4',
    'westy-1.png'=>'86300229fb0792f20e67ca0bb3e9be7c4decb3a43c059f05ebd098256cb6df35'] as $file=>$hash) {
    brand_check(hash_file('sha256', __DIR__.'/../public/assets/brand/westy-companion/'.$file) === $hash, 'original SharePoint asset bytes are retained');
}
$GLOBALS['brand_config']=['service_secret'=>str_repeat('ab',32)];
$_SESSION=[];$_SERVER['REQUEST_URI']='/portal/';
$context=['binding'=>['provider_tenant_slug'=>'alpha']];
$branded=portal_branding($context);
brand_check($branded['logo']==='/portal/tenant-brand-logo.php?surface=safeharbor', 'verified ordinary context chooses fixed media route');
brand_check($branded['alt']==='Company logo', 'tenant artwork has neutral alternative text');
brand_check(portal_branding(['binding'=>['provider_tenant_slug'=>'beta']])['logo']===$branded['logo'], 'switch never puts provider identity in URL');
foreach ([null,[],['identity'=>['tenant_slug'=>'alpha']],['binding'=>['provider_tenant_slug'=>'8west']],['binding'=>['provider_tenant_slug'=>'internal']],['binding'=>['provider_tenant_slug'=>'bad/slug']]] as $unverified) {
    brand_check(portal_branding($unverified)['logo']===$ordinaryLogo, 'unverified or house context retains default');
}
$_SESSION=['desktop_companion_session'=>$pair];
$companion=portal_branding($context);
brand_check($companion['logo']==='/portal/tenant-brand-logo.php?surface=companion', 'valid existing pair chooses only Companion fallback surface');
brand_check($companion['avatar']===$newAvatar && $companion['name']==='Westy Companion', 'company logo preserves product and mascot');
$GLOBALS['brand_config']=[];
brand_check(portal_branding($context)['logo']===$newLogo, 'missing key preserves Companion default');
$_SESSION=[];$_SERVER['REQUEST_URI']='/portal/';
brand_check(portal_branding($context)['logo']===$ordinaryLogo, 'missing key preserves ordinary default');
echo "PASS $checks Companion branding checks\n";
