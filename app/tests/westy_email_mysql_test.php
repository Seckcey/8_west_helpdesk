<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1';$port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
if(!in_array($host,['127.0.0.1','localhost','::1'],true)||!ctype_digit($port))exit(2);
$database='safeharbor_westy_test_email_'.bin2hex(random_bytes(5));
function we_db(): PDO {global $host,$port,$database;return new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root',getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root',getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$database`");$p=we_db();$checks=0;
$settings=['westy_email.enabled'=>true,'westy_email.tenant_ids'=>[1],'westy_email.customer_ids'=>['customer-one']];
function cfg(string $key,mixed $default=null): mixed {global $settings;return $settings[$key]??$default;}
require_once __DIR__.'/../lib/westy_email.php';
function we_check(bool $yes,string $label): void {global $checks;if(!$yes)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function we_refuse(callable $fn,string $label): void {try{$fn();}catch(Throwable){we_check(true,$label);return;}we_check(false,$label);}
function we_sql(PDO $p,string $file): void {$delimiter=';';$buffer='';foreach(preg_split('/\R/',file_get_contents($file)) as $line){if(preg_match('/^DELIMITER (.+)$/',$line,$m)){$delimiter=$m[1];continue;}if(str_starts_with(trim($line),'--'))continue;$buffer.=$line."\n";if(str_ends_with(rtrim($buffer),$delimiter)){$sql=trim(substr(rtrim($buffer),0,-strlen($delimiter)));if($sql!=='')$p->exec($sql);$buffer='';}}if(trim($buffer)!=='')throw new RuntimeException('SQL incomplete');}
function we_context(PDO $p,int $ticket): array {$p->beginTransaction();try{return westy_email_context($p,1,$ticket,1);}finally{$p->rollBack();}}
function we_draft(PDO $p,int $ticket): array {$c=we_context($p,$ticket);$v=westy_email_template($c);$id=westy_email_save($p,1,$ticket,1,$v['subject'],$v['body'],bin2hex(random_bytes(16)),$c['fingerprint']);return westy_email_row($p,'SELECT * FROM westy_email_drafts WHERE id=?',[$id]);}
function we_case(PDO $p,int $id): void {$p->exec("INSERT INTO tickets VALUES($id,1,1,NULL,'[warning] mem on TEST-LAPTOP','open',NULL,'alert','alert:$id',UTC_TIMESTAMP())");$p->exec("INSERT INTO westy_workflows VALUES($id,1,1,'customer-one',$id,'alert:$id','human_owned',1)");}
$identity=static fn()=>true;$calls=0;$send=static function()use(&$calls){$calls++;return ['outcome'=>'submitted','provider_http'=>202];};
try{
 foreach([
 'CREATE TABLE tenants(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(64))',
 'CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,is_active INT,role VARCHAR(16),suite_subject VARCHAR(64))',
 'CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,UNIQUE(tenant_id,id))',
 'CREATE TABLE contacts(id INT UNSIGNED PRIMARY KEY,client_id INT UNSIGNED,name VARCHAR(128),email VARCHAR(190))',
 'CREATE TABLE tickets(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,contact_id INT UNSIGNED NULL,subject VARCHAR(190),status VARCHAR(16),merged_into_id INT UNSIGNED NULL,channel VARCHAR(16),external_key VARCHAR(64),updated_at DATETIME,UNIQUE(tenant_id,id))',
 'CREATE TABLE messages(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id INT UNSIGNED,kind VARCHAR(16),body TEXT,created_at DATETIME)',
 'CREATE TABLE westy_workflows(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,customer_id VARCHAR(36),ticket_id INT UNSIGNED,alert_key VARCHAR(64),state VARCHAR(16),version INT)',
 'CREATE TABLE suite_customer_sync_bindings(tenant_id INT UNSIGNED,client_id INT UNSIGNED,customer_id VARCHAR(36),status VARCHAR(16))'
 ] as $sql)$p->exec($sql.' ENGINE=InnoDB');
 $p->exec("INSERT INTO tenants VALUES(1,'one'),(2,'two');INSERT INTO users VALUES(1,1,1,'owner',''),(2,1,1,'tech',''),(3,2,1,'owner','');INSERT INTO clients VALUES(1,1),(2,2);INSERT INTO contacts VALUES(1,1,'Endpoint Person','endpoint@example.test'),(2,1,'Client Person','client@example.test'),(3,2,'Other','other@example.test');INSERT INTO suite_customer_sync_bindings VALUES(1,1,'customer-one','active')");
 we_sql($p,__DIR__.'/../db/migrations/026_westy_approved_email.sql');we_sql($p,__DIR__.'/../db/migrations/026_westy_approved_email.sql');we_check((int)$p->query('SELECT westy_email_schema_health()')->fetchColumn()===1,'migration and replay');
 for($i=1;$i<=20;$i++)we_case($p,$i);
 we_refuse(fn()=>we_draft($p,1),'missing contact refuses draft');
 westy_email_contact($p,1,1,1,2,'client');we_check(we_context($p,1)['contact']['email']==='client@example.test','explicit client fallback');
 westy_email_contact($p,1,1,1,1,'endpoint');we_check(we_context($p,1)['contact']['email']==='endpoint@example.test','endpoint contact takes priority');
 we_refuse(fn()=>westy_email_contact($p,1,1,1,3,'endpoint'),'cross-customer POC refused');
 we_refuse(fn()=>westy_email_contact($p,1,1,2,1,'endpoint'),'technician cannot change routing');
 $d=we_draft($p,1);we_check($calls===0,'save does not send');
 we_refuse(fn()=>westy_email_send($p,1,1,2,$d['id'],westy_email_review_hash($d),$send,$identity),'technician cannot approve');
 we_refuse(fn()=>westy_email_send($p,1,1,1,$d['id'],'bad',$send,$identity),'exact review required');
 we_refuse(fn()=>westy_email_send($p,1,1,1,$d['id'],westy_email_review_hash($d),$send,static fn()=>false),'revoked or unavailable identity refused');
 we_refuse(fn()=>westy_email_send($p,2,1,3,$d['id'],westy_email_review_hash($d),$send,$identity),'cross-tenant case refused');
 we_check(westy_email_send($p,1,1,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='submitted'&&$calls===1,'approved exact send once');
 we_check(westy_email_send($p,1,1,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='submitted'&&$calls===1,'duplicate approval reconciles existing receipt');
 we_refuse(fn()=>we_draft($p,1),'second advice send for same case blocked');
 we_refuse(fn()=>$p->exec("UPDATE westy_email_drafts SET body_text='changed' WHERE id=".$d['id']),'immutable approved content');
 we_refuse(fn()=>$p->exec('DELETE FROM westy_email_drafts'),'immutable history');
 we_refuse(fn()=>$p->exec("UPDATE westy_email_drafts SET attempted_at=NULL WHERE id=".$d['id']),'attempt cannot reset');
 $d=we_draft($p,2);$unknown=static function()use(&$calls){$calls++;throw new RuntimeException('lost response');};
 we_check(westy_email_send($p,1,2,1,$d['id'],westy_email_review_hash($d),$unknown,$identity)==='uncertain','lost response remains unknown');
 $before=$calls;we_check(westy_email_send($p,1,2,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='uncertain'&&$calls===$before,'unknown result never retries');
 $d=we_draft($p,3);$p->exec("UPDATE contacts SET email='changed@example.test' WHERE id=2");$p->exec("UPDATE contacts SET email='client@example.test' WHERE id=2");
 we_check(westy_email_send($p,1,3,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='revoked','contact change and revert revokes');
 $d=we_draft($p,4);$p->exec("INSERT INTO messages(ticket_id,kind,body,created_at) VALUES(4,'note','new evidence',UTC_TIMESTAMP())");we_check(westy_email_send($p,1,4,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='revoked','human evidence change revokes');
 $d=we_draft($p,5);westy_email_revoke($p,1,5,1,$d['id']);we_check(westy_email_send($p,1,5,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='revoked','explicit revocation stops send');
 $d=we_draft($p,6);$p->exec("UPDATE suite_customer_sync_bindings SET status='inactive'");we_refuse(fn()=>westy_email_send($p,1,6,1,$d['id'],westy_email_review_hash($d),$send,$identity),'inactive customer refused');$p->exec("UPDATE suite_customer_sync_bindings SET status='active'");
 $d=we_draft($p,7);$settings['westy_email.enabled']=false;we_refuse(fn()=>westy_email_send($p,1,7,1,$d['id'],westy_email_review_hash($d),$send,$identity),'disabled sending refused');$settings['westy_email.enabled']=true;
 $d=we_draft($p,8);$d2=we_draft($p,8);we_check(westy_email_send($p,1,8,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='revoked','editing creates new review and supersedes old draft');
 $d=we_draft($p,9);$parallel=we_db();$parallel->exec('SET innodb_lock_wait_timeout=1');$nestedCalls=0;
 $concurrent=static function()use($parallel,$d,$identity,&$nestedCalls){try{westy_email_send($parallel,1,9,1,$d['id'],westy_email_review_hash($d),static function()use(&$nestedCalls){$nestedCalls++;},$identity);}catch(Throwable){}return ['outcome'=>'submitted','provider_http'=>202];};
 we_check(westy_email_send($p,1,9,1,$d['id'],westy_email_review_hash($d),$concurrent,$identity)==='submitted'&&$nestedCalls===0,'competing connections cannot send twice');
 $d=we_draft($p,10);
 $childCode='$settings='.var_export($settings,true).';function cfg($k,$d=null){global $settings;return $settings[$k]??$d;}require '.var_export(__DIR__.'/../lib/westy_email.php',true).';$p=new PDO('.var_export("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",true).',getenv("SAFEHARBOR_WESTY_TEST_USER")?:"root",getenv("SAFEHARBOR_WESTY_TEST_PASS")?:"",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);westy_email_send($p,1,10,1,'.(int)$d['id'].','.var_export(westy_email_review_hash($d),true).',static function(){echo "claimed\\n";fflush(STDOUT);sleep(30);},static fn()=>true);';
 $process=proc_open([PHP_BINARY,'-r',$childCode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Crash test process unavailable');
 fclose($pipes[0]);stream_set_timeout($pipes[1],10);$claimed=fgets($pipes[1]);proc_terminate($process,9);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
 we_check(trim((string)$claimed)==='claimed','child committed send intent before termination');
 we_check(westy_email_send($p,1,10,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='uncertain','killed sender retains durable unknown result');
 $d=we_draft($p,11);$p->exec("UPDATE users SET role='tech' WHERE id=1");$p->exec("UPDATE users SET role='owner' WHERE id=1");we_check(westy_email_send($p,1,11,1,$d['id'],westy_email_review_hash($d),$send,$identity)==='revoked','permission change and revert requires fresh review');
 we_check((int)$p->query("SELECT COUNT(*) FROM tickets WHERE status<>'open'")->fetchColumn()===0,'emails never resolve tickets');
 we_sql($p,__DIR__.'/../db/migrations/026_westy_approved_email.sql');we_check((int)$p->query("SELECT COUNT(*) FROM westy_email_drafts WHERE state='submitted'")->fetchColumn()===2,'migration replay preserves send receipts');
 $p->exec('DROP TRIGGER trg_we_draft_update');we_refuse(fn()=>we_draft($p,12),'missing safety trigger refuses action');
 echo "PASS $checks MySQL approved-email checks\n";
}finally{if($p->inTransaction())$p->rollBack();$server->exec("DROP DATABASE `$database`");}
