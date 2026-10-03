<?php
declare(strict_types=1);
$settings=['portal_devices'=>['enabled'=>true,'security_orders_enabled'=>true]];
function cfg(string $key,mixed $default=null):mixed {global $settings;return $settings[$key]??$default;}
function portal_csrf_token():string{return str_repeat('c',64);}
require_once __DIR__.'/../lib/portal_security_orders_render.php';
$checks=0;
function so_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function so_refused(callable $f):bool{try{$f();return false;}catch(PortalDevicesException){return true;}}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/security_orders/coastmark_secure_plus_v1.json'),true,16,JSON_THROW_ON_ERROR);
$device=['reference'=>'1:'.str_repeat('a',64),'label'=>'<script>Test computer</script>','platform'=>'Windows 11'];
$order=['reference'=>str_repeat('a',32),'state'=>'review','expired'=>false,'device_reference'=>$device['reference'],
    'offer'=>$fixture['quote']['offer'],'gateway_stage'=>null,'created_at'=>'2026-10-03T01:00:00Z','expires_at'=>'2026-10-03T01:10:00Z',
    'can_approve'=>true,'can_install'=>false,'approval_fingerprint'=>str_repeat('b',64),'can_continue'=>false,'can_refresh'=>false,'error_code'=>'','installation'=>null,
    'existing_order'=>false,'superseded'=>false,'can_review_setup'=>false];
so_check(portal_devices_result('security_review',$order)===$order,'closed exact review projection');
so_check(portal_devices_result('security_orders',['items'=>[$order]])===['items'=>[$order]],'closed customer receipt listing');
foreach(['installation_url'=>'https://private.invalid/x','subject'=>'t1u1','plan'=>['command'=>'x'],'package_ref'=>'pkg_private'] as $key=>$value){
    $bad=$order;$bad[$key]=$value;so_check(so_refused(fn()=>portal_devices_result('security_review',$bad)),'extra provider or authority field rejected '.$key);
}
foreach(['unit_price_cents'=>'1500','quantity'=>2,'currency'=>'CAD','billing_interval'=>'year','terms'=>'Changed terms'] as $key=>$value){
    $bad=$order;$bad['offer'][$key]=$value;so_check(so_refused(fn()=>portal_devices_result('security_review',$bad)),'changed commercial fact refused '.$key);
}
$bad=$order;$bad['expired']=true;so_check(so_refused(fn()=>portal_devices_result('security_review',$bad)),'expired order cannot advertise approval');
$ready=array_replace($order,['state'=>'accepted','gateway_stage'=>'ready','can_approve'=>false,'can_install'=>true,'can_refresh'=>true]);
so_check(portal_devices_result('security_install',$ready)===$ready,'verified package remains a separate install step');
$installed=array_replace($ready,['can_install'=>false,'approval_fingerprint'=>null,'installation'=>['state'=>'awaiting_verification','outcome'=>'ok',
    'reboot_pending'=>false,'updated_at'=>'2026-10-03T01:02:00Z','protection'=>'unknown','mdr'=>'unknown','observed_at'=>null]]);
