<?php
/** Pinned forward migration. Only catalogued DDL prefixes may be resumed. CLI only. */
declare(strict_types=1);
const SMP_MIGRATION_PATH = 'app/db/migrations/030_managed_providers.sql';
const SMP_MIGRATION_SHA256 = '88e77d9337d7b1ebc01ae7340c549cdb2651f4db368e64fa17b724ae9d6df205';
const SMP_MIGRATION_SIZE_BYTES = 2735;
const SMP_CATALOG_SHA256 = '96a70ebffee8f460992bf76081e26f8ee3778a95c0c9e8d8dbb1bd9411f4227d';
const SMP_MIGRATION_TABLES = ['suite_managed_providers'];
const SMP_MIGRATION_ROUTINES = [];

function smp_sql_statements(string $sql): array {
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
function smp_normalize_sql(string $statement): string
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


function smp_catalog(PDO $pdo): array {
    $catalog=['tables'=>[], 'triggers'=>[], 'routines'=>[]];
    foreach (SMP_MIGRATION_TABLES as $table) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([$table]); $exists=(int)$q->fetchColumn()===1;
        $definition=$exists ? $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1] : null;
        // AUTO_INCREMENT is data state; every structural byte including CHECK/FK/index definitions is retained.
        $catalog['tables'][$table]=$definition===null ? null : hash('sha256',preg_replace('/ AUTO_INCREMENT=[0-9]+\b/','',$definition));
    }
    $placeholders=implode(',',array_fill(0,count(SMP_MIGRATION_TABLES),'?'));
    $q=$pdo->prepare("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT
        FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
        AND (EVENT_OBJECT_TABLE IN ($placeholders) OR TRIGGER_NAME LIKE 'suite\_%provider%'
          OR TRIGGER_NAME='trg_mci_provider_migration_block') ORDER BY TRIGGER_NAME");
    $q->execute(SMP_MIGRATION_TABLES);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
        $name=array_shift($row);$row[3]=hash('sha256',smp_normalize_sql($row[3]));
        $catalog['triggers'][$name]=$row;
    }
    foreach (SMP_MIGRATION_ROUTINES as $name) {
        $q=$pdo->prepare('SELECT ROUTINE_TYPE,SQL_DATA_ACCESS,SECURITY_TYPE,IS_DETERMINISTIC,ROUTINE_DEFINITION
            FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME=?');
        $q->execute([$name]);$row=$q->fetch(PDO::FETCH_NUM);
        if ($row!==false) {
            if (!is_string($row[4])) throw new RuntimeException('routine definition is not visible');
            $row[4]=hash('sha256',smp_normalize_sql($row[4]));
            $parameters=$pdo->prepare('SELECT PARAMETER_MODE,PARAMETER_NAME,DTD_IDENTIFIER,CHARACTER_SET_NAME,COLLATION_NAME
                FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE() AND SPECIFIC_NAME=? ORDER BY ORDINAL_POSITION');
            $parameters->execute([$name]);$row[]=$parameters->fetchAll(PDO::FETCH_NUM);
        }
        $catalog['routines'][$name]=$row===false ? null : $row;
    }
    return $catalog;
}
function smp_contract(): array {
    $file=__DIR__.'/managed_provider_migration_catalog.json';
    if (!is_file($file) || !hash_equals(SMP_CATALOG_SHA256,hash_file('sha256',$file))) throw new RuntimeException('catalog checksum mismatch');
    return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
}
function smp_snapshot(PDO $pdo): array {
    $states=smp_contract();$actual=smp_catalog($pdo);$position=null;
    foreach ($states as $i=>$state) if ($actual===$state) $position=$i;
    $exists=$actual['tables']['suite_managed_providers']!==null;
    $count=$exists ? (int)$pdo->query('SELECT COUNT(*) FROM suite_managed_providers')->fetchColumn() : 0;
    $final=$position===count($states)-1;
    $drift=$position===null || (!$final && $count!==0);
    return ['recovery_state'=>$drift?'DRIFT':($final?'FINAL':'PREFIX_'.$position),
        'position'=>$position,'schema_ok'=>$final&&!$drift,'drift'=>$drift,'prerequisites_ok'=>!$drift,
        'table_exists'=>$exists,'column_exists'=>$exists,'column_ok'=>!$drift,
        'partial_create_ok'=>!$drift&&!$final&&$position>2,'row_count'=>$count];
}
function smp_apply(PDO $pdo,string $file): array {
    if (!is_file($file) || is_link($file) || !hash_equals(SMP_MIGRATION_SHA256,hash_file('sha256',$file))) throw new RuntimeException('payload checksum mismatch');
    if ($pdo->inTransaction()) throw new RuntimeException('ambient transaction refused');
    if ((int)$pdo->query("SELECT GET_LOCK('suite:managed-provider-migration',0)")->fetchColumn()!==1) throw new RuntimeException('migration already running');
    try {
        $statements=smp_sql_statements(file_get_contents($file));$states=smp_contract();
        if (count($states)!==count($statements)+1) throw new RuntimeException('catalog size mismatch');
        $pdo->exec('SET NAMES utf8mb4');$pdo->exec("SET time_zone = '+00:00'");
        $pre=smp_snapshot($pdo);
        if ($pre['drift']) throw new RuntimeException('schema drift or populated partial state');
        $counts=[];
        foreach (SMP_MIGRATION_TABLES as $table) if ($table!=='suite_managed_providers') $counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        for ($i=$pre['position'];$i<count($statements);$i++) {
            $pdo->exec($statements[$i]);
            if (smp_catalog($pdo)!==$states[$i+1]) throw new RuntimeException('DDL checkpoint mismatch');
        }
        foreach ($counts as $table=>$count) if ($count!==(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()) throw new RuntimeException('existing record count changed; retain write freeze');
        $post=smp_snapshot($pdo);
        if (!$post['schema_ok']) throw new RuntimeException('migration postflight failed');
        return ['ok'=>true,'resumed_from'=>$pre['recovery_state']]+$post;
    } finally { $pdo->query("SELECT RELEASE_LOCK('suite:managed-provider-migration')"); }
}
if (defined('SMP_MIGRATION_LIBRARY_ONLY')) return;
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
try {
    $args=$argv;$sub=$args[1]??'';
    $arg=static function(string $name)use($args):?string{$i=array_search($name,$args,true);return $i===false?null:($args[$i+1]??null);};
    if ($sub==='validate-manifest') {
        $m=json_decode(file_get_contents($arg('--manifest')??''),true,32,JSON_THROW_ON_ERROR);
        $keys=['kind','schemaVersion','migrationId','targetSha','repoPath','fileName','fullSha256','execSha256','mode','sizeBytes'];
        if (!is_array($m) || array_keys($m)!==$keys || ($m['sizeBytes']??null)!==SMP_MIGRATION_SIZE_BYTES) throw new RuntimeException('manifest shape mismatch');
        if (($m['kind']??'')!=='milepost-migration-package' || ($m['schemaVersion']??'')!=='1.0.0'
            || ($m['migrationId']??'')!=='managed_providers' || ($m['repoPath']??'')!==SMP_MIGRATION_PATH
            || ($m['fileName']??'')!==basename(SMP_MIGRATION_PATH) || ($m['fullSha256']??'')!==SMP_MIGRATION_SHA256
            || ($m['execSha256']??'')!==SMP_MIGRATION_SHA256 || ($m['mode']??'')!=='100644'
            || !preg_match('/\A[0-9a-f]{40}\z/D',$m['targetSha']??'')) throw new RuntimeException('manifest mismatch');
        echo $m['targetSha'],"\n";exit;
    }
    $root=$arg('--portal-root')??$arg('--app-root')??'';
    $config=require $root.'/config/config.php';$db=$config['db'];
    if (!preg_match('/\A[A-Za-z0-9_]+\z/D',$db['name']??'')) throw new RuntimeException('invalid database name');
    if ($sub==='backup-defaults') {
        $path=$arg('--out')??'';$fd=fopen($path,'x');if($fd===false)throw new RuntimeException('backup defaults creation failed');
        chmod($path,0600);
        $quote=static fn(string $v):string=>'"'.str_replace(['\\','"'],['\\\\','\\"'],$v).'"';
        fwrite($fd,"[client]\nhost=".$quote($db['host'])."\nport=".(int)($db['port']??3306)."\nuser=".$quote($db['user'])."\npassword=".$quote($db['pass'])."\n");fclose($fd);echo $db['name'],"\n";exit;
    }
    $admin=in_array('--admin-socket',$args,true);
    if($admin && (!function_exists('posix_geteuid') || posix_geteuid()!==0))throw new RuntimeException('root required');
    $dsn=$admin?'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname='.$db['name']:
        'mysql:host='.$db['host'].';port='.(int)($db['port']??3306).';dbname='.$db['name'];
    $pdo=new PDO($dsn.';charset=utf8mb4',$admin?'root':$db['user'],$admin?'':$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $result=match($sub){'plan','introspect'=>smp_snapshot($pdo),'apply'=>smp_apply($pdo,$arg('--migration-file')??''),default=>throw new RuntimeException('unknown command')};
    echo json_encode($result,JSON_THROW_ON_ERROR),"\n";
    exit($result['drift']?1:0);
} catch (Throwable $e) { fwrite(STDERR,"managed provider migration refused; inspect protected release diagnostics\n");exit(1); }
