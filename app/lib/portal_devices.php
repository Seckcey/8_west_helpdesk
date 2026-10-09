<?php
/** Customer device service client. Browser input cannot choose the customer or actor. */
declare(strict_types=1);
require_once __DIR__ . '/portal_data.php';
require_once __DIR__ . '/portal_access.php';

const PORTAL_DEVICES_ENDPOINT = 'https://support.8westit.com/api/svc/customer_portal.php';
const PORTAL_DEVICES_CONTEXT = 'safeharbor-customer-devices-v1';
const PORTAL_DEVICES_WRITE_ROLES = ['client_owner', 'client_admin'];

final class PortalDevicesException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 503)
    { parent::__construct($reason); }
}

function portal_devices_config(): array
{
    $c = cfg('portal_devices', []);
    if (!is_array($c) || ($c['enabled'] ?? null) !== true
        || ($c['endpoint'] ?? '') !== PORTAL_DEVICES_ENDPOINT
        || !is_string($c['secret'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $c['secret']) !== 1
        || $c['secret'] === str_repeat('0', 64)) throw new PortalDevicesException('connection_unavailable');
    return $c;
}

function portal_devices_can_manage(array $context): bool
{ return in_array(portal_customer_role($context['identity']??[]), PORTAL_DEVICES_WRITE_ROLES, true); }

function portal_devices_can_operate(array $context): bool
{ return in_array(portal_customer_role($context['identity']??[]), ['client_owner','client_admin','client_staff'], true); }

function portal_devices_scope(PDO $pdo, array $context, ?callable $accessTransport=null): array
{
    $i = $context['identity'] ?? [];
    foreach (['tenant_id', 'client_id', 'binding_id'] as $field) {
        if (!is_int($i[$field] ?? null) || $i[$field] < 1) throw new PortalDevicesException('sign_in', 401);
    }
    if (!in_array(portal_customer_role($i), PORTAL_CLIENT_ROLES, true)
        || !is_string($i['subject'] ?? null) || preg_match('/^t[1-9][0-9]*u[1-9][0-9]*$/D', $i['subject']) !== 1
        || !is_string($i['session_version'] ?? null) || strlen($i['session_version']) > 41
        || !is_string($i['identity_tenant_slug'] ?? null)) throw new PortalDevicesException('sign_in', 401);
    $binding = portal_identity_binding($pdo,$i,$accessTransport);
    if ($binding === null) throw new PortalDevicesException('sign_in', 401);
    $q = $pdo->prepare("SELECT b.customer_id,t.slug FROM suite_customer_sync_bindings b
        JOIN tenants t ON t.id=b.tenant_id WHERE b.tenant_id=? AND b.client_id=? AND b.status='active'");
    $q->execute([$i['tenant_id'], $i['client_id']]); $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new PortalDevicesException('customer_unavailable');
    return ['provider_slug'=>$row['slug'], 'customer_id'=>$row['customer_id'],
        'identity_tenant_slug'=>$binding['identity_tenant_slug'], 'subject'=>$i['subject'],
        'session_version'=>$i['session_version'], 'role'=>portal_customer_role($i)]
        +(isset($i['customer_access'])?['access'=>$i['customer_access']['access']]:[]);
}

/** Bounded HTTPS transport, no redirects, no response-body/credential logging. */
function portal_devices_transport(string $endpoint, string $body, array $headers): array
{
    $ch = curl_init($endpoint); $response = ''; $tooLarge = false;
    $request=json_decode($body,true);
    $timeout=is_array($request) && str_starts_with((string)($request['action']??''),'security_')?90:12;
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$response, &$tooLarge): int {
            if (strlen($response) + strlen($chunk) > 131072) { $tooLarge = true; return 0; }
            $response .= $chunk; return strlen($chunk);
        }]);
    try {
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $tooLarge) throw new PortalDevicesException('service_unavailable');
        return ['status'=>$status, 'body'=>$response];
    } finally { curl_close($ch); }
}

