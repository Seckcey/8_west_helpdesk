<?php
/** Disposable localhost proof for migration 028 cursor ownership and progression. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1') exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1';$port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'';
if(!in_array($host,['127.0.0.1','localhost','::1'],true)||!ctype_digit($port)||preg_match('/\Asafeharbor_westy_test(?:_[a-z0-9_]+)?\z/D',$base)!==1)exit(2);
if(!function_exists('cfg')) { function cfg(string $key,mixed $default=null):mixed{return $default;} }
require_once __DIR__.'/../lib/westy_mail_poll.php';
$db=$base.'_'.bin2hex(random_bytes(6));$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root',getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$ok=0;$bad=0;function cursor_ok(string $name,bool $value):void{global $ok,$bad;if($value)$ok++;else{$bad++;fwrite(STDERR,"FAIL $name\n");}}
try{$server->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");$server->exec("USE `$db`");$server->exec('CREATE TABLE westy_mail_conversations(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');$server->exec('CREATE TABLE westy_mail_inbound_receipts(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');$sql=file_get_contents(__DIR__.'/../db/migrations/028_westy_mail_poll_cursor.sql');foreach(array_filter(array_map('trim',explode(';',$sql)))as $statement)$server->exec($statement);
 cursor_ok('poll safety controls installed',(int)$server->query('SELECT westy_mail_poll_schema_health()')->fetchColumn()===1);
 $identity=str_repeat('a',64);$poll=['tenant_id'=>9,'activated_at'=>'2026-09-18 00:00:00'];$snapshot=['identity_sha256'=>$identity];cursor_ok('advisory lock acquired',westy_mail_poll_lock($server,9,$identity));$cursor=westy_mail_poll_cursor($server,$poll,$snapshot);cursor_ok('new exact identity starts at configured activation',$cursor['window_start_at']==='2026-09-18 00:00:00'&&$cursor['next_path']===null);
 westy_mail_poll_cursor_commit($server,$cursor,'mailFolders/inbox/messages?$top=5&$skiptoken=opaque');$cursor=westy_mail_poll_cursor($server,$poll,$snapshot);cursor_ok('completed page persists only validated cursor path',$cursor['next_path']==='mailFolders/inbox/messages?$top=5&$skiptoken=opaque');
 westy_mail_poll_cursor_commit($server,$cursor,null);$advanced=westy_mail_poll_cursor($server,$poll,$snapshot);cursor_ok('final page advances fixed window and clears continuation',$advanced['next_path']===null&&$advanced['window_start_at']===$cursor['window_end_at']);
 try{westy_mail_poll_cursor_commit($server,$advanced,'https://evil.example/next');}catch(Throwable){}$retained=westy_mail_poll_cursor($server,$poll,$snapshot);cursor_ok('invalid/failing page commit retains cursor',$retained['window_start_at']===$advanced['window_start_at']&&$retained['next_path']===null);
 $server->exec("INSERT INTO westy_mail_sent_reconciliations(owner_kind,owner_id,message_key,graph_message_sha256,recipient_sha256,subject_sha256) VALUES('conversation',1,'0123456789abcdef0123456789abcdef','".str_repeat('a',64)."','".str_repeat('b',64)."','".str_repeat('c',64)."')");$immutable=false;try{$server->exec('DELETE FROM westy_mail_sent_reconciliations');}catch(Throwable){$immutable=true;}cursor_ok('sent reconciliation evidence is append only',$immutable);
 cursor_ok('different identity has no reset or crossover',westy_mail_poll_cursor($server,$poll,['identity_sha256'=>str_repeat('b',64)])['next_path']===null);westy_mail_poll_unlock($server,9,$identity);
}finally{try{$server->exec("DROP DATABASE IF EXISTS `$db`");}catch(Throwable){}}
echo "\n$ok passed, $bad failed\n";exit($bad?1:0);
