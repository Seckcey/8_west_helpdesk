<?php
/** Private portal reactions. Never a staff defect ticket or provider export. */
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
