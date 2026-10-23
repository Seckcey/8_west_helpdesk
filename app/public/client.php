<?php
/** Client — the "answer the phone smart" screen. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/westy_workflow.php';
require_once __DIR__ . '/../lib/client_operational_context.php';
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

// ---- contact add / remove ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_contact') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if ($name !== '') {
            db()->prepare('INSERT INTO contacts (client_id, name, email) VALUES (?,?,?)')
                ->execute([$id, $name, $email]);
        }
    }
    if ($action === 'remove_contact' && isset($_POST['contact_id'])) {
        db()->prepare('DELETE FROM contacts WHERE id = ? AND client_id = ?')
            ->execute([(int)$_POST['contact_id'], $id]);
    }
    header('Location: /client.php?id=' . $id);
    exit;
}

$tq = db()->prepare(
    'SELECT t.*,
            (SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = "tech") AS first_response_at,
            EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
            c.name AS client_name,
            u.full_name AS assignee_name, u.initials AS assignee_initials, u.color AS assignee_color
       FROM tickets t JOIN clients c ON c.id = t.client_id
       LEFT JOIN users u ON u.id = t.assignee_id
      WHERE t.client_id = ? AND t.tenant_id = ?
      ORDER BY (t.status = "resolved") ASC, FIELD(t.priority, "urgent","high","normal","low"), t.sla_due_at ASC'
);
$tq->execute([$id, tenant_id()]);
$tickets = westy_workflow_ticket_owners(db(), tenant_id(), $tq->fetchAll());
$open = array_filter($tickets, static fn($t) => $t['status'] !== 'resolved');

$kq = db()->prepare('SELECT * FROM contacts WHERE client_id = ? ORDER BY name');
$kq->execute([$id]);
$contacts = $kq->fetchAll();

// Real avg first response for THIS client (creation → first tech reply)
$frq = db()->prepare(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, x.first_tech)) AS avg_min
       FROM tickets t
       JOIN (SELECT ticket_id, MIN(created_at) AS first_tech FROM messages WHERE kind = 'tech' GROUP BY ticket_id) x
         ON x.ticket_id = t.id
      WHERE t.client_id = ? AND t.tenant_id = ?"
);
$frq->execute([$id, tenant_id()]);
$avgMin = $frq->fetchColumn();
$avgFirstResponse = $avgMin !== null
    ? ((int)$avgMin < 60 ? (int)$avgMin . 'm' : intdiv((int)$avgMin, 60) . 'h ' . ((int)$avgMin % 60) . 'm')
    : '—';

// The shared name/status is Milepost's last accepted receipt, separate from the local fields above.
$operational = client_operational_context(db(), tenant_id(), $id);
$deviceUrl = $operational['available'] && $operational['status'] === 'active' && is_string($operational['device_url'] ?? null)
    ? $operational['device_url']
    : null;
// A linked client keeps its own Coastmark history link, whatever its shared state.
$billingUrl = $operational['available'] && is_string($operational['billing_url'] ?? null)
    ? $operational['billing_url']
    : null;
// Milepost's current directory, read for this request only. It describes, never changes, this client.
$roster = $operational['available'] ? client_operational_roster(db(), $user) : ['available' => false];
$current = $roster['available'] ? ($roster['customers'][(string)$operational['customer_id']] ?? null) : null;

page_top($user, $client['name'], 'clients');
?>
<div class="page">
  <a href="/clients.php" class="backlink">← Clients</a>
  <div class="ticket-head">
    <span class="health-dot big <?= $client['health'] === 'good' ? 'dot-mint' : 'dot-gold pulse' ?>"></span>
    <h1 class="page-title"><?= h($client['name']) ?></h1>
    <span class="tier <?= $client['sla_tier'] === 'premium' ? 'tier-premium' : '' ?>"><?= h($client['sla_tier']) ?> SLA</span>
    <span class="ticket-sub"><?= h($client['domain']) ?></span>
    <a href="/client_edit.php?id=<?= (int)$id ?>" class="btn-chip" style="margin-left:auto">Edit</a>
  </div>
  <?php if ($operational['available']): ?>
    <p class="client-shared">Shared client: <strong><?= h($operational['display_name']) ?></strong>
      · <span class="crow-shared-<?= $operational['status'] === 'active' ? 'active' : 'inactive' ?>"><?= h($operational['status']) ?></span>
      · last received from Milepost (source version <?= (int)$operational['source_version'] ?>)
      · <a class="link" href="<?= h($operational['edit_url']) ?>" target="_blank" rel="noopener">Shared details ↗</a></p>
    <?php if ($current !== null): ?>
      <p class="client-shared">Milepost directory now: <strong><?= h($current['display_name']) ?></strong>
        · <span class="crow-shared-<?= $current['status'] === 'active' ? 'active' : 'inactive' ?>"><?= h($current['status']) ?></span>
        <?= $current['client_exists'] ? '' : '· removed in Milepost ' ?>(source version <?= (int)$current['source_version'] ?>)</p>
    <?php elseif ($roster['available']): ?>
      <p class="client-shared">Not in Milepost’s current client directory; kept here as retained history.</p>
    <?php else: ?>
      <p class="client-shared">Milepost’s current client directory is unavailable right now.</p>
    <?php endif; ?>
  <?php elseif ($operational['bound'] !== false): ?>
    <p class="client-shared">Shared details are temporarily unavailable. Local details below are unaffected.</p>
  <?php else: ?>
    <p class="client-shared">Local to Safeharbor; not linked to a shared Milepost client.</p>
  <?php endif; ?>

  <div class="stats">
    <div class="card stat"><div class="stat-k">Open tickets</div><div class="stat-v <?= count($open) > 2 ? 'stat-warn' : '' ?>"><?= count($open) ?></div></div>
    <div class="card stat"><div class="stat-k">Avg first response</div><div class="stat-v <?= $avgFirstResponse !== '—' ? 'stat-good' : 'stat-dim' ?>"><?= h($avgFirstResponse) ?></div></div>
    <div class="card stat"><div class="stat-k">Devices</div><div class="stat-v stat-dim" style="font-size:15px">Not shown here</div><a class="link" href="<?= h($deviceUrl ?? 'https://support.8westit.com/') ?>" target="_blank" rel="noopener" style="display:inline-block;margin-top:6px;font-size:12px"><?= $deviceUrl !== null ? 'View this client in Milepost ↗' : 'View in Milepost ↗' ?></a></div>
    <div class="card stat"><div class="stat-k">Balance</div><div class="stat-v stat-dim" style="font-size:15px">Not shown here</div><a class="link" href="<?= h($billingUrl ?? 'https://coastmark.8westit.com/auth/suite') ?>" target="_blank" rel="noopener" style="display:inline-block;margin-top:6px;font-size:12px"><?= $billingUrl !== null ? 'View this client in Coastmark ↗' : 'View in Coastmark ↗' ?></a></div>
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
        <div class="rail-list-item" style="display:flex;align-items:center;gap:8px">
          <div style="flex:1;min-width:0">
            <div class="rail-list-name"><?= h($c['name']) ?></div>
            <div class="rail-list-sub"><?= h($c['email']) ?></div>
          </div>
          <form method="post" action="/client.php?id=<?= (int)$id ?>" onsubmit="return confirm('Remove <?= h($c['name']) ?>?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_contact">
            <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn-link" title="Remove contact">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
        <form method="post" action="/client.php?id=<?= (int)$id ?>" class="rail-list-item" style="display:flex;gap:8px;align-items:center">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_contact">
          <input type="text" name="name" required placeholder="Name" style="flex:1;min-width:0;padding:6px 10px;border-radius:10px;border:1px solid var(--line);background:var(--input-bg);color:var(--text);font:inherit;font-size:12.5px">
          <input type="email" name="email" placeholder="Email" style="flex:1.2;min-width:0;padding:6px 10px;border-radius:10px;border:1px solid var(--line);background:var(--input-bg);color:var(--text);font:inherit;font-size:12.5px">
          <button type="submit" class="btn-chip">Add</button>
        </form>
      </div>
      <div class="rail-label">Suite</div>
      <div class="card rail-card rail-suite">
        <p><span class="suite-dot"></span><a class="link" href="<?= h($deviceUrl ?? 'https://support.8westit.com/') ?>" target="_blank" rel="noopener"><?= $deviceUrl !== null ? 'Open this client’s devices in Milepost ↗' : 'Find devices in Milepost ↗' ?></a></p>
        <p><span class="suite-dot"></span><a class="link" href="<?= h($billingUrl ?? 'https://coastmark.8westit.com/auth/suite') ?>" target="_blank" rel="noopener"><?= $billingUrl !== null ? 'Review this client’s invoices in Coastmark ↗' : 'Review invoices in Coastmark ↗' ?></a></p>
        <p><?= match (true) {
            $deviceUrl !== null && $billingUrl !== null => 'Milepost and Coastmark check your access before showing this client.',
            $billingUrl !== null => 'Coastmark checks your access before showing this client. In Milepost, select the client.',
            $deviceUrl !== null => 'Milepost checks your access before showing devices. In Coastmark, select the client.',
            default => 'Open the app, then select the client.',
        } ?></p>
      </div>
    </div>
  </div>
</div>
<?php
page_bottom(palette_data());
