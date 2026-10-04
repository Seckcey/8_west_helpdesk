<?php
/** Component of the root-owned scoped release operator, never a standalone web/DDL entrypoint. */
declare(strict_types=1);
define('TAI_MIGRATION_LIBRARY_ONLY', true);
require_once __DIR__.'/tenant_ai_migration.php';
const TAI_RELEASE_APP = 'safeharbor';
const TAI_RELEASE_LOCK_PATHS = [
    '/run/lock/8west-suite-release.lock',
    '/srv/8west/apps/safeharbor/.app-release.lock',
    '/var/lib/safeharbor-report-scheduler/business-reports-deploy.lock',
];
const TAI_RELEASE_LOCK_OWNERS = [];
const TAI_RELEASE_SOURCE_FILES = [
    'app/db/migrations/20261004_tenant_ai.sql', 'deploy/tenant_ai_migration.php',
    'deploy/tenant_ai_migration_catalog.json', 'deploy/tenant_ai_release.php',
    'deploy/tenant_ai_writers.php', 'deploy/tenant_ai_operator.php', 'deploy/tenant_ai_freeze.py',
    'deploy/tenant_ai_freeze_profile.json', 'deploy/tenant_ai_window.py',
    'deploy/tenant_ai_restore.py', 'deploy/tenant_ai_scratch.py',
];

function tai_release_private_file(string $path): string
{
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || realpath($path)!==$path || fileowner($path)!==0
        || (fileperms($path)&0777)!==0600) throw new RuntimeException('protected evidence file required');
    $bytes=file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException('evidence unreadable');
    return $bytes;
}

function tai_release_write(string $path, array $value): void
{
    $fd=fopen($path, 'x');
    if ($fd===false) throw new RuntimeException('existing evidence must not be overwritten');
    try {
        if (!chmod($path, 0600)) throw new RuntimeException('evidence protection failed');
        $bytes=json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        if (fwrite($fd, $bytes)!==strlen($bytes) || !fflush($fd) || !fsync($fd)) throw new RuntimeException('incomplete evidence write');
    } finally { fclose($fd); }
}

