<?php
/**
 * The Queue — home. One calm, dense list; keyboard navigable end to end.
 * Filter via ?f=all|open|in_progress|waiting|resolved
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/westy_workflow.php';
require_once __DIR__ . '/../lib/ticket_delete.php';
enforce_https();
$user = require_login();
$canDelete = ticket_delete_allowed($user);

$FILTERS = ['all' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'waiting' => 'Waiting', 'resolved' => 'Resolved'];
$filter = $_GET['f'] ?? 'all';
if (!isset($FILTERS[$filter])) $filter = 'all';

$where = 't.tenant_id = ?' . ($filter === 'all' ? '' : ' AND t.status = ?');
$stmt = db()->prepare(
    "SELECT t.*,
            (SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'tech') AS first_response_at,
            EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
            c.name AS client_name,
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
$rows = westy_workflow_ticket_owners(db(), tenant_id(), $stmt->fetchAll());

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
      <a href="/ticket_new.php" class="btn-primary btn-sm" style="margin-right:8px">+ New ticket</a>
      <?php $i = 1; foreach ($FILTERS as $key => $label): ?>
      <a href="/?f=<?= $key ?>" class="filter-pill<?= $filter === $key ? ' pill-on' : '' ?>" data-key="<?= $i ?>">
        <?= h($label) ?><span class="pill-count"><?= (int)($counts[$key] ?? ($key === 'all' ? $counts['all'] : 0)) ?></span><kbd class="kbd pill-kbd"><?= $i ?></kbd>
      </a>
      <?php $i++; endforeach; ?>
    </div>
  </div>

  <div class="card list">
    <?= ticket_head_row($canDelete && $rows) ?>
    <?php if (!$rows): ?>
      <div class="empty">
        <img src="/assets/brand/safeharbor-logo-square-20260907.png" alt="" class="empty-mark">
        <p>Nothing here. The harbor is calm.</p>
      </div>
    <?php endif; ?>
    <?php foreach ($rows as $t): ?>
      <?= $canDelete ? ticket_pick_row($t) : ticket_row($t) ?>
    <?php endforeach; ?>
  </div>
  <?php if ($canDelete && $rows): ?>
    <?= ticket_bulk_bar($FILTERS[$filter], count($rows)) ?>
  <?php endif; ?>

  <p class="keyhints">
    <span><kbd class="kbd">j</kbd>/<kbd class="kbd">k</kbd> move</span>
    <span><kbd class="kbd">↵</kbd> open</span>
    <span><kbd class="kbd">s</kbd> status</span>
    <span><kbd class="kbd">p</kbd> priority</span>
    <span><kbd class="kbd">a</kbd> assign to me</span>
    <span><kbd class="kbd">e</kbd> start timer</span>
    <?php if ($canDelete && $rows): ?><span><kbd class="kbd">x</kbd> select</span><span><kbd class="kbd">d</kbd> delete selected</span><?php endif; ?>
    <span class="keyhints-right">⌘K for everything else</span>
  </p>
</div>
<?php
page_bottom(palette_data());
