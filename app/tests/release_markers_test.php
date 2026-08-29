<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/release.php';

$checks = 0;
$failed = 0;

function check_release(bool $condition, string $message): void
{
    global $checks, $failed;
    $checks++;
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safeharbor-release-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700, true);

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $entry) {
        $removeTree($entry->getPathname());
    }
    rmdir($path);
};

try {
    check_release(safeharbor_release_metadata($fixture) === null, 'missing markers fail closed');

    $revision = str_repeat('a', 40);
    $artifact = str_repeat('b', 64);
    $digest = str_repeat('c', 64);
    file_put_contents($fixture . '/REVISION', $revision . "\n");
    file_put_contents($fixture . '/ARTIFACT_SHA256', $artifact . "\n");
    file_put_contents($fixture . '/RELEASE_DIGEST', $digest . "\n");

    $metadata = safeharbor_release_metadata($fixture);
    check_release(is_array($metadata), 'canonical marker set is accepted');
    check_release(($metadata['revision'] ?? '') === $revision, 'revision is exact');
    check_release(($metadata['artifact_sha256'] ?? '') === $artifact, 'artifact digest is exact');
    check_release(($metadata['release_digest'] ?? '') === $digest, 'release digest is exact');

    file_put_contents($fixture . '/REVISION', strtoupper($revision) . "\n");
    check_release(safeharbor_release_metadata($fixture) === null, 'uppercase revisions are rejected');
    file_put_contents($fixture . '/REVISION', $revision . "\n");
    file_put_contents($fixture . '/RELEASE_DIGEST', $digest . " trailing\n");
    check_release(safeharbor_release_metadata($fixture) === null, 'marker trailing data is rejected');
    file_put_contents($fixture . '/RELEASE_DIGEST', $digest . "\n");

    check_release(safeharbor_asset_url('/assets/css/app.css') === '/assets/css/app.css?v=dev', 'development cache key is stable');
    try {
        safeharbor_asset_url('https://example.invalid/app.css');
        check_release(false, 'external asset URL was accepted');
    } catch (InvalidArgumentException) {
        check_release(true, 'external asset URL is rejected');
    }
    try {
        safeharbor_asset_url('/assets/../config/config.php');
        check_release(false, 'traversal asset URL was accepted');
    } catch (InvalidArgumentException) {
        check_release(true, 'traversal asset URL is rejected');
    }

    mkdir($fixture . '/app/lib', 0700, true);
    mkdir($fixture . '/app/public', 0700, true);
    copy(__DIR__ . '/../lib/release.php', $fixture . '/app/lib/release.php');
    copy(__DIR__ . '/../public/health.php', $fixture . '/app/public/health.php');
    foreach (['REVISION', 'ARTIFACT_SHA256', 'RELEASE_DIGEST'] as $marker) {
        copy($fixture . '/' . $marker, $fixture . '/app/' . $marker);
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture . '/app/public/health.php');
    $healthOutput = shell_exec($command);
    $health = json_decode((string)$healthOutput, true);
    check_release(is_array($health), 'health response is JSON');
    check_release(($health['status'] ?? '') === 'ok', 'health reports ok');
    check_release(($health['revision'] ?? '') === $revision, 'health reports exact revision');
    check_release(($health['artifact_sha256'] ?? '') === $artifact, 'health reports exact artifact digest');
    check_release(($health['release_digest'] ?? '') === $digest, 'health reports exact release digest');

    unlink($fixture . '/app/RELEASE_DIGEST');
    $unavailableOutput = shell_exec($command);
    check_release(
        json_decode((string)$unavailableOutput, true) === ['status' => 'unavailable'],
        'health fails closed when a marker is missing'
    );
} finally {
    $removeTree($fixture);
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} of {$checks} release marker checks failed\n");
    exit(1);
}

echo "release markers: {$checks} checks passed\n";
