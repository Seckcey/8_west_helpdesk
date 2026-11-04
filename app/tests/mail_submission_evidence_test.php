<?php
declare(strict_types=1);
$submissionConfig = [];
function cfg(string $key, mixed $default = null): mixed { global $submissionConfig; return $submissionConfig[$key] ?? $default; }
require_once __DIR__.'/../lib/mailer.php';
$checks = 0;
function submission_check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
$graph = ['tenant_id'=>'synthetic','client_id'=>'synthetic','client_secret'=>'synthetic','sender'=>'support@example.test'];
foreach ([
    [202,'provider_accepted'], [400,'failed'], [401,'failed'], [403,'failed'],
    [408,'uncertain'], [429,'failed'], [500,'uncertain'], [503,'uncertain'], [0,'uncertain'],
] as [$status,$expected]) {
    $calls = 0;
    $post = static function () use (&$calls,$status): array {
        return ++$calls === 1 ? ['http'=>200,'body'=>'{"access_token":"synthetic"}'] : ['http'=>$status,'body'=>'private provider detail'];
    };
    $result = mailer_graph_submission_evidence(mailer_send_graph_result($graph,'person@example.test','Options','Measured findings',$post));
    submission_check($result['outcome'] === $expected, 'Graph HTTP '.$status.' has truthful submission evidence');
    submission_check(!str_contains(json_encode($result), 'private'), 'Provider body stays outside durable evidence');
}
foreach ([['error'=>'private timeout'],['http'=>503,'body'=>'private'],['http'=>200,'body'=>'invalid']] as $tokenResponse) {
    $calls = 0;
    $post = static function () use (&$calls,$tokenResponse): array { $calls++; return $tokenResponse; };
    $result = mailer_graph_submission_evidence(mailer_send_graph_result($graph,'person@example.test','Options','Measured findings',$post));
    submission_check($calls === 1 && $result['outcome'] === 'failed', 'Token failure never submitted a message and may retry');
}
$calls = 0;
$post = static function () use (&$calls): array {
    if (++$calls === 1) return ['http'=>200,'body'=>'{"access_token":"synthetic"}'];
    throw new RuntimeException('private timeout after provider may have accepted');
};
$result = mailer_graph_submission_evidence(mailer_send_graph_result($graph,'person@example.test','Options','Measured findings',$post));
submission_check($calls === 2 && $result['outcome'] === 'uncertain', 'Lost send reply cannot authorize blind resend');
submission_check(!in_array('delivered',$result,true), 'Submission never claims recipient delivery');
$submissionConfig = ['mail.graph'=>$graph];
$calls = 0;
$result = mail_send_result('person@example.test','Options','Measured findings',null,$post);
submission_check($calls===2 && $result['outcome']==='uncertain' && $result['transport']==='graph', 'Full mail wrapper retains uncertainty after thrown Graph send and does not fall through');

// Real loopback SMTP peers distinguish an explicit rejection from a lost reply.
foreach (['250 accepted'=>'provider_accepted','250 accepted-then-close'=>'provider_accepted','550 rejected'=>'failed',''=>'uncertain'] as $reply=>$expected) {
    $peer = <<<'PHP'
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if (!$server) exit(2);
echo stream_socket_get_name($server,false)."\n"; flush();
$client=stream_socket_accept($server,8); if (!$client) exit(3);
stream_set_timeout($client,5); fwrite($client,"220 fixture\r\n");
while (($line=fgets($client))!==false) {
    $command=trim($line);
    if ($command==='DATA') {
        fwrite($client,"354 ready\r\n");
        while (($line=fgets($client))!==false && trim($line)!=='.') {}
        if ($argv[1]==='') break;
        fwrite($client,$argv[1]."\r\n");
        if ($argv[1]==='250 accepted-then-close') break;
    } elseif ($command==='QUIT') { fwrite($client,"221 goodbye\r\n"); break; }
    else fwrite($client,"250 okay\r\n");
}
fclose($client); fclose($server);
PHP;
    $process = proc_open([PHP_BINARY,'-r',$peer,$reply],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('SMTP fixture did not start');
    try {
        fclose($pipes[0]); stream_set_timeout($pipes[1],5);
        $address=trim((string)fgets($pipes[1]));
        if (!preg_match('/\A127\.0\.0\.1:([0-9]+)\z/',$address,$match)) throw new RuntimeException('SMTP fixture did not bind');
        $submissionConfig = ['mail.smtp'=>['host'=>'127.0.0.1','port'=>(int)$match[1],'secure'=>''],'mail.from'=>'support@example.test'];
        $result=mail_send_result('person@example.test','Options','Measured findings');
        submission_check($result['transport']==='smtp' && $result['outcome']===$expected,'SMTP '.$expected.' is recorded without delivery claim');
        $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        submission_check(proc_close($process)===0 && $error==='', 'SMTP peer completed cleanly');
        $process=null;
    } finally {
        if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if(is_resource($pipe)) fclose($pipe); proc_close($process); }
    }
}
echo "PASS {$checks} mail submission evidence checks.\n";
