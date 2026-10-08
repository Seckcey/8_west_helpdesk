<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../lib/portal_desktop_sessions.php';
$checks=0;
function renewal_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
session_id('renewal-fixture');
$now=time();$pair=str_repeat('a',32);
$original=['subject'=>'t1u2','session_version'=>'1.1','identity_tenant_slug'=>'fixture','role'=>'client_owner','display_name'=>'Synthetic Person',
    'binding_id'=>1,'tenant_id'=>1,'client_id'=>2,'issued_at'=>$now-60,'expires_at'=>$now+3600];
$before=['identity'=>array_replace($original,['expires_at'=>$now+60])];
$after=['identity'=>array_replace($original,['expires_at'=>$now+1800])];
$_SESSION=['desktop_companion_session'=>$pair];
renewal_check(!portal_desktop_same_context($before,$after),'ordinary browser marker cannot renew identity');
$proof=['version'=>1,'pairing_id'=>$pair,'session_hash'=>hash('sha256',session_id()),'identity'=>$original];
$_SESSION['desktop_renewal']=$proof;
renewal_check(!portal_desktop_same_context($before,$after),'uncaptured original provenance cannot be inferred later');
$before=portal_desktop_capture_context($before);
renewal_check(portal_desktop_same_context($before,$after),'timely lease-only renewal retains exact approved identity');
$older=$before;$older['identity']['expires_at']=$now-1;
renewal_check(portal_desktop_same_context($older,$after),'in-flight captured lease may age after a timely renewal');
foreach(['subject'=>'t1u3','session_version'=>'2.1','identity_tenant_slug'=>'other','role'=>'client_user','display_name'=>'Changed',
    'binding_id'=>3,'tenant_id'=>3,'client_id'=>3,'issued_at'=>$now-59,'expires_at'=>$now+3601] as $key=>$value)
    renewal_check(!portal_desktop_same_context($before,['identity'=>array_replace($after['identity'],[$key=>$value])]),'changed identity rejected: '.$key);
renewal_check(!portal_desktop_same_context($before,['identity'=>$after['identity']+['access_generation'=>2]]),'added access-generation field is not dropped');
renewal_check(!portal_desktop_same_context($before,['identity'=>array_replace($after['identity'],['expires_at'=>$now+30])]),'shortened current lease cannot be hidden');
renewal_check(!portal_desktop_same_context($before,['identity'=>array_replace($after['identity'],['expires_at'=>$now-1])]),'expired current lease cannot continue');
foreach(['pairing_id'=>str_repeat('b',32),'session_hash'=>str_repeat('b',64),'identity'=>array_replace($original,['expires_at'=>$now+4000])] as $key=>$value){
    $_SESSION['desktop_renewal']=array_replace($proof,[$key=>$value]);
    renewal_check(!portal_desktop_same_context($before,$after),'changed provenance rejected: '.$key);
}
$_SESSION['desktop_renewal']=$proof;$_SESSION['desktop_companion_session']=str_repeat('b',32);
renewal_check(!portal_desktop_same_context($before,$after),'different pairing marker cannot borrow provenance');
$_SESSION['desktop_companion_session']=$pair;session_id('replacement-fixture');
renewal_check(!portal_desktop_same_context($before,$after),'regenerated or replacement PHP session cannot borrow provenance');
session_id('renewal-fixture');unset($_SESSION['desktop_renewal']);
renewal_check(!portal_desktop_same_context($before,$after),'logout or legacy session missing current provenance cannot renew');
renewal_check(!portal_desktop_same_context($before,$before),'lost approved provenance fails even if identity bytes remain equal');
renewal_check(portal_desktop_same_context(['identity'=>$original],['identity'=>$original]),'unchanged ordinary identity comparison remains exact');
foreach(['Preview my temp files.','Preview user temporary files.','Clean %TEMP%.','Clean $env:TEMP.','Preview temporary files.'] as $task)
    renewal_check(!portal_westy_windows_temp_requested($task),'fixed system recipe refuses user or unspecified temp');
foreach(['Preview Windows system temporary files.','Preview C:\\Windows\\Temp.'] as $task)
    renewal_check(portal_westy_windows_temp_requested($task),'explicit system temp recognized');
echo "PASS $checks renewal identity and cleanup scope assertions\n";
