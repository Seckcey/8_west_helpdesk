<?php
/** Owner-issued access to one computer; the signed service owns all authority. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_devices.php';
require_once __DIR__ . '/../../lib/portal_render.php';
enforce_https();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (!portal_enabled()) { http_response_code(404); exit('Not found.'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST'); portal_render_error(405, 'Method not allowed', 'Open Computer access from Your devices.'); exit;
}
$token = is_string($_POST['token'] ?? $_GET['token'] ?? null) ? (string)($_POST['token'] ?? $_GET['token']) : '';
$device = is_string($_POST['device_reference'] ?? $_GET['device'] ?? null) ? (string)($_POST['device_reference'] ?? $_GET['device']) : '';
$error = null; $notice = null; $link = null; $access = null; $redeemed = false;
try {
    $context = portal_authenticated_context(db());
    if ($context === null) {
        // Keep the one-use credential out of the OIDC return URL. Reopen the private link after sign-in.
        portal_render_login('Sign in with your own employee account, then open this private computer link again.');
        exit;
    }
    $manage = portal_devices_can_manage($context);
    try {
        if ($method === 'POST') {
            if (!portal_csrf_valid($_POST['csrf'] ?? null)) throw new PortalDevicesException('sign_in', 403);
            $action = $_POST['action'] ?? '';
            if ($action === 'device_access_redeem') {
                $result = portal_devices_request(db(), $context, $action,
                    ['token' => $token, 'display_name' => (string)$context['identity']['display_name']]);
                $redeemed = $result['state'] === 'active';
                $notice = $redeemed ? 'This computer is now available in Your devices.' : null;
            } elseif ($action === 'device_access_create' && $manage) {
                $result = portal_devices_request(db(), $context, $action,
                    ['device_reference' => $device, 'request_key' => $_POST['request_key'] ?? '']);
                if ($result['token'] !== null) {
                    $link = 'https://safeharbor.8westit.com/portal/computer-access.php?token=' . $result['token'];
                    $notice = 'Share this private link with the employee who should use this computer. It expires in 48 hours. No email has been sent.';
                } else {
                    $notice = 'This link was already created. Check access below; it was not replaced or sent again.';
                }
            } elseif ($action === 'device_access_revoke' && $manage) {
                portal_devices_request(db(), $context, $action, ['reference' => $_POST['reference'] ?? '']);
                $notice = 'Access revoked. The link cannot be reused. Work already delivered to the computer may still need review.';
            } else throw new PortalDevicesException('role', 403);
        }
        if ($manage && $device !== '') {
            $after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $access = portal_devices_request(db(), $context, 'device_access_list', ['device_reference' => $device, 'after' => $after === false ? 0 : $after]);
        }
    } catch (PortalDevicesException $failure) {
        $error = portal_devices_error($failure->reason);
        if ($method === 'POST') $error .= ' Check current access before creating another link or repeating the action.';
    }
    $fresh = portal_authenticated_context(db());
    if ($fresh === null || $fresh['identity'] !== $context['identity']) { portal_require_sign_in('Please sign in again.'); exit; }
    portal_page_start('Computer access', 'portal-devices-page', $fresh);
    ?>
<main class="portal-devices">
  <header class="portal-page-header"><div><p class="portal-eyebrow"><?= portal_h($fresh['binding']['client_name']) ?></p><h1>Computer access</h1></div><a href="/portal/devices.php">Your devices</a></header>
  <?php if ($error !== null): ?><p class="portal-notice" role="alert"><?= portal_h($error) ?></p><?php endif; ?>
  <?php if ($notice !== null): ?><p class="portal-notice" role="status"><?= portal_h($notice) ?></p><?php endif; ?>
  <?php if ($manage && $device !== '' && $access !== null): ?>
    <section class="portal-device-setup"><h2>Give an employee access</h2>
      <p>The employee needs their own account in this business. Share the link privately: the first signed-in employee in this business to use it gets access to this computer.</p>
      <?php if (in_array($fresh['identity']['role'], ['client_owner', 'client_admin'], true) && !isset($fresh['identity']['customer_access'])): ?>
        <p><a href="https://id.8westit.com/users.php#employee-invitations">Invite an employee in 8 West ID</a></p>
      <?php endif; ?>
      <?php if ($link !== null): ?><label for="computer-access-link">Private access link</label><input id="computer-access-link" type="text" readonly autocomplete="off" value="<?= portal_h($link) ?>"><?php endif; ?>
      <form method="post" action="/portal/computer-access.php">
        <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
        <input type="hidden" name="action" value="device_access_create">
        <input type="hidden" name="device_reference" value="<?= portal_h($device) ?>">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
        <button class="btn-primary" type="submit">Create employee access link</button>
      </form>
    </section>
    <section class="portal-device-links"><h2>Employees and access links</h2>
      <?php if ($access['items'] === []): ?><p>No employee access has been assigned to this computer yet.</p><?php endif; ?>
      <?php foreach ($access['items'] as $grant): ?><article>
        <div><strong><?= portal_h($grant['employee'] ?? 'Unclaimed access link') ?></strong><span><?= portal_h(['pending' => 'Waiting for an employee', 'active' => 'Has access', 'revoked' => 'Revoked', 'expired' => 'Link expired'][$grant['state']]) ?></span></div>
        <?php if (in_array($grant['state'], ['pending', 'active'], true)): ?><form method="post" action="/portal/computer-access.php">
          <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="device_access_revoke">
          <input type="hidden" name="device_reference" value="<?= portal_h($device) ?>"><input type="hidden" name="reference" value="<?= portal_h($grant['reference']) ?>">
          <button class="btn-link" type="submit">Revoke access</button>
        </form><?php endif; ?>
      </article><?php endforeach; ?>
      <?php if ($access['next_after'] !== null): ?><a href="/portal/computer-access.php?device=<?= rawurlencode($device) ?>&amp;after=<?= (int)$access['next_after'] ?>">Next access records</a><?php endif; ?>
    </section>
  <?php elseif (!$manage && $token !== '' && !$redeemed): ?>
    <section class="portal-device-setup"><h2>Use your assigned computer</h2><p>This private link grants your signed-in account access to one computer in <?= portal_h($fresh['binding']['client_name']) ?>. Your account's existing read-only or employee permissions still apply.</p>
      <form method="post" action="/portal/computer-access.php">
        <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="device_access_redeem">
        <input type="hidden" name="token" value="<?= portal_h($token) ?>"><button class="btn-primary" type="submit">Use this computer</button>
      </form>
    </section>
  <?php elseif ($redeemed): ?><a class="btn-primary" href="/portal/devices.php">Open Your devices</a>
  <?php else: ?><p><?= $manage ? 'Choose Computer access beside a computer in Your devices. Owners and administrators already have access to their own business’s computers.' : 'Ask your business owner for a private computer access link.' ?></p><?php endif; ?>
</main>
<?php portal_page_end();
} catch (Throwable $failure) {
    error_log('[safeharbor-computer-access] request_failed type=' . $failure::class);
    portal_render_error(503, 'Computer access unavailable', 'We could not confirm current access. Check Your devices before repeating an action.');
}
