<?php
/**
 * POST /api/svc/customers.php — Milepost customer registry intake.
 *
 * Default-off, exact-service, destination-specific HMAC. The signed v1 body
 * resolves the destination Safeharbor tenant by slug; no session tenant and no
 * name/domain inference is permitted.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../lib/suite_customer_sync.php';

if (!suite_customer_sync_enabled()) {
    json_out(['ok' => false, 'error' => 'not found'], 404);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'method not allowed'], 405);
}

$rawBody = (string)file_get_contents('php://input');
try {
    $payload = suite_customer_sync_decode($rawBody);
} catch (SuiteCustomerSyncValidationException $error) {
    json_out(['ok' => false, 'error' => $error->getMessage()], 400);
}

$tenant = suite_customer_sync_resolve_tenant(db(), (string)$payload['tenant_slug']);
if ($tenant === null) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

try {
    $auth = suite_customer_sync_authenticate(
        db(),
        $tenant['id'],
        $tenant['slug'],
        (string)($_SERVER['HTTP_X_8W_SERVICE'] ?? ''),
        (string)($_SERVER['HTTP_X_8W_TIMESTAMP'] ?? ''),
        (string)($_SERVER['HTTP_X_8W_SIGNATURE'] ?? ''),
        $rawBody,
    );
} catch (Throwable $error) {
    error_log('suite customer sync auth: ' . $error::class);
    json_out(['ok' => false, 'error' => 'server error'], 500);
}
if (!$auth['ok']) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

try {
    $result = suite_customer_sync_receive(db(), $payload, hash('sha256', $rawBody));
} catch (SuiteCustomerSyncConflictException $error) {
    $body = ['ok' => false, 'error' => $error->errorCode];
    if ($error->expectedSourceVersion !== null) {
        $body['expected_source_version'] = $error->expectedSourceVersion;
    }
    json_out($body, 409);
} catch (SuiteCustomerSyncValidationException $error) {
    json_out(['ok' => false, 'error' => $error->getMessage()], 400);
} catch (SuiteCustomerSyncGateException $error) {
    error_log('suite customer sync gate: ' . $error->getMessage());
    json_out(['ok' => false, 'error' => 'server error'], 500);
} catch (Throwable $error) {
    error_log('suite customer sync intake: ' . $error::class);
    json_out(['ok' => false, 'error' => 'server error'], 500);
}

json_out($result);
