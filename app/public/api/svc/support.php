<?php
/**
 * POST /api/svc/support.php — human support requests raised inside another
 * 8 West product (contract: docs/coastmark-support-intake-contract.md).
 * NOT suite-only: Waypoint is a standalone product outside 8 West IT 365.
 * LIVE since 2026-08-09 — Coastmark and Waypoint both emitting.
 *
 * HMAC-authenticated service traffic only — no session, no CSRF token. Same
 * verifier as api/svc/alerts.php and api/svc/westy.php (lib/svc_auth.php,
 * unchanged): hex HMAC-SHA256 over "{timestamp}\n{raw_body}", ±300s,
 * 120 req/min per identity, plus a per-tenant cap of our own.
 *
 * Only identities listed in SUPPORT_SOURCES may post here. A valid signature
 * from the Milepost alert identity is still a 401 on this door: signing keys
 * prove who you are, not what you are allowed to file.
 *
 * Kill switch is svc.support_enabled AND svc.enabled. The shared flag is
 * already true in production, so this endpoint keeps its own — turning
 * support intake on must not be a side effect of alert intake being on.
 *
 * Status map: 200 handled · 401 bad/absent/wrong-job auth · 404 disabled ·
 * 405 non-POST · 422 bad payload · 429 over rate · 500 fault (caller retries).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../lib/svc_auth.php';
require_once __DIR__ . '/../../../lib/svc_support.php';

if (!support_enabled()) {
    json_out(['ok' => false, 'error' => 'not found'], 404);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'method not allowed'], 405);
}

$rawBody = (string)file_get_contents('php://input');
if ($rawBody === '' || strlen($rawBody) > SVC_MAX_BODY_BYTES) {
    json_out(['ok' => false, 'error' => 'bad body'], 422);
}

$service = (string)($_SERVER['HTTP_X_8W_SERVICE'] ?? '');

try {
    $auth = svc_authenticate(
        $service,
        (string)($_SERVER['HTTP_X_8W_TIMESTAMP'] ?? ''),
        (string)($_SERVER['HTTP_X_8W_SIGNATURE'] ?? ''),
        $rawBody
    );
} catch (Throwable $e) {
    error_log('svc support auth: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server error'], 500);
}
if (!$auth['ok']) {
    json_out(['ok' => false, 'error' => $auth['code'] === 429 ? 'rate limited' : 'unauthorized'], $auth['code']);
}
// Authenticated, but is this identity a support producer? Same generic 401 —
// no oracle telling a caller which of the two checks it failed.
if (support_source_for($service) === null) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    json_out(['ok' => false, 'error' => 'invalid json'], 422);
}

try {
    $result = support_record($payload, $service);
} catch (Throwable $e) {
    // Clean 500: the caller's outbox backs off and retries, the retry is
    // idempotent on external_key, and no stack trace reaches the wire.
    error_log('svc support intake: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server error'], 500);
}
if (!$result['ok']) {
    json_out(
        ['ok' => false, 'error' => $result['error'] ?? 'bad payload'],
        (int)($result['code'] ?? 422)
    );
}

json_out([
    'ok'     => true,
    'ticket' => $result['ticket'] ?? null,
    'action' => $result['action'] ?? 'ignored',
]);
