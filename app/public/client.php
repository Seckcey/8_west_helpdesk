<?php
/** Client: the customer's whole picture, for answering the phone. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/westy_workflow.php';
require_once __DIR__ . '/../lib/client_operational_context.php';
require_once __DIR__ . '/../lib/contacts.php';
require_once __DIR__ . '/../lib/client_page.php';
enforce_https();
$user = require_login();

// A48: after staff sign-in, the canonical ?provider=<slug>&customer_id=<UUID> Support target resolves
// to this tenant's one bound client. Any provider or customer_id parameter takes this path only, so a
// mixed or malformed selector never falls back to ?id=. Numeric ?id= stays the local route.
$supportTarget = isset($_GET['provider']) || isset($_GET['customer_id']);
$id = $supportTarget
    ? (client_operational_support_client(db(), tenant_id(), (string)($_SERVER['QUERY_STRING'] ?? '')) ?? 0)
    : (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ? AND tenant_id = ?');
$stmt->execute([$id, tenant_id()]);
$client = $stmt->fetch();

if (!$client) {
    if ($supportTarget) http_response_code(404);
    page_top($user, 'Not found', 'clients');
    echo '<div class="page"><div class="card empty"><p>Unknown client.</p><a class="link" href="/clients.php">← All clients</a></div></div>';
    page_bottom(palette_data());
    exit;
}

// ---- contact add / remove ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_contact') {
        // The same insert the New ticket form uses for "Add a new contact" (lib/contacts.php).
        contact_add(db(), (int)$id, (string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''));
    }
    if ($action === 'remove_contact' && isset($_POST['contact_id'])) {
        db()->prepare('DELETE FROM contacts WHERE id = ? AND client_id = ?')
            ->execute([(int)$_POST['contact_id'], $id]);
    }
    header('Location: /client.php?id=' . $id);
    exit;
}

// ---- tickets: Open (all of them, most pressing first) or Resolved (newest first, a page at a time) ----
$list = client_list_state($_GET);
$cq = db()->prepare(
    "SELECT COALESCE(SUM(CASE WHEN status <> 'resolved' THEN 1 ELSE 0 END), 0) AS open_count,
            COALESCE(SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END), 0) AS resolved_count
       FROM tickets WHERE client_id = ? AND tenant_id = ?"
);
$cq->execute([$id, tenant_id()]);
$counts = $cq->fetch();
$openCount = (int)$counts['open_count'];
$resolvedCount = (int)$counts['resolved_count'];
$list['page'] = client_resolved_page($list['page'], $resolvedCount);

// The row's facts are the queue's (lib/ticket_queue.php): the first reply, who wrote last and when,
// who asked. Every ticket here is this tenant's and this client's.
$tq = db()->prepare(
    "SELECT t.*,
            (SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'tech') AS first_response_at,
            EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
            (SELECT m.kind FROM messages m WHERE m.ticket_id = t.id AND m.kind IN ('client','tech') ORDER BY m.id DESC LIMIT 1) AS last_kind,
            (SELECT MAX(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind <> 'system') AS last_message_at,
            (SELECT m.author_name FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'client' ORDER BY m.id ASC LIMIT 1) AS opener_name,
            c.name AS client_name, k.name AS contact_name,
            u.full_name AS assignee_name, u.initials AS assignee_initials, u.color AS assignee_color
       FROM tickets t JOIN clients c ON c.id = t.client_id
       LEFT JOIN contacts k ON k.id = t.contact_id AND k.client_id = t.client_id
       LEFT JOIN users u ON u.id = t.assignee_id
      WHERE t.client_id = ? AND t.tenant_id = ? AND " . ($list['show'] === 'resolved'
        ? "t.status = 'resolved' ORDER BY COALESCE(t.resolved_at, t.created_at) DESC, t.id DESC LIMIT " . CLIENT_RESOLVED_PAGE_SIZE
            . ' OFFSET ' . (($list['page'] - 1) * CLIENT_RESOLVED_PAGE_SIZE)
        : "t.status <> 'resolved' ORDER BY CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, t.sla_due_at ASC, t.id ASC")
);
$tq->execute([$id, tenant_id()]);
$tickets = westy_workflow_ticket_owners(db(), tenant_id(), $tq->fetchAll());

$kq = db()->prepare('SELECT * FROM contacts WHERE client_id = ? ORDER BY name');
$kq->execute([$id]);
$contacts = $kq->fetchAll();

// How long this client waited for a first reply, on average, for tickets opened in the last 90 days.
$frq = db()->prepare(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, x.first_tech)) AS avg_min, COUNT(*) AS replied
       FROM tickets t
       JOIN (SELECT ticket_id, MIN(created_at) AS first_tech FROM messages WHERE kind = 'tech' GROUP BY ticket_id) x
         ON x.ticket_id = t.id
      WHERE t.client_id = ? AND t.tenant_id = ? AND t.created_at >= ?"
);
$frq->execute([$id, tenant_id(), gmdate('Y-m-d H:i:s', time() - CLIENT_FIRST_REPLY_DAYS * 86400)]);
$firstReply = $frq->fetch();
$firstReplyTickets = (int)($firstReply['replied'] ?? 0);
$firstReplyMinutes = $firstReplyTickets > 0 && $firstReply['avg_min'] !== null ? max(0, (int)round((float)$firstReply['avg_min'])) : null;

// Approved billable time worked for this client in the last 30 days, counting the latest approved
// change to an entry: the figure Reports shows every member of the team for the same client.
$bq = db()->prepare(
    "SELECT COALESCE(SUM(CASE
                  WHEN COALESCE(adjustment.effective_billable, e.billable) = 1
                  THEN COALESCE(adjustment.effective_minutes, e.minutes)
                  ELSE 0
                END), 0) AS min_total
       FROM time_entries e
       LEFT JOIN time_entry_approval_adjustments adjustment
         ON adjustment.tenant_id = e.tenant_id
        AND adjustment.time_entry_id = e.id
        AND adjustment.version_no = (
            SELECT MAX(latest.version_no)
              FROM time_entry_approval_adjustments latest
             WHERE latest.tenant_id = e.tenant_id AND latest.time_entry_id = e.id
        )
      WHERE e.tenant_id = ? AND e.client_id = ? AND e.approval_status = 'approved' AND e.worked_at >= ?"
);
$bq->execute([tenant_id(), $id, gmdate('Y-m-d H:i:s', time() - CLIENT_BILLABLE_DAYS * 86400)]);
$billableMinutes = (int)$bq->fetchColumn();

// The shared name/status is Milepost's last accepted receipt, separate from the local fields above.
$operational = client_operational_context(db(), tenant_id(), $id);
$deviceUrl = $operational['available'] && $operational['status'] === 'active' && is_string($operational['device_url'] ?? null)
    ? $operational['device_url']
    : null;
// An agreeing linked client keeps its own Coastmark history link, whatever its shared state.
$billingUrl = $operational['available'] && is_string($operational['billing_url'] ?? null)
    ? $operational['billing_url']
    : null;
// Milepost's current directory, read for this request only. It describes, never changes, this client.
$roster = $operational['available'] ? client_operational_roster(db(), $user) : ['available' => false];
$current = $roster['available'] ? ($roster['customers'][(string)$operational['customer_id']] ?? null) : null;

page_top($user, $client['name'], 'clients');
?>
<div class="page page-client">
  <a href="/clients.php" class="backlink">← Clients</a>
  <?= client_head($client) ?>
  <?= client_note($client['notes'] ?? null) ?>
  <?= client_tiles($openCount, $firstReplyMinutes, $firstReplyTickets, $billableMinutes) ?>

  <div class="ticket-grid">
    <div>
      <?= client_ticket_tabs((int)$id, $list['show'], $openCount, $resolvedCount) ?>
      <div class="card list">
        <?php if (!$tickets): ?><?= client_tickets_empty($list['show'], (string)$client['name'], (int)$id) ?><?php endif; ?>
        <?php foreach ($tickets as $t): ?><?= ticket_row($t, true, '', false) ?><?php endforeach; ?>
      </div>
      <?= $list['show'] === 'resolved' ? client_resolved_pager((int)$id, $resolvedCount, $list['page']) : '' ?>
    </div>
    <div class="rail">
      <div class="rail-label">Contacts</div>
      <div class="card rail-list">
        <?php if (!$contacts): ?><p class="rail-list-item rail-list-sub">No contacts yet.</p><?php endif; ?>
        <?php foreach ($contacts as $c): ?><?= client_contact_row($c, (int)$id, csrf_field()) ?><?php endforeach; ?>
        <details class="rail-list-item contact-add">
          <summary class="contact-add-btn">Add a contact</summary>
          <form method="post" action="/client.php?id=<?= (int)$id ?>" class="contact-add-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_contact">
            <label class="field">Name
              <input type="text" name="name" required maxlength="128" autocomplete="off">
            </label>
            <label class="field">Email
              <input type="email" name="email" maxlength="190" autocomplete="off">
            </label>
            <button type="submit" class="btn-primary">Add contact</button>
          </form>
        </details>
      </div>

      <div class="rail-label">Computers and invoices</div>
      <div class="card rail-list">
        <p class="rail-list-item rail-line"><span>Computers</span><a class="link" href="<?= h($deviceUrl ?? 'https://support.8westit.com/') ?>" target="_blank" rel="noopener"><?= $deviceUrl !== null ? 'Open this client in Milepost ↗' : 'Open Milepost ↗' ?></a></p>
        <p class="rail-list-item rail-line"><span>Invoices</span><a class="link" href="<?= h($billingUrl ?? 'https://coastmark.8westit.com/auth/suite') ?>" target="_blank" rel="noopener"><?= $billingUrl !== null ? 'Open this client in Coastmark ↗' : 'Open Coastmark ↗' ?></a></p>
        <p class="rail-list-item field-help"><?= match (true) {
            $deviceUrl !== null && $billingUrl !== null => 'Milepost and Coastmark check your access before showing this client.',
            $billingUrl !== null => 'Coastmark checks your access before showing this client. In Milepost, select the client.',
            $deviceUrl !== null => 'Milepost checks your access before showing devices. In Coastmark, select the client.',
            default => 'Open the app, then select the client.',
        } ?></p>
      </div>

      <div class="client-link">
      <?php if ($operational['available']): ?>
        <p class="client-shared">Linked to Milepost as <strong><?= h($operational['display_name']) ?></strong>
          · <span class="crow-shared-<?= $operational['status'] === 'active' ? 'active' : 'inactive' ?>"><?= h($operational['status']) ?></span>
          · <a class="link" href="<?= h($operational['edit_url']) ?>" target="_blank" rel="noopener">Shared details ↗</a></p>
        <?php if ($current !== null): ?>
          <p class="client-shared">Milepost directory now: <strong><?= h($current['display_name']) ?></strong>
            · <span class="crow-shared-<?= $current['status'] === 'active' ? 'active' : 'inactive' ?>"><?= h($current['status']) ?></span><?= $current['client_exists'] ? '' : ' · removed in Milepost' ?></p>
        <?php elseif ($roster['available']): ?>
          <p class="client-shared">Not in Milepost’s current client directory; kept here as retained history.</p>
        <?php else: ?>
          <p class="client-shared">Milepost’s current client directory is unavailable right now.</p>
        <?php endif; ?>
        <details class="client-link-details">
          <summary>Details</summary>
          <p>Source version last received: <?= (int)$operational['source_version'] ?>.</p>
          <?php if ($current !== null): ?><p>Source version in Milepost’s directory: <?= (int)$current['source_version'] ?>.</p><?php endif; ?>
        </details>
      <?php elseif ($operational['bound'] !== false): ?>
        <p class="client-shared">Shared details are temporarily unavailable. The details kept here are unaffected.</p>
      <?php else: ?>
        <p class="client-shared">Not linked to Milepost. Shared clients are created in Milepost.</p>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
page_bottom(palette_data());
