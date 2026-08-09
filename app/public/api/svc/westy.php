<?php
/**
 * POST /api/svc/westy.php — Westy failure + flagged-answer intake from the
 * other suite apps (contract: docs/westy-failure-reporting-contract.md).
 *
 * HMAC-authenticated service traffic only — no session, no CSRF token. Same
 * verifier as api/svc/alerts.php (lib/svc_auth.php, unchanged): hex
 * HMAC-SHA256 over "{timestamp}\n{raw_body}", ±300s, 120 req/min per identity.
 *
 * Safeharbor does NOT call this endpoint. Its own Westy failures go through
 * lib/westy_report.php in-process — no network hop to sign to itself, and it
 * still works when the web server is the thing having a bad day.
 *
 * Callers register their own identity (milepost-westy, controlpanel-westy),
 * deliberately separate from the `milepost` alert identity so a Westy failure
 * storm cannot burn the rate budget real alert intake depends on.
 *
 * Status map: 200 handled · 401 bad/absent auth · 404 svc disabled ·
 * 405 non-POST · 422 bad payload · 429 over rate · 500 fault (caller retries).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../lib/svc_auth.php';
require_once __DIR__ . '/../../../lib/westy_report.php';

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
    error_log('svc westy auth: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server error'], 500);
}
if (!$auth['ok']) {
    json_out(['ok' => false, 'error' => $auth['code'] === 429 ? 'rate limited' : 'unauthorized'], $auth['code']);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    json_out(['ok' => false, 'error' => 'invalid json'], 422);
}

try {
    $result = westy_report_record($payload);
} catch (Throwable $e) {
    // Clean 500: the caller's outbox backs off and retries; no stack trace and
    // no credential ever reaches the wire.
    error_log('svc westy intake: ' . $e->getMessage());
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
