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
$foreign = false;
try {
    portal_session_start();
    $client = portal_oidc_client();
    $stateCookie = $_COOKIE[$client->stateCookieName()] ?? '';
    $identity = $client->handleCallback(
        $_GET,
        is_string($stateCookie) ? $stateCookie : '',
    );
    $destination = portal_take_login_return(is_string($_GET['state'] ?? null) ? $_GET['state'] : '');
    portal_establish_identity(db(), $identity, null, $destination);
    if(isset($_SESSION[PORTAL_ACCESS_PENDING_KEY]))$destination='/portal/clients.php';
} catch (Throwable $error) {
    $failure = $error;
    // Any site can link here, and the link carries the Lax portal cookie. A callback without this browser's own state
    // is not its sign-in, so it leaves the session, and any sign-in under way, as they were.
    $foreign = portal_callback_is_foreign($error);
    if (! $foreign) portal_destroy_session();
} finally {
    if ($client !== null && ! $foreign) $client->clearStateCookie();
}

if ($failure !== null) {
    $reason = portal_callback_failure_reason($failure);
    error_log('[safeharbor-portal] callback_denied reason=' . $reason . ' type=' . $failure::class);
    portal_render_error(401, 'Sign-in not completed', 'Return to the customer portal and try again.');
    exit;
}

header('Cache-Control: no-store');
header('Location: ' . $destination, true, 302);
exit;
