<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/westy_billing.php';
$checks=0;
function wb_check(bool $condition,string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks++; }
function wb_refuses(callable $action,string $name): void { try { $action(); } catch (Throwable) { wb_check(true,$name); return; } wb_check(false,$name); }
$customer='33333333-3333-4333-8333-333333333333';
$config=westy_billing_settings(['enabled'=>true,'endpoint'=>WESTY_BILLING_ENDPOINT,'service'=>'safeharbor-billing','secret'=>str_repeat('fixture-',6),'tenant_slugs'=>['fixture-msp'],'customer_ids'=>[$customer]]);
foreach ([['enabled'=>false],['endpoint'=>'https://evil.example/'],['service'=>'safeharbor-time'],['secret'=>'short'],['tenant_slugs'=>[]],['customer_ids'=>[]]] as $change) wb_refuses(fn()=>westy_billing_settings(array_replace($config,$change)),'separate complete exact-destination gate required');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE tenants(id INTEGER PRIMARY KEY,slug TEXT);
CREATE TABLE tickets(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,status TEXT,resolved_at TEXT,merged_into_id INTEGER);
CREATE TABLE suite_customer_sync_bindings(tenant_id INTEGER,client_id INTEGER,customer_id TEXT,status TEXT);
CREATE TABLE westy_workflows(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,customer_id TEXT,ticket_id INTEGER,workflow_key TEXT,closed_at TEXT,evidence_sha256 TEXT);
CREATE TABLE time_entries(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,ticket_id INTEGER,approval_status TEXT,billable INTEGER);
CREATE TABLE time_entry_approval_adjustments(tenant_id INTEGER,time_entry_id INTEGER,version_no INTEGER);
CREATE TABLE coastmark_time_export_claims(id INTEGER PRIMARY KEY,tenant_id INTEGER,time_entry_id INTEGER,source_version INTEGER,event_key TEXT,payload_json TEXT,payload_sha256 TEXT);
CREATE TABLE coastmark_time_export_receipts(id INTEGER PRIMARY KEY,tenant_id INTEGER,claim_id INTEGER,outcome TEXT,invoice_id INTEGER);
CREATE TABLE westy_billing_outbox(id INTEGER PRIMARY KEY,tenant_id INTEGER,workflow_id INTEGER,event_key TEXT,state TEXT DEFAULT 'waiting_for_time',payload_json TEXT,payload_sha256 TEXT,attempts INTEGER DEFAULT 0,detail_code TEXT,response_json TEXT,next_attempt_at TEXT,last_attempt_at TEXT,created_at TEXT,updated_at TEXT);
INSERT INTO tenants VALUES(1,'fixture-msp');
INSERT INTO tickets VALUES(42,1,7,'resolved','2026-09-07 09:00:00',NULL);");
$pdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES(1,7,?,?)')->execute([$customer,'active']);
$pdo->prepare('INSERT INTO westy_workflows VALUES(1,1,7,?,42,?,?,?)')->execute([$customer,'22222222-2222-4222-8222-222222222222','2026-09-07 09:00:00',str_repeat('a',64)]);
$billingEvent='safeharbor-billing:'.str_repeat('a',32);
$pdo->prepare('INSERT INTO westy_billing_outbox(id,tenant_id,workflow_id,event_key) VALUES(1,1,1,?)')->execute([$billingEvent]);
$calls=[];
$transport=static function(array $config,string $body) use (&$calls,$billingEvent): array {
    $calls[]=$body;
    return ['status'=>201,'body'=>json_encode(['ok'=>true,'action'=>'created','handoff'=>['id'=>4,'event_key'=>$billingEvent,'state'=>'review_required'],
        'invoices'=>[['invoice_id'=>9,'review_url'=>'https://coastmark.8westit.com/invoices/9','status'=>'draft']]])];
};
function wb_due(PDO $pdo): void { $pdo->exec('UPDATE westy_billing_outbox SET next_attempt_at=NULL'); }
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='waiting_for_time' && !$calls,'no invented time or network delivery');
$pdo->exec("INSERT INTO time_entries VALUES(10,1,7,42,'pending',1)"); wb_due($pdo);
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='waiting_for_time' && !$calls,'pending time never reaches billing');
$pdo->exec("UPDATE time_entries SET approval_status='approved'"); wb_due($pdo);
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='waiting_for_time' && !$calls,'unexported approved time waits');
$timeEvent='safeharbor-time:'.str_repeat('b',32);
$source=['version'=>3,'tenant_key'=>'fixture-msp','client_key'=>'milepost-customer:'.$customer,'ticket_id'=>42,'entry_id'=>10,'source_version'=>0,'approval_status'=>'approved','event_key'=>$timeEvent,'worked_at'=>'2026-09-07T08:00:00Z'];
$sourceBody=json_encode($source);
$pdo->prepare('INSERT INTO coastmark_time_export_claims VALUES(1,1,10,0,?,?,?)')->execute([$timeEvent,$sourceBody,hash('sha256',$sourceBody)]);
$pdo->exec("INSERT INTO coastmark_time_export_receipts VALUES(1,1,1,'ambiguous',NULL)"); wb_due($pdo);
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='waiting_for_time' && !$calls,'ambiguous time import waits');
$pdo->exec("INSERT INTO coastmark_time_export_receipts VALUES(2,1,1,'accepted',9)"); wb_due($pdo);
$timeout=static function(array $config,string $body) use (&$calls): array { $calls[]=$body; return ['status'=>0,'body'=>'']; };
wb_check(westy_billing_dispatch($pdo,1,$config,$timeout)==='uncertain','lost ACK recorded uncertain');
$frozen=$pdo->query('SELECT payload_json FROM westy_billing_outbox')->fetchColumn();
$payload=json_decode($frozen,true);
wb_check($payload['time_event_keys']===[$timeEvent] && $payload['verification_sha256']===str_repeat('a',64),'handoff references exact current approved time and closure digest');
wb_check(array_keys($payload)===['version','event','event_key','tenant_key','client_key','ticket_id','run_key','resolved_at','closed_at','verification_sha256','time_event_keys'],'no rates, money, recipients or commands in contract');
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='uncertain' && count($calls)===1,'backoff prevents immediate duplicate requests');
wb_due($pdo);
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='accepted','verified Coastmark review receipt accepted');
wb_check($calls[0]===$calls[1] && $calls[1]===$frozen,'uncertain retry uses byte-identical frozen body and event key');
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='accepted' && count($calls)===2,'accepted handoff never sends again');
wb_check((int)$pdo->query('SELECT attempts FROM westy_billing_outbox')->fetchColumn()===2,'attempt count reflects actual sends');
$good=$transport($config,$frozen);
wb_check(westy_billing_response($good,$billingEvent),'HTTP 201 created exact scoped receipt validates');
$ignored=json_decode($good['body'],true); $ignored['action']='ignored';
wb_check(westy_billing_response(['status'=>200,'body'=>json_encode($ignored)],$billingEvent),'HTTP 200 ignored receipt reconciles exact replay');
wb_check(!westy_billing_response(['status'=>201,'body'=>json_encode($ignored)],$billingEvent),'201 cannot masquerade as replay');
$fixture=__DIR__.'/../../docs/contracts/interop-20260907/';
$realRequest=json_decode(file_get_contents($fixture.'billing-handoff-v1.json'),true,16,JSON_THROW_ON_ERROR);
wb_check(westy_billing_response(['status'=>201,'body'=>file_get_contents($fixture.'coastmark-created-receipt.json')],$realRequest['event_key']),'actual Coastmark receiver fixture agrees with real frozen producer payload');
wb_check(!westy_billing_response($good,'safeharbor-billing:'.str_repeat('c',32)),'wrong receipt event refused');
$bad=json_decode($good['body'],true); $bad['invoices'][0]['review_url']='https://evil.example/invoice';
wb_check(!westy_billing_response(['status'=>200,'body'=>json_encode($bad)],$billingEvent),'untrusted invoice destination refused');
wb_check(!westy_billing_response(['status'=>202,'body'=>$good['body']],$billingEvent),'provider acceptance cannot masquerade as app receipt');
// A later source correction cannot be substituted into a previously frozen request.
$pdo->exec("UPDATE westy_billing_outbox SET state='uncertain',next_attempt_at=NULL");
$pdo->exec('INSERT INTO time_entry_approval_adjustments VALUES(1,10,1)');
$source['source_version']=1; $source['event_key']='safeharbor-time:'.str_repeat('d',32); $sourceBody=json_encode($source);
$pdo->prepare('INSERT INTO coastmark_time_export_claims VALUES(2,1,10,1,?,?,?)')->execute([$source['event_key'],$sourceBody,hash('sha256',$sourceBody)]);
$pdo->exec("INSERT INTO coastmark_time_export_receipts VALUES(3,1,2,'accepted',9)");
$before=count($calls);
wb_check(westy_billing_dispatch($pdo,1,$config,$transport)==='blocked' && count($calls)===$before,'correction after frozen claim becomes exception without send');
wb_check($pdo->query('SELECT payload_json FROM westy_billing_outbox')->fetchColumn()===$frozen,'frozen request preserved for reconciliation');
$run=$pdo->query('SELECT * FROM westy_workflows')->fetch(); $run['tenant_slug']='fixture-msp'; $outbox=$pdo->query('SELECT * FROM westy_billing_outbox')->fetch();
$pdo->exec("UPDATE tickets SET status='open'"); wb_refuses(fn()=>westy_billing_payload($pdo,$run,$outbox,$config),'reopened ticket refuses billing');
$pdo->exec("UPDATE tickets SET status='resolved'"); $pdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive'");
wb_refuses(fn()=>westy_billing_payload($pdo,$run,$outbox,$config),'inactive customer refuses billing');
// Autonomous work with no human time can complete under matching protected coverage.
$pdo->exec("ALTER TABLE westy_workflows ADD COLUMN state TEXT DEFAULT 'resolved'");
$run['state']='resolved';
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='active'; DELETE FROM time_entries;
UPDATE westy_billing_outbox SET state='waiting_for_time',payload_json=NULL,payload_sha256=NULL,response_json=NULL,attempts=0,next_attempt_at=NULL");
$coverage=['enabled'=>true,'coverage_key'=>'44444444-4444-4444-8444-444444444444','coverage_revision'=>1,'service_code'=>'routine_support'];
$includedConfig=$config;
$includedConfig['included_service']=['enabled'=>true,'policies'=>['fixture-msp'=>[$customer=>$coverage]]];
$makeReceipt=static fn(array $payload):array=>['status'=>201,'body'=>json_encode(['ok'=>true,'action'=>'created','completion'=>[
    'id'=>17,'state'=>'included','additional_amount_cents'=>0,'invoice_id'=>null,'source'=>$payload]])];
