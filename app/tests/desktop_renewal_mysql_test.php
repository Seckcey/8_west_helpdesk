<?php
/** Real PHP-session handoff/identity/binding transitions, synthetic signed-service peer. */
declare(strict_types=1);
ob_start();require __DIR__.'/desktop_handoff_mysql_test.php';
portal_session_start();hs_feed();
$original=hs_identity();$original['expires_at']=time()+3600;
$envelope=portal_desktop_handoff_envelope($original,time());
hs_check($envelope['authority']===$original&&$envelope['identity']['issued_at']===$original['issued_at']
    &&$envelope['identity']['expires_at']<=time()+1800,'approval retains verified original deadline and unchanged issuance before clipping');
$short=$original;$short['expires_at']=time()+90;
hs_check(portal_desktop_handoff_envelope($short,time())['identity']===$short,'shorter issuer lifetime is not extended');
$envelope['identity']['expires_at']=time()+300;$pair=str_repeat('e',32);
$id=portal_desktop_handoff_create($pdo,$pair);hs_approve_fixture($pdo,$id,$envelope);
hs_check(portal_desktop_handoff_take($pdo,$id),'new approved envelope consumes through actual current issuer and binding checks');
$context=portal_desktop_capture_context(portal_authenticated_context($pdo));$proof=$context['desktop_renewal_proof'];
$preserved=count($proof['identity'])===count($original);foreach($original as $key=>$value)$preserved=$preserved&&($proof['identity'][$key]??null)===$value;
hs_check($preserved&&$proof['pairing_id']===$pair&&$proof['session_hash']===hash('sha256',session_id()),'provenance binds original identity exact regenerated PHP session and pair');
$scope=portal_westy_scope($pdo,$context);
$pdo->prepare('INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,expires_at) VALUES(?,?,?,?,?,?)')
    ->execute([$pair,1,10,$original['subject'],$scope['key'],gmdate('Y-m-d H:i:s',time()+300)]);
$pdo->exec("INSERT INTO suite_customer_sync_bindings VALUES(1,1,10,'00000000-0000-4000-8000-000000000010',1,1,'active')");
$config['desktop_companion']=['endpoint'=>PORTAL_DESKTOP_ENDPOINT,'service_secret'=>str_repeat('d',64)];$calls=0;
$localReference='1:'.str_repeat('a',64);
$localDevices=[['reference'=>$localReference,'connection'=>'reporting']];
$localState=['session_id'=>$pair,'expires_at'=>time()+300,'state'=>'paired','connected'=>true,'device_reference'=>$localReference];
$localTransport=static function(string $body)use(&$localState,$pair):array{
    $request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    hs_check($request['action']==='state'&&$request['input']['session_id']===$pair,'local default reads only the exact owned pair');
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DESKTOP_CONTEXT,'ok'=>true,'result'=>$localState])];
};
hs_check(portal_desktop_default_device($pdo,$context,$localDevices,$localTransport)===
    ['default_device_reference'=>$localReference,'local_device_state'=>'ready'],'exact current owned connected reporting computer is the local default');
