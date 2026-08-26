<?php
/**
 * Approval-grade technician time.
 *
 * This module is the only application write path for new time entries and
 * review decisions. Database triggers remain responsible for immutable event
 * history and the authoritative UTC review timestamp.
 */
declare(strict_types=1);

final class TimeEntryValidationException extends InvalidArgumentException {}
final class TimeEntryConflictException extends RuntimeException {}
final class TimeEntryNotFoundException extends RuntimeException {}
final class TimeEntryForbiddenException extends RuntimeException {}

const TIME_ENTRY_APP_SOURCES = ['timer', 'reply', 'suggestion'];
const TIME_ENTRY_APPROVAL_ROLES = ['owner', 'admin'];

/** Keep API error semantics pinned to the service's typed failures. */
function time_entry_http_status(Throwable $error): int
{
    return match (true) {
        $error instanceof TimeEntryValidationException => 422,
        $error instanceof TimeEntryNotFoundException => 404,
        $error instanceof TimeEntryForbiddenException => 403,
        $error instanceof TimeEntryConflictException => 409,
        default => 500,
    };
}

/**
 * Create one pending technician time entry.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function time_entry_create(PDO $pdo, int $tenantId, int $actorUserId, array $input): array
{
    if ($tenantId < 1 || $actorUserId < 1) {
        throw new TimeEntryValidationException('A valid tenant and technician are required.');
    }

    $facts = time_entry_validate_create_input($input);

    // Derive client ownership from the ticket rather than accepting a browser
    // supplied billing identity. The client join also rejects corrupt
    // cross-tenant ticket/client pairs instead of blessing them into time.
    $ticketQuery = $pdo->prepare(
        'SELECT t.id AS ticket_id, t.client_id
           FROM tickets t
           JOIN clients c ON c.id = t.client_id AND c.tenant_id = t.tenant_id
          WHERE t.id = ? AND t.tenant_id = ?'
    );
    $ticketQuery->execute([$facts['ticket_id'], $tenantId]);
    $ticket = $ticketQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($ticket)) {
        throw new TimeEntryNotFoundException('Ticket not found for this tenant.');
    }

    // The supplied actor id is an authenticated session fact, but the service
    // still proves that it names an active technician inside this tenant.
    $userQuery = $pdo->prepare(
        'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1'
    );
    $userQuery->execute([$actorUserId, $tenantId]);
    if ($userQuery->fetchColumn() === false) {
        throw new TimeEntryForbiddenException('Technician is not active in this tenant.');
    }

    $facts['tenant_id'] = $tenantId;
    $facts['client_id'] = (int) $ticket['client_id'];
    $facts['user_id'] = $actorUserId;

    $existing = time_entry_find_by_key($pdo, $tenantId, $facts['entry_key']);
    if ($existing !== null) {
        return time_entry_replay_or_conflict($existing, $facts);
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO time_entries
                (tenant_id, client_id, ticket_id, user_id, entry_key, source,
                 worked_at, started_at, ended_at, minutes, note, billable, approval_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $facts['tenant_id'],
            $facts['client_id'],
            $facts['ticket_id'],
            $facts['user_id'],
            $facts['entry_key'],
            $facts['source'],
            $facts['worked_at'],
            $facts['started_at'],
            $facts['ended_at'],
            $facts['minutes'],
            $facts['note'],
            $facts['billable'],
            'pending',
        ]);
    } catch (PDOException $error) {
        // A concurrent retry can win the unique (tenant_id, entry_key) race.
        // Re-read and compare every stored fact; unrelated constraint failures
        // remain real faults and are never disguised as successful replays.
        if (!time_entry_is_constraint_error($error)) {
            throw $error;
        }
        $existing = time_entry_find_by_key($pdo, $tenantId, $facts['entry_key']);
        if ($existing === null) {
            throw $error;
        }
        return time_entry_replay_or_conflict($existing, $facts);
    }

    // Read by the tenant-scoped idempotency key rather than relying on
    // lastInsertId(): the canonical schema's AFTER INSERT audit trigger also
    // writes an auto-increment event row on this connection.
    $entry = time_entry_find_by_key($pdo, $tenantId, $facts['entry_key']);
    if ($entry === null) {
        throw new RuntimeException('Time entry was inserted but could not be read back.');
    }

    return time_entry_result($entry, false);
}

/**
 * Apply one approval decision to a pending entry.
 *
 * @return array<string, mixed>
 */
