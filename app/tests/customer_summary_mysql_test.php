<?php
/** Exercise the real 040 migration on a fresh, uniquely named CI database. */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';
if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_WESTY_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_WESTY_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_WESTY_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_westy_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 40) exit(2);

function cs_mysql_check(bool $value, string $what): void
{
    if (!$value) throw new RuntimeException($what);
}
function cs_mysql_refuses(PDO $pdo, string $sql, string $state): void
{
    try { $pdo->exec($sql); }
    catch (PDOException $error) {
        cs_mysql_check($error->getCode() === $state, 'unexpected SQLSTATE ' . $error->getCode());
        return;
    }
    throw new RuntimeException('Expected a database refusal.');
}

$database = $base . '_s13_' . bin2hex(random_bytes(4));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", getenv('SAFEHARBOR_WESTY_TEST_USER') ?: 'root',
    getenv('SAFEHARBOR_WESTY_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE $database CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE $database");
    foreach (['users','clients','tickets'] as $table) {
        $pdo->exec("CREATE TABLE $table (id INT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL, UNIQUE(tenant_id,id)) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO $table VALUES (1,1),(2,2),(3,1)");
    }
    $pdo->exec('CREATE TABLE westy_email_drafts (tenant_id INT,client_id INT,ticket_id INT,state VARCHAR(20)) ENGINE=InnoDB');
    $migration = __DIR__ . '/../db/migrations/040_ticket_customer_summaries.sql';
    $sql = str_replace("\r\n", "\n", file_get_contents($migration));
    $fresh = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../db/schema.sql'));
    $block = explode("-- BEGIN CUSTOMER SUMMARIES (040)\n", $fresh, 2)[1];
    cs_mysql_check(str_starts_with($block, str_replace("SET SESSION lock_wait_timeout=60;\n", '', $sql)), 'fresh-install and upgrade definitions match');
    test_execute_sql_file($pdo, $migration);
    cs_mysql_check((int)$pdo->query('SELECT customer_summary_schema_health()')->fetchColumn() === 1, 'summary guards installed');
    cs_mysql_check($pdo->query('SELECT work_summary_delivery FROM clients WHERE tenant_id=1 AND id=1')->fetchColumn() === 'portal', 'existing client defaults to portal');
    $insert = "INSERT INTO ticket_customer_summaries(tenant_id,ticket_id,client_id,body,source_sha256,last_log_id)
               VALUES (1,1,1,'Printer works',REPEAT('a',64),5)";
    cs_mysql_refuses($pdo, "INSERT INTO ticket_customer_summaries(tenant_id,ticket_id,client_id,body,source_sha256,last_log_id,state,approved_by,approved_at)
        VALUES (1,1,1,'Direct approval',REPEAT('a',64),5,'approved',1,UTC_TIMESTAMP())", '45000');
    $pdo->exec($insert);
    $first = (int)$pdo->lastInsertId();
    $approve = "state='approved',approved_at=UTC_TIMESTAMP(),approved_by=1";
    $claim = ",emailed_at=UTC_TIMESTAMP(),email_result='claimed'";
    $where = " WHERE tenant_id=1 AND ticket_id=1 AND id=$first";
    cs_mysql_refuses($pdo, "UPDATE ticket_customer_summaries SET state='approved',approved_at=UTC_TIMESTAMP(),approved_by=2$where", '23000');
    $pdo->exec("UPDATE ticket_customer_summaries SET $approve$claim$where");
    foreach (["body='printer works'", "body='Printer works '", 'ticket_id=3', 'tenant_id=2', 'client_id=3',
              'last_log_id=8',"source_sha256=REPEAT('b',64)",'approved_by=3','approved_at=approved_at + INTERVAL 1 DAY',
              'created_at=created_at + INTERVAL 1 DAY',"state='draft'",'emailed_at=NULL,email_result=NULL'] as $change) {
        cs_mysql_refuses($pdo, "UPDATE ticket_customer_summaries SET $change$where", '45000');
    }
    $pdo->exec("UPDATE ticket_customer_summaries SET email_result='unconfirmed'$where");
    cs_mysql_refuses($pdo, "UPDATE ticket_customer_summaries SET email_result='claimed'$where", '45000');
    cs_mysql_refuses($pdo, "DELETE FROM ticket_customer_summaries$where", '45000');
    $pdo->exec($insert);
    $next = (int)$pdo->lastInsertId();
    $nextWhere = " WHERE tenant_id=1 AND ticket_id=1 AND id=$next";
    cs_mysql_refuses($pdo, "UPDATE ticket_customer_summaries SET $approve$nextWhere", '23000');
    $pdo->exec("UPDATE ticket_customer_summaries SET state='revoked'$where");
    cs_mysql_refuses($pdo, "UPDATE ticket_customer_summaries SET state='approved'$where", '45000');
    cs_mysql_refuses($pdo, "UPDATE ticket_customer_summaries SET $approve$claim$nextWhere", '23000');
    $pdo->exec("UPDATE ticket_customer_summaries SET $approve$nextWhere");
    $pdo->exec('DROP TRIGGER trg_customer_summary_update');
    cs_mysql_check((int)$pdo->query('SELECT customer_summary_schema_health()')->fetchColumn() === 0, 'a missing guard disables summaries');
    test_execute_sql_file($pdo, $migration);
    cs_mysql_check((int)$pdo->query('SELECT customer_summary_schema_health()')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM ticket_customer_summaries WHERE tenant_id=1 AND ticket_id=1')->fetchColumn() === 2,
        'rerun restores the guard and keeps review history');
    echo "Customer summary MySQL migration and immutability checks passed.\n";
} finally {
    $pdo->exec("DROP DATABASE $database");
}
