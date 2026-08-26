<?php
/**
 * Reports — the owner's honest weekly view. Every number is computed from
 * real rows (nothing hardcoded, ever): first response, response-target attainment,
 * aging, time by tech, billable hours by client, CSAT.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();
$canExportTime = in_array((string)$user['role'], ['owner', 'admin'], true);

$tid = (int)$user['tenant_id'];

// This week / right now
$counts = db()->prepare(
    "SELECT
       (SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND status != 'resolved') AS open_now,
       (SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND created_at  >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS created_7d,
       (SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND resolved_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS resolved_7d"
);
$counts->execute([$tid, $tid, $tid]);
$c = $counts->fetch();

// Avg first response, 30d (creation → first tech reply)
$fr = db()->prepare(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, x.first_tech)) AS avg_min, COUNT(*) AS n
       FROM tickets t
       JOIN (SELECT ticket_id, MIN(created_at) AS first_tech FROM messages WHERE kind = 'tech' GROUP BY ticket_id) x
         ON x.ticket_id = t.id
      WHERE t.tenant_id = ? AND t.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
);
$fr->execute([$tid]);
$frRow = $fr->fetch();
$avgFirst = $frRow && $frRow['avg_min'] !== null ? (int)round((float)$frRow['avg_min']) : null;

// First-response target attainment, 30d. Future open targets are undecided;
// resolution time is deliberately irrelevant to a response deadline.
$responseAttainment = service_goal_response_attainment(db(), $tid, 30);
$slaPct = $responseAttainment['pct'];

// Aging buckets (open tickets)
$aging = db()->prepare(
    "SELECT
       SUM(created_at >  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) AS b1,
       SUM(created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) AND created_at >  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)) AS b2,
       SUM(created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY) AND created_at >  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS b3,
       SUM(created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS b4
       FROM tickets WHERE tenant_id = ? AND status != 'resolved'"
);
$aging->execute([$tid]);
$ag = $aging->fetch();

// Approved time this week by tech. Tenant and client facts come from the
// entry snapshot, never from a ticket that may later be merged or reassigned.
$tt = db()->prepare(
    "SELECT u.full_name, u.initials, u.color,
            SUM(e.minutes) AS min_total
       FROM time_entries e
       JOIN users u   ON u.id = e.user_id AND u.tenant_id = e.tenant_id
      WHERE e.tenant_id = ? AND e.approval_status = 'approved' AND e.billable = 1
        AND e.worked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
      GROUP BY u.id ORDER BY min_total DESC"
);
$tt->execute([$tid]);
$timeByTech = $tt->fetchAll();

// Approved billable hours by captured client, 30d.
$bc = db()->prepare(
    "SELECT c.id, c.name, ROUND(SUM(e.minutes) / 60, 1) AS hours
       FROM time_entries e
       JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
      WHERE e.tenant_id = ? AND e.approval_status = 'approved' AND e.billable = 1
        AND e.worked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
      GROUP BY c.id ORDER BY SUM(e.minutes) DESC"
);
$bc->execute([$tid]);
$billByClient = $bc->fetchAll();

// CSAT, 30d
$cs = ['n' => 0, 'sent' => 0, 'avg' => null];
try {
    $csq = db()->prepare(
        "SELECT COUNT(*) AS sent, COUNT(score) AS n, AVG(score) AS avg_score
           FROM csat s JOIN tickets t ON t.id = s.ticket_id
          WHERE t.tenant_id = ? AND s.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
    );
    $csq->execute([$tid]);
    $r = $csq->fetch();
    $cs = ['n' => (int)($r['n'] ?? 0), 'sent' => (int)($r['sent'] ?? 0),
           'avg' => $r && $r['avg_score'] !== null ? round((float)$r['avg_score'], 2) : null];
} catch (Throwable $e) { /* pre-migration */ }

$fmtMin = static function (?int $m): string {
    if ($m === null) return '—';
    if ($m < 60) return $m . 'm';
    return intdiv($m, 60) . 'h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . 'm';
};

