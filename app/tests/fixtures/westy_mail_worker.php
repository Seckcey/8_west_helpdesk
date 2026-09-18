<?php
/** Isolated child process: a second real MySQL connection and a controllable transport. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$input=json_decode(file_get_contents($argv[1]??''),true,32,JSON_THROW_ON_ERROR);
extract($input,EXTR_SKIP);
if (!preg_match('/\Asafeharbor_westy_mail_test_[a-f0-9]{10}\z/D',$name) || !in_array($host,['127.0.0.1','localhost','::1'],true)) exit(2);
function cfg(string $key,mixed $default=null):mixed {global $CONFIG;$v=$CONFIG;foreach(explode('.',$key)as$part){if(!is_array($v)||!array_key_exists($part,$v))return $default;$v=$v[$part];}return $v;}
$_SESSION=['suite_session_version'=>'1.1'];
require_once __DIR__.'/../../lib/westy_mail.php';
$p=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$transport=function(array $graph,array $message)use($base,$block):array {
 file_put_contents($base.'.sent',json_encode($message,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
 file_put_contents($base.'.entered','1');
 if($block){$until=microtime(true)+15;while(!is_file($base.'.release')){if(microtime(true)>$until)throw new RuntimeException('transport rendezvous timed out');usleep(20000);}}
 return ['outcome'=>'submitted','provider_http'=>202,'provider_request_id'=>'synthetic-worker','outcome_code'=>'accepted'];
};
file_put_contents($base.'.started','1');
echo $receipt>0 ? westy_mail_auto_reply($p,$c['conversation'],$receipt,$transport) : westy_mail_send($p,1,$c['id'],$c['id'],$c['conversation'],$transport);
