<?php
declare(strict_types=1);
require_once __DIR__.'/portal_devices_render.php';
require_once __DIR__.'/portal_device_operations.php';

function portal_device_operation_fields(array $device,string $action):void
{ ?>
<input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
<input type="hidden" name="action" value="<?= portal_h($action) ?>">
<input type="hidden" name="device_reference" value="<?= portal_h($device['reference']) ?>">
<?php }

function portal_render_device_help(array $context,array $device,array $operations,?string $error=null):void
{
    $manage=portal_devices_can_manage($context);$available=$operations['available'];
    $items=array_values(array_filter($operations['items'],static fn(array $r):bool=>$r['device_reference']===$device['reference']));
    $pending=false;$health=null;$proposal=false;
    foreach($items as $r) {
        $pending=$pending||in_array($r['state'],['queued','authorized','verifying'],true);
        $proposal=$proposal||$r['state']==='awaiting_approval';
        if($health===null&&$r['recipe']==='health'&&$r['state']==='completed')$health=$r;
    }
    $context['chat_button_placement']='inline';
    portal_page_start('Help with '.$device['label'],'portal-devices-page',$context);
    $self='/portal/device_help.php?device='.rawurlencode($device['reference']);
    ?>
<main class="portal-devices portal-device-help"<?= $pending?' data-operation-pending="true"':'' ?>>
 <a href="/portal/devices.php" class="btn-link">← Your devices</a>
 <header class="portal-devices-heading"><div><p class="portal-eyebrow">WESTY · COMPUTER CHECKS</p><h1><?= portal_h($device['label']) ?></h1><p><?= portal_h($device['platform']) ?> · <?= portal_h($device['connection_label']) ?></p></div><div class="portal-devices-actions"><a class="btn-link" href="<?= portal_h($self) ?>">Refresh status</a><?php portal_westy_button(); ?></div></header>
 <?php if($error!==null):?><div class="portal-device-notice" role="alert"><?= portal_h($error) ?></div><?php endif;?>
 <?php if(!$available):?><div class="portal-device-notice">Computer checks are not available right now. <a href="/portal/new.php">Contact support</a> for help.</div><?php endif;?>
 <div class="portal-devices-layout"><section aria-labelledby="check-heading">
  <?php if($health!==null&&!$pending):?><details class="portal-device-recheck"><summary>Run another health check</summary><?php endif;?>
  <section class="portal-device-setup"><p class="portal-eyebrow">START WITH A CHECK</p><h2 id="check-heading">See what this computer reports</h2><p>Check memory use, free space on the Windows system drive and the print service. This reads system status without changing settings or reading your files.</p>
   <?php if($pending):?><p role="status">A check or approved repair is in progress. Keep the computer on and connected. This page refreshes while you keep it open.</p>
   <?php elseif($manage&&$available&&$device['connection']==='reporting'&&stripos($device['platform'],'windows')!==false):?>
   <form method="post" action="<?= portal_h($self) ?>"><?php portal_device_operation_fields($device,'health_start');?><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>"><label class="portal-device-consent"><input type="checkbox" name="consent" value="yes" required><span>I authorize this health check on <?= portal_h($device['label']) ?>.</span></label><button class="btn-primary">Run health check</button></form>
   <?php elseif(!$manage):?><p class="portal-hint">A business owner or admin can run checks and approve repairs.</p>
   <?php else:?><p class="portal-hint">Checks require a supported Windows computer with a recent check-in. Keep it online, then refresh its status.</p><?php endif;?>
  </section>
  <?php if($health!==null&&!$pending):?></details><?php endif;?>
  <?php if($health!==null):$v=$health['result'];?>
  <section class="portal-device-setup" aria-labelledby="result-heading"><p class="portal-eyebrow">LATEST COMPLETED CHECK</p><h2 id="result-heading">What the computer reported</h2><p class="portal-hint">Observed <?= portal_h(portal_format_utc($v['observed_at'])) ?>. These readings are a snapshot, not a complete diagnosis.</p>
   <dl class="portal-health-readings"><div><dt>Memory in use</dt><dd><?= portal_h((string)$v['memory_used_percent']) ?><span>%</span></dd></div><div><dt>System drive free</dt><dd><?= portal_h((string)$v['system_disk_free_percent']) ?><span>%</span></dd></div><div><dt>Print service</dt><dd class="portal-health-service"><?= portal_h(['running'=>'Running','stopped'=>'Stopped','missing'=>'Unavailable'][$v['spooler']]??'Changing state') ?></dd></div></dl>
   <?php if($v['spooler']==='stopped'):?><p>The Windows print service was stopped. A restart may help printing. Review the exact step and its impact before approving it.</p>
    <?php if($manage&&$available&&!$pending&&!$proposal&&strtotime($v['observed_at'])>=time()-300):?><form method="post" action="<?= portal_h($self) ?>"><?php portal_device_operation_fields($device,'repair_propose');?><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="health_reference" value="<?= portal_h($health['reference']) ?>"><button class="btn-primary">Review print-service repair</button></form><?php elseif(!$pending&&!$proposal):?><p class="portal-hint">Run a fresh health check to review a repair.</p><?php endif;?>
   <?php else:?><p>No automatic repair is offered for these readings. If the problem continues, describe it to Westy or contact support.</p><?php endif;?>
  </section><?php endif;?>
  <?php if($items!==[]):?><section id="operation-history" aria-labelledby="activity-heading"><h2 id="activity-heading">Recent checks and repairs</h2><div class="portal-operation-list">
   <?php foreach(array_slice($items,0,10) as $r):?><article class="portal-device-setup"><h3><?= portal_h($r['title']) ?></h3><p class="portal-hint">Requested <?= portal_h(portal_format_utc($r['created_at'])) ?></p>
    <?php if($r['state']==='awaiting_approval'):?><p class="portal-operation-label">Ready for your review</p><p><?= portal_h($r['impact']) ?></p><p class="portal-hint">Approval applies only to this computer and this repair, and expires <?= portal_h(portal_format_utc($r['expires_at'])) ?>.</p>
     <?php if($manage&&$available&&$r['can_approve']):?><form method="post" action="<?= portal_h($self) ?>"><?php portal_device_operation_fields($device,'repair_approve');?><input type="hidden" name="reference" value="<?= portal_h($r['reference']) ?>"><input type="hidden" name="approval_fingerprint" value="<?= portal_h($r['approval_fingerprint']) ?>"><label class="portal-device-consent"><input type="checkbox" name="consent" value="yes" required><span>I approve restarting the print service on <?= portal_h($device['label']) ?> and the two follow-up health checks described above.</span></label><button class="btn-primary">Approve this repair</button></form><?php else:?><p>The person who requested this repair must approve it using the same sign-in.</p><?php endif;?>
    <?php elseif($r['state']==='service_verified'):?><p class="portal-operation-label is-verified">Print service running on two separate checks</p><p>Try printing now. These checks confirm the service is running; they cannot confirm that your document printed.</p><a href="/portal/new.php">Printing still not working? Contact support</a>
    <?php elseif($r['state']==='needs_help'):?><p class="portal-operation-label">Support review needed</p><p>The outcome could not be verified. A command with an unknown outcome will not be repeated automatically.</p><a href="/portal/new.php">Contact support</a>
    <?php else:?><p role="status"><?= portal_h(match($r['state']){'queued','authorized'=>'Waiting for the computer to complete this request.','verifying'=>'The repair reported completion. Waiting for two separate health checks.','completed'=>'Health check completed.','expired'=>'This approval expired without starting a repair.',default=>'Status unavailable.'}) ?></p><?php endif;?>
   </article><?php endforeach;?></div></section><?php endif;?>
 </section><aside><section class="portal-device-setup"><h2>Need a hand?</h2><p>Westy can explain these controls or help you write a support request. Your chat messages do not approve checks or repairs.</p><button type="button" class="btn-link" data-portal-chat-prompt="Help me understand the computer health checks and how to describe a problem to support.">Ask Westy for help <?= portal_icon('arrow') ?></button><a href="/portal/new.php">Contact support</a></section></aside></div>
</main>
<?php if($pending):?><script src="/assets/js/portal-device-help.js" defer></script><?php endif;?>
<?php portal_page_end();
}
