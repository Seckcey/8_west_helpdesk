<?php
/** Local operational health, never identity, consent or task authority. */
declare(strict_types=1);

function desktop_cleanup_record_valid(array $r,string $job,int $now,string $boot):bool
{
    $keys=['schema','job','boot_id','started_at','completed_at','exit_code','successful_runs','healthy'];
    if(array_keys($r)!==$keys||$r['schema']!==1||$r['job']!==$job||$r['boot_id']!==$boot
        ||!is_int($r['started_at'])||!is_int($r['completed_at'])||!is_int($r['exit_code'])
        ||!is_int($r['successful_runs'])||!is_bool($r['healthy']))return false;
    return $r['started_at']>0&&$r['completed_at']>=$r['started_at']
        &&$r['completed_at']-$r['started_at']<=45&&$r['completed_at']<=$now
        &&$now-$r['completed_at']<=90&&$r['exit_code']===0
        &&$r['successful_runs']===2&&$r['healthy']===true;
}

/** Closed selector; JSON object ordering is not part of this protocol. */
function desktop_cleanup_identities(string $wire):?array
{
    if($wire===''||strlen($wire)>4096)return null;
    try{$r=json_decode($wire,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
    $closed=static function(mixed $v,array $keys):bool{
        return is_array($v)&&count($v)===count($keys)&&array_diff($keys,array_keys($v))===[];
    };
    // Count property tokens, consuming value strings too, so duplicate keys cannot
    // disappear through json_decode. The closed shape has exactly 14 properties.
    if(preg_match_all('/"(?:[^"\\\\]|\\\\.)*"(\s*:)?/s',$wire,$tokens,PREG_SET_ORDER)===false)return null;
    $properties=0;foreach($tokens as $token)if(isset($token[1])&&trim($token[1])===':')$properties++;
    if($properties!==14||!$closed($r,['schema','apps'])||$r['schema']!=='8west-selected-runtime-identities-v1'
        ||!$closed($r['apps'],['milepost','safeharbor']))return null;
    foreach(['milepost','safeharbor'] as $job){
        $a=$r['apps'][$job];
        if(!$closed($a,['mode','user','group','uid','gid'])||!is_int($a['uid'])||!is_int($a['gid']))return null;
        if($a['mode']==='legacy'){
            if($a['user']!=='www-data'||$a['group']!=='www-data'||$a['uid']!==33||$a['gid']!==33)return null;
        }elseif($a['mode']==='isolated'){
            if($a['user']!=='eightwest-'.$job||$a['group']!=='eightwest-'.$job
                ||$a['uid']<=0||$a['gid']<=0||$a['uid']===33||$a['gid']===33)return null;
        }else return null;
    }
    $mp=$r['apps']['milepost'];$sh=$r['apps']['safeharbor'];
    if($mp['mode']!==$sh['mode']||($sh['mode']==='isolated'&&($mp['uid']===$sh['uid']||$mp['gid']===$sh['gid'])))return null;
    return $r['apps'];
}

/** Numeric getfacl output. Duplicate/unknown entries never become an ACL proof. */
function desktop_cleanup_acl_parse(string $wire):?array
{
    if($wire===''||strlen($wire)>8192)return null;
    $acl=[];
    foreach(explode("\n",trim($wire)) as $line){
        if($line==='')continue;
        if(!preg_match('/\A((?:default:)?(?:user|group):(?:0|[1-9][0-9]*)?|(?:default:)?(?:mask|other):):([r-][w-][x-])(?:\s+#effective:[r-][w-][x-])?\z/D',$line,$m)
            ||isset($acl[$m[1]]))return null;
        $acl[$m[1]]=$m[2];
    }
    return $acl===[]?null:$acl;
}

/** Regular files pass their already-open stream as child fd 3; never reopen by name. */
function desktop_cleanup_acl(mixed $target):?array
{
    if(PHP_OS_FAMILY!=='Linux'||!function_exists('proc_open'))return null;
    $descriptors=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']];
    if(is_resource($target)){$descriptors[3]=$target;$path='/proc/self/fd/3';}
    elseif(is_string($target))$path=$target;
    else return null;
    $process=null;$pipes=[];
    try{
        $process=@proc_open(['/usr/bin/getfacl','-ncp','--',$path],$descriptors,$pipes,null,['LC_ALL'=>'C']);
        if(!is_resource($process))return null;
        if(!stream_set_blocking($pipes[1],false)||!stream_set_blocking($pipes[2],false))return null;
        $out='';$err='';$deadline=hrtime(true)+250000000;
        do{
            $out.=stream_get_contents($pipes[1],8193-strlen($out));
            $err.=stream_get_contents($pipes[2],1025-strlen($err));
            if(strlen($out)>8192||strlen($err)>1024||hrtime(true)>$deadline)return null;
            $status=proc_get_status($process);
            if(!$status['running']){
                $out.=stream_get_contents($pipes[1],8193-strlen($out));
                $err.=stream_get_contents($pipes[2],1025-strlen($err));
                return $status['exitcode']===0&&$err===''&&strlen($out)<=8192?desktop_cleanup_acl_parse($out):null;
            }
            usleep(1000);
        }while(true);
    }catch(Throwable){return null;}
    finally{
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($process)){
            $status=proc_get_status($process);
            if($status['running'])proc_terminate($process,9);
            proc_close($process);
        }
    }
}

function desktop_cleanup_acl_exact(?array $actual,array $expected):bool
{
    if($actual===null)return false;
    ksort($actual);ksort($expected);return $actual===$expected;
}

function desktop_cleanup_stat_same(array|false $a,array|false $b):bool
{
    if(!$a||!$b)return false;
    foreach(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $key)if($a[$key]!==$b[$key])return false;
    return true;
}

/** The protected 0750 ancestor grants selected apps traversal, never listing or write. */
function desktop_cleanup_ancestor_acl(string $path,array $stat,?array $acl,?array $apps):bool
{
    if($acl===null)return false;
    if($path==='/etc/8west'){
        if($stat['gid']!==0||($stat['mode']&07777)!==0750)return false;
        $expected=['user:'=>'rwx','group:'=>'r-x','mask:'=>'r-x','other:'=>'---'];
        if($apps!==null){
            foreach($apps as $app)$expected['user:'.$app['uid']]='--x';
            return desktop_cleanup_acl_exact($acl,$expected);
        }
        // Before opening the selector, prove no effective write/default ACL.
        // After parsing it, the second pass binds every named entry to its selected UID.
        foreach($acl as $key=>$value){
            if(isset($expected[$key])){if($expected[$key]!==$value)return false;}
            elseif(!preg_match('/\Auser:[1-9][0-9]*\z/D',$key)||$value!=='--x')return false;
        }
        return array_diff_key($expected,$acl)===[]&&count($acl)>=5&&count($acl)<=6;
    }
    if($path==='/etc/8west/php'&&($stat['gid']!==0||($stat['mode']&07777)!==0755))return false;
    $perms=static fn(int $bits):string=>(($bits&4)?'r':'-').(($bits&2)?'w':'-').(($bits&1)?'x':'-');
    return desktop_cleanup_acl_exact($acl,['user:'=>$perms(($stat['mode']>>6)&7),
        'group:'=>$perms(($stat['mode']>>3)&7),'other:'=>$perms($stat['mode']&7)]);
}

/** Two passes: protected ancestry first, exact selected traversal entries second. */
function desktop_cleanup_ancestors_safe(string $path,?array $apps=null):bool
{
    do{
        clearstatcache(true,$path);$before=@lstat($path);
        if(!$before||($before['mode']&0170000)!==0040000||$before['uid']!==0
            ||($before['mode']&0022)!==0||realpath($path)!==$path)return false;
        if(!desktop_cleanup_ancestor_acl($path,$before,desktop_cleanup_acl($path),$apps))return false;
        clearstatcache(true,$path);
        if(!desktop_cleanup_stat_same($before,@lstat($path)))return false;
        if($path==='/')return true;
        $path=dirname($path);
    }while(true);
}

/** Test seam takes a fixture path; production calls only the one fixed selector path. */
function desktop_cleanup_selected(string $path):?array
{
    clearstatcache(true,$path);$named=@lstat($path);
    if(!$named||($named['mode']&0170000)!==0100000||($named['mode']&07777)!==0644
        ||$named['uid']!==0||$named['gid']!==0||$named['nlink']!==1||$named['size']<1||$named['size']>4096
        ||realpath($path)!==$path)return null;
    $fd=@fopen($path,'rb');if($fd===false)return null;
    try{
        $opened=fstat($fd);
        if(!desktop_cleanup_stat_same($named,$opened)
            ||!desktop_cleanup_acl_exact(desktop_cleanup_acl($fd),['user:'=>'rw-','group:'=>'r--','other:'=>'r--']))return null;
        $wire=stream_get_contents($fd,4097);
        clearstatcache(true,$path);
        if(!desktop_cleanup_stat_same($opened,fstat($fd))||!desktop_cleanup_stat_same($opened,@lstat($path)))return null;
        return is_string($wire)?desktop_cleanup_identities($wire):null;
    }catch(Throwable){return null;}finally{fclose($fd);}
}

/** Explicit arguments support hermetic filesystem tests; runtime never takes a caller path. */
function desktop_cleanup_receipts_ready(string $directory,int $now,string $boot,array $apps,int $uid,int $gid):bool
{
    if(preg_match('/\A[0-9a-f-]{36}\z/D',$boot)!==1)return false;
    // The same closed protocol is required at this test seam; no arbitrary-eUID legacy mode.
    $validated=desktop_cleanup_identities(json_encode(['schema'=>'8west-selected-runtime-identities-v1','apps'=>$apps]));
    if($validated===null||$apps['safeharbor']['uid']!==$uid||$apps['safeharbor']['gid']!==$gid)return false;
    clearstatcache(true,$directory);$root=@lstat($directory);
    if(!$root||($root['mode']&0170000)!==0040000||($root['mode']&07777)!==0755
        ||$root['uid']!==0||$root['gid']!==0||realpath($directory)!==$directory
        ||!desktop_cleanup_acl_exact(desktop_cleanup_acl($directory),['user:'=>'rwx','group:'=>'r-x','other:'=>'r-x']))return false;
    clearstatcache(true,$directory);
    if(!desktop_cleanup_stat_same($root,@lstat($directory)))return false;
    foreach(['milepost','safeharbor'] as $job){
        $owner=$apps[$job];$peer=$apps[$job==='milepost'?'safeharbor':'milepost'];$isolated=$owner['mode']==='isolated';
        $dirAcl=['user:'=>'rwx','group:'=>'---','other:'=>'---'];
        $fileAcl=['user:'=>'rw-','group:'=>'---','other:'=>'---'];
        if($isolated){$dirAcl['user:'.$peer['uid']]='--x';$dirAcl['mask:']='--x';$fileAcl['user:'.$peer['uid']]='r--';$fileAcl['mask:']='r--';}
        $dir=$directory.'/'.$job;$file=$dir.'/status.json';
        clearstatcache(true,$dir);clearstatcache(true,$file);
        $dirStat=@lstat($dir);
        if(!$dirStat||realpath($dir)!==$dir||($dirStat['mode']&0170000)!==0040000
            ||$dirStat['uid']!==$owner['uid']||$dirStat['gid']!==$owner['gid']||($dirStat['mode']&07777)!==($isolated?0710:0700)
            ||!desktop_cleanup_acl_exact(desktop_cleanup_acl($dir),$dirAcl)
            ||is_link($file)||!is_file($file))return false;
        clearstatcache(true,$dir);
        if(!desktop_cleanup_stat_same($dirStat,@lstat($dir)))return false;
        $fd=@fopen($file,'rb');if($fd===false)return false;
        try{
            clearstatcache(true,$file);$stat=fstat($fd);$named=@lstat($file);
            if(!$stat||!$named||$stat['ino']!==$named['ino']||$stat['dev']!==$named['dev']
                ||($stat['mode']&0170000)!==0100000||($stat['mode']&07777)!==($isolated?0640:0600)
                ||$stat['uid']!==$owner['uid']||$stat['gid']!==$owner['gid']||$stat['nlink']!==1||$stat['size']>1024
                ||!desktop_cleanup_acl_exact(desktop_cleanup_acl($fd),$fileAcl))return false;
            $wire=stream_get_contents($fd,1025);
            clearstatcache(true,$file);clearstatcache(true,$dir);clearstatcache(true,$directory);
            if(!desktop_cleanup_stat_same($stat,fstat($fd))||!desktop_cleanup_stat_same($stat,@lstat($file))
                ||!desktop_cleanup_stat_same($dirStat,@lstat($dir))||!desktop_cleanup_stat_same($root,@lstat($directory)))return false;
            if(!is_string($wire)||strlen($wire)>1024
                ||preg_match_all('/"[a-z_]+"\s*:/',$wire)!==8)return false;
            $r=json_decode($wire,true,4,JSON_THROW_ON_ERROR);
            if(!is_array($r)||!desktop_cleanup_record_valid($r,$job,$now,$boot))return false;
        }catch(Throwable){return false;}finally{fclose($fd);}
    }
    return true;
}

function desktop_cleanup_available():bool
{
    if(!function_exists('posix_geteuid')||!function_exists('posix_getegid'))return false;
    if(!desktop_cleanup_ancestors_safe('/etc/8west/php')||!desktop_cleanup_ancestors_safe('/run'))return false;
    $apps=desktop_cleanup_selected('/etc/8west/php/runtime-identities.json');
    if($apps===null||!desktop_cleanup_ancestors_safe('/etc/8west/php',$apps))return false;
    $boot=@file_get_contents('/proc/sys/kernel/random/boot_id');
    return is_string($boot)&&desktop_cleanup_receipts_ready('/run/8west-desktop-cleanup',time(),trim($boot),$apps,posix_geteuid(),posix_getegid());
}
