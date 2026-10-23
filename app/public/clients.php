<?php
/** Clients — every account at a glance. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/client_operational_context.php';
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
// Last accepted shared name/status from Milepost, kept apart from the local Safeharbor fields.
// Null when the lookup failed: every row then reads unavailable, never local.
$shared = client_operational_directory(db(), $tenantId);
// Milepost's current directory for this signed-in provider, read for this request only and never
// stored. It decides which linked clients are the primary businesses; when it is unavailable nothing
// is classified and every client is listed as before. Nothing here is created or inactivated.
$roster = client_operational_roster(db(), $user);
$directory = client_operational_roster_classify($shared, client_operational_bound_customers(db(), $tenantId), $roster);
$provider = client_operational_provider_slug(db(), $tenantId);
$sections = $directory['state'] === 'unavailable'
    ? [['heading' => null, 'rows' => $rows, 'pending' => []]]
    : [
        ['heading' => 'Businesses',
         'rows' => array_values(array_filter($rows, static fn($c) => isset($directory['primary'][(int)$c['id']]))),
         'pending' => $directory['pending']],
        ['heading' => 'Retained and local',
         'rows' => array_values(array_filter($rows, static fn($c) => !isset($directory['primary'][(int)$c['id']]))),
         'pending' => []],
    ];

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
  <?php if ($directory['state'] === 'unavailable'): ?>
    <div class="banner banner-warn" role="status">The Milepost client directory is unavailable right now, so every client is listed from Safeharbor’s own records. Local details, history and Support are unaffected.</div>
  <?php elseif ($directory['state'] === 'empty'): ?>
    <div class="banner banner-info" role="status">Milepost currently lists no shared businesses for this provider. Every client below is kept from Safeharbor’s own records.</div>
  <?php endif; ?>
  <?php foreach ($sections as $section): ?>
  <?php if ($section['heading'] !== null): ?>
    <h2 class="rail-label"><?= h($section['heading']) ?></h2>
  <?php endif; ?>
  <div class="card list"<?= $section['heading'] !== null ? ' aria-label="' . h($section['heading']) . '"' : '' ?>>
    <?php if ($section['rows'] === [] && $section['pending'] === []): ?>
      <div class="empty"><p><?= $section['heading'] === 'Businesses' ? 'No active shared business is linked here yet.' : 'No other clients.' ?></p></div>
    <?php endif; ?>
    <?php foreach ($section['rows'] as $c):
        $op = client_operational_directory_entry($shared, (int)$c['id']);
        $current = $directory['primary'][(int)$c['id']] ?? null;
        // Any linked client keeps its own Coastmark history link, whatever its shared state.
        $billingUrl = $op['available'] && is_string($op['billing_url'] ?? null) ? $op['billing_url'] : null; ?>
    <div class="crow-row">
      <a href="/client.php?id=<?= (int)$c['id'] ?>" class="crow" data-client-id="<?= (int)$c['id'] ?>">
        <span class="health-dot <?= $c['health'] === 'good' ? 'dot-mint' : 'dot-gold pulse' ?>" title="<?= h($c['health']) ?>"></span>
        <span class="crow-main">
          <span class="crow-name"><?= h($c['name']) ?></span>
          <span class="crow-sub"><?= h($c['domain']) ?></span>
          <?php if ($current !== null): ?>
            <span class="crow-shared">Milepost: <?= h($current['display_name']) ?> · <span class="crow-shared-active">active</span></span>
            <?php if ($op['display_name'] !== $current['display_name'] || $op['status'] !== 'active' || $op['source_version'] !== $current['source_version']): ?>
              <span class="crow-shared">Safeharbor last received: <?= h($op['display_name']) ?> · <?= h($op['status']) ?> (version <?= (int)$op['source_version'] ?>)</span>
            <?php endif; ?>
          <?php elseif ($op['available']): ?>
            <span class="crow-shared">Shared: <?= h($op['display_name']) ?> · <span class="crow-shared-<?= $op['status'] === 'active' ? 'active' : 'inactive' ?>"><?= h($op['status']) ?></span></span>
          <?php elseif ($op['bound'] !== false): ?>
            <span class="crow-shared">Shared details unavailable</span>
          <?php else: ?>
            <span class="crow-shared">Local to Safeharbor</span>
          <?php endif; ?>
        </span>
        <span class="tier <?= $c['sla_tier'] === 'premium' ? 'tier-premium' : '' ?>"><?= h($c['sla_tier']) ?></span>
        <span class="crow-open"><?= (int)$c['open_count'] ?> open</span>
      </a>
      <span class="crow-actions" aria-label="<?= h($c['name']) ?> apps">
        <a class="btn-chip" href="/client.php?id=<?= (int)$c['id'] ?>">Support</a>
        <?php if ($op['available'] && $op['status'] === 'active' && is_string($op['device_url'] ?? null)): ?>
          <a class="btn-chip" href="<?= h($op['device_url']) ?>" target="_blank" rel="noopener">Devices ↗</a>
        <?php endif; ?>
        <?php if ($billingUrl !== null): ?>
          <a class="btn-chip" href="<?= h($billingUrl) ?>" target="_blank" rel="noopener" title="Opens this client in Coastmark">Billing ↗</a>
        <?php else: ?>
          <a class="btn-chip" href="https://coastmark.8westit.com/auth/suite" target="_blank" rel="noopener" title="Opens Coastmark; choose the client there">Billing ↗</a>
        <?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
    <?php foreach ($section['pending'] as $customerId => $item):
        $pendingDevices = $provider !== null ? client_operational_device_url($provider, (string)$customerId) : null;
        $pendingBilling = $provider !== null ? client_operational_billing_url($provider, (string)$customerId) : null; ?>
    <div class="crow-row" data-customer-pending="1">
      <span class="crow">
        <span class="health-dot dot-mint" title="active in Milepost"></span>
        <span class="crow-main">
          <span class="crow-name"><?= h($item['display_name']) ?></span>
          <span class="crow-shared">Milepost: <span class="crow-shared-active">active</span> · not yet received by Safeharbor</span>
        </span>
      </span>
      <span class="crow-actions" aria-label="<?= h($item['display_name']) ?> apps">
        <?php if ($pendingDevices !== null): ?>
          <a class="btn-chip" href="<?= h($pendingDevices) ?>" target="_blank" rel="noopener">Devices ↗</a>
        <?php endif; ?>
        <?php if ($pendingBilling !== null): ?>
          <a class="btn-chip" href="<?= h($pendingBilling) ?>" target="_blank" rel="noopener" title="Opens this client in Coastmark">Billing ↗</a>
        <?php else: ?>
          <a class="btn-chip" href="https://coastmark.8westit.com/auth/suite" target="_blank" rel="noopener" title="Opens Coastmark; choose the client there">Billing ↗</a>
        <?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

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
