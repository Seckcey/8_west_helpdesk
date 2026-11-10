<?php
/**
 * The Queue — home. It opens on what needs the signed-in person, not on everything.
 * Views, filters, order and paging are ordinary links: ?view=&client=&priority=&owner=&sort=&page=
 * (lib/ticket_queue.php). Old ?f=<status> links keep their meaning: every ticket, or one status.
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
$total = queue_total($state, $counts);
if ($state['page'] > queue_pages($total)) $state = array_replace($state, ['page' => queue_pages($total), 'after' => 0, 'before' => 0]);
$page = queue_page(db(), tenant_id(), $me, $state);
$rows = westy_workflow_ticket_owners(db(), tenant_id(), $page['rows']);

// Every ticket this tenant has, whatever the view and the filters say: an empty workspace gets first steps.
$counts['all'] = $counts['everything'];
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
  <?php if ($state['view'] === 'all'): ?>
    <p class="queue-note"><?= h(queue_legacy_note($state)) ?>
      <a class="link" href="/">Open Needs attention</a></p>
  <?php endif; ?>
  <?php if ($page['changed']): ?>
    <p class="queue-note queue-note-warn" role="status">This list changed while you were paging, so a ticket may be missing here or shown twice.
      <a class="link" href="<?= h(queue_url($state, ['page' => 1])) ?>">Start again from page 1</a></p>
  <?php endif; ?>
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
  <?= queue_pager($state, $total, $rows) ?>
  <?= queue_elsewhere($state, $counts) ?>
  <?php if ($canDelete && $rows): ?>
    <?= ticket_bulk_bar(queue_view_label($state), count($rows)) ?>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
