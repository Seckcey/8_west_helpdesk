<?php
/** Private, bounded continuations. A receipt can resume inference, never replay execution. */
declare(strict_types=1);
require_once __DIR__.'/portal_westy_shell.php';
require_once __DIR__.'/portal_westy_terminal.php';
require_once __DIR__.'/portal_westy_control.php';

function portal_westy_runs_installed(PDO $pdo):bool
{
    try{$pdo->query('SELECT turn_id FROM portal_westy_tool_runs LIMIT 0');return true;}
    catch(PDOException $error){
        if(($error->errorInfo[1]??null)===1146||($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&str_contains($error->getMessage(),'no such table')))return false;
        throw $error;
    }
}
function portal_westy_run_origin():array
{
    $companion=$_SESSION['desktop_companion_session']??null;
    return ['origin_channel'=>portal_desktop_id($companion)?'companion':'portal',
        'origin_session_hash'=>hash('sha256',session_id()),'companion_session'=>portal_desktop_id($companion)?$companion:null];
}
function portal_westy_run_origin_matches(array $run):bool
{
    foreach(portal_westy_run_origin() as $key=>$value)if($run[$key]!==$value)return false;
    return true;
}
function portal_westy_run_matches(array $run):bool
{
    return portal_westy_run_origin_matches($run)&&strtotime($run['expires_at'].' UTC')>time();
}
function portal_westy_run_find(PDO $pdo,array $scope,string $operation,bool $lock=false):?array
{
    if(!portal_westy_runs_installed($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM portal_westy_tool_runs WHERE scope_key=? AND tenant_id=? AND client_id=? AND operation_key=?'.($lock?portal_westy_lock($pdo):''));
    $q->execute([$scope['key'],$scope['tenant'],$scope['client'],$operation]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function portal_westy_run_create(PDO $pdo,array $scope,int $turn,string $conversation,string $operation):array
{
    if(!$pdo->inTransaction())throw new LogicException('run requires turn lock');
    $origin=portal_westy_run_origin();
    $q=$pdo->prepare("INSERT INTO portal_westy_tool_runs(turn_id,tenant_id,client_id,scope_key,conversation_id,operation_key,origin_channel,origin_session_hash,companion_session,state,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,'running',?,?)");
    $q->execute([$turn,$scope['tenant'],$scope['client'],$scope['key'],$conversation,$operation,...array_values($origin),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+1800)]);
    return portal_westy_run_find($pdo,$scope,$operation,true);
}

/** Images/UIA remain transient across a durable wait boundary, except one bounded text
 * summary (8,000 bytes) of the newest Cua screen reading, so a resumed computer task is
 * not blind. Screenshots never survive, older readings expire, and the replay itself is
 * cleared when the run resumes, ends, stops or expires. */
/** One content-free line naming what filled a conversation that hit the size limit. */
function portal_westy_context_diagnostic(string $where,int $bytes,array $messages,int $base=0):void
{
    try{
        $kinds=['provider'=>0,'command_output'=>0,'screen'=>0,'other_tool'=>0,'text'=>0];
        foreach($messages as $message){
            $size=strlen((string)json_encode($message,JSON_INVALID_UTF8_SUBSTITUTE));$text=json_encode($message['content']??'',JSON_INVALID_UTF8_SUBSTITUTE);
            $kind=match(true){
                ($message['role']??'')==='provider'=>'provider',
                ($message['role']??'')!=='tool'=>'text',
                str_contains((string)$text,'\\"progress\\"')=>'command_output',
                str_contains((string)$text,'untrusted_observation')||str_contains((string)$text,'last_screen')=>'screen',
                default=>'other_tool'};
            $kinds[$kind]+=$size;
        }
        error_log(json_encode(['event'=>'westy_context_limit','where'=>$where,'bytes'=>$bytes,'base_bytes'=>$base,'messages'=>count($messages)]+$kinds,JSON_THROW_ON_ERROR));
    }catch(Throwable){/* Logging cannot change the limit's outcome. */}
}

function portal_westy_run_replay(array $messages):string
{
    $summaryBytes=8000;
    // Which tool results answer a Cua screen reading, and which of them is the newest.
    // Acknowledgements of clicks or typing are not screen readings.
    $cuaCalls=[];
    foreach($messages as $message)if(($message['role']??'')==='provider')foreach($message['output']??[] as $item){
        $item=is_object($item)?get_object_vars($item):$item;
        $id=is_array($item)?($item['call_id']??$item['id']??null):null;
        if(is_string($id)&&in_array($item['name']??null,['cua_window_state','cua_browser_state','cua_list_windows'],true))$cuaCalls[$id]=true;
    }
    $newest=null;
    foreach($messages as $mi=>$message){
        if(($message['role']??'')!=='tool'||!isset($cuaCalls[$message['call_id']??'']))continue;
        foreach($message['content']??[] as $pi=>$part){
            $text=is_string($part['text']??null)?$part['text']:'';
            $envelope=str_contains($text,'"untrusted_observation"')||str_contains($text,'"last_screen"')?json_decode($text,true):null;
            if(is_array($envelope)&&is_array($envelope['result']??null)
                &&(is_array($envelope['untrusted_observation']??null)||is_string($envelope['last_screen']??null)))$newest=[$mi,$pi];
        }
    }
    // Command output: the newest receipt keeps its whole output. Older receipts keep
    // everything except the start of long output, so long tasks still fit after a pause.
    $isOutput=static fn(mixed $data):bool=>is_array($data)&&is_array($data['untrusted_result']['progress']??null)
        &&(is_string($data['untrusted_result']['progress']['stdout']??null)||is_string($data['untrusted_result']['progress']['stderr']??null));
    $newestOutput=null;
    foreach($messages as $mi=>$message){
        if(($message['role']??'')!=='tool')continue;
        foreach($message['content']??[] as $pi=>$part)
            if(is_string($part['text']??null)&&str_contains($part['text'],'"untrusted_result"')&&$isOutput(json_decode($part['text'],true)))$newestOutput=[$mi,$pi];
    }
    // A computer-tool call's arguments can hold typed text. Its replay keeps the call's
    // identity for the receipt and the window or tab it addressed, never what it asked
    // for. Without those identifiers a resumed model repeats calls that name no window.
    // Browser ids are kept only in the driver's minted shapes, bt-<uuid> and tab-<uuid>.
    $target=static function(mixed $arguments):stdClass{
        $arguments=is_string($arguments)?json_decode($arguments,true):(is_object($arguments)?get_object_vars($arguments):$arguments);
        $kept=[];
        foreach(['pid','window_id'] as $key)if(is_int($arguments[$key]??null)&&$arguments[$key]>0)$kept[$key]=$arguments[$key];
        foreach(['target_id'=>'bt','tab_id'=>'tab'] as $key=>$prefix)
            if(is_string($arguments[$key]??null)&&preg_match('/\A'.$prefix.'-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',$arguments[$key])===1)
                $kept[$key]=$arguments[$key];
        return (object)$kept;
    };
    foreach($messages as $mi=>&$message){
        if(($message['role']??'')==='provider'){
            foreach($message['output']??[] as $index=>$item){
                $item=is_object($item)?get_object_vars($item):$item;
                if(!is_array($item)||!is_string($item['name']??null)
                    ||(!str_starts_with($item['name'],'cua_')&&$item['name']!=='desktop_cua'))continue;
                if(($item['type']??null)==='function_call')$item['arguments']=json_encode($target($item['arguments']??null),JSON_THROW_ON_ERROR);
                elseif(($item['type']??null)==='tool_use')$item['input']=$target($item['input']??null);
                else continue;
                $message['output'][$index]=$item;
            }
            continue;
        }
        if(($message['role']??'')!=='tool')continue;
        $parts=[];
        foreach($message['content']??[] as $pi=>$part){
            if(($part['type']??'')!=='text')continue;
            if($newestOutput!==[$mi,$pi]&&str_contains($part['text'],'"untrusted_result"')){
                $data=json_decode($part['text'],true);
                if($isOutput($data)){
                    foreach(['stdout'=>2000,'stderr'=>500] as $field=>$limit){
                        $output=$data['untrusted_result']['progress'][$field]??null;
                        if(is_string($output)&&strlen($output)>$limit){
                            $data['untrusted_result']['progress'][$field]=mb_strcut($output,-$limit,null,'UTF-8');
                            $data['untrusted_result']['progress']['shortened']=true;
                        }
                    }
                    if(($data['untrusted_result']['progress']['shortened']??false)===true)
                        $data['untrusted_result']['guidance']='Earlier output: only its end was kept when the reply paused. Run the command again, or read the file you saved, if you need the rest.';
                    $parts[]=['type'=>'text','text'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)];
                    continue;
                }
            }
            $kept=str_contains($part['text'],'"last_screen"')?json_decode($part['text'],true):null;
            if(is_array($kept)&&is_array($kept['result']??null)&&is_string($kept['last_screen']??null)){
                // A summary from an earlier pause stays only while it is still the newest reading.
                if($newest!==[$mi,$pi])$part=['type'=>'text','text'=>json_encode(['result'=>$kept['result'],'observation_status'=>'expired',
                    'guidance'=>'Expired, not failed: its screen contents were cleared when the reply paused. Observe again before you act or report. Preserve this receipt; do not repeat an executed or uncertain action.'],JSON_THROW_ON_ERROR)];
                $parts[]=$part;continue;
            }
            if(str_contains($part['text'],'"untrusted_observation"')){
                $envelope=json_decode($part['text'],true);
                if($newest===[$mi,$pi]&&is_array($envelope)&&is_array($envelope['untrusted_observation']??null)){
                    $screen=(string)json_encode($envelope['untrusted_observation'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
                    // The bound holds for the part as stored: the replay escapes it once more,
                    // so quote-, path- or non-Latin-heavy text keeps less of the screen.
                    for($limit=6000;;$limit=intdiv($limit*3,4)){
                        $clip=strlen($screen)>$limit?mb_strcut($screen,0,$limit,'UTF-8').'…[truncated]':$screen;
                        $text=json_encode(['result'=>$envelope['result'],'observation_status'=>'summarized','last_screen'=>$clip,
                            'guidance'=>'The screen as it was before the pause, kept as text so you can continue. If your next step only clicks or navigates with an element token or ref listed here, act on it directly; if the driver says it is stale, observe again. Observe first if the outcome of a later call is unknown or if the step types or replaces text. Report only what a current observation shows. Preserve this receipt; do not repeat an executed or uncertain action.'],
                            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                        if(strlen(json_encode($text,JSON_THROW_ON_ERROR))<=$summaryBytes||$limit<200)break;
                    }
                    $parts[]=['type'=>'text','text'=>$text];
                    continue;
                }
                // The public receipt is durable evidence of execution (or its
                // uncertainty). Only the attached screen contents expire.
                $part=['type'=>'text','text'=>is_array($envelope)&&is_array($envelope['result']??null)
                    &&array_key_exists('untrusted_observation',$envelope)
                    ?json_encode(['result'=>$envelope['result'],'observation_status'=>'expired',
                        'guidance'=>'Expired, not failed: its screen contents were cleared when the reply paused. Observe again before you act or report. Preserve this receipt; do not repeat an executed or uncertain action.'],JSON_THROW_ON_ERROR)
                    :'Desktop observation expired, not failed: its screen contents were cleared when the reply paused. Observe again before you act or report.'];
            }
            $parts[]=$part;
        }
        $message['content']=$parts?:[['type'=>'text','text'=>'Transient observation expired.']];
    }
    unset($message);
    $wire=json_encode($messages,JSON_THROW_ON_ERROR);
    if(strlen($wire)>131072){
        portal_westy_context_diagnostic('replay',strlen($wire),$messages);
        throw new PortalWestyException('context_limit');
    }
    return $wire;
}
function portal_westy_run_restore(string $wire):array
{
    $messages=json_decode($wire,true,64,JSON_THROW_ON_ERROR);
    $original=json_decode($wire,false,64,JSON_THROW_ON_ERROR);
    foreach($messages as $index=>&$message)if(($message['role']??null)==='provider')$message['output']=$original[$index]->output;
    unset($message);return $messages;
}
function portal_westy_run_wait(PDO $pdo,array $context,string $operation,array $pending,array $messages):void
{
    $scope=portal_westy_scope($pdo,$context);$replay=portal_westy_run_replay($messages);
    // Serialize with Stop and turn erasure. The intent is durable BEFORE network I/O.
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT state FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$scope['key'],$operation]);
        if($q->fetchColumn()!=='pending')throw new PortalWestyException('stopped');
        $run=portal_westy_run_find($pdo,$scope,$operation,true);
        if(!$run||!portal_westy_run_matches($run)||$run['state']!=='running'||(int)$run['sequence']>=20)throw new PortalWestyException('run_unavailable');
        $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state='waiting',sequence=sequence+1,pending_json=?,replay_json=? WHERE turn_id=? AND scope_key=? AND state='running'");
        $q->execute([json_encode($pending,JSON_THROW_ON_ERROR),$replay,$run['turn_id'],$scope['key']]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function portal_westy_run_result(array $context,array $run,?callable $transport=null):array
{
    $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);
    if(($pending['kind']??null)==='continuation')return ['ready'=>true,'receipt'=>['state'=>'ready','execution_requested'=>false]];
    if(isset($pending['terminal'])){
        $receipt=$pending['terminal'];
        // Only a saved queue refusal proves no execution. Never promote a result
        // fetched from a dispatched command, an unknown response or a lost reply.
        if(($pending['kind']??'shell')==='shell'&&($receipt['state']??null)==='unavailable'
            &&($receipt['reason']??null)==='invalid_pipeline')$receipt=portal_westy_shell_rejection();
        return ['ready'=>true,'receipt'=>$receipt];
    }
    try{
        if(($pending['kind']??'shell')==='terminal')return portal_westy_terminal_result($context,$run,$pending,$transport);
        if(($pending['kind']??null)==='control')return portal_westy_control_result($context,$pending,$transport);
        if(($pending['kind']??'shell')==='shell')$receipt=portal_westy_shell_receipt($context,$pending,$transport);
        else{
            $items=portal_devices_request(db(),$context,'operations',['device_reference'=>$pending['device_reference']],$transport)['items'];$receipt=null;
            foreach($items as $item)if(($item['reference']??null)===$pending['reference']){$receipt=portal_westy_tool_model_result($item);break;}
            if($receipt===null)return ['ready'=>true,'receipt'=>['state'=>'unknown','retry_allowed'=>false]];
        }
        $waiting=in_array($receipt['state']??'', ['queued','claimed','running','authorized','verifying','cancel_requested'],true);
        return ['ready'=>!$waiting,'receipt'=>$receipt];
    }catch(PortalDesktopException|PortalDevicesException $error){
        if($error->reason==='action_unavailable')return ['ready'=>true,'receipt'=>['state'=>'unknown','retry_allowed'=>false,'reason'=>'No execution receipt was found; the intent will not be sent again.']];
        return ['ready'=>false,'receipt'=>['state'=>'unavailable','reason'=>$error->reason,'retry_allowed'=>false]];
    }
}
/** Hold the same turn lock as Stop until the one queue request has returned. */
function portal_westy_run_dispatch(PDO $pdo,array $context,string $operation,array $input,?callable $transport=null):array
{
    $scope=portal_westy_scope($pdo,$context);$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT state FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$scope['key'],$operation]);
        if($q->fetchColumn()!=='pending')throw new PortalWestyException('stopped');
        $run=portal_westy_run_find($pdo,$scope,$operation,true);
        if(!$run||$run['state']!=='waiting'||!portal_westy_run_matches($run))throw new PortalWestyException('stopped');
        try{$receipt=portal_desktop_request($context,'shell_queue',$input,$transport);}
        catch(PortalDesktopException $error){
            $receipt=['state'=>$error->reason==='connection_unknown'?'unknown':'unavailable','reason'=>$error->reason,'retry_allowed'=>false];
            if($error->reason==='invalid_pipeline')$receipt=portal_westy_shell_rejection();
            // A lost queue response is reconciled by request key. It is never sent again.
            if($error->reason!=='connection_unknown'){
                $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);$pending['terminal']=$receipt;
                $q=$pdo->prepare('UPDATE portal_westy_tool_runs SET pending_json=? WHERE turn_id=? AND scope_key=?');$q->execute([json_encode($pending,JSON_THROW_ON_ERROR),$run['turn_id'],$scope['key']]);
            }
        }
        $pdo->commit();return $receipt;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function portal_westy_run_public(PDO $pdo,array $context,array $scope,string $operation,?callable $transport=null):?array
{
    $run=portal_westy_run_find($pdo,$scope,$operation);
    if(!$run||!portal_westy_run_matches($run)||$run['state']!=='waiting')return null;
    $result=portal_westy_run_result($context,$run,$transport);
    $public=['sequence'=>(int)$run['sequence'],'state'=>'waiting','ready'=>$result['ready'],'receipt'=>$result['receipt']];
    $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);
    if(($pending['kind']??null)==='terminal'&&($result['receipt']['state']??null)==='awaiting_approval')
        $public['approval']=['fingerprint'=>$result['receipt']['approval_fingerprint'],
            'command'=>$pending['input']['command']??null,'chars'=>$pending['input']['chars']??null,
            'reason'=>$result['receipt']['review']['reason']??$result['receipt']['reason']??'Review the exact action.'];
    return $public;
}
function portal_westy_run_end(PDO $pdo,array $scope,string $operation,string $state,array $context,?callable $transport=null):void
{
    if(!portal_westy_runs_installed($pdo))return;
    $run=portal_westy_run_find($pdo,$scope,$operation);
    $known=null;$confirmed=null;
    if($run){
        $pending=json_decode((string)$run['pending_json'],true);
        $prior=$context['tool_run']??null;
        if(($pending['kind']??null)!=='control'&&is_array($prior)&&$prior['operation_key']===$operation
            &&$prior['scope_key']===$scope['key']&&$prior['conversation_id']===$run['conversation_id'])
            $pending=json_decode((string)$prior['pending_json'],true);
        if(($pending['kind']??null)==='control'&&portal_desktop_id($pending['session_id']??null)&&portal_desktop_id($pending['task_id']??null))
            $known=array_intersect_key($pending,array_flip(['kind','session_id','task_id']));
        if($known!==null&&($pending['terminal']['state']??null)==='stopped'
            &&($pending['terminal']['session_id']??null)===$known['session_id']&&($pending['terminal']['task_id']??null)===$known['task_id'])
            $confirmed=$pending['terminal'];
        $desktop=$context['desktop']??null;
        if(is_array($desktop)&&($desktop['operation_key']??null)===$operation&&($desktop['conversation_id']??null)===$run['conversation_id']
            &&($desktop['origin_channel']??null)===$run['origin_channel']&&portal_desktop_id($desktop['session_id']??null)&&portal_desktop_id($desktop['task_id']??null))
            $known=['kind'=>'control','session_id'=>$desktop['session_id'],'task_id'=>$desktop['task_id']];
    }
    $retained=$known===null?null:json_encode($known+($confirmed===null?[]:['terminal'=>$confirmed]),JSON_THROW_ON_ERROR);
    // A dispatch can enter waiting before its paid attempt is finalized. If
    // finalization fails, Stop must also remove that waiting replay intent.
    $eligible=$state==='stopped'?"state IN ('running','waiting')":"state='running'";
    $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state=?,replay_json=NULL,pending_json=? WHERE scope_key=? AND operation_key=? AND $eligible");
    $q->execute([$state,$retained,$scope['key'],$operation]);
    // The reserved paid attempt and previously saved receipts remain durable,
    // including when final accounting failed. An unconfirmed stop retains its
    // binding, but cannot replay inference or the desktop action.
    if($run){
        $released=portal_westy_control_release($pdo,$context,$run,$transport);
        if(!$released&&$known!==null&&(($confirmed['session_id']??null)!==$known['session_id']||($confirmed['task_id']??null)!==$known['task_id']))
            throw new PortalWestyException('stop_unconfirmed');
        if($released&&$retained!==null)$pdo->prepare('UPDATE portal_westy_tool_runs SET pending_json=NULL WHERE turn_id=? AND scope_key=? AND pending_json=CAST(? AS JSON)')
            ->execute([$run['turn_id'],$scope['key'],$retained]);
    }
}
/** Admission has not spent a new attempt. Serialize with another resume/Stop and
 * close only the exact waiting sequence that failed; retain paid history/errors. */
function portal_westy_run_admission_failed(PDO $pdo,array $scope,array $expected,array $context,Throwable $error,?callable $transport=null):void
{
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT state FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));
        $q->execute([$scope['key'],$expected['operation_key']]);$turnState=$q->fetchColumn();
        $run=portal_westy_run_find($pdo,$scope,$expected['operation_key'],true);
        if(!$run||$turnState!=='complete'||$run['state']!=='waiting'||!portal_westy_run_origin_matches($run)
            ||(int)$run['turn_id']!==(int)$expected['turn_id']||(int)$run['sequence']!==(int)$expected['sequence']
            ||$run['conversation_id']!==$expected['conversation_id']){
            $pdo->commit();return;
        }
        $reason=$error instanceof PortalWestyException?$error->reason:'interrupted';
        $q=$pdo->prepare("UPDATE portal_westy_turns SET state='unavailable',reason_code=?,finished_at=? WHERE scope_key=? AND operation_key=? AND state='complete'");
        $q->execute([$reason,gmdate('Y-m-d H:i:s'),$scope['key'],$run['operation_key']]);
        // Keep the turn lock until exact cleanup is recorded. This bounded Stop
        // cannot race a second admission into a new paid attempt. Its failure is
        // committed as retained cleanup identity, never a false stop receipt.
        $context['tool_run']=$run;
        try{portal_westy_run_end($pdo,$scope,$run['operation_key'],'stopped',$context,$transport);}catch(Throwable){}
        $pdo->commit();
    }catch(Throwable $failure){if($pdo->inTransaction())$pdo->rollBack();throw $failure;}
}
function portal_westy_run_stop(PDO $pdo,array $context,array $scope,string $operation,?callable $transport=null):void
{
    if(!portal_westy_runs_installed($pdo))return;
    if(portal_westy_scope($pdo,$context)['key']!==$scope['key'])throw new PortalWestyException('sign_in',401);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$scope['key'],$operation]);
        $run=portal_westy_run_find($pdo,$scope,$operation,true);
        if(!$run||!portal_westy_run_origin_matches($run)){$pdo->commit();return;}
        $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state='stopped',replay_json=NULL WHERE scope_key=? AND operation_key=? AND state IN ('running','waiting','complete')");$q->execute([$scope['key'],$operation]);
        $q=$pdo->prepare("UPDATE portal_westy_turns SET state='unavailable',reason_code='stopped',finished_at=? WHERE scope_key=? AND operation_key=? AND state IN ('pending','complete')");$q->execute([gmdate('Y-m-d H:i:s'),$scope['key'],$operation]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $unconfirmed=false;$controlReleased=false;$controlUnconfirmed=false;
    try{$controlReleased=portal_westy_control_release($pdo,$context,$run,$transport);}
    catch(Throwable){$unconfirmed=$controlUnconfirmed=true;}
    if(portal_westy_terminal_installed($pdo))foreach(portal_westy_terminal_processes($run) as $handle=>$stored){
        $process=portal_westy_terminal_owned_process($run,$handle);
        try{portal_desktop_request($context,'terminal_cancel',array_intersect_key($process,array_flip(['run_id','conversation_id','origin_channel','action_id']))+['request_key'=>null],$transport);}
        catch(Throwable){$unconfirmed=true;}
    }
    if($run['pending_json']!==null){
        $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);
        if(($pending['kind']??'shell')==='shell'){
            try{portal_westy_shell_receipt($context,$pending,$transport,'shell_cancel');}
            catch(Throwable){throw new PortalWestyException('stop_unconfirmed');}
        }
        if(($pending['kind']??null)==='terminal'){
            try{portal_desktop_request($context,'terminal_cancel',portal_westy_terminal_identity($pending),$transport);}
            catch(PortalDesktopException $error){if($error->reason!=='action_unavailable')$unconfirmed=true;}
            catch(Throwable){$unconfirmed=true;}
        }
        if(($pending['kind']??null)==='control'&&$controlReleased){
            // Preserve the actual stopped identity for a concurrently finishing
            // response; absence of a binding alone is never confirmation.
            $pending['terminal']=$controlReleased;
            $pdo->prepare("UPDATE portal_westy_tool_runs SET pending_json=? WHERE turn_id=? AND scope_key=? AND state='stopped'")
                ->execute([json_encode($pending,JSON_THROW_ON_ERROR),$run['turn_id'],$scope['key']]);
        }elseif(($pending['kind']??null)==='control'&&!$controlUnconfirmed){
            $alreadyStopped=($pending['terminal']['state']??null)==='stopped'
                &&($pending['terminal']['session_id']??null)===($pending['session_id']??null)
                &&($pending['terminal']['task_id']??null)===($pending['task_id']??null);
            if(portal_desktop_id($pending['session_id']??null)&&portal_desktop_id($pending['task_id']??null)){
                // Losing or replacing a known binding is not a stop receipt.
                // Preserve its intent; never redirect cancellation to a new task.
                if(!$alreadyStopped)$unconfirmed=true;
            }else{
                // A lost prepare may have no saved task binding. Keep its original
                // cancellation-by-request-key recovery; never repeat preparation.
                try{portal_desktop_request($context,'control_cancel',$pending['input'],$transport);}
                catch(Throwable){$unconfirmed=true;}
            }
        }
    }
    if($unconfirmed)throw new PortalWestyException('stop_unconfirmed');
}
