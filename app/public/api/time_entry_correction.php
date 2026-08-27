<?php
/**
 * POST /api/time_entry_correction.php — append one pending replacement for
 * the authenticated technician's rejected entry.
 *
 * Ticket, client, technician, and source are derived from the immutable
 * rejected row. The browser may submit corrected work facts plus a stable
 * idempotency key; it cannot rewrite or remove the rejected history.
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
require_once __DIR__ . '/../../lib/revocation.php';
enforce_revocation($user);
csrf_check();

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
    $rejectedEntryId = time_entry_validate_integer(
        $in['rejected_entry_id'] ?? null,
        'Rejected entry id',
        1,
        4294967295,
    );
    unset($in['rejected_entry_id']);
    $entry = time_entry_correct(
        db(),
        (int) $user['tenant_id'],
        (int) $user['id'],
        $rejectedEntryId,
        $in,
    );
} catch (Throwable $error) {
    $status = time_entry_http_status($error);
    if ($status === 500) {
        error_log('time entry correction: ' . $error->getMessage());
        json_out(['ok' => false, 'error' => 'Corrected time could not be submitted just now.'], 500);
    }
    json_out(['ok' => false, 'error' => $error->getMessage()], $status);
}

$minutes = (int) $entry['minutes'];
$ticketId = (int) $entry['ticket_id'];
json_out([
    'ok' => true,
    'replayed' => (bool) $entry['replayed'],
    'toast' => (bool) $entry['replayed']
        ? "Correction already submitted · {$minutes}m on #{$ticketId}"
        : "Correction submitted for approval · {$minutes}m on #{$ticketId}",
    'entry' => $entry,
]);
