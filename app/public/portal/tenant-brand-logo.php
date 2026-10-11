<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/tenant_branding.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405); header('Allow: GET'); exit; }
$surface=$_GET['surface']??'safeharbor';
try {
    portal_session_start();
    // This enum chooses only the fixed fallback picture; it grants no binding or access.
    if (array_diff(array_keys($_GET),['surface'])!==[] || !in_array($surface,['safeharbor','companion'],true)
        || !portal_enabled()) throw new RuntimeException('Default logo.');
    $context=portal_authenticated_context(db());
    $provider=safeharbor_tenant_brand_provider($context);
    $config=cfg('tenant_branding',[]);
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $logo=tenant_brand_fetch('safeharbor',$provider,is_array($config)?$config:[]);
    if ($logo===null) throw new RuntimeException('Default logo.');
    header('Content-Type: '.$logo['mime']);
    header('Content-Length: '.strlen($logo['bytes']));
    echo $logo['bytes'];
} catch (Throwable $error) {
    header('Location: '.safeharbor_tenant_brand_default($surface),true,302);
}
