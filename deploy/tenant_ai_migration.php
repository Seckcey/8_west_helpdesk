<?php
/** Pinned forward migration. Only catalogued DDL prefixes may be resumed. CLI only. */
declare(strict_types=1);
const TAI_MIGRATION_PATH = 'app/db/migrations/20261004_tenant_ai.sql';
const TAI_MIGRATION_SHA256 = '9ce219bc7f36e0ce981ac28e1b88929f8d111ce97233647503c2fb1ca27dfa36';
const TAI_MIGRATION_SIZE_BYTES = 8924;
const TAI_CATALOG_SHA256 = '99070b51597f4b5ee38a22c33eef6118e4a625d448cd9762fe99dcaf5701ea8e';
const TAI_MIGRATION_TABLES = ['portal_westy_ai_attempts'];
const TAI_MIGRATION_ROUTINES = [];

function tai_migration_sql_statements(string $sql): array {
    $result=[]; $buffer=''; $delimiter=';';
    foreach (explode("\n", str_replace("\r\n", "\n", $sql)) as $line) {
        if (preg_match('/^DELIMITER (\S+)$/', trim($line), $match)) {
            if (trim($buffer)!=='') throw new RuntimeException('invalid delimiter boundary');
            $delimiter=$match[1]; continue;
        }
        if (str_starts_with(ltrim($line),'--') || trim($line)==='') continue;
        $buffer.=$line."\n";
        if (str_ends_with(rtrim($buffer),$delimiter)) {
            $result[]=trim(substr(rtrim($buffer),0,-strlen($delimiter))); $buffer='';
        }
    }
    if (trim($buffer)!=='') throw new RuntimeException('incomplete migration');
    return $result;
}
function tai_migration_normalize_sql(string $statement): string
{
    $normalized = '';
    $quote = null;
    $pendingSpace = false;
    $length = strlen($statement);
    for ($index = 0; $index < $length; $index++) {
        $character = $statement[$index];
        if ($quote === "'") {
            $normalized .= $character;
            if ($character === '\\' && $index + 1 < $length) {
                $normalized .= $statement[++$index];
            } elseif ($character === "'") {
                if ($index + 1 < $length && $statement[$index + 1] === "'") {
                    $normalized .= $statement[++$index];
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($quote === '"') {
            $normalized .= $character;
            if ($character === '\\' && $index + 1 < $length) {
                $normalized .= $statement[++$index];
            } elseif ($character === '"') {
                if ($index + 1 < $length && $statement[$index + 1] === '"') {
                    $normalized .= $statement[++$index];
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($quote === '`') {
            if ($character === '`') {
                if ($index + 1 < $length && $statement[$index + 1] === '`') {
                    $normalized .= '`';
                    $index++;
                } else {
                    $quote = null;
                }
            } else {
                $normalized .= $character;
            }
            continue;
        }
        if (ctype_space($character)) {
            $pendingSpace = $normalized !== '';
            continue;
        }
        if ($pendingSpace) {
            $normalized .= ' ';
            $pendingSpace = false;
        }
        if ($character === "'" || $character === '"') {
            $quote = $character;
            $normalized .= $character;
        } elseif ($character === '`') {
            $quote = '`';
        } else {
            $normalized .= $character;
        }
    }
    return trim($normalized);
}


function tai_migration_catalog(PDO $pdo): array {
    $catalog=['tables'=>[], 'triggers'=>[], 'routines'=>[]];
    foreach (TAI_MIGRATION_TABLES as $table) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([$table]); $exists=(int)$q->fetchColumn()===1;
        $definition=$exists ? $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1] : null;
        // AUTO_INCREMENT is data state; every structural byte including CHECK/FK/index definitions is retained.
        $catalog['tables'][$table]=$definition===null ? null : hash('sha256',preg_replace('/ AUTO_INCREMENT=[0-9]+\b/','',$definition));
    }
    $placeholders=implode(',',array_fill(0,count(TAI_MIGRATION_TABLES),'?'));
    $q=$pdo->prepare("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT
        FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
        AND (EVENT_OBJECT_TABLE IN ($placeholders) OR TRIGGER_NAME LIKE 'portal\_ai\_attempt\_%' OR TRIGGER_NAME LIKE '%\_tai\_%') ORDER BY TRIGGER_NAME");
    $q->execute(TAI_MIGRATION_TABLES);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
        $name=array_shift($row);$row[3]=hash('sha256',tai_migration_normalize_sql($row[3]));
        $catalog['triggers'][$name]=$row;
    }
    foreach (TAI_MIGRATION_ROUTINES as $name) {
        $q=$pdo->prepare('SELECT ROUTINE_TYPE,SQL_DATA_ACCESS,SECURITY_TYPE,IS_DETERMINISTIC,ROUTINE_DEFINITION
            FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME=?');
        $q->execute([$name]);$row=$q->fetch(PDO::FETCH_NUM);
        if ($row!==false) {
            if (!is_string($row[4])) throw new RuntimeException('routine definition is not visible');
            $row[4]=hash('sha256',tai_migration_normalize_sql($row[4]));
            $parameters=$pdo->prepare('SELECT PARAMETER_MODE,PARAMETER_NAME,DTD_IDENTIFIER,CHARACTER_SET_NAME,COLLATION_NAME
                FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE() AND SPECIFIC_NAME=? ORDER BY ORDINAL_POSITION');
            $parameters->execute([$name]);$row[]=$parameters->fetchAll(PDO::FETCH_NUM);
        }
        $catalog['routines'][$name]=$row===false ? null : $row;
    }
    return $catalog;
}
function tai_migration_contract(): array {
    $file=__DIR__.'/tenant_ai_migration_catalog.json';
    if (!is_file($file) || !hash_equals(TAI_CATALOG_SHA256,hash_file('sha256',$file))) throw new RuntimeException('catalog checksum mismatch');
    return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
}
function tai_migration_snapshot(PDO $pdo): array {
    $states=tai_migration_contract();$actual=tai_migration_catalog($pdo);$position=null;
    foreach ($states as $i=>$state) if ($actual===$state) $position=$i;
    $exists=false;$count=0;
    foreach(TAI_MIGRATION_TABLES as $table){
        if($actual['tables'][$table]!==null){$exists=true;$count+=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();}
    }
    $final=$position===count($states)-1;
    $drift=$position===null || (!$final && $count!==0);
    return ['recovery_state'=>$drift?'DRIFT':($final?'FINAL':'PREFIX_'.$position),
        'position'=>$position,'schema_ok'=>$final&&!$drift,'drift'=>$drift,'prerequisites_ok'=>!$drift,
        'table_exists'=>$exists,'column_exists'=>$exists,'column_ok'=>!$drift,
        'partial_create_ok'=>!$drift&&!$final&&$position>0,'row_count'=>$count];
}
function tai_migration_apply(PDO $pdo,string $file): array {
    if (!is_file($file) || is_link($file) || filesize($file)!==TAI_MIGRATION_SIZE_BYTES || !hash_equals(TAI_MIGRATION_SHA256,hash_file('sha256',$file))) throw new RuntimeException('payload checksum mismatch');
    if ($pdo->inTransaction()) throw new RuntimeException('ambient transaction refused');
    if ((int)$pdo->query("SELECT GET_LOCK('suite:tenant-ai-migration',0)")->fetchColumn()!==1) throw new RuntimeException('migration already running');
    try {
        $all=tai_migration_sql_statements(file_get_contents($file));
        $statements=array_values(array_filter($all,static fn(string $sql):bool=>preg_match('/^CREATE (?:TABLE|TRIGGER) /i',$sql)===1));
        foreach($all as $sql)if(preg_match('/^CREATE (?:TABLE|TRIGGER) /i',$sql)!==1)$pdo->exec($sql);
        $states=tai_migration_contract();
        if (count($states)!==count($statements)+1) throw new RuntimeException('catalog size mismatch');
        $pdo->exec('SET NAMES utf8mb4');$pdo->exec("SET time_zone = '+00:00'");
        $pre=tai_migration_snapshot($pdo);
        if ($pre['drift']) throw new RuntimeException('schema drift or populated partial state');
        $counts=[];
        foreach (TAI_MIGRATION_TABLES as $table) if($pre['schema_ok']) $counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        for ($i=$pre['position'];$i<count($statements);$i++) {
            $pdo->exec($statements[$i]);
            if (tai_migration_catalog($pdo)!==$states[$i+1]) throw new RuntimeException('DDL checkpoint mismatch');
        }
        foreach ($counts as $table=>$count) if ($count!==(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()) throw new RuntimeException('existing record count changed; retain write freeze');
        $post=tai_migration_snapshot($pdo);
        if (!$post['schema_ok']) throw new RuntimeException('migration postflight failed');
        return ['ok'=>true,'resumed_from'=>$pre['recovery_state']]+$post;
    } finally { $pdo->query("SELECT RELEASE_LOCK('suite:tenant-ai-migration')"); }
}
// The library is used by the protected release runner and disposable schema tests.
// Direct application is refused: the runner must verify database identity, freeze,
// source pins, verified backup, operator intent and the durable completion receipt.
if (defined('TAI_MIGRATION_LIBRARY_ONLY')) return;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
fwrite(STDERR,"Use the protected tenant AI release runner; direct DDL is refused.\n");exit(1);
