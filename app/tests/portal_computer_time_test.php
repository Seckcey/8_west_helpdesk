<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_computer_time.php';
require_once __DIR__.'/../lib/portal_devices.php';
require_once __DIR__.'/../lib/portal_westy_device_instructions.php';
$count=0;
function time_check(bool $ok,string $label):void {global $count;$count++;if(!$ok)throw new RuntimeException($label);}
$now=strtotime('2026-07-01T12:00:00Z');
$clock=['schema'=>'milepost.computer_time.v1','timezone'=>'America/Los_Angeles','reported_timezone'=>'Pacific Standard Time',
    'observed_at'=>'2026-07-01T12:00:00Z','received_at'=>'2026-07-01T12:00:00Z','expires_at'=>'2026-07-01T20:00:00Z',
    'server_now'=>'2026-07-01T12:00:00Z','source'=>'agent_inventory'];
time_check(portal_computer_time_validate($clock,$now)===$clock,'valid selected endpoint evidence');
foreach([null,[],array_replace($clock,['timezone'=>'Browser/Local']),array_replace($clock,['observed_at'=>'2026-02-30T12:00:00Z']),
    array_replace($clock,['expires_at'=>'2026-07-02T20:00:00Z']),array_replace($clock,['observed_at'=>'2026-07-01T12:06:00Z']),
    $clock+['unexpected'=>true]] as $bad)time_check(portal_computer_time_validate($bad,$now)===null,'invalid optional clock falls back');
time_check(portal_computer_time_validate($clock,$now+28801)===null,'stale clock falls back');
time_check(portal_computer_time_format('2026-11-01 08:30:00',$clock)==='2026-11-01 01:30:00 -07:00 (America/Los_Angeles)','first repeated DST hour');
time_check(portal_computer_time_format('2026-11-01 09:30:00',$clock)==='2026-11-01 01:30:00 -08:00 (America/Los_Angeles)','second repeated DST hour');
time_check(portal_computer_time_format('2026-07-01',$clock)==='Time unavailable','calendar date unchanged');
$row=['reference'=>'1:'.str_repeat('a',64),'label'=>'Test computer','platform'=>'Windows','connection'=>'reporting',
    'connection_label'=>'Reporting','connection_help'=>'Reported','last_seen_at'=>'2026-07-01T12:00:00Z','troubleshooting'=>'support_request'];
$list=['items'=>[$row],'next_after'=>null];
time_check(portal_devices_result('devices',$list)===$list,'old producer without field unchanged');
$withNull=['items'=>[$row+['computer_time'=>null]],'next_after'=>null];
time_check(portal_devices_result('devices',$withNull)===$withNull,'nullable producer unchanged');
$live=$clock;
foreach(['observed_at','received_at','server_now'] as $key)$live[$key]=gmdate('Y-m-d\TH:i:s\Z');
$live['expires_at']=gmdate('Y-m-d\TH:i:s\Z',time()+28800);
time_check(portal_devices_result('devices',['items'=>[$row+['computer_time'=>$live]],'next_after'=>null])['items'][0]['computer_time']===$live,'real consumer accepts new field');
time_check(portal_devices_result('devices',['items'=>[$row+['computer_time'=>['malformed'=>true]]],'next_after'=>null])['items'][0]['computer_time']===null,'bad clock does not refuse device list');
$result=portal_computer_time_model_devices(['items'=>[$row+['computer_time'=>$clock],array_replace($row,['reference'=>'2:'.str_repeat('b',64)])]],$now);
time_check(str_contains($result['items'][0]['time_display']['current_time'],'05:00:00 -07:00'),'model time deterministic');
time_check(str_contains($result['items'][1]['time_display']['current_time'],'UTC (computer timezone unavailable)'),'other device cannot inherit zone');
time_check($result['items'][0]['last_seen_at']===$row['last_seen_at'],'raw UTC receipt retained');
$before=date_default_timezone_get();date_default_timezone_set('Asia/Tokyo');
time_check(portal_computer_time_model_devices(['items'=>[$row+['computer_time'=>$clock]]],$now)['items'][0]['time_display']===$result['items'][0]['time_display'],'server timezone ignored');date_default_timezone_set($before);
time_check(str_contains(portal_computer_time_instructions(),'SELECTED COMPUTER'),'both provider paths can share selected-computer policy');
function portal_guide_articles():array { return []; }
time_check(str_ends_with(portal_westy_device_instructions(false),portal_computer_time_instructions()),'legacy provider path receives policy once');
time_check(str_ends_with(portal_westy_device_instructions(true),portal_computer_time_instructions()),'general provider path receives policy once');
echo "$count computer time consumer checks passed\n";
