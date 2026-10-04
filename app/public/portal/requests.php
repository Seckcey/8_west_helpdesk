<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_data.php';
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

try {
    $summary = portal_ticket_summary(
        db(),
        (int)$context['identity']['tenant_id'],
        (int)$context['identity']['client_id'],
        50,
    );
} catch (Throwable $error) {
    error_log('[safeharbor-portal] summary_failed type=' . $error::class);
    portal_render_error(503, 'Temporarily unavailable', 'Ticket summaries are temporarily unavailable. Try again shortly.');
    exit;
}

$reportArchives = null;
try {
    $reportArchives = portal_report_archives(
        db(),
        (int)$context['identity']['tenant_id'],
        (int)$context['identity']['client_id'],
        3,
    );
} catch (Throwable $error) {
    // A report verification/read failure must hide reports, but it must not
    // take down the customer's ticket workflow.
    error_log('[safeharbor-portal] report_summary_failed type=' . $error::class);
}

portal_render_dashboard($context, $summary, $reportArchives);
