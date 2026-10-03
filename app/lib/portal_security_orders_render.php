<?php
declare(strict_types=1);
require_once __DIR__.'/portal_render.php';
require_once __DIR__.'/portal_security_orders.php';

function portal_security_order_fields(array $order,string $action): void
{ ?>
<input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="<?= portal_h($action) ?>"><input type="hidden" name="reference" value="<?= portal_h($order['reference']) ?>">
<?php if (in_array($action,['security_accept','security_install'],true)): ?><input type="hidden" name="approval_fingerprint" value="<?= portal_h($order['approval_fingerprint']) ?>"><?php endif;
}

function portal_render_security_orders(array $context,array $device,?array $orders,?string $error=null): void
{
    $manage=portal_devices_can_manage($context);$context['chat_button_placement']='inline';
    $url='/portal/security.php?device='.rawurlencode($device['reference']);
    $canReview=$orders!==null && array_filter($orders,static fn(array $o):bool=>in_array($o['state'],['accepted','accepting'],true)||$o['can_approve'])===[];
    portal_page_start('Secure Plus','portal-devices-page portal-security-page',$context);
    ?>
<main class="portal-devices">
 <a class="btn-link" href="/portal/devices.php">Back to your devices</a>
 <header class="portal-devices-heading"><div><p class="portal-eyebrow">COMPUTER SECURITY</p><h1>Secure Plus</h1><p>Security for <?= portal_h($device['label']) ?></p></div><?php portal_westy_button(); ?></header>
 <?php if($error!==null): ?><div class="portal-device-notice" role="alert"><?= portal_h($error) ?> <a href="/portal/new.php">Contact support</a></div><?php endif; ?>
 <div class="portal-devices-layout"><section aria-labelledby="security-orders-heading">
 <div class="portal-section-title"><h2 id="security-orders-heading">Your order and installation</h2><a href="<?= portal_h($url) ?>" class="btn-link">Refresh page</a></div>
 <?php if($orders===null): ?><p>We cannot retrieve the order status right now. Check again before submitting another order.</p>
 <?php elseif($orders===[]): ?><div class="portal-device-empty"><?= portal_icon('device') ?><h3>No Secure Plus order for this computer</h3><p>Review the price and terms before you decide.</p></div>
 <?php else: ?><div class="portal-device-list"><?php foreach($orders as $order): $install=$order['installation']; ?>
 <article class="portal-device-card"><div class="portal-device-details">
  <p class="portal-eyebrow"><?= portal_h(['planning'=>'PREPARING REVIEW','review'=>'READY FOR YOUR REVIEW','accepting'=>'CONFIRMING YOUR ORDER','accepted'=>'ORDER ACCEPTED','needs_review'=>'SUPPORT REVIEW NEEDED'][$order['state']]) ?></p>
  <h3><?= portal_h($device['label']) ?></h3>
  <?php if($order['offer']!==null): ?><p><strong>$15 USD per month</strong> · one computer</p><?php endif; ?>
  <?php if($order['state']==='review'): ?>
   <p><?= portal_h($order['offer']['terms']) ?></p><p class="portal-hint">Review valid until <?= portal_h(portal_format_utc($order['expires_at'])) ?>.</p>
   <?php if($manage && $order['can_approve']): ?><form method="post" action="<?= portal_h($url) ?>"><?php portal_security_order_fields($order,'security_accept'); ?>
    <label class="portal-device-consent"><input type="checkbox" name="consent" value="yes" required><span>I am authorized to order Secure Plus at $15 USD per month for this computer. I accept these terms and authorize its installation.</span></label>
    <button class="btn-primary" type="submit">Accept $15/month order</button>
   </form><?php endif; ?>
  <?php elseif($order['state']==='accepting'): ?><p>We are confirming whether your order was accepted. Continue this request to check its existing receipt.</p>
  <?php elseif($order['error_code']==='billing_setup_required'): ?><p>Support needs to connect your business’s billing account before you can place this order.</p>
  <?php elseif($order['error_code']==='order_already_exists'): ?><p>An accepted Secure Plus order already exists for this computer. Contact support to review that order.</p>
  <?php endif; ?>
  <?php if($order['state']==='accepted' && $install===null): ?>
   <p><?= portal_h(match($order['gateway_stage']) {
       'company_ready'=>'Your security account is ready. Continue to prepare the installation package.',
       'ready'=>'The installation package is ready for this computer.',
       'unknown','rejected','needs_review'=>'Setup needs support review. No new installation will start from this order.',
       'company_creating','package_creating'=>'Setup is waiting for a confirmed response. Check setup status before continuing.',
       default=>'Your order is recorded. Continue to prepare this computer’s security setup.',
   }) ?></p>
  <?php endif; ?>
  <?php if($install!==null): ?>
   <dl class="portal-security-status"><div><dt>Installation</dt><dd><?= portal_h(match($install['state']) {
       'queued'=>'Waiting for computer','claimed','link_delivered','downloading'=>'Preparing installation','verified','installing'=>'Installing',
       'awaiting_verification'=>'Checking provider enrollment','installed'=>'Provider confirmed','refused'=>'Did not start',default=>'Needs support review',
   }) ?></dd></div><div><dt>Device protection</dt><dd><?= portal_h(['current'=>'Current','attention'=>'Needs attention','unknown'=>'Not yet verified'][$install['protection']]) ?></dd></div>
   <div><dt>MDR enrollment</dt><dd><?= portal_h(['active'=>'Active','inactive'=>'Not active','enrolling'=>'Enrollment in progress','disabling'=>'Being disabled','unknown'=>'Not yet verified'][$install['mdr']]) ?></dd></div></dl>
   <?php if($install['reboot_pending']): ?><p>A restart may be needed. Save your work and contact support if you need help choosing a time.</p><?php endif; ?>
   <?php if($install['state']==='unknown'): ?><p>We could not confirm the installation outcome. Contact support before trying another install.</p><?php endif; ?>
   <p class="portal-hint">An installer finishing does not by itself confirm protection or MDR enrollment.</p>
   <?php if($install['observed_at']!==null): ?><p class="portal-hint">Provider checked <?= portal_h(portal_format_utc($install['observed_at'])) ?>.</p><?php endif; ?>
  <?php endif; ?>
  <?php if($order['expired'] && $install===null && $order['state']!=='accepting'): ?><p>This approval window has ended. Contact support to review the existing order before restarting setup.</p><?php endif; ?>
  <?php if($manage && $order['can_continue']): ?><form method="post" action="<?= portal_h($url) ?>"><?php portal_security_order_fields($order,'security_continue'); ?><button type="submit" class="btn-primary"><?= $order['state']==='accepting'?'Check existing order':'Continue setup' ?></button></form><?php endif; ?>
  <?php if($manage && $order['can_install']): ?><form method="post" action="<?= portal_h($url) ?>"><?php portal_security_order_fields($order,'security_install'); ?>
   <label class="portal-device-consent"><input type="checkbox" name="consent" value="yes" required><span>Start the approved Secure Plus installation on <?= portal_h($device['label']) ?> now. Existing security software will not be removed automatically.</span></label><button type="submit" class="btn-primary">Install on this computer</button></form><?php endif; ?>
  <?php if($manage && $order['can_refresh']): ?><form method="post" action="<?= portal_h($url) ?>"><?php portal_security_order_fields($order,'security_refresh'); ?><button type="submit" class="btn-link">Check setup status</button></form><?php endif; ?>
  <p class="portal-hint">Order <?= portal_h(substr($order['reference'],0,8)) ?> · <?= portal_h(portal_format_utc($order['created_at'])) ?></p>
 </div></article><?php endforeach; ?></div><?php endif; ?>
 </section><aside><section class="portal-device-setup"><p class="portal-eyebrow">SECURE PLUS</p><h2>$15 <small>USD / month</small></h2><p>For one selected Windows computer.</p>
 <ul><li>Antivirus and endpoint protection</li><li>Endpoint detection and response</li><li>MDR Foundations</li></ul>
 <p>Reviewing an offer does not place an order. Accepting records your order; this portal does not collect payment or issue an invoice.</p>
 <?php if($manage && $canReview): ?><form method="post" action="<?= portal_h($url) ?>"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="security_review"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="device_reference" value="<?= portal_h($device['reference']) ?>"><button type="submit" class="btn-primary">Review Secure Plus</button></form>
 <?php elseif(!$manage): ?><p>A business owner or admin can review and place an order.</p><?php endif; ?>
 <p class="portal-hint">Keep the computer online during setup. Support will help if its software or current work prevents installation.</p><a href="/portal/new.php">Ask about this add-on</a></section></aside></div>
</main>
<?php portal_page_end();
}
