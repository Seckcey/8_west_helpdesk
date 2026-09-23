<?php
/** Dedicated customer history; no ticket, mail, billing or execution dependencies. */
declare(strict_types=1);

const CENTRAL_ISSUES_PATH = '/api/svc/central_issues.php';
final class CentralIssueRefused extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason)
    { parent::__construct($reason); }
}
function central_issue_require(bool $ok, int $status, string $reason): void
{ if (!$ok) throw new CentralIssueRefused($status, $reason); }
function central_issue_uuid(mixed $value): bool
{ return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1; }
function central_issue_keys(array $value, array $expected): bool
{ $keys=array_keys($value); sort($keys); sort($expected); return $keys===$expected; }
function central_issue_config(mixed $raw): array
{
    central_issue_require(is_array($raw) && ($raw['enabled']??false)===true,404,'unavailable');
    foreach (['secret','digest_key'] as $key) {
        central_issue_require(is_string($raw[$key]??null) && preg_match('/^[a-f0-9]{64}$/D',$raw[$key])===1
            && $raw[$key]!==str_repeat('0',64),503,'configuration_unavailable');
    }
    foreach (['tenant_id','provider_tenant_id'] as $key) {
        central_issue_require(is_int($raw[$key]??null) && $raw[$key]>0,503,'configuration_unavailable');
    }
    central_issue_require(!hash_equals($raw['secret'],$raw['digest_key']),503,'configuration_unavailable');
    return $raw;
}
function central_issue_auth(array $config, string $body, array $server, int $now): string
{
    $caller=$server['HTTP_X_CENTRAL_CALLER']??'';
    $timestamp=$server['HTTP_X_CENTRAL_TIMESTAMP']??'';
    $signature=$server['HTTP_X_CENTRAL_SIGNATURE']??'';
    central_issue_require(($server['REQUEST_METHOD']??'')==='POST'
        && ($server['REQUEST_URI']??'')===CENTRAL_ISSUES_PATH && strlen($body)<=16384
        && in_array($caller,['central-web','central-ai'],true)
        && is_string($timestamp) && preg_match('/^[0-9]{10}$/D',$timestamp)===1
        && abs($now-(int)$timestamp)<=60
        && is_string($signature) && preg_match('/^[a-f0-9]{64}$/D',$signature)===1,401,'unauthorized');
    $secret=$caller==='central-web'?$config['secret']:($config['assistant_secret']??'');
    central_issue_require(is_string($secret) && preg_match('/^[a-f0-9]{64}$/D',$secret)===1
        && $secret!==str_repeat('0',64)
        && ($caller==='central-web' || (!hash_equals($secret,$config['secret']) && !hash_equals($secret,$config['digest_key']))),401,'unauthorized');
    $signed="central.issues.v1\n".$caller."\nPOST\n".CENTRAL_ISSUES_PATH."\n".$timestamp."\n".hash('sha256',$body);
    central_issue_require(hash_equals(hash_hmac('sha256',$signed,$secret),$signature),401,'unauthorized');
    return $caller==='central-web'?'customer':'assistant';
}
function central_issue_text(mixed $raw, int $limit, bool $title=false): string
{
    central_issue_require(is_string($raw),400,'invalid_text');
    $text=trim(utf8_clean($raw));
    central_issue_require($text!=='' && mb_strlen($text,'UTF-8')<=$limit && strlen($text)<=8000
        && preg_match($title?'/[\x00-\x1f\x7f]/u':'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$text)===0,400,'invalid_text');
    return $text;
}
function central_issue_request(string $body, string $actor): array
{
    central_issue_require(strlen($body)<=16384,400,'invalid_request');
    try { $r=json_decode($body,true,8,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new CentralIssueRefused(400,'invalid_request'); }
    central_issue_require(is_array($r) && central_issue_keys($r,['account_ref','owner_sub','id_tenant_id','binding_version','operation','payload'])
        && central_issue_uuid($r['account_ref']) && is_string($r['owner_sub'])
        && preg_match('/^[A-Za-z0-9:_-]{1,64}$/D',$r['owner_sub'])===1
        && is_string($r['id_tenant_id']) && preg_match('/^[1-9][0-9]{0,18}$/D',$r['id_tenant_id'])===1
        && is_int($r['binding_version']) && $r['binding_version']>0
        && in_array($r['operation'],['list','read','create','message','state','erase'],true)
        && is_array($r['payload']),400,'invalid_request');
    central_issue_require(in_array($actor,['customer','assistant'],true)
        && ($actor==='customer' || $r['operation']==='message'),403,'operation_denied');
    $op=$r['operation']; $p=$r['payload'];
    $keys=match($op) {
        'list'=>['cursor'], 'read'=>['issue_ref'],
        'create'=>['operation_key','expected_version','title','body'],
        'message'=>['issue_ref','operation_key','expected_version','body'],
        'state'=>['issue_ref','operation_key','expected_version','state','body'],
        'erase'=>['issue_ref','operation_key','expected_version'],
    };
    central_issue_require(central_issue_keys($p,$keys),400,'invalid_request');
    if ($op==='list') central_issue_require($p['cursor']===null || central_issue_uuid($p['cursor']),400,'invalid_cursor');
    if (array_key_exists('issue_ref',$p)) central_issue_require(central_issue_uuid($p['issue_ref']),400,'invalid_issue');
    if (!in_array($op,['list','read'],true)) {
        central_issue_require(central_issue_uuid($p['operation_key']) && is_int($p['expected_version'])
            && ($op==='create'?$p['expected_version']===0:($p['expected_version']>0 && $p['expected_version']<=201)),400,'invalid_version');
    }
    if (array_key_exists('body',$p)) $p['body']=central_issue_text($p['body'],4000);
    if ($op==='create') $p['title']=central_issue_text($p['title'],140,true);
    if ($op==='state') central_issue_require(in_array($p['state'],['open','unresolved','resolved','cancelled'],true),400,'invalid_state');
    ksort($p); $r['payload']=$p; ksort($r);
    return $r;
}
function central_issue_account(PDO $pdo, array $config, array $r): array
{
    $q=$pdo->prepare('SELECT a.account_ref,a.owner_sub,a.id_tenant_id,a.provider_tenant_id,a.customer_id,a.binding_version
      FROM central_issue_accounts a
      JOIN suite_customer_sync_bindings b ON b.tenant_id=a.tenant_id AND b.id=a.customer_binding_id AND b.customer_id=a.customer_id
      JOIN svc_identities s ON s.tenant_id=a.tenant_id AND s.service=\'central-issues\' AND s.is_active=1
      WHERE a.tenant_id=? AND a.account_ref=? AND a.enabled=1 AND b.status=\'active\' FOR UPDATE');
    $q->execute([$config['tenant_id'],$r['account_ref']]); $a=$q->fetch(PDO::FETCH_ASSOC);
    central_issue_require(is_array($a) && hash_equals((string)$a['owner_sub'],$r['owner_sub'])
        && (string)$a['id_tenant_id']===$r['id_tenant_id'] && (int)$a['provider_tenant_id']===$config['provider_tenant_id']
        && (int)$a['binding_version']===$r['binding_version'] && central_issue_uuid($a['customer_id']),403,'account_unavailable');
    $a['provider_tenant_id']=(int)$a['provider_tenant_id']; $a['binding_version']=(int)$a['binding_version'];
    return $a;
}
function central_issue_summary(array $row): array
{
    return ['issue_ref'=>$row['issue_ref'],'title'=>$row['title'],'state'=>$row['state'],
        'resolution_source'=>$row['resolution_source'],'version'=>(int)$row['version'],
        'created_at'=>str_replace(' ','T',$row['created_at']).'Z','updated_at'=>str_replace(' ','T',$row['updated_at']).'Z'];
}
function central_issue_execute(PDO $pdo, array $config, array $r, string $actor): array
{
    central_issue_require(!$pdo->inTransaction(),503,'transaction_unavailable');
    central_issue_require((int)$pdo->query('SELECT central_issue_schema_health()')->fetchColumn()===1,503,'schema_unavailable');
    $tenant=$config['tenant_id']; $account=$r['account_ref']; $p=$r['payload']; $op=$r['operation'];
    $pdo->beginTransaction();
    try {
        $binding=central_issue_account($pdo,$config,$r);
        $envelope=['contract'=>'central.issues.v1','binding'=>$binding,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z')];
        if ($op==='list') {
            $before=0;
            if ($p['cursor']!==null) {
                $q=$pdo->prepare('SELECT id FROM central_issues WHERE tenant_id=? AND account_ref=? AND issue_ref=? AND erased=0');
                $q->execute([$tenant,$account,$p['cursor']]); $before=$q->fetchColumn();
                central_issue_require($before!==false,409,'cursor_unavailable');
            }
            $q=$pdo->prepare('SELECT * FROM central_issues WHERE tenant_id=? AND account_ref=? AND erased=0 AND (?=0 OR id<?) ORDER BY id DESC LIMIT 21');
            $q->execute([$tenant,$account,$before,$before]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $more=count($rows)>20; $rows=array_slice($rows,0,20);
            $result=['items'=>array_map('central_issue_summary',$rows),'next_cursor'=>$more?end($rows)['issue_ref']:null];
        } else {
            $mutation=$op!=='read';
            $digest=$mutation?hash_hmac('sha256',$actor."\n".json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$config['digest_key']):null;
            if ($mutation) {
                $q=$pdo->prepare('SELECT issue_ref,version,kind,request_digest FROM central_issue_events WHERE tenant_id=? AND account_ref=? AND operation_key=?');
                $q->execute([$tenant,$account,$p['operation_key']]); $receipt=$q->fetch(PDO::FETCH_ASSOC);
                if (is_array($receipt)) {
                    central_issue_require(hash_equals($receipt['request_digest'],$digest),409,'operation_conflict');
                    $pdo->commit();
                    return $envelope+['receipt'=>['issue_ref'=>$receipt['issue_ref'],'version'=>(int)$receipt['version'],'erased'=>$receipt['kind']==='erased','replayed'=>true]];
                }
            }
            if ($op==='create') {
                $q=$pdo->prepare('SELECT COUNT(*) AS total,SUM(erased=0) AS retained FROM central_issues WHERE tenant_id=? AND account_ref=?');
                $q->execute([$tenant,$account]); $count=$q->fetch(PDO::FETCH_ASSOC);
                central_issue_require((int)$count['retained']<100 && (int)$count['total']<1000,507,'storage_limit');
                $issue=null; $ref=$p['operation_key']; $version=1; $state='open'; $kind='opened';
            } else {
                $ref=$p['issue_ref'];
                $q=$pdo->prepare('SELECT * FROM central_issues WHERE tenant_id=? AND account_ref=? AND issue_ref=? FOR UPDATE');
                $q->execute([$tenant,$account,$ref]); $issue=$q->fetch(PDO::FETCH_ASSOC);
                central_issue_require(is_array($issue),404,'issue_unavailable');
                central_issue_require((int)$issue['erased']===0,410,'issue_erased');
                if ($mutation) central_issue_require((int)$issue['version']===$p['expected_version'],409,'version_conflict');
                if ($op==='read') {
                    $q=$pdo->prepare('SELECT version,actor,kind,body,state_after,created_at FROM central_issue_events WHERE tenant_id=? AND account_ref=? AND issue_ref=? ORDER BY version LIMIT 201');
                    $q->execute([$tenant,$account,$ref]); $events=$q->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($events as &$event) { $event['version']=(int)$event['version']; $event['created_at']=str_replace(' ','T',$event['created_at']).'Z'; } unset($event);
                    $result=['issue'=>central_issue_summary($issue),'events'=>$events];
                } else {
                    $version=(int)$issue['version']+1; $state=$issue['state']; $kind=$op==='erase'?'erased':$op;
                    central_issue_require($op==='erase' || $version<=200,507,'storage_limit');
                    if ($op==='message') central_issue_require(in_array($state,['open','unresolved'],true)
                        && ($actor==='customer' || $state==='open'),409,'issue_closed');
                    if ($op==='state') {
                        central_issue_require($p['state']!==$state && ($p['state']==='open' || in_array($state,['open','unresolved'],true)),409,'state_conflict');
                        $state=$p['state'];
                    }
                }
            }
            if ($mutation) {
                if ($op!=='erase') {
                    $q=$pdo->prepare('SELECT COALESCE(SUM(OCTET_LENGTH(body)),0) FROM central_issue_events WHERE tenant_id=? AND account_ref=?');
                    $q->execute([$tenant,$account]);
                    central_issue_require((int)$q->fetchColumn()+strlen($p['body'])<=10485760,507,'storage_limit');
                    $q=$pdo->prepare('SELECT COUNT(*) FROM central_issue_events WHERE tenant_id=? AND account_ref=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 60 SECOND');
                    $q->execute([$tenant,$account]); central_issue_require((int)$q->fetchColumn()<30,429,'rate_limit');
                }
                if ($op==='create') {
                    $q=$pdo->prepare('INSERT INTO central_issues(tenant_id,account_ref,issue_ref,title) VALUES(?,?,?,?)');
                    $q->execute([$tenant,$account,$ref,$p['title']]);
                } else {
                    $resolution=$state==='resolved'?'customer_reported':'none';
                    $q=$pdo->prepare('UPDATE central_issues SET version=?,state=?,resolution_source=?,erased=?,title=? WHERE tenant_id=? AND account_ref=? AND issue_ref=?');
                    $q->execute([$version,$state,$resolution,$op==='erase'?1:0,$op==='erase'?'':$issue['title'],$tenant,$account,$ref]);
                    if ($op==='erase') {
                        $q=$pdo->prepare('UPDATE central_issue_events SET body=NULL WHERE tenant_id=? AND account_ref=? AND issue_ref=?');
                        $q->execute([$tenant,$account,$ref]);
                    }
                }
                $q=$pdo->prepare('INSERT INTO central_issue_events(tenant_id,account_ref,issue_ref,operation_key,request_digest,version,actor,kind,body,state_after) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$tenant,$account,$ref,$p['operation_key'],$digest,$version,$actor,$kind,$op==='erase'?null:$p['body'],$state]);
                $result=['receipt'=>['issue_ref'=>$ref,'version'=>$version,'erased'=>$op==='erase','replayed'=>false]];
            }
        }
        central_issue_require($binding===central_issue_account($pdo,$config,$r),403,'account_unavailable');
        $pdo->commit();
        return $envelope+$result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000') throw new CentralIssueRefused(409,'operation_conflict');
        throw $e;
    }
}