function portal_devices_request(PDO $pdo, array $context, string $action, array $input, ?callable $transport = null, ?callable $accessTransport=null): array
{
    if (!in_array($action, ['devices','enrollments','enrollment_create','enrollment_download','enrollment_revoke',
        'device_access_list','device_access_create','device_access_redeem','device_access_revoke',
        'operations','health_start','temp_start','repair_propose','repair_approve','operation_cancel',
        'security_orders','security_review','security_accept','security_continue','security_install','security_refresh'], true)) {
        throw new PortalDevicesException('invalid_request', 400);
    }
    if (str_starts_with($action,'security_')) {
        if ((cfg('portal_devices',[])['security_orders_enabled']??false)!==true) throw new PortalDevicesException('orders_unavailable');
        if ($action!=='security_orders' && !portal_devices_can_manage($context)) throw new PortalDevicesException('role',403);
    }
    if ((str_starts_with($action, 'enrollment_') || in_array($action,['device_access_list','device_access_create','device_access_revoke'],true))
        && !portal_devices_can_manage($context)) throw new PortalDevicesException('role', 403);
    if (in_array($action,['health_start','temp_start','repair_propose','repair_approve','operation_cancel'],true)
        && !portal_devices_can_operate($context)) throw new PortalDevicesException('role', 403);
    if ($action==='device_access_redeem') {
        if (!in_array(portal_customer_role($context['identity']??[]), ['client_staff','client_viewer'], true)) throw new PortalDevicesException('role',403);
        // A label comes from the verified session. Identity and device authority are never selected by this text.
        $input['display_name'] = mb_strcut(trim((string)($context['identity']['display_name']??'')),0,190,'UTF-8');
    }
    if (in_array($action,['operations','health_start','temp_start','repair_propose','repair_approve','operation_cancel'],true)
        && (cfg('portal_devices',[])['diagnostics_enabled']??false)!==true) throw new PortalDevicesException('operation_unavailable');
    $config = portal_devices_config(); $scope = portal_devices_scope($pdo, $context, $accessTransport);
    $body = json_encode(['action'=>$action,'scope'=>$scope,'input'=>$input], JSON_THROW_ON_ERROR);
    if (strlen($body) > 4096) throw new PortalDevicesException('invalid_request', 400);
    $timestamp = (string)time(); $nonce = bin2hex(random_bytes(16));
    $preimage = PORTAL_DEVICES_CONTEXT . "\nPOST\n/api/svc/customer_portal.php\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    $headers = ['Content-Type: application/json', 'X-Portal-Timestamp: ' . $timestamp, 'X-Portal-Nonce: ' . $nonce,
        'X-Portal-Signature: ' . hash_hmac('sha256', $preimage, $config['secret'])];
    $response = ($transport ?? 'portal_devices_transport')($config['endpoint'], $body, $headers);
    if (portal_devices_scope($pdo, $context, $accessTransport) !== $scope) throw new PortalDevicesException('sign_in', 401);
    try { $data = json_decode($response['body'] ?? '', true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new PortalDevicesException('service_unavailable'); }
    if (($response['status'] ?? 0) !== 200 || !is_array($data) || ($data['ok'] ?? null) !== true) {
        $reason = $data['reason'] ?? 'service_unavailable';
        $safe = ['role','customer_unavailable','customer_access_changed','identity_unavailable','enrollment_limit','enrollment_unavailable','unsupported_platform','installer_unavailable',
            'device_unavailable','device_access_unavailable','device_access_changed',
            'device_offline','operation_unavailable','support_busy','support_status_unavailable','policy_restricted','execution_unresolved','maintenance_active','maintenance_unavailable','rate_limited',
            'fresh_diagnosis_required','approval_expired','approval_changed','repair_cooldown',
            'orders_unavailable','order_unavailable','order_authorization_changed','order_evidence_unavailable',
            'order_service_unavailable','installation_unavailable','installation_busy'];
        throw new PortalDevicesException(in_array($reason, $safe, true) ? $reason : 'service_unavailable');
    }
    if (($data['contract'] ?? '') !== PORTAL_DEVICES_CONTEXT || !is_array($data['result'] ?? null)) throw new PortalDevicesException('service_unavailable');
    $result = $data['result'];
    if ($action === 'enrollment_download') {
        $url = $result['download_url'] ?? null;
        if (!is_string($url) || preg_match('~^https://support\.8westit\.com/download\.php\?t=[A-Za-z0-9_.-]{40,1024}$~D', $url) !== 1) {
            throw new PortalDevicesException('service_unavailable');
        }
    }
    $result=portal_devices_result($action, $result);
    if(in_array($action,['operations','health_start','temp_start','repair_propose'],true)){
        foreach($action==='operations'?$result['items']:[$result] as $operation)if($operation['device_reference']!==($input['device_reference']??null))throw new PortalDevicesException('service_unavailable');
    }
    return $result;
}

function portal_devices_keys(array $value, array $expected): bool
{ $keys=array_keys($value); sort($keys); sort($expected); return $keys===$expected; }

