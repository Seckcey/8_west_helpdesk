<?php
/** Private portal reactions and bounded operator metadata review. */
declare(strict_types=1);

function portal_westy_feedback_installed(PDO $pdo): bool
{
    try { $pdo->query('SELECT id FROM portal_westy_reply_feedback LIMIT 0'); return true; }
    catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) === 1146
            || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($error->getMessage(), 'no such table:'))) return false;
        throw $error;
    }
}

/** Only recorded selections, not today's catalog or an inferred provider build. */
function portal_westy_feedback_attempts(PDO $pdo, array $scope, array $turnIds, bool $lock = false): array
{
    if (!$turnIds) return [];
    $marks=implode(',',array_fill(0,count($turnIds),'?'));
    $q=$pdo->prepare('SELECT id,turn_id,sequence,provider,model_name,catalog_version,ai_revision,state,created_at,finished_at
        FROM portal_westy_ai_attempts WHERE tenant_id=? AND client_id=? AND scope_key=? AND turn_id IN ('.$marks.') ORDER BY sequence'.($lock?portal_westy_lock($pdo):''));
    $q->execute([$scope['tenant'],$scope['client'],$scope['key'],...$turnIds]);
    $byTurn=[];
    foreach ($q->fetchAll() as $row) {
        $id=(int)$row['turn_id']; unset($row['turn_id']);
        foreach (['id','sequence','ai_revision'] as $field) $row[$field]=(int)$row[$field];
        $row['provider_model_version']=null; // The existing receipts do not record this.
        $byTurn[$id][]=$row;
    }
    return $byTurn;
}

/** A continuation changes the fingerprint; already-rated snapshots remain intact. */
function portal_westy_feedback_snapshot(array $turn, array $attempts): ?array
{
    if (!in_array($turn['state'],['complete','unavailable'],true) || !is_string($turn['input_text']) || !is_string($turn['reply_json'])
        || $turn['expires_at']<=gmdate('Y-m-d H:i:s')) return null;
    $reply=json_decode((string)$turn['reply_json'],true,512,JSON_THROW_ON_ERROR);
    if (!is_array($reply) || !is_string($reply['reply']??null) || trim($reply['reply'])==='') return null;
    foreach ($attempts as $attempt) if ($attempt['state']==='pending') return null;
    $snapshot=['version'=>1,'turn_id'=>(int)$turn['id'],'conversation'=>$turn['conversation_key'],
        'operation'=>$turn['operation_key'],'prompt'=>$turn['input_text'],'response'=>$reply['reply'],
        'saved_reply'=>$reply,'turn_state'=>$turn['state'],'reason_code'=>$turn['reason_code'],
        'turn_recorded_model'=>$turn['model_name']?:null,'attempts'=>$attempts,
        'context_kind'=>'saved_portal_turn','provider_request_snapshot'=>null];
    return ['response_id'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)), 'snapshot'=>$snapshot];
}

