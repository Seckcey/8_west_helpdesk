<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_westy_tenant_ai.php';
function portal_westy_lock(PDO $pdo):string{return '';}
$checks=0;
function check(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE portal_westy_accounts(scope_key TEXT,tenant_id INT,client_id INT,conversation_key TEXT);
CREATE TABLE portal_westy_turns(id INT,scope_key TEXT,tenant_id INT,client_id INT,conversation_key TEXT,state TEXT,input_text TEXT,reply_json TEXT,expires_at TEXT,reason_code TEXT,charged_microusd INT,input_tokens INT,output_tokens INT,finished_at TEXT);
CREATE TABLE portal_westy_ai_attempts(id INT,turn_id INT,scope_key TEXT,sequence INT,state TEXT,reserve_microusd INT,charged_microusd INT,usage_json TEXT,finished_at TEXT);
CREATE TABLE portal_westy_budgets(tenant_id INT,client_id INT,month_key TEXT,charged_microusd INT);');
$scope=['key'=>str_repeat('a',64),'tenant'=>1,'client'=>11];$conversation=str_repeat('b',32);$month=gmdate('Y-m');
$reset=static function(string $state='pending',int $sequence=1)use($pdo,$scope,$conversation,$month):void{
    foreach(['accounts','turns','ai_attempts','budgets'] as $table)$pdo->exec('DELETE FROM portal_westy_'.$table);
    $pdo->prepare('INSERT INTO portal_westy_accounts VALUES(?,?,?,?)')->execute([$scope['key'],1,11,$conversation]);
    $pdo->prepare('INSERT INTO portal_westy_turns VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        1,$scope['key'],1,11,$conversation,$state,'Original request','{"reply":"Already visible"}',gmdate('Y-m-d H:i:s',time()+1000),
        $state==='pending'?'':'stopped',$sequence===1?100:140,$sequence===1?null:10,$sequence===1?null:2,null]);
    $pdo->prepare('INSERT INTO portal_westy_ai_attempts VALUES(?,?,?,?,?,?,?,?,?)')->execute([1,1,$scope['key'],$sequence,'pending',100,100,null,null]);
    $pdo->prepare('INSERT INTO portal_westy_budgets VALUES(?,?,?,?)')->execute([1,11,$month,$sequence===1?100:140]);
};
$row=static fn(string $table):array=>$pdo->query('SELECT * FROM portal_westy_'.$table)->fetch();
$usage=['input'=>20,'cached_input'=>3,'cache_write'=>2,'cache_write_1h'=>1,'output'=>4];
$result=['ok'=>true,'data'=>['reply'=>'NEW_PRIVATE_RESULT'],'usage'=>$usage,'cost_micro_usd'=>30,'rounds'=>[['round'=>1,'usage'=>$usage,'cost_micro_usd'=>30]]];
$finish=static fn(bool $authorized=true,array $value=[])=>portal_westy_ai_finish($pdo,$scope,1,1,$conversation,$month,$value?:$result,$authorized);
$reset();$finish();
check($row('turns')['state']==='complete'&&$row('turns')['charged_microusd']===30&&$row('budgets')['charged_microusd']===30,'known completion charges once');
check($row('ai_attempts')['charged_microusd']===30&&json_decode($row('ai_attempts')['usage_json'],true)['known'],'attempt agrees with turn and budget');
$finish();check($row('budgets')['charged_microusd']===30,'completion replay cannot refund twice');
$reset('unavailable');$finish();
check($row('turns')['state']==='unavailable'&&$row('turns')['reason_code']==='stopped'&&!str_contains($row('turns')['reply_json'],'NEW_PRIVATE_RESULT'),'Stop cannot be undone or receive late text');
check($row('turns')['charged_microusd']===30&&$row('budgets')['charged_microusd']===30&&$row('ai_attempts')['state']==='unavailable','known stopped receipt remains fully reconciled');
$reset();$finish(false);
check($row('turns')['state']==='unavailable'&&!str_contains($row('turns')['reply_json'],'NEW_PRIVATE_RESULT')&&$row('ai_attempts')['charged_microusd']===30,'revoked actor loses delivery but retains paid receipt');
$reset();$finish(true,['ok'=>false,'data'=>[],'usage'=>null,'cost_micro_usd'=>null]);
check($row('turns')['charged_microusd']===100&&$row('budgets')['charged_microusd']===100&&!json_decode($row('ai_attempts')['usage_json'],true)['known'],'unknown provider result keeps whole reservation');
$reset('pending',2);$finish();
check($row('turns')['charged_microusd']===70&&$row('budgets')['charged_microusd']===70&&$row('turns')['input_tokens']===36&&$row('turns')['output_tokens']===6,'continuation accumulates prior charge and tokens');
$reset();$pdo->exec('UPDATE portal_westy_turns SET input_text=NULL,reply_json=NULL');$finish();
check($row('turns')['reply_json']===null&&$row('turns')['state']==='unavailable','content erasure cannot be reversed by late completion');
$reset();$pdo->exec("UPDATE portal_westy_accounts SET conversation_key='changed'");$finish();
check($row('turns')['state']==='unavailable'&&!str_contains($row('turns')['reply_json'],'NEW_PRIVATE_RESULT'),'new conversation prevents late delivery');
$reset();$badScope=array_replace($scope,['client'=>12]);
try{portal_westy_ai_finish($pdo,$badScope,1,1,$conversation,$month,$result,true);check(false,'wrong scope');}catch(LogicException){check($row('ai_attempts')['state']==='pending'&&$row('budgets')['charged_microusd']===100,'wrong scope cannot mutate receipt');}
$clock=1791130000;$operation=static fn(int $stamp,string $version='f1'):string=>$version.sprintf('%08x',$stamp).str_repeat('a',22);
check(portal_westy_ai_new_operation($operation($clock),$clock),'new operation version and timestamp accepted');
check(portal_westy_ai_new_operation($operation($clock-90*86400+1),$clock),'last second before retention boundary accepted');
check(!portal_westy_ai_new_operation($operation($clock-90*86400),$clock),'exact metadata retention boundary refuses replay');
check(portal_westy_ai_new_operation($operation($clock+60),$clock)&&!portal_westy_ai_new_operation($operation($clock+61),$clock),'clock skew bounded to sixty seconds');
check(!portal_westy_ai_new_operation($operation($clock,'f2'),$clock)&&!portal_westy_ai_new_operation(str_repeat('a',32),$clock),'unsupported and legacy missing operations refused');
$reset();$resultLocked=false;
portal_westy_ai_finish($pdo,$scope,1,1,$conversation,$month,$result,static function()use($pdo,&$resultLocked):bool{$resultLocked=$pdo->inTransaction();throw new RuntimeException('revoked');});
check($resultLocked&&$row('ai_attempts')['charged_microusd']===30&&$row('turns')['state']==='unavailable','authority recheck inside transaction cannot discard paid receipt');
echo "portal_westy_ai_receipt_test: $checks checks passed\n";
