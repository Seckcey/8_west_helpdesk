<?php
/**
 * Reports: how the desk did in one period, against the period before. Every number is counted
 * from real rows of the signed-in tenant: new and resolved tickets, the median wait for a first
 * reply, first replies on time, survey answers, the team by person, the tickets open longest, and
 * approved billable time by client. The pieces are drawn by lib/reports_page.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/ticket_queue.php';
require_once __DIR__ . '/../lib/reports_page.php';
enforce_https();
$user = require_login();
$canExportTime = in_array((string)$user['role'], ['owner', 'admin'], true);

$tid = (int)$user['tenant_id'];

// One period for the whole page, and the same number of days before it to compare with.
$days = report_period($_GET);
$now = time();
$since = gmdate('Y-m-d H:i:s', $now - $days * 86400);
$before = gmdate('Y-m-d H:i:s', $now - 2 * $days * 86400);

$counts = db()->prepare(
    "SELECT
       (SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND created_at  >= ?) AS new_now,
       (SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND created_at  >= ? AND created_at  < ?) AS new_before,
       (SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND resolved_at >= ?) AS resolved_now,
       (SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND resolved_at >= ? AND resolved_at < ?) AS resolved_before"
);
$counts->execute([$tid, $since, $tid, $before, $since, $tid, $since, $tid, $before, $since]);
$c = $counts->fetch();

// Every ticket opened in the two periods, with its first reply: the median wait and, by current
// owner, the wait and how many first replies were on time (report_outcomes()).
$opened = db()->prepare(
    "SELECT t.id, t.assignee_id, t.status, t.created_at, t.resolved_at, t.sla_due_at, t.merged_into_id,
            EXISTS (SELECT 1 FROM tickets merged_source
                     WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
            (SELECT MIN(m.created_at) FROM messages m
              WHERE m.ticket_id = t.id AND m.kind = 'tech' AND m.created_at >= t.created_at) AS first_response_at
       FROM tickets t
      WHERE t.tenant_id = ? AND t.created_at >= ?"
);
$opened->execute([$tid, $before]);
$thisPeriod = [];
$periodBefore = [];
foreach ($opened->fetchAll() as $row) {
    if ((string)$row['created_at'] >= $since) $thisPeriod[] = $row; else $periodBefore[] = $row;
}
$outcomes = report_outcomes($thisPeriod, $now);
$outcomesBefore = report_outcomes($periodBefore, $now);

// First replies on time. A ticket whose due time is still ahead is undecided; how long a ticket
// took to resolve has nothing to do with a reply deadline. The period before is judged as it
// stood when it ended.
$onTime = service_goal_response_attainment(db(), $tid, $days, $now);
$onTimeBefore = service_goal_response_attainment(db(), $tid, $days, $now - $days * 86400);

// Survey answers in the period: sent, answered, and answered Great (3 of 1 to 3).
$surveys = ['sent' => 0, 'rated' => 0, 'great' => 0];
try {
    $csq = db()->prepare(
        "SELECT COUNT(*) AS sent, COUNT(s.score) AS rated, COALESCE(SUM(CASE WHEN s.score = 3 THEN 1 ELSE 0 END), 0) AS great
           FROM csat s JOIN tickets t ON t.id = s.ticket_id
          WHERE t.tenant_id = ? AND s.created_at >= ?"
    );
    $csq->execute([$tid, $since]);
    $r = $csq->fetch();
    $surveys = ['sent' => (int)($r['sent'] ?? 0), 'rated' => (int)($r['rated'] ?? 0), 'great' => (int)($r['great'] ?? 0)];
} catch (Throwable $e) { /* pre-migration */ }

// ---- the team ----
$members = db()->prepare('SELECT id, full_name, initials, color, is_active, suite_subject FROM users WHERE tenant_id = ? ORDER BY full_name, id');
$members->execute([$tid]);
$team = $members->fetchAll();

