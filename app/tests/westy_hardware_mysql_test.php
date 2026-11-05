<?php
/** Real MySQL transactions, immutable history and fake providers only. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1') exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1'; $port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'';
if (!in_array($host,['127.0.0.1','localhost','::1'],true) || !ctype_digit($port) || (int)$port<1 || (int)$port>65535
    || preg_match('/\Asafeharbor_westy_test(?:_[a-z0-9_]+)?\z/D',$base)!==1 || strlen($base)>44) exit(2);
$database=$base.'_'.bin2hex(random_bytes(5));
$login=['dsn'=>"mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",'user'=>getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root','pass'=>getenv('SAFEHARBOR_WESTY_TEST_PASS')?:''];
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$login['user'],$login['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function utf8_clean(string $v): string { return $v; } // The signed parser already requires valid UTF-8.
require_once __DIR__.'/../lib/westy_hardware.php';
require_once __DIR__.'/../lib/westy_workflow_grants.php';
require_once __DIR__.'/fixtures/westy_hardware.php';
require_once __DIR__.'/sql_file.php';
$checks=0;
function hw_check(bool $ok,string $name): void { global $checks;if(!$ok)throw new RuntimeException($name);$checks++; }
function hw_refuse(callable $f,string $name): void {try{$f();}catch(Throwable){hw_check(true,$name);return;}hw_check(false,$name);}
function hw_same(array $a,array $b): bool {
    $sort=static function(array &$v) use (&$sort):void {foreach($v as &$item)if(is_array($item))$sort($item);unset($item);if(!array_is_list($v))ksort($v);};
    $sort($a);$sort($b);return $a===$b;
}
function hw_send(PDO $pdo,array $p): array { $raw=westy_hardware_json($p);return westy_workflow_receive($pdo,hw_settings(),westy_workflow_request($raw),hash('sha256',$raw)); }
function hw_case(PDO $pdo,int $id,string $state='human_owned'): array {
    $p=hw_payload($id);$status=$state==='working'?'in_progress':'open';$assignee=$state==='working'?null:1;
    $pdo->prepare("INSERT INTO tickets(tenant_id,client_id,subject,status,assignee_id,external_key,auto_close_eligible) VALUES(1,1,'Synthetic hardware case',?,?,?,0)")->execute([$status,$assignee,$p['alert_key']]);
    $ticket=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES(1,1,?,?,?,?,?,6,?,?,?)')
        ->execute([$p['customer_id'],$ticket,$p['workflow_key'],$p['alert_key'],$state,'Original history retained',gmdate('Y-m-d H:i:s',time()-3600),gmdate('Y-m-d H:i:s')]);
    return $p;
}
function hw_count(PDO $p,string $table): int { return (int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
function hw_due(PDO $p,int $id): void { $p->prepare('UPDATE westy_hardware_notices SET next_try_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-1),$id]); }
/** Inject a competing committed decision at the durable-intent/revalidation boundary. */
class HardwareBoundaryPDO extends PDO {
    public int $begins=0;
    public mixed $beforeSubmission=null;
    public function beginTransaction():bool {
        if(++$this->begins===3 && $this->beforeSubmission!==null)($this->beforeSubmission)();
        return parent::beginTransaction();
    }
}
function hw_race(PDO $pdo,array $login,array $payloads): array {
    $code= <<<'PHP'
function utf8_clean(string $v):string{return $v;}
require $argv[1];require $argv[2];
$in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$p=new PDO($in['login']['dsn'],$in['login']['user'],$in['login']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo $p->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";flush();
$raw=westy_hardware_json($in['payload']);echo json_encode(westy_workflow_receive($p,hw_settings(),westy_workflow_request($raw),hash('sha256',$raw)),JSON_THROW_ON_ERROR);
PHP;
    $children=[];$pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE')->fetchColumn();
        foreach($payloads as $payload){
            $proc=proc_open([PHP_BINARY,'-r',$code,__DIR__.'/../lib/westy_hardware.php',__DIR__.'/fixtures/westy_hardware.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc))throw new RuntimeException('race process failed');
            fwrite($pipes[0],json_encode(['login'=>$login,'payload'=>$payload],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],10);
            $id=(int)trim((string)fgets($pipes[1]));$children[]=[$proc,$pipes,$id];if($id<1)throw new RuntimeException('race connection missing');
        }
        $ids=array_column($children,2);$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$pdo->prepare("SELECT COUNT(DISTINCT t.PROCESSLIST_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID IN ($marks)");
        $deadline=microtime(true)+8;
        do{$q->execute($ids);$waiting=(int)$q->fetchColumn();if($waiting===count($ids))break;usleep(20000);}while(microtime(true)<$deadline);
        hw_check($waiting===count($ids),'all concurrent finding arrivals wait at authoritative tenant lock');
        $pdo->commit();$out=[];
        foreach($children as [$proc,$pipes]){$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);hw_check(proc_close($proc)===0 && $err==='','concurrent finding child completed');$out[]=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
        $children=[];return $out;
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($children as [$proc,$pipes])if(is_resource($proc)){proc_terminate($proc);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($proc);}
    }
}
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`");$pdo->exec("SET time_zone='+00:00'");
    foreach ([
        'CREATE TABLE tenants(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(64) UNIQUE)',
        'CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,UNIQUE(tenant_id,id))',
        'CREATE TABLE svc_identities(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,service VARCHAR(64),is_active INT)',
        "CREATE TABLE suite_customer_sync_bindings(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),status VARCHAR(16),source_version INT DEFAULT 1,last_event_id VARCHAR(64) DEFAULT 'fixture-active')",
        'CREATE TABLE suite_customer_sync_events(tenant_id INT UNSIGNED,binding_id INT UNSIGNED,status VARCHAR(16))',
        'CREATE TABLE managed_customer_lifecycle_restore_receipts(tenant_id INT UNSIGNED,source_binding_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),source_version INT,source_event_id VARCHAR(64))',
        'CREATE TABLE suite_managed_providers(tenant_id INT UNSIGNED)',
        "CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,is_active INT,role VARCHAR(16) DEFAULT 'tech',email VARCHAR(190))",
        "CREATE TABLE tickets(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,subject VARCHAR(190),priority VARCHAR(16),status VARCHAR(16) DEFAULT 'open',assignee_id INT UNSIGNED NULL,merged_into_id INT UNSIGNED NULL,channel VARCHAR(16),external_key VARCHAR(128),auto_close_eligible INT,created_at DATETIME,updated_at DATETIME,resolved_at DATETIME NULL,UNIQUE(tenant_id,id),UNIQUE(tenant_id,external_key))",
        'CREATE TABLE messages(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id INT UNSIGNED,author_name VARCHAR(128),kind VARCHAR(16),body TEXT,created_at DATETIME)',
        'CREATE TABLE time_entries(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,ticket_id INT UNSIGNED)',
    ] as $sql) $pdo->exec($sql.' ENGINE=InnoDB');
    foreach (['025_westy_workflow.sql','036_westy_workflow_resume.sql','001_mail_queue.sql','037_westy_hardware_outcomes.sql'] as $file) test_execute_sql_file($pdo,__DIR__.'/../db/migrations/'.$file);
    $pdo->exec("INSERT INTO tenants VALUES(1,'8west'),(2,'other');INSERT INTO clients VALUES(1,1),(2,2);INSERT INTO users VALUES(1,1,1,'owner','owner@example.test');INSERT INTO svc_identities VALUES(1,1,'milepost-workflow',1)");
    $pdo->prepare("INSERT INTO suite_customer_sync_bindings(tenant_id,client_id,customer_id,status) VALUES(1,1,?,'active')")->execute([hw_key(900)]);
    $p=hw_case($pdo,1);$before=$pdo->query('SELECT * FROM westy_workflows')->fetchAll();$receipt=hw_send($pdo,$p);
    hw_check($receipt['state']==='human_owned' && $receipt['version']===6 && $before===$pdo->query('SELECT * FROM westy_workflows')->fetchAll(),'human-owned workflow and version remain unchanged');
    hw_check(count($receipt)===21 && $receipt['outcome']==='needs_hardware_upgrade','exact outcome receipt and unresolved disposition');
    hw_check(hw_count($pdo,'messages')===1 && hw_count($pdo,'mail_queue')===0 && hw_count($pdo,'westy_hardware_notices')===1,'finding commits note and intent; no email queue without verified recipient');
    hw_check(hw_same(hw_send($pdo,$p),array_replace($receipt,['replayed'=>true])),'exact immutable retry');
    hw_refuse(fn()=>hw_send($pdo,array_replace($p,['summary'=>'Changed under same event'])),'changed event conflicts');
    hw_refuse(fn()=>hw_send($pdo,array_replace($p,['event_key'=>hw_key(700),'summary'=>'Changed under same finding'])),'changed finding conflicts');
    $fresh=array_replace($p,['event_key'=>hw_key(701),'finding_key'=>hw_key(702),'summary'=>'Reworded constraint','evidence_sha256'=>str_repeat('f',64),'measured_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
    $dedup=hw_send($pdo,$fresh);
    hw_check($dedup['deduplicated'] && $dedup['notice_id']===$receipt['notice_id'] && hw_count($pdo,'messages')===1 && hw_count($pdo,'westy_hardware_findings')===2,'changed wording/time/bytes retain new finding without another note or notice');
    $status=hw_send($pdo,hw_status($p));
    hw_check(count($status)===16 && hw_same($status['original_receipt'],$receipt) && $status['notice']['mail_queue_id']===null,'fresh status separates original receipt and current intent');
    $missing=hw_status($p);$missing['finding_key']=hw_key(9998);hw_refuse(fn()=>hw_send($pdo,$missing),'missing exact finding status conflicts');
    $bad=array_replace($p,['event_key'=>hw_key(703),'finding_key'=>hw_key(704),'decision_revision'=>3]);hw_refuse(fn()=>hw_send($pdo,$bad),'decision revisions cannot skip');
    $bad['decision_revision']=2;$bad['expected_version']=5;hw_refuse(fn()=>hw_send($pdo,$bad),'new material requires exact current version');
    hw_refuse(fn()=>hw_send($pdo,array_replace($p,['event_key'=>hw_key(705),'customer_id'=>hw_key(901)])),'wrong customer cannot append same-ticket evidence');
    foreach (['working','needs_human'] as $i=>$state) {
        $case=hw_case($pdo,2+$i,$state);$ticket=(int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();$grant=null;
        if ($state==='needs_human') $grant=westy_workflow_grant_action($pdo,1,1,['action'=>'create','ticket_id'=>$ticket,'workflow_key'=>$case['workflow_key'],'expected_version'=>6,'grant_key'=>hw_key(899)]);
        $r=hw_send($pdo,$case);$current=$pdo->query("SELECT status,assignee_id FROM tickets WHERE id=$ticket")->fetch();
        hw_check($r['state']==='needs_human' && $r['version']===7 && $current['status']===($state==='working'?'in_progress':'open'),'new material stops working and advances NH without changing ticket status');
        hw_check(hw_send($pdo,$case)['version']===7,'replay does not advance again');
        if ($grant) {
            $run=$pdo->query("SELECT * FROM westy_workflows WHERE ticket_id=$ticket")->fetch();
            hw_check(hw_count($pdo,'westy_workflow_grants')===1 && $run['version']===7,'pending grant retained but original version stale');
        }
    }
    $calls=0;$sender=static function()use(&$calls){$calls++;return ['outcome'=>'provider_accepted','transport'=>'graph','provider_http'=>202,'outcome_code'=>'graph_accepted'];};
    $state=westy_hardware_dispatch($pdo,$receipt['notice_id'],hw_settings(),hw_resolver(),$sender);
    hw_check($state==='provider_accepted' && $calls===1 && hw_count($pdo,'mail_queue')===1,'current exact device-linked recipient submitted once');
    $queued=$pdo->query('SELECT * FROM mail_queue')->fetch();
    hw_check($queued['to_addr']==='device-person@example.test' && (int)$queued['max_attempts']===0 && $queued['sent_at']!==null,'normal queue row freezes verified recipient and excludes legacy dispatcher');
    hw_check(westy_hardware_dispatch($pdo,$receipt['notice_id'],hw_settings(),hw_resolver(),$sender)==='provider_accepted' && $calls===1,'accepted never resends');
    hw_refuse(fn()=> $pdo->exec("UPDATE westy_hardware_notices SET state='pending' WHERE id=".$receipt['notice_id']),'accepted notice cannot regain retry');
    $absent=hw_send($pdo,hw_case($pdo,4));$noteCount=hw_count($pdo,'messages');$mailCount=hw_count($pdo,'mail_queue');
    hw_check(westy_hardware_dispatch($pdo,$absent['notice_id'],hw_settings(),hw_resolver('absent'),$sender)==='no_device_contact' && $calls===1,'authoritative absent contact sends no email');
    hw_check(hw_count($pdo,'messages')===$noteCount+1 && hw_count($pdo,'mail_queue')===$mailCount,'missing-contact finding recorded once without queue row');
    westy_hardware_dispatch($pdo,$absent['notice_id'],hw_settings(),hw_resolver('absent'),$sender);
    hw_check(hw_count($pdo,'messages')===$noteCount+1,'repeated absent check does not annotate repeatedly');
    foreach (['stale','ambiguous','unavailable','wrong_customer'] as $i=>$kind) {
        $r=hw_send($pdo,hw_case($pdo,5+$i));$notes=hw_count($pdo,'messages');
        $resolve=match($kind){'unavailable'=>fn()=>['status'=>503], 'wrong_customer'=>hw_resolver('present',fn($v)=>array_replace($v,['customer_id'=>hw_key(99)])),default=>hw_resolver($kind)};
        hw_check(westy_hardware_dispatch($pdo,$r['notice_id'],hw_settings(),$resolve,$sender)==='verification_failed' && hw_count($pdo,'messages')===$notes && $calls===1,$kind.' verification is not contact absence or a send');
    }
    $unknown=hw_send($pdo,hw_case($pdo,10));$throw=static function()use(&$calls){$calls++;throw new RuntimeException('timeout after possible acceptance');};
    hw_check(westy_hardware_dispatch($pdo,$unknown['notice_id'],hw_settings(),hw_resolver(),$throw)==='uncertain','provider timeout remains uncertain');$after=$calls;
    hw_check(westy_hardware_dispatch($pdo,$unknown['notice_id'],hw_settings(),hw_resolver(),$sender)==='uncertain' && $calls===$after,'uncertain never retries');
    hw_refuse(fn()=> $pdo->exec("UPDATE westy_hardware_notices SET state='pending' WHERE id=".$unknown['notice_id']),'uncertain cannot regain retry');
    $failed=hw_send($pdo,hw_case($pdo,11));$reject=static function()use(&$calls){$calls++;return ['outcome'=>'failed','transport'=>'graph','provider_http'=>429,'outcome_code'=>'graph_send_rejected'];};
    hw_check(westy_hardware_dispatch($pdo,$failed['notice_id'],hw_settings(),hw_resolver(),$reject)==='failed','explicit rejected submission is retryable');
    hw_due($pdo,$failed['notice_id']);$newContact=hw_resolver('present',fn($r)=>array_replace($r,['contact_revision'=>2,'email'=>'current-device-person@example.test']));
    hw_check(westy_hardware_dispatch($pdo,$failed['notice_id'],hw_settings(),$newContact,$sender)==='provider_accepted','known failure revalidates current contact before retry');
    $q=$pdo->prepare('SELECT q.to_addr FROM mail_queue q JOIN westy_hardware_notices n ON n.mail_queue_id=q.id WHERE n.id=?');$q->execute([$failed['notice_id']]);
    hw_check($q->fetchColumn()==='current-device-person@example.test','retry uses newly verified exact device contact');
    $p2=new PDO($login['dsn'],$login['user'],$login['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $race=hw_send($pdo,hw_case($pdo,12));$nestedCalls=0;$resolver=hw_resolver();
    $nested=static function($b,$h)use($p2,$race,&$nestedCalls,$resolver,$sender){$nestedCalls++;hw_check(westy_hardware_dispatch($p2,$race['notice_id'],hw_settings(),$resolver,$sender)==='verifying','competing connection cannot own recipient claim');return $resolver($b,$h);};
    hw_check(westy_hardware_dispatch($pdo,$race['notice_id'],hw_settings(),$nested,$sender)==='provider_accepted' && $nestedCalls===1,'recipient claim serializes independent connections');
    $bindingRace=hw_send($pdo,hw_case($pdo,13));$beforeCalls=$calls;
    $resolverRace=static function($b,$h)use($p2,$resolver){$p2->exec("UPDATE suite_customer_sync_bindings SET status='inactive'");return $resolver($b,$h);};
    hw_check(westy_hardware_dispatch($pdo,$bindingRace['notice_id'],hw_settings(),$resolverRace,$sender)==='verification_failed' && $calls===$beforeCalls,'binding changed during recipient resolution prevents send');
    $pdo->exec("UPDATE suite_customer_sync_bindings SET status='active'");
    $concurrent=hw_case($pdo,14,'needs_human');$other=array_replace($concurrent,['event_key'=>hw_key(1500),'finding_key'=>hw_key(1501),'summary'=>'Rephrased same decision']);
    $notes=hw_count($pdo,'messages');$intents=hw_count($pdo,'westy_hardware_notices');
    $raceResults=hw_race($pdo,$login,[$concurrent,$other]);
    hw_check($raceResults[0]['notice_id']===$raceResults[1]['notice_id'] && $raceResults[0]['deduplicated']!==$raceResults[1]['deduplicated']
        && hw_count($pdo,'messages')===$notes+1 && hw_count($pdo,'westy_hardware_notices')===$intents+1,'concurrent changed sampling shares one semantic note/notice/version');
    $replays=hw_race($pdo,$login,[$concurrent,$concurrent]);
    hw_check($replays[0]['replayed'] && $replays[1]['replayed'],'concurrent exact receipt replay remains immutable');
    $rollback=hw_case($pdo,15,'working');$counts=[hw_count($pdo,'messages'),hw_count($pdo,'westy_hardware_notices'),hw_count($pdo,'westy_hardware_receipts')];
    $pdo->exec("CREATE TRIGGER fixture_hardware_failure BEFORE INSERT ON westy_hardware_findings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic persistence failure'");
    hw_refuse(fn()=>hw_send($pdo,$rollback),'persistence failure aborts complete transaction');
    hw_check($counts===[hw_count($pdo,'messages'),hw_count($pdo,'westy_hardware_notices'),hw_count($pdo,'westy_hardware_receipts')],'failed finding leaves no note, notice or receipt');
    $pdo->exec('DROP TRIGGER fixture_hardware_failure');
    $superseded=hw_case($pdo,16);$old=hw_send($pdo,$superseded);$beforeCalls=$calls;
    $boundary=new HardwareBoundaryPDO($login['dsn'],$login['user'],$login['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $boundary->beforeSubmission=static function()use($pdo,$superseded){hw_send($pdo,array_replace($superseded,['event_key'=>hw_key(1601),'finding_key'=>hw_key(1602),'decision_revision'=>2,'upgrade_options'=>'The owner now prefers technician assessment for a replacement computer instead of a drive upgrade.']));};
    hw_check(westy_hardware_dispatch($boundary,$old['notice_id'],hw_settings(),hw_resolver(),$sender)==='superseded' && $calls===$beforeCalls,'new decision after durable intent prevents stale options at actual provider boundary');
    $query=$pdo->prepare('SELECT outcome,outcome_code FROM westy_hardware_mail_attempts WHERE notice_id=?');$query->execute([$old['notice_id']]);$attempt=$query->fetch();
    hw_check($attempt['outcome']==='failed' && $attempt['outcome_code']==='decision_superseded_before_submission','known non-submission retained for superseded durable attempt');
    $crash=hw_send($pdo,hw_case($pdo,17));
    $child= <<<'PHP'
function utf8_clean(string $v):string{return $v;}
require $argv[1];require $argv[2];$in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$p=new PDO($in['login']['dsn'],$in['login']['user'],$in['login']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
westy_hardware_dispatch($p,$in['notice'],hw_settings(),hw_resolver(),static function(){echo "durable-attempt\n";flush();exit(17);});
PHP;
    $process=proc_open([PHP_BINARY,'-r',$child,__DIR__.'/../lib/westy_hardware.php',__DIR__.'/fixtures/westy_hardware.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('crash fixture did not start');
    fwrite($pipes[0],json_encode(['login'=>$login,'notice'=>$crash['notice_id']],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],10);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    hw_check(proc_close($process)===17 && trim($out)==='durable-attempt' && $err==='','sender process dies after persisted attempt before result');
    $beforeCalls=$calls;
    hw_check(westy_hardware_dispatch($pdo,$crash['notice_id'],hw_settings(),hw_resolver(),$sender)==='sending' && $calls===$beforeCalls,'interrupted in-flight attempt cannot be blindly retried');
    $pdo->prepare('UPDATE westy_hardware_notices SET updated_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-121),$crash['notice_id']]);
    hw_check(westy_hardware_dispatch($pdo,$crash['notice_id'],hw_settings(),hw_resolver(),$sender)==='uncertain' && $calls===$beforeCalls,'aged interrupted attempt becomes uncertain without another provider call');
    foreach (['westy_hardware_findings','westy_hardware_receipts'] as $table) {
        hw_refuse(fn()=> $pdo->exec("UPDATE $table SET tenant_id=2"),'immutable '.$table.' cannot move tenant');
        hw_refuse(fn()=> $pdo->exec("DELETE FROM $table"),'immutable '.$table.' cannot delete history');
    }
    $before=$pdo->query('SELECT * FROM westy_hardware_receipts ORDER BY id')->fetchAll();
    test_execute_sql_file($pdo,__DIR__.'/../db/migrations/037_westy_hardware_outcomes.sql');
    hw_check($before===$pdo->query('SELECT * FROM westy_hardware_receipts ORDER BY id')->fetchAll(),'037 replay retains immutable receipts');
    hw_check(hw_count($pdo,'westy_billing_outbox')===0 && hw_count($pdo,'time_entries')===0,'no closure, financial work, time, or command is created');
    echo "PASS {$checks} MySQL hardware outcome and notice checks.\n";
    require __DIR__.'/fixtures/westy_hardware_staff_cases.php';
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('cleanup scope changed');
    $pdo->exec("DROP DATABASE `$database`");
}