so_check(portal_devices_result('security_refresh',$installed)===$installed,'installer completion does not assert protection');
foreach(['download_too_large','signature_untrusted_or_invalid','signer_not_allowed','installer_exit_nonzero'] as $outcome){
    $bad=$installed;$bad['installation']['outcome']=$outcome;so_check(portal_devices_result('security_refresh',$bad)===$bad,'native outcome vocabulary '.$outcome);
}
$bad=$installed;$bad['installation']['output']='secret';so_check(so_refused(fn()=>portal_devices_result('security_refresh',$bad)),'raw output rejected');
$form=['csrf'=>str_repeat('c',64),'action'=>'security_accept','reference'=>$order['reference'],'approval_fingerprint'=>$order['approval_fingerprint'],'consent'=>'yes'];
so_check(portal_security_order_inputs($form)===['security_accept',array_intersect_key($form,array_flip(['reference','approval_fingerprint']))],'exact consent forwards no browser scope');
$bad=$form;$bad['customer_id']='another';so_check(so_refused(fn()=>portal_security_order_inputs($bad)),'browser customer override rejected');
$bad=$form;$bad['consent']='no';so_check(so_refused(fn()=>portal_security_order_inputs($bad)),'unchecked consent refused');
$context=['identity'=>['role'=>'client_owner','display_name'=>'Alex'], 'binding'=>['client_name'=>'Test business']];
$_SERVER['REQUEST_URI']='/portal/security.php';
ob_start();portal_render_security_orders($context,$device,[$order]);$html=ob_get_clean();
so_check(str_contains($html,'Accept $15/month order') && str_contains($html,'name="consent"') && str_contains($html,'$15 USD per month'),'review names price consent and device');
so_check(!str_contains($html,'<script>Test computer</script>') && str_contains($html,'&lt;script&gt;Test computer&lt;/script&gt;'),'device name escaped');
so_check(str_contains($html,'href="/portal/devices.php" aria-current="page"'),'device navigation remains current');
ob_start();portal_render_security_orders($context,$device,[$ready]);$html=ob_get_clean();
so_check(str_contains($html,'Install on this computer') && !str_contains($html,'Accept $15/month order'),'installation uses a separate explicit confirmation');
ob_start();portal_render_security_orders($context,$device,[$installed]);$html=ob_get_clean();
so_check(substr_count($html,'Not yet verified')===2 && !str_contains($html,'Install on this computer'),'installer completion separates protection and MDR');
$viewer=$context;$viewer['identity']['role']='client_viewer';
ob_start();portal_render_security_orders($viewer,$device,[$ready]);$html=ob_get_clean();
so_check(!str_contains($html,'action="/portal/security.php') && !str_contains($html,'Install on this computer'),'viewer cannot order install or refresh provider evidence');
$unknown=array_replace($installed,['installation'=>array_replace($installed['installation'],['state'=>'unknown'])]);
ob_start();portal_render_security_orders($context,$device,[$unknown]);$html=ob_get_clean();
so_check(str_contains($html,'before trying another install') && !str_contains($html,'Install on this computer'),'unknown outcomes keep new execution unavailable');

$expired=array_replace($ready,['expired'=>true,'can_install'=>false,'can_refresh'=>false,'approval_fingerprint'=>null,'can_review_setup'=>true]);
so_check(portal_devices_result('security_orders',['items'=>[$expired]])===['items'=>[$expired]],'expired order advertises fresh setup only');
ob_start();portal_render_security_orders($context,$device,[$expired]);$html=ob_get_clean();
so_check(str_contains($html,'Review setup for existing order') && !str_contains($html,'Accept $15/month order'),'expired commercial receipt does not offer another purchase');
$recovery=array_replace($order,['existing_order'=>true]);
ob_start();portal_render_security_orders($context,$device,[$recovery,$expired]);$html=ob_get_clean();
so_check(str_contains($html,'Approve setup for existing order') && str_contains($html,'does not place another order')
    && !str_contains($html,'Review setup for existing order') && !str_contains($html,'Accept $15/month order'), 'fresh setup requires its own consent and hides duplicate review');
$superseded=array_replace($expired,['superseded'=>true,'can_review_setup'=>false]);
so_check(portal_devices_result('security_orders',['items'=>[$superseded]])===['items'=>[$superseded]],'superseded approval remains read-only history');
$bad=$superseded;$bad['can_review_setup']=true;
so_check(so_refused(fn()=>portal_devices_result('security_orders',['items'=>[$bad]])),'superseded approval cannot advertise new execution');
ob_start();portal_render_security_orders($context,$device,[$superseded]);$html=ob_get_clean();
so_check(str_contains($html,'earlier setup approval has been replaced') && !str_contains($html,'action="/portal/security.php'),'old approval retains history without action controls');
echo "PASS portal security order contracts and consent: $checks checks\n";
