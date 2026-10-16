<?php
/** Real private IO and concurrent v2 latch, isolated copied app, no database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !function_exists('pcntl_fork')) { fwrite(STDERR, "Unix CLI with pcntl required\n"); exit(1); }
$root = sys_get_temp_dir() . '/safeharbor-private-revocation-' . bin2hex(random_bytes(12));
mkdir($root, 0700); mkdir($root . '/lib', 0700); mkdir($root . '/config', 0700); mkdir($root . '/lib/eightwestid', 0700);
foreach (glob(__DIR__ . '/../lib/*.php') as $source) copy($source, $root . '/lib/' . basename($source));
copy(__DIR__ . '/../lib/eightwestid/private_file_cache.php', $root . '/lib/eightwestid/private_file_cache.php');
file_put_contents($root . '/config/config.php', '<?php return ["app_env" => "dev"];');
require_once $root . '/lib/revocation.php';
use EightWest\Id\Security\PrivateFileCache;
$passed = 0; $failed = 0;
function rio_check(string $label, bool $ok): void {
    global $passed, $failed;
    if ($ok) $passed++; else { $failed++; fwrite(STDERR, "FAIL revocation IO: $label\n"); }
}
$cleanup = static function (string $directory) use (&$cleanup, $root): void {
    if ($directory !== $root && !str_starts_with($directory, $root . '/')) throw new RuntimeException('Cleanup escaped');
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) continue;
        if ($entry->isDir() && !$entry->isLink()) $cleanup($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($directory);
};
try {
    mkdir($root . '/cache', 0700); $path = $root . '/cache/snapshot.json';
    $now = time(); $secret = random_bytes(48);
    $legacy = json_encode(['generated_at' => gmdate('c', $now), 'count' => 0, 'revoked' => []]);
    $versioned = json_encode(['generated_at' => gmdate('c', $now), 'count' => 0, 'revoked' => [],
        'authorization_count' => 1, 'authorizations' => [['sub' => 't2u3', 'session_version' => '1.1']]]);
    $write = static fn($body, $mode) => revocation_write_cache($path, $now, false, $body, hash_hmac('sha256', $body, $secret), $secret, $mode);
    rio_check('ordinary signed legacy write', $write($legacy, 'legacy') === 'written');
    rio_check('ordinary verified read', revocation_read_cache($path, $secret, $now)['body'] === $legacy);
    rio_check('signed v2 transition', $write($versioned, 'versioned') === 'written');
    rio_check('v2 latch retained', revocation_read_cache($path, $secret, $now)['versioned_observed'] === true);
    rio_check('older legacy cannot downgrade', $write($legacy, 'legacy') === 'downgrade');
    $tampered = json_decode(file_get_contents($path), true); $tampered['versioned_observed'] = false;
    file_put_contents($path, json_encode($tampered));
    rio_check('unsigned metadata change refused', revocation_read_cache($path, $secret, $now) === null);
    rio_check('fresh authenticated v2 restores valid state', $write($versioned, 'versioned') === 'written');
    chmod($root . '/cache', 0777);
    rio_check('unsafe root cannot supply authority', revocation_read_cache($path, $secret, $now) === null);
    rio_check('unsafe root never adopted', $write($versioned, 'versioned') === 'failed');
    chmod($root . '/cache', 0700);
    unlink($path . '.lock'); symlink($path, $path . '.lock');
    rio_check('lock substitution refused', $write($versioned, 'versioned') === 'failed');
    unlink($path . '.lock');
    for ($iteration = 0; $iteration < 3; $iteration++) {
        unlink($path); $write($legacy, 'legacy');
        $lock = PrivateFileCache::lock($path . '.lock');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) throw new RuntimeException('Test lock failed');
        $children = [];
        foreach ([[$versioned, 'versioned'], [$legacy, 'legacy']] as $index => [$body, $mode]) {
            $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('Test fork failed');
            if ($pid === 0) {
                fclose($lock);
                $result = $write($body, $mode);
                file_put_contents($root . '/result-' . $iteration . '-' . $index, $result);
                exit(0);
            }
            $children[] = $pid;
        }
        flock($lock, LOCK_UN); fclose($lock);
        foreach ($children as $pid) pcntl_waitpid($pid, $status);
        $state = revocation_read_cache($path, $secret, $now);
        rio_check('concurrent legacy and v2 keep v2 authority', is_array($state) && $state['versioned_observed'] && $state['body'] === $versioned);
        rio_check('concurrent v2 writer succeeds', file_get_contents($root . '/result-' . $iteration . '-0') === 'written');
        rio_check('legacy writer only writes before v2 or is refused', in_array(file_get_contents($root . '/result-' . $iteration . '-1'), ['written', 'downgrade'], true));
    }
} finally { $cleanup($root); }
echo "private revocation IO: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
