<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/westy_workflow.php';
$checks = 0;
function ww_check(bool $condition, string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks++; }
function ww_refuses(callable $action, string $name): void { try { $action(); } catch (Throwable) { ww_check(true,$name); return; } ww_check(false,$name); }
function service_goal_snapshot_for_new_ticket(PDO $pdo, int $tenant, int $client, string $priority): array {
    return ['due_at'=>gmdate('Y-m-d H:i:s',time()+480*60),'target_id'=>null,'opened_at'=>gmdate('Y-m-d H:i:s')];
}
function ww_key(int $id): string { return sprintf('%08x-aaaa-4aaa-8aaa-aaaaaaaaaaaa',$id); }
$customer = ww_key(100); $otherCustomer = ww_key(200);
$settings = westy_workflow_settings(['enabled'=>true,'hmac_secret'=>str_repeat('test-only-',6),'tenant_slugs'=>[],'customer_ids'=>[]]);
ww_check(westy_workflow_ticket(new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]),1,1)===null,'pre-migration ticket rendering omits the new card without failing');
$p = ['schema_version'=>1,'event_key'=>ww_key(1),'workflow_key'=>ww_key(2),'tenant_slug'=>'8west','customer_id'=>$customer,'alert_key'=>'alert:42','action'=>'claim','expected_version'=>0,'summary'=>'Investigating disk alert; no command has run.','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),'evidence_sha256'=>null,'verification_method'=>null,'job_id'=>null,'job_completed_at'=>null,'alert_resolved_at'=>null,'assignee_id'=>null];
$raw = json_encode($p,JSON_THROW_ON_ERROR);
ww_check(westy_workflow_request($raw)===$p,'valid flat controller request');
$headers = ['service'=>'milepost-workflow','timestamp'=>(string)time()];
$headers['signature'] = hash_hmac('sha256',WESTY_WORKFLOW_CONTEXT . "\n" . $headers['timestamp'] . "\n" . $raw,$settings['hmac_secret']);
ww_check(westy_workflow_authenticated($settings,$headers,$raw),'dedicated contextual HMAC accepted');
ww_check(!westy_workflow_authenticated($settings,array_replace($headers,['service'=>'milepost']),$raw),'legacy service cannot act');
ww_check(!westy_workflow_authenticated($settings,$headers,$raw.' '),'body substitution refused');
ww_check(!westy_workflow_authenticated($settings,$headers,$raw,time()+301),'stale signature refused');
$oldHeaders = $headers; $oldHeaders['signature'] = hash_hmac('sha256',$headers['timestamp'] . "\n" . $raw,$settings['hmac_secret']);
ww_check(!westy_workflow_authenticated($settings,$oldHeaders,$raw),'legacy signature context refused');
foreach ([['enabled'=>false],['hmac_secret'=>'short'],['tenant_slugs'=>'8west'],['customer_ids'=>['bad']]] as $change) ww_refuses(fn()=>westy_workflow_settings(array_replace($settings,$change)),'incomplete gate refused');
ww_check(westy_workflow_settings(['enabled'=>true,'hmac_secret'=>str_repeat('test-only-',6)])['customer_ids']===[],'retired pilot arrays are not required for house defaults');
foreach ([['schema_version'=>2],['expected_version'=>-1],['expected_version'=>'0'],['tenant_slug'=>'MSP'],['customer_id'=>'bad'],['alert_key'=>'alert:18446744073709551616'],['alert_key'=>'alert:0'],['action'=>'approve_command'],['summary'=>''],['summary'=>str_repeat('x',2001)],['occurred_at'=>'2026-02-30T00:00:00Z'],['occurred_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)],['job_id'=>1],['assignee_id'=>1],['evidence_sha256'=>str_repeat('a',64)],['surprise'=>true]] as $change) ww_refuses(fn()=>westy_workflow_request(json_encode(array_replace($p,$change))), 'invalid payload refused');
ww_refuses(fn()=>westy_workflow_request(substr($raw,0,-1).',"tenant_slug":"msp-one"}'),'duplicate JSON field refused');
$resolve = array_replace($p,['action'=>'resolve','expected_version'=>1,'verification_method'=>'agent_job_and_alert_recovery','evidence_sha256'=>str_repeat('a',64),'job_id'=>71,'job_completed_at'=>$p['occurred_at'],'alert_resolved_at'=>$p['occurred_at']]);
ww_check(westy_workflow_request(json_encode($resolve)) === $resolve,'controller job and recovery evidence accepted');
foreach ([['verification_method'=>'model_says_fixed'],['job_id'=>null],['job_completed_at'=>null],['evidence_sha256'=>null],['alert_resolved_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600)]] as $change) ww_refuses(fn()=>westy_workflow_request(json_encode(array_replace($resolve,$change))),'missing or stale independent proof refused');

$pdo ??= new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
if (!$mysql) {
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec("CREATE TABLE tenants(id INTEGER PRIMARY KEY,slug TEXT UNIQUE);
CREATE TABLE clients(id INTEGER PRIMARY KEY,tenant_id INTEGER,UNIQUE(tenant_id,id));
CREATE TABLE svc_identities(id INTEGER PRIMARY KEY,tenant_id INTEGER,service TEXT,is_active INTEGER);
CREATE TABLE suite_customer_sync_bindings(tenant_id INTEGER,client_id INTEGER,customer_id TEXT,status TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,tenant_id INTEGER,is_active INTEGER,role TEXT DEFAULT 'tech');
CREATE TABLE tickets(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,subject TEXT,priority TEXT,status TEXT DEFAULT 'open',assignee_id INTEGER,merged_into_id INTEGER,channel TEXT,external_key TEXT,auto_close_eligible INTEGER,sla_due_at TEXT,service_goal_target_id INTEGER,created_at TEXT,updated_at TEXT,resolved_at TEXT,UNIQUE(tenant_id,id),UNIQUE(tenant_id,external_key));
CREATE TABLE messages(id INTEGER PRIMARY KEY,ticket_id INTEGER,author_name TEXT,kind TEXT,body TEXT,created_at TEXT);
CREATE TABLE time_entries(id INTEGER PRIMARY KEY,tenant_id INTEGER,ticket_id INTEGER);
CREATE TABLE westy_workflows(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,customer_id TEXT,ticket_id INTEGER,workflow_key TEXT,alert_key TEXT,state TEXT,version INTEGER,summary TEXT,evidence_sha256 TEXT,job_id INTEGER,job_completed_at TEXT,alert_resolved_at TEXT,closed_at TEXT,started_at TEXT,updated_at TEXT,UNIQUE(tenant_id,workflow_key),UNIQUE(tenant_id,ticket_id));
CREATE TABLE westy_workflow_receipts(id INTEGER PRIMARY KEY,tenant_id INTEGER,workflow_id INTEGER,event_key TEXT,request_sha256 TEXT,action TEXT,version INTEGER,response_json TEXT,received_at TEXT,UNIQUE(tenant_id,event_key),UNIQUE(tenant_id,workflow_id,version));
CREATE TABLE westy_billing_outbox(id INTEGER PRIMARY KEY,tenant_id INTEGER,workflow_id INTEGER,event_key TEXT,state TEXT DEFAULT 'waiting_for_time',payload_json TEXT,payload_sha256 TEXT,attempts INTEGER DEFAULT 0,detail_code TEXT DEFAULT 'approved_time_required',response_json TEXT,next_attempt_at TEXT,last_attempt_at TEXT,created_at TEXT,updated_at TEXT,UNIQUE(tenant_id,workflow_id));
CREATE TRIGGER receipt_update BEFORE UPDATE ON westy_workflow_receipts BEGIN SELECT RAISE(ABORT,'immutable'); END;
CREATE TRIGGER receipt_delete BEFORE DELETE ON westy_workflow_receipts BEGIN SELECT RAISE(ABORT,'immutable'); END;
CREATE TRIGGER ticket_takeover AFTER UPDATE ON tickets BEGIN UPDATE westy_workflows SET state='human_owned',version=version+1 WHERE tenant_id=OLD.tenant_id AND ticket_id=OLD.id AND state<>'human_owned'; END;
CREATE TRIGGER message_takeover AFTER INSERT ON messages WHEN NEW.kind<>'system' BEGIN UPDATE westy_workflows SET state='human_owned',version=version+1 WHERE ticket_id=NEW.ticket_id AND state<>'human_owned'; END;
CREATE TRIGGER time_takeover AFTER INSERT ON time_entries BEGIN UPDATE westy_workflows SET state='human_owned',version=version+1 WHERE tenant_id=NEW.tenant_id AND ticket_id=NEW.ticket_id AND state<>'human_owned'; END;");
}
$pdo->exec("INSERT INTO tenants VALUES (1,'8west'),(2,'msp-two'); INSERT INTO clients VALUES (1,1),(2,2); INSERT INTO users(id,tenant_id,is_active) VALUES (1,1,1),(2,2,1),(3,1,0); INSERT INTO svc_identities VALUES (1,1,'milepost-workflow',1),(2,2,'milepost-workflow',1);");
$pdo->prepare('INSERT INTO suite_customer_sync_bindings(tenant_id,client_id,customer_id,status) VALUES (?,?,?,?)')->execute([1,1,$customer,'active']);
$pdo->prepare('INSERT INTO suite_customer_sync_bindings(tenant_id,client_id,customer_id,status) VALUES (?,?,?,?)')->execute([2,2,$otherCustomer,'active']);
function ww_send(PDO $pdo,array $settings,array $p): array { $raw=json_encode($p,JSON_THROW_ON_ERROR); return westy_workflow_receive($pdo,$settings,westy_workflow_request($raw),hash('sha256',$raw)); }
$r=ww_send($pdo,$settings,$p); $ticketId=$r['ticket_id'];
ww_check($r['state']==='working' && $r['version']===1 && $r['receipt_id']===$p['event_key'],'claim creates bound ticket and stable receipt');
ww_check((int)$pdo->query("SELECT auto_close_eligible FROM tickets WHERE id=$ticketId")->fetchColumn()===0,'legacy source recovery cannot close claimed ticket');
$replay=ww_send($pdo,$settings,$p);
ww_check($replay['replayed']===true && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===1,'exact retry creates neither ticket nor duplicate evidence');
ww_refuses(fn()=>ww_send($pdo,$settings,array_replace($p,['summary'=>'changed'])),'idempotency conflict rolls back');
ww_refuses(fn()=>ww_send($pdo,$settings,array_replace($p,['event_key'=>ww_key(8),'customer_id'=>$otherCustomer])),'customer outside scope refused');
ww_refuses(fn()=>ww_send($pdo,$settings,array_replace($p,['event_key'=>ww_key(8),'tenant_slug'=>'msp-two'])),'tenant outside scope refused');
$progress=array_replace($p,['event_key'=>ww_key(3),'action'=>'progress','expected_version'=>1,'summary'=>'Waiting for technician command approval.']);
ww_check(ww_send($pdo,$settings,$progress)['version']===2,'progress advances exactly once');
ww_refuses(fn()=>ww_send($pdo,$settings,array_replace($progress,['event_key'=>ww_key(4)])),'stale optimistic version refused');
$status=array_replace($p,['event_key'=>ww_key(5),'action'=>'status']);
$count=(int)$pdo->query('SELECT COUNT(*) FROM westy_workflow_receipts')->fetchColumn();
ww_check(ww_send($pdo,$settings,$status)['version']===2 && (int)$pdo->query('SELECT COUNT(*) FROM westy_workflow_receipts')->fetchColumn()===$count,'status makes no receipt or version write');
$resolve=array_replace($resolve,['event_key'=>ww_key(6),'expected_version'=>2,'summary'=>'Successful agent job and original-alert recovery verified.','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),'job_completed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'alert_resolved_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
ww_check(ww_send($pdo,$settings,$resolve)['state']==='resolved','verified controller evidence closes owned ticket');
ww_check($pdo->query("SELECT status FROM tickets WHERE id=$ticketId")->fetchColumn()==='resolved','ticket and receipt close atomically');
ww_check((int)$pdo->query('SELECT COUNT(*) FROM westy_billing_outbox')->fetchColumn()===1,'verified close atomically queues billing review without sending');
ww_refuses(fn()=> $pdo->exec("UPDATE westy_workflow_receipts SET action='progress'"),'receipts cannot be rewritten');
ww_refuses(fn()=> $pdo->exec('DELETE FROM westy_workflow_receipts'),'receipts cannot be deleted');

// Active human work always wins, including a same-second change and revert.
foreach (['ticket','message','time','escalate'] as $i=>$kind) {
    $base=1000+$i*10; $claim=array_replace($p,['event_key'=>ww_key($base),'workflow_key'=>ww_key($base+1),'alert_key'=>'alert:'.($base+2)]);
    $created=ww_send($pdo,$settings,$claim); $id=$created['ticket_id'];
    if ($kind==='ticket') { $pdo->exec("UPDATE tickets SET priority='urgent' WHERE id=$id"); $pdo->exec("UPDATE tickets SET priority='normal' WHERE id=$id"); }
    if ($kind==='message') $pdo->prepare('INSERT INTO messages(ticket_id,author_name,kind,body) VALUES (?,?,?,?)')->execute([$id,'Human','note','I am taking over.']);
    if ($kind==='time') $pdo->prepare('INSERT INTO time_entries(tenant_id,ticket_id) VALUES (?,?)')->execute([1,$id]);
    $next=array_replace($claim,['event_key'=>ww_key($base+3),'action'=>'progress','expected_version'=>1]);
    if ($kind==='escalate') {
        $escalate=array_replace($next,['action'=>'escalate','assignee_id'=>2]);
        ww_refuses(fn()=>ww_send($pdo,$settings,$escalate),'cross-tenant assignee refused');
        ww_refuses(fn()=>ww_send($pdo,$settings,array_replace($escalate,['assignee_id'=>3])),'inactive assignee refused');
        $pdo->exec("INSERT INTO users(id,tenant_id,is_active,role) VALUES(4,1,1,'viewer')");
        ww_refuses(fn()=>ww_send($pdo,$settings,array_replace($escalate,['assignee_id'=>4])),'non-staff role refused');
        $escalated=ww_send($pdo,$settings,array_replace($escalate,['assignee_id'=>1]));
        ww_check($escalated['state']==='needs_human' && (int)$pdo->query("SELECT assignee_id FROM tickets WHERE id=$id")->fetchColumn()===1,'escalation assigns exact active tenant technician');
    } else {
        $state=ww_send($pdo,$settings,array_replace($claim,['event_key'=>ww_key($base+5),'action'=>'status']));
        ww_check($state['state']==='human_owned','human '.$kind.' takes permanent ownership');
        ww_refuses(fn()=>ww_send($pdo,$settings,$next),'human '.$kind.' prevents further workflow changes');
    }
}
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE tenant_id=1");
ww_refuses(fn()=>ww_send($pdo,$settings,$status),'inactive customer blocks read or replay');
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='active' WHERE tenant_id=1");
$pdo->exec('UPDATE svc_identities SET is_active=0 WHERE tenant_id=1');
ww_refuses(fn()=>ww_send($pdo,$settings,$status),'inactive identity cannot read workflow');
$pdo->exec('UPDATE svc_identities SET is_active=1 WHERE tenant_id=1');
$card=westy_workflow_card(['ticket_id'=>1,'workflow_key'=>ww_key(1),'state'=>'working','summary'=>'<script>alert(1)</script>']);
ww_check(!str_contains($card,'<script>') && str_contains($card,'&lt;script&gt;'),'summary is HTML escaped');
ww_check(str_contains($card,'Take over ticket') && str_contains($card,'support.8westit.com/westy_diag.php?workflow='),'real takeover and Milepost route shown');
// V2 is an explicit capability, with the original V1 cases above unchanged.
require_once __DIR__.'/../lib/westy_workflow_grants.php';
function ww_error(callable $action, string $message, string $name): void {
    try { $action(); } catch (Throwable $e) { ww_check($e->getMessage()===$message,$name.' ('.$e->getMessage().')'); return; }
    ww_check(false,$name.' did not refuse');
}
function ww_object(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    return array_map('ww_object',$value);
}
function ww_same(array $a,array $b): bool { return ww_object($a)===ww_object($b); }
function ww_snapshot(PDO $db, bool $grants = true): array {
    $out=[];
    foreach (array_merge(['tickets','westy_workflows','westy_workflow_receipts','messages','westy_billing_outbox'],$grants?['westy_workflow_grants']:[]) as $table)
        $out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
function ww_human_case(PDO $db,array $settings,array $base,int $seed): array {
    $claim=array_replace($base,['event_key'=>ww_key($seed),'workflow_key'=>ww_key($seed+1),'alert_key'=>'alert:'.($seed+2)]);
    $created=ww_send($db,$settings,$claim);
    $human=ww_send($db,$settings,array_replace($claim,['event_key'=>ww_key($seed+3),'action'=>'escalate','expected_version'=>1,'assignee_id'=>1]));
    $local=['action'=>'create','ticket_id'=>$created['ticket_id'],'workflow_key'=>$claim['workflow_key'],'expected_version'=>$human['version'],'grant_key'=>ww_key($seed+4)];
    $resume=['schema_version'=>2,'action'=>'resume','event_key'=>ww_key($seed+5),'workflow_key'=>$claim['workflow_key'],'tenant_slug'=>$claim['tenant_slug'],
        'customer_id'=>$claim['customer_id'],'alert_key'=>$claim['alert_key'],'expected_version'=>$human['version'],'grant_key'=>$local['grant_key'],
        'continuation_key'=>ww_key($seed+6),'summary'=>'Prior commands confirmed unsent; non-executing intent frozen.','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z')];
    $status=array_replace($resume,['action'=>'status','event_key'=>ww_key($seed+7),'expected_version'=>0,'grant_key'=>null,'continuation_key'=>null,'summary'=>'']);
    return [$local,$resume,$status];
}
$v2status=['schema_version'=>2,'action'=>'status','event_key'=>ww_key(2900),'workflow_key'=>$p['workflow_key'],'tenant_slug'=>'8west','customer_id'=>$customer,
    'alert_key'=>$p['alert_key'],'expected_version'=>0,'grant_key'=>null,'continuation_key'=>null,'summary'=>'','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z')];
ww_check(westy_workflow_request(json_encode($v2status))===$v2status,'v2 exact status envelope');
foreach (['grant_key'=>ww_key(2901),'continuation_key'=>ww_key(2902),'expected_version'=>1,'summary'=>'not empty','assignee_id'=>null,'job_id'=>null,'schema_version'=>3] as $key=>$value)
    ww_refuses(fn()=>westy_workflow_request(json_encode(array_replace($v2status,[$key=>$value]))),'v2 rejects invalid status '.$key);
ww_refuses(fn()=>westy_workflow_request(substr(json_encode($v2status),0,-1).',"grant_key":null}'),'v2 rejects duplicate nullable fields');
$oldRows=ww_snapshot($pdo,false);
ww_refuses(fn()=>ww_send($pdo,$settings,$v2status),'v2 explicitly refuses absent migration');
if ($mysql) {
    ww_sql($pdo,__DIR__.'/../db/migrations/036_westy_workflow_resume.sql');
    westy_workflow_resume_schema_ready($pdo);
    ww_sql($pdo,__DIR__.'/../db/migrations/036_westy_workflow_resume.sql');
} else {
    $pdo->exec("CREATE TABLE westy_workflow_grants(id INTEGER PRIMARY KEY,tenant_id INTEGER,workflow_id INTEGER,client_id INTEGER,customer_id TEXT,ticket_id INTEGER,workflow_key TEXT,alert_key TEXT,grant_key TEXT,assignee_id INTEGER,expected_version INTEGER,create_sha256 TEXT,create_response TEXT,created_at TEXT,cancelled_by INTEGER,cancelled_at TEXT,cancel_sha256 TEXT,cancel_response TEXT,consumed_event_key TEXT,continuation_key TEXT,consumed_at TEXT,pending_workflow_id INTEGER GENERATED ALWAYS AS (CASE WHEN cancelled_at IS NULL AND consumed_at IS NULL THEN workflow_id ELSE NULL END) STORED,UNIQUE(tenant_id,grant_key),UNIQUE(tenant_id,pending_workflow_id),UNIQUE(tenant_id,consumed_event_key),UNIQUE(tenant_id,continuation_key))");
}
ww_check(ww_snapshot($pdo,false)===$oldRows,'initial and repeated additive DDL preserve all old records and exact receipt bytes');
[$local,$resume,$fresh]=ww_human_case($pdo,$settings,$p,3000);
$pdo->exec("INSERT INTO users(id,tenant_id,is_active,role) VALUES(5,1,1,'tech')");
ww_check(westy_workflow_request(json_encode($resume))===$resume,'v2 exact resume envelope');
foreach (['expected_version'=>0,'grant_key'=>null,'continuation_key'=>$resume['event_key'],'assignee_id'=>1,'summary'=>'','action'=>'claim'] as $key=>$value)
    ww_refuses(fn()=>westy_workflow_request(json_encode(array_replace($resume,[$key=>$value]))),'v2 invalid resume '.$key);
ww_check(westy_workflow_grant_request(json_encode($local))===$local,'local exact envelope');
foreach (['expected_version'=>'2','ticket_id'=>0,'grant_key'=>'bad','action'=>'resume','extra'=>null] as $key=>$value)
    ww_refuses(fn()=>westy_workflow_grant_request(json_encode(array_replace($local,[$key=>$value]))),'local malformed '.$key);
ww_refuses(fn()=>westy_workflow_grant_request(substr(json_encode($local),0,-1).',"action":"create"}'),'local duplicate member');
$before=ww_snapshot($pdo);
$s=ww_send($pdo,$settings,$fresh);
$fixture=json_decode(file_get_contents(__DIR__.'/../../docs/contracts/westy-workflow-v2.json'),true,32,JSON_THROW_ON_ERROR);
$actualKeys=array_keys($s); $expectedKeys=$fixture['status_response_fields']; sort($actualKeys); sort($expectedKeys);
ww_check($actualKeys===$expectedKeys && count($s)===18,'v2 status complete exact response fields');
ww_check($s['pending_grant']===null && $s['accepted_continuation']===null && $s['receipt_id']===null && $s['replayed']===false,'status explicit nulls and no write receipt');
ww_check(ww_snapshot($pdo)===$before,'status without grant is read only');
ww_error(fn()=>westy_workflow_grant_action($pdo,1,5,$local),'return_not_available','only exact current assignee can grant');
ww_error(fn()=>westy_workflow_grant_action($pdo,2,2,$local),'workflow_not_found','local tenant isolation');
ww_error(fn()=>westy_workflow_grant_action($pdo,1,3,$local),'assignee_unavailable','inactive actor cannot grant');
$cardRun=westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=1 AND workflow_key=?',[$local['workflow_key']]);
$cardTicket=westy_workflow_row($pdo,'SELECT * FROM tickets WHERE tenant_id=1 AND id=?',[$local['ticket_id']]);
ww_check(westy_workflow_grant_controls($pdo,$cardRun,$cardTicket,['tenant_id'=>1,'id'=>1],true)===['grant_control'=>'create','grant_version'=>2],'real eligible assignee gets create control');
ww_check(westy_workflow_grant_controls($pdo,$cardRun,$cardTicket,['tenant_id'=>1,'id'=>5],true)===[],'other technician never sees return control');
ww_check(westy_workflow_grant_controls($pdo,$cardRun,$cardTicket,['tenant_id'=>1,'id'=>1],false)===[],'existing disabled integration does not offer new grants');
$create=westy_workflow_grant_action($pdo,1,1,$local);
ww_check(westy_workflow_grant_controls($pdo,$cardRun,$cardTicket,['tenant_id'=>1,'id'=>1],false)['grant_control']==='cancel','original creator can withdraw intent even while controller disabled');
ww_check(ww_snapshot($pdo,false)===array_diff_key($before,['westy_workflow_grants'=>true]),'grant changes no ticket/run/receipt/message/billing');
ww_check(ww_same($create,westy_workflow_grant_action($pdo,1,1,array_reverse($local,true))),'canonical exact create retry returns frozen result');
ww_error(fn()=>westy_workflow_grant_action($pdo,1,1,array_replace($local,['expected_version'=>3])),'grant_key_conflict','changed same-key facts conflict');
ww_error(fn()=>westy_workflow_grant_action($pdo,1,1,array_replace($local,['grant_key'=>ww_key(3010)])),'pending_grant_exists','one pending per exact workflow');
$before=ww_snapshot($pdo); $s=ww_send($pdo,$settings,$fresh);
ww_check($s['pending_grant']===['grant_key'=>$local['grant_key'],'assignee_id'=>1,'expected_version'=>2,'created_at'=>$create['created_at']],'discover exact durable pending grant');
ww_check(ww_snapshot($pdo)===$before,'grant discovery writes nothing');
$cancel=array_replace($local,['action'=>'cancel']);
ww_error(fn()=>westy_workflow_grant_action($pdo,1,5,$cancel),'grant_key_conflict','another technician cannot cancel');
$cancelled=westy_workflow_grant_action($pdo,1,1,$cancel);
ww_check(ww_same($cancelled,westy_workflow_grant_action($pdo,1,1,$cancel)),'cancel retry frozen');
ww_error(fn()=>ww_send($pdo,$settings,$resume),'grant_conflict','cancelled grant cannot consume');
ww_check(ww_same(westy_workflow_grant_action($pdo,1,1,$local),$create),'old create retry never uncancels');
ww_check(ww_send($pdo,$settings,$fresh)['pending_grant']===null,'cancel removed only from discovery');
$local['grant_key']=ww_key(3011); $resume['grant_key']=$local['grant_key'];
$create2=westy_workflow_grant_action($pdo,1,1,$local);
foreach (['customer_id'=>$otherCustomer,'alert_key'=>'alert:999999','workflow_key'=>ww_key(999998),'grant_key'=>ww_key(999997)] as $key=>$value) {
    $expected=match($key){'customer_id'=>'active_customer_binding_required','alert_key'=>'workflow_scope_conflict','workflow_key'=>'workflow_not_found',default=>'grant_conflict'};
    ww_error(fn()=>ww_send($pdo,$settings,array_replace($resume,[$key=>$value])),$expected,'v2 exact '.$key.' binding');
}
$before=ww_snapshot($pdo);
// Deliberate whitespace proves the receipt binds exact raw bytes, not a JSON re-encoding.
$rawResume=" \n".json_encode($resume,JSON_THROW_ON_ERROR)."\n";
$resumeHash=hash('sha256',$rawResume);
$accepted=westy_workflow_receive($pdo,$settings,westy_workflow_request($rawResume),$resumeHash);
$keys=array_keys($accepted); $expected=$fixture['resume_response_fields']; sort($keys); sort($expected);
ww_check($keys===$expected && count($accepted)===18,'resume exact frozen response shape');
ww_check($accepted['version']===3 && $accepted['state']==='working' && $accepted['request_sha256']===$resumeHash && $accepted['replayed']===false,'resume one trigger-accounted V increment plus exact request hash');
$run=westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=1 AND workflow_key=?',[$resume['workflow_key']]);
$ticket=westy_workflow_row($pdo,'SELECT * FROM tickets WHERE tenant_id=1 AND id=?',[$local['ticket_id']]);
ww_check($ticket['status']==='in_progress' && $ticket['assignee_id']===null && (int)$ticket['auto_close_eligible']===0,'same ticket returned without legacy closure');
foreach ($before['westy_workflows'] as $old) if ($old['workflow_key']===$run['workflow_key']) {
    foreach (['id','tenant_id','client_id','customer_id','ticket_id','workflow_key','alert_key','started_at'] as $key) ww_check($run[$key]===$old[$key],'resume preserves '.$key);
}
$saved=westy_workflow_row($pdo,'SELECT response_json FROM westy_workflow_receipts WHERE tenant_id=1 AND event_key=?',[$resume['event_key']]);
ww_check(ww_same(json_decode($saved['response_json'],true),$accepted),'complete original receipt persisted');
$after=ww_snapshot($pdo);
$replayed=westy_workflow_receive($pdo,$settings,westy_workflow_request($rawResume),$resumeHash);
ww_check(ww_same($replayed,array_replace($accepted,['replayed'=>true])) && ww_snapshot($pdo)===$after,'exact resume retry only flips returned replay marker');
ww_error(fn()=>ww_send($pdo,$settings,$resume),'event_key_conflict','same decoded request with different bytes cannot reuse event');
ww_error(fn()=>westy_workflow_grant_action($pdo,1,1,array_replace($local,['action'=>'cancel'])),'grant_not_cancellable','consumed cannot cancel');
ww_check(ww_same(westy_workflow_grant_action($pdo,1,1,$local),$create2),'consumed create retry returns its original result without reuse');
$historical=ww_send($pdo,$settings,$fresh);
ww_check(ww_same($historical['accepted_continuation'],['grant_key'=>$resume['grant_key'],'continuation_key'=>$resume['continuation_key'],'event_key'=>$resume['event_key'],'version'=>3,'accepted_at'=>$accepted['accepted_at'],'request_sha256'=>$resumeHash,'original_receipt'=>$accepted]),'fresh status recovers original receipt and request hash');
$expired=$resume; $expired['occurred_at']=gmdate('Y-m-d\TH:i:s\Z',time()-86401);
ww_error(fn()=>westy_workflow_request(json_encode($expired)),'invalid_occurred_at','resume validity never extended for recovery');
$late=array_replace($fresh,['event_key'=>ww_key(3015),'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
ww_check(ww_send($pdo,$settings,$late)['accepted_continuation']===$historical['accepted_continuation'],'fresh status is independent of old request freshness');
$pdo->prepare('UPDATE tickets SET assignee_id=5 WHERE tenant_id=1 AND id=?')->execute([$local['ticket_id']]);
$owned=ww_send($pdo,$settings,$fresh);
ww_check($owned['state']==='human_owned' && $owned['version']===4 && $owned['ticket']['assignee_id']===5 && $owned['accepted_continuation']===$historical['accepted_continuation'],'historical proof never overwrites later human ownership');
ww_check(westy_workflow_receive($pdo,$settings,westy_workflow_request($rawResume),$resumeHash)===$replayed,'old immutable replay remains only historical');
foreach (['ticket','message','time','binding','actor'] as $i=>$kind) {
    [$local2,$resume2,$status2]=ww_human_case($pdo,$settings,$p,4000+$i*100);
    westy_workflow_grant_action($pdo,1,1,$local2);
    $beforeGrant=$pdo->query('SELECT * FROM westy_workflow_grants ORDER BY id')->fetchAll();
    $id=$local2['ticket_id'];
    if ($kind==='ticket') { $pdo->exec("UPDATE tickets SET assignee_id=5,priority='urgent' WHERE id=$id"); $pdo->exec("UPDATE tickets SET priority='normal' WHERE id=$id"); }
    if ($kind==='message') $pdo->exec("INSERT INTO messages(ticket_id,author_name,kind,body) VALUES($id,'Human','note','Human work')");
    if ($kind==='time') $pdo->exec("INSERT INTO time_entries(tenant_id,ticket_id) VALUES(1,$id)");
    if ($kind==='binding') $pdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE tenant_id=1");
    if ($kind==='actor') $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    if ($kind==='binding') ww_error(fn()=>ww_send($pdo,$settings,$status2),'active_customer_binding_required','inactive binding refuses complete v2 status');
    else ww_check(ww_send($pdo,$settings,$status2)['pending_grant']===null,'stale '.$kind.' grant hidden');
    ww_error(fn()=>ww_send($pdo,$settings,$resume2),match($kind){'binding'=>'active_customer_binding_required','actor'=>'return_not_available',default=>'version_conflict'},$kind.' defeats consumption');
    ww_check($pdo->query('SELECT * FROM westy_workflow_grants ORDER BY id')->fetchAll()===$beforeGrant,'invalid discovery/consume preserves stale record '.$kind);
    if ($kind==='actor') { ww_error(fn()=>westy_workflow_grant_action($pdo,1,1,array_replace($local2,['action'=>'cancel'])),'assignee_unavailable','inactive creator cannot cancel'); $pdo->exec('UPDATE users SET is_active=1 WHERE id=1'); }
    ww_error(fn()=>westy_workflow_grant_action($pdo,1,1,array_replace($local2,['action'=>'cancel','expected_version'=>99])),'grant_key_conflict','cancel binds original grant version');
    $cancel2=array_replace($local2,['action'=>'cancel']);
    $result2=westy_workflow_grant_action($pdo,1,1,$cancel2);
    ww_check(ww_same($result2,westy_workflow_grant_action($pdo,1,1,$cancel2)),'original creator can cancel despite changed '.$kind);
    if ($kind==='binding') $pdo->exec("UPDATE suite_customer_sync_bindings SET status='active' WHERE tenant_id=1");
}
[$local3,$resume3,$status3]=ww_human_case($pdo,$settings,$p,5000);
westy_workflow_grant_action($pdo,1,1,$local3);
[$otherLocal,$otherResume,$otherStatus]=ww_human_case($pdo,$settings,$p,5100);
ww_check(westy_workflow_grant_action($pdo,1,1,$otherLocal)['grant_state']==='pending','pending intent never blocks another workflow');
$pdo->prepare("UPDATE westy_workflows SET closed_at=?,job_id=1 WHERE tenant_id=1 AND workflow_key=?")->execute([gmdate('Y-m-d H:i:s'),$resume3['workflow_key']]);
ww_error(fn()=>ww_send($pdo,$settings,$resume3),'return_not_available','prior closure evidence defeats grant');
ww_check(ww_send($pdo,$settings,$status3)['pending_grant']===null,'closure evidence excludes pending discovery');
ww_check(count(ww_snapshot($pdo)['westy_billing_outbox'])===count($oldRows['westy_billing_outbox']),'continuations create no financial work');
// A separately seeded damaged historical fixture must not produce partial v2 proof.
[$badGrant,$badResume,$badStatus]=ww_human_case($pdo,$settings,$p,5200);
westy_workflow_grant_action($pdo,1,1,$badGrant);
$badRun=westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=1 AND workflow_key=?',[$badResume['workflow_key']]);
$pdo->prepare('INSERT INTO westy_workflow_receipts(tenant_id,workflow_id,event_key,request_sha256,action,version,response_json,received_at) VALUES(1,?,?,?,?,3,?,?)')
    ->execute([$badRun['id'],$badResume['event_key'],hash('sha256',json_encode($badResume)),'resume','{"schema_version":2}',gmdate('Y-m-d H:i:s')]);
$pdo->prepare('UPDATE westy_workflow_grants SET consumed_event_key=?,continuation_key=?,consumed_at=? WHERE tenant_id=1 AND grant_key=?')
    ->execute([$badResume['event_key'],$badResume['continuation_key'],gmdate('Y-m-d H:i:s'),$badGrant['grant_key']]);
ww_error(fn()=>ww_send($pdo,$settings,$badStatus),'workflow_v2_receipt_unavailable','partial stored receipt refuses status instead of reconstructing acceptance');
echo 'PASS ' . $checks . ' Westy workflow checks (' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ").\n";
