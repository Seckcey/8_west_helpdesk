<?php
/** Content-free maintenance results. No operator transcript viewer. */
declare(strict_types=1);

function portal_westy_maintain(PDO $pdo, bool $apply = false, ?array $scope = null): array
{
    $now=gmdate('Y-m-d H:i:s');$old=gmdate('Y-m-d H:i:s',time()-90*86400-60);
    $filter='';$args=[];
    if($scope!==null){
        if(!is_int($scope['tenant']??null)||$scope['tenant']<1||!is_int($scope['client']??null)||$scope['client']<1
            ||!is_string($scope['key']??null)||preg_match('/^[a-f0-9]{64}$/D',$scope['key'])!==1)throw new InvalidArgumentException('Invalid exact scope');
        $filter=' AND tenant_id=? AND client_id=? AND scope_key=?';$args=[$scope['tenant'],$scope['client'],$scope['key']];
    }
    $turnWhere=($scope===null?'expires_at<=?':'1=1').$filter.' AND (input_text IS NOT NULL OR reply_json IS NOT NULL)';
    $turnArgs=$scope===null?[$now]:$args;
    $draftWhere="state='draft' AND ".($scope===null?'expires_at<=?':'1=1').$filter;
    $draftArgs=$scope===null?[$now]:$args;
    $count=static function(string $table,string $where,array $parameters)use($pdo):int{
        $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$where);$q->execute($parameters);return (int)$q->fetchColumn();
    };
    $result=['applied'=>$apply,'turn_content'=>$count('portal_westy_turns',$turnWhere,$turnArgs),
        'draft_content'=>$count('portal_westy_drafts',$draftWhere,$draftArgs),'metadata_deleted'=>0];
    if(!$apply)return $result;
    // The accepted operation clock skew is at most 60 seconds; keep metadata for
    // that same grace so a future-stamped operation is expired before its row is purged.
    // One task-specific advisory lock; bounded metadata deletion batches.
    if((int)$pdo->query("SELECT GET_LOCK('safeharbor.portal_westy.maintenance',0)")->fetchColumn()!==1)throw new RuntimeException('Maintenance already running');
    try{
        $pdo->beginTransaction();
        if($scope!==null){
            $q=$pdo->prepare('SELECT scope_key FROM portal_westy_accounts WHERE tenant_id=? AND client_id=? AND scope_key=? FOR UPDATE');$q->execute($args);
            if(!$q->fetchColumn())throw new RuntimeException('Exact account not found');
            $q=$pdo->prepare('UPDATE portal_westy_accounts SET conversation_key=? WHERE tenant_id=? AND client_id=? AND scope_key=?');$q->execute([bin2hex(random_bytes(16)),...$args]);
        }
        $q=$pdo->prepare("UPDATE portal_westy_turns SET input_text=NULL,reply_json=NULL,state=IF(state='pending','unavailable',state),reason_code=IF(state='pending','expired',reason_code) WHERE ".$turnWhere);
        $q->execute($turnArgs);$result['turn_content']=$q->rowCount();
        require_once __DIR__.'/portal_westy_runs.php';
        if(portal_westy_runs_installed($pdo)){
            // Continuations have a shorter lifetime than the transcript; erasure
            // removes them in the same transaction and cannot revive a stopped run.
            $q=$pdo->prepare('DELETE FROM portal_westy_tool_runs WHERE '.($scope===null?'expires_at<=?':'1=1').$filter);
            $q->execute($scope===null?[$now]:$args);
        }
        $q=$pdo->prepare("UPDATE portal_westy_drafts SET state='expired',subject=NULL,body=NULL WHERE ".$draftWhere);
        $q->execute($draftArgs);$result['draft_content']=$q->rowCount();
        $hasAiReceipts=false;
        try{$pdo->query('SELECT id FROM portal_westy_ai_attempts LIMIT 0');$hasAiReceipts=true;}
        catch(PDOException $error){if(($error->errorInfo[1]??null)!==1146)throw $error;}
        if($hasAiReceipts){
            // The inference deadline is 150 seconds. Retain any still-live attempt;
            // after 180 seconds an erased/expired parent keeps its whole unknown hold.
            $attemptFilter=$scope===null?'':' AND a.tenant_id=? AND a.client_id=? AND a.scope_key=?';
            $q=$pdo->prepare("UPDATE portal_westy_ai_attempts a SET state='unavailable',usage_json=JSON_OBJECT('known',false,'rounds',JSON_ARRAY()),finished_at=? WHERE a.state='pending' AND a.created_at<?".$attemptFilter.
                " AND EXISTS(SELECT 1 FROM portal_westy_turns t WHERE t.id=a.turn_id AND t.state<>'pending' AND t.input_text IS NULL AND t.reply_json IS NULL)");
            $q->execute([$now,gmdate('Y-m-d H:i:s',time()-180),...$args]);
        }
        if($scope===null){
            // Immutable sent receipts and their parent account remain linked to tickets.
            $aiReceiptGuard='';
            if($hasAiReceipts){
                $q=$pdo->prepare("DELETE FROM portal_westy_ai_attempts WHERE state<>'pending' AND EXISTS(SELECT 1 FROM portal_westy_turns t WHERE t.id=portal_westy_ai_attempts.turn_id AND t.created_at<? AND t.expires_at<=? AND t.state<>'pending' AND t.input_text IS NULL AND t.reply_json IS NULL) LIMIT 1000");
                $q->execute([$old,$now]);$result['metadata_deleted']+=$q->rowCount();
                // Only a bounded deletion batch or a still-live attempt can retain a parent.
                $aiReceiptGuard=' AND NOT EXISTS(SELECT 1 FROM portal_westy_ai_attempts a WHERE a.turn_id=portal_westy_turns.id)';
            }
            foreach(['DELETE FROM portal_westy_turns WHERE created_at<? AND input_text IS NULL AND reply_json IS NULL'.$aiReceiptGuard.' LIMIT 1000',
                "DELETE FROM portal_westy_drafts WHERE created_at<? AND state='expired' AND subject IS NULL AND body IS NULL LIMIT 1000"] as $sql){
                $q=$pdo->prepare($sql);$q->execute([$old]);$result['metadata_deleted']+=$q->rowCount();
            }
            $q=$pdo->prepare('DELETE FROM portal_westy_budgets WHERE month_key<?');$q->execute([substr($old,0,7)]);
            $q=$pdo->prepare('DELETE FROM portal_westy_accounts WHERE created_at<? AND NOT EXISTS(SELECT 1 FROM portal_westy_turns t WHERE t.scope_key=portal_westy_accounts.scope_key) AND NOT EXISTS(SELECT 1 FROM portal_westy_drafts d WHERE d.scope_key=portal_westy_accounts.scope_key)');$q->execute([$old]);
        }
        $pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    finally{$pdo->query("SELECT RELEASE_LOCK('safeharbor.portal_westy.maintenance')");}
}
