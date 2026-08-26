<?php
/** Clients — every account at a glance. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();
$tenantId = (int)$user['tenant_id'];
$canPurgeDemo = in_array((string)$user['role'], ['owner', 'admin'], true);
$clientNotice = isset($_SESSION['client_notice']) ? (string)$_SESSION['client_notice'] : null;
unset($_SESSION['client_notice']);

// Remove the seeded demo clients (%.example domains) and everything attached.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_demo') {
    csrf_check();
    if (!$canPurgeDemo) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Owner or admin role required.';
        exit;
    }
    $pdo = db();
    $timeCount = $pdo->prepare(
        "SELECT COUNT(*)
           FROM time_entries e
           JOIN tickets t ON t.id = e.ticket_id
           JOIN clients c ON c.id = t.client_id
          WHERE c.tenant_id = ? AND c.domain LIKE '%.example'"
    );
    $timeCount->execute([$tenantId]);
    if ((int)$timeCount->fetchColumn() > 0) {
        $_SESSION['client_notice'] = 'Demo clients were kept because they carry immutable technician-time evidence. Use the guarded sandbox seed only for a full demo reset.';
        header('Location: /clients.php');
        exit;
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "DELETE m FROM messages m
               JOIN tickets t ON t.id = m.ticket_id
               JOIN clients c ON c.id = t.client_id
              WHERE c.tenant_id = " . $tenantId . " AND c.domain LIKE '%.example'"
        );
        $pdo->exec(
            "DELETE t FROM tickets t JOIN clients c ON c.id = t.client_id
              WHERE c.tenant_id = " . $tenantId . " AND c.domain LIKE '%.example'"
        );
        $pdo->exec(
            "DELETE k FROM contacts k JOIN clients c ON c.id = k.client_id
              WHERE c.tenant_id = " . $tenantId . " AND c.domain LIKE '%.example'"
        );
        $pdo->exec("DELETE FROM clients WHERE tenant_id = " . $tenantId . " AND domain LIKE '%.example'");
        $pdo->commit();
        $_SESSION['client_notice'] = 'Demo clients without technician-time evidence were removed.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Safeharbor demo purge refused: ' . $e::class);
        $_SESSION['client_notice'] = 'Demo data was not removed; no changes were committed.';
    }
    header('Location: /clients.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT c.*,
            (SELECT COUNT(*) FROM tickets t WHERE t.client_id = c.id AND t.status != "resolved") AS open_count
       FROM clients c WHERE c.tenant_id = ? ORDER BY c.name'
);
$stmt->execute([$tenantId]);
$rows = $stmt->fetchAll();
$hasDemo = (bool)array_filter($rows, static fn($c) => str_ends_with((string)$c['domain'], '.example'));

page_top($user, 'Clients', 'clients');
?>
<div class="page">
  <?php if ($clientNotice !== null): ?>
    <div class="banner banner-warn"><?= h($clientNotice) ?></div>
  <?php endif; ?>
  <div class="page-head">
    <div>
      <h1 class="page-title">Clients</h1>
      <p class="page-sub"><?= count($rows) ?> accounts under your light</p>
    </div>
    <div class="page-actions">
      <a href="/client_new.php" class="btn-primary btn-sm">+ New client</a>
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

  <?php if ($hasDemo && $canPurgeDemo): ?>
  <div class="demo-card">
    <span class="demo-text">
      <strong>Demo data aboard.</strong> The seeded <code>.example</code> clients (Harbor Dental,
      Bluefin, Copper Kettle, Northwind, Driftwood) and their tickets can be removed in one click
      once you've added your real clients.
    </span>
    <form method="post" action="/clients.php" onsubmit="return confirm('Remove all demo clients and their tickets? No undo.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="purge_demo">
      <button type="submit" class="btn-danger">Remove demo data</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
