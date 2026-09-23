<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/support_mail_poll.php';
function poll_check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); }
$base = 'https://graph.microsoft.com/v1.0/users/support%40example.test';
poll_check(support_mail_continuation($base . "/mailFolders('inbox')/messages/delta?\$deltatoken=opaque", 'support@example.test') === '/mailFolders/inbox/messages/delta?$deltatoken=opaque', 'Graph canonical folder');
foreach (['http://graph.microsoft.com', 'https://evil.example', 'https://graph.microsoft.com:443', 'https://user@graph.microsoft.com'] as $origin) {
    $refused=false; try { support_mail_continuation($origin . '/v1.0/users/support%40example.test/mailFolders/inbox/messages/delta?$deltatoken=x', 'support@example.test'); } catch (RuntimeException) { $refused=true; }
    poll_check($refused, 'foreign continuation admitted');
}
foreach (['other@example.test','support@example.test/messages/other'] as $other) {
    $refused=false; try { support_mail_continuation('https://graph.microsoft.com/v1.0/users/' . rawurlencode($other) . '/mailFolders/inbox/messages/delta?$deltatoken=x', 'support@example.test'); } catch (RuntimeException) { $refused=true; }
    poll_check($refused, 'wrong mailbox admitted');
}
poll_check(support_mail_key('a@example.test',['internetMessageId'=>'same']) !== support_mail_key('b@example.test',['internetMessageId'=>'same']), 'mailbox dedupe collision');
echo "support continuation and message identity checks passed\n";

if (!getenv('SUPPORT_MAIL_TEST_DSN')) exit;
$pdo = new PDO(getenv('SUPPORT_MAIL_TEST_DSN'), 'root', getenv('SUPPORT_MAIL_TEST_PASS') ?: 'suite-test-only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
poll_check($pdo->query('SELECT DATABASE()')->fetchColumn() === 'support_mail_test', 'disposable database required');
$pdo->exec('CREATE TEMPORARY TABLE tenants(id INT PRIMARY KEY, created_at DATETIME)');
$pdo->exec("INSERT INTO tenants VALUES(1,UTC_TIMESTAMP()),(42,UTC_TIMESTAMP())");
$pdo->exec('CREATE TEMPORARY TABLE processed_mail(internet_message_id VARCHAR(255) PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE received(tenant_id INT NOT NULL, message_id VARCHAR(255) NOT NULL) ENGINE=InnoDB');
$config=['enabled'=>true,'mailbox'=>'support@example.test','tenant_ids'=>[42],'client_ids_by_tenant'=>[42=>[7]]];
$r=static fn(string $email): array => ['emailAddress'=>['address'=>$email]];
$messages=[['id'=>'one','toRecipients'=>[$r('support+w365-42@example.test')]],
    ['id'=>'two','toRecipients'=>[$r('support+w365-1@example.test')]],
    ['id'=>'three','bccRecipients'=>[$r('support+w365-42@example.test')]],
    ['id'=>'four','toRecipients'=>[$r('support+w365-42@example.test')],'ccRecipients'=>[$r('support+w365-1@example.test')]]];
$get=static fn(string $path): array => ['value'=>$messages,'@odata.deltaLink'=>$base . '/mailFolders/inbox/messages/delta?$deltatoken=done'];
$saved=[];$held=[];
$save=static function(array $s)use(&$saved):void{$saved[]=$s;};
$hold=static function(string $key,string $reason)use(&$held):void{$held[$key]=$reason;};
$intake=static function(array $m,int $tenant,?array $clients)use($pdo):string{
    poll_check($tenant===42 && $clients===[7], 'wrong workspace/client scope');
    $pdo->prepare('INSERT INTO received VALUES(?,?)')->execute([$tenant,$m['id']]); return 'created:test';
};
$result=support_mail_poll($pdo,$config,[],$get,$intake,$save,$hold);
poll_check($result['created']===2 && $result['held']===2 && count($saved)===1, 'routing or cursor result');
$result=support_mail_poll($pdo,$config,$saved[0],$get,$intake,$save,$hold);
poll_check($result['duplicate']===4 && (int)$pdo->query('SELECT COUNT(*) FROM received')->fetchColumn()===2, 'replay duplicated ticket');
$failureGet=static fn(string $path):array => ['value'=>[['id'=>'retry','toRecipients'=>[$r('support+w365-42@example.test')]]], '@odata.deltaLink'=>$base . '/mailFolders/inbox/messages/delta?$deltatoken=retry'];
$before=count($saved);$refused=false;
try { support_mail_poll($pdo,$config,[],$failureGet,static function()use($pdo):string{$pdo->exec("INSERT INTO received VALUES(42,'must-rollback')");throw new RuntimeException('fixture outage');},$save,$hold); } catch(RuntimeException){$refused=true;}
poll_check($refused && count($saved)===$before && (int)$pdo->query('SELECT COUNT(*) FROM received')->fetchColumn()===2, 'failure committed a partial ticket/cursor');
$result=support_mail_poll($pdo,$config,[],$failureGet,$intake,$save,$hold);
poll_check($result['created']===1, 'failure poisoned dedupe and lost mail');
echo "support MySQL routing, retry, transactional dedupe and BCC checks passed\n";