function time_entry_review(
    PDO $pdo,
    int $tenantId,
    int $reviewerUserId,
    string $reviewerRole,
    int $entryId,
    string $decision,
    string $note = '',
): array {
    if ($tenantId < 1 || $reviewerUserId < 1 || $entryId < 1) {
        throw new TimeEntryValidationException('A valid tenant, reviewer, and entry are required.');
    }
    if (!in_array($reviewerRole, TIME_ENTRY_APPROVAL_ROLES, true)) {
        throw new TimeEntryForbiddenException('Only owners and admins can review technician time.');
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new TimeEntryValidationException('Decision must be approved or rejected.');
    }

    $note = time_entry_validate_text($note, 500, 'Review note', true);
    if ($decision === 'rejected' && $note === '') {
        throw new TimeEntryValidationException('A rejection reason is required.');
    }

    $reviewerQuery = $pdo->prepare(
        'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1'
    );
    $reviewerQuery->execute([$reviewerUserId, $tenantId]);
    if ($reviewerQuery->fetchColumn() === false) {
        throw new TimeEntryForbiddenException('Reviewer is not active in this tenant.');
    }

    $current = time_entry_find_by_id($pdo, $tenantId, $entryId);
    if ($current === null) {
        throw new TimeEntryNotFoundException('Time entry not found for this tenant.');
    }
    if ((string) $current['approval_status'] !== 'pending') {
        throw new TimeEntryConflictException('Time entry has already been reviewed.');
    }

    // reviewed_at and time_entry_events are deliberately absent: migration
    // 011's trigger writes both in the same database transaction as this
    // guarded state transition.
    $update = $pdo->prepare(
        "UPDATE time_entries
            SET approval_status = ?, reviewed_by_user_id = ?, review_note = ?
          WHERE id = ? AND tenant_id = ? AND approval_status = 'pending'"
    );
    $update->execute([$decision, $reviewerUserId, $note, $entryId, $tenantId]);
    if ($update->rowCount() !== 1) {
        $latest = time_entry_find_by_id($pdo, $tenantId, $entryId);
        if ($latest === null) {
            throw new TimeEntryNotFoundException('Time entry not found for this tenant.');
        }
        throw new TimeEntryConflictException('Time entry was reviewed by someone else.');
    }

    $reviewed = time_entry_find_by_id($pdo, $tenantId, $entryId);
    if ($reviewed === null) {
        throw new RuntimeException('Reviewed time entry could not be read back.');
    }
    return time_entry_result($reviewed, false);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function time_entry_validate_create_input(array $input): array
{
    $ticketId = time_entry_validate_integer($input['ticket_id'] ?? null, 'Ticket id', 1, 4294967295);

    $source = $input['source'] ?? null;
    if (!is_string($source) || !in_array($source, TIME_ENTRY_APP_SOURCES, true)) {
        throw new TimeEntryValidationException('Source must be timer, reply, or suggestion.');
    }

    $key = $input['entry_key'] ?? null;
    if (!is_string($key)
        || strlen($key) < 16
        || strlen($key) > 64
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $key) !== 1
    ) {
        throw new TimeEntryValidationException('Entry key must be 16 to 64 conservative ASCII characters.');
    }

    $minutes = time_entry_validate_integer($input['minutes'] ?? null, 'Minutes', 1, 1440);
    $note = time_entry_validate_text($input['note'] ?? '', 255, 'Note', false);
    $billable = time_entry_validate_boolean($input['billable'] ?? true, 'Billable');

    [$workedAt, $workedEpoch] = time_entry_validate_utc_timestamp($input['worked_at'] ?? null, 'Worked at');

    $startedPresent = array_key_exists('started_at', $input) && $input['started_at'] !== null;
    $endedPresent = array_key_exists('ended_at', $input) && $input['ended_at'] !== null;
    if ($startedPresent !== $endedPresent) {
        throw new TimeEntryValidationException('Started at and ended at must be supplied together.');
    }
    if ($source === 'timer' && !$startedPresent) {
        throw new TimeEntryValidationException('Timer entries require started at and ended at.');
    }
    if ($source === 'suggestion' && $startedPresent) {
        throw new TimeEntryValidationException('Suggested entries cannot claim a measured timer interval.');
    }

    $startedAt = null;
    $endedAt = null;
    if ($startedPresent) {
        [$startedAt, $startedEpoch] = time_entry_validate_utc_timestamp($input['started_at'], 'Started at');
        [$endedAt, $endedEpoch] = time_entry_validate_utc_timestamp($input['ended_at'], 'Ended at');
        if ($startedEpoch > $endedEpoch) {
            throw new TimeEntryValidationException('Started at must not be after ended at.');
        }
        if ($endedEpoch - $startedEpoch > 86400) {
            throw new TimeEntryValidationException('A measured timer interval cannot exceed 24 hours.');
        }
        if ($workedEpoch < $startedEpoch || $workedEpoch > $endedEpoch) {
            throw new TimeEntryValidationException('Worked at must fall inside the measured interval.');
        }

        $measuredMinutes = max(1, (int) round(($endedEpoch - $startedEpoch) / 60));
        if (abs($minutes - $measuredMinutes) > 1) {
            throw new TimeEntryValidationException('Minutes do not reasonably match the measured interval.');
        }
    }

    return [
        'ticket_id' => $ticketId,
        'entry_key' => $key,
        'source' => $source,
        'worked_at' => $workedAt,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'minutes' => $minutes,
        'note' => $note,
        'billable' => $billable ? 1 : 0,
    ];
}

