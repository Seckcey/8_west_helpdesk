<?php
/** Real migration/replay/ownership proof on a newly created disposable DB. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST') ?: '127.0.0.1';
$port=getenv('SAFEHARBOR_WESTY_TEST_PORT') ?: '3306';
$base=getenv('SAFEHARBOR_WESTY_TEST_DB') ?: '';
if (!in_array($host,['127.0.0.1','localhost','::1'],true) || !ctype_digit($port) || (int)$port<1 || (int)$port>65535
    || preg_match('/\Asafeharbor_westy_test(?:_[a-z0-9_]+)?\z/D',$base)!==1 || strlen($base)>44) exit(2);
$database=$base.'_'.bin2hex(random_bytes(6));
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root',getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function ww_sql(PDO $pdo,string $file): void {
    $delimiter=';';$buffer='';
    foreach (preg_split('/\R/',file_get_contents($file)) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',$line,$m)) { $delimiter=$m[1]; $buffer=''; continue; }
        if (str_starts_with(ltrim($line),'--')) continue;
        $buffer.=$line."\n";
        if (str_ends_with(rtrim($buffer),$delimiter)) {
            $statement=trim(substr(rtrim($buffer),0,-strlen($delimiter)));
            if ($statement!=='') { $q=$pdo->query($statement); while ($q->nextRowset()) {} $q->closeCursor(); }
            $buffer='';
        }
    }
    if (trim($buffer)!=='') throw new RuntimeException('Unterminated SQL');
}
$server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$runtimeName = 'westy_rt_' . bin2hex(random_bytes(5));
$runtimeCreated = false;
try {
    $server->exec("USE `$database`"); $pdo=$server;
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database) throw new RuntimeException('Wrong database');
    $pdo->exec("SET time_zone = '+00:00'");
    foreach ([
        'CREATE TABLE tenants(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(64) UNIQUE)',
        'CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,UNIQUE(tenant_id,id))',
        'CREATE TABLE svc_identities(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,service VARCHAR(64),is_active INT)',
        'CREATE TABLE suite_customer_sync_bindings(tenant_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),status VARCHAR(16))',
        'CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,is_active INT)',
        "CREATE TABLE tickets(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,subject VARCHAR(190),priority VARCHAR(16),status VARCHAR(16) DEFAULT 'open',assignee_id INT UNSIGNED NULL,merged_into_id INT UNSIGNED NULL,channel VARCHAR(16),external_key VARCHAR(128),auto_close_eligible INT,sla_due_at DATETIME,service_goal_target_id BIGINT UNSIGNED NULL,created_at DATETIME,updated_at DATETIME,resolved_at DATETIME NULL,UNIQUE(tenant_id,id),UNIQUE(tenant_id,external_key))",
        'CREATE TABLE messages(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id INT UNSIGNED,author_name VARCHAR(128),kind VARCHAR(16),body TEXT,created_at DATETIME)',
        'CREATE TABLE time_entries(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,ticket_id INT UNSIGNED)',
    ] as $sql) $pdo->exec($sql.' ENGINE=InnoDB');
    $migration=__DIR__.'/../db/migrations/025_westy_workflow.sql';
    ww_sql($pdo,$migration);
    require __DIR__.'/westy_workflow_test.php';
    $receiptCount=(int)$pdo->query('SELECT COUNT(*) FROM westy_workflow_receipts')->fetchColumn();
    ww_sql($pdo,$migration);
    westy_workflow_schema_ready($pdo);
    $runtimePassword = bin2hex(random_bytes(24));
    $pdo->exec("CREATE USER '$runtimeName'@'localhost' IDENTIFIED BY '$runtimePassword'");
    $runtimeCreated = true;
    $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$database`.* TO '$runtimeName'@'localhost'");
    $runtime = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$runtimeName,$runtimePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    westy_workflow_schema_ready($runtime);
    ww_check((int)$runtime->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()")->fetchColumn()===0,'runtime has no trigger metadata privilege');
    ww_check(ww_send($runtime,$settings,array_replace($p,['action'=>'status','event_key'=>ww_key(9900)]))['state']==='resolved','DML-only runtime reads guarded status through definer health view');
    ww_refuses(fn()=> $runtime->exec('CREATE TABLE privilege_probe(id INT)'),'runtime still cannot create tables');
    ww_refuses(fn()=> $pdo->exec("UPDATE westy_billing_outbox SET event_key='changed'"),'billing identity is immutable');
    ww_refuses(fn()=> $pdo->exec('DELETE FROM westy_billing_outbox'),'billing history cannot be deleted');
    require_once __DIR__.'/../lib/westy_billing.php';
    $pdo->exec("ALTER TABLE time_entries ADD client_id INT UNSIGNED NULL, ADD approval_status VARCHAR(16) NOT NULL DEFAULT 'pending', ADD billable INT NOT NULL DEFAULT 0");
    $pdo->exec('CREATE TABLE time_entry_approval_adjustments(tenant_id INT UNSIGNED,time_entry_id INT UNSIGNED,version_no INT UNSIGNED)');
    $pdo->exec('CREATE TABLE coastmark_time_export_claims(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,time_entry_id INT UNSIGNED,source_version INT UNSIGNED,event_key VARCHAR(64),payload_json LONGTEXT,payload_sha256 CHAR(64))');
    $pdo->exec('CREATE TABLE coastmark_time_export_receipts(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,claim_id INT UNSIGNED,outcome VARCHAR(32),invoice_id INT UNSIGNED)');
    $pdo->prepare('INSERT INTO time_entries(id,tenant_id,ticket_id,client_id,approval_status,billable) VALUES(9900,1,?,1,?,1)')->execute([$ticketId,'approved']);
    $closed=$pdo->query('SELECT closed_at FROM westy_workflows WHERE id=1')->fetchColumn();
    $timeEvent='safeharbor-time:'.str_repeat('f',32);
    $timeBody=json_encode(['version'=>3,'tenant_key'=>'msp-one','client_key'=>'milepost-customer:'.$customer,'ticket_id'=>$ticketId,'entry_id'=>9900,'source_version'=>0,'approval_status'=>'approved','event_key'=>$timeEvent,'worked_at'=>str_replace(' ','T',$closed).'Z']);
    $pdo->prepare('INSERT INTO coastmark_time_export_claims VALUES(1,1,9900,0,?,?,?)')->execute([$timeEvent,$timeBody,hash('sha256',$timeBody)]);
    $pdo->exec("INSERT INTO coastmark_time_export_receipts VALUES(1,1,1,'accepted',9)");
    $billingEvent=$pdo->query('SELECT event_key FROM westy_billing_outbox WHERE id=1')->fetchColumn();
    $billingConfig=['enabled'=>true,'endpoint'=>WESTY_BILLING_ENDPOINT,'service'=>'safeharbor-billing','secret'=>str_repeat('test-only-',6),'tenant_slugs'=>['msp-one'],'customer_ids'=>[$customer]];
    $delivered=westy_billing_dispatch($runtime,1,$billingConfig,static fn()=>['status'=>200,'body'=>json_encode(['ok'=>true,'action'=>'created','handoff'=>['id'=>1,'event_key'=>$billingEvent,'state'=>'review_required'],'invoices'=>[['invoice_id'=>9,'review_url'=>'https://coastmark.8westit.com/invoices/9','status'=>'draft']]])]);
    ww_check($delivered==='accepted','DML-only worker freezes approved-time payload and records injected Coastmark review receipt');
    ww_refuses(fn()=> $runtime->exec("UPDATE westy_billing_outbox SET payload_json='{}' WHERE id=1"),'frozen handoff body cannot change');
    ww_refuses(fn()=> $runtime->exec("UPDATE westy_billing_outbox SET state='uncertain' WHERE id=1"),'accepted handoff cannot be retried');
    ww_check((int)$pdo->query('SELECT COUNT(*) FROM westy_workflow_receipts')->fetchColumn()===$receiptCount,'migration replay retains receipts');
    ww_refuses(fn()=> $pdo->exec('UPDATE westy_workflows SET tenant_id=2 WHERE id=1'),'identity cannot be moved to another tenant');
    ww_refuses(fn()=> $pdo->exec('DELETE FROM westy_workflows WHERE id=1'),'workflow history cannot be deleted');
    // Restore a disposable work state directly to exercise each database guard.
    foreach (['message-update','message-delete','time-update','time-delete'] as $kind) {
        $pdo->exec("UPDATE westy_workflows SET state='working' WHERE id=1");
        if ($kind==='message-update') $pdo->exec("UPDATE messages SET kind='note' WHERE ticket_id=$ticketId LIMIT 1");
        if ($kind==='message-delete') $pdo->exec("DELETE FROM messages WHERE ticket_id=$ticketId AND kind='note' LIMIT 1");
        if ($kind==='time-update') {
            $pdo->exec("INSERT INTO time_entries(tenant_id,ticket_id) VALUES (1,$ticketId)");
            $pdo->exec("UPDATE westy_workflows SET state='working' WHERE id=1");
            $pdo->exec("UPDATE time_entries SET ticket_id=$ticketId WHERE tenant_id=1 AND ticket_id=$ticketId");
        }
        if ($kind==='time-delete') $pdo->exec("DELETE FROM time_entries WHERE tenant_id=1 AND ticket_id=$ticketId");
        ww_check($pdo->query('SELECT state FROM westy_workflows WHERE id=1')->fetchColumn()==='human_owned',$kind.' takes ownership');
    }
    $pdo->exec('DROP TRIGGER trg_westy_ticket_takeover');
    ww_refuses(fn()=>westy_workflow_schema_ready($pdo),'partial migration fails closed');
    ww_refuses(fn()=>westy_workflow_schema_ready($runtime),'DML-only runtime sees missing guard through health view');
    ww_sql($pdo,$migration);
    $pdo->exec('ALTER TABLE westy_workflows ALTER CHECK ck_westy_workflow_resolution NOT ENFORCED');
    ww_refuses(fn()=>westy_workflow_schema_ready($pdo),'unenforced resolution check fails closed');
    echo "PASS $checks total MySQL workflow and migration checks.\n";
} finally {
    if ($server->inTransaction()) $server->rollBack();
    if ($server->query('SELECT DATABASE()')->fetchColumn()!==$database) throw new RuntimeException('Cleanup scope changed');
    $server->exec("DROP DATABASE `$database`");
    if ($runtimeCreated) $server->exec("DROP USER '$runtimeName'@'localhost'");
}