$openBy = db()->prepare("SELECT assignee_id, COUNT(*) AS n FROM tickets WHERE tenant_id = ? AND status <> 'resolved' AND assignee_id IS NOT NULL GROUP BY assignee_id");
$openBy->execute([$tid]);
$openByOwner = array_column($openBy->fetchAll(), 'n', 'assignee_id');
// Tickets no person owns: the assistant's (the queue shows them as Westy's) and the rest, which is
// exactly the queue's Unassigned list.
$westyRule = queue_facts(db())['{westy}'];
$unowned = db()->prepare(
    "SELECT COALESCE(SUM(CASE WHEN $westyRule THEN 1 ELSE 0 END), 0) AS westy,
            COALESCE(SUM(CASE WHEN $westyRule THEN 0 ELSE 1 END), 0) AS unassigned
       FROM tickets t WHERE t.tenant_id = ? AND t.status <> 'resolved' AND t.assignee_id IS NULL"
);
$unowned->execute([$tid]);
$unownedOpen = $unowned->fetch();

$resolvedBy = db()->prepare('SELECT assignee_id, COUNT(*) AS n FROM tickets WHERE tenant_id = ? AND resolved_at >= ? GROUP BY assignee_id');
$resolvedBy->execute([$tid, $since]);
$resolvedByOwner = [];
foreach ($resolvedBy->fetchAll() as $row) $resolvedByOwner[(string)($row['assignee_id'] ?? '')] = (int)$row['n'];

// Approved time in the period by the person who logged it. Tenant and client facts come from the
// entry snapshot, never from a ticket that may later be merged or reassigned.
$tt = db()->prepare(
    "SELECT u.id, u.full_name, u.initials, u.color,
            SUM(CASE WHEN e.billable = 1 THEN e.minutes ELSE 0 END) AS original_min_total,
            SUM(CASE
                  WHEN COALESCE(adjustment.effective_billable, e.billable) = 1
                  THEN COALESCE(adjustment.effective_minutes, e.minutes)
                  ELSE 0
                END) AS min_total,
            SUM(CASE WHEN adjustment.id IS NOT NULL AND e.billable = 1 THEN 1 ELSE 0 END) AS adjusted_count
       FROM time_entries e
       JOIN users u   ON u.id = e.user_id AND u.tenant_id = e.tenant_id
       LEFT JOIN time_entry_approval_adjustments adjustment
         ON adjustment.tenant_id = e.tenant_id
        AND adjustment.time_entry_id = e.id
        AND adjustment.version_no = (
            SELECT MAX(latest.version_no)
              FROM time_entry_approval_adjustments latest
             WHERE latest.tenant_id = e.tenant_id AND latest.time_entry_id = e.id
        )
      WHERE e.tenant_id = ? AND e.approval_status = 'approved'
        AND e.worked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
      GROUP BY u.id
      HAVING min_total > 0 OR original_min_total > 0
      ORDER BY min_total DESC"
);
$tt->execute([$tid, $days]);
$timeByTech = [];
foreach ($tt->fetchAll() as $row) $timeByTech[(int)$row['id']] = $row;

$teamRows = [];
foreach ($team as $member) {
    $id = (int)$member['id'];
    $own = $outcomes['owners'][(string)$id] ?? null;
    $time = $timeByTech[$id] ?? null;
    $open = (int)($openByOwner[$id] ?? 0);
    $resolved = (int)($resolvedByOwner[(string)$id] ?? 0);
    // Someone who has left appears only while the page still has a number of theirs to show.
    if ((int)$member['is_active'] !== 1 && $open === 0 && $resolved === 0 && $own === null && $time === null) continue;
    $teamRows[] = [
        'name' => (string)$member['full_name'], 'avatar' => avatar($member, 24),
        'open' => $open, 'open_url' => '/?view=open&owner=' . $id, 'resolved' => $resolved,
        'median' => $own['median'] ?? null, 'pct' => $own['pct'] ?? null, 'decided' => (int)($own['decided'] ?? 0), 'met' => (int)($own['met'] ?? 0),
        'minutes' => (int)($time['min_total'] ?? 0),
        'before_corrections' => $time !== null && (int)$time['adjusted_count'] > 0 ? (int)$time['original_min_total'] : null,
    ];
}
if ((int)$unownedOpen['westy'] > 0) {
    $teamRows[] = [
        'name' => 'Westy', 'avatar' => ticket_owner_avatar(['westy_owned' => true, 'status' => 'in_progress', 'assignee_id' => null], 24),
        'open' => (int)$unownedOpen['westy'], 'open_url' => '', 'resolved' => 0,
        'median' => null, 'pct' => null, 'decided' => 0, 'met' => 0, 'minutes' => null, 'before_corrections' => null,
    ];
}
$nobody = $outcomes['owners'][''] ?? null;
$teamRows[] = [
    'name' => 'Unassigned', 'avatar' => avatar(null, 24),
    'open' => (int)$unownedOpen['unassigned'], 'open_url' => '/?view=unassigned', 'resolved' => (int)($resolvedByOwner[''] ?? 0),
    'median' => $nobody['median'] ?? null, 'pct' => $nobody['pct'] ?? null, 'decided' => (int)($nobody['decided'] ?? 0), 'met' => (int)($nobody['met'] ?? 0),
    'minutes' => null, 'before_corrections' => null,
];

