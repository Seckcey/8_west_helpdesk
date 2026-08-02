<?php
/**
 * Reports — the owner's honest weekly view. Every number is computed from
 * real rows (nothing hardcoded, ever): first response, SLA attainment,
 * aging, time by tech, billable hours by client, CSAT.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$tid = tenant_id();

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

// SLA attainment, 30d (resolved before the lamp turned red)
$sla = db()->prepare(
    "SELECT COUNT(*) AS n, SUM(resolved_at <= sla_due_at) AS met
       FROM tickets
      WHERE tenant_id = ? AND resolved_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
);
$sla->execute([$tid]);
$slaRow = $sla->fetch();
$slaPct = ($slaRow && (int)$slaRow['n'] > 0) ? (int)round(100 * (int)$slaRow['met'] / (int)$slaRow['n']) : null;

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

// Time this week by tech
$tt = db()->prepare(
    "SELECT u.full_name, u.initials, u.color,
            SUM(e.minutes) AS min_total, SUM(e.minutes * e.billable) AS min_billable
       FROM time_entries e
       JOIN users u   ON u.id = e.user_id
       JOIN tickets t ON t.id = e.ticket_id
      WHERE t.tenant_id = ? AND e.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
      GROUP BY u.id ORDER BY min_total DESC"
);
$tt->execute([$tid]);
$timeByTech = $tt->fetchAll();

// Billable hours by client, 30d
$bc = db()->prepare(
    "SELECT c.id, c.name, ROUND(SUM(e.minutes) / 60, 1) AS hours
       FROM time_entries e
       JOIN tickets t ON t.id = e.ticket_id
       JOIN clients c ON c.id = t.client_id
      WHERE t.tenant_id = ? AND e.billable = 1 AND e.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
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
    <div class="filters">
      <a class="btn-chip" href="/reports_export.php?days=30">⭳ Billable CSV (30d)</a>
    </div>
  </div>

  <div class="stats">
    <div class="card stat"><div class="stat-k">Open now</div><div class="stat-v"><?= (int)$c['open_now'] ?></div></div>
    <div class="card stat"><div class="stat-k">New this week</div><div class="stat-v"><?= (int)$c['created_7d'] ?></div></div>
    <div class="card stat"><div class="stat-k">Resolved this week</div><div class="stat-v stat-good"><?= (int)$c['resolved_7d'] ?></div></div>
    <div class="card stat"><div class="stat-k">Avg first response (30d)</div><div class="stat-v <?= $avgFirst !== null && $avgFirst <= 60 ? 'stat-good' : '' ?>"><?= $fmtMin($avgFirst) ?></div><div class="stat-hint"><?= (int)($frRow['n'] ?? 0) ?> tickets</div></div>
    <div class="card stat"><div class="stat-k">SLA attainment (30d)</div><div class="stat-v <?= $slaPct !== null && $slaPct >= 90 ? 'stat-good' : ($slaPct !== null && $slaPct < 70 ? 'stat-warn' : '') ?>"><?= $slaPct === null ? '—' : $slaPct . '%' ?></div><div class="stat-hint"><?= (int)($slaRow['n'] ?? 0) ?> resolved</div></div>
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

      <div class="rail-label">Time this week</div>
      <div class="card rail-list">
        <?php if (!$timeByTech): ?><div class="empty"><p>No time logged this week yet.</p></div><?php endif; ?>
        <?php foreach ($timeByTech as $t): ?>
        <div class="entry-row">
          <?= avatar($t, 24) ?>
          <div class="entry-main"><div class="entry-note"><?= h($t['full_name']) ?></div></div>
          <span class="entry-badge badge-billable"><?= $fmtMin((int)$t['min_billable']) ?> billable</span>
          <span class="entry-min"><?= $fmtMin((int)$t['min_total']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div>
      <div class="rail-label">Billable hours by client (30d)</div>
      <div class="card rail-list">
        <?php if (!$billByClient): ?><div class="empty"><p>No billable time in the last 30 days.</p></div><?php endif; ?>
        <?php foreach ($billByClient as $b): ?>
        <div class="entry-row">
          <div class="entry-main"><div class="entry-note"><a class="link" href="/client.php?id=<?= (int)$b['id'] ?>"><?= h($b['name']) ?></a></div></div>
          <span class="entry-min"><?= h((string)$b['hours']) ?>h</span>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="page-note">The CSV above is invoice-ready — Coastmark handoff replaces it in Phase 2.</p>
    </div>
  </div>
</div>
<?php
page_bottom(palette_data());
