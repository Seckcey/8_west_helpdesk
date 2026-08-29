<?php
/** Hermetic contract proof for append-only approved-time adjustments. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/time_entry_adjustments.php';

$checks = 0;
$failures = 0;

function adjustment_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$checks} - {$name}\n";
    }
}

function adjustment_expect(
    string $name,
    callable $operation,
    string $class,
    string $message = '',
): void {
    try {
        $operation();
        adjustment_check($name, false);
    } catch (Throwable $error) {
        adjustment_check(
            $name,
            $error instanceof $class
                && ($message === '' || str_contains($error->getMessage(), $message)),
        );
    }
}

/** @return array<string, mixed> */
function adjustment_input(array $replace = []): array
{
    return array_replace([
        'entry_id' => 1001,
        'adjustment_key' => 'adjustment.test.0001',
        'expected_version' => 0,
        'effective_minutes' => 45,
        'effective_billable' => true,
        'reason' => 'Remove duplicate troubleshooting time',
    ], $replace);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE tenants (id INTEGER NOT NULL PRIMARY KEY)');
$pdo->exec('CREATE TABLE users (
  id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, role TEXT NOT NULL,
  is_active INTEGER NOT NULL, PRIMARY KEY (id), UNIQUE (tenant_id, id)
)');
$pdo->exec('CREATE TABLE time_entries (
  id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, minutes INTEGER NOT NULL,
  billable INTEGER NOT NULL, approval_status TEXT NOT NULL,
  reviewed_by_user_id INTEGER NULL, reviewed_at TEXT NULL,
  PRIMARY KEY (id), UNIQUE (tenant_id, id)
)');
$pdo->exec('CREATE TABLE time_entry_approval_adjustments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  time_entry_id INTEGER NOT NULL,
  adjustment_key TEXT NOT NULL,
  version_no INTEGER NOT NULL,
  effective_minutes INTEGER NOT NULL,
  effective_billable INTEGER NOT NULL,
  reason TEXT NOT NULL,
  actor_user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id, adjustment_key),
  UNIQUE (tenant_id, time_entry_id, version_no)
)');
$pdo->exec("INSERT INTO users (id,tenant_id,role,is_active) VALUES
  (101,1,'owner',1),(102,1,'admin',1),(103,1,'tech',1),
  (104,1,'owner',0),(201,2,'owner',1)");
$pdo->exec('INSERT INTO tenants (id) VALUES (1),(2)');
$pdo->exec("INSERT INTO time_entries
  (id,tenant_id,minutes,billable,approval_status,reviewed_by_user_id,reviewed_at) VALUES
  (1001,1,60,1,'approved',101,'2026-08-28 12:30:00'),
  (1002,1,30,0,'approved',101,'2026-08-28 12:30:00'),
  (1003,1,20,1,'pending',NULL,NULL),
  (1004,1,20,1,'rejected',101,'2026-08-28 12:30:00'),
  (1005,1,20,1,'approved',NULL,NULL),
  (2001,2,25,1,'approved',201,'2026-08-28 12:30:00')");

adjustment_expect(
    'request fields must match the exact six-field contract',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', array_replace(adjustment_input(), ['tenant_id' => 1]),
    ),
    TimeEntryValidationException::class,
    'exact contract',
);
adjustment_expect(
    'missing request field is rejected',
    function () use ($pdo): void {
        $input = adjustment_input();
        unset($input['reason']);
        time_entry_adjustment_create($pdo, 1, 101, 'owner', $input);
    },
    TimeEntryValidationException::class,
    'exact contract',
);
adjustment_expect(
    'adjustment key cannot be short',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['adjustment_key' => 'short']),
    ),
    TimeEntryValidationException::class,
    'Adjustment key',
);
adjustment_expect(
    'adjustment key rejects spaces',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input([
            'adjustment_key' => 'adjustment has spaces',
        ]),
    ),
    TimeEntryValidationException::class,
    'Adjustment key',
);
adjustment_expect(
    'reason is required after trimming',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['reason' => " \t "]),
    ),
    TimeEntryValidationException::class,
    'reason is required',
);
adjustment_expect(
    'reason limit counts UTF-8 characters',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['reason' => str_repeat('é', 501)]),
    ),
    TimeEntryValidationException::class,
    '500',
);
adjustment_expect(
    'invalid UTF-8 reason is rejected',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['reason' => "bad\xFF"]),
    ),
    TimeEntryValidationException::class,
    'valid UTF-8',
);
adjustment_expect(
    'negative effective minutes are rejected',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['effective_minutes' => -1]),
    ),
    TimeEntryValidationException::class,
    'allowed range',
);
adjustment_expect(
    'fractional effective minutes are rejected',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['effective_minutes' => 12.5]),
    ),
    TimeEntryValidationException::class,
    'whole number',
);
adjustment_expect(
    'zero effective minutes cannot remain billable',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['effective_minutes' => 0]),
    ),
    TimeEntryValidationException::class,
    'Zero effective minutes',
);
adjustment_expect(
    'technicians cannot adjust approved time',
    fn() => time_entry_adjustment_create($pdo, 1, 103, 'tech', adjustment_input()),
    TimeEntryForbiddenException::class,
    'owners and admins',
);
adjustment_expect(
    'inactive owner cannot adjust approved time',
    fn() => time_entry_adjustment_create($pdo, 1, 104, 'owner', adjustment_input()),
    TimeEntryForbiddenException::class,
    'active owner or admin',
);
adjustment_expect(
    'caller role cannot overstate a database technician',
    fn() => time_entry_adjustment_create($pdo, 1, 103, 'owner', adjustment_input()),
    TimeEntryForbiddenException::class,
    'active owner or admin',
);
adjustment_expect(
    'cross-tenant parent is not found',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['entry_id' => 2001]),
    ),
    TimeEntryNotFoundException::class,
    'this tenant',
);
adjustment_expect(
    'pending time cannot be adjusted',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['entry_id' => 1003]),
    ),
    TimeEntryConflictException::class,
    'Only approved time',
);
adjustment_expect(
    'rejected time cannot be adjusted',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['entry_id' => 1004]),
    ),
    TimeEntryConflictException::class,
    'Only approved time',
);
adjustment_expect(
    'approved label without reviewer evidence fails closed',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['entry_id' => 1005]),
    ),
    TimeEntryConflictException::class,
    'review evidence',
);
adjustment_expect(
    'effective minutes cannot exceed immutable original minutes',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['effective_minutes' => 61]),
    ),
    TimeEntryValidationException::class,
    'original approved minutes',
);

