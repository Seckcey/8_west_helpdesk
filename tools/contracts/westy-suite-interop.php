<?php
/** Optional cross-check against a separate Milepost checkout. No network or live config. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3) exit(2);
$milepost=realpath($argv[1]); $output=realpath($argv[2]);
if (!$milepost || !$output || !is_dir($output)) exit(2);
$files=['bootstrap.php','tenancy.php','westy_workflow.php','westy_workflow_policy.php'];
foreach ($files as $file) if (!is_file($milepost.'/portal/lib/'.$file)) exit(2);
$temp=sys_get_temp_dir().'/safeharbor-interop-'.bin2hex(random_bytes(8));
mkdir($temp.'/lib',0700,true); mkdir($temp.'/config',0700,true);
foreach ($files as $file) copy($milepost.'/portal/lib/'.$file,$temp.'/lib/'.$file);
$secret='public-synthetic-fixture-key-not-a-production-secret';
file_put_contents($temp.'/config/config.php','<?php return '.var_export(['tenancy'=>['mode'=>'pool'],'westy_workflow'=>['enabled'=>true,'hmac_secret'=>$secret]],true).';');
register_shutdown_function(static function() use($temp,$files): void {
    $resolved=realpath($temp); $base=realpath(sys_get_temp_dir());
    if (!$resolved || !$base || !str_starts_with($resolved,$base.DIRECTORY_SEPARATOR) || !str_starts_with(basename($resolved),'safeharbor-interop-')) return;
    foreach ($files as $file) @unlink($temp.'/lib/'.$file);
    @unlink($temp.'/config/config.php'); @rmdir($temp.'/config'); @rmdir($temp.'/lib'); @rmdir($temp);
});
require $temp.'/lib/westy_workflow.php';
// This creates only an in-memory SQLite database and checks the receiver's guards.
require __DIR__.'/../../app/tests/westy_workflow_test.php';
require __DIR__.'/../../app/lib/westy_billing.php';
require __DIR__.'/../../app/lib/coastmark_time_export.php';
$settings['hmac_secret']=$secret;
$w=['id'=>westy_workflow_uuid(),'tenant_slug'=>'msp-one','customer_id'=>$customer,'alert_id'=>90001,'remote_version'=>0];
$manifest=['synthetic_only'=>true,'network_requests'=>0,'public_test_secret'=>$secret,'created_at'=>gmdate('c'),
    'milepost_workflow_sha256'=>hash_file('sha256',$milepost.'/portal/lib/westy_workflow.php'),
    'safeharbor_workflow_sha256'=>hash_file('sha256',__DIR__.'/../../app/lib/westy_workflow.php'),'exchanges'=>[]];
$send=static function(array $w,string $action,array $proof=[]) use($pdo,$settings,$output,&$manifest): array {
    $request=westy_workflow_payload($w,$action,'Synthetic cross-app '.$action.' check.',$proof);
    $body=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $wire=westy_workflow_headers($body); $headers=[];
    foreach ($wire as $line) { [$name,$value]=explode(': ',$line,2); $headers[strtolower($name)]=$value; }
    $auth=['service'=>$headers['x-8w-service'],'timestamp'=>$headers['x-8w-timestamp'],'signature'=>$headers['x-8w-signature']];
    ww_check(westy_workflow_authenticated($settings,$auth,$body),'actual Milepost headers authenticate Safeharbor receiver');
    ww_check(!westy_workflow_authenticated($settings,$auth,$body.' '),'wire tampering refused');
    $r=westy_workflow_receive($pdo,$settings,westy_workflow_request($body),hash('sha256',$body));
    ww_check(westy_workflow_receipt_valid($request,$r),'actual Milepost validates Safeharbor receipt');
    $replay=westy_workflow_receive($pdo,$settings,westy_workflow_request($body),hash('sha256',$body));
    ww_check($r['version']===$replay['version'] && westy_workflow_receipt_valid($request,$replay),'frozen retry retains receipt/version');
    $n=count($manifest['exchanges'])+1; $name=$n.'-'.$action;
    file_put_contents($output.'/'.$name.'.request.json',$body);
    file_put_contents($output.'/'.$name.'.receipt.json',json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    $manifest['exchanges'][]=['name'=>$name,'headers'=>$wire,'request_sha256'=>hash('sha256',$body),'state'=>$r['state'],'version'=>$r['version']];
    return $r;
};
$r=$send($w,'claim'); $w['remote_version']=$r['version'];
$r=$send($w,'status'); $w['remote_version']=$r['version'];
$r=$send($w,'progress'); $w['remote_version']=$r['version'];
// A real positive interval separates synthetic successful-job and alert-recovery facts.
$finished=time(); usleep(1100000); $now=time();
$proof=westy_workflow_recovery(['lineage_valid'=>true,'job_id'=>900,'job_status'=>'done','exit_code'=>0,
    'finished_at'=>gmdate('Y-m-d H:i:s',$finished),'resolved_at'=>gmdate('Y-m-d H:i:s',$now),'last_eval_at'=>gmdate('Y-m-d H:i:s',$now),
    'started_at'=>gmdate('Y-m-d H:i:s',$finished),'alert_status'=>'resolved','breach_level'=>'none','active_same_rule_count'=>0,
    'metric_key'=>'disk_pct','tenant_id'=>1,'agent_id'=>4,'alert_id'=>90001,'script_sha256'=>str_repeat('a',64)],$now);
ww_check($proof!==null,'real Milepost policy produces fresh synthetic proof');
$r=$send($w,'resolve',$proof); $closedTicket=$r['ticket_id'];
$escalation=array_replace($w,['id'=>westy_workflow_uuid(),'alert_id'=>90002,'remote_version'=>0]);
$r=$send($escalation,'claim'); $escalation['remote_version']=$r['version']; $send($escalation,'escalate');

// The second handoff uses the existing real v3 approved-time serializer.
$run=westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=1 AND workflow_key=?',[$w['id']]);
$pdo->exec("ALTER TABLE time_entries ADD COLUMN client_id INTEGER; ALTER TABLE time_entries ADD COLUMN approval_status TEXT; ALTER TABLE time_entries ADD COLUMN billable INTEGER;
CREATE TABLE time_entry_approval_adjustments(tenant_id INTEGER,time_entry_id INTEGER,version_no INTEGER);
CREATE TABLE coastmark_time_export_claims(id INTEGER PRIMARY KEY,tenant_id INTEGER,time_entry_id INTEGER,source_version INTEGER,event_key TEXT,payload_json TEXT,payload_sha256 TEXT);
CREATE TABLE coastmark_time_export_receipts(id INTEGER PRIMARY KEY,tenant_id INTEGER,claim_id INTEGER,outcome TEXT,invoice_id INTEGER);");
$timeKey='safeharbor-time:'.str_repeat('b',32);
$source=coastmark_time_export_payload_for_version($pdo,['id'=>900,'tenant_id'=>1,'tenant_slug'=>'msp-one','customer_id'=>$customer,'entry_key'=>westy_workflow_uuid(),'ticket_id'=>$closedTicket,
    'source'=>'timer','minutes'=>15,'user_id'=>1,'worked_at'=>$run['started_at'],'note'=>'Synthetic interoperability time only.','reviewed_at'=>$run['closed_at'],'reviewed_by_user_id'=>1],0,$timeKey,null);
$sourceBody=json_encode($source,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$pdo->prepare("INSERT INTO time_entries(id,tenant_id,ticket_id,client_id,approval_status,billable) VALUES(900,1,?,1,'approved',1)")->execute([$closedTicket]);
$pdo->prepare('INSERT INTO coastmark_time_export_claims VALUES(1,1,900,0,?,?,?)')->execute([$timeKey,$sourceBody,hash('sha256',$sourceBody)]);
$pdo->exec("INSERT INTO coastmark_time_export_receipts VALUES(1,1,1,'accepted',9)");
$outbox=westy_workflow_row($pdo,'SELECT * FROM westy_billing_outbox WHERE tenant_id=1 AND workflow_id=?',[$run['id']]);
$billingConfig=['enabled'=>true,'endpoint'=>WESTY_BILLING_ENDPOINT,'service'=>'safeharbor-billing','secret'=>$secret,'tenant_slugs'=>['msp-one'],'customer_ids'=>[$customer]];
$frozen=null;
$state=westy_billing_dispatch($pdo,(int)$outbox['id'],$billingConfig,static function(array $config,string $body) use(&$frozen): array { $frozen=$body; return ['status'=>0,'body'=>'']; });
ww_check($state==='uncertain' && is_string($frozen),'actual closed outbox freezes source-backed billing handoff');
file_put_contents($output.'/approved-time-v3.json',$sourceBody);
file_put_contents($output.'/billing-handoff-v1.json',$frozen);
$manifest['billing']=['headers'=>westy_billing_headers($billingConfig,$frozen),'request_sha256'=>hash('sha256',$frozen),'time_sha256'=>hash('sha256',$sourceBody),'source_receipt'=>'fixture prerequisite; import approved-time-v3.json in isolated Coastmark test before handoff'];
$manifest['checks']=$checks;
file_put_contents($output.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo "PASS $checks total Safeharbor and actual Milepost interoperability checks; fixture handoff ready for Coastmark.\n";
