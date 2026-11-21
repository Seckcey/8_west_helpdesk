<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/customer_summary.php';
enforce_https();
$user = require_login();
if (!customer_summary_staff_role($user)) {
    http_response_code(403);
    exit;
}
$waiting = [];
$unavailable = false;
try {
    $waiting = customer_summary_waiting(db(), (int)$user['tenant_id']);
} catch (Throwable $error) {
    error_log('Customer summary list unavailable type=' . $error::class);
    $unavailable = true;
}
page_top($user, 'Summaries awaiting approval', 'queue');
?>
<main class="page page-narrow">
  <a class="backlink" href="/"><?= ui_icon('back') ?>Queue</a>
  <h1 class="page-title">Summaries awaiting approval</h1>
  <?php if ($unavailable): ?>
    <p class="form-error" role="alert">Customer summaries are temporarily unavailable.</p>
  <?php elseif (!$waiting): ?>
    <p>No tickets are waiting for summary approval.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($waiting as $item): ?>
        <li><a class="link" href="/ticket.php?id=<?= h((string)$item['id']) ?>#customer-summary">#<?= h((string)$item['id']) ?> <?= h($item['subject']) ?></a> · <?= h($item['client_name']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>
<?php page_bottom(palette_data()); ?>
