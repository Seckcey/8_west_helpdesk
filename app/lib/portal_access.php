<?php
/** A customer permission is separate from the principal's signed ID role. */
declare(strict_types=1);
require_once __DIR__.'/portal_data.php';

const PORTAL_ACCESS_PENDING_KEY = '_safeharbor_customer_access_pending';

function portal_customer_role(array $identity): string
{ return (string)($identity['customer_access']['role']??$identity['role']??''); }

function portal_access_shape(mixed $choice,array $identity): bool
{
    if(!is_array($choice))return false;
    $keys=array_keys($choice);sort($keys);
    if($keys!==['access','customer_id','identity_tenant_slug','provider_slug','role'])return false;
    $a=$choice['access'];
    if(!is_array($a))return false;
    $keys=array_keys($a);sort($keys);
    return $keys===['generation','identity_role','identity_tenant_slug','reference']
        && is_string($a['reference']) && preg_match('/\A[a-f0-9]{32}\z/D',$a['reference'])===1
        && is_int($a['generation']) && $a['generation']>=1 && $a['generation']<=2147483647
        && $a['identity_tenant_slug']===($identity['identity_tenant_slug']??null)
        && $a['identity_role']===($identity['role']??null)
        && is_string($choice['provider_slug']) && preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/D',$choice['provider_slug'])===1
        && is_string($choice['customer_id']) && preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',$choice['customer_id'])===1
        && is_string($choice['identity_tenant_slug']) && in_array($choice['role'],PORTAL_CLIENT_ROLES,true);
}

function portal_access_request(array $identity,?callable $transport=null): array
{
    require_once __DIR__.'/portal_devices.php';
    $config=portal_devices_config();
    // This array comes only from verified OIDC/server session state, never a form.
    $actor=[];
    foreach(['subject','session_version','identity_tenant_slug','role'] as $key)$actor[$key]=$identity[$key]??null;
    $body=json_encode(['action'=>'access_list','scope'=>$actor,'input'=>[]],JSON_THROW_ON_ERROR);
    $timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
    $preimage=PORTAL_DEVICES_CONTEXT."\nPOST\n/api/svc/customer_portal.php\n".$timestamp."\n".$nonce."\n".hash('sha256',$body);
    $headers=['Content-Type: application/json','X-Portal-Timestamp: '.$timestamp,'X-Portal-Nonce: '.$nonce,
        'X-Portal-Signature: '.hash_hmac('sha256',$preimage,$config['secret'])];
    $response=($transport??'portal_devices_transport')($config['endpoint'],$body,$headers);
    try{$result=json_decode($response['body'],true,12,JSON_THROW_ON_ERROR);}catch(Throwable){throw new PortalDevicesException('customer_access_unavailable');}
    if(($response['status']??0)!==200 || ($result['ok']??null)!==true || ($result['contract']??null)!==PORTAL_DEVICES_CONTEXT
        || !is_array($result['result']??null) || !portal_devices_keys($result['result'],['items'])
        || !is_array($result['result']['items']) || !array_is_list($result['result']['items']) || count($result['result']['items'])>50)
        throw new PortalDevicesException('customer_access_unavailable');
    $items=[];
    foreach($result['result']['items'] as $choice){
        if(!portal_access_shape($choice,$identity))throw new PortalDevicesException('customer_access_unavailable');
        try{portal_identity_tenant_slug($choice['identity_tenant_slug']);}catch(Throwable){throw new PortalDevicesException('customer_access_unavailable');}
        if(isset($items[$choice['access']['reference']]))throw new PortalDevicesException('customer_access_unavailable');
        $items[$choice['access']['reference']]=$choice;
    }
    return array_values($items);
}

/** UUID and provider are authoritative; numeric client IDs never cross applications. */
function portal_access_local_binding(PDO $pdo,array $choice): ?array
{
    $q=$pdo->prepare(portal_binding_select_sql()." JOIN suite_customer_sync_bindings s
        ON s.tenant_id=b.tenant_id AND s.client_id=b.client_id AND s.status='active'
        WHERE t.slug=? AND s.customer_id=? AND b.identity_tenant_slug=? AND b.status='active' LIMIT 2");
    $q->execute([$choice['provider_slug'],$choice['customer_id'],$choice['identity_tenant_slug']]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)return null;
    $b=$rows[0];
    return portal_active_binding_recheck($pdo,(int)$b['id'],$choice['identity_tenant_slug'],(int)$b['tenant_id'],(int)$b['client_id']);
}

function portal_access_choices(PDO $pdo,array $identity,?callable $transport=null): array
{
    $choices=[];
    foreach(portal_access_request($identity,$transport) as $choice){
        $binding=portal_access_local_binding($pdo,$choice);
        if($binding!==null)$choices[]=['access'=>$choice,'binding'=>$binding];
    }
    return $choices;
}

function portal_identity_binding(PDO $pdo,array $identity,?callable $transport=null): ?array
{
    if(!array_key_exists('customer_access',$identity))return portal_active_binding_recheck($pdo,
        $identity['binding_id'],$identity['identity_tenant_slug'],$identity['tenant_id'],$identity['client_id']);
    $selected=$identity['customer_access'];
    if(!portal_access_shape($selected,$identity))return null;
    try{$choices=portal_access_choices($pdo,$identity,$transport);}
    catch(PortalDevicesException $error){throw new PortalIdentityUnavailableException('Customer access validation is temporarily unavailable.',0,$error);}
    foreach($choices as $choice){
        if($choice['access']!=$selected)continue;
        $b=$choice['binding'];
        return (int)$b['id']===$identity['binding_id'] && (int)$b['tenant_id']===$identity['tenant_id']
            && (int)$b['client_id']===$identity['client_id'] ? $b : null;
    }
    return null; // A vanished membership never selects another customer.
}

function portal_access_select(PDO $pdo,array $principal,string $reference,int $generation,?callable $transport=null): array
{
    $selected=null;
    foreach(portal_access_choices($pdo,$principal,$transport) as $choice)
        if($choice['access']['access']['reference']===$reference && $choice['access']['access']['generation']===$generation)$selected=$choice;
    if($selected===null)throw new PortalAuthenticationRejectedException('This customer access is no longer available.');
    $b=$selected['binding'];
    portal_session_start();
    if(!session_regenerate_id(true))throw new PortalAuthException('The customer session could not be rotated.');
    $identity=$principal;
    $identity['binding_id']=(int)$b['id'];$identity['tenant_id']=(int)$b['tenant_id'];$identity['client_id']=(int)$b['client_id'];
    $identity['customer_access']=$selected['access'];
    $_SESSION[PORTAL_SESSION_KEY]=$identity;
    $_SESSION[PORTAL_CSRF_KEY]=bin2hex(random_bytes(32));
    unset($_SESSION[PORTAL_ACCESS_PENDING_KEY],$_SESSION['desktop_companion_session']);
    return $identity;
}
