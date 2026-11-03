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
/** Real independent connections wait at the common lock; infrastructure errors cannot pass a race. */
function ww_race(PDO $server, array $login, array $settings, array $jobs, ?int $humanTicket = null): array {
    $code = <<<'PHP'
require $argv[1];
$in=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$db=new PDO($in['login']['dsn'],$in['login']['user'],$in['login']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("SET time_zone='+00:00'"); $db->exec('SET innodb_lock_wait_timeout=15');
echo $db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n"; flush();
try {
  $j=$in['job'];
  $r=$j['kind']==='grant' ? westy_workflow_grant_action($db,1,1,$j['p'])
    : westy_workflow_receive($db,$in['settings'],westy_workflow_request($j['raw']),hash('sha256',$j['raw']));
  echo json_encode(['result'=>$r],JSON_THROW_ON_ERROR);
} catch (WestyWorkflowConflict $e) { echo json_encode(['conflict'=>$e->getMessage()],JSON_THROW_ON_ERROR); }
PHP;
    $children=[]; $server->beginTransaction();
    try {
        if ($humanTicket !== null) $server->prepare("UPDATE tickets SET priority='urgent' WHERE tenant_id=1 AND id=?")->execute([$humanTicket]);
        else $server->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE')->fetchColumn();
        foreach ($jobs as $job) {
            $process=proc_open([PHP_BINARY,'-r',$code,__DIR__.'/../lib/westy_workflow_grants.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('race child failed to start');
            fwrite($pipes[0],json_encode(['login'=>$login,'settings'=>$settings,'job'=>$job],JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            stream_set_timeout($pipes[1],10);
            $id=(int)trim((string)fgets($pipes[1]));
            $children[]=[$process,$pipes,$id];
            if ($id<1) throw new RuntimeException('race connection missing');
        }
        $ids=array_column($children,2); $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $waiting=$server->prepare("SELECT COUNT(DISTINCT t.PROCESSLIST_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID IN ($placeholders)");
        $deadline=microtime(true)+8;
        do { $waiting->execute($ids); $count=(int)$waiting->fetchColumn(); if ($count===count($ids)) break; usleep(20000); } while (microtime(true)<$deadline);
        ww_check($count===count($ids),'every race connection demonstrably waits at InnoDB lock');
        $server->commit(); $results=[];
        foreach ($children as [$process,$pipes,$id]) {
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
            ww_check($exit===0 && $err==='','race process completes without database/runtime error: '.$err);
            $results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);
        }
        $children=[]; return $results;
    } finally {
        if ($server->inTransaction()) $server->rollBack();
        foreach ($children as [$process,$pipes]) if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }
}
/** Exact shipped endpoints, real auth/CSRF/HMAC and DML DB login; only synthetic session files. */
function ww_http(PDO $pdo, array $config, array $base): void {
    $dir=sys_get_temp_dir().'/safeharbor-workflow-http-'.bin2hex(random_bytes(8)); $process=null;
    try {
        foreach (['lib','config','public/api/svc','sessions'] as $part) mkdir($dir.'/'.$part,0700,true);
        $source=realpath(__DIR__.'/../lib');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $target=$dir.'/lib/'.substr($file->getPathname(),strlen($source)+1);
            $file->isDir() ? mkdir($target,0700,true) : copy($file->getPathname(),$target);
        }
        foreach (['api/westy_workflow_grant.php','api/svc/westy_workflow.php'] as $file) copy(__DIR__.'/../public/'.$file,$dir.'/public/'.$file);
        file_put_contents($dir.'/config/config.php','<?php return '.var_export($config,true).';');
        $csrf=bin2hex(random_bytes(32)); $session=bin2hex(random_bytes(16));
        file_put_contents($dir.'/sessions/sess_'.$session,'user_id|i:1;tenant_id|i:1;csrf|s:64:"'.$csrf.'";');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if (!$socket) throw new RuntimeException('HTTP bind failed');
        $address=stream_socket_get_name($socket,false); fclose($socket);
        $process=proc_open([PHP_BINARY,'-d','session.save_path='.$dir.'/sessions','-S',$address,'-t',$dir.'/public'],[0=>['pipe','r'],1=>['file',$dir.'/http.log','a'],2=>['file',$dir.'/http.log','a']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('HTTP server failed'); fclose($pipes[0]);
        $deadline=microtime(true)+5;
        do { $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if ($probe) { fclose($probe); break; } usleep(20000); } while (microtime(true)<$deadline);
        if (!$probe) throw new RuntimeException('HTTP server readiness failed');
        $send=static function(string $path,string $body,array $headers=[],string $method='POST')use($address):array {
            $http_response_header=[];
            $result=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",array_merge(['Content-Type: application/json'],$headers)),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>8]]));
            if ($result===false || !preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$match)) throw new RuntimeException('HTTP request failed');
            return [(int)$match[1],$result];
        };
        [$grant,$resume,$status]=ww_human_case($pdo,$config['westy_workflow'],$base,7300);
        $raw=json_encode($grant,JSON_THROW_ON_ERROR); $before=ww_snapshot($pdo);
        ww_check($send('/api/westy_workflow_grant.php',$raw)[0]===302,'anonymous grant redirects to real login');
        ww_check($send('/api/westy_workflow_grant.php',$raw,['Cookie: PHPSESSID='.$session])[0]===419,'real endpoint refuses missing CSRF');
        ww_check($send('/api/westy_workflow_grant.php',$raw,['Cookie: PHPSESSID='.$session,'X-CSRF: wrong'])[0]===419,'real endpoint refuses wrong CSRF');
        ww_check($send('/api/westy_workflow_grant.php','',[],'GET')[0]===405 && ww_snapshot($pdo)===$before,'method/auth/CSRF refusals write nothing');
        $headers=['Cookie: PHPSESSID='.$session,'X-CSRF: '.$csrf];
        [$code,$result]=$send('/api/westy_workflow_grant.php',$raw,$headers);
        ww_check($code===200 && json_decode($result,true)['grant_state']==='pending','real authenticated CSRF create succeeds');
        ww_check(ww_snapshot($pdo,false)===array_diff_key($before,['westy_workflow_grants'=>true]),'HTTP grant preserves assignment/version/history');
        $signed=static function(array $p)use($send,$config):array {
            $raw=json_encode($p,JSON_THROW_ON_ERROR); $ts=(string)time();
            return $send('/api/svc/westy_workflow.php',$raw,['X-8W-Service: milepost-workflow','X-8W-Timestamp: '.$ts,'X-8W-Signature: '.hash_hmac('sha256',WESTY_WORKFLOW_CONTEXT."\n".$ts."\n".$raw,$config['westy_workflow']['hmac_secret'])]);
        };
        ww_check($send('/api/svc/westy_workflow.php',json_encode($status))[0]===401,'unsigned v2 cannot read intent');
        [$code,$result]=$signed($status); $read=json_decode($result,true,32,JSON_THROW_ON_ERROR);
        ww_check($code===200 && count($read)===18 && $read['schema_version']===2 && $read['pending_grant']['grant_key']===$grant['grant_key'],'signed exact v2 status interoperates over actual receiver');
        [$code,$result]=$signed($resume); $accepted=json_decode($result,true,32,JSON_THROW_ON_ERROR);
        ww_check($code===200 && count($accepted)===18 && $accepted['request_sha256']===hash('sha256',json_encode($resume)),'signed actual receiver returns complete original raw-body proof');
        [$code,$result]=$signed($resume);
        ww_check($code===200 && ww_same(json_decode($result,true),array_replace($accepted,['replayed'=>true])),'HTTP lost-response replay preserves original proof');
        ww_check($send('/api/westy_workflow_grant.php',json_encode(array_replace($grant,['action'=>'cancel'])),$headers)[0]===409,'HTTP cancellation cannot reverse consumption');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        if (is_dir($dir)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
            rmdir($dir);
        }
    }
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
        'CREATE TABLE suite_customer_sync_bindings(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),status VARCHAR(16),source_version INT NOT NULL DEFAULT 1,last_event_id VARCHAR(64) NOT NULL DEFAULT \'fixture-active\')',
        'CREATE TABLE suite_customer_sync_events(tenant_id INT UNSIGNED,binding_id INT UNSIGNED,status VARCHAR(16))',
        'CREATE TABLE managed_customer_lifecycle_restore_receipts(tenant_id INT UNSIGNED,source_binding_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),source_version INT,source_event_id VARCHAR(64))',
        'CREATE TABLE suite_managed_providers(tenant_id INT UNSIGNED)',
        "CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,is_active INT,role VARCHAR(16) DEFAULT 'tech')",
        "CREATE TABLE tickets(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,subject VARCHAR(190),priority VARCHAR(16),status VARCHAR(16) DEFAULT 'open',assignee_id INT UNSIGNED NULL,merged_into_id INT UNSIGNED NULL,channel VARCHAR(16),external_key VARCHAR(128),auto_close_eligible INT,sla_due_at DATETIME,service_goal_target_id BIGINT UNSIGNED NULL,created_at DATETIME,updated_at DATETIME,resolved_at DATETIME NULL,UNIQUE(tenant_id,id),UNIQUE(tenant_id,external_key))",
        'CREATE TABLE messages(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id INT UNSIGNED,author_name VARCHAR(128),kind VARCHAR(16),body TEXT,created_at DATETIME)',
        'CREATE TABLE time_entries(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,ticket_id INT UNSIGNED)',
    ] as $sql) $pdo->exec($sql.' ENGINE=InnoDB');
    $migration=__DIR__.'/../db/migrations/025_westy_workflow.sql';
    ww_sql($pdo,$migration);
    require __DIR__.'/westy_workflow_test.php';
    require __DIR__.'/westy_device_ownership_scenarios.php';
    $receiptCount=(int)$pdo->query('SELECT COUNT(*) FROM westy_workflow_receipts')->fetchColumn();
    ww_sql($pdo,$migration);
    westy_workflow_schema_ready($pdo);
    $runtimePassword = bin2hex(random_bytes(24));
    // The disposable CI service sees the loopback client through its Docker bridge.
    // This random short-lived account has rights only on the random test database.
    $pdo->exec("CREATE USER '$runtimeName'@'%' IDENTIFIED BY '$runtimePassword'");
    $runtimeCreated = true;
    $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$database`.* TO '$runtimeName'@'%'");
    $runtime = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$runtimeName,$runtimePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    ww_refuses(fn()=>westy_workflow_schema_ready($runtime),'runtime requires only explicit health-function execution');
    $pdo->exec("GRANT EXECUTE ON FUNCTION `$database`.westy_workflow_schema_health TO '$runtimeName'@'%'");
    westy_workflow_schema_ready($runtime);
    ww_check((int)$runtime->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()")->fetchColumn()===0,'runtime has no trigger metadata privilege');
    ww_check(ww_send($runtime,$settings,array_replace($p,['action'=>'status','event_key'=>ww_key(9900)]))['state']==='resolved','DML-only runtime reads guarded status through definer health function');
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
    $timeBody=json_encode(['version'=>3,'tenant_key'=>'8west','client_key'=>'milepost-customer:'.$customer,'ticket_id'=>$ticketId,'entry_id'=>9900,'source_version'=>0,'approval_status'=>'approved','event_key'=>$timeEvent,'worked_at'=>str_replace(' ','T',$closed).'Z']);
    $pdo->prepare('INSERT INTO coastmark_time_export_claims VALUES(1,1,9900,0,?,?,?)')->execute([$timeEvent,$timeBody,hash('sha256',$timeBody)]);
    $pdo->exec("INSERT INTO coastmark_time_export_receipts VALUES(1,1,1,'accepted',9)");
    $billingEvent=$pdo->query('SELECT event_key FROM westy_billing_outbox WHERE id=1')->fetchColumn();
    $billingConfig=['enabled'=>true,'endpoint'=>WESTY_BILLING_ENDPOINT,'service'=>'safeharbor-billing','secret'=>str_repeat('test-only-',6),'tenant_slugs'=>['8west'],'customer_ids'=>[$customer]];
    $delivered=westy_billing_dispatch($runtime,1,$billingConfig,static fn()=>['status'=>201,'body'=>json_encode(['ok'=>true,'action'=>'created','handoff'=>['id'=>1,'event_key'=>$billingEvent,'state'=>'review_required'],'invoices'=>[['invoice_id'=>9,'review_url'=>'https://coastmark.8westit.com/invoices/9','status'=>'draft']]])]);
    ww_check($delivered==='accepted','DML-only worker freezes approved-time payload and records injected Coastmark review receipt');
    ww_refuses(fn()=> $runtime->exec("UPDATE westy_billing_outbox SET payload_json='{}' WHERE id=1"),'frozen handoff body cannot change');
    ww_refuses(fn()=> $runtime->exec("UPDATE westy_billing_outbox SET state='uncertain' WHERE id=1"),'accepted handoff cannot be retried');
    ww_check((int)$pdo->query('SELECT COUNT(*) FROM westy_workflow_receipts')->fetchColumn()===$receiptCount,'migration replay retains receipts');
    westy_workflow_resume_schema_ready($runtime);
    [$dmlGrant,$dmlResume,$dmlStatus]=ww_human_case($pdo,$settings,$p,6000);
    $dmlCreated=westy_workflow_grant_action($runtime,1,1,$dmlGrant);
    $dmlBefore=ww_snapshot($pdo);
    ww_check(ww_send($runtime,$settings,$dmlStatus)['pending_grant']['grant_key']===$dmlGrant['grant_key'] && ww_snapshot($pdo)===$dmlBefore,'ordinary DML runtime discovers v2 intent without any writes');
    $dmlReceipt=ww_send($runtime,$settings,$dmlResume);
    ww_check($dmlReceipt['version']===3 && ww_same(ww_send($runtime,$settings,$dmlStatus)['accepted_continuation']['original_receipt'],$dmlReceipt),'ordinary DML runtime consumes grant and reads original receipt without new privilege');
    $beforeReplay=ww_snapshot($pdo);
    ww_sql($pdo,__DIR__.'/../db/migrations/036_westy_workflow_resume.sql');
    westy_workflow_resume_schema_ready($runtime);
    ww_check(ww_snapshot($pdo)===$beforeReplay,'036 replay retains pending/cancelled/consumed grants and immutable receipts byte for byte');
    $pdo->exec('ALTER TABLE westy_workflow_grants DROP INDEX uq_westy_grant_continuation');
    ww_error(fn()=>westy_workflow_resume_schema_ready($runtime),'workflow_v2_schema_unavailable','missing one-use continuation index refuses v2 under normal privileges');
    ww_error(fn()=>ww_send($runtime,$settings,$dmlStatus),'workflow_v2_schema_unavailable','partial v2 never emits a partial status');
    ww_check(ww_send($runtime,$settings,array_replace($p,['action'=>'status','event_key'=>ww_key(6999)]))['ok'],'v1 remains compatible with partial v2 artifacts');
    $pdo->exec('ALTER TABLE westy_workflow_grants ADD UNIQUE KEY uq_westy_grant_continuation(tenant_id,continuation_key)');
    $pdo->exec('ALTER TABLE westy_workflow_grants MODIFY create_response JSON NULL');
    ww_error(fn()=>westy_workflow_resume_schema_ready($runtime),'workflow_v2_schema_unavailable','partial frozen-result persistence refuses v2');
    $pdo->exec('ALTER TABLE westy_workflow_grants MODIFY create_response JSON NOT NULL');
    westy_workflow_resume_schema_ready($runtime);
    ww_check(ww_snapshot($pdo)===$beforeReplay,'partial-schema qualification preserves all business rows');
    $login=['dsn'=>"mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",'user'=>$runtimeName,'pass'=>$runtimePassword];
    [$raceGrant,$raceResume,$raceStatus]=ww_human_case($pdo,$settings,$p,7000);
    $competing=array_replace($raceGrant,['grant_key'=>ww_key(7010)]);
    $race=ww_race($pdo,$login,$settings,[['kind'=>'grant','p'=>$raceGrant],['kind'=>'grant','p'=>$competing]]);
    $wins=array_values(array_filter($race,fn($r)=>isset($r['result'])));
    $loses=array_values(array_filter($race,fn($r)=>isset($r['conflict'])));
    ww_check(count($wins)===1 && count($loses)===1 && $loses[0]['conflict']==='pending_grant_exists','two simultaneous creates serialize to one pending intent');
    $raceResume['grant_key']=$wins[0]['result']['grant_key']; $raw=json_encode($raceResume,JSON_THROW_ON_ERROR);
    $before=ww_snapshot($pdo);
    $race=ww_race($pdo,$login,$settings,[['kind'=>'resume','raw'=>$raw],['kind'=>'resume','raw'=>$raw]]);
    ww_check(isset($race[0]['result'],$race[1]['result']) && $race[0]['result']['replayed']!==$race[1]['result']['replayed'],'simultaneous exact resume produces one original and one frozen replay');
    $after=ww_snapshot($pdo);
    ww_check(count($after['westy_workflow_receipts'])===count($before['westy_workflow_receipts'])+1 && count($after['messages'])===count($before['messages'])+1,'concurrent replay appends one receipt/history only');
    [$cancelGrant,$cancelResume,$cancelStatus]=ww_human_case($pdo,$settings,$p,7100);
    westy_workflow_grant_action($runtime,1,1,$cancelGrant);
    $race=ww_race($pdo,$login,$settings,[['kind'=>'grant','p'=>array_replace($cancelGrant,['action'=>'cancel'])],['kind'=>'resume','raw'=>json_encode($cancelResume)]]);
    ww_check(count(array_filter($race,fn($r)=>isset($r['result'])))===1 && count(array_filter($race,fn($r)=>in_array($r['conflict']??'',['grant_conflict','grant_not_cancellable'],true)))===1,'cancel versus consume commits one terminal result');
    [$humanGrant,$humanResume,$humanStatus]=ww_human_case($pdo,$settings,$p,7200);
    westy_workflow_grant_action($runtime,1,1,$humanGrant);
    $race=ww_race($pdo,$login,$settings,[['kind'=>'resume','raw'=>json_encode($humanResume)]],$humanGrant['ticket_id']);
    ww_check($race===[['conflict'=>'version_conflict']] && ww_send($runtime,$settings,$humanStatus)['state']==='human_owned','uncommitted human edit wins before waiting resume rechecks version');
    $httpConfig=require __DIR__.'/../config/config.sample.php';
    $httpConfig['app_env']='dev'; $httpConfig['force_https']=false;
    $httpConfig['db']=['host'=>$host,'port'=>(int)$port,'name'=>$database,'charset'=>'utf8mb4','user'=>$runtimeName,'pass'=>$runtimePassword];
    $httpConfig['westy_workflow']=$settings;
    ww_http($pdo,$httpConfig,$p);
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
    ww_refuses(fn()=>westy_workflow_schema_ready($runtime),'DML-only runtime sees missing guard through health function');
    ww_sql($pdo,$migration);
    $pdo->exec('ALTER TABLE westy_workflows ALTER CHECK ck_westy_workflow_resolution NOT ENFORCED');
    ww_refuses(fn()=>westy_workflow_schema_ready($pdo),'unenforced resolution check fails closed');
    echo "PASS $checks total MySQL workflow and migration checks.\n";
} finally {
    if ($server->inTransaction()) $server->rollBack();
    if ($server->query('SELECT DATABASE()')->fetchColumn()!==$database) throw new RuntimeException('Cleanup scope changed');
    $server->exec("DROP DATABASE `$database`");
    if ($runtimeCreated) $server->exec("DROP USER '$runtimeName'@'%'");
}
