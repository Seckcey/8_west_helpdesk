<?php
/**
 * The Queue — home. One calm, dense list; keyboard navigable end to end.
 * Filter via ?f=all|open|in_progress|waiting|resolved
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$FILTERS = ['all' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'waiting' => 'Waiting', 'resolved' => 'Resolved'];
$filter = $_GET['f'] ?? 'all';
if (!isset($FILTERS[$filter])) $filter = 'all';

$where = 't.tenant_id = ?' . ($filter === 'all' ? '' : ' AND t.status = ?');
$stmt = db()->prepare(
    "SELECT t.*, c.name AS client_name,
            u.full_name AS assignee_name, u.initials AS assignee_initials, u.color AS assignee_color
       FROM tickets t
       JOIN clients c ON c.id = t.client_id
       LEFT JOIN users u ON u.id = t.assignee_id
      WHERE $where
      ORDER BY (t.status = 'resolved') ASC,
               FIELD(t.priority, 'urgent','high','normal','low'),
               t.sla_due_at ASC"
);
$stmt->execute($filter === 'all' ? [tenant_id()] : [tenant_id(), $filter]);
$rows = $stmt->fetchAll();

$counts = ['all' => 0];
$cq = db()->prepare('SELECT status, COUNT(*) n FROM tickets WHERE tenant_id = ? GROUP BY status');
$cq->execute([tenant_id()]);
foreach ($cq->fetchAll() as $r) { $counts[$r['status']] = (int)$r['n']; $counts['all'] += (int)$r['n']; }

page_top($user, 'Queue', 'queue');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Queue</h1>
      <p class="page-sub"><?= (int)($counts['open'] ?? 0) ?> open · <?= (int)(($counts['in_progress'] ?? 0) + ($counts['waiting'] ?? 0)) ?> active · <?= (int)($counts['resolved'] ?? 0) ?> resolved</p>
    </div>
    <div class="filters">
      <?php $i = 1; foreach ($FILTERS as $key => $label): ?>
      <a href="/?f=<?= $key ?>" class="filter-pill<?= $filter === $key ? ' pill-on' : '' ?>" data-key="<?= $i ?>">
        <?= h($label) ?><span class="pill-count"><?= (int)($counts[$key] ?? ($key === 'all' ? $counts['all'] : 0)) ?></span><kbd class="kbd pill-kbd"><?= $i ?></kbd>
      </a>
      <?php $i++; endforeach; ?>
    </div>
  </div>

  <div class="card list">
    <div class="trow trow-head" role="row">
      <span class="trow-pri">Pri</span><span class="trow-num">#</span><span class="trow-main">Ticket</span>
      <span class="trow-status">Status</span><span class="trow-tech">Tech</span><span class="trow-sla">SLA</span>
      <span class="trow-age">Age</span>
    </div>
    <?php if (!$rows): ?>
      <div class="empty">
        <img src="/assets/brand/safeharbor-mark.svg" alt="" class="empty-mark">
        <p>Nothing here. The harbor is calm.</p>
      </div>
    <?php endif; ?>
    <?php foreach ($rows as $t): ?>
      <?= ticket_row($t) ?>
    <?php endforeach; ?>
  </div>

  <p class="keyhints">
    <span><kbd class="kbd">j</kbd>/<kbd class="kbd">k</kbd> move</span>
    <span><kbd class="kbd">↵</kbd> open</span>
    <span><kbd class="kbd">s</kbd> status</span>
    <span><kbd class="kbd">p</kbd> priority</span>
    <span><kbd class="kbd">a</kbd> assign to me</span>
    <span><kbd class="kbd">e</kbd> start timer</span>
    <span class="keyhints-right">⌘K for everything else</span>
  </p>
</div>
<?php
page_bottom(palette_data());