$originalBefore = $pdo->query('SELECT * FROM time_entries WHERE id=1001')->fetch();
$first = time_entry_adjustment_create($pdo, 1, 101, 'owner', adjustment_input());
adjustment_check(
    'first correction records version one and normalized effective facts',
    $first['id'] === 1
        && $first['tenant_id'] === 1
        && $first['time_entry_id'] === 1001
        && $first['version'] === 1
        && $first['effective_minutes'] === 45
        && $first['effective_billable'] === true
        && $first['original_minutes'] === 60
        && $first['original_billable'] === true
        && $first['replayed'] === false,
);
adjustment_check(
    'approved parent facts remain byte-for-byte unchanged',
    $originalBefore === $pdo->query('SELECT * FROM time_entries WHERE id=1001')->fetch(),
);
$firstReplay = time_entry_adjustment_create($pdo, 1, 101, 'owner', adjustment_input());
adjustment_check(
    'lost-response retry returns the exact stored adjustment',
    $firstReplay['id'] === $first['id']
        && $firstReplay['created_at'] === $first['created_at']
        && $firstReplay['replayed'] === true
        && (int) $pdo->query('SELECT COUNT(*) FROM time_entry_approval_adjustments')->fetchColumn() === 1,
);
adjustment_expect(
    'same key with changed minutes conflicts',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input(['effective_minutes' => 44]),
    ),
    TimeEntryConflictException::class,
    'different approved-time facts',
);
adjustment_expect(
    'same key with changed actor conflicts',
    fn() => time_entry_adjustment_create($pdo, 1, 102, 'admin', adjustment_input()),
    TimeEntryConflictException::class,
    'different approved-time facts',
);
adjustment_expect(
    'stale expected version conflicts',
    fn() => time_entry_adjustment_create(
        $pdo, 1, 101, 'owner', adjustment_input([
            'adjustment_key' => 'adjustment.test.0002',
            'effective_minutes' => 30,
        ]),
    ),
    TimeEntryConflictException::class,
    'form was loaded',
);

