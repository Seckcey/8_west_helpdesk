<?php
/** Invoked by the root-owned ID signup worker, never routed through HTTP. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
try {
    if ($argc!==2 || $argv[1]!=='create') throw new RuntimeException('signup_portal_input');
    require_once __DIR__.'/../lib/bootstrap.php';
    require_once __DIR__.'/../lib/customer_signup_portal.php';
    $config=cfg('customer_signup_portal',[]);
    if (($config['enabled']??null)!==true || !is_int($config['actor_user_id']??null)
        || !is_string($config['database_config_path']??null) || !is_string($config['identity_database_config_path']??null)
        || !is_string($config['identity_root']??null) || !str_starts_with($config['identity_root'],'/')) {
        throw new RuntimeException('signup_portal_disabled');
    }
    $raw=stream_get_contents(STDIN,4097);$input=json_decode($raw,true,4,JSON_THROW_ON_ERROR);
    if (strlen($raw)>4096 || !is_array($input) || array_keys($input)!==['signup_id'] || !is_string($input['signup_id'])) throw new RuntimeException('signup_portal_input');
    $idRoot=realpath($config['identity_root']);
    if (!is_string($idRoot) || !is_file($idRoot.'/lib/customer_signup_portal_evidence.php')) throw new RuntimeException('signup_portal_identity_unavailable');
    require_once $idRoot.'/lib/customer_signup_portal_evidence.php';
    $identityConfig=(static fn(string $file):mixed=>require $file)($idRoot.'/config/config.php');
    if (!is_array($identityConfig['db']??null)) throw new RuntimeException('signup_portal_identity_unavailable');
    $reader=customer_signup_portal_database($config['identity_database_config_path'],'ewid_signup_portal_reader',$idRoot,$identityConfig['db']);
    $writer=customer_signup_portal_database($config['database_config_path'],'safeharbor_signup_worker',dirname(__DIR__),cfg('db',[]));
    $result=customer_signup_portal_apply($writer,$input['signup_id'],$config['actor_user_id'],
        static fn(string $id):array=>customer_signup_portal_evidence($reader,$id));
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) { fwrite(STDERR,"{\"error\":\"signup_portal_unavailable\"}\n");exit(1); }
