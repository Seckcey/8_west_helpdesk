<?php
/** Capture schema-only evidence on a new disposable MySQL database. Never uses runtime config. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('TENANT_AI_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
define('TAI_MIGRATION_LIBRARY_ONLY',true);
require __DIR__.'/../deploy/tenant_ai_migration.php';
$pdo=new PDO(getenv('TENANT_AI_TEST_DSN')?:'',getenv('TENANT_AI_TEST_USER')?:'root',getenv('TENANT_AI_TEST_PASS')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if(!preg_match('/\Atenant_ai_test_[a-z_]+\z/D',$name)||$pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('fresh disposable schema required');
$root=dirname(__DIR__);$schema=file_get_contents($root.'/app/db/schema.sql');
$schema=preg_replace('/-- BEGIN TENANT AI[^\n]*\n[\s\S]+?-- END TENANT AI[^\n]*(?:\n|$)/','',$schema,1,$removed);
if($removed!==1)throw new RuntimeException('canonical marker missing');
foreach(tai_migration_sql_statements($schema) as $sql)$pdo->exec($sql);
$pdo->exec('SET NAMES utf8mb4');$pdo->exec("SET time_zone='+00:00'");$pdo->exec('SET SESSION group_concat_max_len=1048576');
$states=[tai_migration_catalog($pdo)];
foreach(tai_migration_sql_statements(file_get_contents($root.'/'.TAI_MIGRATION_PATH)) as $sql){$pdo->exec($sql);if(preg_match('/^CREATE (?:TABLE|TRIGGER) /i',$sql)===1)$states[]=tai_migration_catalog($pdo);}
$metadata=[];$queries=json_decode(file_get_contents(__DIR__.'/tenant_ai_metadata_queries.json'),true,32,JSON_THROW_ON_ERROR);
foreach(TAI_MIGRATION_TABLES as $table){
 foreach($queries as $kind=>$query){$query=str_replace('@table@',$table,$query);$metadata[$table][$kind]=['query'=>$query,'value'=>$pdo->query($query)->fetch()];}
 $q=$pdo->prepare('SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME');$q->execute([$table]);
 $metadata[$table]['triggers']=$q->fetchAll();
}
echo json_encode(['states'=>$states,'metadata'=>$metadata],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
