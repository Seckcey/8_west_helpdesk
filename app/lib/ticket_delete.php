<?php
/**
 * Owner/admin bulk ticket delete (docs/ticket-bulk-delete.md).
 *
 * A deleted ticket takes with it everything that belongs only to it: replies and notes,
 * attachments, the satisfaction survey and email-conversation mappings. Queued email is cancelled
 * and detached, Westy failure reports lose their pointer, and one insert-only ticket_deletions row
 * records who did it. Tickets whose history the database guards (technician time, Westy workflow,
 * email and mailbox history, customer-portal requester and receipt rows) are kept and reported,
 * never forced. A merge group, meaning a ticket plus every stub merged into it in either direction,
 * goes as a whole or not at all.
 *
 * A delete always names its tickets: the ones the person ticked, or every ticket the queue page
 * showed. The preview answers with the ids it can delete, and the run may only ever touch exactly
 * that list, so a ticket that arrives or changes after the page loaded is never deleted unseen and
 * a run never goes past the count the person confirmed.
 *
 * Every function takes the PDO and nothing here loads the bootstrap, so the MySQL test can require
 * this file directly (the same shape as ticket_lifecycle.php).
 *
 * Mind the wording in this file. CI reads the text of every production PHP file for SQL deletes and
 * compares the tables it finds with the runtime account's DELETE grants
 * (tests/business_reports_test.php). Only attachments, csat, email_threads, messages and tickets may
 * follow that verb here, in code or in comments.
 */
declare(strict_types=1);

const TICKET_DELETE_ROLES = ['owner', 'admin'];
const TICKET_DELETE_BATCH_SIZE = 100;
const TICKET_DELETE_MAX_IDS = 10000;         // tickets one delete may name; also the merge-partner cap
const TICKET_DELETE_PREVIEW_TTL = 3600;
const TICKET_DELETE_COLUMNS = 'id, client_id, channel, status, external_key, merged_into_id, created_at, resolved_at';
// Why a ticket must stay, in precedence order: a ticket reports the first reason that applies.
const TICKET_DELETE_REASONS = [
    'time'              => 'Technician time is logged on it',
    'westy_workflow'    => 'Westy troubleshooting history',
    'westy_email'       => 'Westy email history',
    'westy_mail'        => 'Westy mailbox history',
    'portal_requester'  => 'Customer-portal requester record',
    'portal_receipt'    => 'Customer-portal Westy receipt',
    'attachment_link'   => 'An attachment is linked to another ticket',
    'grant_attachments' => 'Has attachments (database permission not granted yet)',
    'grant_csat'        => 'Has a satisfaction survey (database permission not granted yet)',
    'merge_kept'        => 'Merged with a ticket that must be kept',
    'merge_unselected'  => 'Merged with a ticket outside this selection',
];
// Guarded history: table => reason. Each has (tenant_id, ticket_id) and a database trigger that
// refuses to remove its rows, so a ticket that owns any of them can never be deleted.
const TICKET_DELETE_GUARDED = [
    'time_entries'             => 'time',
    'westy_workflows'          => 'westy_workflow',
    'westy_email_drafts'       => 'westy_email',
    'westy_mail_conversations' => 'westy_mail',
    'portal_ticket_requesters' => 'portal_requester',
    'portal_westy_drafts'      => 'portal_receipt',
];

final class TicketDeleteValidationException extends InvalidArgumentException {}
final class TicketDeleteForbiddenException extends RuntimeException {}
final class TicketDeleteNotInstalledException extends RuntimeException {}

/** COMMIT failed without saying whether it took effect, so the batch may or may not be gone. Its
 * attachment files are kept and named in the log: removing them is only safe once the rows are. */
final class TicketDeleteCommitUnknownException extends RuntimeException
{
    /** @param list<string> $storedNames */
    public function __construct(public readonly array $storedNames, Throwable $previous)
    {
        parent::__construct('ticket_delete_commit_unknown', 0, $previous);
    }
}

function ticket_delete_allowed(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), TICKET_DELETE_ROLES, true);
}

/**
 * The tickets a request names: {ids:[int, …]}, JSON integers only (strings are refused rather than
 * coerced), 1 to 10,000 of them. Returns them de-duplicated and sorted.
 *
 * @return array{ids:list<int>}
 */
