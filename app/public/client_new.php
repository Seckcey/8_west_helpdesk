<?php
/** New client — name, domain, SLA tier, optional first contact. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name   = trim((string)($_POST['name'] ?? ''));
    $domain = mb_strtolower(trim((string)($_POST['domain'] ?? '')));
    $tier   = in_array($_POST['sla_tier'] ?? '', ['standard', 'premium'], true) ? $_POST['sla_tier'] : 'standard';
    $cName  = trim((string)($_POST['contact_name'] ?? ''));
    $cEmail = mb_strtolower(trim((string)($_POST['contact_email'] ?? '')));

    if ($name === '') {
        $error = 'Client name is required.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO clients (tenant_id, name, domain, sla_tier) VALUES (?,?,?,?)')
                ->execute([tenant_id(), $name, $domain, $tier]);
            $clientId = (int)$pdo->lastInsertId();
            if ($cName !== '') {
                $pdo->prepare('INSERT INTO contacts (client_id, name, email) VALUES (?,?,?)')
                    ->execute([$clientId, $cName, $cEmail]);
            }
            $pdo->commit();
            header('Location: /client.php?id=' . $clientId);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Could not create the client — please try again.';
        }
    }
}

page_top($user, 'New client', 'clients');
?>
<div class="page page-narrow">
  <a href="/clients.php" class="backlink">← Clients</a>
  <div class="page-head">
    <h1 class="page-title">New client</h1>
  </div>
  <?php if ($error): ?><div class="form-error"><?= h($error) ?></div><?php endif; ?>
  <div class="card form-card">
    <form method="post" action="/client_new.php" class="form-grid">
      <?= csrf_field() ?>
      <label class="field span-2">Client name
        <input type="text" name="name" required placeholder="Lakeside Pediatrics" autofocus>
      </label>
      <label class="field">Domain
        <input type="text" name="domain" placeholder="lakesidepeds.com">
      </label>
      <label class="field">SLA tier
        <select name="sla_tier">
          <option value="standard">standard</option>
          <option value="premium">premium</option>
        </select>
      </label>
      <div class="rail-label span-2" style="padding:0">First contact (optional)</div>
      <label class="field">Name
        <input type="text" name="contact_name" placeholder="Office manager">
      </label>
      <label class="field">Email
        <input type="email" name="contact_email" placeholder="office@lakesidepeds.com">
      </label>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">Create client</button>
        <a href="/clients.php" class="btn-link">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php
page_bottom(palette_data());
