<?php
/** Real grant discovery and account drain on an explicitly disposable MySQL server. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('TENANT_AI_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
define('TAI_WRITERS_LIBRARY_ONLY',true);
require __DIR__.'/../../deploy/tenant_ai_writers.php';
$dsn=(string)getenv('TENANT_AI_TEST_DSN');
if(!preg_match('/;dbname=(tenant_ai_test_[a-z0-9_]+)(?:;|$)/',$dsn,$match))exit(2);
$database=$match[1];
$pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($pdo->query('SELECT CURRENT_USER()')->fetchColumn()!=='root@localhost')throw new RuntimeException('localhost disposable root required');
$users=['taifix_schema','taifix_table','taifix_routine','taifix_reader'];
$checks=0;
function writers_check(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function writers_refuses(callable $fn,string $label):void{try{$fn();}catch(RuntimeException){writers_check(true,$label);return;}writers_check(false,$label);}
$profile=['database'=>$database,'writers'=>array_map(static fn($u)=>$u.'@localhost',array_slice($users,0,3))];
$live=null;
$createdUsers=[];$createdTable=false;$createdProcedure=false;
try {
    foreach($users as $user) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=?');$q->execute([$user]);
        if((int)$q->fetchColumn()!==0)throw new RuntimeException('fixture account already exists');
    }
    $pdo->exec('CREATE TABLE fixture_records (id INT PRIMARY KEY, value VARCHAR(30))');$createdTable=true;
    $pdo->exec('CREATE PROCEDURE fixture_write() INSERT INTO fixture_records VALUES(1,\'synthetic\')');$createdProcedure=true;
    foreach($users as $user){$pdo->exec("CREATE USER '$user'@'localhost' IDENTIFIED BY 'synthetic-fixture-only'");$createdUsers[]=$user;}
    $pdo->exec("GRANT INSERT,UPDATE ON `$database`.* TO 'taifix_schema'@'localhost'");
    $pdo->exec("GRANT INSERT(value),UPDATE(value) ON `$database`.fixture_records TO 'taifix_table'@'localhost'");
    $pdo->exec("GRANT EXECUTE ON PROCEDURE `$database`.fixture_write TO 'taifix_routine'@'localhost'");
    $pdo->exec("GRANT SELECT ON `$database`.* TO 'taifix_reader'@'localhost'");
    $facts=tai_writer_inventory($pdo,$database);$expected=$profile['writers'];sort($expected);
    writers_check($facts['writers']===$expected,'schema, column and routine grants all discovered; reader excluded');
    writers_refuses(fn()=>tai_writer_assert_frozen($facts,$profile),'unlocked writers refuse');
    $live=new PDO($dsn,'taifix_schema','synthetic-fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach(array_slice($users,0,3) as $user)$pdo->exec("ALTER USER '$user'@'localhost' ACCOUNT LOCK");
    writers_refuses(fn()=>tai_writer_assert_frozen(tai_writer_inventory($pdo,$database),$profile),'locking accounts does not drain existing connections');
    $live=null;
    $identity=tai_writer_assert_frozen(tai_writer_inventory($pdo,$database),$profile);
    writers_check($identity['database']===$database,'locked writers and real natural connection drain accepted');
    try { $denied=new PDO($dsn,'taifix_schema','synthetic-fixture-only');$denied=null;writers_check(false,'locked writer reconnected'); }
    catch(PDOException){writers_check(true,'new connections blocked by real account lock');}
    $pdo->exec("GRANT DELETE ON `$database`.fixture_records TO 'taifix_reader'@'localhost'");
    writers_refuses(fn()=>tai_writer_assert_frozen(tai_writer_inventory($pdo,$database),$profile),'new table writer requires review');
    $pdo->exec("REVOKE DELETE ON `$database`.fixture_records FROM 'taifix_reader'@'localhost'");
    $clean=tai_writer_inventory($pdo,$database);
    $pdo->exec("GRANT PROXY ON 'taifix_schema'@'localhost' TO 'taifix_reader'@'localhost'");
    writers_refuses(fn()=>tai_writer_assert_frozen(tai_writer_inventory($pdo,$database),$profile),'proxy impersonation grant requires review');
    $pdo->exec("REVOKE PROXY ON 'taifix_schema'@'localhost' FROM 'taifix_reader'@'localhost'");
    foreach(['role_edges'=>1,'enabled_events'=>1] as $field=>$value){$bad=$clean;$bad[$field]=$value;writers_refuses(fn()=>tai_writer_assert_frozen($bad,$profile),$field.' fails closed');}
    $bad=$clean;$bad['global'][]='unreviewed@localhost';writers_refuses(fn()=>tai_writer_assert_frozen($bad,$profile),'unexpected global administrator fails closed');
    $other=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    writers_refuses(fn()=>tai_writer_assert_frozen(tai_writer_inventory($pdo,$database),$profile),'second real administrator connection fails closed');
    $other=null;
    $pdo->exec("ALTER USER 'taifix_schema'@'localhost' ACCOUNT UNLOCK");
    writers_refuses(fn()=>tai_writer_assert_frozen(tai_writer_inventory($pdo,$database),$profile),'reopened writer invalidates previous freeze');
    $live=new PDO($dsn,'taifix_schema','synthetic-fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $live->exec("INSERT INTO fixture_records VALUES(2,'synthetic after reopen')");$live=null;
    writers_check((int)$pdo->query('SELECT COUNT(*) FROM fixture_records')->fetchColumn()===1,'explicit reopen retains existing writer grants');
    echo "tenant_ai_writers_db_test: $checks checks passed\n";
} finally {
    $live=null;
    foreach($createdUsers as $user)$pdo->exec("DROP USER '$user'@'localhost'");
    if($createdProcedure)$pdo->exec('DROP PROCEDURE fixture_write');
    if($createdTable)$pdo->exec('DROP TABLE fixture_records');
}
