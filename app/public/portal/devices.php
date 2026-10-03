<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_devices_render.php';
enforce_https();
if (!portal_enabled()) { http_response_code(404); exit('Not found.'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); portal_render_error(405, 'Method not allowed', 'Open Your devices from the portal.'); exit; }
try {
    $context = portal_authenticated_context(db());
    if ($context === null) { portal_require_sign_in(); exit; }
    $error = null; $download = null; $notice = null; $devices = null; $enrollments = [];
    try {
        if ($method === 'POST') {
            if (!portal_csrf_valid($_POST['csrf'] ?? null)) throw new PortalDevicesException('sign_in', 403);
            $action = $_POST['action'] ?? '';
            if (!is_string($action) || !in_array($action, ['enrollment_create','enrollment_download','enrollment_revoke'], true)) throw new PortalDevicesException('invalid_request', 400);
            if ($action === 'enrollment_create') {
                if (($_POST['consent'] ?? '') !== 'yes') throw new PortalDevicesException('consent_required', 400);
                $result = portal_devices_request(db(), $context, $action, ['request_key'=>$_POST['request_key'] ?? '', 'platform'=>'windows']);
                if (($result['state'] ?? '') === 'ready') $download = portal_devices_request(db(), $context, 'enrollment_download', ['reference'=>$result['reference']]);
                else $notice = 'This request already has an installation link. Its current status is shown below.';
            } else {
                $result = portal_devices_request(db(), $context, $action, ['reference'=>$_POST['reference'] ?? '']);
                if ($action === 'enrollment_download') $download = $result;
                else $notice = 'The installation link is no longer available. Any computer already enrolled is unchanged.';
            }
        }
    } catch (PortalDevicesException $e) { $error = portal_devices_error($e->reason); }
    try {
        $after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($after === false) $after = 0;
        $devices = portal_devices_request(db(), $context, 'devices', ['after'=>$after]);
        if (portal_devices_can_manage($context)) $enrollments = portal_devices_request(db(), $context, 'enrollments', [])['items'] ?? [];
    } catch (PortalDevicesException $e) { $error ??= portal_devices_error($e->reason); }
    // No response leaves this page after a revocation or binding change during a service request.
    $fresh = portal_authenticated_context(db());
    if ($fresh === null || $fresh['identity'] !== $context['identity']) { portal_require_sign_in('Please sign in again.'); exit; }
    portal_render_devices($fresh, $devices, $enrollments, $error, $download, $notice);
} catch (Throwable $e) {
    error_log('[safeharbor-portal-devices] request_failed type=' . $e::class);
    portal_render_error(503, 'Device services unavailable', 'Try again shortly. You can still contact the support team from the portal.');
}