$restored = time_entry_adjustment_create($pdo, 1, 102, 'admin', adjustment_input([
    'adjustment_key' => 'adjustment.test.0002',
    'expected_version' => 1,
    'effective_minutes' => 60,
    'reason' => 'Restore minutes after checking the source record',
]));
adjustment_check(
    'later version may restore time up to the immutable original',
    $restored['version'] === 2
        && $restored['effective_minutes'] === 60
        && $restored['actor_user_id'] === 102,
);
$latest = time_entry_adjustment_latest($pdo, 1, 1001);
adjustment_check(
    'single-entry lookup returns the latest effective evidence',
    is_array($latest)
        && $latest['id'] === $restored['id']
        && $latest['version'] === 2
        && $latest['replayed'] === false,
);

adjustment_expect(
    'originally internal time can never become billable',
    fn() => time_entry_adjustment_create($pdo, 1, 101, 'owner', adjustment_input([
        'entry_id' => 1002,
        'adjustment_key' => 'adjustment.internal.0001',
        'effective_minutes' => 20,
    ])),
    TimeEntryValidationException::class,
    'never become billable',
);
$internal = time_entry_adjustment_create($pdo, 1, 101, 'owner', adjustment_input([
    'entry_id' => 1002,
    'adjustment_key' => 'adjustment.internal.0001',
    'effective_minutes' => 20,
    'effective_billable' => false,
    'reason' => 'Correct internal training time',
]));
adjustment_check(
    'originally internal time remains nonbillable',
    $internal['original_billable'] === false
        && $internal['effective_billable'] === false,
);
$zeroed = time_entry_adjustment_create($pdo, 1, 101, 'owner', adjustment_input([
    'adjustment_key' => 'adjustment.test.0003',
    'expected_version' => 2,
    'effective_minutes' => 0,
    'effective_billable' => false,
    'reason' => 'Remove the duplicate entry from effective totals',
]));
adjustment_check(
    'zero-minute correction is allowed only as nonbillable',
    $zeroed['version'] === 3
        && $zeroed['effective_minutes'] === 0
        && $zeroed['effective_billable'] === false,
);
$latestByEntry = time_entry_adjustment_latest_by_entry($pdo, 1, [1001, 1002, 1001]);
adjustment_check(
    'batch lookup deduplicates ids and returns each latest version',
    count($latestByEntry) === 2
        && $latestByEntry[1001]['version'] === 3
        && $latestByEntry[1002]['version'] === 1,
);
adjustment_check(
    'batch lookup accepts an empty page without querying',
    time_entry_adjustment_latest_by_entry($pdo, 1, []) === [],
);
$historyByEntry = time_entry_adjustment_history_by_entry($pdo, 1, [1002, 1001, 1001]);
adjustment_check(
    'manager history returns every numbered slip in ascending order',
    count($historyByEntry) === 2
        && array_column($historyByEntry[1001], 'version') === [1, 2, 3]
        && array_column($historyByEntry[1001], 'actor_user_id') === [101, 102, 101]
        && array_column($historyByEntry[1002], 'version') === [1],
);
adjustment_check(
    'manager history accepts an empty page without querying',
    time_entry_adjustment_history_by_entry($pdo, 1, []) === [],
);

