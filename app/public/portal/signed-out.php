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
    portal_render_error(405, 'Method not allowed', 'Sign in again from your Westy window.');
    exit;
}

// Starts no session: the page says nothing about who is looking, and what follows a sign-out must not set a cookie.
portal_render_signed_out(($_GET['connection'] ?? null) === 'open');
