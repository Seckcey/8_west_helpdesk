<?php
/** Connecting a Westy window says why it failed, in plain words, and never names another company. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../lib/portal_desktop_sessions.php';
if(!function_exists('cfg')){function cfg(string $key,mixed $default=null):mixed{$v=$GLOBALS['CONFIG']??[];foreach(explode('.',$key) as $k){if(!is_array($v)||!array_key_exists($k,$v))return $default;$v=$v[$k];}return $v;}}
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
refusal_check(portal_desktop_bind_refusals('preview')===portal_desktop_bind_refusals('bind'),'the sign-in page preview shows the same plain reasons');

// The 30-day Companion sign-in goes only to a handoff carrying the pairing's own proof.
$GLOBALS['CONFIG']=['desktop_companion'=>['service_secret'=>str_repeat('d',64)]];
$pair=str_repeat('a',32);$proof=hash_hmac('sha256','westy-companion-portal-proof-v1:'.$pair,str_repeat('d',64));
refusal_check(portal_desktop_companion_proof_valid($pair,$proof),'the Companion proof for this pairing is accepted');
foreach(['another pairing'=>[str_repeat('b',32),$proof],'no proof'=>[$pair,null],'upper case'=>[$pair,strtoupper($proof)],
    'an array'=>[$pair,[$proof]],'a bad pairing id'=>['x',$proof]] as $case=>[$candidatePair,$candidate])
    refusal_check(!portal_desktop_companion_proof_valid($candidatePair,$candidate),'a wrong proof is refused: '.$case);
$GLOBALS['CONFIG']=['desktop_companion'=>[]];
refusal_check(!portal_desktop_companion_proof_valid($pair,$proof),'without the shared secret no proof is accepted');
$now=time();$web=['subject'=>'t1u2','issued_at'=>$now-3600,'expires_at'=>$now+3600];
$envelope=portal_desktop_handoff_envelope($web,$now);
refusal_check(portal_desktop_handoff_grant($envelope,true)===$envelope,'the Companion keeps the whole 30-day envelope');
$plain=portal_desktop_handoff_grant($envelope,false);
refusal_check($plain['authority']===$web&&$plain['identity']['expires_at']===$now+1800&&!isset($plain['web_authority']),'a plain browser gets the web sign-in deadline');
$legacy=$envelope;unset($legacy['web_authority']);
refusal_check(portal_desktop_handoff_grant($legacy,false)['authority']['expires_at']===$web['issued_at']+PORTAL_SESSION_MAX_SECONDS,
    'an envelope without the web deadline is held to 8 h from issue');
$late=portal_desktop_handoff_envelope(array_replace($web,['expires_at'=>$now+60]),$now);
refusal_check(portal_desktop_handoff_grant($late,false)['identity']['expires_at']===$now+60,'a plain browser lease never outlives its web sign-in');
foreach(['state','renew','start','stop','sign_out','action'] as $action)
    refusal_check(portal_desktop_bind_refusals($action)===[],'other actions keep the uncertain desktop_unavailable: '.$action);
echo "PASS $checks desktop sign-in refusal assertions
";
