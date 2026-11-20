<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/westy_billing.php';
require_once __DIR__.'/../lib/westy.php';
$checks=0;
function wb_check(bool $condition,string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks++; }
function wb_refuses(callable $action,string $name): void { try { $action(); } catch (Throwable) { wb_check(true,$name); return; } wb_check(false,$name); }
function wb_error(callable $action,string $code): void {
    try { $action(); } catch (RuntimeException $error) { wb_check($error->getMessage()===$code,'expected '.$code.', got '.$error->getMessage()); return; }
    wb_check(false,'expected '.$code);
}
$customer='33333333-3333-4333-8333-333333333333';
$config=westy_billing_settings(['enabled'=>true,'endpoint'=>WESTY_BILLING_ENDPOINT,'service'=>'safeharbor-billing','secret'=>str_repeat('fixture-',6),'tenant_slugs'=>['fixture-msp'],'customer_ids'=>[$customer]]);
foreach ([['enabled'=>false],['endpoint'=>'https://evil.example/'],['service'=>'safeharbor-time'],['secret'=>'short'],['tenant_slugs'=>[]],['customer_ids'=>[]]] as $change) wb_refuses(fn()=>westy_billing_settings(array_replace($config,$change)),'separate complete exact-destination gate required');
wb_check($config['qa_release_hours'] === 72, 'default review timeout is 72 hours');
foreach ([[12,48], [48,48], ['96',96], [336,336], [500,336], ['99999999999999999999',336], [null,72], ['bad value',72], [false,72]] as [$setting,$expected]) {
    wb_check(westy_billing_settings(array_replace($config,['qa_release_hours'=>$setting]))['qa_release_hours'] === $expected,
        'integer timeout is bounded and invalid settings use the default');
}
function wb_database(): PDO
{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE tenants(id INTEGER PRIMARY KEY,slug TEXT);
CREATE TABLE tickets(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,status TEXT,resolved_at TEXT,merged_into_id INTEGER);
CREATE TABLE suite_customer_sync_bindings(tenant_id INTEGER,client_id INTEGER,customer_id TEXT,status TEXT);
CREATE TABLE westy_workflows(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,customer_id TEXT,ticket_id INTEGER,workflow_key TEXT,closed_at TEXT,evidence_sha256 TEXT,state TEXT DEFAULT 'resolved',alert_resolved_at TEXT);
CREATE TABLE time_entries(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,ticket_id INTEGER,approval_status TEXT,billable INTEGER);
CREATE TABLE time_entry_approval_adjustments(tenant_id INTEGER,time_entry_id INTEGER,version_no INTEGER);
CREATE TABLE coastmark_time_export_claims(id INTEGER PRIMARY KEY,tenant_id INTEGER,time_entry_id INTEGER,source_version INTEGER,event_key TEXT,payload_json TEXT,payload_sha256 TEXT);
CREATE TABLE coastmark_time_export_receipts(id INTEGER PRIMARY KEY,tenant_id INTEGER,claim_id INTEGER,outcome TEXT,invoice_id INTEGER);
CREATE TABLE westy_billing_outbox(id INTEGER PRIMARY KEY,tenant_id INTEGER,workflow_id INTEGER,event_key TEXT,state TEXT DEFAULT 'waiting_for_time',payload_json TEXT,payload_sha256 TEXT,attempts INTEGER DEFAULT 0,detail_code TEXT,response_json TEXT,next_attempt_at TEXT,last_attempt_at TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE westy_workflow_log_state(tenant_id INTEGER,workflow_id INTEGER,qa_state TEXT,qa_reason TEXT,log_sha256 TEXT,log_sealed_seq INTEGER);
CREATE TABLE westy_workflow_v4_receipts(tenant_id INTEGER,workflow_id INTEGER,action TEXT,response_json TEXT,request_sha256 TEXT,received_at TEXT);
CREATE TABLE ticket_work_log(tenant_id INTEGER,workflow_id INTEGER,ticket_id INTEGER,source TEXT,kind TEXT,seq INTEGER,milepost_ref TEXT,summary TEXT,cause_code TEXT);");
    return $pdo;
}
$pdo=wb_database();
$pdo->exec("INSERT INTO tenants VALUES(1,'fixture-msp');
INSERT INTO tickets VALUES(42,1,7,'resolved','2026-09-07 09:00:00',NULL);");
$pdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES(1,7,?,?)')->execute([$customer,'active']);
$pdo->prepare('INSERT INTO westy_workflows(id,tenant_id,client_id,customer_id,ticket_id,workflow_key,closed_at,evidence_sha256) VALUES(1,1,7,?,42,?,?,?)')->execute([$customer,'22222222-2222-4222-8222-222222222222','2026-09-07 09:00:00',str_repeat('a',64)]);
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
// Replay the original interop input with AI enabled for someone else.
$vectorCustomer=substr($realRequest['client_key'],strlen('milepost-customer:'));
$vectorConfig=array_replace($config,['tenant_slugs'=>[$realRequest['tenant_key']],'customer_ids'=>[$vectorCustomer],
    'ai_service'=>['enabled'=>true,'customer_ids'=>[$customer]]]);
$vectorTime='2026-09-07 09:26:44';
$pdo->beginTransaction();
$pdo->prepare('UPDATE tickets SET id=?,resolved_at=?')->execute([$realRequest['ticket_id'],$vectorTime]);
$pdo->prepare('UPDATE suite_customer_sync_bindings SET customer_id=?')->execute([$vectorCustomer]);
$pdo->prepare('UPDATE time_entries SET ticket_id=?')->execute([$realRequest['ticket_id']]);
$vectorSource=json_encode(array_replace($source,array_intersect_key($realRequest,array_flip(['tenant_key','client_key','ticket_id']))));
$pdo->prepare('UPDATE coastmark_time_export_claims SET payload_json=?,payload_sha256=?')->execute([$vectorSource,hash('sha256',$vectorSource)]);
$vectorRun=['tenant_id'=>1,'tenant_slug'=>$realRequest['tenant_key'],'client_id'=>7,'customer_id'=>$vectorCustomer,'ticket_id'=>$realRequest['ticket_id'],
    'workflow_key'=>$realRequest['run_key'],'closed_at'=>$vectorTime,'evidence_sha256'=>$realRequest['verification_sha256']];
$v1=westy_billing_payload($pdo,$vectorRun,['event_key'=>$realRequest['event_key']],$vectorConfig);
wb_check(json_encode($v1,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)===trim(file_get_contents($fixture.'billing-handoff-v1.json'))
    && westy_billing_response(['status'=>201,'body'=>file_get_contents($fixture.'coastmark-created-receipt.json')],$realRequest['event_key'],$v1),
    'other-customer AI switch preserves the original v1 bytes and receipt');
$pdo->rollBack();
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
$receiptV2=file_get_contents(__DIR__.'/../../docs/contracts/interop-20260914/coastmark-included-receipt.json');
$otherAi=array_replace($includedConfig,['ai_service'=>['enabled'=>true,'customer_ids'=>[$vectorCustomer]]]);
$v2=westy_billing_payload($pdo,$run,$outbox,$otherAi);
wb_check(json_encode($v2,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)===json_encode(json_decode($receiptV2,true)['completion']['source'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)
    && westy_billing_response(['status'=>201,'body'=>$receiptV2],$billingEvent,$v2),'other-customer AI switch preserves the original v2 bytes and receipt');
$card=westy_workflow_card(array_merge($run,['state'=>'resolved','summary'=>'Recovery verified','job_id'=>99,'billing_state'=>'accepted','billing_response'=>$makeReceipt($includedPayload)['body']]));
wb_check(str_contains($card,'No extra charge.') && !str_contains($card,'Review time &amp; billing'),'covered completion removes the unnecessary invoice-review step');
$pdo->exec("INSERT INTO time_entries VALUES(50,1,7,42,'approved',0)");
wb_refuses(fn()=>westy_billing_payload($pdo,$run,$outbox,$includedConfig),'human time cannot be silently marked covered');
$pdo->exec('DELETE FROM time_entries');
wb_refuses(fn()=>westy_billing_payload($pdo,array_replace($run,['state'=>'human_owned']),$outbox,$includedConfig),'human takeover prevents a new included-service claim');
$configWrong=$includedConfig; $configWrong['included_service']['policies']=['other'=>[$customer=>$coverage]];
wb_refuses(fn()=>westy_billing_payload($pdo,$run,$outbox,$configWrong),'another tenant coverage cannot authorize work');

// Replay the two financial paths from the same unfrozen SQLite fixture, this time with no verdict.
$pdo->sqliteCreateFunction('westy_work_log_schema_health', static fn(): int => 1, 0);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => '2026-09-10 09:00:00', 0);
$detail = static fn(): string => $pdo->query('SELECT detail_code FROM westy_billing_outbox WHERE id=1')->fetchColumn();
$release = static function () use ($pdo, $config): void {
    $pdo->exec("DELETE FROM time_entries;
        UPDATE westy_billing_outbox SET state='qa_pending',detail_code='qa_review_required',created_at='2026-09-07 09:00:00',
            attempts=0,payload_json=NULL,payload_sha256=NULL,response_json=NULL,last_attempt_at=NULL,next_attempt_at=NULL WHERE id=1");
    wb_check(westy_billing_release_expired_qa($pdo,$config) === 1, 'timer releases the unfrozen closure');
};
$release();
$before = count($calls);
wb_check(westy_billing_dispatch($pdo,1,$config,$transport) === 'waiting_for_time' && count($calls) === $before
    && $detail() === 'qa_not_received:approved_billable_time_required', 'released row without time or coverage still waits locally');
$pdo->exec("INSERT INTO time_entries VALUES(10,1,7,42,'approved',1)");
wb_due($pdo);
wb_check(westy_billing_dispatch($pdo,1,$config,$transport) === 'accepted'
    && json_decode(end($calls),true)['event'] === 'safeharbor.ticket.billing_requested'
    && json_decode(end($calls),true)['time_event_keys'] === [$source['event_key']] && $detail() === 'qa_not_received:coastmark_review_required',
    'timeout references only the current approved staff-time export for invoice review');
$release();
wb_check(westy_billing_dispatch($pdo,1,$includedConfig,$lost) === 'uncertain' && $detail() === 'qa_not_received:delivery_uncertain',
    'timeout provenance survives a lost covered receipt');
wb_due($pdo);
wb_check(westy_billing_dispatch($pdo,1,$includedConfig,$delivered) === 'accepted'
    && json_decode(end($includedCalls),true)['event'] === 'safeharbor.ticket.service_completed' && $detail() === 'qa_not_received:included_service_recorded',
    'covered timeout retries only the zero-charge event and retains its release detail');
$releasedCard = westy_workflow_card(westy_workflow_ticket($pdo,1,42) + ['summary'=>'Recovery verified','job_id'=>99]);
wb_check(str_contains($releasedCard,'Released without a review (billed as staff time only)') && str_contains($releasedCard,'No extra charge.'),
    'the actual staff card retains the timeout and the zero receipt together');
if (getenv('WESTY_INCLUDED_FIXTURE_DIR')) {
    $fixtureDir=getenv('WESTY_INCLUDED_FIXTURE_DIR');
    file_put_contents($fixtureDir.'/included-service-v2.json',$includedCalls[0]);
    file_put_contents($fixtureDir.'/included-card.html',$card);
}

// Synthetic stored facts for Coastmark's frozen v3 vector. Each case has its own database.
function wb_ai_database(): PDO
{
    $db=wb_database();
    $db->exec("INSERT INTO tenants VALUES(1,'8west');
        INSERT INTO tickets VALUES(501,1,7,'resolved','2026-11-16 17:00:00',NULL);
        INSERT INTO suite_customer_sync_bindings VALUES(1,7,'11111111-1111-4111-8111-111111111111','active');
        INSERT INTO ticket_work_log VALUES(1,1,501,'milepost','verify',1,'alert:501;outcome:7;service:workstation_fix','service:from_summary','from_cause_code')");
    $db->prepare("INSERT INTO westy_workflows VALUES(1,1,7,'11111111-1111-4111-8111-111111111111',501,
        '22222222-2222-4222-8222-222222222222','2026-11-16 17:00:00',?,'resolved','2026-11-15 16:00:00')")->execute([str_repeat('a',64)]);
    $db->prepare("INSERT INTO westy_billing_outbox(id,tenant_id,workflow_id,event_key) VALUES(1,1,1,?)")->execute(['safeharbor-billing:'.str_repeat('a',32)]);
    $db->prepare("INSERT INTO westy_workflow_log_state VALUES(1,1,'passed','no_recurrence',?,1)")->execute([str_repeat('b',64)]);
    $db->prepare("INSERT INTO westy_workflow_v4_receipts VALUES(1,1,'qa',?,?, '2026-11-16 16:00:00')")->execute(['{"qa_state":"passed"}',str_repeat('c',64)]);
    return $db;
}
$aiCustomer='11111111-1111-4111-8111-111111111111';
$aiConfig=array_replace($config,['tenant_slugs'=>['8west'],'customer_ids'=>[$aiCustomer],'ai_service'=>['enabled'=>true,'customer_ids'=>[$aiCustomer]]]);
$aiCovered=$aiConfig+['included_service'=>['enabled'=>true,'policies'=>['8west'=>[$aiCustomer=>$coverage]]]];
$aiVector=rtrim(file_get_contents(__DIR__.'/fixtures/ai-service-v3.json'),"\r\n");
$receiptBytes=rtrim(file_get_contents(__DIR__.'/fixtures/ai-service-v3-receipt.json'),"\r\n");
$aiPayload=json_decode($aiVector,true,16,JSON_THROW_ON_ERROR);
$priced=json_decode($receiptBytes,true,16,JSON_THROW_ON_ERROR);
$box=static fn(PDO $db): array => $db->query('SELECT * FROM westy_billing_outbox WHERE id=1')->fetch();
$build=static function(PDO $db,array $settings) use ($box): array {
    $run=$db->query('SELECT w.*,t.slug AS tenant_slug FROM westy_workflows w JOIN tenants t ON t.id=w.tenant_id WHERE w.id=1')->fetch();
    return westy_billing_payload($db,$run,$box($db),$settings);
};
$noSend=static function(PDO $db,array $settings,string $state,string $reason) use ($box): void {
    $sent=0;
    $transport=static function() use (&$sent): array { ++$sent; return ['status'=>0,'body'=>'']; };
    $actual=westy_billing_dispatch($db,1,$settings,$transport);
    $row=$box($db);
    wb_check($actual===$state && $row['detail_code']===$reason && (int)$row['attempts']===0 && $row['payload_json']===null && $sent===0,
        'expected unsent '.$state.'/'.$reason.', got '.$actual.'/'.$row['detail_code']);
};
wb_check(westy_billing_settings($aiConfig)['ai_service']===$aiConfig['ai_service'],'valid AI customer list');
wb_check($config['ai_service']===['enabled'=>false,'customer_ids'=>[]],'missing AI settings default to off');
foreach ([null,'not a list',['named'=>$aiCustomer],[1=>$aiCustomer],[42],[null],['bad-uuid'],['AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA']] as $ids) {
    wb_error(fn()=>westy_billing_settings(array_replace($aiConfig,['ai_service'=>['enabled'=>true,'customer_ids'=>$ids]])),'billing_handoff_unavailable');
}
wb_error(fn()=>westy_billing_settings(array_replace($aiConfig,['ai_service'=>['enabled'=>true]])),'billing_handoff_unavailable');
wb_check(westy_billing_settings(array_replace($aiConfig,['ai_service'=>['enabled'=>true,'customer_ids'=>[]]]))['ai_service']['customer_ids']===[],'empty AI allowlist enables no customers');
$db=wb_ai_database();
$db->exec("INSERT INTO westy_workflow_v4_receipts SELECT 2,workflow_id,action,response_json,request_sha256,received_at FROM westy_workflow_v4_receipts WHERE tenant_id=1;
    INSERT INTO westy_workflow_v4_receipts SELECT tenant_id,2,action,response_json,request_sha256,received_at FROM westy_workflow_v4_receipts WHERE tenant_id=1;
    INSERT INTO westy_workflow_v4_receipts SELECT tenant_id,workflow_id,'log',response_json,request_sha256,received_at FROM westy_workflow_v4_receipts WHERE tenant_id=1 AND workflow_id=1");
wb_check(json_encode($build($db,$aiConfig),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)===$aiVector,'foreign tenants, workflows and non-QA receipts cannot change the matching proof');
foreach ([[],['ai_service'=>['enabled'=>false,'customer_ids'=>'unused']],['ai_service'=>['enabled'=>'true','customer_ids'=>[$aiCustomer]]],
    ['ai_service'=>['enabled'=>true,'customer_ids'=>[$customer]]]] as $change) {
    $legacy=$aiCovered; unset($legacy['ai_service']);
    wb_check($build($db,westy_billing_settings(array_replace($legacy,$change)))['version']===2,'absent, disabled or unlisted AI selection stays on v2');
}
$noSend($db,array_replace($aiCovered,['customer_ids'=>[$customer]]),'waiting_for_time','billing_scope_not_enabled');
$db=wb_ai_database();
$db->prepare('UPDATE westy_workflows SET customer_id=?')->execute([WESTY_BILLING_EXCLUDED_CUSTOMER]);
$db->prepare('UPDATE suite_customer_sync_bindings SET customer_id=?')->execute([WESTY_BILLING_EXCLUDED_CUSTOMER]);
$db->exec('DROP TABLE westy_workflow_log_state');
$master=array_replace($aiCovered,['customer_ids'=>[WESTY_BILLING_EXCLUDED_CUSTOMER],'ai_service'=>['enabled'=>true,'customer_ids'=>[WESTY_BILLING_EXCLUDED_CUSTOMER]]]);
$noSend($db,$master,'waiting_for_time','billing_scope_not_enabled');

foreach ([
    ['UPDATE westy_workflow_log_state SET qa_state=NULL','waiting_for_time','qa_review_required'],
    ['UPDATE westy_workflow_log_state SET tenant_id=2','waiting_for_time','qa_review_required'],
    ['UPDATE westy_workflow_log_state SET workflow_id=2','waiting_for_time','qa_review_required'],
    ["UPDATE westy_workflow_log_state SET qa_state='fault_zero'",'blocked','ai_service_not_chargeable'],
    ["UPDATE westy_workflow_log_state SET qa_state='void'",'blocked','ai_service_not_chargeable'],
    ["UPDATE westy_workflows SET state='human_owned'",'blocked','included_service_workflow_changed'],
    ["UPDATE suite_customer_sync_bindings SET status='inactive'",'blocked','verified_closed_ticket_required'],
    ["UPDATE tickets SET status='open'",'blocked','verified_closed_ticket_required'],
    ["INSERT INTO time_entries VALUES(20,1,8,501,'approved',1)",'blocked','time_source_scope_conflict'],
] as [$mutation,$state,$reason]) {
    $db=wb_ai_database(); $db->exec($mutation);
    $noSend($db,$aiCovered,$state,$reason);
    if ($reason==='qa_review_required') {
        $card=westy_workflow_card(westy_workflow_ticket($db,1,501)+['summary'=>'Fixture','job_id'=>7]);
        wb_check(str_contains($card,'Waiting for the 24-hour review'),'legacy waiting row explains the missing review');
    }
}
foreach (['DELETE FROM westy_workflow_v4_receipts','UPDATE westy_workflow_v4_receipts SET tenant_id=2',
    'UPDATE westy_workflow_v4_receipts SET workflow_id=2',"UPDATE westy_workflow_v4_receipts SET action='log'",
    'UPDATE westy_workflow_v4_receipts SET response_json=\'{"qa_state":"void"}\'',
    'INSERT INTO westy_workflow_v4_receipts SELECT * FROM westy_workflow_v4_receipts',
    "UPDATE westy_workflow_v4_receipts SET received_at='2026-11-16 15:59:59'",
    "UPDATE westy_workflows SET alert_resolved_at='2026-11-16 17:00:01'; UPDATE westy_workflow_v4_receipts SET received_at='2026-11-17 17:00:01'",
    "UPDATE westy_workflow_v4_receipts SET received_at='2026-11-31 16:00:00'",
    "UPDATE westy_workflow_v4_receipts SET received_at='".gmdate('Y-m-d H:i:s',time()+3600)."'",
    "UPDATE westy_workflows SET closed_at='".gmdate('Y-m-d H:i:s',time()+3600)."'; UPDATE tickets SET resolved_at=(SELECT closed_at FROM westy_workflows)",
    "UPDATE westy_workflows SET alert_resolved_at=NULL",
    "UPDATE westy_workflows SET evidence_sha256=upper(evidence_sha256)",
    "UPDATE westy_workflow_log_state SET log_sha256='bad'",
    'UPDATE westy_workflow_v4_receipts SET request_sha256=NULL'] as $mutation) {
    $db=wb_ai_database(); $db->exec($mutation);
    $noSend($db,$aiCovered,'blocked','ai_service_evidence_conflict');
}
foreach ([['pending',1,'waiting_for_time','time_approval_required'],['approved',1,'waiting_for_time','current_time_export_required'],
    ['rejected',1,'blocked','included_service_has_human_time'],['approved',0,'blocked','included_service_has_human_time']] as [$approval,$billable,$state,$reason]) {
    $db=wb_ai_database();
    $db->prepare('INSERT INTO time_entries VALUES(20,1,7,501,?,?)')->execute([$approval,$billable]);
    $noSend($db,$aiCovered,$state,$reason);
}
$db=wb_ai_database();
$db->exec("INSERT INTO time_entries VALUES(20,1,7,501,'approved',1); INSERT INTO coastmark_time_export_receipts VALUES(1,1,1,'accepted',9)");
$humanSource=json_encode(array_replace($source,['tenant_key'=>'8west','client_key'=>'milepost-customer:'.$aiCustomer,'ticket_id'=>501,
    'entry_id'=>20,'source_version'=>0,'event_key'=>$timeEvent]));
$db->prepare('INSERT INTO coastmark_time_export_claims VALUES(1,1,20,0,?,?,?)')->execute([$timeEvent,$humanSource,hash('sha256',$humanSource)]);
wb_check($build($db,$aiCovered)['time_event_keys']===[$timeEvent],'exported approved time takes v1 even when all AI evidence passes');
wb_check(westy_billing_dispatch($db,1,$aiCovered,static fn()=>['status'=>404,'body'=>''])==='blocked','v1 HTTP 404 still blocks');

foreach (['qa_not_received','qa_not_received:delivery_uncertain'] as $released) {
    $db=wb_ai_database();
    $db->prepare('UPDATE westy_billing_outbox SET detail_code=?')->execute([$released]);
    wb_check($build($db,$aiCovered)['version']===2,'timeout followed by passed QA keeps the covered path');
    $db->exec('UPDATE westy_workflow_log_state SET qa_state=NULL');
    $noSend($db,$aiConfig,'waiting_for_time','qa_not_received:approved_billable_time_required');
}
foreach (["milepost_ref='alert:5;service:UPPER'","milepost_ref='alert:5;service:'","milepost_ref='alert:5;service:good;outcome:2'",
    "milepost_ref='alert:5;service:1fix'","milepost_ref='alert:5;service:a-b'","milepost_ref='alert:5;service:valid'||char(10)",
    "milepost_ref=';service:".str_repeat('a',65)."'","milepost_ref=NULL","kind='finding'","kind='action'","source='staff'",
    'seq=2','tenant_id=2','workflow_id=2','ticket_id=502'] as $change) {
    $db=wb_ai_database(); $db->exec('UPDATE ticket_work_log SET '.$change);
    wb_check($build($db,$aiCovered)['version']===2,'no sealed service code falls through to v2: '.$change);
    $noSend($db,$aiConfig,'waiting_for_time','approved_billable_time_required');
}
$db=wb_ai_database();
$db->exec('DELETE FROM ticket_work_log; UPDATE westy_workflow_log_state SET log_sealed_seq=22');
$insert=$db->prepare("INSERT INTO ticket_work_log(tenant_id,workflow_id,ticket_id,source,kind,seq,milepost_ref) VALUES(1,1,501,'milepost','verify',?,?)");
$codes=[str_repeat('a',64)];
for ($i=1;$i<20;$i++) $codes[]='fix_'.$i;
foreach (array_reverse($codes) as $i=>$code) $insert->execute([$i+1,'alert:501;service:'.$code]);
$insert->execute([21,'outcome:9;service:fix_1']);
sort($codes,SORT_STRING);
wb_check($build($db,$aiConfig)['service_codes']===$codes,'twenty distinct sealed codes sort as strings and repeated codes count once');
$insert->execute([22,'alert:501;service:one_more']);
$noSend($db,$aiConfig,'blocked','ai_service_evidence_conflict');

// The same passed workflow can receive five legitimate outcomes from Coastmark.
$labels=['priced'=>'AI service recorded. Coastmark added the fee to a draft invoice for review.',
    'included'=>'AI service recorded. Covered by the customer plan. No extra charge.',
    'unmapped'=>'AI service recorded. Coastmark is holding it for a person to review. No charge yet.',
    'held'=>'AI service recorded. Coastmark is holding it for a person to review. No charge yet.',
    'superseded_by_time'=>'AI service recorded. Staff time is billed for this ticket instead.'];
$zero=['amount_cents'=>0,'invoice_id'=>null,'invoice_line_id'=>null,'hold_reason'=>null];
foreach ($labels as $state=>$label) {
    $db=wb_ai_database();
    $reply=$priced;
    if ($state!=='priced') $reply['service']=array_replace($reply['service'],$zero,['state'=>$state]);
    if ($state==='held') $reply['service']['hold_reason']='daily_cap';
    if ($state==='unmapped') $reply['service']['hold_reason']='no_mapping';
    $receipt=json_encode($reply,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $sent=[];
    $ack=static function(array $settings,string $bytes) use (&$sent,$receipt): array { $sent[]=$bytes; return ['status'=>201,'body'=>$receipt]; };
    wb_check(westy_billing_dispatch($db,1,$aiCovered,$ack)==='accepted' && $sent===[$aiVector] && json_decode($sent[0],true)===$aiPayload,
        'producer matches all fifteen Coastmark fields, types, order, and frozen bytes for '.$state);
    $row=$box($db);
    wb_check($row['payload_sha256']===hash('sha256',$aiVector) && $row['response_json']===$receipt && $row['detail_code']==='ai_service_'.$state,
        'exact verified receipt and detail retained for '.$state);
    $staffCard=westy_workflow_card(westy_workflow_ticket($db,1,501)+['summary'=>'Fixture','job_id'=>7]);
    wb_check(str_contains($staffCard,$label) && str_contains(westy_chat_system_prompt(),$label),'staff card and chat explain '.$state);
    wb_check(westy_billing_dispatch($db,1,$aiCovered,$ack)==='accepted' && $sent===[$aiVector] && $box($db)===$row,'terminal v3 never sends twice');
}
$validResponse=['status'=>201,'body'=>$receiptBytes];
wb_check(westy_billing_response($validResponse,$billingEvent,$aiPayload),'Coastmark fixture receipt validates');
$validate=static fn(array $reply,int $status=201): bool => westy_billing_response(['status'=>$status,'body'=>json_encode($reply)],$billingEvent,$aiPayload);
foreach ([['ok'=>1],['action'=>'ignored'],['action'=>'unknown'],['extra'=>true],['service'=>null],['service'=>[]]] as $changes) {
    wb_check(!$validate(array_replace($priced,$changes)),'wrong success envelope refused');
}
wb_check($validate(array_replace($priced,['action'=>'ignored']),200) && $validate($priced,200),'200 permits both a matching replay and creation');
$reordered=$priced; $reordered['service']=array_reverse($priced['service'],true);
wb_check($validate($reordered),'receipt key order is immaterial');
foreach (['id','event_key','state','amount_cents','invoice_id','invoice_line_id','hold_reason'] as $key) {
    $bad=$priced; unset($bad['service'][$key]);
    wb_check(!$validate($bad),'every service field required: '.$key);
}
foreach ([['extra'=>true],['id'=>'1'],['id'=>0],['event_key'=>'another-event'],['state'=>'unknown'],['amount_cents'=>0],['amount_cents'=>-1],
    ['amount_cents'=>'2500'],['invoice_id'=>null],['invoice_id'=>'1'],['invoice_line_id'=>null],['invoice_line_id'=>0],['hold_reason'=>'held']] as $changes) {
    $bad=$priced; $bad['service']=array_replace($priced['service'],$changes);
    wb_check(!$validate($bad),'invalid priced receipt refused: '.json_encode($changes));
}
foreach ([['state'=>'included','amount_cents'=>1],['state'=>'held','hold_reason'=>null],['state'=>'unmapped','hold_reason'=>'No mapping'],
    ['state'=>'held','hold_reason'=>''],['state'=>'held','hold_reason'=>str_repeat('a',65)],['state'=>'unmapped','hold_reason'=>12],
    ['state'=>'superseded_by_time','invoice_id'=>1],['state'=>'included','invoice_line_id'=>1],['state'=>'included','hold_reason'=>'none'],
    ['state'=>'fault_zero']] as $changes) {
    $bad=$priced; $bad['service']=array_replace($priced['service'],$zero,$changes);
    wb_check(!$validate($bad),'inconsistent zero-fee receipt refused: '.json_encode($changes));
}
$fault=$priced; $fault['service']=array_replace($priced['service'],$zero,['state'=>'fault_zero']);
wb_check(westy_billing_response(['status'=>200,'body'=>json_encode($fault)],$billingEvent,array_replace($aiPayload,['qa_state'=>'fault_zero'])),
    'fault_zero receipt only matches a fault_zero request');
$db=wb_ai_database();
wb_check(westy_billing_dispatch($db,1,$aiConfig,static fn()=>['status'=>201,'body'=>json_encode($fault)])==='uncertain'
    && $box($db)['response_json']===null && $box($db)['detail_code']==='delivery_uncertain','contradictory QA receipt never becomes an accepted staff outcome');
wb_check(!westy_billing_response($validResponse,'other-event',$aiPayload)
    && !westy_billing_response($validResponse,$billingEvent,array_replace($aiPayload,['event_key'=>'other-event'])),
    'receipt, outbox and payload must agree on event identity');
foreach ([202,400,404,409,422,503] as $status) wb_check(!$validate($priced,$status),'non-success response refused: '.$status);
foreach ([400,401,403,409,422] as $status) {
    $db=wb_ai_database();
    wb_check(westy_billing_dispatch($db,1,$aiConfig,static fn()=>['status'=>$status,'body'=>''])==='blocked'
        && $box($db)['detail_code']==='receiver_refused','definitive v3 refusal blocks: '.$status);
}
$db=wb_ai_database(); $sent=[];
$missing=static function(array $settings,string $bytes) use (&$sent): array { $sent[]=$bytes; return ['status'=>404,'body'=>'']; };
wb_check(westy_billing_dispatch($db,1,$aiConfig,$missing)==='uncertain' && $box($db)['detail_code']==='delivery_uncertain'
    && $box($db)['next_attempt_at']!==null,'v3 receiver off schedules a retry');
wb_check(westy_billing_dispatch($db,1,$aiCovered,$missing)==='uncertain' && $sent===[$aiVector],'backoff skips early v3 retry');
wb_due($db);
$replay=static function(array $settings,string $bytes) use (&$sent,$priced): array {
    $sent[]=$bytes; return ['status'=>200,'body'=>json_encode(array_replace($priced,['action'=>'ignored']))];
};
wb_check(westy_billing_dispatch($db,1,$aiCovered,$replay)==='accepted' && $sent===[$aiVector,$aiVector] && $box($db)['detail_code']==='ai_service_priced',
    'an included policy added after claim still replays the frozen v3 and reconciles the receipt');
$db=wb_ai_database(); $aiOff=$aiCovered; unset($aiOff['ai_service']);
wb_check(westy_billing_dispatch($db,1,$aiOff,static fn(array $settings,string $bytes): array=>$makeReceipt(json_decode($bytes,true)))==='accepted','chargeable fixture can complete as v2 while AI is off');
$row=$box($db); $sent=[];
wb_check(westy_billing_dispatch($db,1,$aiCovered,$missing)==='accepted' && !$sent && $box($db)===$row,'an accepted v2 stays terminal even with complete AI evidence');
$db=wb_ai_database();
wb_check(westy_billing_dispatch($db,1,$aiOff,$missing)==='blocked','v2 HTTP 404 still blocks');
foreach ([[$aiOff,$aiCovered,2],[$aiCovered,$aiOff,3]] as [$first,$second,$version]) {
    $db=wb_ai_database(); $sent=[];
    $lostAck=static function(array $settings,string $bytes) use (&$sent): array { $sent[]=$bytes; return ['status'=>503,'body'=>'']; };
    wb_check(westy_billing_dispatch($db,1,$first,$lostAck)==='uncertain' && json_decode($sent[0],true)['version']===$version,'freeze first version on lost acknowledgment');
    wb_due($db);
    wb_check(westy_billing_dispatch($db,1,$second,$lostAck)==='blocked' && $box($db)['detail_code']==='billing_source_changed_after_claim'
        && $sent===[$box($db)['payload_json']],'changing the AI switch cannot send the other version of a frozen claim');
}
foreach (["UPDATE westy_workflow_log_state SET log_sealed_seq=2,log_sha256='".str_repeat('d',64)."'",
    "INSERT INTO ticket_work_log VALUES(1,1,501,'milepost','verify',2,'alert:501;service:second_fix','','');
     UPDATE westy_workflow_log_state SET log_sealed_seq=2,log_sha256='".str_repeat('e',64)."'"] as $advance) {
    $db=wb_ai_database(); $sent=[];
    wb_check(westy_billing_dispatch($db,1,$aiConfig,$missing)==='uncertain','first claim frozen');
    $db->exec($advance); wb_due($db);
    wb_check(westy_billing_dispatch($db,1,$aiConfig,$replay)==='blocked' && $box($db)['detail_code']==='billing_source_changed_after_claim'
        && $box($db)['payload_json']===$aiVector && (int)$box($db)['attempts']===1 && $sent===[$aiVector],'new seal blocks without replacing or resending the first v3');
}
$db=wb_ai_database(); $sent=[];
for ($i=0;$i<8;$i++) { wb_due($db); wb_check(westy_billing_dispatch($db,1,$aiConfig,$missing)==='uncertain','404 retry within cap'); }
wb_due($db);
wb_check(westy_billing_dispatch($db,1,$aiConfig,$missing)==='blocked' && $box($db)['detail_code']==='billing_delivery_needs_review'
    && $sent===array_fill(0,8,$aiVector),'eight frozen attempts exhaust the v3 retry budget');
echo "PASS $checks Westy billing handoff checks.\n";
