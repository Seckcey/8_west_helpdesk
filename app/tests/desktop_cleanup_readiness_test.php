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
$legacy=['schema'=>'8west-selected-runtime-identities-v1','apps'=>[
    'milepost'=>['mode'=>'legacy','user'=>'www-data','group'=>'www-data','uid'=>33,'gid'=>33],
    'safeharbor'=>['mode'=>'legacy','user'=>'www-data','group'=>'www-data','uid'=>33,'gid'=>33]]];
$isolated=$legacy;
foreach(['milepost'=>1601,'safeharbor'=>1602] as $job=>$id)$isolated['apps'][$job]=[
    'mode'=>'isolated','user'=>'eightwest-'.$job,'group'=>'eightwest-'.$job,'uid'=>$id,'gid'=>$id];
$encode=static fn(array $value):string=>json_encode($value,JSON_THROW_ON_ERROR);
cr_check(desktop_cleanup_identities($encode($legacy))===$legacy['apps'],'explicit legacy selection accepted');
cr_check(desktop_cleanup_identities($encode($isolated))===$isolated['apps'],'explicit distinct isolated pairs accepted');
$reordered=['apps'=>array_reverse($isolated['apps'],true),'schema'=>$isolated['schema']];
foreach($reordered['apps'] as &$app)$app=array_reverse($app,true);unset($app);
cr_check(desktop_cleanup_identities($encode($reordered))==$isolated['apps'],'selector property order is immaterial');
cr_check(desktop_cleanup_identities(str_replace('"uid"','"u\\u0069d"',$encode($legacy)))===$legacy['apps'],'valid JSON key escaping preserves selector meaning');
foreach(['', '{}','{bad',str_repeat(' ',4097),'{"schema":"duplicate",'.substr($encode($legacy),1),
    str_replace('"uid":33','"uid":0,"uid":33',$encode($legacy)),
    str_replace('"gid":33','"g\\u0069d":33,"gid":33',$encode($legacy))] as $wire){
    cr_check(desktop_cleanup_identities($wire)===null,'malformed oversized or duplicate selector refuses');
}
foreach([
    ['schema','unknown'],['extra',true],['apps',[]],
] as [$key,$value]){cr_check(desktop_cleanup_identities($encode(array_replace($legacy,[$key=>$value])))===null,'closed root refuses '.$key);}
foreach(['mode'=>'unknown','user'=>'other','group'=>'other','uid'=>'33','gid'=>33.5,'extra'=>true] as $key=>$value){
    $bad=$legacy;$bad['apps']['safeharbor'][$key]=$value;
    cr_check(desktop_cleanup_identities($encode($bad))===null,'closed legacy refuses '.$key);
}
$bad=$legacy;$bad['apps']['safeharbor']=$isolated['apps']['safeharbor'];
cr_check(desktop_cleanup_identities($encode($bad))===null,'mixed modes refuse');
$bad=$legacy;$bad['apps']['extra']=$legacy['apps']['milepost'];
cr_check(desktop_cleanup_identities($encode($bad))===null,'unknown app refuses');
foreach(['mode','user','group','uid','gid'] as $field){
    $bad=$legacy;unset($bad['apps']['safeharbor'][$field]);
    cr_check(desktop_cleanup_identities($encode($bad))===null,'missing identity field refuses '.$field);
}
foreach(['user','group'] as $field){
    $bad=$isolated;$bad['apps']['safeharbor'][$field]='eightwest-milepost';
    cr_check(desktop_cleanup_identities($encode($bad))===null,'cross-app isolated label refuses '.$field);
}
foreach(['uid','gid'] as $field)foreach([0,-1,33,1601] as $value){
    $bad=$isolated;$bad['apps']['safeharbor'][$field]=$value;
    cr_check(desktop_cleanup_identities($encode($bad))===null,'isolated '.$field.' refuses '.$value);
}
$dirAcl=['user:'=>'rwx','user:1601'=>'--x','group:'=>'---','mask:'=>'--x','other:'=>'---'];
$fileAcl=['user:'=>'rw-','user:1601'=>'r--','group:'=>'---','mask:'=>'r--','other:'=>'---'];
$aclWire=static function(array $acl):string{$wire='';foreach($acl as $entry=>$perms)$wire.=$entry.':'.$perms."\n";return $wire;};
cr_check(desktop_cleanup_acl_exact(desktop_cleanup_acl_parse($aclWire($dirAcl)),$dirAcl),'exact traversal-only directory ACL accepted');
cr_check(desktop_cleanup_acl_exact(desktop_cleanup_acl_parse($aclWire($fileAcl)),$fileAcl),'exact peer-read status ACL accepted');
foreach([
    $fileAcl+['user:1603'=>'r--'],$fileAcl+['default:user:'=>'rw-'],
    array_replace($fileAcl,['group:'=>'r--']),array_replace($fileAcl,['user:1601'=>'rw-']),
    array_replace($fileAcl,['mask:'=>'rw-']),array_replace($fileAcl,['other:'=>'r--']),
] as $bad){cr_check(!desktop_cleanup_acl_exact(desktop_cleanup_acl_parse($aclWire($bad)),$fileAcl),'extra/default/broad/write ACL refuses');}
cr_check(desktop_cleanup_acl_parse($aclWire($fileAcl)."user::rw-\n")===null,'duplicate ACL entry refuses');
cr_check(desktop_cleanup_acl_parse("not an ACL\n")===null,'unknown ACL output refuses');
cr_check(!desktop_cleanup_acl_exact(null,$fileAcl),'unavailable ACL query refuses');
$ancestor=['mode'=>0040750,'gid'=>0];
$ancestorAcl=['user:'=>'rwx','user:33'=>'--x','group:'=>'r-x','mask:'=>'r-x','other:'=>'---'];
cr_check(desktop_cleanup_ancestor_acl('/etc/8west',$ancestor,$ancestorAcl,$legacy['apps']),'selected legacy ancestor traversal accepted without changing0750');
$splitAncestor=$ancestorAcl;unset($splitAncestor['user:33']);$splitAncestor['user:1601']='--x';$splitAncestor['user:1602']='--x';
cr_check(desktop_cleanup_ancestor_acl('/etc/8west',$ancestor,$splitAncestor,$isolated['apps']),'only both selected isolated identities traverse protected ancestor');
cr_check(!desktop_cleanup_ancestor_acl('/etc/8west',$ancestor,$ancestorAcl+['user:44'=>'--x'],$legacy['apps']),'unselected ancestor traversal refuses');
cr_check(!desktop_cleanup_ancestor_acl('/etc/8west',$ancestor,array_replace($ancestorAcl,['user:33'=>'-wx']),null),'ancestor write refuses before selector read');
cr_check(!desktop_cleanup_ancestor_acl('/etc/8west',$ancestor,$ancestorAcl+['default:user:'=>'rwx'],null),'ancestor default ACL refuses');
$stat=['dev'=>1,'ino'=>2,'mode'=>0100600,'uid'=>33,'gid'=>33,'nlink'=>1,'size'=>10,'mtime'=>100,'ctime'=>100];
foreach(array_keys($stat) as $field)cr_check(!desktop_cleanup_stat_same($stat,array_replace($stat,[$field=>$stat[$field]+1])),'changed file identity/metadata refuses '.$field);
if(PHP_OS_FAMILY==='Linux'&&function_exists('posix_geteuid')){
    // Metadata fixture only: root provisions explicit33 ownership. Runtime tests
    // separately execute as real33; distinct runtime-UID acceptance is not claimed here.
    cr_check(posix_geteuid()===0&&posix_getegid()===0,'filesystem fixture requires explicit root invocation');
    $dir=sys_get_temp_dir().'/desktop-cleanup-test-'.bin2hex(random_bytes(8));mkdir($dir,0755);
    $selector=$dir.'/identities.json';
    try{
        file_put_contents($selector,$encode($legacy));chmod($selector,0644);
        cr_check(desktop_cleanup_selected($selector)===$legacy['apps'],'real root-owned selector descriptor validates');
        chmod($selector,0664);cr_check(desktop_cleanup_selected($selector)===null,'writable selector refuses');chmod($selector,0644);
        link($selector,$dir.'/selector-link');cr_check(desktop_cleanup_selected($selector)===null,'linked selector refuses');unlink($dir.'/selector-link');
        rename($selector,$dir.'/selector-saved');symlink($dir.'/selector-saved',$selector);
        cr_check(desktop_cleanup_selected($selector)===null,'symlink selector refuses');unlink($selector);rename($dir.'/selector-saved',$selector);
        chown($selector,33);cr_check(desktop_cleanup_selected($selector)===null,'nonroot selector refuses');chown($selector,0);
        // ACL query must keep following the opened inode if its name is replaced.
        $held=fopen($selector,'rb');rename($selector,$dir.'/selector-saved');file_put_contents($selector,$encode($legacy));chmod($selector,0600);
        cr_check(desktop_cleanup_acl_exact(desktop_cleanup_acl($held),['user:'=>'rw-','group:'=>'r--','other:'=>'r--']),'fd3 ACL query retains opened inode across pathname replacement');
        cr_check(!desktop_cleanup_stat_same(fstat($held),lstat($selector)),'renamed descriptor does not match replacement path');
        fclose($held);unlink($selector);rename($dir.'/selector-saved',$selector);
        foreach(['milepost','safeharbor'] as $job){mkdir($dir.'/'.$job,0700);chown($dir.'/'.$job,33);chgrp($dir.'/'.$job,33);file_put_contents($dir.'/'.$job.'/status.json',json_encode(array_replace($r,['job'=>$job])));chmod($dir.'/'.$job.'/status.json',0600);chown($dir.'/'.$job.'/status.json',33);chgrp($dir.'/'.$job.'/status.json',33);}
        $ready=static fn()=>desktop_cleanup_receipts_ready($dir,1002,$boot,$legacy['apps'],33,33);
        cr_check($ready(),'both actual files accepted');
        cr_check(!desktop_cleanup_receipts_ready($dir,1002,$boot,$legacy['apps'],34,33),'wrong own effective UID refuses');
        cr_check(!desktop_cleanup_receipts_ready($dir,1002,$boot,$legacy['apps'],33,34),'wrong own effective GID refuses');
        $file=$dir.'/safeharbor/status.json';
        chgrp($file,34);cr_check(!$ready(),'wrong producer primary GID refuses');chgrp($file,33);
        chown($file,34);cr_check(!$ready(),'wrong producer UID refuses');chown($file,33);
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
        if(file_exists($dir.'/saved'))unlink($dir.'/saved');
        foreach([$selector,$dir.'/selector-link',$dir.'/selector-saved'] as $p)if(file_exists($p)||is_link($p))unlink($p);
        rmdir($dir);
    }
}else echo "SKIP Linux root ownership/ACL fixture; parser checks are not runtime acceptance\n";
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
