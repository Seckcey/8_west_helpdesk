<?php
/** New random databases only, on an explicitly disposable loopback MySQL server. */
declare(strict_types=1);
require_once __DIR__.'/central_issues_test.php';
if (PHP_SAPI!=='cli' || getenv('SAFEHARBOR_CENTRAL_TEST_DISPOSABLE_SERVER')!=='1') exit(2);
$host=getenv('SAFEHARBOR_CENTRAL_TEST_HOST')?:'127.0.0.1';
$port=getenv('SAFEHARBOR_CENTRAL_TEST_PORT')?:'3306';
$base=getenv('SAFEHARBOR_CENTRAL_TEST_DB')?:'';
if (!in_array($host,['127.0.0.1','localhost','::1'],true) || !ctype_digit($port) || (int)$port<1 || (int)$port>65535
    || preg_match('/\Asafeharbor_central_test(?:_[a-z0-9_]+)?\z/D',$base)!==1 || strlen($base)>42) exit(2);
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",getenv('SAFEHARBOR_CENTRAL_TEST_USER')?:'root',getenv('SAFEHARBOR_CENTRAL_TEST_PASS')?:'',$options);
function ci_sql(PDO $pdo,string $text): void {
    $delimiter=';'; $buffer='';
    foreach (preg_split('/\R/',$text) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',$line,$m)) { $delimiter=$m[1]; $buffer=''; continue; }
        if (str_starts_with(ltrim($line),'--')) continue;
        $buffer.=$line."\n";
        if (str_ends_with(rtrim($buffer),$delimiter)) {
            $s=trim(substr(rtrim($buffer),0,-strlen($delimiter)));
            if ($s!=='') { $q=$pdo->query($s); while ($q->nextRowset()) {} $q->closeCursor(); }
            $buffer='';
        }
    }
    if (trim($buffer)!=='') throw new RuntimeException('Unterminated SQL');
}
function ci_db_refuse(callable $f,string $message): void {
    try { $f(); } catch (PDOException) { ci_check(true,$message); return; }
    throw new RuntimeException($message.' (accepted)');
}
if (($argv[1]??'')==='--race') {
    // Child processes inherit only this synthetic test database; no application config.
    $race=getenv('CENTRAL_RACE_DB')?:'';
    if (!preg_match('/^'.preg_quote($base,'/').'_[a-f0-9]{12}$/D',$race)) exit(2);
    $pdo->exec("USE `$race`");
    try { ci_send($pdo,'message',['issue_ref'=>ci_key(900),'operation_key'=>ci_key((int)$argv[2]),'expected_version'=>1,'body'=>'Concurrent note']); echo 'accepted'; }
    catch (CentralIssueRefused $e) { echo $e->status===409?'conflict':'unexpected'; }
    exit;
}
$database=$base.'_'.bin2hex(random_bytes(6));
$runtimeName='central_rt_'.bin2hex(random_bytes(5)); $runtimeCreated=false;
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`"); $pdo->exec("SET time_zone='+00:00'");
    ci_units();
    // Use the full canonical schema so existing guards and customer boundaries participate.
    ci_sql($pdo,file_get_contents(__DIR__.'/../db/schema.sql'));
    ci_sql($pdo,file_get_contents(__DIR__.'/../db/migrations/001_mail_queue.sql'));
    ci_sql($pdo,file_get_contents(__DIR__.'/../db/migrations/002_svc_intake.sql'));
    ci_check((int)$pdo->query('SELECT central_issue_schema_health()')->fetchColumn()===1,'canonical fresh schema ready');
    $migration=file_get_contents(__DIR__.'/../db/migrations/029_central_issue_history.sql');
    ci_sql($pdo,$migration);
    $pdo->exec("INSERT INTO tenants(id,slug,name) VALUES(1,'central-one','Synthetic one'),(2,'central-two','Synthetic two')");
    $pdo->exec("INSERT INTO clients(id,tenant_id,name) VALUES(1,1,'Synthetic 1'),(2,1,'Synthetic 2'),(3,2,'Synthetic 3')");
    $pdo->exec("INSERT INTO svc_identities(tenant_id,service,display_name) VALUES(1,'central-issues','Central history'),(2,'central-issues','Central history')");
    for ($i=1;$i<=3;$i++) {
        $tenant=$i===3?2:1;
        $pdo->prepare('INSERT INTO suite_customer_sync_bindings(tenant_id,customer_id,client_id,source_version,display_name,status,last_event_id,last_occurred_at,last_request_sha256) VALUES(?,?,?,1,?,\'active\',?,UTC_TIMESTAMP(),?)')->execute([$tenant,ci_key(10000+$i),$i,'Synthetic '.$i,ci_key(11000+$i),str_repeat('d',64)]);
        $binding=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO central_issue_accounts(account_ref,tenant_id,customer_binding_id,customer_id,provider_tenant_id,owner_sub,id_tenant_id) VALUES(?,?,?,?,?,?,?)')->execute([ci_key($i),$tenant,$binding,ci_key(10000+$i),11,'t1u'.$i,'1']);
        $pdo->prepare('UPDATE central_issue_accounts SET enabled=1 WHERE account_ref=?')->execute([ci_key($i)]);
    }
    $sideTables=['tickets','messages','time_entries','mail_queue','westy_workflows','coastmark_time_export_claims'];
    $before=[]; foreach ($sideTables as $table) $before[$table]=$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    ci_check(ci_send($pdo,'list',['cursor'=>null])['items']===[],'empty account');
    $create=['operation_key'=>ci_key(100),'expected_version'=>0,'title'=>'Café <script>','body'=>'Opening 😀 note'];
    $receipt=ci_send($pdo,'create',$create)['receipt'];
    ci_check($receipt['version']===1 && !$receipt['replayed'],'create one issue');
    ci_check(ci_send($pdo,'create',$create)['receipt']['replayed'],'exact replay');
    ci_refuse(fn()=>ci_send($pdo,'create',array_replace($create,['body'=>'Changed'])),409,'conflicting replay');
    ci_check(ci_send($pdo,'list',['cursor'=>null],2)['items']===[],'same provider second customer isolated');
    ci_refuse(fn()=>ci_send($pdo,'read',['issue_ref'=>ci_key(100)],2),404,'foreign issue denied');
    ci_refuse(fn()=>ci_send($pdo,'list',['cursor'=>ci_key(100)],2),409,'foreign cursor denied');
    ci_refuse(fn()=>ci_send($pdo,'list',['cursor'=>null],3),403,'other tenant denied');
    foreach (['owner_sub'=>'t1u999','id_tenant_id'=>'2','binding_version'=>2] as $key=>$value) {
        $r=array_replace(ci_request('read',['issue_ref'=>ci_key(100)]),[$key=>$value]);
        ci_refuse(fn()=>central_issue_execute($pdo,ci_config(),$r,'customer'),403,'changed binding denied');
    }
    $pdo->exec('UPDATE central_issue_accounts SET enabled=0 WHERE tenant_id=1');
    ci_refuse(fn()=>ci_send($pdo,'create',$create),403,'disabled binding also denies replay');
    $pdo->exec('UPDATE central_issue_accounts SET enabled=1 WHERE tenant_id=1');
    $pdo->exec("UPDATE svc_identities SET is_active=0 WHERE tenant_id=1 AND service='central-issues'");
    ci_refuse(fn()=>ci_send($pdo,'read',['issue_ref'=>ci_key(100)]),403,'revoked service identity');
    $pdo->exec("UPDATE svc_identities SET is_active=1 WHERE tenant_id=1 AND service='central-issues'");
    $pdo->exec("UPDATE suite_customer_sync_bindings SET source_version=2,status='inactive',last_event_id='10000000-0000-4000-8000-000000012001',last_request_sha256=REPEAT('e',64) WHERE tenant_id=1 AND client_id=1");
    ci_refuse(fn()=>ci_send($pdo,'read',['issue_ref'=>ci_key(100)]),403,'inactive customer denied');
    $pdo->exec("UPDATE suite_customer_sync_bindings SET source_version=3,status='active',last_event_id='10000000-0000-4000-8000-000000012002',last_request_sha256=REPEAT('f',64) WHERE tenant_id=1 AND client_id=1");
    $note=['issue_ref'=>ci_key(100),'operation_key'=>ci_key(101),'expected_version'=>1,'body'=>'Assistant suggestion only'];
    ci_check(ci_send($pdo,'message',$note,1,'assistant')['receipt']['version']===2,'separate assistant turn');
    ci_refuse(fn()=>ci_send($pdo,'message',$note),409,'actor participates in replay identity');
    ci_refuse(fn()=>ci_send($pdo,'message',array_replace($note,['operation_key'=>ci_key(102)])),409,'stale version denied');
    $state=['issue_ref'=>ci_key(100),'operation_key'=>ci_key(103),'expected_version'=>2,'state'=>'resolved','body'=>'I verified it myself'];
    ci_refuse(fn()=>ci_send($pdo,'state',$state,1,'assistant'),403,'assistant cannot resolve');
    ci_send($pdo,'state',$state);
    $read=ci_send($pdo,'read',['issue_ref'=>ci_key(100)]);
    ci_check($read['issue']['resolution_source']==='customer_reported' && count($read['events'])===3,'resolution and complete ordered history');
    ci_refuse(fn()=>ci_send($pdo,'message',array_replace($note,['operation_key'=>ci_key(104),'expected_version'=>3])),409,'closed issue requires reopen');
    ci_send($pdo,'state',array_replace($state,['operation_key'=>ci_key(105),'expected_version'=>3,'state'=>'open','body'=>'Problem returned']));
    ci_send($pdo,'state',array_replace($state,['operation_key'=>ci_key(106),'expected_version'=>4,'state'=>'unresolved','body'=>'Still unresolved']));
    ci_refuse(fn()=>ci_send($pdo,'message',array_replace($note,['operation_key'=>ci_key(107),'expected_version'=>5]),1,'assistant'),409,'assistant stops on unresolved');
    ci_send($pdo,'message',array_replace($note,['operation_key'=>ci_key(108),'expected_version'=>5,'body'=>'Customer next steps']));
    ci_db_refuse(fn()=> $pdo->exec("UPDATE central_issue_accounts SET owner_sub='t1u999' WHERE account_ref='".ci_key(1)."'"),'ownership immutable');
    ci_db_refuse(fn()=> $pdo->exec("UPDATE central_issue_events SET body='rewritten' WHERE issue_ref='".ci_key(100)."'"),'history immutable');
    ci_db_refuse(fn()=> $pdo->exec('DELETE FROM central_issue_events'),'history cannot be deleted');
    $erase=['issue_ref'=>ci_key(100),'operation_key'=>ci_key(109),'expected_version'=>6];
    ci_check(ci_send($pdo,'erase',$erase)['receipt']['erased'],'content erasure');
    ci_check((int)$pdo->query('SELECT COUNT(*) FROM central_issue_events WHERE body IS NOT NULL')->fetchColumn()===0,'all issue message content erased');
    ci_check($pdo->query('SELECT title FROM central_issues LIMIT 1')->fetchColumn()==='','title erased');
    ci_check(ci_send($pdo,'erase',$erase)['receipt']['replayed'],'erase retry is idempotent');
    ci_check(ci_send($pdo,'create',$create)['receipt']['replayed'],'old create returns original receipt without resurrection');
    ci_refuse(fn()=>ci_send($pdo,'read',['issue_ref'=>ci_key(100)]),410,'erased issue cannot be exported');
    ci_refuse(fn()=>ci_send($pdo,'state',array_replace($state,['operation_key'=>ci_key(110),'expected_version'=>7,'state'=>'open'])),410,'erased issue cannot reopen');
    ci_check(ci_send($pdo,'list',['cursor'=>null])['items']===[],'erased issue hidden from list');
    // Race two independent PDO connections while the account lock queues both requests.
    ci_send($pdo,'create',['operation_key'=>ci_key(900),'expected_version'=>0,'title'=>'Race','body'=>'Start']);
    putenv('CENTRAL_RACE_DB='.$database);
    $pdo->beginTransaction(); $pdo->query("SELECT account_ref FROM central_issue_accounts WHERE account_ref='".ci_key(1)."' FOR UPDATE");
    $children=[];
    foreach ([910,911] as $key) {
        $pipes=[]; $proc=proc_open([PHP_BINARY,__FILE__,'--race',(string)$key],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($proc)) throw new RuntimeException('Race worker unavailable');
        fclose($pipes[0]); $children[]=[$proc,$pipes];
    }
    usleep(150000); $pdo->commit(); $out=[];
    foreach ($children as [$proc,$pipes]) { $out[]=trim(stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); ci_check(proc_close($proc)===0 && $err==='','race worker completed'); }
    sort($out); ci_check($out===['accepted','conflict'],'concurrent writes accept exactly one version');
    // Separate customer receives 21 records to prove stable bounded pagination.
    for ($i=0;$i<21;$i++) ci_send($pdo,'create',['operation_key'=>ci_key(200+$i),'expected_version'=>0,'title'=>'Page '.$i,'body'=>'Synthetic'],2);
    $page=ci_send($pdo,'list',['cursor'=>null],2); $next=ci_send($pdo,'list',['cursor'=>$page['next_cursor']],2);
    ci_check(count($page['items'])===20 && count($next['items'])===1 && $next['next_cursor']===null,'bounded scoped pagination');
    ci_check(count(array_unique(array_column(array_merge($page['items'],$next['items']),'issue_ref')))===21,'pagination has no duplicate issues');
    for ($i=21;$i<30;$i++) ci_send($pdo,'create',['operation_key'=>ci_key(200+$i),'expected_version'=>0,'title'=>'Page '.$i,'body'=>'Synthetic'],2);
    ci_refuse(fn()=>ci_send($pdo,'create',['operation_key'=>ci_key(230),'expected_version'=>0,'title'=>'Over rate','body'=>'Synthetic'],2),429,'bounded mutation rate');
    ci_check(ci_send($pdo,'create',['operation_key'=>ci_key(200),'expected_version'=>0,'title'=>'Page 0','body'=>'Synthetic'],2)['receipt']['replayed'],'rate limit allows safe replay');
    ci_send($pdo,'erase',['issue_ref'=>ci_key(200),'operation_key'=>ci_key(240),'expected_version'=>1],2);
    ci_check(true,'rate limit allows content erasure');
    // Replay the exact migration over content, then prove runtime privilege and partial-guard behavior.
    $eventCount=$pdo->query('SELECT COUNT(*) FROM central_issue_events')->fetchColumn();
    ci_sql($pdo,$migration);
    ci_check($pdo->query('SELECT COUNT(*) FROM central_issue_events')->fetchColumn()===$eventCount,'migration replay preserves history');
    $password=bin2hex(random_bytes(24));
    $pdo->exec("CREATE USER '$runtimeName'@'%' IDENTIFIED BY '$password'"); $runtimeCreated=true;
    $pdo->exec("GRANT SELECT,INSERT,UPDATE ON `$database`.* TO '$runtimeName'@'%'");
    $runtime=new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$runtimeName,$password,$options);
    ci_db_refuse(fn()=>ci_send($runtime,'list',['cursor'=>null]),'health requires explicit execute');
    $pdo->exec("GRANT EXECUTE ON FUNCTION `$database`.central_issue_schema_health TO '$runtimeName'@'%'");
    ci_check(count(ci_send($runtime,'list',['cursor'=>null])['items'])===1,'DML runtime reads authorized history');
    ci_db_refuse(fn()=>ci_sql($runtime,$migration),'runtime cannot replace migration guards');
    ci_check((int)$pdo->query('SELECT central_issue_schema_health()')->fetchColumn()===1,'failed low privilege replay retains guards');
    $pdo->exec('DROP TRIGGER trg_ci_event_update');
    ci_refuse(fn()=>ci_send($runtime,'list',['cursor'=>null]),503,'partial migration fails closed for runtime');
    ci_sql($pdo,$migration);
    // Populate a separate tenant up to the retained-issue cap. New writes stop;
    $pdo->exec("GRANT EXECUTE ON FUNCTION `$database`.central_issue_schema_health TO '$runtimeName'@'%'");
    // erasure still frees capacity without exposing any other account's records.
    $config3=array_replace(ci_config(),['tenant_id'=>2]);
    for ($i=0;$i<100;$i++) $pdo->prepare('INSERT INTO central_issues(tenant_id,account_ref,issue_ref,title) VALUES(2,?,?,?)')->execute([ci_key(3),ci_key(3000+$i),'Capacity fixture']);
    ci_refuse(fn()=>ci_send($pdo,'create',['operation_key'=>ci_key(3200),'expected_version'=>0,'title'=>'Over capacity','body'=>'Synthetic'],3,'customer',$config3),507,'retained issue cap');
    ci_send($pdo,'erase',['issue_ref'=>ci_key(3000),'operation_key'=>ci_key(3201),'expected_version'=>1],3,'customer',$config3);
    ci_check(ci_send($pdo,'create',['operation_key'=>ci_key(3202),'expected_version'=>0,'title'=>'Capacity restored','body'=>'Synthetic'],3,'customer',$config3)['receipt']['version']===1,'erasure frees retained capacity');
    $pdo->exec('ALTER TABLE central_issues ALTER CHECK ck_central_issue_resolution NOT ENFORCED');
    ci_refuse(fn()=>ci_send($runtime,'list',['cursor'=>null]),503,'disabled safety constraint fails closed');
    $pdo->exec('ALTER TABLE central_issues ALTER CHECK ck_central_issue_resolution ENFORCED');
    foreach ($sideTables as $table) ci_check($pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn()===$before[$table],$table.' unchanged');
    echo "PASS $ciChecks Central history MySQL checks.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database) throw new RuntimeException('Cleanup scope changed');
    $pdo->exec("DROP DATABASE `$database`");
    if ($runtimeCreated) $pdo->exec("DROP USER '$runtimeName'@'%'");
}