function portal_westy_feedback_latest(PDO $pdo, array $scope, int $turnId, string $responseId, bool $lock = false): array
{
    $q=$pdo->prepare('SELECT revision,reaction,created_at FROM portal_westy_reply_feedback
        WHERE tenant_id=? AND client_id=? AND scope_key=? AND turn_id=? AND response_key=? ORDER BY revision DESC LIMIT 1'.($lock?portal_westy_lock($pdo):''));
    $q->execute([$scope['tenant'],$scope['client'],$scope['key'],$turnId,$responseId]);
    $row=$q->fetch();
    return ['response_id'=>$responseId,'revision'=>$row?(int)$row['revision']:0,
        'reaction'=>$row?$row['reaction']:'none','reacted_at'=>$row?$row['created_at']:null];
}

/** Project only the actor's current saved response, never its private snapshot. */
function portal_westy_feedback_project(PDO $pdo, array $scope, array $turns): array
{
    if (!$turns || !portal_westy_feedback_installed($pdo)) return $turns;
    $ids=array_map(static fn($t)=>(int)$t['id'],$turns);
    $attempts=portal_westy_feedback_attempts($pdo,$scope,$ids);
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare('SELECT f.turn_id,f.response_key,f.revision,f.reaction,f.created_at FROM portal_westy_reply_feedback f
        JOIN (SELECT turn_id,response_key,MAX(revision) AS revision FROM portal_westy_reply_feedback
          WHERE tenant_id=? AND client_id=? AND scope_key=? AND turn_id IN ('.$marks.') GROUP BY turn_id,response_key) latest
        ON f.turn_id=latest.turn_id AND f.response_key=latest.response_key AND f.revision=latest.revision');
    $q->execute([$scope['tenant'],$scope['client'],$scope['key'],...$ids]);$latest=[];
    foreach($q->fetchAll() as $row)$latest[(int)$row['turn_id']][$row['response_key']]=$row;
    foreach ($turns as &$turn) {
        $snapshot=portal_westy_feedback_snapshot($turn,$attempts[(int)$turn['id']]??[]);
        $row=$snapshot===null?null:($latest[(int)$turn['id']][$snapshot['response_id']]??null);
        $turn['feedback']=$snapshot===null?null:['response_id'=>$snapshot['response_id'],'revision'=>$row?(int)$row['revision']:0,
            'reaction'=>$row?$row['reaction']:'none','reacted_at'=>$row?$row['created_at']:null];
    }
    unset($turn);
    return $turns;
}

function portal_westy_feedback_record(PDO $pdo, array $context, array $request): array
{
    if (!portal_devices_keys($request,['conversation','operation','response_id','request_id','revision','reaction'])
        || !is_string($request['response_id']) || preg_match('/\A[a-f0-9]{64}\z/D',$request['response_id'])!==1
        || !is_int($request['revision']) || $request['revision']<0
        || !in_array($request['reaction'],['up','down','none'],true)) throw new PortalWestyException('invalid_request',400);
    $conversation=portal_westy_key($request['conversation']);$operation=portal_westy_key($request['operation']);
    $requestId=portal_westy_key($request['request_id']);
    if (!portal_westy_config()['enabled'] || !portal_westy_feedback_installed($pdo)) throw new PortalWestyException('feedback_unavailable',503);
    $pdo->beginTransaction();
    try {
        $s=portal_westy_scope($pdo,$context,true);
        // The shared scope helper can establish a REPEATABLE READ snapshot before
        // waiting for this binding. Check its active state with a current read too.
        $q=$pdo->prepare("SELECT id FROM customer_portal_bindings WHERE tenant_id=? AND client_id=? AND id=? AND status='active'".portal_westy_lock($pdo));
        $q->execute([$s['tenant'],$s['client'],$s['binding']]);
        if(!$q->fetchColumn())throw new PortalWestyException('sign_in',401);
        // Match the writer/maintenance lock order. A different active tab does not
        // revoke an otherwise retained, owned conversation's feedback authority.
        $q=$pdo->prepare('SELECT scope_key FROM portal_westy_accounts WHERE tenant_id=? AND client_id=? AND binding_id=? AND scope_key=?'.portal_westy_lock($pdo));
        $q->execute([$s['tenant'],$s['client'],$s['binding'],$s['key']]);
        if (!$q->fetchColumn()) throw new PortalWestyException('feedback_changed');
        $q=$pdo->prepare('SELECT * FROM portal_westy_turns WHERE tenant_id=? AND client_id=? AND scope_key=? AND conversation_key=? AND operation_key=?'.portal_westy_lock($pdo));
        $q->execute([$s['tenant'],$s['client'],$s['key'],$conversation,$operation]);$turn=$q->fetch();
        if (!$turn) throw new PortalWestyException('feedback_changed');
        // Current reads are required after a lock wait: authority checks may have
        // already established an older REPEATABLE READ snapshot in this transaction.
        $attempts=portal_westy_feedback_attempts($pdo,$s,[(int)$turn['id']],true);
        $snapshot=portal_westy_feedback_snapshot($turn,$attempts[(int)$turn['id']]??[]);
        if ($snapshot===null || !hash_equals($snapshot['response_id'],$request['response_id'])) throw new PortalWestyException('feedback_changed');
        $current=portal_westy_feedback_latest($pdo,$s,(int)$turn['id'],$snapshot['response_id'],true);
        $q=$pdo->prepare('SELECT turn_id,response_key,revision,reaction FROM portal_westy_reply_feedback WHERE scope_key=? AND request_key=?'.portal_westy_lock($pdo));
        $q->execute([$s['key'],$requestId]);$replay=$q->fetch();
        if ($replay) {
            if ((int)$replay['turn_id']!==(int)$turn['id'] || $replay['response_key']!==$request['response_id']
                || (int)$replay['revision']!==$request['revision']+1 || $replay['reaction']!==$request['reaction']) throw new PortalWestyException('feedback_changed');
            $pdo->commit();return $current;
        }
        if ($current['revision']!==$request['revision']) throw new PortalWestyException('feedback_changed');
        $q=$pdo->prepare('INSERT INTO portal_westy_reply_feedback
            (turn_id,tenant_id,client_id,binding_id,scope_key,actor_subject,conversation_key,operation_key,response_key,request_key,revision,reaction,context_json,created_at,expires_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $now=gmdate('Y-m-d H:i:s');
        $q->execute([(int)$turn['id'],$s['tenant'],$s['client'],$s['binding'],$s['key'],$context['identity']['subject'],
            $conversation,$operation,$snapshot['response_id'],$requestId,$current['revision']+1,$request['reaction'],
            json_encode($snapshot['snapshot'],JSON_THROW_ON_ERROR),$now,$turn['expires_at']]);
        $pdo->commit();
        return ['response_id'=>$snapshot['response_id'],'revision'=>$current['revision']+1,'reaction'=>$request['reaction'],'reacted_at'=>$now];
    } catch (Throwable $error) { if($pdo->inTransaction())$pdo->rollBack();throw $error; }
}

// Read seam only. Capture, immutable snapshots and reaction history above are unchanged.
const PORTAL_FEEDBACK_REVIEW_CONTRACT = 'westy-feedback-review-v1';
const PORTAL_FEEDBACK_SCOPE_CONTRACT = 'westy-feedback-scope-v1';
const PORTAL_FEEDBACK_SCOPE_ENDPOINT = 'https://support.8westit.com/westy_feedback_scope.php';
const PORTAL_FEEDBACK_REVIEW_MAX_BYTES = 8388608;

final class PortalFeedbackReviewException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 503)
    { parent::__construct($reason); }
}

function portal_feedback_review_keys(array $value, array $keys): bool
{
    $actual = array_keys($value); sort($actual); sort($keys);
    return $actual === $keys;
}

/** Closed, bounded filter wire. Calendar dates refer to the latest reaction in UTC. */
function portal_feedback_review_request(array $request): array
{
    $request += ['action'=>'list'];
    if (!portal_feedback_review_keys($request,['contract','action','filters','sort','page','page_size'])
        || $request['contract'] !== PORTAL_FEEDBACK_REVIEW_CONTRACT
        || !in_array($request['action'],['list','export'],true)
        || !is_array($request['filters'])
        || !portal_feedback_review_keys($request['filters'],['model','model_version','task','outcome','reaction','from','to'])
        || !in_array($request['sort'],['date_desc','date_asc','model_asc','reaction_asc','task_asc','outcome_asc'],true)
        || !is_int($request['page']) || $request['page'] < 1 || $request['page'] > 1000
        || !is_int($request['page_size']) || $request['page_size'] < 1
        || $request['page_size'] > ($request['action']==='export' ? 1000 : 50)
        || ($request['action']==='export' && $request['page']!==1)) {
        throw new PortalFeedbackReviewException('invalid_request',400);
    }
    foreach ($request['filters'] as $key=>$value) {
        if (!is_string($value) || strlen($value)>64 || preg_match('/[\x00-\x1f\x7f]/',$value))
            throw new PortalFeedbackReviewException('invalid_request',400);
        if (in_array($key,['from','to'],true) && $value!=='') {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d')!==$value)
                throw new PortalFeedbackReviewException('invalid_request',400);
        }
    }
    $f=$request['filters'];
    if (!in_array($f['model_version'],['','unknown'],true)
        || !in_array($f['task'],['','saved_portal_turn'],true)
        || !in_array($f['outcome'],['','complete','unavailable'],true)
        || !in_array($f['reaction'],['','up','down','none'],true)
        || ($f['from']!=='' && $f['to']!=='' && $f['from']>$f['to']))
        throw new PortalFeedbackReviewException('invalid_request',400);
    return $request;
}

/** Fresh bearer admission, using existing issuer/MFA/feed policy; never provisions a session. */
function portal_feedback_review_actor(PDO $pdo, string $token, ?callable $feed=null): array
{
    require_once __DIR__.'/jwt.php';
    require_once __DIR__.'/suite_roles.php';
    require_once __DIR__.'/suite_revocation_policy.php';
    if (strlen($token)>16384 || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D',$token)!==1)
        throw new PortalFeedbackReviewException('sign_in',401);
    [$claims]=jwt_verify_suite_reason($token,(array)cfg('suite',[]));
    if ($claims===null) throw new PortalFeedbackReviewException('sign_in',401);
    $subject=$claims['sub']??null; $version=$claims['8west:session_version']??null;
    if (!suite_revocation_subject_valid($subject)
        || (array_key_exists('8west:session_version',$claims) && !suite_session_version_valid($version))
        || (array_key_exists('amr',$claims) && !suite_amr_valid($claims['amr']))
        || !suite_products_valid($claims['8west:products']??null)
        || !in_array('safeharbor',$claims['8west:products'],true))
        throw new PortalFeedbackReviewException('sign_in',401);
    $slug=is_string($claims['8west:tenant']??null) ? mb_strtolower(trim($claims['8west:tenant'])) : '';
    $role=is_string($claims['8west:role']??null) ? safeharbor_suite_local_role($claims['8west:role'],$slug) : null;
    if ($slug==='' || $role===null) throw new PortalFeedbackReviewException('not_allowed',403);
    $mode=suite_mfa_policy_mode(cfg('suite.mfa_policy_mode','report'));
    if ($mode==='enforce' && !suite_mfa_policy_evaluate($claims,time(),(int)cfg('suite.mfa_max_age',SUITE_MFA_POLICY_MAX_AGE))['compliant'])
        throw new PortalFeedbackReviewException('sign_in',401);
    if ($feed===null) { require_once __DIR__.'/revocation.php'; $snapshot=revocation_list(); }
    else $snapshot=$feed();
    if ($snapshot===null) throw new PortalFeedbackReviewException('authorization_unavailable');
    if (suite_revocation_decision($snapshot,$subject,$version)['action']!=='allow')
        throw new PortalFeedbackReviewException('sign_in',401);
    $q=$pdo->prepare('SELECT u.id,u.tenant_id,u.role,t.slug FROM users u JOIN tenants t ON t.id=u.tenant_id
        WHERE u.suite_subject=? AND u.is_active=1 LIMIT 2');
    $q->execute([$subject]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1 || $rows[0]['slug']!==$slug || $rows[0]['role']!==$role)
        throw new PortalFeedbackReviewException('not_allowed',403);
    return ['subject'=>$subject,'tenant_slug'=>$slug,'tenant_id'=>(int)$rows[0]['tenant_id'],
        'user_id'=>(int)$rows[0]['id'],'role'=>$role];
}

/** Forward the original verified bearer only to the fixed TLS origin, without redirects. */
function portal_feedback_review_scope(array $actor, string $token, ?callable $transport=null): array
{
    $body=json_encode(['contract'=>PORTAL_FEEDBACK_SCOPE_CONTRACT],JSON_THROW_ON_ERROR);
    if ($transport!==null) $response=$transport(PORTAL_FEEDBACK_SCOPE_ENDPOINT,$token,$body);
    else {
        $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>5,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer ".$token."\r\nConnection: close\r\n",
            'content'=>$body], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        $raw=@file_get_contents(PORTAL_FEEDBACK_SCOPE_ENDPOINT,false,$context,0,524289);
        $status=0;
        foreach ($http_response_header??[] as $line)
            if (preg_match('/^HTTP\/\S+\s+([0-9]{3})(?:\s|$)/i',$line,$match)) $status=(int)$match[1];
        $response=['status'=>$status,'body'=>$raw];
    }
    if (!is_array($response) || ($response['status']??null)!==200 || !is_string($response['body']??null)
        || strlen($response['body'])>524288) throw new PortalFeedbackReviewException('scope_unavailable');
    try { $scope=json_decode($response['body'],true,8,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new PortalFeedbackReviewException('scope_unavailable'); }
    if (!is_array($scope) || !portal_feedback_review_keys($scope,['ok','contract','subject','tenant_slug','customer_ids'])
        || $scope['ok']!==true || $scope['contract']!==PORTAL_FEEDBACK_SCOPE_CONTRACT
        || $scope['subject']!==$actor['subject'] || $scope['tenant_slug']!==$actor['tenant_slug']
        || !is_array($scope['customer_ids']) || !array_is_list($scope['customer_ids']) || count($scope['customer_ids'])>10000)
        throw new PortalFeedbackReviewException('scope_unavailable');
    $ids=[];
    foreach ($scope['customer_ids'] as $id) {
        if (!is_string($id) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$id)!==1 || isset($ids[$id]))
            throw new PortalFeedbackReviewException('scope_unavailable');
        $ids[$id]=true;
    }
    return array_keys($ids);
}

/** Actual Milepost visibility intersected with current local customer lifecycle and binding. */
function portal_feedback_review_clients(PDO $pdo, array $actor, array $customerIds): array
{
    require_once __DIR__.'/managed_customer_status.php';
    if (!$customerIds) return [];
    $marks=implode(',',array_fill(0,count($customerIds),'?'));
    $q=$pdo->prepare("SELECT s.customer_id,s.client_id,b.id AS binding_id FROM suite_customer_sync_bindings s
        JOIN customer_portal_bindings b ON b.tenant_id=s.tenant_id AND b.client_id=s.client_id AND b.status='active'
        WHERE s.tenant_id=? AND s.status='active' AND s.customer_id IN ($marks) ORDER BY s.customer_id");
    $q->execute([$actor['tenant_id'],...$customerIds]); $clients=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row)
        if (managed_customer_operational($pdo,$actor['tenant_id'],(int)$row['client_id']))
            $clients[(int)$row['client_id']]=['customer_id'=>$row['customer_id'],'binding_id'=>(int)$row['binding_id']];
    return $clients;
}

function portal_feedback_review_ascii(mixed $value, int $max): ?string
{
    return is_string($value) && $value!=='' && strlen($value)<=$max && preg_match('/\A[\x20-\x7e]+\z/D',$value)===1 ? $value : null;
}

/** Project stored receipt metadata only. No operator private-conversation authority is inferred. */
function portal_feedback_review_item(array $row, array $client): array
{
    $provider=portal_feedback_review_ascii($row['provider'],16);
    $model=portal_feedback_review_ascii($row['model'],64);
    $catalog=portal_feedback_review_ascii($row['catalog_version'],32);
    $revision=filter_var($row['ai_revision'],FILTER_VALIDATE_INT);
    if ($revision===false || $revision<1 || $revision>9007199254740991) $revision=null;
    $kind=portal_feedback_review_ascii($row['context_kind'],32)??'unknown';
    $outcome=in_array($row['outcome'],['complete','unavailable'],true)?$row['outcome']:'unavailable';
    $reason=portal_feedback_review_ascii($row['reason_code'],40)??'';
    $iso=static fn(string $date):string=>str_replace(' ','T',$date).'Z';
    return ['id'=>(string)$row['id'],'response_id'=>$row['response_key'],'revision'=>(int)$row['revision'],
        'reaction'=>$row['reaction'],'created_at'=>$iso($row['first_at']),'reacted_at'=>$iso($row['created_at']),
        'expires_at'=>$iso($row['expires_at']),'provider'=>$provider,'model'=>$model,'model_version'=>null,
        'catalog_version'=>$catalog,'ai_revision'=>$revision,'task_kind'=>$kind,'outcome'=>$outcome,'reason_code'=>$reason,
        'context_kind'=>$kind,'conversation'=>$row['conversation_key'],'operation'=>$row['operation_key'],
        'customer_id'=>$client['customer_id'],
        'context_summary'=>'Recorded '.$kind.'; outcome '.$outcome.($reason!==''?' ('.$reason.')':'').'; provider '.($provider??'unknown')
            .'; model '.($model??'unknown').'; provider build unknown; catalog '.($catalog??'unknown').'; AI revision '.($revision??'unknown').'.',
        'context_available'=>false,'context_unavailable_reason'=>'no_existing_conversation_access',
        'context_redacted'=>false,'context_truncated'=>false];
}

/** A single bounded latest-reaction query for list or export; historical snapshots stay immutable. */
function portal_feedback_review_rows(PDO $pdo, array $actor, array $clients, array $request): array
{
    if (!$clients) return [];
    $marks=implode(',',array_fill(0,count($clients),'?'));
    $last="CONCAT('$.attempts[',GREATEST(JSON_LENGTH(JSON_EXTRACT(f.context_json,'$.attempts'))-1,0),']')";
    $field=static fn(string $path):string=>"NULLIF(JSON_UNQUOTE(JSON_EXTRACT(f.context_json,$path)),'null')";
    $provider=$field("CONCAT($last,'.provider')");
    $model='COALESCE('.$field("CONCAT($last,'.model_name')").','.$field("'$.turn_recorded_model'").')';
    $catalog=$field("CONCAT($last,'.catalog_version')"); $aiRevision=$field("CONCAT($last,'.ai_revision')");
    $kind=$field("'$.context_kind'"); $outcome=$field("'$.turn_state'"); $reason=$field("'$.reason_code'");
    $sql="SELECT r.* FROM (SELECT f.id,f.turn_id,f.client_id,f.binding_id,f.scope_key,f.response_key,f.revision,f.reaction,
        f.conversation_key,f.operation_key,f.created_at,f.expires_at,
        (SELECT MIN(h.created_at) FROM portal_westy_reply_feedback h WHERE h.turn_id=f.turn_id AND h.response_key=f.response_key
          AND h.tenant_id=f.tenant_id AND h.client_id=f.client_id AND h.scope_key=f.scope_key) AS first_at,
        $provider AS provider,$model AS model,$catalog AS catalog_version,$aiRevision AS ai_revision,
        $kind AS context_kind,$outcome AS outcome,$reason AS reason_code
        FROM portal_westy_reply_feedback f JOIN portal_westy_turns t ON t.id=f.turn_id AND t.tenant_id=f.tenant_id
          AND t.client_id=f.client_id AND t.scope_key=f.scope_key
        JOIN customer_portal_bindings b ON b.id=f.binding_id AND b.tenant_id=f.tenant_id AND b.client_id=f.client_id AND b.status='active'
        WHERE f.tenant_id=? AND f.client_id IN ($marks) AND f.expires_at>? AND t.expires_at>?
          AND t.input_text IS NOT NULL AND t.reply_json IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM portal_westy_reply_feedback newer WHERE newer.turn_id=f.turn_id
            AND newer.tenant_id=f.tenant_id AND newer.client_id=f.client_id AND newer.scope_key=f.scope_key
            AND newer.response_key=f.response_key AND newer.revision>f.revision)) r WHERE 1=1";
    $now=gmdate('Y-m-d H:i:s'); $args=[$actor['tenant_id'],...array_keys($clients),$now,$now];
    foreach (['model'=>'model','task'=>'context_kind','outcome'=>'outcome','reaction'=>'reaction'] as $key=>$column)
        if ($request['filters'][$key]!=='') { $sql.=" AND r.$column=?"; $args[]=$request['filters'][$key]; }
    if ($request['filters']['from']!=='') { $sql.=' AND r.created_at>=?'; $args[]=$request['filters']['from'].' 00:00:00'; }
    if ($request['filters']['to']!=='') {
        $sql.=' AND r.created_at<?'; $args[]=(new DateTimeImmutable($request['filters']['to'],new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
    }
    $order=match($request['sort']) { 'date_asc'=>'created_at ASC,id ASC','model_asc'=>'model ASC,id ASC',
        'reaction_asc'=>'reaction ASC,id ASC','task_asc'=>'context_kind ASC,id ASC','outcome_asc'=>'outcome ASC,id ASC',default=>'created_at DESC,id DESC' };
    $limit=$request['page_size']+1; $offset=($request['page']-1)*$request['page_size'];
    $q=$pdo->prepare($sql.' ORDER BY r.'.$order.' LIMIT '.$limit.' OFFSET '.$offset);
    $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Recheck current actor, remote scope, local lifecycle, retention and erasure before emitting. */
function portal_feedback_review(PDO $pdo, string $token, array $request, ?callable $transport=null, ?callable $feed=null): array
{
    $request=portal_feedback_review_request($request);
    $actor=portal_feedback_review_actor($pdo,$token,$feed);
    $clients=portal_feedback_review_clients($pdo,$actor,portal_feedback_review_scope($actor,$token,$transport));
    $rows=portal_feedback_review_rows($pdo,$actor,$clients,$request);
    $current=portal_feedback_review_actor($pdo,$token,$feed);
    $currentClients=portal_feedback_review_clients($pdo,$current,portal_feedback_review_scope($current,$token,$transport));
    if ($actor!==$current || $clients!==$currentClients) throw new PortalFeedbackReviewException('scope_changed',409);
    if ($rows) {
        $marks=implode(',',array_fill(0,count($rows),'?'));
        $q=$pdo->prepare("SELECT f.id FROM portal_westy_reply_feedback f JOIN portal_westy_turns t ON t.id=f.turn_id
            AND t.tenant_id=f.tenant_id AND t.client_id=f.client_id AND t.scope_key=f.scope_key
            JOIN customer_portal_bindings b ON b.id=f.binding_id AND b.tenant_id=f.tenant_id AND b.client_id=f.client_id AND b.status='active'
            WHERE f.tenant_id=? AND f.id IN ($marks) AND f.expires_at>? AND t.expires_at>?
              AND t.input_text IS NOT NULL AND t.reply_json IS NOT NULL");
        $now=gmdate('Y-m-d H:i:s'); $q->execute([$actor['tenant_id'],...array_column($rows,'id'),$now,$now]);
        if (count($q->fetchAll())!==count($rows)) throw new PortalFeedbackReviewException('feedback_changed',409);
    }
    if (portal_feedback_review_actor($pdo,$token,$feed)!==$actor) throw new PortalFeedbackReviewException('scope_changed',409);
    $more=count($rows)>$request['page_size']; $rows=array_slice($rows,0,$request['page_size']);
    $items=array_map(static fn(array $row):array=>portal_feedback_review_item($row,$clients[(int)$row['client_id']]),$rows);
    $result=['ok'=>true,'contract'=>PORTAL_FEEDBACK_REVIEW_CONTRACT,'tenant_slug'=>$actor['tenant_slug'],
        'page'=>$request['page'],'page_size'=>$request['page_size'],'has_more'=>$more,'row_count'=>count($items),'truncated'=>$more,'items'=>$items];
    if (strlen(json_encode($result,JSON_THROW_ON_ERROR))>PORTAL_FEEDBACK_REVIEW_MAX_BYTES)
        throw new PortalFeedbackReviewException('response_too_large');
    return $result;
}
