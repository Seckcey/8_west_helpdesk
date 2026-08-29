<?php
/** Hermetic approval-grade technician time coverage; no server config required. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/time_entries.php';

$failures = 0;
$checks = 0;

function time_check(string $name, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
}

/** @param class-string<Throwable> $expected */
function time_expect_exception(
    string $name,
    string $expected,
    callable $operation,
    string $messageFragment = '',
): void {
    try {
        $operation();
        time_check($name, false);
    } catch (Throwable $error) {
        time_check(
            $name,
            $error instanceof $expected
                && ($messageFragment === '' || str_contains($error->getMessage(), $messageFragment)),
        );
    }
}

function time_iso(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** @return array<string, mixed> */
function time_valid_input(string $key, array $changes = []): array
{
    $end = new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC'));
    $start = $end->modify('-10 minutes');
    return array_replace([
        'ticket_id' => 100,
        'entry_key' => $key,
        'source' => 'timer',
        'worked_at' => time_iso($end),
        'started_at' => time_iso($start),
        'ended_at' => time_iso($end),
        'minutes' => 10,
        'note' => 'Investigated the workstation',
        'billable' => true,
    ], $changes);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE tenants (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
)');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
)');
$pdo->exec('CREATE TABLE tickets (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    client_id INTEGER NOT NULL,
    subject TEXT NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    FOREIGN KEY (client_id) REFERENCES clients (id)
)');
$pdo->exec('CREATE TABLE time_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    client_id INTEGER NOT NULL,
    ticket_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    entry_key TEXT NOT NULL,
    source TEXT NOT NULL,
    worked_at TEXT NOT NULL,
    started_at TEXT NULL,
    ended_at TEXT NULL,
    minutes INTEGER NOT NULL CHECK (minutes BETWEEN 1 AND 1440),
    note TEXT NOT NULL DEFAULT "",
    billable INTEGER NOT NULL DEFAULT 1,
    approval_status TEXT NOT NULL DEFAULT "pending",
    reviewed_by_user_id INTEGER NULL,
    reviewed_at TEXT NULL,
    review_note TEXT NOT NULL DEFAULT "",
    corrects_time_entry_id INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tenant_id, entry_key),
    UNIQUE (tenant_id, corrects_time_entry_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    FOREIGN KEY (client_id) REFERENCES clients (id),
    FOREIGN KEY (ticket_id) REFERENCES tickets (id),
    FOREIGN KEY (user_id) REFERENCES users (id),
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id),
    FOREIGN KEY (corrects_time_entry_id) REFERENCES time_entries (id)
)');
$pdo->exec('CREATE TABLE time_entry_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    time_entry_id INTEGER NOT NULL,
    actor_user_id INTEGER NOT NULL,
    event_kind TEXT NOT NULL,
    from_status TEXT NULL,
    to_status TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT "",
    snapshot_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (time_entry_id, event_kind),
    FOREIGN KEY (time_entry_id) REFERENCES time_entries (id)
)');
$pdo->exec("CREATE TRIGGER time_entry_submitted_event
    AFTER INSERT ON time_entries
    BEGIN
        INSERT INTO time_entry_events
            (tenant_id, time_entry_id, actor_user_id, event_kind, from_status, to_status, reason, snapshot_json)
        VALUES
            (NEW.tenant_id, NEW.id, NEW.user_id, 'logged', NULL, 'pending', '', '{}');
    END");
$pdo->exec("CREATE TRIGGER time_entry_review_event
    AFTER UPDATE OF approval_status ON time_entries
    WHEN OLD.approval_status = 'pending' AND NEW.approval_status IN ('approved', 'rejected')
    BEGIN
        UPDATE time_entries SET reviewed_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        INSERT INTO time_entry_events
            (tenant_id, time_entry_id, actor_user_id, event_kind, from_status, to_status, reason, snapshot_json)
        VALUES
            (NEW.tenant_id, NEW.id, NEW.reviewed_by_user_id, NEW.approval_status,
             OLD.approval_status, NEW.approval_status, NEW.review_note, '{}');
    END");
$pdo->exec("CREATE TRIGGER time_entry_correction_guard
    BEFORE INSERT ON time_entries
    WHEN NEW.corrects_time_entry_id IS NOT NULL
    BEGIN
        SELECT CASE WHEN NOT EXISTS (
            SELECT 1 FROM time_entries rejected
             WHERE rejected.id = NEW.corrects_time_entry_id
               AND rejected.tenant_id = NEW.tenant_id
               AND rejected.user_id = NEW.user_id
               AND rejected.ticket_id = NEW.ticket_id
               AND rejected.client_id = NEW.client_id
               AND rejected.source = NEW.source
               AND rejected.approval_status = 'rejected'
        ) THEN RAISE(ABORT, 'Only rejected time entries may be corrected') END;
    END");
$pdo->exec("CREATE TRIGGER time_entry_overlap_guard
    BEFORE INSERT ON time_entries
    WHEN NEW.started_at IS NOT NULL AND EXISTS (
        SELECT 1 FROM time_entries existing
         WHERE existing.tenant_id = NEW.tenant_id
           AND existing.user_id = NEW.user_id
           AND existing.approval_status IN ('pending', 'approved')
           AND existing.started_at IS NOT NULL
           AND existing.started_at < NEW.ended_at
           AND existing.ended_at > NEW.started_at
    )
    BEGIN
        SELECT RAISE(ABORT, 'Measured time overlaps existing pending or approved time');
    END");

$pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Tenant One'), (2, 'Tenant Two')");
$pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES
    (10, 1, 'Client One'),
    (20, 2, 'Client Two')");
$pdo->exec("INSERT INTO users (id, tenant_id, role, is_active) VALUES
    (1, 1, 'tech', 1),
    (2, 1, 'owner', 1),
    (3, 1, 'admin', 1),
    (4, 2, 'owner', 1),
    (5, 1, 'admin', 0)");
$pdo->exec("INSERT INTO tickets (id, tenant_id, client_id, subject) VALUES
    (100, 1, 10, 'Tenant one ticket'),
    (101, 1, 20, 'Corrupt cross-tenant client'),
    (200, 2, 20, 'Tenant two ticket')");

time_check('validation failures map to 422',
    time_entry_http_status(new TimeEntryValidationException('bad')) === 422);
time_check('missing tenant facts map to 404',
    time_entry_http_status(new TimeEntryNotFoundException('missing')) === 404);
time_check('role failures map to 403',
    time_entry_http_status(new TimeEntryForbiddenException('forbidden')) === 403);
time_check('idempotency and state conflicts map to 409',
    time_entry_http_status(new TimeEntryConflictException('conflict')) === 409);
time_check('unexpected faults stay server errors',
    time_entry_http_status(new RuntimeException('fault')) === 500);

$firstInput = time_valid_input('timer.20260826.0001', [
    // Browser-supplied authority is ignored; ticket and session win.
    'tenant_id' => 2,
    'client_id' => 20,
    'user_id' => 4,
    'approval_status' => 'approved',
]);
$first = time_entry_create($pdo, 1, 1, $firstInput);
time_check('new time is pending only', $first['approval_status'] === 'pending');
time_check('ticket derives tenant client and actor',
    $first['tenant_id'] === 1
    && $first['client_id'] === 10
    && $first['ticket_id'] === 100
    && $first['user_id'] === 1);
time_check('new time preserves factual fields',
    $first['source'] === 'timer'
    && $first['minutes'] === 10
    && $first['note'] === 'Investigated the workstation'
    && $first['billable'] === true);
time_check('new time reports a non replay', $first['replayed'] === false);
time_check('database owns logged event',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id = {$first['id']}")->fetchColumn() === 1);

$replayed = time_entry_create($pdo, 1, 1, $firstInput);
time_check('byte-equivalent retry returns the original id',
    $replayed['id'] === $first['id'] && $replayed['replayed'] === true);
time_check('safe retry inserts no duplicate row',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE entry_key = 'timer.20260826.0001'")->fetchColumn() === 1);
time_check('safe retry inserts no duplicate event',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id = {$first['id']}")->fetchColumn() === 1);

time_expect_exception(
    'changed facts on one key are a typed conflict',
    TimeEntryConflictException::class,
    fn() => time_entry_create($pdo, 1, 1, array_replace($firstInput, ['minutes' => 9])),
    'different time facts',
);
time_expect_exception(
    'changed note bytes on one key conflict',
    TimeEntryConflictException::class,
    fn() => time_entry_create($pdo, 1, 1, array_replace($firstInput, ['note' => 'Investigated the workstation '])),
);

$tenantTwo = time_entry_create($pdo, 2, 4, time_valid_input('timer.20260826.0001', [
    'ticket_id' => 200,
]));
time_check('the same key is isolated by tenant',
    $tenantTwo['tenant_id'] === 2 && $tenantTwo['id'] !== $first['id']);

time_expect_exception(
    'ticket lookup is tenant scoped',
    TimeEntryNotFoundException::class,
    fn() => time_entry_create($pdo, 1, 1, time_valid_input('timer.20260826.0002', ['ticket_id' => 200])),
);
time_expect_exception(
    'corrupt cross-tenant ticket client is rejected',
    TimeEntryNotFoundException::class,
    fn() => time_entry_create($pdo, 1, 1, time_valid_input('timer.20260826.0003', ['ticket_id' => 101])),
);
time_expect_exception(
    'cross-tenant actor is forbidden',
    TimeEntryForbiddenException::class,
    fn() => time_entry_create($pdo, 1, 4, time_valid_input('timer.20260826.0004')),
);
time_expect_exception(
    'inactive actor is forbidden',
    TimeEntryForbiddenException::class,
    fn() => time_entry_create($pdo, 1, 5, time_valid_input('timer.20260826.0005')),
);

$validationCases = [
    ['legacy is not an application source', ['source' => 'legacy'], 'Source'],
    ['entry key cannot be short', ['entry_key' => 'short'], 'Entry key'],
    ['entry key rejects spaces', ['entry_key' => 'timer key 202608260006'], 'Entry key'],
    ['minutes cannot be zero', ['minutes' => 0], 'Minutes'],
    ['minutes cannot exceed one day', ['minutes' => 1441], 'Minutes'],
    ['note must be valid utf8', ['note' => "bad\xFFtext"], 'UTF-8'],
    ['note cannot be silently truncated', ['note' => str_repeat('n', 256)], '255'],
    ['worked at requires explicit UTC', ['worked_at' => '2026-08-26 12:00:00'], 'RFC3339'],
    ['worked at rejects impossible dates', ['worked_at' => '2026-02-31T12:00:00Z'], 'calendar'],
    ['worked at rejects implausible future time', [
        'worked_at' => time_iso(new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC'))),
    ], 'reasonable'],
    ['timer requires both endpoints', ['ended_at' => null], 'supplied together'],
    ['timer requires an interval', ['started_at' => null, 'ended_at' => null], 'require started'],
];
$caseNo = 20;
foreach ($validationCases as [$name, $changes, $fragment]) {
    $caseNo++;
    time_expect_exception(
        $name,
        TimeEntryValidationException::class,
        fn() => time_entry_create($pdo, 1, 1, time_valid_input('timer.20260826.' . $caseNo, $changes)),
        $fragment,
    );
}

$intervalEnd = new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC'));
$intervalStart = $intervalEnd->modify('-10 minutes');
time_expect_exception(
    'suggestion cannot masquerade as measured timer time',
    TimeEntryValidationException::class,
    fn() => time_entry_create($pdo, 1, 1, time_valid_input('suggest.20260826.0040', ['source' => 'suggestion'])),
    'cannot claim',
);
time_expect_exception(
    'timer endpoints must be strictly ordered',
    TimeEntryValidationException::class,
    fn() => time_entry_create($pdo, 1, 1, time_valid_input('timer.20260826.0041', [
        'started_at' => time_iso($intervalEnd),
        'ended_at' => time_iso($intervalStart),
    ])),
    'must be before',
);
time_expect_exception(
    'worked at must be inside timer interval',
    TimeEntryValidationException::class,
    fn() => time_entry_create($pdo, 1, 1, time_valid_input('timer.20260826.0042', [
        'worked_at' => time_iso($intervalEnd->modify('-30 minutes')),
    ])),
    'inside',
);
time_expect_exception(
    'claimed minutes must match timer evidence',
    TimeEntryValidationException::class,
    fn() => time_entry_create($pdo, 1, 1, time_valid_input('timer.20260826.0043', ['minutes' => 60])),
    'reasonably match',
);

$suggested = time_entry_create($pdo, 1, 1, [
    'ticket_id' => 100,
    'entry_key' => 'suggest.20260826.0050',
    'source' => 'suggestion',
    'worked_at' => time_iso(new DateTimeImmutable('-1 minute', new DateTimeZone('UTC'))),
    'minutes' => '15',
    'note' => 'Reviewed recent activity',
    'billable' => false,
]);
time_check('suggestion supports unmeasured explicit time',
    $suggested['source'] === 'suggestion'
    && $suggested['started_at'] === null
    && $suggested['ended_at'] === null
    && $suggested['billable'] === false);

$reply = time_entry_create($pdo, 1, 1, [
    'ticket_id' => 100,
    'entry_key' => 'reply.20260826.0001',
    'source' => 'reply',
    'worked_at' => time_iso(new DateTimeImmutable('-1 minute', new DateTimeZone('UTC'))),
    'minutes' => 5,
    'note' => 'Replied on #100',
]);
time_check('reply source may record reviewed work without a timer interval',
    $reply['source'] === 'reply' && $reply['billable'] === true);

time_expect_exception(
    'technicians cannot approve their own queue',
    TimeEntryForbiddenException::class,
    fn() => time_entry_review($pdo, 1, 1, 'tech', $first['id'], 'approved'),
    'owners and admins',
);
time_expect_exception(
    'rejection requires a reason',
    TimeEntryValidationException::class,
    fn() => time_entry_review($pdo, 1, 2, 'owner', $first['id'], 'rejected', '   '),
    'reason',
);
time_expect_exception(
    'decision vocabulary is closed',
    TimeEntryValidationException::class,
    fn() => time_entry_review($pdo, 1, 2, 'owner', $first['id'], 'voided'),
);
time_expect_exception(
    'inactive reviewers are forbidden',
    TimeEntryForbiddenException::class,
    fn() => time_entry_review($pdo, 1, 5, 'admin', $first['id'], 'approved'),
);
time_expect_exception(
    'review lookup is tenant scoped',
    TimeEntryNotFoundException::class,
    fn() => time_entry_review($pdo, 2, 4, 'owner', $first['id'], 'approved'),
);

$beforeFacts = $pdo->query("SELECT ticket_id, client_id, user_id, minutes, note, billable, worked_at
                              FROM time_entries WHERE id = {$first['id']}")->fetch();
$approved = time_entry_review($pdo, 1, 2, 'owner', $first['id'], 'approved', 'Checked against ticket work');
$afterFacts = $pdo->query("SELECT ticket_id, client_id, user_id, minutes, note, billable, worked_at
                             FROM time_entries WHERE id = {$first['id']}")->fetch();
time_check('owner can approve one pending entry',
    $approved['approval_status'] === 'approved'
    && $approved['reviewed_by_user_id'] === 2
    && $approved['review_note'] === 'Checked against ticket work'
    && $approved['replayed'] === false);
time_check('database trigger owns reviewed at', is_string($approved['reviewed_at']) && $approved['reviewed_at'] !== '');
time_check('review does not rewrite factual time', $beforeFacts === $afterFacts);
time_check('database trigger records exactly one approval event',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id = {$first['id']}")->fetchColumn() === 2
    && (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id = {$first['id']} AND event_kind = 'approved'")->fetchColumn() === 1);
$approvedReviewedAt = $approved['reviewed_at'];
$approvedEventCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id = {$first['id']}"
)->fetchColumn();
$approvedReplay = time_entry_review(
    $pdo,
    1,
    2,
    'owner',
    $first['id'],
    'approved',
    '  Checked against ticket work  ',
);
time_check('an exact normalized approval retry returns the stored terminal review',
    $approvedReplay['id'] === $approved['id']
    && $approvedReplay['approval_status'] === 'approved'
    && $approvedReplay['reviewed_by_user_id'] === 2
    && $approvedReplay['review_note'] === 'Checked against ticket work'
    && $approvedReplay['replayed'] === true);
time_check('an exact approval retry does not move its timestamp or append an audit event',
    $approvedReplay['reviewed_at'] === $approvedReviewedAt
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id = {$first['id']}"
    )->fetchColumn() === $approvedEventCount);
time_expect_exception(
    'the original reviewer cannot replay a changed approval note',
    TimeEntryConflictException::class,
    fn() => time_entry_review($pdo, 1, 2, 'owner', $first['id'], 'approved', 'Different note'),
    'already been reviewed',
);
time_expect_exception(
    'the original reviewer cannot replay a changed decision',
    TimeEntryConflictException::class,
    fn() => time_entry_review($pdo, 1, 2, 'owner', $first['id'], 'rejected', 'Changed decision'),
    'already been reviewed',
);
time_expect_exception(
    'a different reviewer cannot claim the same approval receipt',
    TimeEntryConflictException::class,
    fn() => time_entry_review($pdo, 1, 3, 'admin', $first['id'], 'approved', 'Checked against ticket work'),
    'already been reviewed',
);
time_expect_exception(
    'reviewed entries cannot transition again',
    TimeEntryConflictException::class,
    fn() => time_entry_review($pdo, 1, 3, 'admin', $first['id'], 'rejected', 'Changed my mind'),
    'already been reviewed',
);

$rejected = time_entry_review($pdo, 1, 3, 'admin', $suggested['id'], 'rejected', '  Not enough supporting detail.  ');
time_check('admin can reject with a trimmed reason',
    $rejected['approval_status'] === 'rejected'
    && $rejected['reviewed_by_user_id'] === 3
    && $rejected['review_note'] === 'Not enough supporting detail.');
time_check('rejection event carries the reason',
    $pdo->query("SELECT reason FROM time_entry_events WHERE time_entry_id = {$suggested['id']} AND event_kind = 'rejected'")->fetchColumn()
        === 'Not enough supporting detail.');

$rejectedFactsBeforeCorrection = $pdo->query(
    "SELECT ticket_id, client_id, user_id, minutes, note, billable, source,
            worked_at, started_at, ended_at, approval_status, review_note
       FROM time_entries WHERE id = {$suggested['id']}"
)->fetch();
$correctionInput = [
    'entry_key' => 'correction.suggested.0001',
    'worked_at' => time_iso(new DateTimeImmutable('-1 minute', new DateTimeZone('UTC'))),
    'minutes' => 12,
    'note' => 'Added the missing support detail',
    'billable' => true,
];
$correction = time_entry_correct($pdo, 1, 1, $suggested['id'], $correctionInput);
time_check('rejected time gets a new pending replacement with explicit lineage',
    $correction['approval_status'] === 'pending'
    && $correction['corrects_time_entry_id'] === $suggested['id']
    && $correction['ticket_id'] === $suggested['ticket_id']
    && $correction['client_id'] === $suggested['client_id']
    && $correction['user_id'] === $suggested['user_id']
    && $correction['source'] === $suggested['source']
    && $correction['minutes'] === 12
    && $correction['note'] === 'Added the missing support detail');
time_check('creating a correction leaves every rejected fact and review byte unchanged',
    $rejectedFactsBeforeCorrection === $pdo->query(
        "SELECT ticket_id, client_id, user_id, minutes, note, billable, source,
                worked_at, started_at, ended_at, approval_status, review_note
           FROM time_entries WHERE id = {$suggested['id']}"
    )->fetch());
$correctionReplay = time_entry_correct($pdo, 1, 1, $suggested['id'], $correctionInput);
time_check('exact correction retry is idempotent',
    $correctionReplay['id'] === $correction['id']
    && $correctionReplay['replayed'] === true
    && (int)$pdo->query("SELECT COUNT(*) FROM time_entries
          WHERE corrects_time_entry_id = {$suggested['id']}")->fetchColumn() === 1);
time_expect_exception(
    'one rejected entry cannot grow two sibling replacements',
    TimeEntryConflictException::class,
    fn() => time_entry_correct(
        $pdo,
        1,
        1,
        $suggested['id'],
        array_replace($correctionInput, ['entry_key' => 'correction.suggested.0002']),
    ),
    'already has replacement',
);
time_expect_exception(
    'another technician cannot correct rejected time',
    TimeEntryForbiddenException::class,
    fn() => time_entry_correct($pdo, 1, 2, $suggested['id'], $correctionInput),
    'technician who logged',
);
time_expect_exception(
    'correction lookup is tenant scoped',
    TimeEntryNotFoundException::class,
    fn() => time_entry_correct($pdo, 2, 4, $suggested['id'], $correctionInput),
);
time_expect_exception(
    'pending or approved time cannot be called a correction target',
    TimeEntryConflictException::class,
    fn() => time_entry_correct($pdo, 1, 1, $reply['id'], [
        'entry_key' => 'correction.pending.0001',
        'worked_at' => time_iso(new DateTimeImmutable('-1 minute', new DateTimeZone('UTC'))),
        'minutes' => 5,
        'note' => 'Not allowed',
        'billable' => true,
    ]),
    'Only rejected',
);
time_expect_exception(
    'correction API facts cannot smuggle ticket or source authority',
    TimeEntryValidationException::class,
    fn() => time_entry_correct(
        $pdo,
        1,
        1,
        $suggested['id'],
        array_replace($correctionInput, ['ticket_id' => 200]),
    ),
    'server controlled',
);

$firstStart = new DateTimeImmutable((string)$first['started_at'], new DateTimeZone('UTC'));
$adjacentEnd = $firstStart;
$adjacentStart = $adjacentEnd->modify('-5 minutes');
$adjacentInput = time_valid_input('timer.adjacent.20260826.0001', [
    'worked_at' => time_iso($adjacentEnd),
    'started_at' => time_iso($adjacentStart),
    'ended_at' => time_iso($adjacentEnd),
    'minutes' => 5,
    'note' => 'Adjacent measured work',
]);
$adjacent = time_entry_create($pdo, 1, 1, $adjacentInput);
time_check('touching measured intervals are allowed without overlap',
    $adjacent['ended_at'] === $first['started_at']);
time_expect_exception(
    'overlapping measured time is a typed conflict',
    TimeEntryConflictException::class,
    fn() => time_entry_create($pdo, 1, 1, time_valid_input('timer.overlap.20260826.0001', [
        'worked_at' => time_iso($adjacentEnd->modify('-1 minute')),
        'started_at' => time_iso($adjacentStart->modify('+1 minute')),
        'ended_at' => time_iso($adjacentEnd->modify('-1 minute')),
        'minutes' => 3,
        'note' => 'Overlapping measured work',
    ])),
    'overlaps',
);
$adjacentRejected = time_entry_review(
    $pdo,
    1,
    2,
    'owner',
    $adjacent['id'],
    'rejected',
    'Wrong work note',
);
$measuredCorrection = time_entry_correct($pdo, 1, 1, $adjacentRejected['id'], [
    'entry_key' => 'correction.measured.0001',
    'minutes' => 5,
    'note' => 'Correct measured work note',
    'billable' => true,
]);
time_check('rejected measured interval stops blocking its one pending correction',
    $measuredCorrection['approval_status'] === 'pending'
    && $measuredCorrection['corrects_time_entry_id'] === $adjacentRejected['id']
    && $measuredCorrection['started_at'] === $adjacentRejected['started_at']
    && $measuredCorrection['ended_at'] === $adjacentRejected['ended_at']);

time_expect_exception(
    'review note cannot be silently truncated',
    TimeEntryValidationException::class,
    fn() => time_entry_review($pdo, 1, 2, 'owner', $reply['id'], 'approved', str_repeat('r', 501)),
    '500',
);

$ownerTime = time_entry_create($pdo, 1, 2, [
    'ticket_id' => 100,
    'entry_key' => 'suggest.owner.self.0001',
    'source' => 'suggestion',
    'worked_at' => time_iso(new DateTimeImmutable('-2 minutes', new DateTimeZone('UTC'))),
    'minutes' => 12,
    'note' => 'Owner handled an escalation',
    'billable' => true,
]);
$ownerReviewed = time_entry_review($pdo, 1, 2, 'owner', $ownerTime['id'], 'approved');
time_check('small MSP owner may review their own time',
    $ownerReviewed['approval_status'] === 'approved'
    && $ownerReviewed['user_id'] === 2
    && $ownerReviewed['reviewed_by_user_id'] === 2);

$retryFacts = time_entry_validate_create_input([
    'ticket_id' => 100,
    'entry_key' => 'reply.recovery.fingerprint.0001',
    'source' => 'reply',
    'worked_at' => time_iso(new DateTimeImmutable('-1 minute', new DateTimeZone('UTC'))),
    'started_at' => time_iso(new DateTimeImmutable('-6 minutes', new DateTimeZone('UTC'))),
    'ended_at' => time_iso(new DateTimeImmutable('-1 minute', new DateTimeZone('UTC'))),
    'minutes' => 5,
    'note' => 'Replied on #100',
    'billable' => false,
]);
$retryFingerprint = time_entry_retry_fingerprint($retryFacts);
$legacyRetryFields = [];
foreach ([
    'ticket_id', 'entry_key', 'source', 'worked_at', 'started_at',
    'ended_at', 'minutes', 'note', 'billable',
] as $legacyRetryField) {
    $legacyRetryFields[$legacyRetryField] = $retryFacts[$legacyRetryField] ?? null;
}
$legacyRetryJson = json_encode(
    $legacyRetryFields,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
);
time_check('reply recovery fingerprint is stable for exact normalized facts',
    hash_equals($retryFingerprint, time_entry_retry_fingerprint($retryFacts))
    && hash_equals(hash('sha256', $legacyRetryJson), $retryFingerprint));
$changedRetryFacts = $retryFacts;
$changedRetryFacts['billable'] = 1;
time_check('reply recovery fingerprint changes with billing provenance',
    !hash_equals($retryFingerprint, time_entry_retry_fingerprint($changedRetryFacts)));
$rawRetryFingerprint = time_entry_retry_request_fingerprint($retryFacts);
time_check('pre-validation reply recovery fingerprint preserves exact request facts',
    hash_equals($rawRetryFingerprint, time_entry_retry_request_fingerprint($retryFacts))
    && !hash_equals(
        $rawRetryFingerprint,
        time_entry_retry_request_fingerprint(array_replace($retryFacts, ['ended_at' => time_iso(new DateTimeImmutable())])),
    ));

time_check('CSV export neutralizes formula and control-prefixed cells',
    time_entry_csv_safe_cell('=HYPERLINK("https://example.test")') === "'=HYPERLINK(\"https://example.test\")"
    && time_entry_csv_safe_cell("\t+SUM(1,1)") === "'\t+SUM(1,1)"
    && time_entry_csv_safe_cell('-4') === "'-4"
    && time_entry_csv_safe_cell('@cmd') === "'@cmd"
    && time_entry_csv_safe_cell('ordinary note') === 'ordinary note');

// Static integration gates: the service is useful only if every production
// writer and downstream consumer actually honors it. Keep these assertions
// close to the behavioral suite so a later shortcut fails CI visibly.
$appRoot = realpath(__DIR__ . '/..');
$timeInsertWriters = [];
$timeDeleteWriters = [];
if (is_string($appRoot)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $absolute = str_replace('\\', '/', $file->getPathname());
        if (str_contains($absolute, '/tests/')) continue;
        $source = file_get_contents($file->getPathname());
        if (is_string($source) && preg_match('/\bINSERT\s+INTO\s+`?time_entries`?\b/i', $source) === 1) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot))), '/');
            $timeInsertWriters[] = $relative;
        }
        if (is_string($source)
            && preg_match('/\bDELETE(?:\s+[A-Za-z_][A-Za-z0-9_]*)?\s+FROM\s+`?time_entries`?\b/i', $source) === 1) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot))), '/');
            $timeDeleteWriters[] = $relative;
        }
    }
}
sort($timeInsertWriters);
time_check('only the central service inserts time entries',
    $timeInsertWriters === ['lib/time_entries.php']);
sort($timeDeleteWriters);
time_check('no production PHP path deletes immutable time entries', $timeDeleteWriters === []);

$mergeSource = file_get_contents(__DIR__ . '/../public/api/ticket_merge.php');
time_check('ticket merge never rewrites immutable time facts',
    is_string($mergeSource)
    && preg_match('/\bUPDATE\s+`?time_entries`?\b/i', $mergeSource) !== 1);

foreach (['reports.php', 'reports_export.php'] as $reportFile) {
    $reportSource = file_get_contents(__DIR__ . '/../public/' . $reportFile);
    $timeQueryCount = 0;
    $approvedFilterCount = 0;
    if (is_string($reportSource)) {
        $timeQueryCount = preg_match_all('/\bFROM\s+time_entries\b/i', $reportSource);
        $approvedFilterCount = preg_match_all(
            '/\bapproval_status\s*=\s*[\'\"]approved[\'\"]/i',
            $reportSource,
        );
    }
    time_check($reportFile . ' exports or reports approved time only',
        $timeQueryCount > 0 && $approvedFilterCount >= $timeQueryCount);
}

foreach (['time.php', 'reports.php', 'reports_export.php'] as $tenantConsumer) {
    $tenantSource = file_get_contents(__DIR__ . '/../public/' . $tenantConsumer);
    time_check($tenantConsumer . ' binds time reads to the authenticated user tenant',
        is_string($tenantSource)
        && str_contains($tenantSource, '$user[\'tenant_id\']')
        && !str_contains($tenantSource, 'tenant_id()'));
}
$authSource = file_get_contents(__DIR__ . '/../lib/auth.php');
time_check('authentication stamps the database-resolved tenant into every session',
    is_string($authSource)
    && substr_count($authSource, '$_SESSION[\'tenant_id\'] =') >= 3);

$timePageSource = file_get_contents(__DIR__ . '/../public/time.php');
time_check('suggestions are limited to the same UTC activity day they suppress',
    is_string($timePageSource)
    && str_contains($timePageSource, 't.updated_at >= UTC_DATE()')
    && str_contains($timePageSource, 'e.worked_at >= UTC_DATE()'));
time_check('suggestion facts stay stable across reload and offline retry',
    is_string($timePageSource)
    && str_contains($timePageSource, '10 AS minutes')
    && str_contains($timePageSource, "'Recent activity today; conservative 10m suggestion' AS reason")
    && !str_contains($timePageSource, 'TIMESTAMPDIFF('));
time_check('older pending and reviewed entries remain visible to technicians',
    is_string($timePageSource)
    && str_contains($timePageSource, "(e.approval_status = 'pending') DESC")
    && str_contains($timePageSource, 'id="time-entry-history"')
    && str_contains($timePageSource, 'Review note:'));
time_check('pending summary counts the technician full queue, not only today',
    is_string($timePageSource)
    && str_contains($timePageSource, "approval_status = 'pending'")
    && str_contains($timePageSource, '$pendingOwn = (int)$pendingCountQuery->fetchColumn()'));
time_check('approval queue renders worked-at source and interval evidence',
    is_string($timePageSource)
    && str_contains($timePageSource, 'Worked <?= h($utcLabel')
    && str_contains($timePageSource, 'source <?= h($e[\'source\']) ?>')
    && str_contains($timePageSource, 'measured <?= h($utcLabel')
    && str_contains($timePageSource, 'no measured interval'));
time_check('rejected entries expose append-only correction lineage instead of an edit path',
    is_string($timePageSource)
    && str_contains($timePageSource, 'data-rejected-entry-id=')
    && str_contains($timePageSource, 'Correct &amp; resubmit')
    && str_contains($timePageSource, 'original history kept')
    && str_contains($timePageSource, 'replacement.corrects_time_entry_id = e.id'));

$exportSource = file_get_contents(__DIR__ . '/../public/reports_export.php');
time_check('every untrusted CSV text field is formula-neutralized',
    is_string($exportSource)
    && substr_count($exportSource, 'time_entry_csv_safe_cell(') >= 4);

$appJs = file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$submitTimer = '';
$clearTimer = '';
if (is_string($appJs)) {
    $submitStart = strpos($appJs, 'async function submitStoppedTimer');
    $submitEnd = $submitStart === false ? false : strpos($appJs, 'function stopTimer', $submitStart);
    if ($submitStart !== false && $submitEnd !== false) {
        $submitTimer = substr($appJs, $submitStart, $submitEnd - $submitStart);
    }
    $clearStart = strpos($appJs, 'function clearMatchingTimer');
    $clearEnd = $clearStart === false ? false : strpos($appJs, 'function fmt', $clearStart);
    if ($clearStart !== false && $clearEnd !== false) {
        $clearTimer = substr($appJs, $clearStart, $clearEnd - $clearStart);
    }
}
time_check('browser creates one stable key and sends that same key',
    is_string($appJs)
    && str_contains($appJs, 'entryKey: newEntryKey()')
    && str_contains($submitTimer, 'entry_key: t.entryKey'));
time_check('browser timer storage is scoped and stamped to authenticated tenant and technician',
    is_string($appJs)
    && str_contains($appJs, 'safeharbor.timer.v2.')
    && str_contains($appJs, 'tenantId: timerTenantId')
    && str_contains($appJs, 'userId: timerUserId')
    && str_contains($appJs, 'String(t.tenantId) !== timerTenantId')
    && str_contains($appJs, 'String(t.userId) !== timerUserId'));
time_check('reply retry preserves source note and billable facts',
    is_string($appJs)
    && str_contains($submitTimer, 'const submission = timerSubmission(t)')
    && str_contains($submitTimer, 'source: submission.source')
    && str_contains($submitTimer, 'note: submission.note')
    && str_contains($submitTimer, 'billable: submission.billable')
    && str_contains($appJs, 'source: "reply"'));
time_check('a stopped submission cannot be rebound to a later composer submit',
    is_string($appJs)
    && str_contains($appJs, 't.state === "stopped" && t.submission')
    && str_contains($appJs, 'event.preventDefault()')
    && str_contains($appJs, 'Retry or discard the saved time before sending another message.'));
time_check('every stopped timer freezes provenance before its first network attempt',
    $submitTimer !== ''
    && str_contains($submitTimer, 'if (!t.submission)')
    && str_contains($submitTimer, 't = { ...t, submission: timerSubmission(t) }')
    && strpos($submitTimer, 'writeTimer(t)') < strpos($submitTimer, 'await api('));
time_check('unconfirmed reply falls back only after 403 to truthful standalone timer facts',
    $submitTimer !== ''
    && str_contains($submitTimer, 'error?.status === 403 && error?.code === "reply_retry_unconfirmed"')
    && str_contains($submitTimer, 'source: "timer"')
    && str_contains($submitTimer, 'note: "Work on #" + current.ticketId')
    && str_contains($submitTimer, 'billable: submission.billable')
    && str_contains($submitTimer, 'Message was not confirmed; measured time was submitted as a standalone timer.'));
$ticketSource = file_get_contents(__DIR__ . '/../public/ticket.php');
$timerApiSource = file_get_contents(__DIR__ . '/../public/api/timer.php');
$retryGrantPosition = is_string($ticketSource)
    ? strpos($ticketSource, "\$_SESSION['time_entry_retry'] = [")
    : false;
$replyValidationPosition = is_string($ticketSource)
    ? strpos($ticketSource, 'time_entry_validate_create_input(')
    : false;
time_check('reply recovery grant is actor-bound and persisted before validation',
    is_string($ticketSource)
    && $retryGrantPosition !== false
    && $replyValidationPosition !== false
    && $retryGrantPosition < $replyValidationPosition
    && str_contains($ticketSource, "'tenant_id' => (int)\$user['tenant_id']")
    && str_contains($ticketSource, "'user_id' => (int)\$user['id']")
    && str_contains($ticketSource, 'time_entry_retry_request_fingerprint($timeInput)')
    && str_contains($ticketSource, 'time_entry_retry_fingerprint($normalizedTime)'));
time_check('reply API accepts only an exact same-actor replay or matching recovery grant',
    is_string($timerApiSource)
    && str_contains($timerApiSource, '$existingReplay')
    && str_contains($timerApiSource, '$grantedRetry')
    && str_contains($timerApiSource, "(int)\$existing['user_id'] === (int)\$user['id']")
    && str_contains($timerApiSource, "(int)(\$grant['tenant_id'] ?? 0) === (int)\$user['tenant_id']")
    && str_contains($timerApiSource, "(int)(\$grant['user_id'] ?? 0) === (int)\$user['id']")
    && str_contains($timerApiSource, '$rawGrant || $normalizedGrant')
    && str_contains($timerApiSource, 'Reply-time retry is not authorized for these facts.')
    && str_contains($timerApiSource, "'reply_retry_unconfirmed'"));

$clientsSource = file_get_contents(__DIR__ . '/../public/clients.php');
time_check('demo purge is hidden from technicians and denied server-side',
    is_string($clientsSource)
    && str_contains($clientsSource, "\$canPurgeDemo = in_array((string)\$user['role'], ['owner', 'admin'], true)")
    && str_contains($clientsSource, 'if (!$canPurgeDemo)')
    && str_contains($clientsSource, 'http_response_code(403)')
    && str_contains($clientsSource, 'if ($hasDemo && $canPurgeDemo)'));
time_check('stopped timer remains local until the exact server acknowledgement',
    $submitTimer !== ''
    && !str_contains($submitTimer, 'writeTimer(null)')
    && str_contains($submitTimer, 'responseEntryKey(r) !== t.entryKey')
    && str_contains($submitTimer, 'clearMatchingTimer(t.entryKey)')
    && str_contains($clearTimer, 'current.entryKey !== entryKey')
    && str_contains($clearTimer, 'writeTimer(null)'));
time_check('browser correction freezes one idempotency key and exact payload before network',
    is_string($appJs)
    && str_contains($appJs, 'function newCorrectionKey()')
    && str_contains($appJs, 'button.dataset.correctionPayload = JSON.stringify(payload)')
    && str_contains($appJs, 'await api("/api/time_entry_correction.php", payload)')
    && str_contains($appJs, 'responseEntryKey(result) !== payload.entry_key')
    && str_contains($appJs, 'billingAnswer === null')
    && str_contains($appJs, 'Type B for billable or I for internal.')
    && str_contains($appJs, 'function parseCorrectionUtc(value)')
    && str_contains($appJs, 'Corrected start (UTC, YYYY-MM-DDTHH:MM:SSZ)')
    && str_contains($appJs, 'button.dataset.correctionPayload = ""'));

$correctionApiSource = file_get_contents(__DIR__ . '/../public/api/time_entry_correction.php');
time_check('correction endpoint derives tenant actor ticket and source on the server',
    is_string($correctionApiSource)
    && str_contains($correctionApiSource, '(int) $user[\'tenant_id\']')
    && str_contains($correctionApiSource, '(int) $user[\'id\']')
    && str_contains($correctionApiSource, 'time_entry_correct(')
    && !str_contains($correctionApiSource, 'INSERT INTO time_entries'));

$reviewApiSource = file_get_contents(__DIR__ . '/../public/api/time_entry_review.php');
time_check('review endpoint returns one exact terminal-review acknowledgement',
    is_string($reviewApiSource)
    && str_contains($reviewApiSource, "'review_ack' => [")
    && str_contains($reviewApiSource, "'entry_id' => (int) \$entry['id']")
    && str_contains($reviewApiSource, "'decision' => \$decisionLabel")
    && str_contains($reviewApiSource, "'reviewer_user_id' => (int) \$entry['reviewed_by_user_id']")
    && str_contains($reviewApiSource, "'note' => (string) \$entry['review_note']")
    && str_contains($reviewApiSource, "'replayed' => (bool) \$entry['replayed']"));

$migration016Source = file_get_contents(__DIR__ . '/../db/migrations/016_time_corrections_overlap.sql');
time_check('migration 016 installs database locks, overlap reads, and immutable correction ownership',
    is_string($migration016Source)
    && str_contains($migration016Source, 'INSERT IGNORE INTO time_entry_interval_guards')
    && str_contains($migration016Source, 'FOR UPDATE;')
    && str_contains($migration016Source, 'Measured time overlaps existing pending or approved time')
    && str_contains($migration016Source, 'Only rejected time entries may be corrected')
    && str_contains($migration016Source, 'NEW.corrects_time_entry_id <=> OLD.corrects_time_entry_id')
    && str_contains($migration016Source, 'trg_time_016_insert_swap_guard')
    && str_contains($migration016Source, 'Time audit writes are locked for migration 016 trigger swap')
    && str_contains($migration016Source, 'trg_time_016_measured_insert_aux_swap')
    && str_contains($migration016Source, 'migration 016 auxiliary trigger swap'));

$mysqlHarness = file_get_contents(__DIR__ . '/time_entries_mysql_test.php');
$correctionMysqlHarness = file_get_contents(__DIR__ . '/time_corrections_mysql_test.php');
$validateWorkflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/validate.yml');
time_check('destructive MySQL proof is standalone and fails closed without an explicit fixture',
    is_string($mysqlHarness)
    && !str_contains($mysqlHarness, 'lib/bootstrap.php')
    && str_contains($mysqlHarness, "getenv('SAFEHARBOR_TIME_TEST_DISPOSABLE_SERVER')")
    && str_contains($mysqlHarness, "\$disposableServer !== '1'")
    && str_contains($mysqlHarness, "getenv('SAFEHARBOR_TIME_TEST_DB')")
    && str_contains($mysqlHarness, 'Refusing destructive test database base.')
    && str_contains($mysqlHarness, '$testDatabase = $databaseBase .')
    && str_contains($mysqlHarness, 'SELECT DATABASE()')
    && str_contains($mysqlHarness, 'fixture connection selected the exact random database')
    && str_contains($mysqlHarness, 'CREATE DATABASE {$quotedDatabase}')
    && !str_contains($mysqlHarness, 'CREATE DATABASE IF NOT EXISTS')
    && str_contains($mysqlHarness, 'DROP DATABASE IF EXISTS {$quotedDatabase}')
    && str_contains($mysqlHarness, 'Time-entry MySQL fixture unavailable:')
    && str_contains($mysqlHarness, "getenv('SAFEHARBOR_TIME_REVIEW_RACE_WORKER')")
    && str_contains($mysqlHarness, 'performance_schema.data_lock_waits')
    && str_contains($mysqlHarness, 'concurrent reviews serialize into one exact replay or one truthful conflict')
    && substr_count($mysqlHarness, 'exit(2);') >= 3);
time_check('Validate executes the 98-check MySQL migration, guard, and review-race proof',
    is_string($validateWorkflow)
    && str_contains($validateWorkflow, 'Test approval-grade time migration and database guards')
    && str_contains($validateWorkflow, "SAFEHARBOR_TIME_TEST_DISPOSABLE_SERVER: '1'")
    && str_contains($validateWorkflow, 'SAFEHARBOR_TIME_TEST_DB: safeharbor_time_test')
    && str_contains($validateWorkflow, 'run: php app/tests/time_entries_mysql_test.php'));
time_check('Validate executes the random-database correction and real race proof',
    is_string($correctionMysqlHarness)
    && str_contains($correctionMysqlHarness, '$testDatabase = $databaseBase . \'_correction_\' . $runId')
    && str_contains($correctionMysqlHarness, "getenv('SAFEHARBOR_TIME_CORRECTION_RACE_WORKER')")
    && str_contains($correctionMysqlHarness, 'concurrent overlap waits on the database UTC-day guard')
    && str_contains($correctionMysqlHarness, 'DROP DATABASE IF EXISTS {$quotedDatabase}')
    && is_string($validateWorkflow)
    && str_contains($validateWorkflow, 'Test time corrections and measured overlap races')
    && str_contains($validateWorkflow, 'run: php app/tests/time_corrections_mysql_test.php'));

fwrite(STDOUT, "Approval-grade technician time: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