function ticket_delete_scope(mixed $in): array
{
    if (!is_array($in) || array_keys($in) !== ['ids'] || !is_array($in['ids']) || !array_is_list($in['ids']) || $in['ids'] === []) {
        throw new TicketDeleteValidationException('Choose at least one ticket.');
    }
    if (count($in['ids']) > TICKET_DELETE_MAX_IDS) {
        throw new TicketDeleteValidationException('Select up to ' . number_format(TICKET_DELETE_MAX_IDS) . ' tickets at a time.');
    }
    foreach ($in['ids'] as $id) {
        if (!is_int($id) || $id < 1 || $id > 4294967295) {
            throw new TicketDeleteValidationException('Ticket numbers must be whole numbers.');
        }
    }
    $ids = array_values(array_unique($in['ids']));
    sort($ids);
    return ['ids' => $ids];
}

function ticket_delete_scope_digest(array $scope): string
{
    return hash('sha256', json_encode($scope['ids'], JSON_THROW_ON_ERROR));
}

/**
 * Compare a delete request with the preview held in the session. Null means it matches;
 * 'preview_expired' means there is no usable preview for this person, ticket list and hour;
 * 'confirm_mismatch' means the typed phrase is wrong.
 */
function ticket_delete_preview_check(?array $stored, int $tenantId, int $actorId, string $digest, string $confirm, int $now): ?string
{
    if (!is_array($stored)
        || !is_int($stored['at'] ?? null) || $now - $stored['at'] > TICKET_DELETE_PREVIEW_TTL || $stored['at'] > $now + 60
        || ($stored['tenant'] ?? null) !== $tenantId || ($stored['actor'] ?? null) !== $actorId
        || !is_string($stored['digest'] ?? null) || !hash_equals($stored['digest'], $digest)
        || !is_string($stored['phrase'] ?? null) || $stored['phrase'] === '') {
        return 'preview_expired';
    }
    return hash_equals($stored['phrase'], trim($confirm)) ? null : 'confirm_mismatch';
}

/**
 * Whether this connection may remove attachment and survey rows. EXPLAIN needs the privileges of
 * the statement it explains but changes nothing, so a missing grant shows up as error 1142 here
 * instead of halfway through a batch.
 *
 * @return array{attachments:bool,csat:bool}
 */
function ticket_delete_permissions(PDO $pdo): array
{
    $allowed = static function (string $statement) use ($pdo): bool {
        try {
            $pdo->query($statement)->fetchAll();
            return true;
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1142) return false;
            throw $error;
        }
    };
    return [
        'attachments' => $allowed('EXPLAIN DELETE FROM attachments WHERE id = 0'),
        'csat'        => $allowed('EXPLAIN DELETE FROM csat WHERE id = 0'),
    ];
}

function ticket_delete_table_exists(PDO $pdo, string $table): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

function ticket_delete_in(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

/** The person must still be an active owner or admin of this tenant, checked against the database. */
function ticket_delete_actor(PDO $pdo, int $tenantId, int $actorId): void
{
    $q = $pdo->prepare('SELECT role FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1');
    $q->execute([$actorId, $tenantId]);
    $role = $q->fetchColumn();
    if (!is_string($role) || !in_array($role, TICKET_DELETE_ROLES, true)) {
        throw new TicketDeleteForbiddenException('Only owners and admins can delete tickets.');
    }
}

/**
 * Tenant tickets by id, keyed by id. Locking reads go through (tenant_id, id) so the secondary
 * index records are locked as well as the rows: the composite foreign keys from time, Westy and
 * portal tables check that index, so a new history row for one of these tickets waits for the batch.
 *
 * @param list<int> $ids
 * @return array<int, array<string, mixed>>
 */
function ticket_delete_rows(PDO $pdo, int $tenantId, array $ids, bool $lock): array
{
    $rows = [];
    foreach (array_chunk(array_values($ids), 1000) as $chunk) {
        $q = $pdo->prepare('SELECT ' . TICKET_DELETE_COLUMNS . ' FROM tickets'
            . ($lock ? ' FORCE INDEX (uq_tickets_tenant_id)' : '')
            . ' WHERE tenant_id = ? AND id IN (' . ticket_delete_in(count($chunk)) . ') ORDER BY id'
            . ($lock ? ' FOR UPDATE' : ''));
        $q->execute(array_merge([$tenantId], $chunk));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int)$row['id']] = $row;
    }
    return $rows;
}

