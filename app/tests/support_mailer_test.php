<?php
declare(strict_types=1);
function cfg(string $key,mixed $default=null):mixed{return $default;}
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/support_addresses.php';
$legacy=['tenant_id'=>'test','client_id'=>'test','client_secret'=>'synthetic','sender'=>'legacy@example.test'];
$config=['enabled'=>true,'mailbox'=>'support@example.test'];
$g=support_graph_config($config,$legacy);
if($g['sender']!=='support@example.test'||$legacy['sender']!=='legacy@example.test')throw new RuntimeException('transport isolation');
$calls=[];
$http=static function(string $url,array $headers,string $payload,int $timeout)use(&$calls):array{
    $calls[]=['url'=>$url,'payload'=>$payload];
    return count($calls)===1?['http'=>200,'body'=>'{"access_token":"synthetic","expires_in":3600}']:['http'=>202,'body'=>''];
};
$r=mailer_send_graph_result($g,'contact@example.test','Test','Test',$http,'support+w365-42@example.test');
$sent=json_decode($calls[1]['payload'],true);
if($r['outcome']!=='submitted'||$calls[1]['url']!=='https://graph.microsoft.com/v1.0/users/support%40example.test/sendMail'
    ||$sent['message']['replyTo'][0]['emailAddress']['address']!=='support+w365-42@example.test')throw new RuntimeException('reply route');
$before=count($calls);
$r=mailer_send_graph_result($g,'contact@example.test','Test','Test',$http,"support@example.test\r\nBcc: other@example.test");
if($r['outcome_code']!=='graph_payload_invalid'||count($calls)!==$before)throw new RuntimeException('invalid reply address sent');
if(support_graph_config(['enabled'=>false,'mailbox'=>'support@example.test'],$legacy)!==null)throw new RuntimeException('disabled transport');
echo "support sender isolation, reply routing and injection-denial checks passed\n";