/**
 * Stable digest for authorizing a reply-time retry without storing message
 * content in the session. Input must already be normalized by
 * time_entry_validate_create_input().
 *
 * @param array<string, mixed> $facts
 */
function time_entry_retry_fingerprint(array $facts): string
{
    $ordered = [];
    foreach ([
        'ticket_id', 'entry_key', 'source', 'worked_at', 'started_at',
        'ended_at', 'minutes', 'note', 'billable',
    ] as $field) {
        $ordered[$field] = $facts[$field] ?? null;
    }
    return hash('sha256', json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/**
 * Digest the exact request facts before semantic validation. A reply message
 * can commit while a timestamp is temporarily outside the accepted window;
 * retaining this actor-bound digest lets the same browser retry those exact
 * facts later without authorizing any changed provenance.
 *
 * @param array<string, mixed> $input
 */
function time_entry_retry_request_fingerprint(array $input): string
{
    $ordered = [];
    foreach ([
        'ticket_id', 'entry_key', 'source', 'worked_at', 'started_at',
        'ended_at', 'minutes', 'note', 'billable',
    ] as $field) {
        $ordered[$field] = $input[$field] ?? null;
    }
    return hash('sha256', json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/** Neutralize spreadsheet formula sigils in an exported untrusted text cell. */
function time_entry_csv_safe_cell(mixed $value): string
{
    $cell = (string)$value;
    return preg_match('/\A[\x00-\x20]*[=+\-@]/u', $cell) === 1 ? "'" . $cell : $cell;
}

function time_entry_validate_integer(mixed $value, string $field, int $minimum, int $maximum): int
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
        $number = (int) $value;
    } else {
        throw new TimeEntryValidationException("{$field} must be a whole number.");
    }
    if ($number < $minimum || $number > $maximum) {
        throw new TimeEntryValidationException("{$field} is outside the allowed range.");
    }
    return $number;
}

function time_entry_validate_boolean(mixed $value, string $field): bool
{
    if (is_bool($value)) return $value;
    if ($value === 0 || $value === '0') return false;
    if ($value === 1 || $value === '1') return true;
    throw new TimeEntryValidationException("{$field} must be true or false.");
}

function time_entry_validate_text(mixed $value, int $maximum, string $field, bool $trim): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new TimeEntryValidationException("{$field} must be valid UTF-8 text.");
    }
    if ($trim) $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > $maximum) {
        throw new TimeEntryValidationException("{$field} cannot exceed {$maximum} characters.");
    }
    return $value;
}

