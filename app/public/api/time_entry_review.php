<?php
/**
 * POST /api/time_entry_review.php — approve or reject pending technician time.
 * Body: {entry_id, decision: approved|rejected, note?}
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

if (!in_array((string) ($user['role'] ?? ''), TIME_ENTRY_APPROVAL_ROLES, true)) {
    json_out(['ok' => false, 'error' => 'Only owners and admins can review technician time.'], 403);
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
    $entryId = time_entry_validate_integer($in['entry_id'] ?? null, 'Entry id', 1, 4294967295);
    if (!is_string($in['decision'] ?? null)) {
        throw new TimeEntryValidationException('Decision must be approved or rejected.');
    }
    if (array_key_exists('note', $in) && !is_string($in['note'])) {
        throw new TimeEntryValidationException('Review note must be text.');
    }
    $entry = time_entry_review(
        db(),
        (int) $user['tenant_id'],
        (int) $user['id'],
        (string) $user['role'],
        $entryId,
        $in['decision'],
        (string) ($in['note'] ?? ''),
    );
} catch (Throwable $error) {
    $status = time_entry_http_status($error);
    if ($status === 500) {
        error_log('time entry review: ' . $error->getMessage());
        json_out(['ok' => false, 'error' => 'Time review could not be saved just now.'], 500);
    }
    json_out(['ok' => false, 'error' => $error->getMessage()], $status);
}

$decisionLabel = $entry['approval_status'] === 'approved' ? 'approved' : 'rejected';
json_out([
    'ok' => true,
    'toast' => 'Time entry #' . (int) $entry['id'] . ' ' . $decisionLabel . '.',
    'review_ack' => [
        'entry_id' => (int) $entry['id'],
        'decision' => $decisionLabel,
        'reviewer_user_id' => (int) $entry['reviewed_by_user_id'],
        'note' => (string) $entry['review_note'],
        'replayed' => (bool) $entry['replayed'],
    ],
    'entry' => $entry,
]);
