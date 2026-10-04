<?php
/** Exact schema/recovery contract on a disposable MySQL database only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('TENANT_AI_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
define('TAI_MIGRATION_LIBRARY_ONLY', true);
require __DIR__.'/../../deploy/tenant_ai_migration.php';
$pdo = new PDO(getenv('TENANT_AI_TEST_DSN') ?: '', getenv('TENANT_AI_TEST_USER') ?: 'root', getenv('TENANT_AI_TEST_PASS') ?: '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
]);
if (!preg_match('/\Atenant_ai_test_[a-z_]+\z/D', (string)$pdo->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('disposable database required');
$file = dirname(__DIR__, 2).'/'.TAI_MIGRATION_PATH;
$all = tai_migration_sql_statements(file_get_contents($file));
$ddl = array_values(array_filter($all, static fn(string $sql): bool => preg_match('/^CREATE (?:TABLE|TRIGGER) /i', $sql) === 1));
$n = 0;
function tai_schema_check(bool $ok, string $message): void { global $n; ++$n; if (!$ok) throw new RuntimeException($message); }
function tai_schema_refuses(callable $call, string $message): void {
    try { $call(); } catch (Throwable) { tai_schema_check(true, $message); return; }
    tai_schema_check(false, $message);
}
function tai_schema_clear(PDO $pdo): void {
    // Only this migration's fixed allowlist in the explicitly disposable database.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_reverse(TAI_MIGRATION_TABLES) as $table) $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
$standalone = static function() use ($pdo, $all): void { foreach ($all as $sql) $pdo->exec($sql); };
tai_schema_check(tai_migration_snapshot($pdo)['schema_ok'], 'canonical schema matches exact final catalog');
$standalone();
tai_schema_check(tai_migration_snapshot($pdo)['schema_ok'], 'standalone exact replay');
$pdo->exec('CREATE TRIGGER tai_test_unexpected BEFORE INSERT ON '.TAI_MIGRATION_TABLES[0].' FOR EACH ROW SET @tai_test=1');
tai_schema_check(tai_migration_snapshot($pdo)['drift'], 'unexpected guard is drift');
tai_schema_refuses($standalone, 'standalone refuses unexpected guard');
$pdo->exec('DROP TRIGGER tai_test_unexpected');
$pdo->exec('ALTER TABLE '.TAI_MIGRATION_TABLES[0].' ADD COLUMN tai_test_unexpected INT NULL');
tai_schema_check(tai_migration_snapshot($pdo)['drift'], 'unexpected column is drift');
tai_schema_refuses($standalone, 'standalone refuses column drift');
$pdo->exec('ALTER TABLE '.TAI_MIGRATION_TABLES[0].' DROP COLUMN tai_test_unexpected');
tai_schema_clear($pdo);
$standalone();
tai_schema_check(tai_migration_snapshot($pdo)['schema_ok'], 'fresh standalone reaches exact final schema');
foreach (range(0, count($ddl)-1) as $prefix) {
    tai_schema_clear($pdo);
    for ($i=0; $i<$prefix; ++$i) $pdo->exec($ddl[$i]);
    $pre=tai_migration_snapshot($pdo);
    tai_schema_check(!$pre['drift'] && $pre['position']===$prefix, 'exact prefix is recognised');
    $result=tai_migration_apply($pdo, $file);
    tai_schema_check($result['ok'] && $result['schema_ok'] && $result['row_count']===0, 'empty prefix resumes to exact final');
}
// A partial schema containing data is not silently repaired into a trusted state.
tai_schema_clear($pdo); $pdo->exec($ddl[0]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec("INSERT INTO portal_westy_ai_attempts(turn_id,sequence,tenant_id,client_id,scope_key,provider,model_name,catalog_version,ai_revision,credential_version,request_fingerprint,state,reserve_microusd,charged_microusd,created_at) VALUES(991,1,991,991,REPEAT('a',64),'openai','gpt-6-luna','synthetic',1,1,REPEAT('a',64),'pending',1,1,UTC_TIMESTAMP())");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
tai_schema_check(tai_migration_snapshot($pdo)['drift'], 'populated partial schema is refused');
tai_schema_refuses($standalone, 'standalone refuses populated partial');
tai_schema_refuses(static fn()=>tai_migration_apply($pdo,$file), 'protected library refuses populated partial');
tai_schema_clear($pdo); tai_migration_apply($pdo,$file);
echo "tenant_ai_migration_db_test: $n checks passed\n";
