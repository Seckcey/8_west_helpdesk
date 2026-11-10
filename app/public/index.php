<?php
/**
 * The Queue — home. It opens on what needs the signed-in person, not on everything.
 * Views, filters, order and paging are ordinary links: ?view=&client=&priority=&owner=&sort=&page=
 * (lib/ticket_queue.php). Old ?f=<status> links still land on the nearest view.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/westy_workflow.php';
require_once __DIR__ . '/../lib/ticket_delete.php';
require_once __DIR__ . '/../lib/first_steps.php';
require_once __DIR__ . '/../lib/ticket_queue.php';
enforce_https();
$user = require_login();
$canDelete = ticket_delete_allowed($user);
$me = (int)$user['id'];

$state = queue_request($_GET);
$counts = queue_counts(db(), tenant_id(), $me, $state);
// A page number past the end (an old link, or tickets resolved since) lands on the last page.
$total = $counts[$state['view']];
$state['page'] = min($state['page'], queue_pages($total));
$rows = westy_workflow_ticket_owners(db(), tenant_id(), queue_page(db(), tenant_id(), $me, $state));

// Every ticket this tenant has, whatever the filters say: an empty workspace gets first steps.
$counts['all'] = $counts['open'] + $counts['resolved'];
if ($counts['all'] === 0 && queue_filtered($state)) {
    $aq = db()->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id = ?');
    $aq->execute([tenant_id()]);
    $counts['all'] = (int)$aq->fetchColumn();
}
// A brand-new workspace (no tickets at all) shows owners and administrators where to start.
$firstSteps = $counts['all'] === 0 && in_array($user['role'], ['owner', 'admin'], true)
    ? staff_first_steps_card($user, 0, tenant_client_count(db(), tenant_id()), staff_invite_allowed($user, $_SESSION ?? []))
    : '';

$cq = db()->prepare('SELECT id, name FROM clients WHERE tenant_id = ? ORDER BY name, id');
$cq->execute([tenant_id()]);
$tq = db()->prepare('SELECT id, full_name FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY full_name, id');
$tq->execute([tenant_id()]);

page_top($user, 'Queue', 'queue');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Queue</h1>
      <p class="page-sub"><?= h(queue_summary($counts)) ?></p>
    </div>
    <div class="page-actions">
      <a href="/ticket_new.php" class="btn-primary">New ticket</a>
    </div>
  </div>
  <div class="toolbar queue-toolbar">
    <?= queue_tabs($state, $counts) ?>
    <?= queue_filters($state, $cq->fetchAll(), $tq->fetchAll(), $me) ?>
    <?php if ($canDelete && $rows): ?><?= ticket_bulk_toggle() ?><?php endif; ?>
  </div>

  <?= $firstSteps ?>
  <div class="card list">
    <?= ticket_head_row($canDelete && $rows) ?>
    <?php if (!$rows): [$emptyText, $emptyLabel, $emptyHref] = queue_empty($state); ?>
      <div class="empty">
        <img src="/assets/brand/safeharbor-logo-square-20260907.png" alt="" class="empty-mark">
        <p><?= h($emptyText) ?></p>
        <a class="btn-chip" href="<?= h($emptyHref) ?>"><?= h($emptyLabel) ?></a>
      </div>
    <?php endif; ?>
    <?php foreach ($rows as $t): ?>
      <?= $canDelete ? ticket_pick_row($t) : ticket_row($t) ?>
    <?php endforeach; ?>
  </div>
  <?= queue_pager($state, $total) ?>
  <?php if ($canDelete && $rows): ?>
    <?= ticket_bulk_bar(QUEUE_VIEWS[$state['view']], count($rows)) ?>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
