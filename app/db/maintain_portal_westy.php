<?php
/** Run as the protected application operator. Dry-run unless --apply. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$lockPath='/var/lib/safeharbor-report-scheduler/business-reports-deploy.lock';
if(!is_file($lockPath)||is_link($lockPath)||fileowner($lockPath)!==0||(fileperms($lockPath)&0777)!==0640)exit(75);
$releaseLock=fopen($lockPath,'r');
if($releaseLock===false||!flock($releaseLock,LOCK_SH|LOCK_NB))exit(75);
require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/../lib/portal_westy_maintenance.php';
$options=[];
foreach(array_slice($argv,1) as $arg){
    if(!preg_match('/^--(apply|tenant|client|scope)(?:=(.+))?$/D',$arg,$m)||isset($options[$m[1]]))exit(2);
    $options[$m[1]]=$m[2]??true;
}
if(isset($options['apply'])&&$options['apply']!==true)exit(2);
$scope=null;
if(isset($options['scope'])||isset($options['tenant'])||isset($options['client'])){
    if(count(array_intersect(['scope','tenant','client'],array_keys($options)))!==3)exit(2);
    $tenant=filter_var($options['tenant'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $client=filter_var($options['client'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($tenant===false||$client===false)exit(2);
    $scope=['tenant'=>$tenant,'client'=>$client,'key'=>$options['scope']];
}
try{echo json_encode(portal_westy_maintain(db(),isset($options['apply']),$scope),JSON_THROW_ON_ERROR)."\n";}
catch(Throwable $e){fwrite(STDERR,'Portal Westy maintenance failed ('.$e::class."). No content was printed.\n");exit(1);}