function portal_devices_timestamp(mixed $value): bool
{ return is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$value)===1 && strtotime($value)!==false; }

/** Reject unexpected/malformed projections before they reach the renderer or browser. */
function portal_devices_result(string $action, array $result): array
{
    require_once __DIR__.'/portal_computer_time.php';
    if(str_starts_with($action,'security_')) {
        require_once __DIR__.'/portal_security_orders.php';
        return portal_security_orders_result($action,$result);
    }
    if(in_array($action,['operations','health_start','temp_start','repair_propose','repair_approve','operation_cancel'],true)) {
        require_once __DIR__.'/portal_device_operations.php';
        return portal_device_operations_result($action,$result);
    }
    $fail = static function (): never { throw new PortalDevicesException('service_unavailable'); };
    if (str_starts_with($action,'device_access_')) {
        if ($action==='device_access_list' && (!portal_devices_keys($result,['items','next_after']) || !is_array($result['items'])
            || !array_is_list($result['items']) || count($result['items'])>50
            || ($result['next_after']!==null && (!is_int($result['next_after']) || $result['next_after']<1)))) $fail();
        foreach ($action==='device_access_list' ? $result['items'] : [$result] as $row) {
            $keys=['reference','state','employee','created_at','expires_at'];
            if ($action==='device_access_create') $keys[]='token';
            if (!is_array($row) || !portal_devices_keys($row,$keys) || !is_string($row['reference'])
                || preg_match('/^[a-f0-9]{32}$/D',$row['reference'])!==1 || !in_array($row['state'],['pending','active','revoked','expired'],true)
                || !portal_devices_timestamp($row['created_at']) || !portal_devices_timestamp($row['expires_at'])
                || ($row['employee']!==null && (!is_string($row['employee']) || strlen($row['employee'])>190
                    || preg_match('//u',$row['employee'])!==1 || preg_match('/[\x00-\x1f\x7f]/',$row['employee'])))) $fail();
            if ($action==='device_access_create' && $row['token']!==null && ($row['state']!=='pending'
                || !is_string($row['token']) || preg_match('/^[a-f0-9]{64}$/D',$row['token'])!==1)) $fail();
            if ($action==='device_access_redeem' && $row['state']!=='active') $fail();
            if ($action==='device_access_revoke' && $row['state']!=='revoked') $fail();
        }
        return $result;
    }
    if ($action==='devices') {
        if (!portal_devices_keys($result,['items','next_after']) || !is_array($result['items']) || !array_is_list($result['items'])
            || count($result['items'])>50 || ($result['next_after']!==null && (!is_int($result['next_after']) || $result['next_after']<1))) $fail();
        foreach($result['items'] as &$row) {
            $keys=['reference','label','platform','connection','connection_label','connection_help','last_seen_at','troubleshooting'];
            $optional=is_array($row)?array_intersect_key($row,array_flip(['hardware','terminal_capabilities','computer_time'])):[];
            if (!is_array($row) || !portal_devices_keys(array_diff_key($row,$optional),$keys)
                || !is_string($row['reference']) || preg_match('/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D',$row['reference'])!==1
                || !in_array($row['connection'],['waiting','stale','inventory','reporting'],true) || $row['troubleshooting']!=='support_request'
                || ($row['last_seen_at']!==null && !portal_devices_timestamp($row['last_seen_at']))) $fail();
            if(array_key_exists('computer_time',$row))$row['computer_time']=portal_computer_time_validate($row['computer_time']);
            if(array_key_exists('terminal_capabilities',$row)){
                if(!is_array($row['terminal_capabilities'])||!portal_devices_keys($row['terminal_capabilities'],['user','system']))$fail();
                foreach($row['terminal_capabilities'] as &$capability){
                    if(!is_array($capability)||!portal_devices_keys($capability,['tty_supported','observed_at','expires_at'])||!is_bool($capability['tty_supported']))$fail();
                    if(!$capability['tty_supported']){if($capability['observed_at']!==null||$capability['expires_at']!==null)$fail();continue;}
                    if(!portal_devices_timestamp($capability['observed_at'])||!portal_devices_timestamp($capability['expires_at']))$fail();
                    $observed=strtotime($capability['observed_at']);$expires=strtotime($capability['expires_at']);
                    if($expires<=$observed||$expires>$observed+15)$fail();
                    if($expires<=time()||$observed>time())$capability=['tty_supported'=>false,'observed_at'=>null,'expires_at'=>null];
                }
                unset($capability);
            }
            if(isset($row['hardware'])) {
                $hardware=$row['hardware'];
                if(!is_array($hardware)||!portal_devices_keys($hardware,['ram_gb','observed_at','source'])
                    ||$hardware['source']!=='agent_inventory'||!portal_devices_timestamp($hardware['observed_at'])
                    ||(!is_int($hardware['ram_gb'])&&!is_float($hardware['ram_gb']))||!is_finite((float)$hardware['ram_gb'])
                    ||$hardware['ram_gb']<=0||$hardware['ram_gb']>1048576)$fail();
            }
            foreach(['label'=>128,'platform'=>128,'connection_label'=>100,'connection_help'=>300] as $field=>$limit) {
                if(!is_string($row[$field]) || mb_strlen($row[$field])>$limit || preg_match('//u',$row[$field])!==1) $fail();
            }
        }
        unset($row);
    } else {
        if ($action==='enrollments' && (!portal_devices_keys($result,['items']) || !is_array($result['items'])
            || !array_is_list($result['items']) || count($result['items'])>50)) $fail();
        $rows = $action==='enrollments' ? $result['items'] : [$result];
        foreach($rows as $row) {
            $keys=['reference','state','created_at','expires_at']; if($action==='enrollment_download') $keys[]='download_url';
            if(!is_array($row) || !portal_devices_keys($row,$keys) || !is_string($row['reference'])
                || preg_match('/^[a-f0-9]{32}$/D',$row['reference'])!==1 || !in_array($row['state'],['ready','enrolled','revoked','expired'],true)
                || !portal_devices_timestamp($row['created_at']) || !portal_devices_timestamp($row['expires_at'])) $fail();
        }
    }
    return $result;
}