$includedCalls=[];
$lost=static function(array $config,string $body) use (&$includedCalls):array {$includedCalls[]=$body;return ['status'=>0,'body'=>''];};
wb_check(westy_billing_dispatch($pdo,1,$includedConfig,$lost)==='uncertain','covered service keeps lost receipt uncertain');
$includedPayload=json_decode($includedCalls[0],true);
wb_check($includedPayload['version']===2 && !isset($includedPayload['time_event_keys']),'covered service invents neither time nor invoice');
wb_due($pdo);
$delivered=static function(array $config,string $body) use (&$includedCalls,$makeReceipt):array {$includedCalls[]=$body;return $makeReceipt(json_decode($body,true));};
wb_check(westy_billing_dispatch($pdo,1,$includedConfig,$delivered)==='accepted','covered service completes without manual time approval');
wb_check(count($includedCalls)===2 && $includedCalls[0]===$includedCalls[1],'covered service replays exact frozen bytes');
wb_check($pdo->query('SELECT detail_code FROM westy_billing_outbox')->fetchColumn()==='included_service_recorded','completion has a distinct durable outcome');
wb_check(westy_billing_dispatch($pdo,1,$includedConfig,$delivered)==='accepted' && count($includedCalls)===2,'completed service cannot double-send');
foreach (['tenant_key'=>'other','ticket_id'=>43,'run_key'=>'55555555-5555-4555-8555-555555555555','coverage_revision'=>2,'verification_sha256'=>str_repeat('b',64)] as $key=>$value) {
    wb_check(!westy_billing_response($makeReceipt(array_replace($includedPayload,[$key=>$value])),$billingEvent,$includedPayload),'wrong covered receipt rejected: '.$key);
}
$badReceipt=$makeReceipt($includedPayload); $body=json_decode($badReceipt['body'],true); $body['completion']['additional_amount_cents']=1;
wb_check(!westy_billing_response(['status'=>201,'body'=>json_encode($body)],$billingEvent,$includedPayload),'covered receipt cannot charge money');
wb_check(!westy_billing_response($good,$billingEvent,$includedPayload),'invoice review cannot masquerade as covered completion');
wb_check(westy_billing_response(['status'=>201,'body'=>file_get_contents(__DIR__.'/../../docs/contracts/interop-20260914/coastmark-included-receipt.json')],$billingEvent,$includedPayload),'actual Coastmark v2 receiver receipt matches actual Safeharbor producer facts');
$card=westy_workflow_card(array_merge($run,['state'=>'resolved','summary'=>'Recovery verified','job_id'=>99,'billing_state'=>'accepted','billing_response'=>$makeReceipt($includedPayload)['body']]));
wb_check(str_contains($card,'No extra charge.') && !str_contains($card,'Review time &amp; billing'),'covered completion removes the unnecessary invoice-review step');
$pdo->exec("INSERT INTO time_entries VALUES(50,1,7,42,'approved',0)");
wb_refuses(fn()=>westy_billing_payload($pdo,$run,$outbox,$includedConfig),'human time cannot be silently marked covered');
$pdo->exec('DELETE FROM time_entries');
wb_refuses(fn()=>westy_billing_payload($pdo,array_replace($run,['state'=>'human_owned']),$outbox,$includedConfig),'human takeover prevents a new included-service claim');
$configWrong=$includedConfig; $configWrong['included_service']['policies']=['other'=>[$customer=>$coverage]];
wb_refuses(fn()=>westy_billing_payload($pdo,$run,$outbox,$configWrong),'another tenant coverage cannot authorize work');
if (getenv('WESTY_INCLUDED_FIXTURE_DIR')) {
    $fixtureDir=getenv('WESTY_INCLUDED_FIXTURE_DIR');
    file_put_contents($fixtureDir.'/included-service-v2.json',$includedCalls[0]);
    file_put_contents($fixtureDir.'/included-card.html',$card);
}
echo "PASS $checks Westy billing handoff checks.\n";
