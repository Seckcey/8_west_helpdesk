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
$settings = westy_workflow_settings(['enabled'=>true,'hmac_secret'=>str_repeat('test-only-',6),'tenant_slugs'=>['msp-one'],'customer_ids'=>[$customer]]);
$p = ['schema_version'=>1,'event_key'=>ww_key(1),'workflow_key'=>ww_key(2),'tenant_slug'=>'msp-one','customer_id'=>$customer,'alert_key'=>'alert:42','action'=>'claim','expected_version'=>0,'summary'=>'Investigating disk alert; no command has run.','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),'evidence_sha256'=>null,'verification_method'=>null,'job_id'=>null,'job_completed_at'=>null,'alert_resolved_at'=>null,'assignee_id'=>null];
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
foreach ([['enabled'=>false],['hmac_secret'=>'short'],['tenant_slugs'=>[]],['customer_ids'=>[]],['customer_ids'=>['bad']]] as $change) ww_refuses(fn()=>westy_workflow_settings(array_replace($settings,$change)),'incomplete gate refused');
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
$pdo->exec("INSERT INTO tenants VALUES (1,'msp-one'),(2,'msp-two'); INSERT INTO clients VALUES (1,1),(2,2); INSERT INTO users(id,tenant_id,is_active) VALUES (1,1,1),(2,2,1),(3,1,0); INSERT INTO svc_identities VALUES (1,1,'milepost-workflow',1),(2,2,'milepost-workflow',1);");
$pdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES (?,?,?,?)')->execute([1,1,$customer,'active']);
$pdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES (?,?,?,?)')->execute([2,2,$otherCustomer,'active']);
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
echo 'PASS ' . $checks . ' Westy workflow checks (' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ").\n";