function portal_devices_error(string $reason): string
{
    return match ($reason) {
        'consent_required' => 'Confirm that you are authorized to add this computer before creating a setup link.',
        'operation_consent' => 'Review the check or repair and tick its confirmation before continuing.',
        'role' => 'Your account does not have permission for this action. Business owners and admins manage computer access; employees can work with their assigned computers and viewers can read their status.',
        'device_unavailable' => 'This computer is not currently assigned to your account or connected to this business. Ask your business owner to check access.',
        'device_access_unavailable' => 'This private computer link is unavailable. It may have expired, been revoked, or been used by another employee. Ask your business owner for a new link.',
        'device_access_changed' => 'That request already belongs to a different computer or account state. Check existing access before creating a new link.',
        'enrollment_limit' => 'This business has created 20 installation links in the last day. Use a current link or contact support for a larger rollout.',
        'enrollment_unavailable' => 'That installation link has expired, was revoked, or has already enrolled a computer. Create a new link to try again.',
        'unsupported_platform' => 'Self-service installation currently supports Windows. Contact support to add a Mac or Linux computer.',
        'installer_unavailable' => 'The verified installer is temporarily unavailable. Contact support or try again shortly.',
        'identity_unavailable', 'sign_in' => 'Your access needs to be checked again. Sign in again or try shortly.',
        'customer_unavailable' => 'Device access is not connected to this business yet. Contact support to complete the connection.',
        'device_offline' => 'This computer needs a recent check-in. Keep it on and connected, then try again.',
        'support_busy' => 'A support workflow blocks this repair. Its ownership or outstanding work needs review; this does not mean a technician is currently using the computer. Health checks and recorded facts remain available.',
        'support_status_unavailable' => 'Current support ownership could not be verified. The repair was not sent. Health checks and recorded facts remain available; try the approval again shortly.',
        'policy_restricted' => 'Your workspace administrator has disabled this action in Westy settings.',
        'execution_unresolved' => 'A previous command on this computer needs a confirmed result. Recorded hardware facts remain available with their capture time.',
        'maintenance_active','maintenance_unavailable' => 'Device checks are paused during maintenance. Try again later or contact support.',
        'fresh_diagnosis_required' => 'Run a new health check before reviewing this repair. The previous observation is too old or no longer applies.',
        'approval_expired','approval_changed' => 'This repair approval is no longer valid. Run a new health check and review the current proposal.',
        'repair_cooldown' => 'A print-service repair was already approved in the last hour. Contact support if printing still does not work.',
        'rate_limited' => 'This business has reached its hourly device-check limit. Contact support if the issue is urgent.',
        'operation_unavailable' => 'A device check cannot start right now. Contact support; there may be unfinished work that needs review.',
        default => 'Device services are temporarily unavailable. Your support requests still work. Try again shortly or contact support.',
    };
}
