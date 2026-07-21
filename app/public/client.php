<?php
/** Client — the "answer the phone smart" screen. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ? AND tenant_id = ?');
$stmt->execute([$id, tenant_id()]);
$client = $stmt->fetch();

if (!$client) {
    page_top($user, 'Not found', 'clients');
    echo '<div class="page"><div class="card empty"><p>Unknown client.</p><a class="link" href="/clients.php">← All clients</a></div></div>';
    page_bottom();
    exit;
}

$tq = db()->prepare(
    'SELECT t.*, c.name AS client_name,
            u.full_name AS assignee_name, u.initials AS assignee_initials, u.color AS assignee_color
       FROM tickets t JOIN clients c ON c.id = t.client_id
       LEFT JOIN users u ON u.id = t.assignee_id
      WHERE t.client_id = ? AND t.tenant_id = ?
      ORDER BY (t.status = "resolved") ASC, FIELD(t.priority, "urgent","high","normal","low"), t.sla_due_at ASC'
);
$tq->execute([$id, tenant_id()]);
$tickets = $tq->fetchAll();
$open = array_filter($tickets, static fn($t) => $t['status'] !== 'resolved');

$kq = db()->prepare('SELECT * FROM contacts WHERE client_id = ? ORDER BY name');
$kq->execute([$id]);
$contacts = $kq->fetchAll();

page_top($user, $client['name'], 'clients');
?>
<div class="page">
  <a href="/clients.php" class="backlink">← Clients</a>
  <div class="ticket-head">
    <span class="health-dot big <?= $client['health'] === 'good' ? 'dot-mint' : 'dot-gold pulse' ?>"></span>
    <h1 class="page-title"><?= h($client['name']) ?></h1>
    <span class="tier <?= $client['sla_tier'] === 'premium' ? 'tier-premium' : '' ?>"><?= h($client['sla_tier']) ?> SLA</span>
    <span class="ticket-sub"><?= h($client['domain']) ?></span>
  </div>

  <div class="stats">
    <div class="card stat"><div class="stat-k">Open tickets</div><div class="stat-v <?= count($open) > 2 ? 'stat-warn' : '' ?>"><?= count($open) ?></div></div>
    <div class="card stat"><div class="stat-k">Avg first response</div><div class="stat-v stat-good">22m</div></div>
    <div class="card stat"><div class="stat-k">Devices</div><div class="stat-v stat-dim">Phase 2</div><div class="stat-hint">via Milepost</div></div>
    <div class="card stat"><div class="stat-k">Balance</div><div class="stat-v stat-dim">Phase 2</div><div class="stat-hint">via Coastmark</div></div>
  </div>

  <div class="ticket-grid">
    <div>
      <div class="rail-label">Tickets</div>
      <div class="card list">
        <?php if (!$tickets): ?>
          <div class="empty"><p>No tickets yet for <?= h($client['name']) ?>. Smooth sailing.</p></div>
        <?php endif; ?>
        <?php foreach ($tickets as $t): ?><?= ticket_row($t) ?><?php endforeach; ?>
      </div>
    </div>
    <div class="rail">
      <div class="rail-label">Contacts</div>
      <div class="card rail-list">
        <?php foreach ($contacts as $c): ?>
        <div class="rail-list-item">
          <div class="rail-list-name"><?= h($c['name']) ?></div>
          <div class="rail-list-sub"><?= h($c['email']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="rail-label">Suite</div>
      <div class="card rail-card rail-suite">
        <p><span class="suite-dot"></span>Device list arrives with Milepost (Phase 2)</p>
        <p><span class="suite-dot"></span>Invoices &amp; agreement arrive with Coastmark (Phase 2)</p>
      </div>
    </div>
  </div>
</div>
<?php
page_bottom(palette_data());
