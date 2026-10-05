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

/** Explicit arguments support hermetic filesystem tests; runtime never takes a caller path. */
function desktop_cleanup_receipts_ready(string $directory,int $now,string $boot,int $uid):bool
{
    if(preg_match('/\A[0-9a-f-]{36}\z/D',$boot)!==1)return false;
    foreach(['milepost','safeharbor'] as $job){
        $dir=$directory.'/'.$job;$file=$dir.'/status.json';
        clearstatcache(true,$dir);clearstatcache(true,$file);
        if(is_link($directory)||realpath($directory)!==$directory||is_link($dir)||realpath($dir)!==$dir
            ||!is_dir($dir)||fileowner($dir)!==$uid||(fileperms($dir)&0777)!==0700
            ||is_link($file)||!is_file($file))return false;
        $fd=@fopen($file,'rb');if($fd===false)return false;
        try{
            $stat=fstat($fd);$named=lstat($file);
            if(!$stat||!$named||$stat['ino']!==$named['ino']||$stat['dev']!==$named['dev']
                ||($stat['mode']&0170000)!==0100000||($stat['mode']&0777)!==0600
                ||$stat['uid']!==$uid||$stat['nlink']!==1||$stat['size']>1024)return false;
            $wire=stream_get_contents($fd,1025);
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
    if(!function_exists('posix_geteuid'))return false;
    $boot=@file_get_contents('/proc/sys/kernel/random/boot_id');
    return is_string($boot)&&desktop_cleanup_receipts_ready('/run/8west-desktop-cleanup',time(),trim($boot),posix_geteuid());
}
