<?php
/**
 * My profile — your own name + password. (Email is the sign-in identity;
 * an admin changes it from Team when 8 West ID lands it'll move there.)
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$error = '';
$ok = '';

// initials_of() lives in lib/bootstrap.php (shared with Team + SSO provisioning)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_name') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        if ($name === '') {
            $error = 'Name can’t be empty.';
        } else {
            db()->prepare('UPDATE users SET full_name = ?, initials = ? WHERE id = ?')
                ->execute([$name, initials_of($name), (int)$user['id']]);
            $ok = 'Name updated.';
            $user['full_name'] = $name;
        }
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $user['password_hash'])) {
            $error = 'Current password didn’t match.';
        } elseif (mb_strlen($new) < 6) {
            $error = 'New password needs 6+ characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords don’t match.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            $ok = 'Password changed.';
        }
    }
}

page_top($user, 'My profile', '');
?>
<div class="page page-narrow">
  <div class="page-head">
    <div>
      <h1 class="page-title">My profile</h1>
      <p class="page-sub"><?= h($user['email']) ?> · <?= h($user['role']) ?> at 8 West IT, LLC</p>
    </div>
    <?= avatar($user, 44) ?>
  </div>

  <?php if ($error): ?><div class="form-error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="form-ok"><?= h($ok) ?></div><?php endif; ?>

  <div class="rail-label">Display name</div>
  <div class="card form-card">
    <form method="post" action="/profile.php" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_name">
      <label class="field span-2">Full name
        <input type="text" name="full_name" required value="<?= h($user['full_name']) ?>">
      </label>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary btn-sm">Save name</button>
        <span class="reply-hint">Avatar initials update automatically. Email changes go through Team.</span>
      </div>
    </form>
  </div>

  <div class="rail-label" style="margin-top:20px">Change password</div>
  <div class="card form-card">
    <form method="post" action="/profile.php" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <label class="field span-2">Current password
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <label class="field">New password
        <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
      </label>
      <label class="field">Confirm new password
        <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
      </label>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary btn-sm">Change password</button>
        <span class="reply-hint">8 West ID single sign-on replaces this when the suite contract lands.</span>
      </div>
    </form>
  </div>
</div>
<?php
page_bottom(palette_data());
