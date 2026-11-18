<?php
/** Included only by the disposable MySQL suite; actual endpoint, auth and ticket POST code. */
declare(strict_types=1);
$httpDir=sys_get_temp_dir().'/safeharbor-s5-http-'.bin2hex(random_bytes(8)); $process=null;
try {
    foreach (['lib','config','cron','public/api/svc','public/assets/w365','sessions'] as $part) mkdir($httpDir.'/'.$part,0700,true);
    $copyFixture = static function (string $from, string $to): void {
        if (!copy($from, $to)) throw new RuntimeException('Cannot copy HTTP fixture file '.basename($from));
    };
    $source=realpath(__DIR__.'/../../lib');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $target=$httpDir.'/lib/'.substr($file->getPathname(),strlen($source)+1);
        if ($file->isDir()) mkdir($target,0700,true);
        else $copyFixture($file->getPathname(), $target);
    }
    foreach (['public/ticket.php','public/api/svc/westy_workflow.php','public/api/svc/alerts.php',
        'public/assets/w365/w365-products.json','cron/westy_billing_dispatch.php'] as $file) {
        $copyFixture(__DIR__.'/../../'.$file, $httpDir.'/'.$file);
    }
    $httpConfig=require __DIR__.'/../../config/config.sample.php';
    $httpConfig['app_env']='production'; $httpConfig['force_https']=false;
    $httpConfig['db']=['host'=>$host,'port'=>(int)$port,'name'=>$database,'charset'=>'utf8mb4','user'=>$runtimeName,'pass'=>$runtimePassword];
    $httpConfig['westy_workflow']=wl_settings();
    $writeConfig = static function () use ($httpDir, &$httpConfig): void {
        if (file_put_contents($httpDir.'/config/config.php', '<?php return '.var_export($httpConfig, true).';') === false) {
            throw new RuntimeException('Cannot write synthetic HTTP fixture config');
        }
    };
    $writeConfig();
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if (!$socket) throw new RuntimeException('HTTP fixture bind failed');
    $address=stream_socket_get_name($socket,false); fclose($socket);
    // The page phase switches databases by rewriting the synthetic config between requests.
    $process=proc_open([PHP_BINARY,'-d','opcache.enable_cli=0','-d','session.save_path='.$httpDir.'/sessions','-S',$address,'-t',$httpDir.'/public'],[0=>['pipe','r'],1=>['file',$httpDir.'/http.log','a'],2=>['file',$httpDir.'/http.log','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('HTTP fixture did not start'); fclose($pipes[0]);
    $deadline=microtime(true)+5;
    do { $probeSocket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if ($probeSocket) { fclose($probeSocket); break; } usleep(20000); } while (microtime(true)<$deadline);
    if (!$probeSocket) throw new RuntimeException('HTTP fixture readiness failed');
    $send=static function(string $path,string $body,array $headers=[]) use ($address): array {
        $http_response_header=[];
        $result=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>['method'=>'POST',
            'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>8]]));
        if ($result===false || !preg_match('/\s(\d{3})\s/',$http_response_header[0] ?? '',$match)) throw new RuntimeException('HTTP fixture request failed');
        return [(int)$match[1],$result];
    };
    $signed=static function(array $p,?int $timestamp=null,?string $signature=null) use ($send): array {
        $raw=westy_work_log_json($p); $ts=(string)($timestamp ?? time());
        return $send('/api/svc/westy_workflow.php',$raw,['Content-Type: application/json','X-8W-Service: milepost-workflow',
            'X-8W-Timestamp: '.$ts,'X-8W-Signature: '.($signature ?? hash_hmac('sha256',WESTY_WORKFLOW_CONTEXT."\n".$ts."\n".$raw,str_repeat('s',40)))]);
    };
    $httpCase=wl_case($pdo,100,'resolved');
    foreach ([$httpCase,wl_qa($httpCase)] as $p) {
        [$status,$body]=$signed($p,null,str_repeat('0',64));
        wl_check($status===401 && json_decode($body,true)===['ok'=>false,'error'=>'unauthorized'],'actual endpoint wrong signature');
        wl_check($signed($p,time()-301)[0]===401,'actual endpoint stale timestamp');
    }
    [$status,$body]=$signed($httpCase); wl_check($status===200 && json_decode($body,true)['stored_through']===1,'HTTP v4 signed log');
    [$status,$body]=$signed(wl_qa($httpCase)); wl_check($status===200 && json_decode($body,true)['qa_state']==='passed','HTTP v4 signed QA');
    $pdo->exec('DROP FUNCTION westy_work_log_schema_health');
    foreach ([$httpCase,wl_qa($httpCase)] as $p) {
        [$status,$body]=$signed($p);
        wl_check($status===503 && json_decode($body,true)===['ok'=>false,'error'=>'workflow_v4_unavailable'],'actual endpoint v4 unavailable map');
    }
    test_execute_sql_file($pdo,$migration);
    $pdo->exec($grantWorkLog);
    westy_work_log_schema_ready($runtime);
    $bad=wl_log(101); $bad['entries'][0]['summary']='password: synthetic';
    [$status,$body]=$signed($bad); wl_check($status===422 && json_decode($body,true)===['ok'=>false,'error'=>'summary_unsafe'],'actual endpoint rejects unsafe text before SQL');

    // Full fresh install for the real ticket page; no real config or user data is read.
    $pdo->exec("USE `$fresh`");
    foreach (['002_svc_intake.sql','009_support_intake.sql','018_ticket_auto_close_eligibility.sql'] as $file) test_execute_sql_file($pdo,__DIR__.'/../../db/migrations/'.$file);
    $pdo->exec("INSERT INTO tenants(id,name,slug) VALUES(1,'Synthetic','8west'),(2,'Other','other')");
    $pdo->exec("INSERT INTO clients(id,tenant_id,name) VALUES(1,1,'Synthetic client'),(2,2,'Other client')");
    $pdo->exec("INSERT INTO users(id,tenant_id,email,password_hash,full_name,role) VALUES(1,1,'staff@example.test','unused','Synthetic technician','owner'),(2,2,'other@example.test','unused','Other technician','tech')");
    $pageCase=wl_case($pdo,777); $pageRun=wl_run($pdo,$pageCase); $ticket=(int)$pageRun['ticket_id'];
    $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$fresh`.* TO '$runtimeName'@'%'");
    foreach (['westy_workflow_schema_health','westy_work_log_schema_health'] as $fn) $pdo->exec("GRANT EXECUTE ON FUNCTION `$fresh`.$fn TO '$runtimeName'@'%'");
    $httpConfig['db']['name']=$fresh;
    $httpConfig['svc']=['enabled'=>true,'secrets'=>['milepost'=>str_repeat('s',40)]];
    $httpConfig['westy_billing_handoff']=['enabled'=>true,'endpoint'=>WESTY_BILLING_ENDPOINT,'service'=>'safeharbor-billing','secret'=>str_repeat('t',40),
        'tenant_slugs'=>['8west'],'customer_ids'=>[$pageCase['customer_id']]];
    $writeConfig();
    $session=bin2hex(random_bytes(16)); $csrf=bin2hex(random_bytes(32));
    file_put_contents($httpDir.'/sessions/sess_'.$session,'user_id|i:1;tenant_id|i:1;csrf|s:64:"'.$csrf.'";');
    $form=['work_log_step'=>'1','kind'=>'note','summary'=>'Synthetic logged step','customer_visible'=>'1'];
    $path='/ticket.php?id='.$ticket;
    wl_check($send($path,http_build_query($form),['Content-Type: application/x-www-form-urlencoded'])[0]===302,'actual page needs login');
    $headers=['Content-Type: application/x-www-form-urlencoded','Cookie: PHPSESSID='.$session];
    wl_check($send($path,http_build_query($form),$headers)[0]===419,'actual page needs CSRF');
    wl_check($send($path,http_build_query($form+['csrf'=>'wrong']),$headers)[0]===419,'actual page rejects wrong CSRF');
    wl_check(wl_count($pdo,'ticket_work_log')===0,'HTTP auth refusals insert nothing');
    [$status,$body]=$send($path,http_build_query($form+['csrf'=>$csrf]),$headers);
    wl_check($status===303,'actual Log step POST redirects after save (HTTP '.$status.')');
    $saved=$pdo->query('SELECT * FROM ticket_work_log')->fetch(); $current=wl_run($pdo,$pageCase);
    wl_check($saved['actor_key']==='safeharbor-user:1' && (int)$saved['customer_visible']===0 && $current['state']==='human_owned','HTTP derives actor/private visibility and takes over');
    // Foreign-ticket path is authorized by the actual tenant query before the writer.
    $otherSession=bin2hex(random_bytes(16));
    file_put_contents($httpDir.'/sessions/sess_'.$otherSession,'user_id|i:2;tenant_id|i:2;csrf|s:64:"'.$csrf.'";');
    $before=wl_count($pdo,'ticket_work_log');
    [$status,$body]=$send($path,http_build_query($form+['csrf'=>$csrf]),['Content-Type: application/x-www-form-urlencoded','Cookie: PHPSESSID='.$otherSession]);
    wl_check($status===200 && !str_contains($body,'name="work_log_step"') && wl_count($pdo,'ticket_work_log')===$before,
        'foreign ticket renders without a Log step form or a write (HTTP '.$status.')');

    // Actual CLI selection excludes a due qa_pending row, before any dispatch.
    $pdo->prepare("INSERT INTO westy_billing_outbox(tenant_id,workflow_id,event_key,state) VALUES(1,?,'s5-cron-pending','qa_pending')")->execute([$pageRun['id']]);
    $cron=proc_open([PHP_BINARY,$httpDir.'/cron/westy_billing_dispatch.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$cronPipes);
    if (!is_resource($cron)) throw new RuntimeException('Cron fixture did not start');
    fclose($cronPipes[0]); $cronOut=stream_get_contents($cronPipes[1]); $cronErr=stream_get_contents($cronPipes[2]);
    fclose($cronPipes[1]); fclose($cronPipes[2]);
    wl_check(proc_close($cron)===0 && $cronOut==='' && $cronErr==='','actual billing cron skips qa_pending without output or transport');

    // S11 at the actual signed alert endpoint with all legacy recovery guards installed.
    $pdo->exec("INSERT INTO svc_identities(tenant_id,service,display_name) VALUES(1,'milepost','Synthetic alerts')");
    $pdo->exec("INSERT INTO tickets(tenant_id,client_id,subject,status,channel,external_key,auto_close_eligible,sla_due_at) VALUES(1,1,'Untouched synthetic alert','open','alert','alert:8888',1,UTC_TIMESTAMP() + INTERVAL 1 HOUR)");
    $auto=(int)$pdo->lastInsertId();
    $raw=json_encode(['event'=>'resolved','external_key'=>'alert:8888','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z')],JSON_THROW_ON_ERROR); $ts=(string)time();
    [$status,$body]=$send('/api/svc/alerts.php',$raw,['Content-Type: application/json','X-8W-Service: milepost','X-8W-Timestamp: '.$ts,'X-8W-Signature: '.hash_hmac('sha256',$ts."\n".$raw,str_repeat('s',40))]);
    wl_check($status===200 && (json_decode($body,true)['action'] ?? null)==='updated','signed source recovery endpoint still works');
    wl_check(westy_workflow_row($pdo,'SELECT status FROM tickets WHERE tenant_id=1 AND id=?',[$auto])['status']==='resolved','signed recovery closed untouched alert with 039');
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_dir($httpDir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($httpDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($httpDir);
    }
}
$pdo->exec("USE `$database`");
