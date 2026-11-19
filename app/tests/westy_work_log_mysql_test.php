<?php
/** S1/S4-S12: disposable MySQL 8 only, real migration/guards, synthetic records. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST') ?: '127.0.0.1'; $port=getenv('SAFEHARBOR_WESTY_TEST_PORT') ?: '3306';
$base=getenv('SAFEHARBOR_WESTY_TEST_DB') ?: '';
if (!in_array($host,['127.0.0.1','localhost','::1'],true) || !ctype_digit($port) || (int)$port<1 || (int)$port>65535
    || preg_match('/\Asafeharbor_westy_test(?:_[a-z0-9_]+)?\z/D',$base)!==1 || strlen($base)>40) exit(2);
$database=$base.'_s5_'.bin2hex(random_bytes(4)); $fresh=$database.'_new';
$login=['user'=>getenv('SAFEHARBOR_WESTY_TEST_USER') ?: 'root','pass'=>getenv('SAFEHARBOR_WESTY_TEST_PASS') ?: ''];
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$login['user'],$login['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function utf8_clean(string $s): string { return mb_check_encoding($s,'UTF-8') ? $s : mb_convert_encoding($s,'UTF-8','Windows-1252'); }
function h(?string $s): string { return htmlspecialchars($s ?? '',ENT_QUOTES,'UTF-8'); }
// Real auth/CSRF are additionally exercised over HTTP by the copied application fixture below.
function csrf_valid(): bool { return ($GLOBALS['wl_csrf'] ?? false) === true; }
require_once __DIR__.'/fixtures/westy_work_log.php';
require_once __DIR__.'/../lib/westy_billing.php';
require_once __DIR__.'/../lib/ticket_lifecycle.php';
require_once __DIR__.'/sql_file.php';
$migration=__DIR__.'/../db/migrations/039_westy_work_log.sql';
$statements=test_sql_statements(file_get_contents($migration));
$GLOBALS['wl_csrf']=true;
function wl_count(PDO $pdo,string $table): int { return (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
function wl_case(PDO $pdo,int $n,string $state='working',?int $at=null): array
{
    $at ??= time();
    $p=wl_log($n); $now=gmdate('Y-m-d H:i:s',$at); $start=gmdate('Y-m-d H:i:s',$at-3600);
    $pdo->prepare("INSERT INTO tickets(tenant_id,client_id,subject,status,channel,external_key,auto_close_eligible,sla_due_at,created_at,updated_at,resolved_at) VALUES(1,1,'Synthetic S5 case',?,'alert',?,0,UTC_TIMESTAMP() + INTERVAL 1 HOUR,?,?,?)")
        ->execute([$state==='resolved'?'resolved':'in_progress',$p['alert_key'],$start,$start,$state==='resolved'?$now:null]);
    $ticket=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,evidence_sha256,job_id,job_completed_at,alert_resolved_at,closed_at,started_at,updated_at) VALUES(1,1,?,?,?,?,?,7,?,?,?,?,?,?,?,?)')
        ->execute([$p['customer_id'],$ticket,$p['workflow_key'],$p['alert_key'],$state,'Original evidence',str_repeat('b',64),12,$now,$now,$state==='resolved'?$now:null,$start,$start]);
    return $p;
}
function wl_run(PDO $pdo,array $p): array { return westy_workflow_row($pdo,'SELECT * FROM westy_workflows WHERE tenant_id=1 AND workflow_key=?',[$p['workflow_key']]); }
function wl_snapshot(PDO $pdo,array $p): array
{
    $run=wl_run($pdo,$p);
    return [array_intersect_key($run,array_flip(['state','version','updated_at','closed_at'])),
        westy_workflow_row($pdo,'SELECT * FROM tickets WHERE tenant_id=1 AND id=?',[$run['ticket_id']])];
}
function wl_v1_status(PDO $pdo,array $log): array
{
    $p=['schema_version'=>1,'event_key'=>wl_key(990001),'workflow_key'=>$log['workflow_key'],'tenant_slug'=>$log['tenant_slug'],
        'customer_id'=>$log['customer_id'],'alert_key'=>$log['alert_key'],'action'=>'status','expected_version'=>0,'summary'=>'',
        'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),'evidence_sha256'=>null,'verification_method'=>null,'job_id'=>null,
        'job_completed_at'=>null,'alert_resolved_at'=>null,'assignee_id'=>null];
    return wl_send($pdo,$p);
}
function wl_missing(PDO $pdo,array $p,bool $legacyWorks=true): void
{
    foreach ([$p,wl_qa($p)] as $request) wl_refuse(fn()=>wl_send($pdo,$request),'workflow_v4_unavailable','v4 refuses partial schema');
    wl_refuse(fn()=>westy_work_log_staff($pdo,1,1,(int)wl_run($pdo,$p)['ticket_id'],['kind'=>'note','summary'=>'Step.']),'workflow_v4_unavailable','staff refuses partial schema');
    wl_check(westy_work_log_rows($pdo,1,(int)wl_run($pdo,$p)['ticket_id'])===[],'timeline unavailable without v4 health');
    wl_check(westy_work_log_rows($pdo,1,(int)wl_run($pdo,$p)['ticket_id'],1)===[],'customer timeline unavailable without v4 health');
    if ($legacyWorks) wl_check(wl_v1_status($pdo,$p)['state']==='working','v1 survives missing v4 capability');
}
function wl_ddl(PDO $pdo,array $statements,string $prefix): void
{
    foreach ($statements as $sql) {
        $code=trim(preg_replace('/^\s*--.*$/m','',$sql));
        if (str_starts_with($code,$prefix)) { $pdo->exec($sql); return; }
    }
    throw new RuntimeException('Missing DDL fixture: '.$prefix);
}
function wl_legacy_definitions(PDO $pdo): array
{
    $triggers = $pdo->query("SELECT trigger_name,action_statement FROM information_schema.triggers
        WHERE trigger_schema=DATABASE() AND trigger_name IN ('trg_westy_workflow_identity_update','trg_westy_billing_identity_update')
        ORDER BY trigger_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $health = $pdo->query('SHOW CREATE FUNCTION westy_workflow_schema_health')->fetch();
    wl_check(count($triggers) === 2 && !empty($health['Create Function']), 'both 025 triggers and the health definition are readable');
    $workflowColumns = $pdo->query('SHOW COLUMNS FROM westy_workflows')->fetchAll();
    $workflowTriggers = $pdo->query("SELECT trigger_name,action_statement FROM information_schema.triggers
        WHERE trigger_schema=DATABASE() AND event_object_table='westy_workflows' ORDER BY trigger_name")->fetchAll();
    return [$triggers, $health['Create Function'], $workflowColumns, $workflowTriggers];
}
function wl_draft(PDO $pdo, int $ticket): int
{
    $pdo->prepare("INSERT INTO westy_email_drafts(tenant_id,ticket_id,client_id,contact_id,recipient,subject,body_text,context_sha256,request_key,created_by)
        VALUES(1,?,1,1,'synthetic@example.test','Synthetic','Reviewed text',?,?,1)")
        ->execute([$ticket, str_repeat('a', 64), bin2hex(random_bytes(16))]);
    return (int)$pdo->lastInsertId();
}

/** Session counters catch even a redundant UPDATE within the same timestamp second. */
function wl_check_replay(PDO $pdo, array $request, array $receipt): void
{
    $writes = static fn(): array => $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_replace')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $before = $writes();
    $replay = wl_send($pdo, $request);
    wl_check(count($before) === 4 && $writes() === $before && $receipt['replayed'] === false && $replay['replayed'] === true
        && $replay == array_replace($receipt, ['replayed'=>true]), $request['action'].' replays its stored response with no DML');
}
function wl_submit_draft(PDO $pdo, int $draft): void
{
    $pdo->prepare("UPDATE westy_email_drafts SET state='submitted',approved_by=1,approved_at=UTC_TIMESTAMP(),attempted_at=UTC_TIMESTAMP(),provider_http=202 WHERE id=?")
        ->execute([$draft]);
}

