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
$error = '';
$ok = '';

function initials_of(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr(end($parts) ?: '', 0, 1));
    return mb_substr($ini, 0, 2);
}

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
                $ok = "{$name} added — they can sign in with {$email}.";
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
<div class="page page-narrow">
  <div class="page-head">
    <div>
      <h1 class="page-title">Team</h1>
      <p class="page-sub"><?= count(array_filter($rows, static fn($r) => (int)$r['is_active'] === 1)) ?> active techs at 8 West IT, LLC</p>
    </div>
  </div>

  <?php if ($error): ?><div class="form-error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="form-ok"><?= h($ok) ?></div><?php endif; ?>

  <div class="card list">
    <?php foreach ($rows as $r): $active = (int)$r['is_active'] === 1; ?>
    <div class="urow <?= $active ? '' : 'urow-inactive' ?>">
      <?= avatar($r, 30) ?>
      <span class="urow-main">
        <span class="urow-name"><?= h($r['full_name']) ?><?= (int)$r['id'] === (int)$user['id'] ? ' <span class="urow-sub">(you)</span>' : '' ?></span>
        <span class="urow-sub"><?= h($r['email']) ?><?= $r['last_login_at'] ? ' · last sign-in ' . rel_time($r['last_login_at']) . ' ago' : '' ?></span>
      </span>
      <span class="role-badge role-<?= h($r['role']) ?>"><?= h($r['role']) ?></span>
      <?php if ($isAdmin && (int)$r['id'] !== (int)$user['id']): ?>
      <form method="post" action="/users.php" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'reactivate' ?>">
        <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="btn-link"><?= $active ? 'Deactivate' : 'Reactivate' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="rail-label" style="margin-top:24px">Add a teammate</div>
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
      <label class="field">Temporary password
        <input type="text" name="password" required minlength="6" placeholder="6+ characters">
      </label>
      <label class="field">Role
        <select name="role">
          <option value="tech">tech</option>
          <option value="admin">admin</option>
          <option value="owner">owner</option>
        </select>
      </label>
      <div class="field span-2">Avatar color
        <div class="swatches">
          <?php foreach ($COLORS as $i => $c): ?>
          <label class="swatch<?= $i === 0 ? ' swatch-on' : '' ?>" style="background:<?= $c ?>">
            <input type="radio" name="color" value="<?= $c ?>" <?= $i === 0 ? 'checked' : '' ?> hidden>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">Add user</button>
        <span class="reply-hint">They appear in assignment rotation immediately.</span>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<script>
document.querySelectorAll('.swatch input').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelectorAll('.swatch').forEach(function (s) { s.classList.remove('swatch-on'); });
    r.closest('.swatch').classList.add('swatch-on');
  });
});
</script>
<?php
page_bottom(palette_data());
