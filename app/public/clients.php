<?php
/** Clients — every account at a glance. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$stmt = db()->prepare(
    'SELECT c.*,
            (SELECT COUNT(*) FROM tickets t WHERE t.client_id = c.id AND t.status != "resolved") AS open_count
       FROM clients c WHERE c.tenant_id = ? ORDER BY c.name'
);
$stmt->execute([tenant_id()]);
$rows = $stmt->fetchAll();

page_top($user, 'Clients', 'clients');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Clients</h1>
      <p class="page-sub"><?= count($rows) ?> accounts under your light</p>
    </div>
  </div>
  <div class="card list">
    <?php foreach ($rows as $c): ?>
    <a href="/client.php?id=<?= (int)$c['id'] ?>" class="crow" data-client-id="<?= (int)$c['id'] ?>">
      <span class="health-dot <?= $c['health'] === 'good' ? 'dot-mint' : 'dot-gold pulse' ?>" title="<?= h($c['health']) ?>"></span>
      <span class="crow-main">
        <span class="crow-name"><?= h($c['name']) ?></span>
        <span class="crow-sub"><?= h($c['domain']) ?></span>
      </span>
      <span class="tier <?= $c['sla_tier'] === 'premium' ? 'tier-premium' : '' ?>"><?= h($c['sla_tier']) ?></span>
      <span class="crow-open"><?= (int)$c['open_count'] ?> open</span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
page_bottom(palette_data());