$migration = file_get_contents(__DIR__ . '/../db/migrations/020_time_approval_adjustments.sql');
$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
$bootstrap = file_get_contents(__DIR__ . '/../lib/bootstrap.php');
$api = file_get_contents(__DIR__ . '/../public/api/time_entry_adjustment.php');
$timePage = file_get_contents(__DIR__ . '/../public/time.php');
$reportsPage = file_get_contents(__DIR__ . '/../public/reports.php');
$reportsExport = file_get_contents(__DIR__ . '/../public/reports_export.php');
$coastmarkExport = file_get_contents(__DIR__ . '/../lib/coastmark_time_export.php');
adjustment_check(
    'migration pins tenant-scoped unique keys and foreign keys',
    is_string($migration)
        && str_contains($migration, 'UNIQUE KEY uq_time_adjustment_tenant_key (tenant_id, adjustment_key)')
        && str_contains($migration, 'UNIQUE KEY uq_time_adjustment_entry_version')
        && str_contains($migration, 'FOREIGN KEY (tenant_id, time_entry_id)')
        && str_contains($migration, 'FOREIGN KEY (tenant_id, actor_user_id)'),
);
adjustment_check(
    'migration permanently guards insert authority plus update and delete',
    is_string($migration)
        && str_contains($migration, 'CREATE TRIGGER trg_time_adjustments_before_insert')
        && str_contains($migration, 'CREATE TRIGGER trg_time_adjustments_no_update')
        && str_contains($migration, 'CREATE TRIGGER trg_time_adjustments_no_delete')
        && str_contains($migration, 'FOR UPDATE')
        && str_contains($migration, 'UTC_TIMESTAMP()'),
);
adjustment_check(
    'migration first application is table-locked until permanent guards exist',
    is_string($migration)
        && str_contains($migration, 'CONSTRAINT ck_time_adjustment_install_lock CHECK (0 = 1)')
        && str_contains($migration, 'DROP CHECK ck_time_adjustment_install_lock')
        && str_contains($migration, '@time_adjustment_install_lock_remaining = 0'),
);
adjustment_check(
    'database reason normalization matches the six PHP trim characters',
    is_string($migration)
        && str_contains($migration, 'ASCII(LEFT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32)')
        && str_contains($migration, 'ASCII(RIGHT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32)')
        && is_string($schema)
        && str_contains($schema, 'ASCII(LEFT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32)')
        && str_contains($schema, 'ASCII(RIGHT(NEW.reason, 1)) IN (0, 9, 10, 11, 13, 32)'),
);
adjustment_check(
    'canonical schema carries the exact adjustment table and three guards',
    is_string($schema)
        && substr_count($schema, 'CREATE TABLE IF NOT EXISTS time_entry_approval_adjustments') === 1
        && substr_count($schema, 'CREATE TRIGGER trg_time_adjustments_') === 3,
);
adjustment_check(
    'bootstrap loads the adjustment service',
    is_string($bootstrap)
        && str_contains($bootstrap, "require_once __DIR__ . '/time_entry_adjustments.php';"),
);
adjustment_check(
    'API pins owner-admin authority and a strict adjustment acknowledgement',
    is_string($api)
        && str_contains($api, 'TIME_ENTRY_ADJUSTMENT_ROLES')
        && str_contains($api, "'adjustment_ack' => [")
        && str_contains($api, "'adjustment_key' =>")
        && str_contains($api, "'entry_id' =>")
        && str_contains($api, "'version_no' =>")
        && str_contains($api, "'effective_minutes' =>")
        && str_contains($api, "'effective_billable' =>")
        && str_contains($api, "'adjusted_by_user_id' =>")
        && str_contains($api, "'reason' =>")
        && str_contains($api, "'replayed' =>"),
);
adjustment_check(
    'Time page uses tenant-scoped exact-id reads and service-owned latest evidence',
    is_string($timePage)
        && str_contains($timePage, 'WHERE e.tenant_id = ?')
        && str_contains($timePage, "' AND e.id = ? LIMIT 1'")
        && str_contains($timePage, 'time_entry_adjustment_latest_by_entry(')
        && str_contains($timePage, 'Original approval:')
        && str_contains($timePage, 'Effective version'),
);
adjustment_check(
    'live reports compare raw billable facts with latest effective totals',
    is_string($reportsPage)
        && substr_count($reportsPage, 'SUM(CASE WHEN e.billable = 1 THEN e.minutes ELSE 0 END)') === 2
        && str_contains($reportsPage, 'HAVING min_total > 0 OR original_min_total > 0')
        && str_contains($reportsPage, 'HAVING hours > 0 OR original_hours > 0')
        && substr_count($reportsPage, 'SELECT MAX(latest.version_no)') === 2,
);
adjustment_check(
    'restricted CSV carries effective facts and formula-neutralized adjustment reasons',
    is_string($reportsExport)
        && str_contains($reportsExport, 'COALESCE(adjustment.effective_minutes, e.minutes)')
        && str_contains($reportsExport, 'COALESCE(adjustment.effective_billable, e.billable)')
        && str_contains($reportsExport, "time_entry_csv_safe_cell((string)(\$r['adjustment_reason'] ?? ''))"),
);
adjustment_check(
    'Coastmark v2 is inspection-only and has no network transport function',
    is_string($coastmarkExport)
        && str_contains($coastmarkExport, 'receipt/reversal-aware v3 is required before any send')
        && !str_contains($coastmarkExport, 'curl_init('),
);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
