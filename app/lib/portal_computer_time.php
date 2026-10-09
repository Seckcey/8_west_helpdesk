<?php
declare(strict_types=1);

function portal_computer_time_instant(mixed $value): ?int
{
    if (!is_string($value)) return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
    return $date!==false && $date->format('Y-m-d\TH:i:s\Z')===$value ? $date->getTimestamp() : null;
}

/** Optional evidence degrades to explicit UTC, without refusing the device or its tools. */
function portal_computer_time_validate(mixed $value, ?int $now=null): ?array
{
    $now??=time();
    $keys=['schema','timezone','reported_timezone','observed_at','received_at','expires_at','server_now','source'];
    if (!is_array($value) || count($value)!==count($keys) || array_diff($keys,array_keys($value))!==[]
        || $value['schema']!=='milepost.computer_time.v1' || $value['source']!=='agent_inventory'
        || !is_string($value['timezone']) || !in_array($value['timezone'],timezone_identifiers_list(DateTimeZone::ALL_WITH_BC),true)
        || !is_string($value['reported_timezone']) || preg_match('/\A[A-Za-z0-9_+() .\/-]{1,128}\z/D',$value['reported_timezone'])!==1) return null;
    $times=[];
    foreach(['observed_at','received_at','expires_at','server_now'] as $field){
        $times[$field]=portal_computer_time_instant($value[$field]);
        if($times[$field]===null)return null;
    }
    if(abs($times['server_now']-$now)>300 || $times['received_at']>$now+30
        || abs($times['observed_at']-$times['received_at'])>300
        || $times['expires_at']!==$times['received_at']+28800 || $times['expires_at']<=$now) return null;
    return $value;
}

function portal_computer_time_format(mixed $value, ?array $clock): string
{
    if(is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$value))$value=str_replace(' ','T',$value).'Z';
    $at=portal_computer_time_instant($value);
    if($at===null)return 'Time unavailable';
    if($clock===null)return gmdate('Y-m-d H:i:s',$at).' UTC (computer timezone unavailable)';
    return (new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone($clock['timezone']))->format('Y-m-d H:i:s P').' ('.$clock['timezone'].')';
}

/** Adds presentation beside original UTC facts only for the already-scoped computer-list result. */
function portal_computer_time_model_devices(array $result, ?int $now=null): array
{
    $now??=time();
    foreach($result['items']??[] as $index=>$device){
        $clock=portal_computer_time_validate($device['computer_time']??null,$now);
        $result['items'][$index]['time_display']=[
            'current_time'=>portal_computer_time_format(gmdate('Y-m-d\TH:i:s\Z',$now),$clock),
            'last_seen'=>portal_computer_time_format($device['last_seen_at']??null,$clock),
            'timezone_status'=>$clock===null?'unavailable':'last_reported',
            'timezone_observed_at'=>$clock===null?null:portal_computer_time_format($clock['observed_at'],$clock),
        ];
    }
    return $result;
}

function portal_computer_time_instructions(): string
{
    return ' For every human-readable timestamp and time reference use the SELECTED COMPUTER timezone, never the browser, account, tenant or server timezone. '
        .'Resolve the exact selected device reference through list_computers when time is relevant; its computer_time and time_display are recorded endpoint evidence. '
        .'Use time_display for the current time and last check-in; for other timestamps convert the UTC instant with that IANA timezone and its offset at that instant (including daylight saving). '
        .'If no computer is selected, its clock evidence is absent/expired/unavailable, or the zone cannot be resolved, explicitly say the computer timezone is unavailable and label times UTC. '
        .'Never inherit a previous or different computer timezone. This is the last reported timezone, not a live measurement or proof of the device clock being synchronized. '
        .'Do not run a command or start a device job just to find the time. Preserve raw UTC receipts and quoted output; date-only calendar dates are unchanged.';
}
