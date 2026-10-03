<?php
/** Customer mobile client. Identities originate in the current authenticated portal binding. */
declare(strict_types=1);
require_once __DIR__.'/portal_devices.php';

const PORTAL_MOBILE_ENDPOINT = 'https://support.8westit.com/api/svc/customer_mobile.php';
const PORTAL_MOBILE_CONTEXT = 'safeharbor-customer-mobile-v1';

function portal_mobile_request(PDO $pdo, array $context, string $action, array $input, ?callable $transport = null): array
{
    if ((cfg('portal_mobile', [])['enabled'] ?? false) !== true) throw new PortalDevicesException('connection_unavailable');
    $keys = match ($action) { 'devices'=>['offset'], 'enrollment_plan'=>['platform','ownership'], 'support'=>['reference'], default=>null };
    if ($keys === null || !portal_devices_keys($input, $keys)) throw new PortalDevicesException('invalid_request', 400);
    if ($action === 'devices' && (!is_int($input['offset']) || $input['offset'] < 0 || $input['offset'] > 1500)) throw new PortalDevicesException('invalid_request', 400);
    if ($action === 'enrollment_plan' && (!in_array($input['platform'], ['android','ios','macos'], true)
        || !in_array($input['ownership'], ['personal','company'], true))) throw new PortalDevicesException('invalid_request', 400);
    if ($action === 'support' && !portal_mobile_reference($input['reference'])) throw new PortalDevicesException('invalid_request', 400);
    $config=portal_devices_config(); $scope=portal_devices_scope($pdo,$context);
    $body=json_encode(['action'=>$action,'scope'=>$scope,'input'=>$input],JSON_THROW_ON_ERROR);
    if(strlen($body)>4096)throw new PortalDevicesException('invalid_request',400);
    $ts=(string)time();$nonce=bin2hex(random_bytes(16));
    $preimage=PORTAL_MOBILE_CONTEXT."\nPOST\n/api/svc/customer_mobile.php\n".$ts."\n".$nonce."\n".hash('sha256',$body);
    $response=($transport??'portal_devices_transport')(PORTAL_MOBILE_ENDPOINT,$body,['Content-Type: application/json',
        'X-Portal-Timestamp: '.$ts,'X-Portal-Nonce: '.$nonce,'X-Portal-Signature: '.hash_hmac('sha256',$preimage,$config['secret'])]);
    if(portal_devices_scope($pdo,$context)!==$scope)throw new PortalDevicesException('sign_in',401);
    try{$data=json_decode($response['body']??'',true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new PortalDevicesException('service_unavailable');}
    if(($response['status']??0)!==200||!is_array($data)||($data['ok']??null)!==true){
        $reason=$data['reason']??'service_unavailable';
        throw new PortalDevicesException(in_array($reason,['customer_unavailable','identity_unavailable','device_unavailable'],true)?$reason:'service_unavailable');
    }
    if(($data['contract']??'')!==PORTAL_MOBILE_CONTEXT||!is_array($data['result']??null))throw new PortalDevicesException('service_unavailable');
    return portal_mobile_result($action,$data['result']);
}

function portal_mobile_reference(mixed $value): bool
{ return is_string($value)&&preg_match('/^(android|intune):[a-f0-9]{64}$/D',$value)===1; }

function portal_mobile_text(mixed $value, int $limit): bool
{ return is_string($value)&&preg_match('//u',$value)===1&&mb_strlen($value)<=$limit&&!preg_match('/[\x00-\x1f\x7f]/u',$value); }

function portal_mobile_device(array $row): bool
{
    if(!portal_devices_keys($row,['reference','label','platform','provider','os_version','ownership','management','compliance','last_reported_at','status','support','commands'])
        ||!portal_mobile_reference($row['reference'])||!portal_mobile_text($row['label'],128)||!portal_mobile_text($row['os_version'],64)
        ||!in_array($row['platform'],['android','ios','macos'],true)||!in_array($row['provider'],['android','intune'],true)
        ||!str_starts_with($row['reference'],$row['provider'].':')||($row['platform']==='android')!==($row['provider']==='android')
        ||!in_array($row['ownership'],['personal','company','unknown'],true)
        ||!in_array($row['management'],['fully_managed','work_profile','unknown','supervised','managed'],true)
        ||!in_array($row['compliance'],['compliant','attention','unknown'],true)
        ||!in_array($row['status'],['reported','not_in_latest_report'],true)||$row['support']!=='guided'||$row['commands']!==[]
        ||($row['last_reported_at']!==null&&!portal_devices_timestamp($row['last_reported_at'])))return false;
    return true;
}

function portal_mobile_result(string $action,array $result): array
{
    $fail=static function():never{throw new PortalDevicesException('service_unavailable');};
    if($action==='devices'){
        if(!portal_devices_keys($result,['providers','items','next_offset'])||!is_array($result['providers'])
            ||!portal_devices_keys($result['providers'],['android','intune'])||!is_array($result['items'])||!array_is_list($result['items'])||count($result['items'])>50
            ||($result['next_offset']!==null&&(!is_int($result['next_offset'])||$result['next_offset']<1||$result['next_offset']>1500)))$fail();
        foreach($result['providers'] as $state)if(!in_array($state,['connected','setup_required','report_unavailable','connection_unavailable','provider_access_required','provider_busy','provider_unavailable','inventory_limit'],true))$fail();
        foreach($result['items'] as $row)if(!is_array($row)||!portal_mobile_device($row))$fail();
    }elseif($action==='enrollment_plan'){
        if(!portal_devices_keys($result,['platform','ownership','method','steps','privacy','offboarding','requires_device_consent','may_require_erase','automatic_enrollment','portal_commands','support','state','account_domain'])
            ||!in_array($result['platform'],['android','ios','macos'],true)||!in_array($result['ownership'],['personal','company'],true)
            ||!in_array($result['method'],['android_work_profile','android_fully_managed','apple_account_driven_user','apple_automated_device','macos_company_portal','macos_automated_device'],true)
            ||!in_array($result['state'],['administrator_setup_required','ready_for_device_consent'],true)||$result['requires_device_consent']!==true||!is_bool($result['may_require_erase'])
            ||$result['automatic_enrollment']!==false||$result['portal_commands']!==[])$fail();
        if($result['account_domain']!==null&&(!is_string($result['account_domain'])||preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$result['account_domain'])!==1))$fail();
        if(($result['state']==='ready_for_device_consent')!==($result['account_domain']!==null))$fail();
        $expected=match($result['platform']){'ios'=>$result['ownership']==='personal'?'apple_account_driven_user':'apple_automated_device','android'=>$result['ownership']==='personal'?'android_work_profile':'android_fully_managed',default=>$result['ownership']==='personal'?'macos_company_portal':'macos_automated_device'};
        if($result['method']!==$expected||($result['platform']==='android'&&$result['state']==='ready_for_device_consent')
            ||$result['may_require_erase']!==($result['ownership']==='company'))$fail();
        foreach(['privacy','offboarding','support'] as $field)if(!portal_mobile_text($result[$field],500))$fail();
        if(!is_array($result['steps'])||!array_is_list($result['steps'])||count($result['steps'])!==3)$fail();
        foreach($result['steps'] as $step)if(!portal_mobile_text($step,400))$fail();
    }elseif($action==='support'){
        if(!portal_devices_keys($result,['device','steps','commands','approval_required'])||!is_array($result['device'])||!portal_mobile_device($result['device'])
            ||$result['commands']!==[]||$result['approval_required']!==true||!is_array($result['steps'])||!array_is_list($result['steps'])||count($result['steps'])!==3)$fail();
        foreach($result['steps'] as $step)if(!portal_mobile_text($step,400))$fail();
    }else $fail();
    return $result;
}

function portal_mobile_provider_label(string $state): string
{
    return match($state){
        'connected'=>'Management reports available', 'setup_required'=>'Business setup needed',
        'provider_access_required'=>'Administrator access needs attention', 'inventory_limit'=>'Inventory needs support review',
        'report_unavailable'=>'Latest report unavailable', default=>'Status temporarily unavailable',
    };
}