page_top($user, 'Reports', 'reports');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Reports</h1>
      <p class="page-sub">Real numbers, computed live — nothing here is decorative</p>
    </div>
    <?php if ($canExportTime): ?>
      <div class="filters">
        <a class="btn-chip" href="/reports_export.php?days=30">⭳ Approved billable CSV (30d)</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="stats">
    <div class="card stat"><div class="stat-k">Open now</div><div class="stat-v"><?= (int)$c['open_now'] ?></div></div>
    <div class="card stat"><div class="stat-k">New this week</div><div class="stat-v"><?= (int)$c['created_7d'] ?></div></div>
    <div class="card stat"><div class="stat-k">Resolved this week</div><div class="stat-v stat-good"><?= (int)$c['resolved_7d'] ?></div></div>
    <div class="card stat"><div class="stat-k">Avg first response (30d)</div><div class="stat-v <?= $avgFirst !== null && $avgFirst <= 60 ? 'stat-good' : '' ?>"><?= $fmtMin($avgFirst) ?></div><div class="stat-hint"><?= (int)($frRow['n'] ?? 0) ?> tickets</div></div>
    <div class="card stat"><div class="stat-k">Response target attainment (30d)</div><div class="stat-v <?= $slaPct !== null && $slaPct >= 90 ? 'stat-good' : ($slaPct !== null && $slaPct < 70 ? 'stat-warn' : '') ?>"><?= $slaPct === null ? '—' : $slaPct . '%' ?></div><div class="stat-hint"><?= $responseAttainment['n'] ?> decided tickets</div></div>
    <div class="card stat"><div class="stat-k">CSAT (30d)</div><div class="stat-v <?= $cs['avg'] !== null && $cs['avg'] >= 2.5 ? 'stat-good' : '' ?>"><?= $cs['avg'] === null ? '—' : $cs['avg'] . ' / 3' ?></div><div class="stat-hint"><?= $cs['n'] ?> of <?= $cs['sent'] ?> answered</div></div>
  </div>

  <div class="report-grid">
    <div>
      <div class="rail-label">Open ticket aging</div>
      <div class="card rail-list">
        <?php foreach ([['&lt; 1 day', (int)$ag['b1'], 'mint'], ['1–3 days', (int)$ag['b2'], 'blue'], ['3–7 days', (int)$ag['b3'], 'gold'], ['&gt; 7 days', (int)$ag['b4'], 'rose']] as [$label, $n, $tone]): ?>
        <div class="entry-row">
          <span class="chip-dot dot-<?= $tone ?>"></span>
          <div class="entry-main"><div class="entry-note"><?= $label ?></div></div>
          <span class="entry-min"><?= $n ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="rail-label">Approved billable time this week</div>
      <div class="card rail-list">
        <?php if (!$timeByTech): ?><div class="empty"><p>No billable time approved this week yet.</p></div><?php endif; ?>
        <?php foreach ($timeByTech as $t): ?>
        <div class="entry-row">
          <?= avatar($t, 24) ?>
          <div class="entry-main"><div class="entry-note"><?= h($t['full_name']) ?></div></div>
          <span class="entry-badge badge-billable">approved billable</span>
          <span class="entry-min"><?= $fmtMin((int)$t['min_total']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div>
      <div class="rail-label">Approved billable hours by client (30d)</div>
      <div class="card rail-list">
        <?php if (!$billByClient): ?><div class="empty"><p>No approved billable time in the last 30 days.</p></div><?php endif; ?>
        <?php foreach ($billByClient as $b): ?>
        <div class="entry-row">
          <div class="entry-main"><div class="entry-note"><a class="link" href="/client.php?id=<?= (int)$b['id'] ?>"><?= h($b['name']) ?></a></div></div>
          <span class="entry-min"><?= h((string)$b['hours']) ?>h</span>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="page-note">Approved time is operational evidence only. Export does not post or invoice anything.</p>
    </div>
  </div>
</div>
<?php
page_bottom(palette_data());
