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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    portal_render_error(405, 'Method not allowed', 'Use the customer portal sign-in link.');
    exit;
}

try {
    portal_session_start();
    portal_oidc_client()->redirectToAuthorization();
} catch (Throwable $error) {
    error_log('[safeharbor-portal] login_failed type=' . $error::class);
    portal_render_error(503, 'Sign-in unavailable', '8 West ID sign-in is temporarily unavailable. Try again shortly.');
}
