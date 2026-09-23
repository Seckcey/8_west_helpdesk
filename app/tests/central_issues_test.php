<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/central_issues.php';
// This is bootstrap's encoding boundary without loading application configuration.
if (!function_exists('utf8_clean')) {
    function utf8_clean(string $s): string { return $s==='' || mb_check_encoding($s,'UTF-8') ? $s : mb_convert_encoding($s,'UTF-8','Windows-1252'); }
}
$ciChecks=0;
function ci_check(bool $ok,string $message): void {
    global $ciChecks; if (!$ok) throw new RuntimeException($message); ++$ciChecks;
}
function ci_refuse(callable $f,int $status,string $message): void {
    try { $f(); } catch (CentralIssueRefused $e) { ci_check($e->status===$status,$message.' (status)'); return; }
    throw new RuntimeException($message.' (accepted)');
}
function ci_key(int $n): string { return '10000000-0000-4000-8000-'.sprintf('%012d',$n); }
function ci_config(): array { return ['enabled'=>true,'tenant_id'=>1,'provider_tenant_id'=>11,'secret'=>str_repeat('a',64),'digest_key'=>str_repeat('b',64),'assistant_secret'=>str_repeat('c',64)]; }
function ci_request(string $op,array $payload,int $account=1): array {
    return ['account_ref'=>ci_key($account),'owner_sub'=>'t1u'.$account,'id_tenant_id'=>'1','binding_version'=>1,'operation'=>$op,'payload'=>$payload];
}
function ci_send(PDO $pdo,string $op,array $payload,int $account=1,string $actor='customer',?array $config=null): array {
    return central_issue_execute($pdo,$config??ci_config(),central_issue_request(json_encode(ci_request($op,$payload,$account),JSON_THROW_ON_ERROR),$actor),$actor);
}
function ci_units(): void {
    $cfg=central_issue_config(ci_config());
    ci_refuse(fn()=>central_issue_config(['enabled'=>false]),404,'disabled service');
    ci_refuse(fn()=>central_issue_config(array_replace($cfg,['digest_key'=>$cfg['secret']])),503,'separate digest authority');
    $body=json_encode(ci_request('list',['cursor'=>null])); $time=time();
    $server=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>CENTRAL_ISSUES_PATH,'HTTP_X_CENTRAL_CALLER'=>'central-web','HTTP_X_CENTRAL_TIMESTAMP'=>(string)$time];
    $server['HTTP_X_CENTRAL_SIGNATURE']=hash_hmac('sha256',"central.issues.v1\ncentral-web\nPOST\n".CENTRAL_ISSUES_PATH."\n$time\n".hash('sha256',$body),$cfg['secret']);
    ci_check(central_issue_auth($cfg,$body,$server,$time)==='customer','signed customer caller');
    ci_refuse(fn()=>central_issue_auth($cfg,$body.' ',$server,$time),401,'body tampering');
    ci_refuse(fn()=>central_issue_auth($cfg,$body,$server,$time+61),401,'expired signature');
    foreach (['REQUEST_URI'=>CENTRAL_ISSUES_PATH.'?x=1','REQUEST_METHOD'=>'GET','HTTP_X_CENTRAL_CALLER'=>'central-ai'] as $k=>$v)
        ci_refuse(fn()=>central_issue_auth($cfg,$body,array_replace($server,[$k=>$v]),$time),401,'transport/caller separation');
    ci_refuse(fn()=>central_issue_request($body,'assistant'),403,'assistant cannot list');
    ci_refuse(fn()=>central_issue_request(json_encode(ci_request('read',['issue_ref'=>null])),'customer'),400,'null issue identifier');
    $r=ci_request('create',['operation_key'=>ci_key(100),'expected_version'=>0,'title'=>' A title ','body'=>' A note ']);
    ci_check(central_issue_request(json_encode($r),'customer')['payload']['body']==='A note','normalized content');
    foreach (['',str_repeat('x',4001),str_repeat('😀',2001),"a\0b"] as $bad) {
        $r['payload']['body']=$bad;
        ci_refuse(fn()=>central_issue_request(json_encode($r),'customer'),400,'bounded plain text');
    }
    $r['payload']['body']='UTF-8 café 😀'; $r['payload']['actor']='assistant';
    ci_refuse(fn()=>central_issue_request(json_encode($r),'customer'),400,'browser cannot select actor');
    ci_refuse(fn()=>central_issue_request(str_repeat(' ',16385),'customer'),400,'bounded request');
    ci_refuse(fn()=>central_issue_request('{broken','customer'),400,'malformed JSON');
    ci_check(central_issue_text("Café 😀\nA second line",4000)==="Café 😀\nA second line",'unicode and line breaks survive');
    ci_refuse(fn()=>central_issue_text("Multi\nline",140,true),400,'single line title');
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { ci_units(); echo "PASS $ciChecks Central history boundary checks.\n"; }
