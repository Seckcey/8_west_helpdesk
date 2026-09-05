<?php
/** The staff Time-page handoff, using a disposable database and fake Coastmark. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/coastmark_time_billing.php';
$checks = 0;
function billing_check(string $label, bool $condition): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($label);
    echo "ok {$checks} - {$label}\n";
}
function billing_refuses(string $label, callable $operation): void {
    try { $operation(); } catch (CoastmarkTimeExportValidationException $error) { billing_check($label, true); return; }
    billing_check($label, false);
}
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY,slug TEXT)');
$pdo->exec('CREATE TABLE clients(id INTEGER PRIMARY KEY,tenant_id INTEGER,name TEXT)');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,tenant_id INTEGER,role TEXT,is_active INTEGER)');
$pdo->exec('CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,customer_id TEXT,status TEXT)');
$pdo->exec('CREATE TABLE time_entries(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,ticket_id INTEGER,entry_key TEXT,source TEXT,worked_at TEXT,minutes INTEGER,note TEXT,billable INTEGER,approval_status TEXT,user_id INTEGER,reviewed_by_user_id INTEGER,reviewed_at TEXT)');
$pdo->exec('CREATE TABLE time_entry_approval_adjustments(id INTEGER PRIMARY KEY,tenant_id INTEGER,time_entry_id INTEGER,adjustment_key TEXT,version_no INTEGER,effective_minutes INTEGER,effective_billable INTEGER,reason TEXT,actor_user_id INTEGER,created_at TEXT)');
$pdo->exec('CREATE TABLE coastmark_time_export_claims(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,time_entry_id INTEGER,source_version INTEGER,event_key TEXT,predecessor_claim_id INTEGER,payload_sha256 TEXT,payload_json TEXT,created_by_user_id INTEGER,created_at TEXT,UNIQUE(tenant_id,time_entry_id,source_version))');
$pdo->exec('CREATE TABLE coastmark_time_export_receipts(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,claim_id INTEGER,operation_key TEXT,operation_kind TEXT,outcome TEXT,response_status INTEGER,response_sha256 TEXT,coastmark_event_id INTEGER,invoice_id INTEGER,invoice_line_id INTEGER,detail_code TEXT,created_at TEXT)');
$pdo->exec("INSERT INTO tenants VALUES(1,'8west'),(2,'other')");
$pdo->exec("INSERT INTO clients VALUES(11,1,'Lifestyle'),(21,2,'Other')");
$pdo->exec("INSERT INTO users VALUES(101,1,'tech',1),(102,1,'owner',1),(201,2,'owner',1)");
$pdo->exec("INSERT INTO suite_customer_sync_bindings VALUES(1,1,11,'11111111-1111-4111-8111-111111111111','active')");
$insert = $pdo->prepare('INSERT INTO time_entries VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([501,502,503] as $id) $insert->execute([$id,1,11,901,'timer:billing:'.$id,'timer','2026-09-05 08:00:00',30,'Support work',1,'approved',101,102,'2026-09-05 08:05:00']);
$insert->execute([601,2,21,902,'timer:other:601','timer','2026-09-05 08:00:00',20,'Other work',1,'approved',201,201,'2026-09-05 08:05:00']);
$config = ['claim_enabled'=>true,'enabled'=>true,'tenant_slugs'=>['8west'],'client_keys'=>['milepost-customer:11111111-1111-4111-8111-111111111111'],'endpoint'=>'https://coastmark.example.test/api/integrations/safeharbor/time-entries','status_endpoint'=>'https://coastmark.example.test/api/integrations/safeharbor/time-events/status','service'=>'safeharbor-time','secret'=>str_repeat('s',32),'timeout_seconds'=>5];
$owner = ['tenant_id'=>1,'id'=>102];
$requests = [];
$ack = static function (string $url, array $headers, string $body, int $timeout) use (&$requests, $pdo): array {
    $payload = json_decode($body,true,flags:JSON_THROW_ON_ERROR);
    $requests[] = $payload;
    $query = $pdo->prepare('SELECT * FROM coastmark_time_export_claims WHERE event_key=?');
    $query->execute([$payload['event_key']]);
    $claim = $query->fetch();
    return ['status'=>$payload['event']==='safeharbor.time_entry.status'?200:201,'body'=>json_encode(['ok'=>true,'action'=>(int)$claim['source_version']>0?'corrected':'created','event'=>['event_key'=>$claim['event_key'],'payload_sha256'=>$claim['payload_sha256'],'coastmark_event_id'=>91],'draft'=>['invoice_id'=>81,'invoice_line_id'=>71]],JSON_THROW_ON_ERROR)];
};
$state = coastmark_time_billing_operation($pdo,$owner,501,'send',$config,$ack);
billing_check('owner sends approved time through the existing signed source', count($requests)===1 && $requests[0]['event']==='safeharbor.time_entry.approved');
billing_check('page receives the real invoice link and completed state', $state['action']===null && $state['invoice_url']==='https://coastmark.example.test/invoices/81');
coastmark_time_billing_operation($pdo,$owner,501,'send',$config,$ack);
billing_check('a repeated click does not send or create a second claim', count($requests)===1 && (int)$pdo->query('SELECT COUNT(*) FROM coastmark_time_export_claims')->fetchColumn()===1);
billing_refuses('technician cannot send billing',fn()=>coastmark_time_billing_operation($pdo,['tenant_id'=>1,'id'=>101],502,'send',$config,$ack));
billing_refuses('another workspace cannot select this entry',fn()=>coastmark_time_billing_operation($pdo,['tenant_id'=>2,'id'=>201],501,'send',$config,$ack));
$pdo->exec("UPDATE time_entries SET approval_status='pending' WHERE id=503");
billing_refuses('pending time cannot be sent',fn()=>coastmark_time_billing_operation($pdo,$owner,503,'send',$config,$ack));
billing_check('ordinary role, tenant and approval refusals made no network requests', count($requests)===1);
try { coastmark_time_billing_operation($pdo,$owner,502,'send',$config,static fn()=>throw new RuntimeException('connection interrupted')); }
catch (CoastmarkTimeExportAmbiguousException $error) {}
$entry = $pdo->query('SELECT * FROM time_entries WHERE id=502')->fetch();
$row = coastmark_time_billing_entries($pdo,1,[502])[502];
$state = coastmark_time_billing_state($entry,$row,0,$config);
billing_check('an interrupted delivery shows Check billing status', $state['action']==='status');
$state = coastmark_time_billing_operation($pdo,$owner,502,'status',$config,$ack);
billing_check('status resolves the existing invoice without resending time', $state['action']===null && end($requests)['event']==='safeharbor.time_entry.status');
$pdo->exec("INSERT INTO time_entry_approval_adjustments VALUES(1,1,501,'adjustment:billing:501',1,20,1,'Correct duration',102,'2026-09-05 08:10:00')");
$state = coastmark_time_billing_operation($pdo,$owner,501,'send',$config,$ack);
billing_check('an approved correction follows the existing adjustment contract', end($requests)['event']==='safeharbor.time_entry.adjusted' && end($requests)['minutes']===20 && $state['action']===null);
billing_check('billing visibility stays within the current workspace', !isset(coastmark_time_billing_entries($pdo,1,[601])[601]));

$manualAck = static function (string $url, array $headers, string $body, int $timeout) use ($ack): array {
    $response = $ack($url, $headers, $body, $timeout);
    $payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    $payload['action'] = 'manual_exception';
    $payload['draft']['invoice_line_id'] = null;
    $response['body'] = json_encode($payload, JSON_THROW_ON_ERROR);
    return $response;
};
$pdo->exec("INSERT INTO time_entry_approval_adjustments VALUES(2,1,502,'adjustment:billing:502:1',1,20,1,'Correct duration after invoice review',102,'2026-09-05 08:10:00')");
$state = coastmark_time_billing_operation($pdo,$owner,502,'send',$config,$manualAck);
$priorClaim = $pdo->query('SELECT * FROM coastmark_time_export_claims WHERE time_entry_id=502 AND source_version=1')->fetch();
billing_check('a non-draft correction points to manual review without resending it', $state['action']===null && $state['label']==='Review correction in Coastmark' && $state['invoice_url']==='https://coastmark.example.test/invoices/81');
$pdo->exec("INSERT INTO time_entry_approval_adjustments VALUES(3,1,502,'adjustment:billing:502:2',2,15,1,'Further approved correction',102,'2026-09-05 08:15:00')");
$entry = $pdo->query('SELECT * FROM time_entries WHERE id=502')->fetch();
$row = coastmark_time_billing_entries($pdo,1,[502])[502];
$state = coastmark_time_billing_state($entry,$row,2,$config);
billing_check('a later correction remains available after manual review is needed', $state['action']==='send' && $state['button']==='Send next adjustment' && str_contains($state['label'],'Review correction in Coastmark'));
$beforeRequests = count($requests);
$state = coastmark_time_billing_operation($pdo,$owner,502,'send',$config,$manualAck);
$latest = end($requests);
billing_check('the later correction sends its next exact version and acknowledged predecessor', count($requests)===$beforeRequests+1 && $latest['source_version']===2 && $latest['minutes']===15 && $latest['predecessor_event_key']===$priorClaim['event_key'] && $state['action']===null);
$preserved = $pdo->query('SELECT * FROM coastmark_time_export_claims WHERE time_entry_id=502 AND source_version=1')->fetch();
billing_check('the earlier correction and its receipt remain unchanged', $preserved===$priorClaim && $pdo->query('SELECT outcome FROM coastmark_time_export_receipts WHERE claim_id='.(int)$priorClaim['id'].' ORDER BY id DESC LIMIT 1')->fetchColumn()==='manual_exception');
coastmark_time_billing_operation($pdo,$owner,502,'send',$config,$manualAck);
billing_check('repeating the delivered correction makes no extra request or claim', count($requests)===$beforeRequests+1 && (int)$pdo->query('SELECT COUNT(*) FROM coastmark_time_export_claims WHERE time_entry_id=502')->fetchColumn()===3);
echo "Time-page billing: {$checks} checks passed\n";
