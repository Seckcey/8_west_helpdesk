<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_data.php';
require_once __DIR__ . '/../../lib/portal_devices.php';
require_once __DIR__ . '/../../lib/portal_render.php';

enforce_https();
if (! portal_enabled()) {
    http_response_code(404);
    exit('Not found.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    portal_render_error(405, 'Method not allowed', 'Use the customer portal page to view ticket summaries.');
    exit;
}

try {
    $context = portal_authenticated_context(db());
} catch (PortalIdentityUnavailableException $error) {
    error_log('[safeharbor-portal] request_denied reason=revocation_unavailable');
    portal_render_error(503, 'Temporarily unavailable', $error->getMessage());
    exit;
} catch (Throwable $error) {
    error_log('[safeharbor-portal] request_failed type=' . $error::class);
    portal_render_error(503, 'Temporarily unavailable', 'The customer portal is temporarily unavailable. Try again shortly.');
    exit;
}

if ($context === null) {
    portal_require_sign_in();
    exit;
}

// The Westy workspace is unchanged; only its address moved off Home.
if (($_GET['view'] ?? null) === 'westy') {
    portal_render_workspace($context);
    exit;
}

// Home reads only through the existing requester-safe helpers, with the signed-in
// identity: members get their own requests, owners and admins the client-wide view.
$identity = $context['identity'];
$summary = null;
try {
    $summary = portal_ticket_summary(db(), (int)$identity['tenant_id'], (int)$identity['client_id'], 6, $identity);
} catch (Throwable $error) {
    error_log('[safeharbor-portal] home_summary_failed type=' . $error::class);
}

// Optional: Milepost decides which computers this person may see. A failure is shown
// as unavailable, never as "no computers".
$devices = null;
try {
    $devices = portal_devices_request(db(), $context, 'devices', ['after' => 0]);
} catch (Throwable $error) {
    error_log('[safeharbor-portal] home_devices_failed type=' . $error::class);
}

$reportArchives = null;
try {
    if (portal_client_admin($identity)) $reportArchives = portal_report_archives(
        db(),
        (int)$identity['tenant_id'],
        (int)$identity['client_id'],
        1,
        $identity,
    );
} catch (Throwable $error) {
    error_log('[safeharbor-portal] home_report_summary_failed type=' . $error::class);
}

// Nothing read above is shown after a revocation or binding change during those reads.
try {
    $fresh = portal_authenticated_context(db());
} catch (PortalIdentityUnavailableException $error) {
    error_log('[safeharbor-portal] request_denied reason=revocation_unavailable');
    portal_render_error(503, 'Temporarily unavailable', $error->getMessage());
    exit;
} catch (Throwable $error) {
    error_log('[safeharbor-portal] request_failed type=' . $error::class);
    portal_render_error(503, 'Temporarily unavailable', 'The customer portal is temporarily unavailable. Try again shortly.');
    exit;
}
if ($fresh === null || $fresh['identity'] !== $identity) {
    portal_require_sign_in('Please sign in again.');
    exit;
}

portal_render_home($fresh, $summary, $devices, $reportArchives);
