<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/desktop_cleanup_readiness.php';
$checks=0;
function cr_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
$boot='12345678-1234-1234-1234-123456789abc';
$r=['schema'=>1,'job'=>'milepost','boot_id'=>$boot,'started_at'=>1000,'completed_at'=>1001,'exit_code'=>0,'successful_runs'=>2,'healthy'=>true];
cr_check(desktop_cleanup_record_valid($r,'milepost',1091,$boot),'90-second boundary accepted');
foreach([
 ['completed_at',999],['completed_at',1046],['completed_at',1101],['completed_at','1001'],
 ['exit_code',1],['exit_code',124],['exit_code',false],['successful_runs',1],['successful_runs',3],
 ['healthy',false],['healthy','true'],['job','safeharbor'],['boot_id','other'],['schema','1']
] as [$key,$value]){cr_check(!desktop_cleanup_record_valid(array_replace($r,[$key=>$value]),'milepost',1100,$boot),'refuse '.$key);}
cr_check(!desktop_cleanup_record_valid($r,'milepost',1092,$boot),'stale refuses');
cr_check(!desktop_cleanup_record_valid($r+['extra'=>true],'milepost',1002,$boot),'unknown field refuses');
cr_check(!desktop_cleanup_record_valid(array_diff_key($r,['healthy'=>true]),'milepost',1002,$boot),'missing field refuses');
if(function_exists('posix_geteuid')){
    $dir=sys_get_temp_dir().'/desktop-cleanup-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    try{
        foreach(['milepost','safeharbor'] as $job){mkdir($dir.'/'.$job,0700);file_put_contents($dir.'/'.$job.'/status.json',json_encode(array_replace($r,['job'=>$job])));chmod($dir.'/'.$job.'/status.json',0600);}
        $ready=static fn()=>desktop_cleanup_receipts_ready($dir,1002,$boot,posix_geteuid());
        cr_check($ready(),'both actual files accepted');
        $file=$dir.'/safeharbor/status.json';
        chmod($file,0644);cr_check(!$ready(),'public receipt refuses');chmod($file,0600);
        file_put_contents($file,'{bad');cr_check(!$ready(),'malformed refuses');
        file_put_contents($file,json_encode(array_replace($r,['job'=>'safeharbor','exit_code'=>1])));cr_check(!$ready(),'peer failure blocks both');
        file_put_contents($file,json_encode(array_replace($r,['job'=>'safeharbor'])));cr_check($ready(),'real peer success restores');
        rename($file,$dir.'/saved');symlink($dir.'/saved',$file);cr_check(!$ready(),'symlink refuses');unlink($file);
        link($dir.'/saved',$file);cr_check(!$ready(),'hardlink refuses');unlink($file);rename($dir.'/saved',$file);
        chmod($dir.'/safeharbor',0755);cr_check(!$ready(),'unsafe directory refuses');chmod($dir.'/safeharbor',0700);
        file_put_contents($file,'{"schema":1,'.substr(json_encode(array_replace($r,['job'=>'safeharbor'])),1));cr_check(!$ready(),'duplicate field refuses');
        unlink($file);cr_check(!$ready(),'missing peer refuses');
    }finally{
        foreach(['milepost','safeharbor'] as $job){$p=$dir.'/'.$job.'/status.json';if(file_exists($p)||is_link($p))unlink($p);rmdir($dir.'/'.$job);}
        if(file_exists($dir.'/saved'))unlink($dir.'/saved');rmdir($dir);
    }
}
require_once __DIR__.'/../lib/portal_westy_desktop.php';
// No bootstrap, credentials, database or network. The invalid config distinguishes
// the normal authorization path from an early cleanup refusal.
function cfg(string $key,mixed $default=null):mixed{return $default;}
cr_check(!desktop_cleanup_available(),'test host has no live cleanup authority');
foreach(['start','bind','observe','observation','action','stop','state','result'] as $action){
    try{portal_desktop_request([],$action,[]);throw new RuntimeException('request unexpectedly allowed');}
    catch(PortalDesktopException $e){
        $expected=in_array($action,['stop','state','result'],true)?'connection_unavailable':'cleanup_unavailable';
        cr_check($e->reason===$expected&&$e->status===503,'gate '.$action);
    }
}
echo "PASS $checks desktop cleanup readiness checks\n";
