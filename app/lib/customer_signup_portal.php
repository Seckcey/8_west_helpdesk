<?php
/** Portal-only admission for verified, newly created 8 West business signups. */
declare(strict_types=1);
require_once __DIR__.'/portal_data.php';

/** Evidence callback is the local ID SELECT-only adapter, never request-supplied facts. */
function customer_signup_portal_apply(PDO $writer,string $signupId,int $actorId,callable $identity,?callable $fault=null): array {
    if (preg_match('/\A[0-9a-f]{64}\z/D',$signupId)!==1 || $actorId<1 || $writer->inTransaction()
        || explode('@',(string)$writer->query('SELECT SESSION_USER()')->fetchColumn())[0]!=='safeharbor_signup_worker') {
        throw new RuntimeException('signup_portal_identity');
    }
    $proof=$identity($signupId);
    $slug='customer-'.substr($signupId,0,24);
    if (($proof['signup_id']??null)!==$signupId || ($proof['provider']??null)!=='8west'
        || ($proof['identity_tenant_slug']??null)!==$slug
        || !is_string($proof['customer_id']??null)
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$proof['customer_id'])!==1
        || !is_string($proof['initial_receipt_id']??null) || preg_match('/\A[0-9a-f]{64}\z/D',$proof['initial_receipt_id'])!==1
        || !is_int($proof['source_version']??null) || $proof['source_version']<1
        || !is_string($proof['business']??null) || !is_string($proof['event_id']??null)
        || !is_string($proof['verified_at']??null)) throw new RuntimeException('signup_portal_proof');
    $reason='Verified business signup '.$signupId.' evidence '.hash('sha256',implode("\n",[
        '8west-business-portal-v1',$signupId,$proof['customer_id'],$slug,$proof['initial_receipt_id'],
    ]));
    $writer->beginTransaction();
    try {
        // Provider lock serializes competing signup adapters, including empty binding lookups.
        $q=$writer->query("SELECT id FROM tenants WHERE BINARY slug=BINARY '8west' FOR UPDATE");
        $tenant=$q->fetchColumn();
        if ($tenant===false) throw new RuntimeException('signup_portal_provider');
        $q=$writer->prepare('SELECT * FROM suite_customer_sync_bindings WHERE tenant_id=? AND customer_id=? FOR UPDATE');
        $q->execute([(int)$tenant,$proof['customer_id']]);$source=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source) || $source['status']!=='active' || (int)$source['source_version']!==$proof['source_version']
            || $source['last_event_id']!==$proof['event_id'] || $source['display_name']!==$proof['business']) {
            throw new RuntimeException('signup_portal_source');
        }
        $client=(int)$source['client_id'];
        portal_mapping_target($writer,(int)$tenant,$client,$actorId);
        if (!managed_customer_operational($writer,(int)$tenant,$client,true)) throw new RuntimeException('signup_portal_contained');
        $q=$writer->prepare('SELECT * FROM suite_customer_sync_events WHERE tenant_id=? AND binding_id=? AND source_version=1');
        $q->execute([(int)$tenant,(int)$source['id']]);$initial=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($initial) || strtotime($initial['occurred_at'].' UTC')<strtotime($proof['verified_at'].' UTC')) {
            throw new RuntimeException('signup_portal_historical');
        }
        $q=$writer->prepare('SELECT * FROM customer_portal_bindings WHERE identity_tenant_slug=? OR (tenant_id=? AND client_id=?) FOR UPDATE');
        $q->execute([$slug,(int)$tenant,$client]);$bindings=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($bindings)>1) throw new RuntimeException('signup_portal_conflict');
        $binding=$bindings[0]??null;
        if (is_array($binding)) {
            if ($binding['identity_tenant_slug']!==$slug || (int)$binding['tenant_id']!==(int)$tenant
                || (int)$binding['client_id']!==$client || $binding['status']!=='active' || $binding['status_reason']!==$reason) {
                throw new RuntimeException('signup_portal_conflict');
            }
            // A staff disable/restore must never look like an untouched signup replay.
            $q=$writer->prepare('SELECT event_kind,reason FROM customer_portal_binding_events WHERE tenant_id=? AND client_id=? AND binding_id=? ORDER BY id');
            $q->execute([(int)$tenant,$client,(int)$binding['id']]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
            if (count($events)!==2 || $events[0]!==['event_kind'=>'prepared','reason'=>$reason]
                || $events[1]!==['event_kind'=>'enabled','reason'=>$reason]) throw new RuntimeException('signup_portal_conflict');
            $bindingId=(int)$binding['id'];
        } else {
            $writer->prepare("INSERT INTO customer_portal_bindings
                (identity_tenant_slug,tenant_id,client_id,status,prepared_by_user_id,last_changed_by_user_id,status_reason)
                VALUES (?,?,?,'disabled',?,?,?)")->execute([$slug,(int)$tenant,$client,$actorId,$actorId,$reason]);
            $bindingId=(int)$writer->lastInsertId();
            if ($fault) $fault('prepared');
            $writer->prepare("UPDATE customer_portal_bindings SET status='active',last_changed_by_user_id=?,status_reason=? WHERE id=? AND status='disabled'")
                ->execute([$actorId,$reason,$bindingId]);
        }
        // Re-read ID after local locks/writes. Any refusal rolls back both audit transitions.
        if ($identity($signupId)!==$proof) throw new RuntimeException('signup_portal_identity_changed');
        if ($fault) $fault('before_commit');
        $writer->commit();
        return ['signup_id'=>$signupId,'customer_id'=>$proof['customer_id'],'provider'=>'8west',
            'identity_tenant_slug'=>$slug,'portal_binding_id'=>$bindingId,'ready'=>true];
    } catch (Throwable $error) {
        if ($writer->inTransaction()) $writer->rollBack();
        throw $error;
    }
}

/** Root-only settings, separate fixed read/write principals; never the web account. */
function customer_signup_portal_database(string $path,string $user,string $applicationRoot,?array $expected=null): PDO {
    $real=realpath($path);$root=realpath($applicationRoot);
    if (!str_starts_with($path,'/') || !is_string($real) || !is_file($real) || !is_string($root)
        || str_starts_with($real,$root.'/') || (fileperms($real)&0077)!==0 || fileowner($real)!==0) {
        throw new RuntimeException('signup_portal_config');
    }
    $db=(static fn(string $file):mixed=>require $file)($real);
    if (!is_array($db) || array_keys($db)!==['host','port','name','charset','user','pass']
        || !is_string($db['host']) || preg_match('/\A[A-Za-z0-9.:-]{1,255}\z/D',$db['host'])!==1
        || !is_int($db['port']) || $db['port']<1 || $db['port']>65535
        || !is_string($db['name']) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$db['name'])!==1
        || $db['charset']!=='utf8mb4' || $db['user']!==$user || !is_string($db['pass']) || $db['pass']==='') {
        throw new RuntimeException('signup_portal_config');
    }
    if ($expected!==null) foreach (['host','port','name','charset'] as $key) {
        if (($expected[$key]??null)!==$db[$key]) throw new RuntimeException('signup_portal_database_target');
    }
    $pdo=new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone='+00:00'",
    ]);
    if (explode('@',(string)$pdo->query('SELECT SESSION_USER()')->fetchColumn())[0]!==$user) throw new RuntimeException('signup_portal_identity');
    return $pdo;
}
