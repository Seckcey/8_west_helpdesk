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
    portal_render_error(405, 'Method not allowed', 'Return to the customer portal and start sign-in again.');
    exit;
}

$client = null;
$failure = null;
try {
    portal_session_start();
    $client = portal_oidc_client();
    $stateCookie = $_COOKIE[$client->stateCookieName()] ?? '';
    $identity = $client->handleCallback(
        $_GET,
        is_string($stateCookie) ? $stateCookie : '',
    );
    portal_establish_identity(db(), $identity);
} catch (Throwable $error) {
    $failure = $error;
    portal_destroy_session();
} finally {
    if ($client !== null) $client->clearStateCookie();
}

if ($failure !== null) {
    $reason = portal_callback_failure_reason($failure);
    error_log('[safeharbor-portal] callback_denied reason=' . $reason . ' type=' . $failure::class);
    portal_render_error(401, 'Sign-in not completed', 'Return to the customer portal and try again.');
    exit;
}

header('Cache-Control: no-store');
header('Location: /portal/', true, 302);
exit;
