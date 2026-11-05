<?php
/** Included at the end of the disposable hardware MySQL fixture. */
declare(strict_types=1);
require_once __DIR__.'/../../lib/westy_hardware_staff.php';

function hw_staff_case(PDO $pdo, int $id, ?int $assignee=1): array {
    $p=hw_case($pdo,$id);$r=hw_send($pdo,$p);
    $pdo->prepare('UPDATE tickets SET assignee_id=? WHERE id=?')->execute([$assignee,$r['ticket_id']]);
    hw_check(westy_hardware_dispatch($pdo,$r['notice_id'],hw_settings(),hw_resolver('absent'),
        static fn()=>throw new LogicException('No customer provider should run'))==='no_device_contact','fixture reaches normal device absence');
    return [$p,$r];
}
function hw_staff_status(PDO $pdo,array $p): array {
    return hw_send($pdo,array_replace(hw_status($p),['action'=>'hardware_staff_status']));
}
function hw_staff_dispatch(PDO $pdo,array $r,callable $sender): string {
    return westy_hardware_staff_dispatch($pdo,1,$r['notice_id'],hw_settings(),$sender);
}
function hw_staff_existing(PDO $pdo,array $r): ?array {
    return westy_workflow_row($pdo,'SELECT * FROM westy_hardware_staff_notices WHERE tenant_id=? AND notice_id=?',[1,$r['notice_id']]);
}
function hw_staff_due(PDO $pdo,array $r): void {
    $pdo->prepare('UPDATE westy_hardware_staff_notices SET next_try_at=? WHERE tenant_id=? AND notice_id=?')
        ->execute([gmdate('Y-m-d H:i:s',time()-1),1,$r['notice_id']]);
}
function hw_staff_child(array $login,array $r,string $mode): array {
    $code= <<<'PHP_CHILD'
function utf8_clean(string $v):string{return $v;}
require $argv[1];require $argv[2];$in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$p=new PDO($in['login']['dsn'],$in['login']['user'],$in['login']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo $p->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";flush();$calls=0;
$state=westy_hardware_staff_dispatch($p,1,$in['notice'],hw_settings(),static function()use(&$calls,$in){
    if($in['mode']==='crash'){echo "durable-staff-attempt\n";flush();exit(17);}
    $calls++;
    return ['outcome'=>'provider_accepted','transport'=>'graph','provider_http'=>202,'outcome_code'=>'graph_accepted'];
});
echo json_encode(['state'=>$state,'calls'=>$calls],JSON_THROW_ON_ERROR);
PHP_CHILD;
    $process=proc_open([PHP_BINARY,'-r',$code,__DIR__.'/../../lib/westy_hardware_staff.php',__DIR__.'/westy_hardware.php'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('staff process did not start');
    fwrite($pipes[0],json_encode(['login'=>$login,'notice'=>$r['notice_id'],'mode'=>$mode],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],10);
    return [$process,$pipes,(int)trim((string)fgets($pipes[1]))];
}
function hw_staff_child_finish(array $child): array {
    [$process,$pipes]=$child;$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$output,$error];
}
class StaffBoundaryPDO extends PDO {
    public int $begins=0;
    public mixed $beforeProvider=null;
    public function beginTransaction():bool {
        if(++$this->begins===2 && $this->beforeProvider!==null)($this->beforeProvider)();
        return parent::beginTransaction();
    }
}

$staffChecksStart=$checks;
[$p,$r]=hw_staff_case($pdo,100);
$legacy=hw_send($pdo,hw_status($p));
try { hw_staff_status($pdo,$p); throw new LogicException('missing staff tables should not report null receipt'); }
catch(PDOException $error){hw_check((int)($error->errorInfo[1]??0)===1146,'only optional read requires 038');}
test_execute_sql_file($pdo,__DIR__.'/../../db/migrations/038_westy_hardware_staff_notices.sql');
hw_check(hw_count($pdo,'westy_hardware_staff_notices')===0,'038 creates no receipt, mail, or backfill');
$contract=json_decode(file_get_contents(__DIR__.'/../../../docs/contracts/westy-workflow-hardware-staff-v3.json'),true,16,JSON_THROW_ON_ERROR);
$absent=hw_staff_status($pdo,$p);
hw_check(array_keys($absent)===$contract['response_fields'] && $absent['staff_notice']===null,'strict optional staff status before dispatch');
hw_refuse(fn()=>hw_send($pdo,array_replace(hw_status($p),['action'=>'hardware_staff_status','email'=>'forbidden@example.test'])),'projection does not accept extra fields');
hw_refuse(fn()=>hw_staff_status($pdo,array_replace($p,['finding_key'=>hw_key(9111)])),'projection does not accept a foreign or missing finding');
$parentBefore=westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE id=?',[$r['notice_id']]);
$staffHistory=static function()use($pdo,$r):array {
    return [$pdo->query('SELECT * FROM westy_hardware_findings ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM westy_hardware_receipts ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM westy_hardware_mail_attempts ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM messages WHERE ticket_id='.(int)$r['ticket_id'].' ORDER BY id')->fetchAll()];
};
$historyBefore=$staffHistory();$mailBefore=hw_count($pdo,'mail_queue');$staffCalls=[];
$staffSend=static function($to,$subject,$body)use(&$staffCalls){$staffCalls[]=[$to,$subject,$body];return ['outcome'=>'provider_accepted','transport'=>'graph','provider_http'=>202,'outcome_code'=>'graph_accepted'];};
$queueBeforeSend=static function($to,$subject,$body)use($staffSend,$p2,$p){
    // The normal provider call owns the transaction; use an ordinary read for committed evidence.
    $q=westy_workflow_row($p2,"SELECT s.state,s.recipient_email,a.outcome FROM westy_hardware_staff_notices s
        JOIN westy_hardware_staff_attempts a ON a.tenant_id=s.tenant_id AND a.staff_notice_id=s.id AND a.attempt_number=1
        JOIN westy_hardware_notices n ON n.id=s.notice_id WHERE n.source_json->>'$.finding_key'=?",[$p['finding_key']]);
    hw_check($q && $q['state']==='sending' && $q['outcome']===null && $q['recipient_email']===$to,'queue and attempt committed before transport');
    return $staffSend($to,$subject,$body);
};
$runtimeName='staff_fixture_'.bin2hex(random_bytes(5));
$pdo->exec("CREATE USER '$runtimeName'@'%' IDENTIFIED BY 'synthetic-staff-fixture'");
try {
    $pdo->exec("GRANT SELECT,INSERT,UPDATE ON `$database`.* TO '$runtimeName'@'%'");
    $runtime=new PDO($login['dsn'],$runtimeName,'synthetic-staff-fixture',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    hw_check(hw_staff_dispatch($runtime,$r,$queueBeforeSend)==='provider_accepted','existing scoped DML grants submit assigned staff fallback');
} finally { $runtime=null;$pdo->exec("DROP USER '$runtimeName'@'%'"); }
$accepted=hw_staff_status($pdo,$p)['staff_notice'];
hw_check(array_keys($accepted)===$contract['staff_notice_fields'] && array_keys($accepted['attempt'])===$contract['attempt_fields']
    && $accepted['recipient_role']==='owner' && $accepted['recipient_user_id']===1 && $accepted['attempt']['provider_http']===202
    && !str_contains(westy_hardware_json($accepted),'@'),'staff read projection includes scoped identity and result without address');
hw_check($staffCalls[0][0]==='owner@example.test' && str_contains($staffCalls[0][2],$p['upgrade_options'])
    && str_contains($staffCalls[0][2],$p['summary']),'provider receives recorded staff address and actual options');
hw_check(hw_staff_dispatch($pdo,$r,$staffSend)==='provider_accepted' && count($staffCalls)===1 && hw_count($pdo,'mail_queue')===$mailBefore+1,'accepted and repeated ticks never duplicate queue or send');
hw_check(hw_same($legacy,hw_send($pdo,hw_status($p))) && $historyBefore===$staffHistory()
    && $parentBefore===westy_workflow_row($pdo,'SELECT * FROM westy_hardware_notices WHERE id=?',[$r['notice_id']]),'original device absence and all source/legacy evidence stay byte-identical');
hw_check((int)$pdo->query('SELECT COUNT(*) FROM mail_queue WHERE id='.(int)$accepted['mail_queue_id'].' AND attempts<max_attempts')->fetchColumn()===0,'legacy worker/rollback cannot pick held staff queue');
hw_check(westy_hardware_staff_dispatch($pdo,2,$r['notice_id'],hw_settings(),$staffSend)==='not_applicable','cross-tenant source cannot be addressed');
$pdo->exec("INSERT INTO users(id,tenant_id,is_active,role,email) VALUES(2,1,1,'tech','assigned@example.test'),(3,1,1,'admin','admin@example.test'),
    (4,2,1,'owner','foreign@example.test'),(5,1,0,'tech','inactive@example.test'),(6,1,1,'owner','second@example.test')");
foreach ([2=>'assigned@example.test',3=>'admin@example.test'] as $actor=>$email) {
    [, $assigned]=hw_staff_case($pdo,100+$actor,$actor);
    hw_check(hw_staff_dispatch($pdo,$assigned,$staffSend)==='provider_accepted' && end($staffCalls)[0]===$email,'eligible assignee wins over multiple owners');
}
[$ambiguousP,$ambiguous]=hw_staff_case($pdo,106,null);$beforeCalls=count($staffCalls);
hw_check(hw_staff_dispatch($pdo,$ambiguous,$staffSend)==='no_recipient' && hw_staff_existing($pdo,$ambiguous)['detail_code']==='ambiguous_tenant_owners'
    && count($staffCalls)===$beforeCalls && hw_staff_status($pdo,$ambiguousP)['staff_notice']['attempt']===null,'multiple owners produce explicit no-recipient receipt without attempt');
$pdo->exec("UPDATE users SET is_active=0 WHERE id=6");
foreach ([null,4,5] as $i=>$actor) {
    [, $fallback]=hw_staff_case($pdo,110+$i,$actor);
    hw_check(hw_staff_dispatch($pdo,$fallback,$staffSend)==='provider_accepted' && end($staffCalls)[0]==='owner@example.test','unassigned/foreign/inactive assignee falls back only to eligible tenant owner');
}
$pdo->exec("UPDATE users SET email='no-reply@example.test' WHERE id=1");
[, $invalid]=hw_staff_case($pdo,114,null);$beforeCalls=count($staffCalls);
hw_check(hw_staff_dispatch($pdo,$invalid,$staffSend)==='no_recipient' && hw_staff_existing($pdo,$invalid)['detail_code']==='no_eligible_staff'
    && count($staffCalls)===$beforeCalls,'machine-sender address is not a staff recipient');
$pdo->exec("UPDATE users SET email='owner@example.test' WHERE id=1");

foreach (["email='changed@example.test'","role='admin'","is_active=0"] as $i=>$change) {
    [$raceP,$raceR]=hw_staff_case($pdo,120+$i,2);$beforeCalls=count($staffCalls);
    $boundary=new StaffBoundaryPDO($login['dsn'],$login['user'],$login['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $boundary->beforeProvider=static function()use($pdo,$change,$raceP,$contract){
        $queued=hw_staff_status($pdo,$raceP)['staff_notice'];
        hw_check(array_keys($queued['attempt'])===$contract['attempt_fields'] && $queued['state']==='queued' && $queued['attempt']['outcome']===null
            && $queued['attempt']['completed_at']===null,'signed projection does not promote a claimed attempt');
        $pdo->exec("UPDATE users SET $change WHERE id=2");
    };
    hw_check(hw_staff_dispatch($boundary,$raceR,$staffSend)==='suppressed' && count($staffCalls)===$beforeCalls
        && hw_staff_existing($pdo,$raceR)['recipient_email']==='assigned@example.test','changed address/role/activity suppresses frozen intent with no reroute');
    $pdo->exec("UPDATE users SET email='assigned@example.test',role='tech',is_active=1 WHERE id=2");
}
[$revP,$revR]=hw_staff_case($pdo,124);$beforeCalls=count($staffCalls);
$boundary=new StaffBoundaryPDO($login['dsn'],$login['user'],$login['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$boundary->beforeProvider=static fn()=>hw_send($pdo,array_replace($revP,['event_key'=>hw_key(9001),'finding_key'=>hw_key(9002),'decision_revision'=>2]));
hw_check(hw_staff_dispatch($boundary,$revR,$staffSend)==='suppressed' && count($staffCalls)===$beforeCalls,'superseding hardware decision cancels stale staff submission');

[$retryP,$retry]=hw_staff_case($pdo,126);
$knownReject=static fn()=>['outcome'=>'failed','transport'=>'graph','provider_http'=>429,'outcome_code'=>'graph_send_rejected'];
hw_check(hw_staff_dispatch($pdo,$retry,$knownReject)==='failed','known provider refusal is distinct from uncertain');
$retryQueue=hw_staff_existing($pdo,$retry)['mail_queue_id'];hw_staff_due($pdo,$retry);
hw_check(hw_staff_dispatch($pdo,$retry,$staffSend)==='provider_accepted' && hw_staff_status($pdo,$retryP)['staff_notice']['attempt']['number']===2
    && hw_staff_existing($pdo,$retry)['mail_queue_id']===$retryQueue,'known failure retries same frozen recipient and queue identity');
[, $unknown]=hw_staff_case($pdo,127);$beforeCalls=count($staffCalls);
hw_check(hw_staff_dispatch($pdo,$unknown,static fn()=>throw new RuntimeException('provider response lost'))==='uncertain'
    && hw_staff_dispatch($pdo,$unknown,$staffSend)==='uncertain' && count($staffCalls)===$beforeCalls,'lost provider result cannot retry');
[, $staffCrash]=hw_staff_case($pdo,128);[$exit,$out,$err]=hw_staff_child_finish(hw_staff_child($login,$staffCrash,'crash'));
hw_check($exit===17 && trim($out)==='durable-staff-attempt' && $err==='','separate sender exits after committed claim');
hw_check(hw_staff_dispatch($pdo,$staffCrash,$staffSend)==='sending','unaged crashed claim is not taken over');
hw_staff_due($pdo,$staffCrash);
$pdo->prepare("UPDATE tickets SET status='resolved',resolved_at=UTC_TIMESTAMP() WHERE id=?")->execute([$staffCrash['ticket_id']]);
hw_check(hw_staff_dispatch($pdo,$staffCrash,$staffSend)==='uncertain' && count($staffCalls)===$beforeCalls,'aged crash reconciles after ticket closure without another send');

[, $compete]=hw_staff_case($pdo,129);$mailBefore=hw_count($pdo,'mail_queue');$children=[];
$pdo->beginTransaction();
try {
    $pdo->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE')->fetchColumn();
    $children=[hw_staff_child($login,$compete,'race'),hw_staff_child($login,$compete,'race')];
    $probe=$pdo->prepare("SELECT COUNT(DISTINCT t.PROCESSLIST_ID) FROM performance_schema.data_lock_waits l
        JOIN performance_schema.threads t ON t.THREAD_ID=l.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID IN (?,?)");
    $limit=microtime(true)+8;
    do {$probe->execute([$children[0][2],$children[1][2]]);$waiting=(int)$probe->fetchColumn();if($waiting!==2)usleep(20000);} while($waiting!==2 && microtime(true)<$limit);
    hw_check($waiting===2,'two independent staff ticks actually compete for the tenant/notice lock');
    $pdo->commit();$results=[];
    while($children){[$exit,$out,$err]=hw_staff_child_finish(array_shift($children));hw_check($exit===0 && $err==='','concurrent staff worker exited');$results[]=json_decode($out,true,8,JSON_THROW_ON_ERROR);}
    $record=hw_staff_existing($pdo,$compete);
    hw_check(array_sum(array_column($results,'calls'))===1 && hw_count($pdo,'mail_queue')===$mailBefore+1 && (int)$record['send_attempts']===1,
        'concurrent ticks create one staff receipt, queue and attempt');
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($children as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
}
$staffBefore=$pdo->query('SELECT * FROM westy_hardware_staff_notices ORDER BY id')->fetchAll();
$attemptBefore=$pdo->query('SELECT * FROM westy_hardware_staff_attempts ORDER BY staff_notice_id,attempt_number')->fetchAll();
test_execute_sql_file($pdo,__DIR__.'/../../db/migrations/038_westy_hardware_staff_notices.sql');
hw_check($staffBefore===$pdo->query('SELECT * FROM westy_hardware_staff_notices ORDER BY id')->fetchAll()
    && $attemptBefore===$pdo->query('SELECT * FROM westy_hardware_staff_attempts ORDER BY staff_notice_id,attempt_number')->fetchAll(),'038 replay retains receipts and attempts');
hw_refuse(fn()=> $pdo->exec("UPDATE westy_hardware_staff_notices SET recipient_email='rewritten@example.test' WHERE id=".(int)$accepted['id']),'frozen staff address cannot be overwritten');
hw_refuse(fn()=> $pdo->exec('DELETE FROM westy_hardware_staff_attempts WHERE staff_notice_id='.(int)$accepted['id']),'staff attempt cannot be deleted');
[, $scheduled]=hw_staff_case($pdo,130);
westy_hardware_staff_dispatch_due($pdo,hw_settings(),$staffSend);
hw_check(hw_staff_existing($pdo,$scheduled)['state']==='provider_accepted','normal due scanner picks up a completed missing-contact notice');
hw_check((int)$pdo->query("SELECT COUNT(*) FROM westy_hardware_staff_notices s JOIN westy_hardware_notices n
    ON n.tenant_id=s.tenant_id AND n.id=s.notice_id WHERE n.state IN ('provider_accepted','uncertain')")->fetchColumn()===0,
    'scheduled fallback does not pick accepted or uncertain customer mail');
echo 'PASS '.($checks-$staffChecksStart)." MySQL hardware staff fallback checks.\n";
