<?php
/** Portal-only signup acceptance against real MySQL and the actual restricted principal. */
declare(strict_types=1);
function cfg(string $key,mixed $default=null):mixed {return $default;}
require_once __DIR__.'/../lib/customer_signup_portal.php';
require_once __DIR__.'/../lib/suite_customer_sync.php';
$dsn=getenv('CUSTOMER_SIGNUP_TEST_DSN');
if (!$dsn) { echo "signup portal: skipped (CUSTOMER_SIGNUP_TEST_DSN unset)\n";exit; }
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone='+00:00'"];
$root=new PDO($dsn,getenv('CUSTOMER_SIGNUP_TEST_USER')?:'root',getenv('CUSTOMER_SIGNUP_TEST_PASS')?:'root',$options);
$schema=$root->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($schema) || preg_match('/\Asignup_test[a-z0-9_]*\z/D',$schema)!==1) throw new RuntimeException('Disposable database required');
$delimiter=';';$buffer='';
foreach (explode("\n",str_replace("\r\n","\n",file_get_contents(__DIR__.'/../db/schema.sql'))) as $line) {
    if (preg_match('/^DELIMITER (.+)$/',trim($line),$match)) {$delimiter=$match[1];continue;}
    if ($buffer==='' && (trim($line)==='' || str_starts_with(trim($line),'--'))) continue;
    $buffer.=$line."\n";
    if (str_ends_with(rtrim($buffer),$delimiter)) {
        $q=$root->query(substr(rtrim($buffer),0,-strlen($delimiter)));$q->closeCursor();$buffer='';
    }
}
$checks=0;
function signup_portal_check(bool $pass,string $label):void {global $checks;if (!$pass) throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function signup_portal_refused(callable $action):bool {try {$action();return false;}catch(Throwable){return true;}}
$root->exec("INSERT INTO tenants (id,name,slug) VALUES (1,'8 West IT','8west'),(2,'Other provider','other')");
$root->exec("INSERT INTO users (id,tenant_id,email,password_hash,full_name,role) VALUES (1,1,'operator@example.invalid','unused','Fixture operator','owner'),(2,2,'other@example.invalid','unused','Other operator','owner')");
$root->exec("CREATE USER IF NOT EXISTS 'safeharbor_signup_worker'@'%' IDENTIFIED BY 'synthetic-signup-only'");
$root->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'safeharbor_signup_worker'@'%'");
foreach (['tenants','clients','suite_customer_sync_bindings','suite_customer_sync_events','customer_portal_bindings',
    'customer_portal_binding_events','managed_customer_lifecycle_restore_receipts','suite_managed_providers'] as $table) $root->exec("GRANT SELECT ON `$schema`.`$table` TO 'safeharbor_signup_worker'@'%'");
$root->exec("GRANT SELECT (id,tenant_id,is_active,role) ON `$schema`.users TO 'safeharbor_signup_worker'@'%'");
$root->exec("GRANT INSERT,UPDATE ON `$schema`.customer_portal_bindings TO 'safeharbor_signup_worker'@'%'");
$root->exec("GRANT LOCK TABLES ON `$schema`.* TO 'safeharbor_signup_worker'@'%'");
$writer=new PDO($dsn,'safeharbor_signup_worker','synthetic-signup-only',$options);
$id=str_repeat('1',64);$customer='11111111-1111-4111-8111-111111111111';$event='22222222-2222-4222-8222-222222222222';$now=gmdate('Y-m-d H:i:s');
$payload=['schema_version'=>1,'event_id'=>$event,'tenant_slug'=>'8west','customer_id'=>$customer,'source_version'=>1,
    'display_name'=>'Synthetic Portal Company','status'=>'active','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),'occurred_at_db'=>$now];
suite_customer_sync_receive($root,$payload,hash('sha256','fixture'));
$proof=['signup_id'=>$id,'customer_id'=>$customer,'provider'=>'8west','identity_tenant_slug'=>'customer-'.substr($id,0,24),
    'source_version'=>1,'event_id'=>$event,'business'=>$payload['display_name'],'initial_receipt_id'=>str_repeat('a',64),'verified_at'=>$now];
$identity=static function(string $value) use(&$proof):array {if ($value!==$proof['signup_id']) throw new RuntimeException('not admitted');return $proof;};
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($root,$id,1,$identity)),'ordinary database writer cannot run adapter');
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($writer,$id,2,$identity)),'other provider actor refused');
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($writer,$id,1,$identity,static function(){throw new RuntimeException('interrupted');})),'interrupted prepare fails safely');
signup_portal_check((int)$root->query('SELECT COUNT(*) FROM customer_portal_bindings')->fetchColumn()===0
    && (int)$root->query('SELECT COUNT(*) FROM customer_portal_binding_events')->fetchColumn()===0,'binding and audit rollback together');
$result=customer_signup_portal_apply($writer,$id,1,$identity);
signup_portal_check($result['ready'] && portal_active_binding_by_identity($writer,$proof['identity_tenant_slug'])!==null,'new verified customer has active exact portal binding');
signup_portal_check(customer_signup_portal_apply($writer,$id,1,$identity)===$result
    && (int)$root->query('SELECT COUNT(*) FROM customer_portal_binding_events')->fetchColumn()===2,'replay keeps one binding and two immutable events');
signup_portal_check((int)$root->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn()===0,'signup schedules no report');
signup_portal_check(signup_portal_refused(fn()=>$writer->exec("INSERT INTO clients (tenant_id,name) VALUES (1,'unauthorized')")),'portal worker cannot create clients');
signup_portal_check(signup_portal_refused(fn()=>$writer->query('SELECT password_hash FROM users')),'portal worker cannot read passwords');
signup_portal_check(signup_portal_refused(fn()=>$writer->exec('UPDATE users SET is_active=0')),'portal worker cannot modify users');
signup_portal_check(signup_portal_refused(fn()=>$writer->exec('INSERT INTO business_report_schedule_versions SELECT * FROM business_report_schedule_versions')),'portal worker cannot schedule reports');
$original=$proof;$proof['provider']='other';
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($writer,$id,1,$identity)),'other provider proof refused');$proof=$original;
$proof['verified_at']=gmdate('Y-m-d H:i:s',time()+10);
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($writer,$id,1,$identity)),'historical client predating verification refused');$proof=$original;
$proof['event_id']='33333333-3333-4333-8333-333333333333';
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($writer,$id,1,$identity)),'mismatched current directory event refused');$proof=$original;
$root->exec("UPDATE customer_portal_bindings SET status='disabled',status_reason='Fixture staff hold',last_changed_by_user_id=1");
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($writer,$id,1,$identity)),'worker never reactivates a disabled portal');
signup_portal_check(portal_active_binding_by_identity($writer,$proof['identity_tenant_slug'])===null,'disabled binding denies portal access');
$root->exec("UPDATE customer_portal_bindings SET status='active',status_reason='Fixture explicit restore',last_changed_by_user_id=1");
$payload['source_version']=2;$payload['status']='inactive';$payload['event_id']='33333333-3333-4333-8333-333333333333';
suite_customer_sync_receive($root,$payload,hash('sha256','inactive fixture'));
signup_portal_check(portal_active_binding_by_identity($writer,$proof['identity_tenant_slug'])===null,'source suspension immediately denies portal access');
signup_portal_check(signup_portal_refused(fn()=>customer_signup_portal_apply($writer,$id,1,$identity)),'source suspension refuses signup recovery');
echo "$checks signup portal checks passed\n";
