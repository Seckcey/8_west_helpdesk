<?php
/**
 * Team — user management. Everyone can view; owner/admin can add users
 * and deactivate/reactivate (never themselves).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/first_steps.php';
// ticket_ago(): "20 minutes ago", "yesterday", the way the ticket page says when something happened.
require_once __DIR__ . '/../lib/ticket_page.php';
enforce_https();
$user = require_login();

$isAdmin = in_array($user['role'], ['owner', 'admin'], true);
$COLORS = ['#2D8CFF', '#7DDCFF', '#F6C95B', '#62F6B0'];
$roleLabels = ['tech' => 'Technician', 'admin' => 'Administrator', 'owner' => 'Owner'];
$roleHelp = ['tech' => 'Work on support requests.', 'admin' => 'Manage support and local team access.', 'owner' => 'Owner access to this Safeharbor workspace.'];
$colorLabels = ['Blue', 'Cyan', 'Gold', 'Mint'];
$error = '';
$ok = '';
$addFailed = false;
// People are added in 8 West ID. The invite shows only where it showed before: an owner or administrator
// whose 8 West ID session grants people management in the MSP Admin panel (one rule, in first_steps.php).
$canInvite = staff_invite_allowed($user, $_SESSION ?? []);
$inviteUrl = STAFF_INVITE_URL;

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
        $addFailed = $error !== '';
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

// After a failed add, the form reopens with the person's choices; otherwise it starts at Technician and Blue.
$chosenRole = $addFailed && in_array($_POST['role'] ?? '', ['owner', 'admin', 'tech'], true) ? $_POST['role'] : 'tech';
$chosenColor = $addFailed && in_array($_POST['color'] ?? '', $COLORS, true) ? $_POST['color'] : $COLORS[0];

// "3 people", or "3 people with access, 1 without" once someone's access is off.
$withAccess = count(array_filter($rows, static fn($r) => (int)$r['is_active'] === 1));
$withoutAccess = count($rows) - $withAccess;
$peopleLine = ($withAccess === 1 ? '1 person' : number_format($withAccess) . ' people')
    . ($withoutAccess > 0 ? ' with access, ' . number_format($withoutAccess) . ' without' : '');

page_top($user, 'Team', 'team');
?>
<div class="page settings-page team-settings">
  <div class="page-head">
    <div>
      <h1 class="page-title">Team</h1>
      <p class="page-sub"><?= h($peopleLine) ?></p>
    </div>
    <?php if ($canInvite): ?>
    <div class="page-actions">
      <a class="btn-primary" href="<?= h($inviteUrl) ?>">Invite a teammate</a>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($canInvite): ?>
  <div class="callout callout-info team-invite-callout"><svg class="callout-icon" viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.2V11M8 5h.01" stroke-linecap="round"/></svg><p><strong>People are added once, in 8 West ID.</strong> Invite someone there and choose which apps they can open. They appear on this list after they sign in.</p></div>
  <?php elseif ($isAdmin): ?>
  <div class="callout callout-info team-invite-callout"><svg class="callout-icon" viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.2V11M8 5h.01" stroke-linecap="round"/></svg><p><strong>People are added once, in 8 West ID.</strong> Ask the person who manages your 8 West ID account to invite teammates.</p></div>
  <?php endif; ?>

  <?php if ($error): ?><div class="form-error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="form-ok" role="status"><?= h($ok) ?></div><?php endif; ?>

  <?php /* One row per person: who they are, then their role, whether they have access, and (for an
           owner or administrator) the one action, each in its own column so the rows line up. */ ?>
  <div class="card list team-list<?= $isAdmin ? ' team-list-actions' : '' ?>">
    <?php foreach ($rows as $r): $active = (int)$r['is_active'] === 1; ?>
    <div class="urow <?= $active ? '' : 'urow-inactive' ?>">
      <?= avatar($r, 30) ?>
      <span class="urow-main">
        <span class="urow-name"><?= h($r['full_name']) ?><?= (int)$r['id'] === (int)$user['id'] ? ' <span class="urow-sub">(you)</span>' : '' ?></span>
        <span class="urow-sub"><?= h($r['email']) ?></span>
        <span class="urow-sub"><?= $r['last_login_at'] ? 'Signed in ' . h(ticket_ago((string)$r['last_login_at'])) : 'Not signed in yet' ?></span>
        <?php if (suite_local_password_allowed($r)): ?><span class="urow-sub">Uses a backup password</span><?php endif; ?>
      </span>
      <span class="urow-role"><span class="role-badge role-<?= h($r['role']) ?>"><?= h($roleLabels[$r['role']] ?? 'Team member') ?></span></span>
      <span class="urow-status"><?= $active ? 'Active' : 'Access disabled' ?></span>
      <?php if ($isAdmin && (int)$r['id'] !== (int)$user['id']): ?>
      <form method="post" action="/users.php" class="urow-action">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'reactivate' ?>">
        <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="btn-secondary" aria-label="<?= h(($active ? 'Disable Safeharbor access for ' : 'Enable Safeharbor access for ') . $r['full_name']) ?>"><?= $active ? 'Disable access' : 'Enable access' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($isAdmin): ?>
  <details class="card backup-signin"<?= $addFailed ? ' open' : '' ?>>
  <summary><span class="backup-signin-title">Backup sign-in for emergencies</span>
    <span class="backup-signin-help">A local password account that still works if 8 West ID is unreachable. Most teams never need one.</span></summary>
  <div class="form-card">
    <h2 class="rail-label">Add a local recovery account</h2>
    <form method="post" action="/users.php" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <label class="field">Full name
        <input type="text" name="full_name" required placeholder="Jordan Ellis" value="<?= $addFailed ? h((string)($_POST['full_name'] ?? '')) : '' ?>">
      </label>
      <label class="field">Work email
        <input type="email" name="email" required placeholder="jordan@8westit.com" value="<?= $addFailed ? h((string)($_POST['email'] ?? '')) : '' ?>">
      </label>
      <label class="field">Local password
        <input type="text" name="password" required minlength="6" placeholder="6+ characters">
      </label>
      <fieldset class="settings-options span-2"><legend>Safeharbor role</legend>
        <div class="settings-role-choices">
          <?php foreach ($roleLabels as $value => $label): ?>
          <label class="settings-choice"><input type="radio" name="role" value="<?= h($value) ?>" <?= $value === $chosenRole ? 'checked' : '' ?>><span><strong><?= h($label) ?></strong><small><?= h($roleHelp[$value]) ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset class="settings-options span-2"><legend>Avatar color</legend>
        <div class="settings-color-choices">
          <?php foreach ($COLORS as $i => $c): ?>
          <label class="settings-choice settings-color"><input type="radio" name="color" value="<?= $c ?>" <?= $c === $chosenColor ? 'checked' : '' ?>>
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
  </details>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
