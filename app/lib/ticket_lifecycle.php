<?php
/**
 * Ticket lifecycle boundaries shared by human and machine write paths.
 *
 * Automatic closure is deliberately a one-use capability. Only the signed
 * Milepost alert intake may create a ticket with auto_close_eligible = 1.
 * Database guards permanently consume that capability on any ticket update,
 * human/customer message, moved message, or technician time entry.
 */
declare(strict_types=1);

/**
 * Atomically close one still-eligible telemetry ticket and append the exact
 * recovery evidence in the same transaction. A concurrent human mutation
 * wins by clearing eligibility first; a concurrent recovery wins only if its
 * guarded UPDATE reaches the row first.
 */
function ticket_try_machine_auto_close(
    PDO $pdo,
    int $tenantId,
    int $ticketId,
    string $occurredAt,
): bool {
    if ($tenantId < 1 || $ticketId < 1) {
        return false;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Lock and prove eligibility before writing the recovery line. The
        // message guard also checks this state, which keeps an emergency
        // rollback to pre-018 application code fail-closed for ineligible
        // tickets.
        $state = $pdo->prepare(
            "SELECT auto_close_eligible
               FROM tickets
              WHERE id = ? AND tenant_id = ? AND status = 'open'
              FOR UPDATE"
        );
        $state->execute([$ticketId, $tenantId]);
        if ((int)$state->fetchColumn() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $line = $pdo->prepare(
            'INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?, ?, ?, ?)'
        );
        $line->execute([
            $ticketId,
            'Milepost',
            'system',
            'Resolved at source at ' . $occurredAt . ' UTC. Ticket auto-closed.',
        ]);

        $close = $pdo->prepare(
            "UPDATE tickets
                SET status = 'resolved', resolved_at = ?, auto_close_eligible = 0
              WHERE id = ? AND tenant_id = ?
                AND status = 'open' AND auto_close_eligible = 1"
        );
        $close->execute([$occurredAt, $ticketId, $tenantId]);
        if ($close->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
