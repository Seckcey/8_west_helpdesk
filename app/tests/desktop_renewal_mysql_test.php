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
portal_destroy_session();echo "PASS $checks renewable handoff and session database assertions\n";ob_end_flush();
