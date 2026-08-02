<?php
/**
 * Saved replies — the canned responses "/" inserts in the composer.
 * Merge fields resolve at insert: {contact.first_name} {client.name}
 * {ticket.id} {tech.first_name}. Small-team tool: every active user can
 * add; owner/admin can delete any, techs their own.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$isAdmin = in_array($user['role'], ['owner', 'admin'], true);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = utf8_clean(trim((string)($_POST['title'] ?? '')));
        $body  = utf8_clean(trim((string)($_POST['body'] ?? '')));
        if ($title === '' || $body === '') {
            $error = 'A saved reply needs both a title and a body.';
        } elseif (mb_strlen($title) > 80) {
            $error = 'Keep the title under 80 characters.';
        } else {
            db()->prepare('INSERT INTO canned_responses (tenant_id, title, body, created_by) VALUES (?,?,?,?)')
                ->execute([tenant_id(), $title, $body, (int)$user['id']]);
            header('Location: /snippets.php');
            exit;
        }
    }

    if ($action === 'delete' && isset($_POST['snippet_id'])) {
        $sid = (int)$_POST['snippet_id'];
        if ($isAdmin) {
            db()->prepare('DELETE FROM canned_responses WHERE id = ? AND tenant_id = ?')
                ->execute([$sid, tenant_id()]);
        } else {
            db()->prepare('DELETE FROM canned_responses WHERE id = ? AND tenant_id = ? AND created_by = ?')
                ->execute([$sid, tenant_id(), (int)$user['id']]);
        }
        header('Location: /snippets.php');
        exit;
    }
}

$rows = db()->prepare(
    'SELECT c.*, u.full_name AS author FROM canned_responses c
       LEFT JOIN users u ON u.id = c.created_by
      WHERE c.tenant_id = ? ORDER BY c.title'
);
$rows->execute([tenant_id()]);
$snippets = $rows->fetchAll();

page_top($user, 'Saved replies', 'queue');
?>
<div class="page page-narrow">
  <a href="/" class="backlink">← Queue</a>
  <div class="page-head">
    <div>
      <h1 class="page-title">Saved replies</h1>
      <p class="page-sub">Type <kbd class="kbd">/</kbd> in any ticket composer to insert one — merge fields fill themselves</p>
    </div>
  </div>

  <?php if ($error): ?><div class="form-error"><?= h($error) ?></div><?php endif; ?>

  <div class="card form-card">
    <form method="post" action="/snippets.php" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <label class="field span-2">Title
        <input type="text" name="title" maxlength="80" placeholder="e.g. Password reset steps" required>
      </label>
      <label class="field span-2">Body
        <textarea name="body" rows="4" placeholder="Hi {contact.first_name}, … — {tech.first_name}, ticket #{ticket.id} at {client.name}" required></textarea>
      </label>
      <p class="page-note span-2">Merge fields: <code>{contact.first_name}</code> <code>{client.name}</code> <code>{ticket.id}</code> <code>{tech.first_name}</code></p>
      <div class="span-2"><button type="submit" class="btn-primary btn-sm">Add saved reply</button></div>
    </form>
  </div>

  <div class="rail-label">Your library (<?= count($snippets) ?>)</div>
  <div class="card rail-list">
    <?php if (!$snippets): ?>
      <div class="empty"><p>No saved replies yet. The ones you answer twice belong here.</p></div>
    <?php endif; ?>
    <?php foreach ($snippets as $s): ?>
    <div class="entry-row">
      <div class="entry-main">
        <div class="entry-note"><strong><?= h($s['title']) ?></strong></div>
        <div class="snippet-body"><?= h(mb_substr($s['body'], 0, 160)) ?><?= mb_strlen($s['body']) > 160 ? '…' : '' ?></div>
        <span class="msg-when">by <?= h($s['author'] ?? '—') ?></span>
      </div>
      <?php if ($isAdmin || (int)$s['created_by'] === (int)$user['id']): ?>
      <form method="post" action="/snippets.php" onsubmit="return confirm('Delete this saved reply?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="snippet_id" value="<?= (int)$s['id'] ?>">
        <button type="submit" class="btn-link">Delete</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
page_bottom(palette_data());
