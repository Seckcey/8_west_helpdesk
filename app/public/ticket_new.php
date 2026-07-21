<?php
/**
 * New ticket — pick a client, write the issue, done. SLA target derives
 * from the client's tier: premium +2h, standard +8h (business rules land
 * in Phase 2; this keeps every ticket answerable from minute one).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$clientsQ = db()->prepare('SELECT id, name, sla_tier FROM clients WHERE tenant_id = ? ORDER BY name');
$clientsQ->execute([tenant_id()]);
$clients = $clientsQ->fetchAll();

$contactsQ = db()->prepare(
    'SELECT k.id, k.client_id, k.name
       FROM contacts k JOIN clients c ON c.id = k.client_id
      WHERE c.tenant_id = ? ORDER BY k.name'
);
$contactsQ->execute([tenant_id()]);
$contacts = $contactsQ->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $clientId  = (int)($_POST['client_id'] ?? 0);
    $contactId = (int)($_POST['contact_id'] ?? 0) ?: null;
    $subject   = trim((string)($_POST['subject'] ?? ''));
    $priority  = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true) ? $_POST['priority'] : 'normal';
    $channel   = in_array($_POST['channel'] ?? '', ['email', 'portal', 'alert', 'phone'], true) ? $_POST['channel'] : 'portal';
    $body      = trim((string)($_POST['body'] ?? ''));

    $cq = db()->prepare('SELECT * FROM clients WHERE id = ? AND tenant_id = ?');
    $cq->execute([$clientId]);
    $client = $cq->fetch();

    if (!$client) {
        $error = 'Pick a client first.';
    } elseif ($subject === '') {
        $error = 'Give the ticket a subject — future-you will thank present-you.';
    } else {
        if ($contactId) {
            $kq = db()->prepare('SELECT 1 FROM contacts WHERE id = ? AND client_id = ?');
            $kq->execute([$contactId, $clientId]);
            if (!$kq->fetch()) $contactId = null;
        }
        $hours = $client['sla_tier'] === 'premium' ? 2 : 8;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO tickets (tenant_id, client_id, contact_id, subject, priority, assignee_id, channel, sla_due_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))')
                ->execute([tenant_id(), $clientId, $contactId, $subject, $priority, (int)$user['id'], $channel, $hours]);
            $tid = (int)$pdo->lastInsertId();
            if ($body !== '') {
                $author = $user['full_name'];
                $pdo->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
                    ->execute([$tid, $author, 'tech', $body]);
            }
            $pdo->commit();
            header('Location: /ticket.php?id=' . $tid);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Could not create the ticket — please try again.';
        }
    }
}

page_top($user, 'New ticket', 'queue');
?>
<div class="page page-narrow">
  <a href="/" class="backlink">← Queue</a>
  <div class="page-head">
    <h1 class="page-title">New ticket</h1>
  </div>
  <?php if ($error): ?><div class="form-error"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$clients): ?>
    <div class="card empty"><p>No clients yet — <a class="link" href="/client_new.php">add your first client</a> and come right back.</p></div>
  <?php else: ?>
  <div class="card form-card">
    <form method="post" action="/ticket_new.php" class="form-grid">
      <?= csrf_field() ?>
      <label class="field">Client
        <select name="client_id" id="f-client">
          <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?> (<?= h($c['sla_tier']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">Contact
        <select name="contact_id" id="f-contact">
          <option value="">—</option>
          <?php foreach ($contacts as $k): ?>
          <option value="<?= (int)$k['id'] ?>" data-client="<?= (int)$k['client_id'] ?>"><?= h($k['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field span-2">Subject
        <input type="text" name="subject" required placeholder="Front desk PC won't print claim forms" autofocus>
      </label>
      <label class="field">Priority
        <select name="priority">
          <option value="low">low</option>
          <option value="normal" selected>normal</option>
          <option value="high">high</option>
          <option value="urgent">urgent</option>
        </select>
      </label>
      <label class="field">Channel
        <select name="channel">
          <option value="portal">portal</option>
          <option value="email">email</option>
          <option value="phone">phone</option>
          <option value="alert">alert</option>
        </select>
      </label>
      <label class="field span-2">First note (optional)
        <textarea name="body" placeholder="What you know so far — error text, who called, what changed…"></textarea>
      </label>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">File ticket</button>
        <span class="reply-hint">Assigned to you · SLA set from the client's tier · timer one key away (E)</span>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
