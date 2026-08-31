<?php
/**
 * POST /api/time_entry_adjustment.php — append one owner/admin adjustment to
 * an approved time entry without rewriting the original approval evidence.
 *
 * Body: {entry_id, adjustment_key, expected_version, effective_minutes,
 *        effective_billable, reason}
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$user = current_user();
if (!$user && suite_sso_attempt()) $user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
csrf_check();

if (!in_array((string) ($user['role'] ?? ''), TIME_ENTRY_ADJUSTMENT_ROLES, true)) {
    json_out(['ok' => false, 'error' => 'Only owners and admins can adjust approved technician time.'], 403);
}

$raw = (string) file_get_contents('php://input');
if (trim($raw) !== '') {
    try {
        $in = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }
    if (!is_array($in)) json_out(['ok' => false, 'error' => 'JSON object required'], 400);
} else {
    $in = $_POST;
}

try {
    $allowedFields = [
        'entry_id',
        'adjustment_key',
        'expected_version',
        'effective_minutes',
        'effective_billable',
        'reason',
    ];
    if (array_diff(array_keys($in), $allowedFields) !== []) {
        throw new TimeEntryValidationException('Adjustment contains an unexpected field.');
    }

    $adjustment = time_entry_adjustment_create(
        db(),
        (int) $user['tenant_id'],
        (int) $user['id'],
        (string) $user['role'],
        $in,
    );
} catch (Throwable $error) {
    $status = time_entry_http_status($error);
    if ($status === 500) {
        error_log('time entry adjustment: ' . $error->getMessage());
        json_out(['ok' => false, 'error' => 'Approved time adjustment could not be saved just now.'], 500);
    }
    json_out(['ok' => false, 'error' => $error->getMessage()], $status);
}

$minutes = (int) $adjustment['effective_minutes'];
$billing = (bool) $adjustment['effective_billable'] ? 'billable' : 'internal';
json_out([
    'ok' => true,
    'toast' => (bool) $adjustment['replayed']
        ? "Adjustment already saved · {$minutes}m {$billing}"
        : "Approved time adjusted · {$minutes}m {$billing}",
    'adjustment_ack' => [
        'adjustment_key' => (string) $adjustment['adjustment_key'],
        'entry_id' => (int) $adjustment['time_entry_id'],
        'version_no' => (int) $adjustment['version'],
        'effective_minutes' => $minutes,
        'effective_billable' => (bool) $adjustment['effective_billable'],
        'adjusted_by_user_id' => (int) $adjustment['actor_user_id'],
        'reason' => (string) $adjustment['reason'],
        'replayed' => (bool) $adjustment['replayed'],
    ],
    'adjustment' => $adjustment,
]);
