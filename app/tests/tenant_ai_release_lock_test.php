<?php
/** Real Linux flock/descriptor checks on synthetic task-owned files only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('TENANT_AI_TEST_DISPOSABLE_SERVER')!=='1'||!function_exists('posix_geteuid')||posix_geteuid()!==0)exit(2);
define('TAI_RELEASE_LIBRARY_ONLY',true);
require __DIR__.'/../../deploy/tenant_ai_release.php';
$directory=sys_get_temp_dir().'/tenant-ai-lock-fixture-'.bin2hex(random_bytes(8));
if(!mkdir($directory,0700))throw new RuntimeException('fixture directory failed');
$files=[];$streams=[];$checks=0;
function tai_lock_expect(bool $ok,string $name):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($name);}
function tai_lock_refuses(callable $fn,string $name):void{
 try{$fn();}catch(RuntimeException){tai_lock_expect(true,$name);return;}
 tai_lock_expect(false,$name);
}
try{
 foreach(['a','b','c'] as $name){$path=$directory.'/'.$name;$files[]=$path;$stream=fopen($path,'x+');chmod($path,0600);$streams[$name]=$stream;flock($stream,LOCK_EX);}
 $a=$directory.'/a';$b=$directory.'/b';$held=[$a=>$streams['a'],$b=>$streams['b']];
 tai_release_verify_locks($held,[$a,$b]);tai_lock_expect(true,'real held exclusive descriptors accepted');
 tai_lock_refuses(fn()=>tai_release_verify_locks([$a=>$streams['a']],[$a,$b]),'missing expected lock');
 tai_lock_refuses(fn()=>tai_release_verify_locks($held,[$a,$directory.'/c']),'unexpected lock set');
 tai_lock_refuses(fn()=>tai_release_verify_locks([$a=>$streams['c'],$b=>$streams['b']],[$a,$b]),'wrong descriptor inode');
 flock($streams['a'],LOCK_UN);
 tai_lock_refuses(fn()=>tai_release_verify_locks($held,[$a,$b]),'unlocked descriptor');
 flock($streams['a'],LOCK_SH);
 tai_lock_refuses(fn()=>tai_release_verify_locks($held,[$a,$b]),'shared lock is not an exclusive freeze');
 flock($streams['a'],LOCK_EX);
 $alias=$directory.'/hardlink';$files[]=$alias;link($a,$alias);
 tai_lock_refuses(fn()=>tai_release_verify_locks([$a=>$streams['a'],$alias=>$streams['a']],[$a,$alias]),'duplicate physical inode');
 $link=$directory.'/symlink';$files[]=$link;symlink($a,$link);
 tai_lock_refuses(fn()=>tai_release_verify_locks([$link=>$streams['a'],$b=>$streams['b']],[$link,$b]),'symlink path');
 chmod($a,0660);
 tai_lock_refuses(fn()=>tai_release_verify_locks($held,[$a,$b]),'group writable lock');
 chmod($a,0600);
 chown($a,33);
 tai_lock_refuses(fn()=>tai_release_verify_locks($held,[$a,$b]),'unreviewed owner is refused');
 tai_release_verify_locks($held,[$a,$b],[$a=>33]);tai_lock_expect(true,'explicit reviewed service-owned mutex accepted');
 chown($a,0);
 unlink($a);$replacement=fopen($a,'x+');$streams['replacement']=$replacement;chmod($a,0600);
 tai_lock_refuses(fn()=>tai_release_verify_locks($held,[$a,$b]),'path replacement cannot inherit old inode proof');
 echo "tenant_ai_release_lock_test: $checks checks passed\n";
}finally{
 foreach($streams as $stream)if(is_resource($stream))fclose($stream);
 foreach(array_reverse($files) as $path)if(is_file($path)||is_link($path))unlink($path);
 rmdir($directory);
}
