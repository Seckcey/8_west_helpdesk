<?php
/** Synthetic-only fixture helpers shared by boundary tests. Never a web route. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../lib/portal_westy.php';

function portal_westy_fixture_sql(PDO $pdo,string $path): void
{
    $delimiter=';';$buffer='';
    foreach(preg_split('/\R/',file_get_contents($path)) as $line){
        if(preg_match('/^\s*DELIMITER\s+(\S+)/i',$line,$match)){ $delimiter=$match[1];continue; }
        $buffer.=$line."\n";$trim=rtrim($buffer);
        if(str_ends_with($trim,$delimiter)){$pdo->exec(substr($trim,0,-strlen($delimiter)));$buffer='';}
    }
    if(trim($buffer)!=='')throw new RuntimeException('Unterminated fixture SQL');
}

function portal_westy_fixture_seed(PDO $pdo): array
{
    $pdo->exec("INSERT INTO tenants(id,name,slug) VALUES(1,'Synthetic provider','preview-provider'),(2,'Other provider','other-provider')");
    $pdo->exec("INSERT INTO clients(id,tenant_id,name) VALUES(11,1,'Northwind Studio'),(12,1,'Other business'),(21,2,'Other tenant business')");
    $pdo->exec("INSERT INTO users(id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES(101,1,'owner@example.test','','Preview owner','PO','owner',1),(201,2,'owner2@example.test','','Other owner','OO','owner',1)");
    $pdo->exec("ALTER TABLE tickets ADD COLUMN auto_close_eligible TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("INSERT INTO service_goal_policy_versions(tenant_id,policy_key,version_no,display_name,effective_from,clock_mode,time_zone,pause_mode) VALUES(1,'standard',1,'Standard','1970-01-01 00:00:00','elapsed','UTC','none'),(2,'standard',1,'Standard','1970-01-01 00:00:00','elapsed','UTC','none')");
    $pdo->exec("INSERT INTO service_goal_policy_targets(tenant_id,policy_version_id,priority,first_response_minutes,resolution_minutes) SELECT p.tenant_id,p.id,q.name,480,NULL FROM service_goal_policy_versions p JOIN (SELECT 'low' name UNION ALL SELECT 'normal' UNION ALL SELECT 'high' UNION ALL SELECT 'urgent') q");
    $contexts=[];
    foreach([[1,11,101,'northwind-preview'],[1,12,101,'other-preview'],[2,21,201,'tenant-preview']] as [$tenant,$client,$actor,$slug]){
        $b=portal_prepare_binding($pdo,$slug,$tenant,$client,$actor,'Synthetic isolated portal test.');
        $b=portal_transition_binding($pdo,(int)$b['id'],$slug,$tenant,$client,$actor,'active','Synthetic isolated test enabled.');
        $contexts[]=['identity'=>['tenant_id'=>$tenant,'client_id'=>$client,'binding_id'=>(int)$b['id'],'identity_tenant_slug'=>$slug,
            'subject'=>'t9u'.$client,'session_version'=>'1.1','role'=>'client_owner','display_name'=>'Alex Morgan',
            'issued_at'=>time(),'expires_at'=>time()+28800],'binding'=>$b];
    }
    return $contexts;
}