/**
 * Ticket ids among $ids that appear in $sql's result (one id column), run in chunks.
 *
 * @param list<int> $ids
 * @param list<int|string> $leading parameters before the id list
 * @return list<int>
 */
function ticket_delete_hits(PDO $pdo, string $sql, array $leading, array $ids): array
{
    $hits = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
        $q = $pdo->prepare(str_replace('{ids}', ticket_delete_in(count($chunk)), $sql));
        $q->execute(array_merge($leading, $chunk));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $hits[(int)$id] = true;
    }
    return array_keys($hits);
}

/**
 * Decide, for $rows (id => ticket row, already locked by the caller when $lock is true), which of
 * the named tickets can be deleted and why the others must stay. Pulls in every merge partner,
 * locking them too, and judges each merge group as a whole.
 *
 * @return array{rows: array<int, array<string, mixed>>, deletable: list<int>, kept: array<int, string>}
 */
function ticket_delete_assess(PDO $pdo, int $tenantId, array $rows, array $scope, array $perms, bool $lock): array
{
    // 1. Merge closure: parents through merged_into_id, stubs through the (tenant_id, merged_into_id)
    // index, until nothing new turns up. Merge chains exist, so this loops.
    $group = $rows;
    $frontier = array_keys($rows);
    $extra = 0;
    while ($frontier !== []) {
        $found = [];
        $parents = [];
        foreach ($frontier as $id) {
            $parent = $group[$id]['merged_into_id'] ?? null;
            if ($parent !== null && !isset($group[(int)$parent])) $parents[(int)$parent] = true;
        }
        if ($parents !== []) {
            foreach (ticket_delete_rows($pdo, $tenantId, array_keys($parents), $lock) as $id => $row) {
                if (!isset($group[$id])) { $group[$id] = $row; $found[] = $id; }
            }
        }
        foreach (array_chunk($frontier, 1000) as $chunk) {
            $q = $pdo->prepare('SELECT ' . TICKET_DELETE_COLUMNS . ' FROM tickets'
                . ' WHERE tenant_id = ? AND merged_into_id IN (' . ticket_delete_in(count($chunk)) . ')'
                . ($lock ? ' FOR UPDATE' : ''));
            $q->execute(array_merge([$tenantId], $chunk));
            $stubs = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['id'];
                if (!isset($group[$id])) { $group[$id] = $row; $found[] = $id; $stubs[] = $id; }
            }
            // Stubs are locked through (tenant_id, id) as well, like every other ticket in the group,
            // so new time, Westy or portal history for one of them waits for the batch.
            if ($lock && $stubs !== []) {
                foreach (ticket_delete_rows($pdo, $tenantId, $stubs, true) as $id => $row) $group[$id] = $row;
            }
        }
        $extra += count($found);
        // The same cap as the selection itself, in the preview and in every batch, so a batch never
        // refuses a group the preview accepted.
        if ($extra > TICKET_DELETE_MAX_IDS) {
            throw new TicketDeleteValidationException('Too many merged tickets are linked to this selection; choose fewer tickets.');
        }
        $frontier = $found;
    }

    // 2. Evidence, in precedence order: the first reason found sticks. Locking reads, so a row
    // committed a moment ago counts.
    $ids = array_keys($group);
    sort($ids);
    $share = $lock ? ' FOR SHARE' : '';
    $reasons = [];
    $mark = static function (array $hits, string $reason) use (&$reasons): void {
        foreach ($hits as $id) $reasons[$id] ??= $reason;
    };
    foreach (TICKET_DELETE_GUARDED as $table => $reason) {
        $mark(ticket_delete_hits($pdo, "SELECT ticket_id FROM {$table} WHERE tenant_id = ? AND ticket_id IN ({ids}){$share}", [$tenantId], $ids), $reason);
    }
    // An attachment row filed under a different ticket than its message (app code never does this;
    // merges move both). Attachment rows only go with their own ticket, and before any message, so
    // the attachment's ticket can still be deleted; the message's ticket cannot while that row
    // points at its message (fk_att_message), whether or not the other ticket is in this set.
    $linked = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
        $q = $pdo->prepare('SELECT m.ticket_id FROM attachments a JOIN messages m ON m.id = a.message_id
             WHERE m.ticket_id IN (' . ticket_delete_in(count($chunk)) . ') AND a.ticket_id <> m.ticket_id' . $share);
        $q->execute($chunk);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $linked[(int)$id] = true;
    }
    $mark(array_keys($linked), 'attachment_link');
    if (!$perms['attachments']) {
        $mark(ticket_delete_hits($pdo, "SELECT ticket_id FROM attachments WHERE ticket_id IN ({ids}){$share}", [], $ids), 'grant_attachments');
    }
    if (!$perms['csat']) {
        $mark(ticket_delete_hits($pdo, "SELECT ticket_id FROM csat WHERE ticket_id IN ({ids}){$share}", [], $ids), 'grant_csat');
    }

    // 3. Merge groups (connected components over merged_into_id inside the closure).
    $root = [];
    $find = static function (int $id) use (&$root): int {
        if (!isset($root[$id])) return $root[$id] = $id;
        $top = $id;
        while ($root[$top] !== $top) $top = $root[$top];
        while ($root[$id] !== $top) { $next = $root[$id]; $root[$id] = $top; $id = $next; }
        return $top;
    };
    foreach ($group as $id => $row) {
        $find($id);
        $parent = $row['merged_into_id'] ?? null;
        if ($parent !== null && isset($group[(int)$parent])) {
            $a = $find($id);
            $b = $find((int)$parent);
            if ($a !== $b) $root[$a] = $b;
        }
    }
    $components = [];
    foreach ($group as $id => $_) $components[$find($id)][] = $id;

    // 4. A group with any guarded member keeps all of its named members; otherwise a group with an
    // unnamed member keeps them too (deleting half a merge leaves a dead link or brings a hidden
    // survivor back into the customer portal).
    $named = array_flip($scope['ids']);
    $deletable = [];
    $kept = [];
    foreach ($components as $members) {
        $selected = array_values(array_filter($members, static fn(int $id): bool => isset($named[$id])));
        if ($selected === []) continue;
        $anyGuarded = false;
        foreach ($members as $id) if (isset($reasons[$id])) { $anyGuarded = true; break; }
        $allSelected = count($selected) === count($members);
        foreach ($selected as $id) {
            if (isset($reasons[$id])) $kept[$id] = $reasons[$id];
            elseif ($anyGuarded) $kept[$id] = 'merge_kept';
            elseif (!$allSelected) $kept[$id] = 'merge_unselected';
            else $deletable[] = $id;
        }
    }
    sort($deletable);
    ksort($kept);
    return ['rows' => $group, 'deletable' => $deletable, 'kept' => $kept];
}

