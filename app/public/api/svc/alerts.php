<?php
/**
 * POST /api/svc/alerts.php — Milepost alert lifecycle intake
 * (Phase 8.1, Path B; design: docs/sprint-8.1-svc-alert-intake.md).
 *
 * HMAC-authenticated service traffic only — no session, no CSRF token.
 * Ships dark: answers 404 until the server config sets svc.enabled=true.
 *
 * Status map: 200 handled · 401 bad/absent auth · 405 non-POST ·
 * 422 bad payload · 429 over rate · 500 unexpected fault (emitter retries).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../lib/svc_auth.php';
require_once __DIR__ . '/../../../lib/svc_intake.php';

if (!svc_enabled()) {
    json_out(['ok' => false, 'error' => 'not found'], 404);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'method not allowed'], 405);
}

$rawBody = (string)file_get_contents('php://input');
if ($rawBody === '' || strlen($rawBody) > SVC_MAX_BODY_BYTES) {
    json_out(['ok' => false, 'error' => 'bad body'], 422);
}

try {
    $auth = svc_authenticate(
        (string)($_SERVER['HTTP_X_8W_SERVICE'] ?? ''),
        (string)($_SERVER['HTTP_X_8W_TIMESTAMP'] ?? ''),
        (string)($_SERVER['HTTP_X_8W_SIGNATURE'] ?? ''),
        $rawBody
    );
} catch (Throwable $e) {
    error_log('svc alerts auth: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server error'], 500);
}
if (!$auth['ok']) {
    json_out(['ok' => false, 'error' => $auth['code'] === 429 ? 'rate limited' : 'unauthorized'], $auth['code']);
}
// svc_auth.php authenticates every registered producer. This endpoint grants
// the narrower alert authority only to the dedicated Milepost identity; a
// support/Westy/customer-sync identity must never mint auto-close capability.
if (!svc_alert_service_authorized($auth)) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    json_out(['ok' => false, 'error' => 'invalid json'], 422);
}

try {
    $result = svc_alert_handle($payload);
} catch (Throwable $e) {
    // Clean 500: the emitter's outbox backs off and retries; nothing is
    // lost, and no stack trace or credential ever reaches the wire.
    error_log('svc alerts intake: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server error'], 500);
}
if (!$result['ok']) {
    json_out(['ok' => false, 'error' => $result['error'] ?? 'bad payload'], 422);
}

json_out([
    'ok'     => true,
    'ticket' => $result['ticket'] ?? null,
    'action' => $result['action'] ?? 'ignored',
]);