/** Called on the fresh install, with the real message, workflow and both mail trigger sets. */
function wl_mail_mirroring_cases(PDO $pdo): void
{
    $pdo->exec("INSERT INTO svc_identities (tenant_id,service,display_name) VALUES (1,'milepost-workflow','Synthetic workflow')");
    $pdo->exec("INSERT INTO contacts (id,client_id,name,email) VALUES (1,1,'Synthetic contact','synthetic@example.test')");
    $customer = wl_log(778)['customer_id'];
    $pdo->prepare("INSERT INTO suite_customer_sync_bindings
        (tenant_id,client_id,customer_id,source_version,display_name,status,last_event_id,last_occurred_at,last_request_sha256)
        VALUES (1,1,?,1,'Synthetic client','active',?,UTC_TIMESTAMP(),?)")
        ->execute([$customer,wl_key(800778),str_repeat('b',64)]);

    // The full schema permits exactly one conversation per ticket. Exercise both states with their own ticket and email draft.
    foreach (['draft','approved'] as $i => $mailState) {
        $number = 778 + $i;
        $first = wl_case($pdo, $number, 'resolved');
        $workflow = wl_run($pdo, $first);
        $id = (int)$workflow['id'];
        $ticket = (int)$workflow['ticket_id'];
        $draft = wl_draft($pdo, $ticket);
        $hash = str_repeat('a',64);
        $pdo->prepare("INSERT INTO westy_mail_conversations
            (tenant_id,ticket_id,client_id,customer_id,contact_id,recipient,sender,subject,body_text,mail_identity_sha256,
             message_sha256,context_sha256,authority_sha256,reply_token_sha256,request_key,message_key,created_by)
            VALUES (1,?,1,?,1,'synthetic@example.test','westy@example.test','Synthetic','Draft',?,?,?,?,?,?,?,1)")
            ->execute([$ticket,$customer,$hash,$hash,$hash,$hash,$hash,bin2hex(random_bytes(16)),bin2hex(random_bytes(16))]);
        $conversation = (int)$pdo->lastInsertId();
        if ($mailState === 'approved') {
            $pdo->prepare("UPDATE westy_mail_conversations SET state='approved',approved_by=1,approved_at=UTC_TIMESTAMP(),approval_session_version='1.1' WHERE id=?")
                ->execute([$conversation]);
            $pdo->prepare("INSERT INTO westy_billing_outbox (tenant_id,workflow_id,event_key,state) VALUES (1,?,'s5-cron-fault','qa_pending')")->execute([$id]);
        }
        $pdo->prepare("INSERT INTO westy_mail_delegations (conversation_id,template_id,template_version,template_sha256,expires_at,approved_by)
            VALUES (?,'acknowledgment',1,?,UTC_TIMESTAMP() + INTERVAL 7 DAY,1)")->execute([$conversation,$hash]);
        $delegation = (int)$pdo->lastInsertId();
        $mail = static fn(): array => [
            westy_workflow_row($pdo,'SELECT * FROM westy_mail_conversations WHERE id=?',[$conversation]),
            westy_workflow_row($pdo,'SELECT * FROM westy_email_drafts WHERE id=?',[$draft]),
            westy_workflow_row($pdo,'SELECT reason FROM westy_mail_conversation_revocations WHERE conversation_id=?',[$conversation]),
            westy_workflow_row($pdo,'SELECT reason FROM westy_mail_delegation_revocations WHERE delegation_id=?',[$delegation]),
        ];
        $before = $mail();
        wl_check($before[0]['state'] === $mailState && $before[1]['state'] === 'draft' && $before[2] === null && $before[3] === null,
            'mail starts live in the '.$mailState.' case');
        $messages = wl_count($pdo,'messages');
        $second = wl_log($number,$first['log_sha256'],2);
        $qa = wl_qa($second, $mailState === 'approved' ? 'fault_zero' : 'passed');
        $third = wl_log($number,$second['log_sha256'],3);
        foreach ([$first,$second,$qa,$third] as $request) {
            $receipt = wl_send($pdo, $request);
            wl_check_replay($pdo, $request, $receipt);
            wl_check($mail() === $before && wl_run($pdo,$first) === $workflow && wl_count($pdo,'messages') === $messages,
                $request['action'].' preserves '.$mailState.' mail, both revocation ledgers, workflow version/timestamps and messages');
        }
        $recorded = westy_work_log_state($pdo,1,$id);
        wl_check((int)$recorded['log_sealed_seq'] === 3 && $recorded['log_sha256'] === $third['log_sha256']
            && $recorded['qa_state'] === $qa['verdict'] && $recorded['qa_reason'] === $qa['reason_code'], 're-seal after QA preserves its verdict and reason');
        if ($qa['verdict'] === 'fault_zero') {
            wl_check(westy_workflow_row($pdo,'SELECT state,detail_code FROM westy_billing_outbox WHERE tenant_id=1 AND workflow_id=?',[$id])
                === ['state'=>'blocked','detail_code'=>'qa_fault_zero'], 'actual cron fixture contains a charge blocked by the QA receiver');
        }
        westy_work_log_staff($pdo,1,1,$ticket,['kind'=>'note','summary'=>'Technician took over the case.']);
        $after = $mail();
        $run = wl_run($pdo,$first);
        wl_check($run['state'] === 'human_owned' && (int)$run['version'] === (int)$workflow['version'] + 1
            && $after[0]['state'] === 'revoked' && $after[2] === ['reason'=>'workflow_changed'] && $after[3] === ['reason'=>'workflow_changed'],
            'staff takeover revokes the '.$mailState.' conversation and delegation through the legacy workflow trigger');
        wl_check($after[1]['state'] === 'draft', 'workflow-only takeover on an already resolved ticket preserves its separate email draft');
    }
}

function wl_schema_snapshot(PDO $pdo): array
{
    $result=[];
    foreach (['ticket_work_log','westy_workflow_v4_receipts','westy_workflow_log_state'] as $table) {
        $row=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
        $result[$table]=preg_replace('/ AUTO_INCREMENT=\d+/','',$row[1]);
    }
    $result['triggers']=$pdo->query("SELECT trigger_name,event_manipulation,event_object_table,action_timing,action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND (trigger_name LIKE 'trg_westy_work_log_%' OR trigger_name LIKE 'trg_westy_v4_%' OR trigger_name IN ('trg_westy_workflow_identity_update','trg_westy_billing_identity_update','trg_westy_workflow_log_insert','trg_westy_workflow_log_guard','trg_westy_workflow_log_delete','trg_westy_billing_qa_guard')) ORDER BY trigger_name")->fetchAll();
    $result['routines']=$pdo->query("SELECT routine_name,routine_definition FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_name IN ('westy_workflow_schema_health','westy_work_log_schema_health') ORDER BY routine_name")->fetchAll();
    $result['columns']=$pdo->query("SELECT table_name,column_name,column_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND (table_name='westy_workflow_log_state' OR (table_name='westy_billing_outbox' AND column_name='state')) ORDER BY table_name,column_name")->fetchAll();
    $result['checks']=$pdo->query("SELECT t.table_name,t.constraint_name,t.enforced,c.check_clause FROM information_schema.table_constraints t JOIN information_schema.check_constraints c ON c.constraint_schema=t.constraint_schema AND c.constraint_name=t.constraint_name WHERE t.constraint_schema=DATABASE() AND t.table_name='westy_billing_outbox' AND t.constraint_name='ck_westy_billing_qa_payload'")->fetchAll();
    return $result;
}
$runtimeName='wl_rt_'.bin2hex(random_bytes(4)); $runtimeCreated=false; $freshCreated=false;
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`"); $pdo->exec("SET time_zone='+00:00'");
    foreach ([
        'CREATE TABLE tenants(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(64) UNIQUE)',
        'CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,UNIQUE(tenant_id,id))',
        'CREATE TABLE svc_identities(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,service VARCHAR(64),is_active INT)',
        "CREATE TABLE suite_customer_sync_bindings(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),status VARCHAR(16),source_version INT DEFAULT 1,last_event_id VARCHAR(64) DEFAULT 'fixture-active')",
        'CREATE TABLE suite_customer_sync_events(tenant_id INT UNSIGNED,binding_id INT UNSIGNED,status VARCHAR(16))',
        'CREATE TABLE managed_customer_lifecycle_restore_receipts(tenant_id INT UNSIGNED,source_binding_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),source_version INT,source_event_id VARCHAR(64))',
        'CREATE TABLE suite_managed_providers(tenant_id INT UNSIGNED,provider_slug VARCHAR(64),lease_until DATETIME,owner_user_id INT UNSIGNED,owner_subject VARCHAR(64))',
        "CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,is_active INT,role VARCHAR(16) DEFAULT 'tech',suite_subject VARCHAR(64),email VARCHAR(190))",
        "CREATE TABLE tickets(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,subject VARCHAR(190),priority VARCHAR(16),status VARCHAR(16) DEFAULT 'open',assignee_id INT UNSIGNED NULL,merged_into_id INT UNSIGNED NULL,channel VARCHAR(16),external_key VARCHAR(128),auto_close_eligible INT,sla_due_at DATETIME NOT NULL,service_goal_target_id INT UNSIGNED NULL,created_at DATETIME,updated_at DATETIME,resolved_at DATETIME NULL,UNIQUE(tenant_id,id),UNIQUE(tenant_id,external_key))",
        'CREATE TABLE messages(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id INT UNSIGNED,author_name VARCHAR(128),kind VARCHAR(16),body TEXT,created_at DATETIME)',
        'CREATE TABLE time_entries(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,ticket_id INT UNSIGNED,client_id INT UNSIGNED,approval_status VARCHAR(16),billable INT)',
        'CREATE TABLE contacts(id INT UNSIGNED PRIMARY KEY,client_id INT UNSIGNED,tenant_id INT UNSIGNED)',
    ] as $sql) $pdo->exec($sql.' ENGINE=InnoDB');
    foreach (['025_westy_workflow.sql','026_westy_approved_email.sql','036_westy_workflow_resume.sql','001_mail_queue.sql','037_westy_hardware_outcomes.sql','038_westy_hardware_staff_notices.sql'] as $file) test_execute_sql_file($pdo,__DIR__.'/../db/migrations/'.$file);
    $pdo->exec("INSERT INTO tenants VALUES(1,'8west'),(2,'other')");
    $pdo->exec("INSERT INTO clients VALUES(1,1),(2,2),(3,1)");
    $pdo->exec("INSERT INTO users VALUES(1,1,1,'owner',NULL,'owner@example.test'),(2,1,1,'viewer',NULL,'viewer@example.test'),(3,2,1,'tech',NULL,'other@example.test')");
    $pdo->exec("INSERT INTO svc_identities VALUES(1,1,'milepost-workflow',1)");
    $pdo->prepare("INSERT INTO suite_customer_sync_bindings(tenant_id,client_id,customer_id,status) VALUES(1,1,?,'active'),(1,3,?,'active')")->execute(['11111111-1111-4111-8111-111111111111',wl_key(900)]);
    $probe=wl_case($pdo,10);
    // S1: original 038 health is not v4 readiness, even when legacy health is 1.
    wl_check((int)$pdo->query('SELECT westy_workflow_schema_health()')->fetchColumn()===1,'038 legacy health');
    wl_missing($pdo,$probe);
    $legacyDefinitions = wl_legacy_definitions($pdo);
    $runtimePassword = bin2hex(random_bytes(24));
    $pdo->exec("CREATE USER '$runtimeName'@'%' IDENTIFIED BY '$runtimePassword'");
    $runtimeCreated = true;
    $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$database`.* TO '$runtimeName'@'%'");
    $pdo->exec("GRANT EXECUTE ON FUNCTION `$database`.westy_workflow_schema_health TO '$runtimeName'@'%'");
    $runtime = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $runtimeName, $runtimePassword,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $grantWorkLog = "GRANT EXECUTE ON FUNCTION `$database`.westy_work_log_schema_health TO '$runtimeName'@'%'";
    $ticket = (int)wl_run($pdo, $probe)['ticket_id'];
    $historicalTime = strtotime($probe['occurred_at']) - 10 * 86400;
    $entry = $probe['entries'][0];
    $entry['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z', $historicalTime);
    $probe = wl_log(10, entries: [$entry]);
    $schema = null;
    foreach (['first apply', 'rerun'] as $pass) {
        $v4Unavailable = $pass === 'first apply';
        foreach ($statements as $index => $sql) {
            $pdo->exec($sql);
            $boundary = $pass.' statement '.($index + 1);
            wl_check((int)$runtime->query('SELECT westy_workflow_schema_health()')->fetchColumn() === 1, 'legacy health at '.$boundary);
            $status = wl_v1_status($runtime, $probe);
            wl_check($status['ok'] === true && $status['action'] === 'status' && $status['state'] === 'working', 'v1 status at '.$boundary);
            if (str_contains($sql, 'DROP FUNCTION IF EXISTS westy_work_log_schema_health')) $v4Unavailable = true;
            if ($v4Unavailable && $index !== array_key_last($statements)) {
                wl_refuse(fn()=>westy_work_log_schema_ready($pdo), 'workflow_v4_unavailable', 'v4 unavailable at '.$boundary);
            } else {
                westy_work_log_schema_ready($pdo);
            }
        }
        wl_check(wl_legacy_definitions($pdo) === $legacyDefinitions, '025 definitions byte-identical after '.$pass);
        $installed = wl_schema_snapshot($pdo);
        if ($pass === 'first apply') {
            $schema = $installed;
            wl_send($pdo, $probe);
            wl_submit_draft($pdo, wl_draft($pdo, $ticket));
        } else {
            wl_check($installed === $schema, '039 rerun preserves definitions');
        }
        // The account can still use its original grant. Only v4 requires a new grant.
        wl_missing($runtime, $probe);
        $pdo->exec($grantWorkLog);
        westy_work_log_schema_ready($runtime);
        $staffRows = westy_work_log_rows($runtime, 1, $ticket);
        $customerRows = westy_work_log_rows($runtime, 1, $ticket, 1);
        wl_check(count($staffRows) === 1 && count($customerRows) === 1
            && $customerRows[0]['occurred_at'] === gmdate('Y-m-d H:i:s', $historicalTime),
            'both timelines read the stored ten-day-old step with the new grant after '.$pass);
    }
    wl_check((int)$runtime->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchColumn()===0,'runtime has no trigger metadata');
    $newTriggers=['trg_westy_work_log_immutable_update','trg_westy_work_log_immutable_delete','trg_westy_work_log_insert_shape','trg_westy_work_log_insert_takeover','trg_westy_v4_receipt_immutable_update','trg_westy_v4_receipt_immutable_delete','trg_westy_workflow_log_insert','trg_westy_workflow_log_guard','trg_westy_workflow_log_delete','trg_westy_billing_qa_guard'];
    foreach ($newTriggers as $name) {
        $pdo->exec('DROP TRIGGER '.$name);
        wl_check((int)$pdo->query('SELECT westy_work_log_schema_health()')->fetchColumn()===0,'health detects missing '.$name);
        wl_missing($pdo,$probe);
        wl_ddl($pdo,$statements,'CREATE TRIGGER '.$name.' ');
    }
    foreach (['ticket_work_log','westy_workflow_v4_receipts','westy_workflow_log_state'] as $table) {
        $pdo->exec("RENAME TABLE $table TO {$table}_missing");
        wl_check((int)$pdo->query('SELECT westy_work_log_schema_health()')->fetchColumn()===0,'health detects missing table');
        wl_missing($pdo,$probe);
        $pdo->exec("RENAME TABLE {$table}_missing TO $table");
    }
    // Preserve data/constraints in a renamed original; a temporary projection omits each column.
    foreach (['westy_workflow_log_state'=>['tenant_id','workflow_id','log_sha256','log_sealed_seq','qa_state','qa_reason','updated_at'],
        'ticket_work_log'=>['id','tenant_id','ticket_id','workflow_id','source','source_event_key','seq','kind','actor_type','actor_key','summary','cause_code','evidence_sha256','milepost_ref','customer_visible','entry_sha256','occurred_at','created_at'],
        'westy_workflow_v4_receipts'=>['tenant_id','workflow_id','event_key','request_sha256','action','response_json','received_at']] as $table=>$removed) {
        $columns=$pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($removed as $column) {
            $keptColumns = array_diff($columns, [$column]);
            $projection = implode(',', array_map(static fn(string $name): string => '`'.str_replace('`','``',$name).'`', $keptColumns));
            $bodyError = null;
            $pdo->exec("RENAME TABLE $table TO {$table}_original");
            try {
                $pdo->exec("CREATE TABLE $table AS SELECT $projection FROM {$table}_original");
                wl_check((int)$pdo->query('SELECT westy_workflow_schema_health()')->fetchColumn()===1,'column fixture preserves legacy health');
                wl_check((int)$pdo->query('SELECT westy_work_log_schema_health()')->fetchColumn()===0,'health detects missing '.$table.'.'.$column);
                wl_missing($pdo,$probe);
            } catch (Throwable $error) {
                $bodyError = $error;
                throw $error;
            } finally {
                wl_cleanup($pdo, ["DROP TABLE IF EXISTS $table", "RENAME TABLE {$table}_original TO $table"], $bodyError);
            }
            westy_work_log_schema_ready($pdo);
        }
    }
    // Each case removes one capability. Drop the dependent CHECK for the ENUM type change.
    $dropPayloadCheck = 'ALTER TABLE westy_billing_outbox DROP CHECK ck_westy_billing_qa_payload';
    $pdo->exec($dropPayloadCheck.", MODIFY state ENUM('waiting_for_time','ready','sending','uncertain','accepted','blocked') NOT NULL DEFAULT 'waiting_for_time'");
    // Use 039's own prepared ADD to put its real payload check back on the narrowed ENUM.
    wl_ddl($pdo, $statements, 'SET @westy_039_ddl = IF(EXISTS (SELECT 1 FROM information_schema.table_constraints');
    $pdo->exec('PREPARE wl_payload_check FROM @westy_039_ddl');
    $pdo->exec('EXECUTE wl_payload_check');
    $pdo->exec('DEALLOCATE PREPARE wl_payload_check');
    $enforced = $pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema=DATABASE() AND table_name='westy_billing_outbox'
          AND constraint_name='ck_westy_billing_qa_payload' AND enforced='YES'")->fetchColumn();
    wl_check((int)$enforced === 1, 'ENUM fixture retains the enforced 039 payload check');
    wl_check((int)$pdo->query('SELECT westy_work_log_schema_health()')->fetchColumn()===0,'missing qa_pending enum'); wl_missing($pdo,$probe);
    $pdo->exec($dropPayloadCheck);
    wl_ddl($pdo, $statements, 'ALTER TABLE westy_billing_outbox');
    wl_check(wl_schema_snapshot($pdo)['columns'] === $schema['columns'], 'payload-check fixture retains the complete 039 ENUM');
    wl_check((int)$pdo->query('SELECT westy_work_log_schema_health()')->fetchColumn()===0,'missing qa_pending payload check'); wl_missing($pdo,$probe);
    test_execute_sql_file($pdo,$migration);
    westy_work_log_schema_ready($pdo);
    $pdo->exec('DROP FUNCTION westy_work_log_schema_health'); wl_missing($pdo,$probe);
    test_execute_sql_file($pdo,$migration);
    $pdo->exec($grantWorkLog);
    westy_work_log_schema_ready($runtime);

    // S4: same authorization ordering and exact errors for both actions.
    $authCase = wl_case($pdo, 11);
    foreach ([$authCase,wl_qa($authCase)] as $request) {
        wl_refuse(fn()=>wl_send($pdo,array_replace($request,['tenant_slug'=>'unknown']),array_replace(wl_settings(),['managed_providers_enabled'=>true])),'unauthorized','unknown tenant');
        wl_refuse(fn()=>wl_send($pdo,array_replace($request,['tenant_slug'=>'other'])),'unauthorized','managed provider disabled');
        wl_refuse(fn()=>wl_send($pdo,array_replace($request,['tenant_slug'=>'other']),array_replace(wl_settings(),['managed_providers_enabled'=>true])),'unauthorized','provider lease absent');
        $pdo->exec('UPDATE svc_identities SET is_active=0'); wl_refuse(fn()=>wl_send($pdo,$request),'unauthorized','inactive identity'); $pdo->exec('UPDATE svc_identities SET is_active=1');
        $pdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE client_id=1"); wl_refuse(fn()=>wl_send($pdo,$request),'active_customer_binding_required','inactive binding'); $pdo->exec("UPDATE suite_customer_sync_bindings SET status='active' WHERE client_id=1");
        $pdo->exec("INSERT INTO suite_customer_sync_events VALUES(1,1,'inactive')"); wl_refuse(fn()=>wl_send($pdo,$request),'active_customer_binding_required','non-operational historical customer'); $pdo->exec('DELETE FROM suite_customer_sync_events');
        wl_refuse(fn()=>wl_send($pdo,array_replace($request,['customer_id'=>wl_key(901)])),'active_customer_binding_required','missing customer binding');
        wl_refuse(fn()=>wl_send($pdo,array_replace($request,['customer_id'=>wl_key(900)])),'workflow_scope_conflict','wrong bound customer');
        wl_refuse(fn()=>wl_send($pdo,array_replace($request,['alert_key'=>'alert:999999'])),'workflow_scope_conflict','wrong alert');
        wl_refuse(fn()=>wl_send($pdo,array_replace($request,['workflow_key'=>wl_key(999999)])),'workflow_not_found','unknown workflow');
    }
    // Moving a separate ticket leaves both customer bindings and the original workflow identity intact.
    $movedCase = wl_case($pdo, 12);
    $pdo->prepare('UPDATE tickets SET client_id=3 WHERE tenant_id=1 AND id=?')->execute([wl_run($pdo, $movedCase)['ticket_id']]);
    foreach ([$movedCase, wl_qa($movedCase)] as $request) {
        wl_refuse(fn()=>wl_send($pdo,$request),'ticket_customer_conflict','wrong ticket client');
    }

    // S5/S6: every run state, remote human decision, seal, exact replay, no ticket/version write.
    foreach (['working','needs_human','human_owned','resolved'] as $i=>$state) {
        $p=wl_case($pdo,20+$i,$state); $entry=$p['entries'][0]; unset($entry['entry_sha256']);
        $entry['actor_key']='milepost-user:17'; $entry['kind']='decision'; $entry['customer_visible']=false;
        $p=wl_log(20+$i,'',1,[$entry]); $before=wl_snapshot($pdo,$p); $messages=wl_count($pdo,'messages');
        $receipt=wl_send($runtime,$p); $after=wl_snapshot($pdo,$p);
        wl_check($before===$after,'mirrored human decision preserves '.$state.' ticket/state/version/timestamps');
        wl_check($receipt['schema_version']===4 && count($receipt)===13 && $receipt['stored_through']===1 && $receipt['log_sha256']===$p['log_sha256'],'exact log receipt');
        $stored=westy_workflow_row($pdo,'SELECT * FROM ticket_work_log WHERE tenant_id=1 AND workflow_id=?',[wl_run($pdo,$p)['id']]);
        wl_check($stored['actor_type']==='user' && $stored['source']==='milepost','truthful mirrored user attribution');
        wl_check_replay($runtime, $p, $receipt);
        wl_check(wl_count($pdo,'messages') === $messages, 'seal and replay leave the ticket conversation alone');
        $changed=$p; $changed['entries'][0]['summary']='Changed under same event';
        wl_refuse(fn()=>wl_send($pdo,$changed),'event_key_conflict','same event different bytes');
        if ($state==='resolved') {
            $before=wl_snapshot($pdo,$p); $qaReceipt=wl_send($runtime,wl_qa($p));
            wl_check($before===wl_snapshot($pdo,$p) && $qaReceipt['qa_state']==='passed' && count($qaReceipt)===12,'QA preserves resolved row identity/state/version and ticket');
        } else {
            $before=wl_snapshot($pdo,$p); wl_refuse(fn()=>wl_send($pdo,wl_qa($p)),'workflow_not_resolved','QA refuses '.$state);
            wl_check($before===wl_snapshot($pdo,$p),'refused QA changes nothing');
        }
    }
    $p=wl_case($pdo,40); $r=wl_send($pdo,$p); $run=wl_run($pdo,$p);
    $pdo->exec('UPDATE svc_identities SET is_active=0');
    wl_refuse(fn()=>wl_send($pdo,$p),'unauthorized','even exact replay requires current authorization');
    $pdo->exec('UPDATE svc_identities SET is_active=1');
    wl_refuse(fn()=>wl_send($pdo,wl_log(40,$p['log_sha256'],3)),'log_sequence_gap','gap refused');
    $bad=wl_log(40,$p['log_sha256'],2); $bad['entries'][0]['summary']='Altered after hash';
    $count=wl_count($pdo,'ticket_work_log'); wl_refuse(fn()=>wl_send($pdo,$bad),'log_chain_mismatch','altered chain refused');
    $bad=wl_log(40,$p['log_sha256'],2); $bad['log_sha256']=str_repeat('0',64);
    wl_refuse(fn()=>wl_send($pdo,$bad),'log_seal_mismatch','wrong seal refuses whole batch');
    wl_check(wl_count($pdo,'ticket_work_log')===$count,'failed batch inserts roll back');
    $two=wl_log(40,$p['log_sha256'],2); $firstEntry=$two['entries'][0]; unset($firstEntry['entry_sha256']);
    $secondEntry=array_replace($firstEntry,['seq'=>3]); $two=wl_log(40,$p['log_sha256'],2,[$firstEntry,$secondEntry]);
    $two['entries'][1]['entry_sha256']=str_repeat('0',64);
    wl_refuse(fn()=>wl_send($pdo,$two),'log_chain_mismatch','late entry failure rolls back earlier entries');
    wl_check(wl_count($pdo,'ticket_work_log')===$count,'multi-entry transaction is atomic');
    $next=wl_log(40,$p['log_sha256'],2); wl_send($pdo,$next);
    wl_check((int)westy_work_log_state($pdo,1,(int)$run['id'])['log_sealed_seq']===2,'forward checkpoint');
    $workflowGuard = 'Westy work log seal and QA are forward-only';
    wl_sql_refuse(fn()=> $pdo->prepare('UPDATE westy_workflow_log_state SET log_sealed_seq=1 WHERE tenant_id=1 AND workflow_id=?')->execute([$run['id']]),$workflowGuard,'seal cannot move backwards');
    wl_sql_refuse(fn()=> $pdo->prepare('DELETE FROM westy_workflow_log_state WHERE tenant_id=1 AND workflow_id=?')->execute([$run['id']]),$workflowGuard,'sealed state cannot be deleted');
    wl_sql_refuse(fn()=> $pdo->prepare('UPDATE westy_workflow_log_state SET log_sha256=? WHERE tenant_id=1 AND workflow_id=?')->execute([str_repeat('0',64),$run['id']]),$workflowGuard,'checkpoint hash cannot be rewritten at same sequence');
    // Replay remains the original seal, even after later batches.
    wl_check(wl_send($pdo,$p)['log_sha256']===$r['log_sha256'],'old replay retains original checkpoint');
    $openBatch = wl_log(40,$next['log_sha256'],3,seal:false);
    wl_check(wl_send($pdo,$openBatch)['log_sha256'] === $next['log_sha256'], 'an unsealed batch returns the last side-table checkpoint');
    $unsealed=wl_case($pdo,41,'resolved'); $unsealed['sealed_seq']=null; $unsealed['log_sha256']=null; $messages=wl_count($pdo,'messages');
    wl_check(wl_send($pdo,$unsealed)['log_sha256']===null && wl_count($pdo,'messages')===$messages,'unsealed batch adds no message');
    $unsealedId = wl_run($pdo, $unsealed)['id'];
    wl_check(westy_work_log_state($pdo,1,(int)$unsealedId) === null, 'an unsealed workflow has no side row');
    $unsealedQa = array_replace(wl_qa($unsealed), ['log_sha256'=>$unsealed['entries'][0]['entry_sha256']]);
    wl_refuse(fn()=>wl_send($pdo,$unsealedQa), 'log_seal_mismatch', 'resolved workflow with no seal refuses QA');
    $newSeal = $pdo->prepare('INSERT INTO westy_workflow_log_state (tenant_id,workflow_id,log_sha256,log_sealed_seq,qa_state,qa_reason) VALUES (1,?,?,?,?,?)');
    foreach ([[null,1,'log_sha256'],[str_repeat('a',64),null,'log_sealed_seq']] as [$hash,$seq,$column]) {
        wl_sql_refuse(fn()=> $newSeal->execute([$unsealedId,$hash,$seq,null,null]), "Column '$column' cannot be null", 'both parts of an initial seal are required');
    }
    foreach ([['passed','no_recurrence'],['fault_zero',null],[null,'reason_only']] as [$verdict,$reason]) {
        wl_sql_refuse(fn()=> $newSeal->execute([$unsealedId,str_repeat('a',64),1,$verdict,$reason]), $workflowGuard, 'initial seal refuses QA or a reason');
    }
    wl_sql_refuse(fn()=> $pdo->prepare('UPDATE westy_workflow_log_state SET workflow_id=? WHERE tenant_id=1 AND workflow_id=?')->execute([$unsealedId,$run['id']]),
        $workflowGuard, 'seal cannot be reassigned to another workflow');
    wl_sql_refuse(fn()=> $pdo->prepare("UPDATE westy_workflow_log_state SET qa_reason='lone_reason' WHERE tenant_id=1 AND workflow_id=?")->execute([$run['id']]),
        $workflowGuard, 'a reason requires a verdict');
    $legacy=wl_case($pdo,42); $legacyRun=wl_run($pdo,$legacy);
    $pdo->prepare("INSERT INTO westy_workflow_receipts(tenant_id,workflow_id,event_key,request_sha256,action,version,response_json,received_at) VALUES(1,?,?,?,'progress',7,'{}',UTC_TIMESTAMP())")->execute([$legacyRun['id'],$legacy['event_key'],hash('sha256',westy_work_log_json($legacy))]);
    wl_refuse(fn()=>wl_send($pdo,$legacy),'event_key_conflict','legacy receipt key cannot be reused by v4');
    foreach (['ticket_work_log','westy_workflow_v4_receipts'] as $table) {
        wl_sql_refuse(fn()=> $pdo->exec("UPDATE $table SET tenant_id=tenant_id"),$table==='ticket_work_log'?'Westy work log is immutable':'Westy v4 receipts are immutable','immutable '.$table.' update');
        wl_sql_refuse(fn()=> $pdo->exec("DELETE FROM $table"),$table==='ticket_work_log'?'Westy work log is immutable':'Westy v4 receipts are immutable','immutable '.$table.' delete');
    }
    // S8: local staff always takes over; form fields cannot select actor/visibility/source/seq.
    $staff=wl_case($pdo,50); $ticket=(int)wl_run($pdo,$staff)['ticket_id'];
    $before=wl_count($pdo,'ticket_work_log'); $GLOBALS['wl_csrf']=false;
    wl_refuse(fn()=>westy_work_log_staff($pdo,1,1,$ticket,['kind'=>'note','summary'=>'A step.']),'csrf_required','staff CSRF'); $GLOBALS['wl_csrf']=true;
    foreach ([[1,2,'unauthorized','viewer'], [1,3,'unauthorized','foreign user'], [2,3,'ticket_not_found','foreign ticket']] as [$tid,$actor,$error,$label]) {
        wl_refuse(fn()=>westy_work_log_staff($pdo,$tid,$actor,$ticket,['kind'=>'note','summary'=>'A step.']), $error, 'staff refuses '.$label);
    }
    foreach (['',str_repeat('a',1001)] as $summary) wl_refuse(fn()=>westy_work_log_staff($pdo,1,1,$ticket,['kind'=>'note','summary'=>$summary]),'invalid_summary','staff summary bounds');
    wl_check(wl_count($pdo,'ticket_work_log')===$before,'staff refusals write nothing');
    $id=westy_work_log_staff($pdo,1,1,$ticket,['kind'=>'finding','summary'=>'<script>example</script>','customer_visible'=>true,'actor_key'=>'system:milepost','source'=>'milepost','seq'=>9]);
    $row=westy_workflow_row($pdo,'SELECT * FROM ticket_work_log WHERE id=?',[$id]); $state=wl_run($pdo,$staff);
    wl_check($state['state']==='human_owned' && (int)$state['version']===8,'staff step triggers takeover');
    wl_check((int)$row['customer_visible']===0 && $row['actor_key']==='safeharbor-user:1' && $row['seq']===null && $row['workflow_id']===null && $row['entry_sha256']===null && $row['source']==='staff','staff shape comes only from server');
    foreach ([['staff','system',null,null,null],['staff','user',1,(int)$state['id'],str_repeat('a',64)],['milepost','user',null,null,null]] as [$source,$actor,$seq,$wid,$hash]) {
        wl_sql_refuse(fn()=> $pdo->prepare('INSERT INTO ticket_work_log(tenant_id,ticket_id,source,source_event_key,kind,actor_type,actor_key,summary,seq,workflow_id,entry_sha256,occurred_at) VALUES(1,?,?,?,\'note\',?,\'synthetic\',\'Synthetic\',?,?,?,UTC_TIMESTAMP())')->execute([$ticket,$source,bin2hex(random_bytes(8)),$actor,$seq,$wid,$hash]),'Westy work log row shape is invalid','row shape prevents posing');
    }
    wl_check(str_contains(westy_work_log_timeline(westy_work_log_rows($pdo,1,$ticket)),'&lt;script&gt;') && westy_work_log_rows($pdo,2,$ticket)===[],'staff timeline escapes and tenant scopes');

    // S9/S10: all terminal transitions, all outbox states, no QA creation or sending.
    $makeBillingCase = static function (int $number, bool $seal = true, ?int $at = null) use ($pdo): array {
        $log = wl_case($pdo, $number, 'resolved', $at);
        if ($seal) wl_send($pdo, $log);
        return [$log,(int)wl_run($pdo,$log)['id']];
    };
    wl_qa_billing_cases($pdo, $makeBillingCase);
    // A separate receiver connection sees the dispatcher's committed claim while transport is in flight.
    $inFlight = wl_case($pdo, 540, 'resolved');
    wl_send($pdo, $inFlight);
    $inFlightId = (int)wl_run($pdo,$inFlight)['id'];
    $pdo->prepare("INSERT INTO westy_billing_outbox (tenant_id,workflow_id,event_key,state) VALUES (1,?,'s5-in-flight','qa_pending')")->execute([$inFlightId]);
    $handoffId = (int)$pdo->lastInsertId();
    wl_send($pdo, wl_qa($inFlight));
    $coverage = ['enabled'=>true,'coverage_key'=>wl_key(540),'coverage_revision'=>1,'service_code'=>'routine_support'];
    $config = ['enabled'=>true,'service'=>'safeharbor-billing','endpoint'=>WESTY_BILLING_ENDPOINT,'secret'=>str_repeat('t',40),
        'tenant_slugs'=>['8west'],'customer_ids'=>[$inFlight['customer_id']],
        'included_service'=>['enabled'=>true,'policies'=>['8west'=>[$inFlight['customer_id']=>$coverage]]]];
    $conflict = null;
    $transport = static function () use ($runtime, $inFlight, &$conflict): array {
        try { wl_send($runtime, wl_qa($inFlight,'void',wl_key(540001))); }
        catch (WestyWorkflowConflict $error) { $conflict = $error->getMessage(); }
        return ['status'=>503,'body'=>''];
    };
    wl_check(westy_billing_dispatch($pdo,$handoffId,$config,$transport) === 'uncertain' && $conflict === 'qa_charge_already_dispatched'
        && westy_work_log_state($pdo,1,$inFlightId)['qa_state'] === 'passed', 'interleaved void preserves the in-flight charge and its eventual outcome');
    $billingGuard = 'Westy billing QA hold is restricted';
    foreach (['passed','fault_zero','void'] as $i=>$verdict) {
        $p=wl_case($pdo,60+$i,'resolved'); wl_send($pdo,$p); $run=wl_run($pdo,$p);
        $pdo->prepare("INSERT INTO westy_billing_outbox(tenant_id,workflow_id,event_key,state) VALUES(1,?,?,'qa_pending')")->execute([$run['id'],'qa-fixture-'.(60+$i)]);
        $outbox=(int)$pdo->lastInsertId();
        $billingConfig=['enabled'=>true,'endpoint'=>WESTY_BILLING_ENDPOINT,'service'=>'safeharbor-billing','secret'=>str_repeat('t',40),'tenant_slugs'=>['8west'],'customer_ids'=>[$p['customer_id']]];
        $calls=0;
        wl_check(westy_billing_dispatch($pdo,$outbox,$billingConfig,static function()use(&$calls){$calls++;throw new RuntimeException('must not send');})==='qa_pending' && $calls===0,'qa_pending dispatch skipped');
        foreach (['ready','sending','accepted','uncertain'] as $unsafe) wl_sql_refuse(fn()=> $pdo->prepare('UPDATE westy_billing_outbox SET state=? WHERE id=?')->execute([$unsafe,$outbox]),$billingGuard,'pending QA cannot move to '.$unsafe);
        wl_sql_refuse(fn()=> $pdo->prepare("UPDATE westy_billing_outbox SET payload_json='{}',payload_sha256=? WHERE id=?")->execute([str_repeat('a',64),$outbox]),$billingGuard,'pending QA cannot acquire payload');
        wl_sql_refuse(fn()=> $pdo->prepare("UPDATE westy_billing_outbox SET state='waiting_for_time',payload_json='{}' WHERE id=?")->execute([$outbox]),$billingGuard,'payload JSON refused while leaving QA hold');
        wl_sql_refuse(fn()=> $pdo->prepare("UPDATE westy_billing_outbox SET state='blocked',payload_sha256=? WHERE id=?")->execute([str_repeat('a',64),$outbox]),$billingGuard,'payload hash refused while leaving QA hold');
        $qa=wl_qa($p,$verdict); $before=wl_snapshot($pdo,$p);
        $bad=$qa; $bad['log_sha256']=str_repeat('0',64); wl_refuse(fn()=>wl_send($pdo,$bad),'log_seal_mismatch','QA log hash');
        $bad=$qa; $bad['evidence_sha256']=str_repeat('0',64); wl_refuse(fn()=>wl_send($pdo,$bad),'log_seal_mismatch','QA evidence hash');
        $qaReceipt=wl_send($pdo,$qa); $box=westy_workflow_row($pdo,'SELECT * FROM westy_billing_outbox WHERE id=?',[$outbox]);
        wl_check($before===wl_snapshot($pdo,$p) && $box['payload_json']===null && (int)$box['attempts']===0,'QA neither alters ticket nor freezes/sends billing');
        wl_check_replay($pdo, $qa, $qaReceipt);
        wl_sql_refuse(fn()=> $pdo->prepare('UPDATE westy_workflow_log_state SET qa_state=NULL,qa_reason=NULL WHERE tenant_id=1 AND workflow_id=?')->execute([$run['id']]),
            $workflowGuard, 'a verdict and reason cannot be cleared together');
        foreach ([null,'passed','fault_zero','void'] as $target) {
            if ($target===$verdict || ($verdict==='passed' && $target==='void')) continue;
            wl_sql_refuse(fn()=> $pdo->prepare('UPDATE westy_workflow_log_state SET qa_state=? WHERE tenant_id=1 AND workflow_id=?')->execute([$target,$run['id']]),$workflowGuard,'trigger refuses terminal '.$verdict.' to '.($target ?? 'NULL'));
            if (in_array($target,['passed','fault_zero','void'],true)) wl_refuse(fn()=>wl_send($pdo,wl_qa($p,$target,wl_key(910000+$i))),'qa_already_recorded','code refuses terminal transition');
        }
        foreach ([null, 'different_reason', strtoupper($qa['reason_code'])] as $changedReason) {
            wl_sql_refuse(fn()=> $pdo->prepare('UPDATE westy_workflow_log_state SET qa_reason=? WHERE tenant_id=1 AND workflow_id=?')->execute([$changedReason,$run['id']]),
                $workflowGuard, 'reason stays fixed under '.$verdict);
        }
        wl_refuse(fn()=>wl_send($pdo,wl_qa($p,$verdict,wl_key(920000+$i))),'qa_already_recorded','same verdict under new event refused');
        if ($verdict==='passed') {
            wl_sql_refuse(fn()=> $pdo->prepare("UPDATE westy_billing_outbox SET state='qa_pending',payload_sha256=? WHERE id=?")->execute([str_repeat('a',64),$outbox]),
                $billingGuard, 'payload refused while entering QA hold');
            $void = wl_qa($p,'void',wl_key(930000));
            $void['reason_code'] = 'recurrence_detected';
            wl_send($pdo, $void);
            $recorded = westy_work_log_state($pdo, 1, (int)$run['id']);
            wl_check($recorded['qa_state'] === 'void' && $recorded['qa_reason'] === $void['reason_code'], 'passed tightens to void with its new reason');
            wl_check(westy_workflow_row($pdo,'SELECT state,detail_code FROM westy_billing_outbox WHERE id=?',[$outbox]) === ['state'=>'blocked','detail_code'=>'qa_void'], 'later void blocks the released hold');
        }
    }
    foreach (['waiting_for_time','ready','sending','uncertain','accepted','blocked'] as $i=>$boxState) {
        $p=wl_case($pdo,70+$i,'resolved'); wl_send($pdo,$p); $run=wl_run($pdo,$p);
        $pdo->prepare('INSERT INTO westy_billing_outbox(tenant_id,workflow_id,event_key,state) VALUES(1,?,?,?)')->execute([$run['id'],'existing-state-'.(70+$i),$boxState]);
        $id=(int)$pdo->lastInsertId(); $before=westy_workflow_row($pdo,'SELECT * FROM westy_billing_outbox WHERE id=?',[$id]);
        wl_check(wl_send($pdo,wl_qa($p,'passed'))['qa_state']==='passed'
            && $before===westy_workflow_row($pdo,'SELECT * FROM westy_billing_outbox WHERE id=?',[$id]),'passed preserves an existing '.$boxState.' row');
    }
    $noBox=wl_case($pdo,80,'resolved'); wl_send($pdo,$noBox); $count=wl_count($pdo,'westy_billing_outbox'); wl_send($pdo,wl_qa($noBox));
    wl_check(wl_count($pdo,'westy_billing_outbox')===$count,'QA creates no outbox');
    $noBoxRun=wl_run($pdo,$noBox);
    $pdo->prepare("UPDATE westy_workflow_log_state SET qa_state='void',qa_reason='recovery_reversed' WHERE tenant_id=1 AND workflow_id=? AND qa_state='passed'")->execute([$noBoxRun['id']]);
    $recorded = westy_work_log_state($pdo, 1, (int)$noBoxRun['id']);
    wl_check($recorded['qa_state'] === 'void' && $recorded['qa_reason'] === 'recovery_reversed', 'direct SQL can record a new reason with passed to void');
    wl_sql_refuse(fn()=> $pdo->prepare("INSERT INTO westy_billing_outbox(tenant_id,workflow_id,event_key,state,payload_json,payload_sha256) VALUES(1,?,'bad-initial','qa_pending','{}',?)")->execute([$noBoxRun['id'],str_repeat('a',64)]),'ck_westy_billing_qa_payload','pending QA INSERT payload check');
    // The real v1 writer explicitly takes the QA hold with the 039 guards installed.
    $resolve=wl_case($pdo,81); $run=wl_run($pdo,$resolve); $now=gmdate('Y-m-d\TH:i:s\Z');
    $v1=['schema_version'=>1,'event_key'=>wl_key(980081),'workflow_key'=>$resolve['workflow_key'],'tenant_slug'=>'8west','customer_id'=>$resolve['customer_id'],'alert_key'=>$resolve['alert_key'],
        'action'=>'resolve','expected_version'=>7,'summary'=>'Verified recovery.','occurred_at'=>$now,'evidence_sha256'=>str_repeat('b',64),'verification_method'=>'agent_job_and_alert_recovery','job_id'=>123,'job_completed_at'=>$now,'alert_resolved_at'=>$now,'assignee_id'=>null];
    $pdo->exec('DROP TRIGGER trg_westy_billing_qa_guard');
    wl_refuse(fn()=>wl_send($runtime,$v1), 'workflow_v4_unavailable', 'new v1 resolve refuses a missing QA guard');
    wl_refuse(fn()=>westy_billing_release_expired_qa($runtime,$billingConfig), 'workflow_v4_unavailable', 'timer refuses a missing QA guard');
    wl_check(wl_run($pdo,$resolve) === $run, 'incomplete QA schema leaves the workflow open');
    wl_ddl($pdo,$statements,'CREATE TRIGGER trg_westy_billing_qa_guard');
    wl_send($runtime,$v1);
    $resolvedBox = westy_workflow_row($pdo,'SELECT id,state FROM westy_billing_outbox WHERE tenant_id=1 AND workflow_id=?',[$run['id']]);
    wl_check($resolvedBox['state']==='qa_pending','new resolve starts on QA hold');
    wl_send($pdo,$resolve);
    wl_send($pdo,wl_qa($resolve,'fault_zero'));
    $blocked = westy_workflow_row($pdo,'SELECT state,detail_code FROM westy_billing_outbox WHERE tenant_id=1 AND id=?',[$resolvedBox['id']]);
    wl_check($blocked===['state'=>'blocked','detail_code'=>'qa_fault_zero'], 'first fault_zero blocks the charge created by the real resolve');
    wl_qa_timeout_cases($pdo, $makeBillingCase);
    // S11: the actual atomic recovery function used by svc/alerts.php, real takeover guards.
    $pdo->exec("INSERT INTO tickets(tenant_id,client_id,subject,status,channel,external_key,auto_close_eligible,sla_due_at) VALUES(1,1,'Untouched alert','open','alert','alert:98989',1,UTC_TIMESTAMP() + INTERVAL 1 HOUR)");
    $auto=(int)$pdo->lastInsertId();
    wl_check(ticket_try_machine_auto_close($pdo,1,$auto,gmdate('Y-m-d H:i:s')),'untouched alert recovery still closes');
    wl_check(!ticket_try_machine_auto_close($pdo,1,$auto,gmdate('Y-m-d H:i:s')),'recovery capability remains one-use');
    $pdo->exec("INSERT INTO tickets(tenant_id,client_id,subject,status,channel,external_key,auto_close_eligible,sla_due_at) VALUES(1,1,'Staff worked alert','open','alert','alert:98990',1,UTC_TIMESTAMP() + INTERVAL 1 HOUR)");
    $worked=(int)$pdo->lastInsertId();
    westy_work_log_staff($pdo,1,1,$worked,['kind'=>'note','summary'=>'A technician investigated this alert.']);
    wl_check(!ticket_try_machine_auto_close($pdo,1,$worked,gmdate('Y-m-d H:i:s')),'staff step consumes legacy closure even without a workflow');

    // S12: real reviewed-mail table/guards; only time + summary leave the customer reader.
    $visible=wl_case($pdo,90); wl_send($pdo,$visible); $run=wl_run($pdo,$visible); $ticket=(int)$run['ticket_id'];
    wl_check(westy_work_log_rows($pdo,1,$ticket,1)===[],'no submitted review, no customer rows');
    $draft=wl_draft($pdo,$ticket);
    wl_check(westy_work_log_rows($pdo,1,$ticket,1)===[],'draft is not customer review');
    wl_submit_draft($pdo,$draft);
    westy_work_log_staff($pdo,1,1,$ticket,['kind'=>'note','summary'=>'Private staff finding']);
    $rows=westy_work_log_rows($pdo,1,$ticket,1);
    wl_check(count($rows)===1 && array_keys($rows[0])===['occurred_at','summary'],'reviewed customer projection omits actor/hash/ids and private rows');
    wl_check(westy_work_log_rows($pdo,1,$ticket,3)===[] && westy_work_log_rows($pdo,2,$ticket,1)===[],'customer reader requires exact tenant/client');
    $html=westy_work_log_timeline($rows,true);
    wl_check(!str_contains($html,'milepost:') && !str_contains($html,$visible['log_sha256']) && !str_contains($html,'alert:90') && !str_contains($html,'Private staff'),'customer output has no internal metadata');

    // S1: actual full schema through 038, then migration 039, then a fresh install.
    $pdo->exec("CREATE DATABASE `$fresh` CHARACTER SET utf8mb4"); $freshCreated=true;
    $pdo->exec("USE `$fresh`");
    $baseline=explode('-- BEGIN S5 WORK LOG (039)',file_get_contents(__DIR__.'/../db/schema.sql'),2)[0];
    foreach (test_sql_statements($baseline) as $sql) {
        $code=trim(preg_replace('/^\s*--.*$/m','',$sql));
        if (preg_match('/\A(?:SELECT|SHOW)\b/i',$code)) { $q=$pdo->query($sql); $q->fetchAll(); $q->closeCursor(); }
        else $pdo->exec($sql);
    }
    $fullLegacyDefinitions = wl_legacy_definitions($pdo);
    test_execute_sql_file($pdo,$migration); $upgradedSchema=wl_schema_snapshot($pdo);
    wl_check(wl_legacy_definitions($pdo) === $fullLegacyDefinitions, 'full legacy workflow column and trigger inventories survive 039 exactly');
    $pdo->exec("USE `$database`"); $pdo->exec("DROP DATABASE `$fresh`"); $freshCreated=false;
    $pdo->exec("CREATE DATABASE `$fresh` CHARACTER SET utf8mb4"); $freshCreated=true;
    $pdo->exec("USE `$fresh`"); test_execute_sql_file($pdo,__DIR__.'/../db/schema.sql');
    westy_work_log_schema_ready($pdo); $freshSchema=wl_schema_snapshot($pdo);
    wl_check($upgradedSchema===$freshSchema,'actual full 038 to 039 equals fresh schema');
    // Main behavioral fixture applied every relevant migration through 038, then 039.
    $pdo->exec("USE `$database`");
    $migratedSchema = wl_schema_snapshot($pdo);
    // Only the spelling of a reference to the current fixture database can vary here.
    foreach (['ticket_work_log','westy_workflow_v4_receipts','westy_workflow_log_state'] as $table) {
        $migratedSchema[$table] = str_replace("REFERENCES `$database`.", 'REFERENCES ', $migratedSchema[$table]);
        $freshSchema[$table] = str_replace("REFERENCES `$fresh`.", 'REFERENCES ', $freshSchema[$table]);
    }
    wl_check($migratedSchema === $freshSchema, 'migrated 038 objects match canonical fresh install');
    $history = static fn(): array => [
        wl_count($pdo,'ticket_work_log'),wl_count($pdo,'westy_workflow_v4_receipts'),
        $pdo->query('SELECT * FROM westy_workflow_log_state ORDER BY tenant_id,workflow_id')->fetchAll(),
    ];
    $beforeRerun = $history();
    test_execute_sql_file($pdo,$migration);
    wl_check($history() === $beforeRerun, 'rerun preserves history, seals, recorded verdicts and timestamps');
    $pdo->exec($grantWorkLog);
    westy_work_log_schema_ready($runtime);
    require __DIR__.'/fixtures/westy_work_log_http.php';
    // 039 never installs the legacy function. Simulate its loss last, before fixture cleanup.
    westy_work_log_schema_ready($runtime);
    $pdo->exec('DROP FUNCTION westy_workflow_schema_health');
    wl_missing($runtime, $probe, false);
} catch (Throwable $suiteError) {
    throw $suiteError;
} finally {
    // Only randomized databases created above are ever removed.
    $cleanup = $pdo->inTransaction() ? ['ROLLBACK'] : [];
    if ($freshCreated) $cleanup[] = "DROP DATABASE `$fresh`";
    $cleanup[] = "DROP DATABASE `$database`";
    if ($runtimeCreated) $cleanup[] = "DROP USER '$runtimeName'@'%'";
    wl_cleanup($pdo, $cleanup, $suiteError ?? null);
}
echo 'PASS '.($GLOBALS['wl_checks'] ?? 0)." MySQL work log checks.\n";


/** Attempt every recovery statement. A teardown error is primary only if the body succeeded. */
function wl_cleanup(PDO $pdo, array $statements, ?Throwable $bodyError): void
{
    $firstError = $bodyError;
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $error) {
            if ($firstError === null) $firstError = $error;
            else fwrite(STDERR, 'Additional fixture cleanup error: '.$error->getMessage().PHP_EOL);
        }
    }
    if ($bodyError === null && $firstError !== null) throw $firstError;
}
