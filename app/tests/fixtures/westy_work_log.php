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

/** Exercise the same QA receiver, dispatcher and cron query on SQLite and MySQL. */
function wl_qa_billing_cases(PDO $pdo, callable $sealedCase): void
{
    $insert = $pdo->prepare('INSERT INTO westy_billing_outbox (tenant_id,workflow_id,event_key,state) VALUES (1,?,?,?)');
    $outbox = static fn(int $id): ?array => westy_workflow_row($pdo, 'SELECT * FROM westy_billing_outbox WHERE tenant_id=1 AND workflow_id=?', [$id]);
    $sideRow = static fn(int $id): ?array => westy_workflow_row($pdo, 'SELECT * FROM westy_workflow_log_state WHERE tenant_id=1 AND workflow_id=?', [$id]);
    $receipts = static fn(): array => $pdo->query('SELECT * FROM westy_workflow_v4_receipts ORDER BY tenant_id,event_key')->fetchAll(PDO::FETCH_ASSOC);

    [$log,$id] = $sealedCase(500);
    $insert->execute([$id,'qa-held-control','qa_pending']);
    wl_check(wl_send($pdo, wl_qa($log))['qa_state'] === 'passed', 'receiver records passed');
    $released = $outbox($id);
    wl_check($released['state'] === 'waiting_for_time' && $released['detail_code'] === 'approved_time_required'
        && $released['payload_json'] === null && $released['payload_sha256'] === null && (int)$released['attempts'] === 0,
        'passed releases a held charge for approved time');
    $config = ['enabled'=>true,'service'=>'safeharbor-billing','endpoint'=>WESTY_BILLING_ENDPOINT,'secret'=>str_repeat('t',40),
        'tenant_slugs'=>['8west'],'customer_ids'=>[$log['customer_id']]];
    foreach (['passed','fault_zero','void'] as $i => $verdict) {
        [$log,$id] = $sealedCase(510 + $i);
        wl_check($outbox($id) === null && wl_send($pdo, wl_qa($log,$verdict))['qa_state'] === $verdict
            && $outbox($id) === null, $verdict.' on a workflow without a held charge creates no outbox row');
    }

    $setBilling = $pdo->prepare('UPDATE westy_billing_outbox SET state=?,payload_json=?,payload_sha256=?,attempts=?,detail_code=?
        WHERE tenant_id=1 AND workflow_id=?');
    $number = 600;
    foreach ([
        ['qa_pending',false,false], ['waiting_for_time',false,false], ['ready',false,false], ['blocked',false,false],
        ['sending',false,true], ['uncertain',false,true], ['accepted',false,true],
        ['sending',true,true], ['uncertain',true,true], ['accepted',true,true],
        ['waiting_for_time',true,true], ['ready',true,true], ['blocked',true,true],
    ] as [$state,$frozen,$refused]) {
        foreach ([['fault_zero',false], ['void',false], ['void',true]] as [$verdict,$afterPass]) {
            [$log,$id] = $sealedCase(++$number);
            $insert->execute([$id,'qa-case-'.$number,$afterPass ? 'qa_pending' : 'waiting_for_time']);
            if ($afterPass) wl_send($pdo, wl_qa($log));
            $detail = $state === 'blocked' ? 'billing_review_required' : 'approved_time_required';
            $setBilling->execute([$state,$frozen ? '{}' : null,$frozen ? hash('sha256','{}') : null,(int)$frozen,$detail,$id]);
            $qa = wl_qa($log,$verdict,wl_key(530000 + $number));
            $qa['reason_code'] = $verdict === 'void' ? 'recovery_reversed' : 'service_fault';
            $case = ($afterPass ? 'late ' : 'first ').$verdict.' with '.$state.($frozen ? ' (frozen)' : ' (unfrozen)');
            $before = $outbox($id);
            if ($refused) {
                $priorState = $sideRow($id);
                $priorReceipts = $receipts();
                wl_refuse(fn() => wl_send($pdo,$qa), 'qa_charge_already_dispatched', $case);
                wl_check($priorState['qa_state'] === ($afterPass ? 'passed' : null) && $sideRow($id) === $priorState
                    && $outbox($id) === $before && $receipts() === $priorReceipts, $case.' preserves the side row, outbox and receipts');
                continue;
            }
            $receipt = wl_send($pdo,$qa);
            $box = $outbox($id);
            $recorded = $sideRow($id);
            wl_check($receipt['qa_state'] === $verdict && $recorded['qa_state'] === $verdict && $recorded['qa_reason'] === $qa['reason_code'],
                $case.' records the verdict and reason');
            wl_check($box['state'] === 'blocked' && ($state === 'blocked' ? $box === $before : $box['detail_code'] === 'qa_'.$verdict)
                && $box['payload_json'] === null && $box['payload_sha256'] === null && (int)$box['attempts'] === 0, $case.' keeps the charge blocked');
            $due = array_map('intval', westy_billing_due_ids($pdo));
            wl_check(count($due) < 10 && in_array((int)$released['id'],$due,true) && !in_array((int)$box['id'],$due,true),
                $case.' is excluded from a cron batch with room for it');
            $calls = 0;
            $transport = static function () use (&$calls): array { ++$calls; return ['status'=>0,'body'=>'']; };
            wl_check(westy_billing_dispatch($pdo,(int)$box['id'],$config,$transport) === 'blocked' && $calls === 0 && $outbox($id) === $box,
                $case.' is skipped by direct dispatch without a write or transport call');
        }
    }
}
