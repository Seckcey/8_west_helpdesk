<?php
/** Clients — every account at a glance. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/client_operational_context.php';
require_once __DIR__ . '/../lib/client_page.php';
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
            (SELECT COUNT(*) FROM tickets t WHERE t.tenant_id = c.tenant_id AND t.client_id = c.id AND t.status != "resolved") AS open_count,
            (SELECT MIN(t.created_at) FROM tickets t WHERE t.tenant_id = c.tenant_id AND t.client_id = c.id AND t.status != "resolved") AS oldest_open
       FROM clients c WHERE c.tenant_id = ? ORDER BY c.name'
);
$stmt->execute([$tenantId]);
$rows = $stmt->fetchAll();
$hasDemo = (bool)array_filter($rows, static fn($c) => str_ends_with((string)$c['domain'], '.example'));
// Last accepted shared name/status from Milepost, kept apart from the local Safeharbor fields.
// A failed lookup remains unknown; it must never label a client unlinked.
$shared = client_operational_directory(db(), $tenantId);
// Milepost's current directory for this signed-in provider, read for this request only and never
// stored. It admits pending clients only from the verified current provider directory; local clients
// remain listed when that read is unavailable. Nothing here is created or inactivated.
$roster = client_operational_roster(db(), $user);
$directory = client_operational_roster_classify($shared, client_operational_bound_customers(db(), $tenantId), $roster);
$provider = client_operational_provider_slug(db(), $tenantId);
$hasLinked = (bool)array_filter($shared ?? [], static fn($entry) => $entry['available']);

page_top($user, 'Clients', 'clients');
?>
<div class="page page-clients">
  <?php if ($clientNotice !== null): ?>
    <div class="banner banner-warn"><?= h($clientNotice) ?></div>
  <?php endif; ?>
  <div class="page-head">
    <div>
      <h1 class="page-title">Clients</h1>
      <p class="page-sub"><?= count($rows) === 1 ? '1 client' : number_format(count($rows)) . ' clients' ?></p>
    </div>
    <div class="page-actions"><a href="/client_new.php" class="btn-primary">New client</a></div>
  </div>
  <?php if ($directory['state'] === 'unavailable'): ?>
    <div class="banner banner-info" role="status">Milepost's client list can't be read right now. Clients below come from Safeharbor's own records.</div>
  <?php elseif ($directory['state'] === 'empty'): ?>
    <div class="banner banner-info" role="status">Milepost currently lists no shared clients. Clients below come from Safeharbor's own records.</div>
  <?php endif; ?>
  <div class="client-directory-filter">
    <label for="client-filter">Find a client</label>
    <input type="search" id="client-filter" data-client-filter placeholder="Name or domain" autocomplete="off">
    <span data-client-results role="status" aria-live="polite"></span>
  </div>
  <div class="card list client-directory" data-client-list aria-label="Clients">
    <div class="client-directory-head" aria-hidden="true"><span>Client</span><span>Open tickets</span><span>Oldest open</span><span>Plan</span><span></span></div>
    <?php if ($rows === [] && $directory['pending'] === []): ?>
      <div class="empty"><p>No clients yet. Add a client to keep their tickets together.</p></div>
    <?php endif; ?>
    <?php foreach ($rows as $c):
        $op = client_operational_directory_entry($shared, (int)$c['id']);
        // Retain each client's existing destination, including inactive/removed history.
        $billingUrl = $op['available'] && is_string($op['billing_url'] ?? null) ? $op['billing_url'] : null; ?>
    <div class="crow-row">
      <a href="/client.php?id=<?= (int)$c['id'] ?>" class="crow" data-client-id="<?= (int)$c['id'] ?>">
        <span class="crow-main">
          <span class="crow-name"><?= h($c['name']) ?></span>
          <span class="crow-sub"><?= h($c['domain']) ?></span>
          <?php if ($c['health'] !== 'good'): ?><span class="crow-health tag tag-caution">Needs attention</span><?php endif; ?>
          <?php if ($op['bound'] === false && $hasLinked): ?><span class="crow-link-state">Not linked</span><?php endif; ?>
          <?php if ($op['bound'] !== false && !$op['available']): ?><span class="crow-shared">Link details unavailable</span><?php endif; ?>
        </span>
        <span class="crow-open"><span class="crow-field">Open tickets </span><strong><?= (int)$c['open_count'] ?></strong></span>
        <span class="crow-oldest"><span class="crow-field">Oldest open </span><?php if ($c['oldest_open'] !== null): ?><time datetime="<?= h(gmdate('c', strtotime($c['oldest_open'] . ' UTC'))) ?>"><?= h(rel_time($c['oldest_open'])) ?></time><?php else: ?>—<?php endif; ?></span>
        <span class="tier <?= $c['sla_tier'] === 'premium' ? 'tier-premium' : '' ?>"><?= client_plan_label((string)$c['sla_tier']) ?></span>
      </a>
      <span class="crow-actions">
        <details class="client-row-menu">
          <summary aria-label="Apps for <?= h($c['name']) ?>">•••</summary>
          <span class="client-row-links">
            <?php if ($op['available'] && $op['status'] === 'active' && is_string($op['device_url'] ?? null)): ?>
              <a class="btn-chip" href="<?= h($op['device_url']) ?>" target="_blank" rel="noopener">Open in Milepost</a>
            <?php endif; ?>
            <?php if ($billingUrl !== null): ?>
              <a class="btn-chip" href="<?= h($billingUrl) ?>" target="_blank" rel="noopener" title="Opens this client in Coastmark">Open in Coastmark</a>
            <?php else: ?>
              <a class="btn-chip" href="https://coastmark.8westit.com/auth/suite" target="_blank" rel="noopener" title="Opens Coastmark; choose the client there">Open in Coastmark</a>
            <?php endif; ?>
          </span>
        </details>
      </span>
    </div>
    <?php endforeach; ?>
    <?php foreach ($directory['pending'] as $customerId => $item):
        $pendingDevices = $provider !== null ? client_operational_device_url($provider, (string)$customerId) : null;
        $pendingBilling = $provider !== null ? client_operational_billing_url($provider, (string)$customerId) : null; ?>
    <div class="crow-row" data-customer-pending="1">
      <span class="crow">
        <span class="crow-main">
          <span class="crow-name"><?= h($item['display_name']) ?></span>
          <span class="crow-sub">Waiting for client details from Milepost</span>
        </span>
        <span class="crow-open"><span class="crow-field">Open tickets </span>—</span>
        <span class="crow-oldest"><span class="crow-field">Oldest open </span>—</span>
        <span class="crow-plan-empty">—</span>
      </span>
      <span class="crow-actions">
        <details class="client-row-menu">
          <summary aria-label="Apps for <?= h($item['display_name']) ?>">•••</summary>
          <span class="client-row-links">
            <?php if ($pendingDevices !== null): ?>
              <a class="btn-chip" href="<?= h($pendingDevices) ?>" target="_blank" rel="noopener">Open in Milepost</a>
            <?php endif; ?>
            <?php if ($pendingBilling !== null): ?>
              <a class="btn-chip" href="<?= h($pendingBilling) ?>" target="_blank" rel="noopener" title="Opens this client in Coastmark">Open in Coastmark</a>
            <?php else: ?>
              <a class="btn-chip" href="https://coastmark.8westit.com/auth/suite" target="_blank" rel="noopener" title="Opens Coastmark; choose the client there">Open in Coastmark</a>
            <?php endif; ?>
          </span>
        </details>
      </span>
    </div>
    <?php endforeach; ?>
    <p class="empty" data-client-empty hidden>No clients match your search.</p>
  </div>
  <?php if ($hasDemo && $canPurgeDemo): ?>
  <div class="client-demo">
    <p class="client-demo-text">The demo clients and their tickets can go once you have added your own clients.</p>
    <form method="post" action="/clients.php" data-confirm="Remove all demo clients and their tickets? No undo.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="purge_demo">
      <button type="submit" class="btn-danger">Remove demo data</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
