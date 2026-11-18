<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/westy_work_log.php';

function wl_key(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
function wl_settings(): array { return ['enabled'=>true,'hmac_secret'=>str_repeat('s',40),'managed_providers_enabled'=>false]; }
function wl_vectors(): array { return json_decode(file_get_contents(__DIR__.'/westy_work_log_vectors.json'),true,16,JSON_THROW_ON_ERROR); }

/** Fixed contract inputs, independent of the stored expected hashes and bodies. */
function wl_vector_entries(): array
{
    $rows = [
        [1,'2026-11-17T11:50:00Z','system:milepost','detect','Print Spooler service stopped.','unknown',null,'alert:4242',true],
        [2,'2026-11-17T11:52:00Z','ai-staff:8west:l1_tech:1','action','Restarted the Print Spooler service (café ✓ a/b).','svc_running:Spooler',str_repeat('a',64),'alert:4242;job:345;repair_execution:7',true],
        [3,'2026-11-17T11:58:00Z','ai-staff:8west:l1_tech:1','verify','Fresh monitoring confirms the service is running.','svc_running:Spooler',str_repeat('b',64),'alert:4242;job:345;outcome:9',false],
    ];
    return array_map(static fn(array $row): array => array_combine(array_slice(WESTY_WORK_LOG_ENTRY_FIELDS,0,9),$row),$rows);
}

function wl_log(int $case, string $previous = '', int $first = 1, ?array $entries = null, bool $seal = true): array
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $workflow = wl_key($case);
    $p = ['schema_version'=>4,'action'=>'log','event_key'=>wl_key(1000000000+$case*1000+$first),'workflow_key'=>$workflow,
        'tenant_slug'=>'8west','customer_id'=>'11111111-1111-4111-8111-111111111111','alert_key'=>'alert:'.$case,
        'occurred_at'=>$now,'first_seq'=>$first,'entries'=>[],'sealed_seq'=>null,'log_sha256'=>null];
    $entries ??= [['seq'=>$first,'occurred_at'=>$now,'actor_key'=>'system:milepost','kind'=>'detect','summary'=>'Synthetic service finding.',
        'cause_code'=>'unknown','evidence_sha256'=>null,'milepost_ref'=>'alert:'.$case,'customer_visible'=>true]];
    $previous = $previous !== '' ? $previous : westy_work_log_genesis($workflow);
    foreach ($entries as $entry) {
        $entry['entry_sha256'] = westy_work_log_entry_hash($previous,$entry);
        $previous = $entry['entry_sha256']; $p['entries'][] = $entry;
    }
    if ($seal) { $p['sealed_seq'] = $p['entries'][count($p['entries'])-1]['seq']; $p['log_sha256'] = $previous; }
    return $p;
}

function wl_qa(array $log, string $verdict = 'passed', ?string $event = null): array
{
    return ['schema_version'=>4,'action'=>'qa','event_key'=>$event ?? wl_key(2000000000+(int)substr($log['alert_key'],6)),
        'workflow_key'=>$log['workflow_key'],'tenant_slug'=>$log['tenant_slug'],'customer_id'=>$log['customer_id'],
        'alert_key'=>$log['alert_key'],'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),'verdict'=>$verdict,'reason_code'=>'no_recurrence',
        'log_sha256'=>$log['log_sha256'],'evidence_sha256'=>str_repeat('b',64)];
}

function wl_send(PDO $pdo, array $p, ?array $settings = null): array
{
    $body = westy_work_log_json($p);
    return westy_workflow_receive($pdo,$settings ?? wl_settings(),westy_workflow_request($body),hash('sha256',$body));
}

function wl_check(bool $ok, string $name): void
{
    $GLOBALS['wl_checks'] = ($GLOBALS['wl_checks'] ?? 0) + 1;
    if (!$ok) throw new RuntimeException('FAIL: '.$name);
}

function wl_refuse(callable $call, string $code, string $name): void
{
    try { $call(); }
    catch (Throwable $error) { wl_check($code === '' || $error->getMessage() === $code,$name.' ('.$error->getMessage().')'); return; }
    wl_check(false,$name.' was accepted');
}

function wl_sql_refuse(callable $call, string $message, string $name): void
{
    try { $call(); }
    catch (PDOException $error) {
        wl_check(str_contains($error->getMessage(),$message),$name.' ('.$error->getMessage().')'); return;
    }
    wl_check(false,$name.' was accepted');
}
