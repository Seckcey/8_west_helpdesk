<?php
declare(strict_types=1);
require_once __DIR__.'/portal_auth.php';
require_once __DIR__.'/portal_westy.php';
require_once __DIR__.'/portal_westy_desktop.php';

/** Temporary handoff/continuation authority only; no private chat or legacy rows. */
function portal_desktop_prune(PDO $pdo):array
{
    $handoffs=$pdo->exec('DELETE FROM portal_desktop_handoffs WHERE expires_at<=UTC_TIMESTAMP() ORDER BY expires_at LIMIT 100');
    $bindings=$pdo->exec('DELETE FROM portal_desktop_bindings WHERE expires_at<=UTC_TIMESTAMP() ORDER BY expires_at LIMIT 100');
    return ['handoffs'=>$handoffs,'bindings'=>$bindings];
}

function portal_desktop_origin():string
{return portal_desktop_id($_SESSION['desktop_companion_session']??null)?'companion':'portal';}
function portal_desktop_context(PDO $pdo,array $context,string $conversation,?string $operation=null):array
{
    unset($context['desktop']);
    if($operation!==null&&!portal_desktop_id($operation))throw new PortalDesktopException('invalid_request',400);
    $scope=portal_westy_scope($pdo,$context);
    $q=$pdo->prepare('SELECT session_id,task_id,conversation_id,operation_key,origin_channel FROM portal_desktop_bindings WHERE tenant_id=? AND client_id=? AND scope_key=? AND conversation_id=? AND origin_channel=? AND origin_session_hash=? AND expires_at>UTC_TIMESTAMP() AND task_id IS NOT NULL'.($operation===null?'':' AND operation_key=?').' LIMIT 2');
    $parameters=[$scope['tenant'],$scope['client'],$scope['key'],$conversation,portal_desktop_origin(),hash('sha256',session_id())];
    if($operation!==null)$parameters[]=$operation;
    $q->execute($parameters);$tasks=$q->fetchAll(PDO::FETCH_ASSOC);
    // Multiple matches are ambiguous; never choose a computer arbitrarily.
    if(count($tasks)>1)throw new PortalDesktopException('desktop_unavailable',409);
    if(count($tasks)===1)$context['desktop']=$tasks[0];
    return $context;
}
function portal_desktop_bind(PDO $pdo,array $context,string $pair):array
{
    if(!portal_desktop_id($pair))throw new PortalDesktopException('invalid_request',400);
    $state=portal_desktop_request($context,'bind',['session_id'=>$pair]);
    $scope=portal_westy_scope($pdo,$context);
    if(($state['session_id']??null)!==$pair||!is_int($state['expires_at']??null))throw new PortalDesktopException('invalid_response');
    $q=$pdo->prepare('SELECT scope_key FROM portal_desktop_bindings WHERE session_id=?');$q->execute([$pair]);$existing=$q->fetchColumn();
    if($existing!==false&&$existing!==$scope['key'])throw new PortalDesktopException('pairing_used',403);
    if($existing===false)$pdo->prepare('INSERT INTO portal_desktop_bindings(session_id,tenant_id,client_id,subject,scope_key,expires_at) VALUES(?,?,?,?,?,?)')
        ->execute([$pair,$scope['tenant'],$scope['client'],$context['identity']['subject'],$scope['key'],gmdate('Y-m-d H:i:s',$state['expires_at'])]);
    return $state;
}
function portal_desktop_list(PDO $pdo,array $context):array
{
    $scope=portal_westy_scope($pdo,$context);
    $q=$pdo->prepare('SELECT session_id FROM portal_desktop_bindings WHERE tenant_id=? AND client_id=? AND scope_key=? AND expires_at>UTC_TIMESTAMP() ORDER BY expires_at DESC LIMIT 10');
    $q->execute([$scope['tenant'],$scope['client'],$scope['key']]);$items=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        try{$items[]=portal_desktop_request($context,'state',['session_id'=>$id]);}catch(PortalDesktopException){}
    }
    return ['items'=>$items];
}
function portal_desktop_binding(PDO $pdo,array $context,string $id):array
{
    if(!portal_desktop_id($id))throw new PortalDesktopException('invalid_request',400);
    $scope=portal_westy_scope($pdo,$context);
    $q=$pdo->prepare('SELECT * FROM portal_desktop_bindings WHERE session_id=? AND tenant_id=? AND client_id=? AND scope_key=? AND expires_at>UTC_TIMESTAMP()');
    $q->execute([$id,$scope['tenant'],$scope['client'],$scope['key']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new PortalDesktopException('desktop_unavailable');return $row;
}
function portal_desktop_start(PDO $pdo,array $context,array $input):array
{
    if(!portal_devices_keys($input,['session_id','conversation_id','operation_key','request_key','allowed_origins'])
        ||!portal_desktop_id($input['operation_key'])||!portal_desktop_id($input['conversation_id'])||!portal_desktop_id($input['request_key']))throw new PortalDesktopException('invalid_request',400);
    $binding=portal_desktop_binding($pdo,$context,(string)$input['session_id']);
    $scope=portal_westy_scope($pdo,$context);$account=portal_westy_account($pdo,$scope);
    portal_westy_require_conversation($account,$input['conversation_id']);
    $operation=$input['operation_key'];unset($input['operation_key']);
    $latest=$pdo->prepare("SELECT input_text FROM portal_westy_turns WHERE scope_key=? AND conversation_key=? AND operation_key=? AND state='complete' AND input_text IS NOT NULL AND expires_at>UTC_TIMESTAMP()");
    $latest->execute([$scope['key'],$input['conversation_id'],$operation]);$intent=$latest->fetchColumn();
    if(!is_string($intent)||trim($intent)==='')throw new PortalDesktopException('conversation_required',409);
    $origin=portal_desktop_origin();
    if($origin==='companion'&&$_SESSION['desktop_companion_session']!==$binding['session_id'])throw new PortalDesktopException('desktop_unavailable');
    $state=portal_desktop_request($context,'start',$input+['intent'=>$intent,'display_name'=>$context['identity']['display_name'],'origin_channel'=>$origin]);
    if(!portal_desktop_id($state['task_id']??null)||($state['conversation_id']??null)!==$input['conversation_id'])throw new PortalDesktopException('invalid_response');
    $pdo->prepare('UPDATE portal_desktop_bindings SET task_id=?,conversation_id=?,operation_key=?,origin_channel=?,origin_session_hash=? WHERE session_id=? AND scope_key=?')
        ->execute([$state['task_id'],$input['conversation_id'],$operation,$origin,hash('sha256',session_id()),$binding['session_id'],$scope['key']]);
    return $state;
}
function portal_desktop_handoff_create(PDO $pdo,string $pair):string
{
    if(!portal_desktop_id($pair))throw new PortalDesktopException('invalid_request',400);
    portal_session_start();$hash=hash('sha256',session_id());
    $q=$pdo->prepare('SELECT * FROM portal_desktop_handoffs WHERE pairing_id=?');$q->execute([$pair]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if($row){
        if(!hash_equals($row['browser_session_hash'],$hash)||strtotime($row['expires_at'].' UTC')<=time())throw new PortalDesktopException('pairing_used');
        return $row['handoff_id'];
    }
    // Bound unauthenticated session allocation. Pairing id itself is not native authority.
    $q=$pdo->prepare('SELECT COUNT(*) FROM portal_desktop_handoffs WHERE browser_session_hash=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');
    $q->execute([$hash]);if((int)$q->fetchColumn()>=3)throw new PortalDesktopException('pairing_limit',429);
    $id=bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO portal_desktop_handoffs(handoff_id,pairing_id,browser_session_hash,state,created_at,expires_at) VALUES(?,?,?,'pending',UTC_TIMESTAMP(),?)")
        ->execute([$id,$pair,$hash,gmdate('Y-m-d H:i:s',time()+300)]);
    return $id;
}
function portal_desktop_handoff_approve(PDO $pdo,array $context,string $id):void
{
    if(!portal_desktop_id($id))throw new PortalDesktopException('invalid_request',400);
    $q=$pdo->prepare("SELECT * FROM portal_desktop_handoffs WHERE handoff_id=? AND state='pending' AND expires_at>UTC_TIMESTAMP()");$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new PortalDesktopException('pairing_used');
    portal_desktop_bind($pdo,$context,$row['pairing_id']);
    $identity=$context['identity'];$identity['expires_at']=min($identity['expires_at'],time()+1800);
    $pdo->prepare("UPDATE portal_desktop_handoffs SET state='approved',identity_json=? WHERE handoff_id=? AND state='pending' AND expires_at>UTC_TIMESTAMP()")
        ->execute([json_encode($identity,JSON_THROW_ON_ERROR),$id]);
}
function portal_desktop_handoff_take(PDO $pdo,string $id,?callable $revocationCheck=null,?callable $accessTransport=null):bool
{
    if(!portal_desktop_id($id))throw new PortalDesktopException('invalid_request',400);
    portal_session_start();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM portal_desktop_handoffs WHERE handoff_id=? AND browser_session_hash=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
        $q->execute([$id,hash('sha256',session_id())]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new PortalDesktopException('pairing_used');
        if($row['state']==='pending'){$pdo->commit();return false;}
        if($row['state']!=='approved')throw new PortalDesktopException('pairing_used');
        $identity=json_decode($row['identity_json'],true,16,JSON_THROW_ON_ERROR);
        // Mint a new server session from the explicitly approved server identity.
        // No browser cookie, OIDC token or secret is copied into the native process.
        if(!session_regenerate_id(true))throw new PortalDesktopException('sign_in');
        $_SESSION[PORTAL_SESSION_KEY]=$identity;$_SESSION[PORTAL_CSRF_KEY]=bin2hex(random_bytes(32));
        $_SESSION['desktop_companion_session']=$row['pairing_id'];
        if(portal_authenticated_context($pdo,$revocationCheck,null,$accessTransport)===null)throw new PortalDesktopException('sign_in',401);
        $pdo->prepare("UPDATE portal_desktop_handoffs SET state='consumed',identity_json=NULL WHERE handoff_id=?")->execute([$id]);
        $pdo->commit();return true;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();unset($_SESSION[PORTAL_SESSION_KEY],$_SESSION['desktop_companion_session']);throw $error;}
}
