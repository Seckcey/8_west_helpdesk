<?php
/**
 * POST /api/ticket_bulk_delete.php — owners and admins permanently delete queue tickets
 * (docs/ticket-bulk-delete.md).
 *
 * {action:"preview", scope:{ids:[…]}}: what would go and what must stay, the exact list of ids that
 * can go (deletable_ids), a run key and the phrase to type. Nothing changes and nothing is locked.
 * {action:"delete", scope:{ids: deletable_ids}, after_id, run_key, confirm}: one batch of up to 100
 * tickets in one transaction. The scope must be exactly the preview's deletable list, so a run can
 * never reach a ticket the person did not see or go past the count they confirmed. The browser
 * repeats it with next_after_id until done.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/attachments.php';
require_once __DIR__ . '/../../lib/ticket_delete.php';
enforce_https();
ignore_user_abort(true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
$user = current_user();
if (!$user && suite_sso_attempt()) $user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
csrf_check();
if (!ticket_delete_allowed($user)) {
    json_out(['ok' => false, 'error' => 'Only owners and admins can delete tickets.'], 403);
}
$tenantId = (int)$user['tenant_id'];
$actorId = (int)$user['id'];

try {
    $in = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($in)) throw new TicketDeleteValidationException('Request must be a JSON object.');
    $scope = ticket_delete_scope($in['scope'] ?? null);
    $action = $in['action'] ?? null;

    if ($action === 'preview') {
        $preview = ticket_delete_preview(db(), $tenantId, $actorId, $scope);
        $runKey = bin2hex(random_bytes(16));
        $phrase = $preview['deletable'] > 0 ? 'DELETE ' . $preview['deletable'] : null;
        $previews = is_array($_SESSION['ticket_delete_previews'] ?? null) ? $_SESSION['ticket_delete_previews'] : [];
        $previews = array_filter($previews, static fn($entry): bool => is_array($entry) && is_int($entry['at'] ?? null)
            && time() - $entry['at'] <= TICKET_DELETE_PREVIEW_TTL);
        if ($phrase !== null) {
            $previews[$runKey] = ['tenant' => $tenantId, 'actor' => $actorId, 'at' => time(), 'phrase' => $phrase,
                'digest' => ticket_delete_scope_digest(['ids' => $preview['deletable_ids']])];
        }
        uasort($previews, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
        $_SESSION['ticket_delete_previews'] = array_slice($previews, 0, 3, true);
        json_out(['ok' => true] + $preview + ['run_key' => $runKey, 'phrase' => $phrase]);
    }

    if ($action !== 'delete') throw new TicketDeleteValidationException('Unknown action.');
    $afterId = $in['after_id'] ?? null;
    if (!is_int($afterId) || $afterId < 0) throw new TicketDeleteValidationException('This delete run is not valid; start again.');
    $runKey = $in['run_key'] ?? null;
    if (!is_string($runKey) || preg_match('/\A[0-9a-f]{32}\z/D', $runKey) !== 1) {
        throw new TicketDeleteValidationException('This delete run is not valid; start again.');
    }
    $confirm = $in['confirm'] ?? '';
    $refusal = ticket_delete_preview_check($_SESSION['ticket_delete_previews'][$runKey] ?? null,
        $tenantId, $actorId, ticket_delete_scope_digest($scope), is_string($confirm) ? $confirm : '', time());
    if ($refusal === 'preview_expired') {
        json_out(['ok' => false, 'code' => 'preview_expired', 'error' => 'This confirmation expired. Close and press Delete… again.'], 409);
    }
    if ($refusal === 'confirm_mismatch') {
        json_out(['ok' => false, 'code' => 'confirm_mismatch', 'error' => 'The confirmation text does not match.'], 422);
    }

    $batch = ticket_delete_batch(db(), $tenantId, $actorId, $scope, $afterId, $runKey, att_dir());
    error_log(sprintf('[safeharbor-ticket-delete] run=%s tenant=%d actor=%d deleted=%d kept=%d files_failed=%d',
        $runKey, $tenantId, $actorId, $batch['deleted'], count($batch['kept']), $batch['files_failed']));
    foreach ($batch['failed_files'] as $name) {
        error_log(sprintf('[safeharbor-ticket-delete] run=%s attachment file not removed: %s', $runKey, $name));
    }
    json_out(['ok' => true, 'deleted' => $batch['deleted'], 'kept' => $batch['kept'],
        'next_after_id' => $batch['next_after_id'], 'done' => $batch['done'], 'files_failed' => $batch['files_failed']]);
} catch (JsonException|TicketDeleteValidationException $error) {
    json_out(['ok' => false, 'error' => $error instanceof JsonException ? 'Request must be valid JSON.' : $error->getMessage()], 422);
} catch (TicketDeleteForbiddenException $error) {
    json_out(['ok' => false, 'error' => $error->getMessage()], 403);
} catch (TicketDeleteNotInstalledException $error) {
    json_out(['ok' => false, 'code' => 'not_installed', 'error' => $error->getMessage()], 503);
} catch (PDOException $error) {
    // Every database error reaches here before the batch commits (file clean-up after COMMIT never
    // throws), so "nothing in this batch was changed" is true.
    $driverCode = (int)($error->errorInfo[1] ?? 0);
    $sqlState = (string)($error->errorInfo[0] ?? '');
    error_log('[safeharbor-ticket-delete] failed PDOException ' . $sqlState . ' ' . $driverCode);
    if (in_array($driverCode, [1205, 1213], true) || $sqlState === '40001') {
        json_out(['ok' => false, 'code' => 'busy', 'error' => 'Another change touched these tickets. Nothing in this batch was changed; try again.'], 409);
    }
    if ($driverCode === 1142) {
        json_out(['ok' => false, 'code' => 'grant_missing', 'error' => 'The database account is missing a permission this delete needs. Nothing in this batch was changed.'], 503);
    }
    if ($sqlState === '42S02') {
        json_out(['ok' => false, 'code' => 'not_installed', 'error' => 'Bulk delete is not installed yet (migration 035).'], 503);
    }
    json_out(['ok' => false, 'error' => 'Delete stopped — nothing in this batch was changed.'], 500);
} catch (Throwable $error) {
    error_log('[safeharbor-ticket-delete] failed ' . $error::class);
    json_out(['ok' => false, 'error' => 'Delete stopped — nothing in this batch was changed.'], 500);
}