foreach(['active','stopped'] as $sessionState){
    $localState['state']=$sessionState;
    hs_check(portal_desktop_default_device($pdo,$context,$localDevices,$localTransport)['default_device_reference']===$localReference,
        'selection does not require an executing task or disable the current computer after Stop');
}
$localState['state']='paired';
foreach([
    [['connected'=>false],$localDevices,'offline'],
    [[],[['reference'=>$localReference,'connection'=>'stale']],'offline'],
    [['device_reference'=>'2:'.str_repeat('b',64)],$localDevices,'unavailable'],
    [['session_id'=>str_repeat('b',32)],$localDevices,'unavailable'],
    [['expires_at'=>time()-1],$localDevices,'expired'],
    [['state'=>'revoked'],$localDevices,'unavailable'],
] as [$change,$allowed,$expected]){
    $saved=$localState;$localState=array_replace($saved,$change);
    hs_check(portal_desktop_default_device($pdo,$context,$allowed,$localTransport)===
        ['default_device_reference'=>null,'local_device_state'=>$expected],'offline, changed, unlisted and expired pairs never select another computer');
    $localState=$saved;
}
unset($localState['device_reference']);
hs_check(portal_desktop_default_device($pdo,$context,$localDevices,$localTransport)['local_device_state']==='unavailable','older producer without a verified reference has no inferred local default');
$localState['device_reference']=$localReference;
$ordinary=array_replace($context,['desktop_companion_pair'=>null]);
hs_check(portal_desktop_default_device($pdo,$ordinary,$localDevices,$localTransport)['local_device_state']==='not_companion','ordinary browser does not infer a local pair');
$unbound=array_replace($context,['desktop_companion_pair'=>str_repeat('f',32)]);
hs_check(portal_desktop_default_device($pdo,$unbound,$localDevices,$localTransport)['default_device_reference']===null,'session marker without an owned binding has no authority');
$refused=static fn()=>['status'=>403,'body'=>json_encode(['ok'=>false,'reason'=>'actor_unavailable'])];
hs_check(portal_desktop_default_device($pdo,$context,$localDevices,$refused)['default_device_reference']===null,'current backend refusal cannot expose a default');
$reply=static function(string $body)use(&$calls,$original,$pair):array{
    $calls++;$request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    hs_check($request['action']==='renew'&&$request['input']['authority_expires_at']===$original['expires_at']
        &&$request['input']['issued_at']===$original['issued_at']&&$request['input']['session_id']===$pair,'service receives original deadline exact pairing and issuance');
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DESKTOP_CONTEXT,'ok'=>true,'result'=>[
        'version'=>1,'session_id'=>$pair,'expires_at'=>time()+1800,'authority_expires_at'=>$original['expires_at'],'server_unix'=>time()]])];
};
$result=portal_desktop_renew($pdo,$context,$reply);
hs_check($result['renewed']&&$_SESSION[PORTAL_SESSION_KEY]['expires_at']===$result['expires_at']&&$_SESSION[PORTAL_SESSION_KEY]['issued_at']===$original['issued_at'],'only current lease expiry advances after valid service receipt');
hs_check(strtotime(portal_desktop_binding($pdo,$context,$pair)['expires_at'].' UTC')===$result['expires_at'],'local binding follows exact validated backend lease');
$after=portal_desktop_capture_context(portal_authenticated_context($pdo));
hs_check(portal_desktop_same_context($context,$after),'real reauthenticated context remains equivalent after timely renewal');
$beforeAttach=$_SESSION['desktop_renewal'];$beforeIdentity=$_SESSION[PORTAL_SESSION_KEY];$nextPair=str_repeat('a',32);
$bound=static fn(PDO $p,array $ctx,string $id):array=>['session_id'=>$id,'expires_at'=>time()+1800];
portal_desktop_attach($pdo,$after,$nextPair,$bound);
hs_check($_SESSION['desktop_renewal']===array_replace($beforeAttach,['pairing_id'=>$nextPair])
    &&$_SESSION[PORTAL_SESSION_KEY]===$beforeIdentity&&!isset($_SESSION['desktop_renewal_registered']),
    'reconnect retains exact existing issuer authority and identity while registering only the new pair');
