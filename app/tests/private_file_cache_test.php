<?php
/** Actual Unix filesystem boundary tests; synthetic bytes only, no network. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) {
    fwrite(STDERR, "Unix PHP CLI with POSIX is required.\n"); exit(1);
}
require_once __DIR__ . '/../lib/eightwestid/private_file_cache.php';
use EightWest\Id\Security\PrivateFileCache as Cache;
$passed = 0;
$failed = 0;
$skipped = 0;
function pc_check(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) { $passed++; } else { $failed++; fwrite(STDERR, "FAIL: $name\n"); }
}
$root = sys_get_temp_dir() . '/eightwest-cache-security-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0700)) throw new RuntimeException('Fixture creation failed');
$private = $root . '/private'; mkdir($private, 0700);
$path = $private . '/cache.json';
$cleanup = static function (string $directory) use (&$cleanup, $root): void {
    if ($directory !== $root && !str_starts_with($directory, $root . '/')) throw new RuntimeException('Cleanup escaped');
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) continue;
        $file = $entry->getPathname();
        if (!$entry->isLink() && $entry->isDir()) $cleanup($file);
        else unlink($file);
    }
    rmdir($directory);
};
try {
    pc_check('private directory accepted', Cache::directory($path) !== null);
    pc_check('first write', Cache::write($path, 'first', 100));
    pc_check('read exact bytes', Cache::read($path, 100) === 'first');
    pc_check('atomic replacement', Cache::write($path, 'second', 100) && Cache::read($path, 100) === 'second');
    clearstatcache(true, $path);
    pc_check('cache mode is 0600', (fileperms($path) & 07777) === 0600);
    pc_check('oversized read refused', Cache::read($path, 2) === null);
    pc_check('oversized write preserves bytes', !Cache::write($path, 'too-large', 2) && Cache::read($path, 100) === 'second');
    chmod($path, 0644);
    pc_check('public file not trusted', Cache::read($path, 100) === null);
    pc_check('public file not adopted or repaired', !Cache::write($path, 'unsafe', 100));
    chmod($path, 0600);
    $victim = $private . '/victim'; file_put_contents($victim, 'victim-sentinel'); chmod($victim, 0600);
    unlink($path); symlink($victim, $path);
    pc_check('symlink read refused', Cache::read($path, 100) === null);
    pc_check('symlink write refused', !Cache::write($path, 'unsafe', 100));
    pc_check('symlink target unchanged', file_get_contents($victim) === 'victim-sentinel');
    unlink($path); link($victim, $path);
    pc_check('hardlink read refused', Cache::read($path, 100) === null);
    pc_check('hardlink write refused', !Cache::write($path, 'unsafe', 100));
    unlink($path);
    pc_check('missing path still accepted for safe creation', Cache::write($path, 'restored', 100));
    $linkDir = $root . '/alias'; symlink($private, $linkDir);
    pc_check('symlink parent refused', Cache::read($linkDir . '/cache.json', 100) === null && !Cache::write($linkDir . '/other', 'x', 100));
    chmod($private, 0777);
    pc_check('writable shared root refused', Cache::read($path, 100) === null && !Cache::write($path, 'unsafe', 100));
    clearstatcache(true, $private);
    pc_check('shared root not silently chmodded', (fileperms($private) & 07777) === 0777);
    chmod($private, 0700);
    pc_check('missing root not created', !Cache::write($root . '/absent/file', 'x', 100) && !file_exists($root . '/absent'));
    mkdir($root . '/unsafe', 0700); chmod($root . '/unsafe', 0777); mkdir($root . '/unsafe/private', 0700);
    pc_check('nonsticky writable ancestor refused', !Cache::write($root . '/unsafe/private/file', 'x', 100));
    foreach (['relative.json', 'file://' . $path, $private . '/../private/cache.json', $private . '//cache.json', $path . "\0bad"] as $bad) {
        pc_check('noncanonical path refused', Cache::read($bad, 100) === null && !Cache::write($bad, 'x', 100));
    }
    posix_mkfifo($private . '/fifo', 0600);
    pc_check('FIFO refused without opening', Cache::read($private . '/fifo', 100) === null && !Cache::write($private . '/fifo', 'x', 100));
    $lockPath = $private . '/state.lock'; symlink($victim, $lockPath);
    pc_check('lock symlink refused', Cache::lock($lockPath) === null);
    unlink($lockPath); link($victim, $lockPath);
    pc_check('lock hardlink refused', Cache::lock($lockPath) === null);
    unlink($lockPath);
    $lock = Cache::lock($lockPath);
    pc_check('safe persistent lock usable', is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB));
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    $lock = Cache::lock($lockPath);
    pc_check('existing safe lock usable', is_resource($lock));
    if (is_resource($lock)) fclose($lock);
    if (posix_geteuid() === 0) {
        chown($path, 65534);
        pc_check('foreign-owned file refused', Cache::read($path, 100) === null && !Cache::write($path, 'x', 100));
        chown($path, 0);
        chown($private, 65534);
        pc_check('foreign-owned root refused', Cache::read($path, 100) === null && !Cache::write($path, 'x', 100));
        chown($private, 0);
        chmod($root, 0711);
        mkdir($root . '/other-app', 0700); chown($root . '/other-app', 65534);
        if (!posix_seteuid(65534)) throw new RuntimeException('UID test transition failed');
        try {
            pc_check('sibling cannot read first app cache', Cache::read($path, 100) === null);
            pc_check('sibling cannot overwrite first app cache', !Cache::write($path, 'x', 100));
            pc_check('isolated second UID writes its own cache', Cache::write($root . '/other-app/cache.json', 'own-app', 100));
            pc_check('isolated second UID reads its own cache', Cache::read($root . '/other-app/cache.json', 100) === 'own-app');
        } finally {
            if (!posix_seteuid(0)) throw new RuntimeException('UID test restoration failed');
        }
        pc_check('first app content preserved after sibling attempts', Cache::read($path, 100) === 'restored');
        chmod($root, 0700);
    } else {
        $skipped = 7;
        echo "SKIP: 7 ownership and cross-UID cases require an isolated root-run test environment.\n";
    }
    pc_check('temporary files cleaned', glob($private . '/.*.tmp') === []);
} finally {
    $cleanup($root);
}
echo "private file cache: $passed passed, $failed failed, $skipped skipped\n";
exit($failed === 0 ? 0 : 1);
