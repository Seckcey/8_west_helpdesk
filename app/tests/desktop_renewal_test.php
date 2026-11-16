<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../lib/portal_desktop_sessions.php';
$checks=0;
function renewal_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
function hs_renewal_refused(callable $run):bool{try{$run();return false;}catch(PortalDesktopException $error){return $error->reason==='renewal_unavailable';}}
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

// Westy Companion keeps its own sign-in for 30 days from approval: a fixed deadline, never a sliding one.
$browser=array_replace($original,['issued_at'=>$now-7200,'expires_at'=>$now+600]);
$approved=$now-60;$envelope=portal_desktop_handoff_envelope($browser,$approved);
renewal_check($envelope['authority']===array_replace($browser,['expires_at'=>$approved+PORTAL_DESKTOP_AUTHORITY_SECONDS])
    &&$envelope['identity']===array_replace($browser,['expires_at'=>$approved+1800]),
    'approval fixes the Companion deadline 30 days after approval, keeps the issuance and grants a 30-minute lease');
renewal_check(portal_desktop_handoff_envelope($browser,$approved+600)['authority']['expires_at']===$approved+600+PORTAL_DESKTOP_AUTHORITY_SECONDS,
    'the deadline counts from approval, not from when the browser sign-in would end');
$companion=static function(array $authority,int $leaseEnd)use($pair):array{
    session_id('companion-fixture');
    $_SESSION=['desktop_companion_session'=>$pair,'desktop_renewal'=>['version'=>1,'pairing_id'=>$pair,
        'session_hash'=>hash('sha256',session_id()),'identity'=>$authority],
        PORTAL_SESSION_KEY=>array_replace($authority,['expires_at'=>$leaseEnd])];
    return $_SESSION[PORTAL_SESSION_KEY];
};
// Ten days after approval the browser sign-in is long over, but the Companion's own one still holds.
$old=array_replace($original,['issued_at'=>$now-10*86400-3600,'expires_at'=>$now-10*86400-3600+PORTAL_SESSION_MAX_SECONDS]);
$authority=array_replace($old,['expires_at'=>$now-10*86400+PORTAL_DESKTOP_AUTHORITY_SECONDS]);
$lease=$companion($authority,$now+1200);
renewal_check(portal_desktop_renewal_proof($lease)!==null&&portal_desktop_identity_covered($lease),'a 10-day-old Companion sign-in still renews inside its 30 days');
renewal_check(portal_local_identity($now)===$lease,'the Companion session identity stays valid past the 8 h web limit');
unset($_SESSION['desktop_renewal']);
renewal_check(!portal_desktop_identity_covered($lease)&&portal_local_identity($now)===null,'without the approved Companion authority the same old identity is refused');
$lease=$companion($authority,$now+1200);$_SESSION['desktop_companion_session']=str_repeat('b',32);
renewal_check(portal_local_identity($now)===null,'authority for another pairing cannot keep this identity alive');
$lease=$companion($authority,$now+1200);session_id('another-browser');
renewal_check(portal_local_identity($now)===null,'authority copied into another PHP session is refused');
$lease=$companion($authority,$now+1200);$_SESSION[PORTAL_SESSION_KEY]['role']='client_admin';
renewal_check(portal_local_identity($now)===null,'a changed role cannot ride an approved Companion authority');
$lease=$companion($authority,$authority['expires_at']+1);
renewal_check(portal_local_identity($now)===null,'a lease past the fixed deadline is refused');
// At 30 days the sign-in ends: no further renewal, and the person signs in again.
$ended=array_replace($old,['expires_at'=>$now-1]);$lease=$companion($ended,$now+600);
renewal_check(portal_desktop_renewal_proof($lease)===null&&portal_local_identity($now)===null,'renewal is refused once the 30 days have passed');
renewal_check(hs_renewal_refused(fn()=>portal_desktop_renew((new ReflectionClass(PDO::class))->newInstanceWithoutConstructor(),['identity'=>$lease],
    static fn()=>throw new RuntimeException('the service must not be called'))),'a renewal attempt after 30 days fails before calling Milepost');
$tooLong=array_replace($old,['expires_at'=>$old['issued_at']+PORTAL_SESSION_MAX_SECONDS+PORTAL_DESKTOP_AUTHORITY_SECONDS+1]);$lease=$companion($tooLong,$now+600);
renewal_check(portal_desktop_renewal_proof($lease)===null&&!portal_desktop_identity_covered($lease),'an authority longer than the web sign-in plus 30 days is refused');
$_SESSION=['desktop_companion_session'=>$pair];session_id('renewal-fixture');
echo "PASS $checks renewal identity and cleanup scope assertions\n";