/**
 * Count rows of one child table for a set of tickets.
 *
 * @param list<int> $ids
 */
function ticket_delete_count(PDO $pdo, string $sql, array $leading, array $ids): int
{
    $total = 0;
    foreach (array_chunk($ids, 1000) as $chunk) {
        $q = $pdo->prepare(str_replace('{ids}', ticket_delete_in(count($chunk)), $sql));
        $q->execute(array_merge($leading, $chunk));
        $total += (int)$q->fetchColumn();
    }
    return $total;
}

/**
 * What a delete of the named tickets would do, without changing or locking anything. The answer
 * includes deletable_ids: the exact list a run may then delete.
 *
 * @return array<string, mixed>
 */
function ticket_delete_preview(PDO $pdo, int $tenantId, int $actorId, array $scope): array
{
    ticket_delete_actor($pdo, $tenantId, $actorId);
    if (!ticket_delete_table_exists($pdo, 'ticket_deletions')) {
        throw new TicketDeleteNotInstalledException('Bulk delete is not installed yet (migration 035).');
    }
    $perms = ticket_delete_permissions($pdo);
    $rows = ticket_delete_rows($pdo, $tenantId, $scope['ids'], false);
    $assessment = ticket_delete_assess($pdo, $tenantId, $rows, $scope, $perms, false);
    $deletable = $assessment['deletable'];
    $kept = $assessment['kept'];

    $byReason = [];
    foreach ($kept as $reason) $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
    $sample = [];
    $sampleIds = array_slice(array_keys($kept), 0, 50);
    if ($sampleIds !== []) {
        $q = $pdo->prepare('SELECT id, subject FROM tickets WHERE tenant_id = ? AND id IN (' . ticket_delete_in(count($sampleIds)) . ') ORDER BY id');
        $q->execute(array_merge([$tenantId], $sampleIds));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sample[] = ['id' => (int)$row['id'], 'subject' => (string)$row['subject'], 'reason' => $kept[(int)$row['id']]];
        }
    }

    // Unsent means dispatch would still try it; a row that already used up its attempts is history.
    $mailUnsent = 0;
    $mailSent = 0;
    if ($deletable !== [] && ticket_delete_table_exists($pdo, 'mail_queue')) {
        $mailUnsent = ticket_delete_count($pdo, 'SELECT COUNT(*) FROM mail_queue WHERE ticket_id IN ({ids}) AND sent_at IS NULL AND attempts < max_attempts', [], $deletable);
        $mailSent = ticket_delete_count($pdo, 'SELECT COUNT(*) FROM mail_queue WHERE ticket_id IN ({ids}) AND sent_at IS NOT NULL', [], $deletable);
    }
    $alerts = 0;
    foreach ($deletable as $id) if (($assessment['rows'][$id]['channel'] ?? '') === 'alert') $alerts++;
    // Someone else viewing or typing on a ticket in the last two minutes may have a running timer or a
    // draft there; timers are saved only when stopped.
    $openElsewhere = $deletable !== [] && ticket_delete_table_exists($pdo, 'ticket_presence')
        ? ticket_delete_count($pdo, 'SELECT COUNT(DISTINCT ticket_id) FROM ticket_presence WHERE user_id <> ?'
            . ' AND last_seen >= NOW() - INTERVAL 2 MINUTE AND ticket_id IN ({ids})', [$actorId], $deletable)
        : 0;

    return [
        'total'          => count($scope['ids']),
        'not_found'      => count($scope['ids']) - count($rows),
        'deletable'      => count($deletable),
        'deletable_ids'  => $deletable,
        'kept'           => count($kept),
        'kept_by_reason' => $byReason,
        'kept_sample'    => $sample,
        'effects'        => [
            'messages'      => $deletable === [] ? 0 : ticket_delete_count($pdo, 'SELECT COUNT(*) FROM messages WHERE ticket_id IN ({ids})', [], $deletable),
            'attachments'   => $deletable === [] ? 0 : ticket_delete_count($pdo, 'SELECT COUNT(*) FROM attachments WHERE ticket_id IN ({ids})', [], $deletable),
            'csat'          => $deletable === [] ? 0 : ticket_delete_count($pdo, 'SELECT COUNT(*) FROM csat WHERE ticket_id IN ({ids})', [], $deletable),
            'mail_unsent'   => $mailUnsent,
            'mail_sent'     => $mailSent,
            'westy_reports' => $deletable === [] ? 0 : ticket_delete_count($pdo, 'SELECT COUNT(*) FROM westy_reports WHERE tenant_id = ? AND ticket_id IN ({ids})', [$tenantId], $deletable),
            'alert_tickets' => $alerts,
            'open_elsewhere' => $openElsewhere,
        ],
        'permissions'    => $perms,
        'reasons'        => TICKET_DELETE_REASONS,
    ];
}

