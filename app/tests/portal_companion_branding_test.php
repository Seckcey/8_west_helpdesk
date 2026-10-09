<?php
/** Hermetic rendered branding boundaries; no session, backend or device operations. */
declare(strict_types=1);
function cfg(string $key, mixed $default = null): mixed { return $default; }
function portal_csrf_token(): string { return str_repeat('c', 64); }
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
    if ($view === 'error') portal_render_error(503, 'Westy could not connect', 'Reconnect and try again.');
    elseif ($view === 'connect') { portal_page_start('Connect Westy'); portal_page_end(); }
    else portal_render_workspace($context);
    $html = (string)ob_get_clean();
    brand_check($_SESSION === $session, 'branding must never change session state');
    return $html;
}

$oldLogo = '/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png';
$newLogo = '/assets/brand/westy-companion/westy-horizontal-logo-v1.png';
$newAvatar = '/assets/brand/westy-companion/westy-1.png';
$pair = str_repeat('a', 32);
foreach (['/portal/', '/portal/?companion=1', '/portal/desktop.php.extra', '/portal/desktop_authorize.php?handoff=synthetic'] as $uri) {
    $html = brand_render($uri, []);
    brand_check(str_contains($html, '· Safeharbor</title>') && str_contains($html, $oldLogo), 'ordinary portal keeps its existing brand');
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
    brand_check(str_contains($html, $newLogo) && !str_contains($html, $oldLogo), 'Companion header and sidebar use authentic artwork');
    brand_check(str_contains($html, 'width="2172" height="724"'), 'horizontal artwork keeps its intrinsic ratio');
    brand_check(str_contains($html, 'data-avatar="'.$newAvatar.'"'), 'dynamic replies receive the Companion avatar');
    brand_check(str_contains($html, '<img src="'.$newAvatar.'" width="64"'), 'empty conversation uses the original mascot');
    brand_check(str_contains($html, 'Synthetic &lt;Owner&gt;'), 'identity escaping is preserved');
    brand_check(str_contains($html, 'id="portal-chat-send"') && str_contains($html, 'id="portal-desktop-stop"'), 'chat and control affordances are preserved');
}
$connect = brand_render('/portal/desktop.php?pair='.$pair, [], 'connect');
brand_check(str_contains($connect, 'Connect Westy · Westy Companion</title>') && str_contains($connect, 'type="image/png"'), 'pre-sign-in Companion has Westy title and favicon');
$error = brand_render('/portal/desktop.php?pair='.$pair, [], 'error');
brand_check(str_contains($error, $newLogo) && !str_contains($error, $oldLogo), 'Companion connection error retains Westy branding');
$ordinaryError = brand_render('/portal/devices.php', [], 'error');
brand_check(str_contains($ordinaryError, $oldLogo) && !str_contains($ordinaryError, $newLogo), 'ordinary errors retain Safeharbor branding');
foreach (['westy-horizontal-logo-v1.png'=>'77495b2260a478ebdd5cc1c92bda1b6748e2df1f041e44227ad18218ff049ab4',
    'westy-1.png'=>'86300229fb0792f20e67ca0bb3e9be7c4decb3a43c059f05ebd098256cb6df35'] as $file=>$hash) {
    brand_check(hash_file('sha256', __DIR__.'/../public/assets/brand/westy-companion/'.$file) === $hash, 'original SharePoint asset bytes are retained');
}
echo "PASS $checks Companion branding checks\n";