// The open tickets that have been open longest.
$longest = db()->prepare(
    "SELECT t.id, t.subject, t.created_at, c.name AS client_name, u.full_name AS assignee_name
       FROM tickets t
       JOIN clients c ON c.id = t.client_id
       LEFT JOIN users u ON u.id = t.assignee_id
      WHERE t.tenant_id = ? AND t.status <> 'resolved'
      ORDER BY t.created_at ASC, t.id ASC
      LIMIT " . REPORT_OPEN_LONGEST
);
$longest->execute([$tid]);
$openLongest = $longest->fetchAll();

// Approved billable time in the period by captured client. Exact minutes, so small approved
// entries remain visible.
$bc = db()->prepare(
    "SELECT c.id, c.name,
            SUM(CASE WHEN e.billable = 1 THEN e.minutes ELSE 0 END) AS original_min_total,
            SUM(CASE
                  WHEN COALESCE(adjustment.effective_billable, e.billable) = 1
                  THEN COALESCE(adjustment.effective_minutes, e.minutes)
                  ELSE 0
                END) AS min_total,
            SUM(CASE WHEN adjustment.id IS NOT NULL AND e.billable = 1 THEN 1 ELSE 0 END) AS adjusted_count
       FROM time_entries e
       JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
       LEFT JOIN time_entry_approval_adjustments adjustment
         ON adjustment.tenant_id = e.tenant_id
        AND adjustment.time_entry_id = e.id
        AND adjustment.version_no = (
            SELECT MAX(latest.version_no)
              FROM time_entry_approval_adjustments latest
             WHERE latest.tenant_id = e.tenant_id AND latest.time_entry_id = e.id
        )
      WHERE e.tenant_id = ? AND e.approval_status = 'approved'
        AND e.worked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
      GROUP BY c.id
      HAVING min_total > 0 OR original_min_total > 0
      ORDER BY min_total DESC"
);
$bc->execute([$tid, $days]);
$billByClient = $bc->fetchAll();

$suitePreferences = is_array($_SESSION['suite_preferences'] ?? null) ? $_SESSION['suite_preferences'] : suite_preferences_defaults();
$timeZone = (string)($suitePreferences['time_zone'] ?? 'UTC');

page_top($user, 'Reports', 'reports');
?>
<div class="page page-reports">
  <div class="page-head">
    <div>
      <h1 class="page-title">Reports</h1>
      <p class="page-sub">How the desk did in <?= report_period_words($days) ?>.</p>
    </div>
    <div class="page-actions">
      <?= report_period_picker($days) ?>
      <?php if ($canExportTime): ?>
        <a class="btn-secondary" href="/reports_export.php?days=<?= $days ?>" title="Approved billable time in <?= report_period_words($days) ?>, as a CSV file">Export time</a>
      <?php endif; ?>
    </div>
  </div>

  <?= report_tiles([
      'new' => (int)$c['new_now'], 'new_before' => (int)$c['new_before'],
      'resolved' => (int)$c['resolved_now'], 'resolved_before' => (int)$c['resolved_before'],
      'median' => $outcomes['all']['median'], 'replied' => $outcomes['all']['replied'], 'median_before' => $outcomesBefore['all']['median'],
      'on_time' => $onTime, 'on_time_before' => $onTimeBefore,
      'surveys' => $surveys['sent'], 'rated' => $surveys['rated'], 'great' => $surveys['great'],
  ], $days) ?>

  <h2 class="rail-label">By technician</h2>
  <?= report_team_table($teamRows, $days) ?>

  <div class="report-grid">
    <div>
      <h2 class="rail-label">Open longest</h2>
      <?= report_open_longest($openLongest, $now, $timeZone) ?>
    </div>
    <div>
      <h2 class="rail-label">Time by client</h2>
      <?= report_time_by_client($billByClient, $days) ?>
    </div>
  </div>
</div>
<?php
page_bottom(palette_data());