/** Validate the outer operator's actual closed-window dump and independent scratch-restore proof. */
function tai_release_proof(string $path, string $target, array $identity, string $candidate): array
{
    $root=dirname($path);
    if (!is_dir($root) || is_link($root) || realpath($root)!==$root || fileowner($root)!==0
        || (fileperms($root)&0777)!==0700 || $root===$candidate || str_starts_with($root,$candidate.'/')) {
        throw new RuntimeException('external root-only evidence directory required');
    }
    $proof=json_decode(tai_release_private_file($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($proof) || array_keys($proof)!==['contract','app','target','identity','freeze_id','source_files','backup','restore']
        || $proof['contract']!=='tenant-ai-closed-window-v1' || $proof['app']!==TAI_RELEASE_APP
        || !preg_match('/\A[0-9a-f]{40}\z/D',$target) || $proof['target']!==$target || $proof['identity']!==$identity
        || !is_string($proof['freeze_id']) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{15,95}\z/D',$proof['freeze_id'])
        || array_keys($proof['source_files'])!==TAI_RELEASE_SOURCE_FILES) throw new RuntimeException('release proof identity mismatch');
    foreach (TAI_RELEASE_SOURCE_FILES as $relative) {
        $file=$candidate.'/'.$relative;
        if (!is_file($file) || is_link($file) || !is_string($proof['source_files'][$relative])
            || !hash_equals($proof['source_files'][$relative],hash_file('sha256',$file))) throw new RuntimeException('candidate source changed');
    }
    // The already loaded modules must be the exact reviewed candidate modules too.
    foreach (['tenant_ai_release.php','tenant_ai_migration.php','tenant_ai_migration_catalog.json'] as $name)
        if (!hash_equals($proof['source_files']['deploy/'.$name],hash_file('sha256',__DIR__.'/'.$name))) throw new RuntimeException('loaded control differs from candidate');
    foreach (['backup','restore'] as $kind) {
        $artifact=$proof[$kind];
        if (!is_array($artifact) || array_keys($artifact)!==['file','size','sha256'] || !is_int($artifact['size']) || $artifact['size']<1
            || !is_string($artifact['file']) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{1,95}\z/D',$artifact['file'])!==1
            || !is_string($artifact['sha256']) || preg_match('/\A[0-9a-f]{64}\z/D',$artifact['sha256'])!==1) throw new RuntimeException('artifact proof malformed');
        $file=$root.'/'.$artifact['file'];
        // Backup files can be large: validate metadata without retaining their contents.
        clearstatcache(true,$file);
        if (!is_file($file) || is_link($file) || realpath($file)!==$file || fileowner($file)!==0 || (fileperms($file)&0777)!==0600
            || filesize($file)!==$artifact['size'] || !hash_equals($artifact['sha256'],hash_file('sha256',$file))) throw new RuntimeException('backup or restore proof changed');
    }
    $restore=json_decode(tai_release_private_file($root.'/'.$proof['restore']['file']),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($restore) || ($restore['contract']??null)!=='tenant-ai-scratch-restore-v1' || ($restore['identity']??null)!==$identity
        || ($restore['backup_sha256']??null)!==$proof['backup']['sha256'] || ($restore['schema_equal']??null)!==true
        || ($restore['rows_equal']??null)!==true || ($restore['scratch_removed']??null)!==true) throw new RuntimeException('verified scratch restore required');
    return $proof;
}

/** Verify inherited exclusive locks, including stable and distinct physical inodes. */
function tai_release_verify_locks(array $heldLocks, array $expectedPaths, array $expectedOwners = []): void
{
    $actualPaths=array_keys($heldLocks);sort($actualPaths);sort($expectedPaths);
    if ($actualPaths!==$expectedPaths || count($expectedPaths)<2) throw new RuntimeException('exact application and shared locks required');
    $seen=[];
    foreach ($heldLocks as $path=>$stream) {
        clearstatcache(true,$path);$stat=is_resource($stream)?fstat($stream):false;$live=lstat($path);
        if (!is_string($path) || !is_file($path) || is_link($path) || realpath($path)!==$path || !$stat || !$live
            || $stat['ino']!==$live['ino'] || $stat['dev']!==$live['dev'] || $live['uid']!==($expectedOwners[$path]??0) || ($live['mode']&0022)!==0)
            throw new RuntimeException('held release lock identity mismatch');
        $identity=$live['dev'].':'.$live['ino'];
        if (isset($seen[$identity])) throw new RuntimeException('distinct release lock inodes required');
        $seen[$identity]=true;
        // A newly opened, unlocked descriptor is not evidence of an inherited
        // freeze lock. A separate open must already be excluded before we check
        // that the passed descriptor itself owns that exclusive flock.
        $probe=fopen($path,'r');
        if ($probe===false) throw new RuntimeException('release lock probe failed');
        try {
            if (flock($probe,LOCK_SH|LOCK_NB)) {
                flock($probe,LOCK_UN);
                throw new RuntimeException('release lock was not already held exclusively');
            }
        } finally { fclose($probe); }
        if (!flock($stream,LOCK_EX|LOCK_NB)) throw new RuntimeException('passed descriptor does not own the release lock');
    }
}

/**
 * The outer reviewed operator owns app-specific Apache/service/account freeze and
 * its existing release locks. It passes the held streams (never path-only claims)
 * and a verifier that rechecks live writer locks/drain on each call. This module
 * owns only pinned additive DDL and durable component intent/receipt. It never
 * unfreezes, unlocks writers, changes grants, installs source or invokes providers.
 *
 * @param array<string, resource> $heldLocks Canonical lock path => already held stream.
 */
function tai_release_apply(PDO $pdo, string $candidate, string $target, string $proofPath, array $heldLocks, callable $verifyFrozen): array
{
    if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0 || $pdo->inTransaction()) throw new RuntimeException('root CLI outside transaction required');
    $candidate=realpath($candidate) ?: throw new RuntimeException('candidate absent');
    $row=$pdo->query('SELECT DATABASE() AS db,@@server_uuid AS server_uuid')->fetch(PDO::FETCH_ASSOC);
    $identity=['database'=>$row['db'],'server_uuid'=>$row['server_uuid']];
    if (!preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D',$identity['database']) || !preg_match('/\A[0-9a-f-]{36}\z/D',$identity['server_uuid'])) throw new RuntimeException('database identity absent');
    $proof=tai_release_proof($proofPath,$target,$identity,$candidate);
    tai_release_verify_locks($heldLocks,TAI_RELEASE_LOCK_PATHS,TAI_RELEASE_LOCK_OWNERS);
    $assertFrozen=static function(string $stage) use ($verifyFrozen,$identity,$proof): void {
        $actual=$verifyFrozen($stage);
        if ($actual!==['identity'=>$identity,'freeze_id'=>$proof['freeze_id'],'writers_closed'=>true]) throw new RuntimeException('live write freeze could not be verified');
    };
    $assertFrozen('preflight');
    $root=dirname($proofPath); $intentPath=$root.'/tenant-ai-intent.json'; $receiptPath=$root.'/tenant-ai-receipt.json';
    $snapshot=tai_migration_snapshot($pdo);
    if ($snapshot['drift']) throw new RuntimeException('schema drift; preserve freeze and backup');
    $expected=['contract'=>'tenant-ai-migration-intent-v1','app'=>TAI_RELEASE_APP,'target'=>$target,'identity'=>$identity,
        'proof_sha256'=>hash_file('sha256',$proofPath),'payload_sha256'=>TAI_MIGRATION_SHA256,'catalog_sha256'=>TAI_CATALOG_SHA256];
    if (file_exists($intentPath) || is_link($intentPath)) {
        if (json_decode(tai_release_private_file($intentPath),true,16,JSON_THROW_ON_ERROR)!==$expected) throw new RuntimeException('original intent mismatch');
    } else {
        if ($snapshot['position']!==0 || file_exists($receiptPath) || is_link($receiptPath)) throw new RuntimeException('unowned final/partial schema or orphan receipt');
        tai_release_write($intentPath,$expected);
    }
    $receipt=['contract'=>'tenant-ai-migration-receipt-v1','intent_sha256'=>hash_file('sha256',$intentPath),'state'=>'FINAL'];
    if (file_exists($receiptPath) || is_link($receiptPath)) {
        if (!$snapshot['schema_ok'] || json_decode(tai_release_private_file($receiptPath),true,16,JSON_THROW_ON_ERROR)!==$receipt) throw new RuntimeException('receipt/schema mismatch');
        return ['state'=>'already_applied','identity'=>$identity];
    }
    // Unknown completion is deliberately not replayed: a final schema without its
    // original durable receipt needs operator recovery, not a guessed success.
    if ($snapshot['schema_ok']) throw new RuntimeException('final schema without original receipt');
    $assertFrozen('before_ddl');
    tai_release_proof($proofPath,$target,$identity,$candidate);
    $result=tai_migration_apply($pdo,$candidate.'/'.TAI_MIGRATION_PATH);
    $assertFrozen('after_ddl');
    tai_release_write($receiptPath,$receipt);
    return ['state'=>'applied','identity'=>$identity,'resumed_from'=>$result['resumed_from']];
}

if (defined('TAI_RELEASE_LIBRARY_ONLY')) return;
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
fwrite(STDERR,"Use this module from the reviewed root-owned scoped release operator; standalone apply is refused.\n");
exit(2);