/**
 * Delete the next window of up to $limit named tickets after $afterId, in one transaction, and
 * report what stayed. Retries a deadlock or lock timeout up to three times; anything else rolls the
 * batch back untouched and is rethrown. Attachment files are removed only after the transaction has
 * committed and outside the retry, so nothing after COMMIT can be reported as a rolled-back batch.
 *
 * $afterAssess is a test seam: it runs while the batch holds its locks, after deciding what goes
 * and before anything is written.
 *
 * @return array{deleted:int, kept:list<array{id:int,reason:string}>, next_after_id:int, done:bool, files_failed:int, failed_files:list<string>}
 */
function ticket_delete_batch(PDO $pdo, int $tenantId, int $actorId, array $scope, int $afterId,
    string $runKey, string $attachmentsDir, int $limit = TICKET_DELETE_BATCH_SIZE, ?callable $afterAssess = null): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('transaction_ownership_required');
    if (preg_match('/\A[0-9a-f]{32}\z/D', $runKey) !== 1) throw new TicketDeleteValidationException('This delete run is not valid; start again.');
    if ($afterId < 0 || $limit < 1 || $limit > TICKET_DELETE_BATCH_SIZE) throw new TicketDeleteValidationException('This delete run is not valid; start again.');
    for ($attempt = 1; ; $attempt++) {
        try {
            $result = ticket_delete_batch_once($pdo, $tenantId, $actorId, $scope, $afterId, $runKey, $limit, $afterAssess);
            break;
        } catch (TicketDeleteCommitUnknownException $error) {
            // Never retried: the rows may already be gone. Try again from the browser is still safe.
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable) {}
            $names = array_values(array_unique($error->storedNames));
            // The cause tells the two cases apart: a lost reply (2006, 2013) may have committed, so the
            // kept files may be orphans; a refused COMMIT rolled back, so they are still in use.
            $cause = $error->getPrevious();
            error_log(sprintf('[safeharbor-ticket-delete] run=%s tenant=%d actor=%d commit unconfirmed (%s%s); attachment files kept: %s',
                $runKey, $tenantId, $actorId, $cause ? $cause::class : 'unknown',
                $cause instanceof PDOException ? ' ' . ($cause->errorInfo[0] ?? '') . ' ' . ($cause->errorInfo[1] ?? '') : '',
                $names === [] ? 'none' : implode(',', $names)));
            throw $error;
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $retryable = in_array((int)($error->errorInfo[1] ?? 0), [1205, 1213], true)
                || (string)($error->errorInfo[0] ?? '') === '40001';
            if (!$retryable || $attempt >= 3) throw $error;
            usleep(100000 * $attempt);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    $names = $result['stored_names'];
    unset($result['stored_names']);
    try {
        [$result['files_failed'], $result['failed_files']] = ticket_delete_remove_files($pdo, $attachmentsDir, $names);
    } catch (Throwable $error) {
        // The rows are already gone. Without the still-in-use check nothing is unlinked; the names
        // go to the log for a manual clean-up instead.
        $names = array_values(array_unique($names));
        $result['files_failed'] = count($names);
        $result['failed_files'] = $names;
    }
    return $result;
}

