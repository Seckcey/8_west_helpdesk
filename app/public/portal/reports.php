<?php
/** Tenant/client-bound access to immutable weekly service-summary archives. */
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
    portal_render_error(405, 'Method not allowed', 'Use the service-summary page to read archived reports.');
    exit;
}

try {
    $context = portal_authenticated_context(db());
} catch (PortalIdentityUnavailableException $error) {
    error_log('[safeharbor-portal] reports_denied reason=revocation_unavailable');
    portal_render_error(503, 'Temporarily unavailable', $error->getMessage());
    exit;
} catch (Throwable $error) {
    error_log('[safeharbor-portal] reports_context_failed type=' . $error::class);
    portal_render_error(503, 'Temporarily unavailable', 'The customer portal is temporarily unavailable. Try again shortly.');
    exit;
}
if ($context === null) {
    portal_require_sign_in('Sign in before viewing weekly service summaries.');
    exit;
}

$tenantId = (int)$context['identity']['tenant_id'];
$clientId = (int)$context['identity']['client_id'];
$rawArchiveId = $_GET['id'] ?? null;
if ($rawArchiveId !== null
    && (! is_string($rawArchiveId)
        || preg_match('/\A[1-9][0-9]{0,18}\z/D', $rawArchiveId) !== 1)) {
    portal_render_error(404, 'Service summary not found', 'That service summary is not available for this business.');
    exit;
}

try {
    if ($rawArchiveId === null) {
        portal_render_reports($context, portal_report_archives(db(), $tenantId, $clientId, 24));
        exit;
    }
    portal_render_report($context, portal_report_archive(db(), $tenantId, $clientId, (int)$rawArchiveId));
} catch (PortalDataNotFoundException|PortalDataValidationException $error) {
    portal_render_error(404, 'Service summary not found', 'That service summary is not available for this business.');
} catch (Throwable $error) {
    error_log('[safeharbor-portal] reports_read_failed type=' . $error::class);
    portal_render_error(503, 'Service summaries unavailable', 'Archived service summaries could not be safely verified. Try again shortly.');
}
