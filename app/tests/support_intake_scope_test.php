<?php
/** Requires only a disposable application's config; temporary tables leave no fixture records. */
declare(strict_types=1);
if (getenv('SUPPORT_INTAKE_DISPOSABLE') !== '1') exit(2);
require_once __DIR__ . '/../lib/intake.php';
$testHost=getenv('SUPPORT_MAIL_TEST_HOST') ?: '127.0.0.1';
if(!in_array($testHost,['127.0.0.1','suite-onboarding-mysql'],true))exit(2);
$CONFIG['db']=['host'=>$testHost,'name'=>'support_mail_test','user'=>'root',
    'pass'=>getenv('SUPPORT_MAIL_TEST_PASS') ?: 'suite-test-only','charset'=>'utf8mb4'];
$p=db();
if ($p->query('SELECT DATABASE()')->fetchColumn() !== 'support_mail_test') exit(2);
function scope_check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);}
$p->exec('CREATE TEMPORARY TABLE clients(id INT PRIMARY KEY,tenant_id INT,domain VARCHAR(190),sla_tier VARCHAR(30))');
$p->exec('CREATE TEMPORARY TABLE contacts(id INT PRIMARY KEY,client_id INT,email VARCHAR(190))');
$p->exec('CREATE TEMPORARY TABLE tickets(id INT PRIMARY KEY,tenant_id INT,client_id INT,status VARCHAR(30),resurface_at DATETIME NULL,assignee_id INT NULL)');
$p->exec('CREATE TEMPORARY TABLE messages(id INT AUTO_INCREMENT PRIMARY KEY,ticket_id INT,author_name VARCHAR(128),kind VARCHAR(30),body TEXT)');
$p->exec('CREATE TEMPORARY TABLE email_threads(ticket_id INT,conversation_id VARCHAR(190) UNIQUE)');
$p->exec("INSERT INTO clients VALUES(7,42,'north.test','standard'),(8,42,'south.test','standard'),(9,43,'other.test','standard')");
$p->exec("INSERT INTO contacts VALUES(1,7,'a@north.test'),(2,8,'b@south.test'),(3,9,'c@other.test')");
$p->exec("INSERT INTO tickets VALUES(101,42,7,'waiting',NULL,NULL),(102,42,8,'open',NULL,NULL),(103,43,9,'open',NULL,NULL)");
$p->exec("INSERT INTO email_threads VALUES(103,'foreign-conversation')");
scope_check(intake_message('a@north.test','A','Re: [#101]','reply',[],null,42,[7])==='appended:#101','valid client reply');
scope_check(intake_message('a@north.test','A','Re: [#102]','reply',[],null,42,[7])==='held:thread-sender-mismatch','same workspace other client');
scope_check(intake_message('a@north.test','A','Re: [#103]','reply',[],null,42,[7])==='held:thread-outside-scope','other workspace ticket');
scope_check(intake_message('b@south.test','B','New','reply',[],null,42,[7])==='held:client-outside-scope','existing pilot client gate');
scope_check(intake_message('unknown@north.test','Unknown','Re: [#101]','reply',[],null,42,[7])==='held:client-outside-scope','domain alone is not client authority');
scope_check(intake_message('a@north.test','A','Re: [#101]','reply',[],'foreign-conversation',42,[7])==='appended:#101','foreign conversation does not redirect a valid ticket');
scope_check(intake_message('a@north.test','A','Automatic reply: [#101]','reply',[],null,42,[7])==='dropped:auto-mail','auto-mail cannot append');
scope_check((int)$p->query('SELECT COUNT(*) FROM messages')->fetchColumn()===2,'denials wrote ticket messages');
scope_check($p->query('SELECT status FROM tickets WHERE id=101')->fetchColumn()==='open','accepted reply reopens own ticket');
echo "support intake cross-workspace, cross-client, thread and auto-mail checks passed\n";
