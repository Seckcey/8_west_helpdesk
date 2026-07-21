<?php
/** Edit client — fields + delete (only when the client has no tickets). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ? AND tenant_id = ?');
$stmt->execute([$id, tenant_id()]);
$client = $stmt->fetch();

if (!$client) {
    header('Location: /clients.php');
    exit;
}

$error = '';

$tq = db()->prepare('SELECT COUNT(*) n FROM tickets WHERE client_id = ?');
$tq->execute([$id]);
$ticketCount = (int)$tq->fetch()['n'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $name   = trim((string)($_POST['name'] ?? ''));
        $domain = mb_strtolower(trim((string)($_POST['domain'] ?? '')));
        $tier   = in_array($_POST['sla_tier'] ?? '', ['standard', 'premium'], true) ? $_POST['sla_tier'] : $client['sla_tier'];
        $health = in_array($_POST['health'] ?? '', ['good', 'watch'], true) ? $_POST['health'] : $client['health'];
        $notes  = trim((string)($_POST['notes'] ?? ''));

        if ($name === '') {
            $error = 'Client name is required.';
        } else {
            db()->prepare('UPDATE clients SET name = ?, domain = ?, sla_tier = ?, health = ?, notes = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$name, $domain, $tier, $health, $notes, $id, tenant_id()]);
            header('Location: /client.php?id=' . $id);
            exit;
        }
    }

    if ($action === 'delete' && $ticketCount === 0) {
        db()->prepare('DELETE FROM contacts WHERE client_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM clients WHERE id = ? AND tenant_id = ?')->execute([$id, tenant_id()]);
        header('Location: /clients.php');
        exit;
    }
}

page_top($user, 'Edit ' . $client['name'], 'clients');
?>
<div class="page page-narrow">
  <a href="/client.php?id=<?= (int)$id ?>" class="backlink">← <?= h($client['name']) ?></a>
  <div class="page-head">
    <h1 class="page-title">Edit client</h1>
  </div>
  <?php if ($error): ?><div class="form-error"><?= h($error) ?></div><?php endif; ?>
  <div class="card form-card">
    <form method="post" action="/client_edit.php?id=<?= (int)$id ?>" class="form-grid">
      <?= csrf_field() ?>
      <label class="field span-2">Client name
        <input type="text" name="name" required value="<?= h($client['name']) ?>">
      </label>
      <label class="field">Domain
        <input type="text" name="domain" value="<?= h($client['domain']) ?>">
      </label>
      <label class="field">SLA tier
        <select name="sla_tier">
          <option value="standard" <?= $client['sla_tier'] === 'standard' ? 'selected' : '' ?>>standard</option>
          <option value="premium" <?= $client['sla_tier'] === 'premium' ? 'selected' : '' ?>>premium</option>
        </select>
      </label>
      <label class="field">Health
        <select name="health">
          <option value="good" <?= $client['health'] === 'good' ? 'selected' : '' ?>>good</option>
          <option value="watch" <?= $client['health'] === 'watch' ? 'selected' : '' ?>>watch</option>
        </select>
      </label>
      <label class="field span-2">Notes
        <textarea name="notes" placeholder="VPN creds in vault, preferred contact hours…"><?= h($client['notes'] ?? '') ?></textarea>
      </label>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">Save changes</button>
        <a href="/client.php?id=<?= (int)$id ?>" class="btn-link">Cancel</a>
      </div>
    </form>
  </div>

  <div class="demo-card">
    <span class="demo-text">
      <?php if ($ticketCount === 0): ?>
        This client has no tickets. Deleting removes it and its contacts — there's no undo.
      <?php else: ?>
        This client has <?= $ticketCount ?> ticket<?= $ticketCount === 1 ? '' : 's' ?>, so it can't be deleted (delete or reassign those first).
      <?php endif; ?>
    </span>
    <?php if ($ticketCount === 0): ?>
    <form method="post" action="/client_edit.php?id=<?= (int)$id ?>" onsubmit="return confirm('Delete <?= h($client['name']) ?> and its contacts? No undo.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn-danger">Delete client</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
page_bottom(palette_data());
