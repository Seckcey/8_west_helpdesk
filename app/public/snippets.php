<?php
/**
 * Saved replies — the canned responses "/" inserts in the composer.
 * Merge fields resolve at insert: {contact.first_name} {client.name}
 * {ticket.id} {tech.first_name}. Small-team tool: every active user can
 * add; owner/admin can edit or delete any in their workspace, others their own.
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

    if ($action === 'edit') {
        $sid = (int)($_POST['snippet_id'] ?? 0);
        $title = utf8_clean(trim((string)($_POST['title'] ?? '')));
        $body = utf8_clean(trim((string)($_POST['body'] ?? '')));
        if ($title === '' || $body === '') {
            $error = 'A saved reply needs both a title and a body.';
        } elseif (mb_strlen($title) > 80) {
            $error = 'Keep the title under 80 characters.';
        } else {
            // Same authority as delete, rechecked at the write; the author never changes.
            if ($isAdmin) {
                db()->prepare('UPDATE canned_responses SET title = ?, body = ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$title, $body, $sid, tenant_id()]);
                $available = db()->prepare('SELECT id FROM canned_responses WHERE id = ? AND tenant_id = ?');
                $available->execute([$sid, tenant_id()]);
            } else {
                db()->prepare('UPDATE canned_responses SET title = ?, body = ? WHERE id = ? AND tenant_id = ? AND created_by = ?')
                    ->execute([$title, $body, $sid, tenant_id(), (int)$user['id']]);
                $available = db()->prepare('SELECT id FROM canned_responses WHERE id = ? AND tenant_id = ? AND created_by = ?');
                $available->execute([$sid, tenant_id(), (int)$user['id']]);
            }
            // An unchanged save can affect zero rows; availability is the meaningful check.
            if (!$available->fetch()) {
                $error = 'That saved reply is not available to edit.';
            } else {
                header('Location: /snippets.php');
                exit;
            }
        }
    }
}

$rows = db()->prepare(
    'SELECT c.*, u.full_name AS author FROM canned_responses c
       LEFT JOIN users u ON u.id = c.created_by
      WHERE c.tenant_id = ? ORDER BY c.title'
);
$rows->execute([tenant_id()]);
$snippets = $rows->fetchAll();

$editable = [];
foreach ($snippets as $snippet) {
    if ($isAdmin || (int)$snippet['created_by'] === (int)$user['id']) {
        $editable[(int)$snippet['id']] = ['title' => $snippet['title'], 'body' => $snippet['body']];
    }
}
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$editId = (int)($posted && ($_POST['action'] ?? '') === 'edit' ? ($_POST['snippet_id'] ?? 0) : ($_GET['edit'] ?? 0));
$editing = $editId > 0 && isset($editable[$editId]);
if ($editId > 0 && !$editing) $error = 'That saved reply is not available to edit.';
$formOpen = $editing || isset($_GET['new']) || ($posted && ($_POST['action'] ?? '') === 'add' && $error !== '');
$formTitle = $editing ? $editable[$editId]['title'] : '';
$formBody = $editing ? $editable[$editId]['body'] : '';
if ($posted && $error !== '' && $formOpen) {
    $formTitle = utf8_clean((string)($_POST['title'] ?? ''));
    $formBody = utf8_clean((string)($_POST['body'] ?? ''));
}

// Saved replies has no rail item of its own, so no rail item is marked as the current page.
page_top($user, 'Saved replies', 'saved-replies');
?>
<?php /* No back link here, but u and Esc still go back to the Queue, as they did when this page called itself Queue. */ ?>
<div class="page page-narrow page-saved-replies" data-back="/">
  <div class="page-head">
    <div>
      <h1 class="page-title">Saved replies</h1>
      <p class="page-sub">Keep the answers you use often. Add them from a ticket's Saved replies menu.</p>
    </div>
    <?php if ($snippets): ?><a href="/snippets.php?new=1#saved-reply-editor" class="btn-primary" data-reply-new<?= $formOpen ? ' hidden' : '' ?>>New saved reply</a><?php endif; ?>
  </div>

  <?php if ($error): ?><div class="form-error" role="alert" data-reply-error><?= h($error) ?></div><?php endif; ?>

  <section class="card saved-reply-library" aria-label="Saved reply library">
    <?php if (!$snippets): ?>
      <div class="empty"><p>No saved replies yet. Save the answers you write most often.</p><a href="/snippets.php?new=1#saved-reply-editor" class="btn-primary" data-reply-new<?= $formOpen ? ' hidden' : '' ?>>New saved reply</a></div>
    <?php endif; ?>
    <?php foreach ($snippets as $s): ?>
    <div class="entry-row saved-reply-row">
      <div class="entry-main">
        <div class="entry-note"><strong><?= h($s['title']) ?></strong></div>
        <div class="snippet-body"><?= h(mb_substr(preg_split('/\R/u', (string)$s['body'])[0], 0, 160)) ?></div>
        <span class="msg-when">by <?= h($s['author'] ?? '—') ?></span>
      </div>
      <?php if ($isAdmin || (int)$s['created_by'] === (int)$user['id']): ?>
      <div class="saved-reply-actions">
      <a href="/snippets.php?edit=<?= (int)$s['id'] ?>#saved-reply-editor" class="btn-secondary" data-reply-edit="<?= (int)$s['id'] ?>" aria-label="Edit <?= h($s['title']) ?>">Edit</a>
      <form method="post" action="/snippets.php" data-confirm="Delete this saved reply?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="snippet_id" value="<?= (int)$s['id'] ?>">
        <button type="submit" class="btn-danger" aria-label="Delete <?= h($s['title']) ?>">Delete</button>
      </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="card saved-reply-editor" id="saved-reply-editor" aria-labelledby="saved-reply-heading"<?= $formOpen ? '' : ' hidden' ?>>
    <h2 id="saved-reply-heading"><?= $editing ? 'Edit saved reply' : 'New saved reply' ?></h2>
    <div class="saved-reply-workspace">
      <form method="post" action="/snippets.php" id="saved-reply-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
        <input type="hidden" name="snippet_id" value="<?= $editing ? $editId : 0 ?>">
        <label class="field">Title<input type="text" name="title" maxlength="80" required value="<?= h($formTitle) ?>"></label>
        <label class="field">Reply<textarea name="body" rows="7" required><?= h($formBody) ?></textarea></label>
        <fieldset class="saved-reply-fields"><legend>Add a field</legend>
          <button type="button" class="btn-secondary" data-reply-field="contact.first_name">Customer first name</button>
          <button type="button" class="btn-secondary" data-reply-field="client.name">Client name</button>
          <button type="button" class="btn-secondary" data-reply-field="ticket.id">Ticket number</button>
          <button type="button" class="btn-secondary" data-reply-field="tech.first_name">Your first name</button>
        </fieldset>
        <div class="saved-reply-form-actions"><button type="submit" class="btn-primary"><?= $editing ? 'Save changes' : 'Add saved reply' ?></button><a href="/snippets.php" class="btn-secondary" data-reply-cancel>Cancel</a></div>
      </form>
      <div class="saved-reply-preview" aria-labelledby="saved-reply-preview-heading"><h3 id="saved-reply-preview-heading">Preview</h3><p class="page-note">Sample values only. The ticket fills in the real details when you insert the reply.</p><div data-reply-preview></div></div>
    </div>
  </section>
  <script type="application/json" id="saved-replies-data"><?= json_encode($editable, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</div>
<?php
page_bottom(palette_data());
