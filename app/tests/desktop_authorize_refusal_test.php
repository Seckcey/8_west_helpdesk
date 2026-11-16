<?php
/** Connecting a Westy window says why it failed, in plain words, and never names another company. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../lib/portal_desktop_sessions.php';
$checks=0;
function refusal_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}

$wrong=portal_desktop_refusal('wrong_company','8 West Ventures');
refusal_check($wrong['status']===403&&$wrong['message']==="This computer belongs to a different company than 8 West Ventures, the one you're signed in for. Sign in with that company's account.",
    'cross-company sign-in names only the company the person signed in for');
refusal_check(str_contains(portal_desktop_refusal('wrong_company',null)['message'],'than your company,'),'missing company name falls back to plain words');
foreach(['session_unavailable','session_expired','pairing_used','pairing_already_used','invalid_request'] as $reason)
    refusal_check(portal_desktop_refusal($reason,'Alpha')['title']==='This sign-in link expired'
        &&str_contains(portal_desktop_refusal($reason,'Alpha')['message'],'Click Connect again in your Westy window.'),'expired or used link: '.$reason);
refusal_check(str_contains(portal_desktop_refusal('actor_unavailable','Alpha')['message'],'turned off'),'turned-off account is said plainly');
refusal_check(portal_desktop_refusal('device_unavailable','Alpha')['status']===403,'unavailable computer is a clear refusal');
foreach(['unexpected','connection_unavailable','desktop_unavailable','cleanup_unavailable','method'] as $reason)
    refusal_check(portal_desktop_refusal($reason,'Alpha')['status']===503,'unknown or service failure stays a temporary failure: '.$reason);

// Only bind passes the new sign-in reasons through; other actions keep their uncertain mapping.
refusal_check(portal_desktop_bind_refusals('bind')===['wrong_company','session_unavailable','pairing_already_used'],'bind keeps the plain sign-in reasons');
foreach(['state','renew','start','stop','sign_out','action'] as $action)
    refusal_check(portal_desktop_bind_refusals($action)===[],'other actions keep the uncertain desktop_unavailable: '.$action);
echo "PASS $checks desktop sign-in refusal assertions
";
