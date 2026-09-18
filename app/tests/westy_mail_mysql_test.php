<?php
/** Disposable MySQL proof for 025/026/027/028 Westy mail boundaries. No network mail. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1') exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1'; $port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
if(!in_array($host,['127.0.0.1','localhost','::1'],true)||!ctype_digit($port))exit(2);
$name='safeharbor_westy_mail_test_'.bin2hex(random_bytes(5)); $user=getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root';$pass=getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'';
function cfg(string $key,mixed $default=null): mixed { global $CONFIG; $v=$CONFIG; foreach(explode('.',$key)as$part){if(!is_array($v)||!array_key_exists($part,$v))return $default;$v=$v[$part];}return $v; }
// Synthetic feed result seam; signature/cache verification has its own hermetic suite.
function revocation_list():?array { return $GLOBALS['wm_identity_snapshot']??null; }
function wm_cert(): array { $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); if($key===false)throw new RuntimeException('synthetic key failed'); $csr=openssl_csr_new(['commonName'=>'westy-mail-test'],$key); $cert=$csr===false?false:openssl_csr_sign($csr,null,$key,1); if($cert===false||!openssl_pkey_export($key,$pem)||!openssl_x509_export($cert,$crt))throw new RuntimeException('synthetic certificate failed'); $base=sys_get_temp_dir().'/safeharbor-westy-mail-'.bin2hex(random_bytes(6)); file_put_contents($base.'.key',$pem);file_put_contents($base.'.crt',$crt);chmod($base.'.key',0600);chmod($base.'.crt',0644);return [$base.'.key',$base.'.crt']; }
function wmdb(): PDO {global $host,$port,$name,$user,$pass;return new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function wmok(bool $v,string $s):void{global $checks;if(!$v)throw new RuntimeException($s);$checks++;echo "PASS $s\n";}
function wmdenied(callable $f,string $label):void { $denied=false;try{$f();}catch(Throwable){$denied=true;}wmok($denied,$label); }
function wmcase(PDO $p,int $id,bool $delegate=true,bool $approve=true,string $workflow='human_owned',string $suiteSubject=''):array {
 $p->prepare("INSERT INTO users VALUES(?,1,1,'owner',?)")->execute([$id,$suiteSubject]);
 $p->exec("INSERT INTO clients VALUES($id,1)");
 $p->exec("INSERT INTO contacts VALUES($id,$id,'person$id@example.test')");
 $p->exec("INSERT INTO suite_customer_sync_bindings(tenant_id,client_id,customer_id,status) VALUES(1,$id,'11111111-1111-4111-8111-111111111111','active')");
 $p->exec("INSERT INTO tickets(id,tenant_id,client_id,contact_id,channel,external_key) VALUES($id,1,$id,$id,'alert','alert:$id')");
 $p->prepare("INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES(1,?,'11111111-1111-4111-8111-111111111111',?,?,?,?,1,'human case',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$id,$id,sprintf('%08d-1111-4111-8111-111111111111',$id),'alert:'.$id,$workflow]);
 $p->beginTransaction();$context=westy_mail_admin($p,1,$id,$id);$p->commit();$token=bin2hex(random_bytes(16));$subject="[#$id] reviewed test [wm:$token]";
 $conversation=westy_mail_create_replacement($p,1,$id,$id,0,$subject,'Reviewed exact text.',$token,bin2hex(random_bytes(16)),$context['fingerprint']);
 if($approve){$m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$conversation]);westy_mail_approve($p,1,$id,$id,$conversation,westy_mail_review_hash($m),$delegate);}
 return compact('id','conversation','subject','token');
}
function wminput(array $c):array { $uuid=bin2hex(random_bytes(12));return ['provider_message_id'=>'graph-'.$uuid,'internet_message_id'=>'internet-'.$uuid,'from'=>'person'.$c['id'].'@example.test','source_reply_to'=>'person'.$c['id'].'@example.test','destination'=>'westy@8westit.com','subject'=>$c['subject'],'body'=>'Please review this.','auto'=>false,'auth_verified'=>true]; }
function wmreceived(PDO $p,array $c,callable $transport):int { westy_mail_send($p,1,$c['id'],$c['id'],$c['conversation'],$transport);$r=westy_mail_accept_inbound($p,1,wminput($c));if(($r['state']??'')!=='accepted')throw new RuntimeException('setup inbound not accepted');return (int)$r['receipt_id']; }
function wmwait(string $path):void { $until=microtime(true)+10;while(!is_file($path)){if(microtime(true)>$until)throw new RuntimeException('worker rendezvous timed out');usleep(20000);} }
function wmworker(array $c,int $receipt,string $base,bool $block):array {
 global $CONFIG,$host,$port,$name,$user,$pass,$workers;
 $payload=$base.'.json';
 file_put_contents($payload,json_encode(compact('CONFIG','host','port','name','user','pass','c','receipt','base','block'),JSON_THROW_ON_ERROR));chmod($payload,0600);
 $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/fixtures/westy_mail_worker.php',$payload],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($proc))throw new RuntimeException('worker failed');$workers[]=$proc;fclose($pipes[0]);return [$proc,$pipes];
}
function wmfinish(array $worker,bool $kill=false):string { [$proc,$pipes]=$worker;if($kill)proc_terminate($proc,9);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);if(!$kill&&$code!==0)throw new RuntimeException('worker failed: '.$stderr);return trim($stdout); }
function wmsql(PDO $p,string $file):void{$d=';';$b='';foreach(preg_split('/\R/',file_get_contents($file))as$l){if(preg_match('/^DELIMITER (.+)$/',$l,$m)){$d=$m[1];continue;}if(str_starts_with(trim($l),'--'))continue;$b.=$l."\n";if(str_ends_with(rtrim($b),$d)){$q=trim(substr(rtrim($b),0,-strlen($d)));if($q!==''){ $s=$p->query($q); if($s){do{$s->fetchAll();}while($s->nextRowset());$s->closeCursor();}}$b='';}}if(trim($b)!=='')throw new RuntimeException('incomplete sql');}
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$server->exec("CREATE DATABASE `$name`");$p=wmdb();$checks=0;[$keyPath,$certPath]=wm_cert();$_SESSION=['suite_session_version'=>'1.1'];$CONFIG=['westy_mail'=>['enabled'=>true,'tenant_ids'=>[1],'customer_ids'=>['11111111-1111-4111-8111-111111111111']],'westy_email'=>['graph'=>['enabled'=>true,'tenant_id'=>'11111111-1111-4111-8111-111111111111','client_id'=>'22222222-2222-4222-8222-222222222222','mailbox'=>'westy@8westit.com','sender'=>'westy@8westit.com','private_key_path'=>$keyPath,'certificate_path'=>$certPath,'timeout_seconds'=>5]]];
try{
 foreach([
 'CREATE TABLE tenants(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(64)) ENGINE=InnoDB',
 'CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,is_active TINYINT,role VARCHAR(16),suite_subject VARCHAR(64)) ENGINE=InnoDB',
 'CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,UNIQUE(tenant_id,id)) ENGINE=InnoDB',
 'CREATE TABLE contacts(id INT UNSIGNED PRIMARY KEY,client_id INT UNSIGNED,email VARCHAR(190)) ENGINE=InnoDB',
 'CREATE TABLE tickets(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,contact_id INT UNSIGNED NULL,subject VARCHAR(190) NOT NULL DEFAULT \'alert\',status VARCHAR(16) NOT NULL DEFAULT \'open\',priority VARCHAR(16) NOT NULL DEFAULT \'normal\',assignee_id INT UNSIGNED NULL,merged_into_id INT UNSIGNED NULL,channel VARCHAR(16),external_key VARCHAR(64),sla_due_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(tenant_id,id)) ENGINE=InnoDB',
 'CREATE TABLE messages(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id INT UNSIGNED,author_name VARCHAR(128),kind VARCHAR(16),body TEXT,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
 'CREATE TABLE time_entries(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED,client_id INT UNSIGNED,ticket_id INT UNSIGNED) ENGINE=InnoDB',
 'CREATE TABLE suite_customer_sync_bindings(id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,tenant_id INT UNSIGNED,client_id INT UNSIGNED,customer_id CHAR(36),status VARCHAR(16)) ENGINE=InnoDB',
 'CREATE TABLE svc_identities(id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED,service VARCHAR(64),is_active TINYINT) ENGINE=InnoDB'
 ]as$q)$p->exec($q);
 foreach(['025_westy_workflow.sql','026_westy_approved_email.sql','027_westy_mail_conversations.sql','028_westy_mail_poll_cursor.sql']as$f){wmsql($p,__DIR__.'/../db/migrations/'.$f);wmsql($p,__DIR__.'/../db/migrations/'.$f);}
 wmok((int)$p->query('SELECT westy_workflow_schema_health()')->fetchColumn()===1,'025 replay and health');
 wmok((int)$p->query('SELECT westy_email_schema_health()')->fetchColumn()===1,'026 replay and health');
 wmok((int)$p->query('SELECT westy_mail_schema_health()')->fetchColumn()===1,'027 replay and health');
 wmok((int)$p->query('SELECT westy_mail_poll_schema_health()')->fetchColumn()===1,'028 replay and health');
 foreach(["INSERT INTO tenants VALUES(1,'one')","INSERT INTO users VALUES(1,1,1,'owner','')","INSERT INTO clients VALUES(1,1)","INSERT INTO contacts VALUES(1,1,'person@example.test')","INSERT INTO tickets(id,tenant_id,client_id,contact_id,channel,external_key) VALUES(1,1,1,1,'alert','alert:1')","INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES(1,1,'11111111-1111-4111-8111-111111111111',1,'11111111-1111-4111-8111-111111111111','alert:1','human_owned',1,'human case',UTC_TIMESTAMP(),UTC_TIMESTAMP())","INSERT INTO suite_customer_sync_bindings(tenant_id,client_id,customer_id,status) VALUES(1,1,'11111111-1111-4111-8111-111111111111','active')","INSERT INTO westy_email_drafts(id,tenant_id,ticket_id,client_id,contact_id,recipient,subject,body_text,context_sha256,request_key,created_by) VALUES(7,1,1,1,1,'person@example.test','legacy','legacy',REPEAT('a',64),REPEAT('b',32),1)","UPDATE westy_email_drafts SET state='uncertain',approved_by=1,approved_at=UTC_TIMESTAMP(),attempted_at=UTC_TIMESTAMP(),provider_http=404,detail='provider_result_unknown' WHERE id=7"]as$q)$p->exec($q);
 $legacy=$p->query('SELECT * FROM westy_email_drafts WHERE id=7')->fetch(PDO::FETCH_ASSOC);
 $hash=str_repeat('a',64);$p->exec("INSERT INTO westy_mail_reconciliations(old_draft_id,actor_id,evidence_sha256,outcome) VALUES(7,1,'$hash','provider_rejected')");$rid=(int)$p->lastInsertId();
 wmok($legacy===$p->query('SELECT * FROM westy_email_drafts WHERE id=7')->fetch(PDO::FETCH_ASSOC),'legacy HTTP404 draft bytes are unchanged by reconciliation');
 $p->prepare("INSERT INTO westy_mail_conversations(tenant_id,ticket_id,client_id,customer_id,contact_id,recipient,sender,mail_identity_sha256,subject,body_text,message_sha256,context_sha256,authority_sha256,reply_token_sha256,request_key,message_key,replacement_of_draft_id,reconciliation_id,created_by) VALUES(1,1,1,'11111111-1111-4111-8111-111111111111',1,'person@example.test','westy@8westit.com',?,?,?,?,?,?,?,?,?,7,?,1)")->execute([$hash,'[#1] test [wm:'.str_repeat('b',32).']','body',$hash,$hash,$hash,$hash,str_repeat('c',32),str_repeat('d',32),$rid]);$cid=(int)$p->lastInsertId();
 $p->exec("UPDATE westy_mail_conversations SET state='approved',approved_by=1,approved_at=UTC_TIMESTAMP(),approval_session_version='1.1' WHERE id=$cid");wmok((string)$p->query("SELECT state FROM westy_mail_conversations WHERE id=$cid")->fetchColumn()==='approved','approved transition with signed session version');
 wmdenied(fn()=>$p->exec("UPDATE westy_mail_conversations SET subject='mutated' WHERE id=$cid"),'immutable subject');
 $p->exec("INSERT INTO westy_mail_delegations(conversation_id,template_id,template_version,template_sha256,expires_at,approved_by) VALUES($cid,'acknowledgment',1,'$hash',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),1)");
 $p->exec("UPDATE westy_mail_conversations SET state='claimed',attempted_at=UTC_TIMESTAMP() WHERE id=$cid");
 $p->exec("UPDATE westy_mail_conversations SET state='submitted',provider_http=202,outcome_code='submitted' WHERE id=$cid");
 $p->prepare("INSERT INTO westy_mail_inbound_receipts(conversation_id,provider_message_sha256,sender_sha256,reply_to_sha256,body_sha256,auth_verified,disposition) VALUES(?,?,?,?,?,1,'accepted')")->execute([$cid,hash('sha256','graph-1'),hash('sha256','person@example.test'),hash('sha256','person@example.test'),hash('sha256','reply body')]);$receipt=(int)$p->lastInsertId();
 $p->prepare("INSERT INTO messages(ticket_id,author_name,kind,body,westy_mail_receipt_id) VALUES(1,'person@example.test','client','reply body',?)")->execute([$receipt]);
 wmok((int)$p->query('SELECT COUNT(*) FROM westy_mail_delegation_revocations')->fetchColumn()===0,'verified inbound receipt does not revoke human-owned mail grant');
 wmdenied(fn()=>$p->prepare("INSERT INTO messages(ticket_id,author_name,kind,body,westy_mail_receipt_id) VALUES(1,'person@example.test','client','changed body',?)")->execute([$receipt]),'receipt rejects mismatched body');
 $p->exec("INSERT INTO messages(ticket_id,author_name,kind,body) VALUES(1,'Human','note','takeover')");
 wmok((int)$p->query('SELECT COUNT(*) FROM westy_mail_delegation_revocations')->fetchColumn()===1,'human message irrevocably revokes delegation');
 wmok((string)$p->query("SELECT status FROM tickets WHERE id=1")->fetchColumn()==='open' && (int)$p->query('SELECT COUNT(*) FROM time_entries')->fetchColumn()===0,'mail path did not change case status or time records');

 foreach(["INSERT INTO tickets(id,tenant_id,client_id,contact_id,channel,external_key) VALUES(2,1,1,1,'alert','alert:2')","INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES(1,1,'11111111-1111-4111-8111-111111111111',2,'22222222-2222-4222-8222-222222222222','alert:2','human_owned',1,'human case',UTC_TIMESTAMP(),UTC_TIMESTAMP())"]as$q)$p->exec($q);
 $p->prepare("INSERT INTO westy_mail_conversations(tenant_id,ticket_id,client_id,customer_id,contact_id,recipient,sender,mail_identity_sha256,subject,body_text,message_sha256,context_sha256,authority_sha256,reply_token_sha256,request_key,message_key,created_by) VALUES(1,2,1,'11111111-1111-4111-8111-111111111111',1,'person@example.test','westy@8westit.com',?,?,?,?,?,?,?,?,?,1)")->execute([$hash,'[#2] test [wm:'.str_repeat('e',32).']','body',$hash,$hash,$hash,$hash,str_repeat('e',32),str_repeat('f',32)]);$draft=(int)$p->lastInsertId();
 $p->exec("UPDATE contacts SET email='changed@example.test' WHERE id=1");
 wmok((string)$p->query("SELECT state FROM westy_mail_conversations WHERE id=$draft")->fetchColumn()==='revoked' && (int)$p->query("SELECT COUNT(*) FROM westy_mail_conversation_revocations WHERE conversation_id=$draft")->fetchColumn()===1,'contact change revokes unsent conversation permanently');
 $p->exec("UPDATE contacts SET email='person@example.test' WHERE id=1");
 wmok((string)$p->query("SELECT state FROM westy_mail_conversations WHERE id=$draft")->fetchColumn()==='revoked','contact change-and-revert cannot restore conversation');

 require_once __DIR__.'/../lib/westy_mail.php';
 foreach(["INSERT INTO tickets(id,tenant_id,client_id,contact_id,channel,external_key) VALUES(3,1,1,1,'alert','alert:3')","INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES(1,1,'11111111-1111-4111-8111-111111111111',3,'33333333-3333-4333-8333-333333333333','alert:3','human_owned',1,'human case',UTC_TIMESTAMP(),UTC_TIMESTAMP())"]as$q)$p->exec($q);
 $p->beginTransaction();$context=westy_mail_admin($p,1,3,1);$p->commit();$token=str_repeat('1',32);$subject='[#3] reviewed message [wm:'.$token.']';$body='A reviewed manual message.';$conversation=westy_mail_create_replacement($p,1,3,1,0,$subject,$body,$token,str_repeat('2',32),$context['fingerprint']);
 $mail=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$conversation]);westy_mail_approve($p,1,3,1,$conversation,westy_mail_review_hash($mail),true);
 $sends=0;$transport=function(array $graph,array $mail)use(&$sends):array{$sends++;return ['outcome'=>'submitted','provider_http'=>202,'provider_request_id'=>'test-request','outcome_code'=>'accepted'];};
 wmok(westy_mail_send($p,1,3,1,$conversation,$transport)==='submitted' && $sends===1,'public create approve and one durable send');
 wmok(westy_mail_send($p,1,3,1,$conversation,$transport)==='submitted' && $sends===1,'second send invocation never retransmits');
 $in=['provider_message_id'=>'graph-public-1','internet_message_id'=>'internet-public-1','from'=>'person@example.test','source_reply_to'=>'person@example.test','destination'=>'westy@8westit.com','subject'=>$subject,'body'=>'Please review this.','auto'=>false,'auth_verified'=>true];$accepted=westy_mail_accept_inbound($p,1,$in);
 wmok(($accepted['state']??'')==='accepted' && westy_mail_auto_reply($p,$conversation,(int)$accepted['receipt_id'],$transport)==='submitted' && $sends===2,'public verified inbound creates exactly one automatic acknowledgment');
 wmok(westy_mail_auto_reply($p,$conversation,(int)$accepted['receipt_id'],$transport)==='submitted' && $sends===2,'automatic acknowledgment is one attempt only');
 wmok((westy_mail_accept_inbound($p,1,$in)['state']??'')==='duplicate','accepted inbound provider id is deduped');
 $bad=$in;$bad['provider_message_id']='graph-held-1';$bad['internet_message_id']='internet-held-1';$bad['source_reply_to']='other@example.test';wmok((westy_mail_accept_inbound($p,1,$bad)['state']??'')==='held','mismatched Reply-To is held');
 $bad['source_reply_to']='person@example.test';wmok((westy_mail_accept_inbound($p,1,$bad)['state']??'')==='held','a held provider id remains terminal');
 foreach(["INSERT INTO tickets(id,tenant_id,client_id,contact_id,channel,external_key) VALUES(4,1,1,1,'alert','alert:4')","INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES(1,1,'11111111-1111-4111-8111-111111111111',4,'44444444-4444-4444-8444-444444444444','alert:4','human_owned',1,'human case',UTC_TIMESTAMP(),UTC_TIMESTAMP())","INSERT INTO westy_email_drafts(id,tenant_id,ticket_id,client_id,contact_id,recipient,subject,body_text,context_sha256,request_key,created_by) VALUES(8,1,4,1,1,'person@example.test','legacy 404','legacy replacement source',REPEAT('c',64),REPEAT('d',32),1)","UPDATE westy_email_drafts SET state='uncertain',approved_by=1,approved_at=UTC_TIMESTAMP(),attempted_at=UTC_TIMESTAMP(),provider_http=404,detail='provider_result_unknown' WHERE id=8"]as$q)$p->exec($q);
 $legacy4=$p->query('SELECT * FROM westy_email_drafts WHERE id=8')->fetch(PDO::FETCH_ASSOC);$reconciliation=westy_mail_reconcile_rejected($p,1,4,1,8);$p->beginTransaction();$context=westy_mail_admin($p,1,4,1);$p->commit();$token4=str_repeat('4',32);$conversation4=westy_mail_create_replacement($p,1,4,1,$reconciliation,'[#4] reviewed replacement [wm:'.$token4.']','A separately approved replacement.',$token4,str_repeat('5',32),$context['fingerprint']);$mail4=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$conversation4]);westy_mail_approve($p,1,4,1,$conversation4,westy_mail_review_hash($mail4));
 wmok(westy_mail_send($p,1,4,1,$conversation4,$transport)==='submitted' && $sends===3 && $legacy4===$p->query('SELECT * FROM westy_email_drafts WHERE id=8')->fetch(PDO::FETCH_ASSOC),'legacy HTTP404 has one separately approved replacement and no old resend');
 foreach(["INSERT INTO tickets(id,tenant_id,client_id,contact_id,channel,external_key) VALUES(5,1,1,1,'alert','alert:5')","INSERT INTO westy_workflows(tenant_id,client_id,customer_id,ticket_id,workflow_key,alert_key,state,version,summary,started_at,updated_at) VALUES(1,1,'11111111-1111-4111-8111-111111111111',5,'55555555-5555-4555-8555-555555555555','alert:5','human_owned',1,'human case',UTC_TIMESTAMP(),UTC_TIMESTAMP())"]as$q)$p->exec($q);
 $p->beginTransaction();$context=westy_mail_admin($p,1,5,1);$p->commit();$token5=str_repeat('6',32);$conversation5=westy_mail_create_replacement($p,1,5,1,0,'[#5] crash recovery [wm:'.$token5.']','A claimed send must not retry.',$token5,str_repeat('7',32),$context['fingerprint']);$mail5=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$conversation5]);westy_mail_approve($p,1,5,1,$conversation5,westy_mail_review_hash($mail5));
 $p->exec("UPDATE westy_mail_conversations SET state='claimed',attempted_at=UTC_TIMESTAMP() WHERE id=$conversation5");$p2=wmdb();wmok(westy_mail_send($p2,1,5,1,$conversation5,$transport)==='claimed' && $sends===3,'second MySQL connection after durable claim cannot retransmit');$p2=null;

 // Public API races and process death. Children open independent MySQL connections.
 $workers=[];$workerRoot=sys_get_temp_dir().'/'.$name;mkdir($workerRoot,0700);
 foreach(['normal','ack'] as $kind){
  $case=wmcase($p,$kind==='normal'?10:11);$receipt=$kind==='ack'?wmreceived($p,$case,$transport):0;
  $base=$workerRoot.'/'.$kind.'-race';$first=wmworker($case,$receipt,$base,true);wmwait($base.'.entered');
  $secondBase=$base.'-second';$second=wmworker($case,$receipt,$secondBase,false);wmwait($secondBase.'.started');
  usleep(100000);file_put_contents($base.'.release','1');
  wmok(wmfinish($first)==='submitted' && wmfinish($second)==='submitted' && count(file($base.'.sent'))===1 && !is_file($secondBase.'.sent'),"$kind competing workers make exactly one transport call");
 }
 foreach(['normal','ack'] as $kind){
  $case=wmcase($p,$kind==='normal'?12:13);$receipt=$kind==='ack'?wmreceived($p,$case,$transport):0;$base=$workerRoot.'/'.$kind.'-killed';
  $worker=wmworker($case,$receipt,$base,true);wmwait($base.'.entered');wmfinish($worker,true);
  $before=$sends;$p2=wmdb();$result=$receipt>0?westy_mail_auto_reply($p2,$case['conversation'],$receipt,$transport):westy_mail_send($p2,1,$case['id'],$case['id'],$case['conversation'],$transport);$p2=null;
  wmok($result==='claimed' && $before===$sends && count(file($base.'.sent'))===1,"$kind killed process after durable claim never retransmits");
  $sent=json_decode(trim(file_get_contents($base.'.sent')),true,32,JSON_THROW_ON_ERROR);
  if($kind==='ack')wmok($sent['body_text']===westy_mail_template()['body'],'ack transport uses the exact frozen approved template');
 }
 require_once __DIR__.'/../lib/westy_mail_poll.php';
 $claimed=$p->query("SELECT * FROM westy_mail_conversations WHERE ticket_id=12")->fetch(PDO::FETCH_ASSOC);
 $graphMessage=['id'=>'synthetic-sent-evidence','toRecipients'=>[['emailAddress'=>['address'=>$claimed['recipient']]]],'subject'=>$claimed['subject'],'internetMessageHeaders'=>[['name'=>'x-westy-message-key','value'=>$claimed['message_key']]]];
 $get=fn(array $g,string $path)=>['outcome'=>'ok','data'=>['value'=>[$graphMessage]]];
 $evidence=westy_mail_poll_reconcile($p,westy_mail_graph_snapshot()['graph'],$get,1);
 westy_mail_poll_reconcile($p,westy_mail_graph_snapshot()['graph'],$get,1);
 wmok(count($evidence)===1 && (int)$p->query('SELECT COUNT(*) FROM westy_mail_sent_reconciliations')->fetchColumn()===1,'028 Sent Items reconciliation stores one deduped evidence row');
 wmok($claimed===$p->query('SELECT * FROM westy_mail_conversations WHERE ticket_id=12')->fetch(PDO::FETCH_ASSOC),'Sent Items evidence does not rewind or rewrite the unknown send');
 wmdenied(fn()=>$p->exec("UPDATE westy_mail_sent_reconciliations SET subject_sha256=REPEAT('a',64)"),'Sent Items evidence cannot be updated');
 wmdenied(fn()=>$p->exec('DELETE FROM westy_mail_sent_reconciliations'),'Sent Items evidence cannot be deleted');
 westy_mail_poll_attention($p,$case['conversation'],$receipt,'automatic_reply_unavailable');westy_mail_poll_attention($p,$case['conversation'],$receipt,'automatic_reply_unavailable');
 wmok((int)$p->query('SELECT COUNT(*) FROM westy_mail_attention_events')->fetchColumn()===1,'028 attention events are deduped');
 wmdenied(fn()=>$p->exec("UPDATE westy_mail_attention_events SET reason='other_reason'"),'attention evidence cannot be updated');
 wmdenied(fn()=>$p->exec('DELETE FROM westy_mail_attention_events'),'attention evidence cannot be deleted');

 // Every forged message has its own provider AND internet id. A rejected id is permanent.
 $case=wmcase($p,20);westy_mail_send($p,1,20,20,$case['conversation'],$transport);
 foreach(['case','token','from','replyto','auth','automatic','destination','ambiguous'] as $badKind){
  $input=wminput($case);
  switch($badKind){
   case 'case':$input['subject']=str_replace('[#20]','[#21]',$input['subject']);break;
   case 'token':$input['subject']=str_replace($case['token'],str_repeat('0',32),$input['subject']);break;
   case 'from':$input['from']=$input['source_reply_to']='attacker@example.test';break;
   case 'replyto':$input['source_reply_to']='attacker@example.test';break;
   case 'auth':$input['auth_verified']=false;break;
   case 'automatic':$input['auto']=true;break;
   case 'destination':$input['destination']='someone@example.test';break;
   case 'ambiguous':$input['subject'].=' [#21]';break;
  }
  wmok((westy_mail_accept_inbound($p,1,$input)['state']??'')==='held',"$badKind forged inbound is held");
  $valid=wminput($case);$valid['provider_message_id']=$input['provider_message_id'];$valid['internet_message_id']=$input['internet_message_id'];
  wmok((westy_mail_accept_inbound($p,1,$valid)['state']??'')==='held',"$badKind held identity cannot later become accepted");
 }
 wmok((int)$p->query('SELECT COUNT(*) FROM messages WHERE ticket_id=20')->fetchColumn()===0,'forged inbound never adds a customer message');
 $caseOther=wmcase($p,21);$otherReceipt=wmreceived($p,$caseOther,$transport);
 wmdenied(fn()=>westy_mail_auto_reply($p,$case['conversation'],$otherReceipt,$transport),'ack rejects a receipt belonging to another conversation');

 // Independent gates deny create, approval, dispatch, inbound, and automatic dispatch.
 $case=wmcase($p,30);$ackCase=wmcase($p,31);$ackReceipt=wmreceived($p,$ackCase,$transport);$draftCase=wmcase($p,32,true,false);$before=$sends;
 foreach(['feature','graph','tenant','customer'] as $gate){
  $saved=$CONFIG;
  if($gate==='feature')$CONFIG['westy_mail']['enabled']=false;
  if($gate==='graph')$CONFIG['westy_email']['graph']['enabled']=false;
  if($gate==='tenant')$CONFIG['westy_mail']['tenant_ids']=[];
  if($gate==='customer')$CONFIG['westy_mail']['customer_ids']=[];
  wmdenied(fn()=>westy_mail_send($p,1,30,30,$case['conversation'],$transport),"$gate gate blocks unsent mail");
  wmdenied(fn()=>westy_mail_auto_reply($p,$ackCase['conversation'],$ackReceipt,$transport),"$gate gate blocks automatic acknowledgment");
  $dm=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$draftCase['conversation']]);
  wmdenied(fn()=>westy_mail_approve($p,1,32,32,$draftCase['conversation'],westy_mail_review_hash($dm),true),"$gate gate blocks exact approval");
  $CONFIG=$saved;
 }
 wmok($sends===$before,'disabled gates cause no transport calls');
 $CONFIG['westy_mail']['enabled']=false;wmdenied(fn()=>westy_mail_accept_inbound($p,1,wminput($ackCase)),'disabled intake refuses receipt writes');
 westy_mail_revoke($p,1,30,30,$case['conversation']);$CONFIG['westy_mail']['enabled']=true;
 wmok(westy_mail_send($p,1,30,30,$case['conversation'],$transport)==='revoked','staff can revoke while the feature is disabled');

 // Exact review and signed version are mandatory; delegate only on human-owned cases.
 $case=wmcase($p,40,true,false);$m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$case['conversation']]);
 $currentAck=westy_mail_template();$staleAckReview=westy_mail_hash([$m['id'],$m['recipient'],$m['recipient_alias_json'],$m['sender'],$m['message_sha256'],$m['context_sha256'],$m['authority_sha256'],$m['reply_token_sha256'],[$currentAck['id'],$currentAck['version'],hash('sha256','Earlier displayed acknowledgment wording.')]]);
 wmdenied(fn()=>westy_mail_approve($p,1,40,40,$case['conversation'],$staleAckReview,true),'changed acknowledgment wording since page render cannot be approved');
 wmok((int)$p->query('SELECT COUNT(*) FROM westy_mail_delegations WHERE conversation_id='.(int)$case['conversation'])->fetchColumn()===0 && $p->query('SELECT state FROM westy_mail_conversations WHERE id='.(int)$case['conversation'])->fetchColumn()==='draft','stale acknowledgment review leaves draft unapproved without delegation');
 wmdenied(fn()=>westy_mail_approve($p,1,40,40,$case['conversation'],str_repeat('f',64),true),'different content review hash cannot approve');
 $_SESSION['suite_session_version']='1';wmdenied(fn()=>westy_mail_approve($p,1,40,40,$case['conversation'],westy_mail_review_hash($m),true),'invalid session version cannot approve');$_SESSION['suite_session_version']='1.1';
 $case=wmcase($p,41,true,false,'working');$m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$case['conversation']]);
 wmdenied(fn()=>westy_mail_approve($p,1,41,41,$case['conversation'],westy_mail_review_hash($m),true),'automatic acknowledgment cannot be delegated for machine-owned case');
 $case=wmcase($p,42,false,false);$m=westy_mail_row($p,'SELECT * FROM westy_mail_conversations WHERE id=?',[$case['conversation']]);
 $p->exec('SET timestamp='.(time()-1000));try{westy_mail_approve($p,1,42,42,$case['conversation'],westy_mail_review_hash($m));}finally{$p->exec('SET timestamp=0');}
 wmdenied(fn()=>westy_mail_send($p,1,42,42,$case['conversation'],$transport),'expired exact approval cannot send');
 foreach(['expired','template'] as $invalidGrant){
  $case=wmcase($p,$invalidGrant==='expired'?43:44,false);$template=westy_mail_template();
  $p->prepare('INSERT INTO westy_mail_delegations(conversation_id,template_id,template_version,template_sha256,expires_at,created_at,approved_by) VALUES(?,?,?,?,?,?,?)')->execute([$case['conversation'],$template['id'],$template['version'],$invalidGrant==='template'?str_repeat('e',64):$template['sha256'],gmdate('Y-m-d H:i:s',time()+($invalidGrant==='expired'?-60:3600)),gmdate('Y-m-d H:i:s',time()-120),$case['id']]);
  $receipt=wmreceived($p,$case,$transport);$before=$sends;
  wmdenied(fn()=>westy_mail_auto_reply($p,$case['conversation'],$receipt,$transport),"$invalidGrant delegation cannot automatically send");wmok($sends===$before,"$invalidGrant delegation makes zero transport calls");
 }

 // Feed failures use the same public identity policy, with synthetic verified-feed results.
 $wm_identity_snapshot=['generated_at'=>time(),'mode'=>'versioned','revoked'=>[],'authorizations'=>['t1u50'=>'1.1','t1u51'=>'1.1']];
 $case=wmcase($p,50,true,true,'human_owned','t1u50');$ackCase=wmcase($p,51,true,true,'human_owned','t1u51');$receipt=wmreceived($p,$ackCase,$transport);$goodSnapshot=$wm_identity_snapshot;
 foreach(['unavailable','version_changed','revoked'] as $feed){
  $wm_identity_snapshot=$goodSnapshot;
  if($feed==='unavailable')$wm_identity_snapshot=null;
  if($feed==='version_changed')$wm_identity_snapshot['authorizations']=['t1u50'=>'1.2','t1u51'=>'1.2'];
  if($feed==='revoked')$wm_identity_snapshot['revoked']=['t1u50'=>time(),'t1u51'=>time()];
  $before=$sends;wmdenied(fn()=>westy_mail_send($p,1,50,50,$case['conversation'],$transport),"$feed feed blocks normal dispatch");
  wmdenied(fn()=>westy_mail_auto_reply($p,$ackCase['conversation'],$receipt,$transport),"$feed feed blocks automatic dispatch");wmok($sends===$before,"$feed feed never invokes transport");
 }
 $wm_identity_snapshot=$goodSnapshot;unset($_SESSION['suite_session_version']);
 wmok(westy_mail_auto_reply($p,$ackCase['conversation'],$receipt,$transport)==='submitted','cron acknowledgment uses frozen session version without browser session');$_SESSION['suite_session_version']='1.1';

 // Revocation survives a change-and-revert for every mutable authority source.
 foreach(['permission','contact','customer','human'] as $change){
  $id=['permission'=>60,'contact'=>62,'customer'=>64,'human'=>66][$change];$case=wmcase($p,$id);$ackCase=wmcase($p,$id+1);$receipt=wmreceived($p,$ackCase,$transport);
  foreach([$case,$ackCase] as $affected){$n=$affected['id'];
   if($change==='permission'){$p->exec("UPDATE users SET role='tech' WHERE id=$n");$p->exec("UPDATE users SET role='owner' WHERE id=$n");}
   if($change==='contact'){$p->exec("UPDATE contacts SET email='changed@example.test' WHERE id=$n");$p->exec("UPDATE contacts SET email='person$n@example.test' WHERE id=$n");}
   if($change==='customer'){$p->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE client_id=$n");$p->exec("UPDATE suite_customer_sync_bindings SET status='active' WHERE client_id=$n");}
   if($change==='human'){$p->exec("INSERT INTO messages(ticket_id,author_name,kind,body) VALUES($n,'Human','note','Taking over this case')");}
  }
  $before=$sends;wmok(westy_mail_send($p,1,$id,$id,$case['conversation'],$transport)==='revoked',"$change permanently revokes unsent authority");
  wmdenied(fn()=>westy_mail_auto_reply($p,$ackCase['conversation'],$receipt,$transport),"$change permanently revokes acknowledgment authority");wmok($sends===$before,"$change revocation prevents transport");
 }
 // Merge moves existing evidence into a survivor without touching its ticket row.
 // The survivor is already human-owned, so the old workflow trigger is a no-op.
 foreach(['unsent','ack'] as $mergeKind){
  $case=wmcase($p,$mergeKind==='unsent'?70:71);$receipt=$mergeKind==='ack'?wmreceived($p,$case,$transport):0;
  $source=$case['id']+10;$client=$case['id'];
  $p->exec("INSERT INTO tickets(id,tenant_id,client_id,contact_id,channel,external_key) VALUES($source,1,$client,$client,'alert','alert:$source')");
  $p->exec("INSERT INTO messages(ticket_id,author_name,kind,body) VALUES($source,'Human','note','New staff evidence merged into survivor')");$messageId=(int)$p->lastInsertId();
  $p->exec("UPDATE messages SET ticket_id=$client WHERE id=$messageId");
  $before=$sends;
  if($mergeKind==='unsent')wmok(westy_mail_send($p,1,$client,$client,$case['conversation'],$transport)==='revoked','message moved into case permanently revokes unsent mail');
  else wmdenied(fn()=>westy_mail_auto_reply($p,$case['conversation'],$receipt,$transport),'message moved into human-owned survivor revokes automatic acknowledgment');
  wmok($sends===$before,"$mergeKind moved-evidence revocation prevents transport");
 }
 // Drive the real cron loop against real core receipts/claims and a 25-message
 // provider fixture keyed by the requested continuation path, never call order.
 $driverCase=wmcase($p,90);westy_mail_send($p,1,90,90,$driverCase['conversation'],$transport);
 $killedMail=$p->query('SELECT * FROM westy_mail_conversations WHERE ticket_id=13')->fetch(PDO::FETCH_ASSOC);
 $killedCase=['id'=>13,'conversation'=>(int)$killedMail['id'],'subject'=>$killedMail['subject']];
 $poll=['tenant_id'=>1,'activated_at'=>gmdate('Y-m-d H:i:s',time()-3600),'mailbox'=>'westy@8westit.com','auth_results_authority'=>'mx.microsoft.com','internal_senders'=>['person90@example.test','person13@example.test'],'auto_ack_enabled'=>true];
 $snapshot=westy_mail_graph_snapshot();$cursor=westy_mail_poll_cursor($p,$poll,$snapshot);$firstPath=westy_mail_poll_window_path($cursor['window_start_at'],$cursor['window_end_at']);
 $paths=[$firstPath];for($i=2;$i<=5;$i++)$paths[]='mailFolders/inbox/messages?$top=5&$skiptoken=fixture-page-'.$i;
 $pages=[];
 for($pageIndex=0;$pageIndex<5;$pageIndex++){
  $items=[];for($offset=0;$offset<5;$offset++){
   $index=$pageIndex*5+$offset;$sourceCase=$index===0?$killedCase:$driverCase;$input=wminput($sourceCase);
   $items[]=['id'=>'driver-graph-'.$index,'internetMessageId'=>'driver-internet-'.$index,'subject'=>$input['subject'],'from'=>['emailAddress'=>['address'=>$input['from']]],'sender'=>['emailAddress'=>['address'=>$input['from']]],'replyTo'=>[],'toRecipients'=>[['emailAddress'=>['address'=>'westy@8westit.com']]],'body'=>['contentType'=>'text','content'=>'Durable driver reply '.$index],'internetMessageHeaders'=>[['name'=>'X-MS-Exchange-Organization-AuthAs','value'=>'Internal'],['name'=>'Authentication-Results','value'=>'dkim=none (message not signed) header.d=none;dmarc=none action=none header.from=example.test;'],['name'=>'X-MS-Exchange-CrossTenant-AuthAs','value'=>'Internal'],['name'=>'X-MS-Exchange-CrossTenant-Id','value'=>$snapshot['graph']['tenant_id']],['name'=>'X-MS-Exchange-CrossTenant-FromEntityHeader','value'=>'Hosted']]];
  }
  $pages[$paths[$pageIndex]]=['outcome'=>'ok','data'=>['value'=>$items],'next_path'=>$paths[$pageIndex+1]??null];
 }
 $requested=[];$failPath=null;
 $get=function(array $graph,string $path)use(&$requested,&$failPath,$pages):array{$requested[]=$path;if($path===$failPath)return ['outcome'=>'uncertain'];if(!isset($pages[$path]))throw new RuntimeException('unexpected continuation path');return $pages[$path];};
 $ack=function(PDO $db,int $conversation,int $receipt)use($transport):string{return westy_mail_auto_reply($db,$conversation,$receipt,$transport);};
 $receiptBaseline=(int)$p->query('SELECT COUNT(*) FROM westy_mail_inbound_receipts')->fetchColumn();$before=$sends;
 $p2=wmdb();wmok(westy_mail_poll_lock($p,1,$snapshot['identity_sha256']) && !westy_mail_poll_lock($p2,1,$snapshot['identity_sha256']),'second MySQL connection cannot acquire active mailbox ownership');
 $crashAccept=function(PDO $db,int $tenant,array $input):array{westy_mail_accept_inbound($db,$tenant,$input);throw new RuntimeException('injected worker interruption after receipt commit');};
 wmdenied(fn()=>westy_mail_poll_run($p,$poll,$snapshot,$get,$crashAccept,'westy_mail_hold_inbound',$ack),'actual driver interruption after receipt commit exits before cursor advance');
 wmok((int)$p->query('SELECT COUNT(*) FROM westy_mail_inbound_receipts')->fetchColumn()===$receiptBaseline+1 && $sends===$before && westy_mail_poll_cursor($p,$poll,$snapshot)['next_path']===null,'interrupted page retains one durable receipt and unchanged cursor without acknowledgment');
 westy_mail_poll_unlock($p,1,$snapshot['identity_sha256']);$p2=null;$p2=wmdb();
 wmok(westy_mail_poll_lock($p2,1,$snapshot['identity_sha256']),'fresh MySQL worker acquires released mailbox ownership');
 $result=westy_mail_poll_run($p2,$poll,$snapshot,$get,'westy_mail_accept_inbound','westy_mail_hold_inbound',$ack);
 wmok($result['seen']===10 && westy_mail_poll_cursor($p2,$poll,$snapshot)['next_path']===$paths[2] && $sends===$before+1,'fresh worker replays receipt safely and persists page-three continuation after ten messages');
 wmok((int)$p2->query("SELECT COUNT(*) FROM westy_mail_attention_events WHERE conversation_id=".(int)$killedCase['conversation']." AND reason='auto_ack_result_unknown'")->fetchColumn()===1 && $p2->query('SELECT a.state FROM westy_mail_auto_attempts a JOIN westy_mail_delegations d ON d.id=a.delegation_id WHERE d.conversation_id='.(int)$killedCase['conversation'])->fetchColumn()==='claimed','replayed killed acknowledgment stays claimed and creates one staff attention event');
 $failPath=$paths[3];wmdenied(fn()=>westy_mail_poll_run($p2,$poll,$snapshot,$get,'westy_mail_accept_inbound','westy_mail_hold_inbound',$ack),'actual driver stops on second-page Graph outage');
 wmok(westy_mail_poll_cursor($p2,$poll,$snapshot)['next_path']===$paths[3] && (int)$p2->query('SELECT COUNT(*) FROM westy_mail_inbound_receipts')->fetchColumn()===$receiptBaseline+15,'completed page commits while failed following page retains its exact continuation');
 westy_mail_poll_unlock($p2,1,$snapshot['identity_sha256']);$p2=null;$p2=wmdb();westy_mail_poll_lock($p2,1,$snapshot['identity_sha256']);$failPath=null;
 $result=westy_mail_poll_run($p2,$poll,$snapshot,$get,'westy_mail_accept_inbound','westy_mail_hold_inbound',$ack);$finished=westy_mail_poll_cursor($p2,$poll,$snapshot);
 wmok($result['seen']===10 && (int)$p2->query('SELECT COUNT(*) FROM westy_mail_inbound_receipts')->fetchColumn()===$receiptBaseline+25 && $finished['next_path']===null && $finished['window_start_at']===$cursor['window_end_at'],'fresh worker resumes failed path and finishes all twenty-five durable receipts');
 wmok($requested===[$paths[0],$paths[0],$paths[1],$paths[2],$paths[3],$paths[3],$paths[4]],'driver requests actual persisted continuation paths across both interruptions');
 wmok($sends===$before+1 && (int)$p2->query('SELECT COUNT(*) FROM messages WHERE ticket_id=90')->fetchColumn()===24 && (int)$p2->query('SELECT COUNT(*) FROM westy_mail_auto_attempts a JOIN westy_mail_delegations d ON d.id=a.delegation_id WHERE d.conversation_id='.(int)$driverCase['conversation'])->fetchColumn()===1,'driver replay and backlog create one acknowledgment and no duplicate customer messages');
 westy_mail_poll_unlock($p2,1,$snapshot['identity_sha256']);$p2=null;
 wmok((int)$p->query("SELECT COUNT(*) FROM tickets WHERE status<>'open'")->fetchColumn()===0 && (int)$p->query('SELECT COUNT(*) FROM time_entries')->fetchColumn()===0 && (int)$p->query('SELECT COUNT(*) FROM westy_billing_outbox')->fetchColumn()===0,'all public mail operations leave ticket status, technician time, and billing unchanged');
 echo "PASS $checks Westy mail MySQL checks\n";
}finally{foreach($workers??[] as $worker){if(is_resource($worker)){proc_terminate($worker,9);proc_close($worker);}}if($p->inTransaction())$p->rollBack();@unlink($keyPath??'');@unlink($certPath??'');if(isset($workerRoot)){foreach(glob($workerRoot.'/*')?:[] as $file)@unlink($file);@rmdir($workerRoot);}$server->exec("DROP DATABASE `$name`");}
