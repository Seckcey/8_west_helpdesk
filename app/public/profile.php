<?php
/** My settings; ID owns linked identities, local recovery remains local. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/support_addresses.php';
enforce_https();
$user = require_login();

$localAccount = suite_local_password_allowed($user);

$error = '';
$ok = '';
$supportConfig = (array)cfg('support_addresses', []);
$supportAddress = null;
if (($supportConfig['transport_verified'] ?? null) === true
    && in_array((int)$user['tenant_id'], support_enabled_tenants(db(), $supportConfig), true)) {
    $supportAddress = support_address((int)$user['tenant_id'], (string)($supportConfig['mailbox'] ?? ''));
}

// initials_of() lives in lib/bootstrap.php (shared with Team + SSO provisioning)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if (!$localAccount) {
        $error = 'Manage your name and sign-in security in 8 West ID My settings.';
    } elseif ($action === 'save_name') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        if ($name === '') {
            $error = 'Name can’t be empty.';
        } else {
            db()->prepare('UPDATE users SET full_name = ?, initials = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$name, initials_of($name), (int)$user['id'], (int)$user['tenant_id']]);
            $ok = 'Name updated.';
            $user['full_name'] = $name;
        }
    }

    if ($localAccount && $action === 'change_password') {
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
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id'], (int)$user['tenant_id']]);
            $ok = 'Password changed.';
        }
    }
}

page_top($user, 'My settings', '');
?>
<div class="page page-narrow">
  <div class="page-head">
    <div>
      <h1 class="page-title">My settings</h1>
      <p class="page-sub"><?= h($user['email']) ?> · <?= h($user['role']) ?> · Safeharbor account</p>
    </div>
    <?= avatar($user, 44) ?>
  </div>

  <?php if ($error): ?><div class="form-error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="form-ok"><?= h($ok) ?></div><?php endif; ?>

  <?php if ($supportAddress !== null): ?>
  <div class="rail-label">Your support inbox</div>
  <div class="card form-card" style="margin-bottom:20px">
    <p>Email <a href="mailto:<?= h($supportAddress) ?>"><?= h($supportAddress) ?></a> to open a ticket in this workspace.</p>
    <p>Replies keep the ticket number in the subject. Add client contacts so incoming messages reach the right client.</p>
    <?php if (isset($supportConfig['client_ids_by_tenant'][(int)$user['tenant_id']])): ?>
      <p>This workspace is in a limited rollout. Intake accepts contacts of its enrolled clients.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card form-card"><p><a href="https://id.8westit.com/settings.php">My settings in 8 West ID</a> manages your suite profile, appearance, regional preferences and sign-in security.</p></div>
  <?php if ($localAccount): ?>
  <div class="rail-label">Local recovery account name</div>
  <div class="card form-card">
    <form method="post" action="/profile.php" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_name">
      <label class="field span-2">Full name
        <input type="text" name="full_name" required value="<?= h($user['full_name']) ?>">
      </label>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary btn-sm">Save name</button>
        <span class="reply-hint">Avatar initials update automatically. This name applies to your local Safeharbor recovery account.</span>
      </div>
    </form>
  </div>

  <div class="rail-label" style="margin-top:20px">Local recovery password</div>
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
        <span class="reply-hint">Changes only the unlinked local Safeharbor recovery account. It does not change an 8 West ID password.</span>
      </div>
    </form>
  </div>
  <?php else: ?><div class="card form-card"><p>This account signs in through 8 West ID. Its password, MFA, passkeys and recovery methods are managed there.</p></div><?php endif; ?>
</div>
<?php
page_bottom(palette_data());
