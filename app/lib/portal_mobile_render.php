<?php
declare(strict_types=1);
require_once __DIR__.'/portal_render.php';
require_once __DIR__.'/portal_mobile.php';

function portal_render_mobile(array $context, ?array $inventory, ?array $plan, ?array $support, ?string $error, string $platform='ios', string $ownership='personal'): void
{
    $context['chat_button_placement']='inline';
    $platforms=['ios'=>'iPhone or iPad','android'=>'Android','macos'=>'Mac'];
    portal_page_start('Phones, tablets & Macs','portal-devices-page',$context);
    ?>
<link rel="stylesheet" href="/assets/css/portal-mobile.css?v=1">
<main class="portal-devices portal-mobile">
 <header class="portal-devices-heading"><div><p class="portal-eyebrow">YOUR BUSINESS</p><h1>Phones, tablets &amp; Macs</h1><p>Connect work devices, understand what is managed, and get help.</p></div><div class="portal-devices-actions"><a class="btn-link" href="/portal/mobile.php">Refresh status</a><?php portal_westy_button(); ?></div></header>
 <p><a href="/portal/devices.php">Computer installation &amp; support <?= portal_icon('arrow') ?></a></p>
 <?php if($error!==null): ?><div class="portal-device-notice" role="alert"><?= portal_h($error) ?> <a href="/portal/new.php">Contact support</a></div><?php endif; ?>
 <?php if($inventory!==null): ?><div class="portal-mobile-providers" aria-label="Management connections">
 <?php foreach(['android'=>'Android Enterprise','intune'=>'Microsoft Intune'] as $provider=>$name): ?><div><strong><?= portal_h($name) ?></strong><span><?= portal_h(portal_mobile_provider_label($inventory['providers'][$provider])) ?></span></div><?php endforeach; ?>
 </div><?php endif; ?>
 <?php if($support!==null): ?><section class="portal-device-install" aria-labelledby="mobile-help"><p class="portal-eyebrow">SAFE FIRST STEPS</p><h2 id="mobile-help">Help with <?= portal_h($support['device']['label']) ?></h2><ol><?php foreach($support['steps'] as $step): ?><li><?= portal_h($step) ?></li><?php endforeach; ?></ol><a href="/portal/new.php">Contact your support team</a></section><?php endif; ?>
 <div class="portal-devices-layout"><section aria-labelledby="mobile-devices"><div class="portal-section-title"><h2 id="mobile-devices">Managed devices</h2></div>
 <?php if($inventory===null): ?><p>Device status is unavailable. Your devices have not been changed.</p>
 <?php elseif($inventory['items']===[]): ?><div class="portal-device-empty"><?= portal_icon('device') ?><h3>No reported devices on this page</h3><p>Use the setup guide to see what your business needs. A device appears after its management service reports it.</p></div>
 <?php else: ?><div class="portal-device-list"><?php foreach($inventory['items'] as $device): ?>
 <article class="portal-device-card"><div class="portal-device-symbol"><?= portal_icon('device') ?></div><div class="portal-device-details"><h3><?= portal_h($device['label']) ?></h3><p><?= portal_h($platforms[$device['platform']]) ?><?= $device['os_version']!==''?' · '.portal_h($device['os_version']):'' ?></p>
 <span class="portal-device-state"><?= portal_h($device['status']==='not_in_latest_report'?'Not in latest provider report':(['compliant'=>'Policy checks passed','attention'=>'Policy needs attention','unknown'=>'Policy status unknown'][$device['compliance']])) ?></span>
 <p class="portal-hint"><?= portal_h(['personal'=>'Personal device','company'=>'Company-owned device','unknown'=>'Ownership not confirmed'][$device['ownership']]) ?> · <?= portal_h(['work_profile'=>'Work profile','fully_managed'=>'Full-device management','managed'=>'Managed','supervised'=>'Supervised','unknown'=>'Management type unknown'][$device['management']]) ?></p>
 <p class="portal-hint">Last reported: <?= $device['last_reported_at']===null?'Not available':portal_h(portal_format_utc($device['last_reported_at'])) ?>. This is not a live connection indicator.</p>
 <div class="portal-mobile-actions"><a class="btn-link" href="/portal/mobile.php?reference=<?= rawurlencode($device['reference']) ?>">Safe troubleshooting</a>
 <button type="button" class="btn-link" data-portal-chat-prompt="<?= portal_h('Help me understand '.$platforms[$device['platform']].' device management. The portal reports '.$device['compliance'].' policy status. Explain safe steps; do not erase, retire, lock, or change settings.') ?>">Ask Westy <?= portal_icon('arrow') ?></button></div>
 </div></article><?php endforeach; ?></div>
 <?php if($inventory['next_offset']!==null): ?><a href="/portal/mobile.php?offset=<?= (int)$inventory['next_offset'] ?>">Next devices <?= portal_icon('arrow') ?></a><?php endif; endif; ?>
 <p class="portal-hint">Intune manages Apple enrollment and policy. Milepost Mac-agent monitoring and remote support have their own status.</p>
 </section><aside><section class="portal-device-setup" aria-labelledby="mobile-setup"><h2 id="mobile-setup">Add a device</h2><p>Start with the device type and who owns it.</p>
 <form method="get" action="/portal/mobile.php" class="portal-mobile-form"><div><label for="mobile-platform">Device</label><select id="mobile-platform" name="platform"><?php foreach($platforms as $value=>$label): ?><option value="<?= portal_h($value) ?>"<?= $platform===$value?' selected':'' ?>><?= portal_h($label) ?></option><?php endforeach; ?></select></div>
 <div><label for="mobile-ownership">Ownership</label><select id="mobile-ownership" name="ownership"><option value="personal"<?= $ownership==='personal'?' selected':'' ?>>Personal / BYOD</option><option value="company"<?= $ownership==='company'?' selected':'' ?>>Company-owned</option></select></div><button type="submit" class="btn-primary">Show setup guide</button></form>
 <?php if($plan!==null): ?><div class="portal-mobile-guide"><p class="portal-eyebrow"><?= $plan['state']==='ready_for_device_consent'?'READY FOR YOUR DEVICE APPROVAL':'ADMINISTRATOR SETUP REQUIRED' ?></p><h3><?= portal_h($platforms[$plan['platform']]) ?> · <?= $plan['ownership']==='personal'?'Personal device':'Company device' ?></h3>
 <?php if($plan['account_domain']!==null): ?><p>Use the work account your business provided at <strong><?= portal_h($plan['account_domain']) ?></strong>. Enrollment still needs your approval on the device.</p><?php endif; ?>
 <p><?= portal_h($plan['privacy']) ?></p><?php if($plan['may_require_erase']): ?><p class="portal-device-notice">An existing device may need to be erased for this enrollment method. Contact support first; this page never resets a device.</p><?php endif; ?>
 <ol><?php foreach($plan['steps'] as $step): ?><li><?= portal_h($step) ?></li><?php endforeach; ?></ol>
 <details><summary>Removing management later</summary><p><?= portal_h($plan['offboarding']) ?></p></details>
 <p class="portal-hint"><?= portal_h($plan['support']) ?></p><a href="/portal/new.php">Ask support to prepare enrollment</a></div><?php endif; ?>
 </section></aside></div>
</main>
<?php portal_page_end();
}
