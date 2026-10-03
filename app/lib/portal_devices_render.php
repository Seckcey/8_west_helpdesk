<?php
declare(strict_types=1);
require_once __DIR__ . '/portal_render.php';
require_once __DIR__ . '/portal_devices.php';

function portal_render_devices(array $context, ?array $devices, array $enrollments, ?string $error = null, ?array $download = null, ?string $notice = null): void
{
    $manage = portal_devices_can_manage($context);
    $context['chat_button_placement'] = 'inline';
    portal_page_start('Your devices', 'portal-devices-page', $context);
    ?>
<main class="portal-devices">
 <header class="portal-devices-heading"><div><p class="portal-eyebrow">YOUR BUSINESS</p><h1>Your devices</h1><p>Connect a computer so your support team can help when you need it.</p></div><div class="portal-devices-actions"><a class="btn-link" href="/portal/devices.php">Refresh status</a><?php portal_westy_button(); ?></div></header>
 <?php if ((cfg('portal_mobile', [])['enabled'] ?? false) === true): ?><p><a class="btn-link" href="/portal/mobile.php">Phones, tablets &amp; Macs <?= portal_icon('arrow') ?></a></p><?php endif; ?>
 <?php if ($error !== null): ?><div class="portal-device-notice" role="alert"><?= portal_h($error) ?> <a href="/portal/new.php">Contact support</a></div><?php endif; ?>
 <?php if ($notice !== null): ?><div class="portal-device-notice is-success" role="status"><?= portal_h($notice) ?></div><?php endif; ?>
 <?php if ($download !== null): ?>
 <section class="portal-device-install" aria-labelledby="install-title"><p class="portal-eyebrow">READY TO INSTALL</p><h2 id="install-title">Install on your Windows computer</h2>
  <ol><li>Download the setup file on the computer you want to add.</li><li>Open it and approve Windows’ administrator prompt. The installer checks the signed Milepost package.</li><li>Keep the computer online, then refresh this page to see its first check-in.</li></ol>
  <a class="btn-primary" href="<?= portal_h($download['download_url']) ?>" rel="noreferrer">Download Milepost setup</a>
  <p class="portal-hint">This private link expires <?= portal_h(portal_format_utc($download['expires_at'])) ?> and can enroll one computer. Share it only with someone authorized to install for your business.</p>
 </section><?php endif; ?>
 <div class="portal-devices-layout"><section aria-labelledby="computers-heading">
  <div class="portal-section-title"><h2 id="computers-heading">Connected computers</h2></div>
  <?php if ($devices === null): ?><p>We cannot retrieve your computers right now. Their status has not been changed.</p>
  <?php elseif (($devices['items'] ?? []) === []): ?><div class="portal-device-empty"><?= portal_icon('device') ?><h3>No computers on this page yet</h3><p><?= $manage ? 'Add your first Windows computer using the setup panel.' : 'Ask a business owner or admin to add your computers.' ?></p></div>
  <?php else: ?><div class="portal-device-list"><?php foreach ($devices['items'] as $device): ?>
   <article class="portal-device-card"><div class="portal-device-symbol"><?= portal_icon('device') ?></div><div class="portal-device-details"><h3><?= portal_h($device['label']) ?></h3><p><?= portal_h($device['platform']) ?></p>
    <span class="portal-device-state<?= $device['connection'] === 'reporting' ? ' is-online' : '' ?>"><?= portal_h($device['connection_label']) ?></span>
    <p class="portal-hint"><?= portal_h($device['connection_help']) ?></p>
    <?php if ($device['last_seen_at'] !== null): ?><p class="portal-hint">Last check-in: <?= portal_h(portal_format_utc($device['last_seen_at'])) ?></p><?php endif; ?>
    <button type="button" class="btn-link" data-portal-chat-prompt="<?= portal_h('Help me describe a problem with my computer ' . $device['label'] . '. It shows ' . $device['connection_label'] . '.') ?>">Ask Westy for help <?= portal_icon('arrow') ?></button>
    <?php if((cfg('portal_devices',[])['diagnostics_enabled']??false)===true):?><a class="btn-link" href="/portal/device_help.php?device=<?= rawurlencode($device['reference']) ?>">Computer checks <?= portal_icon('arrow') ?></a><?php endif;?>
   </div></article>
  <?php endforeach; ?></div><?php if (($devices['next_after'] ?? null) !== null): ?><a href="/portal/devices.php?after=<?= (int)$devices['next_after'] ?>">Next computers <?= portal_icon('arrow') ?></a><?php endif; ?><?php endif; ?>
  <p class="portal-hint">A connected computer is available for support. Antivirus protection and any paid add-ons have their own status.</p>
 </section><aside>
  <section class="portal-device-setup" aria-labelledby="add-device-heading"><h2 id="add-device-heading">Add a device</h2><p>Windows setup takes a few minutes and requires an administrator on that computer.</p>
  <?php if ($manage): ?><form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="enrollment_create"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
   <label class="portal-device-consent"><input type="checkbox" name="consent" value="yes" required><span>I am authorized to add this computer to <?= portal_h($context['binding']['client_name']) ?> and allow the support team to manage it.</span></label>
   <button type="submit" class="btn-primary">Create Windows setup link</button>
  </form><?php else: ?><p>Owners and admins can add devices. Your role can view this business’s device status.</p><?php endif; ?>
  <p class="portal-hint">Adding a device does not order Bitdefender or create a charge.</p><a href="/portal/new.php">Need help with Mac or Linux?</a></section>
  <?php if ($manage && $enrollments !== []): ?><section class="portal-device-links"><h2>Installation links</h2><p class="portal-hint">Recent links for your business. Revoking a link does not remove an installed agent.</p>
  <?php foreach ($enrollments as $grant): ?><article><div><strong><?= portal_h(['ready'=>'Ready to install','enrolled'=>'Computer enrolled','revoked'=>'Link revoked','expired'=>'Link expired'][$grant['state']] ?? 'Unavailable') ?></strong><span><?= portal_h(portal_format_utc($grant['created_at'])) ?></span></div>
   <?php if ($grant['state'] === 'ready'): ?><form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="reference" value="<?= portal_h($grant['reference']) ?>"><button name="action" value="enrollment_download" class="btn-link">Get setup link</button><button name="action" value="enrollment_revoke" class="btn-link">Revoke</button></form><?php endif; ?>
  </article><?php endforeach; ?></section><?php endif; ?>
 </aside></div>
</main>
<?php portal_page_end();
}
