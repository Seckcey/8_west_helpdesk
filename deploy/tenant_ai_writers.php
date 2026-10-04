<?php
/** Read-only MySQL freeze verification. No application config or credentials are loaded. */
declare(strict_types=1);

function tai_writer_profile(string $app): array
{
    return match ($app) {
        'id' => ['database'=>'ewid', 'writers'=>['ewid@localhost','ewid_customer_projector@localhost','ewid_signup_worker@localhost']],
        'safeharbor' => ['database'=>'safeharbor', 'writers'=>['safeharbor@localhost','safeharbor_signup_worker@localhost','safeharbor_time_export@localhost']],
        default => throw new RuntimeException('unknown application freeze profile'),
    };
}

/**
 * Discover every account with target-schema writes, DDL, grant or routine execution.
 * Role grants and unexpected global privileges require review; they are never
 * silently treated as read-only. The returned facts contain no password hashes.
 */
function tai_writer_inventory(PDO $pdo, string $database): array
{
    if (!preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D',$database)) throw new RuntimeException('invalid database identity');
    $writePrivileges=['Insert_priv','Update_priv','Delete_priv','Create_priv','Drop_priv','Grant_priv',
        'References_priv','Index_priv','Alter_priv','Create_tmp_table_priv','Lock_tables_priv',
        'Execute_priv','Create_view_priv','Create_routine_priv','Alter_routine_priv','Event_priv','Trigger_priv'];
    $predicate=implode(' OR ',array_map(static fn(string $name):string=>"`$name`='Y'",$writePrivileges));
    $globalPredicate=$predicate." OR Super_priv='Y' OR Create_user_priv='Y' OR File_priv='Y'";
    $accounts=[];
    foreach ($pdo->query('SELECT User,Host,account_locked FROM mysql.user ORDER BY User,Host')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key=$row['User'].'@'.$row['Host'];
        if (isset($accounts[$key]) || str_contains($row['User'],'@') || str_contains($row['Host'],'@')) throw new RuntimeException('ambiguous account identity');
        $accounts[$key]=['user'=>$row['User'],'host'=>$row['Host'],'locked'=>$row['account_locked']==='Y'];
    }
    $global=[];
    foreach ($pdo->query('SELECT User,Host FROM mysql.user WHERE '.$globalPredicate)->fetchAll(PDO::FETCH_ASSOC) as $row) $global[$row['User'].'@'.$row['Host']]=true;
    // Dynamic grants may allow changing users, roles, binlogs or server state.
    // Their mere presence on an unreviewed account closes the release gate.
    foreach ($pdo->query('SELECT USER AS User,HOST AS Host FROM mysql.global_grants')->fetchAll(PDO::FETCH_ASSOC) as $row) $global[$row['User'].'@'.$row['Host']]=true;
    $writers=[];
    $query=$pdo->prepare('SELECT User,Host FROM mysql.db WHERE ? LIKE Db AND ('.$predicate.')');
    $query->execute([$database]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $writers[$row['User'].'@'.$row['Host']]=true;
    foreach (['tables_priv'=>['Table_priv',['Insert','Update','Delete','Create','Drop','Grant','References','Index','Alter','Create View','Trigger']],
        'columns_priv'=>['Column_priv',['Insert','Update','References']],
        'procs_priv'=>['Proc_priv',['Execute','Alter Routine','Grant']]] as $table=>[$column,$privileges]) {
        $parts=implode(' OR ',array_fill(0,count($privileges),"FIND_IN_SET(?,`$column`)>0"));
        $query=$pdo->prepare("SELECT User,Host FROM mysql.`$table` WHERE Db=? AND ($parts)");
        $query->execute([$database,...$privileges]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $writers[$row['User'].'@'.$row['Host']]=true;
    }
    $events=$pdo->prepare("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=? AND STATUS='ENABLED'");
    $events->execute([$database]);
    $connections=$pdo->query('SELECT ID,USER FROM information_schema.PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
    $identity=$pdo->query('SELECT DATABASE() AS db,@@server_uuid AS server_uuid,@@version AS version,CURRENT_USER() AS account,CONNECTION_ID() AS connection_id')->fetch(PDO::FETCH_ASSOC);
    $writerKeys=array_keys($writers);sort($writerKeys);$globalKeys=array_keys($global);sort($globalKeys);
    foreach (array_unique([...$writerKeys,...$globalKeys]) as $key) {
        if (!isset($accounts[$key])) throw new RuntimeException('grant refers to an absent account');
        $account=$accounts[$key];
        $grants=$pdo->query('SHOW GRANTS FOR '.$pdo->quote($account['user']).'@'.$pdo->quote($account['host']))->fetchAll(PDO::FETCH_COLUMN);
        sort($grants);
        $accounts[$key]['grants_sha256']=hash('sha256',json_encode($grants,JSON_THROW_ON_ERROR));
    }
    return ['identity'=>$identity,'accounts'=>$accounts,'writers'=>$writerKeys,'global'=>$globalKeys,
        'proxies'=>$pdo->query('SELECT User,Host,Proxied_user,Proxied_host,With_grant FROM mysql.proxies_priv ORDER BY User,Host,Proxied_user,Proxied_host')->fetchAll(PDO::FETCH_ASSOC),
        'role_edges'=>(int)$pdo->query('SELECT COUNT(*) FROM mysql.role_edges')->fetchColumn(),
        'enabled_events'=>(int)$events->fetchColumn(),'connections'=>$connections];
}

/** Pure decision seam also used by synthetic negative fixtures. */
function tai_writer_assert_reviewed(array $facts, array $profile): array
{
    $identity=$facts['identity'];
    if ($identity['db']!==$profile['database'] || $identity['account']!=='root@localhost'
        || !preg_match('/\A[0-9a-f-]{36}\z/D',$identity['server_uuid'])) throw new RuntimeException('unexpected operator database identity');
    $writers=$profile['writers'];sort($writers);
    if ($facts['writers']!==$writers || $facts['role_edges']!==0 || $facts['enabled_events']!==0) throw new RuntimeException('writer, role or event inventory requires review');
    // The reviewed server has only the installation's root proxy grant.
    // Any other proxy could impersonate a writer without its own schema grant.
    foreach($facts['proxies'] as $proxy)
        if($proxy['User']!=='root'||$proxy['Host']!=='localhost'||$proxy['Proxied_user']!==''
            ||$proxy['Proxied_host']!==''||(int)$proxy['With_grant']!==1)
            throw new RuntimeException('unreviewed proxy privilege');
    $allowedGlobal=['debian-sys-maint@localhost','mysql.infoschema@localhost','mysql.session@localhost','mysql.sys@localhost','root@localhost'];
    if (!in_array('root@localhost',$facts['global'],true) || array_diff($facts['global'],$allowedGlobal)) throw new RuntimeException('unreviewed global writer');
    foreach(['mysql.infoschema@localhost','mysql.session@localhost','mysql.sys@localhost'] as $internal)
        if(($facts['accounts'][$internal]['locked']??null)!==true)throw new RuntimeException('internal MySQL principal is unexpectedly open');
    return ['database'=>$identity['db'],'server_uuid'=>$identity['server_uuid']];
}

function tai_writer_assert_frozen(array $facts, array $profile): array
{
    $identity=tai_writer_assert_reviewed($facts,$profile);
    $writers=$profile['writers'];
    $users=[];
    foreach ($writers as $account) {
        $row=$facts['accounts'][$account]??null;
        if (!$row || $row['locked']!==true) throw new RuntimeException('application writer is not locked');
        $users[$row['user']]=true;
    }
    foreach ($facts['global'] as $account) $users[$facts['accounts'][$account]['user']]=true;
    foreach ($facts['connections'] as $connection) {
        if ((int)$connection['ID']===(int)$facts['identity']['connection_id']) continue;
        if (isset($users[$connection['USER']])) throw new RuntimeException('writer or other administrator connection has not drained');
    }
    return $identity;
}

if (!defined('TAI_WRITERS_LIBRARY_ONLY')) {
    if (PHP_SAPI!=='cli') http_response_code(404);
    exit(2);
}
