<?php
declare(strict_types=1);
function hw_key(int $id): string { return sprintf('%08x-aaaa-4aaa-8aaa-aaaaaaaaaaaa',$id); }
function hw_settings(): array { return ['enabled'=>true,'hmac_secret'=>str_repeat('synthetic-hardware-',3),'managed_providers_enabled'=>false]; }
function hw_payload(int $id = 1): array {
    return ['schema_version'=>3,'action'=>'hardware_outcome','event_key'=>hw_key($id*10+1),'workflow_key'=>hw_key($id*10+2),
        'tenant_slug'=>'8west','customer_id'=>hw_key(900),'alert_key'=>'alert:'.(8000+$id),'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'expected_version'=>6,'finding_key'=>hw_key($id*10+3),'issue_key'=>hw_key($id*10+4),'decision_revision'=>1,'device_id'=>800+$id,
        'device_binding_key'=>hash('sha256','synthetic-device-'.$id),'evidence_sha256'=>hash('sha256','synthetic-measurement-'.$id),
        'measured_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-30),'constraint_code'=>'storage_capacity',
        'summary'=>'The measured required free-space margin exceeds the approved temporary-file selection. The owner chose a storage upgrade while preserving data.',
        'upgrade_options'=>'A technician can verify the exact storage interface and supported capacity before selecting a larger drive. If storage cannot be expanded, discuss a replacement computer. No specific part or price is established.'];
}
function hw_status(array $p): array {
    return ['schema_version'=>3,'action'=>'hardware_status','event_key'=>hw_key(99999),'workflow_key'=>$p['workflow_key'],
        'tenant_slug'=>$p['tenant_slug'],'customer_id'=>$p['customer_id'],'alert_key'=>$p['alert_key'],'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),'finding_key'=>$p['finding_key']];
}
function hw_resolver(string $state = 'present', ?callable $change = null): callable {
    return static function(string $raw,array $headers) use ($state,$change): array {
        $request = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        $h=[]; foreach ($headers as $line) { [$key,$value]=explode(':',$line,2); $h[strtolower($key)]=trim($value); }
        $preimage=WESTY_HARDWARE_CONTACT_CONTEXT."\nPOST\n/api/svc/westy_hardware_contact.php\n".$h['x-8w-timestamp']."\n".$raw;
        if ($h['x-8w-service']!=='safeharbor-workflow' || !hash_equals(hash_hmac('sha256',$preimage,hw_settings()['hmac_secret']),$h['x-8w-signature'])) throw new RuntimeException('invalid resolver request signature');
        $result=$request+['ok'=>true,'contact_state'=>$state,'contact_reference'=>$state==='present'?str_repeat('a',32):null,
            'contact_revision'=>$state==='present'?1:null,'email'=>$state==='present'?'device-person@example.test':null,
            'resolved_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+30)];
        if ($change) $result=$change($result);
        $body=westy_hardware_json($result); $ts=(string)time();
        return ['status'=>200,'body'=>$body,'headers'=>['x-8w-service'=>'milepost-workflow','x-8w-timestamp'=>$ts,
            'x-8w-signature'=>hash_hmac('sha256',WESTY_HARDWARE_CONTACT_CONTEXT."-response\n".$ts."\n".$body,hw_settings()['hmac_secret'])]];
    };
}