/** @return array{deleted:int, kept:list<array{id:int,reason:string}>, next_after_id:int, done:bool, files_failed:int, failed_files:list<string>, stored_names:list<string>} */
function ticket_delete_batch_once(PDO $pdo, int $tenantId, int $actorId, array $scope, int $afterId,
    string $runKey, int $limit, ?callable $afterAssess): array
{
    ticket_delete_actor($pdo, $tenantId, $actorId);
    $perms = ticket_delete_permissions($pdo);
    $mailQueue = ticket_delete_table_exists($pdo, 'mail_queue');

    $remaining = array_values(array_filter($scope['ids'], static fn(int $id): bool => $id > $afterId));
    $window = array_slice($remaining, 0, $limit);
    $result = ['deleted' => 0, 'kept' => [], 'next_after_id' => $afterId, 'done' => true, 'files_failed' => 0,
        'failed_files' => [], 'stored_names' => []];
    if ($window === []) return $result;
    $result['next_after_id'] = max($window);
    $result['done'] = count($remaining) <= $limit;

    // Every read below is a locking read of committed rows, and READ COMMITTED takes no gap locks,
    // so the batch never holds up new tickets (alert intake included) while it runs. InnoDB refuses
    // READ COMMITTED writes with statement-based binary logging, so keep the default there.
    if (strtoupper((string)$pdo->query('SELECT @@SESSION.binlog_format')->fetchColumn()) !== 'STATEMENT') {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    $pdo->beginTransaction();
    // Missing ids went earlier as part of a merge group.
    $locked = ticket_delete_rows($pdo, $tenantId, $window, true);
    if ($locked === []) { $pdo->commit(); return $result; }

    // Protection is decided before any row is touched: removing a message fires Westy triggers that
    // write history which can never be removed again.
    $assessment = ticket_delete_assess($pdo, $tenantId, $locked, $scope, $perms, true);
    foreach (array_keys($locked) as $id) {
        if (isset($assessment['kept'][$id])) $result['kept'][] = ['id' => $id, 'reason' => $assessment['kept'][$id]];
    }
    $doomed = $assessment['deletable'];
    if ($afterAssess !== null) $afterAssess($doomed);
    if ($doomed === []) { $pdo->commit(); return $result; }

    // Lock everything that goes with them.
    $messageCount = [];
    $messages = 0;
    foreach (array_chunk($doomed, 1000) as $chunk) {
        $q = $pdo->prepare('SELECT id, ticket_id FROM messages WHERE ticket_id IN (' . ticket_delete_in(count($chunk)) . ') FOR UPDATE');
        $q->execute($chunk);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $messageCount[(int)$row['ticket_id']] = ($messageCount[(int)$row['ticket_id']] ?? 0) + 1;
            $messages++;
        }
    }
    $attachmentCount = [];
    $storedNames = [];
    foreach (array_chunk($doomed, 1000) as $chunk) {
        $q = $pdo->prepare('SELECT id, ticket_id, stored_name FROM attachments WHERE ticket_id IN (' . ticket_delete_in(count($chunk)) . ') FOR UPDATE');
        $q->execute($chunk);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attachmentCount[(int)$row['ticket_id']] = ($attachmentCount[(int)$row['ticket_id']] ?? 0) + 1;
            $storedNames[] = (string)$row['stored_name'];
        }
    }
    $csatScore = [];
    foreach (array_chunk($doomed, 1000) as $chunk) {
        $q = $pdo->prepare('SELECT ticket_id, score FROM csat WHERE ticket_id IN (' . ticket_delete_in(count($chunk)) . ') FOR UPDATE');
        $q->execute($chunk);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $csatScore[(int)$row['ticket_id']] = $row['score'] === null ? null : (int)$row['score'];
        }
    }
    $mailCancelled = [];
    $mailRows = 0;
    if ($mailQueue) {
        foreach (array_chunk($doomed, 1000) as $chunk) {
            $q = $pdo->prepare('SELECT id, ticket_id, sent_at, attempts, max_attempts FROM mail_queue WHERE ticket_id IN (' . ticket_delete_in(count($chunk)) . ') FOR UPDATE');
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mailRows++;
                if ($row['sent_at'] === null && (int)$row['attempts'] < (int)$row['max_attempts']) {
                    $mailCancelled[(int)$row['ticket_id']] = ($mailCancelled[(int)$row['ticket_id']] ?? 0) + 1;
                }
            }
        }
    }

    // One immutable record per ticket, in the same transaction as the delete.
    foreach (array_chunk($doomed, 200) as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as $id) {
            $row = $assessment['rows'][$id];
            $values[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())';
            array_push($params, $tenantId, $runKey, $id, (int)$row['client_id'], (string)$row['channel'], (string)$row['status'],
                $row['external_key'], $row['merged_into_id'] === null ? null : (int)$row['merged_into_id'],
                (string)$row['created_at'], $row['resolved_at'], $messageCount[$id] ?? 0, $attachmentCount[$id] ?? 0,
                array_key_exists($id, $csatScore) ? $csatScore[$id] : null, $mailCancelled[$id] ?? 0, $actorId);
        }
        $pdo->prepare('INSERT INTO ticket_deletions (tenant_id, run_key, ticket_id, client_id, channel, status, external_key,
                merged_into_id, ticket_created_at, ticket_resolved_at, message_count, attachment_count, csat_score,
                mail_cancelled, deleted_by_user_id, deleted_at) VALUES ' . implode(',', $values))->execute($params);
    }

    // Children first, in foreign-key order. Attachments go before messages (fk_att_message), and
    // messages are matched by literal ticket ids only: their AFTER DELETE trigger updates tickets,
    // so a form that joined or subqueried tickets would fail with error 1442.
    $affected = static function (string $sql, array $leading, array $ids) use ($pdo): int {
        $total = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $q = $pdo->prepare(str_replace('{ids}', ticket_delete_in(count($chunk)), $sql));
            $q->execute(array_merge($leading, $chunk));
            $total += $q->rowCount();
        }
        return $total;
    };
    if ($storedNames !== []
        && $affected('DELETE FROM attachments WHERE ticket_id IN ({ids})', [], $doomed) !== count($storedNames)) {
        throw new RuntimeException('ticket_delete_attachment_count_changed');
    }
    if ($csatScore !== []
        && $affected('DELETE FROM csat WHERE ticket_id IN ({ids})', [], $doomed) !== count($csatScore)) {
        throw new RuntimeException('ticket_delete_csat_count_changed');
    }
    $affected('DELETE FROM email_threads WHERE ticket_id IN ({ids})', [], $doomed);
    if ($mailRows > 0) {
        // Dispatch only picks rows with attempts < max_attempts (cron/mail_dispatch.php), so unsent
        // mail is cancelled; a row that already failed for good keeps its real error. Every row lets
        // go of the ticket. An email whose send already started still goes out.
        $affected("UPDATE mail_queue SET ticket_id = NULL, attempts = max_attempts, last_error = 'Cancelled: ticket deleted'
             WHERE ticket_id IN ({ids}) AND sent_at IS NULL AND attempts < max_attempts", [], $doomed);
        $affected('UPDATE mail_queue SET ticket_id = NULL WHERE ticket_id IN ({ids})', [], $doomed);
    }
    $affected('UPDATE westy_reports SET ticket_id = NULL, system_message_id = NULL WHERE tenant_id = ? AND ticket_id IN ({ids})', [$tenantId], $doomed);
    if ($messages > 0 && $affected('DELETE FROM messages WHERE ticket_id IN ({ids})', [], $doomed) !== $messages) {
        throw new RuntimeException('ticket_delete_message_count_changed');
    }
    // No trigger fires on removing a ticket; ticket_presence goes by its ON DELETE CASCADE.
    if ($affected('DELETE FROM tickets WHERE tenant_id = ? AND id IN ({ids})', [$tenantId], $doomed) !== count($doomed)) {
        throw new RuntimeException('ticket_delete_ticket_count_changed');
    }
    try {
        $pdo->commit();
    } catch (Throwable $error) {
        throw new TicketDeleteCommitUnknownException($storedNames, $error);
    }

    $result['deleted'] = count($doomed);
    $result['stored_names'] = $storedNames;
    return $result;
}

