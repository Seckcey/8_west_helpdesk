<?php
/**
 * Append-only corrections to already-approved technician time.
 *
 * The approved time_entries row remains the immutable historical fact. Each
 * correction records a new effective interpretation for reporting/export,
 * guarded by an expected version and database-owned audit evidence.
 */
declare(strict_types=1);

require_once __DIR__ . '/time_entries.php';

const TIME_ENTRY_ADJUSTMENT_ROLES = ['owner', 'admin'];

/**
 * Append one approval adjustment or acknowledge an exact retry.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function time_entry_adjustment_create(
    PDO $pdo,
    int $tenantId,
    int $actorUserId,
    string $actorRole,
    array $input,
): array {
    if ($tenantId < 1 || $actorUserId < 1) {
        throw new TimeEntryValidationException('A valid tenant and adjustment actor are required.');
    }
    if (!in_array($actorRole, TIME_ENTRY_ADJUSTMENT_ROLES, true)) {
        throw new TimeEntryForbiddenException('Only owners and admins can adjust approved time.');
    }

    $facts = time_entry_adjustment_validate_input($input);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Serialize the tenant's sparse adjustment-key namespace before
        // locking any parent. A missing-key next-key lock alone is not enough
        // in InnoDB because compatible gap locks can later deadlock on insert.
        // Adjustments are rare operator actions, so this intentionally coarse
        // lock buys deterministic cross-parent key conflicts.
        $lockClause = time_entry_adjustment_lock_clause($pdo);
        $tenantQuery = $pdo->prepare(
            'SELECT id FROM tenants WHERE id = ?' . $lockClause
        );
        $tenantQuery->execute([$tenantId]);
        if ($tenantQuery->fetchColumn() === false) {
            throw new TimeEntryNotFoundException('Tenant not found for this adjustment.');
        }

        // The immutable parent is the serialization point for every version.
        // The INSERT trigger repeats this lock and all authority/bounds checks
        // so direct DML cannot bypass the service.
        $parentQuery = $pdo->prepare(
            'SELECT id, tenant_id, minutes, billable, approval_status,
                    reviewed_by_user_id, reviewed_at
               FROM time_entries
              WHERE id = ? AND tenant_id = ?' . $lockClause
        );
        $parentQuery->execute([$facts['entry_id'], $tenantId]);
        $parent = $parentQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($parent)) {
            throw new TimeEntryNotFoundException('Time entry not found for this tenant.');
        }
        if ((string) $parent['approval_status'] !== 'approved'
            || !isset($parent['reviewed_by_user_id'])
            || (int) $parent['reviewed_by_user_id'] < 1
            || !is_string($parent['reviewed_at'])
            || $parent['reviewed_at'] === ''
        ) {
            throw new TimeEntryConflictException(
                'Only approved time with review evidence can be adjusted.',
            );
        }

        $actorQuery = $pdo->prepare(
            "SELECT id, role, is_active
               FROM users
              WHERE id = ? AND tenant_id = ?" . $lockClause
        );
        $actorQuery->execute([$actorUserId, $tenantId]);
        $actor = $actorQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($actor)
            || (int) $actor['is_active'] !== 1
            || !in_array((string) $actor['role'], TIME_ENTRY_ADJUSTMENT_ROLES, true)
        ) {
            throw new TimeEntryForbiddenException(
                'Adjustment actor must be an active owner or admin in this tenant.',
            );
        }

        $existing = time_entry_adjustment_find_by_key(
            $pdo,
            $tenantId,
            $facts['adjustment_key'],
            true,
        );
        if ($existing !== null) {
            $result = time_entry_adjustment_replay_or_conflict(
                $existing,
                $facts,
                $tenantId,
                $actorUserId,
                $parent,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        }

        $originalMinutes = (int) $parent['minutes'];
        $originalBillable = (int) $parent['billable'] === 1;
        if ($facts['effective_minutes'] > $originalMinutes) {
            throw new TimeEntryValidationException(
                'Effective minutes cannot exceed the original approved minutes.',
            );
        }
        if (!$originalBillable && $facts['effective_billable']) {
            throw new TimeEntryValidationException(
                'Originally nonbillable time can never become billable.',
            );
        }
        if ($facts['effective_minutes'] === 0 && $facts['effective_billable']) {
            throw new TimeEntryValidationException('Zero effective minutes must be nonbillable.');
        }

        $versionQuery = $pdo->prepare(
            'SELECT version_no
               FROM time_entry_approval_adjustments
              WHERE tenant_id = ? AND time_entry_id = ?
              ORDER BY version_no DESC
              LIMIT 1'
        );
        $versionQuery->execute([$tenantId, $facts['entry_id']]);
        $latestVersion = $versionQuery->fetchColumn();
        $currentVersion = $latestVersion === false ? 0 : (int) $latestVersion;
        if ($facts['expected_version'] !== $currentVersion) {
            throw new TimeEntryConflictException(
                'Approved time changed after this adjustment form was loaded.',
            );
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO time_entry_approval_adjustments
                    (tenant_id, time_entry_id, adjustment_key, version_no,
                     effective_minutes, effective_billable, reason, actor_user_id)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $insert->execute([
                $tenantId,
                $facts['entry_id'],
                $facts['adjustment_key'],
                $currentVersion + 1,
                $facts['effective_minutes'],
                $facts['effective_billable'] ? 1 : 0,
                $facts['reason'],
                $actorUserId,
            ]);
        } catch (PDOException $error) {
            // The tenant/key locks make this path defensive rather than
            // expected. Only an exact current-read record becomes a replay;
            // every different use of the key is still a conflict.
            $existing = time_entry_adjustment_find_by_key(
                $pdo,
                $tenantId,
                $facts['adjustment_key'],
                true,
            );
            if ($existing !== null) {
                $result = time_entry_adjustment_replay_or_conflict(
                    $existing,
                    $facts,
                    $tenantId,
                    $actorUserId,
                    $parent,
                );
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $result;
            }
            if (time_entry_error_contains($error, 'current adjustment version')) {
                throw new TimeEntryConflictException(
                    'Approved time changed after this adjustment form was loaded.',
                );
            }
            if (time_entry_error_contains($error, 'active owner or admin')) {
                throw new TimeEntryForbiddenException(
                    'Adjustment actor must be an active owner or admin in this tenant.',
                );
            }
            if (time_entry_error_contains($error, 'approved time')) {
                throw new TimeEntryConflictException(
                    'Only approved time with review evidence can be adjusted.',
                );
            }
            throw $error;
        }

        $stored = time_entry_adjustment_find_by_key(
            $pdo,
            $tenantId,
            $facts['adjustment_key'],
            true,
        );
        if ($stored === null) {
            throw new RuntimeException('Time adjustment was inserted but could not be read back.');
        }
        $result = time_entry_adjustment_result($stored, $parent, false);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** SQLite is used only by the hermetic contract suite; MySQL owns live locks. */
