<?php
/** Explicit owner/admin handoff of reviewed time to a Coastmark draft. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/coastmark_time_billing.php';
enforce_https();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
$user = current_user();
if (!$user && suite_sso_attempt()) $user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
csrf_check();
if (!in_array((string)$user['role'], ['owner', 'admin'], true)) {
    json_out(['ok' => false, 'error' => 'Only owners and admins can send approved time to billing.'], 403);
}
try {
    $input = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Request must be a JSON object.');
    $entryId = time_entry_validate_integer($input['entry_id'] ?? null, 'Entry id', 1, 4294967295);
    if (!is_string($input['action'] ?? null)) throw new InvalidArgumentException('Billing action is required.');
    $config = (array)cfg('coastmark_time_export', []);
    $pdo = coastmark_time_export_database((array)cfg('db', []), $config);
    $state = coastmark_time_billing_operation($pdo, $user, $entryId, $input['action'], $config);
    json_out(['ok' => true, 'billing' => $state, 'toast' => $state['label'] . '. Review the invoice in Coastmark.']);
} catch (JsonException|InvalidArgumentException $error) {
    json_out(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (CoastmarkTimeExportAmbiguousException $error) {
    json_out(['ok' => false, 'error' => 'Delivery is not confirmed yet. Use Check billing status before sending again.', 'refresh' => true], 409);
} catch (CoastmarkTimeExportConflictException $error) {
    json_out(['ok' => false, 'error' => $error->getMessage(), 'refresh' => true], 409);
} catch (Throwable $error) {
    error_log('time entry billing: ' . get_class($error));
    json_out(['ok' => false, 'error' => 'Billing is unavailable just now. Refresh this entry to check its status.'], 503);
}
