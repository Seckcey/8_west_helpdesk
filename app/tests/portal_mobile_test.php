<?php
declare(strict_types=1);
$settings=['portal_mobile'=>['enabled'=>true],'portal_devices'=>['enabled'=>true,'endpoint'=>'https://support.8westit.com/api/svc/customer_portal.php','secret'=>str_repeat('e',64)]];
function cfg(string $key,mixed $default=null):mixed { global $settings; return $settings[$key]??$default; }
function portal_csrf_token():string{return str_repeat('c',64);}
require_once __DIR__.'/../lib/portal_mobile_render.php';
$checks=0;
function pm_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function pm_refused(callable $fn):bool{try{$fn();return false;}catch(PortalDevicesException){return true;}}
$device=['reference'=>'intune:'.str_repeat('a',64),'label'=>'<script>unsafe</script>','platform'=>'ios','provider'=>'intune','os_version'=>'18',
    'ownership'=>'personal','management'=>'managed','compliance'=>'compliant','last_reported_at'=>'2026-10-02T12:00:00Z','status'=>'reported','support'=>'guided','commands'=>[]];
$inventory=['providers'=>['android'=>'setup_required','intune'=>'connected'],'items'=>[$device],'next_offset'=>null];
pm_check(portal_mobile_result('devices',$inventory)===$inventory,'strict minimal mobile inventory');
foreach(['serialNumber'=>'personal identifier','userPrincipalName'=>'private@example.invalid','raw_logs'=>'secret']as$key=>$value){
    $bad=$inventory;$bad['items'][0][$key]=$value;pm_check(pm_refused(fn()=>portal_mobile_result('devices',$bad)),'unexpected personal field refused '.$key);
}
foreach(['commands'=>['wipe'],'platform'=>'windows','reference'=>'1:'.str_repeat('a',64),'provider'=>'android','ownership'=>'maybe','management'=>'rooted','compliance'=>'healthy','last_reported_at'=>'yesterday','status'=>'online']as$key=>$value){
    $bad=$inventory;$bad['items'][0][$key]=$value;pm_check(pm_refused(fn()=>portal_mobile_result('devices',$bad)),'invalid device contract refused '.$key);
}
$plan=['platform'=>'ios','ownership'=>'personal','method'=>'apple_account_driven_user','steps'=>['Prepare your account.','Review the notice.','Approve on the phone.'],
    'privacy'=>'Work data is separate.','offboarding'=>'Remove only managed work data.','requires_device_consent'=>true,'may_require_erase'=>false,
    'automatic_enrollment'=>false,'portal_commands'=>[],'support'=>'Guided help only.','state'=>'administrator_setup_required','account_domain'=>null];
pm_check(portal_mobile_result('enrollment_plan',$plan)===$plan,'closed enrollment guidance');
$bad=$plan;$bad['automatic_enrollment']=true;pm_check(pm_refused(fn()=>portal_mobile_result('enrollment_plan',$bad)),'automatic enrollment refused');
$bad=$plan;$bad['ownership']='company';$bad['method']='apple_automated_device';pm_check(pm_refused(fn()=>portal_mobile_result('enrollment_plan',$bad)),'company erase disclosure cannot be suppressed');
$bad=$plan;$bad['download_url']='https://evil.invalid/profile';pm_check(pm_refused(fn()=>portal_mobile_result('enrollment_plan',$bad)),'arbitrary enrollment redirect refused');
$personalMac=array_replace($plan,['platform'=>'macos','method'=>'macos_company_portal']);
pm_check(portal_mobile_result('enrollment_plan',$personalMac)===$personalMac,'personal Mac user-approved enrollment accepted');
$companyMac=array_replace($personalMac,['ownership'=>'company','method'=>'macos_automated_device','may_require_erase'=>true]);
pm_check(portal_mobile_result('enrollment_plan',$companyMac)===$companyMac,'company Mac corporate enrollment with erase disclosure accepted');
$companyReady=array_replace($companyMac,['state'=>'ready_for_device_consent','account_domain'=>'example.test']);
pm_check(portal_mobile_result('enrollment_plan',$companyReady)===$companyReady,'reviewed company Mac readiness accepted');
foreach(['method'=>'macos_company_portal','may_require_erase'=>false,'automatic_enrollment'=>true,'portal_commands'=>['wipe']]as$key=>$value){
    $bad=$companyMac;$bad[$key]=$value;pm_check(pm_refused(fn()=>portal_mobile_result('enrollment_plan',$bad)),'company Mac boundary '.$key);
}
$bad=$personalMac;$bad['method']='macos_automated_device';pm_check(pm_refused(fn()=>portal_mobile_result('enrollment_plan',$bad)),'personal Mac cannot use corporate enrollment');
$bad=$companyReady;$bad['account_domain']=null;pm_check(pm_refused(fn()=>portal_mobile_result('enrollment_plan',$bad)),'company Mac readiness needs reviewed domain');
$support=['device'=>$device,'steps'=>['Check connection.','Check management app.','Ask support.'],'commands'=>[],'approval_required'=>true];
pm_check(portal_mobile_result('support',$support)===$support,'read-only support contract');
$bad=$support;$bad['commands']=['lock'];pm_check(pm_refused(fn()=>portal_mobile_result('support',$bad)),'support cannot smuggle command');
$context=['identity'=>['role'=>'client_owner','display_name'=>'Alex','subject'=>'t9u11','session_version'=>'1.1'], 'binding'=>['client_name'=>'Test business']];
ob_start();portal_render_mobile($context,$inventory,$plan,null,null);$html=ob_get_clean();
pm_check(str_contains($html,'&lt;script&gt;unsafe&lt;/script&gt;')&&!str_contains($html,'<script>unsafe</script>'),'device labels escaped');
pm_check(str_contains($html,'Show setup guide')&&str_contains($html,'Personal / BYOD')&&str_contains($html,'Company-owned'),'ownership choices rendered');
pm_check(str_contains($html,'not a live connection indicator')&&!str_contains($html,'Create Windows setup link'),'provider status distinct from desktop');
pm_check(str_contains($html,'Removing management later')&&str_contains($html,'ADMINISTRATOR SETUP REQUIRED'),'privacy offboarding and readiness visible');
pm_check(!str_contains($html,'data-portal-chat-prompt="Help me understand &lt;script&gt;'),'untrusted device label never injected into Westy prompt');
$viewer=$context;$viewer['identity']['role']='client_viewer';
ob_start();portal_render_mobile($viewer,$inventory,$plan,null,null);$viewerHtml=ob_get_clean();
pm_check(!str_contains($viewerHtml,'name="action"')&&!str_contains($viewerHtml,'method="post" action="/portal/mobile.php"'),'viewer has no device mutation form');
ob_start();portal_render_mobile($context,$inventory,$companyMac,null,null,'macos','company');$companyHtml=ob_get_clean();
pm_check(str_contains($companyHtml,'Mac · Company device')&&str_contains($companyHtml,'An existing device may need to be erased')&&str_contains($companyHtml,'ADMINISTRATOR SETUP REQUIRED'),'company Mac renders ownership and possible erase without asserting readiness');
ob_start();portal_render_mobile($context,$inventory,$personalMac,null,null,'macos','personal');$personalHtml=ob_get_clean();
pm_check(!str_contains($personalHtml,'An existing device may need to be erased')&&str_contains($personalHtml,'Mac · Personal device'),'personal Mac preserves user-approved presentation');
echo "Portal mobile projection/render checks: $checks passed\n";