hs_check(portal_desktop_renewal_proof($beforeIdentity)!==null,'reconnected same browser remains eligible for bounded renewal');
$_SESSION['desktop_renewal']=$beforeAttach;$_SESSION['desktop_companion_session']=$pair;
try{portal_desktop_attach($pdo,$after,$nextPair,static function(){throw new PortalDesktopException('pairing_used');});}
catch(PortalDesktopException){}
hs_check($_SESSION['desktop_renewal']===$beforeAttach&&$_SESSION['desktop_companion_session']===$pair,'failed bind cannot move original provenance to a different pair');
foreach([['session_id'=>str_repeat('f',32)],['expires_at'=>time()+9000],['authority_expires_at'=>$original['expires_at']+1],['server_unix'=>time()-31],['version'=>2]] as $change){
    $_SESSION[PORTAL_SESSION_KEY]=$context['identity'];unset($_SESSION['desktop_renewal_registered']);
    $bad=static function($body)use($reply,$change){$response=$reply($body);$wire=json_decode($response['body'],true);$wire['result']=array_replace($wire['result'],$change);$response['body']=json_encode($wire);return $response;};
    hs_check(hs_denied(fn()=>portal_desktop_renew($pdo,$context,$bad))&&$_SESSION[PORTAL_SESSION_KEY]===$context['identity'],'malformed wrong-pair late or overlong service reply cannot extend identity');
}
$_SESSION[PORTAL_SESSION_KEY]=$context['identity'];
$revoked=static function($body)use($reply){$response=$reply($body);hs_feed('2.1');return $response;};
hs_check(hs_denied(fn()=>portal_desktop_renew($pdo,$context,$revoked)),'issuer revocation during service round trip prevents committing renewal');hs_feed();
$_SESSION[PORTAL_SESSION_KEY]=$context['identity'];$_SESSION['desktop_renewal']=$proof;$_SESSION['desktop_companion_session']=$pair;
$beforeCalls=$calls;unset($_SESSION['desktop_renewal']);
hs_check(hs_denied(fn()=>portal_desktop_renew($pdo,$context,$reply))&&$calls===$beforeCalls,'ordinary marker without consumed-handoff provenance never calls renewal service');
$_SESSION['desktop_renewal']=$proof;$_SESSION[PORTAL_SESSION_KEY]['expires_at']=time()-1;
hs_check(hs_denied(fn()=>portal_desktop_renew($pdo,$context,$reply))&&$calls===$beforeCalls,'already expired current PHP session never calls renewal service');
portal_destroy_session();portal_session_start();hs_feed();
$failed=portal_desktop_handoff_create($pdo,str_repeat('f',32));hs_approve_fixture($pdo,$failed,portal_desktop_handoff_envelope($original,time()));
$pdo->exec("CREATE TRIGGER renewal_consume_failure BEFORE UPDATE ON portal_desktop_handoffs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic consume failure'");
try{$failedConsume=false;try{portal_desktop_handoff_take($pdo,$failed);}catch(PDOException){$failedConsume=true;}
    hs_check($failedConsume&&!isset($_SESSION[PORTAL_SESSION_KEY])&&!isset($_SESSION['desktop_companion_session'])
        &&!isset($_SESSION['desktop_renewal'])&&!isset($_SESSION['desktop_renewal_registered']),'failed durable consume clears identity marker and all provenance');
}finally{$pdo->exec('DROP TRIGGER renewal_consume_failure');}
// Signing out of a Companion window: one signed request naming this pairing, and never fatal.
hs_feed();$_SESSION[PORTAL_SESSION_KEY]=hs_identity();$_SESSION['desktop_companion_session']=$pair;$signOuts=[];
$signOutReply=static function(int $status,array $reply)use(&$signOuts):callable{
    return static function(string $body)use(&$signOuts,$status,$reply):array{
        $signOuts[]=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        return ['status'=>$status,'body'=>json_encode($reply,JSON_THROW_ON_ERROR)];
    };
};
$ended=['contract'=>PORTAL_DESKTOP_CONTEXT,'ok'=>true,'result'=>['session_id'=>$pair,'state'=>'revoked']];
hs_check(portal_desktop_sign_out($signOutReply(200,$ended)),'Milepost confirming the pairing ended is reported as ended');
hs_check(count($signOuts)===1&&$signOuts[0]['action']==='sign_out'&&$signOuts[0]['input']===['session_id'=>$pair]
    &&$signOuts[0]['scope']['subject']===hs_identity()['subject'],'sign-out names exactly this pairing and this person');
hs_check(!portal_desktop_sign_out($signOutReply(200,['contract'=>PORTAL_DESKTOP_CONTEXT,'ok'=>true,'result'=>['session_id'=>$pair,'state'=>'paired']])),'a pairing still open is not reported as ended');
hs_check(!portal_desktop_sign_out($signOutReply(400,['ok'=>false,'reason'=>'unsupported_action'])),'a Milepost without the action is unconfirmed, not fatal');
hs_check(!portal_desktop_sign_out(static function():array{throw new PortalDesktopException('connection_unknown');}),'a lost connection is unconfirmed, not fatal');
hs_check(portal_local_identity()!==null,'none of that ends the session by itself: logout.php does, whatever the answer');
unlink($cache.'/'.hash('sha256',"https://id.example.test/oauth/revocations.php\0handoff-fixture").'.json');$signOuts=[];
hs_check(portal_desktop_sign_out($signOutReply(200,$ended))&&count($signOuts)===1,'an unavailable issuer feed cannot hold up signing out: Milepost rechecks the person');
hs_feed();
unset($_SESSION['desktop_companion_session']);$signOuts=[];
hs_check(!portal_desktop_sign_out($signOutReply(200,$ended))&&$signOuts===[],'a session with no pairing sends nothing');
$_SESSION['desktop_companion_session']=$pair;unset($_SESSION[PORTAL_SESSION_KEY]);
hs_check(!portal_desktop_sign_out($signOutReply(200,$ended))&&$signOuts===[],'an ended session cannot name a pairing');
portal_destroy_session();echo "PASS $checks renewable handoff and session database assertions\n";ob_end_flush();
