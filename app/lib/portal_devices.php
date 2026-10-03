<?php
/** Customer device service client. Browser input cannot choose the customer or actor. */
declare(strict_types=1);
require_once __DIR__ . '/portal_data.php';

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
{ return in_array($context['identity']['role'] ?? '', PORTAL_DEVICES_WRITE_ROLES, true); }

function portal_devices_scope(PDO $pdo, array $context): array
{
    $i = $context['identity'] ?? [];
    foreach (['tenant_id', 'client_id', 'binding_id'] as $field) {
        if (!is_int($i[$field] ?? null) || $i[$field] < 1) throw new PortalDevicesException('sign_in', 401);
    }
    if (!in_array($i['role'] ?? '', PORTAL_CLIENT_ROLES, true)
        || !is_string($i['subject'] ?? null) || preg_match('/^t[1-9][0-9]*u[1-9][0-9]*$/D', $i['subject']) !== 1
        || !is_string($i['session_version'] ?? null) || strlen($i['session_version']) > 41
        || !is_string($i['identity_tenant_slug'] ?? null)) throw new PortalDevicesException('sign_in', 401);
    $binding = portal_active_binding_recheck($pdo, $i['binding_id'], $i['identity_tenant_slug'], $i['tenant_id'], $i['client_id']);
    if ($binding === null) throw new PortalDevicesException('sign_in', 401);
    $q = $pdo->prepare("SELECT b.customer_id,t.slug FROM suite_customer_sync_bindings b
        JOIN tenants t ON t.id=b.tenant_id WHERE b.tenant_id=? AND b.client_id=? AND b.status='active'");
    $q->execute([$i['tenant_id'], $i['client_id']]); $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new PortalDevicesException('customer_unavailable');
    return ['provider_slug'=>$row['slug'], 'customer_id'=>$row['customer_id'],
        'identity_tenant_slug'=>$i['identity_tenant_slug'], 'subject'=>$i['subject'],
        'session_version'=>$i['session_version'], 'role'=>$i['role']];
}

/** Bounded HTTPS transport, no redirects, no response-body/credential logging. */
function portal_devices_transport(string $endpoint, string $body, array $headers): array
{
    $ch = curl_init($endpoint); $response = ''; $tooLarge = false;
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>12,
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

function portal_devices_request(PDO $pdo, array $context, string $action, array $input, ?callable $transport = null): array
{
    if (!in_array($action, ['devices','enrollments','enrollment_create','enrollment_download','enrollment_revoke'], true)) {
        throw new PortalDevicesException('invalid_request', 400);
    }
    if (str_starts_with($action, 'enrollment_') && !portal_devices_can_manage($context)) throw new PortalDevicesException('role', 403);
    $config = portal_devices_config(); $scope = portal_devices_scope($pdo, $context);
    $body = json_encode(['action'=>$action,'scope'=>$scope,'input'=>$input], JSON_THROW_ON_ERROR);
    if (strlen($body) > 4096) throw new PortalDevicesException('invalid_request', 400);
    $timestamp = (string)time(); $nonce = bin2hex(random_bytes(16));
    $preimage = PORTAL_DEVICES_CONTEXT . "\nPOST\n/api/svc/customer_portal.php\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    $headers = ['Content-Type: application/json', 'X-Portal-Timestamp: ' . $timestamp, 'X-Portal-Nonce: ' . $nonce,
        'X-Portal-Signature: ' . hash_hmac('sha256', $preimage, $config['secret'])];
    $response = ($transport ?? 'portal_devices_transport')($config['endpoint'], $body, $headers);
    if (portal_devices_scope($pdo, $context) !== $scope) throw new PortalDevicesException('sign_in', 401);
    try { $data = json_decode($response['body'] ?? '', true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new PortalDevicesException('service_unavailable'); }
    if (($response['status'] ?? 0) !== 200 || !is_array($data) || ($data['ok'] ?? null) !== true) {
        $reason = $data['reason'] ?? 'service_unavailable';
        $safe = ['role','customer_unavailable','identity_unavailable','enrollment_limit','enrollment_unavailable','unsupported_platform','installer_unavailable'];
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
    return portal_devices_result($action, $result);
}

function portal_devices_keys(array $value, array $expected): bool
{ $keys=array_keys($value); sort($keys); sort($expected); return $keys===$expected; }

function portal_devices_timestamp(mixed $value): bool
{ return is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$value)===1 && strtotime($value)!==false; }

/** Reject unexpected/malformed projections before they reach the renderer or browser. */
function portal_devices_result(string $action, array $result): array
{
    $fail = static function (): never { throw new PortalDevicesException('service_unavailable'); };
    if ($action==='devices') {
        if (!portal_devices_keys($result,['items','next_after']) || !is_array($result['items']) || !array_is_list($result['items'])
            || count($result['items'])>50 || ($result['next_after']!==null && (!is_int($result['next_after']) || $result['next_after']<1))) $fail();
        foreach($result['items'] as $row) {
            if (!is_array($row) || !portal_devices_keys($row,['reference','label','platform','connection','connection_label','connection_help','last_seen_at','troubleshooting'])
                || !is_string($row['reference']) || preg_match('/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D',$row['reference'])!==1
                || !in_array($row['connection'],['waiting','stale','inventory','reporting'],true) || $row['troubleshooting']!=='support_request'
                || ($row['last_seen_at']!==null && !portal_devices_timestamp($row['last_seen_at']))) $fail();
            foreach(['label'=>128,'platform'=>128,'connection_label'=>100,'connection_help'=>300] as $field=>$limit) {
                if(!is_string($row[$field]) || mb_strlen($row[$field])>$limit || preg_match('//u',$row[$field])!==1) $fail();
            }
        }
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
        'role' => 'A business owner or admin can add devices and manage installation links. Your access lets you view device status.',
        'enrollment_limit' => 'This business has created 20 installation links in the last day. Use a current link or contact support for a larger rollout.',
        'enrollment_unavailable' => 'That installation link has expired, was revoked, or has already enrolled a computer. Create a new link to try again.',
        'unsupported_platform' => 'Self-service installation currently supports Windows. Contact support to add a Mac or Linux computer.',
        'installer_unavailable' => 'The verified installer is temporarily unavailable. Contact support or try again shortly.',
        'identity_unavailable', 'sign_in' => 'Your access needs to be checked again. Sign in again or try shortly.',
        'customer_unavailable' => 'Device access is not connected to this business yet. Contact support to complete the connection.',
        default => 'Device services are temporarily unavailable. Your support requests still work. Try again shortly or contact support.',
    };
}
