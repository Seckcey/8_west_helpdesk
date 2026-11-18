<?php
/** S2/S3/S12: no settings file, database or network. */
declare(strict_types=1);
require_once __DIR__.'/fixtures/westy_work_log.php';
require_once __DIR__.'/../lib/westy_hardware.php';
function h(?string $s): string { return htmlspecialchars($s ?? '',ENT_QUOTES,'UTF-8'); }
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
$malicious = ['occurred_at'=>'2026-11-17 12:00:00','summary'=>'<script>alert("x")</script>','kind'=>'note','actor_type'=>'user','actor_key'=>'safeharbor-user:1'];
$staff=westy_work_log_timeline([$malicious]); $customer=westy_work_log_timeline([$malicious],true);
wl_check(!str_contains($staff,'<script>') && str_contains($staff,'&lt;script&gt;'),'staff escapes summary markup');
wl_check(!str_contains($customer,'<script>') && !str_contains($customer,'safeharbor-user:1') && !str_contains($customer,'Technician'),'customer renders time and summary only');
wl_check(westy_work_log_timeline([])==='','empty history renders nothing');
$form=westy_work_log_form(true,['summary'=>'</textarea><script>bad</script>']);
wl_check(str_contains($form,'name="csrf"') && !str_contains($form,'<script>') && !str_contains($form,'name="customer_visible"'),'form CSRF and escaping with no visibility control');
echo 'PASS '.($GLOBALS['wl_checks'] ?? 0)." work log pure checks.\n";
