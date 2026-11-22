<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_auth.php';
require_once __DIR__.'/../../lib/portal_westy.php';
require_once __DIR__.'/../../lib/portal_guide_votes.php';

enforce_https();
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
if(!portal_enabled())json_out(['ok'=>false,'reason'=>'not_found'],404);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');json_out(['ok'=>false,'reason'=>'method'],405);}
try {
    $context=portal_authenticated_context(db());
    if($context===null)json_out(['ok'=>false,'reason'=>'sign_in'],401);
    if(!portal_csrf_valid($_SERVER['HTTP_X_PORTAL_CSRF']??null))json_out(['ok'=>false,'reason'=>'sign_in'],403);
    if(portal_guide_audience($context)==='legacy')json_out(['ok'=>false,'reason'=>'not_found'],404);
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')json_out(['ok'=>false,'reason'=>'invalid_request'],415);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>256)json_out(['ok'=>false,'reason'=>'invalid_request'],413);
    $raw=file_get_contents('php://input',false,null,0,257);
    if(!is_string($raw)||strlen($raw)>256)json_out(['ok'=>false,'reason'=>'invalid_request'],413);
    $request=json_decode($raw,false,8,JSON_THROW_ON_ERROR);
    if(!$request instanceof stdClass)json_out(['ok'=>false,'reason'=>'invalid_request'],400);
    $vote=portal_guide_vote_record(db(),$context,get_object_vars($request));
    $fresh=portal_authenticated_context(db());
    if($fresh===null||!portal_desktop_same_context($context,$fresh))json_out(['ok'=>false,'reason'=>'sign_in'],401);
    session_write_close();
    json_out(['ok'=>true,'vote'=>$vote]);
} catch(PortalWestyException $error){json_out(['ok'=>false,'reason'=>$error->reason],$error->status);}
catch(PortalIdentityUnavailableException){json_out(['ok'=>false,'reason'=>'identity_unavailable'],503);}
catch(JsonException){json_out(['ok'=>false,'reason'=>'invalid_request'],400);}
catch(Throwable){json_out(['ok'=>false,'reason'=>'feedback_unavailable'],503);}
