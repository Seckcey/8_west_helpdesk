<?php
declare(strict_types=1);
require_once __DIR__.'/../../../lib/bootstrap.php';
require_once __DIR__.'/../../../lib/westy_device_ownership.php';
header('Cache-Control: no-store');
if(cfg('westy_workflow.enabled',false)!==true)json_out(['ok'=>false,'error'=>'not found'],404);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_out(['ok'=>false,'error'=>'method not allowed'],405);
try{$settings=westy_workflow_settings((array)cfg('westy_workflow',[]));}
catch(RuntimeException){json_out(['ok'=>false,'error'=>'workflow unavailable'],503);}
$raw=(string)file_get_contents('php://input',false,null,0,16385);
if(!westy_device_ownership_authenticated($settings,['service'=>$_SERVER['HTTP_X_8W_SERVICE']??'','timestamp'=>$_SERVER['HTTP_X_8W_TIMESTAMP']??'','signature'=>$_SERVER['HTTP_X_8W_SIGNATURE']??''],$raw))json_out(['ok'=>false,'error'=>'unauthorized'],401);
try{json_out(westy_device_ownership_receive(db(),$settings,westy_device_ownership_request($raw)));}
catch(WestyWorkflowConflict $e){json_out(['ok'=>false,'error'=>$e->getMessage()],409);}
catch(InvalidArgumentException $e){json_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){json_out(['ok'=>false,'error'=>'ownership unavailable'],503);}
