<?php
/** S2/S3/S9/S10/S12: local fixtures and in-memory SQLite. */
declare(strict_types=1);
require_once __DIR__.'/fixtures/westy_work_log.php';
require_once __DIR__.'/../lib/westy_hardware.php';
require_once __DIR__.'/../lib/westy_billing.php';
function h(?string $s): string { return htmlspecialchars($s ?? '',ENT_QUOTES,'UTF-8'); }
function utf8_clean(string $s): string { return mb_scrub($s, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic">'; }
$vectors = wl_vectors(); $now = strtotime('2026-11-17T12:00:00Z');
$log = westy_workflow_v4_request($vectors['log_body'],$now);
$qa = westy_workflow_v4_request($vectors['qa_body'],$now);
wl_check(westy_workflow_request($vectors['log_body'],$now) === $log,'v4 dispatch accepts canonical log');
wl_check(westy_workflow_request($vectors['qa_body'],$now) === $qa,'v4 dispatch accepts canonical QA');
wl_check(westy_work_log_genesis($log['workflow_key']) === $vectors['genesis'],'fixed genesis');
$previous = $vectors['genesis']; $entries = [];
foreach (wl_vector_entries() as $i => $entry) {
    $hash = westy_work_log_entry_hash($previous,$entry);
    wl_check($hash === $vectors['entry_sha256'][$i],'fixed entry hash '.$i);
    foreach ($entry as $key => $value) {
        $mutated = $entry;
        $mutated[$key] = is_bool($value) ? !$value : (is_int($value) ? $value+1 : ($value === null ? 'x' : $value.'x'));
        wl_check(westy_work_log_entry_hash($previous,$mutated) !== $hash,'each hashed field matters: '.$i.'/'.$key);
    }
    $entry['entry_sha256'] = $hash; $entries[] = $entry; $previous = $hash;
}
wl_check($entries === $log['entries'] && $previous === $log['log_sha256'],'fixed entry fields and seal');
foreach (['log','qa'] as $action) {
    $body = $vectors[$action.'_body'];
    wl_check(westy_work_log_json($action === 'log' ? array_replace($log,['entries'=>$entries]) : $qa) === $body,$action.' canonical bytes');
    $headers = ['service'=>WESTY_WORKFLOW_SERVICE,'timestamp'=>'1795000000','signature'=>$vectors[$action.'_signature']];
    wl_check(hash_hmac('sha256',WESTY_WORKFLOW_CONTEXT."\n1795000000\n".$body,str_repeat('s',40)) === $headers['signature'],$action.' fixed HMAC');
    wl_check(westy_workflow_authenticated(wl_settings(),$headers,$body,1795000000),$action.' auth interop');
    wl_check(!westy_workflow_authenticated(wl_settings(),$headers,$body.' ',1795000000),$action.' changed bytes');
    wl_check(!westy_workflow_authenticated(wl_settings(),$headers,$body,1795000301),$action.' stale timestamp');
    $headers['signature'] = str_repeat('0',64);
    wl_check(!westy_workflow_authenticated(wl_settings(),$headers,$body,1795000000),$action.' wrong signature');
}
$parse = static fn(array $p): array => westy_workflow_v4_request(westy_work_log_json($p),$now);
$bad = static function(array $p,string $code,string $label) use ($parse): void { wl_refuse(fn()=> $parse($p),$code,$label); };
foreach ([' '.$vectors['log_body'],$vectors['log_body']."\n",str_replace('"seq":1','"seq":1,"seq":1',$vectors['log_body']),str_replace('"schema_version":4','"schema_version":4,"schema_version":4',$vectors['log_body']),str_replace('café','caf\\u00e9',$vectors['log_body'])] as $raw) {
    wl_refuse(fn()=>westy_workflow_v4_request($raw,$now),'noncanonical_body','noncanonical or duplicate JSON refused');
}
foreach (['4',4.0,1,2,3] as $version) {
    $p = array_replace($log,['schema_version'=>$version]);
    // Preserve .0 deliberately: canonical PHP encoding normally removes it.
    $raw = $version === 4.0 ? str_replace('"schema_version":4','"schema_version":4.0',$vectors['log_body']) : westy_work_log_json($p);
    wl_refuse(fn()=>westy_workflow_request($raw,$now),'','legacy versions refuse the v4 shape');
    wl_refuse(fn()=>westy_workflow_v4_request($raw,$now),'','v4 refuses noninteger or legacy version');
}
wl_refuse(fn()=>westy_hardware_request($vectors['log_body'],$now),'','v3 direct parser refuses v4');
foreach ([[],array_fill(0,21,$log['entries'][0])] as $entriesBad) $bad(array_replace($log,['entries'=>$entriesBad]),'invalid_entries','batch size');
$p = $log; unset($p['alert_key']); $bad($p,'invalid_fields','missing envelope member');
$p = $log; $p['extra'] = true; $bad($p,'invalid_fields','extra envelope member');
$p = $log; $p['entries'][0]['extra'] = true; $bad($p,'invalid_entry_fields','extra entry member');
$p = $log; unset($p['entries'][0]['cause_code']); $bad($p,'invalid_entry_fields','missing entry member');
foreach (['seq'=>2,'kind'=>'execute','entry_sha256'=>str_repeat('A',64),'actor_key'=>'safeharbor-user:1','customer_visible'=>1] as $key => $value) {
    $p=$log; $p['entries'][0][$key]=$value;
    $bad($p,'invalid_'.$key,'bad '.$key);
}
foreach (['system:other','milepost-user:0','milepost-user:01','ai-staff:','ai-staff:UPPER','ai-staff:'.str_repeat('a',241)] as $actor) {
    $p=$log; $p['entries'][0]['actor_key']=$actor; $bad($p,'invalid_actor_key','actor grammar');
}
foreach (['system:milepost'=>'system','milepost-user:9'=>'user','ai-staff:8west:l1_tech:1'=>'ai_staff'] as $key=>$type) wl_check(westy_work_log_actor_type($key)===$type,'actor derived: '.$type);
foreach (['password: synthetic','Bearer synthetic','tokenrun'.str_repeat('a',40),'https://example.test/path','person@example.test','192.0.2.1'] as $summary) {
    $p=$log; $p['entries'][0]['summary']=$summary; $bad($p,'summary_unsafe','unsafe summary');
}
foreach (["a\nb","a\x00b","a\x7fb","a\u{0085}b",str_repeat('a ',500).'a','   '] as $summary) {
    $p=$log; $p['entries'][0]['summary']=$summary; $bad($p,'invalid_summary','invalid summary bounds/control');
}
$p=$log; $p['entries'][0]['summary']=str_repeat('a ',499).'ab'; wl_check($parse($p)['entries'][0]['summary']===$p['entries'][0]['summary'],'1000 characters retained without rewriting');
$p=$log; $p['entries'][0]['kind']='note'; $bad($p,'invalid_customer_visible','visibility limited to code kinds');
$p=$log; $p['sealed_seq']=null; $bad($p,'invalid_seal','paired seal');
$p=$log; $p['sealed_seq']=2; $bad($p,'invalid_seal','seal at last entry only');
$p=$qa; $p['verdict']='ready'; $bad($p,'invalid_verdict','QA cannot request send');
$p=$qa; $p['reason_code']='No-recurrence'; $bad($p,'invalid_reason_code','QA code grammar');
$p = $log;
foreach ([$now - 10 * 86400, $now + 30] as $entryTime) {
    $p['entries'][0]['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z', $entryTime);
    wl_check($parse($p)['entries'][0]['occurred_at'] === $p['entries'][0]['occurred_at'], 'entry time retained');
}
$p['entries'][0]['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z', $now + 31);
$bad($p, 'invalid_occurred_at', 'entry 31 seconds in the future');
$p['entries'][0]['occurred_at'] = '2026-11-07 12:00:00';
$bad($p, 'invalid_occurred_at', 'historical entry still needs the v1 UTC format');
foreach ([$log, $qa] as $request) {
    $request['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z', $now - 25 * 3600);
    $bad($request, 'invalid_occurred_at', $request['action'].' request 25 hours old');
    $request['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z', $now + 31);
    $bad($request, 'invalid_occurred_at', $request['action'].' request 31 seconds in the future');
}
$p=$log; $p['entries']=[];
for ($i=1;$i<=20;$i++) $p['entries'][]=array_replace($log['entries'][0],['seq'=>$i]);
$p['sealed_seq']=20; wl_check(count($parse($p)['entries'])===20,'maximum batch accepted');
$p=$log; $p['entries'][1]['seq']=3; $bad($p,'invalid_seq','interior sequence gap');
$p=$log; $p['first_seq']=501; $p['entries']=[array_replace($log['entries'][0],['seq'=>501,'kind'=>'note','customer_visible'=>false])]; $p['sealed_seq']=501;
wl_check($parse($p)['first_seq']===501,'single overflow note permitted');
$p['first_seq']=502; $p['entries'][0]['seq']=502; $p['sealed_seq']=502; $bad($p,'log_limit','case log ceiling');
wl_refuse(fn()=>westy_workflow_v4_request(str_repeat(' ',16385),$now),'invalid_body','16 KB cap');
wl_refuse(fn()=>westy_workflow_v4_request(str_replace('Print Spooler',"bad\xff",$vectors['log_body']),$now),'invalid_json','invalid UTF-8');
$p=array_reverse($log,true); $bad($p,'invalid_fields','canonical member order required');
$malicious = ['occurred_at'=>'2026-11-17 12:00:00','summary'=>'<script>alert("x")</script>','kind'=>'note','actor_type'=>'user','actor_key'=>'safeharbor-user:1','source'=>'staff'];
$staff=westy_work_log_timeline([$malicious]); $customer=westy_work_log_timeline([$malicious],true);
wl_check(!str_contains($staff,'<script>') && str_contains($staff,'&lt;script&gt;'),'staff escapes summary markup');
wl_check(!str_contains($customer,'<script>') && !str_contains($customer,'safeharbor-user:1') && !str_contains($customer,'Technician'),'customer renders time and summary only');
wl_check(westy_work_log_timeline([])==='' && westy_work_log_timeline([],hasWorkflow:true)==='', 'no rows or seal means no staff card');
wl_check(!str_contains($staff,'sealed') && !str_contains($staff,'QA:'), 'local steps without a workflow show only their history');
$pending = 'Not sealed yet. The log is sealed when the case ends.';
$mirror = array_replace($malicious,['source'=>'milepost','actor_key'=>'milepost-user:1']);
foreach ([westy_work_log_timeline([$malicious],hasWorkflow:true), westy_work_log_timeline([$malicious,$mirror])] as $unsealed) {
    wl_check(str_contains($unsealed,$pending) && !str_contains($unsealed,'QA:'), 'workflow or mirrored history shows an unsealed log without a verdict');
}
$state = ['log_sealed_seq'=>3,'qa_state'=>null,'qa_reason'=>null];
$sealOnly = westy_work_log_timeline([],state:$state);
wl_check(str_contains($sealOnly,'Sealed through step 3.') && !str_contains($sealOnly,'QA:'), 'seal alone shows a staff card without a verdict');
foreach (['passed'=>'passed','fault_zero'=>'our fault, no charge','void'=>'void'] as $verdict => $label) {
    $state['qa_state'] = $verdict;
    $state['qa_reason'] = '<unsafe>';
    $staff = westy_work_log_timeline([$mirror],state:$state,hasWorkflow:true);
    wl_check(str_contains($staff,'Sealed through step 3.') && str_contains($staff,'QA: '.$label.' [&lt;unsafe&gt;]')
        && !str_contains($staff,'<unsafe>'), 'staff sees the plain verdict and escaped bracketed reason for '.$verdict);
}
wl_check(westy_work_log_timeline([$malicious],true,$state,true) === $customer && westy_work_log_timeline([],true,$state,true) === '',
    'seal and verdict never appear in either customer view');
$form=westy_work_log_form(true,['summary'=>'</textarea><script>bad</script>']);
wl_check(str_contains($form,'name="csrf"') && !str_contains($form,'<script>') && !str_contains($form,'name="customer_visible"'),'form CSRF and escaping with no visibility control');
$migration = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../db/migrations/039_westy_work_log.sql'));
$schema = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../db/schema.sql'));
$block = explode("-- BEGIN S5 WORK LOG (039)\n", $schema, 2)[1];
$timeout = "SET SESSION lock_wait_timeout=60;\n";
wl_check(str_starts_with(preg_replace('/^--[^\n]*\n/m','',$migration), $timeout) && substr_count($migration,$timeout) === 1
    && $block === str_replace($timeout,'',$migration), 'migration starts with the session timeout and its schema block otherwise matches exactly');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('westy_work_log_schema_health', static fn(): int => 1, 0);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY,slug TEXT);
    CREATE TABLE svc_identities (id INTEGER PRIMARY KEY,tenant_id INTEGER,service TEXT,is_active INTEGER);
    CREATE TABLE suite_customer_sync_bindings (tenant_id INTEGER,client_id INTEGER,customer_id TEXT,status TEXT);
    CREATE TABLE tickets (id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,external_key TEXT);
    CREATE TABLE westy_workflows (id INTEGER PRIMARY KEY,tenant_id INTEGER,workflow_key TEXT,customer_id TEXT,alert_key TEXT,ticket_id INTEGER,state TEXT,evidence_sha256 TEXT,closed_at TEXT);
    CREATE TABLE westy_workflow_receipts (tenant_id INTEGER,event_key TEXT);
    CREATE TABLE westy_workflow_log_state (tenant_id INTEGER,workflow_id INTEGER,log_sha256 TEXT NOT NULL,log_sealed_seq INTEGER NOT NULL,
        qa_state TEXT,qa_reason TEXT,updated_at TEXT,PRIMARY KEY (tenant_id,workflow_id));
    CREATE TABLE westy_workflow_v4_receipts (tenant_id INTEGER,workflow_id INTEGER,event_key TEXT,request_sha256 TEXT,action TEXT,response_json TEXT,received_at TEXT,
        UNIQUE (tenant_id,event_key));
    CREATE TABLE westy_billing_outbox (id INTEGER PRIMARY KEY,tenant_id INTEGER,workflow_id INTEGER,event_key TEXT,state TEXT,payload_json TEXT,
        payload_sha256 TEXT,attempts INTEGER DEFAULT 0,detail_code TEXT,next_attempt_at TEXT,created_at TEXT,updated_at TEXT,UNIQUE (tenant_id,workflow_id));
    INSERT INTO tenants VALUES (1,'8west');
    INSERT INTO svc_identities VALUES (1,1,'milepost-workflow',1)");
$pdo->prepare("INSERT INTO suite_customer_sync_bindings VALUES (1,1,?,'active')")->execute([wl_log(1)['customer_id']]);
$makeBillingCase = static function (int $id, bool $seal = true, ?int $at = null) use ($pdo): array {
    $log = wl_log($id);
    $pdo->prepare('INSERT INTO tickets VALUES (?,1,1,?)')->execute([$id,$log['alert_key']]);
    $pdo->prepare("INSERT INTO westy_workflows VALUES (?,1,?,?,?,?,'resolved',?,?)")
        ->execute([$id,$log['workflow_key'],$log['customer_id'],$log['alert_key'],$id,str_repeat('b',64),gmdate('Y-m-d H:i:s',$at)]);
    if ($seal) {
        $pdo->prepare('INSERT INTO westy_workflow_log_state (tenant_id,workflow_id,log_sha256,log_sealed_seq) VALUES (1,?,?,1)')
            ->execute([$id,$log['log_sha256']]);
    }
    return [$log,$id];
};
wl_qa_billing_cases($pdo, $makeBillingCase);
// Lower IDs and the same customer across tenants must not consume any of the ten release slots.
$pdo->exec("INSERT INTO tenants VALUES (2,'other');
    INSERT INTO westy_workflows (id,tenant_id,customer_id) VALUES (901,2,'11111111-1111-4111-8111-111111111111'),
        (902,1,'22222222-2222-4222-8222-222222222222');
    INSERT INTO westy_billing_outbox (tenant_id,workflow_id,state,created_at) VALUES
        (2,901,'qa_pending','2000-01-01 00:00:00'),(1,902,'qa_pending','2000-01-01 00:00:00')");
$otherRows = static fn(): array => $pdo->query('SELECT * FROM westy_billing_outbox WHERE workflow_id IN (901,902) ORDER BY id')->fetchAll();
$before = $otherRows();
wl_qa_timeout_cases($pdo, $makeBillingCase);
wl_check($before === $otherRows(), 'another tenant and another customer keep their entire outbox rows');

// Change two later candidates after discovery but before their turn under the tenant lock.
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => '2010-01-04 00:00:00', 0);
foreach ([1200,1201,1202] as $id) {
    $makeBillingCase($id, true, strtotime('2010-01-01 00:00:00 UTC'));
    $pdo->prepare("INSERT INTO westy_billing_outbox(tenant_id,workflow_id,state,created_at) VALUES(1,?,'qa_pending','2010-01-01 00:00:00')")->execute([$id]);
}
$pdo->exec("CREATE TRIGGER fixture_review_after_selection AFTER UPDATE ON westy_billing_outbox
    WHEN NEW.workflow_id=1200 AND NEW.detail_code='qa_not_received' BEGIN
        UPDATE westy_workflow_log_state SET qa_state='passed',qa_reason='no_recurrence' WHERE tenant_id=1 AND workflow_id=1201;
        UPDATE westy_billing_outbox SET state='blocked',detail_code='qa_void' WHERE tenant_id=1 AND workflow_id=1202;
    END");
$config = ['enabled'=>true,'service'=>'safeharbor-billing','endpoint'=>WESTY_BILLING_ENDPOINT,'secret'=>str_repeat('t',40),
    'tenant_slugs'=>['8west'],'customer_ids'=>[wl_log(1)['customer_id']]];
wl_check(westy_billing_release_expired_qa($pdo,$config) === 1
    && $pdo->query('SELECT state FROM westy_billing_outbox WHERE tenant_id=1 AND workflow_id BETWEEN 1200 AND 1202 ORDER BY id')
        ->fetchAll(PDO::FETCH_COLUMN) === ['waiting_for_time','qa_pending','blocked'], 'a verdict or block after the ID scan wins over a stale timeout candidate');
echo 'PASS '.($GLOBALS['wl_checks'] ?? 0)." work log pure checks.\n";
