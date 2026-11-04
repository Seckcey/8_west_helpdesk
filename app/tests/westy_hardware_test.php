<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/westy_hardware.php';
require_once __DIR__.'/fixtures/westy_hardware.php';
$checks=0;
function hw_check(bool $ok,string $name): void { global $checks; if(!$ok)throw new RuntimeException($name);$checks++; }
function hw_refuse(callable $f,string $name): void { try{$f();}catch(Throwable){hw_check(true,$name);return;}hw_check(false,$name); }
$p=hw_payload();$raw=westy_hardware_json($p);
hw_check(westy_workflow_request($raw)===$p,'exact schema3 finding interoperates through existing parser');
hw_check(westy_workflow_request(westy_hardware_json(hw_status($p)))===hw_status($p),'strict schema3 status');
foreach ([['schema_version'=>4],['action'=>'resume'],['device_id'=>0],['decision_revision'=>'1'],['expected_version'=>0],['issue_key'=>'bad'],
    ['device_binding_key'=>'bad'],['evidence_sha256'=>null],['constraint_code'=>'model_says_upgrade'],['upgrade_options'=>''],['summary'=>str_repeat('x',2001)],
    ['measured_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+60)],['contact_email'=>'wrong@example.test'],['unexpected'=>[]]] as $change)
    hw_refuse(fn()=>westy_workflow_request(westy_hardware_json(array_replace($p,$change))),'invalid finding rejected');
hw_refuse(fn()=>westy_workflow_request(substr($raw,0,-1).',"device_id":999}'),'duplicate field rejected');
hw_refuse(fn()=>westy_workflow_request(westy_hardware_json(array_replace(hw_status($p),['expected_version'=>0]))),'v3 status does not accept extra authority fields');
$settings=hw_settings();
foreach (['present','absent','stale','ambiguous'] as $state) {
    $contact=westy_hardware_contact($p,80,$settings,hw_resolver($state));
    hw_check($contact['contact_state']===$state,'signed resolver preserves '.$state.' distinction');
}
foreach ([['customer_id'=>hw_key(901)],['device_id'=>999],['ticket_id'=>999],['request_key'=>str_repeat('0',32)],
    ['email'=>'postmaster@example.test'],['contact_revision'=>0],['contact_reference'=>'bad'],['email'=>"person@example.test\r\nBcc: private@example.test"],
    ['resolved_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-30)]] as $change)
    hw_refuse(fn()=>westy_hardware_contact($p,80,$settings,hw_resolver('present',fn($r)=>array_replace($r,$change))),'wrong or stale provenance rejected');
hw_refuse(fn()=>westy_hardware_contact($p,80,$settings,hw_resolver('absent',fn($r)=>array_replace($r,['email'=>'person@example.test']))),'absence cannot carry inferred recipient');
$valid=hw_resolver();
hw_refuse(fn()=>westy_hardware_contact($p,80,$settings,static function($b,$h)use($valid){$r=$valid($b,$h);$r['body'].=' ';return $r;}),'resolver body substitution rejected');
hw_refuse(fn()=>westy_hardware_contact($p,80,$settings,fn()=>['status'=>503]),'resolver outage is not contact absence');
echo "PASS {$checks} hardware parser and signed contact checks.\n";
