<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_render.php';

enforce_https();
if (! portal_enabled()) {
    http_response_code(404);
    exit('Not found.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    portal_render_error(405, 'Method not allowed', 'Sign out from the customer portal page.');
    exit;
}

portal_session_start();
if (! portal_csrf_valid($_POST['csrf'] ?? null)) {
    portal_render_error(403, 'Request rejected', 'The sign-out request expired. Return to the portal and try again.');
    exit;
}

try {
    $destination = portal_central_logout_url();
} catch (PortalAuthConfigurationException $error) {
    error_log('[safeharbor-portal] logout_config_failed');
    $destination = 'https://id.8westit.com/logout.php';
}
portal_destroy_session();
header('Cache-Control: no-store');
header('Location: ' . $destination, true, 302);
exit;