function time_entry_adjustment_lock_clause(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function time_entry_adjustment_validate_input(array $input): array
{
    $expectedFields = [
        'entry_id',
        'adjustment_key',
        'expected_version',
        'effective_minutes',
        'effective_billable',
        'reason',
    ];
    $actualFields = array_keys($input);
    sort($actualFields);
    $sortedExpected = $expectedFields;
    sort($sortedExpected);
    if ($actualFields !== $sortedExpected) {
        throw new TimeEntryValidationException('Adjustment request fields must match the exact contract.');
    }

    $entryId = time_entry_validate_integer($input['entry_id'], 'Entry id', 1, 4294967295);
    $expectedVersion = time_entry_validate_integer(
        $input['expected_version'],
        'Expected version',
        0,
        4294967295,
    );
    $effectiveMinutes = time_entry_validate_integer(
        $input['effective_minutes'],
        'Effective minutes',
        0,
        1440,
    );
    $effectiveBillable = time_entry_validate_boolean(
        $input['effective_billable'],
        'Effective billable',
    );

    $key = $input['adjustment_key'];
    if (!is_string($key)
        || strlen($key) < 16
        || strlen($key) > 64
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $key) !== 1
    ) {
        throw new TimeEntryValidationException(
            'Adjustment key must be 16 to 64 conservative ASCII characters.',
        );
    }

    $reason = time_entry_validate_text($input['reason'], 500, 'Adjustment reason', true);
    if ($reason === '') {
        throw new TimeEntryValidationException('An adjustment reason is required.');
    }
    if ($effectiveMinutes === 0 && $effectiveBillable) {
        throw new TimeEntryValidationException('Zero effective minutes must be nonbillable.');
    }

    return [
        'entry_id' => $entryId,
        'adjustment_key' => $key,
        'expected_version' => $expectedVersion,
        'effective_minutes' => $effectiveMinutes,
        'effective_billable' => $effectiveBillable,
        'reason' => $reason,
    ];
}

