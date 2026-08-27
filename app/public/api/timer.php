<?php
/**
 * POST /api/timer.php — submit timer or suggested technician time.
 *
 * The browser supplies a stable entry_key and UTC work facts. The service
 * returns an exact replay for a safe retry, or 409 if that key was reused for
 * different facts. New rows always enter the approval queue as pending.
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

if (!in_array($in['source'] ?? null, ['timer', 'suggestion', 'reply'], true)) {
    json_out(['ok' => false, 'error' => 'Source must be timer, suggestion, or an authorized reply retry'], 422);
}
if (array_key_exists('corrects_time_entry_id', $in)) {
    json_out(['ok' => false, 'error' => 'Use the rejected-time correction endpoint for replacements.'], 422);
}
if (!array_key_exists('billable', $in)) {
    $in['billable'] = true;
}
if (!array_key_exists('note', $in) || $in['note'] === '') {
    $ticketLabel = is_int($in['ticket_id'] ?? null) || is_string($in['ticket_id'] ?? null)
        ? (string) $in['ticket_id']
        : '';
    $in['note'] = $ticketLabel !== '' ? 'Work on #' . $ticketLabel : 'Technician work';
}

try {
    $pdo = db();
    $replyRetryFingerprint = null;
    $replyRequestFingerprint = null;
    if (($in['source'] ?? null) === 'reply') {
        $replyRequestFingerprint = time_entry_retry_request_fingerprint($in);
        $normalized = time_entry_validate_create_input($in);
        $replyRetryFingerprint = time_entry_retry_fingerprint($normalized);
        $entryKey = (string)$normalized['entry_key'];
        $existing = time_entry_find_by_key($pdo, (int)$user['tenant_id'], $entryKey);
        $existingReplay = is_array($existing) && (int)$existing['user_id'] === (int)$user['id'];
        $grant = is_array($_SESSION['time_entry_retry'] ?? null) ? $_SESSION['time_entry_retry'] : [];
        $actorGrant = (int)($grant['tenant_id'] ?? 0) === (int)$user['tenant_id']
            && (int)($grant['user_id'] ?? 0) === (int)$user['id']
            && is_string($grant['entry_key'] ?? null)
            && hash_equals((string)$grant['entry_key'], $entryKey);
        $rawGrant = is_string($grant['request_fingerprint'] ?? null)
            && hash_equals((string)$grant['request_fingerprint'], $replyRequestFingerprint);
        $normalizedGrant = is_string($grant['fingerprint'] ?? null)
            && hash_equals((string)$grant['fingerprint'], $replyRetryFingerprint);
        $grantedRetry = $actorGrant && ($rawGrant || $normalizedGrant);
        if (!$existingReplay && !$grantedRetry) {
            throw new TimeEntryForbiddenException('Reply-time retry is not authorized for these facts.');
        }
    }

    $entry = time_entry_create($pdo, (int) $user['tenant_id'], (int) $user['id'], $in);
    if ($replyRetryFingerprint !== null && $replyRequestFingerprint !== null) {
        $grant = is_array($_SESSION['time_entry_retry'] ?? null) ? $_SESSION['time_entry_retry'] : [];
        $rawMatch = is_string($grant['request_fingerprint'] ?? null)
            && hash_equals((string)$grant['request_fingerprint'], $replyRequestFingerprint);
        $normalizedMatch = is_string($grant['fingerprint'] ?? null)
            && hash_equals((string)$grant['fingerprint'], $replyRetryFingerprint);
        if ($rawMatch || $normalizedMatch) {
            unset($_SESSION['time_entry_retry']);
        }
    }
} catch (Throwable $error) {
    $status = time_entry_http_status($error);
    if ($status === 500) {
        error_log('time entry create: ' . $error->getMessage());
        json_out(['ok' => false, 'error' => 'Time could not be submitted just now.'], 500);
    }
    $response = ['ok' => false, 'error' => $error->getMessage()];
    if ($error instanceof TimeEntryForbiddenException
        && $error->getMessage() === 'Reply-time retry is not authorized for these facts.') {
        $response['code'] = 'reply_retry_unconfirmed';
    }
    json_out($response, $status);
}

$minutes = (int) $entry['minutes'];
$ticketId = (int) $entry['ticket_id'];
$replayed = (bool) $entry['replayed'];
json_out([
    'ok' => true,
    'replayed' => $replayed,
    'toast' => $replayed
        ? "Time already submitted · {$minutes}m on #{$ticketId}"
        : "Time submitted for approval · {$minutes}m on #{$ticketId}",
    'entry' => $entry,
]);
