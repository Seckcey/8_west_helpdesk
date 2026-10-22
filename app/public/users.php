<?php
/**
 * Team — user management. Everyone can view; owner/admin can add users
 * and deactivate/reactivate (never themselves).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$isAdmin = in_array($user['role'], ['owner', 'admin'], true);
$COLORS = ['#2D8CFF', '#7DDCFF', '#F6C95B', '#62F6B0'];
$roleLabels = ['tech' => 'Technician', 'admin' => 'Administrator', 'owner' => 'Owner'];
$roleHelp = ['tech' => 'Work on support requests.', 'admin' => 'Manage support and local team access.', 'owner' => 'Owner access to this Safeharbor workspace.'];
$colorLabels = ['Blue', 'Cyan', 'Gold', 'Mint'];
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pass  = (string)($_POST['password'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['owner', 'admin', 'tech'], true) ? $_POST['role'] : 'tech';
        $color = in_array($_POST['color'] ?? '', $COLORS, true) ? $_POST['color'] : $COLORS[0];

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($pass) < 6) {
            $error = 'Name, a valid email, and a password of 6+ characters are required.';
        } else {
            $dupe = db()->prepare('SELECT 1 FROM users WHERE tenant_id = ? AND email = ?');
            $dupe->execute([tenant_id(), $email]);
            if ($dupe->fetch()) {
                $error = "A user with {$email} already exists.";
            } else {
                db()->prepare('INSERT INTO users (tenant_id, email, password_hash, full_name, initials, color, role) VALUES (?,?,?,?,?,?,?)')
                    ->execute([tenant_id(), $email, password_hash($pass, PASSWORD_DEFAULT), $name, initials_of($name), $color, $role]);
                $ok = "{$name} added as a local Safeharbor account — they can sign in with {$email}.";
            }
        }
    }

    if (($action === 'deactivate' || $action === 'reactivate') && isset($_POST['user_id'])) {
        $target = (int)$_POST['user_id'];
        if ($target === (int)$user['id']) {
            $error = 'You can’t deactivate yourself.';
        } else {
            db()->prepare('UPDATE users SET is_active = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$action === 'reactivate' ? 1 : 0, $target, tenant_id()]);
            $ok = $action === 'reactivate' ? 'User reactivated.' : 'User deactivated.';
        }
    }
}

$stmt = db()->prepare('SELECT * FROM users WHERE tenant_id = ? ORDER BY is_active DESC, full_name');
$stmt->execute([tenant_id()]);
$rows = $stmt->fetchAll();

page_top($user, 'Team', 'team');
?>
<div class="page page-narrow settings-page team-settings">
  <div class="page-head">
    <div>
      <h1 class="page-title">Team</h1>
      <p class="page-sub"><?= count(array_filter($rows, static fn($r) => (int)$r['is_active'] === 1)) ?> active Safeharbor accounts in this workspace</p>
    </div>
  </div>

  <?php if ($error): ?><div class="form-error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="form-ok" role="status"><?= h($ok) ?></div><?php endif; ?>

  <div class="card form-card settings-intro"><h2>Safeharbor team access</h2><p>This page controls local Safeharbor access and recovery accounts. 8 West ID manages suite identities, sign-in links and sign-in recovery.</p><?php if ($isAdmin && ($_SESSION['suite_settings_context']['capabilities']['manage_people'] ?? false) === true
      && in_array('msp_admin', $_SESSION['suite_settings_context']['panels'] ?? [], true)): ?><a class="btn-ghost settings-button" href="https://id.8westit.com/users.php">Manage people in 8 West ID</a><?php endif; ?></div>
  <div class="card list">
    <?php foreach ($rows as $r): $active = (int)$r['is_active'] === 1; ?>
    <div class="urow <?= $active ? '' : 'urow-inactive' ?>">
      <?= avatar($r, 30) ?>
      <span class="urow-main">
        <span class="urow-name"><?= h($r['full_name']) ?><?= (int)$r['id'] === (int)$user['id'] ? ' <span class="urow-sub">(you)</span>' : '' ?></span>
        <span class="urow-sub"><?= h($r['email']) ?> · <?= suite_local_password_allowed($r) ? 'Local recovery account' : '8 West ID account' ?><?= $r['last_login_at'] ? ' · last sign-in ' . rel_time($r['last_login_at']) . ' ago' : '' ?></span>
      </span>
      <span class="role-badge role-<?= h($r['role']) ?>"><?= h($roleLabels[$r['role']] ?? 'Team member') ?></span>
      <span class="urow-status"><?= $active ? 'Active' : 'Access disabled' ?></span>
      <?php if ($isAdmin && (int)$r['id'] !== (int)$user['id']): ?>
      <form method="post" action="/users.php" class="urow-action">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'reactivate' ?>">
        <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="<?= $active ? 'btn-danger' : 'btn-ghost' ?> settings-button" aria-label="<?= h(($active ? 'Disable Safeharbor access for ' : 'Enable Safeharbor access for ') . $r['full_name']) ?>"><?= $active ? 'Disable access' : 'Enable access' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="rail-label" style="margin-top:24px">Add a local recovery account</div>
  <div class="card form-card">
    <form method="post" action="/users.php" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <label class="field">Full name
        <input type="text" name="full_name" required placeholder="Jordan Ellis">
      </label>
      <label class="field">Work email
        <input type="email" name="email" required placeholder="jordan@8westit.com">
      </label>
      <label class="field">Local password
        <input type="text" name="password" required minlength="6" placeholder="6+ characters">
      </label>
      <fieldset class="settings-options span-2"><legend>Safeharbor role</legend>
        <div class="settings-role-choices">
          <?php foreach ($roleLabels as $value => $label): ?>
          <label class="settings-choice"><input type="radio" name="role" value="<?= h($value) ?>" <?= $value === 'tech' ? 'checked' : '' ?>><span><strong><?= h($label) ?></strong><small><?= h($roleHelp[$value]) ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset class="settings-options span-2"><legend>Avatar color</legend>
        <div class="settings-color-choices">
          <?php foreach ($COLORS as $i => $c): ?>
          <label class="settings-choice settings-color"><input type="radio" name="color" value="<?= $c ?>" <?= $i === 0 ? 'checked' : '' ?>>
            <span><i style="background:<?= $c ?>" aria-hidden="true"></i><strong><?= h($colorLabels[$i]) ?></strong></span>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">Add local account</button>
        <a class="btn-ghost settings-button" href="/users.php">Cancel</a>
        <span class="reply-hint">This creates a Safeharbor-only account, not an 8 West ID identity or invitation.</span>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