/** @return array<string, mixed>|null */
function time_entry_adjustment_find_by_key(
    PDO $pdo,
    int $tenantId,
    string $adjustmentKey,
    bool $forUpdate = false,
): ?array {
    $lockClause = $forUpdate ? time_entry_adjustment_lock_clause($pdo) : '';
    $query = $pdo->prepare(
        'SELECT *
           FROM time_entry_approval_adjustments
          WHERE tenant_id = ? AND adjustment_key = ?
          LIMIT 1' . $lockClause
    );
    $query->execute([$tenantId, $adjustmentKey]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function time_entry_adjustment_latest(PDO $pdo, int $tenantId, int $entryId): ?array
{
    if ($tenantId < 1 || $entryId < 1) {
        throw new TimeEntryValidationException('A valid tenant and time entry are required.');
    }
    $query = $pdo->prepare(
        'SELECT adjustment.*, parent.minutes AS original_minutes,
                parent.billable AS original_billable
           FROM time_entry_approval_adjustments adjustment
           JOIN time_entries parent
             ON parent.tenant_id = adjustment.tenant_id
            AND parent.id = adjustment.time_entry_id
          WHERE adjustment.tenant_id = ? AND adjustment.time_entry_id = ?
          ORDER BY adjustment.version_no DESC
          LIMIT 1'
    );
    $query->execute([$tenantId, $entryId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return time_entry_adjustment_result(
        $row,
        [
            'minutes' => $row['original_minutes'],
            'billable' => $row['original_billable'],
        ],
        false,
    );
}

/**
 * @param list<int> $entryIds
 * @return array<int, array<string, mixed>>
 */
function time_entry_adjustment_latest_by_entry(
    PDO $pdo,
    int $tenantId,
    array $entryIds,
): array {
    if ($tenantId < 1) {
        throw new TimeEntryValidationException('A valid tenant is required.');
    }
    $ids = [];
    foreach ($entryIds as $entryId) {
        $ids[] = time_entry_validate_integer($entryId, 'Entry id', 1, 4294967295);
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql =
        'SELECT adjustment.*, parent.minutes AS original_minutes,
                parent.billable AS original_billable
           FROM time_entry_approval_adjustments adjustment
           JOIN (
                 SELECT tenant_id, time_entry_id, MAX(version_no) AS latest_version
                   FROM time_entry_approval_adjustments
                  WHERE tenant_id = ? AND time_entry_id IN (' . $placeholders . ')
                  GROUP BY tenant_id, time_entry_id
                ) latest
             ON latest.tenant_id = adjustment.tenant_id
            AND latest.time_entry_id = adjustment.time_entry_id
            AND latest.latest_version = adjustment.version_no
           JOIN time_entries parent
             ON parent.tenant_id = adjustment.tenant_id
            AND parent.id = adjustment.time_entry_id';
    $query = $pdo->prepare($sql);
    $query->execute(array_merge([$tenantId], $ids));

    $result = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = time_entry_adjustment_result(
            $row,
            [
                'minutes' => $row['original_minutes'],
                'billable' => $row['original_billable'],
            ],
            false,
        );
        $result[$normalized['time_entry_id']] = $normalized;
    }
    return $result;
}

/**
 * Return the complete append-only slip chain for each displayed parent.
 *
 * @param list<int> $entryIds
 * @return array<int, list<array<string, mixed>>>
 */
function time_entry_adjustment_history_by_entry(
    PDO $pdo,
    int $tenantId,
    array $entryIds,
): array {
    if ($tenantId < 1) {
        throw new TimeEntryValidationException('A valid tenant is required.');
    }
    $ids = [];
    foreach ($entryIds as $entryId) {
        $ids[] = time_entry_validate_integer($entryId, 'Entry id', 1, 4294967295);
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare(
        'SELECT adjustment.*, parent.minutes AS original_minutes,
                parent.billable AS original_billable
           FROM time_entry_approval_adjustments adjustment
           JOIN time_entries parent
             ON parent.tenant_id = adjustment.tenant_id
            AND parent.id = adjustment.time_entry_id
          WHERE adjustment.tenant_id = ?
            AND adjustment.time_entry_id IN (' . $placeholders . ')
          ORDER BY adjustment.time_entry_id, adjustment.version_no'
    );
    $query->execute(array_merge([$tenantId], $ids));

    $result = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) continue;
        $normalized = time_entry_adjustment_result(
            $row,
            [
                'minutes' => $row['original_minutes'],
                'billable' => $row['original_billable'],
            ],
            false,
        );
        $result[$normalized['time_entry_id']][] = $normalized;
    }
    return $result;
}

/**
 * @param array<string, mixed> $stored
 * @param array<string, mixed> $facts
 * @param array<string, mixed> $parent
 * @return array<string, mixed>
 */
function time_entry_adjustment_replay_or_conflict(
    array $stored,
    array $facts,
    int $tenantId,
    int $actorUserId,
    array $parent,
): array {
    $same = (int) $stored['tenant_id'] === $tenantId
        && (int) $stored['time_entry_id'] === $facts['entry_id']
        && (string) $stored['adjustment_key'] === $facts['adjustment_key']
        && (int) $stored['version_no'] === $facts['expected_version'] + 1
        && (int) $stored['effective_minutes'] === $facts['effective_minutes']
        && (int) $stored['effective_billable'] === ($facts['effective_billable'] ? 1 : 0)
        && (string) $stored['reason'] === $facts['reason']
        && (int) $stored['actor_user_id'] === $actorUserId;
    if (!$same) {
        throw new TimeEntryConflictException(
            'Adjustment key was already used for different approved-time facts.',
        );
    }
    return time_entry_adjustment_result($stored, $parent, true);
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $parent
 * @return array<string, mixed>
 */
function time_entry_adjustment_result(array $row, array $parent, bool $replayed): array
{
    return [
        'id' => (int) $row['id'],
        'tenant_id' => (int) $row['tenant_id'],
        'time_entry_id' => (int) $row['time_entry_id'],
        'adjustment_key' => (string) $row['adjustment_key'],
        'version' => (int) $row['version_no'],
        'effective_minutes' => (int) $row['effective_minutes'],
        'effective_billable' => (int) $row['effective_billable'] === 1,
        'reason' => (string) $row['reason'],
        'actor_user_id' => (int) $row['actor_user_id'],
        'created_at' => (string) $row['created_at'],
        'original_minutes' => (int) $parent['minutes'],
        'original_billable' => (int) $parent['billable'] === 1,
        'replayed' => $replayed,
    ];
}