/** How many tickets this run has deleted so far, from its own audit rows: right even after a batch
 * whose answer never reached the browser. */
function ticket_delete_run_total(PDO $pdo, int $tenantId, string $runKey): int
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM ticket_deletions WHERE tenant_id = ? AND run_key = ?');
    $q->execute([$tenantId, $runKey]);
    return (int)$q->fetchColumn();
}

/**
 * Remove the files behind deleted attachment rows, after COMMIT: a rolled-back batch must never
 * have lost its files. Only names att_store() can create (40 hex), only regular files directly in
 * the attachments folder, never one another attachment row still names. A missing file is fine.
 *
 * @param list<string> $names
 * @return array{0:int, 1:list<string>} failures and the names that failed
 */
function ticket_delete_remove_files(PDO $pdo, string $dir, array $names): array
{
    $names = array_values(array_unique($names));
    if ($names === []) return [0, []];
    $failed = [];
    $valid = [];
    foreach ($names as $name) {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $name) === 1) $valid[] = $name;
        else $failed[] = $name;
    }
    $stillUsed = [];
    foreach (array_chunk($valid, 1000) as $chunk) {
        $q = $pdo->prepare('SELECT stored_name FROM attachments WHERE stored_name IN (' . ticket_delete_in(count($chunk)) . ')');
        $q->execute($chunk);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) $stillUsed[(string)$name] = true;
    }
    $dir = rtrim($dir, '/');
    foreach ($valid as $name) {
        if (isset($stillUsed[$name])) continue;
        $path = $dir . '/' . $name;
        if (is_link($path) || (file_exists($path) && !is_file($path))) { $failed[] = $name; continue; }
        if (!file_exists($path)) continue;
        if (!@unlink($path)) $failed[] = $name;
    }
    return [count($failed), $failed];
}