/** @return array{0:string,1:int} */
function time_entry_validate_utc_timestamp(mixed $value, string $field): array
{
    if (!is_string($value)
        || preg_match(
            '/\A(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(?:Z|\+00:00)\z/D',
            $value,
            $parts,
        ) !== 1
    ) {
        throw new TimeEntryValidationException("{$field} must be an RFC3339 UTC timestamp.");
    }

    $canonical = $parts[1] . ' ' . $parts[2];
    $utc = new DateTimeZone('UTC');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $canonical, $utc);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $canonical
    ) {
        throw new TimeEntryValidationException("{$field} is not a real calendar time.");
    }

    $epoch = $date->getTimestamp();
    if ($epoch < 946684800 || $epoch > time() + 300) {
        throw new TimeEntryValidationException("{$field} is outside the reasonable recording window.");
    }
    return [$canonical, $epoch];
}

/** @return array<string, mixed>|null */
function time_entry_find_by_key(PDO $pdo, int $tenantId, string $entryKey): ?array
{
    $query = $pdo->prepare('SELECT * FROM time_entries WHERE tenant_id = ? AND entry_key = ? LIMIT 1');
    $query->execute([$tenantId, $entryKey]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function time_entry_find_by_id(PDO $pdo, int $tenantId, int $entryId): ?array
{
    $query = $pdo->prepare('SELECT * FROM time_entries WHERE id = ? AND tenant_id = ? LIMIT 1');
    $query->execute([$entryId, $tenantId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @param array<string, mixed> $entry @param array<string, mixed> $facts @return array<string, mixed> */
function time_entry_replay_or_conflict(array $entry, array $facts): array
{
    $same = (int) $entry['tenant_id'] === $facts['tenant_id']
        && (int) $entry['client_id'] === $facts['client_id']
        && (int) $entry['ticket_id'] === $facts['ticket_id']
        && (int) $entry['user_id'] === $facts['user_id']
        && (string) $entry['entry_key'] === $facts['entry_key']
        && (string) $entry['source'] === $facts['source']
        && (string) $entry['worked_at'] === $facts['worked_at']
        && time_entry_nullable_string($entry['started_at'] ?? null) === $facts['started_at']
        && time_entry_nullable_string($entry['ended_at'] ?? null) === $facts['ended_at']
        && (int) $entry['minutes'] === $facts['minutes']
        && (string) $entry['note'] === $facts['note']
        && (int) $entry['billable'] === $facts['billable'];

    if (!$same) {
        throw new TimeEntryConflictException('Entry key was already used for different time facts.');
    }
    return time_entry_result($entry, true);
}

function time_entry_nullable_string(mixed $value): ?string
{
    return $value === null ? null : (string) $value;
}

function time_entry_is_constraint_error(PDOException $error): bool
{
    $state = (string) ($error->errorInfo[0] ?? $error->getCode());
    return $state === '23000';
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function time_entry_result(array $row, bool $replayed): array
{
    foreach (['id', 'tenant_id', 'client_id', 'ticket_id', 'user_id', 'minutes'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    $row['billable'] = (bool) $row['billable'];
    $row['reviewed_by_user_id'] = isset($row['reviewed_by_user_id'])
        ? (int) $row['reviewed_by_user_id']
        : null;
    $row['started_at'] = time_entry_nullable_string($row['started_at'] ?? null);
    $row['ended_at'] = time_entry_nullable_string($row['ended_at'] ?? null);
    $row['reviewed_at'] = time_entry_nullable_string($row['reviewed_at'] ?? null);
    $row['replayed'] = $replayed;
    return $row;
}
